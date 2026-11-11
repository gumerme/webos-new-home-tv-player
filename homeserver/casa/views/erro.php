<?php use Casa\Icone; ?>
<div class="vazio vidro">
  <?= Icone::svg('alerta', 'ico vazio-ico') ?>
  <p class="vazio-codigo num"><?= (int) $codigo ?></p>
  <p><?= e($mensagem) ?></p>
  <a class="btn" href="/"><?= Icone::svg('voltar') ?>Voltar ao painel</a>
</div>
