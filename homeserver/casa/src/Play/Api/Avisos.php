<?php
declare(strict_types=1);

namespace Casa\Play\Api;

use Casa\Play\Dados\Legendas;

/** "Avisar" (jogo, programa, episódio, chegar na lista) e legendas. */
final class Avisos
{
    private const ANTES = 300;   // jogo/programa: avisa 5 min antes do início

    public static function listar(Pedido $p): array
    {
        $perfil = $p->perfil();
        return array_map(fn($a) => [
            'id'           => (int) $a['id'],
            'tipo'         => $a['tipo'],
            'alvo'         => Base::json($a['alvo']),
            'quando'       => Base::iso($a['quando']),
            'status'       => $a['status'],
            'texto'        => $a['texto'],
            'criado_em'    => Base::iso($a['criado_em']),
            'disparado_em' => Base::iso($a['disparado_em']),
        ], Base::linhas("
            select id, tipo, alvo, quando, status, texto, criado_em, disparado_em
              from play.aviso
             where (perfil_id = ? or perfil_id is null)
               and (status = 'ativo' or (status = 'disparado' and disparado_em > now() - interval '2 days'))
             order by status, quando nulls last, id desc
             limit 100", [$perfil]));
    }

    /** POST aviso {perfil, tipo, alvo, quando?} -> {id}. Repetido devolve o mesmo id. */
    public static function criar(Pedido $p): array
    {
        $perfil = $p->perfil();
        $tipo = $p->str('tipo');
        $alvo = is_array($p->corpo['alvo'] ?? null) ? $p->corpo['alvo'] : throw new Erro('informe o alvo', 422);
        [$alvo, $chave, $quando, $texto] = match ($tipo) {
            'jogo'     => self::jogo($alvo, $p->str('quando')),
            'programa' => self::programa($alvo, $p->str('quando')),
            'episodio' => self::episodio($alvo, $p->str('quando')),
            'chegar'   => self::chegar($alvo),
            default    => throw new Erro('tipo de aviso inválido (jogo, programa, episodio, chegar)', 422),
        };
        $existe = Base::valor("select id from play.aviso where perfil_id = ? and tipo = ? and status = 'ativo' and alvo @> cast(? as jsonb) limit 1",
            [$perfil, $tipo, json_encode($chave, JSON_UNESCAPED_UNICODE)]);
        if ($existe !== null) {
            return ['id' => (int) $existe, 'quando' => $quando, 'repetido' => true];
        }
        $id = Base::valor('insert into play.aviso (perfil_id, tipo, alvo, quando, texto) values (?, ?, cast(? as jsonb), cast(? as timestamptz), ?) returning id',
            [$perfil, $tipo, json_encode($alvo, JSON_UNESCAPED_UNICODE), $quando, $texto]);
        return ['id' => (int) $id, 'quando' => $quando];
    }

    /** Início - 5 min em ISO (pode já ter passado: o worker dispara na hora). */
    public static function quandoAvisar(string $inicio): string
    {
        return (new \DateTimeImmutable($inicio))->modify('-' . self::ANTES . ' seconds')->setTimezone(Base::tz())->format(DATE_ATOM);
    }

    /** ISO de "quando" mandado pela TV (início do jogo/programa ou data do episódio), ou null. */
    private static function quandoTv(string $quando): ?string
    {
        if ($quando === '') {
            return null;
        }
        try {
            return (new \DateTimeImmutable($quando, Base::tz()))->setTimezone(Base::tz())->format(DATE_ATOM);
        } catch (\Throwable) {
            throw new Erro('quando inválido (ISO 8601)', 422);
        }
    }

    /** TV manda {jogo, canal} + quando (início). O banco manda no horário; sem o jogo no banco, vale o da TV. */
    private static function jogo(array $alvo, string $quando = ''): array
    {
        $id = (string) ($alvo['jogo'] ?? $alvo['id'] ?? '');
        $j = $id !== '' ? Base::linha('select id, inicio, status, casa, fora, canal_id from play.jogo where id = ?', [$id]) : null;
        if (!$j) {
            $q = self::quandoTv($quando) ?? throw new Erro('jogo não encontrado', 404);
            $nome = mb_substr(trim((string) ($alvo['nome'] ?? 'Jogo')), 0, 120);
            return [['jogo' => $id, 'canal' => Base::inteiroOuNulo($alvo['canal'] ?? null), 'nome' => $nome], ['jogo' => $id],
                    self::quandoAvisar($q), "$nome começa em 5 minutos"];
        }
        if ($j['status'] === 'fim') {
            throw new Erro('o jogo já terminou', 422);
        }
        $casa = Base::json($j['casa'])['nome'] ?? 'Casa';
        $fora = Base::json($j['fora'])['nome'] ?? 'Visitante';
        $novo = ['jogo' => $j['id'], 'canal' => Base::inteiroOuNulo($j['canal_id']), 'nome' => "$casa × $fora"];
        return [$novo, ['jogo' => $j['id']], self::quandoAvisar($j['inicio']), "$casa × $fora começa em 5 minutos"];
    }

    /** TV manda {canal, programa, titulo} + quando (início). */
    private static function programa(array $alvo, string $quando = ''): array
    {
        $pid = Base::inteiroOuNulo($alvo['programa'] ?? $alvo['id'] ?? null) ?? throw new Erro('informe o programa', 422);
        $existe = Base::valor('select 1 from play.programa where id = ?', [$pid]);
        if (!$existe && ($q = self::quandoTv($quando)) && ($canal = Base::inteiroOuNulo($alvo['canal'] ?? null))) {
            $titulo = mb_substr(trim((string) ($alvo['titulo'] ?? 'Programa')), 0, 200);
            return [['canal' => $canal, 'programa' => $pid, 'titulo' => $titulo], ['programa' => $pid], self::quandoAvisar($q), "$titulo começa em 5 minutos"];
        }
        $pr = Base::linha("select pr.id, pr.canal_id, pr.titulo, pr.inicio, pr.fim > now() as vale, c.nome as canal
                             from play.programa pr
                             left join play.canal c on c.conta_id = pr.conta_id and c.painel_id = pr.canal_id
                            where pr.id = ?", [$pid]) ?? throw new Erro('programa não encontrado', 404);
        if (!Base::b($pr['vale'])) {
            throw new Erro('o programa já terminou', 422);
        }
        $novo = ['canal' => (int) $pr['canal_id'], 'programa' => (int) $pr['id'], 'titulo' => $pr['titulo']];
        return [$novo, ['programa' => (int) $pr['id']], self::quandoAvisar($pr['inicio']),
                $pr['titulo'] . ' começa em 5 minutos' . ($pr['canal'] ? ' no ' . $pr['canal'] : '')];
    }

    private static function episodio(array $alvo, string $quando): array
    {
        $id = Base::inteiroOuNulo($alvo['titulo'] ?? null) ?? throw new Erro('informe a série', 422);
        $temp = Base::inteiroOuNulo($alvo['temporada'] ?? null) ?? throw new Erro('informe a temporada', 422);
        $ep = Base::inteiroOuNulo($alvo['episodio'] ?? null) ?? throw new Erro('informe o episódio', 422);
        $nome = Base::valor("select nome from play.titulo where id = ? and tipo = 's'", [$id]) ?? throw new Erro('série não encontrada', 404);
        $q = self::quandoTv($quando);   // data em que o episódio vai ao ar (a TV manda; o worker avisa quando chegar)
        $novo = ['titulo' => $id, 'temporada' => $temp, 'episodio' => $ep, 'nome' => $nome];
        return [$novo, ['titulo' => $id, 'temporada' => $temp, 'episodio' => $ep], $q, "Episódio novo de $nome: T$temp:E$ep"];
    }

    private static function chegar(array $alvo): array
    {
        $tmdb = Base::inteiroOuNulo($alvo['tmdb'] ?? null) ?? throw new Erro('informe o tmdb', 422);
        $tipo = in_array($alvo['tipo'] ?? 'm', ['m', 's'], true) ? (string) ($alvo['tipo'] ?? 'm') : throw new Erro('tipo deve ser m ou s', 422);
        $nome = mb_substr(trim((string) ($alvo['nome'] ?? '')), 0, 200);
        $ja = Base::valor('select id from play.titulo where tipo = ? and tmdb_id = ? and ativo limit 1', [$tipo, $tmdb]);
        if ($ja !== null) {
            throw new Erro('esse título já está na lista', 409, ['titulo' => (int) $ja]);
        }
        return [['tmdb' => $tmdb, 'tipo' => $tipo, 'nome' => $nome], ['tmdb' => $tmdb, 'tipo' => $tipo], null,
                ($nome !== '' ? $nome : 'O título que você pediu') . ' chegou na lista'];
    }

    public static function cancelar(Pedido $p): array
    {
        $n = Base::exec("update play.aviso set status = 'cancelado' where id = ? and status = 'ativo'", [$p->id()]);
        if ($n === 0 && !Base::valor('select 1 from play.aviso where id = ?', [$p->id()])) {
            throw new Erro('aviso não encontrado', 404);
        }
        return ['ok' => true];
    }

    // ------------------------------------------------------------ legendas

    /** GET legenda/{id}: o arquivo SRT (UTF-8), baixado pelo worker na primeira vez. */
    public static function legenda(Pedido $p): void
    {
        $r = Legendas::arquivo($p->id());
        if ($r === null || $r === '') {
            throw new Erro('legenda não encontrada', 404);
        }
        $txt = (strlen($r) < 1024 && !str_contains($r, "\n") && is_file($r)) ? (string) file_get_contents($r) : $r;
        $txt = self::utf8($txt);
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: private, max-age=86400');
        header('Content-Length: ' . strlen($txt));
        echo $txt;
    }

    /** SRT de sites de legenda costuma vir em Windows-1252: converte e tira o BOM. */
    public static function utf8(string $txt): string
    {
        if (str_starts_with($txt, "\xEF\xBB\xBF")) {
            $txt = substr($txt, 3);
        }
        if (!mb_check_encoding($txt, 'UTF-8')) {
            $txt = mb_convert_encoding($txt, 'UTF-8', 'Windows-1252');
        }
        return $txt;
    }

    /** GET legendas?titulo=&temporada=&episodio= (busca sob demanda no worker). */
    public static function legendas(Pedido $p): array
    {
        $id = $p->int('titulo') ?? throw new Erro('informe o título', 422);
        $lista = Legendas::buscar($id, $p->int('temporada'), $p->int('episodio'));
        return array_values(array_map(fn($l) => [
            'id'     => (int) ($l['id'] ?? 0),
            'idioma' => (string) ($l['idioma'] ?? 'pt-br'),
            'nome'   => $l['nome'] ?? null,
            'fonte'  => $l['fonte'] ?? null,
            'url'    => '/api/play/legenda/' . (int) ($l['id'] ?? 0),
        ], array_filter($lista, fn($l) => is_array($l) && !empty($l['id']))));
    }
}
