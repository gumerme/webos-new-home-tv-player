<?php
declare(strict_types=1);

namespace Casa\Play\Dados;

use Casa\Db;

/**
 * Trailer em português (09/11/2026). O TMDB quase nunca tem trailer dublado de série, então o homeserver procura no
 * próprio YouTube ("<título> trailer dublado") e escolhe, nesta ordem:
 *   1. trailer em português do TMDB;
 *   2. vídeo com "dublado" no título;
 *   3. trailer oficial de canal brasileiro ("Prime Video Brasil", "Netflix Brasil"...): dublado ou legendado em pt;
 *   4. vídeo com "legendado" no título (a legenda já vem na imagem);
 *   5. o do TMDB em inglês, com a legenda pt-BR ligada pela TV (trailer_legenda).
 * O título do vídeo precisa ter as palavras do nome do título e durar até 6 min. Resultado guardado 30 dias em
 * play.cache 'trailer:v2:<tipo>:<tmdb>'; o worker prepara os destaques depois de cada recomendação.
 */
final class Trailer
{
    private const VALIDADE = 30 * 86400;
    private const NAVEGADOR = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36';
    private const VAZIAS = ['the' => 1, 'and' => 1, 'dos' => 1, 'das' => 1, 'uma' => 1, 'com' => 1, 'por' => 1, 'para' => 1, 'que' => 1];

    /** {trailer_youtube, trailer_legenda, trailer_origem} do título (cache 30 dias). */
    public static function melhor(string $tipo, ?int $tmdb, ?string $nome, ?int $ano, ?string $guardado): array
    {
        $chave = 'trailer:v2:' . $tipo . ':' . ($tmdb ?: 'x' . md5((string) $nome));
        $c = Banco::cache($chave, self::VALIDADE);
        if (is_array($c) && array_key_exists('trailer_youtube', $c)) {
            return $c;
        }
        $t = Tmdb::trailerInfo($tipo, $tmdb);
        if ($t && $t['idioma'] === 'pt') {
            return self::guardar($chave, $t['key'], false, 'tmdb-pt');
        }
        if ($nome !== null && trim($nome) !== '') {
            try {
                $v = self::escolher(self::buscar(trim($nome) . ' trailer dublado'), $nome);
                if ($v) {
                    return self::guardar($chave, $v['id'], false, $v['origem']);
                }
            } catch (\Throwable $e) {
                Log::info('trailer: busca no YouTube falhou: ' . $e->getMessage());
                $ing = $t['key'] ?? $guardado;
                return ['trailer_youtube' => $ing, 'trailer_legenda' => $ing !== null, 'trailer_origem' => 'tmdb-en'];   // sem guardar: tenta de novo
            }
        }
        $ing = $t['key'] ?? $guardado;
        return self::guardar($chave, $ing, $ing !== null, $ing ? 'tmdb-en' : 'nenhum');
    }

    private static function guardar(string $chave, ?string $id, bool $legenda, string $origem): array
    {
        $r = ['trailer_youtube' => $id, 'trailer_legenda' => $legenda, 'trailer_origem' => $origem];
        Banco::guardar($chave, $r);
        return $r;
    }

    /** Busca no YouTube: [{id, titulo, segundos, canal}] (sem chave de API: lê o ytInitialData da página de resultados). */
    public static function buscar(string $q): array
    {
        $r = Http::get('https://www.youtube.com/results?search_query=' . rawurlencode($q) . '&hl=pt-BR&gl=BR',
            ['User-Agent' => self::NAVEGADOR, 'Accept-Language' => 'pt-BR,pt;q=0.9'], 20);
        if ($r['status'] !== 200 || !preg_match('/var ytInitialData = (\{.*?\});<\/script>/s', $r['corpo'], $m)) {
            throw new \RuntimeException('resultado do YouTube sem ytInitialData (HTTP ' . $r['status'] . ')');
        }
        return self::videos((array) json_decode($m[1], true));
    }

    /** ytInitialData -> [{id, titulo, segundos, canal}] (puro). */
    public static function videos(array $d): array
    {
        $saida = [];
        $andar = function ($o) use (&$andar, &$saida): void {
            if (!is_array($o)) {
                return;
            }
            if (isset($o['videoRenderer']['videoId'])) {
                $v = $o['videoRenderer'];
                $dur = (string) ($v['lengthText']['simpleText'] ?? '');
                $seg = 0;
                foreach (explode(':', $dur) as $p) {
                    $seg = $seg * 60 + (int) $p;
                }
                $saida[] = ['id' => (string) $v['videoId'], 'titulo' => (string) ($v['title']['runs'][0]['text'] ?? ''),
                            'segundos' => $dur === '' ? 0 : $seg, 'canal' => (string) ($v['ownerText']['runs'][0]['text'] ?? '')];
                return;
            }
            foreach ($o as $x) {
                $andar($x);
            }
        };
        $andar($d);
        return $saida;
    }

    /** Minúsculas sem acento, pontuação vira espaço (Texto::norm apagaria "Dublado" e o ano). */
    private static function limpo(string $s): string
    {
        return trim(preg_replace('/[^a-z0-9]+/', ' ', Texto::fold($s)) ?? '');
    }

    /** Escolhe o trailer em português (puro): dublado > oficial de canal brasileiro > legendado. @return array{id:string, origem:string}|null */
    public static function escolher(array $videos, string $nome): ?array
    {
        $palavras = array_values(array_filter(explode(' ', self::limpo($nome)), fn($w) => mb_strlen($w) >= 3 && !isset(self::VAZIAS[$w])));
        $ok = function (array $v) use ($palavras): bool {
            $t = ' ' . self::limpo($v['titulo']) . ' ';
            if ($v['segundos'] <= 0 || $v['segundos'] > 360 || !preg_match('/\b(trailer|teaser)\b/u', $t)) {
                return false;
            }
            foreach ($palavras as $w) {
                if (!str_contains($t, ' ' . $w . ' ') && !str_contains($t, $w)) {
                    return false;
                }
            }
            return true;
        };
        $bons = array_values(array_filter($videos, $ok));
        foreach ([
            ['dublado', fn($v) => (bool) preg_match('/\bdublad[oa]\b|\bdub\b/u', self::limpo($v['titulo']))],
            ['oficial-br', fn($v) => (bool) preg_match('/\bbrasil\b/u', self::limpo($v['canal'])) && !preg_match('/\blegendad/u', self::limpo($v['titulo']))],
            ['legendado', fn($v) => (bool) preg_match('/\blegendad[oa]\b/u', self::limpo($v['titulo']))],
        ] as [$origem, $regra]) {
            foreach ($bons as $v) {
                if ($regra($v)) {
                    return ['id' => $v['id'], 'origem' => $origem];
                }
            }
        }
        return null;
    }

    /** Prepara (cache) os trailers dos destaques de um perfil: chamado pelo worker depois da recomendação. */
    public static function prepararDestaques(array $ids): int
    {
        if (!$ids) {
            return 0;
        }
        $st = Db::pdo()->prepare("select tipo, tmdb_id, nome, ano, detalhes->>'trailer' as trailer from play.titulo where id = any(cast(? as bigint[]))");
        $st->execute([Banco::ints($ids)]);
        $n = 0;
        foreach ($st->fetchAll() as $t) {
            self::melhor((string) $t['tipo'], $t['tmdb_id'] !== null ? (int) $t['tmdb_id'] : null, (string) $t['nome'],
                $t['ano'] !== null ? (int) $t['ano'] : null, $t['trailer'] ?: null);
            $n++;
        }
        return $n;
    }
}
