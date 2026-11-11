<?php
declare(strict_types=1);

namespace Casa\Play\Api;

/**
 * Top 10 de cada plataforma (JustWatch Brasil), séries e filmes separados.
 * "Atual" = última coleta de cada (tipo, plataforma); a seta compara com a última coleta do dia anterior.
 */
final class Top10
{
    private static ?array $atual = null;
    private static ?array $ontem = null;
    private static ?array $porTitulo = null;

    /** Última coleta de cada plataforma ativa (índice top10_ult: um max() por plataforma). */
    private const SQL = "
        select tp.tipo, pl.codigo, pl.nome as plataforma, pl.ordem, x.posicao, x.titulo_id, x.tmdb_id, x.jw_id,
               x.nome, x.ano, x.poster, x.coletado_em
          from play.plataforma pl
         cross join (values (cast('s' as char(1))), (cast('m' as char(1)))) tp(tipo)
         cross join lateral (select max(coletado_em) as em from play.top10
                              where tipo = tp.tipo and plataforma = pl.codigo %s) u
          join play.top10 x on x.tipo = tp.tipo and x.plataforma = pl.codigo and x.coletado_em = u.em
         where pl.ativa
         order by tp.tipo, pl.ordem, x.posicao";

    public static function atual(): array
    {
        return self::$atual ??= Base::linhas(sprintf(self::SQL, ''));
    }

    /** Última coleta antes de hoje (fuso da casa). */
    public static function ontem(): array
    {
        return self::$ontem ??= Base::linhas(sprintf(self::SQL, 'and coletado_em < cast(? as timestamptz)'), [Base::hojeInicio()]);
    }

    /** Posições atuais de um título: [{codigo, posicao}] na ordem das plataformas. */
    public static function porTitulo(int $id): array
    {
        if (self::$porTitulo === null) {
            self::$porTitulo = [];
            foreach (self::atual() as $l) {
                if ($l['titulo_id'] !== null) {
                    self::$porTitulo[(int) $l['titulo_id']][] = ['codigo' => $l['codigo'], 'posicao' => (int) $l['posicao']];
                }
            }
        }
        return self::$porTitulo[$id] ?? [];
    }

    /** Identidade de um item entre coletas: título da lista, senão JustWatch, senão TMDB, senão nome. */
    public static function chave(array $l): string
    {
        return match (true) {
            !empty($l['titulo_id']) => 't' . $l['titulo_id'],
            !empty($l['jw_id'])     => 'j' . $l['jw_id'],
            !empty($l['tmdb_id'])   => 'd' . $l['tmdb_id'],
            default                 => 'n' . mb_strtolower(trim((string) ($l['nome'] ?? ''))),
        };
    }

    /** "▲2" subiu, "▼1" caiu, "=" igual, "NOVO" não estava ontem. */
    public static function seta(?int $antes, int $agora): string
    {
        if ($antes === null) {
            return 'NOVO';
        }
        $d = $antes - $agora;
        return $d > 0 ? '▲' . $d : ($d < 0 ? '▼' . (-$d) : '=');
    }

    /**
     * Setas de cada item de hoje: "tipo|plataforma|chave" => seta.
     * Plataforma sem coleta ontem (primeiro dia) fica sem seta (null), para não marcar tudo como NOVO.
     */
    public static function setas(array $hoje, array $ontem): array
    {
        $antes = [];
        $temOntem = [];
        foreach ($ontem as $l) {
            $temOntem[$l['tipo'] . '|' . $l['codigo']] = true;
            $k = $l['tipo'] . '|' . $l['codigo'] . '|' . self::chave($l);
            $antes[$k] ??= (int) $l['posicao'];
        }
        $out = [];
        foreach ($hoje as $l) {
            $grupo = $l['tipo'] . '|' . $l['codigo'];
            $k = $grupo . '|' . self::chave($l);
            $out[$k] = isset($temOntem[$grupo]) ? self::seta($antes[$k] ?? null, (int) $l['posicao']) : null;
        }
        return $out;
    }

    /**
     * "Top Brasil" (JustWatch, todas as plataformas, calculado pelo worker em play.cache 'top:brasil'):
     * {atualizado, itens:[Cartao + {posicao}]}, filmes e séries juntos. Null se ainda não calculado.
     */
    public static function brasil(int $perfil, bool $esconderFora = false): ?array
    {
        $d = Base::json(Base::valor("select dados from play.cache where chave = 'top:brasil'"));
        if (!$d || empty($d['itens'])) {
            return null;
        }
        Cartoes::carregar(array_values(array_filter(array_column($d['itens'], 'id'))), $perfil);
        $itens = [];
        foreach ($d['itens'] as $l) {
            $c = !empty($l['id']) ? Cartoes::de((int) $l['id']) : null;
            if (!$c) {
                if ($esconderFora) {
                    continue;
                }
                $c = Cartoes::fora(['tmdb' => $l['tmdb'] ?? null, 'tipo' => $l['tipo'] ?? 'm', 'nome' => $l['nome'] ?? '',
                                    'ano' => $l['ano'] ?? null, 'poster' => $l['poster'] ?? null], $perfil);
            }
            $c['posicao'] = (int) $l['posicao'];
            $itens[] = $c;
        }
        return $itens ? ['atualizado' => Base::iso($d['em'] ?? null), 'itens' => $itens] : null;
    }

    /**
     * Fileiras do Top 10 de um tipo: [{codigo, nome, itens:[Cartao + {posicao, mov}]}].
     * Títulos fora da lista vêm com na_lista:false (a TV desenha apagado com "Avisar").
     */
    public static function fileiras(string $tipo, int $perfil, bool $esconderFora = false): array
    {
        $hoje = array_values(array_filter(self::atual(), fn($l) => $l['tipo'] === $tipo));
        if (!$hoje) {
            return [];
        }
        $setas = self::setas($hoje, array_values(array_filter(self::ontem(), fn($l) => $l['tipo'] === $tipo)));
        Cartoes::carregar(array_column($hoje, 'titulo_id'), $perfil);
        $grupos = [];
        foreach ($hoje as $l) {
            $c = $l['titulo_id'] !== null ? Cartoes::de((int) $l['titulo_id']) : null;
            if (!$c) {
                if ($esconderFora) {
                    continue;
                }
                $c = Cartoes::fora(['tmdb' => $l['tmdb_id'], 'tipo' => $tipo, 'nome' => $l['nome'], 'ano' => $l['ano'], 'poster' => $l['poster']], $perfil);
                $c['plataformas'] = [['codigo' => $l['codigo'], 'posicao' => (int) $l['posicao']]];
            }
            $c['posicao'] = (int) $l['posicao'];
            $c['mov'] = $setas[$tipo . '|' . $l['codigo'] . '|' . self::chave($l)] ?? null;
            $grupos[$l['codigo']] ??= ['codigo' => $l['codigo'], 'nome' => $l['plataforma'], 'itens' => []];
            $grupos[$l['codigo']]['itens'][] = $c;
        }
        return array_values(array_filter($grupos, fn($g) => $g['itens']));
    }

    /** Hora da coleta mais recente (ISO). */
    public static function atualizado(?string $tipo = null): ?string
    {
        $max = null;
        foreach (self::atual() as $l) {
            if ($tipo === null || $l['tipo'] === $tipo) {
                $max = $max === null || strcmp((string) $l['coletado_em'], $max) > 0 ? (string) $l['coletado_em'] : $max;
            }
        }
        return Base::iso($max);
    }
}
