<?php
use Casa\Auth;
use Casa\Icone;
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Entrar · Casa</title>
<script src="/assets/tema.js?v=8"></script>
<link rel="preload" href="/assets/vendor/fonts/archivo-variable.woff2" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="/assets/vendor/tabler-icons/tabler-icons.css?v=3.46.0">
<link rel="stylesheet" href="/assets/app.css?v=7">
</head>
<body class="tela-login">
<main class="login">
  <div class="login-lado" aria-hidden="true">
    <span class="sb-brand-ico grande"><?= Icone::svg('home') ?></span>
    <p class="login-eyebrow">Homeserver</p>
    <p class="login-marca">CASA</p>
    <p class="login-lede">Aparelhos, servidor e energia da casa num lugar só.</p>
  </div>
  <form class="login-caixa panel" method="post" novalidate>
    <div class="login-cab">
      <h1 class="page-title">Entrar</h1>
      <p class="page-subtitle">Acesso restrito aos moradores.</p>
    </div>
    <?php if ($semUsuarios): ?>
      <div class="aviso aviso-aviso"><?= Icone::svg('alerta') ?>
        <span>Nenhum usuário cadastrado. No servidor:<br><code>docker exec -it casa-web php bin/usuario.php criar &lt;login&gt; "Nome" admin</code></span></div>
    <?php endif; ?>
    <?php if ($erro): ?>
      <div class="aviso aviso-erro" role="alert"><?= Icone::svg('alerta') ?><span><?= e($erro) ?></span></div>
    <?php endif; ?>
    <input type="hidden" name="csrf" value="<?= e(Auth::csrf()) ?>">
    <div class="campo">
      <label class="form-label" for="login">Login</label>
      <div class="entrada">
        <?= Icone::svg('usuario') ?>
        <input class="form-input" id="login" name="login" value="<?= e($login) ?>" autocomplete="username" autocapitalize="none" spellcheck="false" required <?= $login === '' ? 'autofocus' : '' ?>>
      </div>
    </div>
    <div class="campo">
      <label class="form-label" for="senha">Senha</label>
      <div class="entrada">
        <?= Icone::svg('cadeado') ?>
        <input class="form-input" id="senha" name="senha" type="password" autocomplete="current-password" required <?= $login !== '' ? 'autofocus' : '' ?>>
        <button type="button" class="btn-icone mini" data-ver-senha="#senha" aria-label="Mostrar senha" title="Mostrar senha"><?= Icone::svg('olho') ?></button>
      </div>
    </div>
    <button class="btn btn-primario largo" data-carregando="Entrando…"><?= Icone::svg('chave') ?>Entrar</button>
  </form>
</main>
<script src="/assets/app.js?v=7"></script>
</body>
</html>
