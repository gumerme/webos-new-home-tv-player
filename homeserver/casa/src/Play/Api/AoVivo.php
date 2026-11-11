<?php
declare(strict_types=1);

namespace Casa\Play\Api;

use Casa\Play\Dados\Epg;
use Casa\Play\Dados\Sinais;
use Casa\Play\Dados\Xtream;

/** Ao vivo: grupos (Jogos de hoje, Favoritos, categorias do painel), jogos, canais, grade e tocar. */
final class AoVivo
{
    /** Canais no formato do contrato, na ordem dos ids pedidos. */
    public static function canaisPorIds(array $ids, int $perfil): array
    {
        if (!$ids) {
            return [];
        }
        $porId = [];
        foreach (Base::linhas("
            select c.painel_id, c.nome, c.numero, c.logo, cat.nome_exib as grupo,
                   (f.canal_id is not null) as favorito,
                   pr.titulo as agora_titulo, pr.inicio as agora_inicio, pr.fim as agora_fim
              from play.canal c
              left join play.categoria cat on cat.conta_id = c.conta_id and cat.tipo = 'c' and cat.painel_id = c.categoria_id
              left join play.canal_fav f on f.perfil_id = ? and f.conta_id = c.conta_id and f.canal_id = c.painel_id
              left join lateral (
                    select titulo, inicio, fim from play.programa p
                     where p.conta_id = c.conta_id and p.canal_id = c.painel_id and p.fim > now() and p.inicio <= now()
                     order by p.fim limit 1) pr on true
             where c.conta_id = ? and c.ativo and c.painel_id = any(cast(? as int[]))", [$perfil, Base::contaId(), Base::arr($ids)]) as $c) {
            $porId[(int) $c['painel_id']] = self::canal($c);
        }
        $out = [];
        foreach ($ids as $id) {
            if (isset($porId[(int) $id])) {
                $out[] = $porId[(int) $id];
                unset($porId[(int) $id]);
            }
        }
        return $out;
    }

    /** Linha -> Canal (puro). */
    public static function canal(array $c, ?int $agora = null): array
    {
        $agora ??= time();
        $prog = null;
        if (!empty($c['agora_titulo'])) {
            $ini = strtotime((string) $c['agora_inicio']);
            $fim = strtotime((string) $c['agora_fim']);
            $prog = [
                'titulo' => $c['agora_titulo'],
                'inicio' => Base::iso($c['agora_inicio']),
                'fim'    => Base::iso($c['agora_fim']),
                'pct'    => $fim > $ini ? max(0, min(100, (int) round(($agora - $ini) * 100 / ($fim - $ini)))) : 0,
            ];
        }
        return [
            'id'        => (int) $c['painel_id'],
            'nome'      => (string) $c['nome'],
            'numero'    => Base::inteiroOuNulo($c['numero'] ?? null),
            'logo'      => Base::img($c['logo'] ?? null, 'w185'),
            'grupo'     => $c['grupo'] ?? null,
            'favorito'  => Base::b($c['favorito'] ?? false),
            'agora'     => $prog,
            'qualidade' => Base::qualidadeCanal((string) $c['nome']),
            'xt'        => ['id' => (int) $c['painel_id']],   // a TV toca sem o homeserver com a cópia de GET conta
        ];
    }

    private static function hoje(): string
    {
        return (new \DateTimeImmutable('now', Base::tz()))->format('Y-m-d');
    }

    /** [{id:"jogos"|"fav"|<categoria>, nome, total}]: jogos só se houver jogo hoje (a TV abre no primeiro). */
    public static function grupos(Pedido $p): array
    {
        $perfil = $p->perfil();
        $conta = Base::contaId();
        $out = [];
        $jogos = (int) Base::valor("select count(*) from play.jogo where data = cast(? as date) or status = 'agora'", [self::hoje()]);
        if ($jogos > 0) {
            $out[] = ['id' => 'jogos', 'nome' => 'Jogos de hoje', 'total' => $jogos];
        }
        $fav = (int) Base::valor('select count(*) from play.canal_fav f join play.canal c on c.conta_id = f.conta_id and c.painel_id = f.canal_id and c.ativo
                                   where f.perfil_id = ? and f.conta_id = ?', [$perfil, $conta]);
        if ($fav > 0) {
            $out[] = ['id' => 'fav', 'nome' => 'Favoritos', 'total' => $fav];
        }
        foreach (Base::linhas("
            select cat.painel_id, cat.nome_exib, count(*) as total
              from play.categoria cat
              join play.canal c on c.conta_id = cat.conta_id and c.categoria_id = cat.painel_id and c.ativo
             where cat.conta_id = ? and cat.tipo = 'c'
             group by cat.painel_id, cat.nome_exib
             order by min(c.numero) nulls last, cat.nome_exib", [$conta]) as $c) {
            $out[] = ['id' => (string) $c['painel_id'], 'nome' => $c['nome_exib'], 'total' => (int) $c['total']];
        }
        return $out;
    }

    public static function jogos(Pedido $p): array
    {
        return self::jogosDo($p->perfil());
    }

    /** Jogos de hoje do perfil (também usados pela Home das TVs, Launcher). */
    public static function jogosDo(int $perfil): array
    {
        $jogos = Base::linhas("select id, inicio, status, minuto, placar, casa, fora, liga, canal_id from play.jogo
                                where data = cast(? as date) or status = 'agora'
                                -- em andamento primeiro, depois os que vão começar, por último os encerrados (mais recente antes)
                                order by case status when 'agora' then 0 when 'fim' then 2 else 1 end,
                                         case when status = 'fim' then -extract(epoch from inicio) else extract(epoch from inicio) end, id", [self::hoje()]);
        $canais = array_column(self::canaisPorIds(array_values(array_unique(array_filter(array_map(fn($j) => (int) $j['canal_id'], $jogos)))), $perfil), null, 'id');
        $avisos = [];
        foreach (Base::linhas("select id, alvo->>'jogo' as jogo from play.aviso where perfil_id = ? and tipo = 'jogo' and status = 'ativo'", [$perfil]) as $a) {
            $avisos[(string) $a['jogo']] = (int) $a['id'];
        }
        $time = fn($t) => ['nome' => $t['nome'] ?? '', 'sigla' => $t['sigla'] ?? null, 'escudo' => Base::img($t['escudo'] ?? null, 'w185')];
        return array_map(fn($j) => [
            'id'       => $j['id'],
            'inicio'   => Base::iso($j['inicio']),
            'status'   => $j['status'],
            'minuto'   => $j['minuto'],
            'placar'   => $j['placar'],
            'liga'     => $j['liga'],
            'casa'     => $time(Base::json($j['casa'])),
            'fora'     => $time(Base::json($j['fora'])),
            'canal'    => $canais[(int) $j['canal_id']] ?? null,
            'aviso_id' => $avisos[(string) $j['id']] ?? null,
        ], $jogos);
    }

    public static function canais(Pedido $p): array
    {
        $perfil = $p->perfil();
        $conta = Base::contaId();
        $grupo = $p->str('grupo', 'fav');
        $ids = match (true) {
            $grupo === 'fav'   => Base::coluna('select canal_id from play.canal_fav where perfil_id = ? and conta_id = ? order by ordem, canal_id', [$perfil, $conta]),
            $grupo === 'jogos' => Base::coluna("select canal_id from play.jogo where (data = cast(? as date) or status = 'agora') and canal_id is not null
                                                group by canal_id order by min(inicio)", [self::hoje()]),
            (bool) preg_match('/^\d{1,9}$/', $grupo) => Base::coluna('select painel_id from play.canal where conta_id = ? and categoria_id = ? and ativo
                                                                        order by numero nulls last, nome', [$conta, (int) $grupo]),
            default => throw new Erro('grupo inválido', 422),
        };
        return self::canaisPorIds(array_map('intval', $ids), $perfil);
    }

    /** GET aovivo/grade?canais=1,2,3&de=ISO&ate=ISO (no máximo 40 canais e 24 h). */
    public static function grade(Pedido $p): array
    {
        $perfil = $p->perfil();
        $conta = Base::contaId();
        $ids = array_slice(array_values(array_unique(array_filter(array_map('intval', explode(',', $p->str('canais')))))), 0, 40);
        if (!$ids) {
            return ['canais' => []];
        }
        $tz = Base::tz();
        try {
            $de = $p->str('de') !== '' ? new \DateTimeImmutable($p->str('de')) : new \DateTimeImmutable('-30 minutes', $tz);
            $ate = $p->str('ate') !== '' ? new \DateTimeImmutable($p->str('ate')) : $de->modify('+4 hours');
        } catch (\Throwable) {
            throw new Erro('de/ate inválidos (ISO 8601)', 422);
        }
        if ($ate <= $de) {
            throw new Erro('ate precisa ser depois de de', 422);
        }
        if ($ate->getTimestamp() - $de->getTimestamp() > 86400) {
            $ate = $de->modify('+24 hours');
        }
        // Canal sem programação até "ate": pede a grade ao painel (no máximo 8 por chamada, para não demorar).
        $cobre = [];
        foreach (Base::linhas('select canal_id, max(fim) >= cast(? as timestamptz) as ok from play.programa
                                where conta_id = ? and canal_id = any(cast(? as int[])) group by canal_id',
            [$ate->format(DATE_ATOM), $conta, Base::arr($ids)]) as $x) {
            $cobre[(int) $x['canal_id']] = Base::b($x['ok']);
        }
        $pedidos = 0;
        foreach ($ids as $id) {
            if (empty($cobre[$id]) && $pedidos < 8) {
                $pedidos++;
                try {
                    Epg::canal($conta, $id);
                } catch (\Throwable $e) {
                    error_log("play: EPG do canal $id: " . $e->getMessage());
                }
            }
        }
        $avisos = [];
        foreach (Base::linhas("select id, alvo->>'programa' as programa from play.aviso where perfil_id = ? and tipo = 'programa' and status = 'ativo'", [$perfil]) as $a) {
            $avisos[(int) $a['programa']] = (int) $a['id'];
        }
        $grade = [];
        foreach ($ids as $id) {
            $grade[$id] = ['id' => $id, 'programas' => []];
        }
        foreach (Base::linhas('select id, canal_id, titulo, inicio, fim, descricao from play.programa
                                where conta_id = ? and canal_id = any(cast(? as int[]))
                                  and fim > cast(? as timestamptz) and inicio < cast(? as timestamptz)
                                order by canal_id, inicio', [$conta, Base::arr($ids), $de->format(DATE_ATOM), $ate->format(DATE_ATOM)]) as $x) {
            $grade[(int) $x['canal_id']]['programas'][] = [
                'id'        => (int) $x['id'],
                'titulo'    => $x['titulo'],
                'inicio'    => Base::iso($x['inicio']),
                'fim'       => Base::iso($x['fim']),
                'descricao' => $x['descricao'],
                'aviso_id'  => $avisos[(int) $x['id']] ?? null,
            ];
        }
        return ['de' => $de->setTimezone($tz)->format(DATE_ATOM), 'ate' => $ate->setTimezone($tz)->format(DATE_ATOM), 'canais' => array_values($grade)];
    }

    public static function tocar(Pedido $p): array
    {
        $conta = Base::conta() ?? throw new Erro('nenhuma lista IPTV ativa no painel', 409);
        $id = $p->id('canal');
        $nome = Base::valor('select nome from play.canal where conta_id = ? and painel_id = ?', [$conta['id'], $id])
            ?? throw new Erro('canal não encontrado', 404);
        if ($tv = $p->ctx->tvId()) {
            Base::exec('update play.tv set assistindo = cast(? as jsonb) where id = ?', [json_encode([
                'canal' => $id, 'nome' => $nome, 'desde' => date(DATE_ATOM), 'perfil' => $p->perfil(),
            ], JSON_UNESCAPED_UNICODE), $tv]);
        }
        self::lembrarCanal($p->perfil(), $id);
        return ['url' => Xtream::linkCanal($conta, $id), 'formato' => 'hls', 'nome' => $nome,
                'canal' => self::canaisPorIds([$id], $p->perfil())[0] ?? null];
    }

    /**
     * Último canal do perfil para o "Continuar assistindo" (Início do Player e Home das TVs):
     * play.preferencia 'ultimo_canal' = {id, em}. Gravado aqui, a cada GET aovivo/tocar (tela cheia ou prévia),
     * para não depender do app; apagado pelo Verde no Continuar (POST preferencia {chave:'ultimo_canal', valor:null}).
     * Falha aqui nunca impede de tocar.
     */
    public static function lembrarCanal(int $perfil, int $canal): void
    {
        try {
            Base::exec("insert into play.preferencia (perfil_id, chave, valor) values (?, 'ultimo_canal', cast(? as jsonb))
                        on conflict (perfil_id, chave) do update set valor = excluded.valor",
                [$perfil, json_encode(['id' => $canal, 'em' => date(DATE_ATOM)])]);
        } catch (\Throwable $e) {
            error_log('play: último canal não gravado: ' . $e->getMessage());
        }
    }

    /** POST aovivo/favorito {perfil, canal, acao: add|del}. Novo favorito entra em primeiro (como na v10). */
    public static function favorito(Pedido $p): array
    {
        $perfil = $p->perfil();
        $conta = Base::contaId();
        $canal = $p->int('canal') ?? throw new Erro('informe o canal', 422);
        if ($p->str('acao', 'add') === 'del') {
            Base::exec('delete from play.canal_fav where perfil_id = ? and conta_id = ? and canal_id = ?', [$perfil, $conta, $canal]);
            self::avisarFavoritos($p, $perfil);
            return ['ok' => true, 'favorito' => false];
        }
        if (!Base::valor('select 1 from play.canal where conta_id = ? and painel_id = ?', [$conta, $canal])) {
            throw new Erro('canal não encontrado', 404);
        }
        Base::exec('insert into play.canal_fav (perfil_id, conta_id, canal_id, ordem)
                    values (?, ?, ?, (select coalesce(min(ordem), 0) - 1 from play.canal_fav where perfil_id = ? and conta_id = ?))
                    on conflict do nothing', [$perfil, $conta, $canal, $perfil, $conta]);
        self::avisarFavoritos($p, $perfil);
        return ['ok' => true, 'favorito' => true];
    }

    /** Favoritos mudaram: as outras TVs recarregam a tela (sinal "atualizado"); a que mudou já sabe. */
    private static function avisarFavoritos(Pedido $p, int $perfil): void
    {
        try {
            Sinais::enviar('atualizado', ['o_que' => 'favoritos'], null, $perfil, $p->ctx->tvId());
        } catch (\Throwable $e) {
            error_log('play: sinal de favoritos não enviado: ' . $e->getMessage());
        }
    }
}
