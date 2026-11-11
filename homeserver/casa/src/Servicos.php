<?php
declare(strict_types=1);

namespace Casa;

/** Saúde dos serviços do homeserver (sem acesso ao Docker: testa portas e a última métrica). */
final class Servicos
{
    public static function status(): array
    {
        $r = [];
        try {
            Db::pdo()->query('select 1');
            $r[] = ['nome' => 'Banco (Postgres)', 'ok' => true, 'detalhe' => '127.0.0.1:5432'];
        } catch (\Throwable $e) {
            $r[] = ['nome' => 'Banco (Postgres)', 'ok' => false, 'detalhe' => $e->getMessage()];
        }

        $idade = null;
        try {
            $idade = Db::pdo()->query('select extract(epoch from now() - max(coletado_em)) from metrica')->fetchColumn();
        } catch (\Throwable) {
        }
        $vivo = $idade !== null && $idade !== false && (float) $idade < 120;
        $r[] = ['nome' => 'Worker (aparelhos)', 'ok' => $vivo,
                'detalhe' => $idade === null || $idade === false ? 'sem métricas ainda' : 'última coleta há ' . (int) $idade . ' s'];

        foreach ([
            ['Homebridge (Siri)', 51826],
            ['Homebridge UI', 8581],
            ['Matter Server', 5580],
        ] as [$nome, $porta]) {
            $r[] = ['nome' => $nome, 'ok' => self::porta('127.0.0.1', $porta), 'detalhe' => "porta $porta"];
        }
        return $r;
    }

    private static function porta(string $ip, int $porta): bool
    {
        $s = @fsockopen($ip, $porta, $e, $m, 0.5);
        if ($s) {
            fclose($s);
            return true;
        }
        return false;
    }
}
