// Cartões das fileiras. Cada um devolve um elemento absoluto (a fileira posiciona).

import { h, icon, gradOf, ago, fmtDur, platName, platColor, iniciais, dec1, clock, parseDate } from './util.js';
import { img, cssUrl } from './net.js';
import { S } from './state.js';

function bgDiv(cls, url) {
  const d = h('div.' + cls);
  if (url) d.style.backgroundImage = cssUrl(img(url));
  return d;
}
/** Arte do cartão: gradiente de reserva + imagem. O nome escrito pelo app só aparece quando não há
 * imagem ou quando ela falha (pôster real já traz o nome na arte). */
function art(nome, url) {
  const pst = h('div.pst.' + gradOf(nome));
  const im = h('div.im');
  const full = url ? img(url) : '';
  const label = function () { if (!pst.firstChild) pst.appendChild(h('span', { text: nome })); };
  if (!full) { label(); return [pst, im]; }
  const probe = new Image();
  probe.onload = function () { im.style.backgroundImage = cssUrl(full); };
  probe.onerror = label;
  probe.src = full;
  return [pst, im];
}
function bar(pct) {
  if (pct == null || pct <= 0) return null;
  return h('div.bar', null, h('b', { style: { width: Math.min(100, Math.max(2, pct)) + '%' } }));
}
function seloCls(selo) {
  if (!selo) return '';
  if (/pediu/i.test(selo)) return 'req';
  if (/^4k$/i.test(selo)) return 'k4';
  return 'nw';
}
export function mvCls(m) { return m === 'NOVO' ? 'nw' : m === '=' ? 'eq' : String(m || '').charAt(0) === '▲' ? 'up' : 'dn'; }

export function avisoKey(c) { return c && c.tmdb ? 'tmdb:' + c.tmdb : ''; }
export function avisoLigado(c) { const k = avisoKey(c); return !!(c && c.aviso_id) || !!(k && S.avisosLigados[k]); }

/** Pôster (po 200x300, ps 168x252). */
export function posterCard(c, small) {
  const off = c.na_lista === false;
  const el = h('div.card.po' + (small ? '.sm' : '') + (off ? '.off' : ''), { 'data-label': c.nome },
    art(c.nome, c.poster),
    c.selo && !off ? h('span.bg.' + seloCls(c.selo), { text: c.selo }) : null,
    off ? h('div.cta', { html: icon('bell') + '<span>' + (avisoLigado(c) ? 'Aviso ligado' : 'Avisar quando chegar') + '</span>' }) : null,
    bar(c.progresso && c.progresso.pct));
  return el;
}

/** Continuar assistindo (330 x 186 + textos). O último canal do Ao vivo vem como {tipo:'canal'}. */
export function continueCard(c) {
  if (c.tipo === 'canal') return channelContinueCard(c);
  const p = c.progresso || {};
  let sb = '';
  if (p.proximo && p.temporada) sb = 'Próximo episódio: T' + p.temporada + ':E' + p.episodio;
  else if (p.temporada) sb = 'T' + p.temporada + ':E' + p.episodio + (p.restante_s ? ' · restam ' + fmtDur(p.restante_s) : '');
  else if (p.restante_s) sb = 'Restam ' + fmtDur(p.restante_s);
  const other = p.tv && p.tv !== S.tvNome;
  const badge = c.selo && /NOVO/.test(c.selo) ? h('span.new', { text: c.selo })
    : other ? h('span.where', { html: icon('tv') + '<span>Parou na ' + escT(p.tv) + (p.quando ? ' · ' + ago(p.quando) : '') + '</span>' }) : null;
  return h('div.card.cc', { 'data-label': c.nome },
    h('div.img', null, art(c.nome, c.fundo || c.poster), badge,
      bar(p.proximo ? 100 : p.pct)),
    h('div.nm', { text: c.nome }), h('div.sb', { text: sb || (c.quando ? ago(c.quando) : '') }));
}
/** Último canal do Ao vivo no Continuar: logo centralizado em cartão escuro, selo AO VIVO, programa de agora
 * com a barra dele (calculada pelo relógio: a cópia do Início pode ser de minutos atrás). */
export function channelContinueCard(c) {
  const a = c.agora;
  const nome = h('span.lgn', { text: c.nome });
  const logo = h('i.lg');
  if (c.logo) {
    const full = img(c.logo);
    const probe = new Image();
    probe.onload = function () { logo.style.backgroundImage = cssUrl(full); nome.style.display = 'none'; };
    probe.src = full;
  }
  return h('div.card.cc.cv', { 'data-label': c.nome },
    h('div.img', null, nome, logo, h('span.lv', { text: 'AO VIVO' }), a ? bar(programPct(a)) : null),
    h('div.nm', { text: (c.numero != null ? c.numero + ' · ' : '') + c.nome }),
    h('div.sb', { text: a && a.titulo ? 'Agora: ' + a.titulo : 'Canal ao vivo' + (c.quando ? ' · visto ' + ago(c.quando) : '') }));
}
export function programPct(a) {
  const i = parseDate(a && a.inicio), f = parseDate(a && a.fim);
  if (i && f && f > i) return Math.max(0, Math.min(100, Math.round((Date.now() - i.getTime()) * 100 / (f.getTime() - i.getTime()))));
  return a && a.pct != null ? a.pct : null;
}
function escT(t) { return String(t || '').replace(/&/g, '&amp;').replace(/</g, '&lt;'); }

/** Cartão do Top 10 (150 x 225) com número grande. */
export function topCard(c) {
  const off = c.na_lista === false;
  return h('div.card.tk' + (off ? '.off' : ''), { 'data-label': '#' + c.posicao + ' ' + c.nome },
    art(c.nome, c.poster),
    off ? h('span.ask', { html: icon('bell') + '<span>' + (avisoLigado(c) ? 'Aviso ligado' : 'Avisar') + '</span>' })
      : (c.mov ? h('span.mv.' + mvCls(c.mov), { text: c.mov }) : null),
    h('span.rn', { text: String(c.posicao || '') }));
}
/** Abertura de cada plataforma na fileira do Top 10. */
export function platTile(t) {
  const el = h('div.card.ptile', null,
    h('span.k', { text: 'Top 10 ' + (t.tipo === 's' ? 'séries' : 'filmes') }),
    h('b', { text: platName(t.codigo, t.nome) }),
    h('span.k', { text: t.hora ? 'hoje · ' + t.hora : 'hoje' }));
  el.style.backgroundColor = platColor(t.codigo);
  return el;
}

/** Saga (540 x 230). */
export function sagaCard(s) {
  const pills = [];
  const total = Math.min(s.total || 0, 14);
  for (let i = 0; i < total; i++) pills.push(h('i' + (i < (s.vistos || 0) ? '.v' : '')));
  return h('div.card.sg', { 'data-label': s.nome },
    h('div.pst.' + gradOf(s.nome)), bgDiv('im', s.fundo), h('div.shade'),
    h('div.sgt', null, h('b', { text: s.nome }), h('div.pp3', null, pills),
      h('span', { text: 'Você viu ' + (s.vistos || 0) + ' de ' + (s.total || 0) + (s.proximo ? ' · próximo: ' + s.proximo.nome : '') })));
}

/** Episódio (400: imagem 225 + textos). */
export function episodeCard(e, atual) {
  const p = e.progresso || {};
  return h('div.card.ep' + (atual ? '.cur' : ''), { 'data-label': e.numero + '. ' + e.nome },
    h('div.img', null, h('div.pst.' + gradOf(e.nome || String(e.numero))), bgDiv('im', e.still),
      p.terminado ? h('span.seen', { html: icon('check') + '<span>Visto</span>' }) : null,
      bar(p.terminado ? 100 : p.pct)),
    h('div.nm', { text: e.numero + '. ' + (e.nome || 'Episódio ' + e.numero) }),
    h('div.sb', { text: (e.duracao_s ? fmtDur(e.duracao_s) + ' · ' : '') + (e.sinopse || '') }));
}

/** Pessoa do elenco (círculo). */
export function castCard(p) {
  const face = h('i', { text: iniciais(p.nome) });
  if (p.foto) { face.style.backgroundImage = cssUrl(img(p.foto)); face.textContent = ''; }
  return h('div.card.cast', { 'data-label': p.nome }, face, h('b', { text: p.nome }), p.personagem ? h('span', { text: p.personagem }) : null);
}

/** Jogo de hoje (430 x 196). */
export function gameCard(g) {
  const live = g.status === 'agora';
  const ligado = !!g.aviso_id || !!S.avisosLigados['jogo:' + g.id];
  let st, right;
  // em andamento: tempo decorrido em destaque à direita e o placar grande entre os times
  if (live) { st = h('span.lv', { text: 'AO VIVO' }); right = h('span.min', { text: g.minuto || 'em andamento' }); }
  else if (g.status === 'fim') { st = h('span', { text: 'Encerrado' }); right = h('span', { text: g.placar || '' }); }
  else {
    st = h('span', { text: 'Começa às ' + clock(g.inicio) });
    right = h('span.bell' + (ligado ? '.on' : ''), { html: icon('bell') + '<span>' + (ligado ? 'aviso ligado' : 'Avisar') + '</span>' });
  }
  // nome inteiro se couber; senão letra menor; senão a sigla (nunca reticências)
  const nomes = ((g.casa && g.casa.nome) || '').length + ((g.fora && g.fora.nome) || '').length;
  const placar = live && g.placar ? g.placar.replace(/\s*x\s*/i, ' – ') : null;
  const lim = placar ? [12, 16] : [18, 22];            // o placar grande ocupa mais espaço que o "×"
  const fit = nomes <= lim[0] ? 'n' : nomes <= lim[1] ? 'sm' : 'sigla';
  const team = function (t) {
    const cr = h('span.cr', { text: (t && t.sigla) || '?' });
    if (t && t.escudo) { cr.style.backgroundImage = cssUrl(img(t.escudo)); cr.textContent = ''; }
    const nome = (t && (fit === 'sigla' ? t.sigla || t.nome : t.nome)) || '';
    return [cr, h('span.tn', { text: nome })];
  };
  return h('div.card.gm' + (live ? '.live' : ''), { 'data-label': (g.casa && g.casa.nome) + ' x ' + (g.fora && g.fora.nome) },
    h('div.st', null, st, right),
    h('div.tm2' + (fit === 'sm' ? '.sm' : ''), null, team(g.casa), h('span.x' + (placar ? '.pl' : ''), { text: placar || '×' }), team(g.fora)),
    // canal "19:15 - Goiás x Ponte Preta [FHD]" é o próprio jogo: não repete o nome
    h('div.ft', { text: [g.liga, g.canal && !/^\s*\d{1,2}[:h]\d{2}\s*-/.test(g.canal.nome || '') ? g.canal.nome : '',
      g.canal && g.canal.qualidade ? qual(g.canal.qualidade) : ''].filter(Boolean).join(' · ') }));
}

/** Pessoa nos resultados da busca. */
export function personCard(p) {
  const face = h('i', { text: iniciais(p.nome) });
  if (p.foto) { face.style.backgroundImage = cssUrl(img(p.foto)); face.textContent = ''; }
  return h('div.card.pp', { 'data-label': p.nome }, face,
    h('div', null, h('b', { text: p.nome }), h('span', { text: (p.papel || '') + (p.total != null ? ' · ' + p.total + ' títulos na sua lista' : '') })));
}

export function qual(q) {
  return { '4k': '4K', fhd: 'Full HD', hd: 'HD', sd: 'SD' }[String(q || '').toLowerCase()] || (q ? String(q).toUpperCase() : '');
}
export function imdbTxt(v) { return v ? 'IMDb ' + dec1(v) : ''; }

/** Pílula de plataforma "#1 HBO Max". */
export function platPill(p) {
  const el = h('span.pl', null, h('i', { style: { background: platColor(p.codigo) } }),
    p.posicao ? h('b', { text: '#' + p.posicao }) : null, h('span', { text: platName(p.codigo, p.nome) }));
  return el;
}
