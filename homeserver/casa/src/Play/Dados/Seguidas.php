<?php
declare(strict_types=1);

namespace Casa\Play\Dados;

use Casa\Db;

/**
 * Tarefa "seguidas" (diária e depois de cada catálogo): episódios das séries seguidas (dublada e
 * legendada) relidos do painel. Episódio que não existia ⇒ aviso "episodio" para cada perfil que
 * segue, disparado na hora (a tarefa avisos manda para as TVs).
 */
final class Seguidas
{
    public static function tarefa(): array
    {
        $conta = Xtream::contaAtiva();
        if (!$conta) {
            return ['msg' => 'sem conta ativa'];
        }
        $pdo = Db::pdo();
        $series = $pdo->query("select s.titulo_id, t.nome, array_agg(s.perfil_id) as perfis
                                 from play.serie_seguida s join play.titulo t on t.id = s.titulo_id and t.tipo = 's'
                                group by s.titulo_id, t.nome")->fetchAll();
        $versoes = $pdo->prepare("select painel_id, idioma from play.versao where conta_id = ? and tipo = 's' and titulo_id = ? and ativo order by (idioma = 'leg'), painel_id");
        $eps = $pdo->prepare('select temporada, numero from play.episodio where conta_id = ? and serie_id = ?');
        $existe = $pdo->prepare("select id from play.aviso where tipo = 'episodio' and perfil_id = ?
                                    and alvo->>'titulo' = ? and alvo->>'temporada' = ? and alvo->>'episodio' = ?");
        // aviso pedido na TV (Suas séries › próximos) com a data do TMDB: o episódio chegou antes, avisa agora
        $antecipa = $pdo->prepare("update play.aviso set quando = now() where id = ? and status = 'ativo' and (quando is null or quando > now())");
        $novo = $pdo->prepare("insert into play.aviso (perfil_id, tipo, alvo, quando, texto) values (?, 'episodio', cast(? as jsonb), now(), ?)");
        $lidas = 0;
        $avisos = 0;
        foreach ($series as $s) {
            $versoes->execute([$conta['id'], $s['titulo_id']]);
            $chegaram = [];
            foreach ($versoes->fetchAll() as $v) {
                $serie = (int) $v['painel_id'];
                $eps->execute([$conta['id'], $serie]);
                $antes = [];
                foreach ($eps->fetchAll() as $e) {
                    $antes["{$e['temporada']}:{$e['numero']}"] = true;
                }
                try {
                    Xtream::serieInfo($conta['id'], $serie, true);
                    $lidas++;
                } catch (\PDOException $e) {
                    throw $e;
                } catch (\Throwable $e) {
                    Log::info("seguidas: série $serie: " . $e->getMessage());
                    continue;
                }
                if (!$antes) {
                    continue;                                  // primeira leitura: nada é "novo"
                }
                $eps->execute([$conta['id'], $serie]);
                foreach ($eps->fetchAll() as $e) {
                    $k = "{$e['temporada']}:{$e['numero']}";
                    if (!isset($antes[$k]) && (int) $e['numero'] > 0) {
                        $chegaram[$k] = ['temporada' => (int) $e['temporada'], 'episodio' => (int) $e['numero'], 'versao' => $v['idioma']];
                    }
                }
                usleep(500_000);
            }
            uasort($chegaram, fn($a, $b) => [$a['temporada'], $a['episodio']] <=> [$b['temporada'], $b['episodio']]);
            foreach (Banco::lerInts($s['perfis']) as $perfil) {
                foreach ($chegaram as $c) {
                    $existe->execute([$perfil, (string) $s['titulo_id'], (string) $c['temporada'], (string) $c['episodio']]);
                    if ($ja = $existe->fetchColumn()) {
                        $antecipa->execute([$ja]);
                        $avisos += $antecipa->rowCount();
                        continue;
                    }
                    $alvo = ['titulo' => (int) $s['titulo_id'], 'temporada' => $c['temporada'], 'episodio' => $c['episodio'], 'versao' => $c['versao']];
                    $novo->execute([$perfil, Banco::json($alvo), sprintf('Novo episódio de %s: T%d:E%d', $s['nome'], $c['temporada'], $c['episodio'])]);
                    $avisos++;
                }
            }
            if (Tarefas::ceder()) {
                break;
            }
        }
        if ($avisos) {
            Tarefas::forcar('avisos');
        }
        return ['msg' => sprintf('%d série(s) seguida(s), %d versão(ões) lidas, %d aviso(s) de episódio', count($series), $lidas, $avisos)];
    }
}
