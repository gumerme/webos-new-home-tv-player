<?php
use Casa\Icone;

$icones = ['comando' => 'tv', 'aparelho' => 'tv', 'sistema' => 'servidor', 'backup' => 'arquivo', 'rede' => 'globo',
           'acesso' => 'cadeado', 'usuario' => 'usuario', 'config' => 'editar', 'seguranca' => 'escudo'];
$origemTxt = static function (?string $o): string {
    if ($o === null || $o === '') {
        return '';
    }
    if (str_starts_with($o, 'painel:')) {
        return 'por ' . substr($o, 7);
    }
    return ['homekit' => 'pela Siri', 'api' => 'pela API', 'worker' => 'servidor', 'coletor' => 'servidor', 'backup' => 'servidor', 'painel' => 'pelo painel'][$o] ?? $o;
};
?>
<nav class="filtros" aria-label="Filtrar atividade">
  <?php foreach ($tipos as $k => $rotulo): ?>
    <a href="/atividade<?= $k !== '' ? '?tipo=' . e($k) : '' ?>" class="<?= $tipo === $k ? 'ativo' : '' ?>"<?= $tipo === $k ? ' aria-current="true"' : '' ?>><?= e($rotulo) ?></a>
  <?php endforeach; ?>
</nav>

<?php if (!$itens): ?>
  <div class="vazio vidro"><?= Icone::svg('atividade', 'ico vazio-ico') ?><p>Nada registrado por aqui ainda.</p></div>
<?php else: ?>
<section class="card vidro">
  <ol class="linha-tempo grande">
    <?php
    $diaAnt = null;
    foreach ($itens as $i):
        $dia = date('Y-m-d', (int) $i['ts']);
        if ($dia !== $diaAnt):
            $diaAnt = $dia;
            $rotDia = $dia === date('Y-m-d') ? 'Hoje' : ($dia === date('Y-m-d', strtotime('-1 day')) ? 'Ontem' : date('d/m/Y', (int) $i['ts']));
    ?>
      <li class="linha-dia"><?= e($rotDia) ?></li>
    <?php endif; ?>
      <li class="lt-item nivel-<?= e($i['nivel']) ?>">
        <span class="lt-ico"><?= Icone::svg($icones[$i['tipo']] ?? 'atividade') ?></span>
        <div class="lt-txt">
          <p class="lt-titulo"><?= e($i['titulo']) ?></p>
          <?php if ($i['detalhe'] || $i['origem']): ?>
            <p class="lt-detalhe"><?= e(implode(' · ', array_filter([$i['detalhe'], $i['tipo'] === 'acesso' ? 'IP ' . $i['origem'] : $origemTxt($i['origem'])]))) ?></p>
          <?php endif; ?>
        </div>
        <time class="lt-hora num" datetime="<?= e(date('c', (int) $i['ts'])) ?>" data-ts="<?= (int) $i['ts'] ?>"><?= e(date('H:i', (int) $i['ts'])) ?></time>
      </li>
    <?php endforeach; ?>
  </ol>
</section>
<?php endif; ?>
