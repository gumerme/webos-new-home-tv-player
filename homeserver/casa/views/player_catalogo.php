<?php
use Casa\Icone;
use Casa\View;

$stat = static fn(string $cor, string $icone, string $rotulo, $valor, string $sub = '') =>
    '<div class="stat-card-pro"><div class="stat-card-icon ' . $cor . '">' . Icone::svg($icone) . '</div>'
    . '<div class="stat-card-label">' . e($rotulo) . '</div><div class="stat-card-value num">' . e((string) $valor) . '</div>'
    . '<div class="stat-card-sub" title="' . e($sub) . '">' . e($sub) . '</div></div>';
$n = static fn($v) => number_format((int) $v, 0, ',', '.');
$tipo = static fn(string $t) => $t === 's' ? 'Série' : 'Filme';
$quando = static fn(?string $ts) => $ts ? View::relativo(strtotime($ts)) : '—';
$qualTxt = ['4k' => '4K', 'fhd' => 'Full HD', 'hd' => 'HD', 'sd' => 'SD'];
$testados = (int) $totais['testados'];
$quebrados = (int) $totais['quebrados'];
$qual = ['m' => [], 's' => []];
foreach ($qualidade as $q) {
    $qual[$q['tipo']][$q['qualidade']] = (int) $q['n'];
}
?>
<div class="stats-container stats-rede">
  <?= $stat('primary', 'caixa', 'Filmes', $n($totais['filmes']), $n($versoes) . ' versões no painel (dublado, legendado, 4K)') ?>
  <?= $stat('info', 'tv', 'Séries', $n($totais['series']), $n($episodios) . ' episódios já lidos · ' . $n($canais) . ' canais') ?>
  <?= $stat('success', 'download', 'Novos em 7 dias', $n($totais['novos7']), $n($totais['sem_tmdb']) . ' ainda sem dados do TMDB') ?>
  <?= $stat($quebrados ? 'danger' : 'success', 'link', 'Links quebrados', $n($quebrados),
      $testados ? $n($testados) . ' testados (' . round(($testados - $quebrados) * 100 / max(1, $testados)) . '% ok)' : 'nenhum teste ainda') ?>
</div>

<div class="grade-integracoes">
  <div class="panel">
    <div class="panel-header"><span class="panel-title"><?= Icone::svg('raio') ?>Qualidade testada</span><span class="suave pequeno">títulos ativos</span></div>
    <div class="panel-body">
      <dl class="kv">
        <?php foreach (['m' => 'Filmes', 's' => 'Séries'] as $t => $rot): ?>
          <?php foreach ($qual[$t] as $q => $total): ?>
            <dt><?= e($rot . ' · ' . ($qualTxt[$q] ?? $q)) ?></dt><dd class="num"><?= e($n($total)) ?></dd>
          <?php endforeach; ?>
        <?php endforeach; ?>
        <?php if (!$qualidade): ?><dt>Catálogo</dt><dd>vazio</dd><?php endif; ?>
      </dl>
    </div>
  </div>

  <div class="panel">
    <div class="panel-header"><span class="panel-title"><?= Icone::svg('atividade') ?>Últimos testes de link</span></div>
    <div class="tabela-rolagem">
      <table class="tabela tabela-resp">
        <thead><tr><th scope="col">Título</th><th scope="col">Resultado</th><th scope="col">Quando</th></tr></thead>
        <tbody>
        <?php foreach ($testes as $x): $ok = \Casa\Play\Api\Base::b($x['link_ok']); ?>
          <tr>
            <td data-rotulo="Título"><?= e($x['nome']) ?> <small class="suave"><?= e($tipo($x['tipo'])) ?></small></td>
            <td data-rotulo="Resultado"><span class="selo <?= $ok ? 'selo-ok' : 'selo-erro' ?>"><?= $ok ? e($qualTxt[$x['qualidade']] ?? 'ok') : 'quebrado' ?></span></td>
            <td data-rotulo="Quando" class="num"><?= e($quando($x['link_testado_em'])) ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$testes): ?><tr><td colspan="3" class="suave">O teste de links ainda não rodou.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="panel">
    <div class="panel-header"><span class="panel-title"><?= Icone::svg('download') ?>Chegaram nos últimos 7 dias</span><span class="suave pequeno"><?= count($novos) ?> mais recentes</span></div>
    <div class="tabela-rolagem">
      <table class="tabela tabela-resp">
        <thead><tr><th scope="col">Título</th><th scope="col">Tipo</th><th scope="col">Qualidade</th><th scope="col">Chegou</th></tr></thead>
        <tbody>
        <?php foreach ($novos as $x): ?>
          <tr>
            <td data-rotulo="Título"><?= e($x['nome']) ?><?= $x['ano'] ? ' <small class="suave">(' . (int) $x['ano'] . ')</small>' : '' ?></td>
            <td data-rotulo="Tipo"><?= e($tipo($x['tipo'])) ?></td>
            <td data-rotulo="Qualidade"><?= e($qualTxt[$x['qualidade']] ?? '—') ?></td>
            <td data-rotulo="Chegou" class="num"><?= e($quando($x['adicionado_em'])) ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$novos): ?><tr><td colspan="4" class="suave">Nada novo nesta semana.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="panel">
    <div class="panel-header"><span class="panel-title"><?= Icone::svg('alerta') ?>Links quebrados</span><span class="suave pequeno">o painel IPTV não entregou o vídeo</span></div>
    <div class="tabela-rolagem">
      <table class="tabela tabela-resp">
        <thead><tr><th scope="col">Título</th><th scope="col">Tipo</th><th scope="col">Testado</th></tr></thead>
        <tbody>
        <?php foreach ($quebradosTitulos as $x): ?>
          <tr>
            <td data-rotulo="Título"><?= e($x['nome']) ?><?= $x['ano'] ? ' <small class="suave">(' . (int) $x['ano'] . ')</small>' : '' ?></td>
            <td data-rotulo="Tipo"><?= e($tipo($x['tipo'])) ?></td>
            <td data-rotulo="Testado" class="num"><?= e($quando($x['link_testado_em'])) ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$quebradosTitulos): ?><tr><td colspan="3" class="suave">Nenhum link quebrado.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<p class="nota">Catálogo sincronizado pelo casa-play a partir da lista ativa. Títulos que saem do painel ficam inativos (somem das TVs) e voltam se reaparecerem.</p>
