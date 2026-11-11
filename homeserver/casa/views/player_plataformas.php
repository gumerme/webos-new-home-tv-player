<?php
use Casa\Auth;
use Casa\Icone;
use Casa\Play\Api\Base;
use Casa\View;
?>
<form method="post" class="panel" data-form>
  <input type="hidden" name="csrf" value="<?= e(Auth::csrf()) ?>">
  <div class="panel-header">
    <span class="panel-title"><?= Icone::svg('apps') ?>Top 10 por plataforma</span>
    <span class="suave pequeno">JustWatch Brasil · séries e filmes separados</span>
  </div>
  <div class="tabela-rolagem">
    <table class="tabela tabela-resp">
      <thead><tr><th scope="col">Plataforma</th><th scope="col">No Top 10</th><th scope="col">Ordem</th><th scope="col">Última coleta</th><th scope="col">Na lista</th></tr></thead>
      <tbody>
      <?php foreach ($lista as $pl): $cod = e($pl['codigo']); ?>
        <tr class="<?= Base::b($pl['ativa']) ? '' : 'inativo' ?>">
          <td data-rotulo="Plataforma"><strong><?= e($pl['nome']) ?></strong> <small class="suave mono"><?= $cod ?></small></td>
          <td data-rotulo="No Top 10">
            <label class="interruptor"><input type="checkbox" name="ativa[]" value="<?= $cod ?>" <?= Base::b($pl['ativa']) ? 'checked' : '' ?>><span class="interruptor-trilho"></span><span class="sr">Mostrar <?= e($pl['nome']) ?></span></label>
          </td>
          <td data-rotulo="Ordem"><label class="sr" for="o-<?= $cod ?>">Ordem de <?= e($pl['nome']) ?></label>
            <input id="o-<?= $cod ?>" name="ordem[<?= $cod ?>]" type="number" min="1" max="99" value="<?= (int) $pl['ordem'] ?>" style="width: 80px"></td>
          <td data-rotulo="Última coleta" class="num"><?= $pl['coleta'] ? e(View::relativo(strtotime($pl['coleta']))) : '—' ?></td>
          <td data-rotulo="Na lista" class="num"><?= $pl['coleta'] ? (int) $pl['na_lista'] . ' títulos' : '—' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="panel-body form-acoes">
    <button class="btn btn-primario" data-carregando="Salvando…"><?= Icone::svg('ok') ?>Salvar</button>
  </div>
</form>
<p class="nota">Cada plataforma ativa vira uma fileira no Início e no Ranking, na ordem acima (menor primeiro). Títulos do Top 10 que não estão na lista
  aparecem apagados com “Avisar quando chegar”. A coleta é de hora em hora; a seta compara a posição de hoje com a última de ontem.</p>
