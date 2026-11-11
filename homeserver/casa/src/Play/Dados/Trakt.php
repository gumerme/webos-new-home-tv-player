<?php
declare(strict_types=1);

namespace Casa\Play\Dados;

use Casa\Db;

/**
 * Trakt (09/11/2026): "relacionados" de cada série (e dos filmes que o MovieLens não cobre, como os lançamentos
 * depois de 2023), calculados pela comunidade do Trakt. Complementa o MovieLens, que só tem filmes.
 *
 *  - Precisa de TRAKT_CLIENT_ID no .env (app gratuito criado em trakt.tv/oauth/applications; só a chave pública,
 *    sem login de usuário). Sem a chave, a tarefa não faz nada.
 *  - Grava play.cache 'cf:trakt:<s|m>:<tmdb>' = {v:[[tmdb, tipo]...]} (vazio quando o Trakt não conhece).
 *    Refaz depois de 60 dias. A limpeza não apaga 'cf:*'.
 *  - Ritmo: ~2 pedidos por título com pausa (limite do Trakt: 1.000 GET a cada 5 min).
 */
final class Trakt
{
    private const API = 'https://api.trakt.tv';
    private const PAUSA_US = 450000;
    private const VALIDADE_DIAS = 60;

    public static function chave(): ?string
    {
        $k = trim((string) getenv('TRAKT_CLIENT_ID'));
        return $k !== '' ? $k : null;
    }

    private static function pedir(string $caminho): mixed
    {
        $k = self::chave();
        Http::segredo($k);
        return Http::json(self::API . $caminho, ['trakt-api-version' => '2', 'trakt-api-key' => (string) $k,
                                                 'Content-Type' => 'application/json'], 20);
    }

    /** Resposta do /related -> [[tmdb, 's'|'m'], ...] (puro). */
    public static function relacionados(array $lista, string $tipo): array
    {
        $saida = [];
        foreach ($lista as $x) {
            $tmdb = (int) ($x['ids']['tmdb'] ?? 0);
            if ($tmdb > 0) {
                $saida[] = [$tmdb, $tipo];
            }
        }
        return $saida;
    }

    /** Um título: tmdb -> id do Trakt -> relacionados. null = parar o lote (limite/erro do Trakt). */
    public static function buscar(string $tipo, int $tmdb): ?array
    {
        $t = $tipo === 's' ? 'show' : 'movie';
        try {
            $achados = self::pedir("/search/tmdb/$tmdb?type=$t");
            $id = null;
            foreach ((array) $achados as $a) {
                if (($a['type'] ?? '') === $t && (int) ($a[$t]['ids']['tmdb'] ?? 0) === $tmdb) {
                    $id = (int) $a[$t]['ids']['trakt'];
                    break;
                }
            }
            if (!$id) {
                return ['v' => []];
            }
            usleep(self::PAUSA_US);
            $rel = self::pedir('/' . $t . "s/$id/related?limit=30");
            return ['v' => self::relacionados((array) $rel, $tipo), 'trakt' => $id];
        } catch (HttpErroStatus $e) {
            if ($e->status === 404) {
                return ['v' => []];
            }
            Log::info('trakt: ' . $e->getMessage());
            return null;
        } catch (\RuntimeException $e) {
            Log::info('trakt: ' . $e->getMessage());
            return null;
        }
    }

    public static function tarefa(): array
    {
        if (!self::chave()) {
            return ['msg' => 'sem TRAKT_CLIENT_ID no .env (nada a fazer)'];
        }
        $pdo = Db::pdo();
        // fila: séries que alimentam o gosto (histórico, lista, Netflix), depois as séries do catálogo pelas mais
        // votadas, depois os filmes que o MovieLens não cobre
        $netflix = [];
        foreach ($pdo->query("select valor from play.preferencia where chave = 'netflix'")->fetchAll(\PDO::FETCH_COLUMN) as $v) {
            foreach (json_decode((string) $v, true)['obras'] ?? [] as $o) {
                if (!empty($o['tmdb'])) {
                    $netflix[] = [$o['t'] === 's' ? 's' : 'm', (int) $o['tmdb']];
                }
            }
        }
        $sel = $pdo->prepare("
            with fila as (
                select x.tipo, x.tmdb, x.prio from unnest(cast(? as text[]), cast(? as int[])) with ordinality as x(tipo, tmdb, prio)
                union all
                select t.tipo, t.tmdb_id, 100000 + row_number() over (
                         order by (exists (select 1 from play.historico h where h.titulo_id = t.id)
                                   or exists (select 1 from play.lista l where l.titulo_id = t.id)) desc,
                                  t.tipo desc, coalesce((t.jw->>'imdb_votos')::bigint, 0) desc, t.id)
                  from play.titulo t
                 where t.ativo and t.tmdb_id is not null
                   and (t.tipo = 's' or not exists (select 1 from play.cache c where c.chave = 'cf:ml:' || t.tmdb_id))
            )
            select distinct on (f.tipo, f.tmdb) f.tipo, f.tmdb, f.prio
              from fila f
             where not exists (select 1 from play.cache c where c.chave = 'cf:trakt:' || f.tipo || ':' || f.tmdb
                                 and c.em > now() - make_interval(days => ?))
             order by f.tipo, f.tmdb, f.prio");
        $tipos = '{' . implode(',', array_column($netflix, 0)) . '}';
        $ids = '{' . implode(',', array_column($netflix, 1)) . '}';
        $sel->execute([$tipos, $ids, self::VALIDADE_DIAS]);
        $fila = $sel->fetchAll();
        usort($fila, fn($a, $b) => (int) $a['prio'] <=> (int) $b['prio']);
        $feitos = 0;
        $com = 0;
        foreach (array_slice($fila, 0, 400) as $f) {
            $r = self::buscar($f['tipo'], (int) $f['tmdb']);
            if ($r === null) {
                break;
            }
            Banco::guardar("cf:trakt:{$f['tipo']}:{$f['tmdb']}", $r);
            $feitos++;
            $com += $r['v'] ? 1 : 0;
            usleep(self::PAUSA_US);
            if ($feitos % 20 === 0 && Tarefas::ceder()) {
                break;
            }
        }
        if ($feitos) {
            Banco::guardar('cf:trakt', ['em' => date('c')]);
        }
        return ['msg' => "$feitos títulos ($com com relacionados); faltam " . max(0, count($fila) - $feitos)];
    }
}
