-- Aparelhos vistos na rede de casa (varredura do coletor a cada 5 min). Chave = MAC.
create table rede_dispositivo (
    mac             text primary key,
    ip              text,
    fabricante      text,                 -- pelo prefixo do MAC (nmap)
    nome_dns        text,                 -- nome que o roteador deu
    upnp_fabricante text,
    upnp_modelo     text,
    upnp_nome       text,
    upnp_servidor   text,
    apelido         text,                 -- nome dado no painel
    conhecido       boolean not null default false,
    online          boolean not null default false,
    primeiro_visto  timestamptz not null default now(),
    ultimo_visto    timestamptz not null default now()
);
create index rede_dispositivo_ip_idx on rede_dispositivo (ip);
