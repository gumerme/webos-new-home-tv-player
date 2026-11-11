<?php
declare(strict_types=1);

namespace Casa;

/**
 * API REST. Todas as rotas /api/* exigem "Authorization: Bearer <CASA_TOKEN>".
 *
 * GET  /api/saude
 * GET  /api/dispositivos[?homekit=1]
 * GET  /api/dispositivos/{slug}
 * POST /api/dispositivos                     {slug,nome,tipo,driver,comodo?,config?,homekit?,ativo?}
 * POST /api/dispositivos/{slug}/comandos     {acao, parametros?, origem?, aguardar? (padrão true)}
 * GET  /api/comandos[?limite=50]
 */
final class Api
{
    public static function atender(string $metodo, string $caminho): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        try {
            if ($caminho !== '/api/saude') {
                self::autenticar($metodo);
            }
            [$status, $corpo] = self::rotear($metodo, $caminho);
        } catch (HttpErro $e) {
            [$status, $corpo] = [$e->getCode(), ['erro' => $e->getMessage()]];
        } catch (\InvalidArgumentException $e) {
            [$status, $corpo] = [422, ['erro' => $e->getMessage()]];
        } catch (\Throwable $e) {
            error_log('API: ' . $e);
            [$status, $corpo] = [500, ['erro' => 'erro interno']];
        }
        http_response_code($status);
        echo json_encode($corpo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private static function rotear(string $metodo, string $caminho): array
    {
        $partes = array_values(array_filter(explode('/', trim($caminho, '/'))));   // ['api', ...]
        $rota = $metodo . ' /' . implode('/', array_map(
            fn($p, $i) => ($i === 2 && $partes[1] === 'dispositivos') ? '{slug}' : $p,
            $partes,
            array_keys($partes)
        ));

        switch ($rota) {
            case 'GET /api/saude':
                Db::pdo()->query('select 1');
                return [200, ['ok' => true, 'hora' => date(DATE_ATOM)]];

            case 'GET /api/dispositivos':
                $lista = Dispositivos::listar(!empty($_GET['homekit']));
                return [200, ['dispositivos' => array_map(Dispositivos::publico(...), $lista)]];

            case 'GET /api/dispositivos/{slug}':
                return [200, Dispositivos::publico(self::dispositivo($partes[2]))];

            case 'POST /api/dispositivos':
                self::exigirAdmin();
                $d = Dispositivos::salvar(self::corpo());
                return [200, Dispositivos::publico($d)];

            case 'POST /api/dispositivos/{slug}/comandos':
                $d = self::dispositivo($partes[2]);
                $b = self::corpo();
                $acao = (string) ($b['acao'] ?? '');
                $origem = self::$usuario ? 'painel:' . self::$usuario['login'] : (string) ($b['origem'] ?? 'api');
                $id = Comandos::enfileirar($d['id'], $acao, (array) ($b['parametros'] ?? []), $origem);
                if (($b['aguardar'] ?? true) === false) {
                    return [202, ['id' => $id, 'status' => 'pendente']];
                }
                $c = Comandos::aguardar($id, match (true) {
                    $acao === 'parear'           => 70,
                    default                      => 12,
                });
                $http = match ($c['status']) { 'ok' => 200, 'erro' => 502, default => 504 };
                return [$http, $c];

            case 'GET /api/comandos':
                return [200, ['comandos' => Comandos::recentes((int) ($_GET['limite'] ?? 50))]];
        }
        throw new HttpErro('rota não encontrada', 404);
    }

    /** Quem chama: null = integração (token), array = usuário do painel. */
    private static ?array $usuario = null;

    /**
     * Aceita o token da casa (Homebridge, app) ou a sessão do painel.
     * Pela sessão, escrita exige o cabeçalho X-CSRF.
     */
    private static function autenticar(string $metodo): void
    {
        $esperado = (string) getenv('CASA_TOKEN');
        $cab = $_SERVER['HTTP_AUTHORIZATION'] ?? (function_exists('getallheaders') ? (getallheaders()['Authorization'] ?? '') : '');
        if (str_starts_with($cab, 'Bearer ')) {
            if ($esperado !== '' && hash_equals($esperado, substr($cab, 7))) {
                return;
            }
            throw new HttpErro('não autorizado', 401);
        }
        if (Auth::temCookie()) {
            Auth::iniciarSessao();
            $u = Auth::usuario();
            if ($u && !$u['trocar_senha']) {
                if ($metodo !== 'GET') {
                    Auth::validarCsrf($_SERVER['HTTP_X_CSRF'] ?? null);
                }
                session_write_close();   // não trava o painel enquanto um comando espera a TV
                self::$usuario = $u;
                return;
            }
        }
        throw new HttpErro('não autorizado', 401);
    }

    private static function exigirAdmin(): void
    {
        if (self::$usuario !== null && self::$usuario['perfil'] !== 'admin') {
            throw new HttpErro('só administradores', 403);
        }
    }

    private static function dispositivo(string $slug): array
    {
        return Dispositivos::porSlug($slug) ?? throw new HttpErro("dispositivo '$slug' não existe", 404);
    }

    private static function corpo(): array
    {
        $b = json_decode((string) file_get_contents('php://input'), true);
        if (!is_array($b)) {
            throw new HttpErro('corpo JSON inválido', 400);
        }
        return $b;
    }
}
