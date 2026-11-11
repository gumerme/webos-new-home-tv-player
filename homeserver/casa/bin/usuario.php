<?php
declare(strict_types=1);

/**
 * Usuários do painel pela linha de comando.
 *   php bin/usuario.php listar
 *   SENHA='...' php bin/usuario.php criar <login> "<Nome>" [admin|morador]
 *   SENHA='...' php bin/usuario.php senha <login>
 * Sem a variável SENHA, a senha é pedida no terminal.
 */

use Casa\Db;
use Casa\Usuarios;

require dirname(__DIR__) . '/src/autoload.php';

function senha(): string
{
    $s = getenv('SENHA');
    if ($s !== false && $s !== '') {
        return $s;
    }
    fwrite(STDERR, 'Senha: ');
    system('stty -echo 2>/dev/null');
    $s = trim((string) fgets(STDIN));
    system('stty echo 2>/dev/null');
    fwrite(STDERR, "\n");
    return $s;
}

try {
    Db::migrar();
    $cmd = $argv[1] ?? '';
    switch ($cmd) {
        case 'listar':
            foreach (Usuarios::listar() as $u) {
                printf("%-4d %-20s %-25s %-8s %s\n", $u['id'], $u['login'], $u['nome'], $u['perfil'], $u['ativo'] ? 'ativo' : 'inativo');
            }
            break;
        case 'criar':
            [$login, $nome, $perfil] = [$argv[2] ?? '', $argv[3] ?? '', $argv[4] ?? 'morador'];
            $id = Usuarios::salvar(null, ['login' => $login, 'nome' => $nome, 'perfil' => $perfil, 'senha' => senha(), 'ativo' => true]);
            echo "usuário criado: #$id $login ($perfil)\n";
            break;
        case 'senha':
            $u = Usuarios::porLogin($argv[2] ?? '') ?? throw new RuntimeException('login não existe');
            Usuarios::definirSenha($u['id'], senha());
            echo "senha alterada: {$u['login']}\n";
            break;
        default:
            fwrite(STDERR, "uso: usuario.php listar | criar <login> \"<Nome>\" [admin|morador] | senha <login>\n");
            exit(2);
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'erro: ' . $e->getMessage() . "\n");
    exit(1);
}
