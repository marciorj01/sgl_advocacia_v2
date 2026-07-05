<?php
date_default_timezone_set('America/Sao_Paulo');

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/core/Empresa.php';

iniciarSessaoSegura();
exigirLogin('auth/login.php');

$empresa = Empresa::criar();
date_default_timezone_set($empresa->timezone());

$modulo = isset($_GET['mod']) ? trim((string)$_GET['mod']) : 'dashboard';
$modulos_validos = [
    'dashboard',
    'advogados',
    'clientes',
    'processos',
    'honorarios',
    'agenda',
    'financeiro',
    'configuracoes',
];

if (!in_array($modulo, $modulos_validos, true)) {
    $modulo = 'dashboard';
}

$titulos = [
    'dashboard' => 'Dashboard',
    'advogados' => 'Advogados',
    'clientes' => 'Clientes',
    'processos' => 'Processos',
    'honorarios' => 'Honorários',
    'agenda' => 'Agenda',
    'financeiro' => 'Financeiro',
    'configuracoes' => 'Configurações',
];

$tituloPagina = $titulos[$modulo] ?? 'Dashboard';
$nomeUsuario = $_SESSION['nome'] ?? $_SESSION['username'] ?? 'Usuário';
$perfilUsuario = $_SESSION['perfil'] ?? 'Usuário';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($empresa->nomeSistema(), ENT_QUOTES, 'UTF-8') ?> - <?= htmlspecialchars($tituloPagina, ENT_QUOTES, 'UTF-8') ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
    <?php require_once __DIR__ . '/config/tema.php'; ?>
</head>
<body>

<div class="d-flex">
    <nav class="sidebar text-white p-3" style="min-height:100vh; width:230px; min-width:230px; position:sticky; top:0; height:100vh; overflow-y:auto;">
        <div class="text-center mb-3 rojex-brand-box">
            <img src="<?= htmlspecialchars($empresa->logoPrincipal(), ENT_QUOTES, 'UTF-8') ?>?v=<?= time() ?>" data-rojex-logo alt="<?= htmlspecialchars($empresa->nomeEscritorio(), ENT_QUOTES, 'UTF-8') ?>" class="img-fluid rounded" style="max-width:100%; max-height:115px; object-fit:contain;">
            <?php if ($empresa->temLogoEscritorio()): ?>
                <div class="rojex-powered mt-2"><?= htmlspecialchars($empresa->poweredBy(), ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>
        </div>

        <div class="text-center mb-3">
            <div class="fw-bold text-warning mb-1"><?= htmlspecialchars($empresa->nomeEscritorio(), ENT_QUOTES, 'UTF-8') ?></div>
            <p class="mb-0">Olá, <strong><?= htmlspecialchars($nomeUsuario, ENT_QUOTES, 'UTF-8') ?></strong>!</p>
            <small class="d-block text-light opacity-75"><?= htmlspecialchars($perfilUsuario, ENT_QUOTES, 'UTF-8') ?></small>
            <small class="d-block text-success mt-1"><i class="bi bi-circle-fill" style="font-size:0.45rem;"></i> Online</small>
        </div>
        <hr class="border-secondary">

        <ul class="nav flex-column gap-1">
            <li><a href="?mod=dashboard" class="nav-link text-white <?= $modulo === 'dashboard' ? 'active bg-secondary rounded' : '' ?>"><i class="bi bi-speedometer2 me-2"></i>Dashboard</a></li>
            <li><a href="?mod=advogados" class="nav-link text-white <?= $modulo === 'advogados' ? 'active bg-secondary rounded' : '' ?>"><i class="bi bi-person-badge me-2"></i>Advogados</a></li>
            <li><a href="?mod=clientes" class="nav-link text-white <?= $modulo === 'clientes' ? 'active bg-secondary rounded' : '' ?>"><i class="bi bi-people me-2"></i>Clientes</a></li>
            <li><a href="?mod=processos" class="nav-link text-white <?= $modulo === 'processos' ? 'active bg-secondary rounded' : '' ?>"><i class="bi bi-folder2-open me-2"></i>Processos</a></li>
            <li><a href="?mod=honorarios" class="nav-link text-white <?= $modulo === 'honorarios' ? 'active bg-secondary rounded' : '' ?>"><i class="bi bi-cash-coin me-2"></i>Honorários</a></li>
            <li><a href="?mod=agenda" class="nav-link text-white <?= $modulo === 'agenda' ? 'active bg-secondary rounded' : '' ?>"><i class="bi bi-calendar-event me-2"></i>Agenda</a></li>
            <li><a href="?mod=financeiro" class="nav-link text-white <?= $modulo === 'financeiro' ? 'active bg-secondary rounded' : '' ?>"><i class="bi bi-bar-chart me-2"></i>Financeiro</a></li>
            <li><a href="?mod=configuracoes" class="nav-link text-white <?= $modulo === 'configuracoes' ? 'active bg-secondary rounded' : '' ?>"><i class="bi bi-gear me-2"></i>Configurações</a></li>
            <li class="mt-3"><a href="auth/alterar_senha.php" class="nav-link text-white"><i class="bi bi-key me-2"></i>Alterar Senha</a></li>
            <li><a href="auth/logout.php" class="nav-link text-white"><i class="bi bi-box-arrow-right me-2"></i>Sair</a></li>
        </ul>

        <div class="mt-auto pt-4" style="position:absolute; bottom:16px; left:0; right:0; padding:0 16px;">
            <hr class="border-secondary">
            <small class="text-light opacity-75 d-block" style="font-size:0.7rem;">ROJEX.AI v4.8.9</small>
            <small class="rojex-powered d-block"><?= htmlspecialchars($empresa->poweredBy(), ENT_QUOTES, 'UTF-8') ?></small>
            <small class="text-success d-block" style="font-size:0.7rem;"><i class="bi bi-database-check"></i> Banco conectado</small>
        </div>
    </nav>

    <main class="flex-grow-1 p-4 bg-light" style="min-width:0;">
        <?php
        $arquivo = __DIR__ . "/modules/{$modulo}.php";
        if (file_exists($arquivo)) {
            include $arquivo;
        } else {
            echo "<div class='alert alert-danger'><strong>Módulo não encontrado:</strong> " . htmlspecialchars($modulo, ENT_QUOTES, 'UTF-8') . "</div>";
        }
        ?>
    </main>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="assets/js/main.js"></script>
</body>
</html>
