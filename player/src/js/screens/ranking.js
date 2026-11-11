// Ranking: as 5 plataformas lado a lado, 10 em cada. Verde alterna séries/filmes, vermelho
// avisa quando chegar (nos apagados), amarelo Minha lista, azul esconde o que você já viu.

import { h, icon, clear, KEY, platName, platColor, gradOf, clock } from '../util.js';
import { MatrixZone, buttonsZone } from '../zones.js';
import { mvCls, avisoLigado } from '../cards.js';
import { api, img, cssUrl } from '../net.js';
import { A, S, prefOn } from '../state.js';
import { toast } from '../ui.js';

export function rankingScreen(ctx) {
  let tipo = S.sub.ranking || 's';
  let esconderVistos = false;
  let data = null;
  const seg = h('div.seg');
  const upd = h('p');
  const head = h('div.rk-h', null, h('h4', { text: 'Top 10 de hoje' }), seg, upd);
  const rkt = h('div.rkt');                       // trilho das colunas (rola para o lado com 6+ plataformas)
  const cols = h('div.cols', null, rkt);
  const keys = h('div.keys', null,
    k('#e5484d', 'avisar quando chegar'), k('#30d158', 'séries / filmes'), k('#ffd60a', 'Minha lista'), k('#0a84ff', 'esconder o que vi'));
  const el = h('div.scr.rank', null, head, cols, keys);
  function k(c, t) { return h('span', null, h('i', { style: { background: c } }), t); }
  const zone = new MatrixZone({ key: 'ranking', el: cols, rows: [], sameCol: true, onKey: onKey, scrollY: 0, hscroll: { box: cols, inner: rkt, pad: 10 } });
  // Séries / Filmes focáveis (antes só pela tecla verde): cima da grade chega neles.
  const bS = h('span', { text: 'Séries', 'data-label': 'Séries' });
  const bM = h('span', { text: 'Filmes', 'data-label': 'Filmes' });
  function escolher(t) { if (t === tipo) return; tipo = t; S.sub.ranking = t; load(true); }
  bS._ok = function () { escolher('s'); };
  bM._ok = function () { escolher('m'); };
  seg.appendChild(bS);
  seg.appendChild(bM);
  const segZone = buttonsZone('rk-tipo', [bS, bM], { entry: 'selected', selected: function () { return tipo === 's' ? bS : bM; } });

  function paintSeg() {
    bS.classList.toggle('on', tipo === 's');
    bM.classList.toggle('on', tipo === 'm');
  }
  function line(c) {
    const off = c.na_lista === false;
    const visto = c.progresso && (c.progresso.pct >= 90 || c.progresso.terminado);
    const th = h('div.th.pst.' + gradOf(c.nome));
    if (c.poster) th.style.backgroundImage = cssUrl(img(c.poster));
    const mv = off ? h('span.mv', { html: icon('bell') + (avisoLigado(c) ? '<span>ligado</span>' : '') }) : (c.mov ? h('span.mv.' + mvCls(c.mov), { text: c.mov }) : null);
    const sub = off ? 'não está na lista' : [c.tipo === 's' ? 'Série' : 'Filme', c.ano, c.minha_lista ? 'na Minha lista' : '', visto ? 'visto' : ''].filter(Boolean).join(' · ');
    const e = h('div.ri' + (off ? '.off' : ''), { 'data-label': '#' + c.posicao + ' ' + c.nome },
      h('span.n', { text: String(c.posicao) }), th,
      h('div.t2', null, h('b', { text: c.nome }), h('span', { text: sub })), mv);
    e._c = c;
    e._ok = function () { if (off || c.id == null) A.toggleAviso(c).then(redraw); else A.openDetail(c); };
    return e;
  }
  function render(keep) {
    clear(rkt);
    paintSeg();
    upd.textContent = 'JustWatch Brasil' + (data && data.atualizado ? ' · atualizado às ' + clock(data.atualizado) : '') + ' · setas comparam com ontem' + ((data && data.plataformas || []).length > 5 ? ' · ▶ mais plataformas' : '');
    const hide = prefOn('esconder_fora_lista', false);
    const columns = [];
    cols.className = 'cols' + ((data && data.plataformas || []).length > 5 ? ' scroll' : '');
    ((data && data.plataformas) || []).forEach(function (p) {
      const items = (p.itens || []).filter(function (c) {
        if (hide && c.na_lista === false) return false;
        if (esconderVistos && c.progresso && (c.progresso.pct >= 90 || c.progresso.terminado)) return false;
        return true;
      }).slice(0, 10).map(line);
      columns.push(items);
      rkt.appendChild(h('div.col', null,
        h('div.ch', { style: { borderBottomColor: platColor(p.codigo) } }, h('b', { text: platName(p.codigo, p.nome) }), h('span', { text: tipo === 's' ? 'séries' : 'filmes' })),
        items));
    });
    const rows = [];
    for (let r = 0; r < 10; r++) {
      const row = [];
      columns.forEach(function (c) { if (c[r]) row.push(c[r]); });
      if (row.length) rows.push(row);
    }
    // navegação por coluna: cada linha guarda os itens da posição r de cada plataforma
    zone.setRows(rows, keep);
    if (!rows.length) rkt.appendChild(h('div.empty', { text: 'O ranking ainda não foi calculado no servidor.' }));
    ctx.setZones(rows.length ? [segZone, zone] : [segZone], keep);
  }
  function redraw() { render(true); }
  function load(keep) {
    return api.get('ranking', { tipo: tipo }).then(function (d) { data = d; render(keep); },
      function () { data = null; render(keep); toast('Não foi possível carregar o ranking.'); });
  }
  function onKey(k, e) {
    if (k === KEY.GREEN) { escolher(tipo === 's' ? 'm' : 's'); return true; }
    if (k === KEY.BLUE) { esconderVistos = !esconderVistos; toast(esconderVistos ? 'Escondendo o que você já viu' : 'Mostrando tudo'); render(true); return true; }
    if (!e || !e._c) return false;
    const c = e._c;
    if (k === KEY.RED) { if (c.na_lista === false || c.id == null) A.toggleAviso(c).then(redraw); else toast('Este título já está na lista.'); return true; }
    if (k === KEY.YELLOW) { if (c.id != null) A.toggleLista(c).then(redraw); return true; }
    return false;
  }
  return { el: el, tab: 'ranking', load: load, scroller: null, entry: 'ranking' };
}
