<?php
declare(strict_types=1);

namespace Casa;

final class Dispositivos
{
    private const SELECT = "
        select d.id, d.slug, d.nome, d.tipo, d.driver, d.comodo, d.config, d.homekit, d.ativo,
               coalesce(e.online, false) as online, coalesce(e.dados, '{}') as dados, e.atualizado_em
          from dispositivo d
          left join estado e on e.dispositivo_id = d.id";

    /** @return list<array<string,mixed>> */
    public static function listar(bool $soHomekit = false, bool $soAtivos = true): array
    {
        $where = [];
        if ($soAtivos) {
            $where[] = 'd.ativo';
        }
        if ($soHomekit) {
            $where[] = 'd.homekit';
        }
        $sql = self::SELECT . ($where ? ' where ' . implode(' and ', $where) : '') . ' order by d.comodo nulls last, d.nome';
        return array_map(self::hidratar(...), Db::pdo()->query($sql)->fetchAll());
    }

    public static function porSlug(string $slug): ?array
    {
        $st = Db::pdo()->prepare(self::SELECT . ' where d.slug = ?');
        $st->execute([$slug]);
        $l = $st->fetch();
        return $l ? self::hidratar($l) : null;
    }

    public static function porId(int $id): ?array
    {
        $st = Db::pdo()->prepare(self::SELECT . ' where d.id = ?');
        $st->execute([$id]);
        $l = $st->fetch();
        return $l ? self::hidratar($l) : null;
    }

    /** Cria ou atualiza pelo slug. */
    public static function salvar(array $d): array
    {
        foreach (['slug', 'nome', 'tipo', 'driver'] as $c) {
            if (empty($d[$c]) || !is_string($d[$c])) {
                throw new \InvalidArgumentException("campo obrigatório: $c");
            }
        }
        if (!preg_match('/^[a-z0-9][a-z0-9-]{0,40}$/', $d['slug'])) {
            throw new \InvalidArgumentException('slug: só minúsculas, números e hífen');
        }
        $st = Db::pdo()->prepare("
            insert into dispositivo (slug, nome, tipo, driver, comodo, config, homekit, ativo)
            values (:slug, :nome, :tipo, :driver, :comodo, cast(:config as jsonb), :homekit, :ativo)
            on conflict (slug) do update set
                nome = excluded.nome, tipo = excluded.tipo, driver = excluded.driver,
                comodo = excluded.comodo, config = dispositivo.config || excluded.config,
                homekit = excluded.homekit, ativo = excluded.ativo");
        $st->execute([
            'slug'    => $d['slug'],
            'nome'    => $d['nome'],
            'tipo'    => $d['tipo'],
            'driver'  => $d['driver'],
            'comodo'  => $d['comodo'] ?? null,
            'config'  => json_encode((object) ($d['config'] ?? []), JSON_UNESCAPED_UNICODE),
            'homekit' => (int) ($d['homekit'] ?? true),
            'ativo'   => (int) ($d['ativo'] ?? true),
        ]);
        return self::porSlug($d['slug']);
    }

    /** Mescla dados no estado atual (não apaga chaves que não vieram). */
    public static function salvarEstado(int $id, array $dados, ?bool $online = null): void
    {
        $st = Db::pdo()->prepare("
            insert into estado (dispositivo_id, online, dados, atualizado_em)
            values (:id, coalesce(cast(:online1 as boolean), false), cast(:dados as jsonb), now())
            on conflict (dispositivo_id) do update set
                online = coalesce(cast(:online2 as boolean), estado.online),
                dados = estado.dados || excluded.dados,
                atualizado_em = now()");
        $st->bindValue('id', $id, \PDO::PARAM_INT);
        $online = $online === null ? null : ($online ? 'true' : 'false');
        $st->bindValue('online1', $online, $online === null ? \PDO::PARAM_NULL : \PDO::PARAM_STR);
        $st->bindValue('online2', $online, $online === null ? \PDO::PARAM_NULL : \PDO::PARAM_STR);
        $st->bindValue('dados', json_encode((object) $dados, JSON_UNESCAPED_UNICODE));
        $st->execute();
    }

    /** Mescla chaves na config (ex.: client_key da TV, MAC descoberto). */
    public static function mesclarConfig(int $id, array $config): void
    {
        $st = Db::pdo()->prepare('update dispositivo set config = config || cast(? as jsonb) where id = ?');
        $st->execute([json_encode((object) $config, JSON_UNESCAPED_UNICODE), $id]);
    }

    /** Versão para fora (API/HomeKit): sem segredos da config. */
    public static function publico(array $d): array
    {
        unset($d['config']['client_key']);
        return $d;
    }

    private static function hidratar(array $l): array
    {
        $l['id'] = (int) $l['id'];
        $l['config'] = json_decode((string) $l['config'], true) ?: [];
        $l['dados'] = json_decode((string) $l['dados'], true) ?: [];
        foreach (['homekit', 'ativo', 'online'] as $b) {
            $l[$b] = filter_var($l[$b], FILTER_VALIDATE_BOOLEAN);
        }
        return $l;
    }
}
