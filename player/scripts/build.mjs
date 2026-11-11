// Build: um único bundle JS para o Chromium 53 (esbuild --target=chrome53) + CSS, fontes,
// hls.js, ícones e appinfo em build/ (a pasta que vira o .ipk).
import { build } from 'esbuild';
import { mkdirSync, rmSync, copyFileSync, readFileSync, writeFileSync, existsSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = join(dirname(fileURLToPath(import.meta.url)), '..');
const out = join(root, 'build');
rmSync(out, { recursive: true, force: true });
mkdirSync(join(out, 'fonts'), { recursive: true });

await build({
  entryPoints: [join(root, 'src/js/main.js')],
  bundle: true,
  format: 'iife',
  target: 'chrome53',
  minify: true,
  legalComments: 'none',
  outfile: join(out, 'app.js'),
  logLevel: 'warning'
});
await build({
  entryPoints: [join(root, 'src/app.css')],
  bundle: true,
  target: 'chrome53',
  minify: true,
  external: ['fonts/*'],
  outfile: join(out, 'app.css'),
  logLevel: 'warning'
});
copyFileSync(join(root, 'src/index.html'), join(out, 'index.html'));
copyFileSync(join(root, 'vendor/hls.min.js'), join(out, 'hls.min.js'));
for (const f of ['icon.png', 'largeIcon.png', 'appinfo.json']) copyFileSync(join(root, f), join(out, f));
const fontDir = join(root, 'node_modules/@fontsource/inter/files');
for (const w of [400, 500, 600, 700, 800]) copyFileSync(join(fontDir, `inter-latin-${w}-normal.woff2`), join(out, 'fonts', `inter-latin-${w}-normal.woff2`));
copyFileSync(join(root, 'node_modules/@fontsource/inter/LICENSE'), join(out, 'fonts', 'LICENSE-Inter-OFL.txt'));
writeFileSync(join(out, 'LICENSES.txt'),
  'WebOS TV Player 2.0\n\n' +
  '- Fonte Inter (pesos 400-800, subconjunto latin): SIL Open Font License 1.1, ver fonts/LICENSE-Inter-OFL.txt (pacote @fontsource/inter).\n' +
  '- hls.js 1.5.15 (hls.min.js): Apache License 2.0, https://github.com/video-dev/hls.js\n');
const size = (f) => (readFileSync(join(out, f)).length / 1024).toFixed(1) + ' KB';
console.log('build/ pronto: app.js ' + size('app.js') + ', app.css ' + size('app.css'));
