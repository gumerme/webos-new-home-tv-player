// Gera a mídia de teste do servidor simulado com ffmpeg (fonte livre: padrão de teste do próprio
// ffmpeg + tom senoidal). Nada do painel IPTV real.
import { execSync } from 'node:child_process';
import { mkdirSync, existsSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const dir = join(dirname(fileURLToPath(import.meta.url)), 'media');
mkdirSync(join(dir, 'live'), { recursive: true });
const ff = (args) => execSync('ffmpeg -hide_banner -loglevel error -y ' + args, { stdio: 'inherit' });
if (!existsSync(join(dir, 'filme.mp4'))) {
  // 3 min, 640x360, H.264 baseline + AAC, faststart (o <video> busca por Range)
  ff('-f lavfi -i testsrc2=size=640x360:rate=25 -f lavfi -i sine=frequency=440:sample_rate=44100 -t 180 ' +
    '-c:v libx264 -profile:v baseline -pix_fmt yuv420p -b:v 300k -g 50 -c:a aac -b:a 64k -movflags +faststart "' + join(dir, 'filme.mp4') + '"');
}
if (!existsSync(join(dir, 'live', 'seg029.ts'))) {
  // 60 s em 30 segmentos de 2 s; o servidor monta uma playlist "ao vivo" que gira sobre eles
  ff('-f lavfi -i testsrc=size=640x360:rate=25 -f lavfi -i sine=frequency=660:sample_rate=44100 -t 60 ' +
    '-c:v libx264 -profile:v baseline -pix_fmt yuv420p -b:v 300k -g 50 -keyint_min 50 -sc_threshold 0 -c:a aac -b:a 64k ' +
    '-f hls -hls_time 2 -hls_list_size 0 -hls_segment_filename "' + join(dir, 'live', 'seg%03d.ts') + '" "' + join(dir, 'live', 'vod.m3u8') + '"');
}
console.log('mídia pronta em ' + dir);
