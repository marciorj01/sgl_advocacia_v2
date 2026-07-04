<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';

iniciarSessaoSegura();

if (usuarioLogado()) {
    header('Location: ../index.php');
    exit();
}

$mensagem_erro = '';

/**
 * Verifica se uma tabela existe no banco atual.
 */
function sglTabelaExiste(mysqli $conn, string $tabela): bool
{
    $sql = "SELECT COUNT(*) AS total
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('s', $tabela);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return (int)($row['total'] ?? 0) > 0;
}

/**
 * Verifica se uma coluna existe em determinada tabela.
 */
function sglColunaExiste(mysqli $conn, string $tabela, string $coluna): bool
{
    $sql = "SELECT COUNT(*) AS total
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
              AND COLUMN_NAME = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ss', $tabela, $coluna);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return (int)($row['total'] ?? 0) > 0;
}

/**
 * Retorna a tabela correta de usuários.
 * Prioridade: usuarios_sistema, pois foi a tabela criada após a recuperação do MySQL/XAMPP.
 */
function sglObterTabelaUsuarios(mysqli $conn): string
{
    if (sglTabelaExiste($conn, 'usuarios_sistema')) {
        return 'usuarios_sistema';
    }

    if (sglTabelaExiste($conn, 'usuarios')) {
        return 'usuarios';
    }

    throw new RuntimeException('Nenhuma tabela de usuários foi encontrada.');
}

/**
 * Valida senha atual em password_hash() ou MD5 legado.
 */
function sglSenhaConfere(string $senhaDigitada, string $hashBanco): bool
{
    if (password_get_info($hashBanco)['algo'] !== 0 && password_verify($senhaDigitada, $hashBanco)) {
        return true;
    }

    return hash_equals(strtolower($hashBanco), md5($senhaDigitada));
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
            $tabelaUsuarios = sglObterTabelaUsuarios($conn);

            $temAtivo = sglColunaExiste($conn, $tabelaUsuarios, 'ativo');
            $temStatus = sglColunaExiste($conn, $tabelaUsuarios, 'status');
            $temEmail = sglColunaExiste($conn, $tabelaUsuarios, 'email');
            $temUltimoLogin = sglColunaExiste($conn, $tabelaUsuarios, 'ultimo_login');
            $temAtualizadoEm = sglColunaExiste($conn, $tabelaUsuarios, 'atualizado_em');

            $whereStatus = '';
            if ($temAtivo) {
                $whereStatus = ' AND ativo = 1';
            } elseif ($temStatus) {
                $whereStatus = " AND status = 'Ativo'";
            }

            $campoBuscaEmail = $temEmail ? ' OR email = ?' : '';

            $sql = "SELECT id, nome, usuario, senha, perfil
                    FROM `$tabelaUsuarios`
                    WHERE (usuario = ?$campoBuscaEmail)
                    $whereStatus
                    LIMIT 1";

            $stmt = $conn->prepare($sql);

            if ($temEmail) {
                $stmt->bind_param('ss', $usuario, $usuario);
            } else {
                $stmt->bind_param('s', $usuario);
            }

            $stmt->execute();
            $result = $stmt->get_result();
            $user = $result->fetch_assoc();
            $stmt->close();

            if ($user && sglSenhaConfere($senha, (string)$user['senha'])) {
                // Migra automaticamente MD5 legado para password_hash() no primeiro login correto.
                if (password_get_info((string)$user['senha'])['algo'] === 0) {
                    $novaSenhaHash = password_hash($senha, PASSWORD_DEFAULT);

                    if ($temAtualizadoEm) {
                        $stmtUpdateSenha = $conn->prepare("UPDATE `$tabelaUsuarios` SET senha = ?, atualizado_em = NOW() WHERE id = ?");
                    } else {
                        $stmtUpdateSenha = $conn->prepare("UPDATE `$tabelaUsuarios` SET senha = ? WHERE id = ?");
                    }

                    $idUsuario = (int)$user['id'];
                    $stmtUpdateSenha->bind_param('si', $novaSenhaHash, $idUsuario);
                    $stmtUpdateSenha->execute();
                    $stmtUpdateSenha->close();
                }

                if ($temUltimoLogin) {
                    $idUsuario = (int)$user['id'];
                    $stmtUltimoLogin = $conn->prepare("UPDATE `$tabelaUsuarios` SET ultimo_login = NOW() WHERE id = ?");
                    $stmtUltimoLogin->bind_param('i', $idUsuario);
                    $stmtUltimoLogin->execute();
                    $stmtUltimoLogin->close();
                }

                session_regenerate_id(true);

                $_SESSION['user_id'] = (int)$user['id'];
                $_SESSION['username'] = (string)$user['usuario'];
                $_SESSION['nome'] = (string)$user['nome'];
                $_SESSION['perfil'] = (string)($user['perfil'] ?? 'Usuário');
                $_SESSION['tabela_usuarios'] = $tabelaUsuarios;
                $_SESSION['ultimo_acesso'] = time();

                $conn->close();

                header('Location: ../index.php');
                exit();
            }

            $conn->close();
            $mensagem_erro = 'Usuário ou senha inválidos.';
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
    <title>Login - SGL Advocacia</title>
    <style>
        body { font-family: Arial, sans-serif; background: #f4f6f8; display: flex; justify-content: center; align-items: center; min-height: 100vh; margin: 0; border-top: 4px solid #263447; }
        .container { background: #fff; padding: 32px; border-radius: 12px; box-shadow: 0 10px 30px rgba(0,0,0,.08); width: 420px; min-height: 560px; text-align: center; }
        h1 { color: #14263b; margin: 0 0 8px; font-size: 28px; }
        .subtitulo { color: #5e6878; margin-bottom: 26px; font-size: 14px; }
        .form-group { margin-bottom: 16px; text-align: left; }
        label { display: block; margin-bottom: 6px; color: #253447; font-weight: 700; }
        input[type="text"], input[type="password"] { width: 100%; padding: 13px; border: 1px solid #cfd6df; border-radius: 8px; box-sizing: border-box; font-size: 15px; }
        input:focus { outline: 2px solid #111; border-color: #2c5f8f; }
        button { width: 100%; padding: 13px; background: #245a89; color: white; border: none; border-radius: 8px; cursor: pointer; font-size: 16px; font-weight: 700; margin-top: 2px; }
        button:hover { background: #1a3c5e; }
        .mensagem-erro { color: #b00000; background: #f8d7da; border: 1px solid #f5b5bc; padding: 12px; border-radius: 8px; margin-bottom: 18px; text-align: left; font-size: 14px; }
        .ajuda { margin-top: 18px; color: #6c757d; font-size: 12px; }
    </style>
</head>
<body>
    <div class="container">
        <h1>SGL Advocacia</h1>
        <div class="subtitulo">Acesso ao sistema</div>

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

        <div class="ajuda">Usuário padrão recuperado: admin</div>
    </div>
</body>
</html>
