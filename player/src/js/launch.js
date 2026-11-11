// Deep links: parâmetros de abertura (PalmSystem.launchParams) e webOSRelaunch.
// Formatos: {abrir:"titulo", id} · {abrir:"tocar", id, temporada, episodio} · {abrir:"continuar"} ·
// {abrir:"canal", id} · v10: {contentTarget:"open?type=m|s&tmdb=&xs=&season=&episode="} ·
// Home 2.0: {contentTarget:"abrir?titulo=ID"} (e tocar=, canal=, continuar=1).

export function parseLaunch(raw) {
  let p = raw;
  if (raw == null) p = (window.PalmSystem && window.PalmSystem.launchParams) || null;
  if (typeof p === 'string') { try { p = JSON.parse(p || '{}'); } catch (e) { p = {}; } }
  if (!p || typeof p !== 'object') return null;
  if (p.abrir) {
    const r = { abrir: String(p.abrir) };
    if (p.id != null) r.id = p.id;
    if (p.temporada != null) r.temporada = parseInt(p.temporada, 10) || null;
    if (p.episodio != null) r.episodio = parseInt(p.episodio, 10) || null;
    if (p.perfil != null) r.perfil = p.perfil;
    return r;
  }
  const target = String(p.contentTarget || p.target || '');
  // Home "WebOS TV" 2.0: só consegue mandar contentTarget (helper /launch?target= e Luna
  // params.contentTarget), então manda "abrir?titulo=ID", "abrir?tocar=ID&temporada=&episodio=",
  // "abrir?canal=ID&nome=" ou "abrir?continuar=1" — o mesmo pedido do formato JSON acima.
  if (target.indexOf('abrir?') === 0) {
    const q = query(target.slice(6));
    const acao = ['titulo', 'tocar', 'canal', 'continuar'].filter(function (a) { return q[a] != null; })[0];
    if (!acao) return null;
    const r = { abrir: acao };
    if (acao !== 'continuar') r.id = /^\d+$/.test(q[acao]) ? parseInt(q[acao], 10) : q[acao];
    if (q.temporada) r.temporada = parseInt(q.temporada, 10) || null;
    if (q.episodio) r.episodio = parseInt(q.episodio, 10) || null;
    if (q.nome) r.nome = q.nome;
    if (q.perfil) r.perfil = q.perfil;
    return r;
  }
  if (target.indexOf('open?') === 0) {
    const q = query(target.slice(5));
    return {
      abrir: 'v10', tipo: q.type === 's' ? 's' : 'm', tmdb: parseInt(q.tmdb, 10) || 0, xs: q.xs || '',
      titulo: q.title || '', temporada: parseInt(q.season, 10) || null, episodio: parseInt(q.episode, 10) || null
    };
  }
  return null;
}

function query(s) {
  const q = {};
  s.split('&').forEach(function (pair) {
    const kv = pair.split('=');
    try { q[kv[0]] = decodeURIComponent((kv[1] || '').replace(/\+/g, ' ')); } catch (e) { /* ignora par ruim */ }
  });
  return q;
}

/** Parâmetros de teste no navegador (modo dev): ?abrir=titulo&id=12 */
export function devLaunch() {
  if (!/^https?:/.test(location.protocol)) return null;
  const m = /[?&]abrir=([^&]+)/.exec(location.search);
  if (!m) return null;
  const get = function (k) { const r = new RegExp('[?&]' + k + '=([^&]+)').exec(location.search); return r ? decodeURIComponent(r[1]) : null; };
  if (m[1] === 'v10') return parseLaunch({ contentTarget: 'open?type=' + (get('type') || 'm') + '&tmdb=' + (get('tmdb') || '') + '&season=' + (get('season') || '') + '&episode=' + (get('episode') || '') });
  return parseLaunch({ abrir: decodeURIComponent(m[1]), id: get('id'), temporada: get('temporada'), episodio: get('episodio') });
}
