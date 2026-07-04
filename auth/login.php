<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';

iniciarSessaoSegura();

if (usuarioLogado()) {
    header('Location: ../index.php');
    exit();
}

$mensagem_erro = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $usuario = trim((string)($_POST['usuario'] ?? ''));
    $senha = (string)($_POST['senha'] ?? '');
    $csrf = (string)($_POST['csrf_token'] ?? '');

    if (!validarTokenCsrf($csrf)) {
        $mensagem_erro = 'Sessão expirada ou formulário inválido. Atualize a página e tente novamente.';
    } elseif ($usuario === '' || $senha === '') {
        $mensagem_erro = 'Por favor, preencha todos os campos.';
    } else {
        try {
            $conn = conectar();

            $stmt = $conn->prepare(
                "SELECT id, nome, usuario, senha, perfil, status
                 FROM usuarios_sistema
                 WHERE usuario = ?
                 LIMIT 1"
            );
            $stmt->bind_param('s', $usuario);
            $stmt->execute();
            $result = $stmt->get_result();
            $user = $result->fetch_assoc();

            if ($user && md5($senha) === $user['senha'] && strtolower($user['status']) === 'ativo') {
                session_regenerate_id(true);

                $_SESSION['user_id'] = (int)$user['id'];
                $_SESSION['username'] = $user['usuario'];
                $_SESSION['nome'] = $user['nome'];
                $_SESSION['perfil'] = $user['perfil'];
                $_SESSION['ultimo_acesso'] = time();

                header('Location: ../index.php');
                exit();
            }

            $mensagem_erro = 'Usuário ou senha inválidos.';
            $stmt->close();
            $conn->close();
        } catch (Throwable $e) {
            $mensagem_erro = 'Erro ao tentar fazer login. Verifique se o banco foi instalado corretamente.';
        }
    }
}

$csrfToken = gerarTokenCsrf();
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Sistema SGL</title>
    <style>
        body { font-family: Arial, sans-serif; background: #f4f6f8; display: flex; justify-content: center; align-items: center; min-height: 100vh; margin: 0; }
        .container { background: #fff; padding: 32px; border-radius: 12px; box-shadow: 0 10px 30px rgba(0,0,0,.08); width: 360px; text-align: center; }
        .logo { max-width: 180px; max-height: 90px; object-fit: contain; margin-bottom: 18px; }
        h2 { color: #1a3c5e; margin-bottom: 20px; }
        .form-group { margin-bottom: 15px; text-align: left; }
        label { display: block; margin-bottom: 6px; color: #444; font-weight: 600; }
        input[type="text"], input[type="password"] { width: 100%; padding: 11px; border: 1px solid #d7dce1; border-radius: 6px; box-sizing: border-box; }
        button { width: 100%; padding: 11px; background: #2c6fad; color: white; border: none; border-radius: 6px; cursor: pointer; font-size: 16px; font-weight: 700; margin-top: 8px; }
        button:hover { background: #1a3c5e; }
        .mensagem-erro { color: #842029; background: #f8d7da; border: 1px solid #f5c2c7; padding: 10px; border-radius: 6px; margin-bottom: 15px; text-align: center; }
        .ajuda { margin-top: 18px; color: #6c757d; font-size: 12px; }
    </style>
</head>
<body>
    <div class="container">
        <img src="../assets/img/logo_custom.png" alt="SGL Advocacia" class="logo">
        <h2>Sistema SGL</h2>
        <?php if ($mensagem_erro): ?>
            <div class="mensagem-erro"><?= htmlspecialchars($mensagem_erro, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>
        <form action="login.php" method="POST" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
            <div class="form-group">
                <label for="usuario">Usuário</label>
                <input type="text" id="usuario" name="usuario" required autofocus>
            </div>
            <div class="form-group">
                <label for="senha">Senha</label>
                <input type="password" id="senha" name="senha" required>
            </div>
            <button type="submit">Entrar</button>
        </form>
    </div>
</body>
</html>
