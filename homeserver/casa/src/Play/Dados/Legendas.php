<?php
declare(strict_types=1);

namespace Casa\Play\Dados;

use Casa\Db;

/**
 * Legendas em português (o painel não tem): add-on OpenSubtitles público do Stremio, sem chave
 * (IMDb pelo TMDB external_ids), pob (pt-BR) antes de por (pt), Blu-ray/WEB/DVD antes de Cam/Telesync.
 * Com OPENSUBTITLES_KEY no .env, a API oficial do OpenSubtitles entra como segunda fonte.
 * Arquivos em var/play/legendas/<id>.srt, sempre UTF-8.
 */
final class Legendas
{
    private const STREMIO = 'https://opensubtitles-v3.strem.io/subtitles/';
    private const OS_API = 'https://api.opensubtitles.com/api/v1/';
    private const BUSCA_VALE_S = 86400;
    private const MAX = 12;

    /** @return list<array{id:int, idioma:string, nome:string, fonte:string}> */
    public static function buscar(int $tituloId, ?int $temporada, ?int $episodio): array
    {
        $pdo = Db::pdo();
        $temporada = $temporada ?: null;
        $episodio = $episodio ?: null;
        // busca recente: devolve o que já está na tabela
        $st = $pdo->prepare("select id, idioma, nome, fonte from play.legenda
                              where titulo_id = ? and temporada is not distinct from ? and episodio is not distinct from ?
                                and criado_em > now() - make_interval(secs => ?)
                              order by id");
        $st->execute([$tituloId, $temporada, $episodio, self::BUSCA_VALE_S]);
        $ja = $st->fetchAll();
        if ($ja) {
            return array_map(fn($l) => ['id' => (int) $l['id'], 'idioma' => $l['idioma'], 'nome' => $l['nome'], 'fonte' => $l['fonte']], $ja);
        }
        $imdb = self::imdb($tituloId);
        if (!$imdb) {
            return [];
        }
        $st = $pdo->prepare('select tipo from play.titulo where id = ?');
        $st->execute([$tituloId]);
        $serie = $st->fetchColumn() === 's';
        if ($serie && (!$temporada || !$episodio)) {
            return [];
        }
        $achadas = [];
        $alvo = $serie ? "series/$imdb:$temporada:$episodio" : "movie/$imdb";
        try {
            $r = Http::json(self::STREMIO . $alvo . '.json', [], 20);
            $achadas = self::ordenar(is_array($r['subtitles'] ?? null) ? $r['subtitles'] : []);
        } catch (\Throwable $e) {
            Log::info('legendas (Stremio): ' . $e->getMessage());
        }
        if (count($achadas) < 3 && getenv('OPENSUBTITLES_KEY')) {
            try {
                $achadas = array_merge($achadas, self::buscarApiOficial($imdb, $serie ? $temporada : null, $serie ? $episodio : null));
            } catch (\Throwable $e) {
                Log::info('legendas (OpenSubtitles): ' . $e->getMessage());
            }
        }
        $achadas = array_slice($achadas, 0, self::MAX);
        if (!$achadas) {
            return [];
        }
        // troca a lista antiga (arquivos já baixados ficam se a mesma legenda voltar)
        $st = $pdo->prepare('select id, url_origem, arquivo from play.legenda where titulo_id = ? and temporada is not distinct from ? and episodio is not distinct from ?');
        $st->execute([$tituloId, $temporada, $episodio]);
        $antigas = [];
        foreach ($st->fetchAll() as $l) {
            $antigas[(string) $l['url_origem']] = $l;
        }
        $saida = [];
        $ins = $pdo->prepare('insert into play.legenda (titulo_id, temporada, episodio, idioma, nome, fonte, url_origem) values (?, ?, ?, ?, ?, ?, ?) returning id');
        $toca = $pdo->prepare('update play.legenda set criado_em = now(), nome = ? where id = ?');
        foreach ($achadas as $a) {
            if (isset($antigas[$a['url']])) {
                $id = (int) $antigas[$a['url']]['id'];
                $toca->execute([$a['nome'], $id]);
                unset($antigas[$a['url']]);
            } else {
                $ins->execute([$tituloId, $temporada, $episodio, $a['idioma'], $a['nome'], $a['fonte'], $a['url']]);
                $id = (int) $ins->fetchColumn();
            }
            $saida[] = ['id' => $id, 'idioma' => $a['idioma'], 'nome' => $a['nome'], 'fonte' => $a['fonte']];
        }
        foreach ($antigas as $l) {
            if ($l['arquivo']) {
                @unlink(self::caminho((int) $l['id']));
            }
            $pdo->prepare('delete from play.legenda where id = ?')->execute([$l['id']]);
        }
        return $saida;
    }

    /**
     * Resposta do add-on → legendas em português na ordem de escolha (pura, testada):
     * pt-BR antes de pt; Blu-ray/WEB/DVD/HDTV antes do resto e Cam/Telesync por último; mais baixadas antes.
     * @return list<array{url:string, idioma:string, nome:string, fonte:string}>
     */
    public static function ordenar(array $subs): array
    {
        $formato = function (array $s): int {
            $f = strtolower(($s['releaseFormat'] ?? '') . ' ' . ($s['movieReleaseName'] ?? '') . ' ' . ($s['subtitleFileName'] ?? ''));
            if (preg_match('/\b(cam|hdcam|telesync|hdts|ts|tc|telecine|screener|dvdscr|scr)\b/', $f)) {
                return 3;
            }
            if (preg_match('/blu-?ray|bdrip|brrip|web|webrip|web-?dl|dvd|hdtv|dsr|remux/', $f)) {
                return 0;
            }
            return 1;
        };
        $lista = [];
        foreach ($subs as $i => $s) {
            $lang = strtolower((string) ($s['lang'] ?? ''));
            if (!in_array($lang, ['pob', 'pt-br', 'por', 'pt'], true) || empty($s['url']) || !preg_match('#^https://#', (string) $s['url'])) {
                continue;
            }
            $br = in_array($lang, ['pob', 'pt-br'], true);
            $nome = (string) ($s['movieReleaseName'] ?? '') ?: (string) ($s['subtitleFileName'] ?? '');
            $nome = trim(preg_replace('/\.(srt|sub|vtt)$/i', '', $nome) ?? $nome);
            $lista[] = ['ordem' => [$br ? 0 : 1, $formato($s), -(int) ($s['downloads'] ?? 0), $i],
                        'url' => (string) $s['url'], 'idioma' => $br ? 'pt-BR' : 'pt',
                        'nome' => mb_substr(($br ? 'Português (Brasil)' : 'Português') . ($nome !== '' ? " · $nome" : ''), 0, 160),
                        'fonte' => 'opensubtitles'];
        }
        usort($lista, fn($a, $b) => $a['ordem'] <=> $b['ordem']);
        $vistas = [];
        $saida = [];
        foreach ($lista as $l) {
            if (isset($vistas[$l['url']])) {
                continue;
            }
            $vistas[$l['url']] = true;
            unset($l['ordem']);
            $saida[] = $l;
        }
        return $saida;
    }

    /** Caminho do .srt UTF-8 da legenda (baixa na primeira vez); null se não deu. */
    public static function arquivo(int $legendaId): ?string
    {
        $pdo = Db::pdo();
        $st = $pdo->prepare('select id, url_origem, arquivo, fonte from play.legenda where id = ?');
        $st->execute([$legendaId]);
        $l = $st->fetch();
        if (!$l) {
            return null;
        }
        $caminho = self::caminho($legendaId);
        if ($l['arquivo'] && is_file($caminho) && filesize($caminho) > 0) {
            @touch($caminho);
            return $caminho;
        }
        $url = (string) $l['url_origem'];
        if (str_starts_with($url, 'os:')) {
            $url = self::linkApiOficial((int) substr($url, 3));
        }
        if (!$url) {
            return null;
        }
        $r = Http::get($url, [], 30);
        if ($r['status'] !== 200 || $r['corpo'] === '') {
            Log::info("legenda $legendaId: HTTP {$r['status']}");
            return null;
        }
        $texto = self::paraUtf8($r['corpo']);
        if (!str_contains($texto, '-->')) {
            Log::info("legenda $legendaId: arquivo não parece SRT");
            return null;
        }
        Banco::pasta('legendas');
        if (file_put_contents($caminho . '.tmp', $texto) === false || !rename($caminho . '.tmp', $caminho)) {
            return null;
        }
        $pdo->prepare('update play.legenda set arquivo = ? where id = ?')->execute(["var/play/legendas/$legendaId.srt", $legendaId]);
        return $caminho;
    }

    /** Texto de legenda em UTF-8 sem BOM, quebras \n (Windows-1252 quando não é UTF-8). */
    public static function paraUtf8(string $s): string
    {
        if (str_starts_with($s, "\x1f\x8b")) {
            $s = (string) @gzdecode($s);
        }
        if (str_starts_with($s, "\xEF\xBB\xBF")) {
            $s = substr($s, 3);
        } elseif (str_starts_with($s, "\xFF\xFE") || str_starts_with($s, "\xFE\xFF")) {
            $s = mb_convert_encoding(substr($s, 2), 'UTF-8', str_starts_with($s, "\xFF\xFE") ? 'UTF-16LE' : 'UTF-16BE');
        }
        if (!mb_check_encoding($s, 'UTF-8')) {
            $s = mb_convert_encoding($s, 'UTF-8', 'Windows-1252');
        }
        return str_replace(["\r\n", "\r"], "\n", $s);
    }

    public static function caminho(int $id): string
    {
        return Banco::pasta('legendas') . "/$id.srt";
    }

    /** IMDb do título: detalhes do TMDB, senão external_ids (guardado no título). */
    private static function imdb(int $tituloId): ?string
    {
        $pdo = Db::pdo();
        $st = $pdo->prepare('select tipo, tmdb_id, imdb_id from play.titulo where id = ?');
        $st->execute([$tituloId]);
        $t = $st->fetch();
        if (!$t) {
            return null;
        }
        if ($t['imdb_id']) {
            return (string) $t['imdb_id'];
        }
        if (!$t['tmdb_id'] || !Tmdb::configurado()) {
            return null;
        }
        $d = Tmdb::get(($t['tipo'] === 's' ? 'tv/' : 'movie/') . (int) $t['tmdb_id'] . '/external_ids');
        $imdb = $d['imdb_id'] ?? null;
        if (!is_string($imdb) || !preg_match('/^tt\d+$/', $imdb)) {
            return null;
        }
        $pdo->prepare('update play.titulo set imdb_id = ? where id = ?')->execute([$imdb, $tituloId]);
        return $imdb;
    }

    // ------------------------------------------------------------ API oficial (opcional)

    private static function cabOficial(bool $comLogin = false): array
    {
        $chave = (string) getenv('OPENSUBTITLES_KEY');
        Http::segredo($chave);
        $cab = ['Api-Key' => $chave, 'User-Agent' => 'casa-play v2.0'];
        if ($comLogin && ($u = getenv('OPENSUBTITLES_USUARIO')) && ($s = getenv('OPENSUBTITLES_SENHA'))) {
            Http::segredo($s);
            $tok = Banco::lembrar('os:token', 20 * 3600, function () use ($cab, $u, $s) {
                $r = Http::postJson(self::OS_API . 'login', ['username' => $u, 'password' => $s], $cab, 20);
                return isset($r['token']) ? ['t' => $r['token']] : null;
            });
            if (!empty($tok['t'])) {
                Http::segredo($tok['t']);
                $cab['Authorization'] = 'Bearer ' . $tok['t'];
            }
        }
        return $cab;
    }

    private static function buscarApiOficial(string $imdb, ?int $temporada, ?int $episodio): array
    {
        $q = ['imdb_id' => (int) substr($imdb, 2), 'languages' => 'pt-br,pt-pt'];
        if ($temporada) {
            $q = ['parent_imdb_id' => (int) substr($imdb, 2), 'season_number' => $temporada, 'episode_number' => $episodio, 'languages' => 'pt-br,pt-pt'];
        }
        $r = Http::json(self::OS_API . 'subtitles?' . http_build_query($q), self::cabOficial(), 20);
        $subs = [];
        foreach ($r['data'] ?? [] as $d) {
            $a = $d['attributes'] ?? [];
            $arq = $a['files'][0]['file_id'] ?? null;
            if (!$arq) {
                continue;
            }
            $subs[] = ['url' => 'os:' . (int) $arq, 'lang' => ($a['language'] ?? '') === 'pt-br' ? 'pob' : 'por', 'movieReleaseName' => $a['release'] ?? '',
                       'downloads' => (int) ($a['download_count'] ?? 0)];
        }
        // o ordenar() exige https: troca temporária
        $ord = self::ordenar(array_map(fn($s) => ['url' => 'https://os/' . substr($s['url'], 3)] + $s, $subs));
        return array_map(fn($l) => ['url' => 'os:' . substr($l['url'], strlen('https://os/')), 'fonte' => 'opensubtitles-api'] + $l, $ord);
    }

    private static function linkApiOficial(int $arquivoId): ?string
    {
        if (!getenv('OPENSUBTITLES_KEY')) {
            return null;
        }
        $r = Http::postJson(self::OS_API . 'download', ['file_id' => $arquivoId, 'sub_format' => 'srt'], self::cabOficial(true), 20);
        return is_string($r['link'] ?? null) ? $r['link'] : null;
    }

    // ------------------------------------------------------------ tarefa (30 min)

    /**
     * Pré-busca: o próximo episódio de quem está no meio de uma série e o item 1 do Continuar de cada
     * perfil (busca a lista e baixa a primeira).
     */
    public static function tarefa(): array
    {
        $pdo = Db::pdo();
        $conta = Xtream::contaAtiva();
        $alvos = [];
        // item 1 do Continuar de cada perfil
        foreach ($pdo->query("select distinct on (p.perfil_id) p.perfil_id, p.titulo_id, p.temporada, p.episodio, p.terminado, t.tipo
                                from play.progresso p join play.titulo t on t.id = p.titulo_id and t.ativo
                               where not p.oculto and p.atualizado_em > now() - interval '30 days'
                               order by p.perfil_id, p.atualizado_em desc")->fetchAll() as $p) {
            if ($p['tipo'] === 'm' && !Banco::bool($p['terminado'])) {
                $alvos['m' . $p['titulo_id']] = [(int) $p['titulo_id'], null, null];
            } elseif ($p['tipo'] === 's' && $p['temporada'] && $p['episodio'] && !Banco::bool($p['terminado'])) {
                $alvos["s{$p['titulo_id']}:{$p['temporada']}:{$p['episodio']}"] = [(int) $p['titulo_id'], (int) $p['temporada'], (int) $p['episodio']];
            }
        }
        // próximo episódio de cada série em andamento
        if ($conta) {
            $series = $pdo->query("select p.titulo_id, p.versao_id, p.temporada, p.episodio from play.progresso p
                                     join play.titulo t on t.id = p.titulo_id and t.ativo and t.tipo = 's'
                                    where not p.oculto and p.temporada is not null and p.episodio is not null
                                      and p.atualizado_em > now() - interval '30 days'")->fetchAll();
            $prox = $pdo->prepare("select e.temporada, e.numero from play.episodio e
                                    where e.conta_id = ? and e.serie_id = ? and (e.temporada, e.numero) > (?, ?)
                                    order by e.temporada, e.numero limit 1");
            foreach ($series as $s) {
                $serie = self::serieDoTitulo($conta['id'], (int) $s['titulo_id'], $s['versao_id'] !== null ? (int) $s['versao_id'] : null);
                if (!$serie) {
                    continue;
                }
                try {
                    Xtream::serieInfo($conta['id'], $serie);
                } catch (\PDOException $e) {
                    throw $e;
                } catch (\Throwable $e) {
                    continue;
                }
                $prox->execute([$conta['id'], $serie, (int) $s['temporada'], (int) $s['episodio']]);
                if ($e = $prox->fetch()) {
                    $alvos["s{$s['titulo_id']}:{$e['temporada']}:{$e['numero']}"] = [(int) $s['titulo_id'], (int) $e['temporada'], (int) $e['numero']];
                }
            }
        }
        $buscas = 0;
        $baixadas = 0;
        foreach (array_slice($alvos, 0, 15) as [$titulo, $temp, $ep]) {
            $lista = self::buscar($titulo, $temp, $ep);
            $buscas++;
            if ($lista && self::arquivo($lista[0]['id'])) {
                $baixadas++;
            }
            if (Tarefas::ceder()) {
                break;
            }
        }
        return ['msg' => sprintf('%d alvo(s), %d busca(s), %d legenda(s) prontas', count($alvos), $buscas, $baixadas)];
    }

    /** series_id do painel de um título (a versão usada no progresso, senão a dublada). */
    public static function serieDoTitulo(int $contaId, int $tituloId, ?int $versaoId): ?int
    {
        $st = Db::pdo()->prepare("select painel_id from play.versao where conta_id = ? and tipo = 's' and titulo_id = ? and ativo
                                   order by (painel_id = ?) desc, (idioma = 'leg'), uhd, painel_id limit 1");
        $st->execute([$contaId, $tituloId, $versaoId ?? 0]);
        $v = $st->fetchColumn();
        return $v === false ? null : (int) $v;
    }
}
