<?php
declare(strict_types=1);

namespace Casa;

use PDO;

final class Db
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            $dsn = sprintf(
                'pgsql:host=%s;port=%s;dbname=%s',
                getenv('DB_HOST') ?: '127.0.0.1',
                getenv('DB_PORT') ?: '5432',
                getenv('DB_NAME') ?: 'casa'
            );
            self::$pdo = new PDO($dsn, getenv('DB_USER') ?: 'casa', getenv('DB_PASS') ?: '', [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        }
        return self::$pdo;
    }

    public static function reconectar(): PDO
    {
        self::$pdo = null;
        return self::pdo();
    }

    /** Aplica sql/NNN_*.sql ainda não aplicados (controle na tabela migracao). */
    public static function migrar(): array
    {
        $pdo = self::pdo();
        $pdo->exec('create table if not exists migracao (arquivo text primary key, aplicado_em timestamptz not null default now())');
        $feitos = $pdo->query('select arquivo from migracao')->fetchAll(PDO::FETCH_COLUMN);
        $aplicados = [];
        $arquivos = glob(dirname(__DIR__) . '/sql/*.sql') ?: [];
        sort($arquivos);
        foreach ($arquivos as $caminho) {
            $nome = basename($caminho);
            if (in_array($nome, $feitos, true)) {
                continue;
            }
            $pdo->beginTransaction();
            try {
                $pdo->exec((string) file_get_contents($caminho));
                $pdo->prepare('insert into migracao (arquivo) values (?)')->execute([$nome]);
                $pdo->commit();
                $aplicados[] = $nome;
            } catch (\Throwable $e) {
                $pdo->rollBack();
                throw new \RuntimeException("migração $nome falhou: " . $e->getMessage(), 0, $e);
            }
        }
        return $aplicados;
    }
}
