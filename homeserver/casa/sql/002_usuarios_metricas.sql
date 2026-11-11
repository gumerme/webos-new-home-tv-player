-- Login do painel e métricas do servidor.

create table usuario (
    id            serial primary key,
    nome          text not null,
    login         text not null unique,
    senha_hash    text not null,
    perfil        text not null default 'morador' check (perfil in ('admin', 'morador')),
    ativo         boolean not null default true,
    trocar_senha  boolean not null default false,
    ultimo_login  timestamptz,
    criado_em     timestamptz not null default now()
);

create table login_tentativa (
    id         bigserial primary key,
    login      text,
    ip         text,
    sucesso    boolean not null,
    criado_em  timestamptz not null default now()
);
create index login_tentativa_quando_idx on login_tentativa (criado_em);

-- Uma linha a cada 30 s (worker). Guardada por 30 dias.
create table metrica (
    coletado_em  timestamptz primary key default now(),
    cpu_pct      real,
    load1        real,
    ram_usada    bigint,
    ram_total    bigint,
    swap_usada   bigint,
    swap_total   bigint,
    disco_usado  bigint,
    disco_total  bigint,
    home_usado   bigint,
    home_total   bigint,
    energia_w    real,      -- RAPL: pacote do processador + memória
    energia_cpu_w real,
    energia_ram_w real,
    temp_c       real,
    rede_rx_bps  bigint,
    rede_tx_bps  bigint
);
