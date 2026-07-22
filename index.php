<?php
// Mantém os cabeçalhos disponíveis para downloads autenticados disparados por
// módulos (por exemplo, o ZIP isolado do LOG da Sprint 4.6.5).
if (ob_get_level() === 0) {
    ob_start();
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/integracoes.php';

iniciarSessaoSegura();
exigirLogin('auth/login.php');

date_default_timezone_set(
    defined('ROJEX_APP_TIMEZONE')
        ? ROJEX_APP_TIMEZONE
        : 'America/Sao_Paulo'
);

/*
 * Headers compatíveis de hardening.
 * A CSP rígida será ativada somente após remover estilos/scripts inline
 * e revisar as dependências externas atualmente homologadas.
 */
if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    header('Cross-Origin-Opener-Policy: same-origin');
}

if (!function_exists('rojexPerfilAtual')) {
    function rojexPerfilAtual(): string
    {
        return trim((string)($_SESSION['perfil'] ?? 'Usuário'));
    }
}

if (!function_exists('rojexEhAdministrador')) {
    function rojexEhAdministrador(): bool
    {
        return in_array(
            rojexPerfilAtual(),
            ['Administrador Master', 'Administrador'],
            true
        );
    }
}

if (!function_exists('rojexPerfisEquipeInterna')) {
    function rojexPerfisEquipeInterna(): array
    {
        return [
            'Suporte ROJEX',
            'Comercial ROJEX',
            'Financeiro ROJEX',
            'Operador ROJEX',
            'Auditor ROJEX',
        ];
    }
}

if (!function_exists('rojexEhEquipeInterna')) {
    function rojexEhEquipeInterna(): bool
    {
        return in_array(rojexPerfilAtual(), rojexPerfisEquipeInterna(), true);
    }
}

if (!function_exists('rojexEhMasterSaas')) {
    function rojexEhMasterSaas(): bool
    {
        static $resultado = null;

        if (is_bool($resultado)) {
            return $resultado;
        }

        $usuarioId = (int)($_SESSION['user_id'] ?? 0);
        if ($usuarioId <= 0) {
            return $resultado = false;
        }

        if (strcasecmp(rojexPerfilAtual(), 'Administrador Master') === 0) {
            return $resultado = true;
        }

        $connAutorizacao = null;

        try {
            $connAutorizacao = conectar();

            if (function_exists('rojexUsuarioEhMasterSaas')) {
                $resultado = rojexUsuarioEhMasterSaas(
                    $connAutorizacao,
                    $usuarioId,
                    rojexPerfilAtual()
                );
            } else {
                $resultado = false;
            }
        } catch (Throwable $e) {
            error_log('[ROJEX AUTORIZAÇÃO MASTER] ' . $e->getMessage());
            $resultado = false;
        } finally {
            if ($connAutorizacao instanceof mysqli) {
                $connAutorizacao->close();
            }
        }

        return $resultado;
    }
}

if (!function_exists('rojexEhUsuarioPlataforma')) {
    function rojexEhUsuarioPlataforma(): bool
    {
        if (!(function_exists('rojexModoPlataforma') && rojexModoPlataforma())) {
            return false;
        }

        if (rojexEhMasterSaas()) {
            return true;
        }

        return rojexEhEquipeInterna();
    }
}

if (!function_exists('rojexModulosPlataformaPorPerfil')) {
    function rojexModulosPlataformaPorPerfil(): array
    {
        /*
         * Esta é apenas a barreira de navegação entre módulos. Cada módulo
         * mantém sua própria autorização de abas, dados e ações no servidor.
         */
        return match (rojexPerfilAtual()) {
            'Suporte ROJEX' => ['master_saas', 'configuracoes'],
            'Comercial ROJEX' => ['master_saas', 'configuracoes'],
            'Financeiro ROJEX' => ['master_saas', 'configuracoes'],
            'Operador ROJEX' => ['master_saas', 'configuracoes'],
            'Auditor ROJEX' => ['master_saas', 'configuracoes'],
            default => rojexEhMasterSaas()
                ? ['master_saas', 'configuracoes']
                : [],
        };
    }
}

if (!function_exists('rojexPodeAcessarModulo')) {
    function rojexPodeAcessarModulo(string $modulo): bool
    {
        if (function_exists('rojexModoPlataforma') && rojexModoPlataforma()) {
            return rojexEhUsuarioPlataforma()
                && in_array($modulo, rojexModulosPlataformaPorPerfil(), true);
        }

        if ($modulo === 'master_saas') {
            return rojexEhMasterSaas();
        }

        return function_exists('rojexContextoTenantValido')
            ? rojexContextoTenantValido()
            : !empty($_SESSION['tenant_id']);
    }
}

if (!function_exists('rojexRegistrarAcessoModuloNegado')) {
    function rojexRegistrarAcessoModuloNegado(string $modulo): void
    {
        try {
            if (!function_exists('sgl_registrar_log')) {
                return;
            }

            $connLog = conectar();

            sgl_registrar_log(
                $connLog,
                'Tentativa de acesso a módulo não autorizado',
                'sistema',
                null,
                'Acesso negado ao módulo solicitado.',
                [
                    'tipo_acao' => 'EVENTO',
                    'modulo' => 'Autorização',
                    'origem' => 'index.php',
                    'resultado' => 'NEGADO',
                    'nivel' => 'AVISO',
                    'dados_novos' => [
                        'modulo_solicitado' => mb_substr($modulo, 0, 80, 'UTF-8'),
                        'perfil' => rojexPerfilAtual(),
                    ],
                ]
            );

            $connLog->close();
        } catch (Throwable $e) {
            error_log('[ROJEX AUTORIZAÇÃO] ' . $e->getMessage());
        }
    }
}

$modulos_validos = [
    'dashboard',
    'advogados',
    'clientes',
    'processos',
    'honorarios',
    'agenda',
    'financeiro',
    'recibos',
    'documentos',
    'modelos',
    'configuracoes',
    'master_saas',
    'busca',
    'cij',
];

$moduloPadrao = (function_exists('rojexModoPlataforma') && rojexModoPlataforma())
    ? 'master_saas'
    : 'dashboard';

$moduloInformado = isset($_GET['mod'])
    ? trim((string)$_GET['mod'])
    : $moduloPadrao;
$acessoModuloBloqueado = false;

/*
 * Compatibilidade com links e favoritos anteriores à Camada Multi-Tenant.
 * No Modo Plataforma, o antigo destino dashboard representa o Dashboard SaaS.
 * A normalização é silenciosa para não gerar aviso indevido ao MASTER.
 */
if (
    function_exists('rojexModoPlataforma')
    && rojexModoPlataforma()
    && rojexEhMasterSaas()
    && $moduloInformado === 'dashboard'
) {
    $moduloInformado = 'master_saas';
    unset($_SESSION['rojex_aviso_autorizacao']);
}

if (!in_array($moduloInformado, $modulos_validos, true)) {
    if ($moduloInformado !== '') {
        rojexRegistrarAcessoModuloNegado($moduloInformado);
    }

    $modulo = rojexPodeAcessarModulo($moduloPadrao)
        ? $moduloPadrao
        : null;
    $acessoModuloBloqueado = $modulo === null;
} elseif (!rojexPodeAcessarModulo($moduloInformado)) {
    rojexRegistrarAcessoModuloNegado($moduloInformado);
    $modulo = rojexPodeAcessarModulo($moduloPadrao)
        ? $moduloPadrao
        : null;
    $acessoModuloBloqueado = $modulo === null;

    /*
     * O MASTER principal pode chegar por URLs antigas de módulos operacionais.
     * Para a equipe interna, a tentativa permanece visível e registrada.
     */
    if (
        function_exists('rojexModoPlataforma')
        && rojexModoPlataforma()
        && rojexEhMasterSaas()
    ) {
        unset($_SESSION['rojex_aviso_autorizacao']);
    } else {
        $_SESSION['rojex_aviso_autorizacao'] =
            'Você não possui permissão para acessar o módulo solicitado.';
    }
} else {
    $modulo = $moduloInformado;
}

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
    'configuracoes' => 'Configurações',
    'master_saas' => 'MASTER SaaS',
    'busca' => 'Busca Global',
    'cij' => 'Centro de Inteligência Jurídica',
];
$tituloPagina = $titulos[$modulo] ?? 'Acesso restrito';
$modoPlataforma = function_exists('rojexModoPlataforma') && rojexModoPlataforma();
$contextoTenantValido = function_exists('rojexContextoTenantValido')
    ? rojexContextoTenantValido()
    : !empty($_SESSION['tenant_id']);
$contextoTenant = function_exists('rojexContextoTenant')
    ? rojexContextoTenant()
    : [];
$nomeContexto = $modoPlataforma
    ? 'ROJEX.AI Plataforma SaaS'
    : trim((string)($contextoTenant['escritorio_nome'] ?? $contextoTenant['nome_escritorio'] ?? $nome_escritorio ?? 'Escritório'));

/*
 * Downloads binários precisam ser processados antes do DOCTYPE, da barra
 * lateral e de config/tema.php. O módulo valida novamente MASTER, arquivo e
 * SHA-256 antes de enviar qualquer byte.
 */
if (
    $modulo === 'configuracoes'
    && $_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['acao_cfg'] ?? '') === 'baixar_log_backup'
) {
    require __DIR__ . '/modules/configuracoes.php';
    exit;
}

/**
 * Resolve a logo do ambiente autenticado sem reutilizar a configuração de
 * outro tenant. A tela de login não passa por este arquivo e permanece ROJEX.
 */
function rojexLogoSidebar(bool $modoPlataforma, array $contextoTenant): array
{
    $logoPadrao = 'assets/img/logo_rojex_ai.png';
    if (!is_file(__DIR__ . '/' . $logoPadrao)) {
        $logoPadrao = 'assets/img/logo_custom.png';
    }

    $resultado = [
        'src' => $logoPadrao,
        'versao' => is_file(__DIR__ . '/' . $logoPadrao)
            ? (string)filemtime(__DIR__ . '/' . $logoPadrao)
            : '1',
    ];

    try {
        $connLogo = conectar();
        $logoArquivo = '';

        if ($modoPlataforma) {
            $stmtLogo = $connLogo->prepare(
                "SELECT valor
                   FROM configuracoes
                  WHERE chave = 'logo_arquivo'
                  LIMIT 1"
            );
            if ($stmtLogo) {
                $stmtLogo->execute();
                $logoArquivo = trim((string)(
                    $stmtLogo->get_result()->fetch_assoc()['valor'] ?? ''
                ));
                $stmtLogo->close();
            }
        } else {
            $tenantId = function_exists('rojexTenantId')
                ? trim((string)rojexTenantId())
                : trim((string)($contextoTenant['tenant_id'] ?? ''));
            $escritorioId = function_exists('rojexEscritorioId')
                ? (int)rojexEscritorioId()
                : (int)($contextoTenant['escritorio_id'] ?? 0);

            if ($tenantId !== '' && $escritorioId > 0) {
                $stmtLogo = $connLogo->prepare(
                    "SELECT valor
                       FROM escritorios_configuracoes_saas
                      WHERE tenant_id = ?
                        AND escritorio_id = ?
                        AND chave = 'logo_arquivo'
                      LIMIT 1"
                );
                if ($stmtLogo) {
                    $stmtLogo->bind_param('si', $tenantId, $escritorioId);
                    $stmtLogo->execute();
                    $logoArquivo = trim((string)(
                        $stmtLogo->get_result()->fetch_assoc()['valor'] ?? ''
                    ));
                    $stmtLogo->close();
                }
            }
        }

        $connLogo->close();

        if (
            $logoArquivo !== ''
            && !str_contains($logoArquivo, '..')
            && preg_match('/^[A-Za-z0-9_\/-]+\.(?:png|jpe?g|webp)$/i', $logoArquivo)
        ) {
            $caminhoRelativo = 'assets/img/' . $logoArquivo;
            $caminhoAbsoluto = __DIR__ . '/' . $caminhoRelativo;
            if (is_file($caminhoAbsoluto)) {
                $resultado = [
                    'src' => $caminhoRelativo,
                    'versao' => (string)filemtime($caminhoAbsoluto),
                ];
            }
        }
    } catch (Throwable $e) {
        error_log('[ROJEX LOGO SIDEBAR] ' . $e->getMessage());
    }

    return $resultado;
}

$logoSidebar = rojexLogoSidebar($modoPlataforma, $contextoTenant);


function sgl_menu_active(?string $atual, array $itens): string {
    return in_array($atual, $itens, true) ? 'show' : '';
}
function sgl_link_active(?string $atual, string $item): string {
    return $atual === $item ? 'active' : '';
}

/*
 * A Busca Global oficial é executada exclusivamente por
 * modules/busca_global.php. A rota pública permanece ?mod=busca.
 */


?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ROJEX.AI - <?= htmlspecialchars($tituloPagina, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> | ERP Jurídico Enterprise</title>
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
            display:flex;
            justify-content:space-between;
            align-items:center;
            gap:12px;
            margin-bottom:18px;
        }
        .sgl-global-search {
            max-width:520px;
            width:100%;
        }
        @media (max-width: 991px) {
            .sgl-topbar { display:block; }
            .sgl-global-search { max-width:100%; margin-top:10px; }
        }
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
            <img src="<?= htmlspecialchars((string)$logoSidebar['src'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>?v=<?= htmlspecialchars((string)$logoSidebar['versao'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" alt="<?= htmlspecialchars($nomeContexto, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
        </div>
        <div class="sgl-user-box">
            <div class="brand mb-1"><?= htmlspecialchars($nomeContexto, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
            <div class="hello">Olá, <strong><?= htmlspecialchars($_SESSION['nome'] ?? $_SESSION['username'] ?? 'Usuário') ?></strong></div>
            <div class="role"><?= htmlspecialchars($_SESSION['perfil'] ?? 'Usuário') ?></div>
            <div class="online"><i class="bi bi-circle-fill" style="font-size:.42rem"></i> Online</div>
        </div>

        <div class="sgl-menu">
            <?php if ($modoPlataforma): ?>
                <?php if (rojexPodeAcessarModulo('master_saas')): ?>
                    <div class="sgl-menu-title">Plataforma SaaS</div>
                    <a href="?mod=master_saas" class="nav-link <?= sgl_link_active((string)$modulo, 'master_saas') ?>">
                        <i class="bi bi-shield-check"></i> Dashboard SaaS
                    </a>
                <?php endif; ?>

                <?php if (rojexPodeAcessarModulo('configuracoes')): ?>
                    <div class="sgl-menu-title">Administração Enterprise</div>
                    <a href="?mod=configuracoes" class="nav-link <?= sgl_link_active((string)$modulo, 'configuracoes') ?>">
                        <i class="bi bi-gear"></i> Configurações Enterprise
                    </a>
                <?php endif; ?>
            <?php else: ?>
                <a href="?mod=dashboard" class="nav-link <?= sgl_link_active($modulo, 'dashboard') ?>"><i class="bi bi-speedometer2"></i> Dashboard</a>
                <a href="?mod=busca" class="nav-link <?= sgl_link_active($modulo, 'busca') ?>"><i class="bi bi-search"></i> Busca Global</a>
                <a href="?mod=cij" class="nav-link <?= sgl_link_active($modulo, 'cij') ?>"><i class="bi bi-cpu"></i> Centro de Inteligência Jurídica</a>

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
                <?php if (rojexEhMasterSaas()): ?>
                    <a href="?mod=master_saas" class="nav-link <?= sgl_link_active($modulo, 'master_saas') ?>">
                        <i class="bi bi-shield-check"></i> Voltar à Plataforma
                    </a>
                <?php endif; ?>
            <?php endif; ?>
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
        <div class="sgl-topbar">
            <div></div>
            <?php if (!$modoPlataforma && $contextoTenantValido): ?>
                <form class="sgl-global-search" method="GET" action="">
                    <input type="hidden" name="mod" value="busca">
                    <div class="input-group shadow-sm">
                        <span class="input-group-text bg-white"><i class="bi bi-search"></i></span>
                        <input type="search" name="q" class="form-control" maxlength="160" value="<?= htmlspecialchars((string)($_GET['q'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" placeholder="Busca global: cliente, processo, documento, agenda...">
                        <button class="btn btn-primary" type="submit">Buscar</button>
                    </div>
                </form>
            <?php endif; ?>
        </div>

        <?php
        if (
            $modoPlataforma
            && rojexEhMasterSaas()
            && !empty($_SESSION['rojex_aviso_autorizacao'])
        ) {
            unset($_SESSION['rojex_aviso_autorizacao']);
        }
        ?>

        <?php if ($modoPlataforma): ?>
            <div class="alert alert-primary shadow-sm d-flex align-items-center gap-2">
                <i class="bi bi-grid-1x2-fill"></i>
                <div>
                    <strong>Modo Plataforma:</strong> nenhum escritório está carregado.
                    <?= rojexEhMasterSaas()
                        ? 'A administração global permanece reservada ao MASTER principal.'
                        : 'Seu acesso está limitado às atribuições do perfil ' . htmlspecialchars(rojexPerfilAtual(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '.' ?>
                    Os dados operacionais dos tenants permanecem bloqueados.
                </div>
            </div>
        <?php elseif (!$contextoTenantValido): ?>
            <div class="alert alert-danger shadow-sm">
                <strong>Contexto do escritório indisponível.</strong> Saia e entre novamente para recarregar o tenant.
            </div>
        <?php endif; ?>

        <?php if (!empty($_SESSION['rojex_aviso_autorizacao'])): ?>
            <div class="alert alert-warning shadow-sm">
                <?= htmlspecialchars(
                    (string)$_SESSION['rojex_aviso_autorizacao'],
                    ENT_QUOTES | ENT_SUBSTITUTE,
                    'UTF-8'
                ) ?>
            </div>
            <?php unset($_SESSION['rojex_aviso_autorizacao']); ?>
        <?php endif; ?>

        <?php
        $moduloExigeTenant = $modulo !== null
            && !in_array($modulo, ['master_saas', 'configuracoes'], true);

        if ($acessoModuloBloqueado || $modulo === null) {
            http_response_code(403);
            echo "<div class='alert alert-danger'><strong>Acesso bloqueado:</strong> seu perfil não possui um módulo inicial autorizado.</div>";
        } elseif ($moduloExigeTenant && !$contextoTenantValido) {
            echo "<div class='alert alert-danger'><strong>Acesso bloqueado:</strong> nenhum contexto de escritório válido está ativo.</div>";
        } else {
            // A URL pública continua ?mod=busca, mas o arquivo oficial é busca_global.php.
            $arquivoModulo = $modulo === 'busca' ? 'busca_global' : $modulo;
            $arquivo = __DIR__ . "/modules/{$arquivoModulo}.php";

            if (file_exists($arquivo)) {
                include $arquivo;
            } else {
                echo "<div class='alert alert-danger'><strong>Módulo não encontrado:</strong> " . htmlspecialchars($modulo, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "</div>";
            }
        }
        ?>
    </main>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="assets/js/main.js"></script>
</body>
</html>
