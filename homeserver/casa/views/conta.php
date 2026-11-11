<?php
use Casa\Auth;
use Casa\Icone;
use Casa\Usuarios;
use Casa\View;
?>
<div class="grade-conta">
  <section class="card vidro perfil-card">
    <span class="avatar grande"><?= e(View::iniciais($u['nome'])) ?></span>
    <h2><?= e($u['nome']) ?></h2>
    <p class="suave"><?= e($u['login']) ?> · <?= e(Usuarios::PERFIS[$u['perfil']] ?? $u['perfil']) ?></p>
    <dl class="perfil-dados">
      <div><dt>Último acesso</dt><dd class="num"><?= e(View::dataHora($u['ultimo_login'])) ?></dd></div>
      <div><dt>Conta criada</dt><dd class="num"><?= e(View::dataHora($u['criado_em'])) ?></dd></div>
    </dl>
    <?php if (Auth::ehAdmin($u)): ?>
      <nav class="atalhos-admin" aria-label="Administração">
        <a href="/usuarios"><?= Icone::svg('usuarios') ?>Usuários</a>
        <a href="/integracoes"><?= Icone::svg('plug') ?>Integrações</a>
      </nav>
    <?php endif; ?>
    <form method="post" action="/sair" class="sair-celular">
      <input type="hidden" name="csrf" value="<?= e(Auth::csrf()) ?>">
      <button class="btn largo"><?= Icone::svg('sair') ?>Sair</button>
    </form>
  </section>

  <form method="post" class="formulario vidro" data-form>
    <header><h3><span class="kpi-ico-box"><?= Icone::svg('cadeado') ?></span>Trocar senha</h3></header>
    <input type="hidden" name="csrf" value="<?= e(Auth::csrf()) ?>">
    <?php if ($u['trocar_senha']): ?><div class="aviso aviso-aviso" role="status"><?= Icone::svg('alerta') ?><span>Defina uma nova senha para continuar usando o painel.</span></div><?php endif; ?>
    <?php if ($erro): ?><div class="aviso aviso-erro" role="alert"><?= Icone::svg('alerta') ?><span><?= e($erro) ?></span></div><?php endif; ?>
    <div class="campo">
      <label for="c-atual">Senha atual</label>
      <div class="entrada"><input id="c-atual" name="atual" type="password" autocomplete="current-password" required>
        <button type="button" class="btn-icone mini" data-ver-senha="#c-atual" aria-label="Mostrar senha atual"><?= Icone::svg('olho') ?></button></div>
    </div>
    <div class="campo">
      <label for="c-nova">Nova senha</label>
      <div class="entrada"><input id="c-nova" name="nova" type="password" minlength="8" autocomplete="new-password" required aria-describedby="h-nova">
        <button type="button" class="btn-icone mini" data-ver-senha="#c-nova" aria-label="Mostrar nova senha"><?= Icone::svg('olho') ?></button></div>
      <small id="h-nova">Mínimo de 8 caracteres, diferente da atual.</small>
    </div>
    <div class="campo">
      <label for="c-conf">Confirmar nova senha</label>
      <input id="c-conf" name="confirmar" type="password" minlength="8" autocomplete="new-password" required>
    </div>
    <div class="form-acoes"><button class="btn btn-primario" data-carregando="Salvando…"><?= Icone::svg('ok') ?>Salvar senha</button></div>
  </form>
</div>
