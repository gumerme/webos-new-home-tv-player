// Capturas de cada tela principal em dev/shots (Chrome headless 1920x1080, servidor simulado).
// Requer: npm run build e node dev/mock-server.mjs rodando.
import { launch, open, key, sleep, snap, waitZone, waitFor, typeText, mock, K } from './harness.mjs';
import { mkdirSync } from 'node:fs';

const OUT = process.env.SHOTS || 'dev/shots';
mkdirSync(OUT, { recursive: true });
const only = process.argv[2] ? process.argv[2].split(',') : null;
const want = (n) => !only || only.includes(n);
const errors = [];
async function shot(page, name) { await sleep(700); await page.screenshot({ path: OUT + '/' + name + '.png' }); console.log('ok  ' + name + '.png  ' + JSON.stringify(await snap(page))); }
async function fresh(browser, q) { await mock('/__mock/reset'); const p = await open(browser, q); return p; }
async function home(browser) {
  const p = await fresh(browser);
  await waitZone(p, 'destaque', 10000); await sleep(800);
  return p;
}
async function tab(p, n) { // n: 0 Início ... 5 Minha lista, 6 busca, 7 ajustes
  for (let i = 0; i < 12; i++) { const s = await snap(p); if (s.zone === 'top') break; await key(p, K.BACK); }
  await key(p, K.LEFT, 9, 60);
  await key(p, K.RIGHT, n, 80);
  await key(p, K.OK);
  await sleep(1400);
}
/** Ao vivo: anda na lista vertical de grupos até o grupo pedido (a lista lembra o último grupo). */
async function toGroup(p, nome) {
  const names = await p.evaluate(() => Array.prototype.map.call(document.querySelectorAll('.lgr .c'), (e) => e.getAttribute('data-label')));
  const want = names.indexOf(nome);
  for (let i = 0; i < 20; i++) {
    const s = await snap(p);
    if (s.zone !== 'grupos') { await key(p, K.BACK); continue; }
    if (s.index === want) return;
    await key(p, s.index < want ? K.DOWN : K.UP);
  }
}
const browser = await launch();
try {
  if (want('procurando')) {
    await mock('/__mock/reset');
    const p = await open(browser, 'servidor=' + encodeURIComponent('http://127.0.0.1:9'));
    await waitFor(p, () => !!document.querySelector('.fullscr.boot'), 10000);
    await sleep(2500);
    await shot(p, 'procurando'); errors.push(...p._errors); await p.close();
  }
  if (want('aprovacao')) {
    await mock('/__mock/reset'); await mock('/__mock/pendente?v=1');
    const p = await open(browser);
    await waitFor(p, () => !!document.querySelector('.code'), 10000);
    if (want('aprovacao')) await shot(p, 'aprovacao');
    await mock('/__mock/aprovar');
    await key(p, K.OK); // "Verificar agora"
    await waitZone(p, 'destaque', 10000);
    errors.push(...p._errors); await p.close(); await mock('/__mock/pendente?v=0');
  }
  const p = await home(browser);
  if (want('inicio')) await shot(p, 'inicio');
  if (want('inicio-rolado')) { await key(p, K.DOWN, 2); await key(p, K.CH_DOWN); await key(p, K.RIGHT, 2); await shot(p, 'inicio-rolado'); }
  if (want('detalhe')) {
    await key(p, K.UP, 6); await waitZone(p, 'top'); await key(p, K.DOWN); await key(p, K.RIGHT); await key(p, K.OK);
    await waitZone(p, 'botoes', 8000); await shot(p, 'detalhe');
    for (let i = 0; i < 6; i++) { if ((await snap(p)).zone === 'elenco') break; await key(p, K.DOWN); }
    await shot(p, 'detalhe-elenco');
    await key(p, K.UP, 6);
    if (want('player-osd')) {
      await key(p, K.OK); await waitFor(p, () => { const v = document.getElementById('video'); return v && v.currentTime > 0.5; }, 10000);
      await key(p, K.RIGHT); await shot(p, 'player-osd');
      await key(p, K.UP); await sleep(300); await shot(p, 'player-legendas'); await key(p, K.BACK);
      await key(p, K.BACK); await waitZone(p, 'botoes', 6000);
    }
    await key(p, K.BACK); await waitZone(p, 'destaque', 4000);
  }
  if (want('ranking')) { await tab(p, 4); await key(p, K.RIGHT, 3); await key(p, K.DOWN, 2); await shot(p, 'ranking'); }
  if (want('series-suas')) { await tab(p, 1); await key(p, K.RIGHT, 2); await key(p, K.OK); await sleep(1200); await key(p, K.DOWN); await shot(p, 'series-suas'); }
  if (want('filmes-categorias')) { await tab(p, 2); await key(p, K.RIGHT, 5); await key(p, K.OK); await sleep(1500); await key(p, K.RIGHT); await sleep(300); await shot(p, 'filmes-categorias'); }
  if (want('aovivo')) {
    await tab(p, 3); await sleep(800); await toGroup(p, 'Jogos de hoje'); await sleep(1500);
    await shot(p, 'aovivo-lista');
    await key(p, K.RIGHT, 2); await shot(p, 'aovivo-jogos');
    await key(p, K.DOWN); await key(p, K.RIGHT); await sleep(300);
    await shot(p, 'aovivo-grade');
    await mock('/__mock/sinal?tipo=aviso'); await sleep(800); await shot(p, 'aovivo'); await key(p, K.BACK);
    await key(p, K.BACK); await toGroup(p, 'Esportes'); await sleep(1500); await shot(p, 'aovivo-grupo');
    await key(p, K.RIGHT); await key(p, K.DOWN, 2); await key(p, K.RIGHT); await sleep(500); await shot(p, 'aovivo-grupo-grade');
    await key(p, K.BACK); await toGroup(p, 'Jogos de hoje'); await sleep(1500);
  }
  if (want('aovivo-previa')) {
    await tab(p, 3); await sleep(800); await toGroup(p, 'Esportes'); await key(p, K.OK); await sleep(1500);
    await key(p, K.OK); await sleep(3000); await shot(p, 'aovivo-previa');
    await key(p, K.OK); await sleep(2500); await shot(p, 'aovivo-tela-cheia'); await key(p, K.BACK); await sleep(500); await key(p, K.BACK);
  }
  if (want('busca')) { await tab(p, 6); await waitZone(p, 'teclado'); await typeText(p, 'leandro hasum'); await sleep(1200); await key(p, K.RIGHT, 6); await key(p, K.DOWN); await shot(p, 'busca'); }
  if (want('ajustes')) { await tab(p, 7); await shot(p, 'ajustes'); }
  if (want('lista')) { await tab(p, 5); await key(p, K.RIGHT, 2); await shot(p, 'minha-lista'); }
  errors.push(...p._errors);
  if (want('player-extra')) {
    const reacher = await mock('/__mock/id?nome=Reacher');
    const q = await open(browser, 'abrir=tocar&id=' + reacher.id + '&temporada=3&episodio=2');
    await waitFor(q, () => document.getElementById('video').currentTime > 4, 15000);
    await shot(q, 'player-pular-abertura');
    await key(q, K.FF, 2); await key(q, K.OK); await key(q, K.RIGHT, 4); await sleep(1500);
    await shot(q, 'proximo-episodio');
    errors.push(...q._errors); await q.close();
    const inter = await mock('/__mock/id?nome=Interestelar');
    const r = await open(browser, 'abrir=tocar&id=' + inter.id);
    await waitFor(r, () => document.getElementById('video').currentTime > 1, 15000);
    await key(r, K.FF, 2); await key(r, K.OK); await key(r, K.RIGHT, 4); await sleep(2500);
    await shot(r, 'pos-filme');
    errors.push(...r._errors); await r.close();
  }
} finally { await browser.close(); }
if (errors.length) { console.log('ERROS DA PÁGINA:'); errors.forEach((e) => console.log('  ' + e)); process.exitCode = 1; }
