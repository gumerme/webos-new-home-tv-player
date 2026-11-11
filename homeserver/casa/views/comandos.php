<?php
use Casa\Icone;
use Casa\View;

$totais = ['todos' => count($comandos), 'ok' => 0, 'erro' => 0];
foreach ($comandos as $c) {
    if (isset($totais[$c['status']])) {
        $totais[$c['status']]++;
    }
}
$origemTxt = static function (string $o): string {
    if (str_starts_with($o, 'painel:')) {
        return 'Painel · ' . substr($o, 7);
    }
    return ['homekit' => 'Siri / app Casa', 'api' => 'API', 'automacao' => 'Automação', 'assistente' => 'Assistente', 'painel' => 'Painel'][$o] ?? $o;
};
?>
<div class="barra-acoes">
  <div class="segmentado" role="radiogroup" aria-label="Filtrar por resultado" data-filtro=".tabela-resp tbody tr">
    <button role="radio" aria-checked="true" data-filtrar="">Todos <span class="num"><?= $totais['todos'] ?></span></button>
    <button role="radio" aria-checked="false" data-filtrar="ok">Concluídos <span class="num"><?= $totais['ok'] ?></span></button>
    <button role="radio" aria-checked="false" data-filtrar="erro">Com erro <span class="num"><?= $totais['erro'] ?></span></button>
  </div>
</div>

<?php if (!$comandos): ?>
  <div class="vazio vidro"><?= Icone::svg('historico', 'ico vazio-ico') ?><p>Nenhum comando enviado ainda.</p></div>
<?php else: ?>
<div class="card vidro sem-padding">
  <table class="tabela tabela-resp">
    <thead><tr><th scope="col">Quando</th><th scope="col">Aparelho</th><th scope="col">Ação</th><th scope="col">Origem</th><th scope="col">Resultado</th></tr></thead>
    <tbody>
    <?php foreach ($comandos as $c):
        $param = $c['parametros'] ? ' ' . json_encode($c['parametros'], JSON_UNESCAPED_UNICODE) : '';
        $erro = $c['resposta']['erro'] ?? null;
        $selo = ['ok' => ['selo-ok', 'ok', 'Concluído'], 'erro' => ['selo-erro', 'x', 'Erro']][$c['status']] ?? ['selo-aviso', 'relogio', ucfirst($c['status'])];
    ?>
      <tr data-status="<?= e($c['status']) ?>">
        <td data-rotulo="Quando" class="num"><?= e(View::dataHora($c['criado_em'])) ?></td>
        <td data-rotulo="Aparelho"><strong><?= e($c['slug']) ?></strong></td>
        <td data-rotulo="Ação"><code><?= e($c['acao'] . $param) ?></code></td>
        <td data-rotulo="Origem" class="suave"><?= e($origemTxt($c['origem'])) ?></td>
        <td data-rotulo="Resultado">
          <span class="selo <?= $selo[0] ?>"><?= Icone::svg($selo[1]) ?><?= e($selo[2]) ?></span>
          <?php if ($erro): ?><p class="msg-erro compacta"><?= e($erro) ?></p><?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>
