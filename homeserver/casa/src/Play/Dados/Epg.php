<?php
declare(strict_types=1);

namespace Casa\Play\Dados;

use Casa\Db;

/**
 * Programação dos canais (get_simple_data_table do painel, títulos e descrições em base64) em
 * play.programa. Sob demanda por canal (cache de 30 min) e na tarefa "epg" (4 h): 24 h dos canais
 * favoritos de todos os perfis e dos canais de jogos. O que acabou há mais de 6 h sai.
 */
final class Epg
{
    private const CACHE_S = 1800;
    private const JANELA_S = 24 * 3600;

    public static function canal(int $contaId, int $canalId, bool $forcar = false): void
    {
        $pdo = Db::pdo();
        if (!$forcar) {
            $st = $pdo->prepare('select epg_em > now() - make_interval(secs => ?) from play.canal where conta_id = ? and painel_id = ?');
            $st->execute([self::CACHE_S, $contaId, $canalId]);
            if (Banco::bool($st->fetchColumn())) {
                return;
            }
        }
        $conta = Xtream::conta($contaId) ?? throw new \RuntimeException("conta $contaId não existe");
        $agora = time();
        $r = Xtream::api($conta, 'get_simple_data_table', ['stream_id' => $canalId], 30);
        $progs = self::lerTabela(is_array($r) ? $r : [], $agora - 6 * 3600, $agora + self::JANELA_S);
        $pdo->beginTransaction();
        try {
            if ($progs) {
                $pdo->prepare("
                    insert into play.programa (conta_id, canal_id, inicio, fim, titulo, descricao)
                    select distinct on (x.i) ?, ?, to_timestamp(x.i), to_timestamp(x.f), x.t, x.d from jsonb_to_recordset(cast(? as jsonb)) as x(i bigint, f bigint, t text, d text)
                    on conflict (conta_id, canal_id, inicio) do update set fim = excluded.fim, titulo = excluded.titulo, descricao = excluded.descricao")
                    ->execute([$contaId, $canalId, Banco::json($progs)]);
                // grade mudou: o que estava na janela e não veio mais sai (sem aviso marcado)
                $pdo->prepare("delete from play.programa p
                                where p.conta_id = ? and p.canal_id = ? and p.inicio >= to_timestamp(?) and p.inicio <= to_timestamp(?)
                                  and not (extract(epoch from p.inicio)::bigint = any(cast(? as bigint[])))
                                  and not exists (select 1 from play.aviso a where a.tipo = 'programa' and a.status = 'ativo'
                                                     and a.alvo->>'programa' = p.id::text)")
                    ->execute([$contaId, $canalId, min(array_column($progs, 'i')), max(array_column($progs, 'i')), Banco::ints(array_column($progs, 'i'))]);
            }
            $pdo->prepare('update play.canal set epg_em = now() where conta_id = ? and painel_id = ?')->execute([$contaId, $canalId]);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Resposta do get_simple_data_table → programas na janela (puro, testado).
     * @return list<array{i:int, f:int, t:string, d:?string}>
     */
    public static function lerTabela(array $r, int $de, int $ate): array
    {
        $saida = [];
        foreach ($r['epg_listings'] ?? [] as $x) {
            if (!is_array($x)) {
                continue;
            }
            $ini = (int) ($x['start_timestamp'] ?? 0) ?: (strtotime((string) ($x['start'] ?? '')) ?: 0);
            $fim = (int) ($x['stop_timestamp'] ?? 0) ?: (strtotime((string) ($x['end'] ?? $x['stop'] ?? '')) ?: 0);
            if (!$ini || $fim <= $ini || $fim < $de || $ini > $ate) {
                continue;
            }
            $titulo = self::b64((string) ($x['title'] ?? ''));
            if ($titulo === '') {
                continue;
            }
            $desc = self::b64((string) ($x['description'] ?? ''));
            $saida[$ini] = ['i' => $ini, 'f' => $fim, 't' => mb_substr($titulo, 0, 300), 'd' => $desc !== '' ? mb_substr($desc, 0, 2000) : null];
        }
        ksort($saida);
        return array_values($saida);
    }

    /** Base64 do painel → texto UTF-8 (alguns vêm em Latin-1). */
    public static function b64(string $s): string
    {
        $d = base64_decode($s, true);
        if ($d === false) {
            $d = $s;                                           // já veio em texto
        }
        if (!mb_check_encoding($d, 'UTF-8')) {
            $d = mb_convert_encoding($d, 'UTF-8', 'Windows-1252');
        }
        return trim($d);
    }

    public static function tarefa(): array
    {
        $conta = Xtream::contaAtiva();
        if (!$conta) {
            return ['msg' => 'sem conta ativa'];
        }
        $pdo = Db::pdo();
        $st = $pdo->prepare("
            select distinct canal_id from (
                select f.canal_id from play.canal_fav f where f.conta_id = ?
                union select j.canal_id from play.jogo j where j.data >= current_date - 1 and j.canal_id is not null
                union select p.canal_id from play.aviso a join play.programa p on p.id::text = a.alvo->>'programa'
                       where a.tipo = 'programa' and a.status = 'ativo'
                union select case when a.alvo->>'canal' ~ '^[0-9]+$' then (a.alvo->>'canal')::int end from play.aviso a
                       where a.tipo in ('programa', 'jogo') and a.status = 'ativo'
            ) x
              join play.canal c on c.conta_id = ? and c.painel_id = x.canal_id and c.ativo");
        $st->execute([$conta['id'], $conta['id']]);
        $canais = array_map('intval', $st->fetchAll(\PDO::FETCH_COLUMN));
        $ok = 0;
        $erros = 0;
        foreach ($canais as $i => $id) {
            try {
                self::canal($conta['id'], $id);
                $ok++;
            } catch (\PDOException $e) {
                throw $e;
            } catch (\Throwable $e) {
                $erros++;
            }
            if ($i % 5 === 4 && Tarefas::ceder()) {
                break;
            }
            usleep(300_000);                                   // devagar com o painel
        }
        $limpos = $pdo->exec("delete from play.programa p where p.fim < now() - interval '6 hours'
                                and not exists (select 1 from play.aviso a where a.tipo = 'programa' and a.status = 'ativo' and a.alvo->>'programa' = p.id::text)");
        if ($ok) {
            Sinais::atualizado('epg');
        }
        return ['msg' => sprintf('%d canal(is) com guia, %d erro(s), %d programa(s) antigos apagados', $ok, $erros, (int) $limpos)];
    }
}
