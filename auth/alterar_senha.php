<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';

iniciarSessaoSegura();
exigirLogin('login.php');

$mensagem = '';
$mensagem_tipo = '';

function sglTabelaExisteSenha(mysqli $conn, string $tabela): bool
{
    $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
    $stmt->bind_param('s', $tabela);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return (int)($row['total'] ?? 0) > 0;
}

function sglColunaExisteSenha(mysqli $conn, string $tabela, string $coluna): bool
{
    $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $stmt->bind_param('ss', $tabela, $coluna);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return (int)($row['total'] ?? 0) > 0;
}

function sglObterTabelaUsuariosSenha(mysqli $conn): string
{
    if (!empty($_SESSION['tabela_usuarios']) && sglTabelaExisteSenha($conn, (string)$_SESSION['tabela_usuarios'])) {
        return (string)$_SESSION['tabela_usuarios'];
    }

    if (sglTabelaExisteSenha($conn, 'usuarios_sistema')) {
        return 'usuarios_sistema';
    }

    if (sglTabelaExisteSenha($conn, 'usuarios')) {
        return 'usuarios';
    }

    throw new RuntimeException('Nenhuma tabela de usuários foi encontrada.');
}

function sglSenhaConfereSenha(string $senhaDigitada, string $hashBanco): bool
{
    if (password_get_info($hashBanco)['algo'] !== 0 && password_verify($senhaDigitada, $hashBanco)) {
        return true;
    }

    return hash_equals(strtolower($hashBanco), md5($senhaDigitada));
}

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
            $tabelaUsuarios = sglObterTabelaUsuariosSenha($conn);
            $temAtivo = sglColunaExisteSenha($conn, $tabelaUsuarios, 'ativo');
            $temStatus = sglColunaExisteSenha($conn, $tabelaUsuarios, 'status');
            $temAtualizadoEm = sglColunaExisteSenha($conn, $tabelaUsuarios, 'atualizado_em');

            $whereStatus = '';
            if ($temAtivo) {
                $whereStatus = ' AND ativo = 1';
            } elseif ($temStatus) {
                $whereStatus = " AND status = 'Ativo'";
            }

            $stmt = $conn->prepare("SELECT senha FROM `$tabelaUsuarios` WHERE id = ? $whereStatus LIMIT 1");
            $stmt->bind_param('i', $user_id);
            $stmt->execute();
            $user = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($user && sglSenhaConfereSenha($senha_atual, (string)$user['senha'])) {
                $nova_senha_hash = password_hash($nova_senha, PASSWORD_DEFAULT);

                if ($temAtualizadoEm) {
                    $stmt_update = $conn->prepare("UPDATE `$tabelaUsuarios` SET senha = ?, atualizado_em = NOW() WHERE id = ?");
                } else {
                    $stmt_update = $conn->prepare("UPDATE `$tabelaUsuarios` SET senha = ? WHERE id = ?");
                }

                $stmt_update->bind_param('si', $nova_senha_hash, $user_id);
                $stmt_update->execute();
                $stmt_update->close();

                $mensagem = 'Senha alterada com sucesso!';
                $mensagem_tipo = 'success';
            } else {
                $mensagem = 'Senha atual incorreta.';
                $mensagem_tipo = 'error';
            }

            $conn->close();
        } catch (Throwable $e) {
            $mensagem = 'Erro ao alterar a senha: ' . $e->getMessage();
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
        <form method="POST" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
            <div class="form-group">
                <label for="senha_atual">Senha atual</label>
                <input type="password" id="senha_atual" name="senha_atual" required>
            </div>
            <div class="form-group">
                <label for="nova_senha">Nova senha</label>
                <input type="password" id="nova_senha" name="nova_senha" required minlength="8">
            </div>
            <div class="form-group">
                <label for="confirmar_nova_senha">Confirmar nova senha</label>
                <input type="password" id="confirmar_nova_senha" name="confirmar_nova_senha" required minlength="8">
            </div>
            <button type="submit">Salvar nova senha</button>
        </form>
        <a class="back-link" href="../index.php">Voltar ao sistema</a>
    </div>
</body>
</html>
