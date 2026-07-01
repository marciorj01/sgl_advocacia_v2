<?php
date_default_timezone_set('America/Sao_Paulo');
// C:\xampp\htdocs\sistema_sgl\index.php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/auth.php';
iniciarSessaoSegura();
exigirLogin('auth/login.php');

// Roteamento com whitelist de segurança
$modulo = isset($_GET['mod']) ? trim($_GET['mod']) : 'dashboard';
$modulos_validos = [
    'dashboard',
    'advogados',
    'clientes',
    'processos',
    'honorarios',
    'agenda',
    'financeiro',
    'configuracoes',
    // Adicione aqui outros módulos que você criar, como um para gerenciar usuários ou advogadas
];

if (!in_array($modulo, $modulos_validos, true)) {
    $modulo = 'dashboard';
}

// Títulos por módulo para o <title> da página
$titulos = [
    'dashboard'  => 'Dashboard',
    'advogados'  => 'Advogados',
    'clientes'   => 'Clientes',
    'processos'  => 'Processos',
    'honorarios' => 'Honorários',
    'agenda'     => 'Agenda',
    'financeiro' => 'Financeiro',
    'configuracoes' => 'Configurações',
];

$tituloPagina = $titulos[$modulo] ?? 'SGL';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SGL - <?= htmlspecialchars($tituloPagina) ?> | Escritório de Advocacia</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
    <?php require_once __DIR__ . '/config/tema.php'; ?>
</head>
<body>

<div class="d-flex">

    <!-- ============================
         SIDEBAR
    ============================= -->
    <nav class="sidebar text-white p-3"
         style="min-height:100vh; width:220px; min-width:220px; position:sticky; top:0; height:100vh; overflow-y:auto;">

        <div class="text-center mb-4">
            <img src="assets/img/logo_custom.png" alt="Struzik, Guimarães & Lecz" class="img-fluid rounded" style="max-width: 100%; height: auto; border: 1px solid rgba(255,255,255,0.08);">
        </div>

        <!-- Informações do Usuário Logado -->
        <div class="text-center mb-3">
            <div class="fw-bold text-warning mb-1">SGL Advocacia</div>
            <p class="mb-0">Olá, <strong><?= htmlspecialchars($_SESSION['nome'] ?? $_SESSION['username']) ?></strong>!</p>
            <small class="d-block text-light opacity-75"><?= htmlspecialchars($_SESSION['perfil'] ?? 'Usuário') ?></small>
            <small class="d-block text-success mt-1"><i class="bi bi-circle-fill" style="font-size:0.45rem;"></i> Online</small>
        </div>
        <hr class="border-secondary">

        <ul class="nav flex-column gap-1">

            <li>
                <a href="?mod=dashboard"
                   class="nav-link text-white <?= $modulo === 'dashboard' ? 'active bg-secondary rounded' : '' ?>">
                    <i class="bi bi-speedometer2 me-2"></i>Dashboard
                </a>
            </li>

            <li>
                <a href="?mod=advogados"
                   class="nav-link text-white <?= $modulo === 'advogados' ? 'active bg-secondary rounded' : '' ?>">
                    <i class="bi bi-person-badge me-2"></i>Advogados
                </a>
            </li>

            <li>
                <a href="?mod=clientes"
                   class="nav-link text-white <?= $modulo === 'clientes' ? 'active bg-secondary rounded' : '' ?>">
                    <i class="bi bi-people me-2"></i>Clientes
                </a>
            </li>

            <li>
                <a href="?mod=processos"
                   class="nav-link text-white <?= $modulo === 'processos' ? 'active bg-secondary rounded' : '' ?>">
                    <i class="bi bi-folder2-open me-2"></i>Processos
                </a>
            </li>

            <li>
                <a href="?mod=honorarios"
                   class="nav-link text-white <?= $modulo === 'honorarios' ? 'active bg-secondary rounded' : '' ?>">
                    <i class="bi bi-cash-coin me-2"></i>Honorários
                </a>
            </li>

            <li>
                <a href="?mod=agenda"
                   class="nav-link text-white <?= $modulo === 'agenda' ? 'active bg-secondary rounded' : '' ?>">
                    <i class="bi bi-calendar-event me-2"></i>Agenda
                </a>
            </li>

            <li>
                <a href="?mod=financeiro"
                   class="nav-link text-white <?= $modulo === 'financeiro' ? 'active bg-secondary rounded' : '' ?>">
                    <i class="bi bi-bar-chart me-2"></i>Financeiro
                </a>
            </li>

            <!-- Links de Configuração e Logout -->
            <li>
                <a href="?mod=configuracoes"
                   class="nav-link text-white <?= $modulo === 'configuracoes' ? 'active bg-secondary rounded' : '' ?>">
                    <i class="bi bi-gear me-2"></i>Configurações
                </a>
            </li>
            <li class="mt-3">
                <a href="auth/alterar_senha.php" class="nav-link text-white">
                    <i class="bi bi-key me-2"></i>Alterar Senha
                </a>
            </li>
            <li>
                <a href="auth/logout.php" class="nav-link text-white">
                    <i class="bi bi-box-arrow-right me-2"></i>Sair
                </a>
            </li>

        </ul>

        <!-- Rodapé da sidebar -->
        <div class="mt-auto pt-4" style="position:absolute; bottom:16px; left:0; right:0; padding:0 16px;">
            <hr class="border-secondary">
            <small class="text-light opacity-75 d-block" style="font-size:0.7rem;">
                Versão 1.0.0
            </small>
            <small class="text-light opacity-75 d-block" style="font-size:0.7rem;">
                Atualizado em <?= date('d/m/Y') ?>
            </small>
            <small class="text-success d-block" style="font-size:0.7rem;">
                <i class="bi bi-database-check"></i> Banco conectado
            </small>
        </div>

    </nav>

    <!-- ============================
         CONTEÚDO PRINCIPAL
    ============================= -->
    <main class="flex-grow-1 p-4 bg-light" style="min-width:0;">

        <?php
        $arquivo = __DIR__ . "/modules/{$modulo}.php";

        if (file_exists($arquivo)) {
            include $arquivo;
        } else {
            echo "
            <div class='alert alert-danger'>
                <strong>Módulo não encontrado:</strong> " . htmlspecialchars($modulo) . "
                <br><small>Verifique se o arquivo <code>modules/{$modulo}.php</code> existe.</small>
            </div>";
        }
        ?>

    </main>

</div><!-- fim d-flex -->

<!-- Scripts — apenas UMA vez cada -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="assets/js/main.js"></script>

</body>
</html>