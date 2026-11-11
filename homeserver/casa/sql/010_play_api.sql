-- Player 2.0, frente API/painel: índices para as consultas da API e do painel (contrato em docs/play-2.0.md).
-- (top10_titulo e historico_titulo (perfil_id, titulo_id) já vêm do 008.)

-- Estatísticas do painel (horas no mês) e ordem "mais vistos" das categorias (todas as pessoas da casa).
create index if not exists historico_inicio on play.historico (inicio);
create index if not exists historico_titulo_todos on play.historico (titulo_id);

-- Progresso por título (pós-filme, "começados").
create index if not exists progresso_titulo on play.progresso (titulo_id);

-- Avisos do perfil (Início, Ao vivo, Suas séries).
create index if not exists aviso_perfil on play.aviso (perfil_id, tipo, status);

-- TV pelo DUID (registro de novo da mesma TV) e pelo aparelho do painel (continuar pela Siri).
create index if not exists tv_duid on play.tv (duid) where duid is not null;
create index if not exists tv_dispositivo on play.tv (dispositivo_slug) where dispositivo_slug is not null;
