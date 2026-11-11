<?php
use Casa\Auth;
use Casa\Icone;
use Casa\Painel;
use Casa\View;

$admin = Auth::ehAdmin($usuario);
$icones = ['tv' => 'tv', 'alimentador' => 'pata', 'pc' => 'servidor', 'tomada' => 'plug', 'interruptor' => 'power', 'lampada' => 'sol'];
?>
<div class="barra-acoes">
  <p class="contagem"><?= count($lista) ?> aparelho<?= count($lista) === 1 ? '' : 's' ?></p>
  <?php if ($admin): ?><a class="btn btn-primario" href="/aparelhos/editar"><?= Icone::svg('mais') ?>Novo aparelho</a><?php endif; ?>
</div>

<?php if (!$lista): ?>
  <div class="vazio vidro"><?= Icone::svg('tv', 'ico vazio-ico') ?><p>Nenhum aparelho cadastrado.</p></div>
<?php endif; ?>

<div class="grade-aparelhos">
<?php foreach ($lista as $d):
    $e = $d['dados'];
    $ligado = $d['online'] && !empty($e['ligado']);
    $naoPareada = ($e['pareada'] ?? null) === false;
    if (!$d['ativo']) {
        [$estado, $classe] = ['Desativado', 'selo-neutro'];
    } elseif (!empty($e['erro'])) {
        [$estado, $classe] = ['Com erro', 'selo-erro'];
    } elseif ($naoPareada) {
        [$estado, $classe] = ['Não pareada', 'selo-aviso'];
    } elseif ($ligado) {
        [$estado, $classe] = ['Ligada', 'selo-ok'];
    } elseif ($d['online']) {
        [$estado, $classe] = ['Em espera', 'selo-aviso'];
    } else {
        [$estado, $classe] = ['Desligada', 'selo-neutro'];
    }
    $local = array_filter([$d['comodo'], Painel::TIPOS[$d['tipo']] ?? $d['tipo'], $d['config']['modelo'] ?? null]);
    $ehTv = $d['tipo'] === 'tv';
    $ehAlim = $d['tipo'] === 'alimentador';
    if ($ehAlim && $d['ativo'] && empty($e['erro'])) {
        [$estado, $classe] = $d['online'] ? ['Online', 'selo-ok'] : ['Offline', 'selo-neutro'];
    }
    $ultimaRef = !empty($e['ultima_refeicao']) ? strtotime($e['ultima_refeicao']) : null;
?>
  <article class="aparelho vidro<?= $ligado ? ' ligado' : '' ?><?= $d['ativo'] ? '' : ' inativo' ?>" data-slug="<?= e($d['slug']) ?>" data-nome="<?= e($d['nome']) ?>">
    <header class="aparelho-cab">
      <span class="aparelho-ico"><?= Icone::svg($icones[$d['tipo']] ?? 'plug') ?></span>
      <div class="aparelho-nome">
        <h2><?= e($d['nome']) ?></h2>
        <p><?= e(implode(' · ', $local)) ?></p>
      </div>
      <span class="selo <?= $classe ?>"><?= e($estado) ?></span>
    </header>

    <dl class="aparelho-info">
      <?php if ($ehTv): ?>
        <div><dt>App</dt><dd><?= e($ligado ? ($e['app'] ?? '—') : '—') ?></dd></div>
        <div><dt>Volume</dt><dd class="num"><?= $ligado && isset($e['volume']) ? (int) $e['volume'] . ($e['mudo'] ?? false ? ' (mudo)' : '') : '—' ?></dd></div>
      <?php endif; ?>
      <?php if ($ehAlim): ?>
        <div><dt>Hoje</dt><dd class="num"><?= isset($e['hoje_porcoes']) ? (int) $e['hoje_porcoes'] . ' porç. em ' . (int) ($e['hoje_refeicoes'] ?? 0) . ' refeiç.' : '—' ?></dd></div>
        <div><dt>Última refeição</dt><dd><?= $ultimaRef ? e(date('H:i', $ultimaRef) . ' · ' . View::relativo($ultimaRef)) : '—' ?></dd></div>
        <div><dt>Próxima</dt><dd class="num"><?= e($e['proxima_refeicao'] ?? '—') ?></dd></div>
        <div><dt>Ração</dt><dd class="<?= in_array($e['racao'] ?? '', ['Pouca', 'Vazio'], true) ? 'msg-aviso' : '' ?>"><?= e($e['racao'] ?? '—') ?></dd></div>
      <?php else: ?>
      <div><dt>Endereço</dt><dd class="num"><?= e($d['config']['ip'] ?? '—') ?></dd></div>
      <div><dt>Siri</dt><dd><?= $d['homekit'] ? 'Sim' : 'Não' ?></dd></div>
      <?php endif; ?>
    </dl>

    <?php if ($ehTv && $ligado && isset($e['volume'])): ?>
      <div class="barra barra-volume" aria-hidden="true"><i style="width: <?= max(0, min(100, (int) $e['volume'])) ?>%"></i></div>
    <?php endif; ?>

    <?php if (!empty($e['erro'])): ?>
      <p class="msg-erro"><?= Icone::svg('alerta') ?><?= e($e['erro']) ?></p>
    <?php elseif ($naoPareada): ?>
      <p class="msg-aviso"><?= Icone::svg('link') ?>Ligue a TV, toque em <b>Parear</b> e aceite o pedido na tela.</p>
    <?php endif; ?>

    <?php if ($d['ativo']): ?>
    <div class="aparelho-controles">
      <?php if ($ehAlim): ?>
        <label class="sr" for="porc-<?= e($d['slug']) ?>">Porções</label>
        <select id="porc-<?= e($d['slug']) ?>" class="porcoes" title="Porções">
          <?php for ($i = 1; $i <= 5; $i++): ?><option value="<?= $i ?>"><?= $i ?> porç<?= $i === 1 ? 'ão' : 'ões' ?></option><?php endfor; ?>
        </select>
        <button class="btn btn-primario btn-energia" data-cmd="alimentar" data-param="porcoes" data-param-de="#porc-<?= e($d['slug']) ?>" data-slug="<?= e($d['slug']) ?>" <?= $d['online'] ? '' : 'disabled' ?>>
          <?= Icone::svg('pata') ?>Servir agora
        </button>
      <?php else: ?>
      <button class="btn <?= $ligado ? 'btn-perigo-suave' : 'btn-primario' ?> btn-energia" data-cmd="<?= $ligado ? 'desligar' : 'ligar' ?>" data-slug="<?= e($d['slug']) ?>">
        <?= Icone::svg('power') ?><?= $ligado ? 'Desligar' : 'Ligar' ?>
      </button>
      <?php endif; ?>
      <?php if ($ehTv): ?>
        <div class="grupo-botoes" role="group" aria-label="Volume de <?= e($d['nome']) ?>">
          <button class="btn-icone" data-cmd="volume_menos" data-slug="<?= e($d['slug']) ?>" aria-label="Diminuir volume" title="Diminuir volume" <?= $ligado ? '' : 'disabled' ?>><?= Icone::svg('vol-menos') ?></button>
          <?php $mudo = !empty($e['mudo']); ?>
          <button class="btn-icone<?= $mudo ? ' ativo' : '' ?>" data-cmd="mudo" data-valor="<?= $mudo ? '0' : '1' ?>" data-slug="<?= e($d['slug']) ?>" aria-pressed="<?= $mudo ? 'true' : 'false' ?>" aria-label="<?= $mudo ? 'Tirar do mudo' : 'Silenciar' ?>" title="<?= $mudo ? 'Tirar do mudo' : 'Silenciar' ?>" <?= $ligado ? '' : 'disabled' ?>><?= Icone::svg('mudo') ?></button>
          <button class="btn-icone" data-cmd="volume_mais" data-slug="<?= e($d['slug']) ?>" aria-label="Aumentar volume" title="Aumentar volume" <?= $ligado ? '' : 'disabled' ?>><?= Icone::svg('vol-mais') ?></button>
        </div>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <footer class="aparelho-rodape">
      <span><?= Icone::svg('relogio') ?>atualizado <?= e(View::dataHora($d['atualizado_em'])) ?></span>
      <span class="aparelho-admin">
        <?php if ($admin && $ehTv): ?><button class="btn-link" data-cmd="parear" data-slug="<?= e($d['slug']) ?>"><?= Icone::svg('link') ?>Parear</button><?php endif; ?>
        <?php if ($admin): ?><a class="btn-link" href="/aparelhos/editar?slug=<?= e(rawurlencode($d['slug'])) ?>"><?= Icone::svg('editar') ?>Editar</a><?php endif; ?>
      </span>
    </footer>
  </article>
<?php endforeach; ?>
</div>
