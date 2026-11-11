// Teste automatizado de navegação (controle remoto simulado por CDP) contra o servidor simulado.
// Requer: npm run build e node dev/mock-server.mjs rodando.  Uso: node dev/test-nav.mjs
import http from 'node:http';
import { launch, open, key, sleep, snap, waitZone, waitFor, typeText, mock, K, MOCK } from './harness.mjs';

// Helper falso da TV (porta 3911): /trailer manda parent.postMessage({trailer:'tocando'}) e, depois de
// helper.ms, {trailer:'fim'} (modo 'fim'), o mesmo como texto JSON ('texto'), {trailer:'erro'} ('erro')
// ou nada ('nada'). Também manda mensagens que não são do trailer, que o app tem de ignorar.
const helper = { mode: 'fim', ms: 1500, hits: [] };
const helperSrv = http.createServer(function (req, res) {
  const u = new URL(req.url, 'http://x');
  if (u.pathname !== '/trailer') { res.writeHead(404); return res.end(); }
  helper.hits.push({ at: Date.now(), q: u.search, mode: helper.mode });
  const m = helper.mode;
  const post = (t) => m === 'texto' ? `parent.postMessage(JSON.stringify({trailer:'${t}'}),'*');` : `parent.postMessage({trailer:'${t}'},'*');`;
  const end = m === 'nada' ? '' : `setTimeout(function(){${post(m === 'erro' ? 'erro' : 'fim')}},${helper.ms});`;
  res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' });
  res.end(`<!doctype html><body style="background:#000"><script>parent.postMessage({outro:1},'*');parent.postMessage('lixo','*');${post('tocando')}${end}</script>`);
});
await new Promise((r) => helperSrv.listen(3911, '127.0.0.1', r));

const results = [];
function check(name, ok, info) { results.push({ name, ok: !!ok }); console.log((ok ? 'ok   ' : 'FALHA') + '  ' + name + (ok || info === undefined ? '' : '  → ' + JSON.stringify(info))); }
const text = (p, sel) => p.evaluate((s) => { const e = document.querySelector(s); return e ? e.textContent : null; }, sel);
const toastText = (p) => p.evaluate(() => { const t = document.getElementById('toast'); return t && /show/.test(t.className) ? t.textContent : ''; });
const logHas = async (re) => (await mock('/__mock/log')).some((l) => re.test(l));

const browser = await launch();
const allErrors = [];
try {
  await mock('/__mock/reset');
  // ---------- partida com histórico da v10 no localStorage ----------
  let p = await open(browser);
  await p.evaluate(() => {
    localStorage.setItem('play_history', JSON.stringify({ m123: { t: 1 } }));
    localStorage.setItem('my_list', JSON.stringify({ m1: 1, s2: 1 }));
    localStorage.setItem('subs_size', '"g"');
  });
  await p.close();
  await mock('/__mock/reset'); // esta TV vira a "TV 1" (a outra TV dos dados de exemplo é a "TV 2")
  p = await open(browser, 'reset=1&rapido=1');
  check('abre direto no Início, sem "Quem está assistindo?": foco em Assistir', await waitZone(p, 'destaque', 10000) && (await snap(p)).text === 'Assistir', await snap(p));
  check('nenhuma tela de perfis', !(await p.$('.fullscr.prof')), null);
  check('perfil do servidor (estado.perfil = 1)', (await p.evaluate(() => window.__play.S.perfil)) === 1, await p.evaluate(() => window.__play.S.perfil));
  await sleep(1500);
  check('importação da v10 enviada ao servidor (POST importar)', await logHas(/POST \/api\/play\/importar/));
  check('sinais (SSE) conectados', await waitFor(p, () => /em sincronia/.test(document.querySelector('.syncd').textContent), 5000), await text(p, '.syncd'));

  // ---------- destaque ----------
  await key(p, K.RIGHT); check('▶ no destaque vai para "Mais informações"', (await snap(p)).text === 'Mais informações', await snap(p));
  const t1 = await text(p, '.hero .big');
  check('destaque quase em tela cheia: arte 1420x1000 e o Continuar começa na borda de baixo (900 px)', await p.evaluate(() => {
    const bd = document.querySelector('.hero .bd').getBoundingClientRect(), rows = document.querySelector('.home .rows').getBoundingClientRect();
    return bd.width === 1420 && bd.height === 1000 && rows.top >= 880 && rows.top < 1000;
  }), await p.evaluate(() => [document.querySelector('.hero .bd').getBoundingClientRect().height, document.querySelector('.home .rows').getBoundingClientRect().top]));
  check('botões do destaque: Assistir, Mais informações, Minha lista, Gostei, Não gostei', (await p.evaluate(() => Array.prototype.map.call(document.querySelectorAll('.hero .btns .btn'), (b) => b.getAttribute('data-label')).join('|'))) === 'Assistir|Mais informações|Minha lista|Gostei|Não gostei',
    await p.evaluate(() => Array.prototype.map.call(document.querySelectorAll('.hero .btns .btn'), (b) => b.getAttribute('data-label'))));
  await key(p, K.RIGHT, 4); await sleep(300);
  check('▶ no último botão troca o item do destaque', (await text(p, '.hero .big')) !== t1, await text(p, '.hero .big'));

  // ---------- continuar ----------
  await key(p, K.DOWN);
  let s = await snap(p);
  check('▼ vai para Continuar assistindo', s.zone === 'continuar' && s.text === 'Interestelar', s);
  await sleep(450);   // fim da animação da rolagem (.25 s)
  const contTop = await p.evaluate(() => document.querySelector('[data-zone="continuar"]').getBoundingClientRect().top);
  check('▼ rola a tela até o Continuar (a fileira aparece inteira)', await p.evaluate(() => window.__play.S && document.querySelector('.home .scroller').style.transform !== '' && document.querySelector('.home .scroller').style.transform !== 'translateY(0px)') && contTop >= 100 && contTop < 400, contTop);
  await key(p, K.UP); s = await snap(p);
  check('▲ volta ao destaque e a tela volta ao topo', s.zone === 'destaque' && await p.evaluate(() => /translateY\(0px\)|^$/.test(document.querySelector('.home .scroller').style.transform)), [s, await p.evaluate(() => document.querySelector('.home .scroller').style.transform)]);
  await key(p, K.DOWN); s = await snap(p);
  await key(p, K.GREEN); await sleep(300);
  s = await snap(p);
  check('verde remove do Continuar', s.zone === 'continuar' && s.text !== 'Interestelar' && /saiu de Continuar/.test(await toastText(p)), s);
  check('remoção enviada (POST continuar-ocultar)', await logHas(/POST \/api\/play\/continuar-ocultar/));
  await key(p, K.RIGHT, 25); await sleep(300); s = await snap(p);
  check('▶ fim do Continuar: cartão "Como remover da lista"', s.zone === 'continuar' && s.text === 'Como remover da lista'
    && /Verde: tira daqui/.test(await p.evaluate(() => (document.querySelector('[data-zone="continuar"] .card.ch') || {}).textContent || '')), s);
  await key(p, K.GREEN); await sleep(300); s = await snap(p);
  check('verde no cartão de ajuda não remove nada', s.text === 'Como remover da lista', s);
  await key(p, K.LEFT, 25); await sleep(300);

  // ---------- Recomendados e Top Brasil (vêm antes dos Top 10) ----------
  await key(p, K.DOWN); s = await snap(p); check('▼ vai para Recomendados para você', s.zone === 'recomendados', s);
  await key(p, K.DOWN); s = await snap(p); check('▼ vai para o Top Brasil no nº 1', s.zone === 'topbr' && s.text === '#1 Pedaço de Mim', s);

  // ---------- Top 10 por plataforma ----------
  await key(p, K.DOWN);
  s = await snap(p); check('▼ entra no Top 10 séries no nº 1 da Netflix', s.zone === 'top10s' && s.text === '#1 Pedaço de Mim', s);
  await key(p, K.CH_DOWN); s = await snap(p); check('CH− pula para o nº 1 do Prime Video', s.text === '#1 Reacher', s);
  check('pílula da plataforma acompanha', (await text(p, '[data-zone="top10s"] .pj .on')) === 'Prime Video', await text(p, '[data-zone="top10s"] .pj .on'));
  await key(p, K.CH_DOWN); s = await snap(p); check('CH− de novo: Disney+', s.text === '#1 Andor', s);
  await key(p, K.CH_UP); s = await snap(p); check('CH+ volta para o Prime Video', s.text === '#1 Reacher', s);
  await key(p, K.RIGHT, 7); s = await snap(p); check('▶ anda na fileira até o apagado (#8 Citadel)', s.text === '#8 Citadel', s);
  await key(p, K.RED); await sleep(500); check('vermelho num apagado desliga o aviso já ligado', /Aviso desligado/.test(await toastText(p)), await toastText(p));
  await key(p, K.RED); await sleep(500); check('vermelho de novo liga "avisar quando chegar"', /avisamos quando Citadel/.test(await toastText(p)), await toastText(p));
  const mounted = await p.evaluate(() => document.querySelectorAll('[data-zone="top10s"] .card').length);
  check('Top 10 (55 itens) monta só os cartões perto do foco', mounted > 0 && mounted < 25, mounted);
  await key(p, K.BACK); s = await snap(p); check('Voltar dentro da tela vai para a barra do topo', s.zone === 'top', s);

  // ---------- Ranking ----------
  await key(p, K.RIGHT, 4); await key(p, K.OK); await waitZone(p, 'ranking', 6000);
  s = await snap(p); check('Ranking abre com foco na grade (nº 1 Netflix)', s.zone === 'ranking' && s.text === '#1 Pedaço de Mim', s);
  await key(p, K.RIGHT, 2); await key(p, K.DOWN, 3); s = await snap(p); check('setas andam por coluna/posição (#4 Disney+)', s.text === '#4 Demolidor', s);
  await key(p, K.GREEN); await sleep(800); check('verde alterna séries/filmes', (await text(p, '.seg .on')) === 'Filmes', await text(p, '.seg .on'));
  for (let i = 0; i < 10 && (await snap(p)).zone === 'ranking'; i++) await key(p, K.UP);
  s = await snap(p); check('▲ na grade chega no seletor Séries/Filmes', s.zone === 'rk-tipo' && s.text === 'Filmes', s);
  await key(p, K.LEFT); await key(p, K.OK); await sleep(800); check('OK em "Séries" troca pelo controle', (await text(p, '.seg .on')) === 'Séries', await text(p, '.seg .on'));
  await key(p, K.RIGHT); await key(p, K.OK); await sleep(800); check('OK em "Filmes" troca de volta', (await text(p, '.seg .on')) === 'Filmes', await text(p, '.seg .on'));
  await key(p, K.DOWN); s = await snap(p); check('▼ do seletor volta para a grade', s.zone === 'ranking', s);

  // ---------- Séries › Suas séries ----------
  await key(p, K.BACK); await key(p, K.LEFT, 3); await key(p, K.OK); await waitZone(p, 'sub', 6000);
  await key(p, K.RIGHT, 2); await key(p, K.OK); await waitZone(p, 'chegaram', 6000);
  s = await snap(p); check('Séries › Suas séries: foco em "Chegaram"', s.zone === 'chegaram', s);
  await key(p, K.DOWN); s = await snap(p); check('▼ vai para a agenda de próximos episódios', s.zone === 'agenda', s);
  await key(p, K.DOWN); await key(p, K.OK); await sleep(500); check('OK liga o aviso do episódio', /Aviso ligado/.test(await toastText(p)), await toastText(p));

  // ---------- Filmes › Categorias ----------
  await key(p, K.BACK); await key(p, K.RIGHT); await key(p, K.OK); await waitZone(p, 'sub', 6000);
  await key(p, K.RIGHT, 5); await key(p, K.OK); await waitZone(p, 'drawer', 6000); await sleep(800);
  check('categorias com id texto (jw:act) e número (26) na mesma lista', await p.evaluate(() => { const ids = Array.prototype.map.call(document.querySelectorAll('.drawer [data-cat]'), (e) => e.getAttribute('data-cat')); return ids.indexOf('jw:act') !== -1 && ids.indexOf('26') !== -1; }));
  await key(p, K.DOWN); await sleep(1200);
  const catName = await text(p, '.gh b');
  check('▼ na lista lateral troca a categoria sozinho', catName === 'Comédia', catName);
  check('categoria com id texto carrega (GET categoria/jw:cmy)', await logHas(/GET \/api\/play\/categoria\/jw(:|%3A)cmy\?/) && await waitFor(p, () => document.querySelectorAll('.pgi .card').length > 0, 4000), await text(p, '.pgi'));
  await key(p, K.RIGHT); s = await snap(p); check('▶ vai da lista para a grade', s.zone === 'grade', s);
  await key(p, K.GREEN); await sleep(800); check('verde muda a ordem', /Mais recentes/.test(await text(p, '.gh .sort')), await text(p, '.gh .sort'));
  await key(p, K.LEFT); s = await snap(p); check('◀ volta para a lista lateral', s.zone === 'drawer', s);
  for (let i = 0; i < 12 && (await snap(p)).text !== '4K'; i++) await key(p, K.DOWN);
  await sleep(1200);
  check('categoria com id número carrega (4K = 26)', (await text(p, '.gh b')) === '4K' && await logHas(/GET \/api\/play\/categoria\/26\?/) && await waitFor(p, () => document.querySelectorAll('.pgi .card').length > 0, 4000), await text(p, '.gh b'));

  // ---------- Ao vivo ----------
  await key(p, K.BACK); await key(p, K.RIGHT); await key(p, K.OK); await waitZone(p, 'grupos', 6000);
  s = await snap(p); check('Ao vivo abre em "Jogos de hoje"', s.text === 'Jogos de hoje', s);
  check('grupos numa lista vertical à esquerda', await p.evaluate(() => {
    const es = document.querySelectorAll('.lgr .c');
    if (es.length < 4 || document.querySelector('.sub')) return false;
    const a = es[0].getBoundingClientRect(), b = es[1].getBoundingClientRect(), body = document.querySelector('.lbody').getBoundingClientRect();
    return Math.abs(a.left - b.left) < 1 && b.top >= a.bottom && a.right <= body.left;
  }));
  await key(p, K.RIGHT); s = await snap(p); check('▶ da lista entra no conteúdo (jogos)', s.zone === 'jogos', s);
  await key(p, K.LEFT); s = await snap(p); check('◀ na borda do conteúdo volta para a lista', s.zone === 'grupos' && s.text === 'Jogos de hoje', s);
  await key(p, K.RIGHT); await key(p, K.DOWN); s = await snap(p); check('▼ vai para a grade', s.zone === 'grade', s);
  await key(p, K.RIGHT); await key(p, K.LEFT); s = await snap(p); check('◀ sai do programa para o canal', s.zone === 'grade' && /SporTV|Premiere|Globo/.test(s.text), s);
  await key(p, K.RED); await sleep(500); check('vermelho tira/põe nos favoritos', /favoritos/.test(await toastText(p)), await toastText(p));
  await key(p, K.LEFT); s = await snap(p); check('◀ no canal volta para a lista de grupos', s.zone === 'grupos', s);
  await key(p, K.RIGHT); await key(p, K.DOWN); await key(p, K.BACK); s = await snap(p); check('Voltar no conteúdo vai para a lista de grupos', s.zone === 'grupos' && s.text === 'Jogos de hoje', s);
  await key(p, K.DOWN); s = await snap(p);
  check('▼ na lista troca o grupo sozinho (Favoritos)', s.zone === 'grupos' && s.text === 'Favoritos' && await waitFor(p, () => !!document.querySelector('.lbody .ltop') && /Favoritos/.test(document.querySelector('.lgr .c.on').textContent), 3000), s);
  s = await snap(p); check('a lista fica com o foco depois de trocar o grupo', s.zone === 'grupos' && s.text === 'Favoritos', s);
  await key(p, K.RIGHT); s = await snap(p); check('▶ entra na grade do grupo', s.zone === 'grade', s);
  await key(p, K.LEFT); await key(p, K.UP); s = await snap(p);
  check('▲ na lista volta ao grupo de cima', s.zone === 'grupos' && s.text === 'Jogos de hoje' && await waitFor(p, () => !!document.querySelector('.lbody .jrow'), 3000), s);
  await key(p, K.UP); s = await snap(p); check('▲ no topo da lista vai para a barra do topo', s.zone === 'top', s);
  await key(p, K.DOWN); await key(p, K.RIGHT); await key(p, K.OK); await sleep(2500);
  s = await snap(p); check('OK num jogo ao vivo abre o canal em tela cheia', s.layer === 'player', s);
  check('vídeo ao vivo tocando (hls.js)', await waitFor(p, () => document.getElementById('video').currentTime > 0.3, 8000));
  await key(p, K.BACK); await sleep(500); s = await snap(p); check('Voltar sai do ao vivo', s.layer === 'base', s);
  await key(p, K.BACK); s = await snap(p); check('Voltar: conteúdo → lista → barra do topo', s.zone === 'grupos', s);

  // ---------- Busca ----------
  await key(p, K.BACK); await key(p, K.RIGHT, 9); await key(p, K.LEFT, 1); await key(p, K.OK); await waitZone(p, 'teclado', 6000); // topo: 6 abas, Buscar, Ajustes
  await typeText(p, 'duna'); await sleep(1200);
  check('resultados aparecem enquanto digita', !!(await text(p, '.res [data-zone="titulos"]')), await text(p, '.res .fix'));
  await key(p, K.RIGHT, 6); s = await snap(p); check('▶ sai do teclado para os resultados', s.zone === 'titulos' || s.zone === 'pessoas', s);
  if (s.zone === 'pessoas') await key(p, K.DOWN);
  await key(p, K.OK); await waitZone(p, 'botoes', 6000);
  s = await snap(p); check('OK abre o detalhe', s.layer === 'detalhe', s);

  // ---------- Player ----------
  await key(p, K.OK);
  check('Assistir abre o player e o vídeo toca', await waitFor(p, () => document.getElementById('video').currentTime > 0.5, 10000));
  await sleep(500);
  check('progresso "inicio" enviado', await logHas(/POST \/api\/play\/progresso/));
  await key(p, K.UP); await sleep(300); check('▲ abre o menu de legendas', await p.evaluate(() => /on/.test(document.querySelector('.subsmenu').className)));
  await key(p, K.DOWN); await key(p, K.OK);
  check('legenda carregada e desenhada', await waitFor(p, () => /Legenda de teste/.test(document.querySelector('.subtext').textContent), 8000), await text(p, '.subtext'));
  const before = await p.evaluate(() => document.getElementById('video').currentTime);
  await key(p, K.OK); await key(p, K.RIGHT); await sleep(400);
  const after = await p.evaluate(() => document.getElementById('video').currentTime);
  check('◀▶ avança 10 s com a barra na tela', after - before > 8, { before, after });
  await key(p, K.BACK); await sleep(600); s = await snap(p); check('Voltar fecha o player e volta ao detalhe', s.layer === 'detalhe', s);
  await key(p, K.BACK); s = await snap(p); check('Voltar fecha o detalhe', s.layer === 'base', s);

  // ---------- sinais ----------
  await key(p, K.BACK); await key(p, K.LEFT, 9); await key(p, K.OK); await waitZone(p, 'destaque', 6000);
  await mock('/__mock/sinal?tipo=progresso');
  check('sinal "progresso" da TV 2 oferece "Continuar aqui"', await waitFor(p, () => { const n = document.querySelector('.notify'); return n && /parou na TV 2/.test(n.textContent); }, 8000));
  s = await snap(p); check('a oferta recebe o foco', s.layer === 'aviso' && s.text === 'Continuar aqui', s);
  await key(p, K.BACK); s = await snap(p); check('Voltar fecha a oferta e devolve o foco', s.layer === 'base', s);
  await mock('/__mock/sinal?tipo=aviso');
  check('sinal "aviso" mostra o aviso no canto', await waitFor(p, () => { const n = document.querySelector('.notify'); return n && /Flamengo/.test(n.textContent); }, 5000));
  await key(p, K.RIGHT); await key(p, K.OK); s = await snap(p); check('"Fechar" fecha o aviso', s.layer === 'base' && !(await p.$('.notify')), s);
  const zb = (await snap(p)).zone;
  await mock('/__mock/sinal?tipo=atualizado'); await sleep(1500);
  check('sinal "atualizado" recarrega sem perder o foco', (await snap(p)).zone === zb, await snap(p));
  // ---------- sem perfis: barra do topo termina em Ajustes ----------
  await key(p, K.UP, 3); await key(p, K.RIGHT, 12); s = await snap(p); check('barra do topo termina em Ajustes (sem avatar)', s.zone === 'top' && s.text === 'Ajustes', s);
  check('nenhum avatar de perfil no topo', !(await p.$('#top .av')), null);
  allErrors.push(...p._errors);
  await p.close();

  // ---------- Continuar: último canal do Ao vivo (gravado pelo servidor no GET aovivo/tocar) ----------
  const ult0 = (await mock('/__mock/ultimo')).ultimo;
  check('GET aovivo/tocar gravou o último canal', !!(ult0 && ult0.id), ult0);
  p = await open(browser, '');
  await waitZone(p, 'destaque', 10000);
  const contList = () => p.evaluate(() => ((window.__play.S.inicio && window.__play.S.inicio.continuar) || []).map((c) => (c.tipo === 'canal' ? 'canal:' : '') + c.nome));
  await waitFor(p, () => (window.__play.S.inicio.continuar || []).some((c) => c.tipo === 'canal'), 6000);
  let cont = await contList();
  check('Continuar: canal em 2º (o título visto depois do canal vem antes)', cont.length > 1 && /^canal:/.test(cont[1]) && !/^canal:/.test(cont[0]), cont);
  const canalNome = (cont[1] || '').replace(/^canal:/, '');
  check('cartão de canal: logo/nome, selo AO VIVO e "Agora: programa"', await p.evaluate(() => {
    const e = document.querySelector('[data-zone="continuar"] .card.cv');
    return !!e && /AO VIVO/.test(e.querySelector('.lv').textContent) && /^Agora: /.test(e.querySelector('.sb').textContent) && !!e.querySelector('.lgn');
  }), await p.evaluate(() => { const e = document.querySelector('[data-zone="continuar"] .card.cv'); return e && e.textContent; }));
  await key(p, K.DOWN); await key(p, K.RIGHT); s = await snap(p);
  check('▶ chega no cartão do canal', s.zone === 'continuar' && s.text === canalNome, s);
  await key(p, K.RED); await key(p, K.BLUE); await sleep(300); s = await snap(p);
  check('vermelho/azul no canal não fazem nada (sem Minha lista nem detalhe)', s.layer === 'base' && s.zone === 'continuar', s);
  const emAntes = ult0 && ult0.em;
  await key(p, K.OK); await sleep(2500); s = await snap(p);
  check('OK no canal abre o ao vivo em tela cheia', s.layer === 'player' && await p.evaluate(() => !!(window.__play.S.playing && window.__play.S.playing.live)), s);
  const ult1 = (await mock('/__mock/ultimo')).ultimo;
  check('tocar o canal renova a hora do último canal', !!ult1 && ult1.em > emAntes, ult1);
  await key(p, K.BACK); await sleep(600);
  await mock('/__mock/sinal?tipo=atualizado');
  check('agora mais recente que o título: o canal vem em 1º', await waitFor(p, () => { const l = window.__play.S.inicio.continuar || []; return l[0] && l[0].tipo === 'canal'; }, 6000), await contList());
  await sleep(500);
  for (let i = 0; i < 5 && (await snap(p)).text !== canalNome; i++) await key(p, K.LEFT);
  s = await snap(p);
  await key(p, K.GREEN); await sleep(500);
  check('verde tira o canal do Continuar', s.text === canalNome && (await snap(p)).text !== canalNome && /saiu de Continuar/.test(await toastText(p)) && !(await contList()).some((x) => /^canal:/.test(x)), [s, await snap(p)]);
  check('verde apaga o último canal no servidor (POST preferencia ultimo_canal = null)', await logHas(/POST \/api\/play\/preferencia/) && (await mock('/__mock/ultimo')).ultimo === null, await mock('/__mock/ultimo'));
  allErrors.push(...p._errors);
  await p.close();

  // ---------- deep links ----------
  const inter = await mock('/__mock/id?nome=Interestelar');
  p = await open(browser, 'abrir=titulo&id=' + inter.id);
  check('deep link {abrir:"titulo"} abre o detalhe', await waitFor(p, () => { const b = document.querySelector('.det .big'); return b && /Interestelar/.test(b.textContent); }, 10000));
  allErrors.push(...p._errors);
  await p.close();
  p = await open(browser, 'abrir=v10&type=m&tmdb=' + inter.tmdb);
  check('deep link da v10 (open?type=&tmdb=) abre o detalhe', await waitFor(p, () => { const b = document.querySelector('.det .big'); return b && /Interestelar/.test(b.textContent); }, 10000));
  allErrors.push(...p._errors);
  await p.close();
  // Home 2.0: o pedido chega como contentTarget "abrir?titulo=ID" (aqui pelo webOSRelaunch)
  p = await open(browser, '');
  await p.evaluate((id) => document.dispatchEvent(new CustomEvent('webOSRelaunch', { detail: { contentTarget: 'abrir?titulo=' + id } })), inter.id);
  check('deep link da Home 2.0 (contentTarget abrir?titulo=) abre o detalhe', await waitFor(p, () => { const b = document.querySelector('.det .big'); return b && /Interestelar/.test(b.textContent); }, 10000));
  allErrors.push(...p._errors);
  await p.close();

  // ---------- IP mudou: endereço salvo morto, acha pelo IP já visto ----------
  p = await open(browser, 'servidor=' + encodeURIComponent('http://127.0.0.1:9'));
  check('endereço salvo não responde: acha o servidor pelos IPs já vistos', await waitFor(p, (m) => window.__play.net.base === m && /em sincronia|conectado/.test(document.querySelector('.syncd').textContent), 12000, MOCK),
    await p.evaluate(() => [window.__play.net.base, document.querySelector('.syncd').textContent]));
  allErrors.push(...p._errors);
  await p.evaluate(() => { localStorage.setItem('play2_servidor_ips', '[]'); localStorage.setItem('play2_servidor_porta', '9'); });
  await p.close();

  // ---------- servidor fora: cópia local ----------
  p = await open(browser, 'servidor=' + encodeURIComponent('http://127.0.0.1:9'));
  check('servidor fora: abre o Início da cópia local', await waitZone(p, 'destaque', 10000), await snap(p));
  check('indicador mostra "sem servidor"', await waitFor(p, () => /sem servidor/.test(document.querySelector('.syncd').textContent), 12000), await text(p, '.syncd'));
  await key(p, K.OK);
  check('toca direto da lista com a cópia de GET conta', await waitFor(p, () => document.getElementById('video').currentTime > 0.3, 12000), await p.evaluate(() => document.getElementById('video').src));
  check('a URL foi montada com o formato da conta (/movie/)', /\/xt\/movie\/demo\/demo\/\d+\.mp4$/.test(await p.evaluate(() => document.getElementById('video').src)), await p.evaluate(() => document.getElementById('video').src));
  await p.screenshot({ path: (process.env.SHOTS || 'dev/shots') + '/_offline-player.png' });
  await key(p, K.BACK); await sleep(500);
  await p.screenshot({ path: (process.env.SHOTS || 'dev/shots') + '/inicio-sem-servidor.png' });
  // Ao vivo sem servidor: lista de canais da cópia guardada (visitada lá em cima com o servidor no ar)
  for (let i = 0; i < 4 && (await snap(p)).zone !== 'top'; i++) await key(p, K.UP);
  await key(p, K.LEFT, 6); await key(p, K.RIGHT, 3); s = await snap(p);
  check('sem servidor: chega na aba Ao vivo', s.zone === 'top' && s.text === 'Ao vivo', s);
  await key(p, K.OK);
  check('sem servidor: Ao vivo mostra os grupos guardados', await waitFor(p, () => document.querySelectorAll('.lgr .c').length >= 3, 6000), await p.evaluate(() => document.querySelectorAll('.lgr .c').length));
  check('sem servidor: avisa que a lista é a guardada na TV', /lista de canais guardada/.test(await toastText(p)), await toastText(p));
  check('sem servidor: grupo aberto mostra canais da cópia', await waitFor(p, () => !!document.querySelector('.lbody') && !document.querySelector('.lbody .empty') && document.querySelector('.lbody').textContent.length > 20, 6000), await text(p, '.lbody'));
  await p.screenshot({ path: (process.env.SHOTS || 'dev/shots') + '/aovivo-sem-servidor.png' });
  allErrors.push(...p._errors);
  await p.close();

  // ---------- Gostei / Não gostei no destaque (gostei = já vi) ----------
  await mock('/__mock/reset');
  p = await open(browser, 'rapido=1');
  await waitZone(p, 'destaque', 10000);
  const hbig = () => text(p, '.hero .big');
  const notas = () => mock('/__mock/notas');
  const idDe = async (nome) => (await mock('/__mock/id?nome=' + encodeURIComponent(nome))).id;
  await key(p, K.RIGHT, 3); s = await snap(p);
  check('destaque: ▶▶▶ chega em "Gostei"', s.zone === 'destaque' && s.text === 'Gostei', s);
  const g1 = await hbig(), g1id = await idDe(g1);
  await key(p, K.OK); await sleep(400);
  check('OK em Gostei: POST nota valor 1', (await notas())[g1id] === 1 && await logHas(/POST \/api\/play\/nota/), await notas());
  check('toast "Marcado como visto · vamos recomendar parecidos" e botão marcado', /Marcado como visto · vamos recomendar parecidos/.test(await toastText(p)) && await p.evaluate(() => document.querySelector('.hero .btn[data-label="Gostei"]').classList.contains('sel')), await toastText(p));
  await key(p, K.OK); await sleep(400);
  check('OK de novo desfaz (valor 0) e o destaque não passa', (await notas())[g1id] === 0 && /Nota retirada/.test(await toastText(p)), await notas());
  await sleep(1600);
  check('desfeito: continua no mesmo destaque', (await hbig()) === g1, [g1, await hbig()]);
  await key(p, K.OK);
  check('Gostei de novo: passa para o próximo destaque', await waitFor(p, (a) => document.querySelector('.hero .big').textContent !== a, 3000, g1) && (await notas())[g1id] === 1, [g1, await hbig()]);
  const g2 = await hbig();
  check('foco continua em Gostei, sem marcação no novo', (await snap(p)).text === 'Gostei' && !(await p.evaluate(() => document.querySelector('.hero .btn[data-label="Gostei"]').classList.contains('sel'))), await snap(p));
  check('SSE "atualizado" (recomendações recalculadas): o gostado sai do destaque e a tela fica no mesmo título',
    await waitFor(p, (n) => !(window.__play.S.inicio.destaque || []).some((c) => c.nome === n), 6000, g1) && (await hbig()) === g2 && (await snap(p)).zone === 'destaque', [g2, await hbig(), await snap(p)]);
  await key(p, K.RIGHT); s = await snap(p);
  check('▶ vai para "Não gostei"', s.text === 'Não gostei', s);
  const g3id = await idDe(g2);
  await key(p, K.OK); await sleep(400);
  check('Não gostei: POST nota valor -1 e toast', (await notas())[g3id] === -1 && /Não vamos recomendar parecidos/.test(await toastText(p)), [await notas(), await toastText(p)]);
  check('Não gostei: passa para o próximo destaque', await waitFor(p, (a) => document.querySelector('.hero .big').textContent !== a, 3000, g2), [g2, await hbig()]);
  check('Não gostei: some do destaque depois do recálculo', await waitFor(p, (n) => !(window.__play.S.inicio.destaque || []).some((c) => c.nome === n), 6000, g2), await p.evaluate(() => window.__play.S.inicio.destaque.map((c) => c.nome)));
  await key(p, K.LEFT, 4); s = await snap(p);
  check('◀ do destaque volta para Assistir', s.text === 'Assistir', s);
  allErrors.push(...p._errors);
  await p.close();

  // ---------- trailer do destaque inteiro (TV simulada + helper falso na 3911) ----------
  await mock('/__mock/reset');
  helper.mode = 'fim'; helper.ms = 1500; helper.hits = [];
  p = await open(browser, 'reset=1', { tv: true });
  await waitZone(p, 'destaque', 10000);
  const big = () => text(p, '.hero .big');
  const frameSrc = () => p.evaluate(() => { const f = document.querySelector('.hero .bd-tv iframe'); return f ? f.src : ''; });
  check('TV: o trailer do destaque começa sozinho (iframe do helper)', await waitFor(p, () => !!document.querySelector('.hero .bd-tv iframe'), 4000) && /127\.0\.0\.1:3911\/trailer\?v=/.test(await frameSrc()), await frameSrc());
  const h1 = await big();
  check("'fim' do helper passa para o próximo destaque", await waitFor(p, (a) => document.querySelector('.hero .big').textContent !== a, 6000, h1), [h1, await big()]);
  helper.mode = 'texto';
  const h2 = await big();
  check('o próximo destaque também começa o trailer sozinho', await waitFor(p, () => !!document.querySelector('.hero .bd-tv iframe'), 4000) && helper.hits.length >= 2, helper.hits.length);
  check("'fim' como texto JSON também vale", await waitFor(p, (a) => document.querySelector('.hero .big').textContent !== a, 6000, h2), [h2, await big()]);
  helper.mode = 'erro'; helper.ms = 300;
  const h3 = await big();
  const n3 = helper.hits.length;
  await waitFor(p, (a) => document.querySelector('.hero .big').textContent !== a, 8000, h3);
  const tAdv = Date.now();
  const hit3 = helper.hits[n3];
  check("'erro' passa para o próximo depois de 2 s", !!hit3 && hit3.mode === 'erro' && (await big()) !== h3 && tAdv - hit3.at >= 2000, hit3 && { ms: tAdv - hit3.at });
  helper.mode = 'nada';
  await waitFor(p, () => !!document.querySelector('.hero .bd-tv iframe'), 4000);
  const h4 = await big();
  await key(p, K.UP); s = await snap(p);
  check('▲ do destaque vai para a barra do topo (o giro de 12 s volta a valer)', s.zone === 'top', s);
  await sleep(13500);
  check('sem "fim", o trailer não é cortado pelo giro de 12 s', (await big()) === h4 && !!(await frameSrc()), [h4, await big()]);
  await key(p, K.DOWN); await key(p, K.RIGHT); s = await snap(p);
  check('foco em "Mais informações"', s.text === 'Mais informações', s);
  await key(p, K.OK); await waitZone(p, 'botoes', 6000);
  check('abrir o detalhe para o trailer do destaque', await waitFor(p, () => !document.querySelector('.hero .bd-tv iframe'), 2500), await frameSrc());
  await key(p, K.BACK); await sleep(300);
  helper.mode = 'fim'; helper.ms = 1500;
  const h5 = await big();
  for (let i = 0; i < 5 && (await big()) === h5; i++) { await key(p, K.RIGHT); await sleep(200); }   // ▶ no último botão troca
  const h6 = await big();
  check('◀ ▶ troca o destaque e o trailer do novo começa', h6 !== h5 && await waitFor(p, () => !!document.querySelector('.hero .bd-tv iframe'), 4000), [h5, h6]);
  check('trailer na área da arte (1420x1000)', await p.evaluate(() => { const r = document.querySelector('.hero .bd-tv iframe').getBoundingClientRect(); return r.width === 1420 && r.height === 1000; }), null);
  await key(p, K.DOWN); s = await snap(p);
  check('▼ para o Continuar rola a tela e o trailer para', s.zone === 'continuar' && await waitFor(p, () => !document.querySelector('.hero .bd-tv iframe'), 2500), s);
  allErrors.push(...p._errors);
  await p.close();
} catch (e) {
  check('execução sem exceção', false, String(e && e.stack || e));
} finally { await browser.close(); helperSrv.close(); }

check('nenhum erro de JavaScript na página', allErrors.length === 0, allErrors.slice(0, 5));
const bad = results.filter((r) => !r.ok);
console.log('\n' + (results.length - bad.length) + ' de ' + results.length + ' verificações passaram.');
process.exitCode = bad.length ? 1 : 0;
