<?php
declare(strict_types=1);

namespace Casa\Play\Dados;

use Casa\Db;
use PDO;

/**
 * Índice em memória dos títulos ativos (porte do buildIndex/findInList da v10): casa nomes do
 * JustWatch/TMDB/avisos com a lista. Nome normalizado exato; senão nomes que começam com ele
 * (ou ele começando com um nome da lista), sempre com ano ±1 quando há ano.
 */
final class Indice
{
    /** @var array{m:array<string,list<array{0:int,1:int}>>, s:array<string,list<array{0:int,1:int}>>} */
    private array $porNome = ['m' => [], 's' => []];
    /** @var array{m:list<string>, s:list<string>} */
    private array $nomes = ['m' => [], 's' => []];
    /** @var array{m:array<int,int>, s:array<int,int>} tmdb => id */
    private array $porTmdb = ['m' => [], 's' => []];
    /** @var array<int,array{0:string,1:string,2:int,3:?int}> id => [tipo, nome, ano, tmdb] */
    private array $info = [];
    private array $memo = [];

    private static ?self $compartilhado = null;
    private static int $carregadoEm = 0;

    /** Índice reaproveitado por 10 min (o catálogo invalida). */
    public static function compartilhado(): self
    {
        if (self::$compartilhado === null || time() - self::$carregadoEm > 600) {
            self::$compartilhado = self::doBanco();
            self::$carregadoEm = time();
        }
        return self::$compartilhado;
    }

    public static function invalidar(): void
    {
        self::$compartilhado = null;
    }

    public static function doBanco(): self
    {
        $i = new self();
        $st = Db::pdo()->query('select id, tipo, nome, nome_norm, coalesce(ano, 0), tmdb_id from play.titulo where ativo order by id');
        while ($l = $st->fetch(PDO::FETCH_NUM)) {
            $i->adicionar((int) $l[0], (string) $l[1], (string) $l[2], (string) $l[3], (int) $l[4], $l[5] !== null ? (int) $l[5] : null);
        }
        $i->fechar();
        return $i;
    }

    /** Para testes: [[id, tipo, nome, ano, tmdb], ...]. */
    public static function deLista(array $linhas): self
    {
        $i = new self();
        foreach ($linhas as $l) {
            $i->adicionar((int) $l[0], $l[1], $l[2], Texto::norm($l[2]), (int) ($l[3] ?? 0), isset($l[4]) ? (int) $l[4] : null);
        }
        $i->fechar();
        return $i;
    }

    private function adicionar(int $id, string $tipo, string $nome, string $k, int $ano, ?int $tmdb): void
    {
        $tipo = $tipo === 's' ? 's' : 'm';
        $this->info[$id] = [$tipo, $nome, $ano, $tmdb];
        if ($k !== '') {
            $this->porNome[$tipo][$k][] = [$id, $ano];
        }
        if ($tmdb && !isset($this->porTmdb[$tipo][$tmdb])) {
            $this->porTmdb[$tipo][$tmdb] = $id;
        }
    }

    private function fechar(): void
    {
        foreach (['m', 's'] as $t) {
            $this->nomes[$t] = array_map('strval', array_keys($this->porNome[$t]));
            sort($this->nomes[$t], SORT_STRING);
        }
    }

    public function total(): int
    {
        return count($this->info);
    }

    public function nome(int $id): ?string
    {
        return $this->info[$id][1] ?? null;
    }

    public function ano(int $id): int
    {
        return $this->info[$id][2] ?? 0;
    }

    public function tmdbDe(int $id): ?int
    {
        return $this->info[$id][3] ?? null;
    }

    public function tipoDe(int $id): ?string
    {
        return $this->info[$id][0] ?? null;
    }

    public function porTmdb(string $tipo, ?int $tmdb): ?int
    {
        return $tmdb ? ($this->porTmdb[$tipo === 's' ? 's' : 'm'][$tmdb] ?? null) : null;
    }

    /** Liga um tmdb a um título (o casamento achou por nome). */
    public function lembrarTmdb(string $tipo, int $tmdb, int $id): void
    {
        $this->porTmdb[$tipo === 's' ? 's' : 'm'][$tmdb] ??= $id;
    }

    /** Melhor título da lista para um nome (findInList da v10). */
    public function achar(string $tipo, ?string $nome, int $ano = 0): ?int
    {
        $tipo = $tipo === 's' ? 's' : 'm';
        $k = Texto::norm($nome);
        if ($k === '') {
            return null;
        }
        $mk = "$tipo|$k|$ano";
        if (array_key_exists($mk, $this->memo)) {
            return $this->memo[$mk];
        }
        $idx = $this->porNome[$tipo];
        $escolher = function (?array $itens) use ($ano): ?int {
            if (!$itens) {
                return null;
            }
            if (!$ano) {
                return $itens[0][0];
            }
            foreach ($itens as [$id, $a]) {
                if (!$a || abs($a - $ano) <= 1) {
                    return $id;
                }
            }
            return null;
        };
        $hit = $escolher($idx[$k] ?? null);
        if ($hit === null && strlen($k) >= 6) {
            // nomes da lista que começam com o nome + ' '
            $lista = $this->nomes[$tipo];
            $pre = $k . ' ';
            $lo = 0;
            $hi = count($lista);
            while ($lo < $hi) {
                $mid = ($lo + $hi) >> 1;
                if (strcmp($lista[$mid], $pre) < 0) {
                    $lo = $mid + 1;
                } else {
                    $hi = $mid;
                }
            }
            for ($i = $lo, $n = count($lista); $hit === null && $i < $n && str_starts_with($lista[$i], $pre); $i++) {
                $hit = $escolher($idx[$lista[$i]]);
            }
            // o nome começando com um nome da lista + ' ' (o mais longo primeiro)
            $espacos = [];
            for ($p = strpos($k, ' '); $p !== false; $p = strpos($k, ' ', $p + 1)) {
                $espacos[] = $p;
            }
            foreach (array_reverse($espacos) as $at) {
                if ($hit !== null || $at < 4) {
                    break;
                }
                $hit = $escolher($idx[substr($k, 0, $at)] ?? null);
            }
        }
        return $this->memo[$mk] = $hit;
    }

    /**
     * Um resultado do TMDB/JustWatch (`{id, title|name, original_*, release_date|first_air_date}`) na lista
     * (matchTmdb da v10): pelo id TMDB já conhecido, senão pelo nome e pelo nome original.
     */
    public function casarTmdb(array $r, string $tipo): ?int
    {
        $tmdb = (int) ($r['id'] ?? 0);
        if ($id = $this->porTmdb($tipo, $tmdb)) {
            return $id;
        }
        $ano = (int) substr((string) ($r['release_date'] ?? $r['first_air_date'] ?? ''), 0, 4);
        $id = $this->achar($tipo, $r['title'] ?? $r['name'] ?? '', $ano)
            ?? $this->achar($tipo, $r['original_title'] ?? $r['original_name'] ?? '', $ano);
        // título com outro tmdb conhecido não é este
        if ($id !== null && $tmdb && ($t = $this->tmdbDe($id)) && $t !== $tmdb) {
            return null;
        }
        return $id;
    }
}
