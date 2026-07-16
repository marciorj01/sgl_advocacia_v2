<?php
/**
 * modules/master_saas.php
 * Sprint 4.5 — Entrada oficial do Painel MASTER SaaS.
 *
 * Nesta primeira etapa, o módulo apenas organiza o acesso às funções SaaS
 * já homologadas em Configurações, sem duplicar regras de negócio.
 */

if (!function_exists('conectar')) {
    require_once __DIR__ . '/../config/database.php';
}

if (!function_exists('iniciarSessaoSegura')) {
    require_once __DIR__ . '/../config/auth.php';
}

if (!function_exists('sgl_registrar_log')) {
    require_once __DIR__ . '/../config/integracoes.php';
}

iniciarSessaoSegura();
exigirLogin('../auth/login.php');

$connMasterSaas = conectar();

if (!function_exists('rojexMasterSaasUsuarioAutorizado')) {
    function rojexMasterSaasUsuarioAutorizado(mysqli $conn): bool
    {
        $usuarioId = (int)($_SESSION['user_id'] ?? 0);
        $perfil = trim((string)($_SESSION['perfil'] ?? ''));

        if ($usuarioId <= 0) {
            return false;
        }

        if ($perfil === 'Administrador Master') {
            return true;
        }

        try {
            $stmt = $conn->prepare(
                "SELECT valor
                   FROM configuracoes
                  WHERE chave = 'usuario_master_id'
                  LIMIT 1"
            );

            if (!$stmt) {
                return false;
            }

            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            return (int)($row['valor'] ?? 0) === $usuarioId;
        } catch (Throwable $e) {
            error_log('[ROJEX MASTER SAAS][AUTORIZAÇÃO] ' . $e->getMessage());
            return false;
        }
    }
}

if (!rojexMasterSaasUsuarioAutorizado($connMasterSaas)) {
    if (function_exists('sgl_registrar_log')) {
        sgl_registrar_log(
            $connMasterSaas,
            'Tentativa de acesso ao Painel MASTER SaaS',
            'sistema',
            null,
            'Acesso negado ao módulo MASTER SaaS.',
            [
                'tipo_acao' => 'EVENTO',
                'modulo' => 'MASTER SaaS',
                'origem' => 'modules/master_saas.php',
                'resultado' => 'NEGADO',
                'nivel' => 'AVISO',
            ]
        );
    }

    $connMasterSaas->close();
    http_response_code(403);

    echo '<div class="alert alert-danger shadow-sm">';
    echo '<h4 class="alert-heading"><i class="bi bi-shield-lock me-2"></i>Acesso restrito</h4>';
    echo '<p class="mb-0">Esta área é exclusiva do usuário MASTER da plataforma ROJEX.AI.</p>';
    echo '</div>';
    return;
}

if (function_exists('sgl_registrar_log')) {
    sgl_registrar_log(
        $connMasterSaas,
        'Acessou o Painel MASTER SaaS',
        'sistema',
        null,
        'Entrada no painel administrativo da plataforma.',
        [
            'tipo_acao' => 'EVENTO',
            'modulo' => 'MASTER SaaS',
            'origem' => 'modules/master_saas.php',
            'resultado' => 'SUCESSO',
            'nivel' => 'INFO',
        ]
    );
}

$atalhosMasterSaas = [
    [
        'titulo' => 'Escritórios SaaS',
        'descricao' => 'Cadastro, implantação, status e gestão dos escritórios clientes.',
        'icone' => 'bi-buildings',
        'url' => '?mod=configuracoes&tab=administracao',
    ],
    [
        'titulo' => 'Licenças',
        'descricao' => 'Planos, limites de usuários, armazenamento, renovação e status.',
        'icone' => 'bi-key',
        'url' => '?mod=configuracoes&tab=administracao',
    ],
    [
        'titulo' => 'Relatórios Administrativos',
        'descricao' => 'Indicadores consolidados da plataforma, escritórios, usuários e saúde.',
        'icone' => 'bi-file-earmark-bar-graph',
        'url' => '?mod=configuracoes&tab=relatorios',
    ],
    [
        'titulo' => 'Saúde do Sistema',
        'descricao' => 'PHP, banco, HTTPS, armazenamento, permissões e controles de segurança.',
        'icone' => 'bi-heart-pulse',
        'url' => '?mod=configuracoes&tab=saude',
    ],
    [
        'titulo' => 'Manutenção',
        'descricao' => 'Simulações e rotinas controladas de manutenção técnica.',
        'icone' => 'bi-tools',
        'url' => '?mod=configuracoes&tab=manutencao',
    ],
    [
        'titulo' => 'Backups',
        'descricao' => 'Criação, integridade, histórico e verificação dos backups Enterprise.',
        'icone' => 'bi-database-check',
        'url' => '?mod=configuracoes&tab=backup',
    ],
    [
        'titulo' => 'Atualizações',
        'descricao' => 'Versões, changelog, requisitos e simulações de compatibilidade.',
        'icone' => 'bi-cloud-arrow-up',
        'url' => '?mod=configuracoes&tab=atualizacoes',
    ],
    [
        'titulo' => 'LOG Enterprise',
        'descricao' => 'Auditoria de eventos, acessos, alterações e operações administrativas.',
        'icone' => 'bi-journal-text',
        'url' => '?mod=configuracoes&tab=logs',
    ],
];

$connMasterSaas->close();
?>

<div class="mb-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
        <div>
            <h3 class="text-primary mb-1">
                <i class="bi bi-shield-check me-2"></i>MASTER SaaS
            </h3>
            <p class="text-muted mb-0">
                Administração exclusiva da plataforma ROJEX.AI e dos escritórios clientes.
            </p>
        </div>
        <span class="badge text-bg-dark px-3 py-2">
            <i class="bi bi-person-lock me-1"></i>
            Acesso MASTER
        </span>
    </div>
</div>

<div class="alert alert-info border-0 shadow-sm">
    <div class="d-flex gap-3">
        <i class="bi bi-info-circle-fill fs-4"></i>
        <div>
            <strong>Reorganização segura da Sprint 4.5</strong>
            <div class="small mt-1">
                Nesta etapa, as funções já homologadas continuam sendo executadas pelo módulo
                Configurações. Este painel cria apenas uma entrada administrativa separada,
                sem mover, duplicar ou alterar regras de negócio.
            </div>
        </div>
    </div>
</div>

<div class="row g-3">
    <?php foreach ($atalhosMasterSaas as $atalho): ?>
        <div class="col-md-6 col-xl-4">
            <div class="card h-100 border-0 shadow-sm">
                <div class="card-body d-flex flex-column">
                    <div class="d-flex align-items-start gap-3 mb-3">
                        <div class="rounded-3 bg-primary-subtle text-primary p-3">
                            <i class="bi <?= htmlspecialchars($atalho['icone'], ENT_QUOTES, 'UTF-8') ?> fs-4"></i>
                        </div>
                        <div>
                            <h5 class="card-title mb-1">
                                <?= htmlspecialchars($atalho['titulo'], ENT_QUOTES, 'UTF-8') ?>
                            </h5>
                            <p class="card-text text-muted small mb-0">
                                <?= htmlspecialchars($atalho['descricao'], ENT_QUOTES, 'UTF-8') ?>
                            </p>
                        </div>
                    </div>

                    <div class="mt-auto">
                        <a
                            href="<?= htmlspecialchars($atalho['url'], ENT_QUOTES, 'UTF-8') ?>"
                            class="btn btn-outline-primary w-100"
                        >
                            Acessar
                            <i class="bi bi-arrow-right ms-1"></i>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>
