<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';

iniciarSessaoSegura();
exigirLogin('login.php');

$mensagem = '';
$mensagem_tipo = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $senha_atual = (string)($_POST['senha_atual'] ?? '');
    $nova_senha = (string)($_POST['nova_senha'] ?? '');
    $confirmar_nova_senha = (string)($_POST['confirmar_nova_senha'] ?? '');
    $csrf = (string)($_POST['csrf_token'] ?? '');
    $user_id = (int)$_SESSION['user_id'];

    if (!validarTokenCsrf($csrf)) {
        $mensagem = 'Sessão expirada ou formulário inválido. Atualize a página e tente novamente.';
        $mensagem_tipo = 'error';
    } elseif ($senha_atual === '' || $nova_senha === '' || $confirmar_nova_senha === '') {
        $mensagem = 'Por favor, preencha todos os campos.';
        $mensagem_tipo = 'error';
    } elseif ($nova_senha !== $confirmar_nova_senha) {
        $mensagem = 'A nova senha e a confirmação não coincidem.';
        $mensagem_tipo = 'error';
    } elseif (strlen($nova_senha) < 8) {
        $mensagem = 'A nova senha deve ter pelo menos 8 caracteres.';
        $mensagem_tipo = 'error';
    } else {
        try {
            $conn = conectar();
            $stmt = $conn->prepare('SELECT senha FROM usuarios WHERE id = ? AND ativo = 1 LIMIT 1');
            $stmt->bind_param('i', $user_id);
            $stmt->execute();
            $user = $stmt->get_result()->fetch_assoc();

            if ($user && password_verify($senha_atual, $user['senha'])) {
                $nova_senha_hash = password_hash($nova_senha, PASSWORD_DEFAULT);
                $stmt_update = $conn->prepare('UPDATE usuarios SET senha = ?, atualizado_em = NOW() WHERE id = ?');
                $stmt_update->bind_param('si', $nova_senha_hash, $user_id);
                $stmt_update->execute();

                $mensagem = 'Senha alterada com sucesso!';
                $mensagem_tipo = 'success';
            } else {
                $mensagem = 'Senha atual incorreta.';
                $mensagem_tipo = 'error';
            }
        } catch (Throwable $e) {
            $mensagem = 'Erro ao alterar a senha. Verifique a estrutura do banco de dados.';
            $mensagem_tipo = 'error';
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
    <title>Alterar Senha</title>
    <style>
        body { font-family: Arial, sans-serif; background: #f4f6f8; display: flex; justify-content: center; align-items: center; min-height: 100vh; margin: 0; }
        .container { background: #fff; padding: 30px; border-radius: 12px; box-shadow: 0 10px 30px rgba(0,0,0,.08); width: 420px; text-align: center; }
        h2 { color: #1a3c5e; margin-bottom: 20px; }
        .form-group { margin-bottom: 15px; text-align: left; }
        label { display: block; margin-bottom: 6px; color: #444; font-weight: 600; }
        input[type="password"] { width: 100%; padding: 11px; border: 1px solid #d7dce1; border-radius: 6px; box-sizing: border-box; }
        button { width: 100%; padding: 11px; background: #2c6fad; color: white; border: none; border-radius: 6px; cursor: pointer; font-size: 16px; font-weight: 700; margin-top: 8px; }
        button:hover { background: #1a3c5e; }
        .mensagem { padding: 10px; border-radius: 6px; margin-bottom: 15px; text-align: center; }
        .mensagem.success { background: #d1e7dd; color: #0f5132; border: 1px solid #badbcc; }
        .mensagem.error { background: #f8d7da; color: #842029; border: 1px solid #f5c2c7; }
        .back-link { display: block; margin-top: 18px; color: #2c6fad; text-decoration: none; }
    </style>
</head>
<body>
    <div class="container">
        <h2>Alterar Senha</h2>
        <?php if ($mensagem): ?>
            <div class="mensagem <?= htmlspecialchars($mensagem_tipo, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($mensagem, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>
        <form action="alterar_senha.php" method="POST">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
            <div class="form-group">
                <label for="senha_atual">Senha atual</label>
                <input type="password" id="senha_atual" name="senha_atual" required>
            </div>
            <div class="form-group">
                <label for="nova_senha">Nova senha</label>
                <input type="password" id="nova_senha" name="nova_senha" minlength="8" required>
            </div>
            <div class="form-group">
                <label for="confirmar_nova_senha">Confirmar nova senha</label>
                <input type="password" id="confirmar_nova_senha" name="confirmar_nova_senha" minlength="8" required>
            </div>
            <button type="submit">Alterar Senha</button>
        </form>
        <a href="../index.php" class="back-link">Voltar para o sistema</a>
    </div>
</body>
</html>
