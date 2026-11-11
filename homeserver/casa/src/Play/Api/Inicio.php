<?php
declare(strict_types=1);

namespace Casa\Play\Api;

use Casa\Play\Dados\Generos;
use Casa\Play\Dados\Texto;

/**
 * Início, seções de Séries/Filmes, Suas séries, categorias e ranking.
 * O Início sai numa chamada só: fileiras pré-calculadas (play.recomendacao) + Top 10 + progresso + lista,
 * com todos os cartões montados por uma única consulta (Cartoes::carregar).
 */
final class Inicio
{
    private const POR_FILEIRA = 30;

    // ------------------------------------------------------------- Início

    public static function inicio(Pedido $p): array
    {
        $perfil = $p->perfil();
        $rec = self::rec($perfil);
        $esconderFora = (bool) Base::pref($perfil, 'esconder_fora_lista', false);

        $continuar = self::continuar($perfil);
        $chegou = self::chegou($perfil);
        $lista = Cartoes::minhaLista($perfil, 500);   // completa: a aba Minha lista da TV usa esta chave
        $destaqueIds = self::ids($rec['destaque'] ?? []);
        $porque = array_slice(array_values(array_filter((array) ($rec['porque'] ?? []), 'is_array')), 0, 2);
        $generos = array_slice(array_values(array_filter((array) ($rec['generos'] ?? []), 'is_array')), 0, 2);
        $epoca = is_array($rec['epoca'] ?? null) ? $rec['epoca'] : null;

        // Uma consulta para todos os cartões da tela.
        $todos = array_merge($destaqueIds, self::ids($rec['recomendados'] ?? []), array_column($continuar, 'id'),
            array_column($chegou, 'id'), $lista, self::ids($epoca['ids'] ?? []));
        foreach ($porque as $x) {
            $todos = array_merge($todos, self::ids($x['ids'] ?? []), [(int) ($x['titulo_id'] ?? 0)]);
        }
        foreach ($generos as $x) {
            $todos = array_merge($todos, self::ids($x['ids'] ?? []));
        }
        Cartoes::carregar($todos, $perfil);

        $recomendados = Cartoes::lista(self::ids($rec['recomendados'] ?? []), $perfil, self::POR_FILEIRA);
        if (!$destaqueIds) {
            // Sem recomendação ainda (perfil novo): destaca os primeiros recomendados ou o Top 10.
            // (sem o que o perfil já viu ou avaliou: Gostei = visto, Não gostei = nunca no destaque)
            $fora = Base::foraDasSugestoes($perfil);
            $destaqueIds = array_slice(array_column($recomendados, 'id'), 0, 10)
                ?: array_slice(array_values(array_filter(array_column(Top10::atual(), 'titulo_id'), fn($id) => $id && !isset($fora[(int) $id]))), 0, 5);
            Cartoes::carregar($destaqueIds, $perfil);
        }

        return [
            'perfil'       => $perfil,
            'destaque'     => self::destaques($destaqueIds, $perfil, $porque),
            'continuar'    => self::encaixarCanal(self::cartoesComSelo($continuar, $perfil), self::ultimoCanal($perfil)),
            'top10'        => ['s' => Top10::fileiras('s', $perfil, $esconderFora), 'm' => Top10::fileiras('m', $perfil, $esconderFora)],
            'top_brasil'   => Top10::brasil($perfil, $esconderFora),
            'recomendados' => $recomendados,
            'chegou'       => self::cartoesComSelo($chegou, $perfil),
            'porque'       => self::porque($porque, $perfil),
            'sagas'        => self::sagas($perfil),
            'epoca'        => $epoca && ($itens = Cartoes::lista(self::ids($epoca['ids'] ?? []), $perfil, self::POR_FILEIRA))
                ? ['titulo' => (string) ($epoca['titulo'] ?? 'Época'), 'subtitulo' => $epoca['subtitulo'] ?? null, 'itens' => $itens] : null,
            'generos'      => array_values(array_filter(array_map(fn($g) => [
                'nome'  => (string) ($g['nome'] ?? Base::GENEROS[(int) ($g['tmdb'] ?? $g['id'] ?? 0)] ?? ''),
                'itens' => Cartoes::lista(self::ids($g['ids'] ?? []), $perfil, self::POR_FILEIRA),
            ], $generos), fn($g) => $g['itens'])),
            'minha_lista'  => Cartoes::lista($lista, $perfil, 500),
        ];
    }

    /** Recomendação pré-calculada do perfil (worker). Formato no comentário do 007. */
    public static function rec(int $perfil): array
    {
        $rec = Base::json(Base::valor('select dados from play.recomendacao where perfil_id = ?', [$perfil]));
        return $rec ? self::semVistos($rec, Base::foraDasSugestoes($perfil)) : $rec;
    }

    /**
     * Tira das listas de sugestão da recomendação pronta os ids em $fora (vistos, Gostei, Não gostei):
     * vale na hora, sem esperar o recálculo. A origem do "porque" (titulo_id) fica. Puro.
     * @param array<int, true> $fora
     */
    public static function semVistos(array $rec, array $fora): array
    {
        if (!$fora) {
            return $rec;
        }
        $tirar = function (mixed $ids) use ($fora): array {
            return array_values(array_filter((array) $ids, function ($x) use ($fora) {
                $id = is_array($x) ? ($x['id'] ?? $x['titulo_id'] ?? null) : $x;
                return !(is_numeric($id) && isset($fora[(int) $id]));
            }));
        };
        $grupos = function (mixed $lista) use ($tirar): mixed {
            if (!is_array($lista)) {
                return $lista;
            }
            foreach ($lista as $k => $g) {
                if (is_array($g) && isset($g['ids'])) {
                    $lista[$k]['ids'] = $tirar($g['ids']);
                }
            }
            return $lista;
        };
        foreach (['destaque', 'recomendados'] as $k) {
            if (isset($rec[$k])) {
                $rec[$k] = $tirar($rec[$k]);
            }
        }
        foreach (['porque', 'generos'] as $k) {
            if (isset($rec[$k])) {
                $rec[$k] = $grupos($rec[$k]);
            }
        }
        if (is_array($rec['epoca'] ?? null) && isset($rec['epoca']['ids'])) {
            $rec['epoca']['ids'] = $tirar($rec['epoca']['ids']);
        }
        foreach ((array) ($rec['por_tipo'] ?? []) as $t => $pt) {
            if (is_array($pt)) {
                if (isset($pt['recomendados'])) {
                    $pt['recomendados'] = $tirar($pt['recomendados']);
                }
                if (isset($pt['generos'])) {
                    $pt['generos'] = $grupos($pt['generos']);
                }
                $rec['por_tipo'][$t] = $pt;
            }
        }
        return $rec;
    }

    /** Ids inteiros de uma lista (aceita números ou objetos {id}). */
    public static function ids(mixed $v): array
    {
        $out = [];
        foreach ((array) $v as $x) {
            $id = is_array($x) ? ($x['id'] ?? $x['titulo_id'] ?? null) : $x;
            if (is_numeric($id) && (int) $id > 0) {
                $out[] = (int) $id;
            }
        }
        return $out;
    }

    private static function destaques(array $ids, int $perfil, array $porque): array
    {
        $porqueDe = [];
        foreach ($porque as $x) {
            $origem = Cartoes::linha((int) ($x['titulo_id'] ?? 0));
            foreach (self::ids($x['ids'] ?? []) as $id) {
                $porqueDe[$id] ??= $origem['nome'] ?? null;
            }
        }
        $nomesPlat = array_column(Top10::atual(), 'plataforma', 'codigo');
        $out = [];
        foreach (Cartoes::lista($ids, $perfil, 10) as $c) {
            $l = Cartoes::linha($c['id']);
            $top = $c['plataformas'][0] ?? null;
            $c['sinopse'] = $l['sinopse'] ?? null;
            $c += Titulos::trailer($c['tipo'], $c['tmdb'] ?? null, $l['trailer'] ?? null, $c['nome'] ?? null, $c['ano'] ?? null);
            $c['rotulo'] = $top !== null ? 'Em alta' : 'Para você';
            $c['classificacao'] = $l['classificacao'] ?? null;
            $c['duracao_min'] = Base::inteiroOuNulo($l['duracao_min'] ?? null);
            $c['temporadas'] = Base::inteiroOuNulo($l['temporadas'] ?? null);
            $c['motivo'] = match (true) {
                $top !== null => 'Top ' . $top['posicao'] . ' na ' . ($nomesPlat[$top['codigo']] ?? $top['codigo']),
                isset($porqueDe[$c['id']]) => 'Porque você assistiu ' . $porqueDe[$c['id']],
                $l && $l['adicionado_em'] && strtotime((string) $l['adicionado_em']) > time() - 3 * 86400 => 'Chegou na lista',
                default => 'Para você',
            };
            $out[] = $c;
        }
        return $out;
    }

    private static function porque(array $porque, int $perfil): array
    {
        $out = [];
        foreach ($porque as $x) {
            $origem = (int) ($x['titulo_id'] ?? 0) > 0 ? Cartoes::linha((int) $x['titulo_id']) : null;
            $nome = $origem['nome'] ?? (string) ($x['nome'] ?? '');     // título da Netflix fora da lista: só o nome
            $itens = Cartoes::lista(self::ids($x['ids'] ?? []), $perfil, self::POR_FILEIRA);
            if ($nome !== '' && $itens) {
                $out[] = ['titulo' => 'Porque você assistiu ' . $nome, 'titulo_id' => $origem ? (int) $origem['id'] : null, 'itens' => $itens];
            }
        }
        return $out;
    }

    /** [{id, selo}] -> cartões com selo, na ordem. */
    public static function cartoesComSelo(array $itens, int $perfil): array
    {
        Cartoes::carregar(array_column($itens, 'id'), $perfil);
        $out = [];
        $vistos = [];
        foreach ($itens as $x) {
            if (isset($vistos[$x['id']])) {
                continue;
            }
            $vistos[$x['id']] = true;
            $c = Cartoes::de((int) $x['id'], $x['selo'] ?? null);
            if ($c) {
                if (isset($x['quando'])) {
                    $c['quando'] = $x['quando'];
                }
                $out[] = $c;
            }
        }
        return $out;
    }

    // -------------------------------------------------------- Continuar

    /**
     * "Continuar": filme parado no meio (>= 30 s) ou série com episódio em andamento ou terminado com
     * próximo episódio disponível. Tirados com "ocultar" voltam só quando tocar de novo.
     * Filme com "Gostei" sai (gostei = já vi); série continua (o Continuar dela é o próximo episódio).
     * @return list<array{id:int, selo:?string, quando:?string}>
     */
    public static function continuar(int $perfil, ?string $tipo = null, int $limite = 20): array
    {
        $l = Base::linhas("
            select p.titulo_id, t.tipo, p.temporada, p.episodio, p.terminado, p.atualizado_em
              from play.progresso p
              join play.titulo t on t.id = p.titulo_id
             where p.perfil_id = ? and not p.oculto and t.ativo
               and (cast(? as text) = '' or t.tipo = cast(? as text))
               and not (t.tipo = 'm' and exists (select 1 from play.nota n where n.perfil_id = p.perfil_id and n.titulo_id = p.titulo_id and n.valor > 0))
               and ((not p.terminado and (p.posicao_s >= 30
                        -- série: episódio recém-aberto (< 30 s) entra se outro episódio já foi visto ou começado
                        or (t.tipo = 's' and exists (select 1 from play.progresso_ep pe
                             where pe.perfil_id = p.perfil_id and pe.titulo_id = p.titulo_id
                               and (pe.terminado or pe.posicao_s >= 30)))))
                    or (p.terminado and t.tipo = 's' and exists (
                        select 1 from play.versao v
                          join play.episodio e on e.conta_id = v.conta_id and e.serie_id = v.painel_id
                         where v.titulo_id = p.titulo_id and v.tipo = 's' and v.ativo and v.conta_id = ?
                           and (e.temporada, e.numero) > (p.temporada, p.episodio))))
             order by p.atualizado_em desc
             limit ?", [$perfil, $tipo ?? '', $tipo ?? '', Base::contaId(), $limite]);
        return array_map(fn($x) => [
            'id'   => (int) $x['titulo_id'],
            'selo' => $x['tipo'] === 's' && $x['temporada'] !== null
                ? (Base::b($x['terminado']) ? 'Próximo depois de ' : '') . 'T' . $x['temporada'] . ':E' . $x['episodio'] : null,
            'quando' => Base::iso($x['atualizado_em']),   // compara com o último canal (encaixarCanal)
        ], $l);
    }

    /**
     * Último canal do Ao vivo (gravado por AoVivo::tocar em play.preferencia 'ultimo_canal' = {id, em}),
     * como cartão de canal do Continuar, ou null: visto há mais de 24 h, canal sumido ou inativo.
     * Formato: {tipo:'canal', id, nome, logo, numero, agora:{titulo,inicio,fim,pct}|null, quando, qualidade, xt}.
     */
    public static function ultimoCanal(int $perfil, ?int $agora = null): ?array
    {
        $u = Base::pref($perfil, 'ultimo_canal');
        if (!is_array($u) || !is_numeric($u['id'] ?? null) || !self::canalRecente((string) ($u['em'] ?? ''), $agora)) {
            return null;
        }
        $c = AoVivo::canaisPorIds([(int) $u['id']], $perfil)[0] ?? null;   // só canal que ainda existe e está ativo
        return $c ? self::cartaoCanal($c, (string) $u['em']) : null;
    }

    /** Canal (formato do contrato) -> cartão de canal do Continuar. Puro. */
    public static function cartaoCanal(array $c, string $quando): array
    {
        return ['tipo' => 'canal', 'id' => (int) $c['id'], 'nome' => (string) $c['nome'], 'logo' => $c['logo'] ?? null,
                'numero' => $c['numero'] ?? null, 'agora' => $c['agora'] ?? null, 'quando' => Base::iso($quando),
                'qualidade' => $c['qualidade'] ?? null, 'xt' => $c['xt'] ?? ['id' => (int) $c['id']]];
    }

    /** Visto há menos de 24 h? Puro. */
    public static function canalRecente(string $em, ?int $agora = null): bool
    {
        $t = $em !== '' ? strtotime($em) : false;
        return $t !== false && $t > ($agora ?? time()) - 86400;
    }

    /**
     * Encaixa o último canal no Continuar (pedido 09/11 noite). Puro.
     * Só entra se foi visto nas últimas 24 h; entra em PRIMEIRO se for mais recente que o primeiro título
     * (ou se não houver título com data), senão em SEGUNDO.
     */
    public static function encaixarCanal(array $cartoes, ?array $canal, ?int $agora = null): array
    {
        if (!$canal || !self::canalRecente((string) ($canal['quando'] ?? ''), $agora)) {
            return $cartoes;
        }
        $em = strtotime((string) $canal['quando']);
        $primeiro = isset($cartoes[0]['quando']) ? strtotime((string) $cartoes[0]['quando']) : false;
        $pos = ($primeiro === false || $em > $primeiro) ? 0 : 1;
        array_splice($cartoes, $pos, 0, [$canal]);
        return $cartoes;
    }

    // ------------------------------------------------------------- Chegou

    /**
     * "Chegou": episódios novos das séries seguidas, títulos pedidos com "Avisar quando chegar" e
     * o que o painel adicionou nos últimos 7 dias.
     * @return list<array{id:int, selo:?string, quando:?string}>
     */
    public static function chegou(int $perfil, int $limite = 30): array
    {
        $out = [];
        foreach (self::episodiosNovos($perfil, 7) as $e) {
            $out[] = ['id' => $e['id'], 'selo' => 'NOVO · T' . $e['temporada'] . ':E' . $e['numero'], 'quando' => $e['quando']];
        }
        foreach (Base::linhas("
            select t.id, a.disparado_em
              from play.aviso a
              join play.titulo t on t.tipo = cast(coalesce(a.alvo->>'tipo', 'm') as char(1))
                                and t.tmdb_id = cast(a.alvo->>'tmdb' as int) and t.ativo
             where a.perfil_id = ? and a.tipo = 'chegar' and a.status = 'disparado'
               and a.disparado_em > now() - interval '14 days'
             order by a.disparado_em desc limit 20", [$perfil]) as $x) {
            $out[] = ['id' => (int) $x['id'], 'selo' => 'Você pediu', 'quando' => Base::iso($x['disparado_em'])];
        }
        $hoje = strtotime(Base::hojeInicio());
        $novos = Base::linhas("
            (select id, adicionado_em from play.titulo
              where tipo = 'm' and ativo and adicionado_em > now() - interval '7 days' and tmdb_id is not null
              order by adicionado_em desc limit 20)
            union all
            (select id, adicionado_em from play.titulo
              where tipo = 's' and ativo and adicionado_em > now() - interval '7 days' and tmdb_id is not null
              order by adicionado_em desc limit 20)");
        usort($novos, fn($a, $b) => strcmp((string) $b['adicionado_em'], (string) $a['adicionado_em']));
        foreach ($novos as $x) {
            $ts = strtotime((string) $x['adicionado_em']);
            $out[] = ['id' => (int) $x['id'], 'selo' => $ts >= $hoje ? 'Chegou hoje' : null, 'quando' => Base::iso($x['adicionado_em'])];
        }
        return array_slice($out, 0, $limite);
    }

    /** Último episódio novo de cada série seguida pelo perfil, nos últimos $dias dias. */
    public static function episodiosNovos(int $perfil, int $dias): array
    {
        return array_map(fn($x) => [
            'id' => (int) $x['titulo_id'], 'temporada' => (int) $x['temporada'], 'numero' => (int) $x['numero'],
            'quando' => Base::iso($x['em']),
        ], Base::linhas("
            select v.titulo_id, max(e.adicionado_em) as em,
                   (array_agg(e.temporada order by e.temporada desc, e.numero desc))[1] as temporada,
                   (array_agg(e.numero order by e.temporada desc, e.numero desc))[1] as numero
              from play.serie_seguida s
              join play.versao v on v.titulo_id = s.titulo_id and v.tipo = 's' and v.ativo and v.conta_id = ?
              join play.episodio e on e.conta_id = v.conta_id and e.serie_id = v.painel_id
             where s.perfil_id = ? and e.adicionado_em > now() - make_interval(days => ?)
             group by v.titulo_id
             order by em desc limit 30", [Base::contaId(), $perfil, $dias]));
    }

    // -------------------------------------------------------------- Sagas

    /**
     * Sagas que o perfil começou: [{id, nome, vistos, total, proximo:Cartao, fundo}].
     * Visto = Base::VISTOS (terminado no histórico ou no progresso, ou Gostei).
     */
    public static function sagas(int $perfil, int $limite = 10): array
    {
        $l = Base::linhas("
            with vistos as (
                select titulo_id from " . Base::VISTOS . " v
            ), comecadas as (
                select distinct t.colecao_id
                  from play.titulo t
                 where t.colecao_id is not null
                   and t.id in (select titulo_id from vistos union select titulo_id from play.progresso where perfil_id = ?)
            )
            select c.id, c.nome, c.fundo, jsonb_array_length(c.partes) as partes, t.id as titulo_id,
                   (t.id in (select titulo_id from vistos)) as visto
              from play.colecao c
              join comecadas k on k.colecao_id = c.id
              join play.titulo t on t.colecao_id = c.id and t.ativo and t.tipo = 'm'
             order by c.id, t.ano nulls last, t.id", [...Base::vistosPar($perfil), $perfil]);
        $sagas = [];
        foreach ($l as $x) {
            $s = &$sagas[(int) $x['id']];
            $s ??= ['id' => (int) $x['id'], 'nome' => $x['nome'], 'fundo' => Base::img($x['fundo'], 'w780'),
                    'vistos' => 0, 'na_lista' => 0, 'partes' => (int) $x['partes'], 'proximo' => null];
            $s['na_lista']++;
            if (Base::b($x['visto'])) {
                $s['vistos']++;
            } elseif ($s['proximo'] === null) {
                $s['proximo'] = (int) $x['titulo_id'];
            }
            unset($s);
        }
        $sagas = array_filter($sagas, fn($s) => $s['vistos'] > 0 && $s['proximo'] !== null);
        Cartoes::carregar(array_column($sagas, 'proximo'), $perfil);
        $out = [];
        foreach ($sagas as $s) {
            $c = Cartoes::de($s['proximo']);
            if ($c) {
                $out[] = ['id' => $s['id'], 'nome' => $s['nome'], 'vistos' => $s['vistos'],
                          'total' => max($s['partes'], $s['na_lista']), 'proximo' => $c, 'fundo' => $s['fundo'] ?? $c['fundo']];
            }
        }
        usort($out, fn($a, $b) => $b['vistos'] <=> $a['vistos']);
        return array_slice($out, 0, $limite);
    }

    // ------------------------------------------------------------- seções

    public static function secao(Pedido $p): array
    {
        $perfil = $p->perfil();
        $tipo = self::tipo($p);
        $nome = $p->str('nome', 'para-voce');
        $fileiras = match ($nome) {
            'para-voce'   => self::paraVoce($perfil, $tipo),
            'top10'       => array_map(fn($g) => ['titulo' => $g['nome'], 'codigo' => $g['codigo'], 'itens' => $g['itens']],
                Top10::fileiras($tipo, $perfil, (bool) Base::pref($perfil, 'esconder_fora_lista', false))),
            'lancamentos' => self::lancamentos($perfil, $tipo),
            'sagas'       => $tipo === 'm' ? self::secaoSagas($perfil) : [],
            '4k'          => self::secao4k($perfil, $tipo),
            default       => throw new Erro("seção '$nome' não existe", 404),
        };
        return ['fileiras' => array_values(array_filter($fileiras, fn($f) => !empty($f['itens'])))];
    }

    private static function soTipo(array $cartoes, string $tipo, int $limite = self::POR_FILEIRA): array
    {
        return array_slice(array_values(array_filter($cartoes, fn($c) => $c['tipo'] === $tipo)), 0, $limite);
    }

    private static function paraVoce(int $perfil, string $tipo): array
    {
        $rec = self::rec($perfil);
        $f = [];
        $f[] = ['titulo' => 'Continuar assistindo', 'itens' => self::cartoesComSelo(self::continuar($perfil, $tipo), $perfil)];
        // listas próprias do tipo (recomendador r4); sem elas (recomendação antiga), as gerais filtradas
        $pt = is_array($rec['por_tipo'][$tipo] ?? null) ? $rec['por_tipo'][$tipo] : null;
        $f[] = ['titulo' => 'Recomendados para você', 'itens' => self::soTipo(Cartoes::lista(self::ids($pt['recomendados'] ?? $rec['recomendados'] ?? []), $perfil, 200), $tipo)];
        foreach ((array) ($rec['porque'] ?? []) as $x) {
            $origem = is_array($x) ? (((int) ($x['titulo_id'] ?? 0) > 0 ? self::origem((int) $x['titulo_id'], $perfil) : null) ?: (($x['nome'] ?? '') ?: null)) : null;
            if ($origem) {
                $f[] = ['titulo' => 'Porque você assistiu ' . $origem, 'itens' => self::soTipo(Cartoes::lista(self::ids($x['ids'] ?? []), $perfil, 200), $tipo)];
            }
        }
        foreach ((array) ($pt['generos'] ?? $rec['generos'] ?? []) as $g) {
            if (is_array($g)) {
                $f[] = ['titulo' => (string) ($g['nome'] ?? '') . ' para você', 'itens' => self::soTipo(Cartoes::lista(self::ids($g['ids'] ?? []), $perfil, 200), $tipo)];
            }
        }
        $f[] = ['titulo' => 'Minha lista', 'itens' => self::soTipo(Cartoes::lista(Cartoes::minhaLista($perfil), $perfil, 200), $tipo)];
        return $f;
    }

    private static function origem(int $id, int $perfil): ?string
    {
        Cartoes::carregar([$id], $perfil);
        return Cartoes::linha($id)['nome'] ?? null;
    }

    private static function lancamentos(int $perfil, string $tipo): array
    {
        $ano = (int) date('Y');
        $ids = fn(string $sql, array $par) => array_map('intval', Base::coluna($sql, $par));
        return [
            ['titulo' => 'Chegaram nos últimos dias', 'itens' => Cartoes::lista($ids(
                "select id from play.titulo where tipo = ? and ativo order by adicionado_em desc nulls last limit 30", [$tipo]), $perfil)],
            ['titulo' => "Lançamentos de $ano", 'itens' => Cartoes::lista($ids(
                "select id from play.titulo where tipo = ? and ativo and ano = ? order by adicionado_em desc nulls last limit 30", [$tipo, $ano]), $perfil)],
            ['titulo' => 'Lançamentos de ' . ($ano - 1), 'itens' => Cartoes::lista($ids(
                "select id from play.titulo where tipo = ? and ativo and ano = ? order by coalesce(cast(jw->>'imdb_votos' as numeric), 0) desc, adicionado_em desc nulls last limit 30",
                [$tipo, $ano - 1]), $perfil)],
        ];
    }

    private static function secaoSagas(int $perfil): array
    {
        $f = [];
        $comecadas = self::sagas($perfil, 20);
        if ($comecadas) {
            $f[] = ['titulo' => 'Continue a saga', 'itens' => array_map(fn($s) => $s['proximo'] + ['saga' => ['id' => $s['id'], 'nome' => $s['nome'], 'vistos' => $s['vistos'], 'total' => $s['total']]], $comecadas)];
        }
        $cols = Base::linhas("
            select c.id, c.nome from play.colecao c
              join play.titulo t on t.colecao_id = c.id and t.ativo and t.tipo = 'm'
             group by c.id, c.nome having count(*) >= 2
             order by max(t.adicionado_em) desc nulls last limit 25");
        if ($cols) {
            $porCol = [];
            foreach (Base::linhas("select id, colecao_id from play.titulo where colecao_id = any(cast(? as int[])) and ativo and tipo = 'm'
                                    order by ano nulls last, id", [Base::arr(array_column($cols, 'id'))]) as $t) {
                $porCol[(int) $t['colecao_id']][] = (int) $t['id'];
            }
            Cartoes::carregar(array_merge(...array_values($porCol) ?: [[]]), $perfil);
            foreach ($cols as $c) {
                $f[] = ['titulo' => $c['nome'], 'saga_id' => (int) $c['id'], 'itens' => Cartoes::lista($porCol[(int) $c['id']] ?? [], $perfil)];
            }
        }
        return $f;
    }

    private static function secao4k(int $perfil, string $tipo): array
    {
        $filtro = "tipo = ? and ativo and (qualidade = '4k' or exists (select 1 from play.versao v where v.titulo_id = t.id and v.uhd and v.ativo))";
        $ids = fn(string $ordem) => array_map('intval', Base::coluna("select t.id from play.titulo t where $filtro order by $ordem limit 30", [$tipo]));
        $rec = self::ids(self::rec($perfil)['recomendados'] ?? []);
        $f = [
            ['titulo' => 'Novos em 4K', 'itens' => Cartoes::lista($ids('t.adicionado_em desc nulls last'), $perfil)],
            ['titulo' => 'Mais bem avaliados em 4K', 'itens' => Cartoes::lista($ids("coalesce(cast(t.jw->>'imdb' as numeric), t.nota_painel, 0) desc"), $perfil)],
        ];
        if ($rec) {
            $em4k = array_map('intval', Base::coluna("select t.id from play.titulo t where $filtro and t.id = any(cast(? as bigint[]))", [$tipo, Base::arr($rec)]));
            $f[] = ['titulo' => 'Para você em 4K', 'itens' => Cartoes::lista(array_values(array_intersect($rec, $em4k)), $perfil)];
        }
        return $f;
    }

    // ------------------------------------------------------ Suas séries

    public static function suasSeries(Pedido $p): array
    {
        $perfil = $p->perfil();
        $novos = self::episodiosNovos($perfil, 14);
        $seguidas = array_map('intval', Base::coluna('select titulo_id from play.serie_seguida where perfil_id = ? order by desde desc limit 100', [$perfil]));
        Cartoes::carregar(array_merge(array_column($novos, 'id'), $seguidas), $perfil);

        $chegaram = [];
        foreach ($novos as $e) {
            $c = Cartoes::de($e['id'], 'NOVO · T' . $e['temporada'] . ':E' . $e['numero']);
            if ($c) {
                $chegaram[] = $c + ['quando' => $e['quando']];
            }
        }

        // Próximos episódios: o worker guarda o "próximo a ir ao ar" do TMDB nos detalhes da série.
        $proximos = [];
        if ($seguidas) {
            $avisos = [];
            foreach (Base::linhas("select id, alvo from play.aviso where perfil_id = ? and tipo = 'episodio' and status = 'ativo'", [$perfil]) as $a) {
                $alvo = Base::json($a['alvo']);
                $avisos[($alvo['titulo'] ?? 0) . ':' . ($alvo['temporada'] ?? 0) . ':' . ($alvo['episodio'] ?? 0)] = (int) $a['id'];
            }
            $hoje = new \DateTimeImmutable('today', Base::tz());
            foreach (Base::linhas("select id, detalhes from play.titulo where id = any(cast(? as bigint[])) and tipo = 's'", [Base::arr($seguidas)]) as $t) {
                $prox = Base::det(Base::json($t['detalhes']), 'proximo_ep', 'proximo_episodio', 'next_episode_to_air');
                if (!is_array($prox)) {
                    continue;
                }
                $data = (string) ($prox['data'] ?? $prox['air_date'] ?? '');
                $temp = Base::inteiroOuNulo($prox['temporada'] ?? $prox['season_number'] ?? null);
                $ep = Base::inteiroOuNulo($prox['episodio'] ?? $prox['episode_number'] ?? null);
                $semData = !preg_match('/^\d{4}-\d{2}-\d{2}$/', $data);
                if (($semData && $temp === null) || (!$semData && $data < $hoje->format('Y-m-d'))) {
                    continue;
                }
                $c = Cartoes::de((int) $t['id']);
                if (!$c) {
                    continue;
                }
                $proximos[] = [
                    'data'      => $semData ? null : $data,
                    'rotulo'    => $semData ? 'Sem data' : self::rotuloData($data, $hoje),
                    'nota'      => $semData ? 'sem data exata' : null,
                    'serie'     => $c,
                    'temporada' => $temp,
                    'episodio'  => $ep,
                    'nome'      => $prox['nome'] ?? $prox['name'] ?? null,
                    'aviso_id'  => $avisos[$t['id'] . ':' . $temp . ':' . $ep] ?? null,
                ];
            }
            usort($proximos, fn($a, $b) => [$a['data'] === null, (string) $a['data']] <=> [$b['data'] === null, (string) $b['data']]);
        }
        return ['chegaram' => $chegaram, 'proximos' => array_slice($proximos, 0, 30)];
    }

    /** "Hoje", "Amanhã", "Sexta" (até 6 dias) ou "21/11". */
    public static function rotuloData(string $data, \DateTimeImmutable $hoje): string
    {
        $d = new \DateTimeImmutable($data, $hoje->getTimezone());
        $dias = (int) $hoje->setTime(0, 0)->diff($d->setTime(0, 0))->format('%r%a');
        return match (true) {
            $dias === 0 => 'Hoje',
            $dias === 1 => 'Amanhã',
            $dias > 1 && $dias < 7 => ['Domingo', 'Segunda', 'Terça', 'Quarta', 'Quinta', 'Sexta', 'Sábado'][(int) $d->format('w')],
            default => $d->format('d/m'),
        };
    }

    // ---------------------------------------------------------- categorias

    public static function categorias(Pedido $p): array
    {
        $perfil = $p->perfil();
        $tipo = self::tipo($p);
        $conta = Base::contaId();
        $totais = [];
        foreach (Base::linhas('select c, count(*) as n from play.titulo t, unnest(t.categorias) c where t.tipo = ? and t.ativo group by c', [$tipo]) as $x) {
            $totais[(int) $x['c']] = (int) $x['n'];
        }
        // Do painel ficam só Lançamentos, 4K e Cinema; o resto é pelos gêneros do JustWatch (decisão 08/11/2026).
        $cats = Base::linhas('select painel_id, nome_exib from play.categoria where conta_id = ? and tipo = ? order by painel_id', [$conta, $tipo]);
        $todas = [];
        foreach ($cats as $c) {
            $id = (int) $c['painel_id'];
            if (($totais[$id] ?? 0) > 0 && preg_match(Generos::PAINEL_MANTIDAS, (string) $c['nome_exib'])) {
                $todas[] = ['id' => $id, 'nome' => $c['nome_exib'], 'total' => $totais[$id]];
            }
        }
        $tg = Generos::totais($tipo);
        $generos = [];
        foreach (Generos::NOMES as $cod => $nome) {
            if (($tg[$cod] ?? 0) > 0) {
                $generos['jw:' . $cod] = ['id' => 'jw:' . $cod, 'nome' => $nome, 'total' => $tg[$cod]];
            }
        }
        uasort($generos, fn($a, $b) => strcmp(Texto::norm($a['nome']), Texto::norm($b['nome'])));
        $todas = array_merge($todas, array_values($generos));
        // Seu gosto: os gêneros (JustWatch) mais fortes do perfil, pelo recomendador; sem ele, pelo histórico.
        $pesos = [];
        foreach ((array) (self::rec($perfil)['gosto']['generos'] ?? []) as $g) {
            if (is_array($g) && !empty($g['tmdb'])) {
                $pesos[(int) $g['tmdb']] = (float) ($g['peso'] ?? 1);
            }
        }
        if (!$pesos) {
            foreach (Base::linhas('select g, count(*) as n
                                     from (select distinct titulo_id from play.historico where perfil_id = ?) h
                                     join play.titulo t on t.id = h.titulo_id and t.tipo = ?
                                     cross join unnest(t.generos) g
                                    group by g order by n desc limit 12', [$perfil, $tipo]) as $x) {
                $pesos[(int) $x['g']] = (float) $x['n'];
            }
        }
        $seu = [];
        foreach (array_keys(Generos::pesosDoPerfil($pesos)) as $cod) {
            if (isset($generos['jw:' . $cod]) && count($seu) < 6) {
                $seu[] = $generos['jw:' . $cod];
            }
        }
        return ['seu_gosto' => $seu, 'todas' => $todas];
    }

    public static function categoria(Pedido $p): array
    {
        $perfil = $p->perfil();
        $tipo = self::tipo($p);
        // id: número = categoria do painel (Lançamentos, 4K, Cinema); "jw:drm" = gênero do JustWatch
        $bruto = (string) ($p->rota['cat'] ?? '');     // texto: número do painel ou "jw:xxx" (já decodificado)
        if (preg_match('/^jw:([a-z]{3})$/', $bruto, $m)) {
            if (!isset(Generos::NOMES[$m[1]])) {
                throw new Erro('categoria não encontrada', 404);
            }
            $nome = Generos::NOMES[$m[1]];
            [$filtro, $parFiltro] = Generos::filtro($m[1]);
            $where = ['t.tipo = ?', 't.ativo', $filtro];
            $par = [$tipo, ...$parFiltro];
        } else {
            $cat = (int) $bruto;
            $nome = Base::valor('select nome_exib from play.categoria where conta_id = ? and tipo = ? and painel_id = ?', [Base::contaId(), $tipo, $cat]);
            if ($nome === null) {
                throw new Erro('categoria não encontrada', 404);
            }
            $where = ['t.tipo = ?', 't.ativo', 't.categorias @> array[cast(? as int)]'];
            $par = [$tipo, $cat];
        }
        $pagina = max(1, (int) $p->int('pagina', 1));
        $porPagina = 60;
        if ($p->bool('esconder_vistos')) {
            // visto = terminado ou Gostei (Base::VISTOS; série com Gostei = série vista)
            $where[] = 't.id not in ' . Base::VISTOS;
            array_push($par, ...Base::vistosPar($perfil));
        }
        if ($p->bool('so4k')) {
            $where[] = "(t.qualidade = '4k' or exists (select 1 from play.versao v where v.titulo_id = t.id and v.uhd and v.ativo))";
        }
        $w = implode(' and ', $where);
        $total = (int) Base::valor("select count(*) from play.titulo t where $w", $par);
        $ordem = match ($p->str('ordem', 'vistos')) {
            'recentes' => 't.adicionado_em desc nulls last, t.id desc',
            'nota'     => "coalesce(cast(t.jw->>'imdb' as numeric), t.nota_painel) desc nulls last, t.id desc",
            'az'       => 't.nome_norm, t.id',
            default    => "coalesce(h.n, 0) desc, coalesce(cast(t.jw->>'imdb_votos' as numeric), 0) desc, t.adicionado_em desc nulls last, t.id desc",
        };
        $ids = array_map('intval', Base::coluna("
            select t.id from play.titulo t
              left join (select titulo_id, count(*) as n from play.historico group by titulo_id) h on h.titulo_id = t.id
             where $w order by $ordem limit ? offset ?", [...$par, $porPagina, ($pagina - 1) * $porPagina]));
        return [
            'nome'    => $nome,
            'total'   => $total,
            'pagina'  => $pagina,
            'paginas' => max(1, (int) ceil($total / $porPagina)),
            'itens'   => Cartoes::lista($ids, $perfil, $porPagina),
        ];
    }

    // ------------------------------------------------------------- ranking

    public static function ranking(Pedido $p): array
    {
        $perfil = $p->perfil();
        $tipo = self::tipo($p);
        return [
            'atualizado'  => Top10::atualizado($tipo),
            'plataformas' => Top10::fileiras($tipo, $perfil, (bool) Base::pref($perfil, 'esconder_fora_lista', false)),
        ];
    }

    public static function tipo(Pedido $p): string
    {
        $t = $p->str('tipo', 'm');
        if (!in_array($t, ['m', 's'], true)) {
            throw new Erro('tipo deve ser m ou s', 422);
        }
        return $t;
    }
}
