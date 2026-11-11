// Reprodução (porte da v10): filmes/episódios pelo <video> com a URL de GET tocar, ao vivo por
// hls.js. OSD, ◀▶ 10 s, FF/RW 60 s, OK pausa, ▲ legendas (legenda, tamanho, sincronia 0,5 s),
// "Pular abertura" (servidor + aprendizado), próximo episódio, créditos, pós-filme com trailer,
// progresso (início, a cada 15 s, pausa, créditos, fim). Ao vivo: painéis de canais e guia,
// CH±/▲▼ zapeia, vermelho favorita, Voltar volta para a prévia ou para.

import { h, icon, clear, KEY, fmtTime, clock, HELPER, APP_ID, ON_TV, OLD_ENGINE, qs } from './util.js';
import { Layer, push, pop, has, paint } from './focus.js';
import { buttonsZone, RowZone } from './zones.js';
import { posterCard } from './cards.js';
import { api, img, cssUrl, srvUrl, xhr } from './net.js';
import { A, S, prefOn } from './state.js';
import { toast } from './ui.js';
import { trailerFrame } from './trailer.js';

const video = document.getElementById('video');
const ui = document.getElementById('player');
let P = null;                 // reprodução atual
let osdTimer = null, tickTimer = null, introTimer = null, expandTimer = null, nextTimer = null;
let hls = null, liveWatch = null;

// ---------- elementos ----------
const osdTitle = h('div.ot'), osdSub = h('div.os'), osdLive = h('span.lv', { text: 'AO VIVO' });
const osdBar = h('b'), osdPos = h('span'), osdDur = h('span'), osdHint = h('div.oh');
const osdBarWrap = h('div.obar', null, osdBar);
const osdVod = h('div.ovod', null, osdPos, osdBarWrap, osdDur);
const osd = h('div.osd', null,
  h('div.otop', null, h('div.otl', null, osdLive, osdTitle), osdSub),
  h('div.obot', null, osdVod, osdHint));
const subText = h('div.subtext.size-m');
const skipBtn = h('div.skip', { html: icon('skip') + '<span>Pular abertura</span>' });
const spinner = h('div.spinner');
const pauseIc = h('div.pauseic', { html: icon('pause', 'fill') });
const subsMenu = h('div.panel.subsmenu');
const livePanel = h('div.panel.lpanel');
const nextUp = h('div.nextup');
const post = h('div.postplay');
[spinner, pauseIc, subText, osd, skipBtn, subsMenu, livePanel, nextUp, post].forEach(function (e) { ui.appendChild(e); });

const layer = new Layer({ name: 'player', onKeyFirst: keyFirst, onBack: function () { closeExtras(); } });

function setUi(cls) {
  ui.className = cls;
  if (/post/.test(cls)) document.body.classList.add('post'); else document.body.classList.remove('post');
}
function showLayer() {
  if (!has(layer)) push(layer);
  document.body.classList.add('playing');
  document.body.classList.remove('pv');
  video.style.cssText = '';
  setUi('active');
}

// ---------- VOD ----------
/** o: {cartao, versao, temporada, episodio, inicio_s (null = o do servidor; 0 = do início), onClose} */
export function playVod(o) {
  stopAll(true);
  const c = o.cartao;
  P = { live: false, o: o, cartao: c, titulo: c.id, nome: c.nome, versao: o.versao || null, temporada: o.temporada || null, episodio: o.episodio || null, started: false, ended: false };
  S.playing = P;
  showLayer();
  layer.setZones([]);
  osdLive.style.display = 'none';
  osdVod.style.display = '';
  osdTitle.textContent = c.nome;
  osdSub.textContent = o.temporada ? 'T' + o.temporada + ':E' + o.episodio : '';
  osdHint.textContent = 'OK pausa     ◀ ▶ 10 s     avanço rápido 1 min     ▲ legendas     Voltar sai';
  spinner.className = 'spinner on';
  showOsd();
  const me = P;
  api.get('tocar', { titulo: c.id, versao: o.versao, temporada: o.temporada, episodio: o.episodio }, { timeout: 12000 })
    .catch(function () { return offlineVod(o); })
    .then(function (info) {
      if (P !== me) return;
      if (!info || !info.url) { toast('Não foi possível abrir este título agora.', 5000); close(); return; }
      start(info);
    });
}

/** Sem servidor: monta a URL com a cópia de GET conta (precisa de xt no cartão/episódio). */
function offlineVod(o) {
  const conta = S.conta, c = o.cartao;
  if (!conta || !conta.formato) return null;
  let xt = c.xt, fmt = conta.formato.filme;
  if (c.tipo === 's') {
    const ep = A.offlineEpisode && A.offlineEpisode(c, o.temporada, o.episodio);
    xt = ep && ep.xt; fmt = conta.formato.serie;
  }
  if (!xt || !fmt) return null;
  toast('Servidor fora: tocando direto da lista.', 4000);
  return { url: fillFmt(fmt, xt.id, xt.ext || 'mp4'), formato: xt.ext === 'mkv' ? 'mkv' : 'mp4', inicio_s: 0, abertura: null, legendas: [], proximo: null, offline: true };
}
function fillFmt(fmt, id, ext) {
  const c = S.conta;
  return fmt.replace('{s}', String(c.servidor || '').replace(/\/+$/, '')).replace('{u}', encodeURIComponent(c.usuario || ''))
    .replace('{p}', encodeURIComponent(c.senha || '')).replace('{id}', id).replace('{ext}', ext);
}

function start(info) {
  P.info = info;
  if (info.temporada) P.temporada = info.temporada;
  if (info.episodio) P.episodio = info.episodio;
  if (info.versao) P.versao = info.versao;
  P.startAt = P.o.inicio_s != null ? P.o.inicio_s : (info.inicio_s || 0);
  P.intro = info.abertura && info.abertura.fim_s > info.abertura.inicio_s ? { s: info.abertura.inicio_s, e: info.abertura.fim_s } : null;
  P.atrasos = (info.prefs && info.prefs.atrasos) || {};
  P.subsDelay = (info.prefs && info.prefs.atraso_s) || 0;
  if (info.nome && !P.nome) { P.nome = info.nome; osdTitle.textContent = info.nome; }
  P.subs = null; P.subsList = info.legendas || [];
  applySubsSize((info.prefs && info.prefs.tamanho) || S.prefs.legenda_tamanho || 'm');
  osdSub.textContent = P.temporada ? 'T' + P.temporada + ':E' + P.episodio + (info.nome_episodio ? ' · ' + info.nome_episodio : '') : '';
  if (info.formato === 'hls') attachHls(info.url, false);
  else { dropHls(); video.src = info.url; playSafe(); }
  if (P.subsList.length && (info.legenda_auto || prefOn('legenda_auto', false))) loadSub(P, P.subsList[0], true);
  if (P.cartao.tipo === 's' || P.temporada) followSeries(info);
  clearInterval(tickTimer);
  tickTimer = setInterval(function () { if (P && !P.live && !video.paused) progress('tick'); }, 15000);
}
function playSafe() { const p = video.play(); if (p && p.catch) p.catch(function () {}); }

/** A Home antiga ainda lê "Minhas Séries" do helper da TV. Erros são ignorados. */
function followSeries(info) {
  const tmdb = info.tmdb || P.cartao.tmdb, xs = info.xs || P.cartao.xs;
  if (!tmdb || !ON_TV) return;
  xhr('GET', HELPER + '/series/set?' + qs({ tmdb: tmdb, xs: xs || '', app: APP_ID, title: P.nome, season: P.temporada, episode: P.episodio }), null, 3000).catch(function () {});
}

function progress(evento) {
  if (!P || P.live || !P.info || P.info.offline) return;
  const d = video.duration;
  if (!d || !isFinite(d)) return;
  api.fire('progresso', { titulo: P.titulo, versao: P.versao, temporada: P.temporada, episodio: P.episodio,
    posicao_s: Math.floor(evento === 'fim' ? d : video.currentTime), duracao_s: Math.floor(d), evento: evento });
  S.stale = true;
}

video.addEventListener('loadedmetadata', function () {
  if (!P || P.live) return;
  if (P.startAt > 30 && P.startAt < video.duration - 60) video.currentTime = P.startAt;
});
video.addEventListener('playing', function () {
  spinner.className = 'spinner';
  if (P && !P.live && !P.started) { P.started = true; progress('inicio'); }
  pauseIc.className = 'pauseic';
});
video.addEventListener('waiting', function () { spinner.className = 'spinner on'; });
video.addEventListener('pause', function () {
  if (P && !P.live && P.started && !P.ended && !P.closing) { progress('pausa'); pauseIc.className = 'pauseic on'; showOsd(); }
});
video.addEventListener('timeupdate', function () {
  if (!P || P.live) return;
  updateOsd(); drawSubs(); updateSkip(); checkCredits();
});
video.addEventListener('ended', function () {
  if (!P || P.live) return;
  P.ended = true;
  progress('fim');
  if (P.nextShown || (P.info && P.info.proximo)) { playNext(); return; }
  if (P.post === 'shown') return;
  if (isMovie() && !P.postDismissed) { showPostplay(); return; }
  close();
});
video.addEventListener('error', function () {
  if (!P) return;
  spinner.className = 'spinner';
  if (P.live && hls) return; // hls.js tenta de novo sozinho
  toast(P.live ? 'Este canal não está disponível agora.' : 'Não foi possível reproduzir este título.', 5000);
  if (!P.live) close(); else if (P.mode === 'full') closeLive();
});

// filmes de verdade (não curtas); no modo dev (navegador) o vídeo de teste tem 3 min
const MIN_MOVIE = /^https?:/.test(location.protocol) ? 60 : 40 * 60;
function isMovie() { return P && !P.live && P.cartao.tipo !== 's' && !P.temporada && video.duration >= MIN_MOVIE; }

// ---------- OSD ----------
function updateOsd() {
  const d = video.duration || 0, t = video.currentTime || 0;
  osdBar.style.width = d ? (t / d * 100) + '%' : '0';
  osdPos.textContent = fmtTime(t);
  osdDur.textContent = d && isFinite(d) ? '-' + fmtTime(Math.ceil(d - t)) : '';
}
function showOsd() {
  osd.className = 'osd';
  clearTimeout(osdTimer);
  osdTimer = setTimeout(function () { if (!video.paused || (P && P.live)) osd.className = 'osd hide'; }, 4000);
}
function osdOn() { return osd.className.indexOf('hide') === -1; }
function seek(delta) {
  if (!video.duration || !isFinite(video.duration)) return;
  const from = video.currentTime, to = Math.max(0, Math.min(video.duration - 5, from + delta));
  video.currentTime = to;
  noteSeek(from, to);
  updateOsd(); showOsd();
}
function togglePause() { if (video.paused) playSafe(); else video.pause(); showOsd(); }

// ---------- créditos, próximo episódio, pós-filme ----------
function creditsAt() {
  const d = video.duration;
  if (!d || !isFinite(d)) return Infinity;
  // contrato: creditos_s = duração dos créditos contada do fim (posição >= duração - creditos_s)
  if (P.info && P.info.creditos_s) return d - P.info.creditos_s;
  if (P.info && P.info.proximo) return d - 40;
  if (isMovie()) return d - Math.min(240, d * 0.04);
  return Infinity;
}
function checkCredits() {
  if (P.creditsSeen || P.ended) return;
  if (video.currentTime < creditsAt()) return;
  P.creditsSeen = true;
  progress('creditos');
  if (P.info && P.info.proximo) showNextUp();
  else if (isMovie() && !P.postDismissed) showPostplay();
}
function showNextUp() {
  const nx = P.info.proximo;
  P.nextShown = true;
  let left = 10;
  const label = h('span');
  const go = h('span.btn.pri', { html: icon('play', 'fill') + '<span>Próximo episódio</span>' });
  const stay = h('span.btn', { text: 'Ver os créditos' });
  go._ok = playNext;
  stay._ok = function () { clearInterval(nextTimer); P.nextShown = false; nextUp.className = 'nextup'; layer.setZones([]); };
  clear(nextUp);
  nextUp.appendChild(h('div.nb', null, h('small', { text: 'A seguir' }), h('b', { text: 'T' + nx.temporada + ':E' + nx.episodio + (nx.nome ? ' · ' + nx.nome : '') }), label));
  nextUp.appendChild(h('div.btns', null, go, stay));
  nextUp.className = 'nextup on';
  layer.setZones([buttonsZone('proximo', [go, stay])]);
  paint();
  function tick() { label.textContent = 'começa em ' + left + ' s'; if (left-- <= 0) playNext(); }
  clearInterval(nextTimer);
  tick();
  nextTimer = setInterval(tick, 1000);
}
function playNext() {
  clearInterval(nextTimer);
  const nx = P && P.info && P.info.proximo;
  if (!nx) { close(); return; }
  const o = P.o;
  toast('Próximo: T' + nx.temporada + ':E' + nx.episodio);
  playVod({ cartao: P.cartao, versao: P.versao, temporada: nx.temporada, episodio: nx.episodio, inicio_s: 0, onClose: o.onClose });
}

function showPostplay() {
  if (!P || P.post) return;
  P.post = 'loading';
  const me = P;
  api.get('titulo/' + P.titulo + '/apos').then(function (r) {
    if (P !== me || !r || !r.sugestao) { if (P === me) P.post = 'none'; return; }
    P.post = 'shown';
    P.picks = [r.sugestao].concat(r.outras || []);
    video.muted = true;
    setUi('active post');
    feature(r.sugestao, r.outras || []);
  }, function () { if (P === me) P.post = 'none'; });
}
function feature(pick, outras) {
  clear(post);
  const media = h('div.ppmedia.' + 'g9');
  if (pick.fundo) media.style.backgroundImage = cssUrl(img(pick.fundo));
  // pós-filme: o trailer não gira; no 'fim' (ou 'erro') do helper ele só fica parado na tela
  if (pick.trailer_youtube && ON_TV && !OLD_ENGINE) media.appendChild(trailerFrame(pick.trailer_youtube, pick.trailer_legenda, null));
  const watch = h('span.btn.pri', { html: icon('play', 'fill') + '<span>Assistir</span>' });
  const info = h('span.btn', { html: icon('info') + '<span>Mais informações</span>' });
  const credits = h('span.btn', { text: 'Ver os créditos' });
  watch._ok = function () { const o = P.o; finishMovie(); playVod({ cartao: pick, onClose: o.onClose }); };
  info._ok = function () { finishMovie(); close(); A.openDetail(pick); };
  credits._ok = hidePostplay;
  const zones = [buttonsZone('acoes', [watch, info, credits])];
  post.appendChild(media);
  post.appendChild(h('div.ppshade'));
  post.appendChild(h('div.pptx', null,
    h('div.kick', null, h('span.lbl', { text: 'Porque você assistiu' }), h('span', { text: P.nome })),
    h('div.big' + (pick.nome.length > 22 ? '.long' : ''), { text: pick.nome }),
    h('div.meta', null, h('span', { text: [pick.ano, pick.imdb ? 'IMDb ' + String(pick.imdb).replace('.', ',') : ''].filter(Boolean).join(' · ') })),
    pick.sinopse ? h('div.syn', { text: pick.sinopse }) : null,
    h('div.btns', null, watch, info, credits)));
  if (outras.length) {
    const row = new RowZone({ key: 'outras', title: 'Mais títulos parecidos', kind: 'ps', items: outras, left: 84,
      render: function (c) { return posterCard(c, true); },
      onOk: function (c) { const rest = P.picks.filter(function (x) { return x !== c; }); feature(c, rest.slice(0, 6)); } });
    post.appendChild(h('div.ppmore', null, row.el));
    zones.push(row);
  }
  layer.setZones(zones);
  paint();
}
function finishMovie() { if (P && !P.ended) { P.ended = true; progress('fim'); } }
function hidePostplay() {
  if (!P) return;
  P.post = 'dismissed'; P.postDismissed = true;
  clear(post);
  video.muted = false;
  setUi('active');
  layer.setZones([]);
  showOsd();
}

// ---------- legendas ----------
export function parseSubs(text) {
  const cues = [];
  String(text).replace(/\r/g, '').split(/\n\s*\n/).forEach(function (block) {
    const m = /(\d+):(\d+):(\d+)[,.](\d+)\s*-->\s*(\d+):(\d+):(\d+)[,.](\d+)/.exec(block);
    if (!m) return;
    const t = function (hh, mi, se, ms) { return +hh * 3600 + +mi * 60 + +se + +ms / 1000; };
    const lines = block.split('\n');
    let at = 0;
    while (at < lines.length && lines[at].indexOf('-->') === -1) at++;
    const body = lines.slice(at + 1).join('\n').replace(/\{[^}]*\}/g, '').replace(/<[^>]+>/g, '').replace(/^\s+|\s+$/g, '');
    if (body) cues.push({ a: t(m[1], m[2], m[3], m[4]), b: t(m[5], m[6], m[7], m[8]), text: body });
  });
  return cues.sort(function (x, y) { return x.a - y.a; });
}
let subsShown = '';
function drawSubs() {
  const cues = P && P.subs;
  let text = '';
  if (cues) {
    const t = video.currentTime - (P.subsDelay || 0);
    for (let i = 0; i < cues.length; i++) {
      if (cues[i].a > t) break;
      if (cues[i].b >= t) text = text ? text + '\n' + cues[i].text : cues[i].text;
    }
  }
  if (!text && P && P.menu && P.menuSample) text = 'Assim fica a legenda neste tamanho';
  if (text === subsShown) return;
  subsShown = text;
  clear(subText);
  if (text) subText.appendChild(h('span', { text: text }));
}
function loadSub(p, it, auto) {
  const url = it.url ? srvUrl(it.url) : srvUrl('/api/play/legenda/' + it.id);
  return xhr('GET', url, null, 12000, 'text').then(function (text) {
    if (P !== p) return;
    p.subs = parseSubs(text);
    p.subId = it.id;
    p.subName = it.nome || it.idioma;
    p.subsDelay = (p.atrasos && p.atrasos[it.id]) || 0;
    if (!auto) {
      api.fire('preferencia', { chave: 'legenda_auto', valor: 1 });
      S.prefs.legenda_auto = 1;
    }
    drawSubs();
    if (!auto) toast('Legenda: ' + (it.nome || it.idioma));
  }, function () { if (!auto) toast('Não foi possível baixar esta legenda. Tente outra.', 5000); });
}
const TAM = { p: 'Pequena', m: 'Média', g: 'Grande' }, TAM_ORD = ['p', 'm', 'g'];
function applySubsSize(t) { P && (P.subSize = TAM[t] ? t : 'm'); subText.className = 'subtext size-' + (P ? P.subSize : 'm'); }
function delayLabel(d) {
  if (!d) return 'Original';
  return (d > 0 ? 'Atrasada ' : 'Adiantada ') + String(Math.abs(d).toFixed(1)).replace('.', ',') + ' s';
}
function openSubsMenu() {
  if (!P || P.live) return;
  P.menu = { sel: 0, buscando: false, nota: '' };
  renderSubsMenu();
}
function menuRows() {
  const rows = [{ off: true }].concat(P.subsList.slice(0, 8).map(function (s) { return { sub: s }; }));
  rows.push({ buscar: true });
  rows.push({ set: 'size' });
  if (P.subs) rows.push({ set: 'sync' });
  return rows;
}
function renderSubsMenu() {
  clear(subsMenu);
  if (!P || !P.menu) { subsMenu.className = 'panel subsmenu'; P && (P.menuSample = false); drawSubs(); return; }
  subsMenu.className = 'panel subsmenu on';
  subsMenu.appendChild(h('div.pt', { text: 'Legendas' }));
  const rows = menuRows();
  P.menu.sel = Math.min(P.menu.sel, rows.length - 1);
  rows.forEach(function (it, i) {
    const sel = i === P.menu.sel ? '.sel' : '';
    if (it.off) subsMenu.appendChild(h('div.pi' + sel + (!P.subs ? '.on' : ''), { text: 'Desligadas' }));
    else if (it.sub) subsMenu.appendChild(h('div.pi' + sel + (P.subId === it.sub.id ? '.on' : ''), null, h('span', { text: it.sub.nome || it.sub.idioma }), h('small', { text: it.sub.fonte || it.sub.idioma || '' })));
    else if (it.buscar) subsMenu.appendChild(h('div.pi' + sel, { text: P.menu.buscando ? 'Buscando legendas…' : 'Buscar mais legendas' }));
    else subsMenu.appendChild(h('div.pi.set' + sel, null, h('span', { text: it.set === 'size' ? 'Tamanho da letra' : 'Sincronia' }),
      h('span.pv', { text: '◀  ' + (it.set === 'size' ? TAM[P.subSize || 'm'] : delayLabel(P.subsDelay || 0)) + '  ▶' })));
  });
  if (P.menu.nota) subsMenu.appendChild(h('div.pn', { text: P.menu.nota }));
  if (P.subs) subsMenu.appendChild(h('div.pn', { text: 'Ajuste a sincronia se a legenda vier antes ou depois da fala: fica guardada para este título.' }));
  subsMenu.appendChild(h('div.ph2', { text: '▲ ▼ escolhe   OK aplica   ◀ ▶ ajusta   Voltar fecha' }));
  P.menuSample = rows[P.menu.sel].set === 'size';
  drawSubs();
}
function subsMenuKey(k) {
  const m = P.menu, rows = menuRows(), n = rows.length, row = rows[m.sel];
  if (k === KEY.BACK || k === KEY.ESC || k === KEY.BACKSPACE) { P.menu = null; renderSubsMenu(); return; }
  if (k === KEY.LEFT || k === KEY.RIGHT) {
    const dir = k === KEY.LEFT ? -1 : 1;
    if (row && row.set === 'size') {
      const i = Math.max(0, Math.min(2, TAM_ORD.indexOf(P.subSize || 'm') + dir));
      applySubsSize(TAM_ORD[i]);
      S.prefs.legenda_tamanho = TAM_ORD[i];
      api.fire('preferencia', { chave: 'legenda_tamanho', valor: TAM_ORD[i] });
    } else if (P.subs) {
      P.subsDelay = Math.round(((P.subsDelay || 0) + dir * 0.5) * 10) / 10;
      api.fire('preferencia', { chave: 'legenda_atraso:' + P.titulo + ':' + P.subId, valor: P.subsDelay });
      if (P.atrasos) P.atrasos[P.subId] = P.subsDelay;
    }
    renderSubsMenu();
    return;
  }
  if (k === KEY.UP) m.sel = (m.sel - 1 + n) % n;
  else if (k === KEY.DOWN) m.sel = (m.sel + 1) % n;
  else if (k === KEY.ENTER && row) {
    if (row.set === 'size') { subsMenuKey(KEY.RIGHT); return; }
    if (row.set) return;
    if (row.buscar) {
      m.buscando = true; renderSubsMenu();
      const me = P;
      api.get('legendas', { titulo: P.titulo, temporada: P.temporada, episodio: P.episodio }, { timeout: 20000 }).then(function (list) {
        if (P !== me || !P.menu) return;
        const seen = {};
        P.subsList.forEach(function (s) { seen[s.id] = 1; });
        (list || []).forEach(function (s) { if (!seen[s.id]) P.subsList.push(s); });
        m.buscando = false;
        m.nota = (list && list.length) ? '' : 'Nenhuma legenda em português encontrada.';
        renderSubsMenu();
      }, function () { if (P === me && P.menu) { m.buscando = false; m.nota = 'Não foi possível buscar legendas agora.'; renderSubsMenu(); } });
      return;
    }
    P.menu = null; renderSubsMenu();
    if (row.off) {
      P.subs = null; P.subId = null; drawSubs();
      api.fire('preferencia', { chave: 'legenda_auto', valor: 0 }); S.prefs.legenda_auto = 0;
      toast('Legendas desligadas');
      return;
    }
    toast('Carregando legenda…');
    loadSub(P, row.sub, false);
    return;
  }
  renderSubsMenu();
}

// ---------- pular abertura (dados do servidor + aprendizado) ----------
const INTRO_SEARCH = 420;
function noteSeek(from, to) {
  if (!P || P.live || !P.temporada) return;
  const l = P.introLearn;
  if (l && Date.now() - l.at < 6000) { l.to = to; l.at = Date.now(); }
  else if (to > from && from < INTRO_SEARCH) P.introLearn = { from: from, to: to, at: Date.now() };
  else return;
  clearTimeout(introTimer);
  introTimer = setTimeout(learnIntro, 6000);
}
function learnIntro() {
  const l = P && P.introLearn;
  if (!l) return;
  P.introLearn = null;
  const len = l.to - l.from;
  if (len < 20 || len > 240 || l.to > INTRO_SEARCH + 240) return; // não é abertura
  P.intro = { s: Math.floor(l.from), e: Math.floor(l.to) };
  api.fire('abertura', { titulo: P.titulo, inicio_s: P.intro.s, fim_s: P.intro.e });
}
function skipShown() { return skipBtn.className.indexOf('on') !== -1; }
function updateSkip() {
  let show = false;
  if (P && P.intro && !P.post && !P.menu && !P.nextShown) {
    const t = video.currentTime;
    show = t >= Math.max(0, P.intro.s - 5) && t < P.intro.e - 3;
  }
  if (show !== skipShown()) skipBtn.className = 'skip' + (show ? ' on' : '');
}
function skipIntro() {
  const it = P && P.intro;
  if (!it) return;
  video.currentTime = it.e;
  P.introLearn = { from: it.s, to: it.e, at: Date.now() }; // ◀ ▶ logo depois corrige
  clearTimeout(introTimer);
  introTimer = setTimeout(learnIntro, 6000);
  skipBtn.className = 'skip';
  updateOsd();
}

// ---------- ao vivo ----------
function loadHlsLib() {
  if (window.Hls) return Promise.resolve();
  return new Promise(function (resolve) {
    const s = document.createElement('script');
    s.src = 'hls.min.js';
    s.onload = s.onerror = function () { resolve(); };
    document.head.appendChild(s);
  });
}
function dropHls() {
  clearInterval(liveWatch);
  if (hls) { try { hls.destroy(); } catch (e) { /* já destruído */ } hls = null; }
}
// Ao vivo pelo hls.js (como na v10): ~15 s atrás do vivo, até 40 s de buffer, segmentos que
// falham são tentados de novo e 12 s seguidos travado reconectam o canal.
function attachHls(url, live) {
  dropHls();
  if (!(window.Hls && window.Hls.isSupported())) { video.src = url; playSafe(); return; }
  const Hls = window.Hls;
  const mine = new Hls(live ? {
    lowLatencyMode: false, liveSyncDurationCount: 6, liveMaxLatencyDurationCount: 14,
    maxBufferLength: 40, maxMaxBufferLength: 60, backBufferLength: 8, maxBufferHole: 1,
    manifestLoadingMaxRetry: 6, levelLoadingMaxRetry: 6, fragLoadingMaxRetry: 8, fragLoadingRetryDelay: 600,
    startFragPrefetch: true, enableWorker: !OLD_ENGINE
  } : { maxBufferLength: 30, backBufferLength: 10, enableWorker: !OLD_ENGINE });
  hls = mine;
  let recoveries = 0;
  mine.on(Hls.Events.ERROR, function (ev, data) {
    if (!data.fatal || hls !== mine) return;
    if (data.type === Hls.ErrorTypes.NETWORK_ERROR && recoveries++ < 6) setTimeout(function () { if (hls === mine) mine.startLoad(); }, 1000);
    else if (data.type === Hls.ErrorTypes.MEDIA_ERROR && recoveries++ < 6) mine.recoverMediaError();
    else if (live) reconnectLive();
  });
  mine.on(Hls.Events.MANIFEST_PARSED, playSafe);
  mine.loadSource(url);
  mine.attachMedia(video);
  if (!live) return;
  let since = 0;
  liveWatch = setInterval(function () {
    if (hls !== mine) return;
    if (video.readyState < 3 && !video.paused) {
      since = since || Date.now();
      if (Date.now() - since > 12000) { since = 0; reconnectLive(); }
    } else since = 0;
  }, 2000);
}
function reconnectLive() { if (P && P.live && P.url) attachHls(P.url, true); }

/** o: {list, idx, mode:'preview'|'full', box (elemento da prévia), fromLive} */
export function playLive(canal, o) {
  o = o || {};
  const list = o.list && o.list.length ? o.list : [canal];
  let idx = o.idx != null ? o.idx : list.indexOf(canal);
  if (idx < 0) idx = 0;
  const same = P && P.live && P.canal && P.canal.id === canal.id;
  if (!same) stopAll(true);
  const prevPanel = same ? P.panel : null;
  P = same ? P : { live: true };
  P.canal = canal; P.list = list; P.idx = idx; P.mode = o.mode || 'full'; P.box = o.box || P.box || null; P.fromLive = !!o.fromLive;
  P.panel = P.mode === 'full' ? prevPanel : null;
  S.playing = P;
  clearTimeout(expandTimer);
  if (P.mode === 'full') {
    showLayer();
    layer.setZones([]);
    osdLive.style.display = '';
    osdVod.style.display = 'none';
    osdHint.textContent = 'OK canais     ▶ programação     ▲ ▼ ou CH troca de canal     Vermelho favorita     Voltar sai';
    paintLiveOsd();
    livePanels();
    showOsd();
  } else placePreview();
  if (same) return;
  spinner.className = 'spinner on';
  const me = P;
  loadHlsLib().then(function () {
    return api.get('aovivo/tocar/' + canal.id).catch(function () {
      const c = S.conta;
      if (c && c.formato && c.formato.canal && canal.xt) return { url: fillFmt(c.formato.canal, canal.xt.id || canal.xt, 'm3u8'), formato: 'hls' };
      return null;
    });
  }).then(function (r) {
    if (P !== me) return;
    if (!r || !r.url) { toast('Este canal não está disponível agora.'); if (P.mode === 'full') closeLive(); else stopPreview(); return; }
    P.url = r.url;
    if (r.canal && !P.canal.nome) { P.canal = r.canal; if (P.mode === 'full') paintLiveOsd(); }
    attachHls(r.url, true);
  });
  loadGuide(P);
  if (P.mode === 'preview') holdPreview();
}
function placePreview() {
  const box = P.box;
  if (!box || !document.body.contains(box)) { stopPreview(); return; }
  const r = box.getBoundingClientRect();
  document.body.classList.add('pv');
  document.body.classList.remove('playing');
  video.style.left = r.left + 'px'; video.style.top = r.top + 'px';
  video.style.width = r.width + 'px'; video.style.height = r.height + 'px';
  box.classList.add('playing');
  if (has(layer)) pop(layer);
  setUi('pvmode');
}
export function holdPreview() {
  clearTimeout(expandTimer);
  if (!P || !P.live || P.mode !== 'preview') return;
  const me = P;
  expandTimer = setTimeout(function () { if (P === me && P.mode === 'preview') playLive(P.canal, { list: P.list, idx: P.idx, mode: 'full', fromLive: true }); }, 6000);
}
export function stopPreview() {
  if (!P || !P.live || P.mode !== 'preview') return;
  if (P.box) P.box.classList.remove('playing');
  stopAll(false);
}
function loadGuide(p) {
  const now = new Date();
  api.get('aovivo/grade', { canais: p.canal.id, de: new Date(now.getTime() - 3600000).toISOString(), ate: new Date(now.getTime() + 6 * 3600000).toISOString() }).then(function (r) {
    if (P !== p) return;
    const c = r && r.canais && r.canais[0];
    p.guide = ((c && c.programas) || []).filter(function (x) { return new Date(x.fim).getTime() > Date.now(); });
    paintLiveOsd();
    if (p.panel === 'guide') livePanels();
  }, function () { p.guide = []; });
}
function paintLiveOsd() {
  const c = P.canal;
  osdTitle.textContent = (c.numero != null ? c.numero + ' · ' : '') + c.nome;
  const g = P.guide || [];
  const now = g[0] || (c.agora ? { titulo: c.agora.titulo, inicio: c.agora.inicio } : null);
  osdSub.textContent = now ? 'Agora: ' + now.titulo + (g[1] ? '   ' + clock(g[1].inicio) + ': ' + g[1].titulo : '') : '';
}
function zap(step) {
  const n = P.list.length;
  if (n < 2) return;
  const i = (P.idx + step + n) % n;
  const panel = P.panel;
  playLive(P.list[i], { list: P.list, idx: i, mode: 'full', fromLive: P.fromLive });
  P.panel = panel; P.sel = i;
  livePanels();
}
function livePanels() {
  clear(livePanel);
  if (!P.panel) { livePanel.className = 'panel lpanel'; return; }
  livePanel.className = 'panel lpanel on' + (P.panel === 'guide' ? ' right' : '');
  if (P.panel === 'list') {
    livePanel.appendChild(h('div.pt', { text: 'Canais' }));
    const from = Math.max(0, Math.min(P.sel - 5, P.list.length - 11));
    P.list.slice(from, from + 11).forEach(function (c, i) {
      const at = from + i;
      livePanel.appendChild(h('div.pi' + (at === P.sel ? '.sel' : '') + (at === P.idx ? '.on' : ''), null,
        h('span', { text: (c.numero != null ? c.numero + ' · ' : '') + c.nome }),
        h('small', { text: c.agora ? 'Agora: ' + c.agora.titulo : '' }), c.favorito ? h('span.fav', { html: icon('star') }) : null));
    });
    livePanel.appendChild(h('div.ph2', { text: '▲ ▼ escolhe   OK assiste   Vermelho favorita   Voltar fecha' }));
  } else {
    livePanel.appendChild(h('div.pt', { text: 'Programação de ' + P.canal.nome }));
    const g = (P.guide || []).slice(0, 8);
    if (!g.length) livePanel.appendChild(h('div.pn', { text: 'Sem programação para este canal.' }));
    g.forEach(function (x, i) { livePanel.appendChild(h('div.pi' + (i === 0 ? '.on' : ''), null, h('span', { text: x.titulo }), h('small', { text: clock(x.inicio) + ' – ' + clock(x.fim) }))); });
    livePanel.appendChild(h('div.ph2', { text: '▲ ▼ troca de canal   ◀ ou Voltar fecha' }));
  }
}
function liveKey(k) {
  if (P.panel === 'list') {
    const n = P.list.length;
    if (k === KEY.UP || k === KEY.DOWN) { P.sel = (P.sel + (k === KEY.UP ? -1 : 1) + n) % n; livePanels(); }
    else if (k === KEY.ENTER) { if (P.sel !== P.idx) zap(P.sel - P.idx); else { P.panel = null; livePanels(); } }
    else if (k === KEY.RED) { A.toggleFav(P.list[P.sel]).then(livePanels); }
    else if (k === KEY.BACK || k === KEY.ESC || k === KEY.BACKSPACE || k === KEY.RIGHT || k === KEY.LEFT) { P.panel = null; livePanels(); showOsd(); }
    return;
  }
  if (P.panel === 'guide') {
    if (k === KEY.BACK || k === KEY.ESC || k === KEY.BACKSPACE || k === KEY.LEFT) { P.panel = null; livePanels(); showOsd(); }
    else if (k === KEY.UP || k === KEY.CH_UP) zap(-1);
    else if (k === KEY.DOWN || k === KEY.CH_DOWN) zap(1);
    return;
  }
  if (k === KEY.BACK || k === KEY.ESC || k === KEY.BACKSPACE || k === KEY.STOP) closeLive();
  else if (k === KEY.UP || k === KEY.CH_UP) zap(-1);
  else if (k === KEY.DOWN || k === KEY.CH_DOWN) zap(1);
  else if (k === KEY.ENTER || k === KEY.LEFT) { P.panel = 'list'; P.sel = P.idx; livePanels(); }
  else if (k === KEY.RIGHT) { P.panel = 'guide'; livePanels(); }
  else if (k === KEY.RED) { A.toggleFav(P.canal).then(function () { showOsd(); toast(P.canal.favorito ? 'Favoritado' : 'Tirado dos favoritos'); }); }
  else showOsd();
}
/** Voltar no ao vivo em tela cheia: volta para a prévia do Ao vivo, ou para. */
function closeLive() {
  if (P && P.fromLive && P.box && document.body.contains(P.box)) {
    P.mode = 'preview'; P.panel = null; livePanels();
    placePreview();
    if (A.afterPlayer) A.afterPlayer(true);
    return;
  }
  close();
}

// ---------- teclas ----------
function keyFirst(k) {
  if (!P) return false;
  if (P.live) { liveKey(k); return true; }
  if (P.post === 'shown' || P.nextShown) {
    if (k === KEY.BACK || k === KEY.ESC || k === KEY.BACKSPACE) { if (P.post === 'shown') { finishMovie(); close(); } else { clearInterval(nextTimer); P.nextShown = false; nextUp.className = 'nextup'; layer.setZones([]); } return true; }
    return false; // setas/OK nos botões
  }
  if (P.menu) { subsMenuKey(k); return true; }
  if (k === KEY.ENTER && skipShown()) { skipIntro(); return true; }
  const on = osdOn();
  if (k === KEY.BACK || k === KEY.ESC || k === KEY.BACKSPACE || k === KEY.STOP) close();
  else if (k === KEY.ENTER) { if (on) togglePause(); else showOsd(); }
  else if (k === KEY.PLAYPAUSE) togglePause();
  else if (k === KEY.PLAY) { playSafe(); showOsd(); }
  else if (k === KEY.PAUSE) { video.pause(); showOsd(); }
  else if (k === KEY.LEFT) { if (on) seek(-10); else showOsd(); }
  else if (k === KEY.RIGHT) { if (on) seek(10); else showOsd(); }
  else if (k === KEY.FF) seek(60);
  else if (k === KEY.RW) seek(-60);
  else if (k === KEY.UP) openSubsMenu();
  else showOsd();
  return true;
}
function closeExtras() { if (P && !P.live) close(); }

// ---------- parar ----------
function stopAll(keepUi) {
  clearInterval(tickTimer); clearInterval(nextTimer); clearTimeout(introTimer); clearTimeout(expandTimer);
  if (P && !P.live && P.introLearn) learnIntro();
  if (P && !P.live && P.started && !P.ended) { P.closing = true; progress('pausa'); }
  if (P && P.box) P.box.classList.remove('playing');
  dropHls();
  try { video.pause(); } catch (e) { /* sem mídia */ }
  video.removeAttribute('src');
  try { video.load(); } catch (e) { /* idem */ }
  video.muted = false;
  P = null; S.playing = null;
  subsShown = ''; clear(subText); clear(post); clear(nextUp); nextUp.className = 'nextup';
  skipBtn.className = 'skip'; subsMenu.className = 'panel subsmenu'; clear(subsMenu);
  livePanel.className = 'panel lpanel'; clear(livePanel);
  spinner.className = 'spinner'; pauseIc.className = 'pauseic';
  document.body.classList.remove('pv');
  if (!keepUi) { document.body.classList.remove('playing'); setUi(''); video.style.cssText = ''; if (has(layer)) pop(layer); }
}
export function close() {
  const o = P && P.o;
  const wasLive = P && P.live;
  stopAll(false);
  if (A.afterPlayer) A.afterPlayer(wasLive);
  if (o && o.onClose) o.onClose();
}
export function isPlaying() { return !!P; }
export function current() { return P; }
/** App escondido (tecla Home): guarda a posição e libera o vídeo. */
export function suspend() { if (!P) return; if (P.live && P.mode === 'preview') stopPreview(); else close(); }
