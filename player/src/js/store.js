// Armazenamento local: chaves pequenas no localStorage (prefixo play2_), respostas grandes
// (inicio, seções) no IndexedDB com reserva no localStorage.

const PFX = 'play2_';

export function lsGet(key, fallback) {
  try {
    const raw = localStorage.getItem(PFX + key);
    if (raw == null) return fallback;
    return JSON.parse(raw);
  } catch (e) { return fallback; }
}
export function lsSet(key, value) {
  try {
    if (value == null) localStorage.removeItem(PFX + key);
    else localStorage.setItem(PFX + key, JSON.stringify(value));
  } catch (e) { /* cheio: ignora */ }
}
/** Leitura crua de chaves da v10 (sem prefixo). */
export function lsRaw(key) {
  try { return localStorage.getItem(key); } catch (e) { return null; }
}

// ---------- IndexedDB (cópia local para abrir na hora e funcionar sem servidor) ----------
let dbp = null;
function db() {
  if (dbp) return dbp;
  dbp = new Promise(function (resolve) {
    if (!window.indexedDB) { resolve(null); return; }
    let req;
    try { req = indexedDB.open('play2', 1); } catch (e) { resolve(null); return; }
    req.onupgradeneeded = function () { req.result.createObjectStore('kv'); };
    req.onsuccess = function () { resolve(req.result); };
    req.onerror = function () { resolve(null); };
    req.onblocked = function () { resolve(null); };
  });
  return dbp;
}
export function idbGet(key) {
  return db().then(function (d) {
    if (!d) return lsGet('idb_' + key, null);
    return new Promise(function (resolve) {
      try {
        const r = d.transaction('kv', 'readonly').objectStore('kv').get(key);
        r.onsuccess = function () { resolve(r.result == null ? null : r.result); };
        r.onerror = function () { resolve(null); };
      } catch (e) { resolve(null); }
    });
  });
}
export function idbSet(key, value) {
  return db().then(function (d) {
    if (!d) { lsSet('idb_' + key, value); return; }
    return new Promise(function (resolve) {
      try {
        const tx = d.transaction('kv', 'readwrite');
        if (value == null) tx.objectStore('kv').delete(key); else tx.objectStore('kv').put(value, key);
        tx.oncomplete = function () { resolve(); };
        tx.onerror = function () { resolve(); };
      } catch (e) { resolve(); }
    });
  });
}
