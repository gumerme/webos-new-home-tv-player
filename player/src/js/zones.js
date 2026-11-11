// Zonas navegáveis: MatrixZone (elementos já no DOM, em linhas) e RowZone (fileira horizontal
// virtualizada: só os cartões perto do foco ficam montados).

import { h, KEY } from './util.js';
import { offsetIn } from './focus.js';

export class Zone {
  constructor(o) {
    o = o || {};
    this.key = o.key;
    this.el = o.el || null;
    this.links = o.links || {};
    this.layer = null;
    if (o.scrollY != null) this.scrollY = o.scrollY;
  }
  canEnter() { return !!this.focusEl0(); }
  focusEl0() { return null; }
  enter() { return true; }
  move() { return false; }
  focusEl() { return this.focusEl0(); }
  ok() {}
  handle() { return false; }
  centerX() {
    const e = this.focusEl();
    if (!e) return null;
    const r = e.getBoundingClientRect();
    return r.left + r.width / 2;
  }
}

function centerOf(el) { const r = el.getBoundingClientRect(); return r.left + r.width / 2; }

/**
 * rows: [[el, el], [el]] — o elemento pode ter ._ok() e ._key(k).
 * o.onOk(el, r, c), o.onFocus(el, r, c), o.onKey(k, el, r, c) -> bool
 * o.vscroll {box, inner, pad}: mantém a linha focada visível rolando `inner` dentro de `box`.
 * o.hscroll {box, inner, pad}: idem na horizontal (pílulas).
 * o.entry: 'remember' (padrão) | 'first' | 'selected' (usa o.selected())
 */
export class MatrixZone extends Zone {
  constructor(o) {
    super(o);
    this.o = o;
    this.rows = o.rows || [];
    this.r = 0; this.c = 0;
    this.visited = false;
    this.sy = 0; this.sx = 0;
  }
  setRows(rows, keep) {
    this.rows = rows.filter(function (r) { return r && r.length; });
    if (!keep) { this.r = 0; this.c = 0; this.visited = false; this.sy = 0; this.sx = 0; this.applyScroll(); }
    this.fix();
  }
  fix() {
    if (!this.rows.length) { this.r = 0; this.c = 0; return; }
    this.r = Math.max(0, Math.min(this.r, this.rows.length - 1));
    this.c = Math.max(0, Math.min(this.c, this.rows[this.r].length - 1));
  }
  focusEl0() { const row = this.rows[this.r]; return row ? row[this.c] || null : null; }
  index() { let n = 0; for (let i = 0; i < this.r; i++) n += this.rows[i].length; return n + this.c; }
  setIndex(n) {
    for (let i = 0; i < this.rows.length; i++) {
      if (n < this.rows[i].length) { this.r = i; this.c = n; this.visited = true; return; }
      n -= this.rows[i].length;
    }
  }
  /** Foca um elemento específico (se estiver na zona). */
  setEl(el) {
    for (let i = 0; i < this.rows.length; i++) {
      const j = this.rows[i].indexOf(el);
      if (j !== -1) { this.r = i; this.c = j; this.visited = true; return true; }
    }
    return false;
  }
  nearest(row, x) {
    if (x == null) return 0;
    let best = 0, bd = Infinity;
    for (let i = 0; i < row.length; i++) {
      const d = Math.abs(centerOf(row[i]) - x);
      if (d < bd) { bd = d; best = i; }
    }
    return best;
  }
  enter(dir, x) {
    if (!this.rows.length) return false;
    const mode = this.o.entry || 'remember';
    if (mode === 'selected' && this.o.selected) { const el = this.o.selected(); if (el && this.setEl(el)) { this.focused(); return true; } }
    if (mode === 'remember' && this.visited) { this.fix(); this.focused(); return true; }
    if (mode === 'first') { this.r = 0; this.c = 0; }
    else if (dir === 'up') { this.r = this.rows.length - 1; this.c = this.nearest(this.rows[this.r], x); }
    else if (dir === 'down') { this.r = 0; this.c = this.nearest(this.rows[0], x); }
    else if (dir === 'left') { this.c = this.rows[this.r] ? this.rows[this.r].length - 1 : 0; }
    else if (dir === 'right') { this.c = 0; }
    this.fix();
    this.visited = true;
    this.focused();
    return true;
  }
  move(dir) {
    if (!this.rows.length) return false;
    const row = this.rows[this.r];
    if (dir === 'left' || dir === 'right') {
      const c = this.c + (dir === 'left' ? -1 : 1);
      if (c < 0 || c >= row.length) {
        if (this.o.wrapRows && dir === 'right' && this.r + 1 < this.rows.length) { this.r++; this.c = 0; this.focused(); return true; }
        if (this.o.wrapRows && dir === 'left' && this.r > 0) { this.r--; this.c = this.rows[this.r].length - 1; this.focused(); return true; }
        return false;
      }
      this.c = c;
      this.focused();
      return true;
    }
    const r = this.r + (dir === 'up' ? -1 : 1);
    if (r < 0 || r >= this.rows.length) return false;
    const x = centerOf(row[this.c]);
    this.r = r;
    this.c = this.o.sameCol ? Math.min(this.c, this.rows[r].length - 1) : this.nearest(this.rows[r], x);
    this.focused();
    return true;
  }
  focused() {
    const el = this.focusEl0();
    if (!el) return;
    this.keepVisible(el);
    if (this.o.onFocus) this.o.onFocus(el, this.r, this.c);
  }
  keepVisible(el) {
    const v = this.o.vscroll, hs = this.o.hscroll;
    if (v) {
      const pad = v.pad != null ? v.pad : 20;
      const y = offsetIn(el, v.inner).y, hh = el.offsetHeight, boxH = v.box.clientHeight;
      if (y - this.sy < pad) this.sy = Math.max(0, y - pad);
      else if (y + hh - this.sy > boxH - pad) this.sy = y + hh - boxH + pad;
    }
    if (hs) {
      const pad = hs.pad != null ? hs.pad : 20;
      const x = offsetIn(el, hs.inner).x, w = el.offsetWidth, boxW = hs.box.clientWidth;
      if (x - this.sx < pad) this.sx = Math.max(0, x - pad);
      else if (x + w - this.sx > boxW - pad) this.sx = x + w - boxW + pad;
    }
    this.applyScroll();
  }
  applyScroll() {
    const v = this.o.vscroll, hs = this.o.hscroll;
    if (v) v.inner.style.transform = v.inner.style.webkitTransform = 'translateY(' + (-this.sy) + 'px)';
    if (hs) hs.inner.style.transform = hs.inner.style.webkitTransform = 'translateX(' + (-this.sx) + 'px)';
  }
  ok() {
    const el = this.focusEl0();
    if (!el) return;
    if (el._ok) el._ok();
    else if (this.o.onOk) this.o.onOk(el, this.r, this.c);
  }
  handle(k) {
    const el = this.focusEl0();
    if (el && el._key && el._key(k)) return true;
    return !!(this.o.onKey && this.o.onKey(k, el, this.r, this.c));
  }
  back() { return !!(this.o.onBack && this.o.onBack()); }
}

/** Atalho: uma linha de botões. */
export function buttonsZone(key, buttons, o) {
  o = o || {};
  o.key = key;
  o.rows = [buttons.filter(Boolean)];
  return new MatrixZone(o);
}
/** Atalho: lista vertical. */
export function listZone(key, items, o) {
  o = o || {};
  o.key = key;
  o.rows = items.filter(Boolean).map(function (x) { return [x]; });
  return new MatrixZone(o);
}

// ---------- fileira virtualizada ----------
export const KINDS = {
  po: { w: 200, pitch: 218, h: 300, vis: 9 },
  ps: { w: 168, pitch: 186, h: 252, vis: 6 },
  cc: { w: 330, pitch: 352, h: 262, vis: 6 },
  tk: { w: 150, pitch: 164, h: 237, vis: 12 },
  sg: { w: 540, pitch: 562, h: 230, vis: 4 },
  ep: { w: 400, pitch: 422, h: 330, vis: 5 },
  cast: { w: 132, pitch: 150, h: 172, vis: 12 },
  gm: { w: 430, pitch: 452, h: 196, vis: 4 },
  pp: { w: 440, pitch: 462, h: 140, vis: 4 }
};

/**
 * o: { key, title, small, kind, items, render(item, i) -> el, extra: el no cabeçalho, left (px),
 *      onOk(item, i, el), onKey(k, item, i, el) -> bool, onMove(item, i), skip(item) -> bool,
 *      group(item) -> id (CH+/CH- pulam de grupo), cls, noHeader }
 */
export class RowZone extends Zone {
  constructor(o) {
    super(o);
    this.o = o;
    const k = KINDS[o.kind] || KINDS.po;
    this.w = o.w || k.w; this.pitch = o.pitch || k.pitch; this.vis = o.vis || k.vis; this.hgt = o.h || k.h;
    this.items = o.items || [];
    this.idx = this.firstFocusable(0, 1);
    this.first = 0;
    this.active = false;
    this.mounted = new Map();
    this.track = h('div.track', { style: { left: (o.left != null ? o.left : 84) + 'px' } });
    this.strip = h('div.strip', { style: { height: (this.hgt + 24) + 'px' } }, this.track);
    this.head = o.noHeader ? null : h('div.rh', null, h('span.rt', { text: o.title || '' }),
      o.small ? h('small', { text: o.small }) : null, o.extra || null);
    this.el = h('div.row' + (o.cls ? '.' + o.cls : ''), { 'data-zone': o.key }, this.head, this.strip);
    this.place();
  }
  isSkip(i) { const it = this.items[i]; return !it || (this.o.skip && this.o.skip(it)); }
  firstFocusable(from, step) {
    for (let i = from; i >= 0 && i < this.items.length; i += step) if (!this.isSkip(i)) return i;
    return -1;
  }
  canEnter() { return this.idx !== -1; }
  focusEl0() {
    if (this.idx === -1) return null;
    if (!this.mounted.has(this.idx)) { this.active = true; this.sync(); }
    return this.mounted.get(this.idx) || null;
  }
  index() { return this.idx; }
  setIndex(i) { if (i >= 0 && i < this.items.length && !this.isSkip(i)) { this.idx = i; this.place(); } }
  item() { return this.items[this.idx]; }
  setItems(items, keep) {
    const old = this.items[this.idx];
    this.items = items || [];
    this.unmountAll();
    let i = -1;
    if (keep && old) {
      for (let j = 0; j < this.items.length; j++) if (this.items[j] && sameItem(this.items[j], old)) { i = j; break; }
      if (i === -1) i = Math.min(this.idx, this.items.length - 1);
    }
    this.idx = i >= 0 && !this.isSkip(i) ? i : this.firstFocusable(Math.max(0, i), 1);
    if (this.idx === -1) this.idx = this.firstFocusable(this.items.length - 1, -1);
    this.place();
  }
  updateItem(i, item) {
    this.items[i] = item;
    const el = this.mounted.get(i);
    if (el) {
      const n = this.renderAt(i);
      if (el.classList.contains('foc')) n.classList.add('foc');
      el.parentNode.replaceChild(n, el);
      this.mounted.set(i, n);
    }
  }
  place() {
    let f = this.idx < 0 ? 0 : this.idx;
    if (f > 0 && this.isSkip(f - 1) && this.items[f - 1]) f--; // abertura da plataforma aparece antes do nº 1
    const max = Math.max(0, this.items.length - this.vis + 1);
    // fileira curta que cabe inteira na tela não rola
    const fits = this.items.length * this.pitch - (this.pitch - this.w) <= (this.o.viewW || 1920) - (this.o.left != null ? this.o.left : 84) - 40;
    this.first = fits ? 0 : Math.min(f, max);
    const x = -this.first * this.pitch;
    this.track.style.transform = this.track.style.webkitTransform = 'translateX(' + x + 'px)';
    this.sync();
  }
  renderAt(i) {
    const el = this.o.render(this.items[i], i);
    el.style.left = (i * this.pitch) + 'px';
    el.style.width = this.w + 'px';
    return el;
  }
  sync() {
    if (!this.active) return;
    const a = Math.max(0, this.first - 2), b = Math.min(this.items.length - 1, this.first + this.vis + 2);
    const self = this;
    this.mounted.forEach(function (el, i) {
      if (i < a || i > b) { if (el.parentNode) el.parentNode.removeChild(el); self.mounted.delete(i); }
    });
    for (let i = a; i <= b; i++) {
      if (this.mounted.has(i)) continue;
      const el = this.renderAt(i);
      this.track.appendChild(el);
      this.mounted.set(i, el);
    }
  }
  unmountAll() {
    this.mounted.forEach(function (el) { if (el.parentNode) el.parentNode.removeChild(el); });
    this.mounted.clear();
  }
  activate() { if (!this.active) { this.active = true; this.sync(); } }
  deactivate() { if (this.active) { this.active = false; this.unmountAll(); } }
  enter() { if (this.idx === -1) return false; this.activate(); this.focused(); return true; }
  move(dir) {
    if (dir !== 'left' && dir !== 'right') return false;
    const n = this.firstFocusable(this.idx + (dir === 'left' ? -1 : 1), dir === 'left' ? -1 : 1);
    if (n === -1) return false;
    this.idx = n;
    this.place();
    this.focused();
    return true;
  }
  jumpGroup(step) {
    if (!this.o.group || this.idx === -1) return false;
    const g = this.o.group(this.items[this.idx]);
    let i = this.idx;
    if (step > 0) {
      while (i < this.items.length && this.o.group(this.items[i]) === g) i++;
      i = this.firstFocusable(i, 1);
    } else {
      while (i >= 0 && this.o.group(this.items[i]) === g) i--;
      if (i < 0) return false;
      const pg = this.o.group(this.items[i]);
      while (i > 0 && this.o.group(this.items[i - 1]) === pg) i--;
      i = this.firstFocusable(i, 1);
    }
    if (i === -1 || i >= this.items.length) return false;
    this.idx = i;
    this.place();
    this.focused();
    return true;
  }
  focused() { if (this.o.onMove) this.o.onMove(this.items[this.idx], this.idx); }
  ok() {
    if (this.idx === -1) return;
    if (this.o.onOk) this.o.onOk(this.items[this.idx], this.idx, this.mounted.get(this.idx));
  }
  handle(k) {
    if (this.idx === -1) return false;
    if ((k === KEY.CH_UP || k === KEY.CH_DOWN) && this.o.group) return this.jumpGroup(k === KEY.CH_DOWN ? 1 : -1);
    return !!(this.o.onKey && this.o.onKey(k, this.items[this.idx], this.idx, this.mounted.get(this.idx)));
  }
  destroy() { this.unmountAll(); }
}

function sameItem(a, b) {
  if (a === b) return true;
  if (a.id != null && b.id != null) return a.id === b.id && (a.tipo || '') === (b.tipo || '');
  if (a.tmdb != null && b.tmdb != null) return a.tmdb === b.tmdb;
  return false;
}
