// Trailer do YouTube pela página do helper local da TV (HELPER/trailer?v=<id>[&leg=pt]), num iframe.
// A página usa a YouTube IFrame API e avisa a janela de cima com
//   parent.postMessage({trailer: 'tocando' | 'fim' | 'erro'}, '*')
// (também aceitamos a mesma coisa como texto JSON). Um ouvinte só, instalado na primeira vez; cada iframe
// registrado recebe só as mensagens dele. Iframe que saiu da página é esquecido sozinho.

import { HELPER } from './util.js';

const EVENTOS = { tocando: 1, fim: 1, erro: 1 };
let frames = [];          // [{f: iframe, cb}]
let ouvindo = false;

/** Mensagem -> 'tocando' | 'fim' | 'erro' | null (ignora o que não tiver "trailer"). Pura. */
export function trailerEvento(data) {
  let d = data;
  if (typeof d === 'string') {
    if (d.indexOf('trailer') === -1) return null;
    try { d = JSON.parse(d); } catch (e) { return null; }
  }
  const t = d && typeof d === 'object' ? d.trailer : null;
  return typeof t === 'string' && EVENTOS[t] ? t : null;
}

function vivos() {
  frames = frames.filter(function (x) { return document.body.contains(x.f); });
  return frames;
}
function dono(source) {
  const l = vivos();
  for (let i = 0; i < l.length; i++) {
    const w = l[i].f.contentWindow;
    if (!w || !source) continue;
    if (source === w) return l[i];
    try { if (source.parent === w) return l[i]; } catch (e) { /* outra origem */ }
  }
  // não deu para saber de quem é (helper que manda de outro quadro): com um trailer só na tela, é dele
  return l.length === 1 ? l[0] : null;
}
function ouvir() {
  if (ouvindo) return;
  ouvindo = true;
  window.addEventListener('message', function (ev) {
    const t = trailerEvento(ev && ev.data);
    if (!t) return;
    const x = dono(ev.source);
    if (x) x.cb(t, x.f);
  });
}

/** Cria o iframe do trailer. cb(evento) recebe 'tocando' | 'fim' | 'erro' deste iframe. */
export function trailerFrame(youtube, legenda, cb) {
  ouvir();
  const f = document.createElement('iframe');
  f.setAttribute('allow', 'autoplay; encrypted-media');
  f.setAttribute('frameborder', '0');
  f.onload = function () { setTimeout(function () { f.className = 'ready'; }, 300); };
  f.src = HELPER + '/trailer?v=' + encodeURIComponent(youtube) + (legenda ? '&leg=pt' : '');
  frames.push({ f: f, cb: function (t) { if (t === 'tocando') f.className = 'ready'; if (cb) cb(t); } });
  return f;
}
