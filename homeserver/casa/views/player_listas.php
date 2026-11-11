<?php
use Casa\Auth;
use Casa\Icone;
use Casa\Play\Api\PainelPlayer;
use Casa\View;

$csrf = e(Auth::csrf());
$stat = static fn(string $cor, string $icone, string $rotulo, $valor, string $sub = '') =>
    '<div class="stat-card-pro"><div class="stat-card-icon ' . $cor . '">' . Icone::svg($icone) . '</div>'
    . '<div class="stat-card-label">' . e($rotulo) . '</div><div class="stat-card-value num">' . e((string) $valor) . '</div>'
    . '<div class="stat-card-sub" title="' . e($sub) . '">' . e($sub) . '</div></div>';
$mil = static fn($n) => (int) $n >= 1000 ? number_format((int) $n / 1000, 1, ',', '.') . ' mil' : (string) (int) $n;
$host = static fn(string $s) => parse_url($s, PHP_URL_HOST) . (parse_url($s, PHP_URL_PORT) ? ':' . parse_url($s, PHP_URL_PORT) : '');
$dias = static fn(?string $ts) => $ts ? (int) floor((strtotime($ts) - time()) / 86400) : null;

$dAtiva = $dias($ativa['vence_em'] ?? null);
$corVence = $dAtiva === null ? 'info' : ($dAtiva <= 7 ? 'danger' : ($dAtiva <= 15 ? 'warning' : 'success'));
$vendo = array_values(array_filter($tvs, fn($t) => $t['assistindo'] && $t['recente'] && empty($t['assistindo']['pausado'])));
$total = (int) $catalogo['filmes'] + (int) $catalogo['series'] + (int) $catalogo['canais'];
$ultimaSync = $sincronia['ultima'] ?? null;
$pendentes = count(array_filter($tvs, fn($t) => $t['status'] === 'pendente'));
?>
<div class="stats-container stats-rede">
  <?= $stat($corVence, 'relogio', 'Lista vence em', $dAtiva === null ? '—' : ($dAtiva < 0 ? 'vencida' : $dAtiva . ' dia' . ($dAtiva === 1 ? '' : 's')),
      $ativa ? (($ativa['vence_em'] ? date('d/m/Y', strtotime($ativa['vence_em'])) . ' · ' : '') . $host($ativa['servidor'])) : 'nenhuma lista ativa') ?>
  <?= $stat($vendo ? 'success' : 'primary', 'tv', 'Assistindo agora', count($vendo) . ' TV' . (count($vendo) === 1 ? '' : 's'),
      $vendo ? implode(' · ', array_map(fn($t) => $t['nome'] . ': ' . ($t['assistindo']['nome'] ?? '?'), $vendo)) : 'nenhuma TV no Player') ?>
  <?= $stat('info', 'caixa', 'Catálogo', $mil($total),
      $mil($catalogo['filmes']) . ' filmes · ' . $mil($catalogo['series']) . ' séries · ' . $mil($catalogo['canais']) . ' canais') ?>
  <?= $stat('primary', 'download', 'Última sincronia', $ultimaSync ? date('H:i', strtotime($ultimaSync)) : '—',
      ($sincronia['proxima'] ?? null ? 'próxima às ' . date('H:i', strtotime($sincronia['proxima'])) . ' · ' : '')
      . (int) $catalogo['novos24'] . ' título(s) novo(s) em 24 h') ?>
</div>

<?php if ($pendentes): ?>
  <div class="aviso aviso-aviso" role="status"><?= Icone::svg('alerta') ?>
    <span><?= $pendentes === 1 ? 'Uma TV pediu acesso ao Player.' : "$pendentes TVs pediram acesso ao Player." ?>
      Confira o código mostrado na tela da TV e toque em <b>Aprovar</b>.</span></div>
<?php endif; ?>

<?php
$formConta = static function (?array $c) use ($csrf): string {
    $id = $c ? (int) $c['id'] : 0;
    $pre = $c ? 'c' . $id : 'cn';
    ob_start(); ?>
  <form method="post" class="editar-form" data-form>
    <input type="hidden" name="csrf" value="<?= $csrf ?>">
    <input type="hidden" name="acao" value="conta_salvar">
    <input type="hidden" name="id" value="<?= $id ?>">
    <input type="hidden" name="ancora" value="listas">
    <div class="campos">
      <div class="campo"><label for="<?= $pre ?>-nome">Nome</label>
        <input id="<?= $pre ?>-nome" name="nome" maxlength="40" required value="<?= e($c['nome'] ?? '') ?>" placeholder="Principal"></div>
      <div class="campo"><label for="<?= $pre ?>-serv">Servidor</label>
        <input id="<?= $pre ?>-serv" name="servidor" required spellcheck="false" autocapitalize="none" value="<?= e($c['servidor'] ?? '') ?>" placeholder="http://painel.exemplo:8080"></div>
      <div class="campo"><label for="<?= $pre ?>-user">Usuário</label>
        <input id="<?= $pre ?>-user" name="usuario" required spellcheck="false" autocapitalize="none" autocomplete="off" value="<?= e($c['usuario'] ?? '') ?>"></div>
      <div class="campo"><label for="<?= $pre ?>-senha"><?= $c ? 'Trocar senha' : 'Senha' ?></label>
        <input id="<?= $pre ?>-senha" name="senha" type="password" autocomplete="new-password" <?= $c ? 'placeholder="em branco = mantém a atual"' : 'required' ?>></div>
    </div>
    <div class="form-acoes">
      <button class="btn btn-primario" data-carregando="Salvando e testando…"><?= Icone::svg('ok') ?><?= $c ? 'Salvar' : 'Adicionar e testar' ?></button>
    </div>
  </form>
<?php return (string) ob_get_clean();
};
?>

<div class="panel" id="listas">
  <div class="panel-header">
    <span class="panel-title"><?= Icone::svg('lista') ?>Listas IPTV</span>
    <button type="button" class="btn btn-primario" data-alternar="#conta-nova" aria-expanded="false" aria-controls="conta-nova"><?= Icone::svg('mais') ?>Adicionar lista</button>
  </div>
  <div class="panel-body" id="conta-nova" hidden><?= $formConta(null) ?></div>
  <?php if (!$contas): ?>
    <div class="panel-body"><p class="suave">Nenhuma lista cadastrada. As TVs recebem servidor, usuário e senha daqui (nada é digitado na TV).</p></div>
  <?php else: ?>
  <div class="tabela-rolagem">
    <table class="tabela tabela-resp">
      <thead><tr><th scope="col">Nome</th><th scope="col">Servidor</th><th scope="col">Usuário</th><th scope="col">Vence</th>
        <th scope="col">Última resposta</th><th scope="col">Situação</th><th scope="col"><span class="sr">Ações</span></th></tr></thead>
      <tbody>
      <?php foreach ($contas as $c):
          $d = $dias($c['vence_em']);
          $ehAtiva = \Casa\Play\Api\Base::b($c['ativa']);
      ?>
        <tr class="<?= $ehAtiva ? '' : 'inativo' ?>">
          <td data-rotulo="Nome"><strong><?= e($c['nome']) ?></strong></td>
          <td data-rotulo="Servidor" class="mono"><?= e($host($c['servidor'])) ?></td>
          <td data-rotulo="Usuário" class="mono"><?= e($c['usuario']) ?></td>
          <td data-rotulo="Vence" class="num"><?= $c['vence_em'] ? e(date('d/m/Y', strtotime($c['vence_em']))) : '—' ?></td>
          <td data-rotulo="Última resposta" class="num"><?= $c['ultima_resposta_em'] ? e(date('d/m H:i', strtotime($c['ultima_resposta_em']))) . ($c['latencia_ms'] !== null ? ' · ' . (int) $c['latencia_ms'] . ' ms' : '') : '—' ?></td>
          <td data-rotulo="Situação">
            <?php if ($ehAtiva): ?><span class="selo selo-ok">Ativa</span><?php endif; ?>
            <?php if ($c['status'] && $c['status'] !== 'Active'): ?><span class="selo selo-erro"><?= e($c['status']) ?></span><?php endif; ?>
            <?php if ($d !== null): ?><span class="selo <?= $d < 0 ? 'selo-erro' : ($d <= 7 ? 'selo-erro' : ($d <= 15 ? 'selo-aviso' : 'selo-neutro')) ?>"><?= $d < 0 ? 'vencida' : 'vence em ' . $d . ' dia' . ($d === 1 ? '' : 's') ?></span><?php endif; ?>
          </td>
          <td class="acoes"><span class="ref-acoes">
            <form method="post"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="acao" value="conta_testar"><input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
              <button class="btn-link" data-carregando="Testando…"><?= Icone::svg('atividade') ?>Testar</button></form>
            <?php if (!$ehAtiva): ?>
            <form method="post" data-confirmar="Ativar esta lista? As TVs passam a tocar por ela."><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="acao" value="conta_ativar"><input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
              <button class="btn-link"><?= Icone::svg('power') ?>Ativar</button></form>
            <?php endif; ?>
            <button type="button" class="btn-link" data-alternar="#conta-<?= (int) $c['id'] ?>" aria-expanded="false" aria-controls="conta-<?= (int) $c['id'] ?>"><?= Icone::svg('editar') ?>Editar</button>
          </span></td>
        </tr>
        <tr class="linha-detalhes" id="conta-<?= (int) $c['id'] ?>" hidden>
          <td colspan="7">
            <?= $formConta($c) ?>
            <?php if (!$ehAtiva): ?>
            <form method="post" data-confirmar="Apagar a lista &quot;<?= e($c['nome']) ?>&quot;? O catálogo dela sai do servidor.">
              <input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="acao" value="conta_apagar"><input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
              <button class="btn-link perigo"><?= Icone::svg('x') ?>Apagar lista</button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<div class="panel" id="tvs">
  <div class="panel-header"><span class="panel-title"><?= Icone::svg('tv') ?>TVs</span><span class="suave pequeno">o token não depende do IP</span></div>
  <?php if (!$tvs): ?>
    <div class="panel-body"><p class="suave">Nenhuma TV abriu o Player 2.0 ainda. Ao abrir, ela mostra um código de 4 letras e aparece aqui para aprovar.</p></div>
  <?php else: ?>
  <div class="tabela-rolagem">
    <table class="tabela tabela-resp">
      <thead><tr><th scope="col">TV</th><th scope="col">Aparelho e IP</th><th scope="col">App</th><th scope="col">Agora</th>
        <th scope="col">Visto</th><th scope="col">Situação</th><th scope="col"><span class="sr">Ações</span></th></tr></thead>
      <tbody>
      <?php foreach ($tvs as $t):
          $a = $t['assistindo'];
          $agora = $a && $t['recente'] ? ($a['nome'] ?? '?') . (isset($a['temporada']) && $a['temporada'] ? ' · T' . $a['temporada'] . ':E' . $a['episodio'] : '') . (!empty($a['pausado']) ? ' (pausado)' : '') : '—';
          $selo = ['ativa' => ['Ativa', 'selo-ok'], 'pendente' => ['Aguardando aprovação', 'selo-aviso'], 'bloqueada' => ['Bloqueada', 'selo-erro']][$t['status']] ?? [$t['status'], 'selo-neutro'];
      ?>
        <tr class="<?= $t['status'] === 'bloqueada' ? 'inativo' : ($t['recente'] ? '' : 'offline') ?>">
          <td data-rotulo="TV"><span class="rede-nome"><span class="rede-ico"><?= Icone::svg('tv') ?></span>
            <span><strong><?= e($t['nome']) ?></strong><small><?= e(implode(' · ', array_filter([$t['modelo'], $t['webos'] ? 'webOS ' . preg_replace('/^webos\s*/i', '', $t['webos']) : null, $t['perfil_nome'] ? 'perfil ' . $t['perfil_nome'] : null]))) ?></small></span></span></td>
          <td data-rotulo="Aparelho e IP" class="mono"><?= e($t['dispositivo_slug'] ?: 'sem aparelho') ?> · <?= e($t['ip'] ?: '—') ?></td>
          <td data-rotulo="App" class="num"><?= e($t['versao_app'] ?: '—') ?></td>
          <td data-rotulo="Agora"><?= e($agora) ?></td>
          <td data-rotulo="Visto" class="num"><?= e($t['recente'] ? 'agora' : View::relativo($t['visto_ts'] !== null ? (int) $t['visto_ts'] : null)) ?></td>
          <td data-rotulo="Situação"><span class="selo <?= $selo[1] ?>"><?= e($selo[0]) ?></span>
            <?php if ($t['status'] === 'pendente'): ?><span class="selo selo-info mono" title="Código mostrado na tela da TV"><?= e($t['codigo']) ?></span><?php endif; ?></td>
          <td class="acoes"><span class="ref-acoes">
            <?php if ($t['status'] !== 'ativa'): ?>
            <form method="post"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="acao" value="tv_aprovar"><input type="hidden" name="id" value="<?= (int) $t['id'] ?>"><input type="hidden" name="codigo" value="<?= e((string) $t['codigo']) ?>"><input type="hidden" name="ancora" value="tvs">
              <button class="btn btn-primario"><?= Icone::svg('ok') ?>Aprovar</button></form>
            <?php else: ?>
            <form method="post" data-confirmar="Bloquear <?= e($t['nome']) ?>? O Player para de funcionar nela."><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="acao" value="tv_bloquear"><input type="hidden" name="id" value="<?= (int) $t['id'] ?>"><input type="hidden" name="ancora" value="tvs">
              <button class="btn-link perigo"><?= Icone::svg('cadeado') ?>Bloquear</button></form>
            <?php endif; ?>
            <button type="button" class="btn-link" data-alternar="#tv-<?= (int) $t['id'] ?>" aria-expanded="false" aria-controls="tv-<?= (int) $t['id'] ?>"><?= Icone::svg('editar') ?>Editar</button>
          </span></td>
        </tr>
        <tr class="linha-detalhes" id="tv-<?= (int) $t['id'] ?>" hidden>
          <td colspan="7">
            <form method="post" class="editar-form">
              <input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="acao" value="tv_salvar"><input type="hidden" name="id" value="<?= (int) $t['id'] ?>"><input type="hidden" name="ancora" value="tvs">
              <div class="campos">
                <div class="campo"><label for="tv<?= (int) $t['id'] ?>-nome">Nome</label>
                  <input id="tv<?= (int) $t['id'] ?>-nome" name="nome" maxlength="40" required value="<?= e($t['nome']) ?>"></div>
                <div class="campo"><label for="tv<?= (int) $t['id'] ?>-perfil">Perfil padrão</label>
                  <select id="tv<?= (int) $t['id'] ?>-perfil" name="perfil_id">
                    <?php foreach ($perfis as $pf): ?><option value="<?= (int) $pf['id'] ?>" <?= (int) $t['perfil_id'] === (int) $pf['id'] ? 'selected' : '' ?>><?= e($pf['nome']) ?></option><?php endforeach; ?>
                  </select></div>
                <div class="campo"><label for="tv<?= (int) $t['id'] ?>-ap">Aparelho do painel</label>
                  <select id="tv<?= (int) $t['id'] ?>-ap" name="dispositivo_slug" aria-describedby="tv<?= (int) $t['id'] ?>-ap-h">
                    <option value="">— nenhum —</option>
                    <?php foreach ($aparelhos as $ap): ?><option value="<?= e($ap['slug']) ?>" <?= $t['dispositivo_slug'] === $ap['slug'] ? 'selected' : '' ?>><?= e($ap['slug'] . ' · ' . $ap['nome']) ?></option><?php endforeach; ?>
                  </select>
                  <small id="tv<?= (int) $t['id'] ?>-ap-h">Avisos na tela, ligar pela rede e “continuar” pela Siri usam este aparelho, com o IP que a TV usou por último.</small></div>
              </div>
              <div class="form-acoes"><button class="btn btn-primario"><?= Icone::svg('ok') ?>Salvar</button></div>
            </form>
            <form method="post" data-confirmar="Remover <?= e($t['nome']) ?>? Ela precisa abrir o Player e ser aprovada de novo.">
              <input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="acao" value="tv_apagar"><input type="hidden" name="id" value="<?= (int) $t['id'] ?>"><input type="hidden" name="ancora" value="tvs">
              <button class="btn-link perigo"><?= Icone::svg('x') ?>Remover TV</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>
<p class="nota"><?= Icone::svg('info') ?> TVs e homeserver trocam de IP (DHCP). Nada depende de IP fixo: a TV acha o servidor sozinha e o painel corrige o IP do
  aparelho a cada chamada. Mesmo assim, <b>reservar o IP de cada TV no roteador</b> é recomendado (avisos e Wake-on-LAN ficam mais rápidos depois de uma troca).</p>

<div class="panel">
  <div class="panel-header"><span class="panel-title"><?= Icone::svg('relogio') ?>Tarefas do servidor</span><a class="btn-link" href="/player/tarefas"><?= Icone::svg('lista') ?>Ver todas</a></div>
  <?php if (!$tarefas): ?>
    <div class="panel-body"><p class="suave">O casa-play ainda não registrou tarefas.</p></div>
  <?php else: ?>
  <div class="tabela-rolagem">
    <table class="tabela tabela-resp">
      <thead><tr><th scope="col">Tarefa</th><th scope="col">Última</th><th scope="col">Duração</th><th scope="col">Situação</th></tr></thead>
      <tbody>
      <?php foreach ($tarefas as $tf): ?>
        <tr>
          <td data-rotulo="Tarefa"><?= e(PainelPlayer::TAREFAS[$tf['nome']] ?? $tf['nome']) ?></td>
          <td data-rotulo="Última" class="num"><?= $tf['ultima_em'] ? e(date('d/m H:i', strtotime($tf['ultima_em']))) : '—' ?></td>
          <td data-rotulo="Duração" class="num"><?= e(PainelPlayer::duracaoMs($tf['duracao_ms'] !== null ? (int) $tf['duracao_ms'] : null)) ?></td>
          <td data-rotulo="Situação"><span class="selo <?= ['ok' => 'selo-ok', 'erro' => 'selo-erro', 'rodando' => 'selo-info'][$tf['status']] ?? 'selo-neutro' ?>"><?= e($tf['status'] ?: '—') ?></span></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>
