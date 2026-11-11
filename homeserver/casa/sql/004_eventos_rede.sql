-- Linha do tempo do servidor (o que aconteceu) e histórico da conexão com a internet.

create table evento (
    id         bigserial primary key,
    tipo       text not null,                 -- sistema, aparelho, backup, usuario, config, seguranca
    nivel      text not null default 'info' check (nivel in ('info', 'ok', 'aviso', 'erro')),
    titulo     text not null,
    detalhe    text,
    origem     text,                          -- worker, painel:<login>, backup, coletor
    criado_em  timestamptz not null default now()
);
create index evento_quando_idx on evento (criado_em desc);

-- Uma linha por minuto (coletor do host). Guardada por 30 dias.
create table metrica_rede (
    coletado_em      timestamptz primary key default now(),
    gateway_ms       real,
    gateway_perda    real,
    internet_ms      real,
    internet_perda   real,
    dns_ms           real
);
