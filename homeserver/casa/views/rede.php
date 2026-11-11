<?php
use Casa\Auth;
use Casa\Icone;
use Casa\View;

$admin = Auth::ehAdmin($usuario);
$stat = static fn(string $cor, string $icone, string $rotulo, $valor, string $sub = '') =>
    '<div class="stat-card-pro"><div class="stat-card-icon ' . $cor . '">' . Icone::svg($icone) . '</div>'
    . '<div class="stat-card-label">' . e($rotulo) . '</div><div class="stat-card-value num">' . e((string) $valor) . '</div>'
    . '<div class="stat-card-sub">' . e($sub) . '</div></div>';
$filtros = ['online' => 'Online agora', 'todos' => 'Todos', 'desconhecidos' => 'Desconhecidos'];
$tipoIcone = static fn(array $r): string => match ($r['tipo']) {
    'TV', 'Chromecast / Google TV' => 'tv',
    'Roteador / Wi-Fi'             => 'roteador',
    'Servidor', 'Computador', 'Mac' => 'servidor',
    'Celular / tablet', 'iPhone / iPad', 'Apple (iPhone, iPad ou Mac)' => 'celular',
    'Impressora'                   => 'impressora',
    'Câmera / campainha'           => 'camera',
    'Casa inteligente'             => 'home',
    'AirPlay'                      => 'som',
    default                        => 'plug',
};
?>
<div class="stats-container stats-rede">
  <?= $stat('success', 'roteador', 'Online agora', $resumo['online'] ?? 0, 'na rede de casa') ?>
  <?= $stat('primary', 'apps', 'Já vistos', $resumo['total'] ?? 0, 'desde a 1ª varredura') ?>
  <?= $stat(($resumo['desconhecidos'] ?? 0) ? 'warning' : 'success', 'escudo', 'Desconhecidos', $resumo['desconhecidos'] ?? 0, ($resumo['novos24'] ?? 0) ? "{$resumo['novos24']} novo(s) em 24 h" : 'nenhum novo em 24 h') ?>
  <?= $stat('info', 'relogio', 'Última varredura', View::relativo($resumo['ultima_varredura'] ?? null),
      $varredura && empty($varredura['erro']) ? ($varredura['rede'] ?? '') . ' · a cada 5 min' : 'a cada 5 min') ?>
</div>

<div class="barra-acoes">
  <nav class="filtros" aria-label="Filtrar aparelhos">
    <?php foreach ($filtros as $k => $rot): ?>
      <a href="/rede?filtro=<?= e($k) ?>" class="<?= $filtro === $k ? 'ativo' : '' ?>"<?= $filtro === $k ? ' aria-current="true"' : '' ?>><?= e($rot) ?></a>
    <?php endforeach; ?>
  </nav>
  <span class="contagem"><?= count($lista) ?> aparelho(s)</span>
</div>

<?php if (!$lista): ?>
  <div class="vazio"><?= Icone::svg('roteador', 'vazio-ico') ?><p><?= $filtro === 'desconhecidos' ? 'Nenhum aparelho desconhecido.' : 'Nenhum aparelho encontrado ainda. A varredura roda a cada 5 minutos.' ?></p></div>
<?php else: ?>
<div class="panel">
  <div class="panel-header"><span class="panel-title"><?= Icone::svg('roteador') ?>Aparelhos</span><span class="suave pequeno">identificados pelo MAC</span></div>
  <div class="tabela-rolagem">
    <table class="tabela tabela-resp tabela-rede">
      <thead><tr><th scope="col">Aparelho</th><th scope="col">Modelo</th><th scope="col">IP</th><th scope="col">MAC</th><th scope="col">Fabricante</th><th scope="col">Situação</th><th scope="col">Visto</th><th scope="col"><span class="sr">Ações</span></th></tr></thead>
      <tbody>
      <?php foreach ($lista as $r):
          $id = str_replace(':', '', $r['mac']);
          $fab = $r['upnp_fabricante'] ?: $r['fabricante'];
          $info = array_filter([
              'Tipo (palpite)'        => $r['tipo'],
              'Modelo'                => $r['modelo_exibir'],
              'Fabricante (MAC)'      => $r['fabricante'] ?: ($r['mac_privado'] ? 'MAC privado: não identifica o fabricante' : null),
              'Fabricante (UPnP)'     => $r['upnp_fabricante'],
              'Nome no roteador'      => $r['nome_dns'],
              'Nome Bonjour'          => $r['mdns_nome'] ?? null,
              'Nome UPnP'             => $r['upnp_nome'],
              'Servidor UPnP'         => $r['upnp_servidor'],
              'Serviços anunciados'   => $r['servicos'] ? implode(', ', $r['servicos']) : null,
              'Página do aparelho'    => trim(($r['http_titulo'] ?? '') . (($r['http_servidor'] ?? null) ? ' · ' . $r['http_servidor'] : ''), ' ·') ?: null,
              'Cadastrado no painel'  => $r['disp_nome'],
              'Visto pela 1ª vez'     => date('d/m/Y H:i', (int) $r['primeiro_ts']),
              'Visto por último'      => date('d/m/Y H:i', (int) $r['ultimo_ts']),
          ], fn($v) => $v !== null && $v !== '');
          foreach ($r['extras'] as $k => $v) {
              $info['Anúncio: ' . $k] = $v;
          }
      ?>
        <tr id="m-<?= e($id) ?>" class="<?= $r['online'] ? '' : 'offline' ?>">
          <td data-rotulo="Aparelho">
            <span class="rede-nome">
              <span class="rede-ico"><?= Icone::svg($tipoIcone($r)) ?></span>
              <span><strong><?= e($r['nome']) ?></strong><small><?= e($r['tipo']) ?></small></span>
            </span>
          </td>
          <td data-rotulo="Modelo"><?= e($r['modelo_exibir'] ?: '—') ?></td>
          <td data-rotulo="IP" class="num mono"><?= e($r['ip'] ?? '—') ?></td>
          <td data-rotulo="MAC" class="num mono suave"><?= e($r['mac']) ?><?php if ($r['mac_privado']): ?> <span class="selo selo-neutro" title="Endereço aleatório (privacidade do iOS/Android)">privado</span><?php endif; ?></td>
          <td data-rotulo="Fabricante"><?= e($fab ?: '—') ?></td>
          <td data-rotulo="Situação">
            <span class="selo <?= $r['online'] ? 'selo-ok' : 'selo-neutro' ?>"><?= $r['online'] ? 'Online' : 'Offline' ?></span>
            <?php if (!$r['conhecido']): ?><span class="selo selo-aviso">Desconhecido</span><?php endif; ?>
            <?php if ($r['novo'] && !$r['conhecido']): ?><span class="selo selo-info">Novo</span><?php endif; ?>
          </td>
          <td data-rotulo="Visto" class="num"><?= e($r['online'] ? 'agora' : View::relativo((int) $r['ultimo_ts'])) ?></td>
          <td class="acoes">
            <button type="button" class="btn-link" data-alternar="#d-<?= e($id) ?>" aria-expanded="false" aria-controls="d-<?= e($id) ?>"><?= Icone::svg('lista') ?>Detalhes</button>
          </td>
        </tr>
        <tr class="linha-detalhes" id="d-<?= e($id) ?>" hidden>
          <td colspan="8">
            <div class="detalhes-rede">
              <dl class="kv">
                <?php foreach ($info as $k => $v): ?><dt><?= e($k) ?></dt><dd title="<?= e((string) $v) ?>"><?= e((string) $v) ?></dd><?php endforeach; ?>
              </dl>
              <?php if ($admin): ?>
              <form method="post" action="/rede?filtro=<?= e($filtro) ?>" class="editar-form">
                <input type="hidden" name="csrf" value="<?= e(Auth::csrf()) ?>">
                <input type="hidden" name="mac" value="<?= e($r['mac']) ?>">
                <label class="form-label" for="ap-<?= e($id) ?>">Apelido</label>
                <input id="ap-<?= e($id) ?>" name="apelido" maxlength="60" value="<?= e($r['apelido'] ?? '') ?>" placeholder="ex.: Celular da Thais">
                <label class="interruptor"><input type="checkbox" name="conhecido" value="1" <?= $r['conhecido'] ? 'checked' : '' ?>><span class="interruptor-trilho"></span>Aparelho conhecido</label>
                <button class="btn btn-primario"><?= Icone::svg('ok') ?>Salvar</button>
              </form>
              <?php endif; ?>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<p class="nota">A varredura roda no servidor a cada 5 minutos. Aparelhos cadastrados (TVs) têm o IP atualizado sozinhos quando mudam de endereço;
  a TV que ainda não tem MAC é reconhecida pelo modelo assim que aparecer ligada. Aparelho novo que não for marcado como conhecido aparece nos pontos de atenção.</p>
<?php endif; ?>
