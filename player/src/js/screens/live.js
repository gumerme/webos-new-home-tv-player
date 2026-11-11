// Ao vivo: lista vertical de grupos à esquerda (abre em "Jogos de hoje" quando houver) e, à
// direita, o conteúdo do grupo: jogos, grade por horário com linha do agora, prévia do canal,
// favoritos (vermelho) e aviso (amarelo).
// Teclas: ▲▼ na lista troca o grupo (com uma pequena espera); ▶ ou OK entra no conteúdo;
// ◀ na borda esquerda do conteúdo volta à lista; ▲ no topo da lista vai à barra do topo;
// Voltar: conteúdo → lista → barra do topo.

import { h, icon, clear, KEY, clock, pad2, debounce, num } from '../util.js';
import { Zone, RowZone, MatrixZone } from '../zones.js';
import { gameCard, qual } from '../cards.js';
import { api, img, cssUrl } from '../net.js';
import { A, S } from '../state.js';
import { toast } from '../ui.js';
import { liveGet } from '../livecache.js';

const SPAN_MIN = 180;
const BODY_W = 1396;           // conteúdo: x 440 → 1836 (1920 − 84)
const EPG_X = 260;             // largura da coluna de canais + folga
const EPG_W = BODY_W - EPG_X;  // régua de horários
const PPM = EPG_W / SPAN_MIN;
const LINE_H = 80;
const GM_W = 444, GM_PITCH = 476; // 3 jogos cabem inteiros na largura do conteúdo

export function liveScreen(ctx) {
  let grupos = [];
  let grupo = S.sub.aovivo || null;
  const gInner = h('div.dri');
  const gBox = h('div.drawer.lgr', null, gInner);
  const body = h('div.lbody');
  const el = h('div.scr.live', null, gBox, body);
  let entries = [];
  let content = [];        // zonas do conteúdo do grupo aberto
  let loading = false;     // conteúdo do grupo ainda chegando
  let wantContent = false; // ▶/OK apertado enquanto carregava: entra quando chegar
  let holdList = false;    // a lista já tinha o foco: não pular para o grupo marcado
  let epg = null, token = 0;
  let jogosTimer = null;      // com "Jogos de hoje" aberto: placar e tempo renovados a cada 60 s
  function entryOf(id) { return entries.filter(function (e) { return e._g.id === id; })[0] || entries[0] || null; }
  const gz = new MatrixZone({
    key: 'grupos', el: gBox, rows: [], scrollY: 0, entry: 'selected',
    vscroll: { box: gBox, inner: gInner, pad: 90 },
    selected: function () { const f = holdList && gz.focusEl0(); return f || entryOf(grupo); },
    onFocus: function (e) { if (e._g.id !== grupo) later(e._g.id); else later.cancel(); },
    links: {
      up: 'top', down: false, left: false,
      right: function () {
        const f = gz.focusEl0();
        if (f && f._g.id !== grupo) { later.cancel(); choose(f._g.id, true); return null; }
        if (loading) { wantContent = true; return null; }
        return firstContent();
      }
    }
  });
  const later = debounce(function (id) { if (id !== grupo) choose(id, false); }, 450);
  function firstContent() { const z = content.filter(function (x) { return x.canEnter(); })[0]; return z ? z.key : null; }
  /** Troca as zonas do conteúdo sem tirar o foco de quem está na lista. */
  function setZones(zones, keep, focusKey) {
    content = zones;
    holdList = ctx.focusZoneKey() === 'grupos';
    ctx.setZones([gz].concat(zones), keep, focusKey);
    holdList = false;
  }

  function paintList() { entries.forEach(function (e) { e.classList.toggle('on', e._g.id === grupo); }); }
  function load(keep) {
    return liveGet('aovivo/grupos').then(function (g) {
      grupos = g || [];
      if (S.liveLocal) toast('Sem o homeserver: lista de canais guardada na TV' + (S.liveLocalEm ? ' (' + new Date(S.liveLocalEm).toLocaleDateString('pt-BR') + ')' : '') + '. Os canais tocam direto do painel.', 6000);
      if (!grupo || !grupos.some(function (x) { return x.id === grupo; })) {
        const j = grupos.filter(function (x) { return x.id === 'jogos'; })[0];
        const f = grupos.filter(function (x) { return x.id === 'fav' && x.total; })[0];
        grupo = (j || f || grupos[0] || {}).id || null;
      }
      clear(gInner);
      gInner.appendChild(h('div.h', { text: 'Grupos' }));
      entries = grupos.map(function (g) {
        const e = h('div.c', { 'data-label': g.nome, 'data-grupo': g.id }, h('span', { text: g.nome }), h('small', { text: g.total != null ? num(g.total) : '' }));
        e._g = g;
        e._ok = function () {
          later.cancel();
          if (g.id !== grupo) { choose(g.id, true); return; }
          if (loading) { wantContent = true; return; }
          const k = firstContent();
          if (k) ctx.focusZone(k);
        };
        gInner.appendChild(e);
        return e;
      });
      holdList = ctx.focusZoneKey() === 'grupos';
      gz.setRows(entries.map(function (e) { return [e]; }), keep);
      holdList = false;
      paintList();
      return openGroup(keep, false);
    }, function () {
      clear(body);
      body.appendChild(h('div.empty', { text: 'O Ao vivo precisa do servidor, que não respondeu.' }));
      setZones([], keep);
    });
  }
  function choose(id, focusContent) { clearInterval(jogosTimer); grupo = id; S.sub.aovivo = id; paintList(); A.stopPreview(); openGroup(false, focusContent); }

  function openGroup(keep, focusContent) {
    const my = ++token;
    loading = true;
    wantContent = !!focusContent;
    if (!keep) { clear(body); body.appendChild(h('div.loading', { text: 'Carregando…' })); setZones([], false); }
    if (grupo === 'jogos') {
      return Promise.all([liveGet('aovivo/jogos').catch(function () { return []; }), liveGet('aovivo/canais', { grupo: 'fav' }).catch(function () { return []; })]).then(function (r) {
        if (my !== token) return;
        const jogos = r[0] || [];
        let canais = r[1] || [];
        if (!canais.length) canais = uniqueChannels(jogos.map(function (j) { return j.canal; }));
        renderJogos(jogos, canais, keep, done());
      }, fail);
    }
    return liveGet('aovivo/canais', { grupo: grupo }).then(function (canais) {
      if (my !== token) return;
      renderGroup(canais || [], keep, done());
    }, fail);
    /** Fim da carga: devolve se ▶/OK foi apertado na lista enquanto carregava (entra no conteúdo). */
    function done() { const w = wantContent; loading = false; wantContent = false; return w; }
    function fail() { if (my !== token) return; done(); clear(body); body.appendChild(h('div.empty', { text: 'Não foi possível carregar este grupo.' })); setZones([], keep); }
  }
  function uniqueChannels(list) {
    const seen = {}, out = [];
    list.forEach(function (c) { if (c && !seen[c.id]) { seen[c.id] = 1; out.push(c); } });
    return out;
  }

  function renderJogos(jogos, canais, keep, focusContent) {
    clear(body);
    const zones = [];
    const row = new RowZone({
      key: 'jogos', kind: 'gm', items: jogos, noHeader: true, scrollY: 0,
      w: GM_W, pitch: GM_PITCH, vis: 3, left: 8, viewW: BODY_W + 48,
      links: { left: 'grupos', up: 'top' },
      render: gameCard,
      onOk: function (g, i) {
        if (g.status === 'agora' && g.canal) A.playChannel(g.canal, { list: uniqueChannels(jogos.filter(function (x) { return x.status === 'agora'; }).map(function (x) { return x.canal; })), mode: 'full' });
        else if (g.status === 'depois') toggleJogo(g, row, i);
        else toast('Este jogo já terminou.');
      },
      onKey: function (k, g, i) { if (k === KEY.YELLOW && g.status === 'depois') { toggleJogo(g, row, i); return true; } return false; }
    });
    if (jogos.length) { body.appendChild(h('div.jrow', null, row.el)); zones.push(row); }
    else body.appendChild(h('div.jnone', { text: 'Nenhum jogo hoje.' }));
    clearInterval(jogosTimer);
    jogosTimer = setInterval(function () {
      if (grupo !== 'jogos' || document.hidden || loading) return;
      api.get('aovivo/jogos').then(function (novos) {
        if (grupo !== 'jogos' || !novos) return;
        const ids = function (l) { return l.map(function (j) { return j.id; }).join(','); };
        // começou ou terminou um jogo (a ordem muda): redesenha mantendo o foco; senão troca só os cards que mudaram
        if (ids(novos) !== ids(row.items)) { openGroup(true, false); return; }
        novos.forEach(function (g, i) {
          const a = row.items[i];
          if (a.status !== g.status || a.minuto !== g.minuto || a.placar !== g.placar || a.aviso_id !== g.aviso_id) row.updateItem(i, g);
        });
      }, function () {});
    }, 60000);
    epg = new Epg({ key: 'grade', canais: canais, top: 280, lines: 7, title: 'Grade · ' + (canais.length ? 'Favoritos' : 'sem favoritos'), hint: 'OK assiste · amarelo avisa quando começar', preview: false });
    epg.links = { left: 'grupos', up: jogos.length ? 'jogos' : 'top' };
    body.appendChild(epg.el);
    if (canais.length) zones.push(epg);
    setZones(zones, keep, focusContent && zones[0] ? zones[0].key : null);
  }
  function toggleJogo(g, row, i) {
    if (g.aviso_id) {
      api.post('aviso/' + g.aviso_id + '/cancelar', {}).then(function () { g.aviso_id = null; row.updateItem(i, g); toast('Aviso desligado'); });
    } else {
      api.post('aviso', { tipo: 'jogo', alvo: { jogo: g.id, canal: g.canal && g.canal.id }, quando: g.inicio }).then(function (r) {
        g.aviso_id = (r && r.id) || true; row.updateItem(i, g); toast('Aviso ligado: 5 min antes do jogo.');
      }, function () { toast('Não foi possível ligar o aviso.'); });
    }
  }

  function renderGroup(canais, keep, focusContent) {
    clear(body);
    const info = h('div.linfo');
    const prev = h('div.lprev', null, h('div.ph', { html: icon('tv') + '<span>OK num canal mostra a prévia aqui</span>' }));
    body.appendChild(h('div.ltop', null, info, prev));
    epg = new Epg({ key: 'grade', canais: canais, top: 340, lines: 6, title: null, preview: true, info: info, prevBox: prev });
    epg.links = { left: 'grupos', up: 'top' };
    if (canais.length) epg.updateInfo(); // já mostra o 1º canal enquanto o foco está na lista
    body.appendChild(epg.el);
    if (!canais.length) body.appendChild(h('div.empty.low', { text: grupo === 'fav' ? 'Nenhum favorito ainda. Vermelho num canal favorita.' : 'Nenhum canal neste grupo.' }));
    setZones(canais.length ? [epg] : [], keep, focusContent && canais.length ? 'grade' : null);
  }

  // ---------- grade (zona própria) ----------
  function Epg(o) {
    // (função construtora comum herdando de Zone: classe não pode ser chamada com .call)
    this.key = o.key; this.links = {}; this.layer = null;
    const self = this;
    this.o = o;
    this.canais = o.canais;
    this.ci = 0; this.pi = -1; this.top0 = 0; this.tc = Date.now();
    this.scrollY = 0;
    const now = new Date();
    const start = new Date(now.getTime()); start.setMinutes(now.getMinutes() < 30 ? 0 : 30, 0, 0);
    this.t0 = start.getTime() - 30 * 60000;
    this.t1 = this.t0 + SPAN_MIN * 60000;
    this.progs = {}; // canal id -> [programas]
    this.lines = h('div.lns');
    const times = h('div.times');
    for (let m = 0; m <= SPAN_MIN - 30; m += 30) {
      const t = new Date(this.t0 + m * 60000);
      const lab = h('span', { style: { left: (EPG_X + m * PPM) + 'px' }, text: pad2(t.getHours()) + ':' + pad2(t.getMinutes()) });
      lab._x = EPG_X + m * PPM;
      times.appendChild(lab);
    }
    this.times = times;
    this.nowLine = h('div.now', null, h('span', { text: clock(now) }));
    const hgt = 44 + o.lines * LINE_H - 8;
    // a linha do agora desce só até a última linha de canal mostrada
    this.nowLine.style.height = (44 + Math.max(1, Math.min(o.lines, o.canais.length)) * LINE_H - 8 - 34) + 'px';
    this.el = h('div.epg', { style: { top: o.top + 'px', height: hgt + 'px' } },
      o.title ? h('div.hd', null, o.title, h('small', { text: o.hint || '' })) : null,
      times, this.lines, this.nowLine);
    this.cells = [];
    this.render();
    this.fetch();
    this.timer = setInterval(function () { self.tickNow(); }, 30000);
    this.tickNow();
    this.later = debounce(function () { self.updateInfo(); }, 150);
  }
  Epg.prototype = Object.create(Zone.prototype);
  Epg.prototype.tickNow = function () {
    const x = EPG_X + (Date.now() - this.t0) / 60000 * PPM;
    this.nowLine.style.left = x + 'px';
    this.nowLine.firstChild.textContent = clock(new Date());
    // esconde o horário da régua que encostaria no rótulo vermelho do agora
    const labs = this.times.children;
    for (let i = 0; i < labs.length; i++) labs[i].style.visibility = Math.abs(labs[i]._x - x) < 80 ? 'hidden' : '';
  };
  Epg.prototype.fetch = function () {
    const self = this;
    const want = this.canais.slice(Math.max(0, this.top0 - 2), this.top0 + this.o.lines + 8).filter(function (c) { return !self.progs[c.id]; });
    if (!want.length) return;
    want.forEach(function (c) { self.progs[c.id] = []; });
    api.get('aovivo/grade', { canais: want.map(function (c) { return c.id; }).join(','), de: new Date(this.t0).toISOString(), ate: new Date(this.t1).toISOString() }).then(function (r) {
      ((r && r.canais) || []).forEach(function (c) { self.progs[c.id] = c.programas || []; });
      self.render();
      if (self.layer) ctx.repaint();
    }, function () { /* sem grade: só os canais */ });
  };
  Epg.prototype.visibleProgs = function (c) {
    const t0 = this.t0, t1 = this.t1;
    return (this.progs[c.id] || []).filter(function (p) {
      const a = new Date(p.inicio).getTime(), b = new Date(p.fim).getTime();
      return b > t0 && a < t1;
    });
  };
  Epg.prototype.render = function () {
    clear(this.lines);
    this.cells = [];
    const end = Math.min(this.canais.length, this.top0 + this.o.lines);
    for (let i = this.top0; i < end; i++) {
      const c = this.canais[i];
      const logo = h('i', { text: (c.nome || '').slice(0, 7) });
      if (c.logo) { logo.style.backgroundImage = cssUrl(img(c.logo)); logo.textContent = ''; }
      const cell = h('div.c', { 'data-label': c.nome }, logo, h('span.cn', { text: c.numero != null ? String(c.numero) : '' }),
        h('span.cnm', { text: c.nome }), c.favorito ? h('span.fav', { html: icon('star') }) : null);
      const progEls = [];
      const line = h('div.ln', { style: { top: (44 + (i - this.top0) * LINE_H) + 'px' } }, cell);
      const vis = this.visibleProgs(c);
      const self = this;
      vis.forEach(function (p) {
        const a = Math.max(self.t0, new Date(p.inicio).getTime()), b = Math.min(self.t1, new Date(p.fim).getTime());
        const past = new Date(p.fim).getTime() < Date.now();
        const nowP = new Date(p.inicio).getTime() <= Date.now() && !past;
        const ligado = !!p.aviso_id || !!S.avisosLigados['prog:' + p.id];
        const e = h('div.pg' + (past ? '.past' : '') + (nowP ? '.cur' : ''), {
          style: { left: (EPG_X + (a - self.t0) / 60000 * PPM) + 'px', width: Math.max(8, (b - a) / 60000 * PPM - 4) + 'px' }, 'data-label': p.titulo
        }, h('b', { text: p.titulo }), h('span', { text: clock(p.inicio) + ' – ' + clock(p.fim) }),
          ligado ? h('span.bl', { html: icon('bell') }) : null);
        e._p = p;
        progEls.push(e);
        line.appendChild(e);
      });
      if (!vis.length) line.appendChild(h('div.pg.none', { style: { left: EPG_X + 'px', width: (EPG_W - 4) + 'px' } }, h('b', { text: c.agora ? c.agora.titulo : 'Sem programação' }), h('span', { text: c.agora ? 'agora' : '' })));
      this.cells.push({ c: c, cell: cell, progs: progEls });
      this.lines.appendChild(line);
    }
  };
  Epg.prototype.canEnter = function () { return this.canais.length > 0; };
  Epg.prototype.focusEl0 = function () {
    const L = this.cells[this.ci - this.top0];
    if (!L) return null;
    if (this.pi < 0 || !L.progs[this.pi]) return L.cell;
    return L.progs[this.pi];
  };
  Epg.prototype.index = function () { return this.ci; };
  Epg.prototype.setIndex = function (i) { this.ci = Math.max(0, Math.min(i, this.canais.length - 1)); this.ensure(); };
  Epg.prototype.enter = function () { this.ensure(); this.later(); return this.canais.length > 0; };
  Epg.prototype.ensure = function () {
    const n = this.o.lines;
    let t = this.top0;
    if (this.ci < t) t = this.ci;
    if (this.ci >= t + n) t = this.ci - n + 1;
    if (t !== this.top0) { this.top0 = t; this.render(); this.fetch(); }
  };
  Epg.prototype.progAt = function (L, tc) {
    if (!L.progs.length) return -1;
    for (let i = 0; i < L.progs.length; i++) {
      const p = L.progs[i]._p;
      if (new Date(p.inicio).getTime() <= tc && new Date(p.fim).getTime() > tc) return i;
    }
    return L.progs.length - 1;
  };
  Epg.prototype.move = function (dir) {
    if (dir === 'up' || dir === 'down') {
      const n = this.ci + (dir === 'up' ? -1 : 1);
      if (n < 0 || n >= this.canais.length) return false;
      const wasProg = this.pi >= 0;
      this.ci = n;
      this.ensure();
      const L = this.cells[this.ci - this.top0];
      this.pi = wasProg && L ? this.progAt(L, this.tc) : -1;
      this.later();
      return true;
    }
    const L = this.cells[this.ci - this.top0];
    if (!L) return false;
    if (dir === 'left') {
      if (this.pi < 0) return false;
      this.pi--;
    } else {
      if (this.pi + 1 >= L.progs.length) return false;
      this.pi = this.pi < 0 ? this.progAt(L, Date.now()) : this.pi + 1;
    }
    const pe = L.progs[this.pi];
    this.tc = pe ? Math.max(Date.now(), new Date(pe._p.inicio).getTime()) : Date.now();
    this.later();
    return true;
  };
  Epg.prototype.channel = function () { return this.canais[this.ci]; };
  Epg.prototype.updateInfo = function () {
    const box = this.o.info;
    if (!box) return;
    const c = this.channel();
    const L = this.cells[this.ci - this.top0];
    const p = this.pi >= 0 && L && L.progs[this.pi] ? L.progs[this.pi]._p : null;
    clear(box);
    const now = c.agora;
    box.appendChild(h('div.lch', null, (c.numero != null ? c.numero + ' · ' : '') + c.nome, c.qualidade ? h('span.q', { text: qual(c.qualidade) }) : null,
      c.favorito ? h('span.fav', { html: icon('star') + '<span>favorito</span>' }) : null));
    const show = p || (now ? { titulo: now.titulo, inicio: now.inicio, fim: now.fim, descricao: now.descricao } : null);
    if (show) {
      const isNow = new Date(show.inicio).getTime() <= Date.now() && new Date(show.fim).getTime() > Date.now();
      box.appendChild(h('div.lpt', { text: show.titulo }));
      box.appendChild(h('div.lpm', null, isNow ? h('span.lv', { text: 'AO VIVO' }) : null, clock(show.inicio) + ' – ' + clock(show.fim)));
      if (show.descricao) box.appendChild(h('div.lpd', { text: show.descricao }));
      if (isNow && now && now.pct != null) box.appendChild(h('div.bar.lbar', null, h('b', { style: { width: now.pct + '%' } })));
    } else box.appendChild(h('div.lpd', { text: 'Sem programação para este canal.' }));
    const hint = p && new Date(p.inicio).getTime() > Date.now() ? 'OK ou amarelo: avisar quando começar · vermelho favorita' : 'OK mostra a prévia · OK de novo tela cheia · vermelho favorita';
    box.appendChild(h('div.lhint', { text: hint }));
  };
  Epg.prototype.ok = function () {
    const c = this.channel();
    if (!c) return;
    const L = this.cells[this.ci - this.top0];
    const p = this.pi >= 0 && L && L.progs[this.pi] ? L.progs[this.pi]._p : null;
    if (p && new Date(p.inicio).getTime() > Date.now()) { this.toggleAviso(c, p); return; }
    if (this.o.preview) {
      if (S.playing && S.playing.live && S.playing.canal && S.playing.canal.id === c.id && S.playing.mode === 'preview') A.playChannel(c, { list: this.canais, idx: this.ci, mode: 'full', fromLive: true });
      else A.playChannel(c, { list: this.canais, idx: this.ci, mode: 'preview', box: this.o.prevBox, fromLive: true });
    } else A.playChannel(c, { list: this.canais, idx: this.ci, mode: 'full', fromLive: true });
  };
  Epg.prototype.toggleAviso = function (c, p) {
    const self = this;
    const k = 'prog:' + p.id;
    const id = p.aviso_id || S.avisosLigados[k];
    if (id) {
      api.post('aviso/' + id + '/cancelar', {}).then(function () { p.aviso_id = null; delete S.avisosLigados[k]; self.render(); ctx.repaint(); toast('Aviso desligado'); });
    } else {
      api.post('aviso', { tipo: 'programa', alvo: { canal: c.id, programa: p.id, titulo: p.titulo }, quando: p.inicio }).then(function (r) {
        p.aviso_id = (r && r.id) || true; S.avisosLigados[k] = p.aviso_id; self.render(); ctx.repaint();
        toast('Aviso ligado: ' + p.titulo + ' às ' + clock(p.inicio));
      }, function () { toast('Não foi possível ligar o aviso.'); });
    }
  };
  Epg.prototype.handle = function (k) {
    const c = this.channel();
    if (!c) return false;
    if (k === KEY.RED) { const self = this; A.toggleFav(c).then(function () { self.render(); ctx.repaint(); self.updateInfo(); }); return true; }
    if (k === KEY.YELLOW) {
      const L = this.cells[this.ci - this.top0];
      const p = this.pi >= 0 && L && L.progs[this.pi] ? L.progs[this.pi]._p : null;
      if (p && new Date(p.inicio).getTime() > Date.now()) this.toggleAviso(c, p);
      else toast('Escolha um programa que ainda vai começar.');
      return true;
    }
    if (k === KEY.CH_UP || k === KEY.CH_DOWN) {
      this.ci = Math.max(0, Math.min(this.canais.length - 1, this.ci + (k === KEY.CH_DOWN ? this.o.lines : -this.o.lines)));
      this.ensure(); this.later();
      return true;
    }
    return false;
  };
  Epg.prototype.destroy = function () { clearInterval(this.timer); this.later.cancel(); };

  return {
    el: el, tab: 'aovivo', scroller: null,
    load: load,
    /** Voltar com prévia tocando: para a prévia (o foco fica). */
    /*  No conteúdo, Voltar vai para a lista de grupos (da lista, a barra do topo cuida). */
    onBack: function () {
      if (S.playing && S.playing.live && S.playing.mode === 'preview') { A.stopPreview(); return true; }
      const k = ctx.focusZoneKey();
      if (k && k !== 'top' && k !== 'grupos' && entries.length) { ctx.focusZone('grupos'); return true; }
      return false;
    },
    onAnyKey: function () { A.holdPreview(); },
    destroy: function () { clearInterval(jogosTimer); token++; later.cancel(); if (epg) epg.destroy(); A.stopPreview(); }
  };
}
