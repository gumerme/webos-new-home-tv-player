// Servidor simulado do homeserver (contrato homeserver/docs/play-2.0.md) para testar o
// app da TV aqui. Node puro, sem dependências.
//   node dev/mock-server.mjs [--porta 8787] [--pendente] [--eventos]
// Serve também o app (build/) em /app/ e a mídia de teste em /media/.
// Controle de teste: /__mock/pendente?v=1|0, /__mock/aprovar, /__mock/sinal?tipo=progresso|aviso|atualizado,
// /__mock/reset, /__mock/log, /__mock/ultimo (último canal gravado pelo aovivo/tocar), /__mock/notas (Gostei/Não gostei)

import http from 'node:http';
import { readFileSync, existsSync, statSync, createReadStream } from 'node:fs';
import { join, dirname, extname, normalize } from 'node:path';
import { fileURLToPath } from 'node:url';
import * as D from './mock-data.mjs';

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..');
const args = process.argv.slice(2);
const PORT = Number(arg('--porta') || process.env.PORT || 8787);
function arg(n) { const i = args.indexOf(n); return i !== -1 ? args[i + 1] : null; }

let state;
function reset() {
  state = {
    pendente: args.includes('--pendente'),
    tvs: new Map(),           // token -> {id, nome, status, codigo}
    perfis: [{ id: 1, nome: 'Perfil 1', cor: '#1E1B4B', inicial: 'P' }, { id: 2, nome: 'Casa', cor: '#3b0a17', inicial: 'C' }],
    p: {},                    // perfil -> dados
    avisoSeq: 100,
    log: []
  };
}
reset();
for (const [n, t] of [['Reacher', 3], ['Ruptura', 2], ['Meu Nome é Farah', 1], ['The Last of Us', 2]]) { const x = D.find(n, 's'); if (x) x.temporadas = t; }

function perfilData(id) {
  id = Number(id) || 1;
  if (!state.p[id]) {
    const now = Date.now();
    const P = state.p[id] = { lista: new Set(), notas: {}, prog: {}, oculto: new Set(), fav: new Set([1, 3, 9, 15, 19]), avisos: [], prefs: { legenda_auto: 0, legenda_tamanho: 'm', trailer_destaque: 1, esconder_fora_lista: 0 }, versoes: {} };
    ['Duna: Parte Dois', 'The Last of Us', 'Oppenheimer', 'Ruptura', 'Klaus', 'Wandinha', 'Perdido em Marte'].forEach(function (n) { const t = D.find(n); if (t) P.lista.add(t.id); });
    const seed = [
      ['Interestelar', 'm', null, null, 4360, 10140, 'TV 2', now - 2 * 3600e3],
      ['Meu Nome é Farah', 's', 1, 4, 2900, 2900, 'TV 1', now - 26 * 3600e3, true],
      ['Tô Ryca 2', 'm', null, null, 4200, 6000, 'TV 2', now - 28 * 3600e3],
      ['Reacher', 's', 3, 2, 1100, 3440, 'TV 1', now - 50 * 3600e3],
      ['Ruptura', 's', 2, 1, 160, 3300, 'TV 1', now - 80 * 3600e3]
    ];
    seed.forEach(function (s) { const t = D.find(s[0], s[1]); if (t) P.prog[t.id] = { temporada: s[2], episodio: s[3], posicao_s: s[4], duracao_s: s[5], tv: s[6], quando: s[7], fim: !!s[8] }; });
    P.avisos.push({ id: ++state.avisoSeq, tipo: 'chegar', alvo: { tmdb: D.find('Citadel', 's').tmdb, tipo: 's' } });
  }
  return state.p[id];
}

// ---------- formatos ----------
function img(kind, id) {
  return { poster: '/api/play/img/w342/t' + id + '.jpg', fundo: '/api/play/img/w780/f' + id + '.jpg' }[kind];
}
function cartao(t, perfil, extra) {
  const P = perfilData(perfil);
  const pr = P.prog[t.id];
  let progresso = null;
  if (pr && !P.oculto.has(t.id)) {
    if (pr.fim && t.tipo === 's') progresso = { pct: 100, restante_s: 0, temporada: pr.temporada, episodio: pr.episodio + 1, tv: pr.tv, quando: new Date(pr.quando).toISOString(), proximo: true };
    else progresso = { pct: Math.round(pr.posicao_s / pr.duracao_s * 100), restante_s: pr.duracao_s - pr.posicao_s, temporada: pr.temporada, episodio: pr.episodio, tv: pr.tv, quando: new Date(pr.quando).toISOString(), proximo: false };
  }
  const c = {
    id: t.na_lista ? t.id : null, tmdb: t.tmdb, tipo: t.tipo, nome: t.nome, ano: t.ano, poster: img('poster', t.id), fundo: img('fundo', t.id),
    imdb: t.imdb, qualidade: t.na_lista ? t.qualidade : null, na_lista: t.na_lista, plataformas: t.plataformas,
    progresso, selo: null, minha_lista: P.lista.has(t.id),
    aviso_id: t.na_lista ? undefined : ((P.avisos.find(function (a) { return a.tipo === 'chegar' && a.alvo && a.alvo.tmdb === t.tmdb; }) || {}).id || null)
  };
  if (t.tipo === 'm' && t.na_lista) c.xt = { id: 50000 + t.id, ext: 'mp4' };
  return Object.assign(c, extra || {});
}
function many(names, perfil, extra) { return names.map(function (n) { return typeof n === 'string' ? D.find(n) : n; }).filter(Boolean).map(function (t) { return cartao(t, perfil, typeof extra === 'function' ? extra(t) : extra); }); }

function inicio(perfil) {
  const P = perfilData(perfil);
  const destaque = [
    ['Duna: Parte Dois', 'porque você gostou de Interestelar', 'Para você'], ['The Last of Us', 'nº 1 no HBO Max', 'Em alta'],
    ['Pedaço de Mim', 'nº 1 na Netflix', 'Em alta'], ['Reacher', 'nº 1 no Prime Video', 'Em alta'], ['Ainda Estou Aqui', 'nº 1 no Globoplay', 'Em alta']
  ].filter(function (d) { return !P.notas[D.find(d[0]).id]; }).map(function (d) {   // avaliado (Gostei = visto) sai do destaque, como Inicio::rec
    const t = D.find(d[0]);
    return cartao(t, perfil, { sinopse: t.sinopse, motivo: d[1], rotulo: d[2], trailer_youtube: 'Way9Dexny3w', classificacao: t.classificacao, duracao_min: t.duracao_min, temporadas: t.temporadas });
  });
  const continuar = Object.keys(P.prog).map(Number).filter(function (id) { return !P.oculto.has(id); })
    .sort(function (a, b) { return P.prog[b].quando - P.prog[a].quando; }).map(function (id) { return cartao(D.byId(id), perfil, { quando: new Date(P.prog[id].quando).toISOString() }); });
  // último canal do Ao vivo (como Inicio::encaixarCanal): só < 24 h; 1º se mais recente que o 1º título, senão 2º
  const uc = P.ultimoCanal && Date.now() - P.ultimoCanal.em < 86400e3 && D.channel(P.ultimoCanal.id);
  if (uc) {
    const cc = Object.assign(canal(uc, perfil), { tipo: 'canal', quando: new Date(P.ultimoCanal.em).toISOString() });
    const t1 = continuar[0] ? Date.parse(continuar[0].quando) : NaN;
    continuar.splice(isNaN(t1) || P.ultimoCanal.em > t1 ? 0 : 1, 0, cc);
  }
  const top = function (tipo) {
    return D.top10(tipo).map(function (p) { return { codigo: p.codigo, nome: p.nome, itens: p.itens.map(function (x) { return cartao(x.t, perfil, { posicao: x.posicao, mov: x.mov }); }) }; });
  };
  const sagas = D.sagas.map(function (s) {
    const vistos = s.itens.filter(function (t) { return P.prog[t.id] && P.prog[t.id].posicao_s / P.prog[t.id].duracao_s > 0.9; }).length + (s.id === 2 ? 1 : s.id === 1 ? 1 : 0);
    const prox = s.itens[Math.min(vistos, s.itens.length - 1)];
    return { id: s.id, nome: s.nome, vistos, total: s.itens.length, proximo: cartao(prox, perfil), fundo: img('fundo', s.itens[0].id) };
  });
  const selos = { 'Os Salafrários': 'Você pediu', 'Gladiador II': 'Chegou hoje', 'Coringa: Delírio a Dois': 'Ontem', 'Moana 2': '4K' };
  return {
    destaque, continuar, top10: { s: top('s'), m: top('m') },
    top_brasil: { atualizado: new Date().toISOString(), itens: many(['Pedaço de Mim', 'Duna: Parte Dois', 'The Last of Us', 'Reacher', 'Ainda Estou Aqui', 'Andor', 'Oppenheimer', 'Narcos', 'Citadel', 'Gladiador II'], perfil)
      .map(function (c, i) { c.posicao = i + 1; return c; }) },
    recomendados: many(['Oppenheimer', 'Narcos', 'Better Call Saul', 'Perdido em Marte', 'Até que a Sorte nos Separe', 'A Chegada', 'Snowfall', 'Gravidade', 'La Casa de Papel', 'A Origem'], perfil),
    chegou: many(['Os Salafrários', 'Gladiador II', 'Coringa: Delírio a Dois', 'Moana 2', 'Wicked', 'Conclave', 'O Auto da Compadecida', 'Nosferatu'], perfil, function (t) { return { selo: selos[t.nome] || null }; }),
    porque: [{ titulo: 'Porque você assistiu Interestelar', itens: many(['A Chegada', 'Perdido em Marte', 'Oppenheimer', 'Duna', 'A Origem', 'Contato', 'Ad Astra', 'Gravidade'], perfil) }],
    sagas,
    epoca: { titulo: 'Filmes de Natal para esta época', subtitulo: 'de 1º/11 a 6/1', itens: many(['Tudo Bem no Natal que Vem', 'Klaus', 'Esqueceram de Mim', 'Operação Natal', 'O Grinch', 'Um Natal Mágico', 'Simplesmente Amor', 'Crônicas de Natal'], perfil) },
    generos: [
      { nome: 'Comédia', itens: many(['Chorar de Rir', 'O Candidato Honesto', 'Os Farofeiros', 'Minha Mãe é uma Peça', 'Amor Sem Medida', 'Não se Aceitam Devoluções', 'De Pernas pro Ar', 'Cine Holliúdy'], perfil) },
      { nome: 'Ficção científica', itens: many(['Duna', 'A Chegada', 'Contato', 'Ad Astra', 'Gravidade', 'A Origem', 'Perdido em Marte'], perfil) }
    ],
    minha_lista: Array.from(P.lista).map(function (id) { return cartao(D.byId(id), perfil); })
  };
}

function titulo(t, perfil) {
  const P = perfilData(perfil);
  const pr = P.prog[t.id];
  const versoes = [{ id: t.id * 10 + 1, idioma: 'dub', uhd: t.qualidade === '4k' }, { id: t.id * 10 + 2, idioma: 'leg', uhd: false }];
  const similares = D.titles.filter(function (x) { return x.tipo === t.tipo && x.id !== t.id && x.na_lista && x.generos[0] === t.generos[0]; }).slice(0, 10);
  return cartao(t, perfil, {
    sinopse: t.sinopse, generos: t.generos, duracao_min: t.duracao_min, temporadas: t.temporadas, classificacao: t.classificacao,
    diretor: t.tipo === 'm' ? (t.nome.indexOf('Duna') === 0 ? 'Denis Villeneuve' : 'Direção de exemplo') : null,
    criadores: t.tipo === 's' ? ['Criação de exemplo'] : [],
    elenco: t.elenco.map(function (p) { const pp = D.people.get(p.nome); return { tmdb: pp.tmdb, nome: p.nome, personagem: p.personagem, foto: '/api/play/img/w185/p' + pp.tmdb + '.jpg' }; }),
    notas: { imdb: t.imdb, imdb_votos: 120000 + (t.id * 3711) % 600000, rotten: 70 + t.id % 29, jw_gostaram: 80 + t.id % 19 },
    plataformas: t.plataformas, dias_top10: t.plataformas.length ? t.dias_top10 : null,
    versoes, versao_escolhida: P.versoes[t.id] || versoes[0].id, legenda_pronta: t.id % 2 === 0,
    qualidade_testada_em: new Date(Date.now() - (t.id % 3) * 86400e3).toISOString(),
    saga: t.saga ? { id: t.saga.id, nome: t.saga.nome, itens: t.saga.itens.map(function (x) { return cartao(x, perfil); }), vistos: 1 } : null,
    similares: similares.map(function (x) { return cartao(x, perfil); }),
    trailer_youtube: 'Way9Dexny3w', nota_usuario: P.notas[t.id] || 0,
    retomar: pr && !(pr.fim && t.tipo !== 's') ? { versao: versoes[0].id, temporada: pr.temporada, episodio: pr.fim ? pr.episodio + 1 : pr.episodio, posicao_s: pr.fim ? 0 : pr.posicao_s, tv: pr.tv, quando: new Date(pr.quando).toISOString() } : null,
    xs: t.xs
  });
}

// ---------- jogos (relativos a agora) ----------
function jogos(perfil) {
  const now = Date.now();
  const m5 = function (x) { return Math.round(x / 300000) * 300000; };
  const ch = function (n) { return D.channels.find(function (c) { return c.nome === n; }); };
  const P = perfilData(perfil);
  const g = [
    { id: 1, inicio: m5(now - 67 * 60000), status: 'agora', minuto: "2º tempo · 67'", placar: '1 × 1', casa: ['Santos', 'SAN'], fora: ['Corinthians', 'COR'], canal: 'SporTV' },
    { id: 2, inicio: m5(now + 31 * 60000), status: 'depois', casa: ['Flamengo', 'FLA'], fora: ['Palmeiras', 'PAL'], canal: 'Premiere' },
    { id: 3, inicio: m5(now + 46 * 60000), status: 'depois', casa: ['Real Madrid', 'RMA'], fora: ['Barcelona', 'BAR'], canal: 'ESPN' },
    { id: 4, inicio: m5(now - 190 * 60000), status: 'fim', placar: '2 × 0', casa: ['Corinthians', 'COR'], fora: ['São Paulo', 'SAO'], canal: 'Premiere' }
  ];
  return g.map(function (j) {
    const aviso = P.avisos.find(function (a) { return a.tipo === 'jogo' && a.alvo && a.alvo.jogo === j.id; });
    return {
      id: j.id, liga: j.id === 3 ? 'LaLiga' : 'Brasileirão', inicio: new Date(j.inicio).toISOString(), status: j.status, minuto: j.minuto || null, placar: j.placar || null,
      casa: { nome: j.casa[0], sigla: j.casa[1], escudo: '/api/play/img/escudo/' + j.casa[1] + '.png' },
      fora: { nome: j.fora[0], sigla: j.fora[1], escudo: '/api/play/img/escudo/' + j.fora[1] + '.png' },
      canal: canal(ch(j.canal), perfil), aviso_id: aviso ? aviso.id : (j.id === 2 ? 99 : null)
    };
  });
}
function canal(c, perfil) {
  const P = perfilData(perfil);
  const now = Date.now();
  const cur = grade(c, now - 1, now + 1, perfil)[0];
  return { id: c.id, nome: c.nome, numero: c.numero, logo: c.logo, grupo: c.grupo, favorito: P.fav.has(c.id), qualidade: c.qualidade, xt: c.xt,
    agora: cur ? { titulo: cur.titulo, inicio: cur.inicio, fim: cur.fim, pct: Math.round((now - Date.parse(cur.inicio)) / (Date.parse(cur.fim) - Date.parse(cur.inicio)) * 100), descricao: cur.descricao } : null };
}
function grade(c, from, to, perfil) {
  let list = D.schedule(c, from, to);
  const P = perfilData(perfil);
  jogosRaw().forEach(function (j) {
    if (j.canal !== c.nome) return;
    const a = j.inicio, b = j.inicio + 120 * 60000;
    list = list.filter(function (p) { return Date.parse(p.fim) <= a || Date.parse(p.inicio) >= b; });
    list.push({ id: 9000000 + j.id, titulo: j.titulo, inicio: new Date(a).toISOString(), fim: new Date(b).toISOString(), descricao: 'Jogo ao vivo.', aviso_id: null });
  });
  list.sort(function (x, y) { return Date.parse(x.inicio) - Date.parse(y.inicio); });
  return list.filter(function (p) { return Date.parse(p.fim) > from && Date.parse(p.inicio) < to; }).map(function (p) {
    const av = P.avisos.find(function (a) { return a.tipo === 'programa' && a.alvo && a.alvo.programa === p.id; });
    return Object.assign({}, p, { aviso_id: av ? av.id : null });
  });
}
function jogosRaw() {
  const now = Date.now();
  const m5 = function (x) { return Math.round(x / 300000) * 300000; };
  return [
    { id: 1, inicio: m5(now - 67 * 60000), canal: 'SporTV', titulo: 'Santos × Corinthians' },
    { id: 2, inicio: m5(now + 31 * 60000), canal: 'Premiere', titulo: 'Flamengo × Palmeiras' },
    { id: 3, inicio: m5(now + 46 * 60000), canal: 'ESPN', titulo: 'Real Madrid × Barcelona' },
    { id: 4, inicio: m5(now - 190 * 60000), canal: 'Premiere', titulo: 'Corinthians × São Paulo' }
  ];
}

// ---------- imagens (SVG gerado) ----------
const PAL = [['#6b7f3a', '#2c3418', '#0f1309'], ['#c43b52', '#5a1426', '#1b0710'], ['#3b4a5c', '#1b2430', '#0c1016'], ['#e9d7a7', '#8a6a33', '#1d1408'],
  ['#a9c1d1', '#5d7a8c', '#1d2a33'], ['#8f2e1a', '#3c120a', '#120604'], ['#11493f', '#0b2622', '#050e0c'], ['#f0b13c', '#8b4b12', '#23110a'],
  ['#2a2f6b', '#14163a', '#07081a'], ['#5b6e7e', '#24303a', '#0d1216'], ['#d84a2b', '#7a1d10', '#1c0704'], ['#3fb6c9', '#145a6b', '#061a20']];
function hsh(s) { let x = 0; for (let i = 0; i < s.length; i++) x = (x * 31 + s.charCodeAt(i)) >>> 0; return x; }
function xmlEsc(s) { return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;'); }
function wrap(t, n) { const out = []; let line = ''; String(t).split(' ').forEach(function (w) { if ((line + ' ' + w).trim().length > n && line) { out.push(line); line = w; } else line = (line + ' ' + w).trim(); }); if (line) out.push(line); return out.slice(0, 4); }
function svgImage(size, name) {
  const m = /^([a-z]+)([\w-]+)/.exec(name.replace(/\.(jpg|png|webp)$/, '')) || [];
  const kind = m[1], id = m[2];
  let label = '', seed = name, W = 342, H = 513;
  if (kind === 't') { const t = D.byId(Number(id)); label = t ? t.nome : ''; seed = label; }
  else if (kind === 'f') { const t = D.byId(Number(id)); seed = (t ? t.nome : name) + 'f'; W = 1280; H = 720; }
  else if (kind === 'e') { W = 640; H = 360; label = 'E' + id.split('-')[2]; seed = name; }
  else if (kind === 'p') { const p = D.personByTmdb(id); W = H = 185; label = p ? p.nome.split(' ').map(function (x) { return x[0]; }).slice(0, 2).join('') : '?'; seed = label + id; }
  else if (kind === 'c') { const c = D.channel(Number(id)); W = 200; H = 120; label = c ? c.nome : '?'; }
  else if (kind === 'escudo' || size === 'escudo') { W = H = 120; label = name.replace(/\.\w+$/, ''); }
  const p = PAL[hsh(seed) % PAL.length];
  if (size === 'logo') {
    return '<svg xmlns="http://www.w3.org/2000/svg" width="200" height="120" viewBox="0 0 200 120"><rect width="200" height="120" rx="14" fill="#f1f5f9"/><text x="100" y="72" text-anchor="middle" font-family="Arial,Helvetica,sans-serif" font-weight="800" font-size="' + (label.length > 9 ? 22 : 30) + '" fill="' + p[2] + '">' + xmlEsc(label) + '</text></svg>';
  }
  if (size === 'escudo') {
    return '<svg xmlns="http://www.w3.org/2000/svg" width="120" height="120" viewBox="0 0 120 120"><path d="M60 6 L108 22 L102 78 Q90 104 60 116 Q30 104 18 78 L12 22 Z" fill="' + p[0] + '" stroke="' + p[2] + '" stroke-width="6"/><text x="60" y="72" text-anchor="middle" font-family="Arial,Helvetica,sans-serif" font-weight="800" font-size="30" fill="#fff">' + xmlEsc(label) + '</text></svg>';
  }
  let body = '<defs><radialGradient id="g" cx="65%" cy="30%" r="90%"><stop offset="0" stop-color="' + p[0] + '"/><stop offset=".5" stop-color="' + p[1] + '"/><stop offset="1" stop-color="' + p[2] + '"/></radialGradient></defs>' +
    '<rect width="' + W + '" height="' + H + '" fill="url(#g)"/>' +
    '<circle cx="' + (W * 0.7) + '" cy="' + (H * 0.35) + '" r="' + (Math.min(W, H) * 0.28) + '" fill="#fff" opacity=".08"/>' +
    '<circle cx="' + (W * 0.25) + '" cy="' + (H * 0.7) + '" r="' + (Math.min(W, H) * 0.18) + '" fill="#000" opacity=".18"/>';
  if (kind === 't') {
    const lines = wrap(label, 12);
    body += '<rect width="' + W + '" height="' + (H * 0.42) + '" fill="#000" opacity=".22"/>';
    lines.forEach(function (l, i) { body += '<text x="' + W / 2 + '" y="' + (90 + i * 40) + '" text-anchor="middle" font-family="Arial,Helvetica,sans-serif" font-weight="800" font-size="34" fill="#fff">' + xmlEsc(l) + '</text>'; });
  } else if (kind === 'p') {
    body += '<text x="' + W / 2 + '" y="' + (H / 2 + 22) + '" text-anchor="middle" font-family="Arial,Helvetica,sans-serif" font-weight="800" font-size="60" fill="#fff">' + xmlEsc(label) + '</text>';
  } else if (kind === 'e') {
    body += '<text x="24" y="' + (H - 24) + '" font-family="Arial,Helvetica,sans-serif" font-weight="800" font-size="48" fill="#fff" opacity=".85">' + xmlEsc(label) + '</text>';
  }
  return '<svg xmlns="http://www.w3.org/2000/svg" width="' + W + '" height="' + H + '" viewBox="0 0 ' + W + ' ' + H + '">' + body + '</svg>';
}

// ---------- HTTP ----------
function send(res, code, data, type) {
  const body = typeof data === 'string' || Buffer.isBuffer(data) ? data : JSON.stringify(data);
  res.writeHead(code, { 'Content-Type': type || 'application/json; charset=utf-8', 'Access-Control-Allow-Origin': '*', 'Cache-Control': 'no-store' });
  res.end(body);
}
const err = function (res, code, msg, extra) { send(res, code, Object.assign({ erro: msg }, extra || {})); };
function readBody(req) {
  return new Promise(function (resolve) {
    let s = '';
    req.on('data', function (c) { s += c; });
    req.on('end', function () { try { resolve(s ? JSON.parse(s) : {}); } catch (e) { resolve({}); } });
  });
}
const MIME = { '.html': 'text/html; charset=utf-8', '.js': 'application/javascript; charset=utf-8', '.css': 'text/css; charset=utf-8', '.woff2': 'font/woff2', '.png': 'image/png', '.json': 'application/json', '.mp4': 'video/mp4', '.ts': 'video/mp2t', '.m3u8': 'application/vnd.apple.mpegurl', '.txt': 'text/plain; charset=utf-8' };
function serveFile(req, res, file) {
  if (!existsSync(file) || !statSync(file).isFile()) return err(res, 404, 'não encontrado');
  const size = statSync(file).size, type = MIME[extname(file)] || 'application/octet-stream';
  const range = /bytes=(\d*)-(\d*)/.exec(req.headers.range || '');
  const head = { 'Content-Type': type, 'Access-Control-Allow-Origin': '*', 'Accept-Ranges': 'bytes', 'Cache-Control': 'no-store' };
  if (range) {
    const a = range[1] ? Number(range[1]) : 0, b = range[2] ? Number(range[2]) : size - 1;
    res.writeHead(206, Object.assign(head, { 'Content-Range': 'bytes ' + a + '-' + b + '/' + size, 'Content-Length': b - a + 1 }));
    createReadStream(file, { start: a, end: b }).pipe(res);
  } else { res.writeHead(200, Object.assign(head, { 'Content-Length': size })); createReadStream(file).pipe(res); }
}
const T0 = Date.now();
function livePlaylist() {
  const seq = Math.floor((Date.now() - T0) / 2000) + 1000;
  let out = '#EXTM3U\n#EXT-X-VERSION:3\n#EXT-X-TARGETDURATION:2\n#EXT-X-MEDIA-SEQUENCE:' + seq + '\n';
  const disc = Math.floor(seq / 30) - (seq % 30 === 0 ? 1 : 0);
  out += '#EXT-X-DISCONTINUITY-SEQUENCE:' + disc + '\n';
  for (let i = 0; i < 6; i++) {
    const n = seq + i;
    if (n % 30 === 0) out += '#EXT-X-DISCONTINUITY\n';
    out += '#EXTINF:2.000,\nseg' + String(n % 30).padStart(3, '0') + '.ts\n';
  }
  return out;
}
function srt() {
  let s = '';
  for (let i = 0; i < 45; i++) {
    const a = i * 4 + 1, b = a + 3;
    const f = function (x) { return '00:' + String(Math.floor(x / 60)).padStart(2, '0') + ':' + String(x % 60).padStart(2, '0') + ',000'; };
    s += (i + 1) + '\n' + f(a) + ' --> ' + f(b) + '\nLegenda de teste ' + (i + 1) + '\nsegunda linha da fala\n\n';
  }
  return s;
}

// ---------- SSE ----------
const clients = new Set();
function sseSend(res, ev, data) { res.write('event: ' + ev + '\ndata: ' + JSON.stringify(data) + '\n\n'); }
function broadcast(ev, data) { clients.forEach(function (c) { sseSend(c.res, ev, data); }); return clients.size; }

function auth(req, url) {
  let t = url.searchParams.get('t');
  const h = req.headers.authorization || '';
  if (!t && /^Bearer /.test(h)) t = h.slice(7);
  const tv = t && state.tvs.get(t);
  if (!tv) return { code: 401 };
  if (tv.status === 'pendente') return { code: 403, codigo: tv.codigo };
  return { tv };
}

const server = http.createServer(async function (req, res) {
  const url = new URL(req.url, 'http://x');
  const path = decodeURIComponent(url.pathname);
  state.log.push(req.method + ' ' + req.url.replace(/t=[^&]+/, 't=***'));
  if (state.log.length > 300) state.log.shift();
  if (req.method === 'OPTIONS') { res.writeHead(204, { 'Access-Control-Allow-Origin': '*', 'Access-Control-Allow-Headers': 'Authorization, Content-Type', 'Access-Control-Allow-Methods': 'GET, POST, OPTIONS' }); return res.end(); }

  // app e mídia
  if (path === '/' || path === '/app') { res.writeHead(302, { Location: '/app/index.html' }); return res.end(); }
  if (path.indexOf('/app/') === 0) return serveFile(req, res, join(ROOT, 'build', normalize(path.slice(5)).replace(/^(\.\.[\\/])+/, '')));
  if (path === '/media/live/index.m3u8') return send(res, 200, livePlaylist(), MIME['.m3u8']);
  // "painel IPTV" simulado (link montado pela TV com a cópia de GET conta quando o homeserver cai)
  if (/^\/xt\/live\//.test(path)) return send(res, 200, livePlaylist().replace(/seg/g, '/media/live/seg'), MIME['.m3u8']);
  if (path.indexOf('/xt/') === 0) return serveFile(req, res, join(ROOT, 'dev', 'media', 'filme.mp4'));
  if (path.indexOf('/media/') === 0) return serveFile(req, res, join(ROOT, 'dev', 'media', normalize(path.slice(7)).replace(/^(\.\.[\\/])+/, '')));

  // controle dos testes
  if (path.indexOf('/__mock/') === 0) {
    const what = path.slice(8);
    if (what === 'pendente') { state.pendente = url.searchParams.get('v') !== '0'; return send(res, 200, { pendente: state.pendente }); }
    if (what === 'aprovar') { state.tvs.forEach(function (tv) { tv.status = 'aprovada'; }); return send(res, 200, { ok: true }); }
    if (what === 'reset') { reset(); return send(res, 200, { ok: true }); }
    if (what === 'log') return send(res, 200, state.log);
    if (what === 'notas') return send(res, 200, perfilData(1).notas);   // Gostei/Não gostei gravados (perfil 1)
    if (what === 'ultimo') return send(res, 200, { ultimo: perfilData(1).ultimoCanal || null });   // último canal do Ao vivo (perfil 1)
    if (what === 'id') { const t = D.find(url.searchParams.get('nome') || ''); return t ? send(res, 200, { id: t.id, tmdb: t.tmdb, tipo: t.tipo }) : err(res, 404, 'não achei'); }
    if (what === 'sinal') {
      const tipo = url.searchParams.get('tipo') || 'progresso';
      const inter = D.find('Interestelar', 'm');
      const data = tipo === 'progresso' ? { titulo: inter.id, nome: inter.nome, posicao_s: 4360, duracao_s: 10140, tv: 'TV 2', temporada: null, episodio: null }
        : tipo === 'aviso' ? { id: 1, tipo: 'jogo', texto: 'Flamengo × Palmeiras começa em 5 min', subtexto: 'Premiere · aviso que você ligou', acao: { tipo: 'canal', id: 3, nome: 'Premiere' } }
          : { o_que: 'catalogo' };
      return send(res, 200, { enviados: broadcast(tipo, data) });
    }
    return err(res, 404, 'controle desconhecido');
  }

  if (path.indexOf('/api/play/') !== 0) return err(res, 404, 'rota desconhecida');
  const r = path.slice('/api/play/'.length);
  const q = url.searchParams;
  const base = 'http://' + (req.headers.host || '127.0.0.1:' + PORT);

  if (r === 'saude') return send(res, 200, { app: 'casa-play', versao: '2.0.0', servidor: 'homeserver', ip: '127.0.0.1', porta: PORT });
  if (r.indexOf('img/') === 0) {
    const parts = r.split('/');
    return send(res, 200, svgImage(parts[1], parts.slice(2).join('/')), 'image/svg+xml');
  }
  if (r === 'tv/registrar' && req.method === 'POST') {
    const b = await readBody(req);
    const token = 'tk' + Math.random().toString(36).slice(2) + Date.now().toString(36);
    const n = state.tvs.size + 1;
    const codigo = 'AB' + (10 + n);
    state.tvs.set(token, { id: n, nome: 'TV ' + n, status: state.pendente ? 'pendente' : 'aprovada', codigo, info: b });
    return send(res, 200, { token, status: state.pendente ? 'pendente' : 'aprovada', codigo });
  }

  const a = auth(req, url);
  if (a.code === 401) return err(res, 401, 'token inválido');
  if (a.code === 403) return err(res, 403, 'aguardando aprovação', { codigo: a.codigo });
  const tv = a.tv;
  const body = req.method === 'POST' ? await readBody(req) : {};
  const perfil = Number(q.get('perfil') || body.perfil || 1);
  const P = perfilData(perfil);

  if (r === 'sinal') {
    res.writeHead(200, { 'Content-Type': 'text/event-stream', 'Cache-Control': 'no-cache', 'Access-Control-Allow-Origin': '*', Connection: 'keep-alive' });
    res.write('retry: 3000\n\n');
    const c = { res, perfil, tv: tv.nome };
    clients.add(c);
    sseSend(res, 'ping', { ola: true, tv: tv.nome, perfil });
    const ping = setInterval(function () { sseSend(res, 'ping', { t: Date.now() }); }, 20000);
    let demo = null;
    if (args.includes('--eventos')) demo = setTimeout(function () { const t = D.find('Interestelar', 'm'); sseSend(res, 'progresso', { titulo: t.id, nome: t.nome, posicao_s: 4400, duracao_s: 10140, tv: 'TV 2' }); }, 6000);
    const end = setTimeout(function () { res.end(); }, 80000);
    req.on('close', function () { clearInterval(ping); clearTimeout(end); clearTimeout(demo); clients.delete(c); });
    return;
  }
  if (r === 'estado') return send(res, 200, { perfil: 1, tv: { id: tv.id, nome: tv.nome, status: tv.status, perfil: 1 }, conta: { vence_em: '2026-11-26', dias: 18 }, catalogo: { versao: 42, atualizado: new Date(Date.now() - 3 * 3600e3).toISOString() }, avisos_pendentes: 0 });
  if (r === 'conta') return send(res, 200, { servidor: base + '/xt', usuario: 'demo', senha: 'demo', formato: { filme: '{s}/movie/{u}/{p}/{id}.{ext}', serie: '{s}/series/{u}/{p}/{id}.{ext}', canal: '{s}/live/{u}/{p}/{id}.m3u8' } });
  if (r === 'perfis') {
    if (req.method === 'POST') {
      const id = state.perfis.length + 1;
      state.perfis.push({ id, nome: body.nome || 'Perfil ' + id, cor: body.cor || '#1E1B4B', inicial: String(body.nome || 'P').charAt(0).toUpperCase() });
      return send(res, 200, state.perfis);
    }
    return send(res, 200, state.perfis);
  }
  if (r === 'inicio') return send(res, 200, inicio(perfil));
  if (r === 'secao') {
    const tipo = q.get('tipo') || 'm', nome = q.get('nome');
    const pool = D.titles.filter(function (t) { return t.tipo === tipo && t.na_lista; });
    let fileiras = [];
    if (nome === 'top10') fileiras = D.top10(tipo).map(function (p) { return { titulo: 'Top 10 ' + p.nome, codigo: p.codigo, itens: p.itens.map(function (x) { return cartao(x.t, perfil, { posicao: x.posicao, mov: x.mov }); }) }; });
    else if (nome === 'sagas') fileiras = D.sagas.map(function (s) { return { titulo: 'Saga ' + s.nome, itens: s.itens.map(function (t) { return cartao(t, perfil); }) }; });
    else if (nome === '4k') fileiras = [{ titulo: 'Em 4K', itens: pool.filter(function (t) { return t.qualidade === '4k'; }).map(function (t) { return cartao(t, perfil, { selo: '4K' }); }) }];
    else if (nome === 'lancamentos') fileiras = [{ titulo: 'Lançamentos', itens: pool.filter(function (t) { return t.ano >= 2024; }).map(function (t) { return cartao(t, perfil); }) }, { titulo: 'Chegaram esta semana', itens: pool.slice(5, 15).map(function (t) { return cartao(t, perfil); }) }];
    else {
      const gens = {};
      pool.forEach(function (t) { (gens[t.generos[0]] = gens[t.generos[0]] || []).push(t); });
      fileiras = [{ titulo: 'Recomendados para você', itens: pool.slice(0, 12).map(function (t) { return cartao(t, perfil); }) }]
        .concat(Object.keys(gens).slice(0, 5).map(function (g) { return { titulo: g + ' para você', itens: gens[g].map(function (t) { return cartao(t, perfil); }) }; }));
    }
    return send(res, 200, { fileiras: fileiras.filter(function (f) { return f.itens.length; }) });
  }
  if (r === 'suas-series') {
    const now = Date.now(), day = 86400e3;
    const chegaram = [['Reacher', 3, 3, 0], ['Ruptura', 2, 2, 1], ['Beleza Fatal', 1, 9, 1], ['Meu Nome é Farah', 1, 6, 3], ['Andor', 2, 4, 4]].map(function (x) {
      const t = D.find(x[0], 's');
      return cartao(t, perfil, { selo: 'NOVO · T' + x[1] + ':E' + x[2], quando: new Date(now - x[3] * day).toISOString() });
    });
    const proximos = [['Meu Nome é Farah', 1, 7, 1, 'Amanhã', true], ['Reacher', 3, 4, 6, 'Sexta', false], ['The Last of Us', 2, 4, 8, 'Domingo', true], ['Ruptura', 2, 10, null, 'Dezembro', false]].map(function (x) {
      const t = D.find(x[0], 's');
      const av = P.avisos.find(function (a) { return a.tipo === 'episodio' && a.alvo.titulo === t.id && a.alvo.episodio === x[2]; });
      return { data: x[3] != null ? new Date(now + x[3] * day).toISOString().slice(0, 10) : null, rotulo: x[4], serie: cartao(t, perfil), temporada: x[1], episodio: x[2], nome: x[2] === 10 ? 'O último dia' : null, aviso_id: av ? av.id : (x[5] ? 500 + t.id : null), nota: x[3] == null ? 'sem data exata' : null };
    });
    return send(res, 200, { chegaram, proximos });
  }
  if (r === 'categorias') {
    // id numérico (categoria do painel) ou texto "jw:xxx" (gênero do JustWatch), misturados
    const tipo = q.get('tipo') || 'm';
    const pool = D.titles.filter(function (t) { return t.tipo === tipo && t.na_lista; });
    const all = D.categorias.map(function (c) { return { id: c.id, nome: c.nome, total: pool.filter(c.tem).length }; }).filter(function (c) { return c.total > 0; });
    const gosto = ['jw:act', 'jw:cmy', 'jw:drm', 'jw:scf'].map(function (id) { return all.find(function (c) { return c.id === id; }); }).filter(Boolean);
    return send(res, 200, { seu_gosto: gosto, todas: all });
  }
  let m;
  if ((m = /^categoria\/([^/]+)$/.exec(r))) { // o caminho já chega decodificado ("jw%3Adrm" = "jw:drm")
    const tipo = q.get('tipo') || 'm';
    const cat = D.categoria(m[1]);
    if (!cat) return err(res, 404, 'categoria não encontrada');
    const nome = cat.nome;
    let itens = D.titles.filter(function (t) { return t.tipo === tipo && t.na_lista && cat.tem(t); });
    if (q.get('so4k') === '1') itens = itens.filter(function (t) { return t.qualidade === '4k'; });
    if (q.get('esconder_vistos') === '1') itens = itens.filter(function (t) { return !P.prog[t.id]; });
    const ord = q.get('ordem');
    if (ord === 'az') itens.sort(function (x, y) { return x.nome.localeCompare(y.nome, 'pt'); });
    else if (ord === 'nota') itens.sort(function (x, y) { return y.imdb - x.imdb; });
    else if (ord === 'recentes') itens.sort(function (x, y) { return y.ano - x.ano; });
    const pagina = Number(q.get('pagina') || 1);
    return send(res, 200, { nome: nome || '?', total: itens.length, pagina, paginas: Math.max(1, Math.ceil(itens.length / 60)), itens: itens.slice((pagina - 1) * 60, pagina * 60).map(function (t) { return cartao(t, perfil); }) });
  }
  if (r === 'ranking' && process.env.MOCK_RANKING) return send(res, 200, JSON.parse(readFileSync(process.env.MOCK_RANKING.replace('{tipo}', url.searchParams.get('tipo') || 's'), 'utf8')));
  if (r === 'ranking') {
    const tipo = q.get('tipo') || 's';
    return send(res, 200, { atualizado: new Date(Math.floor(Date.now() / 3600e3) * 3600e3).toISOString(), plataformas: D.top10(tipo).map(function (p) { return { codigo: p.codigo, nome: p.nome, itens: p.itens.map(function (x) { return cartao(x.t, perfil, { posicao: x.posicao, mov: x.mov }); }) }; }) });
  }
  if (r === 'titulo/por-tmdb') {
    const t = D.byTmdb(q.get('tmdb')) || (q.get('xs') ? D.titles.find(function (x) { return x.xs === q.get('xs'); }) : null);
    if (!t || !t.na_lista) return err(res, 404, 'não está na lista');
    return send(res, 200, { id: t.id, nome: t.nome, tipo: t.tipo });
  }
  if ((m = /^titulo\/(\d+)(\/(temporadas|apos))?$/.exec(r))) {
    const t = D.byId(Number(m[1]));
    if (!t || !t.na_lista) return err(res, 404, 'título não encontrado');
    if (m[3] === 'temporadas') {
      const seasons = D.seasonsOf(t).map(function (s) {
        return { numero: s.numero, episodios: s.episodios.map(function (e) {
          const pr = P.prog[t.id];
          let pg = { pct: 0, terminado: false };
          if (pr && (s.numero < pr.temporada || (s.numero === pr.temporada && e.numero < pr.episodio))) pg = { pct: 100, terminado: true };
          else if (pr && s.numero === pr.temporada && e.numero === pr.episodio) pg = pr.fim ? { pct: 100, terminado: true } : { pct: Math.round(pr.posicao_s / pr.duracao_s * 100), terminado: false };
          return Object.assign({}, e, { progresso: pg });
        }) };
      });
      return send(res, 200, { temporadas: seasons });
    }
    if (m[3] === 'apos') {
      const sims = D.titles.filter(function (x) { return x.tipo === 'm' && x.na_lista && x.id !== t.id && !P.prog[x.id]; });
      const pick = sims.filter(function (x) { return x.generos[0] === t.generos[0]; });
      const list = (pick.length ? pick : sims).slice(0, 5);
      return send(res, 200, { sugestao: cartao(list[0], perfil, { trailer_youtube: 'Way9Dexny3w', sinopse: list[0].sinopse }), outras: list.slice(1).map(function (x) { return cartao(x, perfil); }) });
    }
    return send(res, 200, titulo(t, perfil));
  }
  if (r === 'tocar') {
    const t = D.byId(Number(q.get('titulo')));
    if (!t) return err(res, 404, 'título não encontrado');
    const pr = P.prog[t.id];
    let temporada = Number(q.get('temporada')) || null, episodio = Number(q.get('episodio')) || null;
    if (t.tipo === 's' && !temporada) { temporada = pr ? pr.temporada : 1; episodio = pr ? (pr.fim ? pr.episodio + 1 : pr.episodio) : 1; }
    let proximo = null, nomeEp = null;
    if (t.tipo === 's') {
      const ss = D.seasonsOf(t);
      const s = ss.find(function (x) { return x.numero === temporada; }) || ss[0];
      const e = s.episodios.find(function (x) { return x.numero === episodio; }) || s.episodios[0];
      nomeEp = e.nome;
      const ne = s.episodios.find(function (x) { return x.numero === episodio + 1; });
      if (ne) proximo = { temporada: s.numero, episodio: ne.numero, nome: ne.nome };
      else { const ns = ss.find(function (x) { return x.numero === temporada + 1; }); if (ns) proximo = { temporada: ns.numero, episodio: 1, nome: ns.episodios[0].nome }; }
    }
    const same = pr && (t.tipo !== 's' || (pr.temporada === temporada && pr.episodio === episodio));
    return send(res, 200, {
      url: base + '/media/filme.mp4', formato: 'mp4', inicio_s: same && !pr.fim ? pr.posicao_s : 0, duracao_s: 180,
      abertura: t.tipo === 's' ? { inicio_s: 8, fim_s: 38 } : null, creditos_s: t.tipo === 's' ? 20 : 30, nome: t.nome,
      proximo, legendas: [{ id: 1, idioma: 'pt-BR', nome: 'Português (Brasil)', url: '/api/play/legenda/1' }],
      legenda_auto: !!Number(P.prefs.legenda_auto), prefs: { tamanho: P.prefs.legenda_tamanho || 'm', atraso_s: Number(P.prefs['legenda_atraso:' + t.id + ':1']) || 0, atrasos: { 1: Number(P.prefs['legenda_atraso:' + t.id + ':1']) || 0 } },
      temporada, episodio, nome_episodio: nomeEp, versao: Number(q.get('versao')) || t.id * 10 + 1, tmdb: t.tmdb, xs: t.xs
    });
  }
  if (r === 'progresso' && req.method === 'POST') {
    const t = D.byId(Number(body.titulo));
    if (t) {
      P.prog[t.id] = { temporada: body.temporada || null, episodio: body.episodio || null, posicao_s: body.posicao_s || 0, duracao_s: body.duracao_s || 1, tv: tv.nome, quando: Date.now(), fim: body.evento === 'fim' };
      P.oculto.delete(t.id);
      clients.forEach(function (c) { if (c.tv !== tv.nome && c.perfil === perfil) sseSend(c.res, 'progresso', { titulo: t.id, nome: t.nome, posicao_s: body.posicao_s, duracao_s: body.duracao_s, tv: tv.nome, temporada: body.temporada, episodio: body.episodio, evento: body.evento, terminado: body.evento === 'fim' }); });
    }
    return send(res, 200, { ok: true });
  }
  if (r === 'lista' && req.method === 'POST') { if (body.acao === 'del') P.lista.delete(Number(body.titulo)); else P.lista.add(Number(body.titulo)); return send(res, 200, { ok: true }); }
  if (r === 'nota' && req.method === 'POST') {
    P.notas[body.titulo] = body.valor;
    // como o servidor: recálculo das recomendações na hora (~segundos) e depois atualizado {o_que:'recomendacoes'}
    setTimeout(function () { clients.forEach(function (c) { if (c.perfil === perfil) sseSend(c.res, 'atualizado', { o_que: 'recomendacoes' }); }); }, 3000);
    return send(res, 200, { ok: true, nota_usuario: body.valor });
  }
  if (r === 'continuar-ocultar' && req.method === 'POST') { P.oculto.add(Number(body.titulo)); return send(res, 200, { ok: true }); }
  if (r === 'versao' && req.method === 'POST') { P.versoes[body.titulo] = body.versao; return send(res, 200, { ok: true }); }
  if (r === 'abertura' && req.method === 'POST') return send(res, 200, { ok: true });
  if (r === 'preferencia' && req.method === 'POST' && body.chave === 'ultimo_canal') { if (body.valor !== null) return err(res, 422, 'ultimo_canal: só null'); P.ultimoCanal = null; return send(res, 200, { ok: true }); }
  if (r === 'preferencia' && req.method === 'POST') { P.prefs[body.chave] = body.valor; return send(res, 200, { ok: true }); }
  if (r === 'preferencias') return send(res, 200, P.prefs);
  if (r === 'importar' && req.method === 'POST') {
    const d = body.dados || {};
    const n = function (k) { const v = d[k]; return v && typeof v === 'object' ? Object.keys(v).length : 0; };
    return send(res, 200, { importados: { historico: n('play_history'), lista: n('my_list'), notas: n('play_ratings'), aberturas: n('play_intros'), versoes: n('play_versions'), favoritos: n('live_favs'), progresso: n('play_progress') } });
  }
  if (r === 'busca') {
    const norm = function (s) { return String(s || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase(); };
    const qq = norm(q.get('q'));
    const lev = function (a, b) { const dp = []; for (let i = 0; i <= a.length; i++) { dp[i] = [i]; for (let j = 1; j <= b.length; j++) dp[i][j] = i ? Math.min(dp[i - 1][j] + 1, dp[i][j - 1] + 1, dp[i - 1][j - 1] + (a[i - 1] === b[j - 1] ? 0 : 1)) : j; } return dp[a.length][b.length]; };
    let pessoas = [], corrigido = null;
    D.people.forEach(function (p) { const nn = norm(p.nome); if (nn.indexOf(qq) !== -1 || (qq.length > 5 && lev(nn, qq) <= 2)) pessoas.push(p); });
    if (pessoas.length === 1 && norm(pessoas[0].nome) !== qq && norm(pessoas[0].nome).indexOf(qq) === -1) corrigido = pessoas[0].nome;
    let tit = D.titles.filter(function (t) { return norm(t.nome).indexOf(qq) !== -1; });
    pessoas.forEach(function (p) { p.titulos.forEach(function (t) { if (tit.indexOf(t) === -1) tit.push(t); }); });
    const canais = D.channels.filter(function (c) { return norm(c.nome).indexOf(qq) !== -1; }).map(function (c) { return canal(c, perfil); });
    return send(res, 200, {
      q: q.get('q'), corrigido,
      pessoas: pessoas.slice(0, 4).map(function (p) { return { tmdb: p.tmdb, nome: p.nome, foto: '/api/play/img/w185/p' + p.tmdb + '.jpg', papel: p.papel, total: p.titulos.filter(function (t) { return t.na_lista; }).length }; }),
      titulos: tit.filter(function (t) { return t.na_lista; }).slice(0, 30).map(function (t) { return cartao(t, perfil); }),
      fora: tit.filter(function (t) { return !t.na_lista; }).slice(0, 12).map(function (t) { return cartao(t, perfil); }),
      canais
    });
  }
  if ((m = /^pessoa\/(\d+)$/.exec(r))) {
    const p = D.personByTmdb(m[1]);
    if (!p) return err(res, 404, 'pessoa não encontrada');
    return send(res, 200, { nome: p.nome, foto: '/api/play/img/w185/p' + p.tmdb + '.jpg', titulos: p.titulos.filter(function (t) { return t.na_lista; }).map(function (t) { return cartao(t, perfil); }), fora: p.titulos.filter(function (t) { return !t.na_lista; }).map(function (t) { return cartao(t, perfil); }) });
  }
  if (r === 'avisos') return send(res, 200, P.avisos);
  if (r === 'aviso' && req.method === 'POST') {
    if (body.tipo === 'chegar' && body.alvo && D.byTmdb(body.alvo.tmdb) && D.byTmdb(body.alvo.tmdb).na_lista) return err(res, 409, 'já está na lista', { titulo: D.byTmdb(body.alvo.tmdb).id }); const id = ++state.avisoSeq; P.avisos.push({ id, tipo: body.tipo, alvo: body.alvo || {}, quando: body.quando || null }); return send(res, 200, { id }); }
  if ((m = /^aviso\/(\d+)\/cancelar$/.exec(r))) { P.avisos = P.avisos.filter(function (a) { return a.id !== Number(m[1]); }); return send(res, 200, { ok: true }); }
  if ((m = /^legenda\/(\d+)$/.exec(r))) return send(res, 200, srt(), 'text/plain; charset=utf-8');
  if (r === 'legendas') return send(res, 200, [{ id: 2, idioma: 'pt-BR', nome: 'Português (Brasil) · outra versão', fonte: 'OpenSubtitles' }, { id: 3, idioma: 'pt-PT', nome: 'Português (Portugal)', fonte: 'OpenSubtitles' }]);

  if (r === 'aovivo/grupos') {
    const g = [{ id: 'jogos', nome: 'Jogos de hoje', total: 4 }, { id: 'fav', nome: 'Favoritos', total: P.fav.size }]
      .concat(D.groups.map(function (x) { return { id: x.id, nome: x.nome, total: D.channels.filter(function (c) { return c.gid === x.id; }).length }; }));
    return send(res, 200, g);
  }
  // MOCK_JOGOS=arquivo.json: jogos de verdade (copiados do servidor) para conferir o desenho
  if (r === 'aovivo/jogos' && process.env.MOCK_JOGOS) return send(res, 200, JSON.parse(readFileSync(process.env.MOCK_JOGOS, 'utf8')));
  if (r === 'aovivo/jogos') return send(res, 200, jogos(perfil));
  if (r === 'aovivo/canais') {
    const gid = q.get('grupo');
    const list = gid === 'fav' ? D.channels.filter(function (c) { return P.fav.has(c.id); }) : D.channels.filter(function (c) { return c.gid === gid; });
    return send(res, 200, list.map(function (c) { return canal(c, perfil); }));
  }
  if (r === 'aovivo/grade') {
    const ids = String(q.get('canais') || '').split(',').map(Number).filter(Boolean);
    const de = Date.parse(q.get('de')) || Date.now() - 3600e3, ate = Date.parse(q.get('ate')) || Date.now() + 3 * 3600e3;
    return send(res, 200, { canais: ids.map(function (id) { const c = D.channel(id); return c ? { id, programas: grade(c, de, ate, perfil) } : null; }).filter(Boolean) });
  }
  if ((m = /^aovivo\/tocar\/(\d+)$/.exec(r))) { const c = D.channel(Number(m[1])); if (c) P.ultimoCanal = { id: c.id, em: Date.now() }; return c ? send(res, 200, { url: base + '/media/live/index.m3u8', formato: 'hls', canal: canal(c, perfil) }) : err(res, 404, 'canal não encontrado'); }
  if (r === 'aovivo/favorito' && req.method === 'POST') { if (body.acao === 'del') P.fav.delete(Number(body.canal)); else P.fav.add(Number(body.canal)); return send(res, 200, { ok: true }); }

  return err(res, 404, 'rota desconhecida: ' + r);
});
server.listen(PORT, '0.0.0.0', function () {
  console.log('Servidor simulado do homeserver em http://127.0.0.1:' + PORT + '  (app: /app/index.html?servidor=http://127.0.0.1:' + PORT + ')');
});
