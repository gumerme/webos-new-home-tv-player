<?php
use Casa\Auth;
use Casa\Icone;
use Casa\Usuarios;
use Casa\View;

$admin = Auth::ehAdmin($usuario);
$secoes = [
    'Casa' => [
        ['painel', '/', 'Painel', 'painel'],
        ['aparelhos', '/aparelhos', 'Aparelhos', 'tv'],
        ['rede', '/rede', 'Rede', 'roteador'],
        ['atividade', '/atividade', 'Atividade', 'atividade'],
    ],
];
// Player 2.0: listas, TVs, plataformas e tarefas só para administradores.
$secoes['Player'] = array_values(array_filter([
    $admin ? ['player-listas', '/player/listas', 'Listas e TVs', 'tv'] : null,
    ['player-perfis', '/player/perfis', 'Perfis', 'usuarios'],
    ['player-estatisticas', '/player/estatisticas', 'Estatísticas', 'atividade'],
    ['player-catalogo', '/player/catalogo', 'Catálogo e links', 'caixa'],
    $admin ? ['player-plataformas', '/player/plataformas', 'Plataformas', 'apps'] : null,
    ['player-avisos', '/player/avisos', 'Avisos', 'sino'],
    $admin ? ['player-tarefas', '/player/tarefas', 'Tarefas', 'relogio'] : null,
]));
if ($admin) {
    $secoes['Administração'] = [
        ['usuarios', '/usuarios', 'Usuários', 'usuarios'],
        ['integracoes', '/integracoes', 'Integrações', 'plug'],
    ];
}
$secoes['Conta'] = [['conta', '/conta', 'Minha conta', 'usuario']];
$v = '12';
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="csrf" content="<?= e(Auth::csrf()) ?>">
<title><?= e($titulo) ?> · Casa</title>
<script src="/assets/tema.js?v=<?= $v ?>"></script>
<link rel="preload" href="/assets/vendor/fonts/archivo-variable.woff2" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="/assets/vendor/tabler-icons/tabler-icons.css?v=3.46.0">
<link rel="stylesheet" href="/assets/app.css?v=<?= $v ?>">
</head>
<body data-pagina="<?= e($pagina) ?>">
<a class="pular" href="#conteudo">Pular para o conteúdo</a>
<div id="wrapper">
  <aside class="sb-sidebar" id="sidebar" aria-label="Menu principal">
    <a class="sb-brand" href="/">
      <span class="sb-brand-ico"><?= Icone::svg('home') ?></span>
      <span class="sb-brand-txt">CASA<small>homeserver</small></span>
    </a>
    <nav class="sb-nav">
      <?php foreach ($secoes as $secao => $itens): ?>
        <div class="sb-label"><?= e($secao) ?></div>
        <?php foreach ($itens as [$id, $href, $rotulo, $icone]): ?>
          <a href="<?= e($href) ?>" class="sb-item<?= $menu === $id ? ' active' : '' ?>"<?= $menu === $id ? ' aria-current="page"' : '' ?>>
            <?= Icone::svg($icone) ?><span><?= e($rotulo) ?></span>
          </a>
        <?php endforeach; ?>
      <?php endforeach; ?>
    </nav>
    <div class="sb-rodape">
      <span class="avatar"><?= e(View::iniciais($usuario['nome'])) ?></span>
      <span class="sb-quem"><strong><?= e($usuario['nome']) ?></strong><small><?= e(Usuarios::PERFIS[$usuario['perfil']] ?? '') ?></small></span>
      <form method="post" action="/sair">
        <input type="hidden" name="csrf" value="<?= e(Auth::csrf()) ?>">
        <button class="sb-sair" aria-label="Sair" title="Sair"><?= Icone::svg('sair') ?></button>
      </form>
    </div>
  </aside>
  <div class="sb-fundo" id="sidebar-fundo" hidden></div>

  <div id="content-wrapper">
    <header class="topbar">
      <button class="topbar-btn so-celular" id="menu-abrir" aria-label="Abrir menu" aria-controls="sidebar" aria-expanded="false"><?= Icone::svg('menu') ?></button>
      <div class="topbar-dir">
        <?php if ($pagina === 'dashboard'): ?>
          <span class="ao-vivo" id="ao-vivo" role="status" aria-atomic="true"><i></i><span>conectando…</span></span>
          <button class="topbar-btn" id="pausar" aria-pressed="false" aria-label="Pausar atualização automática" title="Pausar atualização">
            <?= Icone::svg('pausa', 'ico-pausa') ?><?= Icone::svg('play', 'ico-play') ?>
          </button>
        <?php endif; ?>
        <button class="topbar-btn" id="tema" aria-label="Alternar tema claro/escuro" title="Tema">
          <?= Icone::svg('lua', 'ico-lua') ?><?= Icone::svg('sol', 'ico-sol') ?>
        </button>
        <span class="topbar-divisor" aria-hidden="true"></span>
        <a class="topbar-usuario" href="/conta" title="Minha conta">
          <span class="nao-celular"><?= e($usuario['nome']) ?></span>
          <span class="avatar"><?= e(View::iniciais($usuario['nome'])) ?></span>
        </a>
      </div>
    </header>

    <main id="conteudo" class="container-fluid" tabindex="-1">
      <div class="cabecalho-pagina">
        <h1 class="page-title"><?= e($titulo) ?></h1>
        <?php if (!empty($subtitulo)): ?><p class="page-subtitle"><?= e($subtitulo) ?></p><?php endif; ?>
      </div>
      <?php if ($flash): ?>
        <div class="aviso aviso-<?= e($flash['tipo']) ?>" role="status">
          <?= Icone::svg($flash['tipo'] === 'ok' ? 'ok' : 'alerta') ?><span><?= e($flash['msg']) ?></span>
        </div>
      <?php endif; ?>
      <?= $conteudo ?>
    </main>

    <footer class="rodape">Casa · homeserver · <?= date('Y') ?></footer>
  </div>
</div>
<div id="toast" role="status" aria-live="polite"></div>
<script type="application/json" id="icones"><?= json_encode(Icone::todos(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES) ?></script>
<script src="/assets/app.js?v=<?= $v ?>"></script>
</body>
</html>
