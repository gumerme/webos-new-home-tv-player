<?php
declare(strict_types=1);

namespace Casa\Play\Dados;

use Casa\Db;

/**
 * Tarefa "limpeza" (diária, 04:30): sinais com mais de 2 dias, imagens em cache sem uso há 60 dias,
 * legendas sem uso há 90 dias, Top 10 com mais de 90 dias, jogos e avisos velhos, cache TMDB/JustWatch
 * vencido.
 */
final class Limpeza
{
    public static function tarefa(): array
    {
        $pdo = Db::pdo();
        $n = [];
        $n['sinais'] = $pdo->exec("delete from play.sinal where criado_em < now() - interval '2 days'");
        $n['top10'] = $pdo->exec("delete from play.top10 where coletado_em < now() - interval '90 days'");
        $n['jogos'] = $pdo->exec("delete from play.jogo where data < current_date - 7");
        $n['avisos'] = $pdo->exec("delete from play.aviso where status in ('disparado', 'cancelado') and coalesce(disparado_em, criado_em) < now() - interval '60 days'");
        $n['cache'] = $pdo->exec("delete from play.cache where em < now() - interval '30 days' and chave not like 'cf:%'");
        $n['tarefas antigas'] = $pdo->prepare('delete from play.tarefa where not (nome = any(cast(? as text[])))');
        $n['tarefas antigas']->execute(['{' . implode(',', array_keys(Tarefas::LISTA)) . '}']);
        $n['tarefas antigas'] = $n['tarefas antigas']->rowCount();
        $n['episódios órfãos'] = $pdo->exec("delete from play.serie_info s where s.atualizado_em < now() - interval '30 days'
                                               and not exists (select 1 from play.versao v where v.conta_id = s.conta_id and v.tipo = 's' and v.painel_id = s.serie_id and v.ativo)");
        $pdo->exec("delete from play.episodio e where not exists (select 1 from play.serie_info s where s.conta_id = e.conta_id and s.serie_id = e.serie_id)");
        $n['imagens'] = self::arquivosVelhos(Banco::pasta('img'), 60);
        $n['tmp'] = self::arquivosVelhos(Banco::pasta('tmp'), 2);
        // legendas: arquivo sem uso há 90 dias sai (a linha fica; baixa de novo se pedirem)
        $velhas = 0;
        foreach ($pdo->query("select id from play.legenda where arquivo is not null")->fetchAll(\PDO::FETCH_COLUMN) as $id) {
            $f = Legendas::caminho((int) $id);
            if (!is_file($f) || max((int) @fileatime($f), (int) @filemtime($f)) < time() - 90 * 86400) {
                @unlink($f);
                $pdo->prepare('update play.legenda set arquivo = null where id = ?')->execute([$id]);
                $velhas++;
            }
        }
        $n['legendas'] = $velhas;
        return ['msg' => implode(', ', array_map(fn($k, $v) => "$k $v", array_keys($n), $n))];
    }

    /** Apaga arquivos sem uso (acesso ou modificação) há mais de `dias`; devolve quantos. */
    private static function arquivosVelhos(string $pasta, int $dias): int
    {
        if (!is_dir($pasta)) {
            return 0;
        }
        $limite = time() - $dias * 86400;
        $n = 0;
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($pasta, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            if ($f->isFile() && max($f->getATime(), $f->getMTime()) < $limite) {
                @unlink($f->getPathname()) && $n++;
            } elseif ($f->isDir()) {
                @rmdir($f->getPathname());               // só sai se estiver vazia
            }
        }
        return $n;
    }
}
