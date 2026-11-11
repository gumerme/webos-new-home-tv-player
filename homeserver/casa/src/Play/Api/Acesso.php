<?php
declare(strict_types=1);

namespace Casa\Play\Api;

use Casa\Auth;
use Casa\Dispositivos;
use Casa\Eventos;

/**
 * Autenticação das TVs (token próprio, sha256 em play.tv.token_hash), do painel (sessão) e das integrações
 * (CASA_TOKEN). A cada chamada de TV guarda o IP de onde ela veio e mantém o aparelho do painel com o IP certo.
 */
final class Acesso
{
    /** Letras e números sem os parecidos (0/O, 1/I/L) para o código mostrado na TV. */
    private const ALFABETO = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    public static function autenticar(string $acesso, string $metodo): Contexto
    {
        if ($acesso === 'publico') {
            return new Contexto();
        }
        $token = self::token($_SERVER, $_GET);
        if ($token !== null) {
            $casa = (string) getenv('CASA_TOKEN');
            if ($casa !== '' && hash_equals($casa, $token)) {
                return new Contexto(integracao: true);
            }
            if ($acesso === 'integracao') {
                throw new Erro('não autorizado', 401);
            }
            return new Contexto(tv: self::tvPorToken($token));
        }
        if (Auth::temCookie()) {
            Auth::iniciarSessao();
            $u = Auth::usuario();
            if ($u && !$u['trocar_senha']) {
                if ($metodo !== 'GET') {
                    Auth::validarCsrf($_SERVER['HTTP_X_CSRF'] ?? null);
                }
                session_write_close();
                return new Contexto(usuario: $u);
            }
        }
        throw new Erro('não autorizado', 401);
    }

    /** Token da chamada: "Authorization: Bearer x" ou ?t=x (EventSource e <img> não mandam cabeçalho). */
    public static function token(array $server, array $get): ?string
    {
        $cab = (string) ($server['HTTP_AUTHORIZATION'] ?? $server['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
        if ($cab === '' && function_exists('getallheaders') && PHP_SAPI !== 'cli') {
            $cab = (string) (getallheaders()['Authorization'] ?? '');
        }
        if (preg_match('/^Bearer\s+(\S{8,200})$/i', trim($cab), $m)) {
            return $m[1];
        }
        $t = $get['t'] ?? null;
        return is_string($t) && preg_match('/^\S{8,200}$/', $t) ? $t : null;
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function novoToken(): string
    {
        return bin2hex(random_bytes(24));
    }

    public static function novoCodigo(): string
    {
        $c = '';
        for ($i = 0; $i < 4; $i++) {
            $c .= self::ALFABETO[random_int(0, strlen(self::ALFABETO) - 1)];
        }
        return $c;
    }

    /** Situação da TV -> erro (ou null se pode usar). Separado para os testes. */
    public static function barreira(array $tv): ?Erro
    {
        return match ($tv['status'] ?? '') {
            // sem aprovação desde 08/11/2026: "pendente" (linha antiga) vale como ativa
            'ativa', 'pendente' => null,
            'bloqueada' => new Erro('TV bloqueada no painel', 403, ['codigo' => (string) $tv['codigo'], 'bloqueada' => true]),
            default     => new Erro('não autorizado', 401),
        };
    }

    private static function tvPorToken(string $token): array
    {
        $tv = Base::linha('select id, nome, status, codigo, perfil_id, dispositivo_slug, ip, assistindo, versao_app
                             from play.tv where token_hash = ?', [self::hash($token)]);
        if (!$tv) {
            throw new Erro('token inválido: registre a TV de novo', 401, ['registrar' => true]);
        }
        $tv['id'] = (int) $tv['id'];
        $tv['assistindo'] = $tv['assistindo'] !== null ? Base::json($tv['assistindo']) : null;
        self::visto($tv, self::ip());
        $erro = self::barreira($tv);
        if ($erro) {
            throw $erro;
        }
        return $tv;
    }

    public static function ip(): string
    {
        return (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    }

    /** IPv4 privado de casa (só esses corrigem o IP do aparelho). */
    public static function ipDeCasa(string $ip): bool
    {
        return (bool) preg_match('/^(10\.\d{1,3}|192\.168|172\.(1[6-9]|2\d|3[01]))\.\d{1,3}\.\d{1,3}$/', $ip);
    }

    /**
     * Atualiza ip/visto_em (no máximo a cada 30 s, ou na hora se o IP mudou) e, se a TV está ligada a um
     * aparelho do painel, corrige o config.ip dele.
     */
    private static function visto(array $tv, string $ip): void
    {
        if ($ip === '') {
            return;
        }
        Base::exec("update play.tv set ip = ?, visto_em = now()
                     where id = ? and (ip is distinct from ? or visto_em is null or visto_em < now() - interval '30 seconds')",
            [$ip, $tv['id'], $ip]);
        if ($ip === $tv['ip']) {
            return;
        }
        if (!empty($tv['dispositivo_slug'])) {
            self::corrigirAparelho((string) $tv['dispositivo_slug'], $ip, (string) $tv['nome']);
        } elseif ($slug = self::acharAparelho($ip)) {
            // Ainda sem aparelho: tenta de novo a cada troca de IP (a varredura da rede pode ter chegado depois).
            Base::exec('update play.tv set dispositivo_slug = ? where id = ? and dispositivo_slug is null', [$slug, $tv['id']]);
        }
    }

    /** O IP da chamada é o IP atual da TV: o aparelho do painel (SSAP, WoL, avisos) passa a usar esse. */
    public static function corrigirAparelho(string $slug, string $ip, string $nomeTv = ''): void
    {
        if (!self::ipDeCasa($ip)) {
            return;
        }
        try {
            $d = Base::linha("select id, nome, config->>'ip' as ip from dispositivo where slug = ?", [$slug]);
            if ($d && $d['ip'] !== $ip) {
                Dispositivos::mesclarConfig((int) $d['id'], ['ip' => $ip]);
                Eventos::registrar('aparelho', 'info', "{$d['nome']}: IP mudou para $ip",
                    'antes: ' . ($d['ip'] ?: 'sem IP') . ' · visto pelo Player' . ($nomeTv !== '' ? " ($nomeTv)" : ''), 'play');
            }
        } catch (\Throwable $e) {
            error_log('play: IP do aparelho não corrigido: ' . $e->getMessage());
        }
    }

    /**
     * Aparelho do painel (tv-1, tv-2) desta TV: IP da chamada -> MAC na rede -> dispositivo com esse MAC.
     * Sem MAC conhecido, aceita o aparelho cujo config.ip é o próprio IP.
     */
    public static function acharAparelho(string $ip): ?string
    {
        if (!self::ipDeCasa($ip)) {
            return null;
        }
        try {
            $slug = Base::valor("select d.slug
                                   from rede_dispositivo r
                                   join dispositivo d on lower(d.config->>'mac') = r.mac
                                  where r.ip = ? and d.tipo = 'tv'
                                  order by r.ultimo_visto desc limit 1", [$ip]);
            $slug ??= Base::valor("select slug from dispositivo where tipo = 'tv' and config->>'ip' = ? order by id limit 1", [$ip]);
            return $slug !== null ? (string) $slug : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
