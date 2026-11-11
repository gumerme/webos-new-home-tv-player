// Dados de exemplo do servidor simulado (catálogo, Top 10 por plataforma, canais, jogos).
// Nomes reais de títulos só como exemplo; imagens são geradas (SVG).

export const PLATS = [['nfx', 'Netflix'], ['prv', 'Prime Video'], ['dnp', 'Disney+'], ['mxx', 'HBO Max'], ['gop', 'Globoplay']];

const TOP = {
  s: {
    nfx: ['Pedaço de Mim', 'Round 6', 'Wandinha', 'Stranger Things', 'Os Donos do Jogo', 'Sintonia', 'Bridgerton', 'Ginny & Georgia', 'Emily em Paris', 'DNA do Crime'],
    prv: ['Reacher', 'The Boys', 'Fallout', 'Invencível', 'Gen V', 'Os Anéis de Poder', 'Bosch: Legado', 'Citadel', 'Jack Ryan', 'Upload'],
    dnp: ['Andor', 'O Urso', 'Percy Jackson', 'Demolidor', 'Only Murders', 'Shōgun', 'Bluey', 'Ahsoka', 'Loki', 'Agatha'],
    mxx: ['The Last of Us', 'Beleza Fatal', 'A Casa do Dragão', 'The White Lotus', 'Pinguim', 'Euphoria', 'Duna: A Profecia', 'The Pitt', 'Succession', 'Hacks'],
    gop: ['Vale Tudo', 'Mania de Você', 'Os Outros', 'Arcanjo Renegado', 'Justiça 2', 'Guerreiros do Sol', 'Rensga Hits!', "Encantado's", 'Hit Parade', 'O Rei do Gado']
  },
  m: {
    nfx: ['Alerta Vermelho', 'Agente Oculto', 'Klaus', 'Bird Box', 'Resgate 2', 'Atlas', 'Não Olhe para Cima', 'O Poço 2', 'Rebel Moon', 'Pai em Dobro'],
    prv: ['Tô Ryca 2', 'Interestelar', 'Road House', 'O Contador 2', 'A Guerra do Amanhã', 'Sem Remorso', 'Os Farofeiros 2', 'Missão: Impossível', 'Saltburn', 'Cinderela'],
    dnp: ['Divertida Mente 2', 'Moana 2', 'Deadpool & Wolverine', 'Avatar 2', 'Elementos', 'Encanto', 'Free Guy', 'Viva', 'Red', 'Soul'],
    mxx: ['Duna: Parte Dois', 'Coringa', 'Barbie', 'Godzilla e Kong', 'Furiosa', 'Beetlejuice 2', 'Wonka', 'Batman', 'Aquaman 2', 'Matrix'],
    gop: ['Ainda Estou Aqui', 'Auto da Compadecida 2', 'Minha Irmã e Eu', 'Os Farofeiros', 'Tropa de Elite', 'Cidade de Deus', 'Chorar de Rir', 'Cine Holliúdy', 'Bacurau', 'Central do Brasil']
  }
};
export const OFF = new Set(['Os Donos do Jogo', 'Ginny & Georgia', 'Citadel', 'Agatha', 'Hacks', 'Hit Parade', 'Rebel Moon', 'Saltburn', 'Red', 'Matrix', 'Bacurau', 'O Amor Dá Trabalho', 'Ricos de Amor']);
const MOVES = ['▲1', 'NOVO', '▼1', '=', '▲4', '▼2', '=', '▲2', '▼3', '='];

const EXTRA_M = [
  ['Oppenheimer', 2023, ['Drama', 'História']], ['Perdido em Marte', 2015, ['Ficção científica']], ['Até que a Sorte nos Separe', 2012, ['Comédia']],
  ['A Chegada', 2016, ['Ficção científica', 'Drama']], ['Gravidade', 2013, ['Ficção científica']], ['Os Salafrários', 2024, ['Comédia']],
  ['Gladiador II', 2024, ['Ação', 'Aventura']], ['Coringa: Delírio a Dois', 2024, ['Drama']], ['Wicked', 2024, ['Musical', 'Fantasia']],
  ['Conclave', 2024, ['Drama', 'Suspense']], ['O Auto da Compadecida', 2000, ['Comédia']], ['Nosferatu', 2024, ['Terror']],
  ['Duna', 2021, ['Ficção científica', 'Aventura']], ['A Origem', 2010, ['Ficção científica', 'Ação']], ['Contato', 1997, ['Ficção científica']],
  ['Ad Astra', 2019, ['Ficção científica']], ['Tudo Bem no Natal que Vem', 2020, ['Comédia', 'Natal']], ['Klaus', 2019, ['Animação', 'Natal']],
  ['Esqueceram de Mim', 1990, ['Comédia', 'Natal']], ['Operação Natal', 2024, ['Ação', 'Natal']], ['O Grinch', 2018, ['Animação', 'Natal']],
  ['Um Natal Mágico', 2017, ['Família', 'Natal']], ['Simplesmente Amor', 2003, ['Romance', 'Natal']], ['Crônicas de Natal', 2018, ['Aventura', 'Natal']],
  ['O Candidato Honesto', 2014, ['Comédia']], ['Minha Mãe é uma Peça', 2013, ['Comédia']], ['Amor Sem Medida', 2021, ['Comédia', 'Romance']],
  ['Não se Aceitam Devoluções', 2013, ['Comédia']], ['De Pernas pro Ar', 2010, ['Comédia']], ['Velozes e Furiosos', 2001, ['Ação']],
  ['Velozes e Furiosos: Desafio em Tóquio', 2006, ['Ação']], ['+ Velozes + Furiosos', 2003, ['Ação']], ['Missão: Impossível - Efeito Fallout', 2018, ['Ação']],
  ['Missão: Impossível - Acerto de Contas', 2023, ['Ação']], ['O Amor Dá Trabalho', 2025, ['Comédia']], ['Ricos de Amor', 2020, ['Comédia', 'Romance']],
  ['Vai que Cola', 2015, ['Comédia']], ['Meu Passado me Condena', 2013, ['Comédia']], ['Os Farofeiros 3', 2025, ['Comédia']]
];
const EXTRA_S = [
  ['Meu Nome é Farah', 2025, ['Drama', 'Suspense']], ['Ruptura', 2022, ['Ficção científica', 'Suspense']], ['Narcos', 2015, ['Crime', 'Drama']],
  ['Better Call Saul', 2015, ['Crime', 'Drama']], ['Snowfall', 2017, ['Crime', 'Drama']], ['La Casa de Papel', 2017, ['Ação', 'Crime']]
];
const SINOPSES = {
  'Duna: Parte Dois': 'Paul Atreides se une a Chani e aos Fremen enquanto busca vingança contra os conspiradores que destruíram sua família.',
  'Interestelar': 'Com a Terra à beira do colapso, um grupo de exploradores atravessa um buraco de minhoca em busca de um novo lar para a humanidade.',
  'The Last of Us': 'Vinte anos depois da queda da civilização, Joel é contratado para tirar Ellie de uma zona de quarentena. O que começa como um trabalho pequeno vira uma jornada brutal.',
  'Reacher': 'Jack Reacher, ex-investigador da polícia do exército, chega a uma cidade pequena e se vê no meio de uma conspiração.',
  'Tô Ryca 2': 'Selminha descobre que a herança tem mais dívidas do que dinheiro e precisa gastar tudo de novo para não perder o que conquistou.',
  'Pedaço de Mim': 'Uma mulher descobre que está grávida de gêmeos de pais diferentes e vê a vida virar de cabeça para baixo.',
  'Ainda Estou Aqui': 'No Rio de Janeiro dos anos 70, Eunice Paiva reconstrói a família depois que o marido é levado pela ditadura.',
  'Andor': 'Cassian Andor entra na rebelião contra o Império numa história de espiões, sacrifícios e coragem.'
};
const ELENCO = {
  'Duna: Parte Dois': ['Timothée Chalamet|Paul Atreides', 'Zendaya|Chani', 'Rebecca Ferguson|Jessica', 'Javier Bardem|Stilgar', 'Austin Butler|Feyd-Rautha'],
  'Duna': ['Timothée Chalamet|Paul Atreides', 'Zendaya|Chani', 'Rebecca Ferguson|Jessica', 'Oscar Isaac|Leto'],
  'Wonka': ['Timothée Chalamet|Willy Wonka', 'Hugh Grant|Oompa-Loompa'],
  'Interestelar': ['Matthew McConaughey|Cooper', 'Anne Hathaway|Brand', 'Jessica Chastain|Murph', 'Michael Caine|Prof. Brand'],
  'Tudo Bem no Natal que Vem': ['Leandro Hassum|Jorge', 'Elisa Pinheiro|Laura'],
  'Até que a Sorte nos Separe': ['Leandro Hassum|Tino', 'Danielle Winits|Jane'],
  'O Candidato Honesto': ['Leandro Hassum|João Ernesto'],
  'Chorar de Rir': ['Leandro Hassum|Nilo Perequê'],
  'Não se Aceitam Devoluções': ['Leandro Hassum|Juca'],
  'Amor Sem Medida': ['Leandro Hassum|Ricardo', 'Juliana Paes|Ivana'],
  'O Amor Dá Trabalho': ['Leandro Hassum|Tavares'],
  'Ricos de Amor': ['Danilo Mesquita|Paulo'],
  'The Last of Us': ['Pedro Pascal|Joel', 'Bella Ramsey|Ellie'],
  'Reacher': ['Alan Ritchson|Jack Reacher', 'Maria Sten|Frances Neagley']
};
const SAGAS = [
  { id: 1, nome: 'Duna', itens: ['Duna', 'Duna: Parte Dois'] },
  { id: 2, nome: 'Velozes e Furiosos', itens: ['Velozes e Furiosos', '+ Velozes + Furiosos', 'Velozes e Furiosos: Desafio em Tóquio'] },
  { id: 3, nome: 'Missão: Impossível', itens: ['Missão: Impossível', 'Missão: Impossível - Efeito Fallout', 'Missão: Impossível - Acerto de Contas'] },
  { id: 4, nome: 'Os Farofeiros', itens: ['Os Farofeiros', 'Os Farofeiros 2', 'Os Farofeiros 3'] }
];

function hash(s) { let x = 0; for (let i = 0; i < s.length; i++) x = (x * 31 + s.charCodeAt(i)) >>> 0; return x; }

export const titles = [];
const byName = new Map();
function add(nome, tipo, ano, generos) {
  const key = tipo + ':' + nome;
  if (byName.has(key)) return byName.get(key);
  const h = hash(nome);
  const id = titles.length + 1;
  const t = {
    id, tipo, nome, ano: ano || 2015 + (h % 10), tmdb: 10000 + id * 7, xs: tipo === 's' ? String(500 + id) : null,
    imdb: Math.round((6 + (h % 30) / 10) * 10) / 10, qualidade: ['4k', 'fhd', 'fhd', 'hd'][h % 4],
    na_lista: !OFF.has(nome), generos: generos || [['Drama', 'Ação', 'Comédia', 'Suspense', 'Aventura', 'Ficção científica', 'Animação', 'Crime'][h % 8]],
    duracao_min: tipo === 'm' ? 90 + (h % 80) : null, temporadas: tipo === 's' ? 1 + (h % 3) : null,
    classificacao: ['L', '10', '12', '14', '16', '18'][h % 6],
    sinopse: SINOPSES[nome] || (tipo === 's' ? 'Uma série que prende do primeiro ao último episódio, com personagens marcantes e reviravoltas.' : 'Um filme para assistir em casa com a família, com boa história e ótimas atuações.'),
    elenco: (ELENCO[nome] || []).map(function (x) { const p = x.split('|'); return { nome: p[0], personagem: p[1] }; }),
    plataformas: []
  };
  titles.push(t);
  byName.set(key, t);
  return t;
}
for (const tipo of ['s', 'm']) for (const [cod] of PLATS) TOP[tipo][cod].forEach(function (n) { add(n, tipo); });
EXTRA_M.forEach(function (e) { add(e[0], 'm', e[1], e[2]); });
EXTRA_S.forEach(function (e) { add(e[0], 's', e[1], e[2]); });
export function find(nome, tipo) { return byName.get((tipo || 'm') + ':' + nome) || byName.get('s:' + nome) || byName.get('m:' + nome); }
export function byId(id) { return titles[id - 1] || null; }
export function byTmdb(tmdb) { return titles.find(function (t) { return t.tmdb === Number(tmdb); }) || null; }

// posições no Top 10
for (const tipo of ['s', 'm']) for (const [cod] of PLATS) TOP[tipo][cod].forEach(function (n, i) {
  const t = find(n, tipo); t.plataformas.push({ codigo: cod, posicao: i + 1 }); t.dias_top10 = 1 + (hash(n) % 20);
});
export function top10(tipo) {
  return PLATS.map(function ([cod, nome], pi) {
    return { codigo: cod, nome, itens: TOP[tipo][cod].map(function (n, i) { return { t: find(n, tipo), posicao: i + 1, mov: MOVES[(i + pi * 3) % 10] }; }) };
  });
}

// pessoas
export const people = new Map();
titles.forEach(function (t) {
  t.elenco.forEach(function (p) {
    if (!people.has(p.nome)) people.set(p.nome, { tmdb: 900000 + hash(p.nome) % 90000, nome: p.nome, papel: 'Ator', titulos: [] });
    people.get(p.nome).titulos.push(t);
  });
});
export function personByTmdb(tmdb) { for (const p of people.values()) if (p.tmdb === Number(tmdb)) return p; return null; }

export const sagas = SAGAS.map(function (s) { return { id: s.id, nome: s.nome, itens: s.itens.map(function (n) { return find(n, 'm'); }).filter(Boolean) }; });
titles.forEach(function (t) { sagas.forEach(function (s) { if (s.itens.indexOf(t) !== -1) t.saga = s; }); });

// episódios das séries
export function seasonsOf(t) {
  const out = [];
  const names = ['O começo', 'Sem volta', 'A proposta', 'Fogo cruzado', 'O encontro', 'Segredos', 'A queda', 'Depois de tudo'];
  for (let s = 1; s <= (t.temporadas || 1); s++) {
    const eps = [];
    for (let e = 1; e <= 8; e++) eps.push({ id: t.id * 1000 + s * 100 + e, numero: e, nome: names[(e + s) % names.length], sinopse: 'Episódio ' + e + ' da temporada ' + s + ' de ' + t.nome + '.', duracao_s: 2400 + ((t.id + e) % 10) * 180, still: '/api/play/img/w300/e' + t.id + '-' + s + '-' + e + '.jpg', xt: { id: 70000 + t.id * 100 + s * 10 + e, ext: 'mp4' } });
    out.push({ numero: s, episodios: eps });
  }
  return out;
}

// canais e grade
const GRUPOS = [
  ['esportes', 'Esportes', ['SporTV|39', 'SporTV 2|40', 'Premiere|42', 'ESPN|70', 'ESPN 2|71', 'Band Sports|72', 'TNT Sports|73', 'Combate|74']],
  ['abertos', 'Abertos', ['Globo|5', 'SBT|4', 'Record|7', 'Band|13', 'RedeTV!|9', 'TV Cultura|2']],
  ['noticias', 'Notícias', ['GloboNews|40', 'CNN Brasil|57', 'BandNews|58', 'Record News|59']],
  ['filmes', 'Filmes', ['Telecine Action|61', 'Telecine Premium|62', 'HBO|63', 'TNT|64', 'Megapix|65', 'Space|66']],
  ['infantis', 'Infantis', ['Cartoon Network|81', 'Discovery Kids|82', 'Gloob|83']],
  ['docs', 'Documentários', ['Discovery|91', 'History|92', 'National Geographic|93', 'Animal Planet|94']]
];
export const channels = [];
export const groups = GRUPOS.map(function ([id, nome, list]) {
  list.forEach(function (x) {
    const [n, num] = x.split('|');
    channels.push({ id: channels.length + 1, nome: n, numero: Number(num), logo: '/api/play/img/logo/c' + (channels.length + 1) + '.png', grupo: nome, gid: id, qualidade: channels.length % 3 ? 'fhd' : 'hd', xt: { id: 30000 + channels.length } });
  });
  return { id, nome };
});
export function channel(id) { return channels[id - 1] || null; }
const PROGS = {
  esportes: ['SportsCenter', 'Seleção SporTV', 'Troca de Passes', 'Futebol na ESPN', 'Linha de Passe', 'Pré-jogo', 'Gols do Dia', 'Bate-Bola'],
  abertos: ['Jornal Nacional', 'Novela das Nove', 'Fantástico', 'Domingão', 'Programa do Ratinho', 'Jornal da Record', 'Faustão na Band'],
  noticias: ['Edição das 20', 'Jornal das Dez', 'CNN Prime Time', 'Em Pauta', 'Conexão', 'Estúdio i'],
  filmes: ['Velozes e Furiosos 8', 'Matrix Resurrections', 'O Hobbit', 'Duro de Matar', 'John Wick', 'Missão: Impossível'],
  infantis: ['Bluey', 'Peppa Pig', 'Hora de Aventura', 'O Incrível Mundo de Gumball', 'Patrulha Canina'],
  docs: ['Largados e Pelados', 'Caçadores de Mitos', 'Alienígenas do Passado', 'Mundo Selvagem', 'Como é Feito']
};
export function schedule(c, from, to) {
  const out = [];
  const pool = PROGS[c.gid] || PROGS.docs;
  const base = Math.floor(from / 1800000) * 1800000 - 3600000;
  let t = base, i = c.id;
  while (t < to) {
    const dur = [30, 60, 60, 90, 120][(i * 7 + t / 1800000) % 5 | 0] * 60000;
    let titulo = pool[(i + t / 1800000) % pool.length | 0];
    out.push({ id: c.id * 100000 + (t / 60000 % 100000 | 0), titulo, inicio: new Date(t).toISOString(), fim: new Date(t + dur).toISOString(), descricao: titulo + ' — programação de ' + c.nome + '.', aviso_id: null });
    t += dur; i++;
  }
  return out.filter(function (p) { return new Date(p.fim).getTime() > from && new Date(p.inicio).getTime() < to; });
}

// Categorias de Séries/Filmes (contrato 2.0.2): id numérico = categoria do painel mantida
// (Lançamentos, 4K, Cinema); id texto "jw:xxx" = gênero do JustWatch.
const JW = [
  ['jw:act', 'Ação e aventura', ['Ação', 'Aventura']], ['jw:ani', 'Animação', ['Animação']], ['jw:cmy', 'Comédia', ['Comédia']],
  ['jw:crm', 'Crime', ['Crime']], ['jw:doc', 'Documentário', ['Documentário']], ['jw:drm', 'Drama', ['Drama']],
  ['jw:fml', 'Família', ['Família', 'Natal']], ['jw:fnt', 'Fantasia', ['Fantasia']], ['jw:hst', 'História', ['História']],
  ['jw:hrr', 'Terror', ['Terror']], ['jw:msc', 'Música', ['Musical']], ['jw:trl', 'Mistério e suspense', ['Suspense']],
  ['jw:rma', 'Romance', ['Romance']], ['jw:scf', 'Ficção científica', ['Ficção científica']], ['jw:spt', 'Esporte', ['Esporte']],
  ['jw:war', 'Guerra', ['Guerra']], ['jw:wsn', 'Faroeste', ['Faroeste']], ['jw:rly', 'Reality', ['Reality']]
];
export const categorias = [
  { id: 25, nome: 'Lançamentos', tem: function (t) { return t.ano >= 2024; } },
  { id: 26, nome: '4K', tem: function (t) { return t.qualidade === '4k'; } },
  { id: 27, nome: 'Cinema', tem: function (t) { return t.tipo === 'm' && t.ano >= 2019; } }
].concat(JW.map(function ([id, nome, gens]) {
  return { id, nome, tem: function (t) { return t.generos.some(function (g) { return gens.indexOf(g) !== -1; }); } };
}));
/** Aceita o id como veio na URL ("26" ou "jw:drm"). */
export function categoria(id) { return categorias.find(function (c) { return String(c.id) === String(id); }) || null; }
