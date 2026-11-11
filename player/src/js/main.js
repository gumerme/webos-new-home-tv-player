// WebOS TV Player 2.0 — ponto de entrada: barra do topo, abas, ações, partida (descoberta,
// registro/aprovação, perfis, importação da v10), sinais, deep links e memória.

import './polyfill.js';
import { h, $, icon, clear, KEY, clock, ago, fmtTime, parseDate, OLD_ENGINE, ON_TV, APP_VERSION } from './util.js';
import { F, Layer, push, pop, top, paint, handleKey, snapshot } from './focus.js';
import { MatrixZone } from './zones.js';
import { net, api, xhr, HttpError } from './net.js';
import { lsGet, lsSet, idbGet, idbSet } from './store.js';
import { discover, normalizeBase, testAddress } from './discovery.js';
import { S, A } from './state.js';
import { toast, notify, closeNotify } from './ui.js';
import { homeScreen } from './screens/home.js';
import { sectionScreen } from './screens/section.js';
import { rankingScreen } from './screens/ranking.js';
import { liveScreen } from './screens/live.js';
import { myListScreen } from './screens/mylist.js';
import { searchScreen } from './screens/search.js';
import { settingsScreen } from './screens/settings.js';
import { openDetail, openPerson, closeAllOverlays, overlayOpen, currentDetail } from './screens/detail.js';
import { searchingScreen, approvalScreen } from './screens/boot.js';
import * as player from './player.js';
import { guardarAoVivo } from './livecache.js';
import * as signals from './signals.js';
import { parseLaunch, devLaunch } from './launch.js';
import { importV10 } from './importv10.js';

const DEV = /^https?:/.test(location.protocol);
if (OLD_ENGINE) document.documentElement.className += ' old-engine';

// ---------- barra do topo ----------
const TABS = [['inicio', 'Início'], ['series', 'Séries'], ['filmes', 'Filmes'], ['aovivo', 'Ao vivo'], ['ranking', 'Ranking'], ['lista', 'Minha lista']];
const topEl = $('#top');
const screenEl = $('#screen');
const tabEls = TABS.map(function (t) {
  const e = h('span.tab', { text: t[1], 'data-tab': t[0], 'data-label': t[1] });
  e._ok = function () { showTab(t[0], true); };
  return e;
});
const searchBtn = h('span.tb', { html: icon('search'), 'data-label': 'Buscar', 'data-tab': 'busca' });
searchBtn._ok = function () { showTab('busca', true); };
const gearBtn = h('span.tb', { html: icon('gear'), 'data-label': 'Ajustes', 'data-tab': 'ajustes' });
gearBtn._ok = function () { showTab('ajustes', true); };
const syncEl = h('span.syncd', null, h('i'), h('span', { text: '' }));
const clockEl = h('span.clk');
topEl.appendChild(h('div.logo', null, h('span.mk', { html: icon('play', 'fill') }), 'Player'));
topEl.appendChild(h('div.tabs', null, tabEls));
topEl.appendChild(h('div.tr', null, syncEl, searchBtn, gearBtn, clockEl));
const topZone = new MatrixZone({
  key: 'top', el: topEl, rows: [tabEls.concat([searchBtn, gearBtn])], scrollY: 0, entry: 'selected',
  selected: function () { return tabEls.concat([searchBtn, gearBtn]).filter(function (e) { return e.getAttribute('data-tab') === S.tab; })[0] || tabEls[0]; }
});
function paintTop() {
  tabEls.concat([searchBtn, gearBtn]).forEach(function (e) { e.classList.toggle('on', e.getAttribute('data-tab') === S.tab); });
  clockEl.textContent = clock();
  syncEl.className = 'syncd' + (S.online ? (S.sse ? ' ok' : '') : ' off');
  syncEl.lastChild.textContent = S.online ? (S.sse ? 'em sincronia' : 'conectado') : 'sem servidor · cópia local';
}
setInterval(paintTop, 30000);

// ---------- camada base e telas ----------
let current = null;
const base = new Layer({
  name: 'base',
  onBack: function () {
    if (current && current.onBack && current.onBack()) return;
    const z = base.zone();
    if (z && z.key !== 'top') { base.focusZone('top'); return; }
    if (S.tab !== 'inicio') { showTab('inicio'); return; }
    exitApp();
  },
  onKey: function (k) { return !!(current && current.onKey && current.onKey(k)); },
  onScroll: function (y) { topEl.classList.toggle('solid', y > 0); if (current && current.onScroll) current.onScroll(y); }
});
push(base);
F.onAnyKey = function () { if (current && current.onAnyKey && top() === base) current.onAnyKey(); };

const ctx = {
  setZones: function (zones, keep, focusKey) {
    const cur = base.zone();
    let key = null, idx = null;
    if (keep && cur) { key = cur.key; idx = cur.index ? cur.index() : null; }
    if (focusKey) { key = focusKey; idx = null; }
    if (!keep && !focusKey) key = cur && cur.key !== 'top' && zones[0] ? zones[0].key : 'top';
    base.setZones([topZone].concat(zones), key, idx);
  },
  refresh: function () { if (current) current.load(true); },
  repaint: function () { if (top() === base) paint(); },
  scrollY: function () { return base.scrollY; },
  isTop: function () { return top() === base; },
  topName: function () { const t = top(); return t ? t.name : null; },
  focusZoneKey: function () { const z = base.zone(); return z ? z.key : null; },
  focusZone: function (key) { base.focusZone(key); }
};

const FACTORY = {
  inicio: homeScreen,
  series: function (c) { return sectionScreen(c, 's'); },
  filmes: function (c) { return sectionScreen(c, 'm'); },
  aovivo: liveScreen,
  ranking: rankingScreen,
  lista: myListScreen,
  busca: searchScreen,
  ajustes: settingsScreen
};

function showTab(tab, focusContent) {
  if (current && current.destroy) current.destroy();
  S.tab = tab;
  paintTop();
  clear(screenEl);
  topEl.classList.remove('solid');
  current = FACTORY[tab](ctx);
  screenEl.appendChild(current.el);
  base.scroller = current.scroller || null;
  base.viewH = current.viewH || 1080;
  base.anchor = current.anchor != null ? current.anchor : 150;
  base.scrollY = 0;
  if (base.scroller) base.scroller.style.transform = '';
  base.setZones([topZone], 'top');
  const p = current.load(false) || Promise.resolve();
  // a tela pode pedir outra zona de entrada (ex.: Ranking entra na grade; ▲ chega em Séries/Filmes)
  if (focusContent) p.then(function () {
    const want = current && current.entry && base.indexOf(current.entry) !== -1 ? current.entry : (base.zones[1] && base.zones[1].key);
    if (want && base.zone() === topZone) base.focusZone(want);
  });
  return p;
}
function exitApp() {
  if (window.PalmSystem && window.PalmSystem.platformBack) window.PalmSystem.platformBack();
  else window.close();
}

// ---------- ações ----------
A.openDetail = function (c, opts) {
  if (!c) return;
  if (c.id == null) { A.toggleAviso(c); return; }
  closeNotify();
  openDetail(c, opts);
};
A.openPerson = function (p) { if (p && p.tmdb != null) openPerson(p); };
A.afterOverlay = function () { if (S.stale && current && current.tab === 'inicio') { S.stale = false; current.load(true); } };
A.play = function (o) {
  o.onClose = function () { const d = currentDetail(); if (d) d.reload(true); };
  player.playVod(o);
};
A.playTitle = function (c) { A.play({ cartao: c }); };
A.resume = function (c) {
  const p = c.progresso || {};
  A.play({ cartao: c, temporada: p.temporada || null, episodio: p.episodio || null, inicio_s: p.proximo ? 0 : null });
};
A.playChannel = function (c, o) { player.playLive(c, o); };
A.stopPreview = function () { player.stopPreview(); };
A.holdPreview = function () { player.holdPreview(); };
A.afterPlayer = function (wasLive) {
  paintTop();
  if (overlayOpen()) return;
  if (S.stale && current && current.tab === 'inicio' && !wasLive) { S.stale = false; current.load(true); }
  paint();
};
A.toggleLista = function (c) {
  const add = !c.minha_lista;
  return api.post('lista', { titulo: c.id, acao: add ? 'add' : 'del' }).then(function () {
    c.minha_lista = add;
    if (S.inicio) {
      const list = (S.inicio.minha_lista || []).filter(function (x) { return x.id !== c.id; });
      if (add) list.unshift(c);
      S.inicio.minha_lista = list;
      idbSet('inicio:' + S.perfil, S.inicio);
    }
    S.stale = true;
    toast(add ? c.nome + ' entrou na Minha lista' : c.nome + ' saiu da Minha lista');
  }, function () { toast('Não foi possível agora (servidor fora?).'); });
};
/**
 * Gostei (1) / Não gostei (-1) no Detalhe e no destaque: apertar de novo o mesmo desfaz (0).
 * Gostei = já vi (09/11 noite): o servidor trata como visto e recalcula as recomendações na hora
 * (chega `atualizado` pelo SSE e o Início recarrega). Devolve a nota nova.
 */
A.rateTitle = function (c, v) {
  c.nota_usuario = c.nota_usuario === v ? 0 : v;
  api.fire('nota', { titulo: c.id, valor: c.nota_usuario });
  S.stale = true;
  toast(c.nota_usuario === 1 ? 'Marcado como visto · vamos recomendar parecidos' : c.nota_usuario === -1 ? 'Não vamos recomendar parecidos' : 'Nota retirada');
  return c.nota_usuario;
};
A.toggleAviso = function (c) {
  const k = c.tmdb ? 'tmdb:' + c.tmdb : '';
  const id = c.aviso_id || (k && S.avisosLigados[k]);
  if (id && id !== true) {
    return api.post('aviso/' + id + '/cancelar', {}).then(function () { c.aviso_id = null; if (k) delete S.avisosLigados[k]; toast('Aviso desligado'); },
      function () { toast('Não foi possível agora.'); });
  }
  return api.post('aviso', { tipo: 'chegar', alvo: { tmdb: c.tmdb, tipo: c.tipo, nome: c.nome } }).then(function (r) {
    c.aviso_id = (r && r.id) || null;
    if (k) S.avisosLigados[k] = (r && r.id) || true;
    toast('Combinado: avisamos quando ' + c.nome + ' chegar na lista.');
  }, function (e) {
    if (e && e.status === 409) { toast(c.nome + ' já está na lista. Atualizando…'); S.stale = true; return; }
    toast('Não foi possível ligar o aviso.');
  });
};
A.hideContinue = function (c) {
  // último canal do Ao vivo: apaga a preferência 'ultimo_canal' (volta quando tocar um canal de novo)
  if (isCanal(c)) api.fire('preferencia', { chave: 'ultimo_canal', valor: null });
  else api.fire('continuar-ocultar', { titulo: c.id });
  if (S.inicio) S.inicio.continuar = (S.inicio.continuar || []).filter(function (x) { return !(x.id === c.id && isCanal(x) === isCanal(c)); });
  toast(c.nome + ' saiu de Continuar assistindo');
};
/** Continuar: o último canal do Ao vivo vem como {tipo:'canal', ...} no meio dos títulos. */
function isCanal(c) { return !!c && c.tipo === 'canal'; }
function continueTitles() { return ((S.inicio && S.inicio.continuar) || []).filter(function (x) { return !isCanal(x); }); }
A.toggleFav = function (canal) {
  const add = !canal.favorito;
  return api.post('aovivo/favorito', { canal: canal.id, acao: add ? 'add' : 'del' }).then(function () {
    canal.favorito = add;
    toast(add ? canal.nome + ' nos favoritos' : canal.nome + ' fora dos favoritos');
  }, function () { toast('Não foi possível agora.'); });
};
A.loadInicio = function () {
  if (!net.base || !net.token) return Promise.resolve(S.inicio);
  const perfil = S.perfil;
  return api.get('inicio', null, { timeout: 15000 }).then(function (d) {
    if (perfil !== S.perfil) return null;
    S.inicio = d;
    idbSet('inicio:' + perfil, d);
    return d;
  }, function () { return S.inicio; });
};
A.applyAjustes = function () {
  const anim = S.ajustes.animacoes !== false && !(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
  document.body.classList.toggle('anim', anim);
};
A.useAddress = function (v) {
  const b = normalizeBase(v);
  toast('Testando ' + b + '…');
  return testAddress(b).then(function (info) {
    if (!info) { toast('Nada respondeu em ' + b + '. Confira o endereço.', 5000); return false; }
    lsSet('servidor', b);
    setBase(b);
    toast('Servidor encontrado: ' + b);
    afterServer();
    return true;
  });
};
A.rediscover = function (loud) { return rediscover(loud); };
A.importV10 = function (force) {
  return importV10(force).then(function (r) {
    if (r && r.importados) toast('Histórico da versão anterior importado para o perfil Casa.', 5000);
    else if (force) toast('Nada para importar desta TV.');
  });
};
const seasonsCache = {};
A.cacheSeasons = function (id, temporadas) { seasonsCache[id] = temporadas; idbSet('temps:' + id, temporadas); };
A.offlineEpisode = function (c, t, e) {
  const ts = seasonsCache[c.id] || [];
  for (let i = 0; i < ts.length; i++) {
    if (t != null && ts[i].numero !== t) continue;
    for (let j = 0; j < ts[i].episodios.length; j++) if (e == null || ts[i].episodios[j].numero === e) return ts[i].episodios[j];
  }
  return null;
};

// ---------- servidor, token, perfil ----------
function setBase(b) { net.base = b; S.online = true; paintTop(); }
let booted = false, searching = null, retryTimer = null;

function devServer() {
  if (!DEV) return null;
  const m = /[?&]servidor=([^&]+)/.exec(location.search);
  return m ? decodeURIComponent(m[1]) : null;
}

function rediscover(loud) {
  if (searching) return searching;
  if (loud) toast('Procurando o homeserver…');
  searching = discover({ noScan: DEV && /[?&]semvarredura=1/.test(location.search) }).then(function (b) {
    searching = null;
    if (b) { serverGaveUp = false; const changed = b !== net.base; setBase(b); if (changed || !S.sse) afterServer(); if (loud) toast('Servidor: ' + b); }
    else { S.online = false; paintTop(); scheduleRetry(); if (loud) toast('O homeserver não respondeu. Seguindo com a cópia local.', 5000); }
    return b;
  });
  return searching;
}
function scheduleRetry() { clearTimeout(retryTimer); retryTimer = setTimeout(function () { rediscover(false); }, 30000); }
let downTimer = null;
net.onDown = function () {
  if (!booted) return;
  clearTimeout(downTimer);
  downTimer = setTimeout(function () { S.online = false; paintTop(); rediscover(false); }, 1500);
};
let approval = null;
net.onAuthError = function (status, body) {
  if (!booted || approval) return;
  if (status === 403 && body && body.codigo) needApproval(body.codigo, body.bloqueada).then(afterServer);
  if (status === 401) { lsSet('token', null); net.token = ''; ensureToken().then(afterServer); }
};

/** Primeira descoberta: com cópia local abre na hora e procura em silêncio. */
function findServer(override, quiet) {
  return new Promise(function (resolve) {
    let screen = null, cancelled = false;
    const showTimer = quiet ? null : setTimeout(function () { screen = openSearching(); }, 1200);
    function openSearching() {
      return searchingScreen({
        hasCache: !!S.inicio || !!lsGet('conta', null),
        onRetry: function () { attempt(null); },
        onAddress: function (v) {
          const b = normalizeBase(v);
          screen && screen.phase('Testando ' + b + '…');
          testAddress(b).then(function (info) {
            if (info) { lsSet('servidor', b); done(b); }
            else screen && screen.phase('Nada respondeu em ' + b + '.');
          });
        },
        onOffline: function () { done(null); }
      });
    }
    function done(b) {
      if (cancelled) return;
      cancelled = true;
      clearTimeout(showTimer); clearTimeout(retryTimer);
      if (screen) screen.close();
      resolve(b);
    }
    let running = false;
    function attempt(ov) {
      if (running || cancelled) return;
      running = true;
      if (screen) screen.next('');
      discover({
        override: ov, noScan: DEV && /[?&]semvarredura=1/.test(location.search),
        cancelled: function () { return cancelled; },
        onPhase: function (t) { if (screen) screen.phase(t); },
        onProgress: function (a, b) { if (screen) screen.progress(a, b); }
      }).then(function (b) {
        running = false;
        if (b) { done(b); return; }
        if (quiet) { done(null); return; }
        if (!screen) { clearTimeout(showTimer); screen = openSearching(); }
        screen.phase('Não encontrei o homeserver na rede.');
        screen.next('Tento de novo em 30 s. Confira se ele está ligado.');
        clearTimeout(retryTimer);
        retryTimer = setTimeout(function () { attempt(null); }, 30000);
      });
    }
    attempt(override);
  });
}

function deviceInfo() {
  let info = {};
  try { info = JSON.parse((window.PalmSystem && window.PalmSystem.deviceInfo) || '{}') || {}; } catch (e) { info = {}; }
  const m = /Chrome\/(\d+)/.exec(navigator.userAgent);
  let duid = lsGet('duid', null);
  if (!duid) { duid = 'web-' + Math.random().toString(36).slice(2, 10) + Date.now().toString(36); lsSet('duid', duid); }
  return {
    nome: info.modelName ? 'TV ' + info.modelName : (ON_TV ? 'TV LG' : 'Navegador (teste)'),
    modelo: info.modelName || (ON_TV ? '' : 'navegador'),
    duid: duid, versao: APP_VERSION,
    webos: info.platformVersion || (OLD_ENGINE ? '4' : '') || (m ? 'Chrome ' + m[1] : '')
  };
}
function lunaDuid() {
  return new Promise(function (resolve) {
    if (!window.PalmServiceBridge) { resolve(null); return; }
    let done = false;
    const t = setTimeout(function () { if (!done) { done = true; resolve(null); } }, 2000);
    try {
      const b = new window.PalmServiceBridge();
      b.onservicecallback = function (msg) {
        if (done) return;
        done = true; clearTimeout(t);
        try { const r = JSON.parse(msg); resolve(r.idList && r.idList[0] && r.idList[0].idValue || null); } catch (e) { resolve(null); }
      };
      b.call('luna://com.webos.service.sm/deviceid/getIDs', JSON.stringify({ idType: ['LGUDID'] }));
    } catch (e) { done = true; clearTimeout(t); resolve(null); }
  });
}

function register() {
  return lunaDuid().then(function (duid) {
    if (duid) lsSet('duid', duid);
    return api.post('tv/registrar', deviceInfo(), { perfil: false });
  }).then(function (r) {
    if (r && r.token) { net.token = r.token; lsSet('token', r.token); }
    return r;
  });
}
function needApproval(codigo, bloqueada) {
  return new Promise(function (resolve) {
    approval = approvalScreen({ codigo: codigo, nome: deviceInfo().nome, onCheck: check });
    if (bloqueada) approval.status('Esta TV está bloqueada no painel · tento de novo a cada 10 s');
    let timer = null;
    function check() {
      clearTimeout(timer);
      approval.status('Verificando…');
      api.get('estado', null, { perfil: false }).then(function (e) {
        S.estado = e; lsSet('estado', e);
        approval.close(); approval = null;
        resolve();
      }, function (err) {
        if (err && err.body && err.body.codigo) approval.code(err.body.codigo);
        approval.status(err && err.status === 403 ? (err.body && err.body.bloqueada ? 'Esta TV está bloqueada no painel · tento de novo em 10 s' : 'Ainda aguardando aprovação · tento de novo em 10 s') : 'Sem resposta do servidor · tento de novo em 10 s');
        timer = setTimeout(check, 10000);
      });
    }
    timer = setTimeout(check, 10000);
  });
}
function ensureToken() {
  const step = net.token ? Promise.resolve(null) : register();
  return step.then(function (reg) {
    if (reg && reg.status === 'pendente') return needApproval(reg.codigo);
    return api.get('estado', null, { perfil: false }).then(function (e) { S.estado = e; lsSet('estado', e); }, function (err) {
      if (err && err.status === 403) return needApproval(err.body && err.body.codigo, err.body && err.body.bloqueada);
      if (err && err.status === 401) { net.token = ''; lsSet('token', null); return ensureToken(); }
      throw err;
    });
  }).then(function () {
    const e = S.estado || {};
    if (e.tv) { S.tvNome = e.tv.nome || ''; S.tvStatus = e.tv.status || ''; }
  });
}
function loadProfiles() {
  return api.get('perfis', null, { perfil: false }).then(function (list) {
    S.perfis = list || [];
    lsSet('perfis', S.perfis);
  }, function () { S.perfis = lsGet('perfis', []); });
}
// Sem "Quem está assistindo?": a casa toda usa um perfil só — o padrão desta TV no servidor
// (estado.perfil), senão o "Casa". Histórico, Continuar e recomendações ficam todos nele.
function pickProfileIfNeeded() {
  const e = S.estado || {};
  const casa = S.perfis.filter(function (p) { return /^casa$/i.test(p.nome); })[0];
  const id = e.perfil != null ? e.perfil : (casa ? casa.id : (S.perfis[0] ? S.perfis[0].id : 1));
  if (id !== S.perfil) { S.perfil = id; net.perfil = id; lsSet('perfil', id); S.inicio = null; }
  return Promise.resolve();
}
function loadSide() {
  const perfil = S.perfil;
  return Promise.all([
    api.get('preferencias').then(function (p) { if (perfil === S.perfil) { S.prefs = p || {}; lsSet('prefs_' + perfil, S.prefs); } }, function () {}),
    api.get('conta', null, { perfil: false }).then(function (c) { S.conta = c; lsSet('conta', c); }, function () {}),
    api.get('avisos').then(function (list) {
      const m = {};
      (list || []).forEach(function (a) {
        const al = a.alvo || {};
        if (a.tipo === 'chegar' && al.tmdb) m['tmdb:' + al.tmdb] = a.id;
        if (a.tipo === 'programa' && al.programa) m['prog:' + al.programa] = a.id;
        if (a.tipo === 'jogo' && al.jogo) m['jogo:' + al.jogo] = a.id;
      });
      S.avisosLigados = m;
    }, function () {})
  ]);
}

// ---------- sinais ----------
const offered = {};
const quiet = {};
function sse() {
  signals.connect({
    ping: function (d) { if (d && d.tv && d.ola) S.tvNome = d.tv; },
    progresso: function (d) {
      if (!d || d.tv === S.tvNome) return;
      updateContinue(d);
      clearTimeout(quiet[d.titulo]);
      if (d.terminado || d.evento === 'fim' || d.evento === 'inicio') return;
      if (d.evento === 'pausa') { offerContinue(d); return; }
      // ticks chegam a cada 15 s: 40 s sem notícia = a outra TV parou
      quiet[d.titulo] = setTimeout(function () { offerContinue(d); }, DEV && /[?&]rapido=1/.test(location.search) ? 3000 : 40000);
    },
    aviso: function (d) {
      if (!d) return;
      const acts = [];
      if (d.acao && d.acao.tipo === 'canal') acts.push({ label: 'Ir ao canal', primary: true, fn: function () { A.playChannel({ id: d.acao.id, nome: d.acao.nome || '' }, { mode: 'full' }); } });
      if (d.acao && d.acao.tipo === 'titulo') acts.push({ label: 'Abrir', primary: true, fn: function () { A.openDetail({ id: d.acao.id, nome: d.acao.nome || '' }); } });
      acts.push({ label: 'Fechar' });
      notify({ icon: 'bell', title: d.texto || 'Aviso', text: d.subtexto || '', actions: acts });
    },
    atualizado: function () {
      S.stale = true;
      if (top() === base && current && !player.isPlaying()) { S.stale = false; current.load(true); }
    }
  }, function (on) { if (S.sse !== on) { S.sse = on; if (on) S.online = true; paintTop(); } });
}
function updateContinue(d) {
  if (!S.inicio) return;
  const list = continueTitles();
  let c = null;
  list.forEach(function (x) { if (x.id === d.titulo) c = x; });
  const pct = d.duracao_s ? Math.round(d.posicao_s / d.duracao_s * 100) : 0;
  const prog = { pct: pct, restante_s: Math.max(0, (d.duracao_s || 0) - (d.posicao_s || 0)), temporada: d.temporada || null, episodio: d.episodio || null, tv: d.tv, quando: new Date().toISOString(), proximo: false };
  if (c) c.progresso = prog;
  else S.stale = true;
  if (current && current.tab === 'inicio' && top() === base) {
    const z = base.zones.filter(function (x) { return x.key === 'continuar'; })[0];
    if (z && c) z.updateItem(z.items.indexOf(c), c);
  }
}
function offerContinue(d) {
  if (player.isPlaying() || !d || !d.posicao_s) return;
  if (d.duracao_s && d.posicao_s > d.duracao_s * 0.95) return;
  const key = d.titulo + ':' + (d.temporada || '') + ':' + (d.episodio || '');
  if (offered[key] && Date.now() - offered[key] < 30 * 60000) return;
  offered[key] = Date.now();
  const card = continueTitles().filter(function (x) { return x.id === d.titulo; })[0] || { id: d.titulo, nome: d.nome, tipo: d.temporada ? 's' : 'm' };
  notify({
    thumb: card.poster, title: (d.nome || card.nome) + ' parou na ' + d.tv + ' ' + ago(new Date(Date.now() - 40000)),
    text: (d.temporada ? 'T' + d.temporada + ':E' + d.episodio + ' · ' : '') + fmtTime(d.posicao_s) + (d.duracao_s ? ' de ' + fmtTime(d.duracao_s) : ''),
    actions: [{ label: 'Continuar aqui', primary: true, fn: function () { A.play({ cartao: card, temporada: d.temporada, episodio: d.episodio, inicio_s: d.posicao_s }); } }, { label: 'Agora não' }]
  });
}
/** Ao abrir logo depois de parar na outra TV: oferta de continuar daqui. */
function offerOnOpen() {
  const c = continueTitles()[0];
  const p = c && c.progresso;
  if (!p || !p.tv || p.tv === S.tvNome || p.proximo) return;
  const when = parseDate(p.quando);
  if (!when || Date.now() - when.getTime() > 20 * 60000) return;
  const key = c.id + ':' + p.quando;
  if (offered[key]) return;
  offered[key] = Date.now();
  const dur = p.pct > 0 && p.pct < 100 && p.restante_s ? p.restante_s / (1 - p.pct / 100) : 0;
  notify({
    thumb: c.poster, title: c.nome + ' parou na ' + p.tv + ' ' + ago(when),
    text: (p.temporada ? 'T' + p.temporada + ':E' + p.episodio + ' · ' : '') + (dur ? fmtTime(dur - p.restante_s) + ' de ' + fmtTime(dur) : ''),
    actions: [{ label: 'Continuar aqui', primary: true, fn: function () { A.resume(c); } }, { label: 'Agora não' }]
  });
}

// ---------- deep links ----------
let pendingLaunch = null, serverGaveUp = false;
function runPending() { if (pendingLaunch) { const r = pendingLaunch; pendingLaunch = null; handleLaunch(r); } }
function handleLaunch(req) {
  if (!req) return;
  // ainda sem servidor (abrindo pela cópia local): guarda e roda quando ele for encontrado
  if (!booted || (!net.base && !serverGaveUp)) { pendingLaunch = req; return; }
  closeNotify();
  if (player.isPlaying()) player.close();
  if (req.abrir === 'titulo' && req.id != null) { closeAllOverlays(); A.openDetail({ id: req.id, nome: '' }, { temporada: req.temporada, episodio: req.episodio }); }
  else if (req.abrir === 'tocar' && req.id != null) {
    api.get('titulo/' + req.id).then(function (d) { A.play({ cartao: d, temporada: req.temporada, episodio: req.episodio }); },
      function () { A.play({ cartao: { id: req.id, nome: '' }, temporada: req.temporada, episodio: req.episodio }); });
  } else if (req.abrir === 'continuar') {
    A.loadInicio().then(function (d) {
      const c = continueTitles()[0];
      if (c) A.resume(c); else toast('Nada para continuar.');
    });
  } else if (req.abrir === 'canal' && req.id != null) A.playChannel({ id: req.id, nome: req.nome || '' }, { mode: 'full' });
  else if (req.abrir === 'v10') {
    api.get('titulo/por-tmdb', { tipo: req.tipo, tmdb: req.tmdb, xs: req.xs }).then(function (r) {
      if (!r || r.id == null) throw new HttpError(404);
      closeAllOverlays();
      A.openDetail({ id: r.id, nome: r.nome || req.titulo || '' }, { temporada: req.temporada, episodio: req.episodio });
    }).catch(function () { toast('"' + (req.titulo || 'Este título') + '" não está na sua lista.', 5000); });
  }
}
document.addEventListener('webOSRelaunch', function (ev) {
  if (window.PalmSystem && window.PalmSystem.activate) window.PalmSystem.activate();
  handleLaunch(parseLaunch(ev.detail));
});

// ---------- memória (app escondido) ----------
let releaseTimer = null, released = false;
document.addEventListener('visibilitychange', function () {
  clearTimeout(releaseTimer);
  if (document.hidden) {
    player.suspend();
    signals.close();
    releaseTimer = setTimeout(release, 30 * 60000);
    return;
  }
  if (!booted) return;
  sse();
  if (released) { released = false; idbGet('inicio:' + S.perfil).then(function (d) { S.inicio = d; showTab('inicio'); }); }
  else if (current && top() === base) current.load(true);
  A.loadInicio().then(offerOnOpen);
});
function release() {
  if (!document.hidden || player.isPlaying()) return;
  released = true;
  closeAllOverlays();
  if (current && current.destroy) current.destroy();
  current = null;
  clear(screenEl);
  S.inicio = null;
  base.setZones([topZone], 'top');
}

// ---------- teclado ----------
document.addEventListener('keydown', function (e) {
  let k = e.keyCode;
  if (DEV) { // teclas de teste no navegador
    if (k === 112) k = KEY.RED; else if (k === 113) k = KEY.GREEN; else if (k === 114) k = KEY.YELLOW; else if (k === 115) k = KEY.BLUE;
  }
  if (handleKey(k)) e.preventDefault();
});

// ---------- partida ----------
function afterServer() {
  return ensureToken().then(loadProfiles).then(pickProfileIfNeeded).then(function () {
    net.perfil = S.perfil;
    paintTop();
    return loadSide();
  }).then(function () {
    importV10(false).then(function (r) { if (r && r.importados) { toast('Histórico da versão anterior importado para o perfil Casa.', 5000); if (current) current.load(true); } });
    sse();
    setTimeout(function () { if (net.base) guardarAoVivo(false); }, 90000);   // cópia do Ao vivo para quando o homeserver cair
    if (!booted) enterApp(); else { if (current && top() === base) current.load(true); runPending(); }
  }).catch(function () {
    S.online = false; paintTop();
    if (!booted && (S.inicio || lsGet('conta', null))) enterApp();
    scheduleRetry();
  });
}
function enterApp() {
  booted = true;
  paintTop();
  showTab('inicio').then(function () {
    if (base.zone() === topZone && base.indexOf('destaque') !== -1) base.focusZone('destaque');
    else if (base.zone() === topZone && base.zones[1]) base.focusZone(base.zones[1].key);
    if (pendingLaunch && net.base) runPending();
    else if (!pendingLaunch) setTimeout(offerOnOpen, 800);
  });
}

function start() {
  S.ajustes = lsGet('ajustes', {}) || {};
  A.applyAjustes();
  if (DEV && /[?&]reset=1/.test(location.search)) { try { Object.keys(localStorage).forEach(function (k) { if (k.indexOf('play2_') === 0) localStorage.removeItem(k); }); } catch (e) { /* ignora */ } }
  net.token = lsGet('token', '') || '';
  S.perfil = lsGet('perfil', null);
  net.perfil = S.perfil;
  S.perfis = lsGet('perfis', []) || [];
  S.prefs = (S.perfil != null && lsGet('prefs_' + S.perfil, {})) || {};
  S.conta = lsGet('conta', null);
  S.estado = lsGet('estado', null);
  if (S.estado && S.estado.tv) S.tvNome = S.estado.tv.nome || '';
  pendingLaunch = parseLaunch() || devLaunch();
  paintTop();
  const cached = S.perfil != null ? idbGet('inicio:' + S.perfil) : Promise.resolve(null);
  cached.then(function (d) {
    if (d) S.inicio = d;
    const fast = !!(net.token && S.perfil != null && d);
    if (fast) { S.online = false; enterApp(); }
    return findServer(devServer(), fast).then(function (b) {
      if (b) { setBase(b); return afterServer(); }
      S.online = false; paintTop();
      serverGaveUp = true;
      if (!booted) enterApp(); else runPending();
      scheduleRetry();
    });
  });
}

// depuração e testes automatizados
window.__play = { snapshot: snapshot, S: S, net: net, key: function (k) { return handleKey(k); }, version: APP_VERSION };
document.getElementById('splash') && setTimeout(function () { const s = document.getElementById('splash'); if (s) s.className = 'out'; setTimeout(function () { if (s && s.parentNode) s.parentNode.removeChild(s); }, 500); }, 700);
start();
