<?php
use Casa\Auth;
use Casa\Icone;

$cab = static fn(string $icone, string $titulo, string $extra = '') =>
    '<div class="panel-header"><span class="panel-title">' . Icone::svg($icone) . e($titulo) . '</span>' . $extra . '</div>';
$stat = static fn(string $id, string $cor, string $icone, string $rotulo, bool $barra = false) =>
    '<div class="stat-card-pro" id="' . $id . '"><div class="stat-card-icon ' . $cor . '">' . Icone::svg($icone) . '</div>'
    . '<div class="stat-card-label">' . e($rotulo) . '</div><div class="stat-card-value num">—</div>'
    . '<div class="stat-card-sub"></div>' . ($barra ? '<span class="barra"><i></i></span>' : '') . '</div>';
?>
<div class="stats-container">
  <div class="stat-card-pro stat-situacao" id="s-situacao">
    <div class="stat-card-icon primary" id="s-situacao-ico"><?= Icone::svg('relogio') ?></div>
    <div class="stat-card-label">Situação · <?= e($info['hostname']) ?></div>
    <div class="stat-card-value">Verificando…</div>
    <div class="stat-card-sub"><?= e($info['sistema']) ?> · ligado há <span id="uptime" class="num">—</span></div>
  </div>
  <?= $stat('s-cpu', 'primary', 'cpu', 'Processador', true) ?>
  <?= $stat('s-ram', 'info', 'ram', 'Memória', true) ?>
  <?= $stat('s-temp', 'primary', 'termo', 'Temperatura', true) ?>
  <?= $stat('s-energia', 'warning', 'raio', 'Energia na tomada') ?>
  <?= $stat('s-custo', 'warning', 'moeda', 'Custo estimado') ?>
</div>

<section class="grade-painel">
  <div class="panel a-atencao" aria-live="polite">
    <?= $cab('sino', 'Pontos de atenção', '<span class="badge-count" id="n-alertas">0</span>') ?>
    <div class="panel-body"><ul class="alertas" id="l-alertas"><li class="lista-vazia">carregando…</li></ul></div>
  </div>

  <div class="panel a-servicos">
    <?= $cab('caixa', 'Serviços', '<span class="suave pequeno" id="n-servicos"></span>') ?>
    <div class="panel-body"><ul class="lista" id="l-servicos"><li class="lista-vazia">carregando…</li></ul></div>
  </div>

  <div class="panel a-aparelhos">
    <?= $cab('tv', 'Aparelhos', '<a class="link-sutil" href="/aparelhos">Abrir</a>') ?>
    <div class="panel-body"><ul class="lista" id="l-aparelhos"><li class="lista-vazia">carregando…</li></ul></div>
  </div>

  <div class="panel a-internet">
    <?= $cab('globo', 'Internet', '<span class="mp-badge mp-badge-secondary" id="net-selo">—</span>') ?>
    <div class="panel-body">
      <div class="destaque-num"><span class="num" id="net-ms">—</span><span class="unid">ms até a internet</span></div>
      <div class="grafico-box mini" data-grafico="internet-mini"></div>
      <dl class="kv" id="kv-internet"></dl>
    </div>
  </div>

  <div class="panel a-backups">
    <?= $cab('arquivo', 'Backups') ?>
    <div class="panel-body"><dl class="kv" id="kv-backups"></dl></div>
  </div>

  <div class="panel a-manutencao">
    <?= $cab('download', 'Manutenção') ?>
    <div class="panel-body"><dl class="kv" id="kv-manutencao"></dl></div>
  </div>

  <div class="panel a-seguranca">
    <?= $cab('escudo', 'Segurança e acessos') ?>
    <div class="panel-body"><dl class="kv" id="kv-seguranca"></dl><ul class="lista compacta" id="l-ssh"></ul></div>
  </div>

  <div class="panel a-recursos">
    <?= $cab('disco', 'Armazenamento e energia', Auth::ehAdmin($usuario) ? '<a class="link-sutil" href="/integracoes#energia">Tarifa</a>' : '') ?>
    <div class="panel-body">
      <div class="disco" id="d-raiz"><div class="disco-linha"><span>Sistema</span><span class="num">—</span></div><div class="barra"><i></i></div></div>
      <div class="disco" id="d-home"><div class="disco-linha"><span>/home</span><span class="num">—</span></div><div class="barra"><i></i></div></div>
      <dl class="kv" id="kv-energia"></dl>
    </div>
  </div>

  <div class="panel a-atividade">
    <?= $cab('atividade', 'Atividade recente', '<a class="link-sutil" href="/atividade">Ver tudo</a>') ?>
    <div class="panel-body"><ol class="linha-tempo" id="l-atividade"><li class="lista-vazia">carregando…</li></ol></div>
  </div>
</section>

<div class="secao-cab">
  <h2 class="secao-titulo">Histórico</h2>
  <div class="segmentado" role="radiogroup" aria-label="Período dos gráficos">
    <button role="radio" aria-checked="false" data-periodo="6">6 h</button>
    <button role="radio" aria-checked="true" data-periodo="24">24 h</button>
    <button role="radio" aria-checked="false" data-periodo="168">7 dias</button>
  </div>
</div>
<section class="graficos">
  <div class="panel"><?= $cab('cpu', 'Processador', '<span class="leitura" id="l-cpu"></span>') ?><div class="panel-body"><div class="grafico-box" data-grafico="cpu"></div></div></div>
  <div class="panel"><?= $cab('ram', 'Memória', '<span class="leitura" id="l-ram"></span>') ?><div class="panel-body"><div class="grafico-box" data-grafico="ram"></div></div></div>
  <div class="panel"><?= $cab('raio', 'Energia na tomada', '<span class="leitura destaque" id="l-energia"></span>') ?><div class="panel-body"><div class="grafico-box" data-grafico="energia"></div></div></div>
  <div class="panel"><?= $cab('termo', 'Temperatura', '<span class="leitura" id="l-temp"></span>') ?><div class="panel-body"><div class="grafico-box" data-grafico="temp"></div></div></div>
  <div class="panel"><?= $cab('rede', 'Tráfego de rede', '<span class="leitura"><i class="leg leg-1"></i>baixando <b id="l-rx">—</b><i class="leg leg-2"></i>enviando <b id="l-tx">—</b></span>') ?><div class="panel-body"><div class="grafico-box" data-grafico="rede"></div></div></div>
  <div class="panel"><?= $cab('globo', 'Latência', '<span class="leitura"><i class="leg leg-1"></i>internet <i class="leg leg-2"></i>roteador</span>') ?><div class="panel-body"><div class="grafico-box" data-grafico="latencia"></div></div></div>
</section>
