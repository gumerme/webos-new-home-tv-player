<?php
declare(strict_types=1);

namespace Casa\Play\Dados;

use Casa\Comandos;
use Casa\Db;

/**
 * Tarefa "avisos" (30 s): avisos vencidos (`quando <= now()`, status ativo) viram sinal `aviso` para as
 * TVs do perfil e, em cada TV ligada a um aparelho do painel que está ligada e sem o Player na frente
 * (nada da TV nos últimos 60 s e app em primeiro plano ≠ Player), um comando `notificar` do casa-worker
 * (toast do webOS). Também cria o aviso de vencimento da conta (15, 7 e 1 dia antes, uma vez por dia).
 */
final class Avisos
{
    public const APP_PLAYER = 'org.webostv.play';
    private const DIAS_VENCIMENTO = [15, 7, 1];

    public static function tarefa(): array
    {
        self::vencimento();
        $pdo = Db::pdo();
        $avisos = $pdo->query("select a.id, a.perfil_id, a.tipo, a.alvo, a.texto from play.aviso a
                                where a.status = 'ativo' and a.quando is not null and a.quando <= now()
                                order by a.quando limit 50")->fetchAll();
        if (!$avisos) {
            return ['msg' => 'nada a avisar'];
        }
        $tvs = self::tvsParaToast();
        $toasts = 0;
        foreach ($avisos as $a) {
            $alvo = json_decode((string) $a['alvo'], true) ?: [];
            $m = self::montar($a['tipo'], $alvo, $a['texto'] ?: null);
            $texto = $m['texto'];
            Sinais::enviar('aviso', ['id' => (int) $a['id'], 'tipo' => $a['tipo'], 'texto' => $texto, 'subtexto' => $m['subtexto'], 'acao' => $m['acao']],
                null, $a['perfil_id'] !== null ? (int) $a['perfil_id'] : null);
            foreach ($tvs as $tv) {
                try {
                    Comandos::enfileirar($tv['dispositivo_id'], 'notificar', ['texto' => mb_substr($texto, 0, 120)], 'play');
                    $toasts++;
                } catch (\PDOException $e) {
                    throw $e;
                } catch (\Throwable $e) {
                    Log::info("aviso {$a['id']}: notificar {$tv['slug']}: " . $e->getMessage());
                }
            }
            $pdo->prepare("update play.aviso set status = 'disparado', disparado_em = now(), texto = coalesce(texto, ?) where id = ?")
                ->execute([$texto, $a['id']]);
        }
        return ['msg' => sprintf('%d aviso(s) disparado(s), %d notificação(ões) na TV', count($avisos), $toasts)];
    }

    /**
     * TVs do Player ligadas a um aparelho online e ligado, sem o Player em primeiro plano: a TV não
     * chamou a API nos últimos 60 s e o app da frente (casa-worker, a cada 15 s) não é o Player.
     */
    private static function tvsParaToast(): array
    {
        return Db::pdo()->query("
            select distinct on (d.id) d.id as dispositivo_id, d.slug
              from play.tv t
              join public.dispositivo d on d.slug = t.dispositivo_slug and d.ativo
              join public.estado e on e.dispositivo_id = d.id
             where t.status = 'ativa'
               and e.online and coalesce((e.dados->>'ligado')::boolean, true)
               and e.atualizado_em > now() - interval '2 minutes'
               and coalesce(e.dados->>'app', '') <> '" . self::APP_PLAYER . "'
               and (t.visto_em is null or t.visto_em < now() - interval '60 seconds')
             order by d.id")->fetchAll();
    }

    /** Nome do canal (painel_id) na conta ativa. */
    private static function nomeCanal(?int $canal): ?string
    {
        if (!$canal) {
            return null;
        }
        $st = Db::pdo()->prepare('select c.nome from play.canal c join play.conta a on a.id = c.conta_id and a.ativa where c.painel_id = ? limit 1');
        $st->execute([$canal]);
        $n = $st->fetchColumn();
        return $n === false ? null : (string) $n;
    }

    private static function nomeTitulo(mixed $id): ?string
    {
        if (!is_numeric($id) || (int) $id <= 0) {
            return null;
        }
        $st = Db::pdo()->prepare('select nome from play.titulo where id = ?');
        $st->execute([(int) $id]);
        $n = $st->fetchColumn();
        return $n === false ? null : (string) $n;
    }

    /**
     * Texto (1ª linha), subtexto (2ª linha) e ação do aviso, para o sinal `aviso` e o toast.
     * Alvos que a TV cria: jogo {jogo, canal}, programa {canal, programa, titulo (nome do programa)},
     * episodio {titulo, temporada, episodio}, chegar {tmdb, tipo, nome}; o worker acrescenta `titulo` (id) no chegar.
     * @return array{texto:string, subtexto:?string, acao:?array}
     */
    public static function montar(string $tipo, array $alvo, ?string $texto = null): array
    {
        $pdo = Db::pdo();
        $acao = null;
        $sub = null;
        switch ($tipo) {
            case 'jogo':
                $j = null;
                if (!empty($alvo['jogo'])) {
                    $st = $pdo->prepare("select (casa->>'nome') || ' x ' || (fora->>'nome') as nome, canal_id, liga from play.jogo where id = ?");
                    $st->execute([(string) $alvo['jogo']]);
                    $j = $st->fetch() ?: null;
                }
                $nome = $j['nome'] ?? ($alvo['nome'] ?? null);
                $canal = (int) ($alvo['canal'] ?? 0) ?: (int) ($j['canal_id'] ?? 0);
                $nomeCanal = self::nomeCanal($canal);
                $acao = $canal ? ['tipo' => 'canal', 'id' => $canal, 'nome' => $nomeCanal ?? $nome] : null;
                $texto ??= ($nome ? "$nome começa em 5 min" : 'O jogo vai começar');
                $sub = implode(' · ', array_filter([$j['liga'] ?? null, $nomeCanal, 'aviso que você ligou']));
                break;
            case 'programa':
                $p = null;
                if (!empty($alvo['programa']) && is_numeric($alvo['programa'])) {
                    $st = $pdo->prepare('select titulo, canal_id from play.programa where id = ?');
                    $st->execute([(int) $alvo['programa']]);
                    $p = $st->fetch() ?: null;
                }
                $nome = $p['titulo'] ?? (is_string($alvo['titulo'] ?? null) ? $alvo['titulo'] : ($alvo['nome'] ?? null));
                $canal = (int) ($alvo['canal'] ?? 0) ?: (int) ($p['canal_id'] ?? 0);
                $nomeCanal = self::nomeCanal($canal);
                $acao = $canal ? ['tipo' => 'canal', 'id' => $canal, 'nome' => $nomeCanal ?? $nome] : null;
                $texto ??= ($nome ? "$nome começa em 5 min" : 'O programa vai começar');
                $sub = implode(' · ', array_filter([$nomeCanal, 'aviso que você ligou']));
                break;
            case 'episodio':
            case 'chegar':
                $id = is_numeric($alvo['titulo'] ?? null) ? (int) $alvo['titulo'] : 0;
                $nome = self::nomeTitulo($id) ?? ($alvo['nome'] ?? null);
                $acao = $id ? ['tipo' => 'titulo', 'id' => $id, 'nome' => $nome] : null;
                if ($tipo === 'episodio') {
                    $ep = !empty($alvo['temporada']) && !empty($alvo['episodio']) ? sprintf('T%d:E%d', $alvo['temporada'], $alvo['episodio']) : null;
                    $texto ??= 'Novo episódio' . ($nome ? " de $nome" : '') . ($ep ? ": $ep" : '');
                    $sub = 'Série que você segue' . (($alvo['versao'] ?? '') === 'leg' ? ' · legendado' : '');
                } else {
                    $texto ??= 'Chegou na lista' . ($nome ? ": $nome" : '');
                    $sub = 'Você pediu para avisar';
                }
                break;
            case 'vencimento':
                $texto ??= 'A lista IPTV vence em breve';
                $sub = 'Renove a lista para não ficar sem';
                break;
            default:
                $texto ??= 'Aviso do Player';
        }
        return ['texto' => $texto, 'subtexto' => $sub ?: null, 'acao' => $acao];
    }

    /** 1ª linha do aviso. */
    public static function texto(string $tipo, array $alvo): string
    {
        return self::montar($tipo, $alvo)['texto'];
    }

    /** O que a TV faz ao abrir o aviso: {tipo:"canal"|"titulo", id, nome} ou null. */
    public static function acao(string $tipo, array $alvo): ?array
    {
        return self::montar($tipo, $alvo)['acao'];
    }

    /** Vencimento da conta ativa a 15, 7 e 1 dia: um aviso (para todos os perfis) por dia, de dia. */
    private static function vencimento(): void
    {
        $agora = time();
        $h = (int) date('G', $agora);
        if ($h < 9 || $h >= 22) {
            return;
        }
        $c = Db::pdo()->query("select id, nome, extract(epoch from vence_em)::bigint as vence from play.conta where ativa and vence_em is not null")->fetch();
        if (!$c) {
            return;
        }
        $dias = (int) round((strtotime(date('Y-m-d', (int) $c['vence'])) - strtotime(date('Y-m-d', $agora))) / 86400);
        if (!in_array($dias, self::DIAS_VENCIMENTO, true)) {
            return;
        }
        $pdo = Db::pdo();
        $st = $pdo->prepare("select 1 from play.aviso where tipo = 'vencimento' and criado_em::date = current_date and alvo->>'conta' = ?");
        $st->execute([(string) $c['id']]);
        if ($st->fetchColumn()) {
            return;
        }
        $texto = $dias === 1
            ? 'A lista IPTV vence amanhã (' . date('d/m', (int) $c['vence']) . '). Renove para não ficar sem.'
            : "A lista IPTV vence em $dias dias (" . date('d/m', (int) $c['vence']) . ').';
        $pdo->prepare("insert into play.aviso (perfil_id, tipo, alvo, quando, texto) values (null, 'vencimento', cast(? as jsonb), now(), ?)")
            ->execute([Banco::json(['conta' => (int) $c['id'], 'dias' => $dias, 'vence_em' => date('c', (int) $c['vence'])]), $texto]);
    }
}
