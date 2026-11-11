<?php
declare(strict_types=1);

// EPG (get_simple_data_table, base64) e escolha de legendas do add-on OpenSubtitles do Stremio.
require_once __DIR__ . '/_base.php';

use Casa\Play\Dados\Epg;
use Casa\Play\Dados\Legendas;
use Casa\Play\Dados\Links;
use Casa\Play\Dados\Xtream;

// ---- EPG real (Globo Curitiba)
$t = dados('epg_tabela.json');
$p = Epg::lerTabela($t, $t['agora'] - 6 * 3600, $t['agora'] + 24 * 3600);
verdade(count($p) >= 3, 'programas lidos');
verdade($p[0]['t'] !== '' && mb_check_encoding($p[0]['t'], 'UTF-8'), 'título decodificado em UTF-8');
verdade($p[0]['i'] < $p[0]['f'], 'início antes do fim');
$ordenado = array_column($p, 'i');
$copia = $ordenado;
sort($copia);
igual($copia, $ordenado, 'em ordem de início');
// sintético: base64 em Latin-1, fora da janela, sem título
$x = ['epg_listings' => [
    ['title' => base64_encode("Not\xedcias"), 'description' => base64_encode('Jornal'), 'start_timestamp' => '1000', 'stop_timestamp' => '2000'],
    ['title' => base64_encode('Velho'), 'start_timestamp' => '10', 'stop_timestamp' => '20'],
    ['title' => '', 'start_timestamp' => '1500', 'stop_timestamp' => '1600'],
    ['title' => base64_encode('Sem timestamp'), 'start' => '1970-01-01 00:50:00', 'end' => '1970-01-01 01:00:00'],
]];
date_default_timezone_set('UTC');
$p = Epg::lerTabela($x, 900, 5000);
date_default_timezone_set('America/Sao_Paulo');
igual('Notícias', $p[0]['t'] ?? null, 'Latin-1 vira UTF-8');
igual('Jornal', $p[0]['d'] ?? null, 'descrição');
igual(2, count($p), 'fora da janela e sem título saem');
igual('Sem timestamp', $p[1]['t'] ?? null, 'data em texto quando não há timestamp');

// ---- legendas (resposta real para Interestelar)
$l = Legendas::ordenar(dados('stremio_legendas.json')['subtitles']);
verdade(count($l) >= 5, 'legendas em português');
igual('pt-BR', $l[0]['idioma'], 'pt-BR primeiro');
verdade(!preg_match('/cam|telesync|hdts/i', $l[0]['nome']), 'primeira não é de Cam/Telesync: ' . $l[0]['nome']);
$idiomas = array_column($l, 'idioma');
$primeiroPt = array_search('pt', $idiomas, true);
verdade($primeiroPt === false || !in_array('pt-BR', array_slice($idiomas, $primeiroPt), true), 'todas as pt-BR antes das pt');
igual(count($l), count(array_unique(array_column($l, 'url'))), 'sem repetidas');
$s = Legendas::ordenar([
    ['lang' => 'por', 'url' => 'https://x/1', 'releaseFormat' => 'Blu-ray'],
    ['lang' => 'pob', 'url' => 'https://x/2', 'releaseFormat' => 'Cam', 'movieReleaseName' => 'Filme.CAM'],
    ['lang' => 'pob', 'url' => 'https://x/3', 'movieReleaseName' => 'Filme.2014.1080p.BluRay'],
    ['lang' => 'eng', 'url' => 'https://x/4'],
    ['lang' => 'pob', 'url' => 'http://x/5'],
]);
igual(['https://x/3', 'https://x/2', 'https://x/1'], array_column($s, 'url'), 'pob Blu-ray, pob Cam, por; sem inglês e sem http');
igual("1\n00:00:01,000 --> 00:00:02,000\nAção\n", Legendas::paraUtf8("\xEF\xBB\xBF1\r\n00:00:01,000 --> 00:00:02,000\r\nA\xe7\xe3o\r\n"), 'UTF-8 sem BOM, \\n');

// ---- episódios do painel e qualidade
$eps = Xtream::lerEpisodios(['episodes' => ['1' => [
    ['id' => '501', 'episode_num' => 1, 'title' => 'Piloto', 'container_extension' => 'mkv', 'season' => 1, 'added' => '1700000000',
     'info' => ['duration_secs' => 2900, 'plot' => 'Início', 'movie_image' => 'https://img/x.jpg', 'video' => ['height' => 1080]]],
    ['id' => '502', 'episode_num' => '2', 'title' => 'Dois', 'info' => ['duration' => '00:45:10']],
]]]);
igual(2, count($eps), 'episódios');
igual(['ep_id' => 501, 'temporada' => 1, 'numero' => 1, 'ext' => 'mkv', 'duracao_s' => 2900, 'altura' => 1080],
      array_intersect_key($eps[0], array_flip(['ep_id', 'temporada', 'numero', 'ext', 'duracao_s', 'altura'])), 'episódio 1');
igual(2710, $eps[1]['duracao_s'], 'duração hh:mm:ss');
igual('4k', Links::qualidade(2160, ''), '2160 = 4k');
igual('fhd', Links::qualidade(1080, ''), '1080 = fhd');
igual('hd', Links::qualidade(720, ''), '720 = hd');
igual('sd', Links::qualidade(480, ''), '480 = sd');
igual('4k', Links::qualidade(0, 'Duna 4K [HDR]'), 'pelo nome 4K');
igual(null, Links::qualidade(0, 'Duna'), 'sem altura e sem marca = não sabe');
igual('fhd', Links::qualidade(0, 'Interestelar', 3236), 'pela taxa de bits (3,2 Mbit/s do painel)');
igual('hd', Links::qualidade(0, 'Antigo', 1400), '1,4 Mbit/s = hd');

fim(__FILE__);
