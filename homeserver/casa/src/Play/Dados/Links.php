<?php
declare(strict_types=1);

namespace Casa\Play\Dados;

use Casa\Db;

/**
 * Tarefa "links" (02:00–06:00, devagar: ~1 pedido ao painel a cada 2 s): testa se o vídeo abre
 * (GET com Range: bytes=0-1, só os cabeçalhos) e mede a qualidade pelo get_vod_info/get_series_info
 * (altura do vídeo: ≥2000 4k, ≥1000 fhd, ≥700 hd, senão sd) ou pelo nome. Prioridade: Top 10,
 * continuar, recomendados, novos; depois o que nunca foi testado ou foi há mais de 30 dias.
 */
final class Links
{
    private const INICIO = '02:00';
    private const FIM_HORA = 6;
    private const PAUSA_US = 2_000_000;
    private const LOTE_S = 300;

    public static function tarefa(): array
    {
        $agora = time();
        if (!self::naJanela($agora) && !Tarefas::forcada()) {      // forçada fora da janela: um lote só
            return ['msg' => 'fora da janela (02:00–06:00)', 'proxima' => Tarefas::proximoHorario(self::INICIO, $agora)];
        }
        $conta = Xtream::contaAtiva();
        if (!$conta) {
            return ['msg' => 'sem conta ativa', 'proxima' => Tarefas::proximoHorario(self::INICIO, $agora)];
        }
        $ini = time();
        $ok = 0;
        $quebrados = 0;
        $fila = self::fila(60);
        foreach ($fila as $t) {
            if (time() - $ini > self::LOTE_S || Tarefas::ceder()) {
                break;
            }
            try {
                $r = self::testar($conta, $t);
            } catch (\PDOException $e) {
                throw $e;
            } catch (\Throwable $e) {
                Log::info("links: título {$t['id']}: " . $e->getMessage());
                $r = ['ok' => null, 'qualidade' => null];
            }
            Db::pdo()->prepare('update play.titulo set link_ok = coalesce(?, link_ok), qualidade = coalesce(?, qualidade), link_testado_em = now() where id = ?')
                ->execute([$r['ok'] === null ? null : ($r['ok'] ? 'true' : 'false'), $r['qualidade'], $t['id']]);
            $r['ok'] === false ? $quebrados++ : $ok++;
        }
        $msg = sprintf('%d testado(s), %d quebrado(s)', $ok + $quebrados, $quebrados);
        $continua = $fila && self::naJanela(time());
        return ['msg' => $msg, 'proxima' => $continua ? time() + 2 : Tarefas::proximoHorario(self::INICIO)];
    }

    public static function naJanela(int $t): bool
    {
        $h = (int) date('G', $t);
        return $h >= (int) self::INICIO && $h < self::FIM_HORA;
    }

    private static function fila(int $n): array
    {
        $st = Db::pdo()->prepare("
            with top as (select distinct titulo_id from play.top10 where coletado_em > now() - interval '2 days' and titulo_id is not null),
                 uso as (select titulo_id from play.progresso where not terminado and not oculto),
                 rec as (select distinct (jsonb_array_elements_text(dados->'recomendados'))::bigint as titulo_id from play.recomendacao)
            select t.id, t.tipo, t.nome, t.qualidade
              from play.titulo t
             where t.ativo and (t.link_testado_em is null
                    or t.link_testado_em < now() - interval '30 days'
                    or (t.link_testado_em < now() - interval '3 days'
                        and (t.id in (select titulo_id from top) or t.id in (select titulo_id from uso) or t.id in (select titulo_id from rec))))
             order by (t.id in (select titulo_id from top)) desc, (t.id in (select titulo_id from uso)) desc,
                      (t.id in (select titulo_id from rec)) desc, (t.link_testado_em is null) desc, t.adicionado_em desc nulls last
             limit ?");
        $st->execute([$n]);
        return $st->fetchAll();
    }

    /** @return array{ok:?bool, qualidade:?string} */
    private static function testar(array $conta, array $t): array
    {
        $pdo = Db::pdo();
        $st = $pdo->prepare("select painel_id, ext, uhd, nome_painel from play.versao
                              where titulo_id = ? and conta_id = ? and ativo
                              order by uhd, (idioma = 'leg'), painel_id limit 1");
        $st->execute([$t['id'], $conta['id']]);
        $v = $st->fetch();
        if (!$v) {
            return ['ok' => null, 'qualidade' => null];
        }
        $st = $pdo->prepare('select bool_or(uhd) from play.versao where titulo_id = ? and conta_id = ? and ativo');
        $st->execute([$t['id'], $conta['id']]);
        $tem4k = Banco::bool($st->fetchColumn());
        $altura = 0;
        $kbps = 0;
        $segundos = 0;
        if ($t['tipo'] === 'm') {
            $info = Xtream::api($conta, 'get_vod_info', ['vod_id' => (int) $v['painel_id']], 30);
            $altura = (int) ($info['info']['video']['height'] ?? 0);
            $kbps = (int) ($info['info']['bitrate'] ?? 0);
            $segundos = (int) ($info['info']['duration_secs'] ?? 0);
            $tmdb = (int) ($info['info']['tmdb_id'] ?? 0);
            if ($tmdb > 0) {
                // a lista não traz o tmdb; o get_vod_info traz: título sem tmdb ganha e busca detalhes de novo
                $pdo->prepare('update play.titulo set tmdb_id = ?, detalhes_em = null where id = ? and tmdb_id is null')->execute([$tmdb, $t['id']]);
            }
            $ext = (string) ($info['movie_data']['container_extension'] ?? $v['ext'] ?? 'mp4');
            $url = Xtream::linkVideo($conta, 'm', (int) $v['painel_id'], $ext ?: 'mp4');
        } else {
            Xtream::serieInfo($conta['id'], (int) $v['painel_id']);
            $st = $pdo->prepare('select ep_id, ext, duracao_s from play.episodio where conta_id = ? and serie_id = ? order by temporada, numero limit 1');
            $st->execute([$conta['id'], (int) $v['painel_id']]);
            $ep = $st->fetch();
            if (!$ep) {
                return ['ok' => false, 'qualidade' => null];   // série sem episódio no painel
            }
            $url = Xtream::linkVideo($conta, 's', (int) $ep['ep_id'], (string) ($ep['ext'] ?: 'mp4'));
            $segundos = (int) ($ep['duracao_s'] ?? 0);
        }
        usleep(self::PAUSA_US);
        $r = Http::cabecalhos($url, ['Range' => 'bytes=0-1'], 20);
        $ok = in_array($r['status'], [200, 206], true) && !str_starts_with((string) ($r['cab']['content-type'] ?? ''), 'text/');
        // sem bitrate do painel: tamanho do arquivo (Content-Range) ÷ duração
        if (!$kbps && $segundos > 0 && preg_match('#/(\d+)$#', (string) ($r['cab']['content-range'] ?? ''), $m)) {
            $kbps = (int) round((int) $m[1] * 8 / $segundos / 1000);
        }
        usleep(self::PAUSA_US);
        return ['ok' => $ok, 'qualidade' => $tem4k ? '4k' : self::qualidade($altura, (string) $v['nome_painel'], $kbps)];
    }

    /**
     * Altura do vídeo → 4k/fhd/hd/sd; sem altura, pelo nome (4K, FHD, HD); por fim pela taxa de bits
     * (o painel informa `bitrate` em kbit/s no get_vod_info; H.264: ≥12 Mbit/s 4k, ≥2,5 fhd, ≥1 hd).
     */
    public static function qualidade(int $altura, string $nome, int $kbps = 0): ?string
    {
        if ($altura >= 2000) {
            return '4k';
        }
        if ($altura >= 1000) {
            return 'fhd';
        }
        if ($altura >= 700) {
            return 'hd';
        }
        if ($altura > 0) {
            return 'sd';
        }
        if (Texto::ehUhd($nome)) {
            return '4k';
        }
        if (preg_match('/\b(fhd|1080p?)\b/i', $nome)) {
            return 'fhd';
        }
        if (preg_match('/\b(hd|720p?)\b/i', $nome)) {
            return 'hd';
        }
        if ($kbps > 0) {
            return $kbps >= 12000 ? '4k' : ($kbps >= 2500 ? 'fhd' : ($kbps >= 1000 ? 'hd' : 'sd'));
        }
        return null;
    }
}
