// Fileiras prontas (títulos, Top 10, sagas, continuar) com as ações padrão do controle:
// OK abre o detalhe (ou liga "Avisar" no que não está na lista), Vermelho = Minha lista / Avisar.

import { h, KEY, platName, platColor, icon } from './util.js';
import { RowZone } from './zones.js';
import { posterCard, topCard, platTile, sagaCard, continueCard } from './cards.js';
import { A, S, prefOn } from './state.js';

export function cardOk(c, row, i) {
  if (!c) return;
  if (c.na_lista === false || c.id == null) { A.toggleAviso(c).then(function () { if (row) row.updateItem(i, c); }); return; }
  A.openDetail(c);
}
export function cardKey(k, c, row, i) {
  if (!c) return false;
  if (k === KEY.RED) {
    if (c.na_lista === false || c.id == null) A.toggleAviso(c).then(function () { if (row) row.updateItem(i, c); });
    else A.toggleLista(c);
    return true;
  }
  return false;
}

export function titleRow(key, title, small, items, o) {
  o = o || {};
  const row = new RowZone({
    key: key, title: title, small: small, kind: o.kind || 'po', items: items || [], left: o.left, vis: o.vis, viewW: o.viewW,
    render: function (c) { return posterCard(c, o.kind === 'ps'); },
    onOk: function (c, i) { cardOk(c, row, i); },
    onKey: function (k, c, i) { return (o.onKey && o.onKey(k, c, i, row)) || cardKey(k, c, row, i); }
  });
  return row;
}

/** Top 10 por plataforma: 5 x 10 em sequência, com abertura de cada plataforma e pílulas. */
export function top10Row(key, tipo, plats, o) {
  o = o || {};
  const hide = prefOn('esconder_fora_lista', false);
  const items = [];
  (plats || []).forEach(function (p) {
    const list = (p.itens || []).filter(function (c) { return !(hide && c.na_lista === false); });
    if (!list.length) return;
    items.push({ tile: true, codigo: p.codigo, nome: p.nome, tipo: tipo, hora: p.hora, grp: p.codigo });
    list.forEach(function (c) { c.grp = p.codigo; items.push(c); });
  });
  const pills = h('span.pj', null, (plats || []).map(function (p) {
    const e = h('span', { 'data-p': p.codigo }, h('i', { style: { background: platColor(p.codigo) } }), platName(p.codigo, p.nome));
    return e;
  }));
  const more = h('span.more', { html: '<span>CH+ CH− pulam de plataforma</span>' + icon('chev') });
  const row = new RowZone({
    key: key, kind: 'tk', items: items, title: o.title || ('Top 10 ' + (tipo === 's' ? 'séries' : 'filmes') + ' por plataforma'),
    extra: [pills, more], cls: 'tk-row', left: o.left,
    skip: function (it) { return !!it.tile; },
    group: function (it) { return it.grp; },
    render: function (it) { return it.tile ? platTile(it) : topCard(it); },
    onMove: function (it) {
      const pp = pills.children;
      for (let i = 0; i < pp.length; i++) pp[i].className = pp[i].getAttribute('data-p') === (it && it.grp) ? 'on' : '';
    },
    onOk: function (c, i) { cardOk(c, row, i); },
    onKey: function (k, c, i) { return cardKey(k, c, row, i); }
  });
  row.o.onMove(items[row.idx]);
  return row;
}

/** Top Brasil: ranking geral do JustWatch (todas as plataformas), numerado; calculado no homeserver. */
export function topBrasilRow(key, data, o) {
  o = o || {};
  const hide = prefOn('esconder_fora_lista', false);
  const items = ((data && data.itens) || []).filter(function (c) { return !(hide && c.na_lista === false); });
  const row = new RowZone({
    key: key, kind: 'tk', items: items, cls: 'tk-row', left: o.left,
    title: o.title || 'Top Brasil hoje', small: o.small === undefined ? 'JustWatch · filmes e séries de todas as plataformas' : o.small,
    render: topCard,
    onOk: function (c, i) { cardOk(c, row, i); },
    onKey: function (k, c, i) { return cardKey(k, c, row, i); }
  });
  return row;
}

export function sagaRow(key, sagas, o) {
  o = o || {};
  return new RowZone({
    key: key, title: o.title || 'Sagas para continuar', small: o.small === undefined ? 'na ordem certa, só o que está na lista' : o.small,
    kind: 'sg', items: sagas || [], left: o.left,
    render: sagaCard,
    onOk: function (s) { if (s.proximo) A.openDetail(s.proximo); else if (s.itens && s.itens[0]) A.openDetail(s.itens[0]); }
  });
}

/** Cartão final do Continuar: como tirar um título da lista (só texto; OK não faz nada). */
const AJUDA = { ajuda: true, nome: 'Como remover' };
function helpCard() {
  return h('div.card.cc.ch', { 'data-label': 'Como remover da lista' },
    h('div.img', null,
      h('p', null, h('i.dot'), 'Verde: tira daqui (volta se der play de novo)'),
      h('p', null, h('i.dot.az'), 'Azul: detalhes'),
      h('p', null, 'Filme com Gostei também sai')),
    h('div.nm', { text: 'Como remover da lista' }), h('div.sb', { text: 'Último canal: some até ver outro' }));
}

export function continueRow(key, items, o) {
  o = o || {};
  const help = o.removable !== false;
  const lista = (items || []).slice();
  if (help && lista.length) lista.push(AJUDA);
  const row = new RowZone({
    key: key, title: o.title || 'Continuar assistindo', small: o.small === undefined ? 'das duas TVs · Verde remove · Azul detalhes' : o.small,
    kind: 'cc', items: lista, left: o.left,
    render: function (c) { return c.ajuda ? helpCard() : continueCard(c); },
    onOk: function (c) {
      if (c && c.ajuda) return;
      if (o.onOk) o.onOk(c);
      else if (c && c.tipo === 'canal') A.playChannel(c, { mode: 'full' });   // último canal do Ao vivo
      else A.resume(c);
    },
    onKey: function (k, c, i) {
      if (c && c.ajuda) return k === KEY.GREEN || k === KEY.BLUE || k === KEY.RED;
      if (k === KEY.GREEN && o.removable !== false) {
        A.hideContinue(c);
        let list = row.items.slice(); list.splice(i, 1);
        if (list.length === 1 && list[0].ajuda) list = [];   // só sobrou a ajuda
        row.setItems(list, false);
        row.setIndex(Math.min(i, list.length - 1));
        if (!list.length && o.onEmpty) o.onEmpty();
        return true;
      }
      if (c && c.tipo === 'canal') return k === KEY.BLUE || k === KEY.RED;   // canal: sem detalhe nem Minha lista
      if (k === KEY.BLUE) { A.openDetail(c); return true; }
      return cardKey(k, c, row, i);
    }
  });
  return row;
}

export { S };
