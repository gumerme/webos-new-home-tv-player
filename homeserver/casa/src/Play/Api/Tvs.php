<?php
declare(strict_types=1);

namespace Casa\Play\Api;

use Casa\Comandos;
use Casa\Dispositivos;
use Casa\Eventos;

/** Descoberta (saude), registro e estado das TVs, conta IPTV, perfis e integrações (Home, Siri). */
final class Tvs
{
    public const VERSAO = '2.0.0';
    public const APP_ID = 'org.webostv.play';

    /** GET saude (sem token): a TV acha o homeserver mesmo quando o IP dele muda. */
    public static function saude(Pedido $p): array
    {
        return [
            'app'      => 'casa-play',
            'versao'   => self::VERSAO,
            'servidor' => 'homeserver',
            'ip'       => (string) ($_SERVER['SERVER_ADDR'] ?? ''),
            'porta'    => (int) ($_SERVER['SERVER_PORT'] ?? getenv('APACHE_PORT') ?: 8080),
        ];
    }

    /**
     * POST tv/registrar {nome, modelo, duid, versao, webos} -> {token, status, codigo}.
     * TV nova entra "pendente" até um administrador aprovar no painel (Player › Listas e TVs) pelo código
     * mostrado na tela. Com PLAY_APROVACAO=0 entra "ativa" direto. Só aceita IP de rede local/Tailscale.
     * O DUID vem da própria TV e pode ser forjado: com aprovação ligada, um registro sem token nunca mexe numa
     * TV já aprovada (cria outra linha pendente); só linha pendente ou bloqueada com o mesmo DUID é
     * reaproveitada (token e código novos; a aprovação do painel confere o código). Sem aprovação
     * (PLAY_APROVACAO=0) a mesma TV reaproveita a linha como antes. Bloqueada continua bloqueada.
     */
    public static function registrar(Pedido $p): array
    {
        $ip = Acesso::ip();
        if (!self::ipLocal($ip)) {
            throw new Erro('registro só pela rede de casa', 403);
        }
        $limpo = fn(string $k, int $max) => mb_substr(trim((string) ($p->corpo[$k] ?? '')), 0, $max) ?: null;
        $nome = $limpo('nome', 40) ?? 'TV';
        $duid = $limpo('duid', 120);
        $recentes = (int) Base::valor("select count(*) from play.tv where criado_em > now() - interval '1 hour'");
        if ($recentes >= 20) {
            throw new Erro('muitos pedidos de registro; tente mais tarde', 429);
        }
        $token = Acesso::novoToken();
        $codigo = Acesso::novoCodigo();
        $inicial = Acesso::exigeAprovacao() ? 'pendente' : 'ativa';
        $existente = $duid !== null ? Base::linha('select id, status, dispositivo_slug from play.tv where duid = ? order by id desc limit 1', [$duid]) : null;
        if ($existente && $inicial === 'pendente' && $existente['status'] === 'ativa') {
            $existente = null;   // não sequestra a TV aprovada: pede aprovação de novo numa linha nova
        }
        if ($existente) {
            $l = Base::linha("update play.tv set token_hash = ?, codigo = ?, modelo = ?, webos = ?, versao_app = ?, ip = ?, visto_em = now(),
                                     status = case when status in ('bloqueada', 'pendente') then status else ? end
                               where id = ? returning id, status, dispositivo_slug",
                [Acesso::hash($token), $codigo, $limpo('modelo', 80), $limpo('webos', 40), $limpo('versao', 20), $ip, $inicial, (int) $existente['id']]);
        } else {
            $l = Base::linha("insert into play.tv (nome, modelo, duid, webos, versao_app, token_hash, codigo, ip, visto_em, perfil_id, status)
                              values (?, ?, ?, ?, ?, ?, ?, ?, now(), (select min(id) from play.perfil), ?)
                              returning id, status, dispositivo_slug",
                [$nome, $limpo('modelo', 80), $duid, $limpo('webos', 40), $limpo('versao', 20), Acesso::hash($token), $codigo, $ip, $inicial]);
        }
        if (empty($l['dispositivo_slug']) && ($slug = Acesso::acharAparelho($ip))) {
            Base::exec('update play.tv set dispositivo_slug = ? where id = ?', [$slug, (int) $l['id']]);
        } elseif (!empty($l['dispositivo_slug'])) {
            Acesso::corrigirAparelho((string) $l['dispositivo_slug'], $ip, $nome);
        }
        Eventos::registrar('aparelho', 'info', "Player: $nome conectou" . ($l['status'] === 'bloqueada' ? ' (bloqueada)' : ''),
            'IP ' . ($ip ?: '?') . ' · Player › Listas e TVs', 'play');
        return ['token' => $token, 'status' => $l['status'], 'codigo' => $codigo, 'id' => (int) $l['id']];
    }

    /** Rede de casa: 127/8, 10/8, 172.16/12, 192.168/16, 100.64/10 (Tailscale), ::1, fc00::/7, fe80::/10. */
    public static function ipLocal(string $ip): bool
    {
        if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return false;
        }
        if (str_starts_with($ip, '::ffff:')) {
            $ip = substr($ip, 7);
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $n = ip2long($ip);
            foreach ([['127.0.0.0', 8], ['10.0.0.0', 8], ['172.16.0.0', 12], ['192.168.0.0', 16], ['100.64.0.0', 10]] as [$rede, $bits]) {
                if (($n & (-1 << (32 - $bits))) === (ip2long($rede) & (-1 << (32 - $bits)))) {
                    return true;
                }
            }
            return false;
        }
        $ip = strtolower($ip);
        return $ip === '::1' || preg_match('/^f[cd]/', $ip) === 1 || str_starts_with($ip, 'fe8') || str_starts_with($ip, 'fe9')
            || str_starts_with($ip, 'fea') || str_starts_with($ip, 'feb');
    }

    /** GET estado: TV, vencimento da lista, versão do catálogo e avisos pendentes. */
    public static function estado(Pedido $p): array
    {
        $perfil = $p->perfil();
        $conta = Base::conta();
        $dias = null;
        if ($conta && $conta['vence_em']) {
            $dias = (int) floor((strtotime((string) $conta['vence_em']) - time()) / 86400);
        }
        $cat = Base::linha("select max(ultima_em) as em from play.tarefa where nome like 'catalogo%' and status is distinct from 'erro'");
        $tv = $p->ctx->tv;
        return [
            'tv'               => $tv ? ['id' => (int) $tv['id'], 'nome' => $tv['nome'], 'status' => $tv['status'], 'perfil' => $tv['perfil_id'] !== null ? (int) $tv['perfil_id'] : null] : null,
            'perfil'           => $perfil,
            'conta'            => ['vence_em' => Base::iso($conta['vence_em'] ?? null), 'dias' => $dias, 'status' => $conta['status'] ?? null, 'ativa' => (bool) $conta],
            'catalogo'         => ['versao' => $cat['em'] ? strtotime((string) $cat['em']) : null, 'atualizado' => Base::iso($cat['em'] ?? null)],
            'avisos_pendentes' => (int) Base::valor("select count(*) from play.aviso where (perfil_id = ? or perfil_id is null) and status = 'ativo'", [$perfil]),
            'servidor'         => ['versao' => self::VERSAO, 'hora' => date(DATE_ATOM)],
        ];
    }

    /** GET conta: a TV guarda cópia para montar links se o homeserver cair. */
    public static function conta(Pedido $p): array
    {
        // Só TV aprovada (a barreira do token já recusa pendente/bloqueada) ou administrador do painel.
        if (!($p->ctx->tv && ($p->ctx->tv['status'] ?? '') === 'ativa') && !$p->ctx->ehAdmin()) {
            throw new Erro('só a TV aprovada ou um administrador', 403);
        }
        $c = Base::conta() ?? throw new Erro('nenhuma lista IPTV ativa no painel', 404);
        return [
            'servidor' => rtrim((string) $c['servidor'], '/'),
            'usuario'  => $c['usuario'],
            'senha'    => $c['senha'],
            'formato'  => [
                'filme' => '{s}/movie/{u}/{p}/{id}.{ext}',
                'serie' => '{s}/series/{u}/{p}/{id}.{ext}',
                'canal' => '{s}/live/{u}/{p}/{id}.m3u8',
            ],
            'vence_em' => Base::iso($c['vence_em']),
        ];
    }

    // -------------------------------------------------------------- perfis

    public static function perfis(Pedido $p): array
    {
        return array_map(fn($x) => self::perfil($x), Base::linhas('select id, nome, cor from play.perfil order by id'));
    }

    public static function perfil(array $x): array
    {
        return ['id' => (int) $x['id'], 'nome' => $x['nome'], 'cor' => $x['cor'], 'inicial' => mb_strtoupper(mb_substr(trim((string) $x['nome']), 0, 1))];
    }

    /** POST perfis {nome, cor}. */
    public static function criarPerfil(Pedido $p): array
    {
        [$nome, $cor] = self::validarPerfil($p->str('nome'), $p->str('cor', '#1E1B4B'));
        if ((int) Base::valor('select count(*) from play.perfil') >= 12) {
            throw new Erro('limite de 12 perfis', 422);
        }
        $l = Base::linha('insert into play.perfil (nome, cor) values (?, ?) returning id, nome, cor', [$nome, $cor]);
        return self::perfil($l);
    }

    /** @return array{0:string,1:string} nome e cor válidos */
    public static function validarPerfil(string $nome, string $cor): array
    {
        $nome = trim(preg_replace('/\s+/u', ' ', $nome) ?? '');
        if ($nome === '' || mb_strlen($nome) > 20) {
            throw new Erro('nome do perfil: de 1 a 20 caracteres', 422);
        }
        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $cor)) {
            throw new Erro('cor no formato #RRGGBB', 422);
        }
        return [$nome, strtoupper($cor)];
    }

    // --------------------------------------------------------- integrações

    /**
     * GET minhas-series?perfil= — Home "WebOS TV" (formato do helper /series antigo):
     * [{tmdb, title, season, episode, xs, new, poster}].
     */
    public static function minhasSeries(Pedido $p): array
    {
        $perfil = $p->perfil();
        $novos = array_column(Inicio::episodiosNovos($perfil, 7), null, 'id');
        return array_map(fn($x) => [
            'tmdb'    => Base::inteiroOuNulo($x['tmdb_id']),
            'title'   => $x['nome'],
            'season'  => Base::inteiroOuNulo($x['temporada']),
            'episode' => Base::inteiroOuNulo($x['episodio']),
            'xs'      => Base::inteiroOuNulo($x['xs']),
            'new'     => isset($novos[(int) $x['id']]),
            'poster'  => Base::imgAbsoluta($x['poster'], 'w342'),
            'id'      => (int) $x['id'],
        ], Base::linhas("
            select t.id, t.tmdb_id, t.nome, t.poster, pr.temporada, pr.episodio,
                   coalesce(pr.versao_id, (select v.painel_id from play.versao v
                                            where v.titulo_id = t.id and v.tipo = 's' and v.ativo and v.conta_id = ?
                                            order by (v.idioma = 'dub') desc, v.painel_id limit 1)) as xs
              from play.serie_seguida s
              join play.titulo t on t.id = s.titulo_id and t.ativo
              left join play.progresso pr on pr.perfil_id = s.perfil_id and pr.titulo_id = s.titulo_id
             where s.perfil_id = ?
             order by coalesce(pr.atualizado_em, s.desde) desc
             limit 50", [Base::contaId(), $perfil]));
    }

    /**
     * POST continuar {tv:"tv-1", perfil?} — Siri/Atalhos "Continuar na TV da sala":
     * liga a TV (WoL), espera ela subir e abre o Player com {"abrir":"continuar"}.
     */
    public static function continuar(Pedido $p): array
    {
        $slug = $p->str('tv', 'tv-1');
        $d = Dispositivos::porSlug($slug) ?? throw new Erro("aparelho '$slug' não existe", 404);
        if ($d['tipo'] !== 'tv') {
            throw new Erro("'$slug' não é uma TV", 422);
        }
        $perfil = $p->int('perfil');
        if ($perfil === null) {
            $padrao = Base::valor('select perfil_id from play.tv where dispositivo_slug = ? and status = ? order by visto_em desc nulls last limit 1', [$slug, 'ativa']);
            $perfil = $padrao !== null ? (int) $padrao : null;
        }
        $origem = $p->ctx->usuario ? 'painel:' . $p->ctx->usuario['login'] : 'siri';
        @set_time_limit(90);

        $ligou = false;
        if (!(($d['dados']['ligado'] ?? false) && $d['online']) || !self::portaAberta((string) ($d['config']['ip'] ?? ''))) {
            Comandos::aguardar(Comandos::enfileirar($d['id'], 'ligar', [], $origem), 12);
            $ligou = true;
            // Espera a TV responder no SSAP (webOS leva uns 10-25 s depois do WoL).
            $fim = microtime(true) + 35;
            while (microtime(true) < $fim) {
                $d = Dispositivos::porSlug($slug) ?? $d;   // o IP pode ter sido corrigido enquanto isso
                if (self::portaAberta((string) ($d['config']['ip'] ?? ''))) {
                    sleep(4);   // SSAP aceita conexão antes de conseguir abrir apps
                    break;
                }
                usleep(1_500_000);
            }
        }
        $params = ['abrir' => 'continuar'] + ($perfil !== null ? ['perfil' => $perfil] : []);
        $c = ['status' => 'erro'];
        for ($tentativa = 0; $tentativa < 3 && $c['status'] !== 'ok'; $tentativa++) {
            if ($tentativa > 0) {
                sleep(4);
            }
            $c = Comandos::aguardar(Comandos::enfileirar($d['id'], 'abrir_app', ['app' => self::APP_ID, 'params' => $params], $origem), 15);
        }
        $ok = $c['status'] === 'ok';
        if (!$ok) {
            $p->status = 502;
        }
        return ['ok' => $ok, 'tv' => $slug, 'ligou' => $ligou, 'perfil' => $perfil,
                'erro' => $ok ? null : ($c['resposta']['erro'] ?? 'a TV não respondeu')];
    }

    private static function portaAberta(string $ip): bool
    {
        if ($ip === '') {
            return false;
        }
        foreach ([3001, 3000] as $porta) {
            $s = @fsockopen($ip, $porta, $e, $m, 0.8);
            if ($s) {
                fclose($s);
                return true;
            }
        }
        return false;
    }
}
