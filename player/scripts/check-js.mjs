// Reprova o bundle se usar APIs que o Chromium 53 não tem (esbuild só converte sintaxe).
import { readFileSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = join(dirname(fileURLToPath(import.meta.url)), '..');
const js = readFileSync(join(root, 'build/app.js'), 'utf8');
const rules = [
  [/Object\.(entries|values|fromEntries)\(/, 'Object.entries/values/fromEntries'],
  [/\.padStart\(|\.padEnd\(/, 'padStart/padEnd'],
  [/AbortController/, 'AbortController'],
  [/\.flat\(|\.flatMap\(/, 'flat/flatMap'],
  [/\.finally\(/, 'Promise.finally'],
  [/\.replaceAll\(|\.matchAll\(/, 'replaceAll/matchAll'],
  [/\.at\(-?\d/, 'Array.at'],
  [/structuredClone|queueMicrotask|globalThis/, 'structuredClone/queueMicrotask/globalThis'],
  [/\.replaceChildren\(|\.prepend\(|\.toggleAttribute\(/, 'replaceChildren/prepend/toggleAttribute'],
  [/\?\.|\?\?/, 'optional chaining / nullish (sintaxe nova no bundle)'],
  [/scrollIntoView\(\{/, 'scrollIntoView com opções'],
  [/async\s+function|await\s/, 'async/await (deveria ter virado generator)'],
  [/display:\s*grid|aspect-ratio|(^|[;"'\s])inset:/, 'CSS proibido escrito no JS']
];
let bad = 0;
for (const [re, name] of rules) {
  const m = re.exec(js);
  if (m) { bad++; console.error(`[js] ${name} → …${js.slice(Math.max(0, m.index - 60), m.index + 40)}…`); }
}
if (bad) { console.error(`check-js: ${bad} problema(s) para o Chromium 53`); process.exit(1); }
console.log('check-js: ok (bundle chrome53 sem APIs novas conhecidas)');

// Sintaxe: o bundle tem que ser ES2016 puro (Chromium 53 não tem async, spread de objeto,
// vírgula final em chamada, catch sem parâmetro, grupos nomeados/lookbehind em regex...).
import { parse } from 'acorn';
for (const f of ['build/app.js', 'build/hls.min.js']) {
  try { parse(readFileSync(join(root, f), 'utf8'), { ecmaVersion: 2016, sourceType: 'script' }); console.log('check-js: ' + f + ' é ES2016 válido'); }
  catch (e) { console.error('[js] ' + f + ': sintaxe além do ES2016 → ' + e.message); process.exit(1); }
}
