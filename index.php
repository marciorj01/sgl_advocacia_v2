<?php
date_default_timezone_set('America/Sao_Paulo');
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/integracoes.php';
iniciarSessaoSegura();
exigirLogin('auth/login.php');

$modulo = isset($_GET['mod']) ? trim($_GET['mod']) : 'dashboard';
$modulos_validos = [
    'dashboard', 'advogados', 'clientes', 'processos', 'honorarios', 'agenda',
    'financeiro', 'recibos', 'documentos', 'modelos', 'busca_global', 'central_inteligente', 'ia_juridica', 'configuracoes'
];
if (!in_array($modulo, $modulos_validos, true)) { $modulo = 'dashboard'; }

$titulos = [
    'dashboard' => 'Dashboard',
    'advogados' => 'Advogados',
    'clientes' => 'Clientes',
    'processos' => 'Processos',
    'honorarios' => 'Honorários',
    'agenda' => 'Agenda',
    'financeiro' => 'Financeiro',
    'recibos' => 'Recibos',
    'documentos' => 'Documentos',
    'modelos' => 'Modelos Jurídicos',
    'busca_global' => 'Busca Global',
    'central_inteligente' => 'Central Inteligente',
    'ia_juridica' => 'IA para Advogados',
    'configuracoes' => 'Configurações',
];
$tituloPagina = $titulos[$modulo] ?? 'SGL';

function sgl_menu_active(string $atual, array $itens): string {
    return in_array($atual, $itens, true) ? 'show' : '';
}
function sgl_link_active(string $atual, string $item): string {
    return $atual === $item ? 'active' : '';
}
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
    <style>
        :root { --sgl-sidebar-width: 238px; }
        body { overflow-x: hidden; }
        .sgl-layout { min-height: 100vh; }
        .sgl-sidebar {
            width: var(--sgl-sidebar-width);
            min-width: var(--sgl-sidebar-width);
            min-height: 100vh;
            height: 100vh;
            position: sticky;
            top: 0;
            overflow-y: auto;
            overflow-x: hidden;
            background: linear-gradient(180deg, #123a5a 0%, #153f63 100%);
            display: flex;
            flex-direction: column;
        }
        .sgl-logo-wrap { text-align:center; padding: 12px 12px 8px; }
        .sgl-logo-wrap img {
            max-width: 96px;
            max-height: 96px;
            object-fit: contain;
            border-radius: 10px;
            border: 1px solid rgba(255,255,255,.12);
            box-shadow: 0 8px 22px rgba(0,0,0,.18);
        }
        .sgl-user-box { text-align:center; padding: 2px 12px 10px; }
        .sgl-user-box .brand { color:#ffc107; font-weight:800; font-size:.86rem; }
        .sgl-user-box .hello { color:#fff; font-size:.86rem; line-height:1.2; }
        .sgl-user-box .role { color:rgba(255,255,255,.72); font-size:.76rem; }
        .sgl-user-box .online { color:#1ec773; font-size:.72rem; }
        .sgl-menu { padding: 8px 10px 12px; flex: 1; }
        .sgl-menu-title {
            color: rgba(255,255,255,.52);
            font-size: .67rem;
            letter-spacing: .06em;
            text-transform: uppercase;
            padding: 10px 10px 4px;
            font-weight: 700;
        }
        .sgl-menu .nav-link, .sgl-menu .sgl-group-toggle {
            color: rgba(255,255,255,.92);
            border-radius: 9px;
            padding: 8px 10px;
            font-size: .88rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
            width: 100%;
            border: 0;
            background: transparent;
            text-align: left;
        }
        .sgl-menu .nav-link:hover, .sgl-menu .sgl-group-toggle:hover { background: rgba(255,255,255,.10); color:#fff; }
        .sgl-menu .nav-link.active { background:#2f7cc0; color:#fff; box-shadow: inset 3px 0 0 #ffc107; }
        .sgl-submenu { margin-left: 8px; padding-left: 8px; border-left: 1px solid rgba(255,255,255,.16); }
        .sgl-submenu .nav-link { padding: 7px 10px; font-size: .83rem; font-weight: 500; }
        .sgl-group-toggle .chev { margin-left:auto; transition: transform .18s ease; }
        .sgl-group-toggle[aria-expanded="true"] .chev { transform: rotate(180deg); }
        .sgl-footer {
            padding: 10px 14px 14px;
            color: rgba(255,255,255,.70);
            font-size: .70rem;
            border-top: 1px solid rgba(255,255,255,.12);
        }
        .sgl-main { min-width:0; background:#f5f7fa; }

        .sgl-topbar {
            background:#fff;
            border:1px solid rgba(15,23,42,.08);
            border-radius:14px;
            padding:10px 12px;
            margin-bottom:18px;
            box-shadow:0 4px 18px rgba(15,23,42,.05);
        }
        .sgl-global-search .form-control { border-radius: 12px; }
        .sgl-global-search .btn { border-radius: 12px; }
        @media (max-width: 991px) {
            .sgl-layout { display:block!important; }
            .sgl-sidebar { position:relative; width:100%; min-width:100%; height:auto; min-height:auto; }
            .sgl-main { padding: 1rem!important; }
        }
    </style>
</head>
<body>
<div class="d-flex sgl-layout">
    <nav class="sgl-sidebar text-white">
        <div class="sgl-logo-wrap">
            <img src="<?= htmlspecialchars($logo_src ?? 'assets/img/logo_custom.png') ?>?v=<?= time() ?>" alt="<?= htmlspecialchars($nome_escritorio ?? 'SGL Advocacia') ?>">
        </div>
        <div class="sgl-user-box">
            <div class="brand mb-1"><?= htmlspecialchars($nome_escritorio ?? 'SGL Advocacia') ?></div>
            <div class="hello">Olá, <strong><?= htmlspecialchars($_SESSION['nome'] ?? $_SESSION['username'] ?? 'Usuário') ?></strong></div>
            <div class="role"><?= htmlspecialchars($_SESSION['perfil'] ?? 'Usuário') ?></div>
            <div class="online"><i class="bi bi-circle-fill" style="font-size:.42rem"></i> Online</div>
        </div>

        <div class="sgl-menu">
            <a href="?mod=dashboard" class="nav-link <?= sgl_link_active($modulo, 'dashboard') ?>"><i class="bi bi-speedometer2"></i> Dashboard</a>
            <a href="?mod=busca_global" class="nav-link <?= sgl_link_active($modulo, 'busca_global') ?>"><i class="bi bi-search"></i> Busca Global</a>
            <a href="?mod=central_inteligente" class="nav-link <?= sgl_link_active($modulo, 'central_inteligente') ?>"><i class="bi bi-lightbulb"></i> Central Inteligente</a>
            <a href="?mod=ia_juridica" class="nav-link <?= sgl_link_active($modulo, 'ia_juridica') ?>"><i class="bi bi-robot"></i> IA Jurídica</a>

            <div class="sgl-menu-title">Cadastros</div>
            <button class="sgl-group-toggle" data-bs-toggle="collapse" data-bs-target="#menuCadastros" aria-expanded="<?= sgl_menu_active($modulo, ['advogados','clientes']) ? 'true' : 'false' ?>">
                <i class="bi bi-people"></i> Cadastros <i class="bi bi-chevron-down chev"></i>
            </button>
            <div class="collapse <?= sgl_menu_active($modulo, ['advogados','clientes']) ?>" id="menuCadastros">
                <div class="sgl-submenu">
                    <a href="?mod=advogados" class="nav-link <?= sgl_link_active($modulo, 'advogados') ?>"><i class="bi bi-person-badge"></i> Advogados</a>
                    <a href="?mod=clientes" class="nav-link <?= sgl_link_active($modulo, 'clientes') ?>"><i class="bi bi-person-lines-fill"></i> Clientes</a>
                </div>
            </div>

            <div class="sgl-menu-title">Jurídico</div>
            <button class="sgl-group-toggle" data-bs-toggle="collapse" data-bs-target="#menuJuridico" aria-expanded="<?= sgl_menu_active($modulo, ['processos','agenda','documentos','modelos']) ? 'true' : 'false' ?>">
                <i class="bi bi-briefcase"></i> Jurídico <i class="bi bi-chevron-down chev"></i>
            </button>
            <div class="collapse <?= sgl_menu_active($modulo, ['processos','agenda','documentos','modelos']) ?>" id="menuJuridico">
                <div class="sgl-submenu">
                    <a href="?mod=processos" class="nav-link <?= sgl_link_active($modulo, 'processos') ?>"><i class="bi bi-folder2-open"></i> Processos</a>
                    <a href="?mod=agenda" class="nav-link <?= sgl_link_active($modulo, 'agenda') ?>"><i class="bi bi-calendar-event"></i> Agenda</a>
                    <a href="?mod=documentos" class="nav-link <?= sgl_link_active($modulo, 'documentos') ?>"><i class="bi bi-file-earmark-arrow-up"></i> Documentos</a>
                    <a href="?mod=modelos" class="nav-link <?= sgl_link_active($modulo, 'modelos') ?>"><i class="bi bi-journal-text"></i> Modelos</a>
                </div>
            </div>

            <div class="sgl-menu-title">Financeiro</div>
            <button class="sgl-group-toggle" data-bs-toggle="collapse" data-bs-target="#menuFinanceiro" aria-expanded="<?= sgl_menu_active($modulo, ['honorarios','financeiro','recibos']) ? 'true' : 'false' ?>">
                <i class="bi bi-cash-coin"></i> Financeiro <i class="bi bi-chevron-down chev"></i>
            </button>
            <div class="collapse <?= sgl_menu_active($modulo, ['honorarios','financeiro','recibos']) ?>" id="menuFinanceiro">
                <div class="sgl-submenu">
                    <a href="?mod=honorarios" class="nav-link <?= sgl_link_active($modulo, 'honorarios') ?>"><i class="bi bi-cash-stack"></i> Honorários</a>
                    <a href="?mod=financeiro" class="nav-link <?= sgl_link_active($modulo, 'financeiro') ?>"><i class="bi bi-bar-chart"></i> Financeiro</a>
                    <a href="?mod=recibos" class="nav-link <?= sgl_link_active($modulo, 'recibos') ?>"><i class="bi bi-receipt"></i> Recibos</a>
                </div>
            </div>

            <div class="sgl-menu-title">Administração</div>
            <a href="?mod=configuracoes" class="nav-link <?= sgl_link_active($modulo, 'configuracoes') ?>"><i class="bi bi-gear"></i> Configurações</a>
            <a href="auth/alterar_senha.php" class="nav-link"><i class="bi bi-key"></i> Alterar senha</a>
            <a href="auth/logout.php" class="nav-link"><i class="bi bi-box-arrow-right"></i> Sair</a>
        </div>

        <div class="sgl-footer">
            <div>Versão 1.0.0</div>
            <div>Atualizado em <?= date('d/m/Y') ?></div>
            <div class="text-success"><i class="bi bi-database-check"></i> Banco conectado</div>
        </div>
    </nav>

    <main class="flex-grow-1 p-4 sgl-main">
        <div class="sgl-topbar d-flex flex-column flex-xl-row gap-2 align-items-xl-center justify-content-between">
            <div>
                <strong class="text-primary"><i class="bi bi-search"></i> Busca Global Inteligente</strong>
                <div class="small text-muted">Pesquise cliente, CPF, processo, recibo, documento, financeiro ou modelo.</div>
            </div>
            <form class="sgl-global-search d-flex gap-2" method="get" action="index.php" style="min-width:min(620px,100%);">
                <input type="hidden" name="mod" value="busca_global">
                <input type="text" name="q" class="form-control" placeholder="Digite nome, CPF, processo, OAB, recibo, CR, documento..." value="<?= htmlspecialchars($modulo === 'busca_global' ? ($_GET['q'] ?? '') : '', ENT_QUOTES, 'UTF-8') ?>">
                <button class="btn btn-primary px-4" type="submit"><i class="bi bi-search"></i></button>
            </form>
        </div>
        <?php
        $arquivo = __DIR__ . "/modules/{$modulo}.php";
        if (file_exists($arquivo)) {
            include $arquivo;
        } else {
            echo "<div class='alert alert-danger'><strong>Módulo não encontrado:</strong> " . htmlspecialchars($modulo) . "</div>";
        }
        ?>
    </main>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="assets/js/main.js"></script>
</body>
</html>
