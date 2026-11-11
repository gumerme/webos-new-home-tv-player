// Gera o .ipk com ares-package em dist/ (não instala em TV nenhuma).
import { execSync } from 'node:child_process';
import { mkdirSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = join(dirname(fileURLToPath(import.meta.url)), '..');
mkdirSync(join(root, 'dist'), { recursive: true });
execSync('ares-package "' + join(root, 'build') + '" -o "' + join(root, 'dist') + '" --no-minify', { stdio: 'inherit', cwd: root });
