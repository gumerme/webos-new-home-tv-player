// Busca: teclado próprio, resultados enquanto digita (debounce), correção ("você digitou"),
// pessoas, títulos na lista, "Ainda não estão na lista" com Avisar e canais.

import { h, clear, debounce, icon } from '../util.js';
import { RowZone } from '../zones.js';
import { titleRow } from '../rows.js';
import { personCard } from '../cards.js';
import { api, img, cssUrl } from '../net.js';
import { A, S } from '../state.js';
import { keyboard } from '../ui.js';

export function searchScreen(ctx) {
  const qtext = h('span');
  const qq = h('div.qq', null, qtext, h('i.caret'));
  const kb = keyboard({
    key: 'teclado', max: 40, value: S.ultimaBusca || '',
    onChange: function (v) { qtext.textContent = v; S.ultimaBusca = v; later(v); }
  });
  qtext.textContent = kb.get();
  kb.zone.links = { down: false, right: function () { return firstRes(); } };
  const left = h('div.kbd', null, qq, kb.el, h('div.hint', { text: 'Busca também por diretor, ator e plataforma ("netflix comédia").' }));
  const scroller = h('div.scroller');
  const res = h('div.res', null, scroller);
  const el = h('div.scr.search', null, left, res);
  let resZones = [];
  let token = 0;

  function firstRes() { return resZones[0] || null; }
  const later = debounce(run, 350);

  function run(q) {
    q = (q || '').trim();
    const my = ++token;
    if (q.length < 2) { show(null, q); return; }
    api.get('busca', { q: q }).then(function (d) { if (my === token) show(d, q); },
      function () { if (my === token) { clear(scroller); scroller.appendChild(h('div.empty', { text: 'A busca precisa do servidor, que não respondeu.' })); setZones([]); } });
  }
  function show(d, q) {
    clear(scroller);
    const zones = [];
    if (!d) {
      scroller.appendChild(h('div.fix', { text: q ? 'Continue digitando…' : 'Digite o nome de um filme, série, pessoa ou canal.' }));
      setZones(zones);
      return;
    }
    if (d.corrigido && d.corrigido.toLowerCase() !== q.toLowerCase()) {
      scroller.appendChild(h('div.fix', null, 'Resultados para ', h('b', { text: d.corrigido }), ' · você digitou ', h('u', { text: q })));
    } else scroller.appendChild(h('div.fix', null, 'Resultados para ', h('b', { text: q })));
    if (d.pessoas && d.pessoas.length) {
      const z = new RowZone({ key: 'pessoas', title: 'Pessoas', kind: 'pp', items: d.pessoas, left: 12, vis: 3, viewW: 1220,
        render: personCard, onOk: function (p) { A.openPerson(p); } });
      add(z);
    }
    if (d.titulos && d.titulos.length) add(titleRow('titulos', 'Na sua lista', null, d.titulos, { kind: 'ps', left: 12, viewW: 1220 }));
    if (d.fora && d.fora.length) add(titleRow('fora', 'Ainda não estão na lista', 'OK ou vermelho: avisar quando chegar', d.fora, { kind: 'ps', left: 12, viewW: 1220 }));
    if (d.canais && d.canais.length) {
      add(new RowZone({ key: 'canais', title: 'Canais', kind: 'pp', items: d.canais, left: 12, vis: 3, viewW: 1220,
        render: channelCard, onOk: function (c, i) { A.playChannel(c, { list: d.canais, idx: i }); } }));
    }
    if (!zones.length) scroller.appendChild(h('div.empty', { text: 'Nada encontrado para "' + q + '".' }));
    setZones(zones);
    function add(z) { scroller.appendChild(z.el); zones.push(z); z.links = { left: 'teclado' }; }
  }
  function setZones(zones) {
    resZones = zones;
    if (zones[0]) zones[0].links.up = 'top';
    ctx.setZones([kb.zone].concat(zones), true);
  }
  return {
    el: el, tab: 'busca', scroller: scroller, viewH: 930, anchor: 0,
    load: function () {
      ctx.setZones([kb.zone], false, 'teclado');
      if (kb.get()) run(kb.get()); else show(null, '');
      return Promise.resolve();
    }
  };
}

export function channelCard(c) {
  const logo = h('i.logo', { text: (c.nome || '').slice(0, 6) });
  if (c.logo) { logo.style.backgroundImage = cssUrl(img(c.logo)); logo.textContent = ''; }
  return h('div.card.pp.chn', { 'data-label': c.nome }, logo,
    h('div', null, h('b', { text: (c.numero ? c.numero + ' · ' : '') + c.nome }),
      h('span', { text: c.agora ? 'Agora: ' + c.agora.titulo : (c.grupo || '') })),
    c.favorito ? h('span.fav', { html: icon('star') }) : null);
}
