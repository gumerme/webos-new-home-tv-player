<?php
declare(strict_types=1);

/**
 * Worker do Player 2.0 (serviço casa-play): catálogo IPTV, TMDB, Top 10, jogos, EPG, links,
 * legendas, séries seguidas, recomendações e avisos. Uma tarefa por vez, a vencida mais antiga
 * (as forçadas pelo painel primeiro); acorda na hora com NOTIFY play_tarefa.
 * As migrações são aplicadas pelo casa-worker: aqui só se espera a 008 existir.
 */

use Casa\Db;
use Casa\Play\Dados\Banco;
use Casa\Play\Dados\Log;
use Casa\Play\Dados\Tarefas;

require dirname(__DIR__) . '/src/autoload.php';

// a lista de filmes do painel (44 mil itens) é decodificada inteira: o padrão de 128 MB não basta
ini_set('memory_limit', getenv('PLAY_MEMORIA') ?: '1024M');
ini_set('default_socket_timeout', '30');

$rodando = true;
if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    foreach ([SIGTERM, SIGINT] as $sinal) {
        pcntl_signal($sinal, function () use (&$rodando) {
            $rodando = false;
        });
    }
}
Tarefas::$parar = function () use (&$rodando): bool {
    return !$rodando;
};

function preparar(): PDO
{
    $avisou = false;
    while (true) {
        try {
            $pdo = Db::reconectar();
            $st = $pdo->query("select to_regclass('play.cache') is not null and to_regclass('play.tarefa') is not null");
            if (!Banco::bool($st->fetchColumn())) {
                if (!$avisou) {
                    Log::info('esperando o casa-worker aplicar as migrações do Player (007/008)');
                    $avisou = true;
                }
                sleep(10);
                continue;
            }
            Tarefas::garantir();
            $pdo->exec('listen ' . Tarefas::CANAL);
            return $pdo;
        } catch (Throwable $e) {
            Log::info('banco indisponível: ' . $e->getMessage() . ' — nova tentativa em 5 s');
            sleep(5);
        }
    }
}

Log::info('casa-play iniciado; tarefas: ' . implode(', ', array_keys(Tarefas::LISTA)));
$pdo = preparar();

while ($rodando) {
    try {
        // acorda na hora quando o painel força uma tarefa (ou a cada 1 s para ver a agenda)
        $pdo->pgsqlGetNotify(PDO::FETCH_ASSOC, 1000);
        while ($pdo->pgsqlGetNotify(PDO::FETCH_ASSOC, 0)) {
            // esvazia a fila de notificações acumuladas
        }
        if (!$rodando) {
            break;
        }
        $nome = Tarefas::proximaVencida();
        if ($nome !== null) {
            Tarefas::executar($nome);
        }
        gc_collect_cycles();
    } catch (PDOException $e) {
        Log::info('erro de banco: ' . $e->getMessage());
        sleep(2);
        $pdo = preparar();
    } catch (Throwable $e) {
        Log::info('erro: ' . $e->getMessage());
        sleep(5);
    }
}
Log::info('casa-play encerrado');
