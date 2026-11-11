-- WebOS TV Player 2.0, frente worker (casa-play): ajustes do esquema 007. Ver docs/play-2.0.md,
-- "Mudanças no contrato (worker)".

-- 1) play.norm igual ao Texto::norm do PHP e ao norm() da v10: sem apóstrofo, sem [colchetes] e
--    (parênteses), sem marcas de versão (4k, hd, dub, leg...), espaços nas pontas aparados.
--    Nenhum índice usa a função (os índices são das colunas *_norm), então pode ser trocada.
create or replace function play.norm(t text) returns text
language sql immutable parallel safe as $$
  select btrim(regexp_replace(regexp_replace(regexp_replace(regexp_replace(
           lower(public.unaccent('public.unaccent'::regdictionary, coalesce(t, ''))),
           '[''’`]', '', 'g'),
           '\[[^]]*\]|\([^)]*\)', ' ', 'g'),
           '\m(4k|fhd|uhd|hd|sd|hdr|legendado|leg|dublado|dub|dual|audio|nacional|cam|hdcam)\M', ' ', 'g'),
           '[^a-z0-9]+', ' ', 'g'))
$$;

-- 2) Cache das respostas TMDB/JustWatch usadas pelo recomendador e pelo Top 10 (chave = caminho).
create table if not exists play.cache (
    chave  text primary key,
    dados  jsonb not null,
    em     timestamptz not null default now()
);
create index if not exists cache_em on play.cache (em);

-- 3) EPG sob demanda: quando a programação de cada canal foi lida (cache de 30 min).
alter table play.canal add column if not exists epg_em timestamptz;

-- 4) Recomendação: impressão digital do histórico/lista/notas que a gerou (recalcula só se mudar).
alter table play.recomendacao add column if not exists chave text;

-- 5) Índices das consultas do worker.
create index if not exists titulo_sem_detalhes on play.titulo (adicionado_em desc) where ativo and detalhes_em is null;
create index if not exists titulo_link on play.titulo (link_testado_em) where ativo;
create index if not exists versao_conta_ativo on play.versao (conta_id, tipo) where ativo;
create index if not exists top10_titulo on play.top10 (titulo_id) where titulo_id is not null;
create index if not exists historico_titulo on play.historico (perfil_id, titulo_id);
create index if not exists aviso_chegar on play.aviso (tipo) where status = 'ativo' and quando is null;
create index if not exists jogo_canal on play.jogo (canal_id);

-- 6) Semente de gosto do perfil "Casa" (exemplo neutro): títulos vistos/curtidos (por tmdb; "painel" =
--    id no painel), gêneros TMDB (35 comédia, 28 ação), pessoas, preferência por sucessos e a época
--    de Natal. O recomendador lê a preferência "gosto"; editar pelo banco/painel muda a semente.
insert into play.preferencia (perfil_id, chave, valor)
select p.id, 'gosto', $${
  "v": 1,
  "titulos": [],
  "generos": {"35": 1, "28": 0.9},
  "pessoas": [],
  "blockbuster": true,
  "epoca": {"keyword": 207317, "titulo": "Filmes de Natal para esta época", "de": "11-01", "ate": "01-06", "categoria_painel": "natal"}
}$$::jsonb
  from play.perfil p
 where p.nome = 'Casa'
on conflict (perfil_id, chave) do nothing;
