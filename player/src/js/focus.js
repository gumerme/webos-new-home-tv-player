// Motor de foco por "camadas" e "zonas".
// Camada = tela base (barra do topo + tela), detalhe, player, modal. As teclas vão para a do topo.
// Zona = um bloco navegável (fileira, grade, lista, botões). Setas andam dentro da zona; quando a
// zona não tem para onde ir, a camada passa para a zona vizinha (links ou ordem do array).

import { KEY } from './util.js';

export const F = { stack: [], cur: null, onAnyKey: null };

export function offsetIn(el, ancestor) {
  let y = 0, x = 0, n = el;
  while (n && n !== ancestor) { y += n.offsetTop; x += n.offsetLeft; n = n.offsetParent; }
  return { x: x, y: y };
}

export class Layer {
  constructor(o) {
    o = o || {};
    this.name = o.name || 'layer';
    this.el = o.el || null;
    this.zones = [];
    this.zi = -1;
    this.scroller = o.scroller || null;
    this.viewH = o.viewH || 1080;
    this.anchor = o.anchor != null ? o.anchor : 150;
    this.scrollY = 0;
    this.onKey = o.onKey || null;       // teclas que a zona não tratou (cores, CH, play...)
    this.onKeyFirst = o.onKeyFirst || null; // camada que trata tudo sozinha (player)
    this.onBack = o.onBack || null;
    this.onScroll = o.onScroll || null;
    this.onFocus = o.onFocus || null;   // (zone, el)
    this.lazyMargin = o.lazyMargin != null ? o.lazyMargin : 700;
  }
  setZones(zones, focusKey, focusIdx) {
    const old = this.zones;
    this.zones = zones.filter(Boolean);
    this.zones.forEach((z) => { z.layer = this; });
    old.forEach((z) => { if (this.zones.indexOf(z) === -1 && z.destroy) z.destroy(); });
    let zi = focusKey != null ? this.indexOf(focusKey) : -1;
    if (zi === -1) zi = this.firstEnterable(0, 1);
    this.zi = zi;
    if (zi !== -1 && focusIdx != null && this.zones[zi].setIndex) this.zones[zi].setIndex(focusIdx);
    if (zi !== -1) this.zones[zi].enter(null, null);
    this.updateLazy();
    if (isTop(this)) paint();
  }
  indexOf(key) {
    if (key && typeof key === 'object') return this.zones.indexOf(key);
    for (let i = 0; i < this.zones.length; i++) if (this.zones[i].key === key) return i;
    return -1;
  }
  zone() { return this.zones[this.zi] || null; }
  firstEnterable(from, step) {
    for (let i = from; i >= 0 && i < this.zones.length; i += step) if (this.zones[i].canEnter()) return i;
    return -1;
  }
  focusZone(key, dir, idx) {
    const i = this.indexOf(key);
    if (i === -1 || !this.zones[i].canEnter()) return false;
    const x = this.zone() ? this.zone().centerX() : null;
    this.zi = i;
    if (idx != null && this.zones[i].setIndex) this.zones[i].setIndex(idx);
    this.zones[i].enter(dir || null, idx != null ? null : x);
    this.updateLazy();
    if (isTop(this)) paint();
    return true;
  }
  neighbor(dir) {
    const z = this.zone();
    if (!z) return -1;
    const link = z.links && z.links[dir];
    if (link === false || link === null) return -1;
    if (link !== undefined) {
      const target = typeof link === 'function' ? link() : link;
      const i = target == null ? -1 : this.indexOf(target);
      return i !== -1 && this.zones[i].canEnter() ? i : -1;
    }
    if (dir === 'up') return this.firstEnterable(this.zi - 1, -1);
    if (dir === 'down') return this.firstEnterable(this.zi + 1, 1);
    return -1;
  }
  move(dir) {
    const z = this.zone();
    if (!z) { const i = this.firstEnterable(0, 1); if (i !== -1) { this.zi = i; this.zones[i].enter(null, null); paint(); } return; }
    if (z.move(dir)) { paint(); return; }
    const i = this.neighbor(dir);
    if (i === -1) return;
    const x = z.centerX();
    this.zi = i;
    this.zones[i].enter(dir, x);
    this.updateLazy();
    paint();
  }
  scrollTo(zone) {
    if (!this.scroller || !zone || !zone.el || !this.scroller.contains(zone.el)) return;
    let t;
    if (zone.scrollY != null) t = typeof zone.scrollY === 'function' ? zone.scrollY() : zone.scrollY;
    else {
      const top = offsetIn(zone.el, this.scroller).y;
      const hh = zone.el.offsetHeight;
      t = top + hh + 40 <= this.viewH ? 0 : top - this.anchor;
      const max = Math.max(0, this.scroller.offsetHeight - this.viewH + 80);
      if (t > max) t = Math.max(max, 0);
      if (t < 0) t = 0;
    }
    if (t !== this.scrollY) {
      this.scrollY = t;
      this.scroller.style.transform = 'translateY(' + (-t) + 'px)';
      this.scroller.style.webkitTransform = 'translateY(' + (-t) + 'px)';
      this.updateLazy();
      if (this.onScroll) this.onScroll(t);
    }
  }
  /** Fileiras perto da área visível montam os cartões; as longe desmontam (memória). */
  updateLazy() {
    if (!this.scroller) { this.zones.forEach(function (z) { if (z.activate) z.activate(); }); return; }
    const top = this.scrollY - this.lazyMargin, bottom = this.scrollY + this.viewH + this.lazyMargin;
    for (let i = 0; i < this.zones.length; i++) {
      const z = this.zones[i];
      if (!z.activate || !z.el) continue;
      if (!this.scroller.contains(z.el)) { z.activate(); continue; }
      const y = offsetIn(z.el, this.scroller).y;
      const near = (y + z.el.offsetHeight >= top && y <= bottom) || i === this.zi;
      if (near) z.activate(); else z.deactivate();
    }
  }
  key(k) {
    if (this.onKeyFirst && this.onKeyFirst(k)) return true;
    const z = this.zone();
    if (k === KEY.LEFT) { this.move('left'); return true; }
    if (k === KEY.RIGHT) { this.move('right'); return true; }
    if (k === KEY.UP) { this.move('up'); return true; }
    if (k === KEY.DOWN) { this.move('down'); return true; }
    if (k === KEY.ENTER) { if (z) z.ok(); return true; }
    if (k === KEY.BACK || k === KEY.ESC || k === KEY.BACKSPACE) {
      if (z && z.back && z.back()) return true;
      if (this.onBack) { this.onBack(); return true; }
      return false;
    }
    if (z && z.handle(k)) { paint(); return true; }
    if (this.onKey && this.onKey(k)) return true;
    return false;
  }
}

function isTop(layer) { return F.stack[F.stack.length - 1] === layer; }
export function top() { return F.stack[F.stack.length - 1] || null; }

export function push(layer) {
  if (F.stack.indexOf(layer) !== -1) F.stack.splice(F.stack.indexOf(layer), 1);
  F.stack.push(layer);
  paint();
}
export function pop(layer) {
  const i = layer ? F.stack.indexOf(layer) : F.stack.length - 1;
  if (i === -1) return;
  F.stack.splice(i, 1);
  paint();
}
export function has(layer) { return F.stack.indexOf(layer) !== -1; }

/** Desenha o anel de foco no elemento atual da camada do topo. */
export function paint() {
  const L = top();
  const z = L && L.zone();
  const el = z ? z.focusEl() : null;
  if (F.cur && F.cur !== el && F.cur.classList) F.cur.classList.remove('foc');
  F.cur = el;
  if (el) el.classList.add('foc');
  if (L && z) {
    L.scrollTo(z);
    if (z.afterPaint) z.afterPaint(el);
    if (L.onFocus) L.onFocus(z, el);
  }
}

export function handleKey(k) {
  if (F.onAnyKey) F.onAnyKey(k);
  const L = top();
  if (!L) return false;
  return L.key(k);
}

/** Estado atual (testes automatizados e depuração). */
export function snapshot() {
  const L = top();
  const z = L && L.zone();
  return {
    layer: L ? L.name : null,
    zone: z ? z.key : null,
    index: z && z.index != null ? z.index() : null,
    text: F.cur ? (F.cur.getAttribute('data-label') || F.cur.textContent || '').replace(/\s+/g, ' ').trim().slice(0, 80) : null,
    layers: F.stack.map(function (l) { return l.name; })
  };
}
