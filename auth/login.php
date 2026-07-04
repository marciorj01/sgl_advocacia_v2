<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';

iniciarSessaoSegura();

if (usuarioLogado()) {
    header('Location: ../index.php');
    exit();
}

$mensagem_erro = '';

function sgl_login_coluna_existe(mysqli $conn, string $tabela, string $coluna): bool {
    $tabela = preg_replace('/[^a-zA-Z0-9_]/', '', $tabela);
    $coluna = $conn->real_escape_string($coluna);
    $res = $conn->query("SHOW COLUMNS FROM `{$tabela}` LIKE '{$coluna}'");
    return $res && $res->num_rows > 0;
}

function sgl_login_tabela_existe(mysqli $conn, string $tabela): bool {
    $tabela = $conn->real_escape_string($tabela);
    $res = $conn->query("SHOW TABLES LIKE '{$tabela}'");
    return $res && $res->num_rows > 0;
}

function sgl_login_verificar_senha(string $senhaDigitada, string $hashSalvo): bool {
    if (password_get_info($hashSalvo)['algo'] !== 0 && password_verify($senhaDigitada, $hashSalvo)) {
        return true;
    }
    // Compatibilidade temporária com senha antiga em MD5.
    return strlen($hashSalvo) === 32 && hash_equals(strtolower($hashSalvo), md5($senhaDigitada));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $usuario = trim((string)($_POST['usuario'] ?? ''));
    $senha = (string)($_POST['senha'] ?? '');
    $csrf = (string)($_POST['csrf_token'] ?? '');

    if (!validarTokenCsrf($csrf)) {
        $mensagem_erro = 'Sessão expirada ou formulário inválido. Atualize a página e tente novamente.';
    } elseif ($usuario === '' || $senha === '') {
        $mensagem_erro = 'Por favor, preencha usuário e senha.';
    } else {
        try {
            $conn = conectar();
            $tabela = sgl_login_tabela_existe($conn, 'usuarios_sistema') ? 'usuarios_sistema' : 'usuarios';
            $campoStatus = sgl_login_coluna_existe($conn, $tabela, 'ativo') ? 'ativo' : (sgl_login_coluna_existe($conn, $tabela, 'status') ? 'status' : '');
            $campoPerfil = sgl_login_coluna_existe($conn, $tabela, 'perfil') ? 'perfil' : (sgl_login_coluna_existe($conn, $tabela, 'nivel') ? 'nivel' : "'Administrador'");
            $campoUsuario = sgl_login_coluna_existe($conn, $tabela, 'usuario') ? 'usuario' : 'email';

            $whereStatus = '';
            if ($campoStatus === 'ativo') {
                $whereStatus = ' AND COALESCE(ativo,1)=1';
            } elseif ($campoStatus === 'status') {
                $whereStatus = " AND COALESCE(status,'Ativo') <> 'Inativo'";
            }

            $sql = "SELECT id, nome, {$campoUsuario} AS usuario, senha, {$campoPerfil} AS perfil FROM `{$tabela}` WHERE `{$campoUsuario}` = ? {$whereStatus} LIMIT 1";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('s', $usuario);
            $stmt->execute();
            $user = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($user && sgl_login_verificar_senha($senha, (string)$user['senha'])) {
                // Migra MD5 antigo para password_hash no primeiro login válido.
                if (strlen((string)$user['senha']) === 32) {
                    $novoHash = password_hash($senha, PASSWORD_DEFAULT);
                    $upd = $conn->prepare("UPDATE `{$tabela}` SET senha=? WHERE id=?");
                    $uid = (int)$user['id'];
                    $upd->bind_param('si', $novoHash, $uid);
                    $upd->execute();
                    $upd->close();
                }

                session_regenerate_id(true);
                $_SESSION['user_id'] = (int)$user['id'];
                $_SESSION['username'] = (string)$user['usuario'];
                $_SESSION['nome'] = (string)$user['nome'];
                $_SESSION['perfil'] = (string)$user['perfil'];
                $_SESSION['ultimo_acesso'] = time();

                header('Location: ../index.php');
                exit();
            }

            $mensagem_erro = 'Usuário ou senha inválidos.';
            $conn->close();
        } catch (Throwable $e) {
            $mensagem_erro = 'Erro ao validar login: ' . $e->getMessage();
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
    <title>ROJEX.AI - Login</title>
    <style>
        *{box-sizing:border-box} body{font-family:Arial,sans-serif;background:linear-gradient(135deg,#0b2440,#f4f6f8 38%,#fff);display:flex;justify-content:center;align-items:center;min-height:100vh;margin:0;padding:20px}.container{background:#fff;padding:34px;border-radius:18px;box-shadow:0 18px 50px rgba(5,22,45,.22);width:420px;text-align:center;border-top:5px solid #164f86}.logo{max-width:260px;max-height:180px;object-fit:contain;margin-bottom:8px}.brand{font-size:30px;font-weight:900;color:#0b3158;margin:6px 0 2px}.subtitle{color:#5f6b7a;font-size:14px;margin-bottom:22px}.form-group{margin-bottom:15px;text-align:left}label{display:block;margin-bottom:7px;color:#25364d;font-weight:700}input[type="text"],input[type="password"]{width:100%;padding:13px;border:1px solid #cfd8e3;border-radius:10px;font-size:15px}input:focus{outline:2px solid rgba(44,111,173,.25);border-color:#2c6fad}button{width:100%;padding:13px;background:#255d91;color:white;border:none;border-radius:10px;cursor:pointer;font-size:16px;font-weight:800;margin-top:8px}button:hover{background:#163e66}.mensagem-erro{color:#842029;background:#f8d7da;border:1px solid #f5c2c7;padding:12px;border-radius:10px;margin-bottom:16px;text-align:left}.rodape{margin-top:20px;color:#7b8794;font-size:12px}.selo{display:inline-block;background:#eef6ff;color:#164f86;border-radius:999px;padding:6px 12px;font-weight:700;font-size:12px;margin-bottom:4px}
    </style>
</head>
<body>
    <div class="container">
        <img src="../assets/img/rojex_ai.png" alt="ROJEX.AI" class="logo">
        <div class="selo">Plataforma SGL Advocacia</div>
        <div class="brand">ROJEX.AI</div>
        <div class="subtitle">Inteligência Artificial para PMEs</div>
        <?php if ($mensagem_erro): ?><div class="mensagem-erro"><?= htmlspecialchars($mensagem_erro, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
        <form action="login.php" method="POST" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
            <div class="form-group"><label for="usuario">Usuário</label><input type="text" id="usuario" name="usuario" required autofocus></div>
            <div class="form-group"><label for="senha">Senha</label><input type="password" id="senha" name="senha" required></div>
            <button type="submit">Entrar</button>
        </form>
        <div class="rodape">Marca ROJEX.AI na entrada. Logo do escritório continua configurável dentro do sistema.</div>
    </div>
</body>
</html>
