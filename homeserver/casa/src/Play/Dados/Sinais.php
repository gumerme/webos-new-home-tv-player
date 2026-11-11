<?php
declare(strict_types=1);

namespace Casa\Play\Dados;

use Casa\Db;

/**
 * Sinais para as TVs (a API serve por SSE): uma linha em play.sinal + NOTIFY play_sinal com o id.
 * tv/perfil null = todos; exceto = TV que gerou o sinal (não recebe de volta).
 */
final class Sinais
{
    public const CANAL = 'play_sinal';

    public static function enviar(string $evento, array $dados = [], ?int $tvId = null, ?int $perfilId = null, ?int $excetoTv = null): void
    {
        $pdo = Db::pdo();
        $st = $pdo->prepare('insert into play.sinal (tv_id, perfil_id, exceto_tv, evento, dados) values (?, ?, ?, ?, cast(? as jsonb)) returning id');
        $st->execute([$tvId, $perfilId, $excetoTv, $evento, json_encode((object) $dados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
        $id = (int) $st->fetchColumn();
        $pdo->prepare('select pg_notify(?, ?)')->execute([self::CANAL, (string) $id]);
    }

    /** `atualizado {o_que}` para todas as TVs (catálogo, top10, epg) ou só um perfil (recomendações). */
    public static function atualizado(string $oQue, ?int $perfilId = null): void
    {
        self::enviar('atualizado', ['o_que' => $oQue], null, $perfilId);
    }
}
