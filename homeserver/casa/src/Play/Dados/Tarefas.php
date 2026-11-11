<?php
declare(strict_types=1);

namespace Casa\Play\Dados;

use Casa\Db;
use PDOException;
use Throwable;

/**
 * Agenda das tarefas do casa-play. Cada execução fica em play.tarefa (última, duração, status,
 * mensagem, próxima). O painel força uma tarefa com forcar=true + NOTIFY play_tarefa (`forcar()`).
 *
 * Uma tarefa por vez. As longas (catálogo, tmdb, links) trabalham em lotes e chamam `ceder()` entre
 * eles: as curtas que venceram (avisos, jogos) rodam ali no meio. Uma tarefa devolve
 * `['msg' => ..., 'proxima' => timestamp|null]`; null = a agenda padrão abaixo.
 */
final class Tarefas
{
    public const CANAL = 'play_tarefa';

    /** nome => [classe, método, agenda] — agenda: segundos (intervalo) ou 'HH:MM' (diária). */
    public const LISTA = [
        'conta'         => [Xtream::class, 'tarefaConta', 1800],
        'catalogo'      => [Catalogo::class, 'tarefa', 21600],
        'tmdb'          => [Tmdb::class, 'tarefa', 1800],
        'top10'         => [Top10::class, 'tarefa', 3600],
        'jogos'         => [Jogos::class, 'tarefa', '06:00'],
        'epg'           => [Epg::class, 'tarefa', 14400],
        'links'         => [Links::class, 'tarefa', '02:00'],
        'legendas'      => [Legendas::class, 'tarefa', 1800],
        'seguidas'      => [Seguidas::class, 'tarefa', '07:00'],
        'recomendacoes' => [Recomendador::class, 'tarefa', 600],
        'generos'       => [Generos::class, 'tarefa', 86400],     // gêneros do JustWatch (catálogo todo, depois só os novos)
        'trakt'         => [Trakt::class, 'tarefa', 900],         // relacionados do Trakt (séries; filmes fora do MovieLens)
        'anilist'       => [AniList::class, 'tarefa', 900],       // recomendações de anime da comunidade do AniList
        'clima'         => [Clima::class, 'tarefa', 1800],        // clima da Home das TVs (CLIMA_CIDADE/LAT/LON no .env)
        'avisos'        => [Avisos::class, 'tarefa', 30],
        'limpeza'       => [Limpeza::class, 'tarefa', '04:30'],
    ];

    /** Rodam no meio das longas (ceder). */
    public const CURTAS = ['avisos', 'jogos'];

    /**
     * Forçadas que também rodam no meio das longas (ceder), só quando pedidas: "Gostei"/"Não gostei" na TV
     * pede o recálculo das recomendações e o destaque muda em segundos, mesmo com o Trakt/catálogo rodando.
     */
    public const URGENTES = ['recomendacoes'];

    /** Ordem da primeira vez (banco novo): conta e catálogo antes do resto. */
    private const PRIMEIRA = ['conta' => -360, 'catalogo' => -300, 'top10' => -240, 'jogos' => -180, 'tmdb' => -120];

    /** O worker liga para pedir parada (SIGTERM): as longas param no próximo lote. */
    public static ?\Closure $parar = null;

    private static ?string $rodando = null;
    private static bool $cedendo = false;
    private static bool $forcada = false;

    public static function existe(string $nome): bool
    {
        return isset(self::LISTA[$nome]);
    }

    /** Painel/CLI: "rodar agora". */
    public static function forcar(string $nome): void
    {
        if (!self::existe($nome)) {
            throw new \InvalidArgumentException("tarefa desconhecida: $nome");
        }
        $pdo = Db::pdo();
        $pdo->prepare("insert into play.tarefa (nome, forcar, proxima_em) values (?, true, now())
                       on conflict (nome) do update set forcar = true")->execute([$nome]);
        $pdo->prepare('select pg_notify(?, ?)')->execute([self::CANAL, $nome]);
    }

    /** Linhas de todas as tarefas; "rodando" que sobrou de um worker que caiu volta a valer. */
    public static function garantir(): void
    {
        $pdo = Db::pdo();
        $st = $pdo->prepare("insert into play.tarefa (nome, proxima_em) values (?, now() + make_interval(secs => ?)) on conflict (nome) do nothing");
        foreach (array_keys(self::LISTA) as $nome) {
            $st->execute([$nome, self::PRIMEIRA[$nome] ?? 0]);
        }
        $pdo->exec("update play.tarefa set status = 'erro', mensagem = 'worker reiniciado no meio', proxima_em = least(coalesce(proxima_em, now()), now())
                     where status = 'rodando'");
    }

    /** A vencida mais antiga (forçadas primeiro). */
    public static function proximaVencida(?array $so = null): ?string
    {
        $sql = "select nome from play.tarefa where (forcar or proxima_em <= now())";
        $par = [];
        if ($so !== null) {
            $sql .= ' and nome = any(cast(? as text[]))';
            $par[] = '{' . implode(',', $so) . '}';
        }
        $st = Db::pdo()->prepare($sql . ' order by forcar desc, proxima_em nulls first limit 20');
        $st->execute($par);
        foreach ($st->fetchAll(\PDO::FETCH_COLUMN) as $nome) {
            if (self::existe($nome) && $nome !== self::$rodando) {
                return $nome;
            }
        }
        return null;
    }

    /** Roda uma tarefa e registra. Erro de banco sobe (o worker reconecta). */
    public static function executar(string $nome, ?bool $forcada = null): array
    {
        [$classe, $metodo, $agenda] = self::LISTA[$nome];
        $pdo = Db::pdo();
        if ($forcada === null) {
            $st = $pdo->prepare('select forcar from play.tarefa where nome = ?');
            $st->execute([$nome]);
            $forcada = Banco::bool($st->fetchColumn());
        }
        $forcadaAntes = self::$forcada;
        self::$forcada = $forcada;
        $pdo->prepare("update play.tarefa set status = 'rodando', forcar = false, ultima_em = now() where nome = ?")->execute([$nome]);
        $anterior = self::$rodando;
        self::$rodando = $nome;
        $ini = microtime(true);
        try {
            $r = $classe::$metodo();
            $msg = (string) ($r['msg'] ?? 'ok');
            $proxima = $r['proxima'] ?? self::agenda($agenda);
            $status = 'ok';
        } catch (PDOException $e) {
            // Conexão caiu (classe 08, servidor desligando 57P0x): o laço do worker reconecta.
            // Erro de SQL vira erro da tarefa — relançar faria o worker repetir a tarefa sem pausa.
            $estado = (string) ($e->errorInfo[0] ?? $e->getCode());
            try {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
            } catch (Throwable) {
            }
            if (str_starts_with($estado, '08') || str_starts_with($estado, '57P') || !self::conectado($pdo)) {
                self::$rodando = $anterior;
                self::$forcada = $forcadaAntes;
                throw $e;
            }
            $msg = Http::mascarar($e->getMessage());
            $proxima = time() + (in_array($nome, self::CURTAS, true) ? 60 : 600);
            $status = 'erro';
        } catch (Throwable $e) {
            $msg = Http::mascarar($e->getMessage());
            // erro: tenta de novo mais cedo, sem martelar (avisos/jogos em 1 min, o resto em 10 min)
            $proxima = time() + (in_array($nome, self::CURTAS, true) ? 60 : 600);
            $status = 'erro';
        }
        self::$rodando = $anterior;
        self::$forcada = $forcadaAntes;
        $ms = (int) round((microtime(true) - $ini) * 1000);
        $pdo->prepare("update play.tarefa set status = ?, mensagem = ?, duracao_ms = ?, proxima_em = to_timestamp(?) where nome = ?")
            ->execute([$status, mb_substr($msg, 0, 500), $ms, (int) $proxima, $nome]);
        if ($nome !== 'avisos' || $status !== 'ok' || $ms > 2000) {
            Log::info(sprintf('%-4s %s (%s): %s', $status === 'ok' ? 'ok' : 'ERRO', $nome, self::duracao($ms), $msg));
        }
        return ['status' => $status, 'msg' => $msg, 'ms' => $ms];
    }

    private static function conectado(\PDO $pdo): bool
    {
        try {
            $pdo->query('select 1');
            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Chamado pelas tarefas longas entre lotes: roda as curtas que venceram e diz se é para parar
     * (SIGTERM). Nunca dentro de uma curta nem de outro ceder().
     */
    public static function ceder(): bool
    {
        if (self::parar()) {
            return true;
        }
        if (self::$cedendo || in_array(self::$rodando, self::CURTAS, true)) {
            return false;
        }
        self::$cedendo = true;
        try {
            for ($i = 0; $i < 3 && ($nome = self::proximaVencida(self::CURTAS)); $i++) {
                self::executar($nome);
            }
            foreach (self::URGENTES as $nome) {
                if ($nome !== self::$rodando && !self::parar() && self::pedida($nome)) {
                    self::executar($nome, true);
                }
            }
        } finally {
            self::$cedendo = false;
        }
        return self::parar();
    }

    /** Alguém pediu esta tarefa agora (forcar = true)? */
    private static function pedida(string $nome): bool
    {
        $st = Db::pdo()->prepare('select forcar from play.tarefa where nome = ?');
        $st->execute([$nome]);
        return Banco::bool($st->fetchColumn());
    }

    /** A tarefa em execução foi pedida pelo painel/CLI ("rodar agora")? */
    public static function forcada(): bool
    {
        return self::$forcada;
    }

    public static function parar(): bool
    {
        return self::$parar !== null && (self::$parar)();
    }

    /** Próximo horário pela agenda (intervalo em segundos ou 'HH:MM' diário). */
    public static function agenda(int|string $agenda, ?int $agora = null): int
    {
        $agora ??= time();
        if (is_int($agenda)) {
            return $agora + $agenda;
        }
        return self::proximoHorario($agenda, $agora);
    }

    /** Próxima ocorrência de 'HH:MM' (hoje, se ainda não passou; senão amanhã). */
    public static function proximoHorario(string $hhmm, ?int $agora = null): int
    {
        $agora ??= time();
        [$h, $m] = array_map('intval', explode(':', $hhmm));
        $t = mktime($h, $m, 0, (int) date('n', $agora), (int) date('j', $agora), (int) date('Y', $agora));
        return $t > $agora ? $t : mktime($h, $m, 0, (int) date('n', $agora), (int) date('j', $agora) + 1, (int) date('Y', $agora));
    }

    /** Para status/CLI. */
    public static function listar(): array
    {
        return Db::pdo()->query("select nome, ultima_em, duracao_ms, status, mensagem, proxima_em, forcar from play.tarefa order by proxima_em nulls first")->fetchAll();
    }

    private static function duracao(int $ms): string
    {
        return $ms < 1000 ? "$ms ms" : ($ms < 120000 ? round($ms / 1000, 1) . ' s' : round($ms / 60000, 1) . ' min');
    }
}
