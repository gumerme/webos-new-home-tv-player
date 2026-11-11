// Utilidades sem dependências. Tudo precisa rodar no Chromium 53 (TV 2, webOS 4.10).

export const KEY = {
  LEFT: 37, UP: 38, RIGHT: 39, DOWN: 40, ENTER: 13, BACK: 461, ESC: 27, BACKSPACE: 8,
  CH_UP: 33, CH_DOWN: 34, PLAY: 415, PAUSE: 19, PLAYPAUSE: 10252, STOP: 413, FF: 417, RW: 412,
  RED: 403, GREEN: 404, YELLOW: 405, BLUE: 406
};

export const APP_ID = 'org.webostv.play';
export const APP_VERSION = '2.0.12';
export const HELPER = 'http://127.0.0.1:3911';

export const OLD_ENGINE = (function () {
  const m = /Chrome\/(\d+)/.exec(navigator.userAgent);
  return !!m && Number(m[1]) < 70;
})();
export const ON_TV = !!window.PalmSystem;

export function $(sel, root) { return (root || document).querySelector(sel); }
export function $$(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }

/** h('div.cls1.cls2', {attrs}, children...) — children: string | Node | array | null */
export function h(tag, attrs, ...kids) {
  const parts = tag.split('.');
  const e = document.createElement(parts[0] || 'div');
  if (parts.length > 1) e.className = parts.slice(1).join(' ');
  if (attrs) {
    for (const k in attrs) {
      const v = attrs[k];
      if (v == null || v === false) continue;
      if (k === 'style' && typeof v === 'object') { for (const s in v) e.style[s] = v[s]; }
      else if (k === 'html') e.innerHTML = v;
      else if (k === 'text') e.textContent = v;
      else if (k === 'cls') e.className += (e.className ? ' ' : '') + v;
      else if (k.indexOf('on') === 0 && typeof v === 'function') e[k] = v;
      else if (k.charAt(0) === '_') e[k] = v;
      else e.setAttribute(k, v === true ? '' : v);
    }
  }
  add(e, kids);
  return e;
}
function add(e, kids) {
  for (let i = 0; i < kids.length; i++) {
    const k = kids[i];
    if (k == null || k === false) continue;
    if (Array.isArray(k)) add(e, k);
    else if (typeof k === 'object') e.appendChild(k);
    else e.appendChild(document.createTextNode(String(k)));
  }
}

export function clear(e) { while (e && e.firstChild) e.removeChild(e.firstChild); return e; }
export function esc(t) {
  return String(t == null ? '' : t).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}
export function pad2(n) { n = Math.floor(Math.abs(n)); return n < 10 ? '0' + n : String(n); }
export function values(o) { const out = []; for (const k in o) if (Object.prototype.hasOwnProperty.call(o, k)) out.push(o[k]); return out; }
export function clamp(v, a, b) { return v < a ? a : v > b ? b : v; }
export function debounce(fn, ms) {
  let t = null;
  const f = function () { const args = arguments, self = this; clearTimeout(t); t = setTimeout(function () { fn.apply(self, args); }, ms); };
  f.cancel = function () { clearTimeout(t); };
  return f;
}
export function wait(ms) { return new Promise(function (r) { setTimeout(r, ms); }); }

// ---------- tempo e datas (sem Intl: o webOS 4 pode não ter os dados de pt-BR) ----------
const DIAS = ['Domingo', 'Segunda', 'Terça', 'Quarta', 'Quinta', 'Sexta', 'Sábado'];
const MESES = ['janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];

export function parseDate(iso) {
  if (!iso) return null;
  if (iso instanceof Date) return iso;
  const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(String(iso));
  if (m) return new Date(Number(m[1]), Number(m[2]) - 1, Number(m[3]));
  const d = new Date(iso);
  return isNaN(d.getTime()) ? null : d;
}
/** 5460 -> "1:31:00"; 754 -> "12:34" */
export function fmtTime(s) {
  s = Math.max(0, Math.floor(s || 0));
  const hh = Math.floor(s / 3600), mm = Math.floor(s % 3600 / 60), ss = s % 60;
  return (hh ? hh + ':' + pad2(mm) : String(mm)) + ':' + pad2(ss);
}
/** 5460 -> "1 h 31 min"; 1680 -> "28 min" */
export function fmtDur(s) {
  s = Math.max(0, Math.round((s || 0) / 60));
  const hh = Math.floor(s / 60), mm = s % 60;
  if (!hh) return mm + ' min';
  return hh + ' h' + (mm ? ' ' + mm + ' min' : '');
}
export function fmtMin(min) { return min ? fmtDur(min * 60) : ''; }
export function clock(d) { d = parseDate(d) || new Date(); return pad2(d.getHours()) + ':' + pad2(d.getMinutes()); }
export function ddmm(d) { d = parseDate(d); return d ? pad2(d.getDate()) + '/' + pad2(d.getMonth() + 1) : '—'; }
export function ddmmyyyy(d) { d = parseDate(d); return d ? ddmm(d) + '/' + d.getFullYear() : '—'; }
export function weekday(d) { d = parseDate(d); return d ? DIAS[d.getDay()] : ''; }
export function monthName(d) { d = parseDate(d); return d ? MESES[d.getMonth()] : ''; }
function startOfDay(d) { const x = new Date(d.getTime()); x.setHours(0, 0, 0, 0); return x; }
export function dayDiff(d, now) {
  d = parseDate(d); if (!d) return null;
  return Math.round((startOfDay(d) - startOfDay(now || new Date())) / 86400000);
}
/** "agora", "há 3 min", "há 2 h", "ontem", "há 4 dias" */
export function ago(d) {
  d = parseDate(d); if (!d) return '';
  const s = (Date.now() - d.getTime()) / 1000;
  if (s < 60) return 'agora';
  if (s < 3600) return 'há ' + Math.floor(s / 60) + ' min';
  const days = -dayDiff(d);
  if (days === 0) return 'há ' + Math.floor(s / 3600) + ' h';
  if (days === 1) return 'ontem';
  return 'há ' + days + ' dias';
}
/** número com ponto de milhar: 2418 -> "2.418" */
export function num(n) {
  n = Math.round(Number(n) || 0);
  return String(n).replace(/\B(?=(\d{3})+(?!\d))/g, '.');
}
export function dec1(n) { return n == null ? '' : String((Math.round(n * 10) / 10).toFixed(1)).replace('.', ','); }

// ---------- ícones (SVG de traço, sem emoji) ----------
const P = {
  play: '<path class="f" d="M7 4.5v15a1 1 0 0 0 1.5.86l12.4-7.5a1 1 0 0 0 0-1.72L8.5 3.64A1 1 0 0 0 7 4.5z"/>',
  pause: '<path class="f" d="M6 4h4v16H6zM14 4h4v16h-4z"/>',
  search: '<circle cx="11" cy="11" r="7.5"/><path d="m20.5 20.5-4.2-4.2"/>',
  bell: '<path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/>',
  plus: '<path d="M5 12h14M12 5v14"/>',
  info: '<circle cx="12" cy="12" r="9.5"/><path d="M12 16v-4.5M12 8h.01"/>',
  chev: '<path d="m9 18 6-6-6-6"/>',
  tv: '<rect x="2.5" y="7" width="19" height="13.5" rx="2"/><path d="m16.5 2.5-4.5 4.5-4.5-4.5"/>',
  check: '<path d="M20 6 9 17l-5-5"/>',
  up: '<path d="M7 10v11M15 5.9 14 10h5.8a2 2 0 0 1 1.9 2.5l-2.3 8A2 2 0 0 1 17.5 22H4a2 2 0 0 1-2-2v-8a2 2 0 0 1 2-2h2.8a2 2 0 0 0 1.8-1.1L12 2a3.1 3.1 0 0 1 3 3.9z"/>',
  down: '<path d="M17 14V3M9 18.1 10 14H4.2a2 2 0 0 1-1.9-2.5l2.3-8A2 2 0 0 1 6.5 2H20a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2h-2.8a2 2 0 0 0-1.8 1.1L12 22a3.1 3.1 0 0 1-3-3.9z"/>',
  gear: '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>',
  back: '<path d="m15 18-6-6 6-6"/>',
  skip: '<path d="m5 4 10 8-10 8z"/><path d="M19 5v14"/>',
  sub: '<rect x="2.5" y="5" width="19" height="14" rx="2"/><path d="M7 15h4M14 15h3M7 11h2M12 11h5"/>',
  server: '<rect x="3" y="4" width="18" height="7" rx="1.5"/><rect x="3" y="13" width="18" height="7" rx="1.5"/><path d="M7 7.5h.01M7 16.5h.01"/>',
  wifi: '<path d="M5 12.55a11 11 0 0 1 14.08 0M1.42 9a16 16 0 0 1 21.16 0M8.53 16.11a6 6 0 0 1 6.95 0M12 20h.01"/>',
  x: '<path d="M18 6 6 18M6 6l12 12"/>',
  heart: '<path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.7l-1-1.1a5.5 5.5 0 0 0-7.8 7.8l1 1.1L12 21l7.8-7.5 1-1.1a5.5 5.5 0 0 0 0-7.8z"/>',
  star: '<path d="m12 2 3.1 6.3 6.9 1-5 4.9 1.2 6.8L12 17.8 5.8 21l1.2-6.8-5-4.9 6.9-1z"/>',
  list: '<path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/>',
  clock: '<circle cx="12" cy="12" r="9.5"/><path d="M12 7v5l3 2"/>',
  user: '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
  refresh: '<path d="M21 12a9 9 0 1 1-2.64-6.36L21 8"/><path d="M21 3v5h-5"/>'
};
export function icon(name, extra) {
  return '<svg class="ic' + (extra ? ' ' + extra : '') + '" viewBox="0 0 24 24" aria-hidden="true">' + (P[name] || '') + '</svg>';
}
export function iconEl(name, extra) { return h('span.icw', { html: icon(name, extra) }); }

// ---------- plataformas ----------
export const PLAT = {
  nfx: { nome: 'Netflix', cor: '#E50914' },
  prv: { nome: 'Prime Video', cor: '#1A98FF' },
  dnp: { nome: 'Disney+', cor: '#3B6CFF' },
  mxx: { nome: 'HBO Max', cor: '#7B5CFF' },
  gop: { nome: 'Globoplay', cor: '#FF5A1F' },
  lke: { nome: 'Looke', cor: '#14B8A6' },
  pmp: { nome: 'Paramount+', cor: '#0064FF' },
  cru: { nome: 'Crunchyroll', cor: '#F47521' },
  atp: { nome: 'Apple TV', cor: '#A1A1AA' }
};
export function platName(c, fallback) { return (PLAT[c] && PLAT[c].nome) || fallback || c; }
export function platColor(c) { return (PLAT[c] && PLAT[c].cor) || '#64748B'; }

/** Gradiente de reserva (enquanto a imagem não chega ou se falhar), estável por nome. */
export function gradOf(t) {
  let x = 0; t = String(t || '');
  for (let i = 0; i < t.length; i++) x = (x * 31 + t.charCodeAt(i)) >>> 0;
  return 'g' + (1 + x % 12);
}

export function iniciais(nome) {
  const p = String(nome || '?').trim().split(/\s+/);
  return ((p[0] || '?').charAt(0) + (p.length > 1 ? p[p.length - 1].charAt(0) : '')).toUpperCase();
}

export function qs(params) {
  const out = [];
  for (const k in params) {
    const v = params[k];
    if (v == null || v === '') continue;
    out.push(encodeURIComponent(k) + '=' + encodeURIComponent(v));
  }
  return out.join('&');
}
