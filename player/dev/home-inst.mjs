// Home (app/index.html) com instalações falsas no helper: confere o aviso "Atualizando".
import { launch } from './harness.mjs';
const out = process.argv[2];
const b = await launch();
const p = await b.newPage();
await p.setViewport({ width: 1920, height: 1080 });
const errs = [];
p.on('pageerror', (e) => errs.push(String(e)));
await p.setRequestInterception(true);
let n = 0;
p.on('request', (r) => {
  const u = r.url();
  if (u.startsWith('http://127.0.0.1:3911/installing')) {
    n++;
    return r.respond({ status: 200, contentType: 'application/json', headers: { 'Access-Control-Allow-Origin': '*' },
      body: JSON.stringify({ apps: [{ id: 'youtube.leanback.v4', step: 'download', progress: Math.min(95, 20 + n * 5) }, { id: 'com.app.desconhecido', step: 'install', progress: null }] }) });
  }
  if (u.startsWith('http://127.0.0.1:3911') || u.startsWith('http://192.168.')) return r.respond({ status: 503, body: '' });
  r.continue();
});
await p.goto(new URL('../../launcher/app/index.html', import.meta.url).href);
await new Promise((s) => setTimeout(s, 4000));
await p.screenshot({ path: out });
const info = await p.evaluate(() => { const e = document.getElementById('instalando'); return { cls: e.className, txt: e.textContent, anim: getComputedStyle(e.querySelector('.gira')).animationName }; });
console.log(JSON.stringify(info), n, errs.slice(0, 5));
await b.close();
