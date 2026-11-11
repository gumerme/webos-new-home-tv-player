<?php
use Casa\Icone;
use Casa\Play\Api\PainelPlayer;

$stat = static fn(string $cor, string $icone, string $rotulo, $valor, string $sub = '') =>
    '<div class="stat-card-pro"><div class="stat-card-icon ' . $cor . '">' . Icone::svg($icone) . '</div>'
    . '<div class="stat-card-label">' . e($rotulo) . '</div><div class="stat-card-value num">' . e((string) $valor) . '</div>'
    . '<div class="stat-card-sub" title="' . e($sub) . '">' . e($sub) . '</div></div>';
$s = (int) $total['s'];
$variacao = $anterior > 0 ? (int) round(($s - $anterior) * 100 / $anterior) : null;

// Matriz perfil × TV.
$tvsCol = [];
$linhas = [];
$porTv = [];
foreach ($porTvPerfil as $x) {
    $tvsCol[$x['tv']] = true;
    $linhas[$x['perfil']]['cor'] = $x['cor'];
    $linhas[$x['perfil']]['tv'][$x['tv']] = (int) $x['s'];
    $porTv[$x['tv']] = ($porTv[$x['tv']] ?? 0) + (int) $x['s'];
}
arsort($porTv);
$tvsCol = array_keys($porTv);
$maxGen = max(1, ...array_map(fn($g) => $g['s'], $generos ?: [['s' => 1]]));
?>
<nav class="filtros" aria-label="Escolher o mês">
  <?php foreach ($meses as $k => $rot): ?>
    <a href="/player/estatisticas?mes=<?= e($k) ?>" class="<?= $mes === $k ? 'ativo' : '' ?>"<?= $mes === $k ? ' aria-current="true"' : '' ?>><?= e($rot) ?></a>
  <?php endforeach; ?>
</nav>

<div class="stats-container stats-rede">
  <?= $stat('primary', 'relogio', 'Horas no mês', PainelPlayer::horas($s),
      $variacao === null ? 'sem dados em ' . $nomeAnt : ($variacao >= 0 ? '+' : '') . $variacao . '% sobre ' . $nomeAnt . ' (' . PainelPlayer::horas($anterior) . ')') ?>
  <?= $stat('info', 'caixa', 'Títulos assistidos', (int) $total['titulos'], (int) $total['sessoes'] . ' sessões') ?>
  <?= $stat('success', 'tv', 'TV mais usada', $tvsCol[0] ?? '—', $tvsCol ? PainelPlayer::horas($porTv[$tvsCol[0]]) : 'sem uso no mês') ?>
  <?= $stat('warning', 'atividade', 'Gênero da casa', $generos[0]['nome'] ?? '—', $generos ? PainelPlayer::horas($generos[0]['s']) : 'sem uso no mês') ?>
</div>

<?php if (!$porTvPerfil): ?>
  <div class="vazio"><?= Icone::svg('atividade', 'vazio-ico') ?><p>Nada assistido em <?= e($nomeMes) ?>. O tempo conta a partir do Player 2.0 (o histórico importado da v10 não tem horas).</p></div>
<?php else: ?>
<div class="grade-integracoes">
  <div class="panel">
    <div class="panel-header"><span class="panel-title"><?= Icone::svg('usuarios') ?>Horas por perfil e TV</span></div>
    <div class="tabela-rolagem">
      <table class="tabela tabela-resp">
        <thead><tr><th scope="col">Perfil</th><?php foreach ($tvsCol as $tv): ?><th scope="col"><?= e($tv) ?></th><?php endforeach; ?><th scope="col">Total</th></tr></thead>
        <tbody>
        <?php foreach ($linhas as $perfil => $l): ?>
          <tr>
            <td data-rotulo="Perfil"><strong><?= e($perfil) ?></strong></td>
            <?php foreach ($tvsCol as $tv): ?><td data-rotulo="<?= e($tv) ?>" class="num"><?= isset($l['tv'][$tv]) ? e(PainelPlayer::horas($l['tv'][$tv])) : '—' ?></td><?php endforeach; ?>
            <td data-rotulo="Total" class="num"><strong><?= e(PainelPlayer::horas(array_sum($l['tv']))) ?></strong></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="panel">
    <div class="panel-header"><span class="panel-title"><?= Icone::svg('apps') ?>Gêneros</span><span class="suave pequeno">por tempo assistido</span></div>
    <div class="panel-body">
      <ul class="lista compacta">
      <?php foreach ($generos as $g): ?>
        <li><span class="lista-nome" style="display: block; flex: 1"><span><?= e($g['nome']) ?></span>
            <span class="barra" style="margin-top: 6px" aria-hidden="true"><i style="width: <?= (int) round($g['s'] * 100 / $maxGen) ?>%"></i></span></span>
          <span class="num pequeno"><?= e(PainelPlayer::horas($g['s'])) ?></span></li>
      <?php endforeach; ?>
      </ul>
    </div>
  </div>

  <div class="panel card largo sem-padding">
    <div class="panel-header"><span class="panel-title"><?= Icone::svg('play') ?>Mais vistos em <?= e($nomeMes) ?></span></div>
    <div class="tabela-rolagem">
      <table class="tabela tabela-resp">
        <thead><tr><th scope="col">Título</th><th scope="col">Tipo</th><th scope="col">Tempo</th><th scope="col">Sessões</th><th scope="col">Perfis</th></tr></thead>
        <tbody>
        <?php foreach ($mais as $m): ?>
          <tr>
            <td data-rotulo="Título"><strong><?= e($m['nome']) ?></strong></td>
            <td data-rotulo="Tipo"><?= $m['tipo'] === 's' ? 'Série' : 'Filme' ?></td>
            <td data-rotulo="Tempo" class="num"><?= e(PainelPlayer::horas((int) $m['s'])) ?></td>
            <td data-rotulo="Sessões" class="num"><?= (int) $m['sessoes'] ?></td>
            <td data-rotulo="Perfis"><?= e($m['perfis']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php endif; ?>
<p class="nota">Tempo medido pelo Player a cada 15 s enquanto o vídeo toca (pausa não conta). Fonte: histórico do Player 2.0.</p>
