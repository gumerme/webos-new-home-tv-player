# Banco de dados

PostgreSQL 17. Dois esquemas: `public` (sistema da casa: painel, aparelhos, métricas) e `play` (WebOS TV Player).

- **Não é preciso criar nada à mão.** O `casa-worker` aplica sozinho, na subida, as migrações de
  `homeserver/casa/sql/*.sql` em ordem e registra cada uma em `public.migracao`.
- [`db/schema.sql`](../db/schema.sql) é o esquema completo (só estrutura, `pg_dump --schema-only`), para consulta
  ou para criar o banco de uma vez: `psql -U casa -d casa -f db/schema.sql`. Nenhum dado vai junto.
- Segredos (senha do painel Xtream, tokens) só existem no banco e no `.env` do seu servidor; nunca no repositório.

## Tabelas

| Tabela | Para que serve |
|---|---|
| `play.abertura` | Aberturas do app por TV (uso e estatísticas). |
| `play.aviso` | "Avisar": jogo, programa, episódio, título que chegou, vencimento da conta. |
| `play.cache` | Cache genérico chave/valor (respostas TMDB/JustWatch, clima, trailers, vizinhos do recomendador). |
| `play.canal` | Canais ao vivo do painel, com grupo, logo e id do EPG. |
| `play.canal_fav` | Canais favoritos por perfil. |
| `play.categoria` | Categorias do painel (filmes, séries, canais). |
| `play.colecao` | Coleções/sagas do TMDB (ordem dos filmes). |
| `play.conta` | Conta do painel Xtream (servidor, usuário, senha). Fica só no banco do seu homeserver. |
| `play.episodio` | Episódios de cada série (get_series_info), atualizados sob demanda. |
| `play.escudo` | Escudos dos times (cache). |
| `play.historico` | Histórico de reprodução (base das recomendações). |
| `play.jogo` | Jogos do dia (ESPN) com placar e canal provável. |
| `play.legenda` | Legendas baixadas (cache, Stremio/OpenSubtitles). |
| `play.lista` | Minha lista por perfil. |
| `play.nota` | Gostei / Não gostei por perfil. |
| `play.perfil` | Perfis da casa (nome, cor). |
| `play.pessoa` | Elenco e direção (TMDB). |
| `play.plataforma` | Plataformas do ranking (Netflix, Prime, Disney+, HBO Max, Globoplay, Apple TV...). |
| `play.preferencia` | Preferências por perfil (legenda, último canal...). |
| `play.programa` | Grade de programação (EPG) por canal. |
| `play.progresso` | Onde cada perfil parou em cada título (Continuar assistindo). |
| `play.progresso_ep` | Progresso por episódio (vistos na lista de episódios). |
| `play.recomendacao` | Fileiras pré-calculadas por perfil (o Início sai pronto). |
| `play.serie_info` | Informações extras da série (temporadas, próximo episódio). |
| `play.serie_seguida` | Séries seguidas (aviso de episódio novo). |
| `play.sinal` | Sinais para as TVs (SSE). NOTIFY play_sinal com o id. |
| `play.tarefa` | Tarefas agendadas do worker casa-play (catálogo, EPG, Top 10, clima...) e a última execução. |
| `play.titulo` | Uma linha por obra (filme ou série), com dados do TMDB/JustWatch. |
| `play.titulo_pessoa` | Ligação título × pessoa (papel e ordem). |
| `play.top10` | Top 10 diário por plataforma (JustWatch), com a posição do dia anterior. |
| `play.tv` | TVs pareadas: código de aprovação, hash do token, perfil padrão, versão do app, último contato. |
| `play.versao` | Entradas do painel (stream_id/series_id) ligadas ao título: dublado, legendado, 4K. |
| `play.versao_escolhida` | Versão preferida (dublado/legendado) por perfil e título. |
| `public.comando` | Fila de comandos para o worker (ligar, desligar, volume...). O painel/API grava e faz NOTIFY casa_comando. |
| `public.configuracao` | Configurações chave/valor editáveis pelo painel. |
| `public.dispositivo` | Aparelhos da casa (TVs LG etc.): driver, cômodo, config (IP, modelo, chave de pareamento). |
| `public.estado` | Último estado lido de cada aparelho (ligado, app em primeiro plano, volume). |
| `public.evento` | Linha do tempo de eventos (container caiu/voltou, internet, backups, aparelhos). |
| `public.login_tentativa` | Tentativas de login (bloqueio após 5 erros em 15 min). |
| `public.metrica` | Métricas do servidor a cada 30 s (CPU, RAM, disco, energia). Guardada 30 dias. |
| `public.metrica_rede` | Latência/perda da internet por minuto (coletor do host). Guardada 30 dias. |
| `public.migracao` | Controle das migrações aplicadas (criada pelo próprio código, Db.php). |
| `public.rede_dispositivo` | Aparelhos vistos na rede local (varredura a cada 5 min). Chave = MAC. |
| `public.usuario` | Usuários do painel web (admin/morador). Senha só como hash. |

## Colunas

### `play.abertura`

Aberturas do app por TV (uso e estatísticas).

Chave primária: `titulo_id`

```sql
    titulo_id bigint NOT NULL,
    inicio_s integer NOT NULL,
    fim_s integer NOT NULL,
    amostras integer DEFAULT 1 NOT NULL,
    atualizado_em timestamp with time zone DEFAULT now() NOT NULL
```

### `play.aviso`

"Avisar": jogo, programa, episódio, título que chegou, vencimento da conta.

Chave primária: `id`

```sql
    id bigint NOT NULL,
    perfil_id integer,
    tipo text NOT NULL,
    alvo jsonb NOT NULL,
    quando timestamp with time zone,
    status text DEFAULT 'ativo'::text NOT NULL,
    texto text,
    criado_em timestamp with time zone DEFAULT now() NOT NULL,
    disparado_em timestamp with time zone
```

### `play.cache`

Cache genérico chave/valor (respostas TMDB/JustWatch, clima, trailers, vizinhos do recomendador).

Chave primária: `chave`

```sql
    chave text NOT NULL,
    dados jsonb NOT NULL,
    em timestamp with time zone DEFAULT now() NOT NULL
```

### `play.canal`

Canais ao vivo do painel, com grupo, logo e id do EPG.

Chave primária: `conta_id, painel_id`

```sql
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
```

### `play.canal_fav`

Canais favoritos por perfil.

Chave primária: `perfil_id, conta_id, canal_id`

```sql
    perfil_id integer NOT NULL,
    conta_id integer NOT NULL,
    canal_id integer NOT NULL,
    ordem integer DEFAULT 0 NOT NULL
```

### `play.categoria`

Categorias do painel (filmes, séries, canais).

Chave primária: `conta_id, tipo, painel_id`

```sql
    conta_id integer NOT NULL,
    tipo character(1) NOT NULL,
    painel_id integer NOT NULL,
    nome text NOT NULL,
    nome_exib text NOT NULL,
    genero boolean DEFAULT true NOT NULL
```

### `play.colecao`

Coleções/sagas do TMDB (ordem dos filmes).

Chave primária: `id`

```sql
    id integer NOT NULL,
    nome text NOT NULL,
    partes jsonb DEFAULT '[]'::jsonb NOT NULL,
    fundo text,
    atualizado_em timestamp with time zone DEFAULT now() NOT NULL
```

### `play.conta`

Conta do painel Xtream (servidor, usuário, senha). Fica só no banco do seu homeserver.

Chave primária: `id`

```sql
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
```

### `play.episodio`

Episódios de cada série (get_series_info), atualizados sob demanda.

Chave primária: `conta_id, serie_id, ep_id`

```sql
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
```

### `play.escudo`

Escudos dos times (cache).

Chave primária: `chave`

```sql
    chave text NOT NULL,
    nome text NOT NULL,
    sigla text,
    url text,
    atualizado_em timestamp with time zone DEFAULT now() NOT NULL
```

### `play.historico`

Histórico de reprodução (base das recomendações).

Chave primária: `id`

```sql
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
```

### `play.jogo`

Jogos do dia (ESPN) com placar e canal provável.

Chave primária: `id`

```sql
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
```

### `play.legenda`

Legendas baixadas (cache, Stremio/OpenSubtitles).

Chave primária: `id`

```sql
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
```

### `play.lista`

Minha lista por perfil.

Chave primária: `perfil_id, titulo_id`

```sql
    perfil_id integer NOT NULL,
    titulo_id bigint NOT NULL,
    adicionado_em timestamp with time zone DEFAULT now() NOT NULL
```

### `play.nota`

Gostei / Não gostei por perfil.

Chave primária: `perfil_id, titulo_id`

```sql
    perfil_id integer NOT NULL,
    titulo_id bigint NOT NULL,
    valor smallint NOT NULL,
    em timestamp with time zone DEFAULT now() NOT NULL
```

### `play.perfil`

Perfis da casa (nome, cor).

Chave primária: `id`

```sql
    id integer NOT NULL,
    nome text NOT NULL,
    cor text DEFAULT '#1E1B4B'::text NOT NULL,
    criado_em timestamp with time zone DEFAULT now() NOT NULL
```

### `play.pessoa`

Elenco e direção (TMDB).

Chave primária: `tmdb_id`

```sql
    tmdb_id integer NOT NULL,
    nome text NOT NULL,
    nome_norm text NOT NULL,
    foto text
```

### `play.plataforma`

Plataformas do ranking (Netflix, Prime, Disney+, HBO Max, Globoplay, Apple TV...).

Chave primária: `codigo`

```sql
    codigo text NOT NULL,
    nome text NOT NULL,
    jw_package text NOT NULL,
    ordem integer NOT NULL,
    ativa boolean DEFAULT true NOT NULL
```

### `play.preferencia`

Preferências por perfil (legenda, último canal...).

Chave primária: `perfil_id, chave`

```sql
    perfil_id integer NOT NULL,
    chave text NOT NULL,
    valor jsonb NOT NULL
```

### `play.programa`

Grade de programação (EPG) por canal.

Chave primária: `id`

```sql
    id bigint NOT NULL,
    conta_id integer NOT NULL,
    canal_id integer NOT NULL,
    inicio timestamp with time zone NOT NULL,
    fim timestamp with time zone NOT NULL,
    titulo text NOT NULL,
    descricao text
```

### `play.progresso`

Onde cada perfil parou em cada título (Continuar assistindo).

Chave primária: `perfil_id, titulo_id`

```sql
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
```

### `play.progresso_ep`

Progresso por episódio (vistos na lista de episódios).

Chave primária: `perfil_id, titulo_id, temporada, episodio`

```sql
    perfil_id integer NOT NULL,
    titulo_id bigint NOT NULL,
    temporada integer NOT NULL,
    episodio integer NOT NULL,
    posicao_s integer DEFAULT 0 NOT NULL,
    duracao_s integer DEFAULT 0 NOT NULL,
    terminado boolean DEFAULT false NOT NULL,
    atualizado_em timestamp with time zone DEFAULT now() NOT NULL
```

### `play.recomendacao`

Fileiras pré-calculadas por perfil (o Início sai pronto).

Chave primária: `perfil_id`

```sql
    perfil_id integer NOT NULL,
    gerado_em timestamp with time zone DEFAULT now() NOT NULL,
    dados jsonb NOT NULL,
    chave text
```

### `play.serie_info`

Informações extras da série (temporadas, próximo episódio).

Chave primária: `conta_id, serie_id`

```sql
    conta_id integer NOT NULL,
    serie_id integer NOT NULL,
    atualizado_em timestamp with time zone DEFAULT now() NOT NULL
```

### `play.serie_seguida`

Séries seguidas (aviso de episódio novo).

Chave primária: `perfil_id, titulo_id`

```sql
    perfil_id integer NOT NULL,
    titulo_id bigint NOT NULL,
    desde timestamp with time zone DEFAULT now() NOT NULL
```

### `play.sinal`

Sinais para as TVs (SSE). NOTIFY play_sinal com o id.

Chave primária: `id`

```sql
    id bigint NOT NULL,
    tv_id integer,
    perfil_id integer,
    exceto_tv integer,
    evento text NOT NULL,
    dados jsonb DEFAULT '{}'::jsonb NOT NULL,
    criado_em timestamp with time zone DEFAULT now() NOT NULL
```

### `play.tarefa`

Tarefas agendadas do worker casa-play (catálogo, EPG, Top 10, clima...) e a última execução.

Chave primária: `nome`

```sql
    nome text NOT NULL,
    ultima_em timestamp with time zone,
    duracao_ms integer,
    status text,
    mensagem text,
    proxima_em timestamp with time zone,
    forcar boolean DEFAULT false NOT NULL
```

### `play.titulo`

Uma linha por obra (filme ou série), com dados do TMDB/JustWatch.

Chave primária: `id`

```sql
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
```

### `play.titulo_pessoa`

Ligação título × pessoa (papel e ordem).

Chave primária: `titulo_id, pessoa_id, papel`

```sql
    titulo_id bigint NOT NULL,
    pessoa_id integer NOT NULL,
    papel text NOT NULL,
    ordem integer DEFAULT 99 NOT NULL,
    personagem text
```

### `play.top10`

Top 10 diário por plataforma (JustWatch), com a posição do dia anterior.

Chave primária: `coletado_em, tipo, plataforma, posicao`

```sql
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
```

### `play.tv`

TVs pareadas: código de aprovação, hash do token, perfil padrão, versão do app, último contato.

Chave primária: `id`

```sql
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
```

### `play.versao`

Entradas do painel (stream_id/series_id) ligadas ao título: dublado, legendado, 4K.

Chave primária: `conta_id, tipo, painel_id`

```sql
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
```

### `play.versao_escolhida`

Versão preferida (dublado/legendado) por perfil e título.

Chave primária: `perfil_id, titulo_id`

```sql
    perfil_id integer NOT NULL,
    titulo_id bigint NOT NULL,
    idioma text NOT NULL
```

### `public.comando`

Fila de comandos para o worker (ligar, desligar, volume...). O painel/API grava e faz NOTIFY casa_comando.

Chave primária: `id`

```sql
    id bigint NOT NULL,
    dispositivo_id integer NOT NULL,
    acao text NOT NULL,
    parametros jsonb DEFAULT '{}'::jsonb NOT NULL,
    origem text DEFAULT 'api'::text NOT NULL,
    status text DEFAULT 'pendente'::text NOT NULL,
    resposta jsonb,
    criado_em timestamp with time zone DEFAULT now() NOT NULL,
    executado_em timestamp with time zone
```

### `public.configuracao`

Configurações chave/valor editáveis pelo painel.

Chave primária: `chave`

```sql
    chave text NOT NULL,
    valor text,
    atualizado_em timestamp with time zone DEFAULT now() NOT NULL,
    atualizado_por text
```

### `public.dispositivo`

Aparelhos da casa (TVs LG etc.): driver, cômodo, config (IP, modelo, chave de pareamento).

Chave primária: `id`

```sql
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
```

### `public.estado`

Último estado lido de cada aparelho (ligado, app em primeiro plano, volume).

Chave primária: `dispositivo_id`

```sql
    dispositivo_id integer NOT NULL,
    online boolean DEFAULT false NOT NULL,
    dados jsonb DEFAULT '{}'::jsonb NOT NULL,
    atualizado_em timestamp with time zone DEFAULT now() NOT NULL
```

### `public.evento`

Linha do tempo de eventos (container caiu/voltou, internet, backups, aparelhos).

Chave primária: `id`

```sql
    id bigint NOT NULL,
    tipo text NOT NULL,
    nivel text DEFAULT 'info'::text NOT NULL,
    titulo text NOT NULL,
    detalhe text,
    origem text,
    criado_em timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT evento_nivel_check CHECK ((nivel = ANY (ARRAY['info'::text, 'ok'::text, 'aviso'::text, 'erro'::text])))
```

### `public.login_tentativa`

Tentativas de login (bloqueio após 5 erros em 15 min).

Chave primária: `id`

```sql
    id bigint NOT NULL,
    login text,
    ip text,
    sucesso boolean NOT NULL,
    criado_em timestamp with time zone DEFAULT now() NOT NULL
```

### `public.metrica`

Métricas do servidor a cada 30 s (CPU, RAM, disco, energia). Guardada 30 dias.

Chave primária: `coletado_em`

```sql
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
```

### `public.metrica_rede`

Latência/perda da internet por minuto (coletor do host). Guardada 30 dias.

Chave primária: `coletado_em`

```sql
    coletado_em timestamp with time zone DEFAULT now() NOT NULL,
    gateway_ms real,
    gateway_perda real,
    internet_ms real,
    internet_perda real,
    dns_ms real
```

### `public.migracao`

Controle das migrações aplicadas (criada pelo próprio código, Db.php).

Chave primária: `arquivo`

```sql
    arquivo text NOT NULL,
    aplicado_em timestamp with time zone DEFAULT now() NOT NULL
```

### `public.rede_dispositivo`

Aparelhos vistos na rede local (varredura a cada 5 min). Chave = MAC.

Chave primária: `mac`

```sql
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
```

### `public.usuario`

Usuários do painel web (admin/morador). Senha só como hash.

Chave primária: `id`

```sql
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
```
