<?php
/**
 * SGL Advocacia - Login
 * Correção para tabela usuarios_sistema + senha MD5 legado/password_hash.
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/database.php';

if (!empty($_SESSION['usuario_id'])) {
    header('Location: ../index.php');
    exit;
}

$erro = '';
$usuarioInformado = '';

function sgl_tabela_existe(PDO $pdo, string $tabela): bool
{
    $stmt = $pdo->prepare("SHOW TABLES LIKE ?");
    $stmt->execute([$tabela]);
    return (bool) $stmt->fetchColumn();
}

function sgl_buscar_usuario(PDO $pdo, string $login): ?array
{
    $tabelas = ['usuarios_sistema', 'usuarios'];

    foreach ($tabelas as $tabela) {
        if (!sgl_tabela_existe($pdo, $tabela)) {
            continue;
        }

        $stmt = $pdo->prepare("SELECT * FROM `$tabela` WHERE usuario = ? LIMIT 1");
        $stmt->execute([$login]);
        $usuario = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($usuario) {
            $usuario['_tabela_origem'] = $tabela;
            return $usuario;
        }
    }

    return null;
}

function sgl_senha_confere(string $senhaDigitada, string $senhaBanco): bool
{
    if ($senhaBanco === '') {
        return false;
    }

    if (password_get_info($senhaBanco)['algo'] !== 0 && password_verify($senhaDigitada, $senhaBanco)) {
        return true;
    }

    // Compatibilidade com senha antiga do sistema. Ex.: admin123 = 0192023a7bbd73250516f069df18b500
    if (strlen($senhaBanco) === 32 && hash_equals(strtolower($senhaBanco), md5($senhaDigitada))) {
        return true;
    }

    return hash_equals($senhaBanco, $senhaDigitada);
}

function sgl_migrar_senha(PDO $pdo, array $usuario, string $senhaDigitada): void
{
    $hashAtual = (string)($usuario['senha'] ?? '');
    $precisaMigrar = password_get_info($hashAtual)['algo'] === 0;

    if (!$precisaMigrar) {
        return;
    }

    $novoHash = password_hash($senhaDigitada, PASSWORD_DEFAULT);
    $id = (int)$usuario['id'];
    $tabela = $usuario['_tabela_origem'] ?? 'usuarios_sistema';

    if (sgl_tabela_existe($pdo, $tabela)) {
        $stmt = $pdo->prepare("UPDATE `$tabela` SET senha = ? WHERE id = ?");
        $stmt->execute([$novoHash, $id]);
    }

    // Garante que a tabela oficial também fique atualizada quando existir.
    if ($tabela !== 'usuarios_sistema' && sgl_tabela_existe($pdo, 'usuarios_sistema')) {
        $stmt = $pdo->prepare("UPDATE `usuarios_sistema` SET senha = ? WHERE usuario = ?");
        $stmt->execute([$novoHash, $usuario['usuario']]);
    }
}

function sgl_status_ativo(array $usuario): bool
{
    $status = strtolower(trim((string)($usuario['status'] ?? 'Ativo')));
    $ativo = strtolower(trim((string)($usuario['ativo'] ?? '1')));

    return in_array($status, ['ativo', '1', 'sim', 'active'], true)
        && !in_array($ativo, ['0', 'nao', 'não', 'inativo', 'false'], true);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $usuarioInformado = trim((string)($_POST['usuario'] ?? $_POST['login'] ?? $_POST['email'] ?? ''));
    $senha = (string)($_POST['senha'] ?? $_POST['password'] ?? '');

    if ($usuarioInformado === '' || $senha === '') {
        $erro = 'Informe usuário e senha.';
    } else {
        try {
            $usuario = sgl_buscar_usuario($pdo, $usuarioInformado);

            if (!$usuario || !sgl_status_ativo($usuario) || !sgl_senha_confere($senha, (string)$usuario['senha'])) {
                $erro = 'Usuário ou senha inválidos.';
            } else {
                sgl_migrar_senha($pdo, $usuario, $senha);

                session_regenerate_id(true);

                $_SESSION['logado'] = true;
                $_SESSION['usuario_id'] = (int)$usuario['id'];
                $_SESSION['usuario_nome'] = (string)($usuario['nome'] ?? $usuario['usuario']);
                $_SESSION['usuario_login'] = (string)$usuario['usuario'];
                $_SESSION['usuario_perfil'] = (string)($usuario['perfil'] ?? 'Administrador');

                // Compatibilidade com arquivos antigos.
                $_SESSION['id_usuario'] = $_SESSION['usuario_id'];
                $_SESSION['nome'] = $_SESSION['usuario_nome'];
                $_SESSION['usuario'] = $_SESSION['usuario_login'];
                $_SESSION['perfil'] = $_SESSION['usuario_perfil'];

                header('Location: ../index.php');
                exit;
            }
        } catch (Throwable $e) {
            $erro = 'Erro ao validar login: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - SGL Advocacia</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        body {
            min-height: 100vh;
            margin: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #f4f6f9;
            font-family: Arial, Helvetica, sans-serif;
        }
        .login-container {
            width: 100%;
            max-width: 420px;
            background: #fff;
            border-radius: 14px;
            box-shadow: 0 10px 35px rgba(0,0,0,.10);
            padding: 34px;
        }
        .login-title {
            margin: 0 0 6px;
            text-align: center;
            color: #1f2937;
            font-size: 26px;
        }
        .login-subtitle {
            margin: 0 0 28px;
            text-align: center;
            color: #6b7280;
            font-size: 14px;
        }
        .form-group { margin-bottom: 18px; }
        .form-group label {
            display: block;
            margin-bottom: 7px;
            color: #374151;
            font-weight: 600;
        }
        .form-control {
            width: 100%;
            box-sizing: border-box;
            padding: 12px 14px;
            border: 1px solid #d1d5db;
            border-radius: 9px;
            font-size: 15px;
        }
        .btn-login {
            width: 100%;
            border: 0;
            border-radius: 9px;
            padding: 13px 16px;
            background: #1f4e79;
            color: #fff;
            font-size: 16px;
            font-weight: 700;
            cursor: pointer;
        }
        .btn-login:hover { filter: brightness(.95); }
        .alert-error {
            background: #fee2e2;
            border: 1px solid #fecaca;
            color: #991b1b;
            border-radius: 9px;
            padding: 11px 13px;
            margin-bottom: 18px;
            font-size: 14px;
        }
        .login-help {
            margin-top: 18px;
            text-align: center;
            color: #6b7280;
            font-size: 12px;
        }
    </style>
</head>
<body>
    <main class="login-container">
        <h1 class="login-title">SGL Advocacia</h1>
        <p class="login-subtitle">Acesso ao sistema</p>

        <?php if ($erro !== ''): ?>
            <div class="alert-error"><?= htmlspecialchars($erro, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>

        <form method="post" autocomplete="off">
            <div class="form-group">
                <label for="usuario">Usuário</label>
                <input class="form-control" type="text" id="usuario" name="usuario" value="<?= htmlspecialchars($usuarioInformado, ENT_QUOTES, 'UTF-8') ?>" required autofocus>
            </div>

            <div class="form-group">
                <label for="senha">Senha</label>
                <input class="form-control" type="password" id="senha" name="senha" required>
            </div>

            <button class="btn-login" type="submit">Entrar</button>
        </form>

        <div class="login-help">Usuário padrão recuperado: admin</div>
    </main>
</body>
</html>
