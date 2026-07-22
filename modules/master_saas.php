<?php
/**
 * modules/master_saas.php
 * Sprint 4.5 — Painel da Plataforma SaaS.
 *
 * Separa o MASTER principal dos responsáveis internos da ROJEX.AI.
 * A autorização das abas e das ações continua sendo validada novamente
 * dentro de modules/configuracoes.php.
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

if (!function_exists('rojexMasterSaasPerfisInternos')) {
    function rojexMasterSaasPerfisInternos(): array
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

if (!function_exists('rojexMasterSaasPerfilAtual')) {
    function rojexMasterSaasPerfilAtual(): string
    {
        return trim((string)($_SESSION['perfil'] ?? ''));
    }
}

if (!function_exists('rojexMasterSaasEhMasterPrincipal')) {
    function rojexMasterSaasEhMasterPrincipal(mysqli $conn): bool
    {
        $usuarioId = (int)($_SESSION['user_id'] ?? 0);
        $perfil = rojexMasterSaasPerfilAtual();

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

if (!function_exists('rojexMasterSaasEhEquipeInterna')) {
    function rojexMasterSaasEhEquipeInterna(): bool
    {
        if (!in_array(rojexMasterSaasPerfilAtual(), rojexMasterSaasPerfisInternos(), true)) {
            return false;
        }

        // A equipe interna existe somente no contexto da plataforma e nunca
        // pode herdar tenant, escritório ou a permissão total do MASTER.
        if (!(function_exists('rojexModoPlataforma') && rojexModoPlataforma())) {
            return false;
        }

        if (
            (function_exists('rojexTenantId') && rojexTenantId() !== null)
            || (function_exists('rojexEscritorioId') && rojexEscritorioId() !== null)
        ) {
            return false;
        }

        $permissoes = $_SESSION['permissoes_tenant'] ?? [];
        if (!is_array($permissoes) || in_array('plataforma_total', $permissoes, true)) {
            return false;
        }

        return true;
    }
}

$ehMasterPrincipal = rojexMasterSaasEhMasterPrincipal($connMasterSaas);
$ehEquipeInterna = !$ehMasterPrincipal && rojexMasterSaasEhEquipeInterna();
$perfilPlataforma = rojexMasterSaasPerfilAtual();

if (!$ehMasterPrincipal && !$ehEquipeInterna) {
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
    echo '<p class="mb-0">Esta área é exclusiva do MASTER principal e da equipe interna autorizada da plataforma ROJEX.AI.</p>';
    echo '</div>';
    return;
}

if (function_exists('sgl_registrar_log')) {
    sgl_registrar_log(
        $connMasterSaas,
        'Acessou o Painel da Plataforma SaaS',
        'sistema',
        null,
        $ehMasterPrincipal
            ? 'Entrada do MASTER principal no painel administrativo da plataforma.'
            : 'Entrada de responsável interno no painel limitado da plataforma.',
        [
            'tipo_acao' => 'EVENTO',
            'modulo' => 'MASTER SaaS',
            'origem' => 'modules/master_saas.php',
            'resultado' => 'SUCESSO',
                'nivel' => 'INFO',
                'dados_novos' => [
                    'perfil' => $perfilPlataforma,
                    'escopo' => $ehMasterPrincipal ? 'MASTER_TOTAL' : 'EQUIPE_INTERNA_LIMITADA',
                ],
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

$atalhosPorPerfil = [
    'Suporte ROJEX' => [
        [
            'titulo' => 'Equipes dos Escritórios',
            'descricao' => 'Localize o escritório e preste suporte autorizado aos usuários, sem visualizar senhas.',
            'icone' => 'bi-people',
            'url' => '?mod=configuracoes&tab=usuarios&visao_equipes=escritorios',
        ],
        [
            'titulo' => 'Portal do Cliente',
            'descricao' => 'Consulte convites, contas e eventos do portal dentro do escopo permitido ao suporte.',
            'icone' => 'bi-person-badge',
            'url' => '?mod=configuracoes&tab=portal',
        ],
    ],
    'Comercial ROJEX' => [
        [
            'titulo' => 'Escritórios SaaS',
            'descricao' => 'Consulte clientes, implantação, situação comercial e plano contratado.',
            'icone' => 'bi-buildings',
            'url' => '?mod=configuracoes&tab=administracao',
        ],
        [
            'titulo' => 'Planos e Módulos',
            'descricao' => 'Consulte e administre a oferta comercial conforme as permissões do perfil.',
            'icone' => 'bi-boxes',
            'url' => '?mod=configuracoes&tab=planos',
        ],
        [
            'titulo' => 'Licenças',
            'descricao' => 'Acompanhe ativações, vencimentos e limites contratados pelos escritórios.',
            'icone' => 'bi-key',
            'url' => '?mod=configuracoes&tab=administracao',
        ],
    ],
    'Financeiro ROJEX' => [
        [
            'titulo' => 'Licenças e Renovações',
            'descricao' => 'Acompanhe vencimentos, situação de cobrança e renovação das licenças.',
            'icone' => 'bi-cash-coin',
            'url' => '?mod=configuracoes&tab=administracao',
        ],
        [
            'titulo' => 'Relatórios Financeiros',
            'descricao' => 'Consulte indicadores administrativos liberados ao setor financeiro.',
            'icone' => 'bi-file-earmark-bar-graph',
            'url' => '?mod=configuracoes&tab=relatorios',
        ],
    ],
    'Operador ROJEX' => [
        [
            'titulo' => 'Saúde do Sistema',
            'descricao' => 'Consulte o estado técnico da plataforma sem executar manutenção crítica.',
            'icone' => 'bi-heart-pulse',
            'url' => '?mod=configuracoes&tab=saude',
        ],
        [
            'titulo' => 'Atualizações',
            'descricao' => 'Consulte versões e compatibilidade; publicação e execução permanecem protegidas.',
            'icone' => 'bi-cloud-arrow-up',
            'url' => '?mod=configuracoes&tab=atualizacoes',
        ],
    ],
    'Auditor ROJEX' => [
        [
            'titulo' => 'Relatórios Administrativos',
            'descricao' => 'Consulte indicadores consolidados sem alterar cadastros da plataforma.',
            'icone' => 'bi-file-earmark-bar-graph',
            'url' => '?mod=configuracoes&tab=relatorios',
        ],
        [
            'titulo' => 'LOG Enterprise',
            'descricao' => 'Consulte eventos e acessos em modo de auditoria, sem arquivar ou excluir registros.',
            'icone' => 'bi-journal-text',
            'url' => '?mod=configuracoes&tab=logs',
        ],
    ],
];

$atalhosExibidos = $ehMasterPrincipal
    ? $atalhosMasterSaas
    : ($atalhosPorPerfil[$perfilPlataforma] ?? []);

$tituloPainel = $ehMasterPrincipal ? 'MASTER SaaS' : 'Painel da Equipe ROJEX.AI';
$descricaoPainel = $ehMasterPrincipal
    ? 'Administração exclusiva da plataforma ROJEX.AI e dos escritórios clientes.'
    : 'Acesso interno limitado às responsabilidades do perfil ' . $perfilPlataforma . '.';
$rotuloAcesso = $ehMasterPrincipal ? 'Acesso MASTER' : $perfilPlataforma;

$connMasterSaas->close();
?>

<div class="mb-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
        <div>
            <h3 class="text-primary mb-1">
                <i class="bi bi-shield-check me-2"></i><?= htmlspecialchars($tituloPainel, ENT_QUOTES, 'UTF-8') ?>
            </h3>
            <p class="text-muted mb-0">
                <?= htmlspecialchars($descricaoPainel, ENT_QUOTES, 'UTF-8') ?>
            </p>
        </div>
        <span class="badge text-bg-dark px-3 py-2">
            <i class="bi bi-person-lock me-1"></i>
            <?= htmlspecialchars($rotuloAcesso, ENT_QUOTES, 'UTF-8') ?>
        </span>
    </div>
</div>

<div class="alert alert-info border-0 shadow-sm">
    <div class="d-flex gap-3">
        <i class="bi bi-info-circle-fill fs-4"></i>
        <div>
            <strong><?= $ehMasterPrincipal ? 'Administração integral da plataforma' : 'Acesso interno com privilégio mínimo' ?></strong>
            <div class="small mt-1">
                <?php if ($ehMasterPrincipal): ?>
                    O MASTER principal mantém acesso completo às funções homologadas da plataforma.
                <?php else: ?>
                    Você verá somente as áreas previstas para o seu perfil. Cada aba e cada ação
                    também são validadas no servidor; links diretos não ampliam suas permissões.
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div class="row g-3">
    <?php foreach ($atalhosExibidos as $atalho): ?>
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
