<?php
use Casa\Auth;
use Casa\Icone;
?>
<a class="voltar" href="/usuarios"><?= Icone::svg('voltar') ?>Usuários</a>
<form method="post" class="formulario vidro" data-form>
  <input type="hidden" name="csrf" value="<?= e(Auth::csrf()) ?>">
  <?php if ($erro): ?><div class="aviso aviso-erro" role="alert"><?= Icone::svg('alerta') ?><span><?= e($erro) ?></span></div><?php endif; ?>

  <fieldset>
    <legend>Dados</legend>
    <div class="campos">
      <div class="campo">
        <label for="u-nome">Nome</label>
        <input id="u-nome" name="nome" value="<?= e($form['nome']) ?>" required autocomplete="name">
      </div>
      <div class="campo">
        <label for="u-login">Login</label>
        <input id="u-login" name="login" value="<?= e($form['login']) ?>" pattern="[a-z0-9._\-]{3,40}" autocapitalize="none" spellcheck="false" required aria-describedby="h-login">
        <small id="h-login">3 a 40 caracteres: minúsculas, números, ponto e hífen.</small>
      </div>
    </div>
  </fieldset>

  <fieldset>
    <legend>Perfil</legend>
    <div class="opcoes">
      <?php foreach ($perfis as $k => $v): ?>
        <label class="opcao">
          <input type="radio" name="perfil" value="<?= e($k) ?>" <?= $form['perfil'] === $k ? 'checked' : '' ?>>
          <span class="opcao-corpo">
            <strong><?= e($v) ?></strong>
            <small><?= $k === 'admin' ? 'Tudo, inclusive usuários, cadastro de aparelhos e integrações.' : 'Painel, aparelhos, histórico e a própria senha.' ?></small>
          </span>
        </label>
      <?php endforeach; ?>
    </div>
  </fieldset>

  <fieldset>
    <legend>Acesso</legend>
    <div class="campos">
      <div class="campo">
        <label for="u-senha"><?= $alvo ? 'Nova senha' : 'Senha' ?></label>
        <div class="entrada">
          <input id="u-senha" name="senha" type="password" minlength="8" autocomplete="new-password" <?= $alvo ? '' : 'required' ?> aria-describedby="h-senha">
          <button type="button" class="btn-icone mini" data-ver-senha="#u-senha" aria-label="Mostrar senha"><?= Icone::svg('olho') ?></button>
        </div>
        <small id="h-senha"><?= $alvo ? 'Deixe em branco para manter a atual. ' : '' ?>Mínimo de 8 caracteres.</small>
      </div>
    </div>
    <label class="interruptor"><input type="checkbox" name="ativo" value="1" <?= $form['ativo'] ? 'checked' : '' ?>><span class="interruptor-trilho"></span>Ativo (pode entrar no painel)</label>
    <label class="interruptor"><input type="checkbox" name="trocar_senha" value="1" <?= $form['trocar_senha'] ? 'checked' : '' ?>><span class="interruptor-trilho"></span>Pedir troca de senha no próximo acesso</label>
  </fieldset>

  <div class="form-acoes">
    <a class="btn" href="/usuarios">Cancelar</a>
    <button class="btn btn-primario" data-carregando="Salvando…"><?= Icone::svg('ok') ?>Salvar</button>
  </div>
</form>
