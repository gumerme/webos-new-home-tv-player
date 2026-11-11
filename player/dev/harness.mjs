// Utilitários de teste: Chrome instalado (puppeteer-core) em 1920x1080, teclas do controle por CDP.
import puppeteer from 'puppeteer-core';
import { mkdtempSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

export const CHROME = process.env.CHROME || 'C:/Program Files/Google/Chrome/Application/chrome.exe';
export const K = { LEFT: 37, UP: 38, RIGHT: 39, DOWN: 40, OK: 13, BACK: 461, RED: 403, GREEN: 404, YELLOW: 405, BLUE: 406, CH_UP: 33, CH_DOWN: 34, PLAY: 415, PAUSE: 19, FF: 417, RW: 412, STOP: 413 };
export const MOCK = process.env.MOCK || 'http://127.0.0.1:8787';

export async function launch() {
  return puppeteer.launch({
    executablePath: CHROME, headless: true,
    userDataDir: mkdtempSync(join(tmpdir(), 'play2-')),
    args: ['--window-size=1920,1080', '--autoplay-policy=no-user-gesture-required', '--mute-audio', '--disable-features=MediaSessionService'],
    defaultViewport: { width: 1920, height: 1080 }
  });
}
/** o.tv: finge a TV (window.PalmSystem antes de o app carregar: ON_TV = true, trailer do destaque ligado). */
export async function open(browser, query, o) {
  const page = await browser.newPage();
  if (o && o.tv) await page.evaluateOnNewDocument(() => { window.PalmSystem = { deviceInfo: '{}', launchParams: '' }; });
  const errors = [];
  page.on('pageerror', (e) => errors.push(String(e && e.message || e)));
  page.on('console', (m) => { if (m.type() === 'error' && !/favicon|status of 40[134]|ERR_UNSAFE_PORT|ERR_CONNECTION_REFUSED/.test(m.text())) errors.push('console: ' + m.text()); });
  page._errors = errors;
  page._cdp = await page.target().createCDPSession();
  await page.goto(MOCK + '/app/index.html?semvarredura=1&servidor=' + encodeURIComponent((query && /servidor=/.test(query)) ? '' : MOCK) + (query ? '&' + query : ''), { waitUntil: 'load' });
  return page;
}
export async function key(page, code, times = 1, gap = 120) {
  for (let i = 0; i < times; i++) {
    await page._cdp.send('Input.dispatchKeyEvent', { type: 'rawKeyDown', windowsVirtualKeyCode: code, nativeVirtualKeyCode: code });
    await page._cdp.send('Input.dispatchKeyEvent', { type: 'keyUp', windowsVirtualKeyCode: code, nativeVirtualKeyCode: code });
    await sleep(gap);
  }
}
export function sleep(ms) { return new Promise((r) => setTimeout(r, ms)); }
export async function snap(page) { return page.evaluate(() => window.__play && window.__play.snapshot()); }
export async function waitFor(page, fn, timeout = 8000, arg) {
  const t0 = Date.now();
  while (Date.now() - t0 < timeout) {
    try { if (await page.evaluate(fn, arg)) return true; } catch (e) { /* recarregando */ }
    await sleep(150);
  }
  return false;
}
export async function waitZone(page, zone, timeout = 8000) {
  return waitFor(page, (z) => { const s = window.__play && window.__play.snapshot(); return s && s.zone === z; }, timeout, zone);
}

const KB = 'abcdefghijklmnopqrstuvwxyz0123456789';
/** Digita no teclado da tela andando com as setas (como no controle). */
export async function typeText(page, text) {
  for (const ch of text) {
    const target = ch === ' ' ? { r: 6, c: 0 } : { r: Math.floor(KB.indexOf(ch) / 6), c: KB.indexOf(ch) % 6 };
    for (let guard = 0; guard < 30; guard++) {
      const s = await snap(page);
      const t = s.text;
      const cur = t === 'espaço' ? { r: 6, c: 0 } : t === 'apagar' ? { r: 6, c: 1 } : t === 'limpar' ? { r: 6, c: 2 } : { r: Math.floor(KB.indexOf(t) / 6), c: KB.indexOf(t) % 6 };
      if (cur.r === target.r && cur.c === target.c) break;
      if (cur.r !== target.r) await key(page, cur.r < target.r ? K.DOWN : K.UP, 1, 60);
      else await key(page, cur.c < target.c ? K.RIGHT : K.LEFT, 1, 60);
    }
    await key(page, K.OK, 1, 80);
  }
}
export async function mock(path) { return (await fetch(MOCK + path)).json(); }
