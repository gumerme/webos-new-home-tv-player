// Ajustes: servidor (e trocar endereço), perfil, legendas automáticas, tamanho da legenda,
// trailer no destaque, esconder títulos fora da lista, animações, conta, versão do app.

import { h, icon, clear, ddmmyyyy, APP_VERSION, OLD_ENGINE, ON_TV } from '../util.js';
import { MatrixZone } from '../zones.js';
import { api, net } from '../net.js';
import { A, S, prefOn } from '../state.js';
import { lsGet, lsSet } from '../store.js';
import { toast, promptModal } from '../ui.js';

const TAM = [['p', 'Pequena'], ['m', 'Média'], ['g', 'Grande']];

export function settingsScreen(ctx) {
  const inner = h('div.seti');
  const box = h('div.setbox', null, inner);
  const el = h('div.scr.settings', null, h('div.gh.full', null, h('b', { text: 'Ajustes' }), h('span', { text: 'OK muda o valor' })), box);
  const zone = new MatrixZone({ key: 'ajustes', el: box, rows: [], scrollY: 0, vscroll: { box: box, inner: inner, pad: 30 } });

  function setPref(chave, valor) {
    S.prefs[chave] = valor;
    lsSet('prefs_' + S.perfil, S.prefs);
    return api.fire('preferencia', { chave: chave, valor: valor });
  }
  function row(label, value, fn, note) {
    const v = h('span.v', { text: value });
    const e = h('div.sr', { 'data-label': label }, h('div', null, h('b', { text: label }), note ? h('small', { text: note }) : null), v);
    if (fn) e._ok = function () { fn(v); }; else e.className += ' ro';
    return e;
  }
  function render(keep) {
    clear(inner);
    const items = [];
    const est = S.estado || {};
    const conta = est.conta || {};
    items.push(h('div.sh', { text: 'Servidor' }));
    items.push(row('Homeserver', (net.base || 'não encontrado') + (S.online ? ' · conectado' : ' · sem conexão'), function () {
      promptModal({ title: 'Endereço do homeserver', hint: 'Ex.: 192.168.1.20 ou 192.168.1.20:8080', layout: 'endereco', okLabel: 'Testar', max: 40,
        value: (net.base || '').replace(/^https?:\/\//, ''), onDone: function (v) { if (v) A.useAddress(v).then(function () { render(true); }); } });
    }, 'O endereço muda sozinho quando o servidor troca de IP'));
    items.push(row('Procurar o servidor de novo', '', function () { A.rediscover(true); }));
    items.push(row('Esta TV', (S.tvNome || '—') + (S.tvStatus ? ' · ' + S.tvStatus : '')));
    items.push(h('div.sh', { text: 'Legendas' }));
    items.push(row('Legendas automáticas', prefOn('legenda_auto', false) ? 'Ligadas' : 'Desligadas', function (v) {
      const on = !prefOn('legenda_auto', false);
      setPref('legenda_auto', on ? 1 : 0); v.textContent = on ? 'Ligadas' : 'Desligadas';
    }, 'Carrega a melhor legenda em português ao começar'));
    const tam = S.prefs.legenda_tamanho || 'm';
    items.push(row('Tamanho da legenda', (TAM.filter(function (t) { return t[0] === tam; })[0] || TAM[1])[1], function (v) {
      const i = TAM.map(function (t) { return t[0]; }).indexOf(S.prefs.legenda_tamanho || 'm');
      const n = TAM[(i + 1) % TAM.length];
      setPref('legenda_tamanho', n[0]); v.textContent = n[1];
    }));
    items.push(h('div.sh', { text: 'Tela' }));
    const trailerOk = ON_TV ? !OLD_ENGINE : !OLD_ENGINE;
    items.push(row('Trailer no destaque', !trailerOk ? 'Não disponível nesta TV' : prefOn('trailer_destaque', true) ? 'Ligado' : 'Desligado', trailerOk ? function (v) {
      const on = !prefOn('trailer_destaque', true);
      setPref('trailer_destaque', on ? 1 : 0); v.textContent = on ? 'Ligado' : 'Desligado';
    } : null, 'Sem som, só na TV com webOS novo'));
    items.push(row('Esconder títulos fora da lista', prefOn('esconder_fora_lista', false) ? 'Sim' : 'Não', function (v) {
      const on = !prefOn('esconder_fora_lista', false);
      setPref('esconder_fora_lista', on ? 1 : 0); v.textContent = on ? 'Sim' : 'Não';
      S.stale = true;
    }, 'No Top 10 e no Ranking'));
    items.push(row('Animações', S.ajustes.animacoes === false ? 'Desligadas' : 'Ligadas', function (v) {
      S.ajustes.animacoes = S.ajustes.animacoes === false;
      lsSet('ajustes', S.ajustes); A.applyAjustes();
      v.textContent = S.ajustes.animacoes === false ? 'Desligadas' : 'Ligadas';
    }));
    items.push(h('div.sh', { text: 'Conta e app' }));
    items.push(row('Lista IPTV', conta.vence_em ? 'Vence em ' + ddmmyyyy(conta.vence_em) + (conta.dias != null ? ' (' + conta.dias + (conta.dias === 1 ? ' dia)' : ' dias)') : '') : '—', null, 'Cadastrada só no painel do homeserver'));
    const imp = lsGet('importado', null);
    items.push(row('Histórico da versão anterior', imp ? 'Importado em ' + ddmmyyyy(imp.em) : 'Não importado', function () {
      A.importV10(true).then(function () { render(true); });
    }, 'Manda para o perfil "Casa" o que a v10 guardava nesta TV'));
    items.push(row('Versão do app', 'WebOS TV Player ' + APP_VERSION + (OLD_ENGINE ? ' · motor antigo' : '')));
    items.forEach(function (x) { inner.appendChild(x); });
    const rows = items.filter(function (x) { return x.className.indexOf('sr') !== -1 && x.className.indexOf('ro') === -1; }).map(function (x) { return [x]; });
    zone.setRows(rows, keep);
    ctx.setZones([zone], keep);
  }
  return { el: el, tab: 'ajustes', scroller: null, load: function (keep) { render(keep); return Promise.resolve(); } };
}
export { icon };
