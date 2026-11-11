// Reprova o CSS se usar algo que o Chromium 53 (TV 2, webOS 4.10) não tem.
import { readFileSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = join(dirname(fileURLToPath(import.meta.url)), '..');
const files = ['src/app.css', 'build/app.css'];
const rules = [
  [/display\s*:\s*(inline-)?grid/i, 'display:grid'],
  [/grid-template|grid-area|grid-column|grid-row|place-items|place-content/i, 'propriedades de grid'],
  [/(^|[;{\s])(row-|column-|grid-)?gap\s*:/i, 'gap'],
  [/aspect-ratio/i, 'aspect-ratio'],
  [/(^|[;{\s])inset\s*:/i, 'inset'],
  [/position\s*:\s*(-webkit-)?sticky/i, 'position:sticky'],
  [/color-mix\s*\(/i, 'color-mix()'],
  [/clamp\s*\(/i, 'clamp()'],
  [/:focus-visible/i, ':focus-visible'],
  [/:(is|where|has)\s*\(/i, ':is()/:where()/:has()'],
  [/[^-\w](min|max)\s*\(/i, 'min()/max()'],
  [/env\s*\(/i, 'env()'],
  [/#[0-9a-f]{8}\b|#[0-9a-f]{4}\b(?![0-9a-f])/i, 'cor hex de 4/8 dígitos'],
  [/backdrop-filter/i, 'backdrop-filter'],
  [/@container|container-type/i, 'container queries']
];
let bad = 0;
for (const f of files) {
  let css;
  try { css = readFileSync(join(root, f), 'utf8'); } catch { continue; }
  css = css.replace(/\/\*[\s\S]*?\*\//g, '');
  for (const [re, name] of rules) {
    const m = re.exec(css);
    if (m) { bad++; const at = css.slice(Math.max(0, m.index - 50), m.index + 50).replace(/\s+/g, ' '); console.error(`[css] ${f}: ${name} → …${at}…`); }
  }
  // variáveis CSS dentro da condição de @media
  const media = css.match(/@media[^{]*/g) || [];
  for (const m of media) if (/var\(/.test(m)) { bad++; console.error(`[css] ${f}: var() em @media → ${m}`); }
}
if (bad) { console.error(`check-css: ${bad} problema(s) para o Chromium 53`); process.exit(1); }
console.log('check-css: ok (nada de grid, gap, aspect-ratio, inset, sticky, color-mix, clamp, :focus-visible)');
