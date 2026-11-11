<?php
use Casa\Auth;
use Casa\Icone;
use Casa\Play\Api\Base;
use Casa\Play\Api\PainelPlayer;
use Casa\View;

$csrf = e(Auth::csrf());
?>
<?php if (!$tarefas): ?>
  <div class="vazio"><?= Icone::svg('relogio', 'vazio-ico') ?><p>O casa-play ainda não registrou tarefas. Veja <code>docker logs casa-play</code>.</p></div>
<?php else: ?>
<div class="panel">
  <div class="panel-header"><span class="panel-title"><?= Icone::svg('relogio') ?>Tarefas do casa-play</span><span class="suave pequeno">atualize a página para ver o andamento</span></div>
  <div class="tabela-rolagem">
    <table class="tabela tabela-resp">
      <thead><tr><th scope="col">Tarefa</th><th scope="col">Situação</th><th scope="col">Última</th><th scope="col">Duração</th>
        <th scope="col">Próxima</th><th scope="col">Mensagem</th><th scope="col"><span class="sr">Ações</span></th></tr></thead>
      <tbody>
      <?php foreach ($tarefas as $t):
          $forcada = Base::b($t['forcar']);
          $selo = ['ok' => 'selo-ok', 'erro' => 'selo-erro', 'rodando' => 'selo-info'][$t['status']] ?? 'selo-neutro';
      ?>
        <tr>
          <td data-rotulo="Tarefa"><strong><?= e(PainelPlayer::TAREFAS[$t['nome']] ?? $t['nome']) ?></strong> <small class="suave mono"><?= e($t['nome']) ?></small></td>
          <td data-rotulo="Situação"><span class="selo <?= $selo ?>"><?= e($t['status'] ?: '—') ?></span>
            <?php if ($forcada): ?><span class="selo selo-aviso">pedida agora</span><?php endif; ?></td>
          <td data-rotulo="Última" class="num"><?= $t['ultima_em'] ? e(date('d/m H:i', strtotime($t['ultima_em'])) . ' · ' . View::relativo((int) $t['ultima_ts'])) : '—' ?></td>
          <td data-rotulo="Duração" class="num"><?= e(PainelPlayer::duracaoMs($t['duracao_ms'] !== null ? (int) $t['duracao_ms'] : null)) ?></td>
          <td data-rotulo="Próxima" class="num"><?= $t['proxima_em'] ? e(date('d/m H:i', strtotime($t['proxima_em']))) : '—' ?></td>
          <td data-rotulo="Mensagem" class="<?= $t['status'] === 'erro' ? 'msg-erro' : 'suave' ?>" title="<?= e((string) $t['mensagem']) ?>"><?= e(mb_strimwidth((string) $t['mensagem'], 0, 90, '…')) ?: '—' ?></td>
          <td class="acoes">
            <form method="post" style="display: inline">
              <input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="nome" value="<?= e($t['nome']) ?>">
              <button class="btn" <?= $forcada || $t['status'] === 'rodando' ? 'disabled' : '' ?> data-carregando="Pedindo…"><?= Icone::svg('play') ?>Rodar agora</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>
<p class="nota">“Rodar agora” marca a tarefa e acorda o casa-play (NOTIFY play_tarefa); ela roda assim que a tarefa atual terminar.</p>
