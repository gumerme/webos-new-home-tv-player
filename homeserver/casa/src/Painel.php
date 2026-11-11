<?php
declare(strict_types=1);

namespace Casa;

use Casa\Drivers\Registro;
use Casa\Play\Api\PainelPlayer;

/** Painel de gestão (páginas HTML com sessão). */
final class Painel
{
    public const TIPOS = ['tv' => 'TV', 'alimentador' => 'Alimentador de pets', 'tomada' => 'Tomada', 'interruptor' => 'Interruptor', 'lampada' => 'Lâmpada', 'pc' => 'Computador'];

    public static function atender(string $metodo, string $caminho): void
    {
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: same-origin');
        Auth::iniciarSessao();
        try {
            if ($caminho === '/login') {
                self::login($metodo);
                return;
            }
            $u = Auth::usuario();
            if (!$u) {
                self::redirecionar('/login' . ($caminho !== '/' ? '?volta=' . rawurlencode($caminho) : ''));
                return;
            }
            if ($metodo === 'POST') {
                Auth::validarCsrf($_POST['csrf'] ?? null);
            }
            if ($caminho === '/sair') {
                if ($metodo === 'POST') {
                    Auth::sair();
                }
                self::redirecionar('/login');
                return;
            }
            if ($u['trocar_senha'] && $caminho !== '/conta') {
                Auth::flash('Defina uma nova senha para continuar.', 'aviso');
                self::redirecionar('/conta');
                return;
            }
            match ($caminho) {
                '/'                 => View::pagina('Painel', 'painel', 'dashboard', ['info' => Metricas::info(), 'subtitulo' => self::saudacao($u)]),
                '/painel/dados'     => self::dados(),
                '/aparelhos'        => View::pagina('Aparelhos', 'aparelhos', 'aparelhos', ['lista' => Dispositivos::listar(false, false), 'subtitulo' => 'Controle e situação de cada aparelho da casa']),
                '/aparelhos/editar' => self::aparelhoEditar($metodo),
                '/comandos'         => self::redirecionar('/atividade'),
                '/atividade'        => self::atividade(),
                '/rede'             => self::rede($metodo),
                '/usuarios'         => self::usuarios(),
                '/usuarios/editar'  => self::usuarioEditar($metodo),
                '/integracoes'      => self::integracoes($metodo),
                '/conta'            => self::conta($metodo),
                '/player', '/player/listas', '/player/perfis', '/player/estatisticas', '/player/catalogo',
                '/player/plataformas', '/player/avisos', '/player/tarefas'
                                    => PainelPlayer::atender($metodo, $caminho),
                default             => throw new HttpErro('Página não encontrada.', 404),
            };
        } catch (HttpErro $e) {
            http_response_code($e->getCode() ?: 500);
            if (Auth::usuario()) {
                View::pagina('Ops', '', 'erro', ['mensagem' => $e->getMessage(), 'codigo' => $e->getCode()]);
            } else {
                echo e($e->getMessage());
            }
        }
    }

    // ---------------------------------------------------------------- login

    private static function login(string $metodo): void
    {
        if (Auth::usuario()) {
            self::redirecionar('/');
            return;
        }
        $erro = null;
        $login = '';
        if ($metodo === 'POST') {
            try {
                Auth::validarCsrf($_POST['csrf'] ?? null);
                $login = (string) ($_POST['login'] ?? '');
                $r = Auth::entrar($login, (string) ($_POST['senha'] ?? ''), Auth::ip());
                if (is_array($r)) {
                    $volta = (string) ($_GET['volta'] ?? '/');
                    if (!str_starts_with($volta, '/') || str_starts_with($volta, '//')) {
                        $volta = '/';
                    }
                    self::redirecionar($volta);
                    return;
                }
                $erro = $r;
            } catch (HttpErro $e) {
                $erro = $e->getMessage();
            }
        }
        echo View::render('login', ['erro' => $erro, 'login' => $login, 'semUsuarios' => Usuarios::contar() === 0]);
    }

    // ------------------------------------------------------------ dashboard

    private static function dados(): void
    {
        session_write_close();
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        $aparelhos = array_map(fn($d) => [
            'slug'    => $d['slug'],
            'nome'    => $d['nome'],
            'tipo'    => $d['tipo'],
            'comodo'  => $d['comodo'],
            'ativo'   => $d['ativo'],
            'online'  => $d['online'],
            'ligado'  => (bool) ($d['dados']['ligado'] ?? false),
            'app'     => $d['dados']['app'] ?? null,
            'volume'  => $d['dados']['volume'] ?? null,
            'erro'    => $d['dados']['erro'] ?? null,
            'pareada' => $d['dados']['pareada'] ?? null,
        ], Dispositivos::listar(false, false));
        $periodos = [6 => 2, 24 => 5, 168 => 30];   // horas => minutos por ponto
        $h = (int) ($_GET['h'] ?? 24);
        $h = isset($periodos[$h]) ? $h : 24;
        $host = Host::ler();
        $atual = Metricas::atual();
        echo json_encode([
            'info'      => Metricas::info(),
            'atual'     => $atual,
            'host'      => $host,
            'alertas'   => self::alertas($host, $atual),
            'disponibilidade' => Host::disponibilidade(),
            'atividade' => Eventos::linhaDoTempo(12),
            'periodo_h' => $h,
            'serie'     => Metricas::serie($h, $periodos[$h]),
            'serie_rede'=> Host::serieRede($h, $periodos[$h]),
            'energia'   => Metricas::energiaResumo(),
            'servicos'  => Servicos::status(),
            'aparelhos' => $aparelhos,
            'comandos'  => array_map(fn($c) => $c + ['quando' => View::dataHora($c['criado_em'])], Comandos::recentes(8)),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    // ------------------------------------------------------------ aparelhos

    private static function aparelhoEditar(string $metodo): void
    {
        Auth::exigirAdmin();
        $slug = (string) ($_GET['slug'] ?? '');
        $d = $slug !== '' ? Dispositivos::porSlug($slug) : null;
        if ($slug !== '' && !$d) {
            throw new HttpErro('Aparelho não encontrado.', 404);
        }
        $erro = null;
        $form = $d ?? ['slug' => '', 'nome' => '', 'tipo' => 'tv', 'driver' => 'lgwebos', 'comodo' => '', 'config' => [], 'homekit' => true, 'ativo' => true];
        $configTxt = json_encode(Dispositivos::publico($form)['config'] ?: new \stdClass(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($metodo === 'POST') {
            $configTxt = (string) ($_POST['config'] ?? '{}');
            $form = [
                'slug'    => $d['slug'] ?? strtolower(trim((string) ($_POST['slug'] ?? ''))),
                'nome'    => trim((string) ($_POST['nome'] ?? '')),
                'tipo'    => (string) ($_POST['tipo'] ?? ''),
                'driver'  => (string) ($_POST['driver'] ?? ''),
                'comodo'  => trim((string) ($_POST['comodo'] ?? '')) ?: null,
                'homekit' => !empty($_POST['homekit']),
                'ativo'   => !empty($_POST['ativo']),
            ];
            try {
                $config = json_decode(trim($configTxt) === '' ? '{}' : $configTxt, true, 32, JSON_THROW_ON_ERROR);
                if (!is_array($config)) {
                    throw new \InvalidArgumentException('Configuração precisa ser um objeto JSON.');
                }
                if (!in_array($form['driver'], Registro::nomes(), true)) {
                    throw new \InvalidArgumentException('Driver inválido.');
                }
                if (!isset(self::TIPOS[$form['tipo']])) {
                    throw new \InvalidArgumentException('Tipo inválido.');
                }
                Dispositivos::salvar($form + ['config' => $config]);
                Auth::flash("Aparelho \"{$form['nome']}\" salvo.");
                self::redirecionar('/aparelhos');
                return;
            } catch (\JsonException) {
                $erro = 'Configuração: JSON inválido.';
            } catch (\InvalidArgumentException $e) {
                $erro = $e->getMessage();
            }
            $form['config'] = [];
        }
        View::pagina($d ? 'Editar aparelho' : 'Novo aparelho', 'aparelhos', 'aparelho_form', [
            'd' => $d, 'form' => $form, 'configTxt' => $configTxt, 'erro' => $erro,
            'tipos' => self::TIPOS, 'drivers' => Registro::nomes(),
        ]);
    }

    // ------------------------------------------------------------- usuários

    private static function usuarios(): void
    {
        Auth::exigirAdmin();
        View::pagina('Usuários', 'usuarios', 'usuarios', ['lista' => Usuarios::listar(), 'subtitulo' => 'Quem pode entrar no painel e o que cada um pode fazer']);
    }

    private static function usuarioEditar(string $metodo): void
    {
        Auth::exigirAdmin();
        $id = isset($_GET['id']) ? (int) $_GET['id'] : null;
        $alvo = $id ? Usuarios::porId($id) : null;
        if ($id && !$alvo) {
            throw new HttpErro('Usuário não encontrado.', 404);
        }
        $eu = Auth::usuario();
        $erro = null;
        $form = $alvo ?? ['nome' => '', 'login' => '', 'perfil' => 'morador', 'ativo' => true, 'trocar_senha' => true];

        if ($metodo === 'POST') {
            $form = [
                'nome'         => (string) ($_POST['nome'] ?? ''),
                'login'        => (string) ($_POST['login'] ?? ''),
                'perfil'       => (string) ($_POST['perfil'] ?? 'morador'),
                'senha'        => (string) ($_POST['senha'] ?? ''),
                'ativo'        => !empty($_POST['ativo']),
                'trocar_senha' => !empty($_POST['trocar_senha']),
            ];
            try {
                if ($alvo && $alvo['id'] === $eu['id'] && !$form['ativo']) {
                    throw new \InvalidArgumentException('Você não pode desativar a si mesmo.');
                }
                $deixaDeSerAdmin = $alvo && $alvo['perfil'] === 'admin' && $alvo['ativo'] && ($form['perfil'] !== 'admin' || !$form['ativo']);
                if ($deixaDeSerAdmin && Usuarios::adminsAtivos($alvo['id']) === 0) {
                    throw new \InvalidArgumentException('Precisa existir pelo menos um administrador ativo.');
                }
                Usuarios::salvar($alvo['id'] ?? null, $form);
                Eventos::registrar('usuario', 'info', ($alvo ? 'Usuário alterado: ' : 'Usuário criado: ') . $form['login'],
                    'perfil ' . $form['perfil'] . ($form['ativo'] ? '' : ', inativo') . ($form['senha'] !== '' ? ', senha definida' : ''), 'painel:' . $eu['login']);
                Auth::flash("Usuário \"{$form['nome']}\" salvo.");
                self::redirecionar('/usuarios');
                return;
            } catch (\InvalidArgumentException $e) {
                $erro = $e->getMessage();
            }
        }
        View::pagina($alvo ? 'Editar usuário' : 'Novo usuário', 'usuarios', 'usuario_form', [
            'alvo' => $alvo, 'form' => $form, 'erro' => $erro, 'perfis' => Usuarios::PERFIS,
        ]);
    }

    // ---------------------------------------------------------- integrações

    private static function integracoes(string $metodo): void
    {
        Auth::exigirAdmin();
        $erroTarifa = null;
        if ($metodo === 'POST' && ($_POST['acao'] ?? '') === 'tarifa') {
            $quem = Auth::usuario()['login'];
            $num = static function (string $campo): ?float {
                $t = trim(str_replace(['R$', '%', ' '], '', (string) ($_POST[$campo] ?? '')));
                $t = str_contains($t, ',') ? str_replace(['.', ','], ['', '.'], $t) : $t;
                return $t === '' ? null : (is_numeric($t) ? (float) $t : NAN);
            };
            $tarifa = $num('tarifa');
            $fixo = $num('fixo');
            $ef = $num('eficiencia');
            if ($tarifa !== null && (is_nan($tarifa) || $tarifa <= 0 || $tarifa >= 10)) {
                $erroTarifa = 'Tarifa inválida. Use o preço do kWh em reais, ex.: 1,21.';
            } elseif (empty($_POST['padrao']) && (($fixo !== null && (is_nan($fixo) || $fixo < 0 || $fixo > 200)) || ($ef !== null && (is_nan($ef) || $ef < 30 || $ef > 100)))) {
                $erroTarifa = 'Correção inválida: resto fixo entre 0 e 200 W, eficiência entre 30% e 100%.';
            } else {
                Config::set('tarifa_kwh', $tarifa === null ? null : (string) $tarifa, $quem);
                if (!empty($_POST['padrao'])) {
                    Config::set('energia_fixo_w', null, $quem);
                    Config::set('energia_eficiencia', null, $quem);
                    Config::set('energia_correcao_origem', 'classe', $quem);
                } else {
                    $atual = Config::energiaCorrecao();
                    $fixo ??= $atual['fixo_w'];
                    $ef = $ef !== null ? $ef / 100 : $atual['eficiencia'];
                    if (abs($fixo - $atual['fixo_w']) > 0.001 || abs($ef - $atual['eficiencia']) > 0.0001) {
                        Config::set('energia_fixo_w', (string) $fixo, $quem);
                        Config::set('energia_eficiencia', (string) $ef, $quem);
                        Config::set('energia_correcao_origem', 'manual', $quem);
                    }
                }
                Eventos::registrar('config', 'info', 'Configuração de energia alterada',
                    'tarifa ' . ($tarifa === null ? 'removida' : 'R$ ' . number_format($tarifa, 4, ',', '') . '/kWh'), 'painel:' . $quem);
                Auth::flash('Energia salva.');
                self::redirecionar('/');
                return;
            }
        }
        View::pagina('Integrações', 'integracoes', 'integracoes', [
            'subtitulo'  => 'Apple Home, API, energia e acesso administrativo',
            'token'      => (string) getenv('CASA_TOKEN'),
            'pin'        => (string) getenv('HOMEBRIDGE_PIN'),
            'ip'         => Metricas::info()['ip'] ?? 'homeserver',
            'tarifa'     => Config::tarifaKwh(),
            'tarifaNota' => Config::get('tarifa_nota'),
            'correcao'   => Config::energiaCorrecao(),
            'energia'    => Metricas::energiaResumo(),
            'erroTarifa' => $erroTarifa,
        ]);
    }

    // ---------------------------------------------------------- minha conta

    private static function conta(string $metodo): void
    {
        $u = Auth::usuario();
        $erro = null;
        if ($metodo === 'POST') {
            try {
                if (!password_verify((string) ($_POST['atual'] ?? ''), $u['senha_hash'])) {
                    throw new \InvalidArgumentException('Senha atual incorreta.');
                }
                $nova = (string) ($_POST['nova'] ?? '');
                if ($nova !== (string) ($_POST['confirmar'] ?? '')) {
                    throw new \InvalidArgumentException('A confirmação não confere com a nova senha.');
                }
                if (password_verify($nova, $u['senha_hash'])) {
                    throw new \InvalidArgumentException('A nova senha precisa ser diferente da atual.');
                }
                Usuarios::definirSenha($u['id'], $nova);
                Eventos::registrar('usuario', 'info', 'Senha trocada: ' . $u['login'], null, 'painel:' . $u['login']);
                session_regenerate_id(true);
                Auth::flash('Senha alterada.');
                self::redirecionar('/');
                return;
            } catch (\InvalidArgumentException $e) {
                $erro = $e->getMessage();
            }
        }
        View::pagina('Minha conta', 'conta', 'conta', ['u' => $u, 'erro' => $erro, 'subtitulo' => 'Seus dados de acesso']);
    }

    // ------------------------------------------------------------------ rede

    private static function rede(string $metodo): void
    {
        if ($metodo === 'POST') {
            Auth::exigirAdmin();
            $mac = strtolower((string) ($_POST['mac'] ?? ''));
            if (!preg_match('/^([0-9a-f]{2}:){5}[0-9a-f]{2}$/', $mac) || !Rede::porMac($mac)) {
                throw new HttpErro('Aparelho não encontrado.', 404);
            }
            try {
                Rede::salvar($mac, (string) ($_POST['apelido'] ?? ''), !empty($_POST['conhecido']));
                Eventos::registrar('rede', 'info', 'Aparelho da rede atualizado: ' . ($_POST['apelido'] ?: $mac),
                    !empty($_POST['conhecido']) ? 'marcado como conhecido' : 'marcado como desconhecido', 'painel:' . Auth::usuario()['login']);
                Auth::flash('Aparelho atualizado.');
            } catch (\InvalidArgumentException $e) {
                Auth::flash($e->getMessage(), 'erro');
            }
            self::redirecionar('/rede?filtro=' . rawurlencode((string) ($_GET['filtro'] ?? 'online')) . '#m-' . str_replace(':', '', $mac));
            return;
        }
        $filtro = in_array($_GET['filtro'] ?? '', ['online', 'todos', 'desconhecidos'], true) ? $_GET['filtro'] : 'online';
        View::pagina('Rede', 'rede', 'rede', [
            'subtitulo' => 'Aparelhos conectados na rede de casa',
            'lista'     => Rede::listar($filtro),
            'resumo'    => Rede::resumo(),
            'filtro'    => $filtro,
            'varredura' => Host::ler()['varredura'] ?? null,
        ]);
    }

    // ------------------------------------------------------------- atividade

    private const TIPOS_ATIVIDADE = [
        ''         => 'Tudo',
        'comando'  => 'Comandos',
        'aparelho' => 'Aparelhos',
        'sistema'  => 'Sistema',
        'backup'   => 'Backups',
        'rede'     => 'Rede e internet',
        'acesso'   => 'Acessos',
        'usuario'  => 'Usuários',
        'config'   => 'Configuração',
    ];

    private static function atividade(): void
    {
        $tipo = (string) ($_GET['tipo'] ?? '');
        if (!isset(self::TIPOS_ATIVIDADE[$tipo])) {
            $tipo = '';
        }
        View::pagina('Atividade', 'atividade', 'atividade', [
            'subtitulo' => 'Tudo o que aconteceu no servidor e na casa',
            'itens'     => Eventos::linhaDoTempo(300, $tipo ?: null),
            'tipo'      => $tipo,
            'tipos'     => self::TIPOS_ATIVIDADE,
        ]);
    }

    /**
     * Pontos de atenção do servidor, do mais grave ao mais leve.
     * @return list<array{nivel:string,titulo:string,detalhe:string,icone:string}>
     */
    private static function alertas(array $host, ?array $atual): array
    {
        $a = [];
        $add = static function (string $nivel, string $icone, string $titulo, string $detalhe = '') use (&$a): void {
            $a[] = compact('nivel', 'titulo', 'detalhe', 'icone');
        };
        if (!($host['disponivel'] ?? false) || ($host['idade_s'] ?? 9999) > 300) {
            $add('aviso', 'servidor', 'Coletor do servidor parado', 'Containers, backups e internet sem atualização. Veja casa-coletor.timer.');
        } else {
            foreach ($host['containers'] ?? [] as $c) {
                if (!is_array($c)) {
                    continue;
                }
                if (($c['estado'] ?? '') !== 'running') {
                    $add('erro', 'caixa', "Serviço {$c['nome']} parado", 'Estado: ' . ($c['estado'] ?? '?'));
                } elseif (($c['saude'] ?? null) === 'unhealthy') {
                    $add('erro', 'caixa', "Serviço {$c['nome']} com problema", 'Verificação de saúde falhando');
                } elseif (($c['reinicios'] ?? 0) >= 3) {
                    $add('aviso', 'caixa', "Serviço {$c['nome']} reiniciou {$c['reinicios']} vezes", 'Pode estar caindo e voltando sozinho');
                }
            }
            $net = $host['rede']['internet'] ?? null;
            if ($net && ($net['perda'] ?? 0) >= 100) {
                $add('erro', 'globo', 'Sem internet', 'Sem resposta de 1.1.1.1 e 8.8.8.8');
            } elseif ($net && ($net['ms'] ?? 0) > 150) {
                $add('aviso', 'globo', 'Internet lenta', 'Latência de ' . round($net['ms']) . ' ms');
            }
            $s = $host['sistema'] ?? [];
            foreach ($s['unidades_falhando'] ?? [] as $u) {
                $add('erro', 'alerta', "Serviço do sistema falhou: $u", 'systemctl status ' . $u);
            }
            if (!empty($s['reiniciar'])) {
                $add('aviso', 'power', 'Reinício pendente', 'Atualizações instaladas só valem depois de reiniciar');
            }
            if (isset($s['hora_sincronizada']) && !$s['hora_sincronizada']) {
                $add('aviso', 'relogio', 'Relógio fora de sincronia', 'Hora não está sincronizada com a internet');
            }
            $up = $host['atualizacoes'] ?? [];
            if (($up['seguranca'] ?? 0) > 0) {
                $add('aviso', 'escudo', "{$up['seguranca']} atualização(ões) de segurança", "{$up['pendentes']} pacote(s) no total: dnf upgrade");
            } elseif (($up['pendentes'] ?? 0) > 0) {
                $add('info', 'download', "{$up['pendentes']} atualização(ões) disponível(is)", 'dnf upgrade');
            }
            $b = $host['backups'] ?? [];
            if (empty($b['ultimo'])) {
                $add('aviso', 'arquivo', 'Nenhum backup ainda', 'O primeiro roda às 03:30');
            } elseif (time() - (int) $b['ultimo']['quando'] > 26 * 3600) {
                $add('erro', 'arquivo', 'Backup atrasado', 'Último há mais de um dia');
            }
            if (($b['resultado'] ?? 'success') !== 'success') {
                $add('erro', 'arquivo', 'Último backup falhou', 'journalctl -u casa-backup');
            }
            $ssh = $host['ssh'] ?? [];
            if (($ssh['falhas_24h'] ?? 0) > 20) {
                $add('aviso', 'cadeado', "{$ssh['falhas_24h']} tentativas de SSH erradas em 24 h", 'Alguém pode estar tentando entrar');
            }
            $seg = $host['seguranca'] ?? [];
            if (($seg['ssh_root'] ?? '') === 'yes' && !empty($seg['ssh_senha'])) {
                $add('info', 'cadeado', 'Root entra no SSH com senha', 'Mais seguro: PermitRootLogin prohibit-password');
            }
        }
        if ($atual) {
            foreach ([['disco_usado', 'disco_total', 'Disco do sistema'], ['home_usado', 'home_total', 'Disco /home']] as [$u, $t, $nome]) {
                if (!empty($atual[$t]) && $atual[$u] / $atual[$t] > 0.85) {
                    $add('aviso', 'disco', "$nome quase cheio", round($atual[$u] / $atual[$t] * 100) . '% usado');
                }
            }
            if (($atual['temp_c'] ?? 0) >= 80) {
                $add('aviso', 'termo', 'Processador quente', round((float) $atual['temp_c']) . ' °C');
            }
            if (($atual['idade_s'] ?? 0) > 120) {
                $add('erro', 'servidor', 'Worker parado', 'Sem métricas há ' . (int) $atual['idade_s'] . ' s');
            }
        }
        foreach (Dispositivos::listar() as $disp) {
            if ($disp['tipo'] === 'alimentador' && in_array($disp['dados']['racao'] ?? '', ['Pouca', 'Vazio'], true)) {
                $add($disp['dados']['racao'] === 'Vazio' ? 'erro' : 'aviso', 'pata',
                    $disp['nome'] . ': ração ' . strtolower($disp['dados']['racao']), 'Reabasteça o reservatório do alimentador.');
            }
        }
        $novos = Rede::novosDesconhecidos();
        if ($novos) {
            $add('aviso', 'roteador', count($novos) === 1 ? 'Aparelho novo na rede' : count($novos) . ' aparelhos novos na rede',
                implode(', ', array_map(fn($n) => "{$n['nome']} ({$n['ip']})", array_slice($novos, 0, 3)))
                . '. Se for seu, marque como conhecido em Rede.');
        }
        $ordem = ['erro' => 0, 'aviso' => 1, 'info' => 2];
        usort($a, fn($x, $y) => $ordem[$x['nivel']] <=> $ordem[$y['nivel']]);
        return $a;
    }

    private static function saudacao(array $u): string
    {
        $h = (int) date('G');
        $periodo = $h < 12 ? 'Bom dia' : ($h < 18 ? 'Boa tarde' : 'Boa noite');
        return $periodo . ', ' . explode(' ', trim($u['nome']))[0] . '. Situação da casa e do servidor.';
    }

    private static function redirecionar(string $url): void
    {
        header('Location: ' . $url, true, 303);
    }
}
