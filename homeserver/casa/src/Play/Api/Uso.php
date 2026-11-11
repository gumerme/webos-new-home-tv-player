<?php
declare(strict_types=1);

namespace Casa\Play\Api;

use Casa\Play\Dados\Sinais;
use Casa\Play\Dados\Tarefas;

/** O que o perfil faz: progresso, Minha lista, notas, versão, "pular abertura" e preferências. */
final class Uso
{
    private const EVENTOS = ['inicio', 'tick', 'pausa', 'fim', 'creditos'];

    // ---------------------------------------------------------- progresso

    /**
     * POST progresso {perfil, titulo, versao, temporada, episodio, posicao_s, duracao_s, evento}.
     * Grava progresso do título e do episódio, soma o tempo no histórico (estatísticas), segue a série,
     * marca o que a TV está vendo e avisa as outras TVs do perfil.
     */
    public static function progresso(Pedido $p): array
    {
        $perfil = $p->perfil();
        $id = $p->int('titulo') ?? throw new Erro('informe o título', 422);
        $t = Base::linha('select tipo, nome from play.titulo where id = ?', [$id]) ?? throw new Erro('título não encontrado', 404);
        $evento = $p->str('evento', 'tick');
        if (!in_array($evento, self::EVENTOS, true)) {
            throw new Erro('evento inválido', 422);
        }
        $serie = $t['tipo'] === 's';
        $pos = max(0, (int) $p->int('posicao_s', 0));
        $dur = max(0, (int) $p->int('duracao_s', 0));
        $temp = $serie ? $p->int('temporada') : null;
        $ep = $serie ? $p->int('episodio') : null;
        if ($serie && ($temp === null || $ep === null)) {
            throw new Erro('série: informe temporada e episódio', 422);
        }
        $versao = $p->int('versao');
        $terminado = self::terminou($t['tipo'], $evento, $pos, $dur);
        $tv = $p->ctx->tvId();

        Base::transacao(function () use ($perfil, $id, $versao, $temp, $ep, $pos, $dur, $terminado, $tv, $serie, $evento): void {
            Base::exec('
                insert into play.progresso (perfil_id, titulo_id, versao_id, temporada, episodio, posicao_s, duracao_s, terminado, oculto, tv_id, atualizado_em)
                values (?, ?, ?, ?, ?, ?, ?, ?, false, ?, now())
                on conflict (perfil_id, titulo_id) do update set
                    versao_id = coalesce(excluded.versao_id, play.progresso.versao_id),
                    temporada = excluded.temporada, episodio = excluded.episodio,
                    posicao_s = excluded.posicao_s, duracao_s = excluded.duracao_s,
                    terminado = excluded.terminado, oculto = false, tv_id = excluded.tv_id, atualizado_em = now()',
                [$perfil, $id, $versao, $temp, $ep, $pos, $dur, $terminado, $tv]);
            if ($serie) {
                Base::exec('
                    insert into play.progresso_ep (perfil_id, titulo_id, temporada, episodio, posicao_s, duracao_s, terminado, atualizado_em)
                    values (?, ?, ?, ?, ?, ?, ?, now())
                    on conflict (perfil_id, titulo_id, temporada, episodio) do update set
                        posicao_s = excluded.posicao_s, duracao_s = excluded.duracao_s,
                        terminado = excluded.terminado, atualizado_em = now()',
                    [$perfil, $id, $temp, $ep, $pos, $dur, $terminado]);
                Base::exec('insert into play.serie_seguida (perfil_id, titulo_id) values (?, ?) on conflict do nothing', [$perfil, $id]);
            }
            self::historico($perfil, $id, $temp, $ep, $dur, $terminado, $tv, $evento === 'inicio');
        });

        if ($tv !== null) {
            $antes = $p->ctx->tv['assistindo'] ?? null;
            $agora = in_array($evento, ['fim', 'creditos'], true) ? null : [
                'titulo'    => $id,
                'nome'      => $t['nome'],
                'temporada' => $temp,
                'episodio'  => $ep,
                'perfil'    => $perfil,
                'desde'     => (($antes['titulo'] ?? null) === $id && !empty($antes['desde'])) ? $antes['desde'] : date(DATE_ATOM),
                'pausado'   => $evento === 'pausa',
            ];
            Base::exec('update play.tv set assistindo = cast(? as jsonb) where id = ?', [$agora === null ? null : json_encode($agora, JSON_UNESCAPED_UNICODE), $tv]);
        }

        try {
            Sinais::enviar('progresso', [
                'titulo' => $id, 'nome' => $t['nome'], 'posicao_s' => $pos, 'duracao_s' => $dur,
                'tv' => $p->ctx->tvNome(), 'temporada' => $temp, 'episodio' => $ep, 'evento' => $evento, 'terminado' => $terminado,
            ], null, $perfil, $tv);
        } catch (\Throwable $e) {
            error_log('play: sinal de progresso não enviado: ' . $e->getMessage());
        }
        return ['ok' => true, 'terminado' => $terminado];
    }

    /** Fim, créditos ou posição dentro dos créditos (regra da v10) = terminado. */
    public static function terminou(string $tipo, string $evento, int $pos, int $dur): bool
    {
        if (in_array($evento, ['fim', 'creditos'], true)) {
            return true;
        }
        return $dur > 0 && $pos >= $dur - Base::creditos($tipo, $dur);
    }

    /**
     * Histórico: uma linha por sessão (título/episódio). Cada chamada soma o tempo desde a anterior
     * (no máximo 30 s: tick de 15 s; pausa longa não conta). "inicio" abre sessão nova.
     */
    private static function historico(int $perfil, int $id, ?int $temp, ?int $ep, int $dur, bool $terminado, ?int $tv, bool $nova): void
    {
        $n = $nova ? 0 : Base::exec("
            update play.historico
               set assistido_s = assistido_s + cast(least(greatest(extract(epoch from now() - coalesce(fim, inicio)), 0), 30) as int),
                   fim = now(), duracao_s = nullif(?, 0), terminado = terminado or ?, tv_id = coalesce(?, tv_id)
             where id = (select id from play.historico
                          where perfil_id = ? and titulo_id = ?
                            and temporada is not distinct from cast(? as int) and episodio is not distinct from cast(? as int)
                            and coalesce(fim, inicio) > now() - interval '30 minutes'
                          order by id desc limit 1)", [$dur, $terminado, $tv, $perfil, $id, $temp, $ep]);
        if ($n === 0) {
            Base::exec('insert into play.historico (perfil_id, titulo_id, temporada, episodio, inicio, fim, assistido_s, duracao_s, terminado, tv_id)
                        values (?, ?, ?, ?, now(), now(), 0, nullif(?, 0), ?, ?)', [$perfil, $id, $temp, $ep, $dur, $terminado, $tv]);
        }
    }

    // ------------------------------------------------- lista, nota, ocultar

    public static function lista(Pedido $p): array
    {
        $perfil = $p->perfil();
        $id = self::titulo($p);
        if ($p->str('acao', 'add') === 'del') {
            Base::exec('delete from play.lista where perfil_id = ? and titulo_id = ?', [$perfil, $id]);
            return ['ok' => true, 'minha_lista' => false];
        }
        Base::exec('insert into play.lista (perfil_id, titulo_id) values (?, ?) on conflict do nothing', [$perfil, $id]);
        return ['ok' => true, 'minha_lista' => true];
    }

    public static function nota(Pedido $p): array
    {
        $perfil = $p->perfil();
        $id = self::titulo($p);
        $v = (int) $p->int('valor', 0);
        if (!in_array($v, [1, -1, 0], true)) {
            throw new Erro('valor deve ser 1, -1 ou 0', 422);
        }
        if ($v === 0) {
            Base::exec('delete from play.nota where perfil_id = ? and titulo_id = ?', [$perfil, $id]);
        } else {
            Base::exec('insert into play.nota (perfil_id, titulo_id, valor) values (?, ?, ?)
                        on conflict (perfil_id, titulo_id) do update set valor = excluded.valor, em = now()', [$perfil, $id, $v]);
        }
        // Recálculo na hora (pedido 09/11/2026 noite): o destaque e os Recomendados mudam em segundos;
        // no fim o worker manda `atualizado {o_que:'recomendacoes'}` e a TV recarrega o Início.
        // Até lá, Inicio::rec já tira o avaliado das sugestões. Gostei = visto (Base::VISTOS).
        try {
            Tarefas::forcar('recomendacoes');
        } catch (\Throwable $e) {
            error_log('play: recálculo das recomendações não pedido: ' . $e->getMessage());
        }
        return ['ok' => true, 'nota_usuario' => $v];
    }

    /** Tira do "Continuar" (volta quando tocar de novo: o progresso zera o oculto). */
    public static function ocultar(Pedido $p): array
    {
        Base::exec('update play.progresso set oculto = true where perfil_id = ? and titulo_id = ?', [$p->perfil(), self::titulo($p)]);
        return ['ok' => true];
    }

    /** POST versao {perfil, titulo, versao}: versao = id da versão (painel) ou "dub"/"leg". */
    public static function versao(Pedido $p): array
    {
        $perfil = $p->perfil();
        $id = self::titulo($p);
        $v = $p->valor('versao');
        if (is_string($v) && in_array($v, ['dub', 'leg'], true)) {
            $idioma = $v;
        } else {
            $painel = $p->int('versao') ?? throw new Erro('informe a versão', 422);
            $idioma = Base::valor('select idioma from play.versao where titulo_id = ? and painel_id = ? and conta_id = ?', [$id, $painel, Base::contaId()])
                ?? throw new Erro('versão não encontrada para este título', 404);
        }
        Base::exec('insert into play.versao_escolhida (perfil_id, titulo_id, idioma) values (?, ?, ?)
                    on conflict (perfil_id, titulo_id) do update set idioma = excluded.idioma', [$perfil, $id, $idioma]);
        return ['ok' => true, 'idioma' => $idioma];
    }

    private static function titulo(Pedido $p): int
    {
        $id = $p->int('titulo') ?? throw new Erro('informe o título', 422);
        if (!Base::valor('select 1 from play.titulo where id = ?', [$id])) {
            throw new Erro('título não encontrado', 404);
        }
        return $id;
    }

    // ------------------------------------------------------------ abertura

    /**
     * Aprendizado do "Pular abertura" (da casa, não do perfil). Abertura parecida com a guardada
     * (diferença até 15 s) entra na média; muito diferente substitui (alguém corrigiu).
     */
    public static function abertura(Pedido $p): array
    {
        $id = self::titulo($p);
        $ini = (int) $p->int('inicio_s', -1);
        $fim = (int) $p->int('fim_s', -1);
        if (!self::aberturaValida($ini, $fim)) {
            throw new Erro('abertura inválida (entre 20 s e 4 min, começando nos primeiros 7 min)', 422);
        }
        Base::exec('
            insert into play.abertura (titulo_id, inicio_s, fim_s, amostras, atualizado_em) values (?, ?, ?, 1, now())
            on conflict (titulo_id) do update set
                inicio_s = case when abs(play.abertura.inicio_s - excluded.inicio_s) <= 15 and abs(play.abertura.fim_s - excluded.fim_s) <= 15
                                then cast(round((play.abertura.inicio_s * play.abertura.amostras + excluded.inicio_s) / (play.abertura.amostras + 1.0)) as int)
                                else excluded.inicio_s end,
                fim_s    = case when abs(play.abertura.inicio_s - excluded.inicio_s) <= 15 and abs(play.abertura.fim_s - excluded.fim_s) <= 15
                                then cast(round((play.abertura.fim_s * play.abertura.amostras + excluded.fim_s) / (play.abertura.amostras + 1.0)) as int)
                                else excluded.fim_s end,
                amostras = case when abs(play.abertura.inicio_s - excluded.inicio_s) <= 15 and abs(play.abertura.fim_s - excluded.fim_s) <= 15
                                then least(play.abertura.amostras + 1, 20) else 1 end,
                atualizado_em = now()', [$id, $ini, $fim]);
        return ['ok' => true];
    }

    public static function aberturaValida(int $ini, int $fim): bool
    {
        return $ini >= 0 && $fim > $ini && $fim - $ini >= 20 && $fim - $ini <= 240 && $ini <= 420;
    }

    // -------------------------------------------------------- preferências

    /** Chave permitida => validador do valor. */
    public static function validarPreferencia(string $chave, mixed $valor): mixed
    {
        if ($valor === null) {
            return null;                    // null apaga (inclui 'ultimo_canal': Verde no Continuar)
        }
        if ($chave === 'ultimo_canal') {
            throw new Erro("ultimo_canal: só null (apagar); o servidor grava ao tocar o canal", 422);
        }
        if (in_array($chave, ['legenda_auto', 'trailer_destaque', 'esconder_fora_lista'], true)) {
            return $valor === true || $valor === 1 || $valor === '1' || $valor === 'true';
        }
        if ($chave === 'legenda_tamanho') {
            return in_array($valor, ['p', 'm', 'g'], true) ? $valor : throw new Erro('legenda_tamanho: p, m ou g', 422);
        }
        if (preg_match('/^legenda_atraso:\d{1,12}:[\w.\- ]{1,120}$/u', $chave)) {
            if (!is_numeric($valor) || abs((float) $valor) > 600) {
                throw new Erro('atraso em segundos (até 10 min)', 422);
            }
            return round((float) $valor, 1);
        }
        throw new Erro("preferência '$chave' não existe", 422);
    }

    public static function preferencia(Pedido $p): array
    {
        $perfil = $p->perfil();
        $chave = $p->str('chave');
        $valor = self::validarPreferencia($chave, $p->corpo['valor'] ?? null);
        if ($valor === null || (str_starts_with($chave, 'legenda_atraso:') && (float) $valor === 0.0)) {
            Base::exec('delete from play.preferencia where perfil_id = ? and chave = ?', [$perfil, $chave]);
        } else {
            Base::exec('insert into play.preferencia (perfil_id, chave, valor) values (?, ?, cast(? as jsonb))
                        on conflict (perfil_id, chave) do update set valor = excluded.valor',
                [$perfil, $chave, json_encode($valor, JSON_UNESCAPED_UNICODE)]);
        }
        return ['ok' => true];
    }

    public static function preferencias(Pedido $p): array|object
    {
        $out = [];
        foreach (Base::linhas('select chave, valor from play.preferencia where perfil_id = ? order by chave', [$p->perfil()]) as $x) {
            $out[$x['chave']] = json_decode((string) $x['valor'], true);
        }
        return $out ?: new \stdClass();
    }
}
