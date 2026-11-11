<?php
declare(strict_types=1);

namespace Casa\Play\Api;

/**
 * GET sinal?t=<token>&perfil=<id> — Server-Sent Events.
 * Conexão PDO própria com LISTEN play_sinal; a cada NOTIFY lê play.sinal a partir do último id enviado,
 * filtrando por TV, perfil e exceto_tv. "ping" a cada 20 s; fecha em ~80 s (o EventSource reconecta e
 * manda Last-Event-ID, então nada se perde no meio).
 */
final class Sinal
{
    private const DURACAO = 80;
    private const PING = 20;

    public static function rota(Pedido $p): void
    {
        $perfil = $p->perfil();
        $tv = $p->ctx->tvId() ?? 0;
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        @set_time_limit(self::DURACAO + 20);
        ignore_user_abort(false);

        $pdo = self::conectar();
        $pdo->exec('listen play_sinal');
        $ultimo = self::inicio($pdo, (string) ($_SERVER['HTTP_LAST_EVENT_ID'] ?? $p->str('desde')));

        header('Content-Type: text/event-stream; charset=utf-8');
        header('Cache-Control: no-cache, no-store');
        header('X-Accel-Buffering: no');
        header('Connection: keep-alive');
        @ini_set('zlib.output_compression', '0');
        @ini_set('output_buffering', '0');
        @ini_set('implicit_flush', '1');
        while (ob_get_level() > 0) {
            ob_end_flush();
        }
        ob_implicit_flush(true);

        echo "retry: 3000\n";
        // "tv" = nome da TV (igual a estado.tv.nome e ao "tv" do sinal progresso: a TV ignora o próprio progresso).
        self::evento('ping', ['ola' => true, 'tv' => $p->ctx->tvNome(), 'tv_id' => $tv ?: null, 'perfil' => $perfil]);

        $busca = $pdo->prepare('select id, evento, dados from play.sinal
                                 where id > ? and (tv_id is null or tv_id = ?) and (perfil_id is null or perfil_id = ?)
                                   and (exceto_tv is null or exceto_tv <> ?)
                                 order by id limit 50');
        $fim = time() + self::DURACAO;
        $proxPing = time() + self::PING;
        $ultimo = self::enviarNovos($busca, $ultimo, $tv, $perfil);   // o que chegou entre a reconexão e o LISTEN
        while (time() < $fim && !connection_aborted()) {
            $espera = max(1, min($proxPing, $fim) - time()) * 1000;
            $n = self::notificacao($pdo, $espera);
            if ($n) {
                // Junta notificações em rajada antes de consultar.
                while (self::notificacao($pdo, 0)) {
                }
                $ultimo = self::enviarNovos($busca, $ultimo, $tv, $perfil);
            }
            if (time() >= $proxPing) {
                self::evento('ping', ['hora' => date(DATE_ATOM)]);
                $proxPing = time() + self::PING;
            }
        }
        $pdo = null;
    }

    /** Ponto de partida: Last-Event-ID (até 10 min atrás) ou o último sinal existente. */
    private static function inicio(\PDO $pdo, string $ultimoVisto): int
    {
        $max = (int) $pdo->query('select coalesce(max(id), 0) from play.sinal')->fetchColumn();
        if (preg_match('/^\d{1,18}$/', $ultimoVisto)) {
            $st = $pdo->prepare("select coalesce(min(id) - 1, ?) from play.sinal where criado_em > now() - interval '10 minutes'");
            $st->execute([$max]);
            $limite = (int) $st->fetchColumn();
            return min($max, max((int) $ultimoVisto, $limite));
        }
        return $max;
    }

    private static function enviarNovos(\PDOStatement $busca, int $ultimo, int $tv, int $perfil): int
    {
        do {
            $busca->execute([$ultimo, $tv, $perfil, $tv]);
            $linhas = $busca->fetchAll(\PDO::FETCH_ASSOC);
            foreach ($linhas as $l) {
                $ultimo = (int) $l['id'];
                self::evento((string) $l['evento'], Base::json($l['dados']), $ultimo);
            }
        } while (count($linhas) === 50);
        return $ultimo;
    }

    /** Formato SSE (puro, usado nos testes). */
    public static function formatar(string $evento, array $dados, ?int $id = null): string
    {
        $evento = preg_replace('/[^a-z0-9_-]/i', '', $evento) ?: 'mensagem';
        return ($id !== null ? "id: $id\n" : '') . "event: $evento\ndata: "
            . json_encode($dados ?: new \stdClass(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . "\n\n";
    }

    private static function evento(string $evento, array $dados, ?int $id = null): void
    {
        echo self::formatar($evento, $dados, $id);
        @flush();
    }

    /** Pdo\Pgsql::getNotify (8.4) ou PDO::pgsqlGetNotify (antigo). */
    private static function notificacao(\PDO $pdo, int $ms): mixed
    {
        return method_exists($pdo, 'getNotify')
            ? $pdo->getNotify(\PDO::FETCH_ASSOC, $ms)
            : $pdo->pgsqlGetNotify(\PDO::FETCH_ASSOC, $ms);
    }

    /** Conexão só desta stream (o LISTEN não pode ficar na conexão compartilhada). */
    private static function conectar(): \PDO
    {
        $dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s',
            getenv('DB_HOST') ?: '127.0.0.1', getenv('DB_PORT') ?: '5432', getenv('DB_NAME') ?: 'casa');
        $op = [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_EMULATE_PREPARES => false];
        $user = getenv('DB_USER') ?: 'casa';
        $pass = getenv('DB_PASS') ?: '';
        return method_exists(\PDO::class, 'connect') ? \PDO::connect($dsn, $user, $pass, $op) : new \PDO($dsn, $user, $pass, $op);
    }
}
