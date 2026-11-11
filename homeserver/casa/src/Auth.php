<?php
declare(strict_types=1);

namespace Casa;

/** Sessão do painel: login, logout, CSRF e bloqueio por tentativas. */
final class Auth
{
    private const COOKIE = 'casa_sessao';
    private const DURACAO = 7 * 86400;
    private const MAX_FALHAS = 5;
    private const JANELA_MIN = 15;

    private static ?array $usuario = null;
    private static bool $carregado = false;

    public static function temCookie(): bool
    {
        return isset($_COOKIE[self::COOKIE]);
    }

    public static function iniciarSessao(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        $dir = dirname(__DIR__) . '/var/sessoes';
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        session_save_path($dir);
        session_name(self::COOKIE);
        ini_set('session.gc_maxlifetime', (string) self::DURACAO);
        ini_set('session.use_strict_mode', '1');
        session_set_cookie_params([
            'lifetime' => self::DURACAO,
            'path'     => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure'   => !empty($_SERVER['HTTPS']),
        ]);
        session_start();
    }

    public static function usuario(): ?array
    {
        if (self::$carregado) {
            return self::$usuario;
        }
        self::$carregado = true;
        $id = $_SESSION['uid'] ?? null;
        if (!$id) {
            return null;
        }
        $u = Usuarios::porId((int) $id);
        if (!$u || !$u['ativo']) {
            self::sair();
            return null;
        }
        return self::$usuario = $u;
    }

    /** @return array usuário logado, ou string com a mensagem de erro */
    public static function entrar(string $login, string $senha, string $ip): array|string
    {
        $login = strtolower(trim($login));
        if (self::bloqueado($login, $ip)) {
            return 'Muitas tentativas erradas. Aguarde ' . self::JANELA_MIN . ' minutos.';
        }
        $u = $login !== '' ? Usuarios::porLogin($login) : null;
        // Compara mesmo sem usuário, para não revelar quais logins existem pelo tempo de resposta.
        $hash = $u['senha_hash'] ?? '$2y$11$abcdefghijklmnopqrstuuJ0Jx2D0bCq0wXGd8m6lW2T6oPj7lMvK';
        $ok = password_verify($senha, $hash) && $u && $u['ativo'];
        Db::pdo()->prepare('insert into login_tentativa (login, ip, sucesso) values (?, ?, ?)')
            ->execute([$login, $ip, $ok ? 'true' : 'false']);
        if (!$ok) {
            return 'Login ou senha inválidos.';
        }
        if (password_needs_rehash($u['senha_hash'], PASSWORD_BCRYPT, ['cost' => 11])) {
            Usuarios::atualizarHash($u['id'], $senha);
        }
        session_regenerate_id(true);
        $_SESSION['uid'] = $u['id'];
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
        Usuarios::marcarLogin($u['id']);
        self::$usuario = $u;
        self::$carregado = true;
        return $u;
    }

    public static function sair(): void
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        setcookie(self::COOKIE, '', ['expires' => time() - 3600, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
        self::$usuario = null;
    }

    public static function csrf(): string
    {
        return $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
    }

    public static function validarCsrf(?string $token): void
    {
        if (!is_string($token) || !hash_equals(self::csrf(), $token)) {
            throw new HttpErro('A sessão expirou. Recarregue a página e tente de novo.', 419);
        }
    }

    public static function ehAdmin(?array $u = null): bool
    {
        $u ??= self::usuario();
        return ($u['perfil'] ?? '') === 'admin';
    }

    public static function exigirAdmin(): void
    {
        if (!self::ehAdmin()) {
            throw new HttpErro('Só administradores acessam esta página.', 403);
        }
    }

    public static function flash(?string $msg = null, string $tipo = 'ok'): ?array
    {
        if ($msg !== null) {
            $_SESSION['flash'] = ['msg' => $msg, 'tipo' => $tipo];
            return null;
        }
        $f = $_SESSION['flash'] ?? null;
        unset($_SESSION['flash']);
        return $f;
    }

    public static function ip(): string
    {
        return (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    }

    private static function bloqueado(string $login, string $ip): bool
    {
        $st = Db::pdo()->prepare("
            select count(*) from login_tentativa
             where not sucesso and criado_em > now() - make_interval(mins => ?)
               and (login = ? or ip = ?)");
        $st->execute([self::JANELA_MIN, $login, $ip]);
        return (int) $st->fetchColumn() >= self::MAX_FALHAS;
    }
}
