// Minha lista: grade de pôsteres. Vermelho tira da lista.

import { h, clear, KEY } from '../util.js';
import { MatrixZone } from '../zones.js';
import { posterCard } from '../cards.js';
import { cardOk } from '../rows.js';
import { A, S } from '../state.js';

const COLS = 9;

export function myListScreen(ctx) {
  const inner = h('div.pgi');
  const grid = h('div.pgrid.full', null, inner);
  const head = h('div.gh.full');
  const el = h('div.scr.mylist', null, head, grid,
    h('div.keys', null, h('span', null, h('i', { style: { background: '#e5484d' } }), 'tirar da lista'), h('span', null, h('i', { style: { background: '#0a84ff' } }), 'filmes / séries / tudo')));
  let filtro = '';
  const zone = new MatrixZone({
    key: 'grade', el: grid, rows: [], scrollY: 0, vscroll: { box: grid, inner: inner, pad: 16 },
    onOk: function (e) { cardOk(e._c); },
    onKey: function (k, e) {
      if (k === KEY.RED && e) { A.toggleLista(e._c).then(function () { render(true); }); return true; }
      if (k === KEY.BLUE) { filtro = filtro === '' ? 'm' : filtro === 'm' ? 's' : ''; render(false); return true; }
      return false;
    }
  });
  function render(keep) {
    const all = ((S.inicio && S.inicio.minha_lista) || []).filter(function (c) { return c.minha_lista !== false; });
    const list = all.filter(function (c) { return !filtro || c.tipo === filtro; });
    clear(head);
    head.appendChild(h('b', { text: 'Minha lista' }));
    head.appendChild(h('span', { text: list.length + (list.length === 1 ? ' título' : ' títulos') + (filtro ? (filtro === 'm' ? ' · só filmes' : ' · só séries') : '') }));
    clear(inner);
    const cells = list.map(function (c) { const e = posterCard(c, true); e._c = c; inner.appendChild(e); return e; });
    if (!cells.length) inner.appendChild(h('div.empty', { text: 'Sua lista está vazia. Use o botão vermelho num título para guardar aqui.' }));
    const rows = [];
    for (let i = 0; i < cells.length; i += COLS) rows.push(cells.slice(i, i + COLS));
    zone.setRows(rows, keep);
    ctx.setZones([zone], keep);
  }
  return {
    el: el, tab: 'lista', scroller: null,
    load: function (keep) {
      render(keep);
      return A.loadInicio().then(function () { render(true); });
    }
  };
}
