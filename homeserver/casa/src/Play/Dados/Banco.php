<?php
declare(strict_types=1);

namespace Casa\Play\Dados;

use Casa\Db;

/** Pequenos utilitários de banco usados pelas tarefas (JSON em lote, arrays do Postgres, cache). */
final class Banco
{
    /** Testes de fumaça sem Postgres: o cache vira no-op. */
    public static bool $semBanco = false;

    /** Boolean do Postgres em qualquer forma que o driver devolva. */
    public static function bool(mixed $v): bool
    {
        return $v === true || $v === 't' || $v === 'true' || $v === 1 || $v === '1';
    }

    public static function json(mixed $v): string
    {
        return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    /** Lista de inteiros como literal int[] ('{1,2,3}'). */
    public static function ints(array $l): string
    {
        return '{' . implode(',', array_map('intval', $l)) . '}';
    }

    /** int[] do Postgres ('{1,2}') para lista PHP. */
    public static function lerInts(mixed $v): array
    {
        if (is_array($v)) {
            return array_map('intval', $v);
        }
        $v = trim((string) $v, '{}');
        return $v === '' ? [] : array_map('intval', explode(',', $v));
    }

    /** Cache genérico (play.cache, migração 008): respostas TMDB/JustWatch do recomendador. */
    public static function cache(string $chave, int $ttl): mixed
    {
        if (self::$semBanco) {
            return null;
        }
        $st = Db::pdo()->prepare('select dados from play.cache where chave = ? and em > now() - make_interval(secs => ?)');
        $st->execute([$chave, $ttl]);
        $v = $st->fetchColumn();
        return $v === false ? null : json_decode((string) $v, true);
    }

    public static function guardar(string $chave, mixed $dados): void
    {
        if (self::$semBanco) {
            return;
        }
        Db::pdo()->prepare('insert into play.cache (chave, dados, em) values (?, cast(? as jsonb), now())
                            on conflict (chave) do update set dados = excluded.dados, em = now()')
            ->execute([$chave, self::json($dados)]);
    }

    /** Cache com fallback: devolve o guardado (até `ttl`) ou chama `$buscar` e guarda o resultado. */
    public static function lembrar(string $chave, int $ttl, callable $buscar): mixed
    {
        $v = self::cache($chave, $ttl);
        if ($v !== null) {
            return $v;
        }
        $v = $buscar();
        if ($v !== null) {
            self::guardar($chave, $v);
        }
        return $v;
    }

    /** Pasta do cache em disco (casa/var/play/<sub>), criada se preciso. */
    public static function pasta(string $sub): string
    {
        $p = dirname(__DIR__, 3) . '/var/play/' . $sub;
        if (!is_dir($p)) {
            // o casa-play roda como root e o casa-web como www-data: os dois precisam gravar aqui
            @mkdir($p, 0777, true);
            @chmod($p, 0777);
            @chmod(dirname($p), 0777);
        }
        return $p;
    }
}
