<?php
declare(strict_types=1);

namespace Casa;

/** Linha do tempo: eventos + comandos + acessos ao painel, numa lista só. */
final class Eventos
{
    public static function registrar(string $tipo, string $nivel, string $titulo, ?string $detalhe = null, ?string $origem = null): void
    {
        try {
            Db::pdo()->prepare('insert into evento (tipo, nivel, titulo, detalhe, origem) values (?, ?, ?, ?, ?)')
                ->execute([$tipo, $nivel, mb_substr($titulo, 0, 200), $detalhe !== null ? mb_substr($detalhe, 0, 1000) : null, $origem]);
        } catch (\Throwable $e) {
            error_log('evento não gravado: ' . $e->getMessage());
        }
    }

    /** @return list<array{quando:string,tipo:string,nivel:string,titulo:string,detalhe:?string,origem:?string}> */
    public static function linhaDoTempo(int $limite = 20, ?string $tipo = null): array
    {
        $sql = "
            select x.*, extract(epoch from x.quando)::bigint as ts from (
                select criado_em as quando, tipo, nivel, titulo, detalhe, origem from evento
                union all
                select c.criado_em, 'comando',
                       case c.status when 'ok' then 'ok' when 'erro' then 'erro' else 'info' end,
                       d.nome || ': ' || c.acao,
                       c.resposta->>'erro',
                       c.origem
                  from comando c join dispositivo d on d.id = c.dispositivo_id
                union all
                select t.criado_em, 'acesso', case when t.sucesso then 'info' else 'aviso' end,
                       case when t.sucesso then 'Entrada no painel: ' else 'Senha errada no painel: ' end || coalesce(t.login, '?'),
                       null, t.ip
                  from login_tentativa t
            ) x
            " . ($tipo ? 'where tipo = :tipo' : '') . "
            order by quando desc
            limit :limite";
        $st = Db::pdo()->prepare($sql);
        if ($tipo) {
            $st->bindValue('tipo', $tipo);
        }
        $st->bindValue('limite', max(1, min(500, $limite)), \PDO::PARAM_INT);
        $st->execute();
        return $st->fetchAll();
    }

    public static function limpar(): void
    {
        Db::pdo()->exec("delete from evento where criado_em < now() - interval '180 days'");
        Db::pdo()->exec("delete from metrica_rede where coletado_em < now() - interval '30 days'");
    }
}
