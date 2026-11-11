<?php
declare(strict_types=1);

namespace Casa\Play\Dados;

use Casa\Db;

/**
 * Tarefa "jogos": os canais da categoria "Jogos do Dia" do painel (renomeados todo dia: "20:30 - A x B
 * [FHD]"; um canal por jogo, FHD preferido) casados com o placar público da ESPN (horário ±50 min e
 * nomes dos times) para status, minuto, placar e escudos. Roda às 06:00 e a cada 2 min enquanto há
 * jogo acontecendo. Porte do loadGames/gameChannels/gameTeams/teamScore/crestUrl/espnDay da v10.
 */
final class Jogos
{
    private const ESPN = 'https://site.api.espn.com/apis/site/v2/sports/%s/scoreboard?limit=1000&dates=';
    /** Placares consultados na ESPN: esporte => liga mostrada (null = a do evento, futebol). 09/11: + NBA/NFL/NHL/MLB (escudos). */
    private const ESPORTES = ['soccer/all' => null, 'basketball/nba' => 'NBA', 'football/nfl' => 'NFL', 'hockey/nhl' => 'NHL', 'baseball/mlb' => 'MLB'];
    private const DURACAO = 125 * 60;                  // ao vivo de -10 min até +125 min (sem a ESPN)
    private const ESCUDO_GUARDA = 60 * 86400;
    private const ESCUDO_FALTA = 86400;
    private const RUIDO = ['fc' => 1, 'ec' => 1, 'sc' => 1, 'ac' => 1, 'cf' => 1, 'afc' => 1, 'club' => 1, 'clube' => 1,
                           'de' => 1, 'da' => 1, 'do' => 1, 'rb' => 1, 'red' => 1, 'bull' => 1,
                           'nba' => 1, 'nfl' => 1, 'nhl' => 1, 'mlb' => 1];

    public static function tarefa(): array
    {
        $conta = Xtream::contaAtiva();
        if (!$conta) {
            return ['msg' => 'sem conta ativa', 'proxima' => Tarefas::proximoHorario('06:00')];
        }
        $pdo = Db::pdo();
        $st = $pdo->prepare("select painel_id, nome from play.categoria where conta_id = ? and tipo = 'c'");
        $st->execute([$conta['id']]);
        $cat = null;
        foreach ($st->fetchAll() as $c) {
            if (str_contains(Texto::fold($c['nome']), 'jogos do dia')) {
                $cat = (int) $c['painel_id'];
                break;
            }
        }
        if ($cat === null) {
            return ['msg' => 'categoria "Jogos do Dia" não existe no painel (ou o catálogo ainda não rodou)', 'proxima' => time() + 3600];
        }
        $agora = time();
        $canais = (array) Xtream::api($conta, 'get_live_streams', ['category_id' => $cat], 30);
        Catalogo::upsertCanais($conta['id'], array_map(fn($l) => [
            'p' => (int) $l['stream_id'], 'n' => (string) ($l['name'] ?? ''), 'k' => Texto::norm((string) ($l['name'] ?? '')),
            'num' => isset($l['num']) ? (int) $l['num'] : null, 'logo' => ($l['stream_icon'] ?? '') ?: null, 'cat' => $cat, 'epg' => null, 'arq' => false,
        ], array_filter($canais, fn($l) => isset($l['stream_id']))));
        $jogos = self::canaisDeJogos(array_map(fn($l) => ['id' => (int) ($l['stream_id'] ?? 0), 'nome' => (string) ($l['name'] ?? '')], $canais), $agora);

        // ESPN: dias (horário do leste dos EUA) dos jogos
        $eventos = [];
        $dias = array_values(array_unique(array_map(fn($j) => self::diaEspn($j['inicio']), $jogos)));
        foreach (array_slice($dias, 0, 2) as $dia) {
            foreach (self::ESPORTES as $esporte => $liga) {
                try {
                    $r = Http::json(sprintf(self::ESPN, $esporte) . $dia, [], 20);
                    foreach (is_array($r['events'] ?? null) ? $r['events'] : [] as $ev) {
                        $eventos[] = $liga ? $ev + ['_liga' => $liga] : $ev;
                    }
                } catch (\Throwable $e) {
                    Log::info("ESPN $esporte: " . $e->getMessage());
                }
            }
        }
        $jogos = self::casar($jogos, $eventos, $agora);
        $escudos = self::escudos($jogos);

        $hoje = date('Y-m-d', $agora);
        $linhas = [];
        foreach ($jogos as $j) {
            $lado = fn(int $k) => ['nome' => $j['times'][$k], 'sigla' => $j['espn']['siglas'][$k] ?? null,
                                   'escudo' => $escudos[Texto::chaveTime($j['times'][$k])] ?? null];
            $linhas[] = ['id' => $j['espn']['id'] ?? ('p' . substr(md5($hoje . '|' . Texto::fold($j['titulo'])), 0, 16)),
                         'data' => date('Y-m-d', $j['inicio']), 'inicio' => $j['inicio'], 'status' => $j['status'],
                         'minuto' => $j['minuto'], 'placar' => $j['placar'], 'casa' => $lado(0), 'fora' => $lado(1),
                         'liga' => $j['espn']['liga'] ?? null, 'canal' => $j['canal']];
        }
        $pdo->beginTransaction();
        try {
            if ($linhas) {
                $pdo->prepare("
                    insert into play.jogo (id, data, inicio, status, minuto, placar, casa, fora, liga, canal_id, atualizado_em)
                    select distinct on (x.id) x.id, cast(x.data as date), to_timestamp(x.inicio), x.status, x.minuto, x.placar, x.casa, x.fora, x.liga, x.canal, now()
                      from jsonb_to_recordset(cast(? as jsonb)) as x(id text, data text, inicio bigint, status text, minuto text, placar text,
                                                                     casa jsonb, fora jsonb, liga text, canal int)
                    on conflict (id) do update set data = excluded.data, inicio = excluded.inicio, status = excluded.status, minuto = excluded.minuto,
                           placar = excluded.placar, casa = excluded.casa, fora = excluded.fora, liga = coalesce(excluded.liga, play.jogo.liga),
                           canal_id = excluded.canal_id, atualizado_em = now()")->execute([Banco::json($linhas)]);
                // aviso de jogo: 5 min antes do início (que pode ter mudado)
                $pdo->prepare("update play.aviso a set quando = j.inicio - interval '5 minutes'
                                 from play.jogo j
                                where a.tipo = 'jogo' and a.status = 'ativo' and a.alvo->>'jogo' = j.id
                                  and j.id = any(cast(? as text[])) and a.quando is distinct from j.inicio - interval '5 minutes'")
                    ->execute(['{' . implode(',', array_map(fn($l) => '"' . $l['id'] . '"', $linhas)) . '}']);
            }
            // jogo de hoje cujo canal sumiu do painel
            $pdo->prepare("delete from play.jogo where data = cast(? as date) and not (id = any(cast(? as text[])))
                             and not exists (select 1 from play.aviso a where a.tipo = 'jogo' and a.alvo->>'jogo' = play.jogo.id and a.status = 'ativo')")
                ->execute([$hoje, '{' . implode(',', array_map(fn($l) => '"' . $l['id'] . '"', $linhas)) . '}']);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        $agoraN = count(array_filter($jogos, fn($j) => $j['status'] === 'agora'));
        $proximos = array_filter(array_map(fn($j) => $j['status'] === 'depois' ? $j['inicio'] : null, $jogos));
        if ($agoraN) {
            $proxima = $agora + 120;
        } elseif ($proximos) {
            $proxima = max($agora + 120, min(min($proximos) - 600, $agora + 1800));
        } elseif (!$jogos && (int) date('G', $agora) < 13) {
            $proxima = $agora + 3600;                    // o painel ainda não montou os jogos do dia
        } else {
            $proxima = Tarefas::proximoHorario('06:00', $agora);
        }
        $comEspn = count(array_filter($jogos, fn($j) => !empty($j['espn'])));
        return ['msg' => sprintf('%d jogo(s) (%d agora), %d casados com a ESPN', count($jogos), $agoraN, $comEspn), 'proxima' => $proxima];
    }

    /**
     * Canais "20:30 - A x B [FHD]" → um jogo por partida (FHD > HD > SD), puro e testado.
     * Na madrugada, depois das 18 h, os jogos de 0–5 h são do dia seguinte.
     * @return list<array{canal:int, titulo:string, times:array{0:string,1:string}, inicio:int, status:string, minuto:?string, placar:?string, espn:?array}>
     */
    public static function canaisDeJogos(array $canais, int $agora): array
    {
        $preferir = ['FHD', 'HD', 'SD'];
        $porJogo = [];
        foreach ($canais as $ch) {
            if (!preg_match('/^\s*(\d{1,2})\s*[:hH]\s*(\d{2})\s*[-–]\s*(.+?)\s*$/u', $ch['nome'], $m)) {
                continue;
            }
            preg_match_all('/\[([^\]]*)\]/', $m[3], $tags);
            $titulo = trim(preg_replace('/\s*\[[^\]]*\]/', '', $m[3]) ?? $m[3]);
            $times = self::times($titulo);
            if (!$times) {
                continue;
            }
            $rank = count($preferir);
            foreach ($tags[1] as $t) {
                $at = array_search(strtoupper($t), $preferir, true);
                if ($at !== false && $at < $rank) {
                    $rank = $at;
                }
            }
            $h = (int) $m[1];
            $min = (int) $m[2];
            $chave = "$h:$min|" . Texto::fold($titulo);
            if (isset($porJogo[$chave]) && $porJogo[$chave]['rank'] <= $rank) {
                continue;
            }
            $inicio = mktime($h, $min, 0, (int) date('n', $agora), (int) date('j', $agora), (int) date('Y', $agora));
            if ($h < 6 && (int) date('G', $agora) >= 18) {
                $inicio += 86400;
            }
            $porJogo[$chave] = ['rank' => $rank, 'j' => ['canal' => $ch['id'], 'titulo' => $titulo, 'times' => $times, 'inicio' => $inicio,
                                'status' => self::statusPorHora($inicio, $agora), 'minuto' => null, 'placar' => null, 'espn' => null]];
        }
        $lista = array_map(fn($x) => $x['j'], array_values($porJogo));
        $ordem = ['agora' => 0, 'depois' => 1, 'fim' => 2];
        usort($lista, fn($a, $b) => [$ordem[$a['status']], $a['inicio']] <=> [$ordem[$b['status']], $b['inicio']]);
        return $lista;
    }

    public static function statusPorHora(int $inicio, int $agora): string
    {
        return $agora < $inicio - 600 ? 'depois' : ($agora < $inicio + self::DURACAO ? 'agora' : 'fim');
    }

    /** "A x B" / "A vs B" → [A, B]. */
    public static function times(string $titulo): ?array
    {
        return preg_match('/^(.+?)\s+(?:x|vs\.?|versus)\s+(.+)$/iu', $titulo, $m) ? [trim($m[1]), trim($m[2])] : null;
    }

    /** Dia da ESPN (agrupa pelo horário do leste dos EUA, UTC−5). */
    public static function diaEspn(int $inicio): string
    {
        return gmdate('Ymd', $inicio - 5 * 3600);
    }

    private static function palavras(string $s): array
    {
        return array_values(array_filter(explode(' ', Texto::chaveTime($s)), fn($w) => $w !== '' && !isset(self::RUIDO[$w])));
    }

    /** Quanto um nome do painel bate com um time da ESPN: 3 igual, 2 todas as palavras de um no outro, 1 alguma. */
    public static function pontosTime(string $nome, array $time): int
    {
        $chave = Texto::chaveTime($nome);
        $palavras = self::palavras($nome);
        $melhor = 0;
        foreach (['displayName', 'shortDisplayName', 'name', 'location', 'abbreviation'] as $campo) {
            $n = (string) ($time[$campo] ?? '');
            if ($n === '') {
                continue;
            }
            if (Texto::chaveTime($n) === $chave) {
                return 3;
            }
            $outras = self::palavras($n);
            $comum = count(array_intersect($palavras, $outras));
            if ($comum && $comum === min(count($palavras), count($outras))) {
                $melhor = max($melhor, 2);
            } elseif ($comum) {
                $melhor = max($melhor, 1);
            }
        }
        return $melhor;
    }

    /** Casa cada jogo com um evento da ESPN (início ±50 min, os dois times) e copia status/placar (puro). */
    public static function casar(array $jogos, array $eventos, int $agora): array
    {
        foreach ($jogos as &$j) {
            $melhor = null;
            foreach ($eventos as $ev) {
                $comp = $ev['competitions'][0]['competitors'] ?? null;
                $quando = strtotime((string) ($ev['date'] ?? ''));
                if (!is_array($comp) || count($comp) !== 2 || !$quando || abs($quando - $j['inicio']) > 50 * 60) {
                    continue;
                }
                foreach ([0, 1] as $o) {
                    $a = $comp[$o]['team'] ?? [];
                    $b = $comp[1 - $o]['team'] ?? [];
                    $pa = self::pontosTime($j['times'][0], $a);
                    $pb = self::pontosTime($j['times'][1], $b);
                    if (min($pa, $pb) >= 1 && (!$melhor || $pa + $pb > $melhor['pontos'])) {
                        $melhor = ['pontos' => $pa + $pb, 'ev' => $ev, 'casa' => $comp[$o], 'fora' => $comp[1 - $o], 'quando' => $quando];
                    }
                }
            }
            if (!$melhor) {
                continue;
            }
            $ev = $melhor['ev'];
            $estado = $ev['status']['type']['state'] ?? ($ev['competitions'][0]['status']['type']['state'] ?? '');
            $j['inicio'] = $melhor['quando'];
            $j['status'] = match ($estado) {
                'in'   => 'agora',
                'post' => 'fim',
                'pre'  => 'depois',
                default => self::statusPorHora($j['inicio'], $agora),
            };
            if ($j['status'] !== 'depois') {
                $j['placar'] = (int) ($melhor['casa']['score'] ?? 0) . ' x ' . (int) ($melhor['fora']['score'] ?? 0);
            }
            if ($j['status'] === 'agora') {
                $det = (string) ($ev['status']['type']['shortDetail'] ?? '');
                $j['minuto'] = self::minutoPt($det !== '' ? $det : ((string) ($ev['status']['displayClock'] ?? '') ?: null));
            } elseif ($j['status'] === 'fim') {
                $j['minuto'] = 'Fim';
            }
            $j['espn'] = [
                'id'     => (string) ($ev['id'] ?? ''),
                'liga'   => $ev['_liga'] ?? self::ligaPt($ev['competitions'][0]['altGameNote'] ?? ($ev['season']['slug'] ?? null)),
                'siglas' => [$melhor['casa']['team']['abbreviation'] ?? null, $melhor['fora']['team']['abbreviation'] ?? null],
                'logos'  => [$melhor['casa']['team']['logo'] ?? null, $melhor['fora']['team']['logo'] ?? null],
                'nomes'  => [$melhor['casa']['team']['displayName'] ?? null, $melhor['fora']['team']['displayName'] ?? null],
            ];
            if ($j['espn']['id'] === '') {
                $j['espn']['id'] = null;
            }
        }
        unset($j);
        return $jogos;
    }

    /** Tempo de jogo da ESPN em português (puro): "HT" = Intervalo, "ET 105'" = Prorrogação 105'. */
    public static function minutoPt(?string $m): ?string
    {
        if ($m === null || trim($m) === '') {
            return null;
        }
        $m = trim($m);
        $fixos = ['HT' => 'Intervalo', 'Halftime' => 'Intervalo', 'FT' => 'Fim', 'Full Time' => 'Fim', 'Pens' => 'Pênaltis',
                  'PEN' => 'Pênaltis', 'Delayed' => 'Atrasado', 'Postponed' => 'Adiado', 'Suspended' => 'Suspenso',
                  'End of Regulation' => 'Fim do tempo normal', 'Abandoned' => 'Abandonado'];
        if (isset($fixos[$m])) {
            return $fixos[$m];
        }
        return (string) preg_replace(['/^ET\s*/i', '/^1st Half\b/i', '/^2nd Half\b/i'], ['Prorrogação ', '1º tempo', '2º tempo'], $m);
    }

    /** Nome da competição da ESPN em português (puro). Desconhecida: como veio. */
    public static function ligaPt(?string $l): ?string
    {
        if ($l === null || trim($l) === '') {
            return null;
        }
        $fase = '';
        if (preg_match('/^(.*?),\s*(.+)$/', trim($l), $m)) {
            [$l, $fase] = [$m[1], $m[2]];
        }
        $nomes = [
            'brazil serie a' => 'Brasileirão', 'brazilian serie a' => 'Brasileirão', 'brazil serie b' => 'Série B',
            'brazil serie c' => 'Série C', 'copa do brazil' => 'Copa do Brasil', 'copa do brasil' => 'Copa do Brasil',
            'conmebol libertadores' => 'Libertadores', 'copa libertadores' => 'Libertadores', 'conmebol sudamericana' => 'Sul-Americana',
            'copa sudamericana' => 'Sul-Americana', 'english premier league' => 'Premier League', 'laliga' => 'LaLiga',
            'spanish laliga' => 'LaLiga', 'italian serie a' => 'Campeonato Italiano', 'german bundesliga' => 'Bundesliga',
            'french ligue 1' => 'Ligue 1', 'uefa champions league' => 'Champions League', 'uefa europa league' => 'Liga Europa',
            'fifa world cup' => 'Copa do Mundo', 'fifa club world cup' => 'Mundial de Clubes',
            'brazil campeonato paulista' => 'Paulistão', 'brazil campeonato carioca' => 'Carioca', 'brazil campeonato gaucho' => 'Gauchão',
        ];
        $fases = ['final' => 'final', 'finals' => 'final', 'semifinals' => 'semifinal', 'semifinal' => 'semifinal',
                  'quarterfinals' => 'quartas de final', 'round of 16' => 'oitavas de final', 'group stage' => 'fase de grupos'];
        $nome = $nomes[mb_strtolower(trim($l))] ?? trim($l);
        $f = $fase !== '' ? ($fases[mb_strtolower(preg_replace('/\s*-\s*\d+(st|nd|rd|th)\s+leg$/i', '', $fase))] ?? $fase) : '';
        return $f !== '' ? "$nome · $f" : $nome;
    }

    /** Logo de 500 px da ESPN em 128 px pelo redimensionador dela (crestUrl da v10). */
    public static function urlEscudo(?string $logo): ?string
    {
        if (!$logo || str_contains($logo, 'default.png')) {
            return null;
        }
        if (preg_match('#^https://a\.espncdn\.com(/i/[^?\#]+)$#', $logo, $m)) {
            return 'https://a.espncdn.com/combiner/i?img=' . $m[1] . '&w=128&h=128';
        }
        return preg_match('#^https?://\S+$#', $logo) ? $logo : null;
    }

    /**
     * Escudos em play.escudo (chave = nome normalizado): guardados 60 dias; time que a ESPN não tinha
     * é tentado de novo no dia seguinte. Devolve chave => url para os times dos jogos.
     */
    private static function escudos(array $jogos): array
    {
        $pdo = Db::pdo();
        $chaves = [];
        foreach ($jogos as $j) {
            foreach ([0, 1] as $k) {
                $chaves[Texto::chaveTime($j['times'][$k])] = ['nome' => $j['times'][$k], 'sigla' => $j['espn']['siglas'][$k] ?? null,
                    'url' => self::urlEscudo($j['espn']['logos'][$k] ?? null)];
            }
        }
        if (!$chaves) {
            return [];
        }
        $st = $pdo->prepare('select chave, url, extract(epoch from atualizado_em)::bigint as em from play.escudo where chave = any(cast(? as text[]))');
        $st->execute(['{' . implode(',', array_map(fn($c) => '"' . str_replace('"', '', (string) $c) . '"', array_keys($chaves))) . '}']);
        $atuais = [];
        foreach ($st->fetchAll() as $l) {
            $atuais[$l['chave']] = $l;
        }
        $up = $pdo->prepare("insert into play.escudo (chave, nome, sigla, url, atualizado_em) values (?, ?, ?, ?, now())
                             on conflict (chave) do update set nome = excluded.nome, sigla = coalesce(excluded.sigla, play.escudo.sigla),
                                    url = coalesce(excluded.url, play.escudo.url), atualizado_em = now()");
        $saida = [];
        foreach ($chaves as $chave => $x) {
            $a = $atuais[$chave] ?? null;
            $velho = !$a || time() - (int) $a['em'] > ($a['url'] ? self::ESCUDO_GUARDA : self::ESCUDO_FALTA);
            if ($x['url'] && (!$a || $a['url'] !== $x['url'] || $velho)) {
                $up->execute([(string) $chave, $x['nome'], $x['sigla'], $x['url']]);
                $a = ['url' => $x['url']];
            } elseif (!$a) {
                $up->execute([(string) $chave, $x['nome'], $x['sigla'], null]);   // falta registrada (amanhã tenta de novo)
            }
            $saida[$chave] = $a['url'] ?? $x['url'];
        }
        return $saida;
    }
}
