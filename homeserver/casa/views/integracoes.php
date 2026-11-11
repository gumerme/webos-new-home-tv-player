<?php
use Casa\Auth;
use Casa\Config;
use Casa\Icone;
?>
<div class="grade-integracoes">
  <section class="card vidro">
    <header><h3><span class="kpi-ico-box verde"><?= Icone::svg('home') ?></span>Apple Home e Siri</h3></header>
    <ol class="passos">
      <li>No iPhone, abra o app <b>Casa</b> e toque em <b>+</b> › <b>Adicionar Acessório</b>.</li>
      <li>Toque em <b>Mais opções</b> e escolha <b>Casa</b>.</li>
      <li>Digite o código abaixo. Cada TV entra à parte, com o mesmo código.</li>
    </ol>
    <div class="codigo-pin num" aria-label="Código de pareamento"><?= e($pin ?: '—') ?></div>
    <p class="nota">Fora de casa e automações exigem um hub Apple (Apple TV, HomePod ou iPad que fica em casa).</p>
  </section>

  <section class="card vidro">
    <header><h3><span class="kpi-ico-box"><?= Icone::svg('chave') ?></span>Token da casa</h3></header>
    <p class="suave">Usado pelo Homebridge e pelo app do iPhone para falar com a API.</p>
    <div class="segredo">
      <code id="token" class="num" data-valor="<?= e($token) ?>"><?= e(str_repeat('•', 24)) ?></code>
      <div class="segredo-acoes">
        <button class="btn" data-revelar="#token" aria-controls="token"><?= Icone::svg('olho') ?><span>Mostrar</span></button>
        <button class="btn" data-copiar="#token"><?= Icone::svg('copiar') ?>Copiar</button>
      </div>
    </div>
    <p class="nota">Fica em <code>/srv/casa/.env</code> (<code>CASA_TOKEN</code>). Para trocar: editar o .env e o
      <code>homebridge/config.json</code> e rodar <code>docker compose up -d</code>.</p>
  </section>

  <form class="card vidro largo formulario-inline" method="post" id="energia" data-form>
    <header><h3><span class="kpi-ico-box ambar"><?= Icone::svg('raio') ?></span>Energia e tarifa</h3></header>
    <input type="hidden" name="csrf" value="<?= e(Auth::csrf()) ?>">
    <input type="hidden" name="acao" value="tarifa">
    <?php if (!empty($erroTarifa)): ?><div class="aviso aviso-erro" role="alert"><?= Icone::svg('alerta') ?><span><?= e($erroTarifa) ?></span></div><?php endif; ?>

    <?php if ($energia['media24_w'] !== null): ?>
      <div class="energia-resumo">
        <div><span>Medido (CPU + RAM)</span><strong class="num"><?= e(number_format($energia['rapl24_w'], 1, ',', '.')) ?> W</strong></div>
        <div class="seta" aria-hidden="true">→</div>
        <div><span>Estimado na tomada</span><strong class="num"><?= e(number_format($energia['media24_w'], 1, ',', '.')) ?> W</strong></div>
        <div><span>Por mês</span><strong class="num"><?= e(number_format($energia['kwh_mes'], 1, ',', '.')) ?> kWh</strong></div>
        <?php if ($energia['reais_mes'] !== null): ?><div><span>Custo/mês</span><strong class="num">R$ <?= e(number_format($energia['reais_mes'], 2, ',', '.')) ?></strong></div><?php endif; ?>
      </div>
    <?php endif; ?>

    <div class="campos tres">
      <div class="campo">
        <label for="e-tarifa">Tarifa (R$ por kWh)</label>
        <input id="e-tarifa" name="tarifa" inputmode="decimal" placeholder="1,21" value="<?= $tarifa !== null ? e(number_format($tarifa, 4, ',', '')) : '' ?>" aria-describedby="h-tarifa">
        <small id="h-tarifa">Na conta de luz: valor total ÷ kWh consumidos. Vazio = sem custo.</small>
      </div>
      <div class="campo">
        <label for="e-fixo">Resto fixo (W)</label>
        <input id="e-fixo" name="fixo" inputmode="decimal" value="<?= e(number_format($correcao['fixo_w'], 1, ',', '')) ?>" aria-describedby="h-fixo">
        <small id="h-fixo">Chipset, SSD, rede, Wi-Fi e placa. Padrão <?= e(number_format(Config::ENERGIA_FIXO_PADRAO, 1, ',', '')) ?> W.</small>
      </div>
      <div class="campo">
        <label for="e-ef">Eficiência da fonte (%)</label>
        <input id="e-ef" name="eficiencia" inputmode="decimal" value="<?= e(number_format($correcao['eficiencia'] * 100, 0, ',', '')) ?>" aria-describedby="h-ef">
        <small id="h-ef">Fonte externa com pouca carga. Padrão <?= (int) round(Config::ENERGIA_EFICIENCIA_PADRAO * 100) ?>%.</small>
      </div>
    </div>
    <p class="nota">
      Fórmula: <b>(medido + resto fixo) ÷ eficiência</b> —
      <?= $correcao['origem'] === 'classe' ? 'valores padrão da classe da máquina (mini PC H81 + i3-4030U).' : 'valores ajustados manualmente.' ?>
      <?php if ($tarifaNota): ?><br><?= e($tarifaNota) ?><?php endif; ?>
    </p>
    <div class="form-acoes">
      <button class="btn" name="padrao" value="1">Voltar ao padrão</button>
      <button class="btn btn-primario" data-carregando="Salvando…"><?= Icone::svg('ok') ?>Salvar</button>
    </div>
  </form>

  <section class="card vidro largo">
    <header><h3><span class="kpi-ico-box"><?= Icone::svg('codigo') ?></span>API</h3></header>
    <pre class="bloco-codigo"><code>curl -H "Authorization: Bearer $TOKEN" http://<?= e($ip) ?>:8080/api/dispositivos

curl -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
     -d '{"acao":"ligar"}' http://<?= e($ip) ?>:8080/api/dispositivos/tv-1/comandos</code></pre>
    <p class="nota">Rotas: <code>GET /api/saude</code> · <code>GET /api/dispositivos</code> · <code>GET /api/dispositivos/{slug}</code> ·
      <code>POST /api/dispositivos</code> · <code>POST /api/dispositivos/{slug}/comandos</code> · <code>GET /api/comandos</code></p>
  </section>

  <section class="card vidro largo">
    <header><h3><span class="kpi-ico-box"><?= Icone::svg('servidor') ?></span>Homebridge</h3></header>
    <p class="suave">A interface do Homebridge só escuta no próprio servidor. Para abrir do seu computador:</p>
    <pre class="bloco-codigo"><code>ssh -L 8581:127.0.0.1:8581 homeserver@<?= e($ip) ?>

# depois abra http://localhost:8581</code></pre>
  </section>
</div>
