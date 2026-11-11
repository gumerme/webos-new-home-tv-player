// Avisos na tela (toast simples e cartão com ações), teclado na tela e janelas modais.

import { h, icon, clear } from './util.js';
import { Layer, push, pop, has } from './focus.js';
import { buttonsZone, MatrixZone } from './zones.js';
import { img, cssUrl } from './net.js';

let toastEl = null, toastTimer = null;
export function toast(text, ms) {
  if (!toastEl) { toastEl = h('div.tmsg', { id: 'toast' }); document.body.appendChild(toastEl); }
  toastEl.textContent = text;
  toastEl.className = 'tmsg show';
  clearTimeout(toastTimer);
  toastTimer = setTimeout(function () { toastEl.className = 'tmsg'; }, ms || 3200);
}

// ---------- cartão no canto com ações (aviso, "continuar aqui") ----------
let current = null;
/**
 * o: { title, text, icon: 'bell', thumb: url, actions: [{label, fn, primary}], timeout (ms) }
 * Recebe o foco enquanto está na tela; Voltar ou o tempo fecham e devolvem o foco.
 */
export function notify(o) {
  closeNotify();
  const side = o.thumb ? h('div.th') : h('div.ico', { html: icon(o.icon || 'bell') });
  if (o.thumb) side.style.backgroundImage = cssUrl(img(o.thumb));
  const btns = (o.actions || [{ label: 'Fechar' }]).map(function (a) {
    const b = h('span.act' + (a.primary ? '.pri' : ''), { text: a.label });
    b._ok = function () { closeNotify(); if (a.fn) a.fn(); };
    return b;
  });
  const el = h('div.notify', null, side, h('div.nb', null, h('b', { text: o.title || '' }),
    o.text ? h('span', { text: o.text }) : null, h('div.acts', null, btns)));
  document.body.appendChild(el);
  const layer = new Layer({ name: 'aviso', onBack: closeNotify });
  layer.setZones([buttonsZone('acoes', btns)]);
  current = { el: el, layer: layer, timer: setTimeout(closeNotify, o.timeout || 15000) };
  push(layer);
  return current;
}
export function closeNotify() {
  if (!current) return;
  const c = current;
  current = null;
  clearTimeout(c.timer);
  if (c.el.parentNode) c.el.parentNode.removeChild(c.el);
  if (has(c.layer)) pop(c.layer);
}

// ---------- teclado na tela ----------
const LETRAS = 'abcdefghijklmnopqrstuvwxyz0123456789'.split('');
const ENDERECO = '1234567890.:abcdefhlmnorstuw/-'.split('');

/**
 * Teclado 6 colunas (letras e números) + linha larga (espaço, apagar, limpar).
 * o: { layout: 'texto'|'endereco', onChange(valor), onKeyFocus(), value, max }
 * Devolve { el, zone, get(), set(v) }.
 */
export function keyboard(o) {
  o = o || {};
  let value = o.value || '';
  const chars = o.layout === 'endereco' ? ENDERECO : LETRAS;
  const keys = [];
  const rows = [];
  let row = [];
  chars.forEach(function (ch) {
    const k = h('span.key', { text: ch, 'data-label': ch });
    k._ok = function () { if (!o.max || value.length < o.max) set(value + ch); };
    keys.push(k); row.push(k);
    if (row.length === 6) { rows.push(row); row = []; }
  });
  if (row.length) rows.push(row);
  const wide = [];
  if (o.layout !== 'endereco') wide.push(mk('espaço', function () { if (value && value.slice(-1) !== ' ') set(value + ' '); }));
  wide.push(mk('apagar', function () { set(value.slice(0, -1)); }));
  wide.push(mk('limpar', function () { set(''); }));
  if (o.okLabel) wide.push(mk(o.okLabel, function () { if (o.onDone) o.onDone(value); }, 'pri'));
  rows.push(wide);
  function mk(label, fn, extra) {
    const k = h('span.key.w' + (extra ? '.' + extra : ''), { text: label, 'data-label': label });
    k._ok = fn;
    return k;
  }
  const grid = h('div.k2', null, rows.map(function (r) { return h('div.krow', null, r); }));
  function set(v) { value = v; if (o.onChange) o.onChange(value); }
  const zone = new MatrixZone({ key: o.key || 'teclado', rows: rows, el: grid, links: o.links });
  return { el: grid, zone: zone, get: function () { return value; }, set: function (v) { value = v || ''; } };
}

/** Janela para digitar um texto (nome do perfil, endereço do servidor). */
export function promptModal(o) {
  const shown = h('div.qq', null, h('span', { text: o.value || '' }), h('i.caret'));
  const kb = keyboard({
    layout: o.layout, value: o.value || '', max: o.max || 40, okLabel: o.okLabel || 'Pronto',
    onChange: function (v) { shown.firstChild.textContent = v; },
    onDone: function (v) { close(); if (o.onDone) o.onDone(v); }
  });
  const el = h('div.modal', null, h('div.mbox', null,
    h('h3', { text: o.title || '' }), o.hint ? h('p.mh', { text: o.hint }) : null, shown, kb.el));
  document.body.appendChild(el);
  const layer = new Layer({ name: 'digitar', onBack: close });
  layer.setZones([kb.zone]);
  push(layer);
  function close() {
    if (el.parentNode) el.parentNode.removeChild(el);
    pop(layer);
    if (o.onClose) o.onClose();
  }
  return { close: close };
}

/** Escolha numa lista (menu simples). */
export function choiceModal(o) {
  const items = o.items.map(function (it) {
    const e = h('div.mitem' + (it.on ? '.on' : ''), { text: it.label });
    e._ok = function () { close(); if (it.fn) it.fn(); };
    return e;
  });
  const el = h('div.modal', null, h('div.mbox.narrow', null, h('h3', { text: o.title || '' }), h('div.mlist', null, items)));
  document.body.appendChild(el);
  const layer = new Layer({ name: 'escolher', onBack: close });
  layer.setZones([new MatrixZone({ key: 'itens', rows: items.map(function (x) { return [x]; }) })]);
  push(layer);
  function close() { if (el.parentNode) el.parentNode.removeChild(el); pop(layer); }
}

export function emptyNote(text) { return h('div.empty', { text: text }); }
export { clear };
