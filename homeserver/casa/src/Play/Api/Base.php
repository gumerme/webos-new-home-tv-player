<?php
declare(strict_types=1);

namespace Casa\Play\Api;

use Casa\Db;

/**
 * Utilidades da API do Player: consultas, conta ativa, URLs de imagem, datas e nomes de gênero.
 * As consultas usam parâmetros posicionais ou nomeados *distintos* (com prepares nativos o PDO
 * não aceita o mesmo nome duas vezes).
 */
final class Base
{
    /** Tamanhos de imagem servidos (os do próprio TMDB; o container não tem GD). */
    public const TAMANHOS = ['w185', 'w342', 'w780', 'w1280', 'original'];

    private static array $conta = [];

    // ------------------------------------------------------------- banco

    public static function linhas(string $sql, array $p = []): array
    {
        $st = Db::pdo()->prepare($sql);
        $st->execute(self::params($p));
        return $st->fetchAll(\PDO::FETCH_ASSOC);
    }

    public static function linha(string $sql, array $p = []): ?array
    {
        $st = Db::pdo()->prepare($sql);
        $st->execute(self::params($p));
        $l = $st->fetch(\PDO::FETCH_ASSOC);
        return $l === false ? null : $l;
    }

    public static function valor(string $sql, array $p = []): mixed
    {
        $st = Db::pdo()->prepare($sql);
        $st->execute(self::params($p));
        $v = $st->fetchColumn();
        return $v === false ? null : $v;
    }

    public static function coluna(string $sql, array $p = []): array
    {
        $st = Db::pdo()->prepare($sql);
        $st->execute(self::params($p));
        return $st->fetchAll(\PDO::FETCH_COLUMN);
    }

    public static function exec(string $sql, array $p = []): int
    {
        $st = Db::pdo()->prepare($sql);
        $st->execute(self::params($p));
        return $st->rowCount();
    }

    /** Executa $fn numa transação. */
    public static function transacao(callable $fn): mixed
    {
        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try {
            $r = $fn();
            $pdo->commit();
            return $r;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /** bool do PHP vira 'true'/'false' (o Postgres não aceita '' como boolean). */
    private static function params(array $p): array
    {
        foreach ($p as $k => $v) {
            if (is_bool($v)) {
                $p[$k] = $v ? 'true' : 'false';
            } elseif (is_array($v)) {
                $p[$k] = json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
        }
        return $p;
    }

    /** Lista de inteiros no formato de array do Postgres: {1,2,3}. */
    public static function arr(iterable $ints): string
    {
        $out = [];
        foreach ($ints as $i) {
            if ($i !== null && $i !== '' && is_numeric($i)) {
                $out[(int) $i] = true;
            }
        }
        return '{' . implode(',', array_keys($out)) . '}';
    }

    /** Lê um array do Postgres ({1,2}) devolvido como texto. */
    public static function lerArr(?string $txt): array
    {
        $txt = trim((string) $txt, '{}');
        return $txt === '' ? [] : array_map('intval', explode(',', $txt));
    }

    public static function json(mixed $v): array
    {
        if (is_array($v)) {
            return $v;
        }
        $d = is_string($v) && $v !== '' ? json_decode($v, true) : null;
        return is_array($d) ? $d : [];
    }

    /** Primeiro valor presente entre várias chaves possíveis (o formato do "detalhes" é do worker). */
    public static function det(array $d, string ...$chaves): mixed
    {
        foreach ($chaves as $c) {
            if (isset($d[$c]) && $d[$c] !== '' && $d[$c] !== []) {
                return $d[$c];
            }
        }
        return null;
    }

    /** Preferência do perfil (valor JSON já decodificado) ou $padrao. */
    public static function pref(int $perfil, string $chave, mixed $padrao = null): mixed
    {
        $v = self::valor('select valor from play.preferencia where perfil_id = ? and chave = ?', [$perfil, $chave]);
        return $v === null ? $padrao : json_decode((string) $v, true);
    }

    // -------------------------------------------------------------- HTTP

    /**
     * GET por streams (o container não tem a extensão curl). Devolve [status, corpo, cabeçalhos].
     * $cab: lista de linhas "Nome: valor".
     */
    public static function httpGet(string $url, array $cab = [], float $timeout = 8, int $max = 8_388_608): array
    {
        $ctx = stream_context_create([
            'http' => [
                'method'          => 'GET',
                'header'          => implode("\r\n", array_merge(['User-Agent: casa-play/2.0', 'Accept-Encoding: identity'], $cab)),
                'timeout'         => $timeout,
                'ignore_errors'   => true,
                'follow_location' => 1,
                'max_redirects'   => 3,
            ],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);
        $h = @fopen($url, 'rb', false, $ctx);
        if ($h === false) {
            return [0, '', []];
        }
        stream_set_timeout($h, (int) ceil($timeout));
        $corpo = '';
        while (!feof($h) && strlen($corpo) <= $max) {
            $parte = fread($h, 65536);
            if ($parte === false || ($parte === '' && (stream_get_meta_data($h)['timed_out'] ?? false))) {
                break;
            }
            $corpo .= $parte;
        }
        $meta = stream_get_meta_data($h);
        fclose($h);
        $linhas = $meta['wrapper_data'] ?? [];
        $status = 0;
        $cabs = [];
        foreach ((array) $linhas as $l) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', (string) $l, $m)) {
                $status = (int) $m[1];   // depois de redirecionar, vale o último
                $cabs = [];
            } elseif (str_contains((string) $l, ':')) {
                [$k, $v] = explode(':', (string) $l, 2);
                $cabs[strtolower(trim($k))] = trim($v);
            }
        }
        if (strlen($corpo) > $max) {
            return [413, '', $cabs];
        }
        return [$status, $corpo, $cabs];
    }

    /**
     * Chamada ao TMDB com o token do servidor (.env TMDB_TOKEN: token de leitura v4 ou chave v3),
     * com cache em disco (var/play/tmp/tmdb). Sem token ou sem resposta: null.
     */
    public static function tmdb(string $caminho, array $query = [], int $ttl = 86400): ?array
    {
        $token = trim((string) (getenv('TMDB_TOKEN') ?: getenv('TMDB_API_KEY') ?: getenv('TMDB_KEY') ?: ''));
        if ($token === '' || !preg_match('#^[a-z0-9/_-]+$#i', $caminho)) {
            return null;
        }
        $query += ['language' => 'pt-BR'];
        $dir = self::varDir('tmp/tmdb');
        $arq = $dir . '/' . md5($caminho . '?' . http_build_query($query)) . '.json';
        if (is_file($arq) && filemtime($arq) > time() - $ttl) {
            $d = json_decode((string) file_get_contents($arq), true);
            if (is_array($d)) {
                return $d;
            }
        }
        $cab = ['Accept: application/json'];
        if (preg_match('/^[0-9a-f]{32}$/i', $token)) {
            $query['api_key'] = $token;   // chave v3
        } else {
            $cab[] = 'Authorization: Bearer ' . $token;
        }
        [$st, $corpo] = self::httpGet('https://api.themoviedb.org/3/' . $caminho . '?' . http_build_query($query), $cab, 4);
        $d = $st === 200 ? json_decode($corpo, true) : null;
        if (!is_array($d)) {
            return null;
        }
        @file_put_contents($arq . '.tmp', json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        @rename($arq . '.tmp', $arq);
        return $d;
    }

    /** Pasta em casa/var/play/ (criada na hora). */
    public static function varDir(string $sub): string
    {
        $dir = dirname(__DIR__, 3) . '/var/play/' . trim($sub, '/');
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        return $dir;
    }

    // ------------------------------------------------------- conta ativa

    /** Lista IPTV ativa (uma por vez). Inclui a senha: não devolver para fora sem querer. */
    public static function conta(): ?array
    {
        if (!array_key_exists('a', self::$conta)) {
            $l = self::linha('select id, nome, servidor, usuario, senha, vence_em, status from play.conta where ativa limit 1');
            if ($l) {
                $l['id'] = (int) $l['id'];
            }
            self::$conta['a'] = $l;
        }
        return self::$conta['a'];
    }

    public static function contaId(): int
    {
        return (int) (self::conta()['id'] ?? 0);
    }

    // ----------------------------------------------------------- imagens

    /**
     * URL relativa (servida por /api/play/img) para um caminho do TMDB ("/abc.jpg") ou uma URL do painel.
     * Imagens do painel levam assinatura (HMAC) e a URL de origem em ?u=: o servidor só baixa o que ele mesmo
     * entregou (sem virar proxy aberto).
     */
    public static function img(?string $c, string $tam = 'w342'): ?string
    {
        $c = trim((string) $c);
        if ($c === '') {
            return null;
        }
        if (preg_match('#^/[A-Za-z0-9_-]{1,80}\.(jpg|jpeg|png|webp|svg)$#i', $c)) {
            return '/api/play/img/' . $tam . $c;
        }
        if (preg_match('#^https?://image\.tmdb\.org/t/p/[a-z0-9]+(/[A-Za-z0-9_-]{1,80}\.(jpg|jpeg|png|webp|svg))$#i', $c, $m)) {
            return '/api/play/img/' . $tam . $m[1];
        }
        if (preg_match('#^https?://[^\s/]+/\S*$#i', $c) && strlen($c) <= 1000) {
            return '/api/play/img/painel/' . self::assinar($c) . '?u=' . rtrim(strtr(base64_encode($c), '+/', '-_'), '=');
        }
        return null;
    }

    /** URL absoluta do TMDB (para integrações fora das TVs, ex.: a Home "WebOS TV"). */
    public static function imgAbsoluta(?string $c, string $tam = 'w342'): ?string
    {
        $c = trim((string) $c);
        if ($c === '') {
            return null;
        }
        if (preg_match('#^/[A-Za-z0-9_-]{1,80}\.(jpg|jpeg|png|webp|svg)$#i', $c)) {
            return 'https://image.tmdb.org/t/p/' . $tam . $c;
        }
        return preg_match('#^https?://#i', $c) ? $c : null;
    }

    public static function assinar(string $url): string
    {
        $chave = hash('sha256', 'casa-play-img|' . (getenv('CASA_TOKEN') ?: 'casa'));
        return hash_hmac('sha1', $url, $chave);
    }

    // ------------------------------------------------------------- datas

    public static function tz(): \DateTimeZone
    {
        return new \DateTimeZone(getenv('TZ') ?: (date_default_timezone_get() ?: 'America/Sao_Paulo'));
    }

    /** Timestamp do Postgres -> ISO 8601 com o fuso da casa (2026-11-08T19:10:00-03:00). */
    public static function iso(?string $ts): ?string
    {
        if ($ts === null || $ts === '') {
            return null;
        }
        try {
            return (new \DateTimeImmutable($ts))->setTimezone(self::tz())->format(DATE_ATOM);
        } catch (\Throwable) {
            return null;
        }
    }

    /** Começo do dia de hoje (fuso da casa) em ISO, para comparar com timestamptz. */
    public static function hojeInicio(?\DateTimeImmutable $agora = null): string
    {
        $agora ??= new \DateTimeImmutable('now', self::tz());
        return $agora->setTimezone(self::tz())->setTime(0, 0)->format(DATE_ATOM);
    }

    /**
     * Duração dos créditos finais (regra da v10): chegar neles conta como terminado.
     * Filme: 5% entre 3 e 8 min. Episódio: 4% entre 1 e 2,5 min.
     */
    public static function creditos(string $tipo, int $duracao): int
    {
        if ($duracao <= 0) {
            return $tipo === 'm' ? 300 : 90;
        }
        return $tipo === 'm'
            ? (int) round(max(180, min(480, $duracao * 0.05)))
            : (int) round(max(60, min(150, $duracao * 0.04)));
    }

    // ------------------------------------------------------------- visto

    /**
     * Títulos que o perfil já viu (subconsulta de titulo_id; 3 parâmetros = o perfil 3 vezes, ver vistosPar):
     * terminado no histórico ou no progresso, ou "Gostei" (pedido 09/11/2026 noite: gostei = já vi; série com
     * Gostei = série vista, sem marcar episódios). Não grava histórico: é tratado na leitura.
     */
    public const VISTOS = '(select titulo_id from play.historico where perfil_id = ? and terminado
                            union select titulo_id from play.progresso where perfil_id = ? and terminado
                            union select titulo_id from play.nota where perfil_id = ? and valor > 0)';

    /** @return list<int> parâmetros de VISTOS */
    public static function vistosPar(int $perfil): array
    {
        return [$perfil, $perfil, $perfil];
    }

    /**
     * Regra de "visto" (pura, testada): terminado no histórico/progresso ou nota "Gostei" (1).
     * "Não gostei" (-1) não é visto; só tira das sugestões (foraDasSugestoes).
     */
    public static function visto(bool $terminado, int $nota): bool
    {
        return $terminado || $nota > 0;
    }

    /**
     * Ids que nunca entram como sugestão nova (destaque, recomendados, "porque", gêneros, época):
     * os vistos e os avaliados (Gostei ou Não gostei). O recomendador já os tira ao recalcular;
     * aqui vale na hora, antes do recálculo.
     * @return array<int, true>
     */
    public static function foraDasSugestoes(int $perfil): array
    {
        $ids = self::coluna('select titulo_id from play.nota where perfil_id = ? union select titulo_id from ' . self::VISTOS . ' v',
            [$perfil, ...self::vistosPar($perfil)]);
        return array_fill_keys(array_map('intval', $ids), true);
    }

    // ----------------------------------------------------------- gêneros

    /** Gêneros do TMDB (filmes e séries) em português. */
    public const GENEROS = [
        28 => 'Ação', 12 => 'Aventura', 16 => 'Animação', 35 => 'Comédia', 80 => 'Crime', 99 => 'Documentário',
        18 => 'Drama', 10751 => 'Família', 14 => 'Fantasia', 36 => 'História', 27 => 'Terror', 10402 => 'Música',
        9648 => 'Mistério', 10749 => 'Romance', 878 => 'Ficção científica', 10770 => 'Filme para TV', 53 => 'Suspense',
        10752 => 'Guerra', 37 => 'Faroeste', 10759 => 'Ação e aventura', 10762 => 'Infantil', 10763 => 'Notícias',
        10764 => 'Reality', 10765 => 'Ficção e fantasia', 10766 => 'Novela', 10767 => 'Talk show', 10768 => 'Guerra e política',
    ];

    public static function generos(array $ids): array
    {
        $out = [];
        foreach ($ids as $id) {
            if (isset(self::GENEROS[(int) $id])) {
                $out[] = self::GENEROS[(int) $id];
            }
        }
        return array_values(array_unique($out));
    }

    /** Qualidade pelo nome do canal (o painel não informa). */
    public static function qualidadeCanal(string $nome): ?string
    {
        return match (true) {
            (bool) preg_match('/\b(4k|uhd)\b/i', $nome) => '4k',
            (bool) preg_match('/\bfhd\b|full ?hd|1080/i', $nome) => 'fhd',
            (bool) preg_match('/\bhd\b|720/i', $nome) => 'hd',
            (bool) preg_match('/\bsd\b/i', $nome) => 'sd',
            default => null,
        };
    }

    /** boolean vindo do Postgres (o pdo_pgsql devolve bool, mas aceita 't'/'true' por garantia). */
    public static function b(mixed $v): bool
    {
        return $v === true || $v === 1 || in_array($v, ['t', 'true', '1'], true);
    }

    public static function inteiroOuNulo(mixed $v): ?int
    {
        return $v === null || $v === '' || !is_numeric($v) ? null : (int) $v;
    }

    public static function numeroOuNulo(mixed $v): ?float
    {
        return $v === null || $v === '' || !is_numeric($v) ? null : round((float) $v, 1);
    }
}
