<?php
declare(strict_types=1);

namespace Casa;

/**
 * Fila de comandos no Postgres. A API grava e avisa o worker por NOTIFY;
 * só o worker fala com os aparelhos.
 */
final class Comandos
{
    public const CANAL = 'casa_comando';

    public static function enfileirar(int $dispositivoId, string $acao, array $parametros = [], string $origem = 'api'): int
    {
        if (!preg_match('/^[a-z_]{2,40}$/', $acao)) {
            throw new \InvalidArgumentException('ação inválida');
        }
        $pdo = Db::pdo();
        $st = $pdo->prepare('insert into comando (dispositivo_id, acao, parametros, origem) values (?, ?, cast(? as jsonb), ?) returning id');
        $st->execute([$dispositivoId, $acao, json_encode((object) $parametros, JSON_UNESCAPED_UNICODE), $origem]);
        $id = (int) $st->fetchColumn();
        $pdo->prepare('select pg_notify(?, ?)')->execute([self::CANAL, (string) $id]);
        return $id;
    }

    /** Espera o worker terminar (ou o tempo acabar) e devolve o comando. */
    public static function aguardar(int $id, float $timeoutSeg = 10.0): array
    {
        $fim = microtime(true) + $timeoutSeg;
        do {
            $c = self::buscar($id);
            if ($c && in_array($c['status'], ['ok', 'erro'], true)) {
                return $c;
            }
            usleep(150_000);
        } while (microtime(true) < $fim);
        return self::buscar($id) ?? ['id' => $id, 'status' => 'desconhecido'];
    }

    public static function buscar(int $id): ?array
    {
        $st = Db::pdo()->prepare('select * from comando where id = ?');
        $st->execute([$id]);
        $c = $st->fetch();
        return $c ? self::hidratar($c) : null;
    }

    /** Pega pendentes e marca como executando (seguro com mais de um worker). */
    public static function pegarPendentes(int $limite = 20): array
    {
        $st = Db::pdo()->prepare("
            update comando set status = 'executando'
             where id in (select id from comando where status = 'pendente'
                           order by id for update skip locked limit ?)
            returning *");
        $st->execute([$limite]);
        $lista = array_map(self::hidratar(...), $st->fetchAll());
        usort($lista, fn($a, $b) => $a['id'] <=> $b['id']);
        return $lista;
    }

    public static function concluir(int $id, bool $ok, array $resposta): void
    {
        $st = Db::pdo()->prepare('update comando set status = ?, resposta = cast(? as jsonb), executado_em = now() where id = ?');
        $st->execute([$ok ? 'ok' : 'erro', json_encode((object) $resposta, JSON_UNESCAPED_UNICODE), $id]);
    }

    /** Comandos que ficaram "executando" se o worker caiu no meio. */
    public static function recuperarOrfaos(): int
    {
        return Db::pdo()->exec("update comando set status = 'erro', resposta = '{\"erro\":\"worker reiniciado\"}', executado_em = now()
                                 where status = 'executando'") ?: 0;
    }

    public static function recentes(int $limite = 50): array
    {
        $st = Db::pdo()->prepare('select c.*, d.slug from comando c join dispositivo d on d.id = c.dispositivo_id order by c.id desc limit ?');
        $st->execute([max(1, min(500, $limite))]);
        return array_map(self::hidratar(...), $st->fetchAll());
    }

    private static function hidratar(array $c): array
    {
        $c['id'] = (int) $c['id'];
        $c['dispositivo_id'] = (int) $c['dispositivo_id'];
        $c['parametros'] = json_decode((string) $c['parametros'], true) ?: [];
        $c['resposta'] = $c['resposta'] === null ? null : json_decode((string) $c['resposta'], true);
        return $c;
    }
}
