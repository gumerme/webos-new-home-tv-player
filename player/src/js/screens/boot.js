// Telas de tela cheia fora do fluxo normal: "Procurando o homeserver…" e "TV bloqueada".
// (Sem escolha de perfil: a casa usa um perfil só. Sem aprovação: TV nova já entra ativa.)

import { h, icon } from '../util.js';
import { Layer, push, pop, has } from '../focus.js';
import { buttonsZone } from '../zones.js';
import { promptModal } from '../ui.js';

function full(cls) {
  const el = h('div.fullscr.' + cls);
  document.getElementById('overlays').appendChild(el);
  return el;
}

// ---------- procurando ----------
export function searchingScreen(o) {
  const el = full('boot');
  const phase = h('div.phase', { text: 'Começando…' });
  const prog = h('div.bar.wide', null, h('b', { style: { width: '0%' } }));
  const b1 = h('span.btn.hi', { text: 'Digitar o endereço' });
  const b2 = h('span.btn', { text: 'Procurar de novo' });
  const b3 = o.hasCache ? h('span.btn', { text: 'Usar a cópia local' }) : null;
  b1._ok = function () {
    promptModal({ title: 'Endereço do homeserver', hint: 'Ex.: 192.168.1.20 ou 192.168.1.20:8080', layout: 'endereco', okLabel: 'Testar', max: 40,
      onDone: function (v) { if (v) o.onAddress(v); } });
  };
  b2._ok = function () { o.onRetry(); };
  if (b3) b3._ok = function () { o.onOffline(); };
  const next = h('div.next');
  el.appendChild(h('div.in', null,
    h('div.bigic', { html: icon('server') }),
    h('h6', { text: 'Procurando o homeserver…' }),
    h('p', { text: 'O endereço do servidor muda de vez em quando. O Player procura sozinho na rede de casa.' }),
    phase, prog, next,
    h('div.btns.c', null, b1, b2, b3)));
  const layer = new Layer({ name: 'procurando', onBack: function () { if (b3) o.onOffline(); } });
  layer.setZones([buttonsZone('botoes', [b1, b2, b3])]);
  push(layer);
  return {
    phase: function (t) { phase.textContent = t; },
    progress: function (a, b) { prog.firstChild.style.width = (b ? a / b * 100 : 0) + '%'; phase.textContent = phase.textContent.replace(/ · \d+ de \d+$/, '') + ' · ' + a + ' de ' + b; },
    next: function (t) { next.textContent = t || ''; },
    close: function () { if (el.parentNode) el.parentNode.removeChild(el); if (has(layer)) pop(layer); }
  };
}

// ---------- aprovação ----------
export function approvalScreen(o) {
  const el = full('boot');
  const code = h('div.code', { text: o.codigo || '----' });
  const st = h('p.st', { text: 'Tentando de novo a cada 10 s…' });
  const b = h('span.btn.hi', { text: 'Verificar agora' });
  b._ok = function () { o.onCheck(); };
  el.appendChild(h('div.in', null,
    h('div.bigic', { html: icon('tv') }),
    h('h6', { text: 'Esta TV está bloqueada' }),
    h('p', { text: 'Ela foi bloqueada no painel Casa › Player › Listas e TVs. Desbloqueie lá para usar.' }),
    code, h('p.dim', { text: (o.nome || 'Esta TV') + ' · WebOS TV Player 2.0' }), st,
    h('div.btns.c', null, b)));
  const layer = new Layer({ name: 'aprovacao', onBack: function () {} });
  layer.setZones([buttonsZone('botoes', [b])]);
  push(layer);
  return {
    code: function (c) { if (c) code.textContent = c; },
    status: function (t) { st.textContent = t; },
    close: function () { if (el.parentNode) el.parentNode.removeChild(el); if (has(layer)) pop(layer); }
  };
}
