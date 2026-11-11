<?php
use Casa\Auth;
use Casa\Icone;
?>
<a class="voltar" href="/aparelhos"><?= Icone::svg('voltar') ?>Aparelhos</a>
<form method="post" class="formulario vidro" data-form>
  <input type="hidden" name="csrf" value="<?= e(Auth::csrf()) ?>">
  <?php if ($erro): ?><div class="aviso aviso-erro" role="alert"><?= Icone::svg('alerta') ?><span><?= e($erro) ?></span></div><?php endif; ?>

  <fieldset>
    <legend>Identificação</legend>
    <div class="campos">
      <div class="campo">
        <label for="f-nome">Nome</label>
        <input id="f-nome" name="nome" value="<?= e($form['nome']) ?>" required aria-describedby="h-nome">
        <small id="h-nome">É o nome que a Siri usa: “Ligar a <?= e($form['nome'] ?: 'TV da sala') ?>”.</small>
      </div>
      <div class="campo">
        <label for="f-slug">Identificador</label>
        <input id="f-slug" name="slug" value="<?= e($form['slug']) ?>" pattern="[a-z0-9][a-z0-9\-]{0,40}" required <?= $d ? 'readonly' : '' ?> aria-describedby="h-slug" spellcheck="false" autocapitalize="none">
        <small id="h-slug">Minúsculas, números e hífen (ex.: tv-sala). Não muda depois de criado.</small>
      </div>
      <div class="campo">
        <label for="f-comodo">Cômodo</label>
        <input id="f-comodo" name="comodo" value="<?= e($form['comodo'] ?? '') ?>" placeholder="Sala, Quarto…">
      </div>
    </div>
  </fieldset>

  <fieldset>
    <legend>Tipo e conexão</legend>
    <div class="campos">
      <div class="campo">
        <label for="f-tipo">Tipo</label>
        <select id="f-tipo" name="tipo"><?php foreach ($tipos as $k => $v): ?><option value="<?= e($k) ?>" <?= $form['tipo'] === $k ? 'selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?></select>
      </div>
      <div class="campo">
        <label for="f-driver">Driver</label>
        <select id="f-driver" name="driver"><?php foreach ($drivers as $k): ?><option <?= $form['driver'] === $k ? 'selected' : '' ?>><?= e($k) ?></option><?php endforeach; ?></select>
      </div>
      <div class="campo campo-largo">
        <label for="f-config">Configuração (JSON)</label>
        <textarea id="f-config" name="config" rows="8" spellcheck="false" aria-describedby="h-config"><?= e($configTxt) ?></textarea>
        <small id="h-config">TV LG: <code>{"ip":"192.168.1.51","rede":"cabo","apps":[{"id":"netflix","nome":"Netflix"}]}</code>.
          As chaves são mescladas com as atuais; a chave de pareamento da TV fica guardada e não aparece aqui.</small>
      </div>
    </div>
  </fieldset>

  <fieldset>
    <legend>Disponibilidade</legend>
    <label class="interruptor"><input type="checkbox" name="homekit" value="1" <?= $form['homekit'] ? 'checked' : '' ?>><span class="interruptor-trilho"></span>Mostrar na Siri e no app Casa</label>
    <label class="interruptor"><input type="checkbox" name="ativo" value="1" <?= $form['ativo'] ? 'checked' : '' ?>><span class="interruptor-trilho"></span>Ativo (o servidor consulta e controla)</label>
  </fieldset>

  <div class="form-acoes">
    <a class="btn" href="/aparelhos">Cancelar</a>
    <button class="btn btn-primario" data-carregando="Salvando…"><?= Icone::svg('ok') ?>Salvar</button>
  </div>
</form>
