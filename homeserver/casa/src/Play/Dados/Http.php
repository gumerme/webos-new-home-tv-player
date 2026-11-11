<?php
declare(strict_types=1);

namespace Casa\Play\Dados;

use RuntimeException;

/**
 * HTTP de saída por streams (a imagem não tem ext curl): gzip, redirecionamento e User-Agent configurável.
 *
 * O User-Agent padrão é o de uma TV webOS: a ESPN responde 403 a navegador headless, e o painel IPTV
 * sempre foi chamado pela TV. Segredos (usuário/senha do painel, tokens) registrados em `segredo()`
 * nunca aparecem nas mensagens de erro.
 */
final class Http
{
    public const UA_TV = 'Mozilla/5.0 (Web0S; Linux/SmartTV) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/94.0.4606.128 Safari/537.36 WebAppManager';

    public static string $agente = self::UA_TV;

    /** @var list<string> */
    private static array $segredos = [];

    /** Texto que nunca deve sair em log/erro (senha do painel, token...). */
    public static function segredo(?string $valor): void
    {
        if ($valor !== null && strlen($valor) >= 3 && !in_array($valor, self::$segredos, true)) {
            self::$segredos[] = $valor;
            self::$segredos[] = rawurlencode($valor);
        }
    }

    /** Tira usuário/senha/token de URLs e mensagens. */
    public static function mascarar(string $texto): string
    {
        $texto = preg_replace('/([?&](?:username|password|api_key|token|t)=)[^&\s"]+/i', '$1***', $texto) ?? $texto;
        $texto = preg_replace('#/(movie|series|live)/[^/\s]+/[^/\s]+/#', '/$1/***/***/', $texto) ?? $texto;
        foreach (self::$segredos as $s) {
            $texto = str_replace($s, '***', $texto);
        }
        return $texto;
    }

    /** @return array{status:int, corpo:string, cab:array<string,string>} */
    public static function get(string $url, array $cab = [], int $timeout = 20): array
    {
        return self::pedir('GET', $url, $cab, null, $timeout);
    }

    /** GET que devolve o JSON decodificado (lança erro se HTTP ≠ 2xx ou JSON inválido). */
    public static function json(string $url, array $cab = [], int $timeout = 20): mixed
    {
        $r = self::pedir('GET', $url, ['Accept' => 'application/json'] + self::cabecalhosAssoc($cab), null, $timeout);
        return self::decodificar($url, $r);
    }

    public static function postJson(string $url, mixed $corpo, array $cab = [], int $timeout = 20): mixed
    {
        $r = self::pedir('POST', $url, ['Accept' => 'application/json', 'Content-Type' => 'application/json'] + self::cabecalhosAssoc($cab),
            json_encode($corpo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $timeout);
        return self::decodificar($url, $r);
    }

    /**
     * Só o status e os cabeçalhos (o corpo não é lido): teste de link com Range sem arriscar
     * baixar o filme inteiro quando o servidor ignora o Range.
     * @return array{status:int, cab:array<string,string>, ms:int}
     */
    public static function cabecalhos(string $url, array $cab = [], int $timeout = 15): array
    {
        $ini = microtime(true);
        $ctx = self::contexto('GET', self::cabecalhosAssoc($cab), null, $timeout, false);
        $f = @fopen($url, 'rb', false, $ctx);
        $linhas = function_exists('http_get_last_response_headers') ? (http_get_last_response_headers() ?? []) : [];
        if ($f) {
            $meta = stream_get_meta_data($f);
            $linhas = $meta['wrapper_data'] ?? $linhas;
            fclose($f);
        }
        if (!$linhas) {
            throw new RuntimeException('sem resposta de ' . self::host($url) . self::motivo());
        }
        [$status, $cabs] = self::lerCabecalhos($linhas);
        return ['status' => $status, 'cab' => $cabs, 'ms' => (int) round((microtime(true) - $ini) * 1000)];
    }

    // ------------------------------------------------------------------ internos

    private static function pedir(string $metodo, string $url, array $cab, ?string $corpo, int $timeout): array
    {
        $cab = self::cabecalhosAssoc($cab);
        $ctx = self::contexto($metodo, $cab, $corpo, $timeout, true);
        $txt = @file_get_contents($url, false, $ctx);
        $linhas = function_exists('http_get_last_response_headers') ? (http_get_last_response_headers() ?? []) : [];
        if ($txt === false && !$linhas) {
            throw new RuntimeException("falha HTTP em " . self::host($url) . self::motivo());
        }
        [$status, $cabs] = self::lerCabecalhos($linhas);
        $txt = (string) $txt;
        if (($cabs['content-encoding'] ?? '') === 'gzip' || str_starts_with($txt, "\x1f\x8b")) {
            $d = @gzdecode($txt);
            if ($d !== false) {
                $txt = $d;
            }
        }
        return ['status' => $status, 'corpo' => $txt, 'cab' => $cabs];
    }

    private static function contexto(string $metodo, array $cab, ?string $corpo, int $timeout, bool $gzip)
    {
        $cab += ['User-Agent' => self::$agente, 'Accept' => '*/*', 'Connection' => 'close'];
        if ($gzip) {
            $cab += ['Accept-Encoding' => 'gzip'];
        }
        $linhas = '';
        foreach ($cab as $k => $v) {
            $linhas .= "$k: $v\r\n";
        }
        $http = [
            'method'           => $metodo,
            'header'           => $linhas,
            'timeout'          => $timeout,
            'follow_location'  => 1,
            'max_redirects'    => 6,
            'ignore_errors'    => true,
            'protocol_version' => 1.1,
        ];
        if ($corpo !== null) {
            $http['content'] = $corpo;
        }
        return stream_context_create([
            'http' => $http,
            'ssl'  => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);
    }

    /** Cabeçalhos da última resposta (depois dos redirecionamentos). */
    private static function lerCabecalhos(array $linhas): array
    {
        $status = 0;
        $cabs = [];
        foreach ($linhas as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) {
                $status = (int) $m[1];
                $cabs = [];                         // novo bloco: só vale o da última resposta
            } elseif (($p = strpos($h, ':')) !== false) {
                $cabs[strtolower(trim(substr($h, 0, $p)))] = trim(substr($h, $p + 1));
            }
        }
        return [$status, $cabs];
    }

    private static function decodificar(string $url, array $r): mixed
    {
        if ($r['status'] < 200 || $r['status'] >= 300) {
            throw new HttpErroStatus($r['status'], 'HTTP ' . $r['status'] . ' em ' . self::host($url) . self::caminho($url), $r['cab']);
        }
        $j = json_decode($r['corpo'], true, 512, JSON_BIGINT_AS_STRING);
        if ($j === null && trim($r['corpo']) !== 'null') {
            throw new RuntimeException('resposta não é JSON em ' . self::host($url) . self::caminho($url));
        }
        return $j;
    }

    private static function cabecalhosAssoc(array $cab): array
    {
        $saida = [];
        foreach ($cab as $k => $v) {
            if (is_int($k)) {
                [$n, $val] = array_map('trim', explode(':', (string) $v, 2)) + [1 => ''];
                $saida[$n] = $val;
            } else {
                $saida[$k] = (string) $v;
            }
        }
        return $saida;
    }

    private static function host(string $url): string
    {
        return (string) (parse_url($url, PHP_URL_HOST) ?: '?');
    }

    private static function caminho(string $url): string
    {
        return self::mascarar((string) parse_url($url, PHP_URL_PATH));
    }

    private static function motivo(): string
    {
        $m = (string) (error_get_last()['message'] ?? '');
        return $m !== '' ? ' — ' . mb_substr(self::mascarar($m), 0, 160) : '';
    }
}

