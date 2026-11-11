<?php
use Casa\Icone;
use Casa\Usuarios;
use Casa\View;
?>
<div class="barra-acoes">
  <p class="contagem"><?= count($lista) ?> usuário<?= count($lista) === 1 ? '' : 's' ?></p>
  <a class="btn btn-primario" href="/usuarios/editar"><?= Icone::svg('mais') ?>Novo usuário</a>
</div>
<div class="card vidro sem-padding">
  <table class="tabela tabela-resp">
    <thead><tr><th scope="col">Usuário</th><th scope="col">Perfil</th><th scope="col">Situação</th><th scope="col">Último acesso</th><th scope="col"><span class="sr">Ações</span></th></tr></thead>
    <tbody>
    <?php foreach ($lista as $u): ?>
      <tr class="<?= $u['ativo'] ? '' : 'inativo' ?>">
        <td data-rotulo="Usuário">
          <span class="pessoa">
            <span class="avatar"><?= e(View::iniciais($u['nome'])) ?></span>
            <span><strong><?= e($u['nome']) ?></strong><?= $u['id'] === $usuario['id'] ? ' <span class="suave">(você)</span>' : '' ?><small><?= e($u['login']) ?></small></span>
          </span>
        </td>
        <td data-rotulo="Perfil"><span class="selo <?= $u['perfil'] === 'admin' ? 'selo-info' : 'selo-neutro' ?>"><?= e(Usuarios::PERFIS[$u['perfil']] ?? $u['perfil']) ?></span></td>
        <td data-rotulo="Situação">
          <span class="selo <?= $u['ativo'] ? 'selo-ok' : 'selo-neutro' ?>"><?= $u['ativo'] ? 'Ativo' : 'Inativo' ?></span>
          <?php if ($u['trocar_senha']): ?><span class="selo selo-aviso">Trocar senha</span><?php endif; ?>
        </td>
        <td data-rotulo="Último acesso" class="num"><?= e(View::dataHora($u['ultimo_login'])) ?></td>
        <td class="acoes"><a class="btn-link" href="/usuarios/editar?id=<?= (int) $u['id'] ?>"><?= Icone::svg('editar') ?>Editar</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
