<?php
use Casa\Auth;
use Casa\Icone;
use Casa\Play\Api\Base;
use Casa\View;

$admin = Auth::ehAdmin($usuario);
$csrf = e(Auth::csrf());
$tipos = ['jogo' => ['Jogo', 'globo'], 'programa' => ['Programa', 'tv'], 'episodio' => ['Episódio', 'play'],
          'chegar' => ['Chegar na lista', 'download'], 'vencimento' => ['Vencimento', 'relogio']];
$linha = static function (array $a, bool $ativo) use ($tipos, $admin, $csrf): string {
    [$rot, $ico] = $tipos[$a['tipo']] ?? [$a['tipo'], 'sino'];
    $alvo = Base::json($a['alvo']);
    $texto = $a['texto'] ?: ($alvo['nome'] ?? $alvo['titulo'] ?? '');
    $quando = $ativo ? $a['quando'] : $a['disparado_em'];
    ob_start(); ?>
    <tr>
      <td data-rotulo="Aviso"><span class="rede-nome"><span class="rede-ico"><?= Icone::svg($ico) ?></span>
        <span><strong><?= e($texto ?: '—') ?></strong><small><?= e($rot) ?></small></span></span></td>
      <td data-rotulo="Perfil"><?= e($a['perfil'] ?? 'Todos') ?></td>
      <td data-rotulo="<?= $ativo ? 'Quando' : 'Disparado' ?>" class="num"><?= $quando ? e(date('d/m H:i', strtotime($quando))) : ($ativo ? 'quando chegar' : '—') ?></td>
      <td data-rotulo="Criado" class="num"><?= e(View::relativo(strtotime($a['criado_em']))) ?></td>
      <?php if ($ativo && $admin): ?>
      <td class="acoes"><form method="post" data-confirmar="Cancelar este aviso?" style="display: inline">
        <input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
        <button class="btn-link perigo"><?= Icone::svg('x') ?>Cancelar</button></form></td>
      <?php elseif ($ativo): ?><td></td><?php endif; ?>
    </tr>
<?php return (string) ob_get_clean();
};
?>
<div class="panel">
  <div class="panel-header"><span class="panel-title"><?= Icone::svg('sino') ?>Ativos</span><span class="suave pequeno"><?= count($ativos) ?> aviso(s)</span></div>
  <?php if (!$ativos): ?>
    <div class="panel-body"><p class="suave">Nenhum aviso ativo. Na TV: “Avisar” em um jogo, programa, episódio ou título que ainda não está na lista.</p></div>
  <?php else: ?>
  <div class="tabela-rolagem">
    <table class="tabela tabela-resp">
      <thead><tr><th scope="col">Aviso</th><th scope="col">Perfil</th><th scope="col">Quando</th><th scope="col">Criado</th><th scope="col"><span class="sr">Ações</span></th></tr></thead>
      <tbody><?php foreach ($ativos as $a) { echo $linha($a, true); } ?></tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<div class="panel">
  <div class="panel-header"><span class="panel-title"><?= Icone::svg('ok') ?>Disparados</span><span class="suave pequeno">últimos 30 dias</span></div>
  <?php if (!$disparados): ?>
    <div class="panel-body"><p class="suave">Nenhum aviso disparado nos últimos 30 dias.</p></div>
  <?php else: ?>
  <div class="tabela-rolagem">
    <table class="tabela tabela-resp">
      <thead><tr><th scope="col">Aviso</th><th scope="col">Perfil</th><th scope="col">Disparado</th><th scope="col">Criado</th></tr></thead>
      <tbody><?php foreach ($disparados as $a) { echo $linha($a, false); } ?></tbody>
    </table>
  </div>
  <?php endif; ?>
</div>
<p class="nota">Jogo e programa avisam 5 minutos antes, nas TVs do perfil (dentro do Player) e, com o Player fechado, por notificação na tela da TV ligada.
  Vencimento da lista avisa 15, 7 e 1 dia antes.</p>
