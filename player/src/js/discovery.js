// Descoberta do homeserver (contrato: "IPs que mudam"). Nunca depende de IP fixo.
// Ordem: endereço salvo -> homeserver:8080 / homeserver.local:8080 -> IPs já vistos ->
// varredura de 192.168.1.0/24, 192.168.0.0/24 e da sub-rede do último IP (24 em paralelo).

import { lsGet, lsSet } from './store.js';

const TIMEOUT = 1500;
const PARALLEL = 24;

function probe(base) {
  let r = null;
  const promise = new Promise(function (resolve) {
    r = new XMLHttpRequest();
    try { r.open('GET', base + '/api/play/saude', true); } catch (e) { resolve(null); return; }
    r.timeout = TIMEOUT;
    r.onload = function () {
      let d = null;
      try { d = JSON.parse(r.responseText); } catch (e) { d = null; }
      resolve(r.status === 200 && d && d.app === 'casa-play' ? d : null);
    };
    r.onerror = r.ontimeout = r.onabort = function () { resolve(null); };
    try { r.send(); } catch (e) { resolve(null); }
  });
  return { promise: promise, abort: function () { try { r.abort(); } catch (e) { /* já terminou */ } } };
}

export function normalizeBase(s) {
  s = String(s || '').trim().replace(/\/+$/, '');
  if (!s) return '';
  if (!/^https?:\/\//i.test(s)) s = 'http://' + s;
  if (!/:\d+$/.test(s.replace(/^https?:\/\//i, ''))) s += ':8080';
  return s;
}

function remember(base, info) {
  lsSet('servidor', base);
  const ips = lsGet('servidor_ips', []).filter(function (x) { return x !== (info && info.ip); });
  if (info && info.ip) ips.unshift(info.ip);
  lsSet('servidor_ips', ips.slice(0, 5));
  if (info && info.porta) lsSet('servidor_porta', info.porta);
}

/** Testa vários endereços em paralelo; resolve com o primeiro que responder. */
function race(bases, cancelled) {
  if (!bases.length) return Promise.resolve(null);
  return new Promise(function (resolve) {
    let left = bases.length, done = false;
    const probes = bases.map(function (b) { return probe(b); });
    probes.forEach(function (p, i) {
      p.promise.then(function (info) {
        left--;
        if (done) return;
        if (info && !(cancelled && cancelled())) {
          done = true;
          probes.forEach(function (q) { q.abort(); });
          resolve({ base: bases[i], info: info });
        } else if (!left) resolve(null);
      });
    });
  });
}

/** Varredura com no máximo PARALLEL requisições ao mesmo tempo. */
function scan(bases, onProgress, cancelled) {
  return new Promise(function (resolve) {
    let next = 0, running = 0, finished = 0, found = null;
    const live = [];
    function pump() {
      if (found) return;
      if (cancelled && cancelled()) { live.forEach(function (p) { p.abort(); }); resolve(null); return; }
      while (running < PARALLEL && next < bases.length) {
        const base = bases[next++];
        const p = probe(base);
        live.push(p);
        running++;
        p.promise.then(function (info) {
          running--; finished++;
          const at = live.indexOf(p); if (at !== -1) live.splice(at, 1);
          if (found) return;
          if (onProgress) onProgress(finished, bases.length);
          if (info) {
            found = { base: base, info: info };
            live.forEach(function (q) { q.abort(); });
            resolve(found);
            return;
          }
          if (finished >= bases.length) resolve(null); else pump();
        });
      }
    }
    pump();
  });
}

/**
 * opts.override: endereço forçado (modo dev ?servidor=, ou digitado na tela)
 * opts.onPhase(texto), opts.onProgress(feito, total), opts.cancelled()
 * Resolve com a base (http://ip:porta) ou null.
 */
export function discover(opts) {
  opts = opts || {};
  const phase = opts.onPhase || function () {};
  const port = lsGet('servidor_porta', 8080) || 8080;
  const saved = normalizeBase(opts.override || lsGet('servidor', ''));
  const ips = lsGet('servidor_ips', []);

  function ok(hit) { if (hit) remember(hit.base, hit.info); return hit ? hit.base : null; }

  phase('Tentando o endereço salvo');
  return race(saved ? [saved] : [], opts.cancelled).then(function (hit) {
    if (hit) return ok(hit);
    phase('Procurando pelo nome "homeserver"');
    // modo de teste (?semvarredura=1): nem pelo nome — pelo Tailscale "homeserver" acharia o servidor de verdade
    const named = opts.noScan ? [] : ['http://homeserver:' + port, 'http://homeserver.local:' + port];
    if (port !== 8080 && !opts.noScan) named.push('http://homeserver:8080');
    const seen = ips.map(function (ip) { return 'http://' + ip + ':' + port; });
    return race(named.concat(seen), opts.cancelled).then(function (hit2) {
      if (hit2) return ok(hit2);
      if (opts.cancelled && opts.cancelled()) return null;
      if (opts.noScan) { phase('Varredura da rede desligada (modo de teste)'); return null; }
      const nets = ['192.168.1', '192.168.0'];
      const last = ips[0] && /^(\d+\.\d+\.\d+)\.\d+$/.exec(ips[0]);
      if (last && nets.indexOf(last[1]) === -1) nets.unshift(last[1]);
      else if (last) { nets.splice(nets.indexOf(last[1]), 1); nets.unshift(last[1]); }
      const bases = [];
      nets.forEach(function (n) { for (let i = 1; i < 255; i++) bases.push('http://' + n + '.' + i + ':' + port); });
      phase('Procurando na rede de casa (' + nets.map(function (n) { return n + '.x'; }).join(', ') + ')');
      return scan(bases, opts.onProgress, opts.cancelled).then(ok);
    });
  });
}

export function testAddress(base) { return probe(normalizeBase(base)).promise; }
