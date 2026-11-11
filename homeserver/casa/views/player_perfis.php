<?php
use Casa\Auth;
use Casa\Icone;
use Casa\Play\Api\PainelPlayer;
use Casa\View;

$admin = Auth::ehAdmin($usuario);
$csrf = e(Auth::csrf());
?>
<div class="barra-acoes">
  <p class="contagem"><?= count($perfis) ?> perfi<?= count($perfis) === 1 ? 'l' : 's' ?> · sem perfil infantil · “Casa” recebe o histórico importado da v10</p>
  <?php if ($admin): ?><button type="button" class="btn btn-primario" data-alternar="#perfil-novo" aria-expanded="false" aria-controls="perfil-novo"><?= Icone::svg('mais') ?>Novo perfil</button><?php endif; ?>
</div>

<?php if ($admin): ?>
<div id="perfil-novo" hidden>
<form method="post" class="editar-form" data-form>
  <input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="acao" value="perfil_criar">
  <div class="campos">
    <div class="campo"><label for="pn-nome">Nome</label><input id="pn-nome" name="nome" maxlength="20" required placeholder="Thais"></div>
    <div class="campo"><label for="pn-cor">Cor</label><input id="pn-cor" name="cor" type="color" value="#1E1B4B"></div>
  </div>
  <div class="form-acoes"><button class="btn btn-primario" data-carregando="Criando…"><?= Icone::svg('ok') ?>Criar perfil</button></div>
</form>
</div>
<?php endif; ?>

<div class="card vidro sem-padding">
  <table class="tabela tabela-resp">
    <thead><tr><th scope="col">Perfil</th><th scope="col">TVs (padrão)</th><th scope="col">Minha lista</th><th scope="col">Títulos vistos</th>
      <th scope="col">Últimos 30 dias</th><th scope="col">Último uso</th><?php if ($admin): ?><th scope="col"><span class="sr">Ações</span></th><?php endif; ?></tr></thead>
    <tbody>
    <?php foreach ($perfis as $pf): $id = (int) $pf['id']; ?>
      <tr>
        <td data-rotulo="Perfil"><span class="pessoa">
          <span class="avatar" style="background: <?= e($pf['cor']) ?>; color: #fff"><?= e(View::iniciais($pf['nome'])) ?></span>
          <span><strong><?= e($pf['nome']) ?></strong><small class="mono"><?= e($pf['cor']) ?></small></span></span></td>
        <td data-rotulo="TVs (padrão)" class="num"><?= (int) $pf['tvs'] ?></td>
        <td data-rotulo="Minha lista" class="num"><?= (int) $pf['lista'] ?></td>
        <td data-rotulo="Títulos vistos" class="num"><?= (int) $pf['titulos'] ?></td>
        <td data-rotulo="Últimos 30 dias" class="num"><?= e(PainelPlayer::horas((int) $pf['s30'])) ?></td>
        <td data-rotulo="Último uso" class="num"><?= e($pf['ultimo'] ? View::relativo(strtotime($pf['ultimo'])) : '—') ?></td>
        <?php if ($admin): ?>
        <td class="acoes"><button type="button" class="btn-link" data-alternar="#pf-<?= $id ?>" aria-expanded="false" aria-controls="pf-<?= $id ?>"><?= Icone::svg('editar') ?>Editar</button></td>
        <?php endif; ?>
      </tr>
      <?php if ($admin): ?>
      <tr class="linha-detalhes" id="pf-<?= $id ?>" hidden>
        <td colspan="7">
          <form method="post" class="editar-form">
            <input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="acao" value="perfil_salvar"><input type="hidden" name="id" value="<?= $id ?>">
            <div class="campos">
              <div class="campo"><label for="pf<?= $id ?>-nome">Nome</label><input id="pf<?= $id ?>-nome" name="nome" maxlength="20" required value="<?= e($pf['nome']) ?>"></div>
              <div class="campo"><label for="pf<?= $id ?>-cor">Cor</label><input id="pf<?= $id ?>-cor" name="cor" type="color" value="<?= e($pf['cor']) ?>"></div>
            </div>
            <div class="form-acoes"><button class="btn btn-primario"><?= Icone::svg('ok') ?>Salvar</button></div>
          </form>
          <?php if (count($perfis) > 1): ?>
          <form method="post" data-confirmar="Apagar o perfil &quot;<?= e($pf['nome']) ?>&quot;? Progresso, lista, notas e histórico dele somem.">
            <input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="acao" value="perfil_apagar"><input type="hidden" name="id" value="<?= $id ?>">
            <button class="btn-link perigo"><?= Icone::svg('x') ?>Apagar perfil</button>
          </form>
          <?php else: ?>
          <p class="nota">É o único perfil: não dá para apagar.</p>
          <?php endif; ?>
        </td>
      </tr>
      <?php endif; ?>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<p class="nota">As TVs também podem criar perfis pela tela de perfis do Player. O perfil padrão de cada TV fica em Listas e TVs.</p>
