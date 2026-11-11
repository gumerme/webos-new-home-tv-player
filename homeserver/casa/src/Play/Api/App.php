<?php
declare(strict_types=1);

namespace Casa\Play\Api;

/**
 * Distribuição do app da TV (org.webostv.play) pelo homeserver.
 *
 * Publicar: `docker exec casa-play php bin/play.php app-publicar var/play/app/entrada/<pacote>.ipk [--notas "..."]`
 * (ou scripts/publicar-player.sh do PC). O pacote vai para casa/var/play/app/ e atual.json aponta para ele.
 * Cada TV roda casa/tv/atualizador.sh (root, Homebrew): a cada 5 min chama GET app/versao e, se a versão
 * publicada for diferente da instalada (vale para voltar versão), baixa app/pacote/<arquivo>, confere o md5 e
 * instala com o Player fechado. O próprio atualizador é servido em app/atualizador e se troca pelo md5.
 *
 * Rotas públicas (nada secreto: o ipk não leva credenciais; a casa só é alcançável pela LAN/Tailscale).
 */
final class App
{
    public const PACOTE = 'org.webostv.play';

    /** GET app/versao?versao=&atualizador= -> {versao, arquivo, md5, tamanho, publicado_em, notas, atualizador_md5} */
    public static function versao(Pedido $p): array
    {
        self::registrar($p->str('versao'), $p->str('atualizador'));
        $atual = self::atual();
        return ($atual ?? ['versao' => null]) + ['atualizador_md5' => self::md5Atualizador()];
    }

    /** GET app/pacote/{arquivo} -> o .ipk */
    public static function pacote(Pedido $p): void
    {
        $nome = (string) ($p->rota['arquivo'] ?? '');
        if (!preg_match('/^[A-Za-z0-9._-]+\.ipk$/', $nome) || !is_file($arq = self::dir() . '/' . $nome)) {
            throw new Erro('pacote não encontrado', 404);
        }
        header('Content-Type: application/vnd.debian.binary-package');
        header('Content-Length: ' . filesize($arq));
        header('Cache-Control: no-store');
        readfile($arq);
    }

    /** GET app/atualizador -> o script da TV; ?init=1 -> o script de boot (init.d/62-webostv-play-update) */
    public static function atualizador(Pedido $p): void
    {
        $arq = $p->bool('init') ? dirname(self::arqAtualizador()) . '/62-webostv-play-update' : self::arqAtualizador();
        if (!is_file($arq)) {
            throw new Erro('atualizador não encontrado', 404);
        }
        header('Content-Type: text/x-sh; charset=utf-8');
        header('Content-Length: ' . filesize($arq));
        header('Cache-Control: no-store');
        readfile($arq);
    }

    // ------------------------------------------------------------ publicação (CLI)

    /** Copia o ipk para var/play/app e o torna a versão atual. Devolve atual.json. */
    public static function publicar(string $ipk, string $notas = ''): array
    {
        $info = self::lerIpk((string) @file_get_contents($ipk));
        if ($info['pacote'] !== self::PACOTE) {
            throw new \RuntimeException("pacote {$info['pacote']} não é " . self::PACOTE);
        }
        $nome = self::PACOTE . '_' . $info['versao'] . '_all.ipk';
        $dest = self::dir() . '/' . $nome;
        if (realpath($ipk) !== realpath($dest) && !copy($ipk, $dest)) {
            throw new \RuntimeException("não consegui copiar para $dest");
        }
        $atual = [
            'versao'       => $info['versao'],
            'arquivo'      => $nome,
            'md5'          => md5_file($dest),
            'tamanho'      => filesize($dest),
            'publicado_em' => date('c'),
            'notas'        => $notas,
        ];
        file_put_contents(self::dir() . '/atual.json.tmp', json_encode($atual, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        rename(self::dir() . '/atual.json.tmp', self::dir() . '/atual.json');
        return $atual;
    }

    /** Versões guardadas (para voltar uma versão: publicar o arquivo antigo de novo). */
    public static function guardadas(): array
    {
        $l = glob(self::dir() . '/' . self::PACOTE . '_*_all.ipk') ?: [];
        rsort($l, SORT_NATURAL);
        return array_map('basename', $l);
    }

    public static function atual(): ?array
    {
        $j = @file_get_contents(self::dir() . '/atual.json');
        $d = $j ? json_decode($j, true) : null;
        return is_array($d) && !empty($d['versao']) ? $d : null;
    }

    /**
     * Lê Package e Version do control de um .ipk (ar → control.tar.gz → ./control). Função pura.
     * @return array{pacote:string, versao:string}
     */
    public static function lerIpk(string $bin): array
    {
        if (!str_starts_with($bin, "!<arch>\n")) {
            throw new \RuntimeException('não é um .ipk (ar)');
        }
        $pos = 8;
        $control = null;
        while ($pos + 60 <= strlen($bin)) {
            $nome = rtrim(substr($bin, $pos, 16), " /");
            $tam = (int) trim(substr($bin, $pos + 48, 10));
            if ($nome === 'control.tar.gz') {
                $control = substr($bin, $pos + 60, $tam);
                break;
            }
            $pos += 60 + $tam + ($tam % 2);
        }
        $tar = $control !== null ? @gzdecode($control) : false;
        if (!is_string($tar)) {
            throw new \RuntimeException('control.tar.gz ausente ou inválido');
        }
        for ($o = 0; $o + 512 <= strlen($tar); ) {
            $n = rtrim(substr($tar, $o, 100), "\0");
            if ($n === '') {
                break;
            }
            $t = (int) octdec(trim(substr($tar, $o + 124, 12), "\0 "));
            if (preg_replace('#^\./#', '', $n) === 'control') {
                $txt = substr($tar, $o + 512, $t);
                preg_match('/^Package:\s*(\S+)/m', $txt, $pk);
                preg_match('/^Version:\s*(\S+)/m', $txt, $vs);
                if (empty($pk[1]) || empty($vs[1])) {
                    break;
                }
                return ['pacote' => $pk[1], 'versao' => $vs[1]];
            }
            $o += 512 + (int) ceil($t / 512) * 512;
        }
        throw new \RuntimeException('control sem Package/Version');
    }

    // ------------------------------------------------------------ interno

    /** A TV que perguntou (pelo IP) fica com a versão instalada registrada (painel › Listas e TVs). */
    private static function registrar(string $versao, string $md5): void
    {
        if (!preg_match('/^[0-9A-Za-z.+~-]{1,20}$/', $versao)) {
            return;
        }
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        try {
            Base::exec('update play.tv set versao_app = ? where ip = ? and versao_app is distinct from ?', [$versao, $ip, $versao]);
        } catch (\Throwable) {
        }
        @file_put_contents(self::dir() . '/tvs.json', json_encode(
            [$ip => ['versao' => $versao, 'atualizador' => substr($md5, 0, 32), 'em' => date('c')]] + self::tvs(),
            JSON_UNESCAPED_SLASHES));
    }

    /** Última consulta de cada TV (ip => {versao, atualizador, em}). */
    public static function tvs(): array
    {
        $d = json_decode((string) @file_get_contents(self::dir() . '/tvs.json'), true);
        return is_array($d) ? $d : [];
    }

    private static function dir(): string
    {
        $d = Base::varDir('app');
        if (is_writable($d) && (fileperms($d) & 0777) !== 0777) {
            @chmod($d, 0777);          // CLI (root) cria; o casa-web (www-data) grava tvs.json
        }
        return $d;
    }

    private static function arqAtualizador(): string
    {
        return dirname(__DIR__, 3) . '/tv/atualizador.sh';
    }

    public static function md5Atualizador(): ?string
    {
        $a = self::arqAtualizador();
        return is_file($a) ? md5_file($a) : null;
    }
}
