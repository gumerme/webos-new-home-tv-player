<?php
declare(strict_types=1);

namespace Casa\Play\Api;

/**
 * GET img/{w185|w342|w780|w1280|original}/<arquivo TMDB> e img/painel/<hash>?u=<url base64url>.
 * Baixa uma vez para casa/var/play/img/ e serve do disco com cache longo e ETag (sem token: só imagens públicas).
 * Imagem do painel só é baixada se o hash for a assinatura (HMAC) da URL — ninguém usa o servidor como proxy.
 */
final class Imagens
{
    private const MAX = 6 * 1024 * 1024;
    private const TIPOS = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp',
                           'svg' => 'image/svg+xml', 'gif' => 'image/gif'];

    public static function rota(Pedido $p): void
    {
        $alvo = self::validar((string) ($p->rota['tam'] ?? ''), (string) ($p->rota['arquivo'] ?? ''));
        if ($alvo === null) {
            throw new Erro('imagem inválida', 404);
        }
        $arq = Base::varDir('img/' . $alvo['pasta']) . '/' . $alvo['nome'];
        if (!is_file($arq)) {
            $origem = $alvo['tipo'] === 'tmdb'
                ? 'https://image.tmdb.org/t/p/' . $alvo['pasta'] . '/' . $alvo['nome']
                : self::origemPainel($alvo['nome'], $p->str('u'));
            if ($origem === null || !self::baixar($origem, $arq)) {
                header('Cache-Control: public, max-age=600');
                throw new Erro('imagem indisponível', 404);
            }
        }
        self::servir($arq, $alvo['tipo'] === 'tmdb' ? (self::TIPOS[strtolower(pathinfo($alvo['nome'], PATHINFO_EXTENSION))] ?? null) : null);
    }

    /**
     * Valida tamanho e nome (sem travessia de pasta). Puro: usado nos testes.
     * @return array{tipo:string,pasta:string,nome:string}|null
     */
    public static function validar(string $tam, string $arquivo): ?array
    {
        if ($tam === 'painel') {
            return preg_match('/^[0-9a-f]{40}$/', $arquivo) ? ['tipo' => 'painel', 'pasta' => 'painel', 'nome' => $arquivo] : null;
        }
        if (!in_array($tam, Base::TAMANHOS, true)) {
            return null;
        }
        if (!preg_match('/^[A-Za-z0-9_-]{1,80}\.(jpg|jpeg|png|webp|svg)$/i', $arquivo)) {
            return null;
        }
        return ['tipo' => 'tmdb', 'pasta' => $tam, 'nome' => $arquivo];
    }

    /** URL de origem de uma imagem do painel, conferindo a assinatura. */
    public static function origemPainel(string $hash, string $u): ?string
    {
        if ($u === '' || strlen($u) > 1400 || !preg_match('/^[A-Za-z0-9_-]+$/', $u)) {
            return null;
        }
        $url = base64_decode(strtr($u, '-_', '+/'), true);
        if (!is_string($url) || !preg_match('#^https?://[^\s/]+/\S*$#i', $url) || !hash_equals(Base::assinar($url), $hash)) {
            return null;
        }
        return $url;
    }

    /** Tipo pelo conteúdo (painel não tem extensão confiável). */
    public static function tipoPorConteudo(string $b): ?string
    {
        return match (true) {
            str_starts_with($b, "\xFF\xD8\xFF")                                 => 'image/jpeg',
            str_starts_with($b, "\x89PNG\r\n\x1A\n")                            => 'image/png',
            str_starts_with($b, 'GIF8')                                          => 'image/gif',
            str_starts_with($b, 'RIFF') && substr($b, 8, 4) === 'WEBP'          => 'image/webp',
            (bool) preg_match('/^\s*(<\?xml[^>]*>\s*)?(<!--.*?-->\s*)*<svg[\s>]/is', substr($b, 0, 1024)) => 'image/svg+xml',
            default                                                             => null,
        };
    }

    private static function baixar(string $url, string $destino): bool
    {
        $falhou = $destino . '.falhou';
        if (is_file($falhou) && filemtime($falhou) > time() - 3600) {
            return false;   // não insiste em imagem quebrada a cada cartão
        }
        [$st, $corpo] = Base::httpGet($url, ['Accept: image/*'], 8, self::MAX);
        if ($st !== 200 || $corpo === '' || self::tipoPorConteudo($corpo) === null) {
            @touch($falhou);
            return false;
        }
        $tmp = $destino . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tmp, $corpo) === false || !@rename($tmp, $destino)) {
            @unlink($tmp);
            return false;
        }
        @unlink($falhou);
        return true;
    }

    private static function servir(string $arq, ?string $tipo): void
    {
        $tam = (int) filesize($arq);
        $mtime = (int) filemtime($arq);
        $etag = '"' . dechex($mtime) . '-' . dechex($tam) . '"';
        if ($tipo === null) {
            $h = fopen($arq, 'rb');
            $tipo = self::tipoPorConteudo((string) fread($h, 1024)) ?? 'application/octet-stream';
            fclose($h);
        }
        header('Cache-Control: public, max-age=2592000, immutable');
        header('ETag: ' . $etag);
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
        if ($tipo === 'image/svg+xml') {
            header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; sandbox");
        }
        $inm = (string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '');
        if ($inm !== '' && in_array($etag, array_map('trim', explode(',', $inm)), true)) {
            http_response_code(304);
            return;
        }
        header('Content-Type: ' . $tipo);
        header('Content-Length: ' . $tam);
        readfile($arq);
    }
}
