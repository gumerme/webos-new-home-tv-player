<?php
declare(strict_types=1);

namespace Casa\Net;

use RuntimeException;

/** Cliente WebSocket mínimo (texto, ws:// e wss:// com certificado autoassinado). */
final class WebSocket
{
    /** @var resource */
    private $s;

    /**
     * @param bool  $verificarTls true para servidores da internet (certificado válido obrigatório);
     *                            false só para aparelhos da rede local com certificado autoassinado (TVs).
     * @param array $cabecalhos   cabeçalhos extras do handshake ("Nome" => "valor")
     */
    public function __construct(string $url, float $timeout = 3.0, bool $verificarTls = false, array $cabecalhos = [])
    {
        $p = parse_url($url);
        if (!$p || empty($p['host']) || !in_array($p['scheme'] ?? '', ['ws', 'wss'], true)) {
            throw new RuntimeException("URL inválida: $url");
        }
        $tls = $p['scheme'] === 'wss';
        $porta = $p['port'] ?? ($tls ? 443 : 80);
        $caminho = ($p['path'] ?? '/') . (isset($p['query']) ? '?' . $p['query'] : '');
        $ctx = stream_context_create(['ssl' => $verificarTls
            ? ['verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => $p['host'], 'SNI_enabled' => true]
            : ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true]]);
        $s = @stream_socket_client(($tls ? 'tls' : 'tcp') . "://{$p['host']}:$porta", $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $ctx);
        if (!$s) {
            throw new RuntimeException("sem conexão com $url: $errstr");
        }
        $this->s = $s;
        $this->prazo($timeout);

        $chave = base64_encode(random_bytes(16));
        $extra = '';
        foreach ($cabecalhos as $nome => $valor) {
            $extra .= preg_replace('/[\r\n:]/', '', (string) $nome) . ': ' . preg_replace('/[\r\n]/', '', (string) $valor) . "\r\n";
        }
        $host = ($tls && $porta === 443) || (!$tls && $porta === 80) ? $p['host'] : "{$p['host']}:$porta";
        fwrite($s, "GET $caminho HTTP/1.1\r\nHost: $host\r\nUpgrade: websocket\r\nConnection: Upgrade\r\n"
            . "Sec-WebSocket-Key: $chave\r\nSec-WebSocket-Version: 13\r\n$extra\r\n");
        $cab = '';
        while (!str_contains($cab, "\r\n\r\n")) {
            $linha = fgets($s);
            if ($linha === false) {
                throw new RuntimeException("handshake sem resposta: $url");
            }
            $cab .= $linha;
        }
        if (!preg_match('#^HTTP/1\.[01] 101#', $cab)) {
            throw new RuntimeException('handshake recusado: ' . strtok($cab, "\r\n"));
        }
    }

    public function enviar(string $texto): void
    {
        $len = strlen($texto);
        $cab = chr(0x81);
        if ($len < 126) {
            $cab .= chr(0x80 | $len);
        } elseif ($len < 65536) {
            $cab .= chr(0x80 | 126) . pack('n', $len);
        } else {
            $cab .= chr(0x80 | 127) . pack('J', $len);
        }
        $mascara = random_bytes(4);
        $dados = '';
        for ($i = 0; $i < $len; $i++) {
            $dados .= $texto[$i] ^ $mascara[$i % 4];
        }
        $this->escrever($cab . $mascara . $dados);
    }

    /** Próxima mensagem de texto, ou null se o tempo acabar. */
    public function receber(float $timeout): ?string
    {
        $fim = microtime(true) + $timeout;
        $msg = '';
        while (true) {
            $resta = $fim - microtime(true);
            if ($resta <= 0) {
                return null;
            }
            $this->prazo($resta);
            $b = $this->ler(2);
            if ($b === null) {
                return null;
            }
            $fin = (ord($b[0]) & 0x80) !== 0;
            $op = ord($b[0]) & 0x0F;
            $len = ord($b[1]) & 0x7F;
            $mascarado = (ord($b[1]) & 0x80) !== 0;
            if ($len === 126) {
                $len = unpack('n', $this->lerOuFalhar(2))[1];
            } elseif ($len === 127) {
                $len = unpack('J', $this->lerOuFalhar(8))[1];
            }
            $mascara = $mascarado ? $this->lerOuFalhar(4) : '';
            $dados = $len > 0 ? $this->lerOuFalhar($len) : '';
            if ($mascarado) {
                for ($i = 0; $i < $len; $i++) {
                    $dados[$i] = $dados[$i] ^ $mascara[$i % 4];
                }
            }
            if ($op === 0x8) {
                throw new RuntimeException('conexão fechada pelo aparelho');
            }
            if ($op === 0x9) {                       // ping -> pong
                $this->escrever(chr(0x8A) . chr(0x80) . "\0\0\0\0");
                continue;
            }
            if ($op === 0xA) {
                continue;
            }
            $msg .= $dados;
            if ($fin) {
                return $msg;
            }
        }
    }

    /** Frame de ping (alguns servidores exigem ping periódico para manter a sessão). */
    public function ping(): void
    {
        $this->escrever(chr(0x89) . chr(0x80) . random_bytes(4));
    }

    public function fechar(): void
    {
        if (is_resource($this->s)) {
            @fwrite($this->s, chr(0x88) . chr(0x80) . "\0\0\0\0");
            @fclose($this->s);
        }
    }

    public function __destruct()
    {
        $this->fechar();
    }

    private function prazo(float $seg): void
    {
        $seg = max(0.05, $seg);
        stream_set_timeout($this->s, (int) $seg, (int) (($seg - (int) $seg) * 1e6));
    }

    private function escrever(string $bytes): void
    {
        $total = strlen($bytes);
        for ($feito = 0; $feito < $total;) {
            $n = fwrite($this->s, substr($bytes, $feito));
            if ($n === false || $n === 0) {
                throw new RuntimeException('falha ao enviar');
            }
            $feito += $n;
        }
    }

    /** Lê exatamente $n bytes; null se der timeout antes do primeiro byte. */
    private function ler(int $n): ?string
    {
        $buf = '';
        while (strlen($buf) < $n) {
            $parte = fread($this->s, $n - strlen($buf));
            if ($parte === false || $parte === '') {
                if (stream_get_meta_data($this->s)['timed_out'] && $buf === '') {
                    return null;
                }
                if (feof($this->s)) {
                    throw new RuntimeException('conexão encerrada');
                }
                if (stream_get_meta_data($this->s)['timed_out']) {
                    throw new RuntimeException('tempo esgotado no meio da mensagem');
                }
                continue;
            }
            $buf .= $parte;
        }
        return $buf;
    }

    private function lerOuFalhar(int $n): string
    {
        $this->prazo(5);
        return $this->ler($n) ?? throw new RuntimeException('tempo esgotado lendo mensagem');
    }
}
