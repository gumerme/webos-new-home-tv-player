// HTTP por XMLHttpRequest (o Chromium 53 não tem AbortController; o XHR tem timeout).
// Token vai em ?t= e o POST manda JSON como text/plain: são "simple requests", sem preflight
// CORS (o app roda em file:// na TV). O servidor lê o corpo JSON qualquer que seja o Content-Type.

import { qs } from './util.js';

export const net = {
  base: '',          // http://ip:8080 (descoberto)
  token: '',
  perfil: null,
  onAuthError: null, // (status, body) => void
  onDown: null       // servidor não respondeu
};

export class HttpError extends Error {
  constructor(status, body, msg) {
    super(msg || ('HTTP ' + status));
    this.status = status;
    this.body = body;
  }
}

export function xhr(method, url, body, timeout, type) {
  return new Promise(function (resolve, reject) {
    const r = new XMLHttpRequest();
    try { r.open(method, url, true); } catch (e) { reject(new HttpError(0, null, 'url inválida')); return; }
    r.timeout = timeout || 8000;
    if (body != null) r.setRequestHeader('Content-Type', 'text/plain;charset=UTF-8');
    r.onload = function () {
      let data = r.responseText;
      if (type !== 'text') { try { data = data ? JSON.parse(data) : null; } catch (e) { /* texto */ } }
      if (r.status >= 200 && r.status < 300) resolve(data);
      else reject(new HttpError(r.status, data, (data && data.erro) || ('HTTP ' + r.status)));
    };
    r.onerror = function () { reject(new HttpError(0, null, 'sem conexão')); };
    r.ontimeout = function () { reject(new HttpError(0, null, 'tempo esgotado')); };
    try { r.send(body == null ? null : (typeof body === 'string' ? body : JSON.stringify(body))); }
    catch (e) { reject(new HttpError(0, null, 'falha ao enviar')); }
  });
}

/** URL completa de uma rota /api/play/... com token e perfil. */
export function apiUrl(path, params, withProfile) {
  const p = {};
  for (const k in params || {}) p[k] = params[k];
  if (net.token) p.t = net.token;
  if (withProfile !== false && net.perfil != null && p.perfil == null) p.perfil = net.perfil;
  const q = qs(p);
  return net.base + '/api/play/' + path + (q ? (path.indexOf('?') === -1 ? '?' : '&') + q : '');
}

function wrap(promise) {
  return promise.catch(function (e) {
    if (e && (e.status === 401 || e.status === 403) && net.onAuthError) net.onAuthError(e.status, e.body);
    if (e && e.status === 0 && net.onDown) net.onDown();
    throw e;
  });
}

function noServer() { return Promise.reject(new HttpError(0, null, 'sem servidor')); }

export const api = {
  get(path, params, opts) {
    opts = opts || {};
    if (!net.base) return noServer();
    return wrap(xhr('GET', apiUrl(path, params, opts.perfil), null, opts.timeout || 10000, opts.type));
  },
  post(path, body, opts) {
    opts = opts || {};
    if (!net.base) return noServer();
    const b = {};
    for (const k in body || {}) b[k] = body[k];
    if (opts.perfil !== false && net.perfil != null && b.perfil == null) b.perfil = net.perfil;
    return wrap(xhr('POST', apiUrl(path, null, false), b, opts.timeout || 10000));
  },
  /** Post "dispara e esquece" (progresso, preferências): erros não interrompem nada. */
  fire(path, body) { return api.post(path, body).catch(function () { return null; }); }
};

/** Imagens: URLs relativas do servidor recebem a base. /api/play/img/* não precisa de token. */
export function img(u) {
  if (!u || !net.base) return '';
  if (/^https?:\/\//.test(u)) return u;
  if (u.charAt(0) !== '/') u = '/' + u;
  return net.base + u;
}
/** Outras URLs relativas (legendas): base + token. */
export function srvUrl(u) {
  if (!u) return '';
  if (/^https?:\/\//.test(u)) return u;
  if (u.charAt(0) !== '/') u = '/' + u;
  return net.base + u + (u.indexOf('?') === -1 ? '?' : '&') + 't=' + encodeURIComponent(net.token);
}

export function cssUrl(u) { return u ? 'url("' + String(u).replace(/["\\\n]/g, '') + '")' : ''; }
