<?php
declare(strict_types=1);

/**
 * Worker da casa: único processo que fala com os aparelhos.
 * - Executa a fila de comandos (acordado por NOTIFY casa_comando).
 * - Consulta o estado de cada aparelho no intervalo do driver.
 */

use Casa\Comandos;
use Casa\Db;
use Casa\Dispositivos;
use Casa\Drivers\Registro;
use Casa\Eventos;
use Casa\Metricas;
use Casa\Rede;

require dirname(__DIR__) . '/src/autoload.php';

function logar(string $msg): void
{
    fwrite(STDOUT, date('Y-m-d H:i:s') . " $msg\n");
}

$rodando = true;
pcntl_async_signals(true);
foreach ([SIGTERM, SIGINT] as $sinal) {
    pcntl_signal($sinal, function () use (&$rodando) {
        $rodando = false;
    });
}

function preparar(): PDO
{
    while (true) {
        try {
            $pdo = Db::reconectar();
            foreach (Db::migrar() as $m) {
                logar("migração aplicada: $m");
                Eventos::registrar('sistema', 'info', "Banco atualizado: $m", null, 'worker');
            }
            $n = Comandos::recuperarOrfaos();
            if ($n) {
                logar("$n comando(s) órfão(s) marcados como erro");
            }
            $pdo->exec('listen ' . Comandos::CANAL);
            return $pdo;
        } catch (Throwable $e) {
            logar('banco indisponível: ' . $e->getMessage() . ' — nova tentativa em 5 s');
            sleep(5);
        }
    }
}

function executarComando(array $c): void
{
    $d = Dispositivos::porId($c['dispositivo_id']);
    $ini = microtime(true);
    try {
        if (!$d) {
            throw new RuntimeException('dispositivo não existe');
        }
        $res = Registro::driver($d['driver'])->executar($d, $c['acao'], $c['parametros']);
        $resposta = $res['_resposta'] ?? [];
        unset($res['_resposta']);
        if ($res) {
            Dispositivos::salvarEstado($d['id'], $res);
        }
        Comandos::concluir($c['id'], true, $resposta + ['estado' => $res]);
        logar(sprintf('ok   #%d %s %s (%d ms)', $c['id'], $d['slug'], $c['acao'], (microtime(true) - $ini) * 1000));
    } catch (Throwable $e) {
        Comandos::concluir($c['id'], false, ['erro' => $e->getMessage()]);
        logar(sprintf('ERRO #%d %s %s: %s', $c['id'], $d['slug'] ?? '?', $c['acao'], $e->getMessage()));
    }
}

logar('worker iniciado; drivers: ' . implode(', ', Registro::nomes()));
$pdo = preparar();
Eventos::registrar('sistema', 'info', 'Worker iniciado', 'drivers: ' . implode(', ', Registro::nomes()), 'worker');
$proxima = [];   // id do dispositivo => timestamp da próxima consulta
$metricas = new Metricas();
$proximaMetrica = 0;
$proximaLimpeza = time() + 300;
$proximaRede = time() + 20;

while ($rodando) {
    try {
        $pdo->pgsqlGetNotify(PDO::FETCH_ASSOC, 1000);   // acorda na hora quando chega comando

        if (time() >= $proximaMetrica) {
            $proximaMetrica = time() + 30;
            try {
                $m = $metricas->coletar();
                if ($m !== null) {
                    Metricas::salvar($m);
                }
            } catch (PDOException $e) {
                throw $e;
            } catch (Throwable $e) {
                logar('métricas: ' . $e->getMessage());
            }
        }
        if (time() >= $proximaRede) {
            $proximaRede = time() + 60;
            try {
                foreach (Rede::sincronizarIps() as $m) {
                    logar("rede: $m");
                }
            } catch (PDOException $e) {
                throw $e;
            } catch (Throwable $e) {
                logar('rede: ' . $e->getMessage());
            }
        }
        if (time() >= $proximaLimpeza) {
            $proximaLimpeza = time() + 86400;
            Metricas::limpar();
            Eventos::limpar();
        }

        foreach (Comandos::pegarPendentes() as $c) {
            executarComando($c);
        }

        foreach (Dispositivos::listar() as $d) {
            if (!$rodando || time() < ($proxima[$d['id']] ?? 0)) {
                continue;
            }
            try {
                $drv = Registro::driver($d['driver']);
                $proxima[$d['id']] = time() + $drv->intervalo($d);
                $e = $drv->consultar($d);
                Dispositivos::salvarEstado($d['id'], $e['dados'], $e['online']);
                if ($d['online'] !== $e['online'] && $d['atualizado_em'] !== null) {
                    logar(sprintf('%s ficou %s', $d['slug'], $e['online'] ? 'online' : 'offline'));
                    Eventos::registrar('aparelho', $e['online'] ? 'ok' : 'info',
                        $d['nome'] . ($e['online'] ? ' apareceu na rede' : ' saiu da rede'), null, 'worker');
                }
            } catch (Throwable $e) {
                $proxima[$d['id']] = time() + 60;
                logar("consulta {$d['slug']}: " . $e->getMessage());
            }
            // Comando chegou durante as consultas? Atende antes de seguir.
            foreach (Comandos::pegarPendentes() as $c) {
                executarComando($c);
            }
        }
    } catch (PDOException $e) {
        logar('erro de banco: ' . $e->getMessage());
        sleep(2);
        $pdo = preparar();
    }
}
logar('worker encerrado');
