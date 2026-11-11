// Cópia local do Ao vivo (10/11/2026): grupos e canais guardados no IndexedDB a cada resposta do
// servidor. Sem o homeserver, o Ao vivo mostra a última cópia e o canal toca direto no painel
// (formato da conta, ver player.js). Com servidor, uma vez a cada 12 h, todos os grupos são
// guardados em segundo plano (um por vez) para a lista inteira existir mesmo sem nunca ter aberto o grupo.

import { api } from './net.js';
import { idbGet, idbSet, lsGet, lsSet } from './store.js';
import { S } from './state.js';

const COPIA_H = 12;

function chave(path, params) {
  return 'av:' + (S.perfil != null ? S.perfil : 'x') + ':' + path + (params && params.grupo ? ':' + params.grupo : '');
}

/** "Agora" da cópia antiga já passou: tira (sem servidor não há grade nova). */
function semAgoraVencido(lista) {
  if (!Array.isArray(lista)) return lista;
  const now = Date.now();
  return lista.map(function (c) {
    const a = c && c.agora;
    if (!a || !a.fim || Date.parse(a.fim) > now) return c;
    const o = {};
    for (const k in c) o[k] = c[k];
    o.agora = null;
    return o;
  });
}

/** GET do Ao vivo com cópia local: resposta nova é guardada; falha usa a última guardada (S.liveLocal = true). */
export function liveGet(path, params) {
  const k = chave(path, params);
  return api.get(path, params).then(function (d) {
    S.liveLocal = false;
    if (d != null) idbSet(k, { em: Date.now(), d: d });
    return d;
  }, function (e) {
    return idbGet(k).then(function (c) {
      if (!c || c.d == null) throw e;
      S.liveLocal = true;
      S.liveLocalEm = c.em || null;
      return path === 'aovivo/canais' ? semAgoraVencido(c.d) : c.d;
    });
  });
}

let guardando = false;
/** Com servidor: guarda todos os grupos de canais (a cada 12 h, um pedido por vez, 400 ms entre eles). */
export function guardarAoVivo(forcar) {
  if (guardando) return;
  if (!forcar && Date.now() - (lsGet('av_copia_em', 0) || 0) < COPIA_H * 3600000) return;
  guardando = true;
  const perfil = S.perfil;
  api.get('aovivo/grupos').then(function (gs) {
    idbSet(chave('aovivo/grupos'), { em: Date.now(), d: gs });
    const ids = (gs || []).map(function (g) { return g.id; }).filter(function (id) { return id && id !== 'jogos'; });
    let i = 0;
    function proximo() {
      if (S.perfil !== perfil) { guardando = false; return; }   // trocou de perfil: recomeça na próxima vez
      if (i >= ids.length) { lsSet('av_copia_em', Date.now()); guardando = false; return; }
      const params = { grupo: ids[i++] };
      api.get('aovivo/canais', params).then(function (d) {
        idbSet(chave('aovivo/canais', params), { em: Date.now(), d: d });
        setTimeout(proximo, 400);
      }, function () { guardando = false; });
    }
    proximo();
  }, function () { guardando = false; });
}
