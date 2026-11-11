<?php
declare(strict_types=1);

namespace Casa;

final class Usuarios
{
    public const PERFIS = ['admin' => 'Administrador', 'morador' => 'Morador'];

    public static function listar(): array
    {
        return array_map(self::hidratar(...), Db::pdo()->query('select * from usuario order by ativo desc, nome')->fetchAll());
    }

    public static function porId(int $id): ?array
    {
        $st = Db::pdo()->prepare('select * from usuario where id = ?');
        $st->execute([$id]);
        $u = $st->fetch();
        return $u ? self::hidratar($u) : null;
    }

    public static function porLogin(string $login): ?array
    {
        $st = Db::pdo()->prepare('select * from usuario where lower(login) = lower(?)');
        $st->execute([trim($login)]);
        $u = $st->fetch();
        return $u ? self::hidratar($u) : null;
    }

    public static function contar(): int
    {
        return (int) Db::pdo()->query('select count(*) from usuario')->fetchColumn();
    }

    /** Cria (id null) ou atualiza. Senha vazia na edição = mantém a atual. */
    public static function salvar(?int $id, array $d): int
    {
        $nome = trim((string) ($d['nome'] ?? ''));
        $login = strtolower(trim((string) ($d['login'] ?? '')));
        $perfil = (string) ($d['perfil'] ?? 'morador');
        $senha = (string) ($d['senha'] ?? '');
        if ($nome === '') {
            throw new \InvalidArgumentException('Informe o nome.');
        }
        if (!preg_match('/^[a-z0-9._-]{3,40}$/', $login)) {
            throw new \InvalidArgumentException('Login: 3 a 40 caracteres (letras minúsculas, números, ponto, hífen).');
        }
        if (!isset(self::PERFIS[$perfil])) {
            throw new \InvalidArgumentException('Perfil inválido.');
        }
        $outro = self::porLogin($login);
        if ($outro && $outro['id'] !== $id) {
            throw new \InvalidArgumentException("O login \"$login\" já existe.");
        }
        if ($id === null || $senha !== '') {
            self::validarSenha($senha);
        }
        $ativo = !empty($d['ativo']);
        $trocar = !empty($d['trocar_senha']);
        $pdo = Db::pdo();
        if ($id === null) {
            $st = $pdo->prepare('insert into usuario (nome, login, senha_hash, perfil, ativo, trocar_senha) values (?, ?, ?, ?, ?, ?) returning id');
            $st->execute([$nome, $login, self::hash($senha), $perfil, $ativo ? 'true' : 'false', $trocar ? 'true' : 'false']);
            return (int) $st->fetchColumn();
        }
        $pdo->prepare('update usuario set nome = ?, login = ?, perfil = ?, ativo = ?, trocar_senha = ? where id = ?')
            ->execute([$nome, $login, $perfil, $ativo ? 'true' : 'false', $trocar ? 'true' : 'false', $id]);
        if ($senha !== '') {
            self::definirSenha($id, $senha, $trocar);
        }
        return $id;
    }

    public static function definirSenha(int $id, string $senha, bool $trocarDepois = false): void
    {
        self::validarSenha($senha);
        Db::pdo()->prepare('update usuario set senha_hash = ?, trocar_senha = ? where id = ?')
            ->execute([self::hash($senha), $trocarDepois ? 'true' : 'false', $id]);
    }

    public static function marcarLogin(int $id): void
    {
        Db::pdo()->prepare('update usuario set ultimo_login = now() where id = ?')->execute([$id]);
    }

    public static function atualizarHash(int $id, string $senha): void
    {
        Db::pdo()->prepare('update usuario set senha_hash = ? where id = ?')->execute([self::hash($senha), $id]);
    }

    /** Impede ficar sem nenhum administrador ativo. */
    public static function adminsAtivos(?int $exceto = null): int
    {
        $st = Db::pdo()->prepare("select count(*) from usuario where perfil = 'admin' and ativo and id <> coalesce(?, -1)");
        $st->execute([$exceto]);
        return (int) $st->fetchColumn();
    }

    public static function validarSenha(string $senha): void
    {
        if (mb_strlen($senha) < 8) {
            throw new \InvalidArgumentException('A senha precisa ter pelo menos 8 caracteres.');
        }
    }

    public static function hash(string $senha): string
    {
        return password_hash($senha, PASSWORD_BCRYPT, ['cost' => 11]);
    }

    private static function hidratar(array $u): array
    {
        $u['id'] = (int) $u['id'];
        foreach (['ativo', 'trocar_senha'] as $b) {
            $u[$b] = filter_var($u[$b], FILTER_VALIDATE_BOOLEAN);
        }
        return $u;
    }
}
