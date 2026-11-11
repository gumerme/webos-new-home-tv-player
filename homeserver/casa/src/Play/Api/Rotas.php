<?php
declare(strict_types=1);

namespace Casa\Play\Api;

/**
 * Tabela de rotas de /api/play/* (contrato em docs/play-2.0.md).
 * acesso: publico (sem token) | tv (token da TV, sessão do painel ou CASA_TOKEN) | integracao (CASA_TOKEN ou sessão).
 * saida: json | stream (o handler escreve a resposta: SSE, imagem, legenda).
 */
final class Rotas
{
    /** [método, caminho, [classe, método], acesso, saída] */
    public const TABELA = [
        ['GET',  'saude',                   [Tvs::class, 'saude'],         'publico',    'json'],
        ['POST', 'tv/registrar',            [Tvs::class, 'registrar'],     'publico',    'json'],
        ['GET',  'img/{tam}/{arquivo}',     [Imagens::class, 'rota'],      'publico',    'stream'],
        ['GET',  'app/versao',              [App::class, 'versao'],        'publico',    'json'],
        ['GET',  'app/pacote/{arquivo}',    [App::class, 'pacote'],        'publico',    'stream'],
        ['GET',  'app/atualizador',         [App::class, 'atualizador'],   'publico',    'stream'],
        ['GET',  'launcher',                [Launcher::class, 'rota'],     'publico',    'json'],      // Home das TVs; só rede de casa

        ['GET',  'estado',                  [Tvs::class, 'estado'],        'tv',         'json'],
        ['GET',  'conta',                   [Tvs::class, 'conta'],         'tv',         'json'],
        ['GET',  'perfis',                  [Tvs::class, 'perfis'],        'tv',         'json'],
        ['POST', 'perfis',                  [Tvs::class, 'criarPerfil'],   'tv',         'json'],

        ['GET',  'inicio',                  [Inicio::class, 'inicio'],     'tv',         'json'],
        ['GET',  'secao',                   [Inicio::class, 'secao'],      'tv',         'json'],
        ['GET',  'suas-series',             [Inicio::class, 'suasSeries'], 'tv',         'json'],
        ['GET',  'categorias',              [Inicio::class, 'categorias'], 'tv',         'json'],
        ['GET',  'categoria/{cat}',         [Inicio::class, 'categoria'],  'tv',         'json'],
        ['GET',  'ranking',                 [Inicio::class, 'ranking'],    'tv',         'json'],

        ['GET',  'titulo/por-tmdb',         [Titulos::class, 'porTmdb'],   'tv',         'json'],
        ['GET',  'titulo/{id}',             [Titulos::class, 'titulo'],    'tv',         'json'],
        ['GET',  'titulo/{id}/temporadas',  [Titulos::class, 'temporadas'], 'tv',        'json'],
        ['GET',  'titulo/{id}/apos',        [Titulos::class, 'apos'],      'tv',         'json'],
        ['GET',  'tocar',                   [Titulos::class, 'tocar'],     'tv',         'json'],

        ['POST', 'progresso',               [Uso::class, 'progresso'],     'tv',         'json'],
        ['POST', 'lista',                   [Uso::class, 'lista'],         'tv',         'json'],
        ['POST', 'nota',                    [Uso::class, 'nota'],          'tv',         'json'],
        ['POST', 'continuar-ocultar',       [Uso::class, 'ocultar'],       'tv',         'json'],
        ['POST', 'versao',                  [Uso::class, 'versao'],        'tv',         'json'],
        ['POST', 'abertura',                [Uso::class, 'abertura'],      'tv',         'json'],
        ['POST', 'preferencia',             [Uso::class, 'preferencia'],   'tv',         'json'],
        ['GET',  'preferencias',            [Uso::class, 'preferencias'],  'tv',         'json'],

        ['GET',  'busca',                   [Busca::class, 'busca'],       'tv',         'json'],
        ['GET',  'pessoa/{tmdb}',           [Busca::class, 'pessoa'],      'tv',         'json'],

        ['GET',  'avisos',                  [Avisos::class, 'listar'],     'tv',         'json'],
        ['POST', 'aviso',                   [Avisos::class, 'criar'],      'tv',         'json'],
        ['POST', 'aviso/{id}/cancelar',     [Avisos::class, 'cancelar'],   'tv',         'json'],
        ['GET',  'legenda/{id}',            [Avisos::class, 'legenda'],    'tv',         'stream'],
        ['GET',  'legendas',                [Avisos::class, 'legendas'],   'tv',         'json'],

        ['POST', 'importar',                [Importar::class, 'rota'],     'tv',         'json'],

        ['GET',  'aovivo/grupos',           [AoVivo::class, 'grupos'],     'tv',         'json'],
        ['GET',  'aovivo/jogos',            [AoVivo::class, 'jogos'],      'tv',         'json'],
        ['GET',  'aovivo/canais',           [AoVivo::class, 'canais'],     'tv',         'json'],
        ['GET',  'aovivo/grade',            [AoVivo::class, 'grade'],      'tv',         'json'],
        ['GET',  'aovivo/tocar/{canal}',    [AoVivo::class, 'tocar'],      'tv',         'json'],
        ['POST', 'aovivo/favorito',         [AoVivo::class, 'favorito'],   'tv',         'json'],

        ['GET',  'sinal',                   [Sinal::class, 'rota'],        'tv',         'stream'],

        ['GET',  'minhas-series',           [Tvs::class, 'minhasSeries'],  'integracao', 'json'],
        ['POST', 'continuar',               [Tvs::class, 'continuar'],     'integracao', 'json'],
    ];

    /** Parâmetros de rota que precisam ser inteiros positivos. */
    private const INTEIROS = ['id', 'tmdb', 'canal'];

    /**
     * Acha a rota. Devolve null (não existe), 'metodo' (existe com outro método) ou
     * ['handler' => [classe, método], 'params' => [...], 'acesso' => ..., 'saida' => ...].
     */
    public static function resolver(string $metodo, string $caminho): array|string|null
    {
        $caminho = (string) preg_replace('#/+#', '/', $caminho);
        if (!str_starts_with($caminho, '/api/play/')) {
            return null;
        }
        $resto = trim(substr($caminho, strlen('/api/play/')), '/');
        if ($resto === '') {
            return null;
        }
        $segs = explode('/', $resto);
        $outroMetodo = false;
        foreach (self::TABELA as [$m, $padrao, $handler, $acesso, $saida]) {
            $params = self::casar(explode('/', $padrao), $segs);
            if ($params === null) {
                continue;
            }
            if ($m !== strtoupper($metodo)) {
                $outroMetodo = true;
                continue;
            }
            return ['handler' => $handler, 'params' => $params, 'acesso' => $acesso, 'saida' => $saida];
        }
        return $outroMetodo ? 'metodo' : null;
    }

    private static function casar(array $padrao, array $segs): ?array
    {
        if (count($padrao) !== count($segs)) {
            return null;
        }
        $params = [];
        foreach ($padrao as $i => $p) {
            $s = rawurldecode($segs[$i]);
            if (preg_match('/^\{(\w+)\}$/', $p, $m)) {
                if ($s === '' || str_contains($s, "\0")) {
                    return null;
                }
                if (in_array($m[1], self::INTEIROS, true) && !preg_match('/^[1-9]\d{0,11}$/', $s)) {
                    return null;
                }
                $params[$m[1]] = $s;
            } elseif ($p !== $s) {
                return null;
            }
        }
        return $params;
    }
}
