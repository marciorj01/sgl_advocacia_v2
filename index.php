<?php
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

if (!function_exists('rojexEhMasterSaas')) {
    function rojexEhMasterSaas(): bool
    {
        static $resultado = null;

        if (is_bool($resultado)) {
            return $resultado;
        }

        $usuarioId = (int)($_SESSION['user_id'] ?? 0);
        $perfil = rojexPerfilAtual();

        if ($usuarioId <= 0) {
            return $resultado = false;
        }

        if ($perfil === 'Administrador Master') {
            return $resultado = true;
        }

        try {
            $connMaster = conectar();

            $stmt = $connMaster->prepare(
                "SELECT valor
                   FROM configuracoes
                  WHERE chave = 'usuario_master_id'
                  LIMIT 1"
            );

            if (!$stmt) {
                $connMaster->close();
                return $resultado = false;
            }

            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $connMaster->close();

            return $resultado = ((int)($row['valor'] ?? 0) === $usuarioId);
        } catch (Throwable $e) {
            error_log('[ROJEX MASTER SAAS][INDEX] ' . $e->getMessage());
            return $resultado = false;
        }
    }
}

if (!function_exists('rojexPodeAcessarModulo')) {
    function rojexPodeAcessarModulo(string $modulo): bool
    {
        /*
         * Configurações continua acessível a todos os usuários autenticados.
         * O próprio módulo mantém abas e ações administrativas protegidas.
         *
         * MASTER SaaS possui uma segunda barreira no roteador e outra
         * dentro de modules/master_saas.php.
         */
        if ($modulo === 'master_saas') {
            return rojexEhMasterSaas();
        }

        return true;
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

$moduloInformado = isset($_GET['mod'])
    ? trim((string)$_GET['mod'])
    : 'dashboard';

if (!in_array($moduloInformado, $modulos_validos, true)) {
    if ($moduloInformado !== '') {
        rojexRegistrarAcessoModuloNegado($moduloInformado);
    }

    $modulo = 'dashboard';
} elseif (!rojexPodeAcessarModulo($moduloInformado)) {
    rojexRegistrarAcessoModuloNegado($moduloInformado);
    $modulo = 'dashboard';
    $_SESSION['rojex_aviso_autorizacao'] =
        'Você não possui permissão para acessar o módulo solicitado.';
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
$tituloPagina = $titulos[$modulo] ?? 'SGL';

function sgl_menu_active(string $atual, array $itens): string {
    return in_array($atual, $itens, true) ? 'show' : '';
}
function sgl_link_active(string $atual, string $item): string {
    return $atual === $item ? 'active' : '';
}

function sgl_table_exists(mysqli $conn, string $tabela): bool {
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $tabela)) { return false; }
    $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
    $stmt->bind_param('s', $tabela);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return ((int)($row['total'] ?? 0)) > 0;
}

function sgl_table_columns(mysqli $conn, string $tabela): array {
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $tabela)) { return []; }
    $stmt = $conn->prepare("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
    $stmt->bind_param('s', $tabela);
    $stmt->execute();
    $res = $stmt->get_result();
    $cols = [];
    while ($row = $res->fetch_assoc()) { $cols[] = $row['COLUMN_NAME']; }
    $stmt->close();
    return $cols;
}

function sgl_busca_global(mysqli $conn, string $termo): array {
    $termo = mb_substr(trim($termo), 0, 160, 'UTF-8');
    if ($termo === '') { return []; }

    $digitos = preg_replace('/\D+/', '', $termo);
    $like = '%' . $termo . '%';
    $likeDigitos = $digitos !== '' ? '%' . $digitos . '%' : '';

    $config = [
        'clientes' => [
            'titulo' => 'Clientes',
            'mod' => 'clientes',
            'campos' => ['id','nome','cpf_cnpj','email','telefone','celular','whatsapp','cidade','estado'],
            'principal' => ['nome','cpf_cnpj','id'],
            'numericos' => ['cpf_cnpj','telefone','celular','whatsapp']
        ],
        'processos' => [
            'titulo' => 'Processos',
            'mod' => 'processos',
            'campos' => ['id','numero_processo','num_processo','titulo','cliente','tipo_processo','comarca','vara','status'],
            'principal' => ['numero_processo','num_processo','titulo','id'],
            'numericos' => ['numero_processo','num_processo']
        ],
        'advogados' => [
            'titulo' => 'Advogados',
            'mod' => 'advogados',
            'campos' => ['id','nome','cpf','oab','email','telefone','celular'],
            'principal' => ['nome','oab','id'],
            'numericos' => ['cpf','oab','telefone','celular']
        ],
        'agenda' => [
            'titulo' => 'Agenda',
            'mod' => 'agenda',
            'campos' => ['id','titulo','descricao','cliente','nome_cliente','data','data_evento','numero_processo','status'],
            'principal' => ['titulo','descricao','nome_cliente','id'],
            'numericos' => ['numero_processo']
        ],
        'honorarios' => [
            'titulo' => 'Honorários',
            'mod' => 'honorarios',
            'campos' => ['id','cliente','nome_cliente','descricao','status','numero_processo'],
            'principal' => ['descricao','nome_cliente','cliente','id'],
            'numericos' => ['numero_processo']
        ],
        'documentos_arquivos' => [
            'titulo' => 'Documentos',
            'mod' => 'documentos',
            'campos' => ['id','codigo','nome_arquivo','nome_original','titulo','descricao','categoria'],
            'principal' => ['titulo','nome_original','nome_arquivo','codigo','id'],
            'numericos' => ['codigo']
        ],
        'modelos_documentos' => [
            'titulo' => 'Modelos',
            'mod' => 'modelos',
            'campos' => ['id','codigo','titulo','nome','descricao','categoria','area_direito'],
            'principal' => ['titulo','nome','codigo','id'],
            'numericos' => ['codigo']
        ],
    ];

    $saida = [];
    $vistos = [];

    foreach ($config as $tabela => $cfg) {
        if (!sgl_table_exists($conn, $tabela)) { continue; }

        $cols = sgl_table_columns($conn, $tabela);
        if (!in_array('id', $cols, true)) { continue; }

        $campos = array_values(array_intersect($cfg['campos'], $cols));
        $camposNumericos = array_values(array_intersect($cfg['numericos'] ?? [], $cols));
        if (!$campos && !$camposNumericos) { continue; }

        $wheres = [];
        $params = [];
        $types = '';

        foreach ($campos as $campo) {
            $wheres[] = "`{$campo}` LIKE ?";
            $params[] = $like;
            $types .= 's';
        }

        if ($digitos !== '') {
            foreach ($camposNumericos as $campo) {
                $expr = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(`{$campo}`, '.', ''), '-', ''), '/', ''), '(', ''), ')', ''), ' ', ''), '+', '')";
                $wheres[] = "{$expr} LIKE ?";
                $params[] = $likeDigitos;
                $types .= 's';
            }
        }

        if (!$wheres) { continue; }

        $principal = 'id';
        foreach (($cfg['principal'] ?? []) as $possivel) {
            if (in_array($possivel, $cols, true)) { $principal = $possivel; break; }
        }

        $whereDeletado = in_array('deletado', $cols, true)
            ? " AND COALESCE(`deletado`,0)=0"
            : '';

        $camposSelect = array_values(array_unique(array_merge(
            ['id', $principal],
            $tabela === 'clientes' && in_array('cpf_cnpj', $cols, true)
                ? ['cpf_cnpj']
                : []
        )));

        $selectSql = implode(
            ', ',
            array_map(
                static fn(string $campo): string => "`{$campo}`",
                $camposSelect
            )
        );

        $sql = "SELECT {$selectSql}
                FROM `{$tabela}`
                WHERE (" . implode(' OR ', $wheres) . ")
                {$whereDeletado}
                ORDER BY id DESC
                LIMIT 10";

        try {
            $stmt = $conn->prepare($sql);
            if ($types !== '') { $stmt->bind_param($types, ...$params); }
            $stmt->execute();
            $res = $stmt->get_result();

            while ($row = $res->fetch_assoc()) {
                $chave = $tabela . ':' . (string)$row['id'];
                if (isset($vistos[$chave])) { continue; }
                $vistos[$chave] = true;

                $texto = (string)($row[$principal] ?? '');
                if ($texto === '') { $texto = 'Registro sem descrição'; }

                if ($tabela === 'clientes') {
                    $doc = (string)($row['cpf_cnpj'] ?? '');
                    if ($doc !== '') { $texto .= ' — ' . $doc; }
                }

                $saida[] = [
                    'modulo' => $cfg['titulo'],
                    'mod' => $cfg['mod'],
                    'id' => (string)$row['id'],
                    'texto' => $texto,
                ];
            }
            $stmt->close();
        } catch (Throwable $e) {
            error_log(
                '[ROJEX BUSCA GLOBAL][' . $tabela . '] ' .
                $e->getMessage()
            );
            continue;
        }
    }

    return $saida;
}


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
                    <i class="bi bi-shield-check"></i> MASTER SaaS
                </a>
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
            <form class="sgl-global-search" method="GET" action="">
                <input type="hidden" name="mod" value="busca">
                <div class="input-group shadow-sm">
                    <span class="input-group-text bg-white"><i class="bi bi-search"></i></span>
                    <input type="search" name="q" class="form-control" maxlength="160" value="<?= htmlspecialchars((string)($_GET['q'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" placeholder="Busca global: cliente, processo, documento, agenda...">
                    <button class="btn btn-primary" type="submit">Buscar</button>
                </div>
            </form>
        </div>

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
        if ($modulo === 'busca') {
            $q = mb_substr(
                trim((string)($_GET['q'] ?? '')),
                0,
                160,
                'UTF-8'
            );
            echo "<div class='mb-4'><h3 class='text-primary mb-1'><i class='bi bi-search me-2'></i>Busca Global</h3><p class='text-muted mb-0'>Pesquise clientes, processos, advogados, agenda, documentos e modelos em um único lugar.</p></div>";

            if ($q === '') {
                echo "<div class='alert alert-info'>Digite um termo na barra de busca para iniciar a pesquisa.</div>";
            } else {
                try {
                    $connBusca = conectar();
                    $resultados = sgl_busca_global($connBusca, $q);
                    $connBusca->close();

                    echo "<div class='d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3'>";
                    echo "<div><h5 class='mb-0'>Resultado para: <strong>" . htmlspecialchars($q, ENT_QUOTES, 'UTF-8') . "</strong></h5><div class='text-muted small'>" . count($resultados) . " resultado(s) encontrado(s).</div></div>";
                    echo "<a href='?mod=busca' class='btn btn-outline-secondary btn-sm'><i class='bi bi-x-lg me-1'></i>LIMPAR PESQUISA</a>";
                    echo "</div>";
                    echo "<div class='card shadow-sm border-0'><div class='card-header bg-dark text-white d-flex justify-content-between'><span><i class='bi bi-list-search me-1'></i>Resultados da Busca Global</span><span>" . count($resultados) . " resultado(s)</span></div><div class='card-body'>";
                    if (!$resultados) {
                        echo "<div class='text-center py-4 text-muted'><i class='bi bi-search fs-1 d-block mb-3 opacity-25'></i>Nenhum resultado encontrado.</div>";
                    } else {
                        echo "<div class='table-responsive'><table class='table table-hover align-middle mb-0'><thead class='table-light'><tr><th>Módulo</th><th>ID</th><th>Registro</th><th class='text-end'>Ação</th></tr></thead><tbody>";
                        foreach ($resultados as $r) {
                            echo "<tr>";
                            echo "<td><span class='badge bg-secondary'>" . htmlspecialchars($r['modulo'], ENT_QUOTES, 'UTF-8') . "</span></td>";
                            echo "<td><code>" . htmlspecialchars($r['id'], ENT_QUOTES, 'UTF-8') . "</code></td>";
                            echo "<td><strong>" . htmlspecialchars($r['texto'], ENT_QUOTES, 'UTF-8') . "</strong></td>";
                            echo "<td class='text-end'><a class='btn btn-sm btn-outline-primary' href='?mod=" . htmlspecialchars($r['mod'], ENT_QUOTES, 'UTF-8') . "'>Abrir módulo</a></td>";
                            echo "</tr>";
                        }
                        echo "</tbody></table></div>";
                    }
                    echo "</div></div>";
                } catch (Throwable $e) {
                    echo "<div class='alert alert-danger'>Não foi possível executar a busca global.</div>";
                }
            }
        } else {
            $arquivo = __DIR__ . "/modules/{$modulo}.php";
            if (file_exists($arquivo)) {
                include $arquivo;
            } else {
                echo "<div class='alert alert-danger'><strong>Módulo não encontrado:</strong> " . htmlspecialchars($modulo) . "</div>";
            }
        }
        ?>
    </main>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="assets/js/main.js"></script>
</body>
</html>