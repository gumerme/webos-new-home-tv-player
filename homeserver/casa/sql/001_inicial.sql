-- Núcleo: aparelhos, estado atual e fila de comandos.

create table dispositivo (
    id          serial primary key,
    slug        text not null unique,              -- ex.: tv-sala
    nome        text not null,                     -- nome falado pela Siri
    tipo        text not null,                     -- tv, tomada, interruptor, lampada
    driver      text not null,                     -- lgwebos, wol, matter, midea
    comodo      text,
    config      jsonb not null default '{}',       -- ip, mac, client_key, apps...
    homekit     boolean not null default true,     -- aparece no app Casa / Siri
    ativo       boolean not null default true,
    criado_em   timestamptz not null default now()
);

create table estado (
    dispositivo_id int primary key references dispositivo(id) on delete cascade,
    online         boolean not null default false,
    dados          jsonb not null default '{}',    -- {"ligado":true,"volume":12,"app":"netflix"}
    atualizado_em  timestamptz not null default now()
);

create table comando (
    id             bigserial primary key,
    dispositivo_id int not null references dispositivo(id) on delete cascade,
    acao           text not null,                  -- ligar, desligar, volume, abrir_app...
    parametros     jsonb not null default '{}',
    origem         text not null default 'api',    -- api, homekit, automacao, assistente
    status         text not null default 'pendente', -- pendente, executando, ok, erro
    resposta       jsonb,
    criado_em      timestamptz not null default now(),
    executado_em   timestamptz
);
create index comando_pendente_idx on comando (id) where status = 'pendente';
create index comando_disp_idx on comando (dispositivo_id, id desc);

-- TVs da casa (MAC é preenchido sozinho pelo worker na 1ª vez que a TV responder).
-- homekit=false até parear (acao "parear") e confirmar o MAC.
insert into dispositivo (slug, nome, tipo, driver, comodo, config, homekit) values
 ('tv-1', 'TV 1', 'tv', 'lgwebos', null,
  '{"ip":"192.168.1.10","modelo":"55UR8750PSA","webos":"10.3","rede":"cabo"}', false),
 ('tv-2', 'TV 2', 'tv', 'lgwebos', null,
  '{"ip":"192.168.1.11","modelo":"43LM6300PSB","webos":"4.10","rede":"wifi"}', false);
