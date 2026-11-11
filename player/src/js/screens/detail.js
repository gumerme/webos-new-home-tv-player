// Detalhe (camada sobre a tela): retomar de onde qualquer TV parou, versões dub/leg, qualidade
// testada, legenda pronta, plataformas, notas, temporadas/episódios, saga, elenco, similares.
// Também a tela de Pessoa (títulos de um ator/diretor).

import { h, icon, clear, KEY, gradOf, fmtTime, fmtMin, dec1, num, ago, HELPER, debounce, iniciais } from '../util.js';
import { Layer, push, pop, has, paint } from '../focus.js';
import { buttonsZone, RowZone } from '../zones.js';
import { titleRow } from '../rows.js';
import { platPill, qual, episodeCard, castCard } from '../cards.js';
import { api, img, cssUrl } from '../net.js';
import { A, S } from '../state.js';
import { toast } from '../ui.js';

const stack = []; // detalhes/pessoas abertos (Voltar volta um)

function overlay(name) {
  const bd = h('div.det-bd');
  const shade = h('div.det-shade');
  const scroller = h('div.scroller');
  const el = h('div.overlay.det-' + name, null, bd, shade, scroller);
  document.getElementById('overlays').appendChild(el);
  const layer = new Layer({ name: name, scroller: scroller, viewH: 1080, anchor: 120, onBack: back });
  return { el: el, bd: bd, scroller: scroller, layer: layer };
}
function back() {
  const top = stack.pop();
  if (top) top.close();
  if (!stack.length && A.afterOverlay) A.afterOverlay();
}
export function closeAllOverlays() { while (stack.length) stack.pop().close(); }
export function overlayOpen() { return stack.length > 0; }
export function currentDetail() { const t = stack[stack.length - 1]; return t && t.kind === 'titulo' ? t : null; }

export function openDetail(card, opts) {
  opts = opts || {};
  if (stack.length >= 6) { const old = stack.shift(); old.close(); }
  const o = overlay('detalhe');
  const self = { kind: 'titulo', id: card.id, close: close, reload: reload, o: o };
  stack.push(self);
  push(o.layer);
  let d = null, temporadas = null, seasonIdx = 0, versao = null;
  o.bd.className = 'det-bd ' + gradOf(card.nome);
  if (card.fundo) o.bd.style.backgroundImage = cssUrl(img(card.fundo));
  o.scroller.appendChild(h('div.det', null, h('div.big', { text: card.nome }), h('div.loading.inl', { text: 'Carregando…' })));

  function close() {
    if (o.el.parentNode) o.el.parentNode.removeChild(o.el);
    o.layer.setZones([]);
    if (has(o.layer)) pop(o.layer);
  }
  function reload(keep) {
    return api.get('titulo/' + card.id).then(function (r) {
      d = r;
      versao = d.versao_escolhida || (d.versoes && d.versoes[0] && d.versoes[0].id) || null;
      if (d.tipo === 's') return loadSeasons().then(function () { render(keep); });
      render(keep);
    }, function (e) {
      clear(o.scroller);
      o.scroller.appendChild(h('div.det', null, h('div.big', { text: card.nome }),
        h('div.syn', { text: e && e.status === 404 ? 'Este título não está mais na lista.' : 'Não foi possível abrir agora (servidor fora?).' })));
      const b = h('span.btn', { text: 'Voltar' }); b._ok = back;
      o.scroller.firstChild.appendChild(h('div.btns', null, b));
      o.layer.setZones([buttonsZone('botoes', [b])]);
    });
  }
  function loadSeasons() {
    return api.get('titulo/' + card.id + '/temporadas', { versao: versao }).then(function (r) {
      temporadas = (r && r.temporadas) || [];
      if (A.cacheSeasons) A.cacheSeasons(card.id, temporadas);
      const want = opts.temporada || (d.retomar && d.retomar.temporada);
      seasonIdx = 0;
      temporadas.forEach(function (t, i) { if (t.numero === want) seasonIdx = i; });
    }, function () { temporadas = []; });
  }

  function render(keep) {
    o.bd.className = 'det-bd ' + gradOf(d.nome);
    o.bd.style.backgroundImage = d.fundo ? cssUrl(img(d.fundo)) : '';
    clear(o.scroller);
    const zones = [];
    const top = h('div.det');
    // plataformas
    const kick = h('div.kick', null, (d.plataformas || []).map(platPill), d.dias_top10 ? h('span', { text: 'no Top 10 há ' + d.dias_top10 + (d.dias_top10 > 1 ? ' dias' : ' dia') }) : null);
    if (kick.childNodes.length) top.appendChild(kick);
    top.appendChild(h('div.big' + (d.nome.length > 22 ? '.long' : ''), { text: d.nome }));
    const tipoTxt = d.tipo === 's' ? 'Série' : 'Filme';
    const dur = d.tipo === 's' ? (d.temporadas ? d.temporadas + (d.temporadas > 1 ? ' temporadas' : ' temporada') : '') : fmtMin(d.duracao_min);
    top.appendChild(h('div.meta', null,
      h('span', { text: [tipoTxt, d.ano, dur].filter(Boolean).join(' · ') }),
      d.classificacao ? h('span.age', { text: d.classificacao }) : null,
      d.generos && d.generos.length ? h('span', { text: d.generos.slice(0, 3).join(' · ') }) : null,
      d.diretor ? h('span', { text: d.diretor }) : (d.criadores && d.criadores.length ? h('span', { text: d.criadores.slice(0, 2).join(', ') }) : null)));
    const n = d.notas || {};
    const scores = h('div.scores', null,
      n.imdb || d.imdb ? h('span.imdb', null, h('b', { text: dec1(n.imdb || d.imdb) }), 'IMDb' + (n.imdb_votos ? ' · ' + votos(n.imdb_votos) + ' votos' : '')) : null,
      n.rotten ? h('span', null, h('b', { text: n.rotten + '%' }), 'Rotten') : null,
      n.jw_gostaram ? h('span', null, h('b', { text: n.jw_gostaram + '%' }), 'gostaram no JustWatch') : null);
    if (scores.childNodes.length) top.appendChild(scores);
    if (d.sinopse) top.appendChild(h('div.syn', { text: d.sinopse }));

    // versões e qualidade
    const chips = (d.versoes || []).map(function (v) {
      const c = h('span.chip' + (v.id === versao ? '.on' : ''), { text: (v.idioma === 'leg' ? 'Legendado' : 'Dublado') + (v.uhd ? ' 4K' : ''), 'data-label': v.idioma });
      c._ok = function () {
        if (v.id === versao) return;
        versao = v.id;
        api.fire('versao', { titulo: d.id, versao: v.id });
        chips.forEach(function (x) { x.classList.remove('on'); });
        c.classList.add('on');
        toast((v.idioma === 'leg' ? 'Legendado' : 'Dublado') + ' escolhido para este título');
        if (d.tipo === 's') loadSeasons().then(function () { render(true); });
      };
      return c;
    });
    const opts2 = h('div.opts2');
    if (chips.length) { opts2.appendChild(h('span.lb', { text: 'Versão' })); chips.forEach(function (c) { opts2.appendChild(c); }); }
    if (d.qualidade) opts2.appendChild(h('span.chip.ok.st', { html: icon('check') + '<span>' + qual(d.qualidade) + (d.qualidade_testada_em ? ' testado ' + quando(d.qualidade_testada_em) : '') + '</span>' }));
    if (d.legenda_pronta) opts2.appendChild(h('span.chip.st', { html: icon('sub') + '<span>Legenda pt-BR pronta</span>' }));
    if (opts2.childNodes.length) top.appendChild(opts2);
    if (chips.length > 1) zones.push(buttonsZone('versoes', chips, { el: opts2, entry: 'selected', selected: function () { return chips.filter(function (c) { return c.classList.contains('on'); })[0]; } }));

    // botões
    const btns = [];
    const r = d.retomar;
    const firstEp = temporadas && temporadas[0] && temporadas[0].episodios[0];
    if (r && r.posicao_s > 0) {
      const label = d.tipo === 's' ? 'Continuar T' + r.temporada + ':E' + r.episodio + ' · ' + fmtTime(r.posicao_s) : 'Continuar ' + fmtTime(r.posicao_s);
      btns.push(btn(label, 'play', function () { A.play({ cartao: d, versao: versao, temporada: r.temporada, episodio: r.episodio, inicio_s: r.posicao_s }); }, true,
        r.tv && r.tv !== S.tvNome ? ' · parou na ' + r.tv : (r.quando ? ' · ' + ago(r.quando) : '')));
      if (d.tipo !== 's') btns.push(btn('Do início', null, function () { A.play({ cartao: d, versao: versao, inicio_s: 0 }); }));
    } else if (d.tipo === 's') {
      const ep = firstEp;
      btns.push(btn(ep ? 'Assistir T' + temporadas[0].numero + ':E' + ep.numero : 'Assistir', 'play', function () {
        A.play({ cartao: d, versao: versao, temporada: ep ? temporadas[0].numero : null, episodio: ep ? ep.numero : null });
      }, true));
    } else btns.push(btn('Assistir', 'play', function () { A.play({ cartao: d, versao: versao }); }, true));
    if (d.trailer_youtube) btns.push(btn('Trailer', null, function () { openTrailer(d.trailer_youtube, d.trailer_legenda); }));
    const lista = btn('Lista', d.minha_lista ? 'check' : 'plus', function () {
      A.toggleLista(d).then(function () { lista.innerHTML = icon(d.minha_lista ? 'check' : 'plus') + '<span>Lista</span>'; });
    });
    btns.push(lista);
    const up = btn('Gostei', 'up', function () { rate(1); });
    const down = btn('Não gostei', 'down', function () { rate(-1); });
    function paintRate() { up.classList.toggle('sel', d.nota_usuario === 1); down.classList.toggle('sel', d.nota_usuario === -1); }
    function rate(v) { A.rateTitle(d, v); paintRate(); }
    paintRate();
    btns.push(up, down);
    const btnRow = h('div.btns', null, btns);
    top.appendChild(btnRow);
    const btnZone = buttonsZone('botoes', btns, { el: btnRow });
    btnZone.scrollY = 0;
    zones.push(btnZone);
    if (zones[0] && zones[0].key === 'versoes') zones[0].scrollY = 0;
    o.scroller.appendChild(top);

    // temporadas e episódios
    if (d.tipo === 's' && temporadas && temporadas.length) {
      const pills = temporadas.map(function (t, i) {
        const p = h('span.pill' + (i === seasonIdx ? '.on' : ''), { text: 'Temporada ' + t.numero });
        p._ok = function () { pickSeason(i); };
        return p;
      });
      const pillBar = h('div.sub.inl', null, pills);
      const epRow = new RowZone({
        key: 'episodios', kind: 'ep', items: temporadas[seasonIdx].episodios || [], noHeader: true,
        render: function (e) { return episodeCard(e, r && r.temporada === temporadas[seasonIdx].numero && r.episodio === e.numero); },
        onOk: function (e) {
          const t = temporadas[seasonIdx];
          const resume = e.progresso && e.progresso.pct > 0 && !e.progresso.terminado;
          A.play({ cartao: d, versao: versao, temporada: t.numero, episodio: e.numero, inicio_s: resume ? null : 0 });
        }
      });
      const sec = h('div.dsec', null, h('div.rh', null, h('span.rt', { text: 'Episódios' }), h('small', { text: temporadas.length + (temporadas.length > 1 ? ' temporadas' : ' temporada') })), pillBar, epRow.el);
      o.scroller.appendChild(sec);
      const seasonZone = buttonsZone('temporadas', pills, { el: pillBar, entry: 'selected', selected: function () { return pills[seasonIdx]; },
        onFocus: debounce(function (el, rr, c) { if (c !== seasonIdx) pickSeason(c); }, 350) });
      zones.push(seasonZone, epRow);
      const wantEp = opts.episodio || (r && r.temporada === temporadas[seasonIdx].numero ? r.episodio : null);
      if (wantEp) (temporadas[seasonIdx].episodios || []).forEach(function (e, i) { if (e.numero === wantEp) epRow.setIndex(i); });
      function pickSeason(i) {
        seasonIdx = i;
        pills.forEach(function (p, j) { p.classList.toggle('on', j === i); });
        epRow.setItems(temporadas[i].episodios || [], false);
        epRow.activate();
        paint();
      }
    }
    // saga
    if (d.saga && d.saga.itens && d.saga.itens.length) {
      const z = titleRow('saga', 'Saga ' + d.saga.nome, 'você viu ' + (d.saga.vistos || 0) + ' de ' + d.saga.itens.length, d.saga.itens, { kind: 'ps' });
      o.scroller.appendChild(h('div.dsec', null, z.el)); zones.push(z);
    }
    // elenco
    if (d.elenco && d.elenco.length) {
      const z = new RowZone({ key: 'elenco', title: 'Elenco', small: 'OK mostra os títulos da pessoa', kind: 'cast', items: d.elenco, render: castCard,
        onOk: function (p) { A.openPerson(p); } });
      o.scroller.appendChild(h('div.dsec', null, z.el)); zones.push(z);
    }
    if (d.similares && d.similares.length) {
      const z = titleRow('similares', 'Parecidos', null, d.similares, { kind: 'ps' });
      o.scroller.appendChild(h('div.dsec', null, z.el)); zones.push(z);
    }
    let focusKey = keep ? (o.layer.zone() && o.layer.zone().key) : (opts.episodio ? 'episodios' : 'botoes');
    let focusIdx = keep && o.layer.zone() && o.layer.zone().index ? o.layer.zone().index() : null;
    if (keep && focusKey === 'episodios') focusIdx = null; // episódio atual já posicionado
    o.layer.setZones(zones, focusKey, focusIdx);
    opts.episodio = null;
  }
  function btn(label, ic, fn, pri, small) {
    const b = h('span.btn' + (pri ? '.pri' : ''), { 'data-label': label, html: (ic ? icon(ic, ic === 'play' ? 'fill' : '') : '') + '<span></span>' });
    b.lastChild.textContent = label;
    if (small) { const s = h('small', { text: small }); b.appendChild(s); }
    b._ok = fn;
    return b;
  }
  reload(false);
  return self;
}

function votos(v) { return v >= 1000000 ? dec1(v / 1000000) + ' mi' : v >= 1000 ? num(Math.round(v / 1000)) + ' mil' : String(v); }
function quando(iso) { const a = ago(iso); return a === 'agora' || /min|h$/.test(a) ? 'hoje' : a; }

export function openTrailer(key, legenda) {
  // trailer sem dublagem: pede a legenda em pt-BR ao app do YouTube (o app pode ignorar se a conta tiver outra preferência)
  const extra = legenda ? '&hl=pt-BR&cc_lang_pref=pt&cc_load_policy=1' : '&hl=pt-BR';
  // 09/11: abre no nosso YouTube (org.webostv.youtube, fork do YouTube AdFree: bloqueia anúncios), não no oficial
  const url = HELPER + '/launch?id=org.webostv.youtube&target=' + encodeURIComponent('https://www.youtube.com/tv?v=' + key + extra);
  const r = new XMLHttpRequest();
  r.open('GET', url, true);
  r.timeout = 4000;
  r.onerror = r.ontimeout = function () { toast('Não foi possível abrir o YouTube.'); };
  try { r.send(); } catch (e) { toast('Não foi possível abrir o YouTube.'); }
}

// ---------- Pessoa ----------
export function openPerson(p) {
  const o = overlay('pessoa');
  const self = { kind: 'pessoa', close: close, o: o };
  stack.push(self);
  push(o.layer);
  o.bd.className = 'det-bd g9';
  const face = h('i', { text: iniciais(p.nome) });
  if (p.foto) { face.style.backgroundImage = cssUrl(img(p.foto)); face.textContent = ''; }
  const sub = h('span', { text: 'Carregando…' });
  o.scroller.appendChild(h('div.person.big2', null, face, h('div', null, h('b', { text: p.nome }), sub)));
  function close() { if (o.el.parentNode) o.el.parentNode.removeChild(o.el); o.layer.setZones([]); if (has(o.layer)) pop(o.layer); }
  api.get('pessoa/' + p.tmdb).then(function (r) {
    sub.textContent = (r.titulos || []).length + ' títulos na sua lista' + ((r.fora || []).length ? ' · ' + r.fora.length + ' fora dela' : '');
    const zones = [];
    if (r.titulos && r.titulos.length) { const z = titleRow('titulos', 'Na sua lista', null, r.titulos); o.scroller.appendChild(z.el); zones.push(z); }
    if (r.fora && r.fora.length) { const z = titleRow('fora', 'Ainda não estão na lista', 'OK ou vermelho: avisar quando chegar', r.fora); o.scroller.appendChild(z.el); zones.push(z); }
    if (zones[0]) zones[0].scrollY = 0;
    if (!zones.length) o.scroller.appendChild(h('div.empty', { text: 'Nenhum título desta pessoa.' }));
    const b = h('span.btn', { text: 'Voltar' }); b._ok = back;
    if (!zones.length) { o.scroller.appendChild(h('div.btns.pad', null, b)); zones.push(buttonsZone('voltar', [b])); }
    o.layer.setZones(zones);
  }, function () { sub.textContent = 'Não foi possível carregar.'; const b = h('span.btn', { text: 'Voltar' }); b._ok = back; o.scroller.appendChild(h('div.btns.pad', null, b)); o.layer.setZones([buttonsZone('voltar', [b])]); });
  return self;
}

export function backOverlay() { back(); }
export { KEY };
