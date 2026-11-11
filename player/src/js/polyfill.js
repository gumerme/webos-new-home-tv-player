// Polyfills pequenos para o Chromium 53 (webOS 4.10). O código do app evita essas APIs, mas
// bibliotecas e trechos futuros ficam protegidos.
/* eslint-disable no-extend-native */
if (!Object.values) Object.values = function (o) { return Object.keys(o).map(function (k) { return o[k]; }); };
if (!Object.entries) Object.entries = function (o) { return Object.keys(o).map(function (k) { return [k, o[k]]; }); };
if (!String.prototype.padStart) {
  String.prototype.padStart = function (n, s) {
    let t = String(this); s = s == null ? ' ' : String(s);
    while (t.length < n) t = s + t;
    return t.slice(t.length - Math.max(n, String(this).length));
  };
}
if (typeof Promise !== 'undefined' && !Promise.prototype['finally']) {
  Promise.prototype['finally'] = function (fn) {
    return this.then(function (v) { return Promise.resolve(fn()).then(function () { return v; }); },
      function (e) { return Promise.resolve(fn()).then(function () { throw e; }); });
  };
}
