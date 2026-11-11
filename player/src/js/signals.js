// Sinais em tempo real (SSE): /api/play/sinal?t=&perfil=. O servidor fecha em ~80 s e o
// EventSource reconecta sozinho; se cair de vez, recriamos após 5 s.

import { net } from './net.js';
import { qs } from './util.js';

let es = null, retry = null, handlers = null, wanted = false, onState = null;

export function connect(h, stateCb) {
  handlers = h;
  onState = stateCb || null;
  wanted = true;
  open();
}
function open() {
  close(true);
  if (!wanted || !net.base || !net.token || !window.EventSource) return;
  const url = net.base + '/api/play/sinal?' + qs({ t: net.token, perfil: net.perfil });
  try { es = new EventSource(url); } catch (e) { schedule(); return; }
  es.onopen = function () { if (onState) onState(true); };
  es.onerror = function () {
    if (onState) onState(false);
    if (es && es.readyState === 2) schedule(); // CLOSED: o navegador desistiu
  };
  ['progresso', 'aviso', 'atualizado', 'ping'].forEach(function (name) {
    es.addEventListener(name, function (ev) {
      let data = null;
      try { data = ev.data ? JSON.parse(ev.data) : null; } catch (e) { data = null; }
      if (onState) onState(true);
      if (handlers && handlers[name]) handlers[name](data);
    });
  });
}
function schedule() {
  clearTimeout(retry);
  retry = setTimeout(open, 5000);
}
export function close(keepWanted) {
  clearTimeout(retry);
  if (!keepWanted) wanted = false;
  if (es) { try { es.close(); } catch (e) { /* já fechado */ } es = null; }
}
export function reconnect() { wanted = true; open(); }
