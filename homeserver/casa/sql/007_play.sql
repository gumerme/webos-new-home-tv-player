-- WebOS TV Player 2.0: catálogo, Top 10, perfis, progresso e sinais (contrato em docs/play-2.0.md).
create extension if not exists pg_trgm;
create extension if not exists unaccent;
create schema if not exists play;

-- Normalização para busca (unaccent não é immutable; o wrapper permite índice).
create or replace function play.norm(t text) returns text
language sql immutable parallel safe as $$
  select regexp_replace(lower(public.unaccent('public.unaccent'::regdictionary, coalesce(t, ''))), '[^a-z0-9]+', ' ', 'g')
$$;

-- ---------------------------------------------------------------- lista IPTV
create table play.conta (
    id                 serial primary key,
    nome               text not null,
    servidor           text not null,                -- http://painel.exemplo (sem barra no fim)
    usuario            text not null,
    senha              text not null,
    ativa              boolean not null default true, -- só uma ativa por vez
    vence_em           timestamptz,
    status             text,                          -- Active, Expired... (player_api)
    info               jsonb not null default '{}',
    ultima_resposta_em timestamptz,
    latencia_ms        int,
    criado_em          timestamptz not null default now()
);
create unique index conta_uma_ativa on play.conta ((true)) where ativa;

-- ---------------------------------------------------------------- catálogo
create table play.categoria (
    conta_id   int not null references play.conta(id) on delete cascade,
    tipo       char(1) not null,                       -- m filme, s série, c canal
    painel_id  int not null,
    nome       text not null,                          -- como vem do painel
    nome_exib  text not null,                          -- com acentos (mapa ACCENTS da v10)
    genero     boolean not null default true,          -- false: "Lançamentos", "4K", "Legendados"...
    primary key (conta_id, tipo, painel_id)
);

-- Uma linha por obra (filme ou série). Versões dublada/legendada/4K do painel apontam para ela.
create table play.titulo (
    id             bigserial primary key,
    tipo           char(1) not null,                   -- m | s
    nome           text not null,                      -- nome exibido (sem [L], 4K, ano)
    nome_norm      text not null,
    ano            int,
    tmdb_id        int,
    imdb_id        text,
    poster         text,                               -- caminho TMDB (/abc.jpg) ou URL do painel
    fundo          text,
    sinopse        text,
    generos        int[] not null default '{}',        -- ids TMDB
    categorias     int[] not null default '{}',        -- painel_id das categorias (gênero do painel)
    adicionado_em  timestamptz,                        -- mais recente entre as versões
    nota_painel    numeric(4,2),
    detalhes       jsonb not null default '{}',        -- TMDB enxuto: duração, classificação, elenco, diretor, keywords, colecao, temporadas, trailer...
    detalhes_em    timestamptz,
    jw             jsonb not null default '{}',        -- {imdb, imdb_votos, rotten, gostaram, id}
    jw_em          timestamptz,
    colecao_id     int,                                -- saga TMDB
    qualidade      text,                               -- 4k | fhd | hd | sd (teste de link)
    link_ok        boolean,
    link_testado_em timestamptz,
    ativo          boolean not null default true,
    atualizado_em  timestamptz not null default now()
);
create index titulo_tmdb on play.titulo (tipo, tmdb_id);
create index titulo_nome_trgm on play.titulo using gin (nome_norm gin_trgm_ops);
create index titulo_adic on play.titulo (tipo, adicionado_em desc) where ativo;
create index titulo_colecao on play.titulo (colecao_id) where colecao_id is not null;
create index titulo_cats on play.titulo using gin (categorias);

-- Entradas do painel (stream_id de filme ou series_id de série).
create table play.versao (
    conta_id      int not null references play.conta(id) on delete cascade,
    tipo          char(1) not null,                    -- m | s
    painel_id     int not null,
    titulo_id     bigint references play.titulo(id) on delete set null,
    nome_painel   text not null,
    idioma        text not null default 'dub',         -- dub | leg
    uhd           boolean not null default false,
    ext           text,                                -- mp4, mkv (filmes)
    categoria_id  int,
    tmdb_painel   int,
    poster_painel text,
    nota          numeric(4,2),
    adicionado_em timestamptz,
    ativo         boolean not null default true,
    visto_em      timestamptz not null default now(),  -- última sincronia em que apareceu
    primary key (conta_id, tipo, painel_id)
);
create index versao_titulo on play.versao (titulo_id);

-- Episódios (get_series_info), atualizados sob demanda e para séries seguidas.
create table play.episodio (
    conta_id    int not null,
    serie_id    int not null,                          -- series_id do painel
    ep_id       int not null,                          -- id do episódio no painel
    temporada   int not null,
    numero      int not null,
    nome        text,
    sinopse     text,
    ext         text,
    duracao_s   int,
    still       text,
    adicionado_em timestamptz,
    primary key (conta_id, serie_id, ep_id)
);
create index episodio_ord on play.episodio (conta_id, serie_id, temporada, numero);
create table play.serie_info (
    conta_id    int not null,
    serie_id    int not null,
    atualizado_em timestamptz not null default now(),
    primary key (conta_id, serie_id)
);

create table play.pessoa (
    tmdb_id    int primary key,
    nome       text not null,
    nome_norm  text not null,
    foto       text
);
create index pessoa_nome_trgm on play.pessoa using gin (nome_norm gin_trgm_ops);
create table play.titulo_pessoa (
    titulo_id  bigint not null references play.titulo(id) on delete cascade,
    pessoa_id  int not null references play.pessoa(tmdb_id) on delete cascade,
    papel      text not null,                          -- elenco | direcao | criacao
    ordem      int not null default 99,
    personagem text,
    primary key (titulo_id, pessoa_id, papel)
);
create index titulo_pessoa_p on play.titulo_pessoa (pessoa_id);

create table play.colecao (
    id     int primary key,                            -- TMDB collection id
    nome   text not null,
    partes jsonb not null default '[]',                -- [{tmdb, nome, ano, ordem}]
    fundo  text,
    atualizado_em timestamptz not null default now()
);

-- ---------------------------------------------------------------- ao vivo
create table play.canal (
    conta_id     int not null references play.conta(id) on delete cascade,
    painel_id    int not null,
    nome         text not null,
    nome_norm    text not null,
    numero       int,
    logo         text,
    categoria_id int,
    epg_id       text,
    arquivo      boolean not null default false,        -- tv_archive
    ativo        boolean not null default true,
    primary key (conta_id, painel_id)
);
create index canal_nome_trgm on play.canal using gin (nome_norm gin_trgm_ops);

create table play.programa (
    id          bigserial primary key,
    conta_id    int not null,
    canal_id    int not null,                          -- painel_id do canal
    inicio      timestamptz not null,
    fim         timestamptz not null,
    titulo      text not null,
    descricao   text,
    unique (conta_id, canal_id, inicio)
);
create index programa_janela on play.programa (conta_id, canal_id, fim);

create table play.jogo (
    id          text primary key,                      -- espn id ou hash
    data        date not null,
    inicio      timestamptz not null,
    status      text not null default 'depois',        -- depois | agora | fim
    minuto      text,
    placar      text,
    casa        jsonb not null,                        -- {nome, sigla, escudo}
    fora        jsonb not null,
    liga        text,
    canal_id    int,                                   -- painel_id do canal "Jogos do Dia"
    atualizado_em timestamptz not null default now()
);
create index jogo_data on play.jogo (data);
create table play.escudo (
    chave   text primary key,                          -- nome normalizado do time
    nome    text not null,
    sigla   text,
    url     text,
    atualizado_em timestamptz not null default now()
);

-- ---------------------------------------------------------------- Top 10 por plataforma
create table play.plataforma (
    codigo      text primary key,                      -- nfx, prv, dnp, mxx, gop, atp
    nome        text not null,
    jw_package  text not null,
    ordem       int not null,
    ativa       boolean not null default true
);
insert into play.plataforma (codigo, nome, jw_package, ordem, ativa) values
 ('nfx', 'Netflix', 'nfx', 1, true),
 ('prv', 'Prime Video', 'prv', 2, true),
 ('dnp', 'Disney+', 'dnp', 3, true),
 ('mxx', 'HBO Max', 'mxx', 4, true),
 ('gop', 'Globoplay', 'gop', 5, true),
 ('atp', 'Apple TV', 'atp', 6, false);

create table play.top10 (
    coletado_em  timestamptz not null,
    tipo         char(1) not null,                     -- s | m
    plataforma   text not null references play.plataforma(codigo),
    posicao      int not null,
    jw_id        text,
    tmdb_id      int,
    nome         text not null,
    ano          int,
    poster       text,
    titulo_id    bigint references play.titulo(id) on delete set null,
    primary key (coletado_em, tipo, plataforma, posicao)
);
create index top10_ult on play.top10 (tipo, plataforma, coletado_em desc);

-- ---------------------------------------------------------------- TVs, perfis e uso
create table play.perfil (
    id         serial primary key,
    nome       text not null,
    cor        text not null default '#1E1B4B',
    criado_em  timestamptz not null default now()
);
insert into play.perfil (nome, cor) values ('Casa', '#3B0A17');

create table play.tv (
    id          serial primary key,
    nome        text not null,
    modelo      text,
    duid        text,
    webos       text,
    versao_app  text,
    token_hash  text not null unique,                  -- sha256 do token
    codigo      text not null,                         -- 4 caracteres mostrados na TV
    status      text not null default 'pendente',      -- pendente | ativa | bloqueada
    dispositivo_slug text,                             -- tv-1 / tv-2 em public.dispositivo (avisos via SSAP)
    ip          text,
    perfil_id   int references play.perfil(id) on delete set null,
    assistindo  jsonb,                                 -- {titulo, nome, desde, canal}
    visto_em    timestamptz,
    criado_em   timestamptz not null default now()
);

create table play.progresso (
    perfil_id   int not null references play.perfil(id) on delete cascade,
    titulo_id   bigint not null references play.titulo(id) on delete cascade,
    versao_id   int,                                   -- painel_id usado
    temporada   int,
    episodio    int,
    posicao_s   int not null default 0,
    duracao_s   int not null default 0,
    terminado   boolean not null default false,
    oculto      boolean not null default false,        -- tirado do "Continuar" (volta se tocar de novo)
    tv_id       int references play.tv(id) on delete set null,
    atualizado_em timestamptz not null default now(),
    primary key (perfil_id, titulo_id)
);
create index progresso_rec on play.progresso (perfil_id, atualizado_em desc);

-- Progresso por episódio (marcar vistos na lista de episódios).
create table play.progresso_ep (
    perfil_id   int not null references play.perfil(id) on delete cascade,
    titulo_id   bigint not null references play.titulo(id) on delete cascade,
    temporada   int not null,
    episodio    int not null,
    posicao_s   int not null default 0,
    duracao_s   int not null default 0,
    terminado   boolean not null default false,
    atualizado_em timestamptz not null default now(),
    primary key (perfil_id, titulo_id, temporada, episodio)
);

create table play.historico (
    id          bigserial primary key,
    perfil_id   int not null references play.perfil(id) on delete cascade,
    titulo_id   bigint not null references play.titulo(id) on delete cascade,
    temporada   int,
    episodio    int,
    inicio      timestamptz not null default now(),
    fim         timestamptz,
    assistido_s int not null default 0,
    duracao_s   int,
    terminado   boolean not null default false,
    tv_id       int references play.tv(id) on delete set null
);
create index historico_perfil on play.historico (perfil_id, inicio desc);

create table play.lista (
    perfil_id  int not null references play.perfil(id) on delete cascade,
    titulo_id  bigint not null references play.titulo(id) on delete cascade,
    adicionado_em timestamptz not null default now(),
    primary key (perfil_id, titulo_id)
);
create table play.nota (
    perfil_id  int not null references play.perfil(id) on delete cascade,
    titulo_id  bigint not null references play.titulo(id) on delete cascade,
    valor      smallint not null,                      -- 1 gostei, -1 não gostei
    em         timestamptz not null default now(),
    primary key (perfil_id, titulo_id)
);
create table play.canal_fav (
    perfil_id  int not null references play.perfil(id) on delete cascade,
    conta_id   int not null,
    canal_id   int not null,
    ordem      int not null default 0,
    primary key (perfil_id, conta_id, canal_id)
);
create table play.serie_seguida (
    perfil_id  int not null references play.perfil(id) on delete cascade,
    titulo_id  bigint not null references play.titulo(id) on delete cascade,
    desde      timestamptz not null default now(),
    primary key (perfil_id, titulo_id)
);
create table play.versao_escolhida (
    perfil_id  int not null references play.perfil(id) on delete cascade,
    titulo_id  bigint not null references play.titulo(id) on delete cascade,
    idioma     text not null,                          -- dub | leg
    primary key (perfil_id, titulo_id)
);
create table play.preferencia (
    perfil_id  int not null references play.perfil(id) on delete cascade,
    chave      text not null,
    valor      jsonb not null,
    primary key (perfil_id, chave)
);
create table play.abertura (
    titulo_id  bigint primary key references play.titulo(id) on delete cascade,
    inicio_s   int not null,
    fim_s      int not null,
    amostras   int not null default 1,
    atualizado_em timestamptz not null default now()
);

-- "Avisar": jogo, programa, episódio, chegar (título fora da lista), vencimento.
create table play.aviso (
    id          bigserial primary key,
    perfil_id   int references play.perfil(id) on delete cascade,
    tipo        text not null,
    alvo        jsonb not null,                        -- {jogo}, {canal, programa}, {titulo, temporada, episodio}, {tmdb, tipo, nome}
    quando      timestamptz,                           -- disparo (jogo/programa: início - 5 min)
    status      text not null default 'ativo',         -- ativo | disparado | cancelado
    texto       text,
    criado_em   timestamptz not null default now(),
    disparado_em timestamptz
);
create index aviso_ativo on play.aviso (status, quando);

create table play.legenda (
    id          bigserial primary key,
    titulo_id   bigint references play.titulo(id) on delete cascade,
    temporada   int,
    episodio    int,
    idioma      text not null,
    nome        text,
    fonte       text not null,
    url_origem  text,
    arquivo     text,                                  -- var/play/legendas/<id>.srt (null até baixar)
    criado_em   timestamptz not null default now()
);
create index legenda_alvo on play.legenda (titulo_id, temporada, episodio);

-- Fileiras pré-calculadas por perfil (o Início sai pronto).
create table play.recomendacao (
    perfil_id  int primary key references play.perfil(id) on delete cascade,
    gerado_em  timestamptz not null default now(),
    dados      jsonb not null                          -- {destaque:[ids], recomendados:[ids], porque:[{titulo_id, ids}], generos:[{id,nome,ids}], gosto:{generos, pessoas}}
);

-- Sinais para as TVs (SSE). NOTIFY play_sinal com o id.
create table play.sinal (
    id         bigserial primary key,
    tv_id      int,                                    -- null = todas
    perfil_id  int,                                    -- null = todos
    exceto_tv  int,                                    -- não mandar para a TV que gerou
    evento     text not null,
    dados      jsonb not null default '{}',
    criado_em  timestamptz not null default now()
);
create index sinal_criado on play.sinal (criado_em);

create table play.tarefa (
    nome        text primary key,
    ultima_em   timestamptz,
    duracao_ms  int,
    status      text,                                  -- ok | erro | rodando
    mensagem    text,
    proxima_em  timestamptz,
    forcar      boolean not null default false         -- painel pede "rodar agora"
);
