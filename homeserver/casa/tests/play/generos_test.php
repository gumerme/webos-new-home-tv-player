<?php
declare(strict_types=1);

// Gêneros pelo JustWatch (Generos): tradução TMDB -> JustWatch e pesos do perfil. Puro, sem banco.
require_once __DIR__ . '/_base.php';

use Casa\Play\Dados\Generos;

igual(['drm', 'cmy'], Generos::doTitulo(['drm', 'cmy'], [28]), 'JustWatch classificou: vale o JustWatch');
igual(['drm'], Generos::doTitulo(['drm', 'eur'], []), '"eur" (Made in Europe) não é categoria');
igual(['act', 'scf', 'fnt'], Generos::doTitulo([], [28, 12, 10765]), 'sem JustWatch: TMDB traduzido, sem repetir ação');
igual([], Generos::doTitulo(null, [10770]), 'TV Movie não vira gênero');
igual([28, 12, 10759], Generos::tmdbDe('act'), 'ação e aventura = 28, 12, 10759');
igual([10765, 878], Generos::tmdbDe('scf'), 'ficção científica = 878 e Sci-Fi & Fantasy');
$p = Generos::pesosDoPerfil([18 => 1.0, 35 => 0.8, 28 => 0.5, 12 => 0.4, 99 => -0.3]);
igual(['drm', 'cmy', 'act'], array_keys($p), 'perfil: drama, comédia, ação (negativo fora)');
igual(0.5, $p['act'], 'ação e aventura pegam o maior peso, não a soma');
verdade((bool) preg_match(Generos::PAINEL_MANTIDAS, 'Lançamentos'), 'Lançamentos do painel fica');
verdade((bool) preg_match(Generos::PAINEL_MANTIDAS, '4K'), '4K do painel fica');
verdade((bool) preg_match(Generos::PAINEL_MANTIDAS, 'Cinema'), 'Cinema do painel fica');
verdade(!preg_match(Generos::PAINEL_MANTIDAS, 'Comédia Romântica'), 'Comédia Romântica do painel sai');
verdade(!preg_match(Generos::PAINEL_MANTIDAS, 'Netflix'), 'categoria de produtora sai');
[$f, $par] = Generos::filtro('cmy');
verdade(str_contains($f, "jw->'g'") && $par[0] === 'cmy' && $par[1] === '{35}', 'filtro SQL do gênero');
verdade(str_contains(Generos::sqlValores(), "(10765, 'fnt')"), 'VALUES tem a tradução de Sci-Fi & Fantasy');
fim(__FILE__);
