--
-- PostgreSQL database dump
--


-- Dumped from database version 17.11
-- Dumped by pg_dump version 17.11

SET statement_timeout = 0;
SET lock_timeout = 0;
SET idle_in_transaction_session_timeout = 0;
SET transaction_timeout = 0;
SET client_encoding = 'UTF8';
SET standard_conforming_strings = on;
SELECT pg_catalog.set_config('search_path', '', false);
SET check_function_bodies = false;
SET xmloption = content;
SET client_min_messages = warning;
SET row_security = off;

--
-- Name: play; Type: SCHEMA; Schema: -; Owner: -
--

CREATE SCHEMA play;


--
-- Name: pg_trgm; Type: EXTENSION; Schema: -; Owner: -
--

CREATE EXTENSION IF NOT EXISTS pg_trgm WITH SCHEMA public;


--
-- Name: EXTENSION pg_trgm; Type: COMMENT; Schema: -; Owner: -
--

COMMENT ON EXTENSION pg_trgm IS 'text similarity measurement and index searching based on trigrams';


--
-- Name: unaccent; Type: EXTENSION; Schema: -; Owner: -
--

CREATE EXTENSION IF NOT EXISTS unaccent WITH SCHEMA public;


--
-- Name: EXTENSION unaccent; Type: COMMENT; Schema: -; Owner: -
--

COMMENT ON EXTENSION unaccent IS 'text search dictionary that removes accents';


--
-- Name: norm(text); Type: FUNCTION; Schema: play; Owner: -
--

CREATE FUNCTION play.norm(t text) RETURNS text
    LANGUAGE sql IMMUTABLE PARALLEL SAFE
    AS $$
  select btrim(regexp_replace(regexp_replace(regexp_replace(regexp_replace(
           lower(public.unaccent('public.unaccent'::regdictionary, coalesce(t, ''))),
           '[''’`]', '', 'g'),
           '\[[^]]*\]|\([^)]*\)', ' ', 'g'),
           '\m(4k|fhd|uhd|hd|sd|hdr|legendado|leg|dublado|dub|dual|audio|nacional|cam|hdcam)\M', ' ', 'g'),
           '[^a-z0-9]+', ' ', 'g'))
$$;


SET default_tablespace = '';

SET default_table_access_method = heap;

--
-- Name: abertura; Type: TABLE; Schema: play; Owner: -
--

CREATE TABLE play.abertura (
    titulo_id bigint NOT NULL,
    inicio_s integer NOT NULL,
    fim_s integer NOT NULL,
    amostras integer DEFAULT 1 NOT NULL,
    atualizado_em timestamp with time zone DEFAULT now() NOT NULL
);


--
-- Name: aviso; Type: TABLE; Schema: play; Owner: -
--

CREATE TABLE play.aviso (
    id bigint NOT NULL,
    perfil_id integer,
    tipo text NOT NULL,
    alvo jsonb NOT NULL,
    quando timestamp with time zone,
    status text DEFAULT 'ativo'::text NOT NULL,
    texto text,
    criado_em timestamp with time zone DEFAULT now() NOT NULL,
    disparado_em timestamp with time zone
);


--
-- Name: aviso_id_seq; Type: SEQUENCE; Schema: play; Owner: -
--

CREATE SEQUENCE play.aviso_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: aviso_id_seq; Type: SEQUENCE OWNED BY; Schema: play; Owner: -
--

ALTER SEQUENCE play.aviso_id_seq OWNED BY play.aviso.id;


--
-- Name: cache; Type: TABLE; Schema: play; Owner: -
--

CREATE TABLE play.cache (
    chave text NOT NULL,
    dados jsonb NOT NULL,
    em timestamp with time zone DEFAULT now() NOT NULL
);


--
-- Name: canal; Type: TABLE; Schema: play; Owner: -
--

CREATE TABLE play.canal (
    conta_id integer NOT NULL,
    painel_id integer NOT NULL,
    nome text NOT NULL,
    nome_norm text NOT NULL,
    numero integer,
    logo text,
    categoria_id integer,
    epg_id text,
    arquivo boolean DEFAULT false NOT NULL,
    ativo boolean DEFAULT true NOT NULL,
    epg_em timestamp with time zone
);


--
-- Name: canal_fav; Type: TABLE; Schema: play; Owner: -
--

CREATE TABLE play.canal_fav (
    perfil_id integer NOT NULL,
    conta_id integer NOT NULL,
    canal_id integer NOT NULL,
    ordem integer DEFAULT 0 NOT NULL
);


--
-- Name: categoria; Type: TABLE; Schema: play; Owner: -
--

CREATE TABLE play.categoria (
    conta_id integer NOT NULL,
    tipo character(1) NOT NULL,
    painel_id integer NOT NULL,
    nome text NOT NULL,
    nome_exib text NOT NULL,
    genero boolean DEFAULT true NOT NULL
);


--
-- Name: colecao; Type: TABLE; Schema: play; Owner: -
--

CREATE TABLE play.colecao (
    id integer NOT NULL,
    nome text NOT NULL,
    partes jsonb DEFAULT '[]'::jsonb NOT NULL,
    fundo text,
    atualizado_em timestamp with time zone DEFAULT now() NOT NULL
);


--
-- Name: conta; Type: TABLE; Schema: play; Owner: -
--

CREATE TABLE play.conta (
    id integer NOT NULL,
    nome text NOT NULL,
    servidor text NOT NULL,
    usuario text NOT NULL,
    senha text NOT NULL,
    ativa boolean DEFAULT true NOT NULL,
    vence_em timestamp with time zone,
    status text,
    info jsonb DEFAULT '{}'::jsonb NOT NULL,
    ultima_resposta_em timestamp with time zone,
    latencia_ms integer,
    criado_em timestamp with time zone DEFAULT now() NOT NULL
);


--
-- Name: conta_id_seq; Type: SEQUENCE; Schema: play; Owner: -
--

CREATE SEQUENCE play.conta_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: conta_id_seq; Type: SEQUENCE OWNED BY; Schema: play; Owner: -
--

ALTER SEQUENCE play.conta_id_seq OWNED BY play.conta.id;


--
-- Name: episodio; Type: TABLE; Schema: play; Owner: -
--

CREATE TABLE play.episodio (
    conta_id integer NOT NULL,
    serie_id integer NOT NULL,
    ep_id integer NOT NULL,
    temporada integer NOT NULL,
    numero integer NOT NULL,
    nome text,
    sinopse text,
    ext text,
    duracao_s integer,
    still text,
    adicionado_em timestamp with time zone
);


--
-- Name: escudo; Type: TABLE; Schema: play; Owner: -
--

CREATE TABLE play.escudo (
    chave text NOT NULL,
    nome text NOT NULL,
    sigla text,
    url text,
    atualizado_em timestamp with time zone DEFAULT now() NOT NULL
);


--
-- Name: historico; Type: TABLE; Schema: play; Owner: -
--

CREATE TABLE play.historico (
    id bigint NOT NULL,
    perfil_id integer NOT NULL,
    titulo_id bigint NOT NULL,
    temporada integer,
    episodio integer,
    inicio timestamp with time zone DEFAULT now() NOT NULL,
    fim timestamp with time zone,
    assistido_s integer DEFAULT 0 NOT NULL,
    duracao_s integer,
    terminado boolean DEFAULT false NOT NULL,
    tv_id integer
);


--
-- Name: historico_id_seq; Type: SEQUENCE; Schema: play; Owner: -
--

CREATE SEQUENCE play.historico_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: historico_id_seq; Type: SEQUENCE OWNED BY; Schema: play; Owner: -
--

ALTER SEQUENCE play.historico_id_seq OWNED BY play.historico.id;


--
-- Name: jogo; Type: TABLE; Schema: play; Owner: -
--

CREATE TABLE play.jogo (
    id text NOT NULL,
    data date NOT NULL,
    inicio timestamp with time zone NOT NULL,
    status text DEFAULT 'depois'::text NOT NULL,
    minuto text,
    placar text,
    casa jsonb NOT NULL,
    fora jsonb NOT NULL,
    liga text,
    canal_id integer,
    atualizado_em timestamp with time zone DEFAULT now() NOT NULL
);


--
-- Name: legenda; Type: TABLE; Schema: play; Owner: -
--

CREATE TABLE play.legenda (
    id bigint NOT NULL,
    titulo_id bigint,
    temporada integer,
    episodio integer,
    idioma text NOT NULL,
    nome text,
    fonte text NOT NULL,
    url_origem text,
    arquivo text,
    criado_em timestamp with time zone DEFAULT now() NOT NULL
);


--
-- Name: legenda_id_seq; Type: SEQUENCE; Schema: play; Owner: -
--

CREATE SEQUENCE play.legenda_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: legenda_id_seq; Type: SEQUENCE OWNED BY; Schema: play; Owner: -
--

ALTER SEQUENCE play.legenda_id_seq OWNED BY play.legenda.id;


--
-- Name: lista; Type: TABLE; Schema: play; Owner: -
--

CREATE TABLE play.lista (
    perfil_id integer NOT NULL,
    titulo_id bigint NOT NULL,
    adicionado_em timestamp with time zone DEFAULT now() NOT NULL
);


--
-- Name: nota; Type: TABLE; Schema: play; Owner: -
--

CREATE TABLE play.nota (
    perfil_id integer NOT NULL,
    titulo_id bigint NOT NULL,
    valor smallint NOT NULL,
    em timestamp with time zone DEFAULT now() NOT NULL
);


--
-- Name: perfil; Type: TABLE; Schema: play; Owner: -
--

CREATE TABLE play.perfil (
    id integer NOT NULL,
    nome text NOT NULL,
    cor text DEFAULT '#1E1B4B'::text NOT NULL,
    criado_em timestamp with time zone DEFAULT now() NOT NULL
);


--
-- Name: perfil_id_seq; Type: SEQUENCE; Schema: play; Owner: -
--

CREATE SEQUENCE play.perfil_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: perfil_id_seq; Type: SEQUENCE OWNED BY; Schema: play; Owner: -
--

ALTER SEQUENCE play.perfil_id_seq OWNED BY play.perfil.id;


--
-- Name: pessoa; Type: TABLE; Schema: play; Owner: -
--

CREATE TABLE play.pessoa (
    tmdb_id integer NOT NULL,
    nome text NOT NULL,
    nome_norm text NOT NULL,
    foto text
);


--
-- Name: plataforma; Type: TABLE; Schema: play; Owner: -
--

CREATE TABLE play.plataforma (
    codigo text NOT NULL,
    nome text NOT NULL,
    jw_package text NOT NULL,
    ordem integer NOT NULL,
    ativa boolean DEFAULT true NOT NULL
);


--
-- Name: preferencia; Type: TABLE; Schema: play; Owner: -
--

CREATE TABLE play.preferencia (
    perfil_id integer NOT NULL,
    chave text NOT NULL,
    valor jsonb NOT NULL
);


--
-- Name: programa; Type: TABLE; Schema: play; Owner: -
--

CREATE TABLE play.programa (
    id bigint NOT NULL,
    conta_id integer NOT NULL,
    canal_id integer NOT NULL,
    inicio timestamp with time zone NOT NULL,
    fim timestamp with time zone NOT NULL,
    titulo text NOT NULL,
    descricao text
);


--
-- Name: programa_id_seq; Type: SEQUENCE; Schema: play; Owner: -
--

CREATE SEQUENCE play.programa_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: programa_id_seq; Type: SEQUENCE OWNED BY; Schema: play; Owner: -
--

ALTER SEQUENCE play.programa_id_seq OWNED BY play.programa.id;


--
-- Name: progresso; Type: TABLE; Schema: play; Owner: -
--

CREATE TABLE play.progresso (
    perfil_id integer NOT NULL,
    titulo_id bigint NOT NULL,
    versao_id integer,
    temporada integer,
    episodio integer,
    posicao_s integer DEFAULT 0 NOT NULL,
    duracao_s integer DEFAULT 0 NOT NULL,
    terminado boolean DEFAULT false NOT NULL,
    oculto boolean DEFAULT false NOT NULL,
    tv_id integer,
    atualizado_em timestamp with time zone DEFAULT now() NOT NULL
);


--
-- Name: progresso_ep; Type: TABLE; Schema: play; Owner: -
--

CREATE TABLE play.progresso_ep (
    perfil_id integer NOT NULL,
    titulo_id bigint NOT NULL,
    temporada integer NOT NULL,
    episodio integer NOT NULL,
    posicao_s integer DEFAULT 0 NOT NULL,
    duracao_s integer DEFAULT 0 NOT NULL,
    terminado boolean DEFAULT false NOT NULL,
    atualizado_em timestamp with time zone DEFAULT now() NOT NULL
);


--
-- Name: recomendacao; Type: TABLE; Schema: play; Owner: -
--

CREATE TABLE play.recomendacao (
    perfil_id integer NOT NULL,
    gerado_em timestamp with time zone DEFAULT now() NOT NULL,
    dados jsonb NOT NULL,
    chave text
);


--
-- Name: serie_info; Type: TABLE; Schema: play; Owner: -
--

CREATE TABLE play.serie_info (
    conta_id integer NOT NULL,
    serie_id integer NOT NULL,
    atualizado_em timestamp with time zone DEFAULT now() NOT NULL
);


--
-- Name: serie_seguida; Type: TABLE; Schema: play; Owner: -
--

CREATE TABLE play.serie_seguida (
    perfil_id integer NOT NULL,
    titulo_id bigint NOT NULL,
    desde timestamp with time zone DEFAULT now() NOT NULL
);


--
-- Name: sinal; Type: TABLE; Schema: play; Owner: -
--

CREATE TABLE play.sinal (
    id bigint NOT NULL,
    tv_id integer,
    perfil_id integer,
    exceto_tv integer,
    evento text NOT NULL,
    dados jsonb DEFAULT '{}'::jsonb NOT NULL,
    criado_em timestamp with time zone DEFAULT now() NOT NULL
);


--
-- Name: sinal_id_seq; Type: SEQUENCE; Schema: play; Owner: -
--

CREATE SEQUENCE play.sinal_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: sinal_id_seq; Type: SEQUENCE OWNED BY; Schema: play; Owner: -
--

ALTER SEQUENCE play.sinal_id_seq OWNED BY play.sinal.id;


--
-- Name: tarefa; Type: TABLE; Schema: play; Owner: -
--

CREATE TABLE play.tarefa (
    nome text NOT NULL,
    ultima_em timestamp with time zone,
    duracao_ms integer,
    status text,
    mensagem text,
    proxima_em timestamp with time zone,
    forcar boolean DEFAULT false NOT NULL
);


--
-- Name: titulo; Type: TABLE; Schema: play; Owner: -
--

CREATE TABLE play.titulo (
    id bigint NOT NULL,
    tipo character(1) NOT NULL,
    nome text NOT NULL,
    nome_norm text NOT NULL,
    ano integer,
    tmdb_id integer,
    imdb_id text,
    poster text,
    fundo text,
    sinopse text,
    generos integer[] DEFAULT '{}'::integer[] NOT NULL,
    categorias integer[] DEFAULT '{}'::integer[] NOT NULL,
    adicionado_em timestamp with time zone,
    nota_painel numeric(4,2),
    detalhes jsonb DEFAULT '{}'::jsonb NOT NULL,
    detalhes_em timestamp with time zone,
    jw jsonb DEFAULT '{}'::jsonb NOT NULL,
    jw_em timestamp with time zone,
    colecao_id integer,
    qualidade text,
    link_ok boolean,
    link_testado_em timestamp with time zone,
    ativo boolean DEFAULT true NOT NULL,
    atualizado_em timestamp with time zone DEFAULT now() NOT NULL
);


--
-- Name: titulo_id_seq; Type: SEQUENCE; Schema: play; Owner: -
--

CREATE SEQUENCE play.titulo_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: titulo_id_seq; Type: SEQUENCE OWNED BY; Schema: play; Owner: -
--

ALTER SEQUENCE play.titulo_id_seq OWNED BY play.titulo.id;


--
-- Name: titulo_pessoa; Type: TABLE; Schema: play; Owner: -
--

CREATE TABLE play.titulo_pessoa (
    titulo_id bigint NOT NULL,
    pessoa_id integer NOT NULL,
    papel text NOT NULL,
    ordem integer DEFAULT 99 NOT NULL,
    personagem text
);


--
-- Name: top10; Type: TABLE; Schema: play; Owner: -
--

CREATE TABLE play.top10 (
    coletado_em timestamp with time zone NOT NULL,
    tipo character(1) NOT NULL,
    plataforma text NOT NULL,
    posicao integer NOT NULL,
    jw_id text,
    tmdb_id integer,
    nome text NOT NULL,
    ano integer,
    poster text,
    titulo_id bigint
);


--
-- Name: tv; Type: TABLE; Schema: play; Owner: -
--

CREATE TABLE play.tv (
    id integer NOT NULL,
    nome text NOT NULL,
    modelo text,
    duid text,
    webos text,
    versao_app text,
    token_hash text NOT NULL,
    codigo text NOT NULL,
    status text DEFAULT 'pendente'::text NOT NULL,
    dispositivo_slug text,
    ip text,
    perfil_id integer,
    assistindo jsonb,
    visto_em timestamp with time zone,
    criado_em timestamp with time zone DEFAULT now() NOT NULL
);


--
-- Name: tv_id_seq; Type: SEQUENCE; Schema: play; Owner: -
--

CREATE SEQUENCE play.tv_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: tv_id_seq; Type: SEQUENCE OWNED BY; Schema: play; Owner: -
--

ALTER SEQUENCE play.tv_id_seq OWNED BY play.tv.id;


--
-- Name: versao; Type: TABLE; Schema: play; Owner: -
--

CREATE TABLE play.versao (
    conta_id integer NOT NULL,
    tipo character(1) NOT NULL,
    painel_id integer NOT NULL,
    titulo_id bigint,
    nome_painel text NOT NULL,
    idioma text DEFAULT 'dub'::text NOT NULL,
    uhd boolean DEFAULT false NOT NULL,
    ext text,
    categoria_id integer,
    tmdb_painel integer,
    poster_painel text,
    nota numeric(4,2),
    adicionado_em timestamp with time zone,
    ativo boolean DEFAULT true NOT NULL,
    visto_em timestamp with time zone DEFAULT now() NOT NULL
);


--
-- Name: versao_escolhida; Type: TABLE; Schema: play; Owner: -
--

CREATE TABLE play.versao_escolhida (
    perfil_id integer NOT NULL,
    titulo_id bigint NOT NULL,
    idioma text NOT NULL
);


--
-- Name: comando; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.comando (
    id bigint NOT NULL,
    dispositivo_id integer NOT NULL,
    acao text NOT NULL,
    parametros jsonb DEFAULT '{}'::jsonb NOT NULL,
    origem text DEFAULT 'api'::text NOT NULL,
    status text DEFAULT 'pendente'::text NOT NULL,
    resposta jsonb,
    criado_em timestamp with time zone DEFAULT now() NOT NULL,
    executado_em timestamp with time zone
);


--
-- Name: comando_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.comando_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: comando_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.comando_id_seq OWNED BY public.comando.id;


--
-- Name: configuracao; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.configuracao (
    chave text NOT NULL,
    valor text,
    atualizado_em timestamp with time zone DEFAULT now() NOT NULL,
    atualizado_por text
);


--
-- Name: dispositivo; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.dispositivo (
    id integer NOT NULL,
    slug text NOT NULL,
    nome text NOT NULL,
    tipo text NOT NULL,
    driver text NOT NULL,
    comodo text,
    config jsonb DEFAULT '{}'::jsonb NOT NULL,
    homekit boolean DEFAULT true NOT NULL,
    ativo boolean DEFAULT true NOT NULL,
    criado_em timestamp with time zone DEFAULT now() NOT NULL
);


--
-- Name: dispositivo_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.dispositivo_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: dispositivo_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.dispositivo_id_seq OWNED BY public.dispositivo.id;


--
-- Name: estado; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.estado (
    dispositivo_id integer NOT NULL,
    online boolean DEFAULT false NOT NULL,
    dados jsonb DEFAULT '{}'::jsonb NOT NULL,
    atualizado_em timestamp with time zone DEFAULT now() NOT NULL
);


--
-- Name: evento; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.evento (
    id bigint NOT NULL,
    tipo text NOT NULL,
    nivel text DEFAULT 'info'::text NOT NULL,
    titulo text NOT NULL,
    detalhe text,
    origem text,
    criado_em timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT evento_nivel_check CHECK ((nivel = ANY (ARRAY['info'::text, 'ok'::text, 'aviso'::text, 'erro'::text])))
);


--
-- Name: evento_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.evento_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: evento_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.evento_id_seq OWNED BY public.evento.id;


--
-- Name: login_tentativa; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.login_tentativa (
    id bigint NOT NULL,
    login text,
    ip text,
    sucesso boolean NOT NULL,
    criado_em timestamp with time zone DEFAULT now() NOT NULL
);


--
-- Name: login_tentativa_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.login_tentativa_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: login_tentativa_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.login_tentativa_id_seq OWNED BY public.login_tentativa.id;


--
-- Name: metrica; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.metrica (
    coletado_em timestamp with time zone DEFAULT now() NOT NULL,
    cpu_pct real,
    load1 real,
    ram_usada bigint,
    ram_total bigint,
    swap_usada bigint,
    swap_total bigint,
    disco_usado bigint,
    disco_total bigint,
    home_usado bigint,
    home_total bigint,
    energia_w real,
    energia_cpu_w real,
    energia_ram_w real,
    temp_c real,
    rede_rx_bps bigint,
    rede_tx_bps bigint
);


--
-- Name: metrica_rede; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.metrica_rede (
    coletado_em timestamp with time zone DEFAULT now() NOT NULL,
    gateway_ms real,
    gateway_perda real,
    internet_ms real,
    internet_perda real,
    dns_ms real
);


--
-- Name: migracao; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.migracao (
    arquivo text NOT NULL,
    aplicado_em timestamp with time zone DEFAULT now() NOT NULL
);


--
-- Name: rede_dispositivo; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.rede_dispositivo (
    mac text NOT NULL,
    ip text,
    fabricante text,
    nome_dns text,
    upnp_fabricante text,
    upnp_modelo text,
    upnp_nome text,
    upnp_servidor text,
    apelido text,
    conhecido boolean DEFAULT false NOT NULL,
    online boolean DEFAULT false NOT NULL,
    primeiro_visto timestamp with time zone DEFAULT now() NOT NULL,
    ultimo_visto timestamp with time zone DEFAULT now() NOT NULL,
    mac_privado boolean DEFAULT false NOT NULL,
    modelo text,
    mdns_nome text,
    mdns_servicos text[],
    http_titulo text,
    http_servidor text,
    extras jsonb DEFAULT '{}'::jsonb NOT NULL
);


--
-- Name: usuario; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.usuario (
    id integer NOT NULL,
    nome text NOT NULL,
    login text NOT NULL,
    senha_hash text NOT NULL,
    perfil text DEFAULT 'morador'::text NOT NULL,
    ativo boolean DEFAULT true NOT NULL,
    trocar_senha boolean DEFAULT false NOT NULL,
    ultimo_login timestamp with time zone,
    criado_em timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT usuario_perfil_check CHECK ((perfil = ANY (ARRAY['admin'::text, 'morador'::text])))
);


--
-- Name: usuario_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.usuario_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: usuario_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.usuario_id_seq OWNED BY public.usuario.id;


--
-- Name: aviso id; Type: DEFAULT; Schema: play; Owner: -
--

ALTER TABLE ONLY play.aviso ALTER COLUMN id SET DEFAULT nextval('play.aviso_id_seq'::regclass);


--
-- Name: conta id; Type: DEFAULT; Schema: play; Owner: -
--

ALTER TABLE ONLY play.conta ALTER COLUMN id SET DEFAULT nextval('play.conta_id_seq'::regclass);


--
-- Name: historico id; Type: DEFAULT; Schema: play; Owner: -
--

ALTER TABLE ONLY play.historico ALTER COLUMN id SET DEFAULT nextval('play.historico_id_seq'::regclass);


--
-- Name: legenda id; Type: DEFAULT; Schema: play; Owner: -
--

ALTER TABLE ONLY play.legenda ALTER COLUMN id SET DEFAULT nextval('play.legenda_id_seq'::regclass);


--
-- Name: perfil id; Type: DEFAULT; Schema: play; Owner: -
--

ALTER TABLE ONLY play.perfil ALTER COLUMN id SET DEFAULT nextval('play.perfil_id_seq'::regclass);


--
-- Name: programa id; Type: DEFAULT; Schema: play; Owner: -
--

ALTER TABLE ONLY play.programa ALTER COLUMN id SET DEFAULT nextval('play.programa_id_seq'::regclass);


--
-- Name: sinal id; Type: DEFAULT; Schema: play; Owner: -
--

ALTER TABLE ONLY play.sinal ALTER COLUMN id SET DEFAULT nextval('play.sinal_id_seq'::regclass);


--
-- Name: titulo id; Type: DEFAULT; Schema: play; Owner: -
--

ALTER TABLE ONLY play.titulo ALTER COLUMN id SET DEFAULT nextval('play.titulo_id_seq'::regclass);


--
-- Name: tv id; Type: DEFAULT; Schema: play; Owner: -
--

ALTER TABLE ONLY play.tv ALTER COLUMN id SET DEFAULT nextval('play.tv_id_seq'::regclass);


--
-- Name: comando id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.comando ALTER COLUMN id SET DEFAULT nextval('public.comando_id_seq'::regclass);


--
-- Name: dispositivo id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.dispositivo ALTER COLUMN id SET DEFAULT nextval('public.dispositivo_id_seq'::regclass);


--
-- Name: evento id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.evento ALTER COLUMN id SET DEFAULT nextval('public.evento_id_seq'::regclass);


--
-- Name: login_tentativa id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.login_tentativa ALTER COLUMN id SET DEFAULT nextval('public.login_tentativa_id_seq'::regclass);


--
-- Name: usuario id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.usuario ALTER COLUMN id SET DEFAULT nextval('public.usuario_id_seq'::regclass);


--
-- Name: abertura abertura_pkey; Type: CONSTRAINT; Schema: play; Owner: -
--

ALTER TABLE ONLY play.abertura
    ADD CONSTRAINT abertura_pkey PRIMARY KEY (titulo_id);


--
-- Name: aviso aviso_pkey; Type: CONSTRAINT; Schema: play; Owner: -
--

ALTER TABLE ONLY play.aviso
    ADD CONSTRAINT aviso_pkey PRIMARY KEY (id);


--
-- Name: cache cache_pkey; Type: CONSTRAINT; Schema: play; Owner: -
--

ALTER TABLE ONLY play.cache
    ADD CONSTRAINT cache_pkey PRIMARY KEY (chave);


--
-- Name: canal_fav canal_fav_pkey; Type: CONSTRAINT; Schema: play; Owner: -
--

ALTER TABLE ONLY play.canal_fav
    ADD CONSTRAINT canal_fav_pkey PRIMARY KEY (perfil_id, conta_id, canal_id);


--
-- Name: canal canal_pkey; Type: CONSTRAINT; Schema: play; Owner: -
--

ALTER TABLE ONLY play.canal
    ADD CONSTRAINT canal_pkey PRIMARY KEY (conta_id, painel_id);


--
-- Name: categoria categoria_pkey; Type: CONSTRAINT; Schema: play; Owner: -
--

ALTER TABLE ONLY play.categoria
    ADD CONSTRAINT categoria_pkey PRIMARY KEY (conta_id, tipo, painel_id);


--
-- Name: colecao colecao_pkey; Type: CONSTRAINT; Schema: play; Owner: -
--

ALTER TABLE ONLY play.colecao
    ADD CONSTRAINT colecao_pkey PRIMARY KEY (id);


--
-- Name: conta conta_pkey; Type: CONSTRAINT; Schema: play; Owner: -
--

ALTER TABLE ONLY play.conta
    ADD CONSTRAINT conta_pkey PRIMARY KEY (id);


--
-- Name: episodio episodio_pkey; Type: CONSTRAINT; Schema: play; Owner: -
--

ALTER TABLE ONLY play.episodio
    ADD CONSTRAINT episodio_pkey PRIMARY KEY (conta_id, serie_id, ep_id);


--
-- Name: escudo escudo_pkey; Type: CONSTRAINT; Schema: play; Owner: -
--

ALTER TABLE ONLY play.escudo
    ADD CONSTRAINT escudo_pkey PRIMARY KEY (chave);


--
-- Name: historico historico_pkey; Type: CONSTRAINT; Schema: play; Owner: -
--

ALTER TABLE ONLY play.historico
    ADD CONSTRAINT historico_pkey PRIMARY KEY (id);


--
-- Name: jogo jogo_pkey; Type: CONSTRAINT; Schema: play; Owner: -
--

ALTER TABLE ONLY play.jogo
    ADD CONSTRAINT jogo_pkey PRIMARY KEY (id);


--
-- Name: legenda legenda_pkey; Type: CONSTRAINT; Schema: play; Owner: -
--

ALTER TABLE ONLY play.legenda
    ADD CONSTRAINT legenda_pkey PRIMARY KEY (id);


--
-- Name: lista lista_pkey; Type: CONSTRAINT; Schema: play; Owner: -
--

ALTER TABLE ONLY play.lista
    ADD CONSTRAINT lista_pkey PRIMARY KEY (perfil_id, titulo_id);


--
-- Name: nota nota_pkey; Type: CONSTRAINT; Schema: play; Owner: -
--

ALTER TABLE ONLY play.nota
    ADD CONSTRAINT nota_pkey PRIMARY KEY (perfil_id, titulo_id);


--
-- Name: perfil perfil_pkey; Type: CONSTRAINT; Schema: play; Owner: -
--

ALTER TABLE ONLY play.perfil
    ADD CONSTRAINT perfil_pkey PRIMARY KEY (id);


--
-- Name: pessoa pessoa_pkey; Type: CONSTRAINT; Schema: play; Owner: -
--

ALTER TABLE ONLY play.pessoa
    ADD CONSTRAINT pessoa_pkey PRIMARY KEY (tmdb_id);


--
-- Name: plataforma plataforma_pkey; Type: CONSTRAINT; Schema: play; Owner: -
--

ALTER TABLE ONLY play.plataforma
    ADD CONSTRAINT plataforma_pkey PRIMARY KEY (codigo);


--
-- Name: preferencia preferencia_pkey; Type: CONSTRAINT; Schema: play; Owner: -
--

ALTER TABLE ONLY play.preferencia
    ADD CONSTRAINT preferencia_pkey PRIMARY KEY (perfil_id, chave);


--
-- Name: programa programa_conta_id_canal_id_inicio_key; Type: CONSTRAINT; Schema: play; Owner: -
--

ALTER TABLE ONLY play.programa
    ADD CONSTRAINT programa_conta_id_canal_id_inicio_key UNIQUE (conta_id, canal_id, inicio);


--
-- Name: programa programa_pkey; Type: CONSTRAINT; Schema: play; Owner: -
--

ALTER TABLE ONLY play.programa
    ADD CONSTRAINT programa_pkey PRIMARY KEY (id);


--
-- Name: progresso_ep progresso_ep_pkey; Type: CONSTRAINT; Schema: play; Owner: -
--

ALTER TABLE ONLY play.progresso_ep
    ADD CONSTRAINT progresso_ep_pkey PRIMARY KEY (perfil_id, titulo_id, temporada, episodio);


--
-- Name: progresso progresso_pkey; Type: CONSTRAINT; Schema: play; Owner: -
--

ALTER TABLE ONLY play.progresso
    ADD CONSTRAINT progresso_pkey PRIMARY KEY (perfil_id, titulo_id);


--
-- Name: recomendacao recomendacao_pkey; Type: CONSTRAINT; Schema: play; Owner: -
--

ALTER TABLE ONLY play.recomendacao
    ADD CONSTRAINT recomendacao_pkey PRIMARY KEY (perfil_id);


--
-- Name: serie_info serie_info_pkey; Type: CONSTRAINT; Schema: play; Owner: -
--

ALTER TABLE ONLY play.serie_info
    ADD CONSTRAINT serie_info_pkey PRIMARY KEY (conta_id, serie_id);


--
-- Name: serie_seguida serie_seguida_pkey; Type: CONSTRAINT; Schema: play; Owner: -
--

ALTER TABLE ONLY play.serie_seguida
    ADD CONSTRAINT serie_seguida_pkey PRIMARY KEY (perfil_id, titulo_id);


--
-- Name: sinal sinal_pkey; Type: CONSTRAINT; Schema: play; Owner: -
--

ALTER TABLE ONLY play.sinal
    ADD CONSTRAINT sinal_pkey PRIMARY KEY (id);


--
-- Name: tarefa tarefa_pkey; Type: CONSTRAINT; Schema: play; Owner: -
--

ALTER TABLE ONLY play.tarefa
    ADD CONSTRAINT tarefa_pkey PRIMARY KEY (nome);


--
-- Name: titulo_pessoa titulo_pessoa_pkey; Type: CONSTRAINT; Schema: play; Owner: -
--

ALTER TABLE ONLY play.titulo_pessoa
    ADD CONSTRAINT titulo_pessoa_pkey PRIMARY KEY (titulo_id, pessoa_id, papel);


--
-- Name: titulo titulo_pkey; Type: CONSTRAINT; Schema: play; Owner: -
--

ALTER TABLE ONLY play.titulo
    ADD CONSTRAINT titulo_pkey PRIMARY KEY (id);


--
-- Name: top10 top10_pkey; Type: CONSTRAINT; Schema: play; Owner: -
--

ALTER TABLE ONLY play.top10
    ADD CONSTRAINT top10_pkey PRIMARY KEY (coletado_em, tipo, plataforma, posicao);


--
-- Name: tv tv_pkey; Type: CONSTRAINT; Schema: play; Owner: -
--

ALTER TABLE ONLY play.tv
    ADD CONSTRAINT tv_pkey PRIMARY KEY (id);


--
-- Name: tv tv_token_hash_key; Type: CONSTRAINT; Schema: play; Owner: -
--

ALTER TABLE ONLY play.tv
    ADD CONSTRAINT tv_token_hash_key UNIQUE (token_hash);


--
-- Name: versao_escolhida versao_escolhida_pkey; Type: CONSTRAINT; Schema: play; Owner: -
--

ALTER TABLE ONLY play.versao_escolhida
    ADD CONSTRAINT versao_escolhida_pkey PRIMARY KEY (perfil_id, titulo_id);


--
-- Name: versao versao_pkey; Type: CONSTRAINT; Schema: play; Owner: -
--

ALTER TABLE ONLY play.versao
    ADD CONSTRAINT versao_pkey PRIMARY KEY (conta_id, tipo, painel_id);


--
-- Name: comando comando_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.comando
    ADD CONSTRAINT comando_pkey PRIMARY KEY (id);


--
-- Name: configuracao configuracao_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.configuracao
    ADD CONSTRAINT configuracao_pkey PRIMARY KEY (chave);


--
-- Name: dispositivo dispositivo_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.dispositivo
    ADD CONSTRAINT dispositivo_pkey PRIMARY KEY (id);


--
-- Name: dispositivo dispositivo_slug_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.dispositivo
    ADD CONSTRAINT dispositivo_slug_key UNIQUE (slug);


--
-- Name: estado estado_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.estado
    ADD CONSTRAINT estado_pkey PRIMARY KEY (dispositivo_id);


--
-- Name: evento evento_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.evento
    ADD CONSTRAINT evento_pkey PRIMARY KEY (id);


--
-- Name: login_tentativa login_tentativa_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.login_tentativa
    ADD CONSTRAINT login_tentativa_pkey PRIMARY KEY (id);


--
-- Name: metrica metrica_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.metrica
    ADD CONSTRAINT metrica_pkey PRIMARY KEY (coletado_em);


--
-- Name: metrica_rede metrica_rede_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.metrica_rede
    ADD CONSTRAINT metrica_rede_pkey PRIMARY KEY (coletado_em);


--
-- Name: migracao migracao_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.migracao
    ADD CONSTRAINT migracao_pkey PRIMARY KEY (arquivo);


--
-- Name: rede_dispositivo rede_dispositivo_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.rede_dispositivo
    ADD CONSTRAINT rede_dispositivo_pkey PRIMARY KEY (mac);


--
-- Name: usuario usuario_login_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.usuario
    ADD CONSTRAINT usuario_login_key UNIQUE (login);


--
-- Name: usuario usuario_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.usuario
    ADD CONSTRAINT usuario_pkey PRIMARY KEY (id);


--
-- Name: aviso_ativo; Type: INDEX; Schema: play; Owner: -
--

CREATE INDEX aviso_ativo ON play.aviso USING btree (status, quando);


--
-- Name: aviso_chegar; Type: INDEX; Schema: play; Owner: -
--

CREATE INDEX aviso_chegar ON play.aviso USING btree (tipo) WHERE ((status = 'ativo'::text) AND (quando IS NULL));


--
-- Name: aviso_perfil; Type: INDEX; Schema: play; Owner: -
--

CREATE INDEX aviso_perfil ON play.aviso USING btree (perfil_id, tipo, status);


--
-- Name: cache_em; Type: INDEX; Schema: play; Owner: -
--

CREATE INDEX cache_em ON play.cache USING btree (em);


--
-- Name: canal_nome_trgm; Type: INDEX; Schema: play; Owner: -
--

CREATE INDEX canal_nome_trgm ON play.canal USING gin (nome_norm public.gin_trgm_ops);


--
-- Name: conta_uma_ativa; Type: INDEX; Schema: play; Owner: -
--

CREATE UNIQUE INDEX conta_uma_ativa ON play.conta USING btree ((true)) WHERE ativa;


--
-- Name: episodio_ord; Type: INDEX; Schema: play; Owner: -
--

CREATE INDEX episodio_ord ON play.episodio USING btree (conta_id, serie_id, temporada, numero);


--
-- Name: historico_inicio; Type: INDEX; Schema: play; Owner: -
--

CREATE INDEX historico_inicio ON play.historico USING btree (inicio);


--
-- Name: historico_perfil; Type: INDEX; Schema: play; Owner: -
--

CREATE INDEX historico_perfil ON play.historico USING btree (perfil_id, inicio DESC);


--
-- Name: historico_titulo; Type: INDEX; Schema: play; Owner: -
--

CREATE INDEX historico_titulo ON play.historico USING btree (perfil_id, titulo_id);


--
-- Name: historico_titulo_todos; Type: INDEX; Schema: play; Owner: -
--

CREATE INDEX historico_titulo_todos ON play.historico USING btree (titulo_id);


--
-- Name: jogo_canal; Type: INDEX; Schema: play; Owner: -
--

CREATE INDEX jogo_canal ON play.jogo USING btree (canal_id);


--
-- Name: jogo_data; Type: INDEX; Schema: play; Owner: -
--

CREATE INDEX jogo_data ON play.jogo USING btree (data);


--
-- Name: legenda_alvo; Type: INDEX; Schema: play; Owner: -
--

CREATE INDEX legenda_alvo ON play.legenda USING btree (titulo_id, temporada, episodio);


--
-- Name: pessoa_nome_trgm; Type: INDEX; Schema: play; Owner: -
--

CREATE INDEX pessoa_nome_trgm ON play.pessoa USING gin (nome_norm public.gin_trgm_ops);


--
-- Name: programa_janela; Type: INDEX; Schema: play; Owner: -
--

CREATE INDEX programa_janela ON play.programa USING btree (conta_id, canal_id, fim);


--
-- Name: progresso_rec; Type: INDEX; Schema: play; Owner: -
--

CREATE INDEX progresso_rec ON play.progresso USING btree (perfil_id, atualizado_em DESC);


--
-- Name: progresso_titulo; Type: INDEX; Schema: play; Owner: -
--

CREATE INDEX progresso_titulo ON play.progresso USING btree (titulo_id);


--
-- Name: sinal_criado; Type: INDEX; Schema: play; Owner: -
--

CREATE INDEX sinal_criado ON play.sinal USING btree (criado_em);


--
-- Name: titulo_adic; Type: INDEX; Schema: play; Owner: -
--

CREATE INDEX titulo_adic ON play.titulo USING btree (tipo, adicionado_em DESC) WHERE ativo;


--
-- Name: titulo_cats; Type: INDEX; Schema: play; Owner: -
--

CREATE INDEX titulo_cats ON play.titulo USING gin (categorias);


--
-- Name: titulo_colecao; Type: INDEX; Schema: play; Owner: -
--

CREATE INDEX titulo_colecao ON play.titulo USING btree (colecao_id) WHERE (colecao_id IS NOT NULL);


--
-- Name: titulo_link; Type: INDEX; Schema: play; Owner: -
--

CREATE INDEX titulo_link ON play.titulo USING btree (link_testado_em) WHERE ativo;


--
-- Name: titulo_nome_trgm; Type: INDEX; Schema: play; Owner: -
--

CREATE INDEX titulo_nome_trgm ON play.titulo USING gin (nome_norm public.gin_trgm_ops);


--
-- Name: titulo_pessoa_p; Type: INDEX; Schema: play; Owner: -
--

CREATE INDEX titulo_pessoa_p ON play.titulo_pessoa USING btree (pessoa_id);


--
-- Name: titulo_sem_detalhes; Type: INDEX; Schema: play; Owner: -
--

CREATE INDEX titulo_sem_detalhes ON play.titulo USING btree (adicionado_em DESC) WHERE (ativo AND (detalhes_em IS NULL));


--
-- Name: titulo_tmdb; Type: INDEX; Schema: play; Owner: -
--

CREATE INDEX titulo_tmdb ON play.titulo USING btree (tipo, tmdb_id);


--
-- Name: top10_titulo; Type: INDEX; Schema: play; Owner: -
--

CREATE INDEX top10_titulo ON play.top10 USING btree (titulo_id) WHERE (titulo_id IS NOT NULL);


--
-- Name: top10_ult; Type: INDEX; Schema: play; Owner: -
--

CREATE INDEX top10_ult ON play.top10 USING btree (tipo, plataforma, coletado_em DESC);


--
-- Name: tv_dispositivo; Type: INDEX; Schema: play; Owner: -
--

CREATE INDEX tv_dispositivo ON play.tv USING btree (dispositivo_slug) WHERE (dispositivo_slug IS NOT NULL);


--
-- Name: tv_duid; Type: INDEX; Schema: play; Owner: -
--

CREATE INDEX tv_duid ON play.tv USING btree (duid) WHERE (duid IS NOT NULL);


--
-- Name: versao_conta_ativo; Type: INDEX; Schema: play; Owner: -
--

CREATE INDEX versao_conta_ativo ON play.versao USING btree (conta_id, tipo) WHERE ativo;


--
-- Name: versao_titulo; Type: INDEX; Schema: play; Owner: -
--

CREATE INDEX versao_titulo ON play.versao USING btree (titulo_id);


--
-- Name: comando_disp_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX comando_disp_idx ON public.comando USING btree (dispositivo_id, id DESC);


--
-- Name: comando_pendente_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX comando_pendente_idx ON public.comando USING btree (id) WHERE (status = 'pendente'::text);


--
-- Name: evento_quando_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX evento_quando_idx ON public.evento USING btree (criado_em DESC);


--
-- Name: login_tentativa_quando_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX login_tentativa_quando_idx ON public.login_tentativa USING btree (criado_em);


--
-- Name: rede_dispositivo_ip_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX rede_dispositivo_ip_idx ON public.rede_dispositivo USING btree (ip);


--
-- Name: abertura abertura_titulo_id_fkey; Type: FK CONSTRAINT; Schema: play; Owner: -
--

ALTER TABLE ONLY play.abertura
    ADD CONSTRAINT abertura_titulo_id_fkey FOREIGN KEY (titulo_id) REFERENCES play.titulo(id) ON DELETE CASCADE;


--
-- Name: aviso aviso_perfil_id_fkey; Type: FK CONSTRAINT; Schema: play; Owner: -
--

ALTER TABLE ONLY play.aviso
    ADD CONSTRAINT aviso_perfil_id_fkey FOREIGN KEY (perfil_id) REFERENCES play.perfil(id) ON DELETE CASCADE;


--
-- Name: canal canal_conta_id_fkey; Type: FK CONSTRAINT; Schema: play; Owner: -
--

ALTER TABLE ONLY play.canal
    ADD CONSTRAINT canal_conta_id_fkey FOREIGN KEY (conta_id) REFERENCES play.conta(id) ON DELETE CASCADE;


--
-- Name: canal_fav canal_fav_perfil_id_fkey; Type: FK CONSTRAINT; Schema: play; Owner: -
--

ALTER TABLE ONLY play.canal_fav
    ADD CONSTRAINT canal_fav_perfil_id_fkey FOREIGN KEY (perfil_id) REFERENCES play.perfil(id) ON DELETE CASCADE;


--
-- Name: categoria categoria_conta_id_fkey; Type: FK CONSTRAINT; Schema: play; Owner: -
--

ALTER TABLE ONLY play.categoria
    ADD CONSTRAINT categoria_conta_id_fkey FOREIGN KEY (conta_id) REFERENCES play.conta(id) ON DELETE CASCADE;


--
-- Name: historico historico_perfil_id_fkey; Type: FK CONSTRAINT; Schema: play; Owner: -
--

ALTER TABLE ONLY play.historico
    ADD CONSTRAINT historico_perfil_id_fkey FOREIGN KEY (perfil_id) REFERENCES play.perfil(id) ON DELETE CASCADE;


--
-- Name: historico historico_titulo_id_fkey; Type: FK CONSTRAINT; Schema: play; Owner: -
--

ALTER TABLE ONLY play.historico
    ADD CONSTRAINT historico_titulo_id_fkey FOREIGN KEY (titulo_id) REFERENCES play.titulo(id) ON DELETE CASCADE;


--
-- Name: historico historico_tv_id_fkey; Type: FK CONSTRAINT; Schema: play; Owner: -
--

ALTER TABLE ONLY play.historico
    ADD CONSTRAINT historico_tv_id_fkey FOREIGN KEY (tv_id) REFERENCES play.tv(id) ON DELETE SET NULL;


--
-- Name: legenda legenda_titulo_id_fkey; Type: FK CONSTRAINT; Schema: play; Owner: -
--

ALTER TABLE ONLY play.legenda
    ADD CONSTRAINT legenda_titulo_id_fkey FOREIGN KEY (titulo_id) REFERENCES play.titulo(id) ON DELETE CASCADE;


--
-- Name: lista lista_perfil_id_fkey; Type: FK CONSTRAINT; Schema: play; Owner: -
--

ALTER TABLE ONLY play.lista
    ADD CONSTRAINT lista_perfil_id_fkey FOREIGN KEY (perfil_id) REFERENCES play.perfil(id) ON DELETE CASCADE;


--
-- Name: lista lista_titulo_id_fkey; Type: FK CONSTRAINT; Schema: play; Owner: -
--

ALTER TABLE ONLY play.lista
    ADD CONSTRAINT lista_titulo_id_fkey FOREIGN KEY (titulo_id) REFERENCES play.titulo(id) ON DELETE CASCADE;


--
-- Name: nota nota_perfil_id_fkey; Type: FK CONSTRAINT; Schema: play; Owner: -
--

ALTER TABLE ONLY play.nota
    ADD CONSTRAINT nota_perfil_id_fkey FOREIGN KEY (perfil_id) REFERENCES play.perfil(id) ON DELETE CASCADE;


--
-- Name: nota nota_titulo_id_fkey; Type: FK CONSTRAINT; Schema: play; Owner: -
--

ALTER TABLE ONLY play.nota
    ADD CONSTRAINT nota_titulo_id_fkey FOREIGN KEY (titulo_id) REFERENCES play.titulo(id) ON DELETE CASCADE;


--
-- Name: preferencia preferencia_perfil_id_fkey; Type: FK CONSTRAINT; Schema: play; Owner: -
--

ALTER TABLE ONLY play.preferencia
    ADD CONSTRAINT preferencia_perfil_id_fkey FOREIGN KEY (perfil_id) REFERENCES play.perfil(id) ON DELETE CASCADE;


--
-- Name: progresso_ep progresso_ep_perfil_id_fkey; Type: FK CONSTRAINT; Schema: play; Owner: -
--

ALTER TABLE ONLY play.progresso_ep
    ADD CONSTRAINT progresso_ep_perfil_id_fkey FOREIGN KEY (perfil_id) REFERENCES play.perfil(id) ON DELETE CASCADE;


--
-- Name: progresso_ep progresso_ep_titulo_id_fkey; Type: FK CONSTRAINT; Schema: play; Owner: -
--

ALTER TABLE ONLY play.progresso_ep
    ADD CONSTRAINT progresso_ep_titulo_id_fkey FOREIGN KEY (titulo_id) REFERENCES play.titulo(id) ON DELETE CASCADE;


--
-- Name: progresso progresso_perfil_id_fkey; Type: FK CONSTRAINT; Schema: play; Owner: -
--

ALTER TABLE ONLY play.progresso
    ADD CONSTRAINT progresso_perfil_id_fkey FOREIGN KEY (perfil_id) REFERENCES play.perfil(id) ON DELETE CASCADE;


--
-- Name: progresso progresso_titulo_id_fkey; Type: FK CONSTRAINT; Schema: play; Owner: -
--

ALTER TABLE ONLY play.progresso
    ADD CONSTRAINT progresso_titulo_id_fkey FOREIGN KEY (titulo_id) REFERENCES play.titulo(id) ON DELETE CASCADE;


--
-- Name: progresso progresso_tv_id_fkey; Type: FK CONSTRAINT; Schema: play; Owner: -
--

ALTER TABLE ONLY play.progresso
    ADD CONSTRAINT progresso_tv_id_fkey FOREIGN KEY (tv_id) REFERENCES play.tv(id) ON DELETE SET NULL;


--
-- Name: recomendacao recomendacao_perfil_id_fkey; Type: FK CONSTRAINT; Schema: play; Owner: -
--

ALTER TABLE ONLY play.recomendacao
    ADD CONSTRAINT recomendacao_perfil_id_fkey FOREIGN KEY (perfil_id) REFERENCES play.perfil(id) ON DELETE CASCADE;


--
-- Name: serie_seguida serie_seguida_perfil_id_fkey; Type: FK CONSTRAINT; Schema: play; Owner: -
--

ALTER TABLE ONLY play.serie_seguida
    ADD CONSTRAINT serie_seguida_perfil_id_fkey FOREIGN KEY (perfil_id) REFERENCES play.perfil(id) ON DELETE CASCADE;


--
-- Name: serie_seguida serie_seguida_titulo_id_fkey; Type: FK CONSTRAINT; Schema: play; Owner: -
--

ALTER TABLE ONLY play.serie_seguida
    ADD CONSTRAINT serie_seguida_titulo_id_fkey FOREIGN KEY (titulo_id) REFERENCES play.titulo(id) ON DELETE CASCADE;


--
-- Name: titulo_pessoa titulo_pessoa_pessoa_id_fkey; Type: FK CONSTRAINT; Schema: play; Owner: -
--

ALTER TABLE ONLY play.titulo_pessoa
    ADD CONSTRAINT titulo_pessoa_pessoa_id_fkey FOREIGN KEY (pessoa_id) REFERENCES play.pessoa(tmdb_id) ON DELETE CASCADE;


--
-- Name: titulo_pessoa titulo_pessoa_titulo_id_fkey; Type: FK CONSTRAINT; Schema: play; Owner: -
--

ALTER TABLE ONLY play.titulo_pessoa
    ADD CONSTRAINT titulo_pessoa_titulo_id_fkey FOREIGN KEY (titulo_id) REFERENCES play.titulo(id) ON DELETE CASCADE;


--
-- Name: top10 top10_plataforma_fkey; Type: FK CONSTRAINT; Schema: play; Owner: -
--

ALTER TABLE ONLY play.top10
    ADD CONSTRAINT top10_plataforma_fkey FOREIGN KEY (plataforma) REFERENCES play.plataforma(codigo);


--
-- Name: top10 top10_titulo_id_fkey; Type: FK CONSTRAINT; Schema: play; Owner: -
--

ALTER TABLE ONLY play.top10
    ADD CONSTRAINT top10_titulo_id_fkey FOREIGN KEY (titulo_id) REFERENCES play.titulo(id) ON DELETE SET NULL;


--
-- Name: tv tv_perfil_id_fkey; Type: FK CONSTRAINT; Schema: play; Owner: -
--

ALTER TABLE ONLY play.tv
    ADD CONSTRAINT tv_perfil_id_fkey FOREIGN KEY (perfil_id) REFERENCES play.perfil(id) ON DELETE SET NULL;


--
-- Name: versao versao_conta_id_fkey; Type: FK CONSTRAINT; Schema: play; Owner: -
--

ALTER TABLE ONLY play.versao
    ADD CONSTRAINT versao_conta_id_fkey FOREIGN KEY (conta_id) REFERENCES play.conta(id) ON DELETE CASCADE;


--
-- Name: versao_escolhida versao_escolhida_perfil_id_fkey; Type: FK CONSTRAINT; Schema: play; Owner: -
--

ALTER TABLE ONLY play.versao_escolhida
    ADD CONSTRAINT versao_escolhida_perfil_id_fkey FOREIGN KEY (perfil_id) REFERENCES play.perfil(id) ON DELETE CASCADE;


--
-- Name: versao_escolhida versao_escolhida_titulo_id_fkey; Type: FK CONSTRAINT; Schema: play; Owner: -
--

ALTER TABLE ONLY play.versao_escolhida
    ADD CONSTRAINT versao_escolhida_titulo_id_fkey FOREIGN KEY (titulo_id) REFERENCES play.titulo(id) ON DELETE CASCADE;


--
-- Name: versao versao_titulo_id_fkey; Type: FK CONSTRAINT; Schema: play; Owner: -
--

ALTER TABLE ONLY play.versao
    ADD CONSTRAINT versao_titulo_id_fkey FOREIGN KEY (titulo_id) REFERENCES play.titulo(id) ON DELETE SET NULL;


--
-- Name: comando comando_dispositivo_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.comando
    ADD CONSTRAINT comando_dispositivo_id_fkey FOREIGN KEY (dispositivo_id) REFERENCES public.dispositivo(id) ON DELETE CASCADE;


--
-- Name: estado estado_dispositivo_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.estado
    ADD CONSTRAINT estado_dispositivo_id_fkey FOREIGN KEY (dispositivo_id) REFERENCES public.dispositivo(id) ON DELETE CASCADE;


--
-- PostgreSQL database dump complete
--


