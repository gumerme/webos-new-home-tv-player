-- Configurações editáveis pelo painel (chave/valor).
create table configuracao (
    chave          text primary key,
    valor          text,
    atualizado_em  timestamptz not null default now(),
    atualizado_por text
);
