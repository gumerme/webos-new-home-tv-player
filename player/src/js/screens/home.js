// Início: destaque (10 itens girando a cada 12 s; com trailer, o trailer toca inteiro, com som, e só no fim
// passa para o próximo), continuar (títulos e o último canal do Ao vivo), Top 10 séries e filmes
// por plataforma, recomendados, chegou, porque assistiu, sagas, época, gêneros, minha lista.

import { h, icon, gradOf, fmtMin, dec1, clear, ON_TV, OLD_ENGINE, fmtTime } from '../util.js';
import { trailerFrame } from '../trailer.js';
import { MatrixZone } from '../zones.js';
import { titleRow, top10Row, topBrasilRow, sagaRow, continueRow } from '../rows.js';
import { platPill, qual } from '../cards.js';
import { img, cssUrl } from '../net.js';
import { A, S, prefOn } from '../state.js';

export function homeScreen(ctx) {
  const scroller = h('div.scroller');
  const el = h('div.scr.home', null, scroller);
  let hero = null, rotTimer = null, trailerTimer = null, trailerWatch = null, trailerOn = false, heroIdx = 0, rateTimer = null;
  const RATE_PASSA_MS = 1500;           // Gostei/Não gostei no destaque: mostra a marcação e passa ao próximo
  const TRAILER_TRAVA_MS = 5 * 60000;   // trava de segurança: nunca veio 'fim' do helper
  const TRAILER_ERRO_MS = 2000;         // 'erro': mostra o fundo mais 2 s e passa

  function render(data, keep) {
    stopTrailer();
    clear(scroller);
    const zones = [];
    if (data.destaque && data.destaque.length) {
      // recarregou (SSE "atualizado" depois de um Gostei, por ex.): fica no mesmo título se ele ainda está lá
      const curId = hero && hero.list[heroIdx] ? hero.list[heroIdx].id : null;
      let at = -1;
      data.destaque.forEach(function (x, j) { if (curId != null && x.id === curId) at = j; });
      heroIdx = at >= 0 ? at : Math.min(heroIdx, data.destaque.length - 1);
      hero = buildHero(data.destaque);
      scroller.appendChild(hero.el);
      zones.push(hero.zone);
    } else hero = null;
    const body = h('div.rows' + (hero ? '' : '.nohero'));
    scroller.appendChild(body);
    function add(z) { if (z && z.items && z.items.length) { body.appendChild(z.el); zones.push(z); } }
    add(continueRow('continuar', data.continuar, { onEmpty: function () { ctx.refresh(); } }));
    add(titleRow('recomendados', 'Recomendados para você', 'pelo que a casa assistiu e gostou', data.recomendados));
    add(topBrasilRow('topbr', data.top_brasil));
    if (data.top10) {
      add(top10Row('top10s', 's', data.top10.s));
      add(top10Row('top10m', 'm', data.top10.m));
    }
    add(titleRow('chegou', 'Chegou na sua lista', 'o que você pediu para avisar e estreias dos últimos 7 dias', data.chegou));
    (data.porque || []).slice(0, 2).forEach(function (p, i) {
      const t = /^porque/i.test(p.titulo || '') ? p.titulo : 'Porque você assistiu ' + (p.titulo || '');
      add(titleRow('porque' + i, t, null, p.itens));
    });
    add(sagaRow('sagas', data.sagas));
    if (data.epoca) add(titleRow('epoca', data.epoca.titulo || 'Para esta época', data.epoca.subtitulo || null, data.epoca.itens));
    (data.generos || []).slice(0, 2).forEach(function (g, i) { add(titleRow('genero' + i, g.nome + ' para você', null, g.itens)); });
    add(titleRow('minhalista', 'Minha lista', null, data.minha_lista));
    if (zones.length === (hero ? 1 : 0)) body.appendChild(h('div.empty', { text: 'Nada por aqui ainda. O servidor está preparando o catálogo.' }));
    ctx.setZones(zones, keep);
    startRotation();
  }

  // ---------- destaque ----------
  function buildHero(list) {
    const bd = h('div.bd');
    const shade = h('div.bd-shade');
    const tv = h('div.bd-tv');
    const htx = h('div.htx');
    const dots = h('div.dots');
    const btnRow = h('div.btns');
    const box = h('div.hero', null, bd, tv, shade, htx);
    const zone = new MatrixZone({ key: 'destaque', el: box, rows: [[]], scrollY: 0, entry: 'remember' });
    const baseMove = zone.move;
    zone.move = function (dir) {
      if (baseMove.call(zone, dir)) return true;
      if (dir === 'left' || dir === 'right') { show(heroIdx + (dir === 'left' ? -1 : 1), true); return true; }
      return false;
    };
    function show(i, byUser) {
      clearTimeout(rateTimer);
      heroIdx = (i + list.length) % list.length;
      const c = list[heroIdx];
      stopTrailer();
      bd.className = 'bd ' + gradOf(c.nome);
      bd.style.backgroundImage = c.fundo ? cssUrl(img(c.fundo)) : '';
      clear(htx);
      const kick = h('div.kick', null, h('span.lbl', { text: c.rotulo || (heroIdx === 0 ? 'Para você' : 'Em alta') }),
        c.motivo ? h('span', { text: c.motivo }) : null);
      const meta = h('div.meta', null,
        c.imdb ? h('span.imdb', { text: 'IMDb ' + dec1(c.imdb) }) : null,
        h('span', { text: [c.tipo === 's' ? 'Série' : 'Filme', c.ano, c.duracao_min ? fmtMin(c.duracao_min) : (c.temporadas ? c.temporadas + (c.temporadas > 1 ? ' temporadas' : ' temporada') : '')].filter(Boolean).join(' · ') }),
        c.classificacao ? h('span.age', { text: c.classificacao }) : null,
        (c.plataformas || []).slice(0, 2).map(platPill),
        c.qualidade ? h('span.chip.ok', { html: icon('check') + '<span>' + qual(c.qualidade) + '</span>' }) : null);
      const big = h('div.big' + (c.nome.length > 22 ? '.long' : ''), { text: c.nome });
      const prog = c.progresso && c.progresso.pct > 0 && !c.progresso.proximo;
      const play = h('span.btn.pri', { 'data-label': prog ? 'Continuar' : 'Assistir', html: icon('play', 'fill') + '<span>' + (prog ? 'Continuar' : 'Assistir') + '</span>' });
      play._ok = function () { if (prog) A.resume(c); else A.playTitle(c); };
      const info = h('span.btn', { 'data-label': 'Mais informações', html: icon('info') + '<span>Mais informações</span>' });
      info._ok = function () { A.openDetail(c); };
      const lista = h('span.btn', { 'data-label': 'Minha lista' });
      function paintLista() { lista.innerHTML = icon(c.minha_lista ? 'check' : 'plus') + '<span>Minha lista</span>'; }
      paintLista();
      lista._ok = function () { A.toggleLista(c).then(paintLista); };
      // Gostei / Não gostei (09/11 noite): como no Detalhe (de novo desfaz); marcou, mostra e passa ao próximo
      const up = h('span.btn', { 'data-label': 'Gostei', html: icon('up') + '<span>Gostei</span>' });
      const down = h('span.btn', { 'data-label': 'Não gostei', html: icon('down') + '<span>Não gostei</span>' });
      function paintRate() { up.classList.toggle('sel', c.nota_usuario === 1); down.classList.toggle('sel', c.nota_usuario === -1); }
      function rate(v) {
        clearTimeout(rateTimer);
        const idx = heroIdx;
        if (A.rateTitle(c, v) !== 0 && list.length > 1) {
          rateTimer = setTimeout(function () { if (hero && hero.list === list && heroIdx === idx) show(heroIdx + 1, true); }, RATE_PASSA_MS);
        }
        paintRate();
        ctx.repaint();
      }
      up._ok = function () { rate(1); };
      down._ok = function () { rate(-1); };
      paintRate();
      clear(btnRow);
      [play, info, lista, up, down].forEach(function (b) { btnRow.appendChild(b); });
      clear(dots);
      list.forEach(function (x, j) { dots.appendChild(h('i' + (j === heroIdx ? '.on' : ''))); });
      const canTrailer = trailerAllowed() && c.trailer_youtube;
      if (list.length > 1 || canTrailer) dots.appendChild(h('span', { text: canTrailer ? 'trailer em instantes' : '◀ ▶ troca o destaque' }));
      htx.appendChild(kick); htx.appendChild(big); htx.appendChild(meta);
      if (c.sinopse) htx.appendChild(h('div.syn', { text: c.sinopse }));
      htx.appendChild(btnRow);
      htx.appendChild(dots);
      const keepC = zone.c;
      zone.setRows([[play, info, lista, up, down]], true);
      zone.c = Math.min(keepC, 4);
      // quase imediato (pedido 09/11): 0,6 s só para não disparar enquanto se troca de destaque com ◀ ▶
      if (canTrailer) trailerTimer = setTimeout(function () { startTrailer(c); }, 600);
      if (byUser) startRotation();
      ctx.repaint();
    }
    // Trailer inteiro (pedido 09/11 noite): sem corte de tempo; o helper avisa 'fim' e só então vem o
    // próximo destaque (que, com trailer, começa sozinho). 'erro' passa em 2 s; sem notícia, trava de 5 min.
    function startTrailer(c) {
      if (!heroVisible()) return;
      const idx = heroIdx;
      const f = trailerFrame(c.trailer_youtube, c.trailer_legenda, function (ev) {
        if (!trailerOn || heroIdx !== idx || !tv.contains(f)) return;
        if (!heroOnScreen()) { stopTrailer(true); return; }
        if (ev === 'tocando') { armTrava(TRAILER_TRAVA_MS); return; }   // a trava conta do começo de verdade
        if (ev === 'fim') heroNext();
        else if (ev === 'erro') armTrava(TRAILER_ERRO_MS);
      });
      clear(tv);
      tv.appendChild(f);
      trailerOn = true;
      armTrava(TRAILER_TRAVA_MS);
      // abriu algo por cima, tocou um vídeo ou rolou a tela: o trailer para (e o giro de 12 s volta)
      clearInterval(trailerWatch);
      trailerWatch = setInterval(function () { if (!heroOnScreen()) stopTrailer(true); }, 1000);
    }
    function armTrava(ms) {
      stopRotation();
      rotTimer = setTimeout(heroNext, ms);
    }
    show(heroIdx);
    return { el: box, zone: zone, show: show, list: list };
  }
  /** Fim do trailer: próximo destaque (o giro de 12 s recomeça; o trailer dele começa sozinho). */
  function heroNext() {
    if (!hero) return;
    hero.show(heroIdx + 1, true);
  }
  function trailerAllowed() { return ON_TV && !OLD_ENGINE && prefOn('trailer_destaque', true) && S.ajustes.trailer !== false; }
  function heroVisible() { return !!hero && ctx.scrollY() === 0 && ctx.isTop() && !S.playing; }
  // com o trailer já tocando, o cartão de aviso no canto (camada "aviso") não o interrompe
  function heroOnScreen() { return !!hero && ctx.scrollY() === 0 && !S.playing && (ctx.isTop() || ctx.topName() === 'aviso'); }
  /** resume: o trailer foi interrompido (rolou, abriu algo): o giro de 12 s volta a contar. */
  function stopTrailer(resume) {
    clearTimeout(trailerTimer);
    clearInterval(trailerWatch);
    const was = trailerOn;
    trailerOn = false;
    const tv = hero && hero.el.querySelector('.bd-tv');
    if (tv) clear(tv);
    if (resume && was) startRotation();
  }
  function startRotation() {
    stopRotation();
    if (!hero) return;
    rotTimer = setTimeout(function tick() {
      if (heroVisible() && !(ctx.focusZoneKey() === 'destaque')) hero.show(heroIdx + 1);
      rotTimer = setTimeout(tick, 12000);
    }, 12000);
  }
  function stopRotation() { clearTimeout(rotTimer); }

  return {
    el: el, scroller: scroller, viewH: 1080, anchor: 140, tab: 'inicio',
    load: function (keep) {
      if (S.inicio && !keep) render(S.inicio, keep);
      return A.loadInicio().then(function (data) { if (data) render(data, true); });
    },
    renderCached: function () { if (S.inicio) render(S.inicio, true); },
    onScroll: function (y) { if (y > 0) stopTrailer(true); },
    destroy: function () { stopTrailer(); stopRotation(); clearTimeout(rateTimer); },
    fmt: fmtTime
  };
}
