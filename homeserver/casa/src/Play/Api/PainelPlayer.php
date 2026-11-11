<?php
declare(strict_types=1);

namespace Casa\Play\Api;

use Casa\Auth;
use Casa\Db;
use Casa\Dispositivos;
use Casa\Eventos;
use Casa\HttpErro;
use Casa\Play\Dados\Sinais;
use Casa\Play\Dados\Tarefas;
use Casa\View;

/**
 * Painel › Player: listas IPTV e TVs, perfis, estatísticas, catálogo e links, plataformas, avisos e tarefas.
 * Chamado por Casa\Painel (sessão e CSRF já validados).
 */
final class PainelPlayer
{
    /** Nomes amigáveis das tarefas do worker (as desconhecidas aparecem pelo nome). */
    public const TAREFAS = [
        'catalogo' => 'Catálogo', 'catalogo_filmes' => 'Catálogo (filmes)', 'catalogo_series' => 'Catálogo (séries)',
        'catalogo_canais' => 'Catálogo (canais)', 'top10' => 'Top 10', 'epg' => 'Programação (EPG)', 'links' => 'Teste de links',
        'tmdb' => 'Detalhes (TMDB)', 'justwatch' => 'Notas (JustWatch)', 'jw' => 'Notas (JustWatch)', 'recomendacao' => 'Recomendações',
        'recomendacoes' => 'Recomendações', 'jogos' => 'Jogos (ESPN)', 'avisos' => 'Avisos', 'conta' => 'Conta IPTV',
        'legendas' => 'Legendas', 'seguidas' => 'Séries seguidas', 'limpeza' => 'Limpeza',
    ];

    public static function atender(string $metodo, string $caminho): void
    {
        try {
            self::rotear($metodo, $caminho);
        } catch (Erro $e) {
            throw new HttpErro($e->getMessage(), $e->getCode() ?: 400);
        } catch (\PDOException $e) {
            error_log('painel player: ' . $e->getMessage());
            throw new HttpErro('Banco do Player indisponível (a migração sql/007_play.sql já rodou?).', 500);
        }
    }

    private static function rotear(string $metodo, string $caminho): void
    {
        match ($caminho) {
            '/player'              => self::ir(Auth::ehAdmin() ? '/player/listas' : '/player/estatisticas'),
            '/player/listas'       => self::listas($metodo),
            '/player/perfis'       => self::perfis($metodo),
            '/player/estatisticas' => self::estatisticas(),
            '/player/catalogo'     => self::catalogo(),
            '/player/plataformas'  => self::plataformas($metodo),
            '/player/avisos'       => self::avisos($metodo),
            '/player/tarefas'      => self::tarefas($metodo),
            default                => throw new HttpErro('Página não encontrada.', 404),
        };
    }

    private static function ir(string $url): void
    {
        header('Location: ' . $url, true, 303);
    }

    private static function quem(): string
    {
        return 'painel:' . (Auth::usuario()['login'] ?? '?');
    }

    private static function sinal(string $evento, array $dados, ?int $tv = null): void
    {
        try {
            Sinais::enviar($evento, $dados, $tv);
        } catch (\Throwable $e) {
            error_log('play: sinal não enviado: ' . $e->getMessage());
        }
    }

    // --------------------------------------------------------- listas e TVs

    private static function listas(string $metodo): void
    {
        Auth::exigirAdmin();
        if ($metodo === 'POST') {
            try {
                $msg = self::acaoListas((string) ($_POST['acao'] ?? ''));
                Auth::flash($msg);
            } catch (\InvalidArgumentException | Erro $e) {
                Auth::flash($e->getMessage(), 'erro');
            }
            self::ir('/player/listas' . (isset($_POST['ancora']) ? '#' . preg_replace('/[^a-z0-9-]/', '', (string) $_POST['ancora']) : ''));
            return;
        }
        $contas = Base::linhas("select id, nome, servidor, usuario, ativa, vence_em, status, ultima_resposta_em, latencia_ms,
                                       info->'user_info'->>'max_connections' as conexoes
                                  from play.conta order by ativa desc, id");
        $tvs = Base::linhas("select tv.id, tv.nome, tv.modelo, tv.webos, tv.versao_app, tv.status, tv.codigo, tv.dispositivo_slug, tv.ip,
                                    tv.perfil_id, tv.assistindo, extract(epoch from tv.visto_em)::bigint as visto_ts,
                                    (tv.visto_em > now() - interval '3 minutes') as recente, pf.nome as perfil_nome
                               from play.tv left join play.perfil pf on pf.id = tv.perfil_id
                              order by (tv.status = 'pendente') desc, tv.id");
        foreach ($tvs as &$t) {
            $t['assistindo'] = $t['assistindo'] !== null ? Base::json($t['assistindo']) : null;
            $t['recente'] = Base::b($t['recente']);
        }
        unset($t);
        $cat = Base::linha("select count(*) filter (where tipo = 'm' and ativo) as filmes, count(*) filter (where tipo = 's' and ativo) as series,
                                   count(*) filter (where ativo and adicionado_em > now() - interval '24 hours') as novos24
                              from play.titulo");
        $ativa = null;
        foreach ($contas as $c) {
            if (Base::b($c['ativa'])) {
                $ativa = $c;
            }
        }
        View::pagina('Listas e TVs', 'player-listas', 'player_listas', [
            'subtitulo' => 'Lista IPTV, TVs com o Player e o que cada uma está vendo',
            'contas'    => $contas,
            'ativa'     => $ativa,
            'tvs'       => $tvs,
            'perfis'    => Base::linhas('select id, nome from play.perfil order by id'),
            'aparelhos' => array_values(array_filter(Dispositivos::listar(false, false), fn($d) => $d['tipo'] === 'tv')),
            'catalogo'  => $cat + ['canais' => (int) Base::valor('select count(*) from play.canal where ativo and conta_id = ?', [Base::contaId()])],
            'sincronia' => Base::linha("select max(ultima_em) as ultima, min(proxima_em) filter (where proxima_em > now()) as proxima
                                          from play.tarefa where nome like 'catalogo%'"),
            'tarefas'   => self::listaTarefas(),
        ]);
    }

    private static function acaoListas(string $acao): string
    {
        $id = (int) ($_POST['id'] ?? 0);
        switch ($acao) {
            case 'conta_salvar':
                return self::salvarConta($id ?: null);

            case 'conta_testar':
                return self::testarConta($id);

            case 'conta_ativar':
                $nome = Base::valor('select nome from play.conta where id = ?', [$id]) ?? throw new \InvalidArgumentException('Lista não encontrada.');
                Base::transacao(function () use ($id): void {
                    Base::exec('update play.conta set ativa = false where ativa and id <> ?', [$id]);
                    Base::exec('update play.conta set ativa = true where id = ?', [$id]);
                });
                foreach (['conta', 'catalogo', 'epg'] as $t) {
                    try {
                        self::forcar($t);
                    } catch (\Throwable $e) {
                        error_log("play: tarefa $t não forçada: " . $e->getMessage());
                    }
                }
                self::sinal('atualizado', ['o_que' => 'catalogo']);
                Eventos::registrar('config', 'info', "Player: lista ativa agora é \"$nome\"", null, self::quem());
                return "Lista \"$nome\" ativada. O catálogo será sincronizado em seguida.";

            case 'conta_apagar':
                $c = Base::linha('select nome, ativa from play.conta where id = ?', [$id]) ?? throw new \InvalidArgumentException('Lista não encontrada.');
                if (Base::b($c['ativa'])) {
                    throw new \InvalidArgumentException('Ative outra lista antes de apagar esta.');
                }
                Base::exec('delete from play.conta where id = ?', [$id]);
                Eventos::registrar('config', 'aviso', "Player: lista \"{$c['nome']}\" apagada", null, self::quem());
                return "Lista \"{$c['nome']}\" apagada.";

            case 'tv_salvar':
                $nome = trim((string) ($_POST['nome'] ?? ''));
                if ($nome === '' || mb_strlen($nome) > 40) {
                    throw new \InvalidArgumentException('Nome da TV: de 1 a 40 caracteres.');
                }
                $perfil = (int) ($_POST['perfil_id'] ?? 0) ?: null;
                $slug = trim((string) ($_POST['dispositivo_slug'] ?? '')) ?: null;
                if ($slug !== null && !Dispositivos::porSlug($slug)) {
                    throw new \InvalidArgumentException('Aparelho não encontrado.');
                }
                Base::exec('update play.tv set nome = ?, perfil_id = ?, dispositivo_slug = ? where id = ?', [$nome, $perfil, $slug, $id]);
                if ($slug !== null && ($ip = Base::valor('select ip from play.tv where id = ?', [$id]))) {
                    Acesso::corrigirAparelho($slug, (string) $ip, $nome);
                }
                return "TV \"$nome\" salva.";

            case 'tv_aprovar':
            case 'tv_bloquear':
                $novo = $acao === 'tv_aprovar' ? 'ativa' : 'bloqueada';
                $nome = Base::valor('update play.tv set status = ? where id = ? returning nome', [$novo, $id]) ?? throw new \InvalidArgumentException('TV não encontrada.');
                Eventos::registrar('aparelho', $novo === 'ativa' ? 'ok' : 'aviso', "Player: $nome " . ($novo === 'ativa' ? 'aprovada' : 'bloqueada'), null, self::quem());
                return $novo === 'ativa' ? "TV \"$nome\" aprovada: o Player abre sozinho em alguns segundos." : "TV \"$nome\" bloqueada.";

            case 'tv_apagar':
                $nome = Base::valor('delete from play.tv where id = ? returning nome', [$id]) ?? throw new \InvalidArgumentException('TV não encontrada.');
                Eventos::registrar('aparelho', 'aviso', "Player: $nome removida", 'precisa registrar e aprovar de novo', self::quem());
                return "TV \"$nome\" removida.";
        }
        throw new \InvalidArgumentException('Ação inválida.');
    }

    /** Valida o endereço do painel IPTV: http(s)://host[:porta][/caminho], sem barra no fim. */
    public static function validarServidor(string $s): string
    {
        $s = rtrim(trim($s), '/');
        if (!preg_match('~^https?://[a-z0-9.-]+(:\d{1,5})?(/[^\s?#]*)?$~i', $s)) {
            throw new \InvalidArgumentException('Servidor: use http://endereco[:porta], sem barra no fim.');
        }
        return $s;
    }

    private static function salvarConta(?int $id): string
    {
        $nome = trim((string) ($_POST['nome'] ?? ''));
        $usuario = trim((string) ($_POST['usuario'] ?? ''));
        $senha = (string) ($_POST['senha'] ?? '');
        if ($nome === '' || mb_strlen($nome) > 40) {
            throw new \InvalidArgumentException('Nome da lista: de 1 a 40 caracteres.');
        }
        $servidor = self::validarServidor((string) ($_POST['servidor'] ?? ''));
        if ($usuario === '' || strlen($usuario) > 120 || strlen($senha) > 120) {
            throw new \InvalidArgumentException('Usuário obrigatório (até 120 caracteres).');
        }
        if ($id) {
            if (!Base::valor('select 1 from play.conta where id = ?', [$id])) {
                throw new \InvalidArgumentException('Lista não encontrada.');
            }
            // Senha em branco = mantém a atual (o painel nunca mostra a senha de volta).
            Base::exec('update play.conta set nome = ?, servidor = ?, usuario = ?, senha = coalesce(nullif(?, \'\'), senha) where id = ?',
                [$nome, $servidor, $usuario, $senha, $id]);
            Eventos::registrar('config', 'info', "Player: lista \"$nome\" alterada", $senha !== '' ? 'senha trocada' : null, self::quem());
            self::sinal('atualizado', ['o_que' => 'conta']);
            return "Lista \"$nome\" salva.";
        }
        if ($senha === '') {
            throw new \InvalidArgumentException('Informe a senha da lista.');
        }
        $id = (int) Base::valor('insert into play.conta (nome, servidor, usuario, senha, ativa)
                                 values (?, ?, ?, ?, not exists (select 1 from play.conta where ativa)) returning id',
            [$nome, $servidor, $usuario, $senha]);
        Eventos::registrar('config', 'info', "Player: lista \"$nome\" adicionada", null, self::quem());
        $teste = self::testarConta($id);
        return "Lista \"$nome\" adicionada. $teste";
    }

    /**
     * Testa a lista no player_api.php (resposta, vencimento, situação). A URL leva a senha: nunca vai para log.
     */
    private static function testarConta(int $id): string
    {
        $c = Base::linha('select id, nome, servidor, usuario, senha from play.conta where id = ?', [$id]) ?? throw new \InvalidArgumentException('Lista não encontrada.');
        $url = rtrim($c['servidor'], '/') . '/player_api.php?username=' . rawurlencode($c['usuario']) . '&password=' . rawurlencode($c['senha']);
        $t0 = microtime(true);
        [$st, $corpo] = Base::httpGet($url, ['Accept: application/json'], 10, 2_000_000);
        $ms = (int) round((microtime(true) - $t0) * 1000);
        $j = json_decode($corpo, true);
        $ui = is_array($j) ? ($j['user_info'] ?? null) : null;
        if (!is_array($ui)) {
            throw new \InvalidArgumentException("\"{$c['nome']}\": sem resposta válida do painel" . ($st ? " (HTTP $st)" : ' (sem conexão)') . '.');
        }
        if ((int) ($ui['auth'] ?? 0) !== 1) {
            Base::exec('update play.conta set status = ?, ultima_resposta_em = now(), latencia_ms = ? where id = ?', ['Recusada', $ms, $id]);
            throw new \InvalidArgumentException("\"{$c['nome']}\": usuário ou senha recusados pelo painel.");
        }
        $vence = is_numeric($ui['exp_date'] ?? null) ? (int) $ui['exp_date'] : null;
        $info = ['user_info' => array_intersect_key($ui, array_flip(['status', 'exp_date', 'is_trial', 'active_cons', 'created_at', 'max_connections', 'allowed_output_formats'])),
                 'server_info' => array_intersect_key((array) ($j['server_info'] ?? []), array_flip(['url', 'port', 'https_port', 'server_protocol', 'timezone', 'time_now']))];
        Base::exec('update play.conta set status = ?, vence_em = case when cast(? as bigint) is null then vence_em else to_timestamp(cast(? as bigint)) end,
                           info = cast(? as jsonb), ultima_resposta_em = now(), latencia_ms = ? where id = ?',
            [(string) ($ui['status'] ?? '?'), $vence, $vence, json_encode($info, JSON_UNESCAPED_UNICODE), $ms, $id]);
        $dias = $vence ? (int) floor(($vence - time()) / 86400) : null;
        return "\"{$c['nome']}\" respondeu em $ms ms: " . ($ui['status'] ?? '?') . ($dias !== null ? ", vence em $dias dia(s) (" . date('d/m/Y', $vence) . ')' : '') . '.';
    }

    // -------------------------------------------------------------- perfis

    private static function perfis(string $metodo): void
    {
        if ($metodo === 'POST') {
            Auth::exigirAdmin();
            try {
                $acao = (string) ($_POST['acao'] ?? '');
                $id = (int) ($_POST['id'] ?? 0);
                if ($acao === 'perfil_apagar') {
                    if ((int) Base::valor('select count(*) from play.perfil') <= 1) {
                        throw new \InvalidArgumentException('Não dá para apagar o último perfil.');
                    }
                    $nome = Base::valor('delete from play.perfil where id = ? returning nome', [$id]) ?? throw new \InvalidArgumentException('Perfil não encontrado.');
                    Eventos::registrar('config', 'aviso', "Player: perfil \"$nome\" apagado", 'progresso, lista e notas do perfil foram junto', self::quem());
                    Auth::flash("Perfil \"$nome\" apagado.");
                } else {
                    [$nome, $cor] = Tvs::validarPerfil((string) ($_POST['nome'] ?? ''), (string) ($_POST['cor'] ?? '#1E1B4B'));
                    if ($acao === 'perfil_salvar') {
                        if (!Base::exec('update play.perfil set nome = ?, cor = ? where id = ?', [$nome, $cor, $id])) {
                            throw new \InvalidArgumentException('Perfil não encontrado.');
                        }
                    } else {
                        Base::exec('insert into play.perfil (nome, cor) values (?, ?)', [$nome, $cor]);
                    }
                    Auth::flash("Perfil \"$nome\" salvo.");
                }
            } catch (\InvalidArgumentException | Erro $e) {
                Auth::flash($e->getMessage(), 'erro');
            }
            self::ir('/player/perfis');
            return;
        }
        View::pagina('Perfis', 'player-perfis', 'player_perfis', [
            'subtitulo' => 'Uma pessoa por perfil: progresso, lista, notas e recomendações separados',
            'perfis'    => Base::linhas("
                select pf.id, pf.nome, pf.cor,
                       (select count(*) from play.tv where perfil_id = pf.id) as tvs,
                       (select count(*) from play.lista where perfil_id = pf.id) as lista,
                       (select count(distinct titulo_id) from play.historico where perfil_id = pf.id) as titulos,
                       (select coalesce(sum(assistido_s), 0) from play.historico where perfil_id = pf.id and inicio > now() - interval '30 days') as s30,
                       (select max(inicio) from play.historico where perfil_id = pf.id) as ultimo
                  from play.perfil pf order by pf.id"),
        ]);
    }

    // -------------------------------------------------------- estatísticas

    /** Início e fim do mês pedido (YYYY-MM) no fuso da casa. */
    public static function mes(?string $mes): array
    {
        $tz = Base::tz();
        $ini = preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', (string) $mes)
            ? new \DateTimeImmutable($mes . '-01 00:00', $tz)
            : (new \DateTimeImmutable('first day of this month', $tz))->setTime(0, 0);
        return [$ini, $ini->modify('+1 month'), $ini->modify('-1 month')];
    }

    private static function estatisticas(): void
    {
        [$ini, $fim, $ant] = self::mes($_GET['mes'] ?? null);
        $par = [$ini->format(DATE_ATOM), $fim->format(DATE_ATOM)];
        $janela = 'h.inicio >= cast(? as timestamptz) and h.inicio < cast(? as timestamptz)';
        $total = Base::linha("select coalesce(sum(assistido_s), 0) as s, count(*) as sessoes, count(distinct titulo_id) as titulos
                                from play.historico h where $janela", $par);
        $anterior = (int) Base::valor("select coalesce(sum(assistido_s), 0) from play.historico h where $janela", [$ant->format(DATE_ATOM), $ini->format(DATE_ATOM)]);
        $porTvPerfil = Base::linhas("select coalesce(tv.nome, 'Sem TV') as tv, pf.nome as perfil, pf.cor, sum(h.assistido_s) as s
                                       from play.historico h join play.perfil pf on pf.id = h.perfil_id left join play.tv on tv.id = h.tv_id
                                      where $janela group by 1, 2, 3 order by s desc", $par);
        $generos = Base::linhas("select g, sum(h.assistido_s) as s from play.historico h join play.titulo t on t.id = h.titulo_id
                                  cross join unnest(t.generos) g where $janela group by g order by s desc limit 10", $par);
        $mais = Base::linhas("select t.id, t.nome, t.tipo, sum(h.assistido_s) as s, count(*) as sessoes, string_agg(distinct pf.nome, ', ') as perfis
                                from play.historico h join play.titulo t on t.id = h.titulo_id join play.perfil pf on pf.id = h.perfil_id
                               where $janela group by t.id, t.nome, t.tipo order by s desc limit 15", $par);
        $meses = [];
        $m = (new \DateTimeImmutable('first day of this month', Base::tz()))->setTime(0, 0);
        for ($i = 0; $i < 6; $i++) {
            $meses[$m->format('Y-m')] = self::nomeMes($m);
            $m = $m->modify('-1 month');
        }
        View::pagina('Estatísticas', 'player-estatisticas', 'player_estatisticas', [
            'subtitulo' => 'Horas assistidas por TV e perfil, gêneros e mais vistos · ' . self::nomeMes($ini),
            'mes'       => $ini->format('Y-m'),
            'meses'     => $meses,
            'nomeMes'   => self::nomeMes($ini),
            'nomeAnt'   => self::nomeMes($ant),
            'total'     => $total,
            'anterior'  => $anterior,
            'porTvPerfil' => $porTvPerfil,
            'generos'   => array_map(fn($g) => ['nome' => Base::GENEROS[(int) $g['g']] ?? ('Gênero ' . $g['g']), 's' => (int) $g['s']], $generos),
            'mais'      => $mais,
        ]);
    }

    public static function nomeMes(\DateTimeImmutable $d): string
    {
        $n = ['janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];
        return $n[(int) $d->format('n') - 1] . '/' . $d->format('Y');
    }

    /** 5460 -> "1 h 31 min" */
    public static function horas(int $s): string
    {
        $h = intdiv($s, 3600);
        $m = intdiv($s % 3600, 60);
        return $h > 0 ? "$h h " . str_pad((string) $m, 2, '0', STR_PAD_LEFT) . ' min' : "$m min";
    }

    /** Duração de tarefa: "52 s", "3 min 05 s", "2 h 05". */
    public static function duracaoMs(?int $ms): string
    {
        if ($ms === null) {
            return '—';
        }
        $s = (int) round($ms / 1000);
        return match (true) {
            $s < 1    => '< 1 s',
            $s < 60   => "$s s",
            $s < 3600 => intdiv($s, 60) . ' min ' . str_pad((string) ($s % 60), 2, '0', STR_PAD_LEFT) . ' s',
            default   => intdiv($s, 3600) . ' h ' . str_pad((string) intdiv($s % 3600, 60), 2, '0', STR_PAD_LEFT),
        };
    }

    // --------------------------------------------------- catálogo e links

    private static function catalogo(): void
    {
        $conta = Base::contaId();
        View::pagina('Catálogo e links', 'player-catalogo', 'player_catalogo', [
            'subtitulo' => 'O que a lista tem, o que chegou e como estão os links',
            'totais'    => Base::linha("
                select count(*) filter (where tipo = 'm' and ativo) as filmes, count(*) filter (where tipo = 's' and ativo) as series,
                       count(*) filter (where not ativo) as inativos, count(*) filter (where ativo and tmdb_id is null) as sem_tmdb,
                       count(*) filter (where ativo and link_ok = false) as quebrados, count(*) filter (where ativo and link_testado_em is not null) as testados,
                       count(*) filter (where ativo and adicionado_em > now() - interval '7 days') as novos7
                  from play.titulo"),
            'versoes'   => (int) Base::valor('select count(*) from play.versao where conta_id = ? and ativo', [$conta]),
            'episodios' => (int) Base::valor('select count(*) from play.episodio where conta_id = ?', [$conta]),
            'canais'    => (int) Base::valor('select count(*) from play.canal where conta_id = ? and ativo', [$conta]),
            'qualidade' => Base::linhas("select tipo, coalesce(qualidade, 'não testado') as qualidade, count(*) as n
                                           from play.titulo where ativo group by 1, 2 order by 1, 3 desc"),
            'novos'     => Base::linhas("select id, tipo, nome, ano, qualidade, adicionado_em from play.titulo
                                          where ativo and adicionado_em > now() - interval '7 days' order by adicionado_em desc limit 40"),
            'quebradosTitulos' => Base::linhas("select id, tipo, nome, ano, link_testado_em from play.titulo
                                          where ativo and link_ok = false order by link_testado_em desc nulls last limit 40"),
            'testes'    => Base::linhas("select id, tipo, nome, qualidade, link_ok, link_testado_em from play.titulo
                                          where link_testado_em is not null order by link_testado_em desc limit 25"),
        ]);
    }

    // ---------------------------------------------------------- plataformas

    private static function plataformas(string $metodo): void
    {
        Auth::exigirAdmin();
        if ($metodo === 'POST') {
            $ativas = array_map('strval', (array) ($_POST['ativa'] ?? []));
            $ordens = (array) ($_POST['ordem'] ?? []);
            Base::transacao(function () use ($ativas, $ordens): void {
                foreach (Base::coluna('select codigo from play.plataforma') as $cod) {
                    $ordem = max(1, min(99, (int) ($ordens[$cod] ?? 99)));
                    Base::exec('update play.plataforma set ativa = ?, ordem = ? where codigo = ?', [in_array($cod, $ativas, true), $ordem, $cod]);
                }
            });
            self::sinal('atualizado', ['o_que' => 'top10']);
            Eventos::registrar('config', 'info', 'Player: plataformas do Top 10 alteradas', 'ativas: ' . (implode(', ', $ativas) ?: 'nenhuma'), self::quem());
            Auth::flash('Plataformas salvas. As TVs atualizam o Top 10 na hora.');
            self::ir('/player/plataformas');
            return;
        }
        View::pagina('Plataformas', 'player-plataformas', 'player_plataformas', [
            'subtitulo' => 'Quais plataformas entram no Top 10 e em que ordem',
            'lista'     => Base::linhas("select pl.codigo, pl.nome, pl.ordem, pl.ativa,
                                                (select max(coletado_em) from play.top10 where plataforma = pl.codigo) as coleta,
                                                (select count(*) from play.top10 x where x.plataforma = pl.codigo and x.titulo_id is not null
                                                    and x.coletado_em = (select max(coletado_em) from play.top10 where plataforma = pl.codigo)) as na_lista
                                           from play.plataforma pl order by pl.ordem, pl.codigo"),
        ]);
    }

    // --------------------------------------------------------------- avisos

    private static function avisos(string $metodo): void
    {
        if ($metodo === 'POST') {
            Auth::exigirAdmin();
            $n = Base::exec("update play.aviso set status = 'cancelado' where id = ? and status = 'ativo'", [(int) ($_POST['id'] ?? 0)]);
            Auth::flash($n ? 'Aviso cancelado.' : 'Aviso não estava mais ativo.', $n ? 'ok' : 'aviso');
            self::ir('/player/avisos');
            return;
        }
        $sql = "select a.id, a.tipo, a.alvo, a.quando, a.status, a.texto, a.criado_em, a.disparado_em, pf.nome as perfil
                  from play.aviso a left join play.perfil pf on pf.id = a.perfil_id";
        View::pagina('Avisos', 'player-avisos', 'player_avisos', [
            'subtitulo'  => 'Lembretes de jogos, programas, episódios e títulos pedidos',
            'ativos'     => Base::linhas("$sql where a.status = 'ativo' order by a.quando nulls last, a.id desc limit 200"),
            'disparados' => Base::linhas("$sql where a.status = 'disparado' and a.disparado_em > now() - interval '30 days' order by a.disparado_em desc limit 100"),
        ]);
    }

    // -------------------------------------------------------------- tarefas

    public static function listaTarefas(): array
    {
        return Base::linhas('select nome, ultima_em, duracao_ms, status, mensagem, proxima_em, forcar,
                                    extract(epoch from ultima_em)::bigint as ultima_ts from play.tarefa order by nome');
    }

    private static function forcar(string $nome): void
    {
        if (class_exists(Tarefas::class)) {
            Tarefas::forcar($nome);
            return;
        }
        // Reserva enquanto o worker não publica Tarefas::forcar: mesmo efeito.
        Base::exec('update play.tarefa set forcar = true where nome = ?', [$nome]);
        Db::pdo()->prepare('select pg_notify(?, ?)')->execute(['play_tarefa', $nome]);
    }

    private static function tarefas(string $metodo): void
    {
        Auth::exigirAdmin();
        if ($metodo === 'POST') {
            $nome = (string) ($_POST['nome'] ?? '');
            if (!Base::valor('select 1 from play.tarefa where nome = ?', [$nome])) {
                Auth::flash('Tarefa não encontrada.', 'erro');
            } else {
                self::forcar($nome);
                Eventos::registrar('config', 'info', 'Player: tarefa "' . (self::TAREFAS[$nome] ?? $nome) . '" pedida agora', null, self::quem());
                Auth::flash('Pedido enviado: "' . (self::TAREFAS[$nome] ?? $nome) . '" roda em instantes.');
            }
            self::ir('/player/tarefas');
            return;
        }
        View::pagina('Tarefas', 'player-tarefas', 'player_tarefas', [
            'subtitulo' => 'O que o casa-play faz sozinho e quando roda de novo',
            'tarefas'   => self::listaTarefas(),
        ]);
    }
}
