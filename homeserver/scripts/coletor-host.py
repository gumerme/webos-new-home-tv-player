#!/usr/bin/python3
"""
Coletor do host para o painel da casa (roda como root, casa-coletor.timer, a cada 60 s).

Grava /srv/casa/casa/var/host.json com o que os containers não enxergam:
containers, backups, atualizações, segurança, acessos SSH, internet e sincronia de hora.
Também guarda latência/perda da internet em metrica_rede e registra eventos
(container caiu/voltou, internet caiu/voltou) na tabela evento.
Só biblioteca padrão do Python.
"""
import glob
import json
import os
import re
import socket
import subprocess
import time

BASE = '/srv/casa'
SAIDA = f'{BASE}/casa/var/host.json'
LENTO = f'{BASE}/casa/var/host-lento.json'      # atualizações: a cada 6 h
ESTADO = f'{BASE}/casa/var/coletor-estado.json'  # para detectar mudanças entre execuções
INTERNET = ('1.1.1.1', '8.8.8.8')


def sh(cmd, timeout=25):
    try:
        r = subprocess.run(cmd, shell=isinstance(cmd, str), capture_output=True, text=True, timeout=timeout)
        return r.returncode, r.stdout.strip()
    except Exception as e:  # noqa: BLE001
        return -1, str(e)


def psql(sql, params=()):
    """Executa SQL no Postgres do compose (sem expor senha: usa o usuário local do container)."""
    def lit(v):
        if v is None:
            return 'null'
        if isinstance(v, (int, float)):
            return repr(float(v))
        return "'" + str(v).replace("'", "''") + "'"
    texto = sql % tuple(lit(p) for p in params) if params else sql
    try:
        subprocess.run(['docker', 'exec', '-i', 'postgres', 'psql', '-q', '-U', 'casa', '-d', 'casa', '-v', 'ON_ERROR_STOP=1'],
                       input=texto, capture_output=True, text=True, timeout=15)
    except Exception:  # noqa: BLE001
        pass


def psql_ler(sql):
    """Consulta simples: devolve linhas (colunas separadas por |)."""
    try:
        r = subprocess.run(['docker', 'exec', '-i', 'postgres', 'psql', '-q', '-t', '-A', '-U', 'casa', '-d', 'casa'],
                           input=sql, capture_output=True, text=True, timeout=15)
        return [l for l in r.stdout.splitlines() if l.strip()] if r.returncode == 0 else None
    except Exception:  # noqa: BLE001
        return None


def evento(tipo, nivel, titulo, detalhe=None):
    psql("insert into evento (tipo, nivel, titulo, detalhe, origem) values (%s, %s, %s, %s, 'coletor');",
         (tipo, nivel, titulo, detalhe))


# ------------------------------------------------------------------ partes

def containers():
    rc, nomes = sh(['docker', 'ps', '-a', '--format', '{{.Names}}'])
    if rc != 0 or not nomes:
        return []
    rc, js = sh(['docker', 'inspect'] + nomes.split())
    if rc != 0:
        return []
    lista = []
    for c in json.loads(js):
        st = c.get('State', {})
        lista.append({
            'nome': c.get('Name', '').lstrip('/'),
            'imagem': c.get('Config', {}).get('Image', ''),
            'estado': st.get('Status'),                       # running, exited, restarting...
            'saude': (st.get('Health') or {}).get('Status'),  # healthy, unhealthy, None
            'iniciado': st.get('StartedAt'),
            'reinicios': c.get('RestartCount', 0),
            'codigo_saida': st.get('ExitCode'),
        })
    lista.sort(key=lambda x: x['nome'])
    return lista


def ping(host):
    rc, out = sh(['ping', '-c', '3', '-W', '1', '-i', '0.3', '-q', host], timeout=10)
    perda = re.search(r'([\d.]+)% packet loss', out)
    rtt = re.search(r'= [\d.]+/([\d.]+)/', out)
    return {
        'host': host,
        'perda': float(perda.group(1)) if perda else 100.0,
        'ms': float(rtt.group(1)) if rtt else None,
    }


def dns_ms(nome='www.google.com'):
    ini = time.monotonic()
    try:
        socket.getaddrinfo(nome, 443)
        return round((time.monotonic() - ini) * 1000, 1)
    except OSError:
        return None


def rede():
    _, rota = sh("ip -j route show default")
    gw, iface = None, None
    try:
        r = json.loads(rota)[0]
        gw, iface = r.get('gateway'), r.get('dev')
    except Exception:  # noqa: BLE001
        pass
    _, addr = sh(f"ip -j -4 addr show dev {iface}" if iface else 'true')
    ip = None
    try:
        ip = json.loads(addr)[0]['addr_info'][0]['local']
    except Exception:  # noqa: BLE001
        pass
    _, vel = sh(f'cat /sys/class/net/{iface}/speed' if iface else 'true')
    internet = min((ping(h) for h in INTERNET), key=lambda p: (p['perda'], p['ms'] or 9e9))
    _, dns_srv = sh("awk '/^nameserver/{print $2}' /etc/resolv.conf | head -3")
    return {
        'interface': iface,
        'ip': ip,
        'velocidade_mbps': int(vel) if vel.isdigit() else None,
        'gateway': ping(gw) if gw else None,
        'internet': internet,
        'dns_ms': dns_ms(),
        'dns_servidores': dns_srv.split() if dns_srv else [],
    }


def backups():
    arquivos = sorted(glob.glob(f'{BASE}/backup/postgres/casa-*.sql.gz'), key=os.path.getmtime, reverse=True)
    _, timer = sh(['systemctl', 'list-timers', 'casa-backup.timer', '--all', '--output=json'])
    _, serv = sh(['systemctl', 'show', 'casa-backup.service', '-p', 'Result', '-p', 'ExecMainStatus'])
    s = dict(l.split('=', 1) for l in serv.splitlines() if '=' in l)
    try:
        tj = json.loads(timer)[0]
    except Exception:  # noqa: BLE001
        tj = {}
    t = {'NextElapseUSecRealtime': tj.get('next'), 'LastTriggerUSec': tj.get('last')}
    num = lambda v: int(v) // 1_000_000 if v else None  # noqa: E731  (microssegundos -> segundos)
    return {
        'quantidade': len(arquivos),
        'total_bytes': sum(os.path.getsize(a) for a in arquivos),
        'ultimo': {'arquivo': os.path.basename(arquivos[0]), 'quando': int(os.path.getmtime(arquivos[0])),
                   'bytes': os.path.getsize(arquivos[0])} if arquivos else None,
        'proximo': num(t.get('NextElapseUSecRealtime')),
        'ultima_execucao': num(t.get('LastTriggerUSec')),
        'resultado': s.get('Result'),
        'fora_da_maquina': False,   # ainda não há cópia externa
    }


def ssh_acessos():
    _, log = sh(['journalctl', '-u', 'sshd', '--since', '-24h', '--no-pager', '-o', 'short-unix'], timeout=20)
    falhas, entradas = 0, []
    for linha in log.splitlines():
        if 'Failed password' in linha or 'Invalid user' in linha:
            falhas += 1
        m = re.search(r'^([\d.]+) .*Accepted (\w+) for (\S+) from (\S+)', linha)
        if m:
            entradas.append({'quando': int(float(m.group(1))), 'metodo': m.group(2), 'usuario': m.group(3), 'ip': m.group(4)})
    return {'falhas_24h': falhas, 'entradas_24h': len(entradas), 'ultimas': entradas[-5:][::-1]}


def seguranca():
    _, se = sh(['getenforce'])
    _, fw = sh(['systemctl', 'is-active', 'firewalld'])
    _, portas = sh(['firewall-cmd', '--list-ports'])
    _, servicos = sh(['firewall-cmd', '--list-services'])
    try:
        fips = open('/proc/sys/crypto/fips_enabled').read().strip() == '1'
    except OSError:
        fips = None
    _, root_ssh = sh("sshd -T 2>/dev/null | awk '/^permitrootlogin/{print $2}'")
    _, senha_ssh = sh("sshd -T 2>/dev/null | awk '/^passwordauthentication/{print $2}'")
    return {
        'selinux': se or None,
        'firewall': fw == 'active',
        'portas': portas.split(),
        'servicos': servicos.split(),
        'fips': fips,
        'ssh_root': root_ssh or None,
        'ssh_senha': senha_ssh == 'yes',
    }


def sistema():
    with open('/proc/stat') as f:
        boot = next(int(l.split()[1]) for l in f if l.startswith('btime'))
    _, falhas = sh(['systemctl', '--failed', '--no-legend', '--plain'])
    unidades = [l.split()[0] for l in falhas.splitlines() if l.strip()]
    rc_reboot, _ = sh(['dnf', 'needs-restarting', '-r'], timeout=60)
    _, ntp = sh(['timedatectl', 'show', '-p', 'NTPSynchronized', '--value'])
    _, chrony = sh(['chronyc', '-c', 'tracking'])
    desvio = None
    try:
        desvio = round(float(chrony.split(',')[4]) * 1000, 2)   # segundos -> ms
    except Exception:  # noqa: BLE001
        pass
    _, kernel_novo = sh("rpm -q kernel --last 2>/dev/null | head -1 | awk '{print $1}' | sed 's/^kernel-//'")
    return {
        'boot': boot,
        'kernel': os.uname().release,
        'kernel_instalado': kernel_novo or None,
        'reiniciar': rc_reboot == 1,
        'unidades_falhando': unidades,
        'hora_sincronizada': ntp == 'yes',
        'desvio_hora_ms': desvio,
    }


def atualizacoes():
    """Pesado (consulta repositórios): só a cada 6 h."""
    try:
        with open(LENTO) as f:
            cache = json.load(f)
        if time.time() - cache.get('verificado_em', 0) < 6 * 3600:
            return cache
    except (OSError, ValueError):
        pass
    rc, out = sh(['dnf', '-q', 'check-update', '--refresh'], timeout=300)
    pacotes = [l.split()[0] for l in out.splitlines() if l and not l.startswith(' ') and '.' in l.split()[0]] if rc == 100 else []
    _, seg = sh(['dnf', '-q', 'updateinfo', 'list', '--security'], timeout=120)
    seg_n = len([l for l in seg.splitlines() if l.strip()])
    dados = {'verificado_em': int(time.time()), 'pendentes': len(pacotes), 'seguranca': seg_n,
             'exemplos': pacotes[:8], 'erro': rc not in (0, 100)}
    tmp = LENTO + '.tmp'
    with open(tmp, 'w') as f:
        json.dump(dados, f)
    os.replace(tmp, LENTO)
    return dados


# ------------------------------------------------------------- rede de casa

REDE_ULTIMA = f'{BASE}/casa/var/rede-ultima.txt'
REDE_INTERVALO = 300      # varredura a cada 5 min
SSDP_PORTA = 1901         # porta local fixa para receber respostas UPnP (liberada só para a LAN no firewall)


def _rede_local():
    _, rota = sh("ip -j route show default")
    try:
        iface = json.loads(rota)[0]['dev']
        info = json.loads(sh(f"ip -j -4 addr show dev {iface}")[1])[0]['addr_info'][0]
        mac = open(f'/sys/class/net/{iface}/address').read().strip().lower()
        return iface, info['local'], info['prefixlen'], mac
    except Exception:  # noqa: BLE001
        return None


def _ssdp():
    """M-SEARCH UPnP: devolve {ip: {servidor, fabricante, modelo, nome}}.
    A descrição vem de aparelhos da rede (não confiáveis): sem parser XML, só regex em no máximo 64 KB
    e só de endereços da própria rede local."""
    import ipaddress
    import html
    import urllib.request
    vistos = {}
    s = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
    try:
        s.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
        s.bind(('', SSDP_PORTA))
        s.settimeout(4)
        msg = ('M-SEARCH * HTTP/1.1\r\nHOST: 239.255.255.250:1900\r\nMAN: "ssdp:discover"\r\n'
               'MX: 3\r\nST: ssdp:all\r\n\r\n').encode()
        for _ in range(2):
            s.sendto(msg, ('239.255.255.250', 1900))
        while True:
            d, a = s.recvfrom(4096)
            t = d.decode(errors='ignore')
            e = vistos.setdefault(a[0], {'locs': set(), 'servidor': None})
            m = re.search(r'(?im)^location:\s*(\S+)', t)
            if m:
                e['locs'].add(m.group(1))
            m = re.search(r'(?im)^server:\s*(.+)$', t)
            if m:
                e['servidor'] = m.group(1).strip()[:120]
    except (socket.timeout, OSError):
        pass
    finally:
        s.close()
    def campo(txt, tag):
        m = re.search(rf'<{tag}>([^<]{{1,120}})</{tag}>', txt)
        return html.unescape(m.group(1)).strip() if m else None

    for ip, e in vistos.items():
        for loc in list(e['locs'])[:4]:
            try:
                host = re.match(r'https?://([^/:]+)', loc)
                # só busca a descrição no próprio IP que respondeu, e só se for endereço privado
                if not host or host.group(1) != ip or not ipaddress.ip_address(ip).is_private:
                    continue
                with urllib.request.urlopen(loc, timeout=3) as r:
                    x = r.read(65536).decode('utf-8', errors='ignore')
                if campo(x, 'modelName') or campo(x, 'friendlyName'):
                    e.update(fabricante=campo(x, 'manufacturer'), modelo=campo(x, 'modelName'), nome=campo(x, 'friendlyName'))
                    break
            except Exception:  # noqa: BLE001
                pass
        e.pop('locs', None)
    return vistos


# ---- mDNS / Bonjour -------------------------------------------------------
# Consulta "legacy unicast" (porta de origem != 5353): os aparelhos respondem direto na 1901/udp.
MDNS_TIPOS = [
    '_services._dns-sd._udp.local', '_device-info._tcp.local', '_airplay._tcp.local', '_raop._tcp.local',
    '_googlecast._tcp.local', '_hap._tcp.local', '_matter._tcp.local', '_matterc._udp.local',
    '_ipp._tcp.local', '_ipps._tcp.local', '_printer._tcp.local', '_pdl-datastream._tcp.local',
    '_uscan._tcp.local', '_scanner._tcp.local', '_companion-link._tcp.local', '_spotify-connect._tcp.local',
    '_smb._tcp.local', '_http._tcp.local', '_amzn-wplay._tcp.local', '_androidtvremote2._tcp.local',
    '_sleep-proxy._udp.local', '_workstation._tcp.local', '_apple-mobdev2._tcp.local',
]
TXT_MODELO = ('model', 'md', 'ty', 'usb_mdl', 'product', 'am', 'rpmd', 'mdl')
TXT_GUARDAR = TXT_MODELO + ('fn', 'manufacturer', 'mf', 'osxvers', 'srcvers', 'rpvr', 'note')


def _dns_nome(buf, pos):
    rotulos, saltos, fim = [], 0, None
    while True:
        if pos >= len(buf):
            raise ValueError('nome truncado')
        n = buf[pos]
        if n == 0:
            pos += 1
            break
        if n & 0xC0 == 0xC0:
            if pos + 1 >= len(buf) or saltos > 15:
                raise ValueError('ponteiro inválido')
            if fim is None:
                fim = pos + 2
            pos = ((n & 0x3F) << 8) | buf[pos + 1]
            saltos += 1
            continue
        rotulos.append(buf[pos + 1:pos + 1 + n].decode('utf-8', 'ignore'))
        pos += 1 + n
        if len(rotulos) > 40:
            raise ValueError('nome longo demais')
    return '.'.join(rotulos), (fim if fim is not None else pos)


def _dns_registros(buf):
    import struct
    if len(buf) < 12:
        return []
    qd, an, ns, ar = struct.unpack('>HHHH', buf[4:12])
    pos = 12
    for _ in range(qd):
        _, pos = _dns_nome(buf, pos)
        pos += 4
    regs = []
    for _ in range(min(an + ns + ar, 200)):
        nome, pos = _dns_nome(buf, pos)
        if pos + 10 > len(buf):
            break
        tipo, _cl, _ttl, tam = struct.unpack('>HHIH', buf[pos:pos + 10])
        pos += 10
        ini, dados = pos, buf[pos:pos + tam]
        pos += tam
        if tipo == 12:
            regs.append(('PTR', nome, _dns_nome(buf, ini)[0]))
        elif tipo == 16:
            txt, i = {}, 0
            while i < len(dados):
                n = dados[i]
                s = dados[i + 1:i + 1 + n].decode('utf-8', 'ignore')
                i += 1 + n
                if '=' in s:
                    k, v = s.split('=', 1)
                    txt[k.lower()[:30]] = v[:100]
            regs.append(('TXT', nome, txt))
        elif tipo == 33 and tam >= 6:
            regs.append(('SRV', nome, _dns_nome(buf, ini + 6)[0]))
    return regs


def _dns_pergunta(nomes, unicast=True):
    import struct
    q = b''
    for n in nomes:
        for r in n.split('.'):
            q += bytes([len(r)]) + r.encode()
        q += b'\0' + struct.pack('>HH', 12, 0x8001 if unicast else 0x0001)   # PTR, classe IN (bit QU = resposta unicast)
    return struct.pack('>HHHHHH', 0, 0, len(nomes), 0, 0, 0) + q


def _mdns(meu_ip=None):
    """{ip: {servicos:set, nomes:set, txt:dict}} a partir das respostas Bonjour.
    Preferência: escutar a 5353 junto com os outros (respostas multicast, que iPhones usam);
    se não der, consulta unicast respondida na 1901."""
    import struct
    porip = {}
    s = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
    multicast = False
    try:
        s.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
        try:
            s.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEPORT, 1)
            s.bind(('', 5353))
            s.setsockopt(socket.IPPROTO_IP, socket.IP_ADD_MEMBERSHIP,
                         struct.pack('4s4s', socket.inet_aton('224.0.0.251'), socket.inet_aton(meu_ip or '0.0.0.0')))
            multicast = True
        except OSError:
            s.close()
            s = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
            s.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
            s.bind(('', SSDP_PORTA))
        s.setsockopt(socket.IPPROTO_IP, socket.IP_MULTICAST_TTL, 255)
        tipos_extra = set()
        for rodada in range(2):
            perguntar = MDNS_TIPOS if rodada == 0 else sorted(tipos_extra - set(MDNS_TIPOS))[:30]
            if not perguntar:
                break
            for i in range(0, len(perguntar), 12):
                s.sendto(_dns_pergunta(perguntar[i:i + 12], unicast=not multicast), ('224.0.0.251', 5353))
            fim = time.time() + 4
            while (resta := fim - time.time()) > 0:
                s.settimeout(resta)
                try:
                    d, a = s.recvfrom(9000)
                except socket.timeout:
                    break
                if a[0] == meu_ip:                     # anúncios do próprio servidor (Homebridge etc.)
                    continue
                try:
                    regs = _dns_registros(d)
                except Exception:  # noqa: BLE001
                    continue
                if not regs or not (d[2] & 0x80):      # só respostas (bit QR), não perguntas de outros
                    continue
                e = porip.setdefault(a[0], {'servicos': set(), 'nomes': set(), 'txt': {}})
                for tipo, nome, valor in regs:
                    if tipo == 'PTR' and nome == '_services._dns-sd._udp.local':
                        tipos_extra.add(valor)
                    elif tipo == 'PTR' and valor.endswith('.local'):
                        partes = valor.split('._', 1)
                        if len(partes) == 2:
                            e['nomes'].add(partes[0][:60])
                            e['servicos'].add(partes[1].split('.')[0].lstrip('_')[:30])
                    elif tipo == 'TXT':
                        for k, v in valor.items():
                            if k in TXT_GUARDAR and v:
                                e['txt'].setdefault(k, v)
    except OSError:
        pass
    finally:
        s.close()
    return porip


# ---- página web do aparelho (a cada 12 h por aparelho) ----------------------
REDE_HTTP = f'{BASE}/casa/var/rede-http.json'


def _http_info(ip):
    """Título/servidor da página inicial do aparelho (só leitura de GET /, sem credenciais).
    HTTPS sem verificar certificado de propósito: aparelhos da LAN usam certificado autoassinado,
    nada sensível é enviado e o retorno é tratado como dado não confiável (limitado a 32 KB)."""
    import html
    import http.client
    import ssl
    for porta, tls in ((80, False), (8080, False), (443, True)):
        try:
            c = (http.client.HTTPSConnection(ip, porta, timeout=2, context=ssl._create_unverified_context())
                 if tls else http.client.HTTPConnection(ip, porta, timeout=2))
            c.request('GET', '/', headers={'User-Agent': 'casa-homeserver/1.0'})
            r = c.getresponse()
            corpo = r.read(32768).decode('utf-8', 'ignore')
            srv = (r.getheader('Server') or '')[:80] or None
            c.close()
            t = re.search(r'<title[^>]*>([^<]{1,120})', corpo, re.I)
            titulo = html.unescape(t.group(1)).strip()[:120] if t else None
            if titulo or srv:
                return {'titulo': titulo, 'servidor': srv, 'porta': porta}
        except Exception:  # noqa: BLE001
            continue
    return {}


# ---- fabricantes (base oficial IEEE, atualizada por mês) --------------------
OUI_ARQ = f'{BASE}/casa/var/oui.csv'


def _oui():
    import csv
    import urllib.request
    try:
        if not os.path.exists(OUI_ARQ) or time.time() - os.path.getmtime(OUI_ARQ) > 30 * 86400:
            req = urllib.request.Request('https://standards-oui.ieee.org/oui/oui.csv', headers={'User-Agent': 'Mozilla/5.0'})
            with urllib.request.urlopen(req, timeout=60) as r:
                dados = r.read(20 * 1024 * 1024)
            if b'Assignment' in dados[:200]:
                with open(OUI_ARQ + '.tmp', 'wb') as f:
                    f.write(dados)
                os.replace(OUI_ARQ + '.tmp', OUI_ARQ)
    except Exception:  # noqa: BLE001
        pass
    base = {}
    try:
        with open(OUI_ARQ, newline='', encoding='utf-8', errors='ignore') as f:
            for linha in csv.reader(f):
                if len(linha) >= 3 and len(linha[1]) == 6:
                    base[linha[1].upper()] = linha[2].strip()[:60]
    except OSError:
        pass
    return base


def _mac_privado(mac):
    try:
        return bool(int(mac[1], 16) & 2)
    except (ValueError, IndexError):
        return False


def varrer_rede():
    """Varredura ARP (nmap) + nomes do roteador + UPnP. Grava rede_dispositivo e registra aparelhos novos."""
    try:
        if time.time() - float(open(REDE_ULTIMA).read() or 0) < REDE_INTERVALO:
            return None
    except (OSError, ValueError):
        pass
    local = _rede_local()
    if not local:
        return None
    iface, meu_ip, prefixo, meu_mac = local
    if prefixo < 22:                      # não varre redes grandes demais
        prefixo = 24
    rc, xml_txt = sh(['nmap', '-sn', '-PR', '-n', '-oX', '-', f'{meu_ip}/{prefixo}'], timeout=120)
    if rc != 0:
        return None
    import xml.etree.ElementTree as ET
    achados = {}
    for h in ET.fromstring(xml_txt).iter('host'):
        ip = mac = fab = None
        for a in h.iter('address'):
            if a.get('addrtype') == 'ipv4':
                ip = a.get('addr')
            elif a.get('addrtype') == 'mac':
                mac, fab = a.get('addr', '').lower(), a.get('vendor')
        if ip and mac:
            achados[mac] = {'ip': ip, 'fabricante': fab}
    achados[meu_mac] = {'ip': meu_ip, 'fabricante': None}

    import concurrent.futures as cf

    def nome(ip):
        try:
            return socket.gethostbyaddr(ip)[0]
        except OSError:
            return None
    with cf.ThreadPoolExecutor(16) as ex:
        nomes = dict(zip(achados, ex.map(lambda m: nome(achados[m]['ip']), achados)))
    upnp = _ssdp()
    mdns = _mdns(meu_ip)

    # fabricante que o nmap não conhece -> base IEEE
    if any(not d['fabricante'] and not _mac_privado(m) for m, d in achados.items()):
        oui = _oui()
        for m, d in achados.items():
            if not d['fabricante'] and not _mac_privado(m):
                d['fabricante'] = oui.get(m.replace(':', '')[:6].upper())

    # página web: só a cada 12 h por aparelho (não incomodar os aparelhos a cada 5 min)
    try:
        with open(REDE_HTTP) as f:
            cache_http = json.load(f)
    except (OSError, ValueError):
        cache_http = {}
    vencidos = [m for m in achados if m != meu_mac and time.time() - cache_http.get(m, {}).get('ts', 0) > 12 * 3600]
    with cf.ThreadPoolExecutor(8) as ex:
        for m, info in zip(vencidos, ex.map(lambda m: _http_info(achados[m]['ip']), vencidos)):
            cache_http[m] = info | {'ts': int(time.time())}
    with open(REDE_HTTP + '.tmp', 'w') as f:
        json.dump(cache_http, f)
    os.replace(REDE_HTTP + '.tmp', REDE_HTTP)

    existentes = psql_ler('select mac from rede_dispositivo;')
    if existentes is None:
        return None
    primeira = len(existentes) == 0       # 1ª varredura: tudo que já está na rede entra como conhecido
    existentes = set(existentes)

    cmds = ['begin;']
    for mac, d in achados.items():
        u = upnp.get(d['ip'], {})
        md = mdns.get(d['ip'], {})
        txt = md.get('txt', {})
        h = cache_http.get(mac, {})
        modelo = u.get('modelo') or next((txt[k] for k in TXT_MODELO if txt.get(k)), None)
        nome_mdns = txt.get('fn') or (sorted(md.get('nomes', []), key=len)[0] if md.get('nomes') else None)
        servicos = sorted(md.get('servicos', []))[:20]
        arr = ('ARRAY[' + ', '.join(_lit(x) for x in servicos) + ']::text[]') if servicos else 'null'
        cmds.append(
            "insert into rede_dispositivo (mac, ip, fabricante, nome_dns, upnp_fabricante, upnp_modelo, upnp_nome, upnp_servidor, conhecido, online, ultimo_visto, "
            "mac_privado, modelo, mdns_nome, mdns_servicos, http_titulo, http_servidor, extras) "
            "values (%s, %s, %s, %s, %s, %s, %s, %s, %s, true, now(), %s, %s, %s, %s, %s, %s, %s::jsonb) "
            "on conflict (mac) do update set ip = excluded.ip, online = true, ultimo_visto = now(), "
            "fabricante = coalesce(excluded.fabricante, rede_dispositivo.fabricante), "
            "nome_dns = coalesce(excluded.nome_dns, rede_dispositivo.nome_dns), "
            "upnp_fabricante = coalesce(excluded.upnp_fabricante, rede_dispositivo.upnp_fabricante), "
            "upnp_modelo = coalesce(excluded.upnp_modelo, rede_dispositivo.upnp_modelo), "
            "upnp_nome = coalesce(excluded.upnp_nome, rede_dispositivo.upnp_nome), "
            "upnp_servidor = coalesce(excluded.upnp_servidor, rede_dispositivo.upnp_servidor), "
            "mac_privado = excluded.mac_privado, "
            "modelo = coalesce(excluded.upnp_modelo, rede_dispositivo.upnp_modelo, excluded.modelo, rede_dispositivo.modelo), "
            "mdns_nome = coalesce(excluded.mdns_nome, rede_dispositivo.mdns_nome), "
            "mdns_servicos = coalesce(excluded.mdns_servicos, rede_dispositivo.mdns_servicos), "
            "http_titulo = coalesce(excluded.http_titulo, rede_dispositivo.http_titulo), "
            "http_servidor = coalesce(excluded.http_servidor, rede_dispositivo.http_servidor), "
            "extras = rede_dispositivo.extras || excluded.extras;"
            % (tuple(_lit(v) for v in (mac, d['ip'], d['fabricante'], nomes.get(mac), u.get('fabricante'), u.get('modelo'),
                                       u.get('nome'), u.get('servidor'), primeira or mac == meu_mac,
                                       _mac_privado(mac), modelo, nome_mdns)) + (arr,)
               + tuple(_lit(v) for v in (h.get('titulo'), h.get('servidor'), json.dumps(txt, ensure_ascii=False)))))
    lista = ', '.join(_lit(m) for m in achados)
    cmds.append(f'update rede_dispositivo set online = false where online and mac not in ({lista});')
    cmds.append('commit;')
    try:
        subprocess.run(['docker', 'exec', '-i', 'postgres', 'psql', '-q', '-U', 'casa', '-d', 'casa', '-v', 'ON_ERROR_STOP=1'],
                       input='\n'.join(cmds), capture_output=True, text=True, timeout=30)
    except Exception:  # noqa: BLE001
        return None
    if not primeira:
        for mac in set(achados) - existentes:
            d, u = achados[mac], upnp.get(achados[mac]['ip'], {})
            quem = u.get('nome') or nomes.get(mac) or d['fabricante'] or 'fabricante desconhecido'
            evento('rede', 'aviso', f"Aparelho novo na rede: {quem}", f"IP {d['ip']} · MAC {mac}")
    with open(REDE_ULTIMA, 'w') as f:
        f.write(str(time.time()))
    return {'aparelhos': len(achados), 'rede': f'{meu_ip}/{prefixo}', 'interface': iface}


def _lit(v):
    if v is None:
        return 'null'
    if isinstance(v, bool):
        return 'true' if v else 'false'
    return "'" + str(v).replace("'", "''") + "'"


# ------------------------------------------------------------------ principal

def principal():
    dados = {'gerado_em': int(time.time())}
    for nome, func in [('containers', containers), ('rede', rede), ('backups', backups), ('ssh', ssh_acessos),
                       ('seguranca', seguranca), ('sistema', sistema), ('atualizacoes', atualizacoes)]:
        try:
            dados[nome] = func()
        except Exception as e:  # noqa: BLE001
            dados[nome] = {'erro': str(e)}

    try:
        varredura = varrer_rede()
    except Exception as e:  # noqa: BLE001
        varredura = {'erro': str(e)}
    if varredura is not None:
        dados['varredura'] = varredura | {'quando': int(time.time())}
    else:
        try:
            with open(SAIDA) as f:
                ant = json.load(f).get('varredura')
            if ant:
                dados['varredura'] = ant
        except (OSError, ValueError):
            pass

    tmp = SAIDA + '.tmp'
    with open(tmp, 'w') as f:
        json.dump(dados, f, ensure_ascii=False)
    os.chmod(tmp, 0o644)
    os.replace(tmp, SAIDA)

    # histórico da internet
    r = dados.get('rede') or {}
    gw, net = r.get('gateway') or {}, r.get('internet') or {}
    psql('insert into metrica_rede (gateway_ms, gateway_perda, internet_ms, internet_perda, dns_ms) values (%s, %s, %s, %s, %s);',
         (gw.get('ms'), gw.get('perda'), net.get('ms'), net.get('perda'), r.get('dns_ms')))

    # eventos por mudança de estado
    try:
        with open(ESTADO) as f:
            antes = json.load(f)
    except (OSError, ValueError):
        antes = None
    agora = {
        'containers': {c['nome']: c['estado'] for c in dados.get('containers') or [] if isinstance(c, dict)},
        'internet': (net.get('perda', 100) < 100),
        'reiniciar': (dados.get('sistema') or {}).get('reiniciar'),
    }
    if antes is not None:
        for nome, est in agora['containers'].items():
            ant = antes.get('containers', {}).get(nome)
            if ant and ant != est:
                evento('sistema', 'ok' if est == 'running' else 'erro', f'Container {nome}: {ant} → {est}')
            elif ant is None:
                evento('sistema', 'info', f'Container {nome} apareceu ({est})')
        for nome in set(antes.get('containers', {})) - set(agora['containers']):
            evento('sistema', 'aviso', f'Container {nome} foi removido')
        if antes.get('internet') is True and agora['internet'] is False:
            evento('rede', 'erro', 'Internet caiu', 'Sem resposta de 1.1.1.1 e 8.8.8.8')
        elif antes.get('internet') is False and agora['internet'] is True:
            evento('rede', 'ok', 'Internet voltou')
        if not antes.get('reiniciar') and agora['reiniciar']:
            evento('sistema', 'aviso', 'Atualizações pedem reinício do servidor')
    with open(ESTADO, 'w') as f:
        json.dump(agora, f)


if __name__ == '__main__':
    principal()
