-- Mais detalhes dos aparelhos da rede: mDNS/Bonjour, página web do aparelho, MAC privado, modelo deduzido.
alter table rede_dispositivo
    add column mac_privado    boolean not null default false,  -- MAC aleatório (celulares/tablets)
    add column modelo         text,                            -- melhor palpite (UPnP > mDNS > web)
    add column mdns_nome      text,
    add column mdns_servicos  text[],
    add column http_titulo    text,
    add column http_servidor  text,
    add column extras         jsonb not null default '{}';     -- chaves TXT do mDNS (model, md, ty...)
