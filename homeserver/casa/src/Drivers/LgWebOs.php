<?php
declare(strict_types=1);

namespace Casa\Drivers;

use Casa\Dispositivos;
use Casa\Net\WakeOnLan;
use Casa\Net\WebSocket;
use RuntimeException;

/**
 * TVs LG webOS pela API SSAP (a mesma do app do celular).
 * webOS 23+ (TV1, webOS 10) exige wss:3001; webOS 4 (TV2) aceita ws:3000.
 * Ligar = Wake-on-LAN (o MAC é descoberto sozinho na 1ª consulta com a TV ligada).
 *
 * config: ip, mac?, broadcast?, rede (cabo|wifi), client_key (gravado no pareamento)
 */
final class LgWebOs implements Driver
{
    // Manifesto sem assinatura (mesmo formato do app FreeMyTvWebOS, aceito nas duas TVs).
    private const PERMISSOES = [
        'LAUNCH', 'LAUNCH_WEBAPP', 'APP_TO_APP', 'CLOSE', 'CONTROL_AUDIO', 'CONTROL_DISPLAY',
        'CONTROL_POWER', 'CONTROL_INPUT_TV', 'CONTROL_INPUT_MEDIA_PLAYBACK', 'CONTROL_INPUT_JOYSTICK',
        'CONTROL_MOUSE_AND_KEYBOARD', 'READ_APP_STATUS', 'READ_CURRENT_CHANNEL', 'READ_INPUT_DEVICE_LIST',
        'READ_NETWORK_STATE', 'READ_RUNNING_APPS', 'READ_INSTALLED_APPS', 'READ_POWER_STATE',
        'READ_TV_CHANNEL_LIST', 'WRITE_NOTIFICATION_TOAST', 'READ_SETTINGS',
    ];

    private int $seq = 0;

    public function executar(array $d, string $acao, array $p): array
    {
        switch ($acao) {
            case 'parear':
                $this->conectar($d, true)->fechar();
                Dispositivos::mesclarConfig($d['id'], ['precisa_parear' => false]);
                return ['_resposta' => ['pareado' => true]];

            case 'ligar':
                return $this->ligar($d);

            case 'desligar':
                $this->chamarUma($d, 'ssap://system/turnOff');
                return ['ligado' => false];

            case 'volume':
                $v = max(0, min(100, (int) ($p['valor'] ?? -1)));
                $this->chamarUma($d, 'ssap://audio/setVolume', ['volume' => $v]);
                return ['volume' => $v];

            case 'volume_mais':
                $this->chamarUma($d, 'ssap://audio/volumeUp');
                return [];

            case 'volume_menos':
                $this->chamarUma($d, 'ssap://audio/volumeDown');
                return [];

            case 'mudo':
                $m = (bool) ($p['valor'] ?? true);
                $this->chamarUma($d, 'ssap://audio/setMute', ['mute' => $m]);
                return ['mudo' => $m];

            case 'abrir_app':
                $app = (string) ($p['app'] ?? '');
                if ($app === '') {
                    throw new \InvalidArgumentException('parâmetro "app" obrigatório (ex.: netflix, youtube.leanback.v4)');
                }
                // params (objeto) chega ao app como launch params / webOSRelaunch (ex.: Player {"abrir":"continuar"}).
                $launch = ['id' => $app];
                if (!empty($p['params']) && is_array($p['params'])) {
                    $launch['params'] = $p['params'];
                }
                $this->chamarUma($d, 'ssap://system.launcher/launch', $launch);
                return ['app' => $app];

            case 'notificar':
                $this->chamarUma($d, 'ssap://system.notifications/createToast', ['message' => (string) ($p['texto'] ?? '')]);
                return [];

            case 'apps':
                $r = $this->chamarUma($d, 'ssap://com.webos.applicationManager/listLaunchPoints');
                $apps = array_map(fn($a) => ['id' => $a['id'] ?? '', 'nome' => $a['title'] ?? ''], $r['launchPoints'] ?? []);
                return ['_resposta' => ['apps' => $apps]];

            default:
                throw new \InvalidArgumentException("ação '$acao' não existe para TV LG");
        }
    }

    public function consultar(array $d): array
    {
        $ip = $d['config']['ip'] ?? null;
        if (!$ip || !(self::porta($ip, 3001) || self::porta($ip, 3000))) {
            return ['online' => false, 'dados' => ['ligado' => false]];
        }
        // Sem chave (ou chave recusada): só sabemos que está na rede. Não reabre o pedido na tela.
        if (empty($d['config']['client_key']) || !empty($d['config']['precisa_parear'])) {
            return ['online' => true, 'dados' => ['ligado' => true, 'pareada' => false]];
        }
        try {
            $ws = $this->conectar($d);
            $dados = ['ligado' => true, 'pareada' => true, 'erro' => null];

            $pw = $this->tentar($ws, 'ssap://com.webos.service.tvpower/power/getPowerState');
            if (!empty($pw['state'])) {
                $dados['energia'] = $pw['state'];   // Active, Screen Off, Active Standby...
                $dados['ligado'] = in_array($pw['state'], ['Active', 'Screen Saver'], true);
            }
            if ($dados['ligado']) {
                $v = $this->tentar($ws, 'ssap://audio/getVolume');
                if ($v) {
                    $dados['volume'] = (int) ($v['volumeStatus']['volume'] ?? $v['volume'] ?? 0);
                    $dados['mudo'] = (bool) ($v['volumeStatus']['muteStatus'] ?? $v['muted'] ?? false);
                }
                $a = $this->tentar($ws, 'ssap://com.webos.applicationManager/getForegroundAppInfo');
                if ($a) {
                    $dados['app'] = $a['appId'] ?? null;
                }
            }
            if (empty($d['config']['mac'])) {
                $this->aprenderMac($d, $ws);
            }
            $ws->fechar();
            return ['online' => true, 'dados' => $dados];
        } catch (\Throwable $e) {
            return ['online' => true, 'dados' => ['erro' => $e->getMessage()]];
        }
    }

    public function intervalo(array $d): int
    {
        return 15;
    }

    // ---------------------------------------------------------------- internos

    private function ligar(array $d): array
    {
        $ip = $d['config']['ip'] ?? '';
        $mac = $d['config']['mac'] ?? '';
        $feito = false;
        // TV em "tela apagada" ainda responde: acende a tela pelo SSAP.
        if ($ip && !empty($d['config']['client_key']) && (self::porta($ip, 3001) || self::porta($ip, 3000))) {
            try {
                $this->chamarUma($d, 'ssap://com.webos.service.tvpower/power/turnOnScreen');
                $feito = true;
            } catch (\Throwable) {
            }
        }
        if ($mac) {
            WakeOnLan::enviar($mac, $d['config']['broadcast'] ?? '255.255.255.255');
            $feito = true;
        }
        if (!$feito) {
            throw new RuntimeException('MAC da TV ainda desconhecido: ligue pelo controle uma vez para o servidor aprender');
        }
        return ['ligado' => true];
    }

    private function aprenderMac(array $d, WebSocket $ws): void
    {
        $n = $this->tentar($ws, 'ssap://com.webos.service.connectionmanager/getinfo');
        if (!$n) {
            return;
        }
        $ordem = ($d['config']['rede'] ?? 'cabo') === 'wifi' ? ['wifiInfo', 'wiredInfo'] : ['wiredInfo', 'wifiInfo'];
        foreach ($ordem as $k) {
            $mac = $n[$k]['macAddress'] ?? '';
            if (preg_match('/^([0-9a-f]{2}:){5}[0-9a-f]{2}$/i', $mac) && $mac !== '00:00:00:00:00:00') {
                Dispositivos::mesclarConfig($d['id'], ['mac' => strtolower($mac)]);
                return;
            }
        }
    }

    private function conectar(array $d, bool $parear = false): WebSocket
    {
        $ip = $d['config']['ip'] ?? throw new RuntimeException('config.ip vazio');
        $ws = null;
        $erros = [];
        foreach (["wss://$ip:3001", "ws://$ip:3000"] as $url) {
            try {
                $ws = new WebSocket($url, 2.5);
                break;
            } catch (RuntimeException $e) {
                $erros[] = $e->getMessage();
            }
        }
        if (!$ws) {
            throw new RuntimeException('TV fora do ar (' . implode('; ', $erros) . ')');
        }

        $chave = $d['config']['client_key'] ?? null;
        if (!$chave && !$parear) {
            throw new RuntimeException('TV não pareada: envie a ação "parear" com a TV ligada');
        }
        $payload = [
            'forcePairing' => false,
            'pairingType'  => 'PROMPT',
            'manifest'     => ['manifestVersion' => 1, 'appVersion' => '1.0', 'permissions' => self::PERMISSOES],
        ];
        if ($chave && !$parear) {
            $payload['client-key'] = $chave;
        }
        $ws->enviar(json_encode(['id' => 'registro', 'type' => 'register', 'payload' => $payload]));

        $fim = microtime(true) + ($parear ? 60 : 8);
        while (($resta = $fim - microtime(true)) > 0) {
            $txt = $ws->receber($resta);
            if ($txt === null) {
                break;
            }
            $m = json_decode($txt, true) ?: [];
            if (($m['id'] ?? '') !== 'registro') {
                continue;
            }
            $tipo = $m['type'] ?? '';
            if ($tipo === 'registered') {
                $nova = $m['payload']['client-key'] ?? null;
                if ($nova && $nova !== $chave) {
                    Dispositivos::mesclarConfig($d['id'], ['client_key' => $nova, 'precisa_parear' => false]);
                }
                return $ws;
            }
            if ($tipo === 'error') {
                throw new RuntimeException('TV recusou o registro: ' . ($m['error'] ?? 'erro'));
            }
            if (!$parear && ($m['payload']['pairingType'] ?? '') === 'PROMPT') {
                // A chave guardada não vale mais: não deixa o pedido aberto na tela.
                $ws->fechar();
                Dispositivos::mesclarConfig($d['id'], ['precisa_parear' => true]);
                throw new RuntimeException('a TV não aceitou a chave salva: envie "parear" de novo');
            }
        }
        throw new RuntimeException($parear ? 'ninguém aceitou o pedido na TV em 60 s' : 'TV não confirmou o registro');
    }

    private function chamar(WebSocket $ws, string $uri, array $payload = []): array
    {
        $id = 'r' . (++$this->seq);
        $msg = ['id' => $id, 'type' => 'request', 'uri' => $uri];
        if ($payload) {
            $msg['payload'] = $payload;
        }
        $ws->enviar(json_encode($msg));
        $fim = microtime(true) + 5;
        while (($resta = $fim - microtime(true)) > 0) {
            $txt = $ws->receber($resta);
            if ($txt === null) {
                break;
            }
            $m = json_decode($txt, true) ?: [];
            if (($m['id'] ?? '') !== $id) {
                continue;
            }
            if (($m['type'] ?? '') === 'error' || (isset($m['payload']['returnValue']) && $m['payload']['returnValue'] === false)) {
                throw new RuntimeException("$uri: " . ($m['error'] ?? $m['payload']['errorText'] ?? 'erro'));
            }
            return $m['payload'] ?? [];
        }
        throw new RuntimeException("$uri: sem resposta da TV");
    }

    private function chamarUma(array $d, string $uri, array $payload = []): array
    {
        $ws = $this->conectar($d);
        try {
            return $this->chamar($ws, $uri, $payload);
        } finally {
            $ws->fechar();
        }
    }

    private function tentar(WebSocket $ws, string $uri): ?array
    {
        try {
            return $this->chamar($ws, $uri);
        } catch (\Throwable) {
            return null;
        }
    }

    private static function porta(string $ip, int $porta): bool
    {
        $s = @fsockopen($ip, $porta, $e, $m, 0.8);
        if ($s) {
            fclose($s);
            return true;
        }
        return false;
    }
}
