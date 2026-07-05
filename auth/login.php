<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../core/Empresa.php';

iniciarSessaoSegura();

$empresa = Empresa::criar();
date_default_timezone_set($empresa->timezone());

if (usuarioLogado()) {
    header('Location: ../index.php');
    exit();
}

$mensagem_erro = '';

function colunaExiste(mysqli $conn, string $tabela, string $coluna): bool
{
    $stmt = $conn->prepare("SHOW COLUMNS FROM `$tabela` LIKE ?");
    $stmt->bind_param('s', $coluna);
    $stmt->execute();
    $existe = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    return $existe;
}

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
            $user = null;

            /*
             * Correção 02:
             * O login agora consulta as tabelas de usuário de forma simples e tolerante.
             * Isso evita erro por diferença estrutural entre usuarios e usuarios_sistema.
             */
            $consultasLogin = [
                "SELECT id, nome, usuario, senha, perfil, status FROM usuarios WHERE usuario = ? LIMIT 1",
                "SELECT id, nome, usuario, senha, perfil, status FROM usuarios_sistema WHERE usuario = ? LIMIT 1",
                "SELECT id, nome, usuario, senha, perfil, status FROM usuarios_sistema_backup_login WHERE usuario = ? LIMIT 1",
            ];

            foreach ($consultasLogin as $sql) {
                try {
                    $stmt = $conn->prepare($sql);
                    $stmt->bind_param('s', $usuario);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    $registro = $result ? $result->fetch_assoc() : null;
                    if ($result instanceof mysqli_result) {
                        $result->free();
                    }
                    $stmt->close();

                    if ($registro) {
                        $status = trim((string)($registro['status'] ?? 'Ativo'));
                        if ($status === '' || strcasecmp($status, 'Ativo') === 0 || $status === '1') {
                            $user = $registro;
                            break;
                        }
                    }
                } catch (Throwable $eTabela) {
                    error_log('ROJEX LOGIN consulta ignorada: ' . $eTabela->getMessage());
                    continue;
                }
            }

            $senhaOk = false;
            if ($user) {
                $hash = (string)$user['senha'];
                $senhaOk = password_verify($senha, $hash)
                    || hash_equals($hash, md5($senha))
                    || hash_equals($hash, $senha);
            }

            if ($user && $senhaOk) {
                session_regenerate_id(true);
                $_SESSION['user_id'] = (int)$user['id'];
                $_SESSION['username'] = (string)$user['usuario'];
                $_SESSION['nome'] = (string)$user['nome'];
                $_SESSION['perfil'] = (string)$user['perfil'];
                $_SESSION['ultimo_acesso'] = time();
                $conn->close();
                header('Location: ../index.php');
                exit();
            }

            $mensagem_erro = 'Usuário ou senha inválidos.';
            $conn->close();
        } catch (Throwable $e) {
            error_log('ROJEX LOGIN ERRO GERAL: ' . $e->getMessage());
            $mensagem_erro = 'Erro ao tentar fazer login. Verifique o banco de dados e tente novamente.';
        }
    }
}

$csrfToken = gerarTokenCsrf();
$logoLogin = '../' . $empresa->logoPrincipal();
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - <?= htmlspecialchars($empresa->nomeSistema(), ENT_QUOTES, 'UTF-8') ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        :root { --primary: <?= htmlspecialchars($empresa->corPrimaria(), ENT_QUOTES, 'UTF-8') ?>; --secondary: <?= htmlspecialchars($empresa->corSecundaria(), ENT_QUOTES, 'UTF-8') ?>; --accent: <?= htmlspecialchars($empresa->corAccent(), ENT_QUOTES, 'UTF-8') ?>; }
        * { box-sizing: border-box; }
        body { margin:0; min-height:100vh; font-family: Arial, sans-serif; display:flex; align-items:center; justify-content:center; background: radial-gradient(circle at top left, rgba(13,110,253,.30), transparent 30%), linear-gradient(135deg, #06141f, #102a3d 48%, #070b12); padding:24px; }
        .login-shell { width:100%; max-width:980px; display:grid; grid-template-columns: 1.05fr .95fr; background:rgba(255,255,255,.96); border-radius:26px; overflow:hidden; box-shadow:0 28px 90px rgba(0,0,0,.35); }
        .brand-panel { padding:44px; color:#fff; background: linear-gradient(160deg, var(--primary), #07111a); position:relative; overflow:hidden; }
        .brand-panel:after { content:""; position:absolute; width:340px; height:340px; border-radius:50%; right:-120px; top:-120px; background:rgba(255,255,255,.08); }
        .brand-logo { max-width:240px; max-height:160px; object-fit:contain; background:rgba(255,255,255,.08); border:1px solid rgba(255,255,255,.15); border-radius:18px; padding:14px; margin-bottom:28px; }
        .brand-panel h1 { margin:0 0 12px; font-size:36px; letter-spacing:.5px; }
        .brand-panel p { margin:0; color:rgba(255,255,255,.78); line-height:1.6; }
        .badge { display:inline-flex; align-items:center; gap:8px; padding:8px 12px; border-radius:999px; background:rgba(212,175,55,.14); color:var(--accent); margin-top:28px; font-weight:700; font-size:13px; }
        .form-panel { padding:48px 44px; display:flex; flex-direction:column; justify-content:center; }
        .form-panel h2 { margin:0 0 8px; color:#102a3d; font-size:28px; }
        .subtitle { color:#667085; margin:0 0 28px; }
        .form-group { margin-bottom:16px; }
        label { display:block; margin-bottom:7px; color:#344054; font-weight:700; }
        input[type="text"], input[type="password"] { width:100%; padding:13px 14px; border:1px solid #d0d5dd; border-radius:12px; font-size:15px; outline:none; transition:.2s; }
        input:focus { border-color:var(--secondary); box-shadow:0 0 0 4px rgba(13,110,253,.12); }
        button { width:100%; padding:13px; background:linear-gradient(135deg, var(--secondary), var(--primary)); color:white; border:none; border-radius:12px; cursor:pointer; font-size:16px; font-weight:800; margin-top:8px; }
        .mensagem-erro { color:#842029; background:#f8d7da; border:1px solid #f5c2c7; padding:12px; border-radius:12px; margin-bottom:18px; text-align:center; }
        .powered { margin-top:24px; color:#667085; font-size:12px; text-align:center; }
        @media (max-width: 800px) { .login-shell { grid-template-columns:1fr; } .brand-panel { display:none; } .form-panel { padding:34px 24px; } }
    </style>
</head>
<body>
    <div class="login-shell">
        <section class="brand-panel">
            <img src="<?= htmlspecialchars($logoLogin, ENT_QUOTES, 'UTF-8') ?>?v=<?= time() ?>" alt="Logo" class="brand-logo">
            <h1><?= htmlspecialchars($empresa->nomeSistema(), ENT_QUOTES, 'UTF-8') ?></h1>
            <p>ERP Jurídico Inteligente para gestão profissional de escritórios, processos, clientes, agenda, financeiro e relatórios.</p>
            <div class="badge"><i class="bi bi-shield-check"></i> Ambiente seguro e profissional</div>
        </section>
        <section class="form-panel">
            <h2>Acessar sistema</h2>
            <p class="subtitle"><?= htmlspecialchars($empresa->nomeEscritorio(), ENT_QUOTES, 'UTF-8') ?></p>
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
                <button type="submit"><i class="bi bi-box-arrow-in-right"></i> Entrar</button>
            </form>
            <div class="powered"><?= htmlspecialchars($empresa->poweredBy(), ENT_QUOTES, 'UTF-8') ?></div>
        </section>
    </div>
</body>
</html>
