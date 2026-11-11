# Instalação

Ordem: **1. homeserver → 2. pacotes → 3. cada TV → 4. aprovar a TV no painel.**
Tudo abaixo também pode ser feito pelo Claude Code (veja o [`CLAUDE.md`](../CLAUDE.md)).

> Antes de tudo: a TV precisa de **root** e do Homebrew Channel. O root não faz parte deste projeto; veja
> https://tv.slada.sk/guides/rooting-guide e faça **por sua conta e risco**.

## 1. Homeserver

Requisitos: Linux x86 com Docker e Docker Compose, na mesma rede das TVs. Os exemplos usam `/srv/casa`.

```bash
# no servidor, como root
git clone https://github.com/gumerme/webos-tv-player.git /opt/webos-tv-player
mkdir -p /srv/casa && cp -a /opt/webos-tv-player/homeserver/. /srv/casa/
cd /srv/casa
sh scripts/instalar.sh          # cria o .env (chmod 600) com senha do banco e token da casa aleatórios
vi .env                         # preencha TMDB_TOKEN (obrigatório); CLIMA_*, TRAKT_CLIENT_ID e OPENSUBTITLES_* são opcionais
docker compose up -d --build
docker logs --tail 20 casa-worker   # deve listar "migração aplicada: 001_inicial.sql" ... 010
```

O banco é criado sozinho: o `casa-worker` aplica `casa/sql/*.sql` na subida
(detalhes em [banco-de-dados.md](banco-de-dados.md)).

Serviços opcionais: se não usa Apple Home ou Matter, comente `homebridge` e `matter-server` no `compose.yaml`.
O `casa-rec` baixa o MovieLens 32M (~1 GB) na primeira vez e treina por alguns minutos.

Primeiro usuário do painel (a senha vai por variável, não fica no histórico se você usar `read -rs`):

```bash
read -rs SENHA && docker exec -e SENHA="$SENHA" casa-web php bin/usuario.php criar admin "Seu Nome" admin; unset SENHA
```

Painel: `http://IP-DO-SERVIDOR:8080`.

Cadastrar o seu painel (a senha vai pelo STDIN):

```bash
read -rs SENHA && printf '%s' "$SENHA" | docker exec -i casa-play php bin/play.php conta-adicionar \
    --nome Principal --servidor http://SEU-PAINEL:PORTA --usuario SEU_USUARIO && unset SENHA
docker exec casa-play php bin/play.php conta-testar
docker exec casa-play php bin/play.php tarefa catalogo --agora
docker exec casa-play php bin/play.php tarefa top10 --agora
docker exec casa-play php bin/play.php status
```

A primeira carga do TMDB leva horas e roda sozinha em lotes; o app já funciona durante ela.
Conferir: `curl -s http://IP-DO-SERVIDOR:8080/api/play/saude`.

Opcional (systemd do host): métricas/rede do painel e backup diário do banco.

```bash
cp systemd/* /etc/systemd/system/ && systemctl daemon-reload
systemctl enable --now casa-coletor.timer casa-backup.timer
```

> Dica: dê ao servidor o nome `homeserver` na rede (DNS do roteador ou mDNS). As TVs procuram nesta ordem:
> último endereço que respondeu → `http://homeserver:8080` → varredura da rede /24.

## 2. Pacotes

```bash
# Player de TV
cd player
npm ci
npm run media && npm test       # opcional: mídia de teste (ffmpeg) + testes no Chrome (CHROME=/caminho/do/chrome fora do Windows)
npm i -g @webos-tools/cli       # ares-package
npm run package                 # gera player/dist/org.webostv.play_<versão>_all.ipk

# Home
cd ../launcher
cp app/defaults.example.js app/defaults.js   # opcional: endereço do homeserver, chave TMDB de reserva
python scripts/build.py                      # gera launcher/dist/org.webos.freemytv_<versão>_all.ipk
```

Publicar o Player no homeserver (as TVs instalam sozinhas em até 5 min, com o Player fechado):

```bash
cd homeserver && HOMESERVER=root@IP-DO-SERVIDOR sh scripts/publicar-player.sh
```

## 3. Em cada TV (SSH como root)

Pastas usadas: `/var/lib/webosbrew/` (Homebrew Channel). Copie os arquivos com `scp` para `/tmp` antes.

**Player e atualizador**

```sh
D=/var/lib/webosbrew/webostv-play; mkdir -p $D
cp /tmp/safe_install.sh $D/                      # tv/player/safe_install.sh
cp /tmp/org.webostv.play_*_all.ipk /tmp/play.ipk && sh $D/safe_install.sh
# atualizador automático (baixa do homeserver)
curl -s -o $D/atualizador.sh http://IP-DO-SERVIDOR:8080/api/play/app/atualizador && chmod 755 $D/atualizador.sh
curl -s -o /var/lib/webosbrew/init.d/62-webostv-play-update "http://IP-DO-SERVIDOR:8080/api/play/app/atualizador?init=1"
chmod 755 /var/lib/webosbrew/init.d/62-webostv-play-update
sh /var/lib/webosbrew/init.d/62-webostv-play-update
```

**Helper** (apps abertos, ícones, trailers, aviso de instalação; escuta só em 127.0.0.1:3911)

```sh
mkdir -p /var/lib/webosbrew/webostv-helper
cp /tmp/helper.py /var/lib/webosbrew/webostv-helper/        # tv/helper/helper.py
cp /tmp/60-webostv-helper /var/lib/webosbrew/init.d/ && chmod 755 /var/lib/webosbrew/init.d/60-webostv-helper
sh /var/lib/webosbrew/init.d/60-webostv-helper
```

**Home no lugar da Home da LG** (tudo em RAM por *bind mount*; desfazer = apagar o script do `init.d` e reiniciar)

```sh
luna-send-pub -n 1 luna://com.webos.appInstallService/dev/install \
  '{"id":"org.webos.freemytv","ipkUrl":"/tmp/org.webos.freemytv_2.0.5_all.ipk","subscribe":true}'
```

- **webOS 10** (`tv/home-takeover-webos10/`): copie `home-appinfo.json` e `hidden-original-appinfo.json` para
  `/var/lib/webosbrew/freemytv-as-home/`, `50-freemytv-as-home` para `/var/lib/webosbrew/init.d/` e reinicie.
  Atualizar depois: `sh update.sh /tmp/novo.ipk` (reinicia a TV). **Não** use `systemctl restart sam` com a TV
  em uso no webOS 10: a tela fica preta; reinicie a TV.
- **webOS 4.x** (`tv/home-takeover-webos4/`): copie `fullhome-appinfo.json` e `hidden-original-appinfo.json` para
  `/var/lib/webosbrew/webostv-as-home/`, `50-webostv-as-home` para o `init.d` e rode-o. Atualizar: `sh update.sh /tmp/novo.ipk`.

**Ajustes opcionais** (`tv/init.d/`, copiar para `/var/lib/webosbrew/init.d/`):

| Script | O que faz |
|---|---|
| `45-webostv-screensaver` | Protetor de tela com relógio e clima no lugar do da LG (copie `launcher/screensaver/` para `/var/lib/webosbrew/webostv-screensaver/`; troque a cidade em `index.html`) |
| `55-trim-preload` | Tira apps da lista de pré-carregamento da LG |
| `56-stop-unused-services` | Encerra AirPlay e ThinQ depois do boot (libera RAM) |
| `57-screensaver-timeout` | Protetor de tela em 5 min |
| `40-stop-lg-telemetry` | Só webOS 4.x: para os serviços de telemetria da LG |

## 4. Aprovar a TV

1. Abra o Player na TV: ele acha o homeserver sozinho e mostra um código.
2. No painel: **Player › Listas e TVs › Aprovar**, escolha o perfil padrão.
3. Na TV, escolha o perfil. Repita em cada TV.

## Problemas comuns

- **"Sem conexão com o homeserver"**: confira `curl http://IP-DO-SERVIDOR:8080/api/play/saude` de outro aparelho
  da rede e se o firewall libera a porta 8080. O IP da TV pode mudar entre cabo e Wi-Fi.
- **Atualização não instala**: o `safe_install.sh` espera o Player fechar. Veja `/var/lib/webosbrew/webostv-play/atualizador.log`.
- **Ver as TVs e versões**: `docker exec casa-play php bin/play.php app-status`.
