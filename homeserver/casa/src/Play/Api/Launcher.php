<?php
declare(strict_types=1);

namespace Casa\Play\Api;

use Casa\Play\Dados\Banco;
use Casa\Play\Dados\Generos;

/**
 * Home das TVs (launcher "WebOS TV", 09/11/2026): tudo o que a tela inicial mostra numa chamada só,
 * montado aqui para a TV só desenhar. Sem token (a Home roda em outra origem e não tem o do Player),
 * mas só pela rede de casa, como o registro.
 *
 * GET launcher?v=<versão anterior>&fundo=w780|w1280&poster=w185|w342
 *   -> {v, destaque, continuar, jogos, recomendados, top_brasil, aviso, clima}
 *      (continuar pode ter um cartão {tipo:'canal'}: o último canal do Ao vivo, ver Launcher::canal)
 *   -> {v, igual:true} quando nada mudou (a TV pergunta a cada minuto com a tela à vista)
 * A TV guarda a última resposta: com o homeserver fora, mostra a cópia (imagens ficam no cache do navegador, 30 dias).
 */
final class Launcher
{
    private const POR_FILEIRA = 20;

    public static function rota(Pedido $p): array
    {
        if (!Tvs::ipLocal(Acesso::ip())) {
            throw new Erro('só pela rede de casa', 403);
        }
        $perfil = (int) Base::valor('select min(id) from play.perfil');
        // TV 1 (webOS 10, tela em 1080p): w1280/w342. TV 2 (webOS 4, tela em 720p): w780/w185.
        $fundo = $p->str('fundo') === 'w1280' ? 'w1280' : 'w780';
        $poster = $p->str('poster') === 'w185' ? 'w185' : 'w342';
        $d = self::montar($perfil, $fundo, $poster);
        $v = substr(md5(json_encode($d)), 0, 12);
        return $p->str('v') === $v ? ['v' => $v, 'igual' => true] : ['v' => $v] + $d;
    }

    public static function montar(int $perfil, string $fundo, string $poster = 'w342'): array
    {
        $rec = Inicio::rec($perfil);
        $continuar = Inicio::continuar($perfil, null, 12);
        $recIds = array_slice(Inicio::ids($rec['recomendados'] ?? []), 0, self::POR_FILEIRA);
        Cartoes::carregar(array_merge(array_column($continuar, 'id'), $recIds), $perfil);

        $novos = array_flip(array_map('intval', array_column(Inicio::episodiosNovos($perfil, 7), 'id')));
        $cont = array_map(function (array $c) use ($novos) {
            $c['episodio_novo'] = isset($novos[$c['id']]);
            return $c;
        }, Inicio::cartoesComSelo($continuar, $perfil));
        $recs = Cartoes::lista($recIds, $perfil, self::POR_FILEIRA);
        $top = Top10::brasil($perfil, true);
        $topItens = array_slice(array_values(array_filter((array) ($top['itens'] ?? []), fn($c) => !empty($c['id']))), 0, 20);   // Top 20 (pedido 09/11)
        $jogos = AoVivo::jogosDo($perfil);

        // sinopse e fundo no tamanho da tela: o destaque mostra o título em foco
        $todos = array_merge($cont, $recs, $topItens);
        $extra = self::extras(array_values(array_unique(array_column($todos, 'id'))), $fundo);
        $enfeitar = fn(array $l) => array_map(fn($c) => self::enxuto($c, $extra[$c['id']] ?? [], $poster), $l);

        // último canal do Ao vivo no Continuar (1º se mais recente que o 1º título, senão 2º; só < 24 h)
        $continuar = array_map(fn($c) => ($c['tipo'] ?? '') === 'canal' ? self::canal($c) : self::enxuto($c, $extra[$c['id']] ?? [], $poster),
            Inicio::encaixarCanal($cont, Inicio::ultimoCanal($perfil)));
        $d = [
            'continuar'    => $continuar,
            'jogos'        => array_map([self::class, 'jogo'], $jogos),
            'recomendados' => $enfeitar($recs),
            'top_brasil'   => $enfeitar($topItens),
            'aviso'        => self::proximoAviso($perfil),
            'clima'        => Base::json(Base::valor("select dados from play.cache where chave = 'clima' and em > now() - interval '3 hours'")) ?: null,
        ];
        $d['destaque'] = self::destaque($d);
        return $d;
    }

    /** Ordem do destaque em repouso: jogo ao vivo, depois o Continuar mais recente, depois a melhor recomendação. Puro. */
    public static function destaque(array $d): ?array
    {
        foreach ($d['jogos'] as $i => $j) {
            if ($j['status'] === 'agora') {
                return ['fileira' => 'jogos', 'i' => $i];
            }
        }
        if ($d['continuar']) {
            return ['fileira' => 'continuar', 'i' => 0];
        }
        return $d['recomendados'] ? ['fileira' => 'recomendados', 'i' => 0] : null;
    }

    /** Só o que a Home usa (menos bytes para a TV 2). */
    private static function enxuto(array $c, array $x, string $poster = 'w342'): array
    {
        $o = ['id' => $c['id'], 'tipo' => $c['tipo'], 'nome' => $c['nome'], 'ano' => $c['ano'], 'imdb' => $c['imdb'],
              'poster' => $poster === 'w342' || !$c['poster'] ? $c['poster'] : str_replace('/w342/', '/' . $poster . '/', $c['poster']), 'fundo' => $x['fundo'] ?? $c['fundo'], 'sinopse' => $x['sinopse'] ?? null,
              'generos' => $x['generos'] ?? []];
        foreach (['progresso', 'selo', 'posicao', 'episodio_novo', 'qualidade'] as $k) {
            if (!empty($c[$k])) {
                $o[$k] = $c[$k];
            }
        }
        return $o;
    }

    /**
     * Cartão de canal da Home: {tipo:'canal', id, nome, numero, logo (URL de imagem do homeserver ou null),
     * agora:{titulo, inicio, fim}|null, quando}. Sem "pct": a Home calcula pelo relógio, e assim a resposta
     * (e o "v") não muda a cada minuto só por causa da barra do programa. Puro.
     */
    public static function canal(array $c): array
    {
        $a = $c['agora'] ?? null;
        return ['tipo' => 'canal', 'id' => (int) $c['id'], 'nome' => (string) $c['nome'], 'numero' => $c['numero'] ?? null,
                'logo' => $c['logo'] ?? null, 'agora' => $a ? ['titulo' => $a['titulo'], 'inicio' => $a['inicio'], 'fim' => $a['fim']] : null,
                'quando' => $c['quando'] ?? null];
    }

    /** Sinopse curta e fundo no tamanho pedido. */
    private static function extras(array $ids, string $fundo): array
    {
        if (!$ids) {
            return [];
        }
        $out = [];
        foreach (Base::linhas("select id, sinopse, fundo, generos, jw->'g' as g from play.titulo where id = any(cast(? as bigint[]))", ['{' . implode(',', $ids) . '}']) as $l) {
            // gêneros do JustWatch (os mesmos das Categorias do Player); sem JW, os do TMDB traduzidos
            $gen = Generos::doTitulo(Base::json($l['g']) ?: null, Banco::lerInts($l['generos']));
            $out[(int) $l['id']] = ['sinopse' => self::curta((string) $l['sinopse']), 'fundo' => Base::img($l['fundo'] ?? null, $fundo),
                                    'generos' => array_slice(array_map(fn($c) => Generos::NOMES[$c], $gen), 0, 2)];
        }
        return $out;
    }

    /** Até ~170 caracteres, cortando em fim de frase ou palavra. Puro. */
    public static function curta(string $s, int $max = 170): ?string
    {
        $s = trim(preg_replace('/\s+/u', ' ', $s) ?? '');
        if ($s === '') {
            return null;
        }
        if (mb_strlen($s) <= $max) {
            return $s;
        }
        $c = mb_substr($s, 0, $max);
        $ponto = mb_strrpos($c, '. ');
        if ($ponto !== false && $ponto > $max * 0.55) {
            return mb_substr($c, 0, $ponto + 1);
        }
        return rtrim(mb_substr($c, 0, (int) mb_strrpos($c, ' ')), ',;:') . '…';
    }

    private static function jogo(array $j): array
    {
        $c = $j['canal'] ?? null;
        // o painel chama "NBA Lakers": com a liga já à parte, o nome fica só "Lakers"
        $tira = function (array $t) use ($j): array {
            $liga = (string) ($j['liga'] ?? '');
            if ($liga !== '' && stripos((string) $t['nome'], $liga . ' ') === 0) {
                $t['nome'] = substr((string) $t['nome'], strlen($liga) + 1);
            }
            return $t;
        };
        return ['id' => $j['id'], 'inicio' => $j['inicio'], 'status' => $j['status'], 'minuto' => $j['minuto'], 'placar' => $j['placar'],
                'liga' => $j['liga'], 'casa' => $tira($j['casa']), 'fora' => $tira($j['fora']), 'aviso' => $j['aviso_id'] !== null,
                'canal' => $c ? ['id' => $c['id'], 'nome' => $c['nome']] : null];
    }

    /** Próximo aviso ligado (jogo, programa, episódio) para a pílula do topo. */
    private static function proximoAviso(int $perfil): ?array
    {
        $a = Base::linha("select id, tipo, alvo, quando, texto from play.aviso
                           where perfil_id = ? and status = 'ativo' and quando is not null and quando > now() - interval '10 minutes'
                           order by quando limit 1", [$perfil]);
        if (!$a) {
            return null;
        }
        $alvo = Base::json($a['alvo']);
        return ['id' => (int) $a['id'], 'tipo' => $a['tipo'], 'texto' => $a['texto'], 'quando' => Base::iso($a['quando']),
                'canal' => isset($alvo['canal']) ? (int) $alvo['canal'] : null, 'titulo' => isset($alvo['titulo']) ? (int) $alvo['titulo'] : null];
    }
}
