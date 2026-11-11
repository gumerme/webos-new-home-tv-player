// Séries e Filmes com submenu: Para você · Top 10 · Suas séries (só Séries) · Lançamentos ·
// Sagas · 4K (só Filmes) · Categorias (lista lateral + grade ordenável).

import { h, icon, clear, KEY, num, debounce, ddmm, gradOf, dayDiff, weekday, monthName } from '../util.js';
import { MatrixZone, buttonsZone, listZone } from '../zones.js';
import { titleRow, top10Row, continueRow, cardOk, cardKey } from '../rows.js';
import { posterCard } from '../cards.js';
import { api, img, cssUrl } from '../net.js';
import { A, S } from '../state.js';
import { toast } from '../ui.js';

const SUBS = {
  s: [['para-voce', 'Para você'], ['top10', 'Top 10'], ['suas', 'Suas séries'], ['lancamentos', 'Lançamentos'], ['sagas', 'Sagas'], ['categorias', 'Categorias']],
  m: [['para-voce', 'Para você'], ['top10', 'Top 10'], ['lancamentos', 'Lançamentos'], ['sagas', 'Sagas'], ['4k', '4K'], ['categorias', 'Categorias']]
};
const ORDENS = [['vistos', 'Mais vistos'], ['recentes', 'Mais recentes'], ['nota', 'Nota'], ['az', 'A–Z']];
/** Id de categoria: número (categoria do painel) ou texto "jw:drm" (gênero do JustWatch).
 *  Compara sempre como texto (o servidor pode mandar 26 numa resposta e "26" noutra). */
function sameCat(a, b) { return a != null && b != null && String(a) === String(b); }
/** Id no caminho da URL: codificado, mas o ":" fica legível (é válido num segmento de caminho). */
function catPath(id) { return encodeURIComponent(String(id)).replace(/%3A/gi, ':'); }

export function sectionScreen(ctx, tipo) {
  const tabKey = tipo === 's' ? 'series' : 'filmes';
  let sub = S.sub[tabKey] || 'para-voce';
  const pills = SUBS[tipo].map(function (s) {
    const e = h('span.pill', { text: s[1], 'data-sub': s[0] });
    e._ok = function () { choose(s[0], true); };
    return e;
  });
  const subBar = h('div.sub', null, pills);
  const scroller = h('div.scroller');
  const view = h('div.view', null, scroller);
  const el = h('div.scr.sec', null, subBar, view);
  const subZone = buttonsZone('sub', pills, { entry: 'selected', selected: function () { return pills.filter(function (p) { return p.getAttribute('data-sub') === sub; })[0]; } });
  subZone.scrollY = 0;
  let extra = null; // limpeza da sub-tela
  let token = 0;

  function paintPills() { pills.forEach(function (p) { p.classList.toggle('on', p.getAttribute('data-sub') === sub); }); }
  function choose(s, focusContent) {
    if (extra && extra.destroy) extra.destroy();
    extra = null;
    sub = s; S.sub[tabKey] = s;
    paintPills();
    return load(false, focusContent);
  }
  function setContent(zones, keep, focusContent) {
    ctx.setZones([subZone].concat(zones), keep, focusContent && zones.length ? zones[0].key : null);
  }
  function loading() { clear(scroller); scroller.appendChild(h('div.loading', { text: 'Carregando…' })); }

  function load(keep, focusContent) {
    const my = ++token;
    if (!keep) { loading(); setContent([], false); }
    view.className = 'view' + (sub === 'categorias' ? ' cat' : '') + (sub === 'suas' ? ' suas' : '');
    if (sub === 'suas') return api.get('suas-series').then(function (d) { if (my === token) renderSuas(d, keep, focusContent); }, fail);
    if (sub === 'categorias') return api.get('categorias', { tipo: tipo }).then(function (d) { if (my === token) renderCats(d, keep, focusContent); }, fail);
    return api.get('secao', { tipo: tipo, nome: sub }).then(function (d) { if (my === token) renderRows(d, keep, focusContent); }, fail);
    function fail() { if (my !== token) return; clear(scroller); scroller.appendChild(h('div.empty', { text: 'Não foi possível carregar agora. O servidor está fora?' })); setContent([], keep); }
  }

  // ---------- fileiras ----------
  function renderRows(d, keep, focusContent) {
    clear(scroller);
    const zones = [];
    (d.fileiras || []).forEach(function (f, i) {
      if (!f.itens || !f.itens.length) return;
      let z;
      if (sub === 'top10' || f.itens[0].posicao != null) {
        z = top10Row('f' + i, tipo, [{ codigo: f.codigo || (f.itens[0].plataformas && f.itens[0].plataformas[0] && f.itens[0].plataformas[0].codigo), nome: f.titulo, itens: f.itens }], { title: f.titulo });
      } else z = titleRow('f' + i, f.titulo, f.subtitulo || null, f.itens);
      scroller.appendChild(z.el);
      zones.push(z);
    });
    if (!zones.length) scroller.appendChild(h('div.empty', { text: 'Nada nesta seção por enquanto.' }));
    setContent(zones, keep, focusContent);
  }

  // ---------- Suas séries ----------
  function renderSuas(d, keep, focusContent) {
    clear(scroller);
    const zones = [];
    const chegaram = (d.chegaram || []).map(function (c) { return c; });
    if (chegaram.length) {
      const z = continueRow('chegaram', chegaram, { title: 'Chegaram', small: 'episódios novos das séries que você acompanha', removable: false });
      scroller.appendChild(z.el);
      zones.push(z);
    } else scroller.appendChild(h('div.rh', null, h('span.rt', { text: 'Chegaram' }), h('small', { text: 'nenhum episódio novo nos últimos dias' })));
    const items = (d.proximos || []).map(agendaItem);
    scroller.appendChild(h('div.rh.agh', null, h('span.rt', { text: 'Próximos episódios' }), h('small', { text: 'OK liga ou desliga o aviso · Verde abre a série' })));
    const inner = h('div.agi', null, items);
    const box = h('div.agenda', null, inner);
    scroller.appendChild(box);
    if (items.length) zones.push(listZone('agenda', items, { el: box, vscroll: { box: box, inner: inner, pad: 8 }, scrollY: 0 }));
    else inner.appendChild(h('div.empty', { text: 'Sem próximos episódios com data.' }));
    if (zones[0]) zones[0].scrollY = 0;
    setContent(zones, keep, focusContent);
  }
  function agendaItem(p) {
    const dd = dayDiff(p.data);
    // com data, o rótulo sai da própria data (não pode contradizer o dia); sem data, vale o do servidor
    const rot = dd == null ? (p.rotulo || '') : dd === 0 ? 'Hoje' : dd === 1 ? 'Amanhã' : dd > 1 && dd < 7 ? weekday(p.data) : (p.rotulo || weekday(p.data));
    const extra = [p.nome, p.nota].filter(Boolean).filter(function (x, i, a) { return i === 0 || String(a[0]).toLowerCase().indexOf(String(x).toLowerCase()) === -1 && String(x).toLowerCase().indexOf(String(a[0]).toLowerCase()) === -1; });
    const th = h('div.th.pst.' + gradOf(p.serie && p.serie.nome));
    if (p.serie && (p.serie.fundo || p.serie.poster)) th.style.backgroundImage = cssUrl(img(p.serie.fundo || p.serie.poster));
    const st = h('span.st');
    function paint() { st.className = 'st' + (p.aviso_id ? ' on' : ''); st.innerHTML = icon('bell') + '<span>' + (p.aviso_id ? 'Aviso ligado' : 'Avisar') + '</span>'; }
    paint();
    const e = h('div.ag', { 'data-label': (p.serie && p.serie.nome) + ' T' + p.temporada + ':E' + p.episodio },
      h('div.d', null, h('span', { text: rot }), h('b', { text: p.data ? ddmm(p.data) : '—' })),
      th, h('div.x', null, h('b', { text: p.serie ? p.serie.nome : '' }), h('span', { text: ['T' + p.temporada + ':E' + p.episodio].concat(extra).join(' · ') })), st);
    e._ok = function () {
      if (p.aviso_id) api.post('aviso/' + p.aviso_id + '/cancelar', {}).then(function () { p.aviso_id = null; paint(); toast('Aviso desligado'); }, function () { toast('Não foi possível agora.'); });
      else api.post('aviso', { tipo: 'episodio', alvo: { titulo: p.serie && p.serie.id, temporada: p.temporada, episodio: p.episodio }, quando: p.data || null })
        .then(function (r) { p.aviso_id = (r && r.id) || true; paint(); toast('Aviso ligado: você será avisado quando chegar.'); }, function () { toast('Não foi possível agora.'); });
    };
    e._key = function (k) { if (k === KEY.GREEN && p.serie) { A.openDetail(p.serie); return true; } return false; };
    return e;
  }

  // ---------- Categorias ----------
  const cat = { id: null, ordem: 'vistos', esconder: false, so4k: false, pagina: 1, paginas: 1, itens: [], total: 0, nome: '' };
  function renderCats(d, keep, focusContent) {
    clear(scroller);
    const entries = [];
    function line(c) {
      const e = h('div.c', { 'data-label': c.nome, 'data-cat': String(c.id) }, h('span', { text: c.nome }), h('small', { text: num(c.total) }));
      e._cat = c;
      e._ok = function () { pick(c, true); };
      entries.push(e);
      return e;
    }
    const inner = h('div.dri', null,
      d.seu_gosto && d.seu_gosto.length ? h('div.h', { text: 'Seu gosto' }) : null,
      (d.seu_gosto || []).map(line),
      h('div.h', { text: 'Todas' }),
      (d.todas || []).filter(function (c) { return !(d.seu_gosto || []).some(function (g) { return sameCat(g.id, c.id); }); }).map(line));
    const drawer = h('div.drawer', null, inner);
    const head = h('div.gh');
    const gridInner = h('div.pgi');
    const grid = h('div.pgrid', null, gridInner);
    const keys = h('div.keys', null,
      key('#e5484d', 'Minha lista'), key('#30d158', 'ordem'), key('#ffd60a', 'esconder vistos'), key('#0a84ff', 'só 4K'));
    scroller.appendChild(drawer); scroller.appendChild(head); scroller.appendChild(grid); scroller.appendChild(keys);
    const drawerZone = new MatrixZone({
      key: 'drawer', el: drawer, rows: entries.map(function (e) { return [e]; }), scrollY: 0,
      vscroll: { box: drawer, inner: inner, pad: 90 }, entry: 'selected',
      selected: function () { return entries.filter(function (e) { return sameCat(e._cat.id, cat.id); })[0]; },
      onFocus: function (e) { if (!sameCat(e._cat.id, cat.id)) later(e._cat); },
      links: { right: 'grade', down: false }
    });
    const gridZone = new MatrixZone({
      key: 'grade', el: grid, rows: [], scrollY: 0, vscroll: { box: grid, inner: gridInner, pad: 16 },
      links: { left: 'drawer', down: false },
      onOk: function (e) { cardOk(e._c, null); },
      onKey: function (k, e) { return gridKey(k, e); },
      onFocus: function (e, r) { if (r >= Math.ceil(gridInner.children.length / 7) - 2) more(); }
    });
    const later = debounce(function (c) { pick(c, false); }, 600);
    function key(c, t) { return h('span', null, h('i', { style: { background: c } }), t); }
    function paintHead() {
      clear(head);
      head.appendChild(h('b', { text: cat.nome }));
      head.appendChild(h('span', { text: num(cat.total) + (tipo === 's' ? ' séries' : ' filmes') + ' · dublado e legendado juntos' }));
      const flags = [];
      if (cat.esconder) flags.push('sem os vistos');
      if (cat.so4k) flags.push('só 4K');
      if (flags.length) head.appendChild(h('span.flag', { text: flags.join(' · ') }));
      head.appendChild(h('span.sort', { text: ORDENS.filter(function (o) { return o[0] === cat.ordem; })[0][1] }));
    }
    function pick(c, focusGrid) {
      later.cancel();
      if (!sameCat(c.id, cat.id)) { cat.id = c.id; cat.nome = c.nome; cat.pagina = 1; cat.itens = []; }
      entries.forEach(function (e) { e.classList.toggle('on', sameCat(e._cat.id, cat.id)); });
      fetchPage(1).then(function () { if (focusGrid) ctx.focusZone('grade'); });
    }
    function fetchPage(p) {
      const myId = cat.id;
      if (p === 1) { clear(gridInner); gridInner.appendChild(h('div.loading', { text: 'Carregando…' })); gridZone.setRows([], false); paintHead(); }
      return api.get('categoria/' + catPath(cat.id), { tipo: tipo, ordem: cat.ordem, pagina: p, esconder_vistos: cat.esconder ? 1 : 0, so4k: cat.so4k ? 1 : 0 }).then(function (r) {
        if (myId !== cat.id) return;
        cat.pagina = r.pagina || p; cat.paginas = r.paginas || 1; cat.total = r.total != null ? r.total : cat.total;
        if (r.nome) cat.nome = r.nome;
        if (p === 1) { clear(gridInner); cat.itens = []; }
        (r.itens || []).forEach(function (c) {
          cat.itens.push(c);
          const e = posterCard(c, true);
          e._c = c;
          gridInner.appendChild(e);
        });
        if (!cat.itens.length) gridInner.appendChild(h('div.empty', { text: 'Nenhum título com esses filtros.' }));
        paintHead();
        const cells = Array.prototype.slice.call(gridInner.querySelectorAll('.card'));
        const rows = [];
        for (let i = 0; i < cells.length; i += 7) rows.push(cells.slice(i, i + 7));
        gridZone.setRows(rows, p > 1);
        lazyImages();
        ctx.repaint();
      }, function () { if (p === 1) { clear(gridInner); gridInner.appendChild(h('div.empty', { text: 'Não foi possível carregar esta categoria.' })); } });
    }
    let busy = false;
    function more() {
      if (busy || cat.pagina >= cat.paginas) return;
      busy = true;
      fetchPage(cat.pagina + 1).then(function () { busy = false; }, function () { busy = false; });
    }
    function lazyImages() { /* imagens da grade: o fundo carrega por CSS; 60 por página */ }
    function gridKey(k, e) {
      if (k === KEY.GREEN) {
        const i = ORDENS.map(function (o) { return o[0]; }).indexOf(cat.ordem);
        cat.ordem = ORDENS[(i + 1) % ORDENS.length][0];
        toast('Ordem: ' + ORDENS[(i + 1) % ORDENS.length][1]);
        fetchPage(1); return true;
      }
      if (k === KEY.YELLOW) { cat.esconder = !cat.esconder; toast(cat.esconder ? 'Escondendo o que você já viu' : 'Mostrando tudo'); fetchPage(1); return true; }
      if (k === KEY.BLUE) { cat.so4k = !cat.so4k; toast(cat.so4k ? 'Só títulos em 4K' : 'Todas as qualidades'); fetchPage(1); return true; }
      if (k === KEY.RED && e) return cardKey(k, e._c, null);
      return false;
    }
    extra = { destroy: function () { later.cancel(); } };
    setContent([drawerZone, gridZone], keep, focusContent ? 'drawer' : null);
    const first = (d.seu_gosto && d.seu_gosto[0]) || (d.todas && d.todas[0]);
    if (first && cat.id == null) pick(first, false); else if (cat.id != null) pick({ id: cat.id, nome: cat.nome }, false);
  }

  paintPills();
  return {
    el: el, scroller: scroller, viewH: 884, anchor: 10, tab: tabKey,
    load: function (keep) { return load(keep, false); },
    onKey: function (k) {
      if (k === KEY.CH_UP || k === KEY.CH_DOWN) {
        const i = SUBS[tipo].map(function (s) { return s[0]; }).indexOf(sub);
        const n = SUBS[tipo][(i + (k === KEY.CH_DOWN ? 1 : -1) + SUBS[tipo].length) % SUBS[tipo].length][0];
        choose(n, false);
        return true;
      }
      return false;
    },
    destroy: function () { token++; if (extra && extra.destroy) extra.destroy(); }
  };
}
