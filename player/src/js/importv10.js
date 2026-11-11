// Importação da v10: na primeira execução com token ativo, lê as chaves que a v10 guardava no
// localStorage (mesma origem: o app substitui a v10 no lugar) e manda para o perfil "Casa".

import { lsRaw, lsGet, lsSet } from './store.js';
import { api } from './net.js';
import { S } from './state.js';

const KEYS = ['play_history', 'play_progress', 'my_list', 'play_ratings', 'play_intros', 'play_versions',
  'live_favs', 'subs_pref', 'subs_size', 'subs_delay', 'continue_hidden', 'tmdb_map'];

export function collectV10() {
  const dados = {};
  let n = 0;
  KEYS.forEach(function (k) {
    const raw = lsRaw(k);
    if (raw == null) return;
    let v;
    try { v = JSON.parse(raw); } catch (e) { v = raw; }
    dados[k] = v;
    n++;
  });
  return n ? dados : null;
}

/** force: Ajustes > importar de novo. Resolve com o resumo do servidor (ou null). */
export function importV10(force) {
  if (!force && lsGet('importado', null)) return Promise.resolve(null);
  const dados = collectV10();
  if (!dados) { lsSet('importado', { em: new Date().toISOString(), vazio: true }); return Promise.resolve(null); }
  const casa = S.perfis.filter(function (p) { return /^casa$/i.test(p.nome); })[0] || S.perfis[0];
  if (!casa) return Promise.resolve(null);
  return api.post('importar', { perfil: casa.id, dados: dados }, { timeout: 60000 }).then(function (r) {
    lsSet('importado', { em: new Date().toISOString(), perfil: casa.id, resumo: (r && r.importados) || null });
    return r;
  }, function () { return null; });
}
