<?php
/**
 * modules/configuracoes.php
 * Sprint 4.1.3 — Administração Enterprise do ROJEX.AI (Etapa 10 — Fechamento Técnico e Homologação Final).
 * Mantém arquitetura modular atual, compatibilidade retroativa, segurança, CSRF e recursos de administração SaaS.
 */

$conn = conectar();
require_once __DIR__ . '/../config/integracoes.php';
if (function_exists('sgl_garantir_logs')) { sgl_garantir_logs($conn); }
if (function_exists('sgl_completar_logs_sem_responsavel')) { sgl_completar_logs_sem_responsavel($conn); }
$upload_dir = __DIR__ . '/../assets/img/';
$upload_marca_dir = $upload_dir . 'branding/';
if (!is_dir($upload_dir)) {
    @mkdir($upload_dir, 0755, true);
}
if (!is_dir($upload_marca_dir)) {
    @mkdir($upload_marca_dir, 0755, true);
}

// -----------------------------------------------------------------------------
// Base estrutural mínima
// -----------------------------------------------------------------------------
$conn->query("CREATE TABLE IF NOT EXISTS configuracoes (
    chave VARCHAR(80) NOT NULL,
    valor TEXT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (chave)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$conn->query("CREATE TABLE IF NOT EXISTS logs_sistema (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    usuario_id INT NULL,
    acao VARCHAR(120) NOT NULL,
    tabela VARCHAR(80) NULL,
    registro_id VARCHAR(30) NULL,
    detalhes TEXT NULL,
    ip VARCHAR(45) NULL,
    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_logs_usuario (usuario_id),
    INDEX idx_logs_acao (acao)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");


// Complementos compatíveis para a gestão Enterprise de usuários.
// As colunas são adicionadas somente quando ainda não existem.
if (sgl_tabela_existe($conn, 'usuarios')) {
    $colunasUsuarioEnterprise = [
        'telefone' => "VARCHAR(40) NULL",
        'cargo' => "VARCHAR(100) NULL",
        'departamento' => "VARCHAR(100) NULL",
        'observacoes' => "TEXT NULL",
        'vinculo_status' => "VARCHAR(30) NOT NULL DEFAULT 'ativo'",
        'desligado_em' => "DATETIME NULL",
        'desligado_por' => "INT NULL",
    ];

    foreach ($colunasUsuarioEnterprise as $colunaUsuario => $definicaoUsuario) {
        if (!sgl_coluna_existe($conn, 'usuarios', $colunaUsuario)) {
            try {
                $conn->query("ALTER TABLE usuarios ADD COLUMN `$colunaUsuario` $definicaoUsuario");
            } catch (Throwable $e) {
                // Mantém a tela funcional mesmo em bancos sem permissão de ALTER.
            }
        }
    }
}

$conn->query("CREATE TABLE IF NOT EXISTS usuarios_historico (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    usuario_id INT NOT NULL,
    acao VARCHAR(80) NOT NULL,
    dados_snapshot LONGTEXT NOT NULL,
    realizado_por INT NULL,
    realizado_por_nome VARCHAR(140) NULL,
    ip VARCHAR(45) NULL,
    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_uh_usuario (usuario_id),
    INDEX idx_uh_acao (acao),
    INDEX idx_uh_criado (criado_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");


// Base da Sprint 4.1.3 — Administração Enterprise.
// As tabelas centrais são criadas de forma não destrutiva e ainda não executam
// bloqueios, cobranças, backups ou atualizações automáticas.
$conn->query("CREATE TABLE IF NOT EXISTS escritorios_saas (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    tenant_id VARCHAR(80) NOT NULL,
    nome VARCHAR(180) NOT NULL,
    documento VARCHAR(30) NULL,
    responsavel VARCHAR(140) NULL,
    email VARCHAR(140) NULL,
    subdominio VARCHAR(180) NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'implantacao',
    plano VARCHAR(30) NOT NULL DEFAULT 'enterprise',
    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_escritorios_tenant (tenant_id),
    INDEX idx_escritorios_status (status),
    INDEX idx_escritorios_plano (plano)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// Complementos não destrutivos da Etapa 3 — Gestão de Escritórios.
foreach ([
    'telefone' => "VARCHAR(40) NULL",
    'cidade' => "VARCHAR(100) NULL",
    'uf' => "VARCHAR(2) NULL",
    'ultimo_acesso' => "DATETIME NULL",
    'observacoes' => "TEXT NULL",
    'encerrado_em' => "DATETIME NULL",
] as $colunaEscritorio => $definicaoEscritorio) {
    if (!sgl_coluna_existe($conn, 'escritorios_saas', $colunaEscritorio)) {
        try {
            $conn->query("ALTER TABLE escritorios_saas ADD COLUMN `$colunaEscritorio` $definicaoEscritorio");
        } catch (Throwable $e) {
            // Compatibilidade com hospedagens sem permissão de ALTER.
        }
    }
}

$conn->query("CREATE TABLE IF NOT EXISTS licencas_saas (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    escritorio_id BIGINT NULL,
    chave_licenca VARCHAR(120) NOT NULL,
    plano VARCHAR(30) NOT NULL DEFAULT 'enterprise',
    status VARCHAR(30) NOT NULL DEFAULT 'teste',
    limite_usuarios INT NOT NULL DEFAULT 100,
    limite_armazenamento_gb INT NOT NULL DEFAULT 50,
    ativada_em DATE NULL,
    renovacao_em DATE NULL,
    observacoes TEXT NULL,
    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_licencas_chave (chave_licenca),
    INDEX idx_licencas_escritorio (escritorio_id),
    INDEX idx_licencas_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$conn->query("CREATE TABLE IF NOT EXISTS backups_sistema (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    tipo VARCHAR(40) NOT NULL DEFAULT 'manual',
    status VARCHAR(30) NOT NULL DEFAULT 'planejado',
    arquivo VARCHAR(255) NULL,
    tamanho_bytes BIGINT NULL,
    hash_arquivo VARCHAR(128) NULL,
    iniciado_por INT NULL,
    detalhes TEXT NULL,
    iniciado_em DATETIME NULL,
    concluido_em DATETIME NULL,
    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_backups_status (status),
    INDEX idx_backups_criado (criado_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// Sprint 4.6.5 — inventário imutável dos backups isolados do LOG.
$conn->query("CREATE TABLE IF NOT EXISTS logs_backups (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id VARCHAR(80) NOT NULL,
    escritorio_id BIGINT NOT NULL,
    escritorio_nome VARCHAR(180) NULL,
    periodo_inicio DATETIME NOT NULL,
    periodo_fim DATETIME NOT NULL,
    arquivo VARCHAR(500) NOT NULL,
    nome_arquivo VARCHAR(255) NOT NULL,
    sha256 CHAR(64) NOT NULL,
    tamanho_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
    total_registros BIGINT UNSIGNED NOT NULL DEFAULT 0,
    ids_json LONGTEXT NOT NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'GERADO',
    verificado_em DATETIME NULL,
    download_em DATETIME NULL,
    arquivado_em DATETIME NULL,
    criado_por INT NULL,
    criado_por_nome VARCHAR(150) NULL,
    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_lb_tenant_escritorio (tenant_id, escritorio_id),
    INDEX idx_lb_status (status),
    INDEX idx_lb_criado (criado_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

foreach ([
    'tenant_id' => "VARCHAR(80) NOT NULL DEFAULT ''",
    'escritorio_id' => "BIGINT NOT NULL DEFAULT 0",
    'escritorio_nome' => "VARCHAR(180) NULL",
    'periodo_inicio' => "DATETIME NULL",
    'periodo_fim' => "DATETIME NULL",
    'arquivo' => "VARCHAR(500) NULL",
    'nome_arquivo' => "VARCHAR(255) NULL",
    'sha256' => "CHAR(64) NULL",
    'tamanho_bytes' => "BIGINT UNSIGNED NOT NULL DEFAULT 0",
    'total_registros' => "BIGINT UNSIGNED NOT NULL DEFAULT 0",
    'ids_json' => "LONGTEXT NULL",
    'status' => "VARCHAR(30) NOT NULL DEFAULT 'GERADO'",
    'verificado_em' => "DATETIME NULL",
    'download_em' => "DATETIME NULL",
    'arquivado_em' => "DATETIME NULL",
    'criado_por' => "INT NULL",
    'criado_por_nome' => "VARCHAR(150) NULL",
    'criado_em' => "DATETIME NULL DEFAULT CURRENT_TIMESTAMP",
    'atualizado_em' => "DATETIME NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP",
] as $colunaLogBackup => $definicaoLogBackup) {
    if (sgl_tabela_existe($conn, 'logs_backups') && !sgl_coluna_existe($conn, 'logs_backups', $colunaLogBackup)) {
        try {
            $conn->query("ALTER TABLE logs_backups ADD COLUMN `$colunaLogBackup` $definicaoLogBackup");
        } catch (Throwable $e) {
            // A migração oficial deve fornecer a estrutura; mantém a tela disponível.
        }
    }
}

// Complementos não destrutivos da Etapa 8 — Backup Enterprise.
foreach ([
    'nome_original' => "VARCHAR(255) NULL",
    'escopo' => "VARCHAR(40) NULL",
    'quantidade_arquivos' => "INT NOT NULL DEFAULT 0",
    'verificado_em' => "DATETIME NULL",
    'verificacao_status' => "VARCHAR(30) NULL",
    'responsavel_nome' => "VARCHAR(140) NULL",
] as $colunaBackup => $definicaoBackup) {
    if (!sgl_coluna_existe($conn, 'backups_sistema', $colunaBackup)) {
        try {
            $conn->query("ALTER TABLE backups_sistema ADD COLUMN `$colunaBackup` $definicaoBackup");
        } catch (Throwable $e) {
            // Mantém compatibilidade com hospedagens sem permissão de ALTER.
        }
    }
}

$conn->query("CREATE TABLE IF NOT EXISTS atualizacoes_sistema (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    versao VARCHAR(40) NOT NULL,
    titulo VARCHAR(180) NOT NULL,
    descricao TEXT NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'planejada',
    obrigatoria TINYINT(1) NOT NULL DEFAULT 0,
    publicada_em DATETIME NULL,
    aplicada_em DATETIME NULL,
    aplicada_por INT NULL,
    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_atualizacoes_versao (versao),
    INDEX idx_atualizacoes_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// Complementos não destrutivos da Etapa 9 — Central de Atualizações.
foreach ([
    'tipo' => "VARCHAR(30) NOT NULL DEFAULT 'melhoria'",
    'changelog' => "LONGTEXT NULL",
    'requisitos' => "LONGTEXT NULL",
    'impacto' => "VARCHAR(30) NOT NULL DEFAULT 'baixo'",
    'versao_php_minima' => "VARCHAR(20) NULL",
    'versao_banco_minima' => "VARCHAR(30) NULL",
    'tamanho_estimado_bytes' => "BIGINT NOT NULL DEFAULT 0",
    'arquivos_estimados' => "INT NOT NULL DEFAULT 0",
    'responsavel_nome' => "VARCHAR(140) NULL",
    'verificada_em' => "DATETIME NULL",
    'compatibilidade_status' => "VARCHAR(30) NULL",
] as $colunaAtualizacao => $definicaoAtualizacao) {
    if (!sgl_coluna_existe($conn, 'atualizacoes_sistema', $colunaAtualizacao)) {
        try {
            $conn->query("ALTER TABLE atualizacoes_sistema ADD COLUMN `$colunaAtualizacao` $definicaoAtualizacao");
        } catch (Throwable $e) {
            // Mantém compatibilidade com hospedagens sem permissão de ALTER.
        }
    }
}

$conn->query("CREATE TABLE IF NOT EXISTS manutencoes_sistema (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    tipo VARCHAR(60) NOT NULL,
    modo VARCHAR(20) NOT NULL DEFAULT 'execucao',
    status VARCHAR(30) NOT NULL DEFAULT 'concluida',
    resumo TEXT NULL,
    detalhes LONGTEXT NULL,
    executado_por INT NULL,
    executado_por_nome VARCHAR(140) NULL,
    iniciado_em DATETIME NULL,
    concluido_em DATETIME NULL,
    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_manutencoes_tipo (tipo),
    INDEX idx_manutencoes_status (status),
    INDEX idx_manutencoes_criado (criado_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");


// Complementos não destrutivos da Sprint 4.5 — Etapa 3.3.
// Mantêm compatibilidade com o banco homologado e preparam o catálogo comercial.
if (sgl_tabela_existe($conn, 'modulos_saas')) {
    foreach ([
        'status_lancamento' => "VARCHAR(30) NOT NULL DEFAULT 'producao'",
        'requer_api' => "TINYINT(1) NOT NULL DEFAULT 0",
        'exibir_portal' => "TINYINT(1) NOT NULL DEFAULT 0",
        'exibir_menu' => "TINYINT(1) NOT NULL DEFAULT 1",
        'exibir_venda' => "TINYINT(1) NOT NULL DEFAULT 1",
    ] as $colunaModulo => $definicaoModulo) {
        if (!sgl_coluna_existe($conn, 'modulos_saas', $colunaModulo)) {
            try {
                $conn->query("ALTER TABLE modulos_saas ADD COLUMN `$colunaModulo` $definicaoModulo");
            } catch (Throwable $e) {
                // A tela continua funcional em hospedagens sem permissão de ALTER.
            }
        }
    }
}


// -----------------------------------------------------------------------------
// Base não destrutiva do Provisionamento Enterprise — Sprint 4.5 / Etapa 3.4.4
// As tabelas abaixo isolam contrato, módulos, vínculo do administrador e
// configurações iniciais por tenant sem alterar as configurações globais atuais.
// -----------------------------------------------------------------------------
$conn->query("CREATE TABLE IF NOT EXISTS assinaturas_saas (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    escritorio_id BIGINT NOT NULL,
    plano_id BIGINT UNSIGNED NOT NULL,
    periodicidade VARCHAR(20) NOT NULL DEFAULT 'mensal',
    valor_base DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    desconto_modulos DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    valor_extras DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    ajuste_comercial DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    valor_contratado DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    status VARCHAR(30) NOT NULL DEFAULT 'trial',
    trial_inicio DATE NULL,
    trial_fim DATE NULL,
    inicio_vigencia DATE NULL,
    proximo_vencimento DATE NULL,
    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_assinaturas_escritorio (escritorio_id),
    INDEX idx_assinaturas_plano (plano_id),
    INDEX idx_assinaturas_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$conn->query("CREATE TABLE IF NOT EXISTS escritorios_modulos_saas (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    escritorio_id BIGINT NOT NULL,
    modulo_id BIGINT UNSIGNED NOT NULL,
    origem VARCHAR(30) NOT NULL DEFAULT 'plano',
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    valor_ajuste DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_escritorio_modulo (escritorio_id, modulo_id),
    INDEX idx_emod_modulo (modulo_id),
    INDEX idx_emod_ativo (ativo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$conn->query("CREATE TABLE IF NOT EXISTS usuarios_escritorios_saas (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    usuario_id INT NOT NULL,
    escritorio_id BIGINT NOT NULL,
    tenant_id VARCHAR(80) NOT NULL,
    papel VARCHAR(40) NOT NULL DEFAULT 'administrador',
    principal TINYINT(1) NOT NULL DEFAULT 1,
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_usuario_escritorio (usuario_id, escritorio_id),
    INDEX idx_ues_escritorio (escritorio_id),
    INDEX idx_ues_tenant (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$conn->query("CREATE TABLE IF NOT EXISTS escritorios_configuracoes_saas (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    escritorio_id BIGINT NOT NULL,
    tenant_id VARCHAR(80) NOT NULL,
    chave VARCHAR(80) NOT NULL,
    valor TEXT NULL,
    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_ecfg_tenant_chave (tenant_id, chave),
    INDEX idx_ecfg_escritorio (escritorio_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// -----------------------------------------------------------------------------
// Funções utilitárias
// -----------------------------------------------------------------------------
function sgl_cfg_get(mysqli $conn, string $chave, string $default = ''): string {
    $stmt = $conn->prepare("SELECT valor FROM configuracoes WHERE chave = ? LIMIT 1");
    $stmt->bind_param('s', $chave);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ? (string)$row['valor'] : $default;
}

function sgl_cfg_set(mysqli $conn, string $chave, string $valor): void {
    $stmt = $conn->prepare("INSERT INTO configuracoes (chave, valor) VALUES (?, ?) ON DUPLICATE KEY UPDATE valor = VALUES(valor)");
    $stmt->bind_param('ss', $chave, $valor);
    $stmt->execute();
    $stmt->close();
}

/**
 * Resolve o proprietário da identidade visual no servidor.
 * O navegador nunca informa tenant_id ou escritorio_id para esta operação.
 *
 * @return array{tipo:string,tenant_id:string,escritorio_id:int}
 */
function rojex_marca_contexto_atual(bool $ehUsuarioMaster): array {
    $modoPlataforma = function_exists('rojexModoPlataforma')
        && rojexModoPlataforma();

    if ($modoPlataforma) {
        if (!$ehUsuarioMaster) {
            throw new RuntimeException(
                'Somente o MASTER pode alterar a identidade visual da plataforma.'
            );
        }

        return [
            'tipo' => 'plataforma',
            'tenant_id' => '',
            'escritorio_id' => 0,
        ];
    }

    if (
        !function_exists('rojexContextoTenantValido')
        || !function_exists('rojexTenantId')
        || !function_exists('rojexEscritorioId')
        || !rojexContextoTenantValido()
    ) {
        throw new RuntimeException(
            'Contexto Multi-Tenant inválido para alterar a identidade visual.'
        );
    }

    $tenantId = trim((string)rojexTenantId());
    $escritorioId = (int)rojexEscritorioId();
    if ($tenantId === '' || $escritorioId <= 0) {
        throw new RuntimeException(
            'Tenant ou escritório não identificado para a identidade visual.'
        );
    }

    return [
        'tipo' => 'tenant',
        'tenant_id' => $tenantId,
        'escritorio_id' => $escritorioId,
    ];
}

function rojex_marca_cfg_get(
    mysqli $conn,
    array $contexto,
    string $chave,
    string $default = ''
): string {
    if (($contexto['tipo'] ?? '') === 'plataforma') {
        return sgl_cfg_get($conn, $chave, $default);
    }

    $tenantId = (string)($contexto['tenant_id'] ?? '');
    $escritorioId = (int)($contexto['escritorio_id'] ?? 0);
    if ($tenantId === '' || $escritorioId <= 0) {
        return $default;
    }

    $stmt = $conn->prepare(
        "SELECT valor
           FROM escritorios_configuracoes_saas
          WHERE tenant_id = ?
            AND escritorio_id = ?
            AND chave = ?
          LIMIT 1"
    );
    if (!$stmt) {
        return $default;
    }

    $stmt->bind_param('sis', $tenantId, $escritorioId, $chave);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ? (string)($row['valor'] ?? '') : $default;
}

function rojex_marca_cfg_set(
    mysqli $conn,
    array $contexto,
    string $chave,
    string $valor
): void {
    if (($contexto['tipo'] ?? '') === 'plataforma') {
        sgl_cfg_set($conn, $chave, $valor);
        return;
    }

    $tenantId = (string)($contexto['tenant_id'] ?? '');
    $escritorioId = (int)($contexto['escritorio_id'] ?? 0);
    if ($tenantId === '' || $escritorioId <= 0) {
        throw new RuntimeException('Escopo inválido para salvar a identidade visual.');
    }

    $stmt = $conn->prepare(
        "INSERT INTO escritorios_configuracoes_saas
            (escritorio_id, tenant_id, chave, valor)
         VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            escritorio_id = VALUES(escritorio_id),
            valor = VALUES(valor)"
    );
    if (!$stmt) {
        throw new RuntimeException('Não foi possível preparar a identidade visual.');
    }

    $stmt->bind_param('isss', $escritorioId, $tenantId, $chave, $valor);
    if (!$stmt->execute()) {
        $erro = $stmt->error;
        $stmt->close();
        throw new RuntimeException($erro ?: 'Não foi possível salvar a identidade visual.');
    }
    $stmt->close();
}

function rojex_marca_cfg_delete(
    mysqli $conn,
    array $contexto,
    string $chave
): void {
    if (($contexto['tipo'] ?? '') === 'plataforma') {
        $stmt = $conn->prepare('DELETE FROM configuracoes WHERE chave = ?');
        if (!$stmt) {
            throw new RuntimeException('Não foi possível preparar a remoção da identidade visual.');
        }
        $stmt->bind_param('s', $chave);
    } else {
        $tenantId = (string)($contexto['tenant_id'] ?? '');
        $escritorioId = (int)($contexto['escritorio_id'] ?? 0);
        if ($tenantId === '' || $escritorioId <= 0) {
            throw new RuntimeException('Escopo inválido para remover a identidade visual.');
        }
        $stmt = $conn->prepare(
            'DELETE FROM escritorios_configuracoes_saas
              WHERE tenant_id = ? AND escritorio_id = ? AND chave = ?'
        );
        if (!$stmt) {
            throw new RuntimeException('Não foi possível preparar a remoção da identidade visual.');
        }
        $stmt->bind_param('sis', $tenantId, $escritorioId, $chave);
    }

    if (!$stmt || !$stmt->execute()) {
        $erro = $stmt ? $stmt->error : '';
        if ($stmt) {
            $stmt->close();
        }
        throw new RuntimeException($erro ?: 'Não foi possível remover a identidade visual.');
    }
    $stmt->close();
}

function rojex_marca_prefixo_arquivo(array $contexto): string {
    if (($contexto['tipo'] ?? '') === 'plataforma') {
        return 'platform_master_logo';
    }

    $tenantHash = substr(hash('sha256', (string)$contexto['tenant_id']), 0, 20);
    return 'tenant_' . $tenantHash . '_office_' . (int)$contexto['escritorio_id'] . '_logo';
}


/**
 * Busca as preferências visuais individuais do usuário autenticado.
 *
 * A ausência de registro é válida e retorna os padrões seguros.
 * A tabela é criada exclusivamente pela migração oficial 4.4.2.
 */
function rojex_usuario_preferencias_get(mysqli $conn, int $usuarioId): array {
    $padroes = [
        'tema_modo' => 'claro',
        'tema_densidade' => 'confortavel',
        'tema_bordas' => 'suaves',
        'tema_fonte_percentual' => '100',
    ];

    if ($usuarioId <= 0 || !sgl_tabela_existe($conn, 'usuarios_preferencias')) {
        return $padroes;
    }

    try {
        $stmt = $conn->prepare(
            "SELECT tema_modo, tema_densidade, tema_bordas, tema_fonte_percentual
               FROM usuarios_preferencias
              WHERE usuario_id = ?
              LIMIT 1"
        );
        $stmt->bind_param('i', $usuarioId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) {
            return $padroes;
        }

        return [
            'tema_modo' => in_array((string)($row['tema_modo'] ?? ''), ['claro','escuro','automatico'], true)
                ? (string)$row['tema_modo']
                : $padroes['tema_modo'],
            'tema_densidade' => in_array((string)($row['tema_densidade'] ?? ''), ['compacta','confortavel'], true)
                ? (string)$row['tema_densidade']
                : $padroes['tema_densidade'],
            'tema_bordas' => in_array((string)($row['tema_bordas'] ?? ''), ['retas','suaves','arredondadas'], true)
                ? (string)$row['tema_bordas']
                : $padroes['tema_bordas'],
            'tema_fonte_percentual' => (string)max(
                90,
                min(115, (int)($row['tema_fonte_percentual'] ?? 100))
            ),
        ];
    } catch (Throwable $e) {
        return $padroes;
    }
}

/**
 * Insere ou atualiza somente as preferências do usuário autenticado.
 */
function rojex_usuario_preferencias_set(
    mysqli $conn,
    int $usuarioId,
    string $modo,
    string $densidade,
    string $bordas,
    int $fonte
): bool {
    if ($usuarioId <= 0 || !sgl_tabela_existe($conn, 'usuarios_preferencias')) {
        return false;
    }

    try {
        $stmt = $conn->prepare(
            "INSERT INTO usuarios_preferencias
                (usuario_id, tema_modo, tema_densidade, tema_bordas, tema_fonte_percentual)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                tema_modo = VALUES(tema_modo),
                tema_densidade = VALUES(tema_densidade),
                tema_bordas = VALUES(tema_bordas),
                tema_fonte_percentual = VALUES(tema_fonte_percentual)"
        );
        $stmt->bind_param('isssi', $usuarioId, $modo, $densidade, $bordas, $fonte);
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Restaura somente as preferências individuais do usuário.
 */
function rojex_usuario_preferencias_reset(mysqli $conn, int $usuarioId): bool {
    return rojex_usuario_preferencias_set(
        $conn,
        $usuarioId,
        'claro',
        'confortavel',
        'suaves',
        100
    );
}

function sgl_limpar_texto(string $texto, int $max = 255): string {
    $texto = trim(strip_tags($texto));
    return mb_substr($texto, 0, $max, 'UTF-8');
}

function sgl_validar_hex(string $cor, string $padrao): string {
    $cor = trim($cor);
    return preg_match('/^#[0-9A-Fa-f]{6}$/', $cor) ? $cor : $padrao;
}

function sgl_coluna_existe(mysqli $conn, string $tabela, string $coluna): bool {
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $tabela) || !preg_match('/^[a-zA-Z0-9_]+$/', $coluna)) {
        return false;
    }

    $sql = "SELECT COUNT(*) AS total
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
              AND COLUMN_NAME = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ss', $tabela, $coluna);
    $stmt->execute();
    $resultado = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return ((int)($resultado['total'] ?? 0)) > 0;
}

function sgl_tabela_existe(mysqli $conn, string $tabela): bool {
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $tabela)) {
        return false;
    }

    $sql = "SELECT COUNT(*) AS total
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('s', $tabela);
    $stmt->execute();
    $resultado = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return ((int)($resultado['total'] ?? 0)) > 0;
}

function sgl_log(mysqli $conn, string $acao, ?string $tabela = null, ?string $registro = null, ?string $detalhes = null): void {
    try {
        if (function_exists('sgl_registrar_log')) {
            sgl_registrar_log($conn, $acao, $tabela, $registro, $detalhes, [
                'tipo_acao' => 'EVENTO',
                'modulo' => $tabela ?: 'Configurações',
                'origem' => 'modules/configuracoes.php',
                'resultado' => 'SUCESSO',
                'nivel' => 'INFO',
            ]);
            return;
        }
        $usuario_id = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
        $nomeSessao = $_SESSION['nome'] ?? $_SESSION['username'] ?? 'Sistema';
        $perfilSessao = $_SESSION['perfil'] ?? 'Perfil não informado';
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        $navegador = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 180);
        $detalhesCompletos = trim(($detalhes ?: '') . ' | Responsável: ' . $nomeSessao . ' (' . $perfilSessao . ') | Navegador: ' . $navegador);
        $usuarioLogin = $_SESSION['username'] ?? null;
        $stmt = $conn->prepare("INSERT INTO logs_sistema (usuario_id, usuario_nome, usuario_login, usuario_perfil, acao, tabela, registro_id, detalhes, ip) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param('issssssss', $usuario_id, $nomeSessao, $usuarioLogin, $perfilSessao, $acao, $tabela, $registro, $detalhesCompletos, $ip);
        $stmt->execute();
        $stmt->close();
    } catch (Throwable $e) {
        // Log nunca deve quebrar a tela de configuração.
    }
}

function sgl_redirect_cfg(string $tab, string $tipo, string $msg): void {
    $url = '?mod=configuracoes&tab=' . rawurlencode($tab) . '&msg_' . rawurlencode($tipo) . '=' . rawurlencode($msg);

    // A URL será inserida dentro de JavaScript, portanto deve ser codificada
    // como string JavaScript/JSON. htmlspecialchars() transformava "&" em
    // "&amp;", fazendo o PHP receber "amp;tab" e retornar para Escritório.
    $urlJs = json_encode(
        $url,
        JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
        | JSON_HEX_TAG
        | JSON_HEX_APOS
        | JSON_HEX_AMP
        | JSON_HEX_QUOT
    );

    echo '<script>window.location.href = ' . $urlJs . ';</script>';
    exit;
}

function rojex_redirect_assistente(int $etapa, string $tipo, string $msg): void {
    $etapa = max(1, min(6, $etapa));
    $url = '?mod=configuracoes&tab=novo_escritorio&etapa=' . $etapa . '&msg_' . rawurlencode($tipo) . '=' . rawurlencode($msg);
    $urlJs = json_encode($url, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
    echo '<script>window.location.href = ' . $urlJs . ';</script>';
    exit;
}


/**
 * Calcula a composição comercial temporária do Assistente Novo Escritório.
 *
 * A função não grava no banco e utiliza apenas os valores administrativos já
 * cadastrados no plano e na composição dos módulos. Valores ainda não
 * definidos permanecem zerados, preservando a flexibilidade comercial.
 */
function rojex_motor_comercial_calcular(
    array $plano,
    string $periodicidade,
    array $modulosPlano,
    array $modulosSelecionados,
    float $ajusteManual = 0.0
): array {
    $periodicidade = $periodicidade === 'anual' ? 'anual' : 'mensal';
    $selecionados = array_values(array_unique(array_map('intval', $modulosSelecionados)));

    $valorMensal = round(max(0, (float)($plano['valor_mensal'] ?? 0)), 2);
    $valorAnual = round(max(0, (float)($plano['valor_anual'] ?? 0)), 2);
    $valorBase = $periodicidade === 'anual' ? $valorAnual : $valorMensal;

    $removidos = [];
    $incluidos = [];
    $obrigatorios = [];
    $opcionais = [];
    $descontoModulos = 0.0;

    foreach ($modulosPlano as $modulo) {
        $id = (int)($modulo['id'] ?? 0);
        if ($id <= 0) continue;

        $obrigatorio = !empty($modulo['obrigatorio']) || !empty($modulo['modulo_essencial']);
        $permiteRemocao = !$obrigatorio && !empty($modulo['permite_remocao']);
        $selecionado = $obrigatorio || in_array($id, $selecionados, true);
        $desconto = $periodicidade === 'anual'
            ? round(max(0, (float)($modulo['desconto_remocao_anual'] ?? 0)), 2)
            : round(max(0, (float)($modulo['desconto_remocao_mensal'] ?? 0)), 2);

        $item = [
            'id' => $id,
            'nome' => (string)($modulo['nome'] ?? 'Módulo'),
            'obrigatorio' => $obrigatorio,
            'permite_remocao' => $permiteRemocao,
            'valor_ajuste' => $desconto,
        ];

        if ($obrigatorio) $obrigatorios[] = $item;
        elseif ($permiteRemocao) $opcionais[] = $item;

        if ($selecionado) {
            $incluidos[] = $item;
        } elseif ($permiteRemocao) {
            $removidos[] = $item;
            $descontoModulos += $desconto;
        }
    }

    $descontoModulos = round($descontoModulos, 2);
    $ajusteManual = round($ajusteManual, 2);
    $valorFinal = round(max(0, $valorBase - $descontoModulos + $ajusteManual), 2);
    $economia = round(max(0, $valorBase - $valorFinal), 2);

    return [
        'periodicidade' => $periodicidade,
        'valor_base' => $valorBase,
        'valor_mensal_plano' => $valorMensal,
        'valor_anual_plano' => $valorAnual,
        'desconto_anual_percentual' => round(max(0, (float)($plano['desconto_anual_percentual'] ?? 0)), 2),
        'desconto_modulos' => $descontoModulos,
        'extras' => [],
        'valor_extras' => 0.0,
        'ajuste_manual' => $ajusteManual,
        'valor_final' => $valorFinal,
        'economia' => $economia,
        'modulos_incluidos' => $incluidos,
        'modulos_removidos' => $removidos,
        'modulos_obrigatorios' => $obrigatorios,
        'modulos_opcionais' => $opcionais,
        'quantidade_incluidos' => count($incluidos),
        'quantidade_removidos' => count($removidos),
        'gerado_em' => date('Y-m-d H:i:s'),
        'observacao' => 'Composição temporária. Valores podem ser alterados pelo MASTER antes do provisionamento definitivo.',
    ];
}

function sgl_select_count(mysqli $conn, string $sql): int {
    try {
        $res = $conn->query($sql);
        if ($res) {
            $row = $res->fetch_assoc();
            return (int)($row['total'] ?? 0);
        }
    } catch (Throwable $e) {}
    return 0;
}

function sgl_buscar_lixeira(mysqli $conn): array {
    $itens = [];

    $mapa = [
        'advogados' => ['campo' => 'nome', 'cond' => "status='Excluído'", 'tipo' => 'Advogados'],
        'clientes' => ['campo' => 'nome', 'cond' => sgl_coluna_existe($conn, 'clientes', 'deletado') ? 'deletado = 1' : "status='Excluído'", 'tipo' => 'Clientes'],
        'processos' => ['campo' => 'numero_processo', 'cond' => "status='Excluído'", 'tipo' => 'Processos'],
        'agenda' => ['campo' => sgl_coluna_existe($conn, 'agenda', 'titulo') ? 'titulo' : 'tipo_compromisso', 'cond' => sgl_coluna_existe($conn, 'agenda', 'deletado') ? 'deletado = 1' : "status='Cancelado'", 'tipo' => 'Agenda'],
        'honorarios' => ['campo' => sgl_coluna_existe($conn, 'honorarios', 'nome_cliente') ? 'nome_cliente' : 'id', 'cond' => sgl_coluna_existe($conn, 'honorarios', 'deletado') ? 'deletado = 1' : "status='Excluído'", 'tipo' => 'Honorários'],
        'contas_pagar' => ['campo' => sgl_coluna_existe($conn, 'contas_pagar', 'descricao') ? 'descricao' : 'fornecedor', 'cond' => sgl_coluna_existe($conn, 'contas_pagar', 'deletado') ? 'deletado = 1' : "status='Excluído'", 'tipo' => 'Contas a Pagar'],
        'contas_receber' => ['campo' => sgl_coluna_existe($conn, 'contas_receber', 'descricao') ? 'descricao' : 'cliente', 'cond' => sgl_coluna_existe($conn, 'contas_receber', 'deletado') ? 'deletado = 1' : "status='Excluído'", 'tipo' => 'Contas a Receber'],
        'documentos_arquivos' => ['campo' => sgl_coluna_existe($conn, 'documentos_arquivos', 'titulo') ? 'titulo' : 'nome_arquivo', 'cond' => sgl_coluna_existe($conn, 'documentos_arquivos', 'deletado') ? 'deletado = 1' : "status='Excluído'", 'tipo' => 'Documentos'],
        'modelos_documentos' => ['campo' => sgl_coluna_existe($conn, 'modelos_documentos', 'titulo') ? 'titulo' : 'nome', 'cond' => sgl_coluna_existe($conn, 'modelos_documentos', 'deletado') ? 'deletado = 1' : "status='Excluído'", 'tipo' => 'Modelos'],
        'recibos' => ['campo' => sgl_coluna_existe($conn, 'recibos', 'numero') ? 'numero' : 'nome_cliente', 'cond' => sgl_coluna_existe($conn, 'recibos', 'deletado') ? 'deletado = 1' : "status='Cancelado'", 'tipo' => 'Recibos'],
    ];

    $stmtLog = null;
    if (sgl_tabela_existe($conn, 'logs_sistema')) {
        try {
            $stmtLog = $conn->prepare(
                "SELECT criado_em,
                        COALESCE(usuario_nome, usuario_login, 'Sistema') AS responsavel
                   FROM logs_sistema
                  WHERE tabela = ?
                    AND registro_id = ?
                    AND (
                        acao LIKE '%lixeira%'
                        OR acao LIKE '%exclu%'
                        OR acao LIKE '%remov%'
                    )
                  ORDER BY id DESC
                  LIMIT 1"
            );
        } catch (Throwable $e) {
            $stmtLog = null;
        }
    }

    foreach ($mapa as $tabela => $cfg) {
        if (!sgl_tabela_existe($conn, $tabela) || !sgl_coluna_existe($conn, $tabela, 'id') || !sgl_coluna_existe($conn, $tabela, $cfg['campo'])) {
            continue;
        }

        try {
            $sql = "SELECT id, `{$cfg['campo']}` AS nome FROM `$tabela` WHERE {$cfg['cond']} ORDER BY id DESC";
            $res = $conn->query($sql);
            if ($res) {
                while ($row = $res->fetch_assoc()) {
                    $id = (string)$row['id'];
                    $excluidoEm = null;
                    $excluidoPor = 'Não identificado';

                    if ($stmtLog) {
                        try {
                            $stmtLog->bind_param('ss', $tabela, $id);
                            $stmtLog->execute();
                            $meta = $stmtLog->get_result()->fetch_assoc();
                            if ($meta) {
                                $excluidoEm = $meta['criado_em'] ?? null;
                                $excluidoPor = (string)($meta['responsavel'] ?? 'Não identificado');
                            }
                        } catch (Throwable $e) {}
                    }

                    $itens[] = [
                        'tabela' => $tabela,
                        'id' => $id,
                        'nome' => (string)($row['nome'] ?: 'Registro sem descrição'),
                        'tipo' => $cfg['tipo'],
                        'excluido_em' => $excluidoEm,
                        'excluido_por' => $excluidoPor,
                    ];
                }
            }
        } catch (Throwable $e) {}
    }

    if ($stmtLog) {
        $stmtLog->close();
    }

    usort($itens, static function (array $a, array $b): int {
        $dataA = $a['excluido_em'] ?? '';
        $dataB = $b['excluido_em'] ?? '';
        if ($dataA === $dataB) {
            return ((int)$b['id']) <=> ((int)$a['id']);
        }
        return strcmp($dataB, $dataA);
    });

    return $itens;
}

function sgl_lixeira_item_valido(string $valor, array $permitidas): ?array {
    $partes = explode('|', $valor, 2);
    if (count($partes) !== 2) return null;

    $tabela = preg_replace('/[^a-zA-Z0-9_]/', '', $partes[0]);
    $id = trim($partes[1]);

    if (!in_array($tabela, $permitidas, true) || $id === '' || !preg_match('/^[A-Za-z0-9_-]{1,80}$/', $id)) {
        return null;
    }

    return ['tabela' => $tabela, 'id' => $id];
}

function sgl_lixeira_restaurar(mysqli $conn, string $tabela, string $id): bool {
    if (!sgl_tabela_existe($conn, $tabela) || !sgl_coluna_existe($conn, $tabela, 'id')) return false;

    try {
        if (sgl_coluna_existe($conn, $tabela, 'deletado')) {
            $stmt = $conn->prepare("UPDATE `$tabela` SET deletado = 0 WHERE id = ?");
            $stmt->bind_param('s', $id);
        } elseif (sgl_coluna_existe($conn, $tabela, 'status')) {
            $status = ($tabela === 'processos') ? 'Em Andamento' : (($tabela === 'agenda') ? 'Agendado' : 'Ativo');
            $stmt = $conn->prepare("UPDATE `$tabela` SET status = ? WHERE id = ?");
            $stmt->bind_param('ss', $status, $id);
        } else {
            return false;
        }

        $ok = $stmt->execute();
        $afetadas = $stmt->affected_rows;
        $stmt->close();
        return $ok && $afetadas > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function sgl_lixeira_excluir(mysqli $conn, string $tabela, string $id): bool {
    if (!sgl_tabela_existe($conn, $tabela) || !sgl_coluna_existe($conn, $tabela, 'id')) return false;

    try {
        $stmt = $conn->prepare("DELETE FROM `$tabela` WHERE id = ?");
        $stmt->bind_param('s', $id);
        $ok = $stmt->execute();
        $afetadas = $stmt->affected_rows;
        $stmt->close();
        return $ok && $afetadas > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function sgl_registrar_historico_usuario(mysqli $conn, int $usuarioId, string $acao): bool {
    try {
        $stmt = $conn->prepare("SELECT * FROM usuarios WHERE id = ? LIMIT 1");
        $stmt->bind_param('i', $usuarioId);
        $stmt->execute();
        $dados = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$dados) {
            return false;
        }

        unset($dados['senha']);
        $snapshot = json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $realizadoPor = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
        $realizadoPorNome = (string)($_SESSION['nome'] ?? $_SESSION['username'] ?? 'Sistema');
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;

        $stmt = $conn->prepare(
            "INSERT INTO usuarios_historico
                (usuario_id, acao, dados_snapshot, realizado_por, realizado_por_nome, ip)
             VALUES (?, ?, ?, ?, ?, ?)"
        );
        $stmt->bind_param('ississ', $usuarioId, $acao, $snapshot, $realizadoPor, $realizadoPorNome, $ip);
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    } catch (Throwable $e) {
        return false;
    }
}

function sgl_formatar_bytes(float $bytes): string {
    if ($bytes < 1024) return number_format($bytes, 0, ',', '.') . ' B';
    $unidades = ['KB','MB','GB','TB'];
    $valor = $bytes;
    foreach ($unidades as $unidade) {
        $valor /= 1024;
        if ($valor < 1024 || $unidade === 'TB') {
            return number_format($valor, $valor >= 100 ? 0 : 2, ',', '.') . ' ' . $unidade;
        }
    }
    return number_format($bytes, 0, ',', '.') . ' B';
}

function sgl_ini_bytes(string $valor): int {
    $valor = trim($valor);
    if ($valor === '') return 0;
    $ultimo = strtolower(substr($valor, -1));
    $numero = (float)$valor;
    return match ($ultimo) {
        'g' => (int)($numero * 1024 * 1024 * 1024),
        'm' => (int)($numero * 1024 * 1024),
        'k' => (int)($numero * 1024),
        default => (int)$numero,
    };
}

function sgl_backup_resumo(mysqli $conn): array {
    $tabelas = ['usuarios','advogados','clientes','processos','agenda','honorarios','contas_pagar','contas_receber','configuracoes','logs_sistema'];
    $saida = [];
    foreach ($tabelas as $tabela) {
        if (sgl_tabela_existe($conn, $tabela)) {
            $saida[$tabela] = sgl_select_count($conn, "SELECT COUNT(*) AS total FROM `$tabela`");
        }
    }
    return $saida;
}

function rojex_relatorio_html_tabela(array $cabecalhos, array $linhas): string {
    $html = '<table><thead><tr>';
    foreach ($cabecalhos as $cabecalho) {
        $html .= '<th>' . htmlspecialchars((string)$cabecalho, ENT_QUOTES, 'UTF-8') . '</th>';
    }
    $html .= '</tr></thead><tbody>';
    if (!$linhas) {
        $html .= '<tr><td colspan="' . max(1, count($cabecalhos)) . '">Nenhum registro localizado.</td></tr>';
    } else {
        foreach ($linhas as $linha) {
            $html .= '<tr>';
            foreach ($linha as $valor) {
                $html .= '<td>' . htmlspecialchars((string)$valor, ENT_QUOTES, 'UTF-8') . '</td>';
            }
            $html .= '</tr>';
        }
    }
    return $html . '</tbody></table>';
}


function rojex_manutencao_diretorios_permitidos(): array {
    $raiz = realpath(__DIR__ . '/..');
    if ($raiz === false) return [];

    $candidatos = [
        $raiz . DIRECTORY_SEPARATOR . 'cache',
        $raiz . DIRECTORY_SEPARATOR . 'tmp',
        $raiz . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'cache',
        $raiz . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'tmp',
        $raiz . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'tmp',
        $raiz . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'tmp',
    ];

    $permitidos = [];
    foreach ($candidatos as $diretorio) {
        $real = realpath($diretorio);
        if ($real !== false && is_dir($real) && str_starts_with($real, $raiz)) {
            $permitidos[] = $real;
        }
    }
    return array_values(array_unique($permitidos));
}

function rojex_manutencao_mapear_temporarios(int $idadeHoras = 24): array {
    $limite = time() - (max(1, $idadeHoras) * 3600);
    $extensoes = ['tmp','temp','cache','part','bak'];
    $arquivos = [];
    $bytes = 0;
    $erros = [];

    foreach (rojex_manutencao_diretorios_permitidos() as $diretorio) {
        try {
            $iterador = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($diretorio, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::LEAVES_ONLY
            );

            foreach ($iterador as $arquivo) {
                if (count($arquivos) >= 5000) break 2;
                if (!$arquivo->isFile() || $arquivo->isLink()) continue;

                $nome = $arquivo->getFilename();
                if (in_array(strtolower($nome), ['.htaccess','index.php','index.html','web.config'], true)) continue;

                $extensao = strtolower(pathinfo($nome, PATHINFO_EXTENSION));
                if (!in_array($extensao, $extensoes, true)) continue;
                if ($arquivo->getMTime() > $limite) continue;

                $caminho = $arquivo->getRealPath();
                if ($caminho === false) continue;

                $tamanho = max(0, (int)$arquivo->getSize());
                $arquivos[] = $caminho;
                $bytes += $tamanho;
            }
        } catch (Throwable $e) {
            $erros[] = basename($diretorio) . ': ' . $e->getMessage();
        }
    }

    return [
        'arquivos' => $arquivos,
        'quantidade' => count($arquivos),
        'bytes' => $bytes,
        'erros' => $erros,
    ];
}

function rojex_manutencao_tabelas(mysqli $conn): array {
    $tabelas = [];
    try {
        $res = $conn->query(
            "SELECT TABLE_NAME, ENGINE, TABLE_ROWS,
                    COALESCE(DATA_LENGTH,0) + COALESCE(INDEX_LENGTH,0) AS tamanho_bytes
               FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_TYPE = 'BASE TABLE'
              ORDER BY TABLE_NAME"
        );
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $nome = (string)($row['TABLE_NAME'] ?? '');
                if ($nome !== '' && preg_match('/^[a-zA-Z0-9_]+$/', $nome)) {
                    $tabelas[] = [
                        'nome' => $nome,
                        'engine' => (string)($row['ENGINE'] ?? ''),
                        'linhas' => (int)($row['TABLE_ROWS'] ?? 0),
                        'bytes' => (int)($row['tamanho_bytes'] ?? 0),
                    ];
                }
            }
        }
    } catch (Throwable $e) {}
    return $tabelas;
}

function rojex_registrar_manutencao(
    mysqli $conn,
    string $tipo,
    string $modo,
    string $status,
    string $resumo,
    array $detalhes
): void {
    try {
        $usuarioId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
        $usuarioNome = (string)($_SESSION['nome'] ?? $_SESSION['username'] ?? 'Sistema');
        $json = json_encode($detalhes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $agora = date('Y-m-d H:i:s');

        $stmt = $conn->prepare(
            "INSERT INTO manutencoes_sistema
                (tipo, modo, status, resumo, detalhes, executado_por, executado_por_nome, iniciado_em, concluido_em)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->bind_param(
            'sssssisss',
            $tipo,
            $modo,
            $status,
            $resumo,
            $json,
            $usuarioId,
            $usuarioNome,
            $agora,
            $agora
        );
        $stmt->execute();
        $stmt->close();
    } catch (Throwable $e) {
        // O histórico auxiliar nunca deve impedir a manutenção principal.
    }
}


function rojex_backup_diretorio(): string {
    $raiz = realpath(__DIR__ . '/..') ?: (__DIR__ . '/..');
    $diretorio = $raiz . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'backups';

    if (!is_dir($diretorio)) {
        @mkdir($diretorio, 0750, true);
    }

    // Impede acesso HTTP direto em Apache. O arquivo permanece disponível
    // somente pela administração local do servidor.
    if (is_dir($diretorio)) {
        $htaccess = $diretorio . DIRECTORY_SEPARATOR . '.htaccess';
        if (!is_file($htaccess)) {
            @file_put_contents($htaccess, "Require all denied\nDeny from all\n");
        }

        $index = $diretorio . DIRECTORY_SEPARATOR . 'index.php';
        if (!is_file($index)) {
            @file_put_contents($index, "<?php http_response_code(403); exit;\n");
        }
    }

    return $diretorio;
}

function rojex_backup_nome_seguro(string $tipo, string $extensao): string {
    $tipo = preg_replace('/[^a-z0-9_-]/i', '', strtolower($tipo));
    $extensao = preg_replace('/[^a-z0-9]/i', '', strtolower($extensao));
    try {
        $sufixo = strtoupper(bin2hex(random_bytes(4)));
    } catch (Throwable $e) {
        $sufixo = strtoupper(substr(hash('sha256', uniqid('', true)), 0, 8));
    }

    return 'rojex_' . $tipo . '_' . date('Ymd_His') . '_' . $sufixo . '.' . $extensao;
}

function rojex_backup_sql_valor(mysqli $conn, mixed $valor): string {
    if ($valor === null) return 'NULL';
    if (is_int($valor) || is_float($valor)) return (string)$valor;
    return "'" . $conn->real_escape_string((string)$valor) . "'";
}

function rojex_backup_banco(mysqli $conn, string $arquivoSql): array {
    $handle = @fopen($arquivoSql, 'wb');
    if (!$handle) {
        return ['ok'=>false, 'erro'=>'Não foi possível criar o arquivo SQL.', 'tabelas'=>0, 'registros'=>0];
    }

    $tabelas = 0;
    $registros = 0;

    fwrite($handle, "-- ROJEX.AI — Backup Enterprise\n");
    fwrite($handle, "-- Gerado em: " . date('d/m/Y H:i:s') . "\n");
    fwrite($handle, "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n");

    try {
        $resTabelas = $conn->query(
            "SELECT TABLE_NAME
               FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_TYPE = 'BASE TABLE'
              ORDER BY TABLE_NAME"
        );

        while ($resTabelas && ($rowTabela = $resTabelas->fetch_assoc())) {
            $tabela = (string)$rowTabela['TABLE_NAME'];
            if (!preg_match('/^[a-zA-Z0-9_]+$/', $tabela)) continue;

            $resCreate = $conn->query("SHOW CREATE TABLE `$tabela`");
            $createRow = $resCreate ? $resCreate->fetch_assoc() : null;
            $createSql = $createRow['Create Table'] ?? null;
            if (!$createSql) continue;

            fwrite($handle, "-- --------------------------------------------------------\n");
            fwrite($handle, "-- Estrutura da tabela `$tabela`\n");
            fwrite($handle, "DROP TABLE IF EXISTS `$tabela`;\n");
            fwrite($handle, $createSql . ";\n\n");

            $resDados = $conn->query("SELECT * FROM `$tabela`");
            if ($resDados) {
                $campos = [];
                foreach ($resDados->fetch_fields() as $campo) {
                    $campos[] = '`' . str_replace('`', '``', $campo->name) . '`';
                }

                while ($linha = $resDados->fetch_assoc()) {
                    $valores = [];
                    foreach ($linha as $valor) {
                        $valores[] = rojex_backup_sql_valor($conn, $valor);
                    }

                    fwrite(
                        $handle,
                        "INSERT INTO `$tabela` (" . implode(',', $campos) . ") VALUES (" .
                        implode(',', $valores) . ");\n"
                    );
                    $registros++;
                }
            }

            fwrite($handle, "\n");
            $tabelas++;
        }

        fwrite($handle, "SET FOREIGN_KEY_CHECKS=1;\n");
        fclose($handle);

        return [
            'ok'=>is_file($arquivoSql) && filesize($arquivoSql) > 0,
            'erro'=>'',
            'tabelas'=>$tabelas,
            'registros'=>$registros,
        ];
    } catch (Throwable $e) {
        fclose($handle);
        @unlink($arquivoSql);
        return ['ok'=>false, 'erro'=>$e->getMessage(), 'tabelas'=>$tabelas, 'registros'=>$registros];
    }
}

function rojex_backup_fontes_arquivos(): array {
    $raiz = realpath(__DIR__ . '/..') ?: (__DIR__ . '/..');

    $candidatos = [
        'uploads' => $raiz . DIRECTORY_SEPARATOR . 'uploads',
        'documentos' => $raiz . DIRECTORY_SEPARATOR . 'documentos',
        'assets_img' => $raiz . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'img',
        'modelos' => $raiz . DIRECTORY_SEPARATOR . 'modelos',
        'templates' => $raiz . DIRECTORY_SEPARATOR . 'templates',
    ];

    $fontes = [];
    foreach ($candidatos as $rotulo => $caminho) {
        $real = realpath($caminho);
        if ($real !== false && is_dir($real) && str_starts_with($real, $raiz)) {
            $fontes[$rotulo] = $real;
        }
    }

    return $fontes;
}

function rojex_backup_mapear_arquivos(): array {
    $arquivos = [];
    $bytes = 0;

    foreach (rojex_backup_fontes_arquivos() as $rotulo => $diretorio) {
        try {
            $iterador = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($diretorio, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::LEAVES_ONLY
            );

            foreach ($iterador as $arquivo) {
                if (!$arquivo->isFile() || $arquivo->isLink()) continue;

                $real = $arquivo->getRealPath();
                if ($real === false) continue;

                $relativo = $rotulo . '/' . ltrim(str_replace('\\', '/', substr($real, strlen($diretorio))), '/');
                $arquivos[] = ['origem'=>$real, 'destino'=>$relativo, 'bytes'=>(int)$arquivo->getSize()];
                $bytes += (int)$arquivo->getSize();

                if (count($arquivos) >= 50000) break 2;
            }
        } catch (Throwable $e) {}
    }

    return ['arquivos'=>$arquivos, 'quantidade'=>count($arquivos), 'bytes'=>$bytes];
}

function rojex_backup_zip_arquivos(string $arquivoZip, array $arquivos, ?string $sqlArquivo = null): array {
    if (!class_exists('ZipArchive')) {
        return ['ok'=>false, 'erro'=>'A extensão ZIP do PHP não está habilitada.', 'quantidade'=>0];
    }

    $zip = new ZipArchive();
    $abertura = $zip->open($arquivoZip, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    if ($abertura !== true) {
        return ['ok'=>false, 'erro'=>'Não foi possível criar o arquivo ZIP.', 'quantidade'=>0];
    }

    $quantidade = 0;

    if ($sqlArquivo && is_file($sqlArquivo)) {
        if ($zip->addFile($sqlArquivo, 'banco/backup.sql')) {
            $quantidade++;
        }
    }

    foreach ($arquivos as $arquivo) {
        if (!is_file($arquivo['origem'])) continue;
        if ($zip->addFile($arquivo['origem'], $arquivo['destino'])) {
            $quantidade++;
        }
    }

    $manifesto = [
        'produto' => 'ROJEX.AI ERP Jurídico Enterprise',
        'gerado_em' => date(DATE_ATOM),
        'quantidade_arquivos' => $quantidade,
        'inclui_banco' => $sqlArquivo !== null,
    ];
    $zip->addFromString(
        'manifesto_backup.json',
        json_encode($manifesto, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    );

    $zip->close();

    return [
        'ok'=>is_file($arquivoZip) && filesize($arquivoZip) > 0,
        'erro'=>'',
        'quantidade'=>$quantidade,
    ];
}

function rojex_backup_registrar(
    mysqli $conn,
    string $tipo,
    string $status,
    ?string $arquivo,
    int $tamanho,
    ?string $hash,
    string $escopo,
    int $quantidadeArquivos,
    string $detalhes,
    ?string $verificacaoStatus = null
): int {
    $usuarioId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
    $responsavel = (string)($_SESSION['nome'] ?? $_SESSION['username'] ?? 'Sistema');
    $agora = date('Y-m-d H:i:s');
    $nomeOriginal = $arquivo ? basename($arquivo) : null;
    $verificadoEm = $verificacaoStatus ? $agora : null;

    $stmt = $conn->prepare(
        "INSERT INTO backups_sistema
            (tipo,status,arquivo,nome_original,tamanho_bytes,hash_arquivo,iniciado_por,responsavel_nome,
             escopo,quantidade_arquivos,detalhes,iniciado_em,concluido_em,verificado_em,verificacao_status)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
    );
    $stmt->bind_param(
        'ssssissssisssss',
        $tipo,
        $status,
        $arquivo,
        $nomeOriginal,
        $tamanho,
        $hash,
        $usuarioId,
        $responsavel,
        $escopo,
        $quantidadeArquivos,
        $detalhes,
        $agora,
        $agora,
        $verificadoEm,
        $verificacaoStatus
    );
    $stmt->execute();
    $id = (int)$stmt->insert_id;
    $stmt->close();

    return $id;
}

function rojex_backup_validar_arquivo(string $arquivo, ?string $hashEsperado = null): array {
    if (!is_file($arquivo)) {
        return ['ok'=>false, 'status'=>'ausente', 'hash'=>null, 'tamanho'=>0];
    }

    $tamanho = (int)filesize($arquivo);
    $hash = hash_file('sha256', $arquivo);
    $ok = $tamanho > 0 && ($hashEsperado === null || hash_equals($hashEsperado, $hash));

    return [
        'ok'=>$ok,
        'status'=>$ok ? 'integro' : 'divergente',
        'hash'=>$hash,
        'tamanho'=>$tamanho,
    ];
}

function rojex_log_backup_diretorio(): string {
    $diretorio = __DIR__ . '/../storage/log_backups';
    if (!is_dir($diretorio)) {
        @mkdir($diretorio, 0750, true);
    }
    if (is_dir($diretorio)) {
        $htaccess = $diretorio . '/.htaccess';
        if (!is_file($htaccess)) {
            @file_put_contents($htaccess, "Require all denied\nDeny from all\n");
        }
        $index = $diretorio . '/index.php';
        if (!is_file($index)) {
            @file_put_contents($index, "<?php http_response_code(404); exit;\n");
        }
    }
    return $diretorio;
}

function rojex_log_backup_json(mixed $valor): string {
    $json = json_encode(
        $valor,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
    );
    if ($json === false) {
        throw new RuntimeException('Não foi possível serializar os dados do backup.');
    }
    return $json;
}

function rojex_log_backup_csv_valor(mixed $valor): string {
    if ($valor === null) return '';
    if (is_bool($valor)) return $valor ? '1' : '0';
    if (is_array($valor) || is_object($valor)) return rojex_log_backup_json($valor);
    return (string)$valor;
}

function rojex_log_backup_buscar(mysqli $conn, int $backupId, bool $bloquear = false): ?array {
    $sql = "SELECT * FROM logs_backups WHERE id = ? LIMIT 1" . ($bloquear ? " FOR UPDATE" : "");
    $stmt = $conn->prepare($sql);
    if (!$stmt) throw new RuntimeException('Falha ao preparar a consulta do backup de LOG.');
    $stmt->bind_param('i', $backupId);
    $stmt->execute();
    $registro = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $registro ?: null;
}

function rojex_log_backup_caminho_valido(string $arquivo): bool {
    $base = realpath(rojex_log_backup_diretorio());
    $real = realpath($arquivo);
    return $base !== false
        && $real !== false
        && is_file($real)
        && ($real === $base || str_starts_with($real, $base . DIRECTORY_SEPARATOR));
}

function rojex_log_backup_gerar_zip(
    mysqli $conn,
    string $tenantId,
    int $escritorioId,
    string $escritorioNome,
    string $periodoInicio,
    string $periodoFim
): array {
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('A extensão ZIP do PHP não está habilitada.');
    }
    if ($tenantId === '' || $escritorioId <= 0) {
        throw new RuntimeException('Selecione um único escritório válido.');
    }

    $stmt = $conn->prepare(
        "SELECT * FROM logs_sistema
          WHERE tenant_id = ?
            AND escritorio_id = ?
            AND escopo = 'TENANT'
            AND criado_em >= ?
            AND criado_em <= ?
          ORDER BY id ASC"
    );
    if (!$stmt) throw new RuntimeException('Falha ao preparar a seleção isolada dos logs.');
    $stmt->bind_param('siss', $tenantId, $escritorioId, $periodoInicio, $periodoFim);
    $stmt->execute();
    $resultado = $stmt->get_result();
    $registros = [];
    while ($linha = $resultado->fetch_assoc()) {
        $registros[] = $linha;
    }
    $stmt->close();

    if (!$registros) {
        throw new RuntimeException('Nenhum LOG deste escritório foi encontrado no período informado.');
    }

    $ids = array_map(static fn(array $linha): int => (int)$linha['id'], $registros);
    $diretorio = rojex_log_backup_diretorio();
    if (!is_dir($diretorio) || !is_writable($diretorio)) {
        throw new RuntimeException('A pasta storage/log_backups não possui permissão de gravação.');
    }

    $sufixo = bin2hex(random_bytes(8));
    $tenantSeguro = preg_replace('/[^A-Za-z0-9_-]+/', '-', $tenantId) ?: 'tenant';
    $nomeZip = 'logs_' . $tenantSeguro . '_escritorio_' . $escritorioId . '_'
        . date('Ymd_His') . '_' . $sufixo . '.zip';
    $arquivoZip = $diretorio . DIRECTORY_SEPARATOR . $nomeZip;
    $temporario = $diretorio . DIRECTORY_SEPARATOR . '.tmp_' . $sufixo;
    if (!@mkdir($temporario, 0750, true) && !is_dir($temporario)) {
        throw new RuntimeException('Não foi possível criar a área temporária do backup.');
    }

    $arquivoCsv = $temporario . DIRECTORY_SEPARATOR . 'logs.csv';
    $arquivoJsonl = $temporario . DIRECTORY_SEPARATOR . 'logs.jsonl';
    $arquivoManifesto = $temporario . DIRECTORY_SEPARATOR . 'manifesto.json';

    try {
        $csv = fopen($arquivoCsv, 'wb');
        if (!$csv) throw new RuntimeException('Não foi possível criar logs.csv.');
        fwrite($csv, "\xEF\xBB\xBF");
        $cabecalhos = array_keys($registros[0]);
        fputcsv($csv, $cabecalhos, ';', '"', '\\');
        foreach ($registros as $registro) {
            $linhaCsv = [];
            foreach ($cabecalhos as $cabecalho) {
                $linhaCsv[] = rojex_log_backup_csv_valor($registro[$cabecalho] ?? null);
            }
            fputcsv($csv, $linhaCsv, ';', '"', '\\');
        }
        fclose($csv);

        $jsonl = fopen($arquivoJsonl, 'wb');
        if (!$jsonl) throw new RuntimeException('Não foi possível criar logs.jsonl.');
        foreach ($registros as $registro) {
            fwrite($jsonl, rojex_log_backup_json($registro) . "\n");
        }
        fclose($jsonl);

        $manifesto = [
            'produto' => 'ROJEX.AI ERP Jurídico Enterprise SaaS',
            'sprint' => '4.6.5',
            'formato' => 1,
            'gerado_em' => date(DATE_ATOM),
            'tenant_id' => $tenantId,
            'escritorio_id' => $escritorioId,
            'escritorio_nome' => $escritorioNome,
            'periodo_inicio' => $periodoInicio,
            'periodo_fim' => $periodoFim,
            'total_registros' => count($registros),
            'primeiro_id' => min($ids),
            'ultimo_id' => max($ids),
            'ids' => $ids,
            'arquivos' => [
                'logs.csv' => ['sha256' => hash_file('sha256', $arquivoCsv), 'bytes' => filesize($arquivoCsv)],
                'logs.jsonl' => ['sha256' => hash_file('sha256', $arquivoJsonl), 'bytes' => filesize($arquivoJsonl)],
            ],
        ];
        file_put_contents(
            $arquivoManifesto,
            json_encode($manifesto, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );

        $zip = new ZipArchive();
        if ($zip->open($arquivoZip, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
            throw new RuntimeException('Não foi possível criar o arquivo ZIP.');
        }
        $zip->addFile($arquivoCsv, 'logs.csv');
        $zip->addFile($arquivoJsonl, 'logs.jsonl');
        $zip->addFile($arquivoManifesto, 'manifesto.json');
        if (!$zip->close()) {
            throw new RuntimeException('Não foi possível finalizar o arquivo ZIP.');
        }

        $sha256 = hash_file('sha256', $arquivoZip);
        $tamanho = filesize($arquivoZip);
        if (!is_string($sha256) || strlen($sha256) !== 64 || $tamanho === false || $tamanho <= 0) {
            throw new RuntimeException('O ZIP foi criado, mas sua integridade inicial não pôde ser confirmada.');
        }

        return [
            'arquivo' => $arquivoZip,
            'nome_arquivo' => $nomeZip,
            'sha256' => $sha256,
            'tamanho_bytes' => (int)$tamanho,
            'total_registros' => count($registros),
            'ids_json' => rojex_log_backup_json($ids),
        ];
    } catch (Throwable $e) {
        if (is_file($arquivoZip)) @unlink($arquivoZip);
        throw $e;
    } finally {
        foreach ([$arquivoCsv, $arquivoJsonl, $arquivoManifesto] as $temporarioArquivo) {
            if (is_file($temporarioArquivo)) @unlink($temporarioArquivo);
        }
        if (is_dir($temporario)) @rmdir($temporario);
    }
}


function rojex_atualizacao_versao_atual(mysqli $conn): string {
    $versao = trim(sgl_cfg_get($conn, 'versao_sistema', ''));
    if ($versao === '') {
        $versao = trim(sgl_cfg_get($conn, 'versao', '4.1.3'));
    }
    return $versao !== '' ? $versao : '4.1.3';
}

function rojex_atualizacao_ambiente(mysqli $conn): string {
    $ambiente = trim(sgl_cfg_get($conn, 'ambiente_sistema', 'desenvolvimento'));
    return in_array($ambiente, ['desenvolvimento','homologacao','producao'], true)
        ? $ambiente
        : 'desenvolvimento';
}

function rojex_atualizacao_banco_versao(mysqli $conn): string {
    try {
        $row = $conn->query("SELECT VERSION() AS versao")->fetch_assoc();
        return (string)($row['versao'] ?? 'Não identificado');
    } catch (Throwable $e) {
        return 'Não identificado';
    }
}

function rojex_atualizacao_backup_recente(mysqli $conn, int $dias = 30): array {
    $resultado = [
        'ok' => false,
        'id' => null,
        'data' => null,
        'status' => 'ausente',
        'dias' => null,
    ];

    if (!sgl_tabela_existe($conn, 'backups_sistema')) {
        return $resultado;
    }

    try {
        $stmt = $conn->prepare(
            "SELECT id, criado_em, verificacao_status
               FROM backups_sistema
              WHERE status = 'concluido'
                AND verificacao_status = 'integro'
              ORDER BY id DESC
              LIMIT 1"
        );
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) return $resultado;

        $timestamp = strtotime((string)$row['criado_em']);
        $idadeDias = $timestamp ? (int)floor((time() - $timestamp) / 86400) : null;

        return [
            'ok' => $idadeDias !== null && $idadeDias <= $dias,
            'id' => (int)$row['id'],
            'data' => (string)$row['criado_em'],
            'status' => (string)($row['verificacao_status'] ?? 'não verificado'),
            'dias' => $idadeDias,
        ];
    } catch (Throwable $e) {
        return $resultado;
    }
}

function rojex_atualizacao_diagnostico(mysqli $conn, array $atualizacao): array {
    $raiz = realpath(__DIR__ . '/..') ?: (__DIR__ . '/..');
    $phpMinimo = trim((string)($atualizacao['versao_php_minima'] ?? '8.0.0'));
    if ($phpMinimo === '') $phpMinimo = '8.0.0';

    $bancoMinimo = trim((string)($atualizacao['versao_banco_minima'] ?? ''));
    $bancoAtual = rojex_atualizacao_banco_versao($conn);
    $backup = rojex_atualizacao_backup_recente($conn, 30);
    $espacoLivre = @disk_free_space($raiz);
    $tamanhoEstimado = max(0, (int)($atualizacao['tamanho_estimado_bytes'] ?? 0));
    $espacoMinimo = max(50 * 1024 * 1024, $tamanhoEstimado * 3);

    $pastas = [
        'Raiz do projeto' => $raiz,
        'Modules' => $raiz . DIRECTORY_SEPARATOR . 'modules',
        'Config' => $raiz . DIRECTORY_SEPARATOR . 'config',
        'Storage' => $raiz . DIRECTORY_SEPARATOR . 'storage',
    ];

    $permissoes = [];
    $pastasOk = true;
    foreach ($pastas as $nome => $caminho) {
        $existe = is_dir($caminho);
        $gravavel = $existe && is_writable($caminho);
        $permissoes[$nome] = ['existe'=>$existe, 'gravavel'=>$gravavel];
        if (!$existe || !$gravavel) $pastasOk = false;
    }

    $checks = [
        'php' => [
            'titulo' => 'Versão do PHP',
            'ok' => version_compare(PHP_VERSION, $phpMinimo, '>='),
            'atual' => PHP_VERSION,
            'requerido' => $phpMinimo,
            'recomendacao' => "PHP {$phpMinimo} ou superior.",
        ],
        'banco' => [
            'titulo' => 'Banco de dados',
            'ok' => $bancoAtual !== 'Não identificado',
            'atual' => $bancoAtual,
            'requerido' => $bancoMinimo !== '' ? $bancoMinimo : 'Compatível com a instalação atual',
            'recomendacao' => 'Validar scripts de migração antes de uma atualização real.',
        ],
        'backup' => [
            'titulo' => 'Backup íntegro recente',
            'ok' => $backup['ok'],
            'atual' => $backup['data']
                ? date('d/m/Y H:i', strtotime($backup['data'])) . ' (' . $backup['dias'] . ' dia(s))'
                : 'Nenhum backup íntegro localizado',
            'requerido' => 'Backup íntegro com até 30 dias',
            'recomendacao' => 'Crie e verifique um backup completo antes de atualizar.',
        ],
        'disco' => [
            'titulo' => 'Espaço livre',
            'ok' => $espacoLivre !== false && $espacoLivre >= $espacoMinimo,
            'atual' => $espacoLivre !== false ? sgl_formatar_bytes((float)$espacoLivre) : 'Não identificado',
            'requerido' => sgl_formatar_bytes((float)$espacoMinimo),
            'recomendacao' => 'Reserve pelo menos três vezes o tamanho estimado do pacote.',
        ],
        'permissoes' => [
            'titulo' => 'Permissões operacionais',
            'ok' => $pastasOk,
            'atual' => $pastasOk ? 'Pastas acessíveis e graváveis' : 'Uma ou mais pastas exigem atenção',
            'requerido' => 'Pastas essenciais graváveis',
            'recomendacao' => 'Corrija permissões antes de qualquer atualização real.',
        ],
    ];

    $aprovados = 0;
    foreach ($checks as $check) {
        if ($check['ok']) $aprovados++;
    }

    $percentual = (int)round(($aprovados / max(1, count($checks))) * 100);
    $status = $percentual === 100 ? 'compativel' : ($percentual >= 60 ? 'atencao' : 'incompativel');

    return [
        'checks' => $checks,
        'permissoes' => $permissoes,
        'percentual' => $percentual,
        'status' => $status,
        'backup' => $backup,
        'espaco_livre' => $espacoLivre,
        'espaco_minimo' => $espacoMinimo,
        'gerado_em' => time(),
    ];
}
// -----------------------------------------------------------------------------
// Autoridade MASTER
// -----------------------------------------------------------------------------
// O primeiro administrador autenticado nesta versão é fixado como MASTER técnico.
// Isso evita bloquear o administrador atual mesmo que seu perfil antigo ainda seja
// apenas "Administrador".
$usuarioSessaoId = (int)($_SESSION['user_id'] ?? 0);
$perfilSessaoAtual = (string)($_SESSION['perfil'] ?? '');
$usuarioMasterId = (int)sgl_cfg_get($conn, 'usuario_master_id', '0');

if ($usuarioMasterId <= 0 && $usuarioSessaoId > 0 && in_array($perfilSessaoAtual, ['Administrador', 'Administrador Master'], true)) {
    $usuarioMasterId = $usuarioSessaoId;
    sgl_cfg_set($conn, 'usuario_master_id', (string)$usuarioMasterId);
}

$ehUsuarioMaster = $usuarioSessaoId > 0 && (
    $usuarioSessaoId === $usuarioMasterId
    || $perfilSessaoAtual === 'Administrador Master'
);

$msg = '';
$msg_tipo = 'success';
$acao_cfg = $_POST['acao_cfg'] ?? '';
$csrf = gerarTokenCsrf();
$tab_ativa = $_GET['tab'] ?? 'escritorio';

$acoesExclusivasMaster = [
    'novo_usuario',
    'editar_usuario',
    'alterar_status_usuario',
    'resetar_senha_usuario',
    'encerrar_vinculo_usuario',
    'salvar_sistema',
    'salvar_licenca_saas',
    'alterar_status_licenca_saas',
    'salvar_escritorio_saas',
    'assistente_novo_escritorio_salvar',
    'assistente_novo_escritorio_reiniciar',
    'assistente_novo_escritorio_provisionar',
    'alterar_status_escritorio_saas',
    'salvar_plano_saas',
    'alterar_status_plano_saas',
    'excluir_plano_saas',
    'ativar_portal_escritorio',
    'criar_conta_portal',
    'alterar_status_conta_portal',
    'reemitir_convite_portal',
    'simular_manutencao',
    'executar_manutencao',
    'simular_backup',
    'executar_backup',
    'verificar_backup',
    'gerar_log_backup',
    'verificar_log_backup',
    'arquivar_log_backup',
    'salvar_atualizacao',
    'alterar_status_atualizacao',
    'simular_atualizacao',
];

if ($acao_cfg !== '' && in_array($acao_cfg, $acoesExclusivasMaster, true) && !$ehUsuarioMaster) {
    sgl_redirect_cfg('escritorio', 'erro', 'Ação permitida somente ao usuário MASTER.');
}

if (in_array($tab_ativa, ['usuarios', 'sistema', 'administracao', 'novo_escritorio', 'planos', 'modulos', 'portal', 'desligados', 'relatorios', 'saude', 'manutencao', 'backup', 'atualizacoes', 'logs'], true) && !$ehUsuarioMaster) {
    sgl_redirect_cfg('escritorio', 'erro', 'Área restrita ao usuário MASTER.');
}

if (isset($_GET['msg_sucesso'])) { $msg = $_GET['msg_sucesso']; $msg_tipo = 'success'; }
if (isset($_GET['msg_aviso'])) { $msg = $_GET['msg_aviso']; $msg_tipo = 'warning'; }
if (isset($_GET['msg_erro'])) { $msg = $_GET['msg_erro']; $msg_tipo = 'danger'; }

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !validarTokenCsrf($_POST['csrf_token'] ?? null)) {
    $msg = 'Token de segurança inválido. Atualize a página e tente novamente.';
    $msg_tipo = 'danger';
    $acao_cfg = '';
}

// Download autenticado. O buffer iniciado pelo index.php impede qualquer saída
// HTML anterior aos cabeçalhos do arquivo.
if (
    $ehUsuarioMaster
    && ($_GET['acao_cfg'] ?? '') === 'baixar_log_backup'
    && $_SERVER['REQUEST_METHOD'] === 'GET'
) {
    $backupIdDownload = max(0, (int)($_GET['backup_id'] ?? 0));
    try {
        $backupDownload = rojex_log_backup_buscar($conn, $backupIdDownload);
        if (!$backupDownload || !rojex_log_backup_caminho_valido((string)$backupDownload['arquivo'])) {
            throw new RuntimeException('Arquivo de backup não localizado.');
        }
        $hashAtual = hash_file('sha256', (string)$backupDownload['arquivo']);
        if (!is_string($hashAtual) || !hash_equals((string)$backupDownload['sha256'], $hashAtual)) {
            throw new RuntimeException('Download bloqueado: o SHA-256 do ZIP não confere.');
        }

        $agoraDownload = date('Y-m-d H:i:s');
        $stmt = $conn->prepare(
            "UPDATE logs_backups
                SET download_em = ?, status = CASE WHEN status = 'VERIFICADO' THEN 'BAIXADO' ELSE status END
              WHERE id = ?"
        );
        $stmt->bind_param('si', $agoraDownload, $backupIdDownload);
        $stmt->execute();
        $stmt->close();
        sgl_log(
            $conn,
            'Download de backup de LOG iniciado',
            'logs_backups',
            (string)$backupIdDownload,
            'Arquivo: ' . (string)$backupDownload['nome_arquivo']
        );

        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        $arquivoDownload = (string)$backupDownload['arquivo'];
        $nomeDownload = basename((string)$backupDownload['nome_arquivo']);
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . str_replace('"', '', $nomeDownload) . '"');
        header('Content-Length: ' . (string)filesize($arquivoDownload));
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('X-Content-Type-Options: nosniff');
        $handleDownload = fopen($arquivoDownload, 'rb');
        if (!$handleDownload) throw new RuntimeException('Não foi possível abrir o ZIP para download.');
        fpassthru($handleDownload);
        fclose($handleDownload);
        exit;
    } catch (Throwable $e) {
        sgl_redirect_cfg('logs', 'erro', $e->getMessage());
    }
}

// -----------------------------------------------------------------------------
// Ações POST
// -----------------------------------------------------------------------------
// Backup e Arquivamento do LOG por Escritório — Sprint 4.6.5
// -----------------------------------------------------------------------------
if ($acao_cfg === 'gerar_log_backup') {
    $escritorioIdBackup = max(0, (int)($_POST['log_backup_escritorio_id'] ?? 0));
    $dataInicioBackup = trim((string)($_POST['log_backup_data_inicio'] ?? ''));
    $dataFimBackup = trim((string)($_POST['log_backup_data_fim'] ?? ''));
    $inicioObj = DateTime::createFromFormat('!Y-m-d', $dataInicioBackup);
    $fimObj = DateTime::createFromFormat('!Y-m-d', $dataFimBackup);

    if ($escritorioIdBackup <= 0 || !$inicioObj || !$fimObj) {
        sgl_redirect_cfg('logs', 'erro', 'Selecione um único escritório e informe o período inicial e final.');
    }
    if ($inicioObj > $fimObj) {
        sgl_redirect_cfg('logs', 'erro', 'A data inicial não pode ser posterior à data final.');
    }

    $zipGerado = null;
    try {
        $stmt = $conn->prepare(
            "SELECT id, tenant_id, nome FROM escritorios_saas WHERE id = ? LIMIT 1"
        );
        $stmt->bind_param('i', $escritorioIdBackup);
        $stmt->execute();
        $escritorioBackup = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$escritorioBackup || trim((string)$escritorioBackup['tenant_id']) === '') {
            throw new RuntimeException('Escritório inválido ou sem tenant_id.');
        }

        $periodoInicioSql = $inicioObj->format('Y-m-d 00:00:00');
        $periodoFimSql = $fimObj->format('Y-m-d 23:59:59');
        $zipGerado = rojex_log_backup_gerar_zip(
            $conn,
            trim((string)$escritorioBackup['tenant_id']),
            (int)$escritorioBackup['id'],
            (string)$escritorioBackup['nome'],
            $periodoInicioSql,
            $periodoFimSql
        );
        $statusGerado = 'GERADO';
        $responsavelBackup = (string)($_SESSION['nome'] ?? $_SESSION['username'] ?? 'MASTER');
        $stmt = $conn->prepare(
            "INSERT INTO logs_backups (
                tenant_id, escritorio_id, escritorio_nome, periodo_inicio, periodo_fim,
                arquivo, nome_arquivo, sha256, tamanho_bytes, total_registros,
                ids_json, status, criado_por, criado_por_nome
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->bind_param(
            'sissssssiissis',
            $escritorioBackup['tenant_id'],
            $escritorioIdBackup,
            $escritorioBackup['nome'],
            $periodoInicioSql,
            $periodoFimSql,
            $zipGerado['arquivo'],
            $zipGerado['nome_arquivo'],
            $zipGerado['sha256'],
            $zipGerado['tamanho_bytes'],
            $zipGerado['total_registros'],
            $zipGerado['ids_json'],
            $statusGerado,
            $usuarioSessaoId,
            $responsavelBackup
        );
        if (!$stmt->execute()) throw new RuntimeException($stmt->error ?: 'Falha ao registrar o backup.');
        $novoBackupId = (int)$stmt->insert_id;
        $stmt->close();
        sgl_log(
            $conn,
            'Gerou backup isolado do LOG',
            'logs_backups',
            (string)$novoBackupId,
            'Tenant: ' . $escritorioBackup['tenant_id'] . '; Escritório: ' . $escritorioIdBackup
                . '; Registros: ' . $zipGerado['total_registros'] . '; SHA-256: ' . $zipGerado['sha256']
        );
        sgl_redirect_cfg('logs', 'sucesso', 'ZIP isolado gerado. Agora clique em Verificar.');
    } catch (Throwable $e) {
        if (is_array($zipGerado) && !empty($zipGerado['arquivo']) && is_file((string)$zipGerado['arquivo'])) {
            @unlink((string)$zipGerado['arquivo']);
        }
        sgl_redirect_cfg('logs', 'erro', 'Não foi possível gerar o backup do LOG: ' . $e->getMessage());
    }
}

if ($acao_cfg === 'verificar_log_backup') {
    $backupId = max(0, (int)($_POST['backup_id'] ?? 0));
    try {
        $backup = rojex_log_backup_buscar($conn, $backupId);
        if (!$backup || !rojex_log_backup_caminho_valido((string)$backup['arquivo'])) {
            throw new RuntimeException('ZIP não localizado no servidor.');
        }
        $hashAtual = hash_file('sha256', (string)$backup['arquivo']);
        if (!is_string($hashAtual) || !hash_equals((string)$backup['sha256'], $hashAtual)) {
            $statusFalha = 'FALHA_INTEGRIDADE';
            $stmt = $conn->prepare("UPDATE logs_backups SET status = ? WHERE id = ?");
            $stmt->bind_param('si', $statusFalha, $backupId);
            $stmt->execute();
            $stmt->close();
            throw new RuntimeException('Falha de integridade: SHA-256 divergente.');
        }
        $zipTeste = new ZipArchive();
        if ($zipTeste->open((string)$backup['arquivo'], ZipArchive::CHECKCONS) !== true) {
            throw new RuntimeException('O arquivo não passou na validação estrutural ZIP.');
        }
        foreach (['logs.csv', 'logs.jsonl', 'manifesto.json'] as $itemObrigatorio) {
            if ($zipTeste->locateName($itemObrigatorio) === false) {
                $zipTeste->close();
                throw new RuntimeException('ZIP incompleto: ' . $itemObrigatorio . ' não foi encontrado.');
            }
        }
        $zipTeste->close();
        $agora = date('Y-m-d H:i:s');
        $statusVerificado = 'VERIFICADO';
        $stmt = $conn->prepare(
            "UPDATE logs_backups SET status = ?, verificado_em = ? WHERE id = ?"
        );
        $stmt->bind_param('ssi', $statusVerificado, $agora, $backupId);
        $stmt->execute();
        $stmt->close();
        sgl_log($conn, 'Verificou backup isolado do LOG', 'logs_backups', (string)$backupId, 'SHA-256 confirmado.');
        sgl_redirect_cfg('logs', 'sucesso', 'Integridade confirmada. Faça o download do ZIP.');
    } catch (Throwable $e) {
        sgl_redirect_cfg('logs', 'erro', $e->getMessage());
    }
}

if ($acao_cfg === 'arquivar_log_backup') {
    $backupId = max(0, (int)($_POST['backup_id'] ?? 0));
    $confirmacao = strtoupper(trim((string)($_POST['confirmacao_arquivar'] ?? '')));
    if ($confirmacao !== 'ARQUIVAR') {
        sgl_redirect_cfg('logs', 'erro', 'Confirmação inválida. Digite ARQUIVAR.');
    }

    $transacaoAberta = false;
    $arquivoParaRemover = '';
    $arquivoRenomeado = '';
    try {
        $conn->begin_transaction();
        $transacaoAberta = true;
        $backup = rojex_log_backup_buscar($conn, $backupId, true);
        if (!$backup) throw new RuntimeException('Backup não localizado.');
        if (empty($backup['verificado_em']) || empty($backup['download_em'])) {
            throw new RuntimeException('É obrigatório verificar e baixar o ZIP antes de arquivar.');
        }
        if (in_array((string)$backup['status'], ['ARQUIVADO', 'FALHA_INTEGRIDADE'], true)) {
            throw new RuntimeException('Este backup não está disponível para arquivamento.');
        }
        if (!rojex_log_backup_caminho_valido((string)$backup['arquivo'])) {
            throw new RuntimeException('O ZIP temporário não está disponível.');
        }
        $hashAtual = hash_file('sha256', (string)$backup['arquivo']);
        if (!is_string($hashAtual) || !hash_equals((string)$backup['sha256'], $hashAtual)) {
            throw new RuntimeException('Arquivamento bloqueado: SHA-256 divergente.');
        }
        $ids = json_decode((string)$backup['ids_json'], true);
        if (!is_array($ids) || !$ids) throw new RuntimeException('A lista imutável de IDs do backup está vazia.');
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn(int $id): bool => $id > 0)));
        if (!$ids) throw new RuntimeException('Nenhum ID válido foi encontrado no manifesto interno.');

        $totalExcluido = 0;
        foreach (array_chunk($ids, 500) as $loteIds) {
            $marcadores = implode(',', array_fill(0, count($loteIds), '?'));
            $tipos = 'si' . str_repeat('i', count($loteIds));
            $valores = array_merge([(string)$backup['tenant_id'], (int)$backup['escritorio_id']], $loteIds);
            $stmt = $conn->prepare(
                "DELETE FROM logs_sistema
                  WHERE tenant_id = ?
                    AND escritorio_id = ?
                    AND escopo = 'TENANT'
                    AND id IN ($marcadores)"
            );
            if (!$stmt) throw new RuntimeException('Falha ao preparar a exclusão isolada dos IDs.');
            $stmt->bind_param($tipos, ...$valores);
            if (!$stmt->execute()) throw new RuntimeException($stmt->error ?: 'Falha ao arquivar os logs.');
            $totalExcluido += max(0, $stmt->affected_rows);
            $stmt->close();
        }

        $agora = date('Y-m-d H:i:s');
        $statusArquivado = 'ARQUIVADO';
        $stmt = $conn->prepare(
            "UPDATE logs_backups
                SET status = ?, arquivado_em = ?, arquivo = ''
              WHERE id = ?"
        );
        $stmt->bind_param('ssi', $statusArquivado, $agora, $backupId);
        $stmt->execute();
        $stmt->close();

        $arquivoParaRemover = (string)$backup['arquivo'];
        $arquivoRenomeado = $arquivoParaRemover . '.arquivando';
        if (!@rename($arquivoParaRemover, $arquivoRenomeado)) {
            throw new RuntimeException('Não foi possível preparar a remoção segura do ZIP temporário.');
        }

        $conn->commit();
        $transacaoAberta = false;
        if (!@unlink($arquivoRenomeado)) {
            error_log('[ROJEX LOG BACKUP] ZIP arquivado pendente de remoção: ' . basename($arquivoRenomeado));
        }
        sgl_log(
            $conn,
            'Arquivou backup isolado do LOG',
            'logs_backups',
            (string)$backupId,
            'IDs previstos: ' . count($ids) . '; registros removidos: ' . $totalExcluido
        );
        sgl_redirect_cfg('logs', 'sucesso', 'Arquivamento concluído. Somente os IDs do ZIP foram removidos e a cópia temporária foi apagada.');
    } catch (Throwable $e) {
        if ($transacaoAberta) {
            try { $conn->rollback(); } catch (Throwable $ignorado) {}
        }
        if ($arquivoRenomeado !== '' && is_file($arquivoRenomeado) && !is_file($arquivoParaRemover)) {
            @rename($arquivoRenomeado, $arquivoParaRemover);
        }
        sgl_redirect_cfg('logs', 'erro', 'Arquivamento não concluído: ' . $e->getMessage());
    }
}

// -----------------------------------------------------------------------------
// -----------------------------------------------------------------------------
// Central de Atualizações Enterprise — Sprint 4.1.3 / Etapa 9
// -----------------------------------------------------------------------------
if ($acao_cfg === 'salvar_atualizacao') {
    $atualizacaoId = max(0, (int)($_POST['atualizacao_id'] ?? 0));
    $versao = sgl_limpar_texto((string)($_POST['atualizacao_versao'] ?? ''), 40);
    $titulo = sgl_limpar_texto((string)($_POST['atualizacao_titulo'] ?? ''), 180);
    $descricao = sgl_limpar_texto((string)($_POST['atualizacao_descricao'] ?? ''), 3000);
    $changelog = trim((string)($_POST['atualizacao_changelog'] ?? ''));
    $requisitos = trim((string)($_POST['atualizacao_requisitos'] ?? ''));
    $tipo = (string)($_POST['atualizacao_tipo'] ?? 'melhoria');
    $status = (string)($_POST['atualizacao_status'] ?? 'planejada');
    $impacto = (string)($_POST['atualizacao_impacto'] ?? 'baixo');
    $obrigatoria = !empty($_POST['atualizacao_obrigatoria']) ? 1 : 0;
    $phpMinimo = sgl_limpar_texto((string)($_POST['atualizacao_php_minimo'] ?? '8.0.0'), 20);
    $bancoMinimo = sgl_limpar_texto((string)($_POST['atualizacao_banco_minimo'] ?? ''), 30);
    $arquivosEstimados = max(0, min(100000, (int)($_POST['atualizacao_arquivos_estimados'] ?? 0)));
    $tamanhoMb = max(0, min(100000, (float)str_replace(',', '.', (string)($_POST['atualizacao_tamanho_mb'] ?? '0'))));
    $tamanhoBytes = (int)round($tamanhoMb * 1024 * 1024);
    $publicadaEm = trim((string)($_POST['atualizacao_publicada_em'] ?? ''));

    $tiposPermitidos = ['melhoria','correcao','seguranca','banco','interface','integracao'];
    $statusPermitidos = ['planejada','disponivel','homologacao','instalada','cancelada'];
    $impactosPermitidos = ['baixo','medio','alto','critico'];

    if ($versao === '' || $titulo === '') {
        sgl_redirect_cfg('atualizacoes', 'erro', 'Informe a versão e o título da atualização.');
    }
    if (!preg_match('/^[0-9A-Za-z._-]{1,40}$/', $versao)) {
        sgl_redirect_cfg('atualizacoes', 'erro', 'A versão contém caracteres inválidos.');
    }
    if (!in_array($tipo, $tiposPermitidos, true)) $tipo = 'melhoria';
    if (!in_array($status, $statusPermitidos, true)) $status = 'planejada';
    if (!in_array($impacto, $impactosPermitidos, true)) $impacto = 'baixo';

    $publicadaSql = null;
    if ($publicadaEm !== '') {
        $objPublicacao = DateTime::createFromFormat('Y-m-d\TH:i', $publicadaEm);
        if (!$objPublicacao) {
            sgl_redirect_cfg('atualizacoes', 'erro', 'Data de publicação inválida.');
        }
        $publicadaSql = $objPublicacao->format('Y-m-d H:i:s');
    }

    try {
        $stmtDuplicada = $conn->prepare(
            "SELECT id FROM atualizacoes_sistema WHERE versao = ? AND id <> ? LIMIT 1"
        );
        $stmtDuplicada->bind_param('si', $versao, $atualizacaoId);
        $stmtDuplicada->execute();
        $duplicada = $stmtDuplicada->get_result()->fetch_assoc();
        $stmtDuplicada->close();

        if ($duplicada) {
            sgl_redirect_cfg('atualizacoes', 'erro', 'Esta versão já está cadastrada.');
        }

        $responsavel = (string)($_SESSION['nome'] ?? $_SESSION['username'] ?? 'Sistema');
        $aplicadaEm = $status === 'instalada' ? date('Y-m-d H:i:s') : null;
        $aplicadaPor = $status === 'instalada' ? $usuarioSessaoId : null;

        if ($atualizacaoId > 0) {
            $stmt = $conn->prepare(
                "UPDATE atualizacoes_sistema
                    SET versao=?, titulo=?, descricao=?, status=?, obrigatoria=?, publicada_em=?,
                        aplicada_em=?, aplicada_por=?, tipo=?, changelog=?, requisitos=?, impacto=?,
                        versao_php_minima=?, versao_banco_minima=?, tamanho_estimado_bytes=?,
                        arquivos_estimados=?, responsavel_nome=?
                  WHERE id=?"
            );
            $tiposBind = 'ssssississssssii' . 'si';
            $stmt->bind_param(
                $tiposBind,
                $versao,
                $titulo,
                $descricao,
                $status,
                $obrigatoria,
                $publicadaSql,
                $aplicadaEm,
                $aplicadaPor,
                $tipo,
                $changelog,
                $requisitos,
                $impacto,
                $phpMinimo,
                $bancoMinimo,
                $tamanhoBytes,
                $arquivosEstimados,
                $responsavel,
                $atualizacaoId
            );
            $stmt->execute();
            $stmt->close();
            $registroAtualizacao = $atualizacaoId;
            $acaoAtualizacao = 'Atualizou versão na Central de Atualizações';
            $mensagemAtualizacao = 'Atualização editada com sucesso.';
        } else {
            $stmt = $conn->prepare(
                "INSERT INTO atualizacoes_sistema
                    (versao,titulo,descricao,status,obrigatoria,publicada_em,aplicada_em,aplicada_por,
                     tipo,changelog,requisitos,impacto,versao_php_minima,versao_banco_minima,
                     tamanho_estimado_bytes,arquivos_estimados,responsavel_nome)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
            );
            $tiposBind = 'ssssississssssii' . 's';
            $stmt->bind_param(
                $tiposBind,
                $versao,
                $titulo,
                $descricao,
                $status,
                $obrigatoria,
                $publicadaSql,
                $aplicadaEm,
                $aplicadaPor,
                $tipo,
                $changelog,
                $requisitos,
                $impacto,
                $phpMinimo,
                $bancoMinimo,
                $tamanhoBytes,
                $arquivosEstimados,
                $responsavel
            );
            $stmt->execute();
            $registroAtualizacao = (int)$stmt->insert_id;
            $stmt->close();
            $acaoAtualizacao = 'Cadastrou versão na Central de Atualizações';
            $mensagemAtualizacao = 'Atualização cadastrada com sucesso.';
        }

        if ($status === 'instalada') {
            sgl_cfg_set($conn, 'versao_sistema', $versao);
            sgl_cfg_set($conn, 'data_atualizacao_sistema', date('Y-m-d H:i:s'));
        }

        sgl_log(
            $conn,
            $acaoAtualizacao,
            'atualizacoes_sistema',
            (string)$registroAtualizacao,
            "Versão: {$versao}; Status: {$status}; Impacto: {$impacto}"
        );
        sgl_redirect_cfg('atualizacoes', 'sucesso', $mensagemAtualizacao);
    } catch (Throwable $e) {
        sgl_redirect_cfg('atualizacoes', 'erro', 'Não foi possível salvar a atualização.');
    }
}

if ($acao_cfg === 'alterar_status_atualizacao') {
    $atualizacaoId = max(0, (int)($_POST['atualizacao_id'] ?? 0));
    $novoStatus = (string)($_POST['novo_status_atualizacao'] ?? '');
    $statusPermitidos = ['planejada','disponivel','homologacao','instalada','cancelada'];

    if ($atualizacaoId <= 0 || !in_array($novoStatus, $statusPermitidos, true)) {
        sgl_redirect_cfg('atualizacoes', 'erro', 'Atualização ou status inválido.');
    }

    try {
        $stmt = $conn->prepare("SELECT versao FROM atualizacoes_sistema WHERE id=? LIMIT 1");
        $stmt->bind_param('i', $atualizacaoId);
        $stmt->execute();
        $rowAtualizacao = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$rowAtualizacao) {
            sgl_redirect_cfg('atualizacoes', 'erro', 'Atualização não encontrada.');
        }

        $aplicadaEm = $novoStatus === 'instalada' ? date('Y-m-d H:i:s') : null;
        $aplicadaPor = $novoStatus === 'instalada' ? $usuarioSessaoId : null;

        $stmt = $conn->prepare(
            "UPDATE atualizacoes_sistema
                SET status=?, aplicada_em=?, aplicada_por=?
              WHERE id=?"
        );
        $stmt->bind_param('ssii', $novoStatus, $aplicadaEm, $aplicadaPor, $atualizacaoId);
        $stmt->execute();
        $stmt->close();

        if ($novoStatus === 'instalada') {
            sgl_cfg_set($conn, 'versao_sistema', (string)$rowAtualizacao['versao']);
            sgl_cfg_set($conn, 'data_atualizacao_sistema', date('Y-m-d H:i:s'));
        }

        sgl_log(
            $conn,
            'Alterou status de atualização',
            'atualizacoes_sistema',
            (string)$atualizacaoId,
            'Novo status: ' . $novoStatus
        );
        sgl_redirect_cfg('atualizacoes', 'sucesso', 'Status da atualização alterado.');
    } catch (Throwable $e) {
        sgl_redirect_cfg('atualizacoes', 'erro', 'Não foi possível alterar o status.');
    }
}

if ($acao_cfg === 'simular_atualizacao') {
    $atualizacaoId = max(0, (int)($_POST['atualizacao_id'] ?? 0));

    try {
        $stmt = $conn->prepare("SELECT * FROM atualizacoes_sistema WHERE id=? LIMIT 1");
        $stmt->bind_param('i', $atualizacaoId);
        $stmt->execute();
        $atualizacaoSelecionada = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$atualizacaoSelecionada) {
            sgl_redirect_cfg('atualizacoes', 'erro', 'Atualização não encontrada para simulação.');
        }

        $diagnosticoAtualizacao = rojex_atualizacao_diagnostico($conn, $atualizacaoSelecionada);
        $diagnosticoAtualizacao['atualizacao_id'] = $atualizacaoId;
        $diagnosticoAtualizacao['versao'] = (string)$atualizacaoSelecionada['versao'];
        $diagnosticoAtualizacao['titulo'] = (string)$atualizacaoSelecionada['titulo'];

        $_SESSION['rojex_atualizacao_preview'] = $diagnosticoAtualizacao;

        $agora = date('Y-m-d H:i:s');
        $stmt = $conn->prepare(
            "UPDATE atualizacoes_sistema
                SET verificada_em=?, compatibilidade_status=?
              WHERE id=?"
        );
        $stmt->bind_param(
            'ssi',
            $agora,
            $diagnosticoAtualizacao['status'],
            $atualizacaoId
        );
        $stmt->execute();
        $stmt->close();

        sgl_log(
            $conn,
            'Simulou atualização do sistema',
            'atualizacoes_sistema',
            (string)$atualizacaoId,
            "Versão: {$atualizacaoSelecionada['versao']}; Compatibilidade: {$diagnosticoAtualizacao['status']}; Pontuação: {$diagnosticoAtualizacao['percentual']}%"
        );

        sgl_redirect_cfg(
            'atualizacoes',
            $diagnosticoAtualizacao['status'] === 'compativel' ? 'sucesso' : 'aviso',
            'Simulação concluída. Nenhum arquivo ou dado foi alterado.'
        );
    } catch (Throwable $e) {
        sgl_redirect_cfg('atualizacoes', 'erro', 'Não foi possível executar a simulação da atualização.');
    }
}

// -----------------------------------------------------------------------------
// Estrutura de Backup Enterprise — Sprint 4.1.3 / Etapa 8
// -----------------------------------------------------------------------------
if ($acao_cfg === 'simular_backup') {
    $tipoBackup = (string)($_POST['backup_tipo'] ?? 'banco');
    if (!in_array($tipoBackup, ['banco','arquivos','completo'], true)) {
        $tipoBackup = 'banco';
    }

    $mapaArquivos = rojex_backup_mapear_arquivos();
    $tabelasBackup = rojex_manutencao_tabelas($conn);
    $estimativaBanco = 0;
    foreach ($tabelasBackup as $tabelaBackup) {
        $estimativaBanco += (int)($tabelaBackup['bytes'] ?? 0);
    }

    $previewBackup = [
        'tipo'=>$tipoBackup,
        'criado_em'=>time(),
        'tabelas'=>count($tabelasBackup),
        'estimativa_banco_bytes'=>$estimativaBanco,
        'arquivos_quantidade'=>$mapaArquivos['quantidade'],
        'arquivos_bytes'=>$mapaArquivos['bytes'],
        'zip_disponivel'=>class_exists('ZipArchive'),
        'diretorio'=>rojex_backup_diretorio(),
    ];

    $previewBackup['hash'] = hash_hmac(
        'sha256',
        json_encode([
            $previewBackup['tipo'],
            $previewBackup['criado_em'],
            $previewBackup['tabelas'],
            $previewBackup['arquivos_quantidade'],
        ]),
        (string)($_SESSION['csrf_token'] ?? session_id())
    );

    $_SESSION['rojex_backup_preview'] = $previewBackup;
    sgl_log($conn, 'Simulou backup Enterprise', 'backups_sistema', null, 'Tipo: ' . $tipoBackup);
    sgl_redirect_cfg('backup', 'sucesso', 'Simulação de backup concluída. Revise a prévia antes de executar.');
}

if ($acao_cfg === 'executar_backup') {
    $previewBackup = $_SESSION['rojex_backup_preview'] ?? null;
    $hashInformado = (string)($_POST['backup_hash'] ?? '');
    $confirmacao = strtoupper(trim((string)($_POST['confirmacao_backup'] ?? '')));

    if (!is_array($previewBackup) || empty($previewBackup['hash']) || !hash_equals((string)$previewBackup['hash'], $hashInformado)) {
        sgl_redirect_cfg('backup', 'erro', 'A simulação de backup expirou ou não corresponde à execução.');
    }
    if ((time() - (int)($previewBackup['criado_em'] ?? 0)) > 1800) {
        unset($_SESSION['rojex_backup_preview']);
        sgl_redirect_cfg('backup', 'erro', 'A simulação expirou após 30 minutos. Faça uma nova simulação.');
    }
    if ($confirmacao !== 'BACKUP') {
        sgl_redirect_cfg('backup', 'erro', 'Confirmação inválida. Digite BACKUP.');
    }

    $tipoBackup = (string)$previewBackup['tipo'];
    $diretorioBackup = rojex_backup_diretorio();

    if (!is_dir($diretorioBackup) || !is_writable($diretorioBackup)) {
        sgl_redirect_cfg('backup', 'erro', 'A pasta storage/backups não existe ou não possui permissão de gravação.');
    }

    $sqlTemporario = null;
    $arquivoFinal = null;
    $quantidadeArquivos = 0;
    $detalhes = [];
    $erroBackup = '';

    try {
        if ($tipoBackup === 'banco') {
            $nomeArquivo = rojex_backup_nome_seguro('banco', 'sql');
            $arquivoFinal = $diretorioBackup . DIRECTORY_SEPARATOR . $nomeArquivo;
            $resultadoSql = rojex_backup_banco($conn, $arquivoFinal);

            if (!$resultadoSql['ok']) {
                throw new RuntimeException($resultadoSql['erro'] ?: 'Falha ao gerar backup do banco.');
            }

            $quantidadeArquivos = 1;
            $detalhes = [
                'tabelas'=>$resultadoSql['tabelas'],
                'registros'=>$resultadoSql['registros'],
            ];
        } else {
            if (!class_exists('ZipArchive')) {
                throw new RuntimeException('A extensão ZIP do PHP não está habilitada.');
            }

            $mapaArquivos = rojex_backup_mapear_arquivos();

            if ($tipoBackup === 'completo') {
                $sqlTemporario = $diretorioBackup . DIRECTORY_SEPARATOR . rojex_backup_nome_seguro('banco_temp', 'sql');
                $resultadoSql = rojex_backup_banco($conn, $sqlTemporario);
                if (!$resultadoSql['ok']) {
                    throw new RuntimeException($resultadoSql['erro'] ?: 'Falha ao gerar SQL para o backup completo.');
                }
                $detalhes['tabelas'] = $resultadoSql['tabelas'];
                $detalhes['registros'] = $resultadoSql['registros'];
            }

            $nomeArquivo = rojex_backup_nome_seguro($tipoBackup, 'zip');
            $arquivoFinal = $diretorioBackup . DIRECTORY_SEPARATOR . $nomeArquivo;
            $resultadoZip = rojex_backup_zip_arquivos(
                $arquivoFinal,
                $mapaArquivos['arquivos'],
                $tipoBackup === 'completo' ? $sqlTemporario : null
            );

            if (!$resultadoZip['ok']) {
                throw new RuntimeException($resultadoZip['erro'] ?: 'Falha ao gerar o arquivo ZIP.');
            }

            $quantidadeArquivos = (int)$resultadoZip['quantidade'];
            $detalhes['arquivos_incluidos'] = $quantidadeArquivos;
            $detalhes['fontes'] = array_keys(rojex_backup_fontes_arquivos());
        }

        if ($sqlTemporario && is_file($sqlTemporario)) {
            @unlink($sqlTemporario);
        }

        $validacao = rojex_backup_validar_arquivo($arquivoFinal);
        if (!$validacao['ok']) {
            throw new RuntimeException('O arquivo foi criado, mas não passou na verificação de integridade.');
        }

        $detalhes['hash_sha256'] = $validacao['hash'];
        $detalhes['tamanho_bytes'] = $validacao['tamanho'];

        $backupId = rojex_backup_registrar(
            $conn,
            'manual',
            'concluido',
            $arquivoFinal,
            (int)$validacao['tamanho'],
            (string)$validacao['hash'],
            $tipoBackup,
            $quantidadeArquivos,
            json_encode($detalhes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'integro'
        );

        $_SESSION['rojex_backup_ultimo_resultado'] = [
            'id'=>$backupId,
            'tipo'=>$tipoBackup,
            'arquivo'=>basename($arquivoFinal),
            'tamanho'=>$validacao['tamanho'],
            'hash'=>$validacao['hash'],
            'quantidade'=>$quantidadeArquivos,
        ];

        unset($_SESSION['rojex_backup_preview']);
        sgl_log(
            $conn,
            'Executou backup Enterprise',
            'backups_sistema',
            (string)$backupId,
            "Tipo: {$tipoBackup}; Arquivo: " . basename($arquivoFinal) . '; Integridade: OK'
        );
        sgl_redirect_cfg('backup', 'sucesso', 'Backup criado e verificado com sucesso.');
    } catch (Throwable $e) {
        if ($sqlTemporario && is_file($sqlTemporario)) @unlink($sqlTemporario);
        if ($arquivoFinal && is_file($arquivoFinal) && filesize($arquivoFinal) === 0) @unlink($arquivoFinal);

        rojex_backup_registrar(
            $conn,
            'manual',
            'falhou',
            $arquivoFinal,
            0,
            null,
            $tipoBackup,
            0,
            $e->getMessage(),
            'falhou'
        );

        sgl_log($conn, 'Falha no backup Enterprise', 'backups_sistema', null, $e->getMessage());
        sgl_redirect_cfg('backup', 'erro', 'Não foi possível concluir o backup: ' . $e->getMessage());
    }
}

if ($acao_cfg === 'verificar_backup') {
    $backupId = max(0, (int)($_POST['backup_id'] ?? 0));

    try {
        $stmt = $conn->prepare("SELECT id, arquivo, hash_arquivo FROM backups_sistema WHERE id = ? LIMIT 1");
        $stmt->bind_param('i', $backupId);
        $stmt->execute();
        $backupRegistro = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$backupRegistro || empty($backupRegistro['arquivo'])) {
            sgl_redirect_cfg('backup', 'erro', 'Backup não localizado para verificação.');
        }

        $validacao = rojex_backup_validar_arquivo(
            (string)$backupRegistro['arquivo'],
            $backupRegistro['hash_arquivo'] ?: null
        );
        $statusVerificacao = $validacao['status'];
        $agora = date('Y-m-d H:i:s');

        $stmt = $conn->prepare(
            "UPDATE backups_sistema
                SET verificado_em=?, verificacao_status=?, tamanho_bytes=?, hash_arquivo=?
              WHERE id=?"
        );
        $stmt->bind_param(
            'ssisi',
            $agora,
            $statusVerificacao,
            $validacao['tamanho'],
            $validacao['hash'],
            $backupId
        );
        $stmt->execute();
        $stmt->close();

        sgl_log(
            $conn,
            'Verificou integridade de backup',
            'backups_sistema',
            (string)$backupId,
            'Resultado: ' . $statusVerificacao
        );

        sgl_redirect_cfg(
            'backup',
            $validacao['ok'] ? 'sucesso' : 'erro',
            $validacao['ok']
                ? 'Integridade confirmada: arquivo válido e hash correspondente.'
                : 'Falha de integridade: arquivo ausente ou hash divergente.'
        );
    } catch (Throwable $e) {
        sgl_redirect_cfg('backup', 'erro', 'Não foi possível verificar o backup.');
    }
}

// -----------------------------------------------------------------------------
// Ferramentas de Manutenção Enterprise — Sprint 4.1.3 / Etapa 7
// -----------------------------------------------------------------------------
if ($acao_cfg === 'simular_manutencao') {
    $acoesSelecionadas = $_POST['manutencao_acoes'] ?? [];
    if (!is_array($acoesSelecionadas)) $acoesSelecionadas = [];

    $permitidas = ['temporarios','logs_antigos','analisar_banco','otimizar_banco','permissoes'];
    $acoesSelecionadas = array_values(array_intersect($permitidas, array_map('strval', $acoesSelecionadas)));
    $diasLogs = max(30, min(3650, (int)($_POST['manutencao_dias_logs'] ?? 365)));
    $idadeTemporarios = max(24, min(8760, (int)($_POST['manutencao_idade_temporarios'] ?? 72)));

    if (!$acoesSelecionadas) {
        sgl_redirect_cfg('manutencao', 'erro', 'Selecione ao menos uma rotina para simular.');
    }

    $preview = [
        'acoes' => $acoesSelecionadas,
        'dias_logs' => $diasLogs,
        'idade_temporarios' => $idadeTemporarios,
        'criado_em' => time(),
        'temporarios' => ['quantidade'=>0,'bytes'=>0,'erros'=>[]],
        'logs_antigos' => 0,
        'tabelas' => [],
        'permissoes' => [],
    ];

    if (in_array('temporarios', $acoesSelecionadas, true)) {
        $mapaTemporarios = rojex_manutencao_mapear_temporarios($idadeTemporarios);
        $preview['temporarios'] = [
            'quantidade' => $mapaTemporarios['quantidade'],
            'bytes' => $mapaTemporarios['bytes'],
            'erros' => $mapaTemporarios['erros'],
        ];
    }

    if (in_array('logs_antigos', $acoesSelecionadas, true) && sgl_tabela_existe($conn, 'logs_sistema')) {
        $dataCorte = date('Y-m-d H:i:s', strtotime("-{$diasLogs} days"));
        $stmt = $conn->prepare(
            "SELECT COUNT(*) AS total
               FROM logs_sistema
              WHERE criado_em < ?
                AND COALESCE(escopo, 'LEGADO') <> 'TENANT'"
        );
        $stmt->bind_param('s', $dataCorte);
        $stmt->execute();
        $preview['logs_antigos'] = (int)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
        $stmt->close();
    }

    if (in_array('analisar_banco', $acoesSelecionadas, true) || in_array('otimizar_banco', $acoesSelecionadas, true)) {
        $preview['tabelas'] = rojex_manutencao_tabelas($conn);
    }

    if (in_array('permissoes', $acoesSelecionadas, true)) {
        $raizProjeto = realpath(__DIR__ . '/..') ?: (__DIR__ . '/..');
        foreach ([
            'assets/img' => $raizProjeto . '/assets/img',
            'uploads' => $raizProjeto . '/uploads',
            'storage' => $raizProjeto . '/storage',
            'config' => $raizProjeto . '/config',
        ] as $rotulo => $caminhoPermissao) {
            $preview['permissoes'][$rotulo] = [
                'existe' => is_dir($caminhoPermissao),
                'gravavel' => is_dir($caminhoPermissao) ? is_writable($caminhoPermissao) : false,
            ];
        }
    }

    $preview['hash'] = hash_hmac(
        'sha256',
        json_encode([
            $preview['acoes'],
            $preview['dias_logs'],
            $preview['idade_temporarios'],
            $preview['criado_em'],
        ]),
        (string)($_SESSION['csrf_token'] ?? session_id())
    );

    $_SESSION['rojex_manutencao_preview'] = $preview;
    rojex_registrar_manutencao(
        $conn,
        'simulacao',
        'dry_run',
        'concluida',
        'Simulação de manutenção concluída sem alterar dados.',
        $preview
    );
    sgl_log($conn, 'Simulou manutenção Enterprise', 'manutencoes_sistema', null, 'Ações: ' . implode(', ', $acoesSelecionadas));
    sgl_redirect_cfg('manutencao', 'sucesso', 'Simulação concluída. Revise a prévia antes de executar.');
}

if ($acao_cfg === 'executar_manutencao') {
    $preview = $_SESSION['rojex_manutencao_preview'] ?? null;
    $hashInformado = (string)($_POST['manutencao_hash'] ?? '');
    $confirmacao = strtoupper(trim((string)($_POST['confirmacao_manutencao'] ?? '')));

    if (!is_array($preview) || empty($preview['hash']) || !hash_equals((string)$preview['hash'], $hashInformado)) {
        sgl_redirect_cfg('manutencao', 'erro', 'A simulação expirou ou não corresponde à execução. Faça uma nova simulação.');
    }
    if ((time() - (int)($preview['criado_em'] ?? 0)) > 1800) {
        unset($_SESSION['rojex_manutencao_preview']);
        sgl_redirect_cfg('manutencao', 'erro', 'A simulação expirou após 30 minutos. Faça uma nova simulação.');
    }
    if ($confirmacao !== 'MANUTENCAO') {
        sgl_redirect_cfg('manutencao', 'erro', 'Confirmação inválida. Digite MANUTENCAO.');
    }

    $resultado = [
        'acoes' => $preview['acoes'],
        'temporarios_excluidos' => 0,
        'temporarios_bytes' => 0,
        'logs_excluidos' => 0,
        'tabelas_analisadas' => 0,
        'tabelas_otimizadas' => 0,
        'falhas' => [],
    ];

    if (in_array('temporarios', $preview['acoes'], true)) {
        $mapaTemporarios = rojex_manutencao_mapear_temporarios((int)$preview['idade_temporarios']);
        foreach ($mapaTemporarios['arquivos'] as $arquivoTemporario) {
            $tamanho = is_file($arquivoTemporario) ? max(0, (int)@filesize($arquivoTemporario)) : 0;
            if (is_file($arquivoTemporario) && @unlink($arquivoTemporario)) {
                $resultado['temporarios_excluidos']++;
                $resultado['temporarios_bytes'] += $tamanho;
            } else {
                $resultado['falhas'][] = 'Não foi possível remover: ' . basename($arquivoTemporario);
            }
        }
    }

    if (in_array('logs_antigos', $preview['acoes'], true) && sgl_tabela_existe($conn, 'logs_sistema')) {
        $diasLogs = max(30, min(3650, (int)$preview['dias_logs']));
        $dataCorte = date('Y-m-d H:i:s', strtotime("-{$diasLogs} days"));

        // Logs TENANT somente podem ser removidos pelo fluxo auditado da Sprint
        // 4.6.5, após ZIP, verificação, download e confirmação ARQUIVAR.
        // A manutenção comum atua apenas em eventos LEGADO/PLATAFORMA.
        $sqlLimpezaLogs = "DELETE FROM logs_sistema
                            WHERE criado_em < ?
                              AND COALESCE(escopo, 'LEGADO') <> 'TENANT'
                              AND id NOT IN (
                                  SELECT id FROM (
                                      SELECT id FROM logs_sistema ORDER BY id DESC LIMIT 1000
                                  ) AS logs_preservados
                              )";
        $stmt = $conn->prepare($sqlLimpezaLogs);
        $stmt->bind_param('s', $dataCorte);
        $stmt->execute();
        $resultado['logs_excluidos'] = max(0, $stmt->affected_rows);
        $stmt->close();
    }

    $tabelas = rojex_manutencao_tabelas($conn);
    if (in_array('analisar_banco', $preview['acoes'], true)) {
        foreach ($tabelas as $tabelaInfo) {
            $nomeTabela = $tabelaInfo['nome'];
            try {
                if ($conn->query("ANALYZE TABLE `$nomeTabela`")) {
                    $resultado['tabelas_analisadas']++;
                }
            } catch (Throwable $e) {
                $resultado['falhas'][] = "ANALYZE {$nomeTabela}: " . $e->getMessage();
            }
        }
    }

    if (in_array('otimizar_banco', $preview['acoes'], true)) {
        foreach ($tabelas as $tabelaInfo) {
            $nomeTabela = $tabelaInfo['nome'];
            try {
                if ($conn->query("OPTIMIZE TABLE `$nomeTabela`")) {
                    $resultado['tabelas_otimizadas']++;
                }
            } catch (Throwable $e) {
                $resultado['falhas'][] = "OPTIMIZE {$nomeTabela}: " . $e->getMessage();
            }
        }
    }

    $statusManutencao = $resultado['falhas'] ? 'concluida_com_avisos' : 'concluida';
    $resumoManutencao =
        "{$resultado['temporarios_excluidos']} temporário(s), " .
        "{$resultado['logs_excluidos']} log(s), " .
        "{$resultado['tabelas_analisadas']} tabela(s) analisada(s), " .
        "{$resultado['tabelas_otimizadas']} tabela(s) otimizada(s).";

    rojex_registrar_manutencao(
        $conn,
        'manutencao_controlada',
        'execucao',
        $statusManutencao,
        $resumoManutencao,
        $resultado
    );
    sgl_log($conn, 'Executou manutenção Enterprise', 'manutencoes_sistema', null, $resumoManutencao);

    $_SESSION['rojex_manutencao_ultimo_resultado'] = $resultado;
    unset($_SESSION['rojex_manutencao_preview']);

    sgl_redirect_cfg(
        'manutencao',
        $resultado['falhas'] ? 'aviso' : 'sucesso',
        $resultado['falhas']
            ? 'Manutenção concluída com avisos. Consulte o resultado detalhado.'
            : 'Manutenção concluída com sucesso.'
    );
}

// -----------------------------------------------------------------------------
// Painel de Planos SaaS — Sprint 4.5 / Etapa 3.2
// -----------------------------------------------------------------------------
if ($acao_cfg === 'salvar_plano_saas') {
    $planoId = max(0, (int)($_POST['plano_saas_id'] ?? 0));
    $codigoPlano = strtolower(preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($_POST['codigo_plano_saas'] ?? '')));
    $nomePlano = sgl_limpar_texto((string)($_POST['nome_plano_saas'] ?? ''), 100);
    $descricaoPlano = sgl_limpar_texto((string)($_POST['descricao_plano_saas'] ?? ''), 3000);
    $valorMensalPlano = round(max(0, (float)str_replace(',', '.', (string)($_POST['valor_mensal_plano_saas'] ?? '0'))), 2);
    $valorAnualPlano = round(max(0, (float)str_replace(',', '.', (string)($_POST['valor_anual_plano_saas'] ?? '0'))), 2);
    $trialPadraoPlano = (int)($_POST['trial_padrao_plano_saas'] ?? 15);
    $trialMinimoPlano = (int)($_POST['trial_minimo_plano_saas'] ?? 7);
    $trialMaximoPlano = (int)($_POST['trial_maximo_plano_saas'] ?? 30);
    $limiteUsuariosPlano = max(1, min(100000, (int)($_POST['limite_usuarios_plano_saas'] ?? 5)));
    $armazenamentoPlano = max(1, min(1000000, (int)($_POST['armazenamento_plano_saas'] ?? 10)));
    $suporteInclusoPlano = !empty($_POST['suporte_incluso_plano_saas']) ? 1 : 0;
    $nivelSuportePlano = (string)($_POST['nivel_suporte_plano_saas'] ?? 'padrao');
    $ordemPlano = max(0, min(100000, (int)($_POST['ordem_plano_saas'] ?? 0)));
    $destaquePlano = !empty($_POST['destaque_plano_saas']) ? 1 : 0;
    $ativoPlano = !empty($_POST['ativo_plano_saas']) ? 1 : 0;
    $motivoPrecoPlano = sgl_limpar_texto((string)($_POST['motivo_preco_plano_saas'] ?? ''), 255);

    if ($codigoPlano === '' || strlen($codigoPlano) < 2 || $nomePlano === '') {
        sgl_redirect_cfg('planos', 'erro', 'Informe um código interno válido e o nome do plano.');
    }
    if ($trialMinimoPlano < 7 || $trialMaximoPlano > 30 || $trialMinimoPlano > $trialMaximoPlano) {
        sgl_redirect_cfg('planos', 'erro', 'O período de Trial deve permanecer entre 7 e 30 dias.');
    }
    if ($trialPadraoPlano < $trialMinimoPlano || $trialPadraoPlano > $trialMaximoPlano) {
        sgl_redirect_cfg('planos', 'erro', 'O Trial padrão deve estar entre o mínimo e o máximo definidos.');
    }
    if (!in_array($nivelSuportePlano, ['padrao','prioritario','premium'], true)) {
        $nivelSuportePlano = 'padrao';
    }

    $descontoAnualPlano = 0.00;
    if ($valorMensalPlano > 0) {
        $totalDozeMeses = $valorMensalPlano * 12;
        $descontoAnualPlano = max(0, min(100, round((1 - ($valorAnualPlano / $totalDozeMeses)) * 100, 2)));
    }

    try {
        $stmtDup = $conn->prepare("SELECT id FROM planos_saas WHERE codigo = ? AND id <> ? LIMIT 1");
        $stmtDup->bind_param('si', $codigoPlano, $planoId);
        $stmtDup->execute();
        $duplicadoPlano = $stmtDup->get_result()->fetch_assoc();
        $stmtDup->close();
        if ($duplicadoPlano) {
            sgl_redirect_cfg('planos', 'erro', 'Já existe um plano com este código interno.');
        }

        $conn->begin_transaction();
        $precoAnteriorPlano = null;
        if ($planoId > 0) {
            $stmtAnterior = $conn->prepare("SELECT valor_mensal, valor_anual FROM planos_saas WHERE id = ? LIMIT 1 FOR UPDATE");
            $stmtAnterior->bind_param('i', $planoId);
            $stmtAnterior->execute();
            $precoAnteriorPlano = $stmtAnterior->get_result()->fetch_assoc();
            $stmtAnterior->close();
            if (!$precoAnteriorPlano) {
                throw new RuntimeException('Plano não encontrado.');
            }
        }

        if ($destaquePlano === 1) {
            $stmtDestaque = $conn->prepare("UPDATE planos_saas SET destaque = 0 WHERE id <> ?");
            $stmtDestaque->bind_param('i', $planoId);
            $stmtDestaque->execute();
            $stmtDestaque->close();
        }

        if ($planoId > 0) {
            $stmt = $conn->prepare(
                "UPDATE planos_saas SET codigo=?, nome=?, descricao=?, valor_mensal=?, valor_anual=?,
                    desconto_anual_percentual=?, trial_dias_padrao=?, trial_dias_minimo=?, trial_dias_maximo=?,
                    limite_usuarios_padrao=?, limite_armazenamento_gb_padrao=?, suporte_incluso=?, nivel_suporte=?,
                    ordem_exibicao=?, destaque=?, ativo=? WHERE id=?"
            );
            $stmt->bind_param(
                'sssdddiiiiiisiiii',
                $codigoPlano, $nomePlano, $descricaoPlano, $valorMensalPlano, $valorAnualPlano,
                $descontoAnualPlano, $trialPadraoPlano, $trialMinimoPlano, $trialMaximoPlano,
                $limiteUsuariosPlano, $armazenamentoPlano, $suporteInclusoPlano, $nivelSuportePlano,
                $ordemPlano, $destaquePlano, $ativoPlano, $planoId
            );
            $stmt->execute();
            $stmt->close();
            $registroPlano = $planoId;
            $acaoPlanoLog = 'Atualizou plano SaaS';
            $mensagemPlano = 'Plano atualizado com sucesso.';
        } else {
            $stmt = $conn->prepare(
                "INSERT INTO planos_saas
                    (codigo,nome,descricao,valor_mensal,valor_anual,desconto_anual_percentual,
                     trial_dias_padrao,trial_dias_minimo,trial_dias_maximo,limite_usuarios_padrao,
                     limite_armazenamento_gb_padrao,suporte_incluso,nivel_suporte,ordem_exibicao,destaque,ativo)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
            );
            $stmt->bind_param(
                'sssdddiiiiiisiii',
                $codigoPlano, $nomePlano, $descricaoPlano, $valorMensalPlano, $valorAnualPlano,
                $descontoAnualPlano, $trialPadraoPlano, $trialMinimoPlano, $trialMaximoPlano,
                $limiteUsuariosPlano, $armazenamentoPlano, $suporteInclusoPlano, $nivelSuportePlano,
                $ordemPlano, $destaquePlano, $ativoPlano
            );
            $stmt->execute();
            $registroPlano = (int)$stmt->insert_id;
            $stmt->close();
            $acaoPlanoLog = 'Criou plano SaaS';
            $mensagemPlano = 'Plano cadastrado com sucesso.';
        }

        $precoMudou = $precoAnteriorPlano === null
            || abs((float)$precoAnteriorPlano['valor_mensal'] - $valorMensalPlano) > 0.001
            || abs((float)$precoAnteriorPlano['valor_anual'] - $valorAnualPlano) > 0.001;
        if ($precoMudou && sgl_tabela_existe($conn, 'planos_precos_historico')) {
            $valorMensalAnterior = $precoAnteriorPlano !== null ? (float)$precoAnteriorPlano['valor_mensal'] : null;
            $valorAnualAnterior = $precoAnteriorPlano !== null ? (float)$precoAnteriorPlano['valor_anual'] : null;
            $alteradoPor = $usuarioSessaoId > 0 ? $usuarioSessaoId : null;
            $alteradoPorNome = (string)($_SESSION['nome'] ?? $_SESSION['username'] ?? 'Sistema');
            $motivoHistorico = $motivoPrecoPlano !== '' ? $motivoPrecoPlano : ($precoAnteriorPlano === null ? 'Preço inicial do plano.' : 'Alteração realizada pelo Painel de Planos SaaS.');
            $stmtHistorico = $conn->prepare(
                "INSERT INTO planos_precos_historico
                    (plano_id,valor_mensal_anterior,valor_mensal_novo,valor_anual_anterior,valor_anual_novo,motivo,alterado_por,alterado_por_nome)
                 VALUES (?,?,?,?,?,?,?,?)"
            );
            $stmtHistorico->bind_param(
                'iddddsis',
                $registroPlano, $valorMensalAnterior, $valorMensalPlano, $valorAnualAnterior,
                $valorAnualPlano, $motivoHistorico, $alteradoPor, $alteradoPorNome
            );
            $stmtHistorico->execute();
            $stmtHistorico->close();
        }

        $conn->commit();
        sgl_log($conn, $acaoPlanoLog, 'planos_saas', (string)$registroPlano, "Plano: {$nomePlano}; Mensal: {$valorMensalPlano}; Anual: {$valorAnualPlano}; Trial: {$trialPadraoPlano} dias; Ativo: {$ativoPlano}");
        sgl_redirect_cfg('planos', 'sucesso', $mensagemPlano);
    } catch (Throwable $e) {
        try { $conn->rollback(); } catch (Throwable $ignorado) {}
        sgl_redirect_cfg('planos', 'erro', 'Não foi possível salvar o plano. Verifique os dados informados.');
    }
}

if ($acao_cfg === 'alterar_status_plano_saas') {
    $planoId = max(0, (int)($_POST['plano_saas_id'] ?? 0));
    $novoStatusPlano = (int)($_POST['novo_status_plano_saas'] ?? -1);
    if ($planoId <= 0 || !in_array($novoStatusPlano, [0,1], true)) {
        sgl_redirect_cfg('planos', 'erro', 'Plano ou status inválido.');
    }
    try {
        $stmt = $conn->prepare("UPDATE planos_saas SET ativo = ? WHERE id = ?");
        $stmt->bind_param('ii', $novoStatusPlano, $planoId);
        $stmt->execute();
        $stmt->close();
        sgl_log($conn, $novoStatusPlano ? 'Ativou plano SaaS' : 'Inativou plano SaaS', 'planos_saas', (string)$planoId, 'Novo status: ' . ($novoStatusPlano ? 'ativo' : 'inativo'));
        sgl_redirect_cfg('planos', 'sucesso', $novoStatusPlano ? 'Plano ativado com sucesso.' : 'Plano inativado com sucesso.');
    } catch (Throwable $e) {
        sgl_redirect_cfg('planos', 'erro', 'Não foi possível alterar o status do plano.');
    }
}


if ($acao_cfg === 'excluir_plano_saas') {
    $planoId = max(0, (int)($_POST['plano_saas_id'] ?? 0));
    if ($planoId <= 0) {
        sgl_redirect_cfg('planos', 'erro', 'Plano inválido para exclusão.');
    }

    try {
        $conn->begin_transaction();

        $stmtPlano = $conn->prepare(
            "SELECT id, codigo, nome, ativo, valor_mensal, valor_anual
               FROM planos_saas
              WHERE id = ?
              LIMIT 1
              FOR UPDATE"
        );
        $stmtPlano->bind_param('i', $planoId);
        $stmtPlano->execute();
        $planoExcluir = $stmtPlano->get_result()->fetch_assoc();
        $stmtPlano->close();

        if (!$planoExcluir) {
            throw new RuntimeException('Plano não encontrado.');
        }

        $codigoPlanoExcluir = (string)$planoExcluir['codigo'];
        $vinculosEscritorios = 0;
        $vinculosLicencas = 0;

        if (sgl_tabela_existe($conn, 'escritorios_saas') && sgl_coluna_existe($conn, 'escritorios_saas', 'plano')) {
            $stmtVinculo = $conn->prepare("SELECT COUNT(*) AS total FROM escritorios_saas WHERE plano = ?");
            $stmtVinculo->bind_param('s', $codigoPlanoExcluir);
            $stmtVinculo->execute();
            $vinculosEscritorios = (int)($stmtVinculo->get_result()->fetch_assoc()['total'] ?? 0);
            $stmtVinculo->close();
        }

        if (sgl_tabela_existe($conn, 'licencas_saas') && sgl_coluna_existe($conn, 'licencas_saas', 'plano')) {
            $stmtVinculo = $conn->prepare("SELECT COUNT(*) AS total FROM licencas_saas WHERE plano = ?");
            $stmtVinculo->bind_param('s', $codigoPlanoExcluir);
            $stmtVinculo->execute();
            $vinculosLicencas = (int)($stmtVinculo->get_result()->fetch_assoc()['total'] ?? 0);
            $stmtVinculo->close();
        }

        if ($vinculosEscritorios > 0 || $vinculosLicencas > 0) {
            $conn->rollback();
            $detalhesVinculo = [];
            if ($vinculosEscritorios > 0) $detalhesVinculo[] = $vinculosEscritorios . ' escritório(s)';
            if ($vinculosLicencas > 0) $detalhesVinculo[] = $vinculosLicencas . ' licença(s)';
            sgl_redirect_cfg(
                'planos',
                'aviso',
                'Não é possível excluir este plano porque existem ' . implode(' e ', $detalhesVinculo) . ' vinculados. Utilize Inativar.'
            );
        }

        // planos_modulos_saas e planos_precos_historico possuem FK ON DELETE CASCADE.
        // Assim, somente os vínculos internos do plano são removidos junto com ele.
        $stmtExcluir = $conn->prepare("DELETE FROM planos_saas WHERE id = ? LIMIT 1");
        $stmtExcluir->bind_param('i', $planoId);
        $stmtExcluir->execute();
        $afetadasPlano = $stmtExcluir->affected_rows;
        $stmtExcluir->close();

        if ($afetadasPlano !== 1) {
            throw new RuntimeException('O plano não foi excluído.');
        }

        $conn->commit();

        sgl_log(
            $conn,
            'Excluiu plano SaaS',
            'planos_saas',
            (string)$planoId,
            'Plano excluído definitivamente: ' . (string)$planoExcluir['nome'] .
            ' | Código: ' . $codigoPlanoExcluir .
            ' | Mensal: ' . (string)$planoExcluir['valor_mensal'] .
            ' | Anual: ' . (string)$planoExcluir['valor_anual']
        );
        sgl_redirect_cfg('planos', 'sucesso', 'Plano excluído definitivamente com sucesso.');
    } catch (Throwable $e) {
        try { $conn->rollback(); } catch (Throwable $ignorado) {}
        sgl_redirect_cfg('planos', 'erro', 'Não foi possível excluir o plano. Verifique se existem vínculos e tente novamente.');
    }
}


// -----------------------------------------------------------------------------
// Portal do Cliente — Sprint 4.7.4
// Ativação por escritório, conta isolada e convite de uso único.
// -----------------------------------------------------------------------------
if ($acao_cfg === 'ativar_portal_escritorio') {
    $escritorioPortalId = max(0, (int)($_POST['portal_escritorio_id'] ?? 0));
    if ($escritorioPortalId <= 0) sgl_redirect_cfg('portal', 'erro', 'Selecione um escritório válido.');

    try {
        $conn->begin_transaction();
        $stmt = $conn->prepare("SELECT id,tenant_id,nome,status FROM escritorios_saas WHERE id=? LIMIT 1 FOR UPDATE");
        $stmt->bind_param('i', $escritorioPortalId); $stmt->execute();
        $escritorioPortal = $stmt->get_result()->fetch_assoc(); $stmt->close();
        if (!$escritorioPortal || trim((string)$escritorioPortal['tenant_id']) === '') throw new RuntimeException('Escritório ou tenant inválido.');
        if ((string)$escritorioPortal['status'] !== 'ativo') throw new RuntimeException('Ative o escritório antes de liberar o Portal do Cliente.');

        $stmt = $conn->prepare("SELECT id FROM modulos_saas WHERE codigo='portal_cliente' AND ativo=1 AND status_lancamento='producao' LIMIT 1");
        $stmt->execute(); $moduloPortalId = (int)($stmt->get_result()->fetch_assoc()['id'] ?? 0); $stmt->close();
        if ($moduloPortalId <= 0) throw new RuntimeException('O módulo portal_cliente não está disponível em produção.');

        $origemPortal = 'administrativo';
        $stmt = $conn->prepare("INSERT INTO escritorios_modulos_saas (escritorio_id,modulo_id,origem,ativo,valor_ajuste) VALUES (?,?,?,1,0) ON DUPLICATE KEY UPDATE origem=VALUES(origem),ativo=1,atualizado_em=CURRENT_TIMESTAMP");
        $stmt->bind_param('iis', $escritorioPortalId, $moduloPortalId, $origemPortal); $stmt->execute(); $stmt->close();
        $conn->commit();
        sgl_log($conn, 'Ativou Portal do Cliente para escritório', 'escritorios_modulos_saas', (string)$escritorioPortalId, 'Tenant: ' . $escritorioPortal['tenant_id']);
        sgl_redirect_cfg('portal', 'sucesso', 'Portal ativado para o escritório. Nenhum conteúdo foi publicado.');
    } catch (Throwable $e) {
        try { $conn->rollback(); } catch (Throwable $ignorado) {}
        sgl_redirect_cfg('portal', 'erro', $e->getMessage());
    }
}

if ($acao_cfg === 'criar_conta_portal' || $acao_cfg === 'reemitir_convite_portal') {
    $contaPortalId = max(0, (int)($_POST['portal_conta_id'] ?? 0));
    $escritorioPortalId = max(0, (int)($_POST['portal_escritorio_id'] ?? 0));
    $clientePortalId = trim((string)($_POST['portal_cliente_id'] ?? ''));
    $emailPortal = mb_strtolower(trim((string)($_POST['portal_email'] ?? '')), 'UTF-8');
    $reemitirConvite = $acao_cfg === 'reemitir_convite_portal';
    if (!$reemitirConvite && ($escritorioPortalId <= 0 || $clientePortalId === '' || !filter_var($emailPortal, FILTER_VALIDATE_EMAIL))) {
        sgl_redirect_cfg('portal', 'erro', 'Informe escritório, cliente e e-mail válidos.');
    }

    try {
        $conn->begin_transaction();
        if ($reemitirConvite) {
            $stmt = $conn->prepare("SELECT pc.id,pc.tenant_id,pc.escritorio_id,pc.cliente_id,pc.email,e.subdominio FROM portal_clientes_contas pc INNER JOIN escritorios_saas e ON e.id=pc.escritorio_id AND e.tenant_id=pc.tenant_id WHERE pc.id=? LIMIT 1 FOR UPDATE");
            $stmt->bind_param('i', $contaPortalId); $stmt->execute(); $contaPortal = $stmt->get_result()->fetch_assoc(); $stmt->close();
            if (!$contaPortal) throw new RuntimeException('Conta do Portal não encontrada.');
            $escritorioPortalId = (int)$contaPortal['escritorio_id']; $clientePortalId = (string)$contaPortal['cliente_id']; $emailPortal = (string)$contaPortal['email']; $tenantPortal = (string)$contaPortal['tenant_id'];
        } else {
            $stmt = $conn->prepare("SELECT e.tenant_id,e.subdominio,e.status,c.id,c.nome,c.email FROM escritorios_saas e INNER JOIN clientes c ON c.escritorio_id=e.id AND c.tenant_id=e.tenant_id AND c.id=? AND c.deletado=0 AND c.status='Ativo' INNER JOIN escritorios_modulos_saas em ON em.escritorio_id=e.id AND em.ativo=1 INNER JOIN modulos_saas m ON m.id=em.modulo_id AND m.codigo='portal_cliente' AND m.ativo=1 AND m.status_lancamento='producao' WHERE e.id=? AND e.status='ativo' LIMIT 1 FOR UPDATE");
            $stmt->bind_param('si', $clientePortalId, $escritorioPortalId); $stmt->execute(); $clientePortal = $stmt->get_result()->fetch_assoc(); $stmt->close();
            if (!$clientePortal) throw new RuntimeException('Cliente não pertence ao escritório selecionado ou o Portal ainda não está ativo.');
            $tenantPortal = trim((string)$clientePortal['tenant_id']);
            if ($tenantPortal === '') throw new RuntimeException('Contexto Multi-Tenant inválido.');

            $statusContaPortal = 'CONVITE_PENDENTE';
            $usuarioAtivadorPortal = (int)($_SESSION['user_id'] ?? 0);
            $stmt = $conn->prepare("INSERT INTO portal_clientes_contas (tenant_id,escritorio_id,cliente_id,email,email_normalizado,senha_hash,status,primeiro_acesso_pendente,ativado_por_usuario_id,ativado_em) VALUES (?,?,?,?,?,NULL,?,1,?,NOW())");
            $stmt->bind_param('sissssi', $tenantPortal, $escritorioPortalId, $clientePortalId, $emailPortal, $emailPortal, $statusContaPortal, $usuarioAtivadorPortal);
            $stmt->execute(); $contaPortalId = (int)$conn->insert_id; $stmt->close();

            $zeroPortal = 0;
            $stmt = $conn->prepare("INSERT INTO portal_clientes_permissoes (conta_id,tenant_id,escritorio_id,cliente_id,ver_processos,ver_documentos,enviar_documentos,ver_honorarios,ver_recibos,ver_agenda,receber_notificacoes,atualizado_por_usuario_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
            $stmt->bind_param('isisiiiiiiii', $contaPortalId, $tenantPortal, $escritorioPortalId, $clientePortalId, $zeroPortal, $zeroPortal, $zeroPortal, $zeroPortal, $zeroPortal, $zeroPortal, $zeroPortal, $usuarioAtivadorPortal);
            $stmt->execute(); $stmt->close();
        }

        $stmt = $conn->prepare("UPDATE portal_clientes_tokens SET revogado_em=NOW() WHERE conta_id=? AND tenant_id=? AND escritorio_id=? AND cliente_id=? AND tipo='CONVITE' AND utilizado_em IS NULL AND revogado_em IS NULL");
        $stmt->bind_param('isis', $contaPortalId, $tenantPortal, $escritorioPortalId, $clientePortalId); $stmt->execute(); $stmt->close();

        $tokenPortal = bin2hex(random_bytes(32));
        $tokenHashPortal = hash('sha256', $tokenPortal);
        $expiraPortal = date('Y-m-d H:i:s', time() + 172800);
        $tipoTokenPortal = 'CONVITE';
        $ipPortal = filter_var((string)($_SERVER['REMOTE_ADDR'] ?? ''), FILTER_VALIDATE_IP) ? (string)$_SERVER['REMOTE_ADDR'] : '';
        $uaPortal = mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255, 'UTF-8');
        $stmt = $conn->prepare("INSERT INTO portal_clientes_tokens (conta_id,tenant_id,escritorio_id,cliente_id,tipo,token_hash,expira_em,solicitado_ip,user_agent) VALUES (?,?,?,?,?,?,?,?,?)");
        $stmt->bind_param('isissssss', $contaPortalId, $tenantPortal, $escritorioPortalId, $clientePortalId, $tipoTokenPortal, $tokenHashPortal, $expiraPortal, $ipPortal, $uaPortal);
        $stmt->execute(); $stmt->close();
        $conn->commit();

        $scriptPortal = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? '/index.php'));
        $basePortal = rtrim(str_replace('\\', '/', dirname($scriptPortal)), '/.');
        $hostPortal = trim((string)($_SERVER['HTTP_HOST'] ?? ''));
        if (!preg_match('/^(?:localhost|127\.0\.0\.1|\[::1\]|[a-z0-9.-]+)(?::\d{1,5})?$/i', $hostPortal)) {
            $hostPortal = '';
        }
        $httpsPortal = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
            || (string)($_SERVER['SERVER_PORT'] ?? '') === '443';
        $origemPortal = $hostPortal !== '' ? (($httpsPortal ? 'https' : 'http') . '://' . $hostPortal) : '';
        $baseConvitePortal = $origemPortal . $basePortal;
        if (defined('ROJEX_APP_URL') && trim((string)ROJEX_APP_URL) !== '') {
            $baseConvitePortal = rtrim((string)ROJEX_APP_URL, '/');
        }
        $_SESSION['rojex_portal_convite'] = [
            'email' => $emailPortal,
            'expira_em' => $expiraPortal,
            'url' => $baseConvitePortal . '/portal/primeiro_acesso.php?token=' . rawurlencode($tokenPortal) . '&tenant=' . rawurlencode($tenantPortal),
        ];
        sgl_log($conn, $reemitirConvite ? 'Reemitiu convite do Portal' : 'Criou conta do Portal', 'portal_clientes_contas', (string)$contaPortalId, 'Tenant: ' . $tenantPortal . '; Cliente: ' . $clientePortalId . '; permissões iniciais desativadas.');
        sgl_redirect_cfg('portal', 'sucesso', $reemitirConvite ? 'Novo convite gerado com validade de 48 horas.' : 'Conta criada e convite seguro gerado. Nenhum conteúdo foi publicado.');
    } catch (Throwable $e) {
        try { $conn->rollback(); } catch (Throwable $ignorado) {}
        sgl_redirect_cfg('portal', 'erro', $e->getCode() === 1062 ? 'Já existe uma conta para este cliente ou e-mail neste escritório.' : $e->getMessage());
    }
}

if ($acao_cfg === 'alterar_status_conta_portal') {
    $contaPortalId = max(0, (int)($_POST['portal_conta_id'] ?? 0));
    $novoStatusPortal = (string)($_POST['portal_novo_status'] ?? '');
    if ($contaPortalId <= 0 || !in_array($novoStatusPortal, ['ATIVA','DESATIVADA'], true)) sgl_redirect_cfg('portal', 'erro', 'Conta ou status inválido.');
    try {
        $usuarioPortal = (int)($_SESSION['user_id'] ?? 0);
        if ($novoStatusPortal === 'DESATIVADA') {
            $stmt = $conn->prepare("UPDATE portal_clientes_contas SET status='DESATIVADA',desativado_por_usuario_id=?,desativado_em=NOW() WHERE id=?");
            $stmt->bind_param('ii', $usuarioPortal, $contaPortalId);
        } else {
            $stmt = $conn->prepare("UPDATE portal_clientes_contas SET status='ATIVA',desativado_por_usuario_id=NULL,desativado_em=NULL,motivo_desativacao=NULL WHERE id=?");
            $stmt->bind_param('i', $contaPortalId);
        }
        $stmt->execute(); $stmt->close();
        if ($novoStatusPortal === 'DESATIVADA') {
            $stmt = $conn->prepare("UPDATE portal_clientes_sessoes SET encerrada_em=NOW(),motivo_encerramento='CONTA_DESATIVADA' WHERE conta_id=? AND encerrada_em IS NULL");
            $stmt->bind_param('i', $contaPortalId); $stmt->execute(); $stmt->close();
        }
        sgl_log($conn, 'Alterou status de conta do Portal', 'portal_clientes_contas', (string)$contaPortalId, 'Novo status: ' . $novoStatusPortal);
        sgl_redirect_cfg('portal', 'sucesso', 'Status da conta atualizado.');
    } catch (Throwable $e) { sgl_redirect_cfg('portal', 'erro', 'Não foi possível alterar o status da conta.'); }
}

// -----------------------------------------------------------------------------
// Cadastro e Configurador de Módulos SaaS — Sprint 4.5 / Etapa 3.3
// -----------------------------------------------------------------------------
if ($acao_cfg === 'salvar_modulo_saas') {
    $moduloId = max(0, (int)($_POST['modulo_saas_id'] ?? 0));
    $codigoModulo = strtolower(preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($_POST['codigo_modulo_saas'] ?? '')));
    $nomeModulo = sgl_limpar_texto((string)($_POST['nome_modulo_saas'] ?? ''), 120);
    $descricaoModulo = sgl_limpar_texto((string)($_POST['descricao_modulo_saas'] ?? ''), 3000);
    $categoriaModulo = strtolower(preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($_POST['categoria_modulo_saas'] ?? 'operacional')));
    $iconeModulo = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($_POST['icone_modulo_saas'] ?? 'bi-box'));
    $ordemModulo = max(0, min(100000, (int)($_POST['ordem_modulo_saas'] ?? 0)));
    $essencialModulo = !empty($_POST['essencial_modulo_saas']) ? 1 : 0;
    $permiteDesativacaoModulo = !empty($_POST['permite_desativacao_modulo_saas']) ? 1 : 0;
    $exigeIaModulo = !empty($_POST['exige_ia_modulo_saas']) ? 1 : 0;
    $requerApiModulo = !empty($_POST['requer_api_modulo_saas']) ? 1 : 0;
    $exibirPortalModulo = !empty($_POST['exibir_portal_modulo_saas']) ? 1 : 0;
    $exibirMenuModulo = !empty($_POST['exibir_menu_modulo_saas']) ? 1 : 0;
    $exibirVendaModulo = !empty($_POST['exibir_venda_modulo_saas']) ? 1 : 0;
    $ativoModulo = !empty($_POST['ativo_modulo_saas']) ? 1 : 0;
    $statusLancamentoModulo = (string)($_POST['status_lancamento_modulo_saas'] ?? 'producao');
    if (!in_array($statusLancamentoModulo, ['producao','beta','desenvolvimento','descontinuado'], true)) $statusLancamentoModulo = 'producao';

    if ($codigoModulo === '' || strlen($codigoModulo) < 2 || $nomeModulo === '') {
        sgl_redirect_cfg('modulos', 'erro', 'Informe o código interno e o nome do módulo.');
    }
    if ($essencialModulo) $permiteDesativacaoModulo = 0;

    try {
        $stmtDup = $conn->prepare("SELECT id FROM modulos_saas WHERE codigo=? AND id<>? LIMIT 1");
        $stmtDup->bind_param('si', $codigoModulo, $moduloId);
        $stmtDup->execute();
        $duplicado = $stmtDup->get_result()->fetch_assoc();
        $stmtDup->close();
        if ($duplicado) sgl_redirect_cfg('modulos', 'erro', 'Já existe um módulo com este código interno.');

        $conn->begin_transaction();
        $camposExtras = [];
        $valoresExtras = [];
        $tiposExtras = '';
        foreach ([
            'status_lancamento'=>[$statusLancamentoModulo,'s'],
            'requer_api'=>[$requerApiModulo,'i'],
            'exibir_portal'=>[$exibirPortalModulo,'i'],
            'exibir_menu'=>[$exibirMenuModulo,'i'],
            'exibir_venda'=>[$exibirVendaModulo,'i'],
        ] as $campoExtra=>$dadosExtra) {
            if (sgl_coluna_existe($conn, 'modulos_saas', $campoExtra)) {
                $camposExtras[$campoExtra] = $dadosExtra[0];
                $valoresExtras[] = $dadosExtra[0];
                $tiposExtras .= $dadosExtra[1];
            }
        }

        if ($moduloId > 0) {
            $sets = ['codigo=?','nome=?','descricao=?','categoria=?','icone=?','modulo_essencial=?','permite_desativacao=?','exige_ia_externa=?','ordem_exibicao=?','ativo=?'];
            $tipos = 'sssssiiiii';
            $valores = [$codigoModulo,$nomeModulo,$descricaoModulo,$categoriaModulo,$iconeModulo,$essencialModulo,$permiteDesativacaoModulo,$exigeIaModulo,$ordemModulo,$ativoModulo];
            foreach ($camposExtras as $campo=>$valor) { $sets[] = "`$campo`=?"; }
            $tipos .= $tiposExtras . 'i';
            $valores = array_merge($valores,$valoresExtras,[$moduloId]);
            $stmt=$conn->prepare("UPDATE modulos_saas SET ".implode(',',$sets)." WHERE id=?");
            $stmt->bind_param($tipos,...$valores);
            $stmt->execute(); $stmt->close();
            $acaoLog='Atualizou módulo SaaS'; $mensagem='Módulo atualizado com sucesso.'; $registro=$moduloId;
        } else {
            $colunas=['codigo','nome','descricao','categoria','icone','modulo_essencial','permite_desativacao','exige_ia_externa','ordem_exibicao','ativo'];
            $tipos='sssssiiiii';
            $valores=[$codigoModulo,$nomeModulo,$descricaoModulo,$categoriaModulo,$iconeModulo,$essencialModulo,$permiteDesativacaoModulo,$exigeIaModulo,$ordemModulo,$ativoModulo];
            foreach ($camposExtras as $campo=>$valor) $colunas[]=$campo;
            $tipos.=$tiposExtras; $valores=array_merge($valores,$valoresExtras);
            $stmt=$conn->prepare("INSERT INTO modulos_saas (`".implode('`,`',$colunas)."`) VALUES (".implode(',',array_fill(0,count($colunas),'?')).")");
            $stmt->bind_param($tipos,...$valores); $stmt->execute(); $registro=(int)$stmt->insert_id; $stmt->close();
            $acaoLog='Criou módulo SaaS'; $mensagem='Módulo cadastrado com sucesso.';
        }
        $conn->commit();
        sgl_log($conn,$acaoLog,'modulos_saas',(string)$registro,"Módulo: {$nomeModulo}; Código: {$codigoModulo}; Categoria: {$categoriaModulo}; Status: {$statusLancamentoModulo}");
        sgl_redirect_cfg('modulos','sucesso',$mensagem);
    } catch (Throwable $e) {
        try{$conn->rollback();}catch(Throwable $ignorado){}
        sgl_redirect_cfg('modulos','erro','Não foi possível salvar o módulo. Verifique os dados informados.');
    }
}

if ($acao_cfg === 'alterar_status_modulo_saas') {
    $moduloId=max(0,(int)($_POST['modulo_saas_id']??0));
    $novoStatus=(int)($_POST['novo_status_modulo_saas']??-1);
    if($moduloId<=0 || !in_array($novoStatus,[0,1],true)) sgl_redirect_cfg('modulos','erro','Módulo ou status inválido.');
    try {
        $stmt=$conn->prepare("SELECT nome,modulo_essencial FROM modulos_saas WHERE id=? LIMIT 1"); $stmt->bind_param('i',$moduloId); $stmt->execute(); $mod=$stmt->get_result()->fetch_assoc(); $stmt->close();
        if(!$mod) sgl_redirect_cfg('modulos','erro','Módulo não encontrado.');
        if(!$novoStatus && !empty($mod['modulo_essencial'])) sgl_redirect_cfg('modulos','aviso','Módulos essenciais não podem ser inativados.');
        $stmt=$conn->prepare("UPDATE modulos_saas SET ativo=? WHERE id=?"); $stmt->bind_param('ii',$novoStatus,$moduloId); $stmt->execute(); $stmt->close();
        sgl_log($conn,$novoStatus?'Ativou módulo SaaS':'Inativou módulo SaaS','modulos_saas',(string)$moduloId,'Módulo: '.$mod['nome']);
        sgl_redirect_cfg('modulos','sucesso',$novoStatus?'Módulo ativado com sucesso.':'Módulo inativado com sucesso.');
    } catch(Throwable $e){ sgl_redirect_cfg('modulos','erro','Não foi possível alterar o status do módulo.'); }
}

if ($acao_cfg === 'excluir_modulo_saas') {
    $moduloId=max(0,(int)($_POST['modulo_saas_id']??0));
    if($moduloId<=0) sgl_redirect_cfg('modulos','erro','Módulo inválido para exclusão.');
    try {
        $conn->begin_transaction();
        $stmt=$conn->prepare("SELECT * FROM modulos_saas WHERE id=? LIMIT 1 FOR UPDATE"); $stmt->bind_param('i',$moduloId); $stmt->execute(); $mod=$stmt->get_result()->fetch_assoc(); $stmt->close();
        if(!$mod) throw new RuntimeException('Módulo não encontrado.');
        if(!empty($mod['modulo_essencial'])) { $conn->rollback(); sgl_redirect_cfg('modulos','aviso','Módulos essenciais não podem ser excluídos. Utilize a edição para revisar sua configuração.'); }
        $vinculos=0;
        if(sgl_tabela_existe($conn,'planos_modulos_saas')) { $stmt=$conn->prepare("SELECT COUNT(*) total FROM planos_modulos_saas WHERE modulo_id=?"); $stmt->bind_param('i',$moduloId); $stmt->execute(); $vinculos=(int)($stmt->get_result()->fetch_assoc()['total']??0); $stmt->close(); }
        if($vinculos>0){ $conn->rollback(); sgl_redirect_cfg('modulos','aviso','Não é possível excluir este módulo porque ele está vinculado a '.$vinculos.' plano(s). Remova os vínculos ou utilize Inativar.'); }
        $stmt=$conn->prepare("DELETE FROM modulos_saas WHERE id=? LIMIT 1"); $stmt->bind_param('i',$moduloId); $stmt->execute(); $afetadas=$stmt->affected_rows; $stmt->close();
        if($afetadas!==1) throw new RuntimeException('Exclusão não realizada.');
        $conn->commit();
        sgl_log($conn,'Excluiu módulo SaaS','modulos_saas',(string)$moduloId,'Módulo excluído: '.$mod['nome'].' | Código: '.$mod['codigo']);
        sgl_redirect_cfg('modulos','sucesso','Módulo excluído definitivamente com sucesso.');
    } catch(Throwable $e){ try{$conn->rollback();}catch(Throwable $ignorado){} sgl_redirect_cfg('modulos','erro','Não foi possível excluir o módulo. Verifique os vínculos existentes.'); }
}

if ($acao_cfg === 'salvar_configuracao_plano_modulos') {
    $planoId=max(0,(int)($_POST['plano_configurador_id']??0));
    if($planoId<=0) sgl_redirect_cfg('modulos','erro','Selecione um plano válido.');
    $modulosPost=$_POST['modulos_plano']??[];
    try {
        $conn->begin_transaction();
        $stmt=$conn->prepare("SELECT id,nome FROM planos_saas WHERE id=? LIMIT 1 FOR UPDATE"); $stmt->bind_param('i',$planoId); $stmt->execute(); $plano=$stmt->get_result()->fetch_assoc(); $stmt->close();
        if(!$plano) throw new RuntimeException('Plano não encontrado.');
        $res=$conn->query("SELECT id,nome,modulo_essencial,permite_desativacao FROM modulos_saas ORDER BY id");
        $salvos=0;
        while($res && ($mod=$res->fetch_assoc())){
            $mid=(int)$mod['id']; $dados=is_array($modulosPost[$mid]??null)?$modulosPost[$mid]:[];
            $incluido=!empty($dados['incluido'])?1:0;
            $obrigatorio=!empty($dados['obrigatorio'])?1:0;
            $permiteRemocao=!empty($dados['permite_remocao'])?1:0;
            if(!empty($mod['modulo_essencial'])) { $incluido=1; $obrigatorio=1; $permiteRemocao=0; }
            if(empty($mod['permite_desativacao'])) $permiteRemocao=0;
            if(!$incluido){ $obrigatorio=0; $permiteRemocao=0; }
            $descMensal=round(max(0,(float)str_replace(',','.',(string)($dados['desconto_mensal']??'0'))),2);
            $descAnual=round(max(0,(float)str_replace(',','.',(string)($dados['desconto_anual']??'0'))),2);
            if(!$permiteRemocao){$descMensal=0;$descAnual=0;}
            $ativo=$incluido?1:0;
            $stmtUp=$conn->prepare("INSERT INTO planos_modulos_saas (plano_id,modulo_id,incluido_padrao,obrigatorio,permite_remocao,desconto_remocao_mensal,desconto_remocao_anual,ativo) VALUES (?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE incluido_padrao=VALUES(incluido_padrao),obrigatorio=VALUES(obrigatorio),permite_remocao=VALUES(permite_remocao),desconto_remocao_mensal=VALUES(desconto_remocao_mensal),desconto_remocao_anual=VALUES(desconto_remocao_anual),ativo=VALUES(ativo)");
            $stmtUp->bind_param('iiiiiddi',$planoId,$mid,$incluido,$obrigatorio,$permiteRemocao,$descMensal,$descAnual,$ativo); $stmtUp->execute(); $stmtUp->close(); $salvos++;
        }
        $conn->commit();
        sgl_log($conn,'Configurou módulos do plano SaaS','planos_modulos_saas',(string)$planoId,'Plano: '.$plano['nome'].'; Módulos processados: '.$salvos);
        sgl_redirect_cfg('modulos','sucesso','Configuração de módulos do plano salva com sucesso.');
    } catch(Throwable $e){ try{$conn->rollback();}catch(Throwable $ignorado){} sgl_redirect_cfg('modulos','erro','Não foi possível salvar a configuração dos módulos do plano.'); }
}

// -----------------------------------------------------------------------------
// Assistente Enterprise "Novo Escritório" — Sprint 4.5 / Etapa 3.4.2
// Nesta etapa os dados permanecem somente em sessão. Nenhum tenant é provisionado.
// -----------------------------------------------------------------------------
if (!isset($_SESSION['rojex_novo_escritorio']) || !is_array($_SESSION['rojex_novo_escritorio'])) {
    $_SESSION['rojex_novo_escritorio'] = [];
}

if ($acao_cfg === 'assistente_novo_escritorio_reiniciar') {
    unset($_SESSION['rojex_novo_escritorio']);
    sgl_redirect_cfg('novo_escritorio', 'sucesso', 'Assistente reiniciado com segurança. Nenhum registro foi criado.');
}

if ($acao_cfg === 'assistente_novo_escritorio_salvar') {
    $etapaAssistente = max(1, min(6, (int)($_POST['etapa_assistente'] ?? 1)));
    $dadosAssistente = $_SESSION['rojex_novo_escritorio'] ?? [];

    if ($etapaAssistente === 1) {
        $nomeFantasia = sgl_limpar_texto((string)($_POST['assistente_nome_fantasia'] ?? ''), 180);
        $razaoSocial = sgl_limpar_texto((string)($_POST['assistente_razao_social'] ?? ''), 180);
        $documento = preg_replace('/\D+/', '', (string)($_POST['assistente_documento'] ?? ''));
        $responsavel = sgl_limpar_texto((string)($_POST['assistente_responsavel'] ?? ''), 140);
        $email = strtolower(sgl_limpar_texto((string)($_POST['assistente_email'] ?? ''), 140));
        $telefone = sgl_limpar_texto((string)($_POST['assistente_telefone'] ?? ''), 40);
        $cidade = sgl_limpar_texto((string)($_POST['assistente_cidade'] ?? ''), 100);
        $uf = strtoupper(sgl_limpar_texto((string)($_POST['assistente_uf'] ?? ''), 2));
        $tenant = strtoupper(preg_replace('/[^A-Z0-9._-]/i', '', (string)($_POST['assistente_tenant'] ?? '')));
        $subdominio = strtolower(preg_replace('/[^a-z0-9-]/i', '', (string)($_POST['assistente_subdominio'] ?? '')));

        if ($nomeFantasia === '' || $razaoSocial === '' || $documento === '' || $responsavel === '' || $email === '') {
            rojex_redirect_assistente(1, 'erro', 'Preencha os campos obrigatórios do escritório.');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            rojex_redirect_assistente(1, 'erro', 'Informe um e-mail válido.');
        }
        if (!in_array(strlen($documento), [11,14], true)) {
            rojex_redirect_assistente(1, 'erro', 'Informe um CPF ou CNPJ válido em quantidade de dígitos.');
        }
        if ($tenant === '') {
            $baseTenant = preg_replace('/[^A-Z0-9]+/', '-', strtoupper(iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $nomeFantasia) ?: $nomeFantasia));
            $tenant = trim(substr($baseTenant, 0, 48), '-') . '-' . strtoupper(substr(hash('sha256', $documento), 0, 8));
        }
        if ($subdominio === '') {
            $baseSub = strtolower(iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $nomeFantasia) ?: $nomeFantasia);
            $subdominio = trim(preg_replace('/[^a-z0-9]+/', '-', $baseSub), '-');
            $subdominio = substr($subdominio, 0, 50);
        }

        try {
            $stmt = $conn->prepare("SELECT id,nome FROM escritorios_saas WHERE (REPLACE(REPLACE(REPLACE(documento,'.',''),'/',''),'-','')=? OR LOWER(email)=? OR tenant_id=? OR LOWER(subdominio)=?) LIMIT 1");
            $stmt->bind_param('ssss', $documento, $email, $tenant, $subdominio);
            $stmt->execute();
            $duplicado = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($duplicado) {
                rojex_redirect_assistente(1, 'erro', 'Já existe um escritório com documento, e-mail, Tenant ID ou subdomínio informado.');
            }
        } catch (Throwable $e) {
            rojex_redirect_assistente(1, 'erro', 'Não foi possível concluir a validação de duplicidade.');
        }

        $dadosAssistente['escritorio'] = compact('nomeFantasia','razaoSocial','documento','responsavel','email','telefone','cidade','uf','tenant','subdominio');
        $_SESSION['rojex_novo_escritorio'] = $dadosAssistente;
        rojex_redirect_assistente(2, 'sucesso', 'Dados do escritório validados.');
    }

    if ($etapaAssistente === 2) {
        $planoId = max(0, (int)($_POST['assistente_plano_id'] ?? 0));
        $periodicidade = (string)($_POST['assistente_periodicidade'] ?? 'mensal');
        if (!in_array($periodicidade, ['mensal','anual'], true)) $periodicidade = 'mensal';
        try {
            $stmt = $conn->prepare("SELECT * FROM planos_saas WHERE id=? AND ativo=1 LIMIT 1");
            $stmt->bind_param('i', $planoId);
            $stmt->execute();
            $plano = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$plano) rojex_redirect_assistente(2, 'erro', 'Selecione um plano comercial ativo.');
            $dadosAssistente['plano'] = ['id'=>$planoId,'periodicidade'=>$periodicidade,'snapshot'=>$plano];
            $_SESSION['rojex_novo_escritorio'] = $dadosAssistente;
            rojex_redirect_assistente(3, 'sucesso', 'Plano comercial selecionado.');
        } catch (Throwable $e) {
            rojex_redirect_assistente(2, 'erro', 'Não foi possível carregar o plano selecionado.');
        }
    }

    if ($etapaAssistente === 3) {
        $planoIdAssistente = (int)($dadosAssistente['plano']['id'] ?? 0);
        if ($planoIdAssistente <= 0) rojex_redirect_assistente(2, 'erro', 'Selecione primeiro o plano comercial.');

        $selecionados = array_values(array_unique(array_map('intval', (array)($_POST['assistente_modulos'] ?? []))));

        try {
            $stmt = $conn->prepare(
                "SELECT m.*, pm.incluido_padrao, pm.obrigatorio, pm.permite_remocao,
                        pm.desconto_remocao_mensal, pm.desconto_remocao_anual
                   FROM planos_modulos_saas pm
                   INNER JOIN modulos_saas m ON m.id = pm.modulo_id
                  WHERE pm.plano_id = ? AND pm.ativo = 1 AND m.ativo = 1
                  ORDER BY m.ordem_exibicao, m.nome"
            );
            $stmt->bind_param('i', $planoIdAssistente);
            $stmt->execute();
            $res = $stmt->get_result();
            $modulosComerciais = [];
            $idsPermitidos = [];
            $idsObrigatorios = [];

            while ($modulo = $res->fetch_assoc()) {
                $modulosComerciais[] = $modulo;
                $idModulo = (int)$modulo['id'];
                $idsPermitidos[] = $idModulo;
                if (!empty($modulo['obrigatorio']) || !empty($modulo['modulo_essencial'])) {
                    $idsObrigatorios[] = $idModulo;
                }
            }
            $stmt->close();

            // Impede que IDs de módulos externos ao plano sejam enviados pelo navegador.
            $selecionados = array_values(array_intersect($selecionados, $idsPermitidos));
            $selecionados = array_values(array_unique(array_merge($selecionados, $idsObrigatorios)));

            $planoSnapshot = (array)($dadosAssistente['plano']['snapshot'] ?? []);
            $periodicidade = (string)($dadosAssistente['plano']['periodicidade'] ?? 'mensal');
            $motorComercial = rojex_motor_comercial_calcular(
                $planoSnapshot,
                $periodicidade,
                $modulosComerciais,
                $selecionados,
                0.0
            );

            $dadosAssistente['modulos'] = $selecionados;
            $dadosAssistente['comercial'] = $motorComercial;
            $_SESSION['rojex_novo_escritorio'] = $dadosAssistente;
            rojex_redirect_assistente(4, 'sucesso', 'Personalização e composição comercial armazenadas.');
        } catch (Throwable $e) {
            rojex_redirect_assistente(3, 'erro', 'Não foi possível calcular a composição comercial dos módulos.');
        }
    }

    if ($etapaAssistente === 4) {
        $trialDias = max(7, min(30, (int)($_POST['assistente_trial_dias'] ?? 15)));
        $inicio = date('Y-m-d');
        $fimTrial = date('Y-m-d', strtotime('+' . $trialDias . ' days'));
        try { $chave = 'ROJEX-' . strtoupper(bin2hex(random_bytes(12))); }
        catch (Throwable $e) { $chave = 'ROJEX-' . strtoupper(substr(hash('sha256', uniqid('', true)), 0, 24)); }
        $dadosAssistente['licenca'] = ['chave'=>$chave,'trial_dias'=>$trialDias,'inicio'=>$inicio,'fim_trial'=>$fimTrial];
        $_SESSION['rojex_novo_escritorio'] = $dadosAssistente;
        rojex_redirect_assistente(5, 'sucesso', 'Prévia da licença gerada.');
    }

    if ($etapaAssistente === 5) {
        $adminNome = sgl_limpar_texto((string)($_POST['assistente_admin_nome'] ?? ''), 140);
        $adminLogin = strtolower(preg_replace('/[^a-z0-9._-]/i', '', (string)($_POST['assistente_admin_login'] ?? '')));
        $adminEmail = strtolower(sgl_limpar_texto((string)($_POST['assistente_admin_email'] ?? ''), 140));
        $adminSenha = (string)($_POST['assistente_admin_senha'] ?? '');
        $adminIdioma = in_array((string)($_POST['assistente_admin_idioma'] ?? 'pt-BR'), ['pt-BR','en-US','es-ES'], true) ? (string)$_POST['assistente_admin_idioma'] : 'pt-BR';
        $adminFuso = sgl_limpar_texto((string)($_POST['assistente_admin_fuso'] ?? 'America/Sao_Paulo'), 80);
        if ($adminNome === '' || $adminLogin === '' || !filter_var($adminEmail, FILTER_VALIDATE_EMAIL) || strlen($adminSenha) < 6) {
            rojex_redirect_assistente(5, 'erro', 'Informe nome, login, e-mail válido e senha inicial com pelo menos 6 caracteres.');
        }
        try {
            // A tabela oficial de usuários do ROJEX.AI utiliza a coluna `usuario`
            // para o login. A validação permanece compatível com bancos antigos
            // que eventualmente utilizem `username`.
            $colunaLoginUsuario = sgl_coluna_existe($conn, 'usuarios', 'usuario')
                ? 'usuario'
                : (sgl_coluna_existe($conn, 'usuarios', 'username') ? 'username' : '');

            if ($colunaLoginUsuario === '') {
                rojex_redirect_assistente(5, 'erro', 'A estrutura da tabela de usuários não possui uma coluna de login compatível.');
            }

            if (sgl_coluna_existe($conn, 'usuarios', 'email')) {
                $stmt = $conn->prepare(
                    "SELECT id FROM usuarios
                      WHERE LOWER(`{$colunaLoginUsuario}`) = LOWER(?)
                         OR LOWER(email) = LOWER(?)
                      LIMIT 1"
                );
                $stmt->bind_param('ss', $adminLogin, $adminEmail);
            } else {
                $stmt = $conn->prepare(
                    "SELECT id FROM usuarios
                      WHERE LOWER(`{$colunaLoginUsuario}`) = LOWER(?)
                      LIMIT 1"
                );
                $stmt->bind_param('s', $adminLogin);
            }

            $stmt->execute();
            $duplicado = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($duplicado) {
                rojex_redirect_assistente(5, 'erro', 'O login ou e-mail do administrador já está em uso.');
            }
        } catch (Throwable $e) {
            rojex_redirect_assistente(5, 'erro', 'Não foi possível validar o administrador.');
        }
        $dadosAssistente['administrador'] = ['nome'=>$adminNome,'login'=>$adminLogin,'email'=>$adminEmail,'senha_hash'=>password_hash($adminSenha, PASSWORD_DEFAULT),'senha_definida'=>true,'idioma'=>$adminIdioma,'fuso'=>$adminFuso];
        $_SESSION['rojex_novo_escritorio'] = $dadosAssistente;
        rojex_redirect_assistente(6, 'sucesso', 'Administrador validado. Revise os dados antes do provisionamento.');
    }
}


// -----------------------------------------------------------------------------
// Provisionamento Enterprise Transacional — Sprint 4.5 / Etapa 3.4.4
// Revalida todos os dados e executa criação atômica com rollback completo.
// -----------------------------------------------------------------------------
if ($acao_cfg === 'assistente_novo_escritorio_provisionar') {
    $dadosAssistente = $_SESSION['rojex_novo_escritorio'] ?? [];
    $escritorio = (array)($dadosAssistente['escritorio'] ?? []);
    $planoSessao = (array)($dadosAssistente['plano'] ?? []);
    $licencaSessao = (array)($dadosAssistente['licenca'] ?? []);
    $administrador = (array)($dadosAssistente['administrador'] ?? []);
    $modulosSelecionados = array_values(array_unique(array_map('intval', (array)($dadosAssistente['modulos'] ?? []))));

    if (!$escritorio || !$planoSessao || !$licencaSessao || !$administrador || empty($administrador['senha_hash'])) {
        rojex_redirect_assistente(1, 'erro', 'A sessão do assistente está incompleta. Revise o cadastro antes de provisionar.');
    }

    $nomeFantasia = sgl_limpar_texto((string)($escritorio['nomeFantasia'] ?? ''), 180);
    $razaoSocial = sgl_limpar_texto((string)($escritorio['razaoSocial'] ?? ''), 180);
    $documento = preg_replace('/\D+/', '', (string)($escritorio['documento'] ?? ''));
    $responsavel = sgl_limpar_texto((string)($escritorio['responsavel'] ?? ''), 140);
    $emailEscritorio = strtolower(sgl_limpar_texto((string)($escritorio['email'] ?? ''), 140));
    $telefone = sgl_limpar_texto((string)($escritorio['telefone'] ?? ''), 40);
    $cidade = sgl_limpar_texto((string)($escritorio['cidade'] ?? ''), 100);
    $uf = strtoupper(sgl_limpar_texto((string)($escritorio['uf'] ?? ''), 2));
    $tenant = strtoupper(preg_replace('/[^A-Z0-9._-]/i', '', (string)($escritorio['tenant'] ?? '')));
    $subdominio = strtolower(preg_replace('/[^a-z0-9-]/i', '', (string)($escritorio['subdominio'] ?? '')));
    $planoId = max(0, (int)($planoSessao['id'] ?? 0));
    $periodicidade = (($planoSessao['periodicidade'] ?? '') === 'anual') ? 'anual' : 'mensal';
    $trialDias = max(7, min(30, (int)($licencaSessao['trial_dias'] ?? 15)));
    $adminNome = sgl_limpar_texto((string)($administrador['nome'] ?? ''), 150);
    $adminLogin = strtolower(preg_replace('/[^a-z0-9._-]/i', '', (string)($administrador['login'] ?? '')));
    $adminEmail = strtolower(sgl_limpar_texto((string)($administrador['email'] ?? ''), 120));
    $adminSenhaHash = (string)($administrador['senha_hash'] ?? '');
    $adminIdioma = in_array((string)($administrador['idioma'] ?? 'pt-BR'), ['pt-BR','en-US','es-ES'], true) ? (string)$administrador['idioma'] : 'pt-BR';
    $adminFuso = sgl_limpar_texto((string)($administrador['fuso'] ?? 'America/Sao_Paulo'), 80);

    if ($nomeFantasia === '' || $razaoSocial === '' || !in_array(strlen($documento), [11,14], true)
        || !filter_var($emailEscritorio, FILTER_VALIDATE_EMAIL) || $tenant === '' || $subdominio === ''
        || $planoId <= 0 || $adminNome === '' || $adminLogin === '' || !filter_var($adminEmail, FILTER_VALIDATE_EMAIL)
        || !password_get_info($adminSenhaHash)['algo']) {
        rojex_redirect_assistente(6, 'erro', 'Os dados finais não passaram na revalidação de segurança. Revise o assistente.');
    }

    try {
        // Recarrega plano e módulos diretamente do banco para não confiar no snapshot da sessão.
        $stmt = $conn->prepare("SELECT * FROM planos_saas WHERE id=? AND ativo=1 LIMIT 1");
        $stmt->bind_param('i', $planoId);
        $stmt->execute();
        $planoAtual = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$planoAtual) {
            rojex_redirect_assistente(2, 'erro', 'O plano selecionado foi inativado ou removido. Escolha outro plano.');
        }

        $stmt = $conn->prepare(
            "SELECT m.*, pm.incluido_padrao, pm.obrigatorio, pm.permite_remocao,
                    pm.desconto_remocao_mensal, pm.desconto_remocao_anual
               FROM planos_modulos_saas pm
               INNER JOIN modulos_saas m ON m.id=pm.modulo_id
              WHERE pm.plano_id=? AND pm.ativo=1 AND m.ativo=1
              ORDER BY m.ordem_exibicao, m.nome"
        );
        $stmt->bind_param('i', $planoId);
        $stmt->execute();
        $resModulos = $stmt->get_result();
        $modulosPlanoAtual = [];
        $idsPermitidos = [];
        $idsObrigatorios = [];
        while ($modulo = $resModulos->fetch_assoc()) {
            $modulosPlanoAtual[] = $modulo;
            $mid = (int)$modulo['id'];
            $idsPermitidos[] = $mid;
            if (!empty($modulo['obrigatorio']) || !empty($modulo['modulo_essencial'])) $idsObrigatorios[] = $mid;
        }
        $stmt->close();

        $modulosSelecionados = array_values(array_intersect($modulosSelecionados, $idsPermitidos));
        $modulosSelecionados = array_values(array_unique(array_merge($modulosSelecionados, $idsObrigatorios)));
        $comercialFinal = rojex_motor_comercial_calcular($planoAtual, $periodicidade, $modulosPlanoAtual, $modulosSelecionados, 0.0);

        $conn->begin_transaction();

        // Bloqueios e revalidação contra concorrência imediatamente antes das inserções.
        $stmt = $conn->prepare("SELECT id FROM escritorios_saas WHERE REPLACE(REPLACE(REPLACE(documento,'.',''),'/',''),'-','')=? OR LOWER(email)=? OR tenant_id=? OR LOWER(subdominio)=? LIMIT 1 FOR UPDATE");
        $stmt->bind_param('ssss', $documento, $emailEscritorio, $tenant, $subdominio);
        $stmt->execute();
        $duplicadoEscritorio = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($duplicadoEscritorio) throw new RuntimeException('Já existe escritório com documento, e-mail, tenant ou subdomínio informado.');

        $colunaLoginUsuario = sgl_coluna_existe($conn, 'usuarios', 'usuario') ? 'usuario' : (sgl_coluna_existe($conn, 'usuarios', 'username') ? 'username' : '');
        if ($colunaLoginUsuario === '') throw new RuntimeException('A tabela usuarios não possui coluna de login compatível.');
        $stmt = $conn->prepare("SELECT id FROM usuarios WHERE LOWER(`{$colunaLoginUsuario}`)=LOWER(?) OR LOWER(email)=LOWER(?) LIMIT 1 FOR UPDATE");
        $stmt->bind_param('ss', $adminLogin, $adminEmail);
        $stmt->execute();
        $duplicadoAdmin = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($duplicadoAdmin) throw new RuntimeException('O login ou e-mail do administrador já está em uso.');

        $codigoPlano = substr((string)($planoAtual['codigo'] ?? 'enterprise'), 0, 30);
        $statusEscritorio = 'implantacao';
        $observacoesEscritorio = 'Provisionado pelo Assistente Enterprise. Razão social: ' . $razaoSocial;
        $stmt = $conn->prepare("INSERT INTO escritorios_saas (tenant_id,nome,documento,responsavel,email,subdominio,status,plano,telefone,cidade,uf,observacoes) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
        $stmt->bind_param('ssssssssssss', $tenant, $nomeFantasia, $documento, $responsavel, $emailEscritorio, $subdominio, $statusEscritorio, $codigoPlano, $telefone, $cidade, $uf, $observacoesEscritorio);
        if (!$stmt->execute()) throw new RuntimeException('Falha ao criar o escritório.');
        $escritorioId = (int)$stmt->insert_id;
        $stmt->close();

        $inicio = date('Y-m-d');
        $fimTrial = date('Y-m-d', strtotime('+' . $trialDias . ' days'));
        $proximoVencimento = $fimTrial;
        $statusAssinatura = 'trial';
        $valorBase = (float)$comercialFinal['valor_base'];
        $descontoModulos = (float)$comercialFinal['desconto_modulos'];
        $valorExtras = (float)$comercialFinal['valor_extras'];
        $ajusteComercial = (float)$comercialFinal['ajuste_manual'];
        $valorContratado = (float)$comercialFinal['valor_final'];
        $stmt = $conn->prepare("INSERT INTO assinaturas_saas (escritorio_id,plano_id,periodicidade,valor_base,desconto_modulos,valor_extras,ajuste_comercial,valor_contratado,status,trial_inicio,trial_fim,inicio_vigencia,proximo_vencimento) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)");
        $stmt->bind_param('iisdddddsssss', $escritorioId, $planoId, $periodicidade, $valorBase, $descontoModulos, $valorExtras, $ajusteComercial, $valorContratado, $statusAssinatura, $inicio, $fimTrial, $inicio, $proximoVencimento);
        if (!$stmt->execute()) throw new RuntimeException('Falha ao criar a assinatura comercial.');
        $assinaturaId = (int)$stmt->insert_id;
        $stmt->close();

        do {
            try { $chaveLicenca = 'ROJEX-' . strtoupper(bin2hex(random_bytes(12))); }
            catch (Throwable $e) { $chaveLicenca = 'ROJEX-' . strtoupper(substr(hash('sha256', uniqid('', true)), 0, 24)); }
            $stmt = $conn->prepare("SELECT id FROM licencas_saas WHERE chave_licenca=? LIMIT 1");
            $stmt->bind_param('s', $chaveLicenca);
            $stmt->execute();
            $existeChave = (bool)$stmt->get_result()->fetch_assoc();
            $stmt->close();
        } while ($existeChave);

        $statusLicenca = 'teste';
        $limiteUsuarios = max(1, (int)($planoAtual['limite_usuarios_padrao'] ?? 1));
        $limiteArmazenamento = max(1, (int)($planoAtual['limite_armazenamento_gb_padrao'] ?? 1));
        $observacoesLicenca = json_encode(['assinatura_id'=>$assinaturaId,'periodicidade'=>$periodicidade,'valor_contratado'=>$valorContratado,'trial_dias'=>$trialDias], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        $stmt = $conn->prepare("INSERT INTO licencas_saas (escritorio_id,chave_licenca,plano,status,limite_usuarios,limite_armazenamento_gb,ativada_em,renovacao_em,observacoes) VALUES (?,?,?,?,?,?,?,?,?)");
        $stmt->bind_param('isssiisss', $escritorioId, $chaveLicenca, $codigoPlano, $statusLicenca, $limiteUsuarios, $limiteArmazenamento, $inicio, $fimTrial, $observacoesLicenca);
        if (!$stmt->execute()) throw new RuntimeException('Falha ao criar a licença.');
        $licencaId = (int)$stmt->insert_id;
        $stmt->close();

        $stmtModulo = $conn->prepare("INSERT INTO escritorios_modulos_saas (escritorio_id,modulo_id,origem,ativo,valor_ajuste) VALUES (?,?,'plano',1,0.00)");
        foreach ($modulosSelecionados as $moduloId) {
            $stmtModulo->bind_param('ii', $escritorioId, $moduloId);
            if (!$stmtModulo->execute()) throw new RuntimeException('Falha ao vincular os módulos contratados.');
        }
        $stmtModulo->close();

        $perfilAdmin = 'Administrador';
        $nivelAdmin = 'Administrador';
        $statusAdmin = 'Ativo';
        $ativoAdmin = 1;
        $cargoAdmin = 'Administrador do Escritório';
        $departamentoAdmin = 'Administração';
        $observacoesAdmin = 'Administrador provisionado automaticamente para o tenant ' . $tenant;
        $vinculoStatus = 'ativo';
        $stmt = $conn->prepare("INSERT INTO usuarios (nome,`{$colunaLoginUsuario}`,email,senha,perfil,nivel,status,ativo,cargo,departamento,observacoes,vinculo_status) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
        $stmt->bind_param('sssssssissss', $adminNome, $adminLogin, $adminEmail, $adminSenhaHash, $perfilAdmin, $nivelAdmin, $statusAdmin, $ativoAdmin, $cargoAdmin, $departamentoAdmin, $observacoesAdmin, $vinculoStatus);
        if (!$stmt->execute()) throw new RuntimeException('Falha ao criar o administrador.');
        $usuarioAdminId = (int)$stmt->insert_id;
        $stmt->close();

        $papel = 'administrador';
        $principal = 1;
        $ativoVinculo = 1;
        $stmt = $conn->prepare("INSERT INTO usuarios_escritorios_saas (usuario_id,escritorio_id,tenant_id,papel,principal,ativo) VALUES (?,?,?,?,?,?)");
        $stmt->bind_param('iissii', $usuarioAdminId, $escritorioId, $tenant, $papel, $principal, $ativoVinculo);
        if (!$stmt->execute()) throw new RuntimeException('Falha ao vincular o administrador ao tenant.');
        $stmt->close();

        $temaModo = 'claro';
        $temaDensidade = 'confortavel';
        $temaBordas = 'suaves';
        $temaFonte = 100;
        $stmt = $conn->prepare("INSERT INTO usuarios_preferencias (usuario_id,tema_modo,tema_densidade,tema_bordas,tema_fonte_percentual) VALUES (?,?,?,?,?)");
        $stmt->bind_param('isssi', $usuarioAdminId, $temaModo, $temaDensidade, $temaBordas, $temaFonte);
        if (!$stmt->execute()) throw new RuntimeException('Falha ao criar as preferências do administrador.');
        $stmt->close();

        // Reutiliza os padrões seguros fornecidos pelo núcleo Empresa sem alterar
        // a configuração global do MASTER. Os valores são persistidos por tenant.
        require_once __DIR__ . '/../core/Empresa.php';
        $empresaBase = new Empresa($conn);
        $configuracoesIniciais = [
            'nome_escritorio' => $nomeFantasia,
            'razao_social' => $razaoSocial,
            'documento' => $documento,
            'responsavel' => $responsavel,
            'email' => $emailEscritorio,
            'telefone' => $telefone,
            'cidade' => $cidade,
            'uf' => $uf,
            'tenant_id' => $tenant,
            'subdominio' => $subdominio,
            'timezone' => $adminFuso !== '' ? $adminFuso : $empresaBase->timezone(),
            'idioma' => $adminIdioma,
            'cor_primaria' => $empresaBase->corPrimaria(),
            'cor_secundaria' => $empresaBase->corSecundaria(),
            'cor_accent' => $empresaBase->corAccent(),
            'plano_codigo' => $codigoPlano,
            'licenca_chave' => $chaveLicenca,
        ];
        $stmtCfg = $conn->prepare("INSERT INTO escritorios_configuracoes_saas (escritorio_id,tenant_id,chave,valor) VALUES (?,?,?,?)");
        foreach ($configuracoesIniciais as $chaveCfg => $valorCfg) {
            $valorCfg = (string)$valorCfg;
            $stmtCfg->bind_param('isss', $escritorioId, $tenant, $chaveCfg, $valorCfg);
            if (!$stmtCfg->execute()) throw new RuntimeException('Falha ao criar as configurações iniciais do tenant.');
        }
        $stmtCfg->close();

        sgl_log($conn, 'Provisionou novo escritório SaaS', 'escritorios_saas', (string)$escritorioId,
            'Tenant: ' . $tenant . '; Plano: ' . $codigoPlano . '; Assinatura: ' . $assinaturaId .
            '; Licença: ' . $licencaId . '; Administrador: ' . $usuarioAdminId . '; Módulos: ' . count($modulosSelecionados) .
            '; Valor contratado: R$ ' . number_format($valorContratado, 2, ',', '.'));

        $conn->commit();
        unset($_SESSION['rojex_novo_escritorio']);
        sgl_redirect_cfg('administracao', 'sucesso', 'Escritório provisionado com sucesso. Tenant: ' . $tenant . ' | Licença: ' . $chaveLicenca);
    } catch (Throwable $e) {
        try { $conn->rollback(); } catch (Throwable $ignorado) {}
        error_log('ROJEX Provisionamento Enterprise: ' . $e->getMessage());
        rojex_redirect_assistente(6, 'erro', 'Provisionamento cancelado e revertido integralmente: ' . $e->getMessage());
    }
}

// -----------------------------------------------------------------------------
// Gestão de Escritórios SaaS — Sprint 4.1.3 / Etapa 3
// -----------------------------------------------------------------------------
if ($acao_cfg === 'salvar_escritorio_saas') {
    $escritorioIdSaas = max(0, (int)($_POST['escritorio_saas_id'] ?? 0));
    $tenantIdSaas = strtoupper(preg_replace('/[^A-Z0-9._-]/i', '', (string)($_POST['tenant_id_saas'] ?? '')));
    $nomeSaas = sgl_limpar_texto((string)($_POST['nome_escritorio_saas'] ?? ''), 180);
    $documentoSaas = sgl_limpar_texto((string)($_POST['documento_escritorio_saas'] ?? ''), 30);
    $responsavelSaas = sgl_limpar_texto((string)($_POST['responsavel_escritorio_saas'] ?? ''), 140);
    $emailSaas = sgl_limpar_texto((string)($_POST['email_escritorio_saas'] ?? ''), 140);
    $telefoneSaas = sgl_limpar_texto((string)($_POST['telefone_escritorio_saas'] ?? ''), 40);
    $cidadeSaas = sgl_limpar_texto((string)($_POST['cidade_escritorio_saas'] ?? ''), 100);
    $ufSaas = strtoupper(sgl_limpar_texto((string)($_POST['uf_escritorio_saas'] ?? ''), 2));
    $subdominioSaas = strtolower(preg_replace('/[^a-zA-Z0-9.-]/', '', (string)($_POST['subdominio_escritorio_saas'] ?? '')));
    $statusEscritorioSaas = (string)($_POST['status_escritorio_saas'] ?? 'implantacao');
    $planoEscritorioSaas = (string)($_POST['plano_escritorio_saas'] ?? 'enterprise');
    $observacoesEscritorioSaas = sgl_limpar_texto((string)($_POST['observacoes_escritorio_saas'] ?? ''), 1500);

    if ($nomeSaas === '') {
        sgl_redirect_cfg('administracao', 'erro', 'Informe o nome do escritório.');
    }
    if ($tenantIdSaas === '') {
        try { $tenantIdSaas = 'ROJEX-TENANT-' . strtoupper(bin2hex(random_bytes(8))); }
        catch (Throwable $e) { $tenantIdSaas = 'ROJEX-TENANT-' . strtoupper(substr(hash('sha256', uniqid('', true)), 0, 16)); }
    }
    if ($emailSaas !== '' && !filter_var($emailSaas, FILTER_VALIDATE_EMAIL)) {
        sgl_redirect_cfg('administracao', 'erro', 'Informe um e-mail válido para o escritório.');
    }
    if (!in_array($statusEscritorioSaas, ['implantacao','ativo','suspenso','bloqueado','encerrado'], true)) {
        $statusEscritorioSaas = 'implantacao';
    }
    if (!in_array($planoEscritorioSaas, ['starter','professional','enterprise'], true)) {
        $planoEscritorioSaas = 'enterprise';
    }

    try {
        $stmtDup = $conn->prepare("SELECT id FROM escritorios_saas WHERE tenant_id = ? AND id <> ? LIMIT 1");
        $stmtDup->bind_param('si', $tenantIdSaas, $escritorioIdSaas);
        $stmtDup->execute();
        $duplicadoTenant = $stmtDup->get_result()->fetch_assoc();
        $stmtDup->close();
        if ($duplicadoTenant) {
            sgl_redirect_cfg('administracao', 'erro', 'Este Tenant ID já pertence a outro escritório.');
        }

        if ($escritorioIdSaas > 0) {
            $stmt = $conn->prepare("UPDATE escritorios_saas SET tenant_id=?, nome=?, documento=?, responsavel=?, email=?, telefone=?, cidade=?, uf=?, subdominio=?, status=?, plano=?, observacoes=?, encerrado_em=IF(?='encerrado', COALESCE(encerrado_em,NOW()), NULL) WHERE id=?");
            $stmt->bind_param('sssssssssssssi', $tenantIdSaas, $nomeSaas, $documentoSaas, $responsavelSaas, $emailSaas, $telefoneSaas, $cidadeSaas, $ufSaas, $subdominioSaas, $statusEscritorioSaas, $planoEscritorioSaas, $observacoesEscritorioSaas, $statusEscritorioSaas, $escritorioIdSaas);
            $stmt->execute();
            $stmt->close();
            $registroEscritorioLog = (string)$escritorioIdSaas;
            $mensagemEscritorio = 'Escritório atualizado com sucesso.';
            $acaoEscritorioLog = 'Atualizou escritório SaaS';
        } else {
            $stmt = $conn->prepare("INSERT INTO escritorios_saas (tenant_id,nome,documento,responsavel,email,telefone,cidade,uf,subdominio,status,plano,observacoes,encerrado_em) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,IF(?='encerrado',NOW(),NULL))");
            $stmt->bind_param('sssssssssssss', $tenantIdSaas, $nomeSaas, $documentoSaas, $responsavelSaas, $emailSaas, $telefoneSaas, $cidadeSaas, $ufSaas, $subdominioSaas, $statusEscritorioSaas, $planoEscritorioSaas, $observacoesEscritorioSaas, $statusEscritorioSaas);
            $stmt->execute();
            $registroEscritorioLog = (string)$stmt->insert_id;
            $stmt->close();
            $mensagemEscritorio = 'Escritório cadastrado com sucesso.';
            $acaoEscritorioLog = 'Criou escritório SaaS';
        }

        sgl_log($conn, $acaoEscritorioLog, 'escritorios_saas', $registroEscritorioLog, "Tenant: {$tenantIdSaas}; Status: {$statusEscritorioSaas}; Plano: {$planoEscritorioSaas}");
        sgl_redirect_cfg('administracao', 'sucesso', $mensagemEscritorio);
    } catch (Throwable $e) {
        sgl_redirect_cfg('administracao', 'erro', 'Não foi possível salvar o escritório.');
    }
}

if ($acao_cfg === 'alterar_status_escritorio_saas') {
    $escritorioIdSaas = max(0, (int)($_POST['escritorio_saas_id'] ?? 0));
    $novoStatusEscritorio = (string)($_POST['novo_status_escritorio'] ?? '');
    if ($escritorioIdSaas <= 0 || !in_array($novoStatusEscritorio, ['implantacao','ativo','suspenso','bloqueado','encerrado'], true)) {
        sgl_redirect_cfg('administracao', 'erro', 'Escritório ou status inválido.');
    }
    try {
        $stmt = $conn->prepare("UPDATE escritorios_saas SET status=?, encerrado_em=IF(?='encerrado',COALESCE(encerrado_em,NOW()),NULL) WHERE id=?");
        $stmt->bind_param('ssi', $novoStatusEscritorio, $novoStatusEscritorio, $escritorioIdSaas);
        $stmt->execute();
        $stmt->close();
        sgl_log($conn, 'Alterou status de escritório SaaS', 'escritorios_saas', (string)$escritorioIdSaas, 'Novo status: ' . $novoStatusEscritorio);
        sgl_redirect_cfg('administracao', 'sucesso', 'Status do escritório atualizado.');
    } catch (Throwable $e) {
        sgl_redirect_cfg('administracao', 'erro', 'Não foi possível alterar o status do escritório.');
    }
}

// -----------------------------------------------------------------------------
// Central de Licenças SaaS — Sprint 4.1.3 / Etapa 2
// -----------------------------------------------------------------------------
if ($acao_cfg === 'salvar_licenca_saas') {
    $licencaId = max(0, (int)($_POST['licenca_id'] ?? 0));
    $chaveLicenca = strtoupper(preg_replace('/[^A-Z0-9._-]/i', '', (string)($_POST['chave_licenca'] ?? '')));
    $planoLicenca = (string)($_POST['plano_licenca_saas'] ?? 'enterprise');
    $statusLicencaSaas = (string)($_POST['status_licenca_saas'] ?? 'teste');
    $limiteUsuariosSaas = max(1, min(1000, (int)($_POST['limite_usuarios_saas'] ?? 100)));
    $limiteArmazenamentoSaas = max(1, min(100000, (int)($_POST['limite_armazenamento_saas'] ?? 50)));
    $ativadaEm = trim((string)($_POST['ativada_em'] ?? ''));
    $renovacaoEm = trim((string)($_POST['renovacao_em'] ?? ''));
    $observacoesLicenca = sgl_limpar_texto((string)($_POST['observacoes_licenca'] ?? ''), 1500);
    $escritorioId = max(0, (int)($_POST['escritorio_id'] ?? 0));

    if ($chaveLicenca === '' || strlen($chaveLicenca) < 8) {
        sgl_redirect_cfg('administracao', 'erro', 'Informe uma chave de licença válida com pelo menos 8 caracteres.');
    }
    if (!in_array($planoLicenca, ['starter','professional','enterprise'], true)) {
        $planoLicenca = 'enterprise';
    }
    if (!in_array($statusLicencaSaas, ['teste','ativa','suspensa','expirada','cancelada'], true)) {
        $statusLicencaSaas = 'teste';
    }
    foreach ([$ativadaEm, $renovacaoEm] as $dataLicencaSaas) {
        if ($dataLicencaSaas !== '') {
            $obj = DateTime::createFromFormat('Y-m-d', $dataLicencaSaas);
            if (!$obj || $obj->format('Y-m-d') !== $dataLicencaSaas) {
                sgl_redirect_cfg('administracao', 'erro', 'Informe datas válidas para a licença.');
            }
        }
    }
    if ($ativadaEm !== '' && $renovacaoEm !== '' && $renovacaoEm < $ativadaEm) {
        sgl_redirect_cfg('administracao', 'erro', 'A renovação não pode ser anterior à ativação.');
    }

    try {
        $stmtDup = $conn->prepare("SELECT id FROM licencas_saas WHERE chave_licenca = ? AND id <> ? LIMIT 1");
        $stmtDup->bind_param('si', $chaveLicenca, $licencaId);
        $stmtDup->execute();
        $duplicada = $stmtDup->get_result()->fetch_assoc();
        $stmtDup->close();
        if ($duplicada) {
            sgl_redirect_cfg('administracao', 'erro', 'Esta chave de licença já está cadastrada.');
        }

        $escritorioVinculo = $escritorioId > 0 ? $escritorioId : null;
        $ativadaSql = $ativadaEm !== '' ? $ativadaEm : null;
        $renovacaoSql = $renovacaoEm !== '' ? $renovacaoEm : null;

        if ($licencaId > 0) {
            $stmt = $conn->prepare("UPDATE licencas_saas SET escritorio_id = ?, chave_licenca = ?, plano = ?, status = ?, limite_usuarios = ?, limite_armazenamento_gb = ?, ativada_em = ?, renovacao_em = ?, observacoes = ? WHERE id = ?");
            $stmt->bind_param('isssiisssi', $escritorioVinculo, $chaveLicenca, $planoLicenca, $statusLicencaSaas, $limiteUsuariosSaas, $limiteArmazenamentoSaas, $ativadaSql, $renovacaoSql, $observacoesLicenca, $licencaId);
            $stmt->execute();
            $stmt->close();
            $registroLog = (string)$licencaId;
            $acaoLog = 'Atualizou licença SaaS';
            $mensagem = 'Licença atualizada com sucesso.';
        } else {
            $stmt = $conn->prepare("INSERT INTO licencas_saas (escritorio_id, chave_licenca, plano, status, limite_usuarios, limite_armazenamento_gb, ativada_em, renovacao_em, observacoes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param('isssiisss', $escritorioVinculo, $chaveLicenca, $planoLicenca, $statusLicencaSaas, $limiteUsuariosSaas, $limiteArmazenamentoSaas, $ativadaSql, $renovacaoSql, $observacoesLicenca);
            $stmt->execute();
            $registroLog = (string)$stmt->insert_id;
            $stmt->close();
            $acaoLog = 'Criou licença SaaS';
            $mensagem = 'Licença cadastrada com sucesso.';
        }

        // Se a licença pertence ao tenant desta instalação, mantém compatibilidade
        // com as chaves antigas já consumidas por outras telas do sistema.
        $tenantAtual = sgl_cfg_get($conn, 'tenant_id', '');
        if ($escritorioId > 0 && $tenantAtual !== '') {
            $stmtTenant = $conn->prepare("SELECT tenant_id FROM escritorios_saas WHERE id = ? LIMIT 1");
            $stmtTenant->bind_param('i', $escritorioId);
            $stmtTenant->execute();
            $tenantLicenca = (string)($stmtTenant->get_result()->fetch_assoc()['tenant_id'] ?? '');
            $stmtTenant->close();
            if ($tenantLicenca === $tenantAtual) {
                sgl_cfg_set($conn, 'chave_instalacao', $chaveLicenca);
                sgl_cfg_set($conn, 'plano_licenca', $planoLicenca);
                sgl_cfg_set($conn, 'status_licenca', $statusLicencaSaas === 'cancelada' ? 'suspensa' : $statusLicencaSaas);
                sgl_cfg_set($conn, 'limite_usuarios_licenca', (string)min(100, $limiteUsuariosSaas));
                sgl_cfg_set($conn, 'limite_armazenamento_gb', (string)$limiteArmazenamentoSaas);
                sgl_cfg_set($conn, 'data_ativacao_licenca', $ativadaEm);
                sgl_cfg_set($conn, 'data_renovacao_licenca', $renovacaoEm);
            }
        }

        sgl_log($conn, $acaoLog, 'licencas_saas', $registroLog, "Plano: {$planoLicenca}; Status: {$statusLicencaSaas}; Limite: {$limiteUsuariosSaas} usuários.");
        sgl_redirect_cfg('administracao', 'sucesso', $mensagem);
    } catch (Throwable $e) {
        sgl_redirect_cfg('administracao', 'erro', 'Não foi possível salvar a licença. Verifique os dados informados.');
    }
}

if ($acao_cfg === 'alterar_status_licenca_saas') {
    $licencaId = max(0, (int)($_POST['licenca_id'] ?? 0));
    $novoStatus = (string)($_POST['novo_status'] ?? '');
    if ($licencaId <= 0 || !in_array($novoStatus, ['teste','ativa','suspensa','expirada','cancelada'], true)) {
        sgl_redirect_cfg('administracao', 'erro', 'Licença ou status inválido.');
    }

    try {
        $stmt = $conn->prepare("UPDATE licencas_saas SET status = ? WHERE id = ?");
        $stmt->bind_param('si', $novoStatus, $licencaId);
        $stmt->execute();
        $alteradas = $stmt->affected_rows;
        $stmt->close();
        if ($alteradas < 1) {
            sgl_redirect_cfg('administracao', 'aviso', 'A licença já estava com o status selecionado ou não foi encontrada.');
        }

        $stmtAtual = $conn->prepare("SELECT l.chave_licenca, e.tenant_id FROM licencas_saas l LEFT JOIN escritorios_saas e ON e.id = l.escritorio_id WHERE l.id = ? LIMIT 1");
        $stmtAtual->bind_param('i', $licencaId);
        $stmtAtual->execute();
        $licencaAtualizada = $stmtAtual->get_result()->fetch_assoc();
        $stmtAtual->close();
        if ($licencaAtualizada && (string)($licencaAtualizada['tenant_id'] ?? '') === sgl_cfg_get($conn, 'tenant_id', '')) {
            sgl_cfg_set($conn, 'status_licenca', $novoStatus === 'cancelada' ? 'suspensa' : $novoStatus);
        }

        sgl_log($conn, 'Alterou status de licença SaaS', 'licencas_saas', (string)$licencaId, 'Novo status: ' . $novoStatus);
        sgl_redirect_cfg('administracao', 'sucesso', 'Status da licença atualizado.');
    } catch (Throwable $e) {
        sgl_redirect_cfg('administracao', 'erro', 'Não foi possível alterar o status da licença.');
    }
}

if ($acao_cfg === 'salvar_escritorio') {
    $campos = [
        'nome_escritorio' => 140,
        'razao_social_escritorio' => 180,
        'codigo_interno_escritorio' => 40,
        'responsavel_administrativo_escritorio' => 140,
        'email_administrativo_escritorio' => 140,
        'inscricao_estadual_escritorio' => 60,
        'inscricao_municipal_escritorio' => 60,
        'responsavel_escritorio' => 140,
        'oab_responsavel' => 60,
        'cpf_cnpj_escritorio' => 30,
        'cpf_responsavel' => 30,
        'telefone_escritorio' => 40,
        'celular_escritorio' => 40,
        'whatsapp_escritorio' => 40,
        'email_escritorio' => 140,
        'site_escritorio' => 160,
        'cep_escritorio' => 20,
        'endereco_escritorio' => 180,
        'numero_escritorio' => 30,
        'complemento_escritorio' => 100,
        'bairro_escritorio' => 100,
        'cidade_escritorio' => 100,
        'uf_escritorio' => 2,
        'pais_escritorio' => 60,
        'instagram_escritorio' => 160,
        'facebook_escritorio' => 160,
        'linkedin_escritorio' => 160,
        'rodape_documentos' => 255,
    ];
    foreach ($campos as $campo => $max) {
        $valor = sgl_limpar_texto((string)($_POST[$campo] ?? ''), $max);
        if (in_array($campo, ['email_escritorio', 'email_administrativo_escritorio'], true) && $valor !== '' && !filter_var($valor, FILTER_VALIDATE_EMAIL)) {
            sgl_redirect_cfg('escritorio', 'erro', 'Informe um endereço de e-mail válido.');
        }
        if ($campo === 'uf_escritorio') {
            $valor = strtoupper($valor);
        }
        if ($campo === 'codigo_interno_escritorio') {
            $valor = strtoupper(preg_replace('/[^a-zA-Z0-9._-]/', '', $valor));
        }
        sgl_cfg_set($conn, $campo, $valor);
    }

    $tipoEscritorio = (string)($_POST['tipo_escritorio'] ?? 'escritorio_advocacia');
    $tiposPermitidos = ['escritorio_advocacia', 'advogado_autonomo', 'departamento_juridico', 'consultoria_juridica', 'outro'];
    if (!in_array($tipoEscritorio, $tiposPermitidos, true)) {
        $tipoEscritorio = 'escritorio_advocacia';
    }

    $statusOperacional = (string)($_POST['status_operacional_escritorio'] ?? 'ativo');
    if (!in_array($statusOperacional, ['ativo', 'implantacao', 'suspenso'], true)) {
        $statusOperacional = 'ativo';
    }

    $dataInicio = trim((string)($_POST['data_inicio_atividades_escritorio'] ?? ''));
    if ($dataInicio !== '') {
        $dataObj = DateTime::createFromFormat('Y-m-d', $dataInicio);
        if (!$dataObj || $dataObj->format('Y-m-d') !== $dataInicio) {
            sgl_redirect_cfg('escritorio', 'erro', 'A data de início das atividades é inválida.');
        }
    }

    $timezone = (string)($_POST['timezone_escritorio'] ?? 'America/Sao_Paulo');
    $timezonesPermitidos = ['America/Sao_Paulo', 'America/Manaus', 'America/Cuiaba', 'America/Recife', 'America/Fortaleza', 'America/Belem', 'America/Rio_Branco', 'UTC'];
    if (!in_array($timezone, $timezonesPermitidos, true)) {
        $timezone = 'America/Sao_Paulo';
    }

    sgl_cfg_set($conn, 'tipo_escritorio', $tipoEscritorio);
    sgl_cfg_set($conn, 'status_operacional_escritorio', $statusOperacional);
    sgl_cfg_set($conn, 'data_inicio_atividades_escritorio', $dataInicio);
    sgl_cfg_set($conn, 'timezone_escritorio', $timezone);
    sgl_cfg_set($conn, 'idioma_escritorio', 'pt-BR');
    sgl_cfg_set($conn, 'moeda_escritorio', 'BRL');

    // Mantém compatibilidade com telas antigas que ainda usam chaves simples.
    sgl_cfg_set($conn, 'razao_social', sgl_limpar_texto((string)($_POST['razao_social_escritorio'] ?? ''), 180));
    sgl_cfg_set($conn, 'cnpj', sgl_limpar_texto((string)($_POST['cpf_cnpj_escritorio'] ?? ''), 30));
    sgl_cfg_set($conn, 'telefone', sgl_limpar_texto((string)($_POST['telefone_escritorio'] ?? ''), 40));
    sgl_cfg_set($conn, 'whatsapp', sgl_limpar_texto((string)($_POST['whatsapp_escritorio'] ?? ''), 40));
    sgl_cfg_set($conn, 'email', sgl_limpar_texto((string)($_POST['email_escritorio'] ?? ''), 140));
    sgl_cfg_set($conn, 'site', sgl_limpar_texto((string)($_POST['site_escritorio'] ?? ''), 160));

    sgl_log($conn, 'Atualizou dados do escritório', 'configuracoes', null, 'Aba Escritório');
    sgl_redirect_cfg('escritorio', 'sucesso', 'Dados do escritório salvos com sucesso.');
}

if ($acao_cfg === 'upload_logo' && isset($_FILES['logo'])) {
    try {
        $contextoMarca = rojex_marca_contexto_atual($ehUsuarioMaster);
        $file = $_FILES['logo'];
        $allowed = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
        ];
        $tmp = (string)($file['tmp_name'] ?? '');
        $tamanho = (int)($file['size'] ?? 0);

        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Erro no upload da logomarca.');
        }
        if (!is_uploaded_file($tmp) || $tamanho < 1 || $tamanho > 2 * 1024 * 1024) {
            throw new RuntimeException('A logomarca deve ser uma imagem válida de até 2 MB.');
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = (string)$finfo->file($tmp);
        if (!isset($allowed[$mime]) || @getimagesize($tmp) === false) {
            throw new RuntimeException('Use somente imagens JPG, PNG ou WebP válidas.');
        }

        if (!is_dir($upload_marca_dir) && !@mkdir($upload_marca_dir, 0755, true)) {
            throw new RuntimeException('Não foi possível preparar a pasta das logomarcas.');
        }

        $prefixo = rojex_marca_prefixo_arquivo($contextoMarca);
        $sufixo = bin2hex(random_bytes(6));
        $nomeArquivo = $prefixo . '_' . $sufixo . '.' . $allowed[$mime];
        $caminhoFinal = $upload_marca_dir . $nomeArquivo;
        $valorBanco = 'branding/' . $nomeArquivo;

        if (!move_uploaded_file($tmp, $caminhoFinal)) {
            throw new RuntimeException(
                'Falha ao salvar a imagem. Verifique as permissões de assets/img/branding.'
            );
        }

        try {
            rojex_marca_cfg_set($conn, $contextoMarca, 'logo_arquivo', $valorBanco);
        } catch (Throwable $e) {
            @unlink($caminhoFinal);
            throw $e;
        }

        foreach (glob($upload_marca_dir . $prefixo . '_*') ?: [] as $arquivoAntigo) {
            if ($arquivoAntigo !== $caminhoFinal && is_file($arquivoAntigo)) {
                @unlink($arquivoAntigo);
            }
        }

        sgl_log(
            $conn,
            'Atualizou logomarca Multi-Tenant',
            'escritorios_configuracoes_saas',
            (string)($contextoMarca['escritorio_id'] ?? 0),
            'Escopo: ' . (string)$contextoMarca['tipo']
        );
        sgl_redirect_cfg('marca', 'sucesso', 'Logomarca deste ambiente atualizada com sucesso.');
    } catch (Throwable $e) {
        error_log('[ROJEX MARCA][UPLOAD] ' . $e->getMessage());
        $mensagensUploadSeguras = [
            'Erro no upload da logomarca.',
            'A logomarca deve ser uma imagem válida de até 2 MB.',
            'Use somente imagens JPG, PNG ou WebP válidas.',
            'Não foi possível preparar a pasta das logomarcas.',
            'Falha ao salvar a imagem. Verifique as permissões de assets/img/branding.',
            'Somente o MASTER pode alterar a identidade visual da plataforma.',
            'Contexto Multi-Tenant inválido para alterar a identidade visual.',
            'Tenant ou escritório não identificado para a identidade visual.',
        ];
        $mensagemUpload = in_array($e->getMessage(), $mensagensUploadSeguras, true)
            ? $e->getMessage()
            : 'Não foi possível salvar a logomarca com segurança.';
        sgl_redirect_cfg('marca', 'erro', $mensagemUpload);
    }
}

if ($acao_cfg === 'remover_logo') {
    try {
        $contextoMarca = rojex_marca_contexto_atual($ehUsuarioMaster);
        $prefixo = rojex_marca_prefixo_arquivo($contextoMarca);

        rojex_marca_cfg_delete($conn, $contextoMarca, 'logo_arquivo');
        foreach (glob($upload_marca_dir . $prefixo . '_*') ?: [] as $arquivoMarca) {
            if (is_file($arquivoMarca)) {
                @unlink($arquivoMarca);
            }
        }

        sgl_log(
            $conn,
            'Removeu logomarca Multi-Tenant',
            'escritorios_configuracoes_saas',
            (string)($contextoMarca['escritorio_id'] ?? 0),
            'Escopo: ' . (string)$contextoMarca['tipo']
        );
        sgl_redirect_cfg('marca', 'aviso', 'Logo personalizada deste ambiente removida.');
    } catch (Throwable $e) {
        error_log('[ROJEX MARCA][REMOCAO] ' . $e->getMessage());
        sgl_redirect_cfg('marca', 'erro', 'Não foi possível remover a logomarca com segurança.');
    }
}

if ($acao_cfg === 'salvar_marca') {
    $nomeMarca = sgl_limpar_texto((string)($_POST['nome_marca_exibicao'] ?? ''), 80);
    $sloganMarca = sgl_limpar_texto((string)($_POST['slogan_marca'] ?? ''), 160);
    $posicaoLogo = in_array(($_POST['posicao_logo'] ?? 'esquerda'), ['esquerda','centro'], true)
        ? (string)$_POST['posicao_logo']
        : 'esquerda';

    try {
        $contextoMarca = rojex_marca_contexto_atual($ehUsuarioMaster);
        foreach ([
            'nome_marca_exibicao' => $nomeMarca,
            'slogan_marca' => $sloganMarca,
            'posicao_logo' => $posicaoLogo,
            'exibir_nome_menu' => !empty($_POST['exibir_nome_menu']) ? '1' : '0',
            'exibir_slogan_documentos' => !empty($_POST['exibir_slogan_documentos']) ? '1' : '0',
        ] as $chaveMarca => $valorMarca) {
            rojex_marca_cfg_set($conn, $contextoMarca, $chaveMarca, $valorMarca);
        }

        sgl_log(
            $conn,
            'Atualizou configurações da marca Multi-Tenant',
            'escritorios_configuracoes_saas',
            (string)($contextoMarca['escritorio_id'] ?? 0),
            'Escopo: ' . (string)$contextoMarca['tipo']
        );
        sgl_redirect_cfg('marca', 'sucesso', 'Configurações da marca deste ambiente salvas.');
    } catch (Throwable $e) {
        error_log('[ROJEX MARCA][CONFIGURACAO] ' . $e->getMessage());
        sgl_redirect_cfg('marca', 'erro', 'Não foi possível salvar a marca com segurança.');
    }
}

if ($acao_cfg === 'salvar_tema') {
    if ($usuarioSessaoId <= 0) {
        sgl_redirect_cfg('tema', 'erro', 'Sessão de usuário inválida. Entre novamente no sistema.');
    }

    $modoTema = in_array(($_POST['tema_modo'] ?? 'claro'), ['claro','escuro','automatico'], true)
        ? (string)$_POST['tema_modo']
        : 'claro';
    $densidade = in_array(($_POST['tema_densidade'] ?? 'confortavel'), ['compacta','confortavel'], true)
        ? (string)$_POST['tema_densidade']
        : 'confortavel';
    $bordas = in_array(($_POST['tema_bordas'] ?? 'suaves'), ['retas','suaves','arredondadas'], true)
        ? (string)$_POST['tema_bordas']
        : 'suaves';
    $fonte = max(90, min(115, (int)($_POST['tema_fonte_percentual'] ?? 100)));

    if (!rojex_usuario_preferencias_set(
        $conn,
        $usuarioSessaoId,
        $modoTema,
        $densidade,
        $bordas,
        $fonte
    )) {
        sgl_redirect_cfg(
            'tema',
            'erro',
            'Não foi possível salvar suas preferências. Confirme a migração 4.4.2.'
        );
    }

    $detalhesLog = "Modo: {$modoTema}; Densidade: {$densidade}; Bordas: {$bordas}; Fonte: {$fonte}%.";

    if ($ehUsuarioMaster) {
        sgl_cfg_set($conn, 'cor_primaria', sgl_validar_hex((string)($_POST['cor_primaria'] ?? ''), '#1a3c5e'));
        sgl_cfg_set($conn, 'cor_secundaria', sgl_validar_hex((string)($_POST['cor_secundaria'] ?? ''), '#2c6fad'));
        sgl_cfg_set($conn, 'cor_accent', sgl_validar_hex((string)($_POST['cor_accent'] ?? ''), '#f0a500'));
        sgl_cfg_set($conn, 'cor_fundo', sgl_validar_hex((string)($_POST['cor_fundo'] ?? ''), '#f4f6f9'));
        sgl_cfg_set($conn, 'cor_texto', sgl_validar_hex((string)($_POST['cor_texto'] ?? ''), '#212529'));

        $detalhesLog .= ' Identidade institucional atualizada pelo MASTER.';
        sgl_log(
            $conn,
            'Atualizou tema e identidade visual Enterprise',
            'usuarios_preferencias',
            (string)$usuarioSessaoId,
            $detalhesLog
        );
        sgl_redirect_cfg('tema', 'sucesso', 'Identidade institucional e suas preferências foram salvas.');
    }

    sgl_log(
        $conn,
        'Atualizou preferências visuais individuais',
        'usuarios_preferencias',
        (string)$usuarioSessaoId,
        $detalhesLog
    );
    sgl_redirect_cfg('tema', 'sucesso', 'Suas preferências visuais foram salvas.');
}

if ($acao_cfg === 'restaurar_tema') {
    if ($usuarioSessaoId <= 0) {
        sgl_redirect_cfg('tema', 'erro', 'Sessão de usuário inválida. Entre novamente no sistema.');
    }

    if (!rojex_usuario_preferencias_reset($conn, $usuarioSessaoId)) {
        sgl_redirect_cfg(
            'tema',
            'erro',
            'Não foi possível restaurar suas preferências. Confirme a migração 4.4.2.'
        );
    }

    if ($ehUsuarioMaster) {
        foreach ([
            'cor_primaria' => '#1a3c5e',
            'cor_secundaria' => '#2c6fad',
            'cor_accent' => '#f0a500',
            'cor_fundo' => '#f4f6f9',
            'cor_texto' => '#212529',
        ] as $chaveTema => $valorTema) {
            sgl_cfg_set($conn, $chaveTema, $valorTema);
        }

        sgl_log(
            $conn,
            'Restaurou tema institucional e preferências individuais',
            'usuarios_preferencias',
            (string)$usuarioSessaoId
        );
        sgl_redirect_cfg('tema', 'aviso', 'Tema institucional e suas preferências foram restaurados.');
    }

    sgl_log(
        $conn,
        'Restaurou preferências visuais individuais',
        'usuarios_preferencias',
        (string)$usuarioSessaoId
    );
    sgl_redirect_cfg('tema', 'aviso', 'Suas preferências visuais foram restauradas.');
}

if ($acao_cfg === 'novo_usuario') {
    $nome = sgl_limpar_texto((string)($_POST['nome'] ?? ''), 120);
    $usuario = preg_replace('/[^a-zA-Z0-9._-]/', '', (string)($_POST['usuario'] ?? ''));
    $email = sgl_limpar_texto((string)($_POST['email'] ?? ''), 120);
    $telefone = sgl_limpar_texto((string)($_POST['telefone'] ?? ''), 40);
    $cargo = sgl_limpar_texto((string)($_POST['cargo'] ?? ''), 100);
    $departamento = sgl_limpar_texto((string)($_POST['departamento'] ?? ''), 100);
    $observacoes = sgl_limpar_texto((string)($_POST['observacoes'] ?? ''), 1000);
    $perfil = (string)($_POST['perfil'] ?? 'Usuário');
    $senha = (string)($_POST['senha'] ?? '');

    $perfis = [
        'Administrador Master','Administrador','Advogado','Coordenador',
        'Financeiro','Atendente','Estagiário','Consulta','Auditor','Usuário'
    ];

    if ($nome === '' || $usuario === '' || strlen($senha) < 6 || !in_array($perfil, $perfis, true)) {
        sgl_redirect_cfg('usuarios', 'erro', 'Preencha nome, usuário, perfil e senha com no mínimo 6 caracteres.');
    }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        sgl_redirect_cfg('usuarios', 'erro', 'E-mail do usuário inválido.');
    }

    $limiteUsuarios = max(1, min(100, (int)sgl_cfg_get($conn, 'limite_usuarios_licenca', '100')));
    $totalUsuariosAtuais = sgl_select_count($conn, "SELECT COUNT(*) AS total FROM usuarios");
    if ($totalUsuariosAtuais >= $limiteUsuarios) {
        sgl_redirect_cfg('usuarios', 'erro', "Limite da licença atingido: {$limiteUsuarios} usuário(s).");
    }

    try {
        $stmtDup = $conn->prepare("SELECT id FROM usuarios WHERE usuario = ? OR (? <> '' AND email = ?) LIMIT 1");
        $stmtDup->bind_param('sss', $usuario, $email, $email);
        $stmtDup->execute();
        $duplicado = $stmtDup->get_result()->fetch_assoc();
        $stmtDup->close();

        if ($duplicado) {
            sgl_redirect_cfg('usuarios', 'erro', 'Já existe usuário com este login ou e-mail.');
        }

        $hash = password_hash($senha, PASSWORD_DEFAULT);

        $colunasExtras = [];
        $valoresExtras = [];
        $tiposExtras = '';

        foreach ([
            'telefone' => [$telefone, 's'],
            'cargo' => [$cargo, 's'],
            'departamento' => [$departamento, 's'],
            'observacoes' => [$observacoes, 's'],
        ] as $colunaExtra => $dadosExtra) {
            if (sgl_coluna_existe($conn, 'usuarios', $colunaExtra)) {
                $colunasExtras[] = $colunaExtra;
                $valoresExtras[] = $dadosExtra[0];
                $tiposExtras .= $dadosExtra[1];
            }
        }

        $colunasSql = "nome, usuario, email, senha, perfil, ativo";
        $placeholders = "?, ?, ?, ?, ?, 1";
        if ($colunasExtras) {
            $colunasSql .= ", " . implode(", ", $colunasExtras);
            $placeholders .= ", " . implode(", ", array_fill(0, count($colunasExtras), "?"));
        }

        $stmt = $conn->prepare("INSERT INTO usuarios ($colunasSql) VALUES ($placeholders)");
        $tipos = 'sssss' . $tiposExtras;
        $valores = array_merge([$nome, $usuario, $email, $hash, $perfil], $valoresExtras);
        $stmt->bind_param($tipos, ...$valores);
        $stmt->execute();
        $novoId = (string)$stmt->insert_id;
        $stmt->close();

        sgl_log(
            $conn,
            'Criou usuário Enterprise',
            'usuarios',
            $novoId,
            "Login: {$usuario}; Perfil: {$perfil}; Departamento: " . ($departamento ?: '-')
        );
        sgl_redirect_cfg('usuarios', 'sucesso', 'Usuário criado com sucesso.');
    } catch (Throwable $e) {
        sgl_redirect_cfg('usuarios', 'erro', 'Não foi possível criar o usuário. Verifique os dados informados.');
    }
}

if ($acao_cfg === 'editar_usuario' && !empty($_POST['usuario_id'])) {
    $id = (int)$_POST['usuario_id'];
    $nome = sgl_limpar_texto((string)($_POST['nome'] ?? ''), 120);
    $email = sgl_limpar_texto((string)($_POST['email'] ?? ''), 120);
    $telefone = sgl_limpar_texto((string)($_POST['telefone'] ?? ''), 40);
    $cargo = sgl_limpar_texto((string)($_POST['cargo'] ?? ''), 100);
    $departamento = sgl_limpar_texto((string)($_POST['departamento'] ?? ''), 100);
    $observacoes = sgl_limpar_texto((string)($_POST['observacoes'] ?? ''), 1000);
    $perfil = (string)($_POST['perfil'] ?? 'Usuário');

    $perfis = [
        'Administrador Master','Administrador','Advogado','Coordenador',
        'Financeiro','Atendente','Estagiário','Consulta','Auditor','Usuário'
    ];

    if ($nome === '' || !in_array($perfil, $perfis, true)) {
        sgl_redirect_cfg('usuarios', 'erro', 'Nome ou perfil inválido.');
    }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        sgl_redirect_cfg('usuarios', 'erro', 'E-mail do usuário inválido.');
    }

    try {
        $stmtAtual = $conn->prepare("SELECT perfil FROM usuarios WHERE id = ? LIMIT 1");
        $stmtAtual->bind_param('i', $id);
        $stmtAtual->execute();
        $usuarioAtual = $stmtAtual->get_result()->fetch_assoc();
        $stmtAtual->close();

        if (!$usuarioAtual) {
            sgl_redirect_cfg('usuarios', 'erro', 'Usuário não encontrado.');
        }

        $perfilAtual = (string)$usuarioAtual['perfil'];
        $ehAdminAtual = in_array($perfilAtual, ['Administrador', 'Administrador Master'], true);
        $seraAdmin = in_array($perfil, ['Administrador', 'Administrador Master'], true);

        if ($ehAdminAtual && !$seraAdmin) {
            $totalAdminsAtivos = sgl_select_count(
                $conn,
                "SELECT COUNT(*) AS total FROM usuarios WHERE ativo = 1 AND perfil IN ('Administrador','Administrador Master')"
            );
            if ($totalAdminsAtivos <= 1) {
                sgl_redirect_cfg('usuarios', 'erro', 'Não é possível remover o perfil do último administrador ativo.');
            }
        }

        $stmtDup = $conn->prepare("SELECT id FROM usuarios WHERE id <> ? AND ? <> '' AND email = ? LIMIT 1");
        $stmtDup->bind_param('iss', $id, $email, $email);
        $stmtDup->execute();
        $duplicado = $stmtDup->get_result()->fetch_assoc();
        $stmtDup->close();

        if ($duplicado) {
            sgl_redirect_cfg('usuarios', 'erro', 'Este e-mail já está vinculado a outro usuário.');
        }

        $sets = ["nome = ?", "email = ?", "perfil = ?"];
        $tipos = "sss";
        $valores = [$nome, $email, $perfil];

        foreach ([
            'telefone' => $telefone,
            'cargo' => $cargo,
            'departamento' => $departamento,
            'observacoes' => $observacoes,
        ] as $colunaExtra => $valorExtra) {
            if (sgl_coluna_existe($conn, 'usuarios', $colunaExtra)) {
                $sets[] = "`$colunaExtra` = ?";
                $tipos .= 's';
                $valores[] = $valorExtra;
            }
        }

        if (sgl_coluna_existe($conn, 'usuarios', 'atualizado_em')) {
            $sets[] = "atualizado_em = NOW()";
        }

        $tipos .= 'i';
        $valores[] = $id;

        $stmt = $conn->prepare("UPDATE usuarios SET " . implode(', ', $sets) . " WHERE id = ?");
        $stmt->bind_param($tipos, ...$valores);
        $stmt->execute();
        $stmt->close();

        sgl_log(
            $conn,
            'Atualizou usuário Enterprise',
            'usuarios',
            (string)$id,
            "Perfil anterior: {$perfilAtual}; Novo perfil: {$perfil}; Departamento: " . ($departamento ?: '-')
        );
        sgl_redirect_cfg('usuarios', 'sucesso', 'Dados do usuário atualizados.');
    } catch (Throwable $e) {
        sgl_redirect_cfg('usuarios', 'erro', 'Não foi possível atualizar o usuário.');
    }
}

if ($acao_cfg === 'alterar_status_usuario' && !empty($_POST['usuario_id'])) {
    $id = (int)$_POST['usuario_id'];
    $ativo = ((int)($_POST['ativo'] ?? 1) === 1) ? 1 : 0;

    if ($id === (int)($_SESSION['user_id'] ?? 0) && $ativo === 0) {
        sgl_redirect_cfg('usuarios', 'erro', 'Você não pode desativar o próprio usuário logado.');
    }

    try {
        $stmtPerfil = $conn->prepare("SELECT perfil FROM usuarios WHERE id = ? LIMIT 1");
        $stmtPerfil->bind_param('i', $id);
        $stmtPerfil->execute();
        $usuarioAlvo = $stmtPerfil->get_result()->fetch_assoc();
        $stmtPerfil->close();

        if (!$usuarioAlvo) {
            sgl_redirect_cfg('usuarios', 'erro', 'Usuário não encontrado.');
        }

        if ($ativo === 0 && in_array($usuarioAlvo['perfil'], ['Administrador','Administrador Master'], true)) {
            $totalAdminsAtivos = sgl_select_count(
                $conn,
                "SELECT COUNT(*) AS total FROM usuarios WHERE ativo = 1 AND perfil IN ('Administrador','Administrador Master')"
            );
            if ($totalAdminsAtivos <= 1) {
                sgl_redirect_cfg('usuarios', 'erro', 'Não é possível desativar o último administrador ativo.');
            }
        }

        $sqlAtualizacao = sgl_coluna_existe($conn, 'usuarios', 'atualizado_em')
            ? "UPDATE usuarios SET ativo = ?, atualizado_em = NOW() WHERE id = ?"
            : "UPDATE usuarios SET ativo = ? WHERE id = ?";

        $stmt = $conn->prepare($sqlAtualizacao);
        $stmt->bind_param('ii', $ativo, $id);
        $stmt->execute();
        $stmt->close();

        sgl_log($conn, $ativo ? 'Ativou usuário' : 'Desativou usuário', 'usuarios', (string)$id);
        sgl_redirect_cfg('usuarios', 'sucesso', 'Status do usuário atualizado.');
    } catch (Throwable $e) {
        sgl_redirect_cfg('usuarios', 'erro', 'Não foi possível alterar o status do usuário.');
    }
}

if ($acao_cfg === 'encerrar_vinculo_usuario' && !empty($_POST['usuario_id'])) {
    $id = (int)$_POST['usuario_id'];

    if ($id === $usuarioSessaoId || $id === $usuarioMasterId) {
        sgl_redirect_cfg('usuarios', 'erro', 'O usuário MASTER não pode ter o vínculo encerrado.');
    }

    try {
        $stmt = $conn->prepare("SELECT id, nome, usuario, perfil, ativo FROM usuarios WHERE id = ? LIMIT 1");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $alvo = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$alvo) {
            sgl_redirect_cfg('usuarios', 'erro', 'Usuário não encontrado.');
        }

        if (!sgl_registrar_historico_usuario($conn, $id, 'ENCERRAMENTO_DE_VINCULO')) {
            sgl_redirect_cfg('usuarios', 'erro', 'Não foi possível criar o histórico de segurança. Nenhuma alteração foi realizada.');
        }

        $sets = ["ativo = 0"];
        if (sgl_coluna_existe($conn, 'usuarios', 'vinculo_status')) {
            $sets[] = "vinculo_status = 'encerrado'";
        }
        if (sgl_coluna_existe($conn, 'usuarios', 'desligado_em')) {
            $sets[] = "desligado_em = NOW()";
        }
        if (sgl_coluna_existe($conn, 'usuarios', 'desligado_por')) {
            $sets[] = "desligado_por = " . (int)$usuarioSessaoId;
        }
        if (sgl_coluna_existe($conn, 'usuarios', 'atualizado_em')) {
            $sets[] = "atualizado_em = NOW()";
        }

        $stmt = $conn->prepare("UPDATE usuarios SET " . implode(', ', $sets) . " WHERE id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->close();

        sgl_log(
            $conn,
            'Encerrou vínculo de usuário',
            'usuarios',
            (string)$id,
            'Cadastro preservado integralmente em usuarios_historico para auditoria e prova futura.'
        );
        sgl_redirect_cfg('usuarios', 'aviso', 'Vínculo encerrado. O cadastro e o histórico foram preservados.');
    } catch (Throwable $e) {
        sgl_redirect_cfg('usuarios', 'erro', 'Não foi possível encerrar o vínculo do usuário.');
    }
}

if ($acao_cfg === 'resetar_senha_usuario' && !empty($_POST['usuario_id'])) {
    $id = (int)$_POST['usuario_id'];
    $nova = (string)($_POST['nova_senha'] ?? '');

    // Política atual preservada conforme decisão do projeto.
    if (strlen($nova) < 6) {
        sgl_redirect_cfg('usuarios', 'erro', 'A nova senha deve ter no mínimo 6 caracteres.');
    }

    try {
        $hash = password_hash($nova, PASSWORD_DEFAULT);
        $sqlAtualizacao = sgl_coluna_existe($conn, 'usuarios', 'atualizado_em')
            ? "UPDATE usuarios SET senha = ?, atualizado_em = NOW() WHERE id = ?"
            : "UPDATE usuarios SET senha = ? WHERE id = ?";

        $stmt = $conn->prepare($sqlAtualizacao);
        $stmt->bind_param('si', $hash, $id);
        $stmt->execute();
        $stmt->close();

        sgl_log($conn, 'Redefiniu senha de usuário', 'usuarios', (string)$id);
        sgl_redirect_cfg('usuarios', 'sucesso', 'Senha redefinida com sucesso.');
    } catch (Throwable $e) {
        sgl_redirect_cfg('usuarios', 'erro', 'Não foi possível redefinir a senha.');
    }
}

if ($acao_cfg === 'salvar_sistema') {
    $modoDebug = !empty($_POST['modo_debug']) ? '1' : '0';
    $diasAlerta = max(1, min(60, (int)($_POST['dias_alerta_prazos'] ?? 7)));
    $itensPagina = max(10, min(100, (int)($_POST['itens_por_pagina'] ?? 25)));

    $ambiente = (string)($_POST['ambiente_sistema'] ?? 'desenvolvimento');
    if (!in_array($ambiente, ['desenvolvimento','homologacao','producao'], true)) {
        $ambiente = 'desenvolvimento';
    }

    $plano = (string)($_POST['plano_licenca'] ?? 'enterprise');
    if (!in_array($plano, ['starter','professional','enterprise'], true)) {
        $plano = 'enterprise';
    }

    $statusLicenca = (string)($_POST['status_licenca'] ?? 'ativa');
    if (!in_array($statusLicenca, ['ativa','teste','suspensa','expirada'], true)) {
        $statusLicenca = 'ativa';
    }

    $statusIa = (string)($_POST['status_integracao_ia'] ?? 'desativada');
    if (!in_array($statusIa, ['desativada','preparada','ativa'], true)) {
        $statusIa = 'desativada';
    }

    $provedorIa = (string)($_POST['provedor_ia'] ?? 'nao_definido');
    if (!in_array($provedorIa, ['nao_definido','openai','anthropic','google','deepseek','outro'], true)) {
        $provedorIa = 'nao_definido';
    }

    $limiteUsuarios = max(1, min(100, (int)($_POST['limite_usuarios_licenca'] ?? 100)));
    $limiteArmazenamento = max(1, min(10000, (int)($_POST['limite_armazenamento_gb'] ?? 50)));

    $dataAtivacao = trim((string)($_POST['data_ativacao_licenca'] ?? ''));
    $dataRenovacao = trim((string)($_POST['data_renovacao_licenca'] ?? ''));

    foreach ([$dataAtivacao, $dataRenovacao] as $dataLicenca) {
        if ($dataLicenca !== '') {
            $objData = DateTime::createFromFormat('Y-m-d', $dataLicenca);
            if (!$objData || $objData->format('Y-m-d') !== $dataLicenca) {
                sgl_redirect_cfg('sistema', 'erro', 'Informe datas válidas para a licença.');
            }
        }
    }

    $dominio = strtolower(sgl_limpar_texto((string)($_POST['dominio_saas'] ?? ''), 180));
    $subdominio = strtolower(preg_replace('/[^a-zA-Z0-9.-]/', '', (string)($_POST['subdominio_saas'] ?? '')));
    $versaoSistema = sgl_limpar_texto((string)($_POST['versao_sistema'] ?? '4.1.3'), 30);
    $versaoBanco = sgl_limpar_texto((string)($_POST['versao_banco'] ?? '1.0'), 30);

    $recursosPermitidos = [
        'recurso_portal_cliente',
        'recurso_assinatura_digital',
        'recurso_whatsapp',
        'recurso_email_automatico',
        'recurso_cnj',
        'recurso_bi',
        'recurso_cij',
        'recurso_ia',
    ];

    $configSistema = [
        'modo_debug' => $modoDebug,
        'dias_alerta_prazos' => (string)$diasAlerta,
        'itens_por_pagina' => (string)$itensPagina,
        'ambiente_sistema' => $ambiente,
        'versao_sistema' => $versaoSistema,
        'versao_banco' => $versaoBanco,
        'plano_licenca' => $plano,
        'status_licenca' => $statusLicenca,
        'data_ativacao_licenca' => $dataAtivacao,
        'data_renovacao_licenca' => $dataRenovacao,
        'limite_usuarios_licenca' => (string)$limiteUsuarios,
        'limite_armazenamento_gb' => (string)$limiteArmazenamento,
        'dominio_saas' => $dominio,
        'subdominio_saas' => $subdominio,
        'status_integracao_ia' => $statusIa,
        'provedor_ia' => $provedorIa,
        'modo_manutencao_preparado' => !empty($_POST['modo_manutencao_preparado']) ? '1' : '0',
        'cache_aplicacao_preparado' => !empty($_POST['cache_aplicacao_preparado']) ? '1' : '0',
        'backup_automatico_preparado' => !empty($_POST['backup_automatico_preparado']) ? '1' : '0',
    ];

    foreach ($recursosPermitidos as $recurso) {
        $configSistema[$recurso] = !empty($_POST[$recurso]) ? '1' : '0';
    }

    foreach ($configSistema as $chaveSistema => $valorSistema) {
        sgl_cfg_set($conn, $chaveSistema, $valorSistema);
    }

    sgl_log(
        $conn,
        'Atualizou Sistema Enterprise e preparação SaaS',
        'configuracoes',
        null,
        "Ambiente: {$ambiente}; Plano: {$plano}; Licença: {$statusLicenca}; Limite de usuários: {$limiteUsuarios}; IA: {$statusIa}"
    );

    sgl_redirect_cfg('sistema', 'sucesso', 'Configurações do Sistema Enterprise salvas com sucesso.');
}

$lixeira_permitidas = ['advogados','clientes','processos','agenda','honorarios','contas_pagar','contas_receber','documentos_arquivos','modelos_documentos','recibos'];

if ($acao_cfg === 'restaurar_item_lixeira' && !empty($_POST['tabela']) && !empty($_POST['item_id'])) {
    $item = sgl_lixeira_item_valido((string)$_POST['tabela'] . '|' . (string)$_POST['item_id'], $lixeira_permitidas);
    if (!$item) sgl_redirect_cfg('lixeira', 'erro', 'Registro inválido para restauração.');

    if (sgl_lixeira_restaurar($conn, $item['tabela'], $item['id'])) {
        sgl_log($conn, 'Restaurou item da lixeira', $item['tabela'], $item['id']);
        sgl_redirect_cfg('lixeira', 'sucesso', 'Registro restaurado com sucesso.');
    }

    sgl_redirect_cfg('lixeira', 'erro', 'Não foi possível restaurar o registro.');
}

if ($acao_cfg === 'excluir_item_lixeira' && !empty($_POST['tabela']) && !empty($_POST['item_id'])) {
    $item = sgl_lixeira_item_valido((string)$_POST['tabela'] . '|' . (string)$_POST['item_id'], $lixeira_permitidas);
    if (!$item) sgl_redirect_cfg('lixeira', 'erro', 'Registro inválido para exclusão.');

    if (sgl_lixeira_excluir($conn, $item['tabela'], $item['id'])) {
        sgl_log($conn, 'Excluiu definitivamente item da lixeira', $item['tabela'], $item['id']);
        sgl_redirect_cfg('lixeira', 'aviso', 'Item excluído permanentemente.');
    }

    sgl_redirect_cfg('lixeira', 'erro', 'Não foi possível excluir o item. Ele pode possuir vínculos com outros registros.');
}

if ($acao_cfg === 'acao_lixeira_lote') {
    $acaoLote = (string)($_POST['acao_lote'] ?? '');
    $selecionados = $_POST['itens'] ?? [];

    if (!is_array($selecionados) || empty($selecionados)) {
        sgl_redirect_cfg('lixeira', 'erro', 'Selecione ao menos um item.');
    }
    if (!in_array($acaoLote, ['restaurar', 'excluir'], true)) {
        sgl_redirect_cfg('lixeira', 'erro', 'Ação em lote inválida.');
    }

    $sucesso = 0;
    $falhas = 0;

    foreach ($selecionados as $valor) {
        $item = sgl_lixeira_item_valido((string)$valor, $lixeira_permitidas);
        if (!$item) { $falhas++; continue; }

        $ok = $acaoLote === 'restaurar'
            ? sgl_lixeira_restaurar($conn, $item['tabela'], $item['id'])
            : sgl_lixeira_excluir($conn, $item['tabela'], $item['id']);

        if ($ok) {
            $sucesso++;
            sgl_log(
                $conn,
                $acaoLote === 'restaurar' ? 'Restaurou item da lixeira em lote' : 'Excluiu definitivamente item da lixeira em lote',
                $item['tabela'],
                $item['id']
            );
        } else {
            $falhas++;
        }
    }

    $mensagemLote = "{$sucesso} item(ns) processado(s) com sucesso";
    if ($falhas > 0) {
        sgl_redirect_cfg('lixeira', 'aviso', $mensagemLote . " e {$falhas} falha(s).");
    }
    sgl_redirect_cfg('lixeira', 'sucesso', $mensagemLote . '.');
}

if ($acao_cfg === 'esvaziar_lixeira') {
    if (strtoupper(trim((string)($_POST['confirmacao'] ?? ''))) !== 'ESVAZIAR') {
        sgl_redirect_cfg('lixeira', 'erro', 'Confirmação inválida. Digite ESVAZIAR.');
    }

    $todos = sgl_buscar_lixeira($conn);
    $sucesso = 0;
    $falhas = 0;

    foreach ($todos as $registro) {
        $item = sgl_lixeira_item_valido($registro['tabela'] . '|' . $registro['id'], $lixeira_permitidas);
        if (!$item) { $falhas++; continue; }

        if (sgl_lixeira_excluir($conn, $item['tabela'], $item['id'])) {
            $sucesso++;
            sgl_log($conn, 'Esvaziou item da lixeira', $item['tabela'], $item['id'], 'Exclusão permanente por esvaziamento da lixeira.');
        } else {
            $falhas++;
        }
    }

    sgl_log($conn, 'Executou esvaziamento da lixeira', 'lixeira', null, "Sucessos: {$sucesso}; Falhas: {$falhas}");

    if ($falhas > 0) {
        sgl_redirect_cfg('lixeira', 'aviso', "Lixeira processada: {$sucesso} excluído(s) e {$falhas} falha(s) por vínculos.");
    }

    sgl_redirect_cfg('lixeira', 'aviso', "Lixeira esvaziada. {$sucesso} item(ns) excluído(s) permanentemente.");
}

// -----------------------------------------------------------------------------
// Dados para tela
// -----------------------------------------------------------------------------
$config_padrao = [
    'nome_escritorio' => 'SGL Advocacia',
    'razao_social_escritorio' => '',
    'identificador_escritorio' => '',
    'codigo_interno_escritorio' => '',
    'tipo_escritorio' => 'escritorio_advocacia',
    'status_operacional_escritorio' => 'ativo',
    'data_inicio_atividades_escritorio' => '',
    'responsavel_administrativo_escritorio' => '',
    'email_administrativo_escritorio' => '',
    'timezone_escritorio' => 'America/Sao_Paulo',
    'idioma_escritorio' => 'pt-BR',
    'moeda_escritorio' => 'BRL',
    'inscricao_estadual_escritorio' => '',
    'inscricao_municipal_escritorio' => '',
    'responsavel_escritorio' => '',
    'oab_responsavel' => '',
    'cpf_cnpj_escritorio' => '',
    'cpf_responsavel' => '',
    'telefone_escritorio' => '',
    'celular_escritorio' => '',
    'whatsapp_escritorio' => '',
    'email_escritorio' => '',
    'site_escritorio' => '',
    'cep_escritorio' => '',
    'endereco_escritorio' => '',
    'numero_escritorio' => '',
    'complemento_escritorio' => '',
    'bairro_escritorio' => '',
    'cidade_escritorio' => '',
    'uf_escritorio' => '',
    'pais_escritorio' => 'Brasil',
    'instagram_escritorio' => '',
    'facebook_escritorio' => '',
    'linkedin_escritorio' => '',
    'rodape_documentos' => 'Documento emitido pelo ROJEX.AI',
    'nome_marca_exibicao' => '',
    'slogan_marca' => '',
    'posicao_logo' => 'esquerda',
    'exibir_nome_menu' => '1',
    'exibir_slogan_documentos' => '0',
    'cor_primaria' => '#1a3c5e',
    'cor_secundaria' => '#2c6fad',
    'cor_accent' => '#f0a500',
    'cor_fundo' => '#f4f6f9',
    'cor_texto' => '#212529',
    'modo_debug' => '0',
    'dias_alerta_prazos' => '7',
    'itens_por_pagina' => '25',
    'logo_arquivo' => '',
    'limite_usuarios_licenca' => '100',
    'identificador_instalacao' => '',
    'tenant_id' => '',
    'chave_instalacao' => '',
    'ambiente_sistema' => 'desenvolvimento',
    'versao_sistema' => '4.1.3',
    'sprint_atual' => '4.1.3',
    'status_homologacao' => 'homologada',
    'data_homologacao' => '',
    'homologacao_sprint_4_1_3' => '0',
    'versao_banco' => '1.0',
    'plano_licenca' => 'enterprise',
    'status_licenca' => 'ativa',
    'data_ativacao_licenca' => '',
    'data_renovacao_licenca' => '',
    'limite_armazenamento_gb' => '50',
    'dominio_saas' => '',
    'subdominio_saas' => '',
    'status_integracao_ia' => 'desativada',
    'provedor_ia' => 'nao_definido',
    'modo_manutencao_preparado' => '0',
    'cache_aplicacao_preparado' => '0',
    'backup_automatico_preparado' => '0',
    'recurso_portal_cliente' => '1',
    'recurso_assinatura_digital' => '0',
    'recurso_whatsapp' => '1',
    'recurso_email_automatico' => '1',
    'recurso_cnj' => '0',
    'recurso_bi' => '0',
    'recurso_cij' => '1',
    'recurso_ia' => '0',
];

$cfg = [];
foreach ($config_padrao as $chave => $default) {
    $cfg[$chave] = sgl_cfg_get($conn, $chave, $default);
}

// Identidade visual institucional: MASTER usa o escopo da plataforma e cada
// escritório usa somente sua linha em escritorios_configuracoes_saas.
$contextoMarcaTela = null;
try {
    $contextoMarcaTela = rojex_marca_contexto_atual($ehUsuarioMaster);
    foreach ([
        'logo_arquivo',
        'nome_marca_exibicao',
        'slogan_marca',
        'posicao_logo',
        'exibir_nome_menu',
        'exibir_slogan_documentos',
    ] as $chaveMarcaTela) {
        $cfg[$chaveMarcaTela] = rojex_marca_cfg_get(
            $conn,
            $contextoMarcaTela,
            $chaveMarcaTela,
            ($contextoMarcaTela['tipo'] ?? '') === 'plataforma'
                ? (string)($cfg[$chaveMarcaTela] ?? '')
                : (string)($config_padrao[$chaveMarcaTela] ?? '')
        );
    }
} catch (Throwable $e) {
    error_log('[ROJEX MARCA][LEITURA] ' . $e->getMessage());
    if ($tab_ativa === 'marca') {
        $msg = 'A identidade visual foi bloqueada porque o contexto Multi-Tenant não é válido.';
        $msg_tipo = 'danger';
    }
}

// Preferências visuais são individuais e nunca são lidas da tabela global.
$preferenciasUsuario = rojex_usuario_preferencias_get($conn, $usuarioSessaoId);
$cfg = array_merge($cfg, $preferenciasUsuario);

// Fechamento técnico da Sprint 4.1.3. A migração é executada somente uma vez,
// preservando versões futuras que venham a ser registradas pela Central de Atualizações.
if (($cfg['homologacao_sprint_4_1_3'] ?? '0') !== '1') {
    $dataHomologacao = date('Y-m-d H:i:s');
    foreach ([
        'versao_sistema' => '4.1.3',
        'sprint_atual' => '4.1.3',
        'status_homologacao' => 'homologada',
        'data_homologacao' => $dataHomologacao,
        'homologacao_sprint_4_1_3' => '1',
    ] as $chaveHomologacao => $valorHomologacao) {
        sgl_cfg_set($conn, $chaveHomologacao, $valorHomologacao);
        $cfg[$chaveHomologacao] = $valorHomologacao;
    }

    sgl_log(
        $conn,
        'Homologou Sprint 4.1.3',
        'configuracoes',
        '4.1.3',
        'Fechamento técnico e homologação final da Administração Enterprise.'
    );
}

// Identificador técnico permanente: prepara o cadastro para futura vinculação SaaS
// sem alterar a arquitetura atual nem expor dados sensíveis.
if ($cfg['identificador_escritorio'] === '') {
    try {
        $cfg['identificador_escritorio'] = 'ROJEX-' . strtoupper(bin2hex(random_bytes(4)));
    } catch (Throwable $e) {
        $cfg['identificador_escritorio'] = 'ROJEX-' . strtoupper(substr(hash('sha256', uniqid('', true)), 0, 8));
    }
    sgl_cfg_set($conn, 'identificador_escritorio', $cfg['identificador_escritorio']);
}


foreach ([
    'identificador_instalacao' => 'INST',
    'tenant_id' => 'TENANT',
    'chave_instalacao' => 'KEY',
] as $chaveIdentificador => $prefixoIdentificador) {
    if (($cfg[$chaveIdentificador] ?? '') === '') {
        try {
            $sufixoIdentificador = strtoupper(bin2hex(random_bytes(8)));
        } catch (Throwable $e) {
            $sufixoIdentificador = strtoupper(substr(hash('sha256', uniqid('', true)), 0, 16));
        }

        $cfg[$chaveIdentificador] = 'ROJEX-' . $prefixoIdentificador . '-' . $sufixoIdentificador;
        sgl_cfg_set($conn, $chaveIdentificador, $cfg[$chaveIdentificador]);
    }
}

$logoPadrao = 'assets/img/logo_custom.png';
foreach ([
    'assets/img/logo_rojex_ai.png',
    'assets/img/logo_rojex.png',
    'assets/img/logo.png',
    'assets/img/logo_custom.png',
] as $candidatoLogoPadrao) {
    if (is_file(__DIR__ . '/../' . $candidatoLogoPadrao)) {
        $logoPadrao = $candidatoLogoPadrao;
        break;
    }
}

$logo_exibir = $logoPadrao;
$logoArquivoConfigurado = trim((string)($cfg['logo_arquivo'] ?? ''));
if (
    $logoArquivoConfigurado !== ''
    && !str_contains($logoArquivoConfigurado, '..')
    && preg_match('/^[A-Za-z0-9_\/-]+\.(?:png|jpe?g|webp)$/i', $logoArquivoConfigurado)
    && is_file($upload_dir . $logoArquivoConfigurado)
) {
    $logo_exibir = 'assets/img/' . $logoArquivoConfigurado;
}
$lixeira_todos = sgl_buscar_lixeira($conn);

$lixeira_busca = trim((string)($_GET['lixeira_q'] ?? ''));
$lixeira_modulo = trim((string)($_GET['lixeira_modulo'] ?? ''));
$lixeira_por_pagina = max(10, min(100, (int)($_GET['lixeira_por_pagina'] ?? 25)));
$lixeira_pagina = max(1, (int)($_GET['lixeira_pagina'] ?? 1));

$lixeira_modulos = [];
foreach ($lixeira_todos as $registro) {
    $lixeira_modulos[$registro['tabela']] = $registro['tipo'];
}
asort($lixeira_modulos);

$lixeira_filtrados = array_values(array_filter($lixeira_todos, static function (array $registro) use ($lixeira_busca, $lixeira_modulo): bool {
    if ($lixeira_modulo !== '' && $registro['tabela'] !== $lixeira_modulo) return false;

    if ($lixeira_busca !== '') {
        $alvo = mb_strtolower($registro['nome'] . ' ' . $registro['tipo'] . ' ' . $registro['id'], 'UTF-8');
        if (mb_strpos($alvo, mb_strtolower($lixeira_busca, 'UTF-8')) === false) return false;
    }

    return true;
}));

$lixeira_total_filtrado = count($lixeira_filtrados);
$lixeira_total_paginas = max(1, (int)ceil($lixeira_total_filtrado / $lixeira_por_pagina));
if ($lixeira_pagina > $lixeira_total_paginas) $lixeira_pagina = $lixeira_total_paginas;

$lixeira_offset = ($lixeira_pagina - 1) * $lixeira_por_pagina;
$lixeira_itens = array_slice($lixeira_filtrados, $lixeira_offset, $lixeira_por_pagina);

$backup_resumo = sgl_backup_resumo($conn);

$usuarios = [];
if (sgl_tabela_existe($conn, 'usuarios')) {
    $camposUsuarios = ['id', 'nome', 'usuario', 'email', 'perfil', 'ativo'];

    foreach (['telefone','cargo','departamento','observacoes','vinculo_status','desligado_em','desligado_por','criado_em','ultimo_login'] as $campoUsuarioOpcional) {
        if (sgl_coluna_existe($conn, 'usuarios', $campoUsuarioOpcional)) {
            $camposUsuarios[] = $campoUsuarioOpcional;
        }
    }

    $resUsuarios = $conn->query(
        "SELECT " . implode(', ', $camposUsuarios) . " FROM usuarios ORDER BY ativo DESC, nome ASC"
    );
    if ($resUsuarios) {
        while ($u = $resUsuarios->fetch_assoc()) {
            $usuarios[] = $u;
        }
    }
}

$logs = [];
$logsRelatorio = [];
$logDataInicio = trim((string)($_GET['log_data_inicio'] ?? ''));
$logDataFim = trim((string)($_GET['log_data_fim'] ?? ''));
$logUsuario = max(0, (int)($_GET['log_usuario'] ?? 0));
$logModulo = trim((string)($_GET['log_modulo'] ?? ''));

if (sgl_tabela_existe($conn, 'logs_sistema')) {
    try {
        $whereLogs = [];
        $tiposLogs = '';
        $valoresLogs = [];

        if ($logDataInicio !== '') {
            $whereLogs[] = "l.criado_em >= ?";
            $tiposLogs .= 's';
            $valoresLogs[] = $logDataInicio . ' 00:00:00';
        }
        if ($logDataFim !== '') {
            $whereLogs[] = "l.criado_em <= ?";
            $tiposLogs .= 's';
            $valoresLogs[] = $logDataFim . ' 23:59:59';
        }
        if ($logUsuario > 0) {
            $whereLogs[] = "l.usuario_id = ?";
            $tiposLogs .= 'i';
            $valoresLogs[] = $logUsuario;
        }
        if ($logModulo !== '') {
            $whereLogs[] = "l.tabela = ?";
            $tiposLogs .= 's';
            $valoresLogs[] = $logModulo;
        }

        $sqlLogsBase = "SELECT l.*,
                               COALESCE(l.usuario_nome, u.nome) AS responsavel_nome,
                               COALESCE(l.usuario_login, u.usuario) AS responsavel_login,
                               COALESCE(l.usuario_perfil, u.perfil) AS responsavel_perfil
                          FROM logs_sistema l
                     LEFT JOIN usuarios u ON u.id = l.usuario_id";

        if ($whereLogs) {
            $sqlLogsBase .= " WHERE " . implode(' AND ', $whereLogs);
        }
        $sqlLogsBase .= " ORDER BY l.id ASC";

        $stmtLogs = $conn->prepare($sqlLogsBase);
        if ($tiposLogs !== '') {
            $stmtLogs->bind_param($tiposLogs, ...$valoresLogs);
        }
        $stmtLogs->execute();
        $resLogs = $stmtLogs->get_result();
        while ($l = $resLogs->fetch_assoc()) {
            $logsRelatorio[] = $l;
        }
        $stmtLogs->close();

        $logs = array_slice(array_reverse($logsRelatorio), 0, 100);
    } catch (Throwable $e) {
        $logs = [];
        $logsRelatorio = [];
    }
}

$logModulosDisponiveis = [];
if (sgl_tabela_existe($conn, 'logs_sistema')) {
    try {
        $resModulosLog = $conn->query("SELECT DISTINCT tabela FROM logs_sistema WHERE tabela IS NOT NULL AND tabela <> '' ORDER BY tabela");
        if ($resModulosLog) {
            while ($moduloLog = $resModulosLog->fetch_assoc()) {
                $logModulosDisponiveis[] = (string)$moduloLog['tabela'];
            }
        }
    } catch (Throwable $e) {}
}

$logBackupEscritorios = [];
$logBackupsRecentes = [];
if ($ehUsuarioMaster) {
    try {
        $resEscritoriosBackup = $conn->query(
            "SELECT id, tenant_id, nome, status
               FROM escritorios_saas
              WHERE tenant_id IS NOT NULL AND tenant_id <> ''
              ORDER BY nome ASC, id ASC"
        );
        if ($resEscritoriosBackup) {
            while ($escritorioBackupLista = $resEscritoriosBackup->fetch_assoc()) {
                $logBackupEscritorios[] = $escritorioBackupLista;
            }
        }
        $resBackupsLog = $conn->query(
            "SELECT *
               FROM logs_backups
              ORDER BY id DESC
              LIMIT 100"
        );
        if ($resBackupsLog) {
            while ($backupLogLista = $resBackupsLog->fetch_assoc()) {
                $logBackupsRecentes[] = $backupLogLista;
            }
        }
    } catch (Throwable $e) {
        $logBackupEscritorios = [];
        $logBackupsRecentes = [];
    }
}

$totalUsuarios = count($usuarios);
$totalAtivos = count(array_filter($usuarios, fn($u) => (int)$u['ativo'] === 1));
$totalInativos = $totalUsuarios - $totalAtivos;
$totalAdministradores = count(array_filter($usuarios, fn($u) => in_array($u['perfil'] ?? '', ['Administrador','Administrador Master'], true)));
$totalAdvogadosUsuarios = count(array_filter($usuarios, fn($u) => ($u['perfil'] ?? '') === 'Advogado'));
$totalFinanceiroUsuarios = count(array_filter($usuarios, fn($u) => ($u['perfil'] ?? '') === 'Financeiro'));
$limiteUsuariosLicenca = max(1, min(100, (int)$cfg['limite_usuarios_licenca']));
$percentualLicencaUsuarios = min(100, (int)round(($totalUsuarios / $limiteUsuariosLicenca) * 100));
$totalLogs = sgl_select_count($conn, "SELECT COUNT(*) AS total FROM logs_sistema");
$inventarioLogs = [];
if (sgl_tabela_existe($conn, 'logs_sistema')) {
    try {
        $resInv = $conn->query("SELECT COALESCE(l.usuario_nome, u.nome, 'Sistema') AS usuario_nome, COALESCE(l.usuario_perfil, u.perfil, '-') AS perfil, COALESCE(l.tabela, '-') AS modulo, COUNT(*) AS total, MAX(l.criado_em) AS ultimo_registro FROM logs_sistema l LEFT JOIN usuarios u ON u.id = l.usuario_id GROUP BY usuario_nome, perfil, modulo ORDER BY total DESC, ultimo_registro DESC LIMIT 30");
        if ($resInv) { while ($i = $resInv->fetch_assoc()) { $inventarioLogs[] = $i; } }
    } catch (Throwable $e) {}
}
$totalLixeira = count($lixeira_todos);


// Sincroniza, sem duplicar, esta instalação com a estrutura SaaS central.
// O cadastro nasce a partir das configurações homologadas na Sprint 4.1.2.
$tenantAtualSaas = (string)($cfg['tenant_id'] ?? '');
$escritorioAtualSaasId = 0;
if ($tenantAtualSaas !== '' && sgl_tabela_existe($conn, 'escritorios_saas')) {
    try {
        $nomeSaas = (string)($cfg['nome_escritorio'] ?: 'Escritório sem nome');
        $documentoSaas = (string)($cfg['cpf_cnpj_escritorio'] ?? '');
        $responsavelSaas = (string)($cfg['responsavel_administrativo_escritorio'] ?: $cfg['responsavel_escritorio']);
        $emailSaas = (string)($cfg['email_administrativo_escritorio'] ?: $cfg['email_escritorio']);
        $subdominioSaas = (string)($cfg['subdominio_saas'] ?? '');
        $statusEscritorioSaas = in_array(($cfg['status_operacional_escritorio'] ?? ''), ['ativo','implantacao','suspenso'], true) ? (string)$cfg['status_operacional_escritorio'] : 'implantacao';
        $planoAtualSaas = in_array(($cfg['plano_licenca'] ?? ''), ['starter','professional','enterprise'], true) ? (string)$cfg['plano_licenca'] : 'enterprise';

        $stmt = $conn->prepare("INSERT INTO escritorios_saas (tenant_id, nome, documento, responsavel, email, subdominio, status, plano) VALUES (?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE nome = VALUES(nome), documento = VALUES(documento), responsavel = VALUES(responsavel), email = VALUES(email), subdominio = CASE WHEN VALUES(subdominio) IS NULL OR TRIM(VALUES(subdominio)) = '' THEN escritorios_saas.subdominio ELSE VALUES(subdominio) END, status = VALUES(status), plano = VALUES(plano)");
        $stmt->bind_param('ssssssss', $tenantAtualSaas, $nomeSaas, $documentoSaas, $responsavelSaas, $emailSaas, $subdominioSaas, $statusEscritorioSaas, $planoAtualSaas);
        $stmt->execute();
        $stmt->close();

        $stmt = $conn->prepare("SELECT id FROM escritorios_saas WHERE tenant_id = ? LIMIT 1");
        $stmt->bind_param('s', $tenantAtualSaas);
        $stmt->execute();
        $escritorioAtualSaasId = (int)($stmt->get_result()->fetch_assoc()['id'] ?? 0);
        $stmt->close();

        $chaveAtualSaas = (string)($cfg['chave_instalacao'] ?? '');
        if ($escritorioAtualSaasId > 0 && $chaveAtualSaas !== '') {
            $statusAtualSaas = in_array(($cfg['status_licenca'] ?? ''), ['teste','ativa','suspensa','expirada'], true) ? (string)$cfg['status_licenca'] : 'teste';
            $limiteUsuariosAtualSaas = max(1, (int)($cfg['limite_usuarios_licenca'] ?? 100));
            $limiteArmazenamentoAtualSaas = max(1, (int)($cfg['limite_armazenamento_gb'] ?? 50));
            $ativacaoAtualSaas = ($cfg['data_ativacao_licenca'] ?? '') !== '' ? (string)$cfg['data_ativacao_licenca'] : null;
            $renovacaoAtualSaas = ($cfg['data_renovacao_licenca'] ?? '') !== '' ? (string)$cfg['data_renovacao_licenca'] : null;
            $stmt = $conn->prepare("INSERT INTO licencas_saas (escritorio_id, chave_licenca, plano, status, limite_usuarios, limite_armazenamento_gb, ativada_em, renovacao_em, observacoes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Licença sincronizada com a instalação atual.') ON DUPLICATE KEY UPDATE escritorio_id = VALUES(escritorio_id), plano = VALUES(plano), limite_usuarios = VALUES(limite_usuarios), limite_armazenamento_gb = VALUES(limite_armazenamento_gb)");
            $stmt->bind_param('isssiiss', $escritorioAtualSaasId, $chaveAtualSaas, $planoAtualSaas, $statusAtualSaas, $limiteUsuariosAtualSaas, $limiteArmazenamentoAtualSaas, $ativacaoAtualSaas, $renovacaoAtualSaas);
            $stmt->execute();
            $stmt->close();
        }
    } catch (Throwable $e) {
        // A sincronização administrativa nunca deve interromper Configurações.
    }
}

$escritoriosSaas = [];
$licencasSaas = [];
$licencaEditar = null;
$escritorioEditar = null;
$escritorioBusca = trim((string)($_GET['escritorio_q'] ?? ''));
$escritorioStatusFiltro = trim((string)($_GET['escritorio_status'] ?? ''));
$escritorioPlanoFiltro = trim((string)($_GET['escritorio_plano'] ?? ''));
if ($ehUsuarioMaster && sgl_tabela_existe($conn, 'escritorios_saas')) {
    try {
        $sqlEscritorios = "SELECT e.*,
            (SELECT COUNT(*) FROM licencas_saas l WHERE l.escritorio_id=e.id) AS total_licencas,
            (SELECT COUNT(*) FROM licencas_saas l WHERE l.escritorio_id=e.id AND l.status='ativa') AS licencas_ativas
            FROM escritorios_saas e WHERE 1=1";
        $tiposEscritorios = '';
        $valoresEscritorios = [];
        if ($escritorioBusca !== '') {
            $sqlEscritorios .= " AND (e.nome LIKE ? OR e.tenant_id LIKE ? OR e.documento LIKE ? OR e.responsavel LIKE ?)";
            $buscaLike = '%' . $escritorioBusca . '%';
            $tiposEscritorios .= 'ssss';
            array_push($valoresEscritorios, $buscaLike, $buscaLike, $buscaLike, $buscaLike);
        }
        if (in_array($escritorioStatusFiltro, ['implantacao','ativo','suspenso','bloqueado','encerrado'], true)) {
            $sqlEscritorios .= " AND e.status = ?";
            $tiposEscritorios .= 's';
            $valoresEscritorios[] = $escritorioStatusFiltro;
        }
        if (in_array($escritorioPlanoFiltro, ['starter','professional','enterprise'], true)) {
            $sqlEscritorios .= " AND e.plano = ?";
            $tiposEscritorios .= 's';
            $valoresEscritorios[] = $escritorioPlanoFiltro;
        }
        $sqlEscritorios .= " ORDER BY e.status='ativo' DESC, e.nome ASC";
        $stmtEscritorios = $conn->prepare($sqlEscritorios);
        if ($tiposEscritorios !== '') { $stmtEscritorios->bind_param($tiposEscritorios, ...$valoresEscritorios); }
        $stmtEscritorios->execute();
        $res = $stmtEscritorios->get_result();
        if ($res) { while ($row = $res->fetch_assoc()) { $escritoriosSaas[] = $row; } }
        $stmtEscritorios->close();

        $editarEscritorioId = max(0, (int)($_GET['editar_escritorio'] ?? 0));
        if ($editarEscritorioId > 0) {
            $stmtEditarEscritorio = $conn->prepare("SELECT * FROM escritorios_saas WHERE id=? LIMIT 1");
            $stmtEditarEscritorio->bind_param('i', $editarEscritorioId);
            $stmtEditarEscritorio->execute();
            $escritorioEditar = $stmtEditarEscritorio->get_result()->fetch_assoc() ?: null;
            $stmtEditarEscritorio->close();
        }
    } catch (Throwable $e) {}
}
if ($ehUsuarioMaster && sgl_tabela_existe($conn, 'licencas_saas')) {
    try {
        $res = $conn->query("SELECT l.*, e.nome AS escritorio_nome, e.tenant_id FROM licencas_saas l LEFT JOIN escritorios_saas e ON e.id = l.escritorio_id ORDER BY l.id DESC");
        if ($res) { while ($row = $res->fetch_assoc()) { $licencasSaas[] = $row; } }
        $editarLicencaId = max(0, (int)($_GET['editar_licenca'] ?? 0));
        if ($editarLicencaId > 0) {
            foreach ($licencasSaas as $licencaItem) {
                if ((int)$licencaItem['id'] === $editarLicencaId) { $licencaEditar = $licencaItem; break; }
            }
        }
    } catch (Throwable $e) {}
}


// Dados do Painel de Planos SaaS — Sprint 4.5 / Etapa 3.2.
$planosSaas = [];
$planoEditar = null;
$historicoPrecosPlanos = [];
if ($ehUsuarioMaster && sgl_tabela_existe($conn, 'planos_saas')) {
    try {
        $resPlanos = $conn->query("SELECT * FROM planos_saas ORDER BY ordem_exibicao ASC, nome ASC, id ASC");
        if ($resPlanos) {
            while ($rowPlano = $resPlanos->fetch_assoc()) $planosSaas[] = $rowPlano;
        }
        $editarPlanoId = max(0, (int)($_GET['editar_plano'] ?? 0));
        if ($editarPlanoId > 0) {
            foreach ($planosSaas as $planoItem) {
                if ((int)$planoItem['id'] === $editarPlanoId) { $planoEditar = $planoItem; break; }
            }
        }
        if (sgl_tabela_existe($conn, 'planos_precos_historico')) {
            $resHistoricoPlanos = $conn->query(
                "SELECT h.*, p.nome AS plano_nome, p.codigo AS plano_codigo
                   FROM planos_precos_historico h
                   INNER JOIN planos_saas p ON p.id = h.plano_id
                  ORDER BY h.id DESC LIMIT 30"
            );
            if ($resHistoricoPlanos) {
                while ($rowHistoricoPlano = $resHistoricoPlanos->fetch_assoc()) $historicoPrecosPlanos[] = $rowHistoricoPlano;
            }
        }
    } catch (Throwable $e) {
        $planosSaas = [];
        $planoEditar = null;
        $historicoPrecosPlanos = [];
    }
}
$totalPlanosSaas = count($planosSaas);
$totalPlanosAtivos = 0;
$totalPlanosInativos = 0;
$totalPlanosDestaque = 0;
foreach ($planosSaas as $planoContador) {
    if (!empty($planoContador['ativo'])) $totalPlanosAtivos++; else $totalPlanosInativos++;
    if (!empty($planoContador['destaque'])) $totalPlanosDestaque++;
}


// Dados do Cadastro de Módulos e Configurador Comercial — Sprint 4.5 / Etapa 3.3.
$modulosSaas=[]; $moduloEditar=null; $configuradorPlanoId=max(0,(int)($_GET['configurar_plano']??0)); $vinculosPlanoModulo=[];
if($ehUsuarioMaster && sgl_tabela_existe($conn,'modulos_saas')){
    try{
        $resMod=$conn->query("SELECT * FROM modulos_saas ORDER BY categoria ASC, ordem_exibicao ASC, nome ASC, id ASC");
        while($resMod && ($rowMod=$resMod->fetch_assoc())) $modulosSaas[]=$rowMod;
        $editarModuloId=max(0,(int)($_GET['editar_modulo']??0));
        if($editarModuloId>0) foreach($modulosSaas as $modItem) if((int)$modItem['id']===$editarModuloId){$moduloEditar=$modItem;break;}
        if($configuradorPlanoId<=0 && $planosSaas) $configuradorPlanoId=(int)$planosSaas[0]['id'];
        if($configuradorPlanoId>0 && sgl_tabela_existe($conn,'planos_modulos_saas')){
            $stmt=$conn->prepare("SELECT * FROM planos_modulos_saas WHERE plano_id=?"); $stmt->bind_param('i',$configuradorPlanoId); $stmt->execute(); $res=$stmt->get_result();
            while($res && ($row=$res->fetch_assoc())) $vinculosPlanoModulo[(int)$row['modulo_id']]=$row;
            $stmt->close();
        }
    }catch(Throwable $e){$modulosSaas=[];$moduloEditar=null;$vinculosPlanoModulo=[];}
}
$totalModulosSaas=count($modulosSaas); $totalModulosAtivos=0; $totalModulosIa=0; $totalModulosBeta=0;
foreach($modulosSaas as $modCont){if(!empty($modCont['ativo']))$totalModulosAtivos++;if(!empty($modCont['exige_ia_externa']))$totalModulosIa++;if(($modCont['status_lancamento']??'producao')==='beta')$totalModulosBeta++;}

// Dados administrativos do Portal. A consulta é global apenas para o MASTER
// e sempre exibe o contexto completo para impedir associações ambíguas.
$portalEscritorios = [];
$portalClientes = [];
$portalContas = [];
$portalConviteGerado = null;
if ($ehUsuarioMaster && sgl_tabela_existe($conn, 'portal_clientes_contas')) {
    try {
        $res = $conn->query("SELECT e.id,e.tenant_id,e.nome,e.status,COALESCE(em.ativo,0) AS portal_ativo FROM escritorios_saas e LEFT JOIN modulos_saas m ON m.codigo='portal_cliente' LEFT JOIN escritorios_modulos_saas em ON em.escritorio_id=e.id AND em.modulo_id=m.id ORDER BY e.nome");
        while ($res && ($row = $res->fetch_assoc())) $portalEscritorios[] = $row;

        $res = $conn->query("SELECT c.id,c.tenant_id,c.escritorio_id,c.nome,c.email FROM clientes c INNER JOIN escritorios_saas e ON e.id=c.escritorio_id AND e.tenant_id=c.tenant_id WHERE c.deletado=0 AND c.status='Ativo' ORDER BY e.nome,c.nome");
        while ($res && ($row = $res->fetch_assoc())) $portalClientes[] = $row;

        $res = $conn->query("SELECT pc.*,c.nome AS cliente_nome,e.nome AS escritorio_nome,pp.ver_processos,pp.ver_documentos,pp.enviar_documentos,pp.ver_honorarios,pp.ver_recibos,pp.ver_agenda,pp.receber_notificacoes FROM portal_clientes_contas pc INNER JOIN clientes c ON c.id=pc.cliente_id AND c.tenant_id=pc.tenant_id AND c.escritorio_id=pc.escritorio_id INNER JOIN escritorios_saas e ON e.id=pc.escritorio_id AND e.tenant_id=pc.tenant_id LEFT JOIN portal_clientes_permissoes pp ON pp.conta_id=pc.id ORDER BY pc.id DESC");
        while ($res && ($row = $res->fetch_assoc())) $portalContas[] = $row;
    } catch (Throwable $e) {
        $portalEscritorios = []; $portalClientes = []; $portalContas = [];
    }
    if (!empty($_SESSION['rojex_portal_convite']) && is_array($_SESSION['rojex_portal_convite'])) {
        $portalConviteGerado = $_SESSION['rojex_portal_convite'];
        unset($_SESSION['rojex_portal_convite']);
    }
}


// Histórico de usuários desligados — Sprint 4.1.3 / Etapa 4.
$usuariosDesligados = [];
$historicoUsuariosDesligados = [];
$desligadoBusca = trim((string)($_GET['desligado_q'] ?? ''));
$desligadoAcao = trim((string)($_GET['desligado_acao'] ?? ''));
$desligadoDataInicio = trim((string)($_GET['desligado_data_inicio'] ?? ''));
$desligadoDataFim = trim((string)($_GET['desligado_data_fim'] ?? ''));

if ($ehUsuarioMaster && sgl_tabela_existe($conn, 'usuarios')) {
    try {
        $where = [];
        $tipos = '';
        $valores = [];
        if (sgl_coluna_existe($conn, 'usuarios', 'vinculo_status')) {
            $where[] = "u.vinculo_status = 'encerrado'";
        } elseif (sgl_coluna_existe($conn, 'usuarios', 'ativo')) {
            $where[] = "u.ativo = 0";
        }
        if ($desligadoBusca !== '') {
            $where[] = "(u.nome LIKE ? OR u.usuario LIKE ? OR u.email LIKE ? OR u.perfil LIKE ?)";
            $like = '%' . $desligadoBusca . '%';
            $tipos .= 'ssss';
            array_push($valores, $like, $like, $like, $like);
        }
        if ($desligadoDataInicio !== '' && sgl_coluna_existe($conn, 'usuarios', 'desligado_em')) {
            $where[] = "u.desligado_em >= ?";
            $tipos .= 's';
            $valores[] = $desligadoDataInicio . ' 00:00:00';
        }
        if ($desligadoDataFim !== '' && sgl_coluna_existe($conn, 'usuarios', 'desligado_em')) {
            $where[] = "u.desligado_em <= ?";
            $tipos .= 's';
            $valores[] = $desligadoDataFim . ' 23:59:59';
        }
        $campos = ['u.id','u.nome','u.usuario','u.email','u.perfil','u.ativo'];
        foreach (['telefone','cargo','departamento','observacoes','vinculo_status','desligado_em','desligado_por','criado_em','ultimo_login'] as $campo) {
            if (sgl_coluna_existe($conn, 'usuarios', $campo)) $campos[] = 'u.`' . $campo . '`';
        }
        $sql = "SELECT " . implode(', ', $campos) . ", d.nome AS desligado_por_nome FROM usuarios u LEFT JOIN usuarios d ON d.id = u.desligado_por";
        if ($where) $sql .= " WHERE " . implode(' AND ', $where);
        $sql .= sgl_coluna_existe($conn, 'usuarios', 'desligado_em') ? " ORDER BY u.desligado_em DESC, u.id DESC" : " ORDER BY u.id DESC";
        $stmt = $conn->prepare($sql);
        if ($tipos !== '') $stmt->bind_param($tipos, ...$valores);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) $usuariosDesligados[] = $row;
        $stmt->close();
    } catch (Throwable $e) { $usuariosDesligados = []; }
}

if ($ehUsuarioMaster && sgl_tabela_existe($conn, 'usuarios_historico')) {
    try {
        $where = [];
        $tipos = '';
        $valores = [];
        if ($desligadoAcao !== '') { $where[] = 'h.acao = ?'; $tipos .= 's'; $valores[] = $desligadoAcao; }
        if ($desligadoDataInicio !== '') { $where[] = 'h.criado_em >= ?'; $tipos .= 's'; $valores[] = $desligadoDataInicio . ' 00:00:00'; }
        if ($desligadoDataFim !== '') { $where[] = 'h.criado_em <= ?'; $tipos .= 's'; $valores[] = $desligadoDataFim . ' 23:59:59'; }
        $sql = "SELECT h.* FROM usuarios_historico h" . ($where ? " WHERE " . implode(' AND ', $where) : '') . " ORDER BY h.id DESC LIMIT 500";
        $stmt = $conn->prepare($sql);
        if ($tipos !== '') $stmt->bind_param($tipos, ...$valores);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $dados = json_decode((string)$row['dados_snapshot'], true);
            $row['snapshot'] = is_array($dados) ? $dados : [];
            if ($desligadoBusca !== '') {
                $alvo = mb_strtolower(json_encode($row['snapshot'], JSON_UNESCAPED_UNICODE) . ' ' . ($row['realizado_por_nome'] ?? '') . ' ' . ($row['acao'] ?? ''), 'UTF-8');
                if (mb_strpos($alvo, mb_strtolower($desligadoBusca, 'UTF-8')) === false) continue;
            }
            $historicoUsuariosDesligados[] = $row;
        }
        $stmt->close();
    } catch (Throwable $e) { $historicoUsuariosDesligados = []; }
}

// Indicadores consolidados do painel MASTER da Sprint 4.1.3.
$totalEscritoriosSaas = sgl_tabela_existe($conn, 'escritorios_saas')
    ? sgl_select_count($conn, "SELECT COUNT(*) AS total FROM escritorios_saas") : 0;
$totalEscritoriosAtivos = sgl_tabela_existe($conn, 'escritorios_saas')
    ? sgl_select_count($conn, "SELECT COUNT(*) AS total FROM escritorios_saas WHERE status = 'ativo'") : 0;
$totalLicencasSaas = sgl_tabela_existe($conn, 'licencas_saas')
    ? sgl_select_count($conn, "SELECT COUNT(*) AS total FROM licencas_saas") : 0;
$totalLicencasAtivas = sgl_tabela_existe($conn, 'licencas_saas')
    ? sgl_select_count($conn, "SELECT COUNT(*) AS total FROM licencas_saas WHERE status = 'ativa'") : 0;
$totalUsuariosDesligados = sgl_tabela_existe($conn, 'usuarios') && sgl_coluna_existe($conn, 'usuarios', 'vinculo_status')
    ? sgl_select_count($conn, "SELECT COUNT(*) AS total FROM usuarios WHERE vinculo_status = 'encerrado'") : 0;
$totalBackupsRegistrados = sgl_tabela_existe($conn, 'backups_sistema')
    ? sgl_select_count($conn, "SELECT COUNT(*) AS total FROM backups_sistema") : 0;
$totalAtualizacoesPendentes = sgl_tabela_existe($conn, 'atualizacoes_sistema')
    ? sgl_select_count($conn, "SELECT COUNT(*) AS total FROM atualizacoes_sistema WHERE status IN ('planejada','disponivel')") : 0;

// -----------------------------------------------------------------------------
// Diagnóstico Enterprise e saúde do sistema
// -----------------------------------------------------------------------------
$versaoBancoServidor = '-';
$charsetBanco = '-';
try {
    $resVersaoBanco = $conn->query("SELECT VERSION() AS versao");
    if ($resVersaoBanco) {
        $versaoBancoServidor = (string)($resVersaoBanco->fetch_assoc()['versao'] ?? '-');
    }

    $resCharset = $conn->query("SELECT @@character_set_database AS charset_banco");
    if ($resCharset) {
        $charsetBanco = (string)($resCharset->fetch_assoc()['charset_banco'] ?? '-');
    }
} catch (Throwable $e) {}

$servidorWeb = (string)($_SERVER['SERVER_SOFTWARE'] ?? 'Não identificado');
$httpsAtivo = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || ((string)($_SERVER['SERVER_PORT'] ?? '') === '443');

$limiteMemoriaPhp = (string)ini_get('memory_limit');
$uploadMaximoPhp = (string)ini_get('upload_max_filesize');
$postMaximoPhp = (string)ini_get('post_max_size');
$tempoExecucaoPhp = (string)ini_get('max_execution_time');
$timezonePhp = (string)date_default_timezone_get();

$espacoTotalDisco = @disk_total_space(__DIR__) ?: 0;
$espacoLivreDisco = @disk_free_space(__DIR__) ?: 0;
$espacoUsadoDisco = max(0, $espacoTotalDisco - $espacoLivreDisco);
$percentualDisco = $espacoTotalDisco > 0
    ? min(100, (int)round(($espacoUsadoDisco / $espacoTotalDisco) * 100))
    : 0;

$diagnosticoModulos = [
    'Clientes' => sgl_tabela_existe($conn, 'clientes') ? sgl_select_count($conn, "SELECT COUNT(*) AS total FROM clientes") : 0,
    'Advogados' => sgl_tabela_existe($conn, 'advogados') ? sgl_select_count($conn, "SELECT COUNT(*) AS total FROM advogados") : 0,
    'Processos' => sgl_tabela_existe($conn, 'processos') ? sgl_select_count($conn, "SELECT COUNT(*) AS total FROM processos") : 0,
    'Agenda' => sgl_tabela_existe($conn, 'agenda') ? sgl_select_count($conn, "SELECT COUNT(*) AS total FROM agenda") : 0,
    'Documentos' => sgl_tabela_existe($conn, 'documentos_arquivos') ? sgl_select_count($conn, "SELECT COUNT(*) AS total FROM documentos_arquivos") : 0,
    'Modelos' => sgl_tabela_existe($conn, 'modelos_documentos') ? sgl_select_count($conn, "SELECT COUNT(*) AS total FROM modelos_documentos") : 0,
    'Contas a Pagar' => sgl_tabela_existe($conn, 'contas_pagar') ? sgl_select_count($conn, "SELECT COUNT(*) AS total FROM contas_pagar") : 0,
    'Contas a Receber' => sgl_tabela_existe($conn, 'contas_receber') ? sgl_select_count($conn, "SELECT COUNT(*) AS total FROM contas_receber") : 0,
    'Usuários' => $totalUsuarios,
    'LOG' => $totalLogs,
    'Lixeira' => $totalLixeira,
];

$segurancaSistema = [
    'CSRF' => function_exists('gerarTokenCsrf') && function_exists('validarTokenCsrf'),
    'Hash de senha' => function_exists('password_hash') && defined('PASSWORD_DEFAULT'),
    'LOG Enterprise' => sgl_tabela_existe($conn, 'logs_sistema'),
    'Lixeira Enterprise' => function_exists('sgl_buscar_lixeira'),
    'Histórico de usuários' => sgl_tabela_existe($conn, 'usuarios_historico'),
    'HTTPS' => $httpsAtivo,
    'Banco conectado' => $conn->ping(),
];

$totalChecksSeguranca = count($segurancaSistema);
$checksSegurancaOk = count(array_filter($segurancaSistema));
$percentualSaude = $totalChecksSeguranca > 0
    ? (int)round(($checksSegurancaOk / $totalChecksSeguranca) * 100)
    : 0;


// -----------------------------------------------------------------------------
// Painel de Saúde Consolidado — Sprint 4.1.3 / Etapa 6
// -----------------------------------------------------------------------------
$inicioDiagnosticoSaude = microtime(true);
$memoriaAtualBytes = memory_get_usage(true);
$memoriaPicoBytes = memory_get_peak_usage(true);
$opcacheAtivo = function_exists('opcache_get_status') && (bool)@opcache_get_status(false);
$extensoesObrigatorias = ['mysqli','mbstring','json','openssl','fileinfo','session'];
$extensoesStatus = [];
foreach ($extensoesObrigatorias as $extensaoObrigatoria) {
    $extensoesStatus[$extensaoObrigatoria] = extension_loaded($extensaoObrigatoria);
}

$totalTabelasBanco = 0;
$tamanhoBancoBytes = 0;
$tempoConsultaMs = 0.0;
try {
    $inicioConsultaSaude = microtime(true);
    $resBancoSaude = $conn->query("SELECT COUNT(*) AS total_tabelas,
        COALESCE(SUM(data_length + index_length),0) AS tamanho_bytes
        FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()");
    $tempoConsultaMs = round((microtime(true) - $inicioConsultaSaude) * 1000, 2);
    if ($resBancoSaude) {
        $dadosBancoSaude = $resBancoSaude->fetch_assoc();
        $totalTabelasBanco = (int)($dadosBancoSaude['total_tabelas'] ?? 0);
        $tamanhoBancoBytes = (float)($dadosBancoSaude['tamanho_bytes'] ?? 0);
    }
} catch (Throwable $e) {}

$pastasCriticasSaude = [
    'assets/img' => __DIR__ . '/../assets/img',
    'uploads' => __DIR__ . '/../uploads',
    'config' => __DIR__ . '/../config',
];
$pastasStatusSaude = [];
foreach ($pastasCriticasSaude as $nomePastaSaude => $caminhoPastaSaude) {
    $pastasStatusSaude[$nomePastaSaude] = [
        'existe' => is_dir($caminhoPastaSaude),
        'gravavel' => is_dir($caminhoPastaSaude) ? is_writable($caminhoPastaSaude) : false,
    ];
}

$licencasProblematicas = sgl_tabela_existe($conn, 'licencas_saas')
    ? sgl_select_count($conn, "SELECT COUNT(*) AS total FROM licencas_saas WHERE status IN ('suspensa','expirada','cancelada')") : 0;
$escritoriosProblematicos = sgl_tabela_existe($conn, 'escritorios_saas')
    ? sgl_select_count($conn, "SELECT COUNT(*) AS total FROM escritorios_saas WHERE status IN ('suspenso','bloqueado','encerrado')") : 0;

$checksSaudeConsolidada = [];
$adicionarCheckSaude = static function (array &$destino, string $categoria, string $item, string $valor, string $nivel, string $recomendacao): void {
    $destino[] = compact('categoria','item','valor','nivel','recomendacao');
};

$adicionarCheckSaude($checksSaudeConsolidada, 'Servidor', 'PHP', PHP_VERSION, version_compare(PHP_VERSION, '8.0.0', '>=') ? 'excelente' : 'critico', 'Utilize PHP 8.0 ou superior.');
$adicionarCheckSaude($checksSaudeConsolidada, 'Servidor', 'HTTPS', $httpsAtivo ? 'Ativo' : 'Inativo', $httpsAtivo ? 'excelente' : (($cfg['ambiente_sistema'] ?? '') === 'producao' ? 'critico' : 'atencao'), 'Ative SSL obrigatoriamente no ambiente de produção.');
$adicionarCheckSaude($checksSaudeConsolidada, 'Servidor', 'OPcache', $opcacheAtivo ? 'Ativo' : 'Inativo', $opcacheAtivo ? 'excelente' : 'atencao', 'Habilite OPcache na Hostinger para melhorar o desempenho.');
$adicionarCheckSaude($checksSaudeConsolidada, 'PHP', 'Memória', $limiteMemoriaPhp, sgl_ini_bytes($limiteMemoriaPhp) >= 128*1024*1024 ? 'excelente' : 'atencao', 'Recomendado: memory_limit de pelo menos 128M.');
$adicionarCheckSaude($checksSaudeConsolidada, 'PHP', 'Upload máximo', $uploadMaximoPhp, sgl_ini_bytes($uploadMaximoPhp) >= 16*1024*1024 ? 'excelente' : 'atencao', 'Recomendado: upload_max_filesize de pelo menos 16M.');
$adicionarCheckSaude($checksSaudeConsolidada, 'PHP', 'POST máximo', $postMaximoPhp, sgl_ini_bytes($postMaximoPhp) >= 16*1024*1024 ? 'excelente' : 'atencao', 'Recomendado: post_max_size de pelo menos 16M.');
$adicionarCheckSaude($checksSaudeConsolidada, 'Banco', 'Conexão', $conn->ping() ? 'Conectado' : 'Falha', $conn->ping() ? 'excelente' : 'critico', 'Verifique as credenciais e a disponibilidade do MySQL/MariaDB.');
$adicionarCheckSaude($checksSaudeConsolidada, 'Banco', 'Charset', $charsetBanco, $charsetBanco === 'utf8mb4' ? 'excelente' : 'atencao', 'Utilize utf8mb4 para compatibilidade completa com caracteres.');
$adicionarCheckSaude($checksSaudeConsolidada, 'Banco', 'Tempo de consulta', number_format($tempoConsultaMs, 2, ',', '.') . ' ms', $tempoConsultaMs <= 100 ? 'excelente' : ($tempoConsultaMs <= 500 ? 'atencao' : 'critico'), 'Investigue consultas e índices se o tempo permanecer elevado.');
$adicionarCheckSaude($checksSaudeConsolidada, 'Armazenamento', 'Disco utilizado', $percentualDisco . '%', $percentualDisco < 80 ? 'excelente' : ($percentualDisco < 90 ? 'atencao' : 'critico'), 'Mantenha pelo menos 20% do disco livre.');
$adicionarCheckSaude($checksSaudeConsolidada, 'SaaS', 'Licenças com atenção', (string)$licencasProblematicas, $licencasProblematicas === 0 ? 'excelente' : 'atencao', 'Revise licenças suspensas, expiradas ou canceladas.');
$adicionarCheckSaude($checksSaudeConsolidada, 'SaaS', 'Escritórios com atenção', (string)$escritoriosProblematicos, $escritoriosProblematicos === 0 ? 'excelente' : 'atencao', 'Revise escritórios suspensos, bloqueados ou encerrados.');

foreach ($extensoesStatus as $extensaoSaude => $ativaExtensaoSaude) {
    $adicionarCheckSaude($checksSaudeConsolidada, 'PHP', 'Extensão ' . $extensaoSaude, $ativaExtensaoSaude ? 'Carregada' : 'Ausente', $ativaExtensaoSaude ? 'excelente' : 'critico', 'Habilite a extensão ' . $extensaoSaude . '.');
}
foreach ($pastasStatusSaude as $nomePastaSaude => $statusPastaSaude) {
    $nivelPastaSaude = !$statusPastaSaude['existe'] ? 'atencao' : ($statusPastaSaude['gravavel'] ? 'excelente' : 'atencao');
    $valorPastaSaude = !$statusPastaSaude['existe'] ? 'Não localizada' : ($statusPastaSaude['gravavel'] ? 'Gravável' : 'Somente leitura');
    $adicionarCheckSaude($checksSaudeConsolidada, 'Permissões', $nomePastaSaude, $valorPastaSaude, $nivelPastaSaude, 'Confirme existência e permissões adequadas na hospedagem.');
}

$quantidadeExcelenteSaude = count(array_filter($checksSaudeConsolidada, static fn($c) => $c['nivel'] === 'excelente'));
$quantidadeAtencaoSaude = count(array_filter($checksSaudeConsolidada, static fn($c) => $c['nivel'] === 'atencao'));
$quantidadeCriticoSaude = count(array_filter($checksSaudeConsolidada, static fn($c) => $c['nivel'] === 'critico'));
$totalChecksSaudeConsolidada = count($checksSaudeConsolidada);
$pontuacaoSaudeConsolidada = $totalChecksSaudeConsolidada > 0
    ? max(0, (int)round((($quantidadeExcelenteSaude + ($quantidadeAtencaoSaude * 0.5)) / $totalChecksSaudeConsolidada) * 100))
    : 0;
$tempoRespostaDiagnosticoMs = round((microtime(true) - $inicioDiagnosticoSaude) * 1000, 2);


// -----------------------------------------------------------------------------
// Relatórios Administrativos Enterprise — Sprint 4.1.3 / Etapa 5
// -----------------------------------------------------------------------------
$relatorioTipo = trim((string)($_GET['relatorio_tipo'] ?? 'resumo'));
$relatorioDataInicio = trim((string)($_GET['relatorio_data_inicio'] ?? ''));
$relatorioDataFim = trim((string)($_GET['relatorio_data_fim'] ?? ''));
$relatorioFormato = trim((string)($_GET['relatorio_exportar'] ?? ''));
$tituloRelatorio = 'Relatório Administrativo';
$cabecalhosRelatorio = [];
$linhasRelatorio = [];
$periodoDescricao = 'Período: todos os registros';
$nomeArquivoRelatorio = 'rojex_relatorio_' . date('Ymd_His');

$tiposRelatorioPermitidos = ['resumo','escritorios','licencas','usuarios','desligados','saude'];
if (!in_array($relatorioTipo, $tiposRelatorioPermitidos, true)) {
    $relatorioTipo = 'resumo';
}
foreach ([$relatorioDataInicio, $relatorioDataFim] as $dataRelatorio) {
    if ($dataRelatorio !== '') {
        $objDataRelatorio = DateTime::createFromFormat('Y-m-d', $dataRelatorio);
        if (!$objDataRelatorio || $objDataRelatorio->format('Y-m-d') !== $dataRelatorio) {
            $relatorioDataInicio = '';
            $relatorioDataFim = '';
            break;
        }
    }
}

if ($ehUsuarioMaster) {
    $tituloRelatorio = 'Relatório Administrativo';
    $cabecalhosRelatorio = [];
    $linhasRelatorio = [];

    $dentroPeriodo = static function (?string $data) use ($relatorioDataInicio, $relatorioDataFim): bool {
        if (!$data) return $relatorioDataInicio === '' && $relatorioDataFim === '';
        $dia = substr($data, 0, 10);
        if ($relatorioDataInicio !== '' && $dia < $relatorioDataInicio) return false;
        if ($relatorioDataFim !== '' && $dia > $relatorioDataFim) return false;
        return true;
    };

    if ($relatorioTipo === 'escritorios') {
        $tituloRelatorio = 'Relatório de Escritórios SaaS';
        $cabecalhosRelatorio = ['Tenant ID','Escritório','Documento','Responsável','Plano','Status','Licenças','Criado em'];
        foreach ($escritoriosSaas as $itemRelatorio) {
            if (!$dentroPeriodo($itemRelatorio['criado_em'] ?? null)) continue;
            $linhasRelatorio[] = [
                $itemRelatorio['tenant_id'] ?? '',
                $itemRelatorio['nome'] ?? '',
                $itemRelatorio['documento'] ?? '',
                $itemRelatorio['responsavel'] ?? '',
                ucfirst((string)($itemRelatorio['plano'] ?? '')),
                ucfirst((string)($itemRelatorio['status'] ?? '')),
                $itemRelatorio['total_licencas'] ?? 0,
                !empty($itemRelatorio['criado_em']) ? date('d/m/Y H:i', strtotime($itemRelatorio['criado_em'])) : '',
            ];
        }
    } elseif ($relatorioTipo === 'licencas') {
        $tituloRelatorio = 'Relatório de Licenças SaaS';
        $cabecalhosRelatorio = ['Chave','Escritório','Plano','Status','Usuários','Armazenamento','Ativação','Renovação'];
        foreach ($licencasSaas as $itemRelatorio) {
            if (!$dentroPeriodo($itemRelatorio['criado_em'] ?? null)) continue;
            $linhasRelatorio[] = [
                $itemRelatorio['chave_licenca'] ?? '',
                $itemRelatorio['escritorio_nome'] ?? 'Sem vínculo',
                ucfirst((string)($itemRelatorio['plano'] ?? '')),
                ucfirst((string)($itemRelatorio['status'] ?? '')),
                $itemRelatorio['limite_usuarios'] ?? 0,
                ($itemRelatorio['limite_armazenamento_gb'] ?? 0) . ' GB',
                !empty($itemRelatorio['ativada_em']) ? date('d/m/Y', strtotime($itemRelatorio['ativada_em'])) : '',
                !empty($itemRelatorio['renovacao_em']) ? date('d/m/Y', strtotime($itemRelatorio['renovacao_em'])) : '',
            ];
        }
    } elseif ($relatorioTipo === 'usuarios') {
        $tituloRelatorio = 'Relatório de Usuários';
        $cabecalhosRelatorio = ['Nome','Login','E-mail','Perfil','Status','Vínculo','Criado em','Último login'];
        foreach ($usuarios as $itemRelatorio) {
            if (!$dentroPeriodo($itemRelatorio['criado_em'] ?? null)) continue;
            $linhasRelatorio[] = [
                $itemRelatorio['nome'] ?? '',
                $itemRelatorio['usuario'] ?? '',
                $itemRelatorio['email'] ?? '',
                $itemRelatorio['perfil'] ?? '',
                !empty($itemRelatorio['ativo']) ? 'Ativo' : 'Inativo',
                $itemRelatorio['vinculo_status'] ?? 'ativo',
                !empty($itemRelatorio['criado_em']) ? date('d/m/Y H:i', strtotime($itemRelatorio['criado_em'])) : '',
                !empty($itemRelatorio['ultimo_login']) ? date('d/m/Y H:i', strtotime($itemRelatorio['ultimo_login'])) : '',
            ];
        }
    } elseif ($relatorioTipo === 'desligados') {
        $tituloRelatorio = 'Relatório de Usuários Desligados';
        $cabecalhosRelatorio = ['Nome','Login','E-mail','Perfil','Desligado em','Responsável','Ação','IP'];
        foreach ($usuariosDesligados as $itemRelatorio) {
            $dataReferencia = $itemRelatorio['historico_criado_em'] ?? $itemRelatorio['desligado_em'] ?? null;
            if (!$dentroPeriodo($dataReferencia)) continue;
            $linhasRelatorio[] = [
                $itemRelatorio['nome'] ?? '',
                $itemRelatorio['usuario'] ?? '',
                $itemRelatorio['email'] ?? '',
                $itemRelatorio['perfil'] ?? '',
                !empty($dataReferencia) ? date('d/m/Y H:i', strtotime($dataReferencia)) : '',
                $itemRelatorio['realizado_por_nome'] ?? $itemRelatorio['desligado_por_nome'] ?? 'Não identificado',
                $itemRelatorio['historico_acao'] ?? 'ENCERRAMENTO_DE_VINCULO',
                $itemRelatorio['historico_ip'] ?? '',
            ];
        }
    } elseif ($relatorioTipo === 'saude') {
        $tituloRelatorio = 'Relatório de Saúde do Sistema';
        $cabecalhosRelatorio = ['Indicador','Resultado','Situação'];
        $linhasRelatorio[] = ['Saúde geral', $percentualSaude . '%', $percentualSaude >= 85 ? 'Saudável' : 'Atenção'];
        $linhasRelatorio[] = ['PHP', PHP_VERSION, version_compare(PHP_VERSION, '8.0.0', '>=') ? 'Compatível' : 'Atualizar'];
        $linhasRelatorio[] = ['MySQL/MariaDB', $versaoBancoServidor, 'Conectado'];
        $linhasRelatorio[] = ['HTTPS', $httpsAtivo ? 'Ativo' : 'Inativo', $httpsAtivo ? 'OK' : 'Atenção no deploy'];
        $linhasRelatorio[] = ['Espaço em disco usado', $percentualDisco . '%', $percentualDisco < 85 ? 'OK' : 'Atenção'];
        foreach ($segurancaSistema as $nomeCheck => $statusCheck) {
            $linhasRelatorio[] = [$nomeCheck, $statusCheck ? 'Ativo' : 'Inativo', $statusCheck ? 'OK' : 'Atenção'];
        }
    } else {
        $tituloRelatorio = 'Resumo Administrativo Consolidado';
        $cabecalhosRelatorio = ['Indicador','Quantidade/Situação'];
        $linhasRelatorio = [
            ['Escritórios SaaS', count($escritoriosSaas)],
            ['Licenças SaaS', count($licencasSaas)],
            ['Usuários cadastrados', $totalUsuarios],
            ['Usuários ativos', $totalAtivos],
            ['Usuários desligados', count($usuariosDesligados)],
            ['Eventos no LOG', $totalLogs],
            ['Itens na lixeira', $totalLixeira],
            ['Saúde do sistema', $percentualSaude . '%'],
            ['Ambiente', $cfg['ambiente_sistema'] ?? 'desenvolvimento'],
            ['Versão do sistema', $cfg['versao_sistema'] ?? ''],
        ];
    }

    $periodoDescricao = ($relatorioDataInicio || $relatorioDataFim)
        ? 'Período: ' . ($relatorioDataInicio ? date('d/m/Y', strtotime($relatorioDataInicio)) : 'início') . ' até ' . ($relatorioDataFim ? date('d/m/Y', strtotime($relatorioDataFim)) : 'hoje')
        : 'Período: todos os registros';

    sgl_log($conn, 'Exportou relatório administrativo', 'relatorios_administrativos', null, "Tipo: {$relatorioTipo}; Formato: {$relatorioFormato}; {$periodoDescricao}");

    $nomeArquivoRelatorio = 'rojex_' . $relatorioTipo . '_' . date('Ymd_His');

    // A exportação é executada no navegador para evitar "headers already sent",
    // pois este módulo é carregado após o layout principal do index.php.
}


$manutencaoPreview = $_SESSION['rojex_manutencao_preview'] ?? null;
$manutencaoUltimoResultado = $_SESSION['rojex_manutencao_ultimo_resultado'] ?? null;
unset($_SESSION['rojex_manutencao_ultimo_resultado']);

$manutencoesRecentes = [];
if (sgl_tabela_existe($conn, 'manutencoes_sistema')) {
    try {
        $resManutencoes = $conn->query(
            "SELECT id, tipo, modo, status, resumo, executado_por_nome, criado_em
               FROM manutencoes_sistema
              ORDER BY id DESC
              LIMIT 20"
        );
        if ($resManutencoes) {
            while ($rowManutencao = $resManutencoes->fetch_assoc()) {
                $manutencoesRecentes[] = $rowManutencao;
            }
        }
    } catch (Throwable $e) {}
}

$manutencaoDiretorios = rojex_manutencao_diretorios_permitidos();
$manutencaoTabelas = rojex_manutencao_tabelas($conn);


$backupPreview = $_SESSION['rojex_backup_preview'] ?? null;
$backupUltimoResultado = $_SESSION['rojex_backup_ultimo_resultado'] ?? null;
unset($_SESSION['rojex_backup_ultimo_resultado']);

$backupsRecentes = [];
if (sgl_tabela_existe($conn, 'backups_sistema')) {
    try {
        $resBackups = $conn->query(
            "SELECT id,tipo,status,arquivo,nome_original,tamanho_bytes,hash_arquivo,escopo,
                    quantidade_arquivos,responsavel_nome,criado_em,verificado_em,verificacao_status
               FROM backups_sistema
              ORDER BY id DESC
              LIMIT 30"
        );
        if ($resBackups) {
            while ($rowBackup = $resBackups->fetch_assoc()) {
                $backupsRecentes[] = $rowBackup;
            }
        }
    } catch (Throwable $e) {}
}

$backupDiretorio = rojex_backup_diretorio();
$backupZipDisponivel = class_exists('ZipArchive');


$atualizacaoPreview = $_SESSION['rojex_atualizacao_preview'] ?? null;
$atualizacoesLista = [];

if (sgl_tabela_existe($conn, 'atualizacoes_sistema')) {
    try {
        $resAtualizacoes = $conn->query(
            "SELECT *
               FROM atualizacoes_sistema
              ORDER BY
                CASE status
                    WHEN 'disponivel' THEN 1
                    WHEN 'homologacao' THEN 2
                    WHEN 'planejada' THEN 3
                    WHEN 'instalada' THEN 4
                    ELSE 5
                END,
                id DESC"
        );
        if ($resAtualizacoes) {
            while ($rowAtualizacao = $resAtualizacoes->fetch_assoc()) {
                $atualizacoesLista[] = $rowAtualizacao;
            }
        }
    } catch (Throwable $e) {}
}

$versaoSistemaAtual = rojex_atualizacao_versao_atual($conn);
$ambienteSistemaAtual = rojex_atualizacao_ambiente($conn);
$versaoBancoAtual = rojex_atualizacao_banco_versao($conn);
$backupAtualizacaoRecente = rojex_atualizacao_backup_recente($conn, 30);
$totalAtualizacoesDisponiveis = 0;
$totalAtualizacoesInstaladas = 0;
foreach ($atualizacoesLista as $atualizacaoContador) {
    if ($atualizacaoContador['status'] === 'disponivel') $totalAtualizacoesDisponiveis++;
    if ($atualizacaoContador['status'] === 'instalada') $totalAtualizacoesInstaladas++;
}

$tabs_validas = ['escritorio','marca','tema','usuarios','sistema','administracao','novo_escritorio','planos','modulos','portal','desligados','relatorios','saude','manutencao','backup','atualizacoes','lixeira','logs'];

if (!in_array($tab_ativa, $tabs_validas, true)) { $tab_ativa = 'escritorio'; }
?>

<div class="d-flex justify-content-between align-items-start mb-4 flex-wrap gap-2">
    <div>
        <h3 class="mb-1 text-primary"><i class="bi bi-gear-fill me-2"></i>Configurações</h3>
        <p class="text-muted mb-0">Administração do escritório, usuários, identidade visual, segurança e manutenção do sistema.</p>
    </div>
    <div class="d-flex gap-2">
        <a href="?mod=dashboard" class="btn btn-outline-secondary"><i class="bi bi-speedometer2 me-1"></i> Dashboard</a>
    </div>
</div>

<?php if ($msg): ?>
    <div class="alert alert-<?=htmlspecialchars($msg_tipo)?> alert-dismissible fade show shadow-sm">
        <?=htmlspecialchars($msg)?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<div class="row g-3 mb-4">
    <div class="col-md-3"><div class="card shadow-sm border-0"><div class="card-body"><small class="text-muted">USUÁRIOS</small><h3 class="mb-0"><?= $totalUsuarios ?></h3><small class="text-success"><?= $totalAtivos ?> ativo(s)</small></div></div></div>
    <div class="col-md-3"><div class="card shadow-sm border-0"><div class="card-body"><small class="text-muted">LIXEIRA</small><h3 class="mb-0 text-danger"><?= $totalLixeira ?></h3><small class="text-muted">registro(s)</small></div></div></div>
    <div class="col-md-3"><div class="card shadow-sm border-0"><div class="card-body"><small class="text-muted">AUDITORIA</small><h3 class="mb-0 text-primary"><?= $totalLogs ?></h3><small class="text-muted">evento(s)</small></div></div></div>
    <div class="col-md-3"><div class="card shadow-sm border-0"><div class="card-body"><small class="text-muted">BANCO</small><h3 class="mb-0 text-success"><i class="bi bi-check-circle"></i></h3><small class="text-success">conectado</small></div></div></div>
</div>

<ul class="nav nav-tabs mb-4" id="cfgTabs" role="tablist">
    <?php
    $tabDefs = [
        'escritorio' => ['Escritório','bi-building'],
        'marca' => ['Marca','bi-image'],
        'tema' => ['Tema','bi-palette'],
        'usuarios' => ['Usuários','bi-people'],
        'sistema' => ['Sistema','bi-sliders'],
        'administracao' => ['Administração','bi-shield-lock-fill'],
        'novo_escritorio' => ['Novo Escritório','bi-building-add'],
        'planos' => ['Planos SaaS','bi-tags-fill'],
        'modulos' => ['Módulos SaaS','bi-grid-3x3-gap-fill'],
        'portal' => ['Portal do Cliente','bi-person-workspace'],
        'desligados' => ['Desligados','bi-person-x-fill'],
        'relatorios' => ['Relatórios','bi-file-earmark-bar-graph-fill'],
        'saude' => ['Saúde','bi-heart-pulse-fill'],
        'manutencao' => ['Manutenção','bi-tools'],
        'backup' => ['Backup','bi-cloud-arrow-up-fill'],
        'atualizacoes' => ['Atualizações','bi-arrow-repeat'],
        'lixeira' => ['Lixeira','bi-trash3'],
        'logs' => ['Logs','bi-clock-history'],
    ];
    foreach ($tabDefs as $id => $tab) :
        if (in_array($id, ['usuarios','sistema','administracao','novo_escritorio','planos','modulos','portal','desligados','relatorios','saude','manutencao','backup','atualizacoes','logs'], true) && !$ehUsuarioMaster) { continue; }
        $active = $tab_ativa === $id ? 'active' : '';
        $badge = ($id === 'lixeira' && $totalLixeira > 0) ? '<span class="badge bg-danger ms-1">' . $totalLixeira . '</span>' : '';
    ?>
    <li class="nav-item" role="presentation">
        <a class="nav-link <?=$active?>" href="?mod=configuracoes&tab=<?=$id?>"><i class="bi <?=$tab[1]?> me-1"></i><?=$tab[0]?><?=$badge?></a>
    </li>
    <?php endforeach; ?>
</ul>

<div class="tab-content">
<?php if ($tab_ativa === 'escritorio'): ?>
<form method="POST">
    <input type="hidden" name="csrf_token" value="<?=htmlspecialchars($csrf)?>">
    <input type="hidden" name="acao_cfg" value="salvar_escritorio">

    <div class="card shadow-sm border-0 mb-4">
        <div class="card-header bg-dark text-white"><i class="bi bi-building me-1"></i> Dados Institucionais</div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-6"><label class="form-label">Nome fantasia / Escritório</label><input name="nome_escritorio" class="form-control" value="<?=htmlspecialchars($cfg['nome_escritorio'])?>"></div>
                <div class="col-md-6"><label class="form-label">Razão social</label><input name="razao_social_escritorio" class="form-control" value="<?=htmlspecialchars($cfg['razao_social_escritorio'])?>"></div>
                <div class="col-md-3"><label class="form-label">Identificador Enterprise</label><input class="form-control bg-light" value="<?=htmlspecialchars($cfg['identificador_escritorio'])?>" readonly><div class="form-text">Código técnico permanente do cadastro.</div></div>
                <div class="col-md-3"><label class="form-label">Código interno</label><input name="codigo_interno_escritorio" class="form-control text-uppercase" maxlength="40" value="<?=htmlspecialchars($cfg['codigo_interno_escritorio'])?>" placeholder="Ex.: MATRIZ-SP"></div>
                <div class="col-md-3"><label class="form-label">Tipo de organização</label><select name="tipo_escritorio" class="form-select"><option value="escritorio_advocacia" <?=$cfg['tipo_escritorio']==='escritorio_advocacia'?'selected':''?>>Escritório de advocacia</option><option value="advogado_autonomo" <?=$cfg['tipo_escritorio']==='advogado_autonomo'?'selected':''?>>Advogado autônomo</option><option value="departamento_juridico" <?=$cfg['tipo_escritorio']==='departamento_juridico'?'selected':''?>>Departamento jurídico</option><option value="consultoria_juridica" <?=$cfg['tipo_escritorio']==='consultoria_juridica'?'selected':''?>>Consultoria jurídica</option><option value="outro" <?=$cfg['tipo_escritorio']==='outro'?'selected':''?>>Outro</option></select></div>
                <div class="col-md-3"><label class="form-label">Status operacional</label><select name="status_operacional_escritorio" class="form-select"><option value="ativo" <?=$cfg['status_operacional_escritorio']==='ativo'?'selected':''?>>Ativo</option><option value="implantacao" <?=$cfg['status_operacional_escritorio']==='implantacao'?'selected':''?>>Em implantação</option><option value="suspenso" <?=$cfg['status_operacional_escritorio']==='suspenso'?'selected':''?>>Suspenso</option></select></div>
                <div class="col-md-3"><label class="form-label">CPF/CNPJ</label><input name="cpf_cnpj_escritorio" class="form-control" value="<?=htmlspecialchars($cfg['cpf_cnpj_escritorio'])?>"></div>
                <div class="col-md-3"><label class="form-label">Inscrição Estadual</label><input name="inscricao_estadual_escritorio" class="form-control" value="<?=htmlspecialchars($cfg['inscricao_estadual_escritorio'])?>"></div>
                <div class="col-md-3"><label class="form-label">Inscrição Municipal</label><input name="inscricao_municipal_escritorio" class="form-control" value="<?=htmlspecialchars($cfg['inscricao_municipal_escritorio'])?>"></div>
                <div class="col-md-3"><label class="form-label">Início das atividades</label><input type="date" name="data_inicio_atividades_escritorio" class="form-control" value="<?=htmlspecialchars($cfg['data_inicio_atividades_escritorio'])?>"></div>
            </div>
        </div>
    </div>

    <div class="card shadow-sm border-0 mb-4">
        <div class="card-header bg-dark text-white"><i class="bi bi-diagram-3 me-1"></i> Administração Enterprise</div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-4"><label class="form-label">Responsável administrativo</label><input name="responsavel_administrativo_escritorio" class="form-control" value="<?=htmlspecialchars($cfg['responsavel_administrativo_escritorio'])?>"></div>
                <div class="col-md-4"><label class="form-label">E-mail administrativo</label><input type="email" name="email_administrativo_escritorio" class="form-control" value="<?=htmlspecialchars($cfg['email_administrativo_escritorio'])?>"></div>
                <div class="col-md-4"><label class="form-label">Fuso horário</label><select name="timezone_escritorio" class="form-select"><?php foreach (['America/Sao_Paulo'=>'Brasília / São Paulo','America/Manaus'=>'Manaus','America/Cuiaba'=>'Cuiabá','America/Recife'=>'Recife','America/Fortaleza'=>'Fortaleza','America/Belem'=>'Belém','America/Rio_Branco'=>'Rio Branco','UTC'=>'UTC'] as $tzValor => $tzNome): ?><option value="<?=htmlspecialchars($tzValor)?>" <?=$cfg['timezone_escritorio']===$tzValor?'selected':''?>><?=htmlspecialchars($tzNome)?></option><?php endforeach; ?></select></div>
                <div class="col-md-6"><div class="border rounded p-3 bg-light h-100"><small class="text-muted d-block">IDIOMA PADRÃO</small><strong>Português do Brasil (pt-BR)</strong></div></div>
                <div class="col-md-6"><div class="border rounded p-3 bg-light h-100"><small class="text-muted d-block">MOEDA PADRÃO</small><strong>Real brasileiro (BRL)</strong></div></div>
            </div>
        </div>
    </div>

    <div class="card shadow-sm border-0 mb-4">
        <div class="card-header bg-dark text-white"><i class="bi bi-person-badge me-1"></i> Responsável jurídico</div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-5"><label class="form-label">Responsável técnico</label><input name="responsavel_escritorio" class="form-control" value="<?=htmlspecialchars($cfg['responsavel_escritorio'])?>"></div>
                <div class="col-md-3"><label class="form-label">OAB</label><input name="oab_responsavel" class="form-control" value="<?=htmlspecialchars($cfg['oab_responsavel'])?>"></div>
                <div class="col-md-4"><label class="form-label">CPF do responsável</label><input name="cpf_responsavel" class="form-control" value="<?=htmlspecialchars($cfg['cpf_responsavel'])?>"></div>
            </div>
        </div>
    </div>

    <div class="card shadow-sm border-0 mb-4">
        <div class="card-header bg-dark text-white"><i class="bi bi-telephone me-1"></i> Contato</div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-3"><label class="form-label">Telefone</label><input name="telefone_escritorio" class="form-control" value="<?=htmlspecialchars($cfg['telefone_escritorio'])?>"></div>
                <div class="col-md-3"><label class="form-label">Celular</label><input name="celular_escritorio" class="form-control" value="<?=htmlspecialchars($cfg['celular_escritorio'])?>"></div>
                <div class="col-md-3"><label class="form-label">WhatsApp</label><input name="whatsapp_escritorio" class="form-control" value="<?=htmlspecialchars($cfg['whatsapp_escritorio'])?>"></div>
                <div class="col-md-3"><label class="form-label">E-mail</label><input type="email" name="email_escritorio" class="form-control" value="<?=htmlspecialchars($cfg['email_escritorio'])?>"></div>
                <div class="col-md-6"><label class="form-label">Site</label><input name="site_escritorio" class="form-control" value="<?=htmlspecialchars($cfg['site_escritorio'])?>"></div>
            </div>
        </div>
    </div>

    <div class="card shadow-sm border-0 mb-4">
        <div class="card-header bg-dark text-white"><i class="bi bi-geo-alt me-1"></i> Endereço</div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-2"><label class="form-label">CEP</label><input name="cep_escritorio" class="form-control" value="<?=htmlspecialchars($cfg['cep_escritorio'])?>"></div>
                <div class="col-md-5"><label class="form-label">Endereço</label><input name="endereco_escritorio" class="form-control" value="<?=htmlspecialchars($cfg['endereco_escritorio'])?>"></div>
                <div class="col-md-2"><label class="form-label">Número</label><input name="numero_escritorio" class="form-control" value="<?=htmlspecialchars($cfg['numero_escritorio'])?>"></div>
                <div class="col-md-3"><label class="form-label">Complemento</label><input name="complemento_escritorio" class="form-control" value="<?=htmlspecialchars($cfg['complemento_escritorio'])?>"></div>
                <div class="col-md-3"><label class="form-label">Bairro</label><input name="bairro_escritorio" class="form-control" value="<?=htmlspecialchars($cfg['bairro_escritorio'])?>"></div>
                <div class="col-md-4"><label class="form-label">Cidade</label><input name="cidade_escritorio" class="form-control" value="<?=htmlspecialchars($cfg['cidade_escritorio'])?>"></div>
                <div class="col-md-2"><label class="form-label">UF</label><input name="uf_escritorio" maxlength="2" class="form-control text-uppercase" value="<?=htmlspecialchars($cfg['uf_escritorio'])?>"></div>
                <div class="col-md-3"><label class="form-label">País</label><input name="pais_escritorio" class="form-control" value="<?=htmlspecialchars($cfg['pais_escritorio'])?>"></div>
            </div>
        </div>
    </div>

    <div class="card shadow-sm border-0 mb-4">
        <div class="card-header bg-dark text-white"><i class="bi bi-share me-1"></i> Redes sociais e documentos</div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-4"><label class="form-label">Instagram</label><input name="instagram_escritorio" class="form-control" value="<?=htmlspecialchars($cfg['instagram_escritorio'])?>"></div>
                <div class="col-md-4"><label class="form-label">Facebook</label><input name="facebook_escritorio" class="form-control" value="<?=htmlspecialchars($cfg['facebook_escritorio'])?>"></div>
                <div class="col-md-4"><label class="form-label">LinkedIn</label><input name="linkedin_escritorio" class="form-control" value="<?=htmlspecialchars($cfg['linkedin_escritorio'])?>"></div>
                <div class="col-12"><label class="form-label">Rodapé padrão para documentos e recibos</label><input name="rodape_documentos" class="form-control" value="<?=htmlspecialchars($cfg['rodape_documentos'])?>" placeholder="Ex.: Documento emitido pelo ROJEX.AI"></div>
            </div>
        </div>
    </div>

    <div class="text-end mb-4"><button class="btn btn-primary"><i class="bi bi-floppy me-1"></i>Salvar dados do escritório</button></div>
</form>
<?php endif; ?>

<?php if ($tab_ativa === 'marca'): ?>
<div class="row g-4">
    <div class="col-lg-5">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-header bg-dark text-white"><i class="bi bi-image me-1"></i> Logomarca do Escritório</div>
            <div class="card-body">
                <div class="text-center mb-4">
                    <p class="text-muted small mb-2">Logo interna atual</p>
                    <img src="<?=htmlspecialchars($logo_exibir, ENT_QUOTES, 'UTF-8')?>?v=<?=time()?>" class="img-thumbnail bg-light" style="max-width:260px;max-height:220px;object-fit:contain;" alt="Logo atual">
                    <div class="form-text mt-2">A identidade da tela de login ROJEX.AI não é alterada por esta configuração.</div>
                </div>

                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token" value="<?=htmlspecialchars($csrf)?>">
                    <input type="hidden" name="acao_cfg" value="upload_logo">
                    <label class="form-label fw-semibold">Substituir logo interna</label>
                    <input type="file" name="logo" class="form-control" accept=".jpg,.jpeg,.png,.webp" required onchange="prevLogo(this)">
                    <div class="form-text">JPG, PNG ou WebP, até 2 MB. A alteração afeta somente este ambiente autenticado.</div>
                    <div id="prev_wrap" style="display:none;" class="mt-3 text-center"><img id="prev_img" src="#" class="img-thumbnail" style="max-width:220px;max-height:140px;object-fit:contain;" alt="Prévia"></div>
                    <button class="btn btn-primary mt-3"><i class="bi bi-upload me-1"></i>Enviar logomarca</button>
                </form>

                <?php if ($cfg['logo_arquivo']): ?>
                <form method="POST" class="mt-3">
                    <input type="hidden" name="csrf_token" value="<?=htmlspecialchars($csrf)?>">
                    <input type="hidden" name="acao_cfg" value="remover_logo">
                    <button class="btn btn-outline-danger btn-sm" onclick="return confirm('Remover logo personalizada?')"><i class="bi bi-x-circle me-1"></i>Remover logo personalizada</button>
                </form>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-lg-7">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-header bg-dark text-white"><i class="bi bi-badge-ad me-1"></i> Identidade da Marca</div>
            <div class="card-body">
                <form method="POST" class="row g-3">
                    <input type="hidden" name="csrf_token" value="<?=htmlspecialchars($csrf)?>">
                    <input type="hidden" name="acao_cfg" value="salvar_marca">
                    <div class="col-12">
                        <label class="form-label">Nome de exibição da marca</label>
                        <input name="nome_marca_exibicao" class="form-control" maxlength="80" value="<?=htmlspecialchars($cfg['nome_marca_exibicao'])?>" placeholder="Ex.: SGL Advocacia">
                        <div class="form-text">Campo visual opcional. Não altera o nome oficial do produto ROJEX.AI.</div>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Slogan institucional</label>
                        <input name="slogan_marca" class="form-control" maxlength="160" value="<?=htmlspecialchars($cfg['slogan_marca'])?>" placeholder="Ex.: Excelência jurídica com tecnologia">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Posição preferencial da logo</label>
                        <select name="posicao_logo" class="form-select">
                            <option value="esquerda" <?=$cfg['posicao_logo']==='esquerda'?'selected':''?>>À esquerda</option>
                            <option value="centro" <?=$cfg['posicao_logo']==='centro'?'selected':''?>>Centralizada</option>
                        </select>
                    </div>
                    <div class="col-md-6 d-flex flex-column justify-content-end gap-2 pb-1">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" name="exibir_nome_menu" value="1" <?=$cfg['exibir_nome_menu']==='1'?'checked':''?>>
                            <label class="form-check-label">Exibir nome junto à logo</label>
                        </div>
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" name="exibir_slogan_documentos" value="1" <?=$cfg['exibir_slogan_documentos']==='1'?'checked':''?>>
                            <label class="form-check-label">Preparar slogan para documentos</label>
                        </div>
                    </div>
                    <div class="col-12">
                        <div class="alert alert-info small mb-0"><i class="bi bi-shield-check me-1"></i>Estas preferências ficam armazenadas para integração gradual nos menus, recibos e documentos, sem quebrar os layouts atuais.</div>
                    </div>
                    <div class="col-12"><button class="btn btn-primary"><i class="bi bi-floppy me-1"></i>Salvar identidade da marca</button></div>
                </form>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($tab_ativa === 'tema'): ?>
<div class="card shadow-sm border-0 mb-4">
    <div class="card-header bg-dark text-white"><i class="bi bi-palette me-1"></i> Tema Enterprise</div>
    <div class="card-body">
        <form method="POST" id="formTemaEnterprise" class="row g-4">
            <input type="hidden" name="csrf_token" value="<?=htmlspecialchars($csrf)?>">
            <input type="hidden" name="acao_cfg" value="salvar_tema">

            <div class="col-lg-7">
                <div class="row g-3">
                    <?php foreach ([
                        ['cor_primaria','Cor primária / menu'],
                        ['cor_secundaria','Cor secundária / itens ativos'],
                        ['cor_accent','Cor de destaque'],
                        ['cor_fundo','Cor de fundo'],
                        ['cor_texto','Cor principal do texto']
                    ] as $c): ?>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold"><?=$c[1]?></label>
                        <div class="input-group">
                            <input type="color" name="<?=$c[0]?>" id="<?=$c[0]?>" class="form-control form-control-color" value="<?=htmlspecialchars($cfg[$c[0]])?>" <?=$ehUsuarioMaster?'':'disabled'?> oninput="syncCor('<?=$c[0]?>');atualizarPreviewTema();">
                            <input type="text" id="<?=$c[0]?>_txt" class="form-control" value="<?=htmlspecialchars($cfg[$c[0]])?>" maxlength="7" style="font-family:monospace" <?=$ehUsuarioMaster?'':'disabled'?> oninput="syncTxt('<?=$c[0]?>');atualizarPreviewTema();">
                        </div>
                    </div>
                    <?php endforeach; ?>

                    <div class="col-12">
                        <?php if ($ehUsuarioMaster): ?>
                            <div class="alert alert-primary small mb-0">
                                <i class="bi bi-building-gear me-1"></i>
                                As cores acima são institucionais e afetam todos os usuários deste escritório.
                            </div>
                        <?php else: ?>
                            <div class="alert alert-secondary small mb-0">
                                <i class="bi bi-lock me-1"></i>
                                As cores institucionais são administradas pelo usuário MASTER. As opções abaixo são exclusivas da sua conta.
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Modo visual</label>
                        <select name="tema_modo" id="tema_modo" class="form-select" onchange="atualizarPreviewTema()">
                            <option value="claro" <?=$cfg['tema_modo']==='claro'?'selected':''?>>Claro</option>
                            <option value="escuro" <?=$cfg['tema_modo']==='escuro'?'selected':''?>>Escuro</option>
                            <option value="automatico" <?=$cfg['tema_modo']==='automatico'?'selected':''?>>Automático pelo dispositivo</option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Densidade dos componentes</label>
                        <select name="tema_densidade" id="tema_densidade" class="form-select" onchange="atualizarPreviewTema()">
                            <option value="confortavel" <?=$cfg['tema_densidade']==='confortavel'?'selected':''?>>Confortável</option>
                            <option value="compacta" <?=$cfg['tema_densidade']==='compacta'?'selected':''?>>Compacta</option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Estilo das bordas</label>
                        <select name="tema_bordas" id="tema_bordas" class="form-select" onchange="atualizarPreviewTema()">
                            <option value="retas" <?=$cfg['tema_bordas']==='retas'?'selected':''?>>Retas</option>
                            <option value="suaves" <?=$cfg['tema_bordas']==='suaves'?'selected':''?>>Suaves</option>
                            <option value="arredondadas" <?=$cfg['tema_bordas']==='arredondadas'?'selected':''?>>Arredondadas</option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Escala da fonte: <span id="temaFonteValor"><?=htmlspecialchars($cfg['tema_fonte_percentual'])?>%</span></label>
                        <input type="range" name="tema_fonte_percentual" id="tema_fonte_percentual" class="form-range" min="90" max="115" step="5" value="<?=htmlspecialchars($cfg['tema_fonte_percentual'])?>" oninput="atualizarPreviewTema()">
                    </div>
                </div>
            </div>

            <div class="col-lg-5">
                <label class="form-label fw-semibold">Prévia segura</label>
                <div id="previewTemaEnterprise" class="border shadow-sm overflow-hidden">
                    <div class="preview-tema-menu d-flex align-items-center justify-content-between">
                        <strong><i class="bi bi-grid me-1"></i> ROJEX.AI</strong><span class="badge preview-tema-badge">Enterprise</span>
                    </div>
                    <div class="preview-tema-corpo">
                        <h5 class="mb-2">Painel do escritório</h5>
                        <p class="mb-3 preview-tema-muted">Exemplo de visualização antes da aplicação global.</p>
                        <div class="preview-tema-card border mb-3">
                            <small>PROCESSOS ATIVOS</small><h4 class="mb-0">128</h4>
                        </div>
                        <button type="button" class="btn preview-tema-btn">Ação principal</button>
                    </div>
                </div>
                <div class="form-text mt-2">Nesta etapa, as escolhas são gravadas e visualizadas aqui. A aplicação geral será feita de forma controlada para preservar todos os módulos.</div>
            </div>

            <div class="col-12 d-flex gap-2 flex-wrap">
                <button class="btn btn-primary"><i class="bi bi-floppy me-1"></i><?=$ehUsuarioMaster?'Salvar tema Enterprise':'Salvar minhas preferências'?></button>
        </form>
                <form method="POST" onsubmit="return confirm('<?=$ehUsuarioMaster?'Restaurar o tema institucional e suas preferências?':'Restaurar somente suas preferências visuais?'?>')">
                    <input type="hidden" name="csrf_token" value="<?=htmlspecialchars($csrf)?>">
                    <input type="hidden" name="acao_cfg" value="restaurar_tema">
                    <button class="btn btn-outline-secondary"><i class="bi bi-arrow-counterclockwise me-1"></i>Restaurar padrão</button>
                </form>
            </div>
    </div>
</div>
<?php endif; ?>

<?php if ($tab_ativa === 'usuarios'): ?>
<div class="row g-3 mb-4">
    <div class="col-md-2"><div class="card border-0 shadow-sm h-100"><div class="card-body"><small class="text-muted">TOTAL</small><h4 class="mb-0"><?= $totalUsuarios ?></h4><small><?= $limiteUsuariosLicenca ?> permitido(s)</small></div></div></div>
    <div class="col-md-2"><div class="card border-0 shadow-sm h-100"><div class="card-body"><small class="text-muted">ATIVOS</small><h4 class="mb-0 text-success"><?= $totalAtivos ?></h4><small><?= $totalInativos ?> inativo(s)</small></div></div></div>
    <div class="col-md-2"><div class="card border-0 shadow-sm h-100"><div class="card-body"><small class="text-muted">ADMINISTRAÇÃO</small><h4 class="mb-0 text-primary"><?= $totalAdministradores ?></h4><small>administrador(es)</small></div></div></div>
    <div class="col-md-2"><div class="card border-0 shadow-sm h-100"><div class="card-body"><small class="text-muted">ADVOGADOS</small><h4 class="mb-0"><?= $totalAdvogadosUsuarios ?></h4><small>perfil jurídico</small></div></div></div>
    <div class="col-md-2"><div class="card border-0 shadow-sm h-100"><div class="card-body"><small class="text-muted">FINANCEIRO</small><h4 class="mb-0"><?= $totalFinanceiroUsuarios ?></h4><small>perfil financeiro</small></div></div></div>
    <div class="col-md-2"><div class="card border-0 shadow-sm h-100"><div class="card-body"><small class="text-muted">LICENÇA</small><h4 class="mb-0"><?= $percentualLicencaUsuarios ?>%</h4><div class="progress mt-2" style="height:6px"><div class="progress-bar" style="width:<?=$percentualLicencaUsuarios?>%"></div></div></div></div></div>
</div>

<div class="alert alert-info border-0 shadow-sm">
    <i class="bi bi-shield-check me-1"></i>
    A estrutura Enterprise está preparada para perfis, departamentos e limite de licença.
    A política atual de senha com mínimo de 6 caracteres foi preservada nesta fase.
</div>

<div class="row g-4">
    <div class="col-xl-4">
        <div class="card shadow-sm border-0">
            <div class="card-header bg-dark text-white"><i class="bi bi-person-plus me-1"></i> Novo Usuário Enterprise</div>
            <div class="card-body">
                <form method="POST" class="row g-3">
                    <input type="hidden" name="csrf_token" value="<?=htmlspecialchars($csrf)?>">
                    <input type="hidden" name="acao_cfg" value="novo_usuario">

                    <div class="col-12"><label class="form-label">Nome completo</label><input name="nome" class="form-control" maxlength="120" required></div>
                    <div class="col-md-6"><label class="form-label">Login</label><input name="usuario" class="form-control" maxlength="80" required></div>
                    <div class="col-md-6"><label class="form-label">Telefone</label><input name="telefone" class="form-control" maxlength="40"></div>
                    <div class="col-12"><label class="form-label">E-mail</label><input type="email" name="email" class="form-control" maxlength="120"></div>
                    <div class="col-md-6"><label class="form-label">Cargo</label><input name="cargo" class="form-control" maxlength="100" placeholder="Ex.: Sócio, Analista"></div>
                    <div class="col-md-6"><label class="form-label">Departamento</label><input name="departamento" class="form-control" maxlength="100" placeholder="Ex.: Jurídico"></div>
                    <div class="col-12">
                        <label class="form-label">Perfil</label>
                        <select name="perfil" class="form-select">
                            <?php foreach (['Administrador Master','Administrador','Advogado','Coordenador','Financeiro','Atendente','Estagiário','Consulta','Auditor','Usuário'] as $perfilOpcao): ?>
                                <option value="<?=htmlspecialchars($perfilOpcao)?>"><?=htmlspecialchars($perfilOpcao)?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12"><label class="form-label">Observações</label><textarea name="observacoes" class="form-control" rows="2" maxlength="1000"></textarea></div>
                    <div class="col-12">
                        <label class="form-label">Senha inicial</label>
                        <input type="password" name="senha" class="form-control" minlength="6" required>
                        <div class="form-text">Política atual preservada: mínimo de 6 caracteres.</div>
                    </div>
                    <div class="col-12"><button class="btn btn-primary w-100"><i class="bi bi-plus-circle me-1"></i>Criar usuário</button></div>
                </form>
            </div>
        </div>
    </div>

    <div class="col-xl-8">
        <div class="card shadow-sm border-0">
            <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center">
                <span><i class="bi bi-people me-1"></i> Usuários do Sistema</span>
                <span><?=count($usuarios)?> de <?=$limiteUsuariosLicenca?></span>
            </div>

            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr><th>Usuário</th><th>Perfil / setor</th><th>Último acesso</th><th>Status</th><th class="text-end">Ações</th></tr>
                    </thead>
                    <tbody>
                    <?php if(empty($usuarios)): ?>
                        <tr><td colspan="5" class="text-center py-4 text-muted">Nenhum usuário encontrado.</td></tr>
                    <?php endif; ?>

                    <?php foreach($usuarios as $u): ?>
                    <?php
                        $perfilUsuario = (string)($u['perfil'] ?? 'Usuário');
                        $badgePerfil = in_array($perfilUsuario, ['Administrador','Administrador Master'], true)
                            ? 'bg-primary'
                            : ($perfilUsuario === 'Financeiro' ? 'bg-success' : 'bg-secondary');
                    ?>
                    <tr>
                        <td>
                            <strong><?=htmlspecialchars($u['nome'])?></strong>
                            <div><code><?=htmlspecialchars($u['usuario'])?></code></div>
                            <small class="text-muted"><?=htmlspecialchars($u['email'] ?? '')?></small>
                        </td>
                        <td>
                            <span class="badge <?=$badgePerfil?>"><?=htmlspecialchars($perfilUsuario)?></span>
                            <?php if (!empty($u['cargo'])): ?><div class="small mt-1"><?=htmlspecialchars($u['cargo'])?></div><?php endif; ?>
                            <?php if (!empty($u['departamento'])): ?><small class="text-muted"><?=htmlspecialchars($u['departamento'])?></small><?php endif; ?>
                        </td>
                        <td>
                            <?php if (!empty($u['ultimo_login'])): ?>
                                <?=date('d/m/Y H:i', strtotime($u['ultimo_login']))?>
                            <?php else: ?>
                                <span class="text-muted">Nunca acessou</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ((int)$u['id'] === $usuarioMasterId): ?>
                                <span class="badge bg-warning text-dark">MASTER</span>
                            <?php elseif (($u['vinculo_status'] ?? '') === 'encerrado'): ?>
                                <span class="badge bg-dark">Vínculo encerrado</span>
                            <?php elseif ((int)$u['ativo'] === 1): ?>
                                <span class="badge bg-success">Ativo</span>
                            <?php else: ?>
                                <span class="badge bg-danger">Inativo</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end">
                            <button class="btn btn-sm btn-outline-primary" type="button" data-bs-toggle="collapse" data-bs-target="#editarUsuario<?= (int)$u['id'] ?>">
                                <i class="bi bi-pencil-square"></i> Editar
                            </button>

                            <?php if ((int)$u['id'] !== $usuarioMasterId): ?>
                            <form method="POST" class="d-inline">
                                <input type="hidden" name="csrf_token" value="<?=htmlspecialchars($csrf)?>">
                                <input type="hidden" name="acao_cfg" value="alterar_status_usuario">
                                <input type="hidden" name="usuario_id" value="<?= (int)$u['id'] ?>">
                                <input type="hidden" name="ativo" value="<?=((int)$u['ativo']===1)?0:1?>">
                                <button class="btn btn-sm <?=((int)$u['ativo']===1)?'btn-outline-danger':'btn-outline-success'?>" onclick="return confirm('Alterar status deste usuário?')">
                                    <?=((int)$u['ativo']===1)?'Desativar':'Ativar'?>
                                </button>
                            </form>

                            <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#senha<?= (int)$u['id'] ?>">Senha</button>

                            <?php if (($u['vinculo_status'] ?? '') !== 'encerrado'): ?>
                            <form method="POST" class="d-inline" onsubmit="return confirm('Encerrar definitivamente o vínculo deste usuário? O acesso será bloqueado, mas todo o cadastro será preservado para auditoria e prova futura.');">
                                <input type="hidden" name="csrf_token" value="<?=htmlspecialchars($csrf)?>">
                                <input type="hidden" name="acao_cfg" value="encerrar_vinculo_usuario">
                                <input type="hidden" name="usuario_id" value="<?= (int)$u['id'] ?>">
                                <button class="btn btn-sm btn-dark"><i class="bi bi-person-x me-1"></i>Encerrar vínculo</button>
                            </form>
                            <?php endif; ?>
                            <?php else: ?>
                                <span class="badge bg-warning text-dark"><i class="bi bi-shield-lock me-1"></i>Protegido</span>
                            <?php endif; ?>
                        </td>
                    </tr>

                    <tr class="collapse bg-light" id="editarUsuario<?= (int)$u['id'] ?>">
                        <td colspan="5">
                            <form method="POST" class="row g-3 p-2">
                                <input type="hidden" name="csrf_token" value="<?=htmlspecialchars($csrf)?>">
                                <input type="hidden" name="acao_cfg" value="editar_usuario">
                                <input type="hidden" name="usuario_id" value="<?= (int)$u['id'] ?>">

                                <div class="col-md-4"><label class="form-label">Nome</label><input name="nome" class="form-control form-control-sm" value="<?=htmlspecialchars($u['nome'])?>" required></div>
                                <div class="col-md-4"><label class="form-label">E-mail</label><input type="email" name="email" class="form-control form-control-sm" value="<?=htmlspecialchars($u['email'] ?? '')?>"></div>
                                <div class="col-md-4"><label class="form-label">Telefone</label><input name="telefone" class="form-control form-control-sm" value="<?=htmlspecialchars($u['telefone'] ?? '')?>"></div>
                                <div class="col-md-4"><label class="form-label">Cargo</label><input name="cargo" class="form-control form-control-sm" value="<?=htmlspecialchars($u['cargo'] ?? '')?>"></div>
                                <div class="col-md-4"><label class="form-label">Departamento</label><input name="departamento" class="form-control form-control-sm" value="<?=htmlspecialchars($u['departamento'] ?? '')?>"></div>
                                <div class="col-md-4">
                                    <label class="form-label">Perfil</label>
                                    <select name="perfil" class="form-select form-select-sm">
                                        <?php foreach (['Administrador Master','Administrador','Advogado','Coordenador','Financeiro','Atendente','Estagiário','Consulta','Auditor','Usuário'] as $perfilOpcao): ?>
                                            <option value="<?=htmlspecialchars($perfilOpcao)?>" <?=$perfilUsuario===$perfilOpcao?'selected':''?>><?=htmlspecialchars($perfilOpcao)?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-12"><label class="form-label">Observações</label><textarea name="observacoes" class="form-control form-control-sm" rows="2"><?=htmlspecialchars($u['observacoes'] ?? '')?></textarea></div>
                                <div class="col-12 text-end"><button class="btn btn-sm btn-primary"><i class="bi bi-floppy me-1"></i>Salvar alterações</button></div>
                            </form>
                        </td>
                    </tr>

                    <tr class="collapse bg-light" id="senha<?= (int)$u['id'] ?>">
                        <td colspan="5">
                            <form method="POST" class="d-flex gap-2 justify-content-end align-items-center p-2">
                                <input type="hidden" name="csrf_token" value="<?=htmlspecialchars($csrf)?>">
                                <input type="hidden" name="acao_cfg" value="resetar_senha_usuario">
                                <input type="hidden" name="usuario_id" value="<?= (int)$u['id'] ?>">
                                <span class="small text-muted">Política atual: mínimo de 6 caracteres.</span>
                                <input type="password" name="nova_senha" class="form-control form-control-sm" style="max-width:260px" minlength="6" placeholder="Nova senha" required>
                                <button class="btn btn-primary btn-sm">Redefinir senha</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card shadow-sm border-0 mt-4">
            <div class="card-header bg-dark text-white"><i class="bi bi-diagram-3 me-1"></i> Preparação de Permissões</div>
            <div class="card-body">
                <div class="row g-2">
                    <?php foreach (['Dashboard','Jurídico','Financeiro','Agenda','Documentos','Modelos','Configurações','LOG','Lixeira','CIJ','IA','Administração'] as $moduloPermissao): ?>
                        <div class="col-md-4"><div class="border rounded p-2 bg-light"><i class="bi bi-check2-circle text-success me-1"></i><?=htmlspecialchars($moduloPermissao)?></div></div>
                    <?php endforeach; ?>
                </div>
                <div class="form-text mt-3">A matriz ACL completa será ativada em etapa própria, após o fechamento da estrutura administrativa.</div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($tab_ativa === 'sistema'): ?>
<div class="alert alert-warning border-0 shadow-sm">
    <i class="bi bi-shield-lock me-1"></i>
    Área exclusiva do usuário MASTER. As configurações abaixo preparam o ROJEX.AI para produção e modelo SaaS sem ativar integrações externas nesta etapa.
</div>

<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-body">
                <small class="text-muted">SAÚDE DO SISTEMA</small>
                <h3 class="mb-1 <?=$percentualSaude>=85?'text-success':($percentualSaude>=60?'text-warning':'text-danger')?>"><?=$percentualSaude?>%</h3>
                <div class="progress" style="height:7px"><div class="progress-bar" style="width:<?=$percentualSaude?>%"></div></div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-body">
                <small class="text-muted">AMBIENTE</small>
                <h4 class="mb-0 text-uppercase"><?=htmlspecialchars($cfg['ambiente_sistema'])?></h4>
                <small>ROJEX.AI <?=htmlspecialchars($cfg['versao_sistema'])?></small>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-body">
                <small class="text-muted">LICENÇA</small>
                <h4 class="mb-0 text-capitalize"><?=htmlspecialchars($cfg['status_licenca'])?></h4>
                <small class="text-capitalize"><?=htmlspecialchars($cfg['plano_licenca'])?></small>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-body">
                <small class="text-muted">ARMAZENAMENTO DO SERVIDOR</small>
                <h4 class="mb-0"><?=sgl_formatar_bytes($espacoLivreDisco)?></h4>
                <small>livres de <?=sgl_formatar_bytes($espacoTotalDisco)?></small>
            </div>
        </div>
    </div>
</div>

<form method="POST">
    <input type="hidden" name="csrf_token" value="<?=htmlspecialchars($csrf)?>">
    <input type="hidden" name="acao_cfg" value="salvar_sistema">

    <div class="row g-4">
        <div class="col-xl-6">
            <div class="card shadow-sm border-0 mb-4">
                <div class="card-header bg-dark text-white"><i class="bi bi-sliders me-1"></i> Sistema e Ambiente</div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Ambiente</label>
                            <select name="ambiente_sistema" class="form-select">
                                <option value="desenvolvimento" <?=$cfg['ambiente_sistema']==='desenvolvimento'?'selected':''?>>Desenvolvimento</option>
                                <option value="homologacao" <?=$cfg['ambiente_sistema']==='homologacao'?'selected':''?>>Homologação</option>
                                <option value="producao" <?=$cfg['ambiente_sistema']==='producao'?'selected':''?>>Produção</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Versão ROJEX</label>
                            <input name="versao_sistema" class="form-control" maxlength="30" value="<?=htmlspecialchars($cfg['versao_sistema'])?>">
                            <div class="form-text text-success">
                                Sprint <?=htmlspecialchars($cfg['sprint_atual'] ?? '4.1.3')?> <?=($cfg['status_homologacao'] ?? '') === 'homologada' ? 'homologada' : htmlspecialchars($cfg['status_homologacao'] ?? '')?>
                                <?php if (!empty($cfg['data_homologacao'])): ?>
                                    em <?=date('d/m/Y H:i', strtotime($cfg['data_homologacao']))?>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="col-md-3"><label class="form-label">Versão SQL</label><input name="versao_banco" class="form-control" maxlength="30" value="<?=htmlspecialchars($cfg['versao_banco'])?>"></div>
                        <div class="col-md-6"><label class="form-label">Alertar prazos em até X dias</label><input type="number" min="1" max="60" name="dias_alerta_prazos" class="form-control" value="<?=htmlspecialchars($cfg['dias_alerta_prazos'])?>"></div>
                        <div class="col-md-6"><label class="form-label">Itens por página</label><input type="number" min="10" max="100" name="itens_por_pagina" class="form-control" value="<?=htmlspecialchars($cfg['itens_por_pagina'])?>"></div>
                        <div class="col-12">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="modo_debug" value="1" <?=$cfg['modo_debug']==='1'?'checked':''?>>
                                <label class="form-check-label">Modo debug controlado</label>
                            </div>
                            <div class="form-text">Mantenha desativado quando o ambiente for Produção.</div>
                        </div>
                        <div class="col-md-4"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="modo_manutencao_preparado" value="1" <?=$cfg['modo_manutencao_preparado']==='1'?'checked':''?>><label class="form-check-label">Preparar manutenção</label></div></div>
                        <div class="col-md-4"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="cache_aplicacao_preparado" value="1" <?=$cfg['cache_aplicacao_preparado']==='1'?'checked':''?>><label class="form-check-label">Preparar cache</label></div></div>
                        <div class="col-md-4"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="backup_automatico_preparado" value="1" <?=$cfg['backup_automatico_preparado']==='1'?'checked':''?>><label class="form-check-label">Preparar backup</label></div></div>
                    </div>
                </div>
            </div>

            <div class="card shadow-sm border-0 mb-4">
                <div class="card-header bg-dark text-white"><i class="bi bi-fingerprint me-1"></i> Identidade da Instalação</div>
                <div class="card-body">
                    <div class="mb-3"><label class="form-label">ID da instalação</label><input class="form-control bg-light" readonly value="<?=htmlspecialchars($cfg['identificador_instalacao'])?>"></div>
                    <div class="mb-3"><label class="form-label">Tenant ID</label><input class="form-control bg-light" readonly value="<?=htmlspecialchars($cfg['tenant_id'])?>"></div>
                    <div><label class="form-label">Chave técnica da instalação</label><input class="form-control bg-light" readonly value="<?=htmlspecialchars($cfg['chave_instalacao'])?>"></div>
                    <div class="form-text mt-2">Identificadores permanentes gerados localmente. A ativação online ficará para a fase de produção.</div>
                </div>
            </div>

            <div class="card shadow-sm border-0 mb-4">
                <div class="card-header bg-dark text-white"><i class="bi bi-cloud-check me-1"></i> Infraestrutura SaaS</div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-6"><label class="form-label">Domínio</label><input name="dominio_saas" class="form-control" maxlength="180" value="<?=htmlspecialchars($cfg['dominio_saas'])?>" placeholder="exemplo.com.br"></div>
                        <div class="col-md-6"><label class="form-label">Subdomínio</label><input name="subdominio_saas" class="form-control" maxlength="120" value="<?=htmlspecialchars($cfg['subdominio_saas'])?>" placeholder="cliente.rojex.ai"></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-6">
            <div class="card shadow-sm border-0 mb-4">
                <div class="card-header bg-dark text-white"><i class="bi bi-award me-1"></i> Licenciamento</div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Plano</label>
                            <select name="plano_licenca" class="form-select">
                                <option value="starter" <?=$cfg['plano_licenca']==='starter'?'selected':''?>>Starter</option>
                                <option value="professional" <?=$cfg['plano_licenca']==='professional'?'selected':''?>>Professional</option>
                                <option value="enterprise" <?=$cfg['plano_licenca']==='enterprise'?'selected':''?>>Enterprise</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Situação</label>
                            <select name="status_licenca" class="form-select">
                                <option value="ativa" <?=$cfg['status_licenca']==='ativa'?'selected':''?>>Ativa</option>
                                <option value="teste" <?=$cfg['status_licenca']==='teste'?'selected':''?>>Em teste</option>
                                <option value="suspensa" <?=$cfg['status_licenca']==='suspensa'?'selected':''?>>Suspensa</option>
                                <option value="expirada" <?=$cfg['status_licenca']==='expirada'?'selected':''?>>Expirada</option>
                            </select>
                        </div>
                        <div class="col-md-6"><label class="form-label">Data de ativação</label><input type="date" name="data_ativacao_licenca" class="form-control" value="<?=htmlspecialchars($cfg['data_ativacao_licenca'])?>"></div>
                        <div class="col-md-6"><label class="form-label">Próxima renovação</label><input type="date" name="data_renovacao_licenca" class="form-control" value="<?=htmlspecialchars($cfg['data_renovacao_licenca'])?>"></div>
                        <div class="col-md-6"><label class="form-label">Limite de usuários</label><input type="number" min="1" max="100" name="limite_usuarios_licenca" class="form-control" value="<?=htmlspecialchars($cfg['limite_usuarios_licenca'])?>"></div>
                        <div class="col-md-6"><label class="form-label">Armazenamento contratado (GB)</label><input type="number" min="1" max="10000" name="limite_armazenamento_gb" class="form-control" value="<?=htmlspecialchars($cfg['limite_armazenamento_gb'])?>"></div>
                    </div>
                </div>
            </div>

            <div class="card shadow-sm border-0 mb-4">
                <div class="card-header bg-dark text-white"><i class="bi bi-stars me-1"></i> Preparação para IA</div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Provedor planejado</label>
                            <select name="provedor_ia" class="form-select">
                                <option value="nao_definido" <?=$cfg['provedor_ia']==='nao_definido'?'selected':''?>>Não definido</option>
                                <option value="openai" <?=$cfg['provedor_ia']==='openai'?'selected':''?>>OpenAI</option>
                                <option value="anthropic" <?=$cfg['provedor_ia']==='anthropic'?'selected':''?>>Anthropic</option>
                                <option value="google" <?=$cfg['provedor_ia']==='google'?'selected':''?>>Google Gemini</option>
                                <option value="deepseek" <?=$cfg['provedor_ia']==='deepseek'?'selected':''?>>DeepSeek</option>
                                <option value="outro" <?=$cfg['provedor_ia']==='outro'?'selected':''?>>Outro</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Status da integração</label>
                            <select name="status_integracao_ia" class="form-select">
                                <option value="desativada" <?=$cfg['status_integracao_ia']==='desativada'?'selected':''?>>Desativada</option>
                                <option value="preparada" <?=$cfg['status_integracao_ia']==='preparada'?'selected':''?>>Estrutura preparada</option>
                                <option value="ativa" <?=$cfg['status_integracao_ia']==='ativa'?'selected':''?>>Ativa</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <div class="alert alert-info small mb-0">
                                As chaves de API não são gravadas nesta tela. Na produção, serão armazenadas fora do código, em variável de ambiente segura.
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card shadow-sm border-0 mb-4">
                <div class="card-header bg-dark text-white"><i class="bi bi-grid me-1"></i> Recursos da Licença</div>
                <div class="card-body">
                    <div class="row g-2">
                        <?php foreach ([
                            'recurso_portal_cliente' => 'Portal do Cliente',
                            'recurso_assinatura_digital' => 'Assinatura Digital',
                            'recurso_whatsapp' => 'WhatsApp',
                            'recurso_email_automatico' => 'E-mails automáticos',
                            'recurso_cnj' => 'Integração CNJ',
                            'recurso_bi' => 'BI Executivo',
                            'recurso_cij' => 'Centro de Inteligência Jurídica',
                            'recurso_ia' => 'Integração com IA',
                        ] as $chaveRecurso => $nomeRecurso): ?>
                        <div class="col-md-6">
                            <div class="form-check form-switch border rounded p-3 ps-5 bg-light">
                                <input class="form-check-input" type="checkbox" name="<?=$chaveRecurso?>" value="1" <?=$cfg[$chaveRecurso]==='1'?'checked':''?>>
                                <label class="form-check-label"><?=htmlspecialchars($nomeRecurso)?></label>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="text-end mb-4">
        <button class="btn btn-primary btn-lg"><i class="bi bi-floppy me-1"></i>Salvar Sistema Enterprise</button>
    </div>
</form>

<div class="row g-4">
    <div class="col-xl-6">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-header bg-dark text-white"><i class="bi bi-heart-pulse me-1"></i> Saúde do Servidor</div>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <tbody>
                        <tr><th>PHP</th><td><?=htmlspecialchars(PHP_VERSION)?></td><td><span class="badge bg-success">OK</span></td></tr>
                        <tr><th>MySQL/MariaDB</th><td><?=htmlspecialchars($versaoBancoServidor)?></td><td><span class="badge bg-success">OK</span></td></tr>
                        <tr><th>Servidor web</th><td><?=htmlspecialchars($servidorWeb)?></td><td><span class="badge bg-success">Ativo</span></td></tr>
                        <tr><th>Charset do banco</th><td><?=htmlspecialchars($charsetBanco)?></td><td><span class="badge <?=$charsetBanco==='utf8mb4'?'bg-success':'bg-warning text-dark'?>"><?=htmlspecialchars($charsetBanco)?></span></td></tr>
                        <tr><th>Timezone PHP</th><td><?=htmlspecialchars($timezonePhp)?></td><td><span class="badge bg-secondary">Informativo</span></td></tr>
                        <tr><th>Memória PHP</th><td><?=htmlspecialchars($limiteMemoriaPhp)?></td><td><span class="badge bg-secondary">Limite</span></td></tr>
                        <tr><th>Upload máximo</th><td><?=htmlspecialchars($uploadMaximoPhp)?> / POST <?=htmlspecialchars($postMaximoPhp)?></td><td><span class="badge bg-secondary">Limite</span></td></tr>
                        <tr><th>Tempo máximo</th><td><?=htmlspecialchars($tempoExecucaoPhp)?> segundo(s)</td><td><span class="badge bg-secondary">Limite</span></td></tr>
                        <tr><th>Disco utilizado</th><td><?=sgl_formatar_bytes($espacoUsadoDisco)?> de <?=sgl_formatar_bytes($espacoTotalDisco)?></td><td><span class="badge <?=$percentualDisco<85?'bg-success':'bg-warning text-dark'?>"><?=$percentualDisco?>%</span></td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-xl-6">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-header bg-dark text-white"><i class="bi bi-shield-check me-1"></i> Segurança e Integridade</div>
            <div class="card-body">
                <div class="row g-2">
                    <?php foreach ($segurancaSistema as $nomeCheck => $statusCheck): ?>
                    <div class="col-md-6">
                        <div class="border rounded p-3 d-flex justify-content-between align-items-center">
                            <span><?=htmlspecialchars($nomeCheck)?></span>
                            <span class="badge <?=$statusCheck?'bg-success':'bg-warning text-dark'?>"><?=$statusCheck?'OK':'Atenção'?></span>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php if (!$httpsAtivo): ?>
                    <div class="alert alert-warning small mt-3 mb-0">
                        HTTPS aparece como atenção porque o sistema ainda está em localhost. Na Hostinger, deverá ser ativado com certificado SSL.
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div class="card shadow-sm border-0 mt-4">
    <div class="card-header bg-dark text-white"><i class="bi bi-clipboard-data me-1"></i> Diagnóstico Geral dos Módulos</div>
    <div class="card-body">
        <div class="row g-2">
            <?php foreach ($diagnosticoModulos as $nomeDiagnostico => $totalDiagnostico): ?>
            <div class="col-md-3">
                <div class="border rounded p-3 bg-light d-flex justify-content-between align-items-center">
                    <span><?=htmlspecialchars($nomeDiagnostico)?></span>
                    <strong><?=number_format((int)$totalDiagnostico, 0, ',', '.')?></strong>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <div class="alert alert-info small mt-3 mb-0">
            Backup automático, atualização remota, ativação online da licença e gestão multi-tenant serão implementados na Sprint 4.1.3, sem execução automática nesta etapa.
        </div>
    </div>
</div>
<?php endif; ?>


<?php if ($tab_ativa === 'novo_escritorio'):
$assistenteDados = $_SESSION['rojex_novo_escritorio'] ?? [];
$assistenteEtapa = max(1, min(6, (int)($_GET['etapa'] ?? 1)));
$assistenteEscritorio = $assistenteDados['escritorio'] ?? [];
$assistentePlano = $assistenteDados['plano'] ?? [];
$assistenteLicenca = $assistenteDados['licenca'] ?? [];
$assistenteAdmin = $assistenteDados['administrador'] ?? [];
$assistenteModulosSelecionados = array_map('intval', (array)($assistenteDados['modulos'] ?? []));
$assistenteComercial = is_array($assistenteDados['comercial'] ?? null) ? $assistenteDados['comercial'] : [];
$etapasAssistente = [1=>'Dados do Escritório',2=>'Plano Comercial',3=>'Personalização',4=>'Licença',5=>'Administrador',6=>'Resumo Final'];
$planoSelecionadoId = (int)($assistentePlano['id'] ?? 0);
$modulosDoPlanoAssistente = [];
if ($planoSelecionadoId > 0 && sgl_tabela_existe($conn,'planos_modulos_saas')) {
    try {
        $stmt=$conn->prepare("SELECT m.*,pm.incluido_padrao,pm.obrigatorio,pm.permite_remocao,pm.desconto_remocao_mensal,pm.desconto_remocao_anual FROM planos_modulos_saas pm INNER JOIN modulos_saas m ON m.id=pm.modulo_id WHERE pm.plano_id=? AND pm.ativo=1 AND m.ativo=1 ORDER BY m.ordem_exibicao,m.nome");
        $stmt->bind_param('i',$planoSelecionadoId); $stmt->execute(); $res=$stmt->get_result();
        while($row=$res->fetch_assoc()) $modulosDoPlanoAssistente[]=$row;
        $stmt->close();
    } catch(Throwable $e) {}
}
if (!$assistenteModulosSelecionados && $modulosDoPlanoAssistente) {
    foreach($modulosDoPlanoAssistente as $m) if(!empty($m['incluido_padrao']) || !empty($m['obrigatorio'])) $assistenteModulosSelecionados[]=(int)$m['id'];
}
if ($planoSelecionadoId > 0 && !empty($assistentePlano['snapshot'])) {
    $assistenteComercial = rojex_motor_comercial_calcular(
        (array)$assistentePlano['snapshot'],
        (string)($assistentePlano['periodicidade'] ?? 'mensal'),
        $modulosDoPlanoAssistente,
        $assistenteModulosSelecionados,
        (float)($assistenteComercial['ajuste_manual'] ?? 0)
    );
}
?>
<style>
.rojex-wizard-step{min-width:150px}.rojex-wizard-line{height:3px;background:#dee2e6;flex:1;min-width:20px}.rojex-wizard-step.active .rounded-circle{box-shadow:0 0 0 .25rem rgba(13,110,253,.18)}
</style>
<div class="card shadow-sm border-0 mb-4">
 <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center flex-wrap gap-2"><span><i class="bi bi-building-add me-1"></i> Assistente Enterprise — Novo Escritório</span><span class="badge bg-warning text-dark">Pré-provisionamento</span></div>
 <div class="card-body">
  <div class="alert alert-info border-0"><strong>Etapa 3.4.3:</strong> os dados e a composição comercial são validados e mantidos temporariamente na sessão. Nenhum tenant, licença ou usuário será criado até a ativação do provisionamento transacional.</div>
  <div class="d-flex align-items-start overflow-auto pb-3 mb-3">
   <?php foreach($etapasAssistente as $numero=>$rotulo): ?>
    <div class="rojex-wizard-step text-center <?=$assistenteEtapa===$numero?'active':''?>"><div class="rounded-circle mx-auto d-flex align-items-center justify-content-center <?=$assistenteEtapa>=$numero?'bg-primary text-white':'bg-light text-muted border'?>" style="width:42px;height:42px"><?=$numero?></div><small class="d-block mt-2 fw-semibold"><?=htmlspecialchars($rotulo)?></small></div>
    <?php if($numero<6): ?><div class="rojex-wizard-line mt-4"></div><?php endif; ?>
   <?php endforeach; ?>
  </div>

  <?php if($assistenteEtapa===1): ?>
  <form method="post" class="row g-3"><input type="hidden" name="csrf_token" value="<?=htmlspecialchars($csrf)?>"><input type="hidden" name="acao_cfg" value="assistente_novo_escritorio_salvar"><input type="hidden" name="etapa_assistente" value="1">
   <div class="col-md-6"><label class="form-label">Nome Fantasia *</label><input name="assistente_nome_fantasia" class="form-control" required maxlength="180" value="<?=htmlspecialchars((string)($assistenteEscritorio['nomeFantasia']??''))?>"></div>
   <div class="col-md-6"><label class="form-label">Razão Social *</label><input name="assistente_razao_social" class="form-control" required maxlength="180" value="<?=htmlspecialchars((string)($assistenteEscritorio['razaoSocial']??''))?>"></div>
   <div class="col-md-4"><label class="form-label">CPF/CNPJ *</label><input name="assistente_documento" class="form-control" required maxlength="20" value="<?=htmlspecialchars((string)($assistenteEscritorio['documento']??''))?>"></div>
   <div class="col-md-4"><label class="form-label">Responsável *</label><input name="assistente_responsavel" class="form-control" required value="<?=htmlspecialchars((string)($assistenteEscritorio['responsavel']??''))?>"></div>
   <div class="col-md-4"><label class="form-label">E-mail *</label><input type="email" name="assistente_email" class="form-control" required value="<?=htmlspecialchars((string)($assistenteEscritorio['email']??''))?>"></div>
   <div class="col-md-3"><label class="form-label">Telefone</label><input name="assistente_telefone" class="form-control" value="<?=htmlspecialchars((string)($assistenteEscritorio['telefone']??''))?>"></div>
   <div class="col-md-3"><label class="form-label">Cidade</label><input name="assistente_cidade" class="form-control" value="<?=htmlspecialchars((string)($assistenteEscritorio['cidade']??''))?>"></div>
   <div class="col-md-2"><label class="form-label">Estado</label><input name="assistente_uf" class="form-control text-uppercase" maxlength="2" value="<?=htmlspecialchars((string)($assistenteEscritorio['uf']??''))?>"></div>
   <div class="col-md-2"><label class="form-label">Tenant ID</label><input name="assistente_tenant" class="form-control font-monospace" placeholder="Automático" value="<?=htmlspecialchars((string)($assistenteEscritorio['tenant']??''))?>"></div>
   <div class="col-md-2"><label class="form-label">Subdomínio</label><div class="input-group"><input name="assistente_subdominio" class="form-control" placeholder="automático" value="<?=htmlspecialchars((string)($assistenteEscritorio['subdominio']??''))?>"><span class="input-group-text">.rojex.ai</span></div></div>
   <div class="col-12 text-end"><button class="btn btn-primary">Validar e continuar <i class="bi bi-arrow-right ms-1"></i></button></div>
  </form>
  <?php elseif($assistenteEtapa===2): ?>
  <form method="post"><input type="hidden" name="csrf_token" value="<?=htmlspecialchars($csrf)?>"><input type="hidden" name="acao_cfg" value="assistente_novo_escritorio_salvar"><input type="hidden" name="etapa_assistente" value="2">
   <div class="row g-3"><?php foreach($planosSaas as $p): if(empty($p['ativo'])) continue; ?><div class="col-lg-4"><label class="card h-100 shadow-sm border-2 p-3" style="cursor:pointer"><div class="form-check"><input class="form-check-input" type="radio" name="assistente_plano_id" value="<?=(int)$p['id']?>" <?=$planoSelecionadoId===(int)$p['id']?'checked':''?> required><span class="fw-bold ms-1"><?=htmlspecialchars($p['nome'])?></span></div><hr><h4>R$ <?=number_format((float)$p['valor_mensal'],2,',','.')?><small class="fs-6 text-muted">/mês</small></h4><div>R$ <?=number_format((float)$p['valor_anual'],2,',','.')?>/ano</div><small class="text-success"><?=number_format((float)$p['desconto_anual_percentual'],2,',','.')?>% anual</small><ul class="small mt-3 mb-0"><li>Trial: <?=(int)$p['trial_dias_padrao']?> dias</li><li><?=(int)$p['limite_usuarios_padrao']?> usuários</li><li><?=(int)$p['limite_armazenamento_gb_padrao']?> GB</li><li>Suporte <?=!empty($p['suporte_incluso'])?'incluído':'não incluído'?></li></ul></label></div><?php endforeach; ?></div>
   <div class="mt-4"><label class="form-label">Periodicidade</label><select name="assistente_periodicidade" class="form-select" style="max-width:300px"><option value="mensal" <?=($assistentePlano['periodicidade']??'mensal')==='mensal'?'selected':''?>>Mensal</option><option value="anual" <?=($assistentePlano['periodicidade']??'')==='anual'?'selected':''?>>Anual com desconto</option></select></div>
   <div class="d-flex justify-content-between mt-4"><a class="btn btn-outline-secondary" href="?mod=configuracoes&tab=novo_escritorio&etapa=1"><i class="bi bi-arrow-left"></i> Voltar</a><button class="btn btn-primary">Continuar <i class="bi bi-arrow-right"></i></button></div>
  </form>
  <?php elseif($assistenteEtapa===3): ?>
  <form method="post"><input type="hidden" name="csrf_token" value="<?=htmlspecialchars($csrf)?>"><input type="hidden" name="acao_cfg" value="assistente_novo_escritorio_salvar"><input type="hidden" name="etapa_assistente" value="3">
   <div class="row g-3"><?php if(!$modulosDoPlanoAssistente): ?><div class="col-12"><div class="alert alert-warning">O plano selecionado não possui módulos ativos configurados.</div></div><?php endif; foreach($modulosDoPlanoAssistente as $m): $obrig=!empty($m['obrigatorio'])||!empty($m['modulo_essencial']); $checked=$obrig||in_array((int)$m['id'],$assistenteModulosSelecionados,true); ?><div class="col-md-6"><div class="border rounded p-3 h-100"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="assistente_modulos[]" value="<?=(int)$m['id']?>" <?=$checked?'checked':''?> <?=$obrig?'disabled':''?>><label class="form-check-label fw-semibold"><i class="bi <?=htmlspecialchars($m['icone']?:'bi-box')?> me-1"></i><?=htmlspecialchars($m['nome'])?></label><?php if($obrig): ?><input type="hidden" name="assistente_modulos[]" value="<?=(int)$m['id']?>"><?php endif; ?></div><small class="text-muted"><?=htmlspecialchars((string)$m['descricao'])?></small><div class="mt-2"><?php if($obrig): ?><span class="badge bg-dark">Obrigatório</span><?php elseif(!empty($m['permite_remocao'])): $descModulo=($assistentePlano['periodicidade']??'mensal')==='anual'?(float)($m['desconto_remocao_anual']??0):(float)($m['desconto_remocao_mensal']??0); ?><span class="badge bg-info text-dark">Opcional</span> <span class="badge bg-light text-dark border">Desconto ao remover: R$ <?=number_format($descModulo,2,',','.')?></span><?php endif; ?></div></div></div><?php endforeach; ?></div>
   <div class="d-flex justify-content-between mt-4"><a class="btn btn-outline-secondary" href="?mod=configuracoes&tab=novo_escritorio&etapa=2"><i class="bi bi-arrow-left"></i> Voltar</a><button class="btn btn-primary">Continuar <i class="bi bi-arrow-right"></i></button></div>
  </form>
  <?php elseif($assistenteEtapa===4): $pSnap=$assistentePlano['snapshot']??[]; ?>
  <form method="post"><input type="hidden" name="csrf_token" value="<?=htmlspecialchars($csrf)?>"><input type="hidden" name="acao_cfg" value="assistente_novo_escritorio_salvar"><input type="hidden" name="etapa_assistente" value="4">
   <div class="row g-3"><div class="col-md-4"><label class="form-label">Trial</label><input type="number" name="assistente_trial_dias" class="form-control" min="<?=max(7,(int)($pSnap['trial_dias_minimo']??7))?>" max="<?=min(30,(int)($pSnap['trial_dias_maximo']??30))?>" value="<?=(int)($assistenteLicenca['trial_dias']??$pSnap['trial_dias_padrao']??15)?>"></div><div class="col-md-4"><label class="form-label">Limite de usuários</label><div class="form-control bg-light"><?=(int)($pSnap['limite_usuarios_padrao']??0)?></div></div><div class="col-md-4"><label class="form-label">Armazenamento</label><div class="form-control bg-light"><?=(int)($pSnap['limite_armazenamento_gb_padrao']??0)?> GB</div></div></div>
   <?php if(!empty($assistenteLicenca['chave'])): ?><div class="alert alert-success mt-4"><strong>Prévia da chave:</strong> <code><?=htmlspecialchars($assistenteLicenca['chave'])?></code><br><small>Será recriada e confirmada dentro da transação definitiva.</small></div><?php endif; ?>
   <div class="d-flex justify-content-between mt-4"><a class="btn btn-outline-secondary" href="?mod=configuracoes&tab=novo_escritorio&etapa=3"><i class="bi bi-arrow-left"></i> Voltar</a><button class="btn btn-primary">Gerar prévia e continuar <i class="bi bi-arrow-right"></i></button></div>
  </form>
  <?php elseif($assistenteEtapa===5): ?>
  <form method="post" class="row g-3"><input type="hidden" name="csrf_token" value="<?=htmlspecialchars($csrf)?>"><input type="hidden" name="acao_cfg" value="assistente_novo_escritorio_salvar"><input type="hidden" name="etapa_assistente" value="5">
   <div class="col-md-6"><label class="form-label">Nome do administrador *</label><input name="assistente_admin_nome" class="form-control" required value="<?=htmlspecialchars((string)($assistenteAdmin['nome']??''))?>"></div><div class="col-md-3"><label class="form-label">Login *</label><input name="assistente_admin_login" class="form-control" required value="<?=htmlspecialchars((string)($assistenteAdmin['login']??''))?>"></div><div class="col-md-3"><label class="form-label">Senha inicial *</label><input type="password" name="assistente_admin_senha" class="form-control" minlength="6" required autocomplete="new-password"></div>
   <div class="col-md-4"><label class="form-label">E-mail *</label><input type="email" name="assistente_admin_email" class="form-control" required value="<?=htmlspecialchars((string)($assistenteAdmin['email']??$assistenteEscritorio['email']??''))?>"></div><div class="col-md-4"><label class="form-label">Idioma</label><select name="assistente_admin_idioma" class="form-select"><option value="pt-BR">Português (Brasil)</option><option value="en-US">English</option><option value="es-ES">Español</option></select></div><div class="col-md-4"><label class="form-label">Fuso horário</label><input name="assistente_admin_fuso" class="form-control" value="<?=htmlspecialchars((string)($assistenteAdmin['fuso']??'America/Sao_Paulo'))?>"></div>
   <div class="col-12 d-flex justify-content-between"><a class="btn btn-outline-secondary" href="?mod=configuracoes&tab=novo_escritorio&etapa=4"><i class="bi bi-arrow-left"></i> Voltar</a><button class="btn btn-primary">Revisar cadastro <i class="bi bi-arrow-right"></i></button></div>
  </form>
  <?php else: $pSnap=$assistentePlano['snapshot']??[]; $valorBase=(float)($assistenteComercial['valor_base']??0); $valorFinal=(float)($assistenteComercial['valor_final']??$valorBase); $descontoModulos=(float)($assistenteComercial['desconto_modulos']??0); $economia=(float)($assistenteComercial['economia']??0); $modulosRemovidos=(array)($assistenteComercial['modulos_removidos']??[]); ?>
  <div class="row g-3">
   <div class="col-lg-6"><div class="card h-100 border-0 bg-light"><div class="card-body"><h6>Escritório</h6><strong><?=htmlspecialchars((string)($assistenteEscritorio['nomeFantasia']??'-'))?></strong><br><?=htmlspecialchars((string)($assistenteEscritorio['razaoSocial']??'-'))?><br><small><?=htmlspecialchars((string)($assistenteEscritorio['documento']??'-'))?> · <?=htmlspecialchars((string)($assistenteEscritorio['email']??'-'))?></small><hr><code><?=htmlspecialchars((string)($assistenteEscritorio['tenant']??'-'))?></code><br><?=htmlspecialchars((string)($assistenteEscritorio['subdominio']??'-'))?>.rojex.ai</div></div></div>
   <div class="col-lg-6"><div class="card h-100 border-0 bg-light"><div class="card-body"><h6>Plano e licença</h6><strong><?=htmlspecialchars((string)($pSnap['nome']??'-'))?></strong> · <?=htmlspecialchars((string)($assistentePlano['periodicidade']??'-'))?><div class="table-responsive mt-3"><table class="table table-sm mb-2"><tbody><tr><td>Valor base</td><td class="text-end">R$ <?=number_format($valorBase,2,',','.')?></td></tr><tr><td>Desconto por módulos removidos</td><td class="text-end text-success">- R$ <?=number_format($descontoModulos,2,',','.')?></td></tr><tr><td>Módulos extras</td><td class="text-end">+ R$ <?=number_format((float)($assistenteComercial['valor_extras']??0),2,',','.')?></td></tr><tr><td>Ajuste comercial</td><td class="text-end">R$ <?=number_format((float)($assistenteComercial['ajuste_manual']??0),2,',','.')?></td></tr><tr class="fw-bold border-top"><td>Valor contratado</td><td class="text-end fs-5">R$ <?=number_format($valorFinal,2,',','.')?></td></tr></tbody></table></div><?php if($economia>0): ?><span class="badge bg-success">Economia: R$ <?=number_format($economia,2,',','.')?></span><?php endif; ?><div class="small mt-2">Trial: <?=(int)($assistenteLicenca['trial_dias']??0)?> dias · Usuários: <?=(int)($pSnap['limite_usuarios_padrao']??0)?> · Armazenamento: <?=(int)($pSnap['limite_armazenamento_gb_padrao']??0)?> GB</div><hr><code><?=htmlspecialchars((string)($assistenteLicenca['chave']??'-'))?></code></div></div></div>
   <div class="col-lg-6"><div class="card h-100 border-0 bg-light"><div class="card-body"><h6>Módulos contratados</h6><span class="badge bg-primary"><?=count($assistenteModulosSelecionados)?> incluído(s)</span> <span class="badge bg-secondary"><?=count($modulosRemovidos)?> removido(s)</span><div class="small mt-3"><?php foreach($modulosDoPlanoAssistente as $m) if(in_array((int)$m['id'],$assistenteModulosSelecionados,true)) echo '<span class="badge bg-white text-dark border me-1 mb-1">'.htmlspecialchars($m['nome']).'</span>'; ?></div><?php if($modulosRemovidos): ?><hr><small class="text-muted d-block mb-1">Removidos:</small><?php foreach($modulosRemovidos as $mr): ?><span class="badge bg-light text-muted border me-1 mb-1"><?=htmlspecialchars((string)($mr['nome']??'Módulo'))?><?php if((float)($mr['valor_ajuste']??0)>0): ?> · -R$ <?=number_format((float)$mr['valor_ajuste'],2,',','.')?><?php endif; ?></span><?php endforeach; ?><?php endif; ?></div></div></div>
   <div class="col-lg-6"><div class="card h-100 border-0 bg-light"><div class="card-body"><h6>Administrador</h6><strong><?=htmlspecialchars((string)($assistenteAdmin['nome']??'-'))?></strong><br><?=htmlspecialchars((string)($assistenteAdmin['login']??'-'))?><br><small><?=htmlspecialchars((string)($assistenteAdmin['email']??'-'))?> · <?=htmlspecialchars((string)($assistenteAdmin['fuso']??'-'))?></small><hr><small class="text-muted">A senha permanece protegida por hash na sessão e será gravada somente no provisionamento definitivo.</small></div></div></div>
  </div>
  <div class="alert alert-success mt-4"><i class="bi bi-shield-check me-1"></i><strong>Provisionamento transacional disponível.</strong> Todos os dados serão revalidados e criados em uma única transação. Qualquer falha executará rollback completo.</div>
  <form method="post" class="d-flex justify-content-between" onsubmit="return confirm('Confirmar o provisionamento definitivo deste escritório? Esta ação criará o tenant, a licença e o administrador.');"><input type="hidden" name="csrf_token" value="<?=htmlspecialchars($csrf)?>"><input type="hidden" name="acao_cfg" value="assistente_novo_escritorio_provisionar"><a class="btn btn-outline-secondary" href="?mod=configuracoes&tab=novo_escritorio&etapa=5"><i class="bi bi-arrow-left"></i> Corrigir administrador</a><button class="btn btn-success"><i class="bi bi-rocket-takeoff me-1"></i> Provisionar escritório</button></form>
  <?php endif; ?>
 </div>
</div>
<form method="post" class="text-end"><input type="hidden" name="csrf_token" value="<?=htmlspecialchars($csrf)?>"><input type="hidden" name="acao_cfg" value="assistente_novo_escritorio_reiniciar"><button class="btn btn-sm btn-outline-danger" onclick="return confirm('Reiniciar o assistente e apagar os dados temporários?')"><i class="bi bi-arrow-counterclockwise me-1"></i>Reiniciar assistente</button></form>
<?php endif; ?>

<?php if ($tab_ativa === 'planos'): ?>
<div class="row g-3 mb-4">
    <div class="col-md-3"><div class="card shadow-sm border-0 h-100"><div class="card-body"><small class="text-muted">PLANOS CADASTRADOS</small><h3 class="mb-0"><?=$totalPlanosSaas?></h3></div></div></div>
    <div class="col-md-3"><div class="card shadow-sm border-0 h-100"><div class="card-body"><small class="text-muted">ATIVOS</small><h3 class="mb-0 text-success"><?=$totalPlanosAtivos?></h3></div></div></div>
    <div class="col-md-3"><div class="card shadow-sm border-0 h-100"><div class="card-body"><small class="text-muted">INATIVOS</small><h3 class="mb-0 text-secondary"><?=$totalPlanosInativos?></h3></div></div></div>
    <div class="col-md-3"><div class="card shadow-sm border-0 h-100"><div class="card-body"><small class="text-muted">EM DESTAQUE</small><h3 class="mb-0 text-warning"><?=$totalPlanosDestaque?></h3></div></div></div>
</div>

<div class="card shadow-sm border-0 mb-4">
    <div class="card-header bg-dark text-white"><i class="bi bi-tags-fill me-1"></i> <?=$planoEditar?'Editar Plano SaaS':'Cadastrar Novo Plano SaaS'?></div>
    <div class="card-body">
        <div class="alert alert-info small border-0">
            Os valores são administráveis pelo MASTER. O desconto anual é calculado automaticamente comparando o valor anual com doze mensalidades. A exclusão comercial é feita por inativação para preservar contratos e históricos.
        </div>
        <form method="post" class="row g-3" id="formPlanoSaas">
            <input type="hidden" name="csrf_token" value="<?=htmlspecialchars($csrf)?>">
            <input type="hidden" name="acao_cfg" value="salvar_plano_saas">
            <input type="hidden" name="plano_saas_id" value="<?=(int)($planoEditar['id'] ?? 0)?>">
            <div class="col-lg-3"><label class="form-label">Código interno *</label><input name="codigo_plano_saas" class="form-control font-monospace" maxlength="40" required pattern="[A-Za-z0-9_-]+" value="<?=htmlspecialchars((string)($planoEditar['codigo'] ?? ''))?>" placeholder="ex.: professional"></div>
            <div class="col-lg-5"><label class="form-label">Nome do plano *</label><input name="nome_plano_saas" class="form-control" maxlength="100" required value="<?=htmlspecialchars((string)($planoEditar['nome'] ?? ''))?>"></div>
            <div class="col-lg-2"><label class="form-label">Ordem</label><input type="number" name="ordem_plano_saas" class="form-control" min="0" max="100000" value="<?=(int)($planoEditar['ordem_exibicao'] ?? 0)?>"></div>
            <div class="col-lg-2"><label class="form-label">Nível de suporte</label><select name="nivel_suporte_plano_saas" class="form-select"><?php foreach(['padrao'=>'Padrão','prioritario'=>'Prioritário','premium'=>'Premium'] as $v=>$n): ?><option value="<?=$v?>" <?=($planoEditar['nivel_suporte'] ?? 'padrao')===$v?'selected':''?>><?=$n?></option><?php endforeach; ?></select></div>
            <div class="col-12"><label class="form-label">Descrição comercial</label><textarea name="descricao_plano_saas" class="form-control" rows="2" maxlength="3000"><?=htmlspecialchars((string)($planoEditar['descricao'] ?? ''))?></textarea></div>

            <div class="col-md-3"><label class="form-label">Valor mensal (R$) *</label><input type="number" step="0.01" min="0" name="valor_mensal_plano_saas" id="valorMensalPlano" class="form-control" required value="<?=number_format((float)($planoEditar['valor_mensal'] ?? 0),2,'.','')?>"></div>
            <div class="col-md-3"><label class="form-label">Valor anual (R$) *</label><input type="number" step="0.01" min="0" name="valor_anual_plano_saas" id="valorAnualPlano" class="form-control" required value="<?=number_format((float)($planoEditar['valor_anual'] ?? 0),2,'.','')?>"></div>
            <div class="col-md-3"><label class="form-label">Desconto anual calculado</label><div class="form-control bg-light" id="descontoAnualCalculado"><?=number_format((float)($planoEditar['desconto_anual_percentual'] ?? 0),2,',','.')?>%</div></div>
            <div class="col-md-3"><label class="form-label">Motivo da alteração de preço</label><input name="motivo_preco_plano_saas" class="form-control" maxlength="255" placeholder="Opcional; registrado no histórico"></div>

            <div class="col-md-2"><label class="form-label">Trial mínimo</label><input type="number" name="trial_minimo_plano_saas" class="form-control" min="7" max="30" value="<?=(int)($planoEditar['trial_dias_minimo'] ?? 7)?>" required></div>
            <div class="col-md-2"><label class="form-label">Trial padrão</label><input type="number" name="trial_padrao_plano_saas" class="form-control" min="7" max="30" value="<?=(int)($planoEditar['trial_dias_padrao'] ?? 15)?>" required></div>
            <div class="col-md-2"><label class="form-label">Trial máximo</label><input type="number" name="trial_maximo_plano_saas" class="form-control" min="7" max="30" value="<?=(int)($planoEditar['trial_dias_maximo'] ?? 30)?>" required></div>
            <div class="col-md-3"><label class="form-label">Limite padrão de usuários</label><input type="number" name="limite_usuarios_plano_saas" class="form-control" min="1" max="100000" value="<?=(int)($planoEditar['limite_usuarios_padrao'] ?? 5)?>" required></div>
            <div class="col-md-3"><label class="form-label">Armazenamento padrão (GB)</label><input type="number" name="armazenamento_plano_saas" class="form-control" min="1" max="1000000" value="<?=(int)($planoEditar['limite_armazenamento_gb_padrao'] ?? 10)?>" required></div>

            <div class="col-12 d-flex flex-wrap gap-4">
                <div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="suporte_incluso_plano_saas" id="suportePlano" value="1" <?=!isset($planoEditar['suporte_incluso']) || !empty($planoEditar['suporte_incluso'])?'checked':''?>><label class="form-check-label" for="suportePlano">Suporte incluído</label></div>
                <div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="destaque_plano_saas" id="destaquePlano" value="1" <?=!empty($planoEditar['destaque'])?'checked':''?>><label class="form-check-label" for="destaquePlano">Plano em destaque</label></div>
                <div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="ativo_plano_saas" id="ativoPlano" value="1" <?=!isset($planoEditar['ativo']) || !empty($planoEditar['ativo'])?'checked':''?>><label class="form-check-label" for="ativoPlano">Plano ativo</label></div>
            </div>
            <div class="col-12 d-flex gap-2"><button class="btn btn-dark"><i class="bi bi-save me-1"></i><?=$planoEditar?'Atualizar plano':'Cadastrar plano'?></button><?php if($planoEditar): ?><a href="?mod=configuracoes&tab=planos" class="btn btn-outline-secondary">Cancelar edição</a><?php endif; ?></div>
        </form>
    </div>
</div>

<div class="card shadow-sm border-0 mb-4">
    <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center"><span><i class="bi bi-list-check me-1"></i> Planos Comerciais</span><span class="badge bg-light text-primary"><?=$totalPlanosSaas?> plano(s)</span></div>
    <div class="card-body p-0"><div class="table-responsive"><table class="table table-hover align-middle mb-0">
        <thead class="table-light"><tr><th>Plano</th><th>Preços</th><th>Trial</th><th>Capacidade</th><th>Suporte</th><th>Status</th><th class="text-end">Ações</th></tr></thead>
        <tbody><?php if(!$planosSaas): ?><tr><td colspan="7" class="text-center text-muted py-4">Nenhum plano cadastrado.</td></tr><?php else: foreach($planosSaas as $planoItem): ?>
        <tr>
            <td><strong><?=htmlspecialchars($planoItem['nome'])?></strong> <?php if(!empty($planoItem['destaque'])): ?><span class="badge bg-warning text-dark">Destaque</span><?php endif; ?><br><code><?=htmlspecialchars($planoItem['codigo'])?></code><br><small class="text-muted">Ordem: <?=(int)$planoItem['ordem_exibicao']?></small></td>
            <td><strong>R$ <?=number_format((float)$planoItem['valor_mensal'],2,',','.')?></strong>/mês<br><span>R$ <?=number_format((float)$planoItem['valor_anual'],2,',','.')?>/ano</span><br><small class="text-success"><?=number_format((float)$planoItem['desconto_anual_percentual'],2,',','.')?>% de desconto anual</small></td>
            <td><?=(int)$planoItem['trial_dias_padrao']?> dias<br><small class="text-muted">Faixa: <?=(int)$planoItem['trial_dias_minimo']?>–<?=(int)$planoItem['trial_dias_maximo']?> dias</small></td>
            <td><?=(int)$planoItem['limite_usuarios_padrao']?> usuário(s)<br><small class="text-muted"><?=(int)$planoItem['limite_armazenamento_gb_padrao']?> GB</small></td>
            <td><?=!empty($planoItem['suporte_incluso'])?'Incluído':'Não incluído'?><br><small class="text-muted"><?=htmlspecialchars(ucfirst($planoItem['nivel_suporte']))?></small></td>
            <td><span class="badge <?=!empty($planoItem['ativo'])?'bg-success':'bg-secondary'?>"><?=!empty($planoItem['ativo'])?'Ativo':'Inativo'?></span></td>
            <td class="text-end"><a href="?mod=configuracoes&tab=planos&editar_plano=<?=(int)$planoItem['id']?>" class="btn btn-sm btn-outline-primary" title="Editar"><i class="bi bi-pencil"></i></a>
                <form method="post" class="d-inline"><input type="hidden" name="csrf_token" value="<?=htmlspecialchars($csrf)?>"><input type="hidden" name="acao_cfg" value="alterar_status_plano_saas"><input type="hidden" name="plano_saas_id" value="<?=(int)$planoItem['id']?>"><input type="hidden" name="novo_status_plano_saas" value="<?=!empty($planoItem['ativo'])?0:1?>"><button class="btn btn-sm <?=!empty($planoItem['ativo'])?'btn-outline-danger':'btn-outline-success'?>" onclick="return confirm('Confirmar <?=!empty($planoItem['ativo'])?'inativação':'ativação'?> deste plano?')" title="<?=!empty($planoItem['ativo'])?'Inativar':'Ativar'?>"><i class="bi <?=!empty($planoItem['ativo'])?'bi-pause-circle':'bi-play-circle'?>"></i></button></form>
                <form method="post" class="d-inline"><input type="hidden" name="csrf_token" value="<?=htmlspecialchars($csrf)?>"><input type="hidden" name="acao_cfg" value="excluir_plano_saas"><input type="hidden" name="plano_saas_id" value="<?=(int)$planoItem['id']?>"><button class="btn btn-sm btn-danger" onclick="return confirm('Deseja realmente excluir definitivamente o plano <?=htmlspecialchars(addslashes((string)$planoItem['nome']), ENT_QUOTES, 'UTF-8')?>?\n\nEsta ação removerá também os módulos e o histórico de preços vinculados e não poderá ser desfeita.\n\nPlanos utilizados por escritórios ou licenças não serão excluídos.')" title="Excluir definitivamente"><i class="bi bi-trash"></i></button></form>
            </td>
        </tr><?php endforeach; endif; ?></tbody>
    </table></div></div>
</div>

<div class="card shadow-sm border-0 mb-4">
    <div class="card-header bg-light"><i class="bi bi-clock-history me-1"></i> Histórico recente de preços</div>
    <div class="card-body p-0"><div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead><tr><th>Data</th><th>Plano</th><th>Mensal</th><th>Anual</th><th>Motivo</th><th>Responsável</th></tr></thead><tbody>
    <?php if(!$historicoPrecosPlanos): ?><tr><td colspan="6" class="text-center text-muted py-3">Nenhuma alteração de preço registrada.</td></tr><?php else: foreach($historicoPrecosPlanos as $histPlano): ?><tr><td><?=date('d/m/Y H:i',strtotime($histPlano['criado_em']))?></td><td><?=htmlspecialchars($histPlano['plano_nome'])?></td><td><?=isset($histPlano['valor_mensal_anterior'])?'R$ '.number_format((float)$histPlano['valor_mensal_anterior'],2,',','.').' → ':''?><strong>R$ <?=number_format((float)$histPlano['valor_mensal_novo'],2,',','.')?></strong></td><td><?=isset($histPlano['valor_anual_anterior'])?'R$ '.number_format((float)$histPlano['valor_anual_anterior'],2,',','.').' → ':''?><strong>R$ <?=number_format((float)$histPlano['valor_anual_novo'],2,',','.')?></strong></td><td><?=htmlspecialchars($histPlano['motivo'] ?: '-')?></td><td><?=htmlspecialchars($histPlano['alterado_por_nome'] ?: 'Sistema')?></td></tr><?php endforeach; endif; ?>
    </tbody></table></div></div>
</div>
<script>
(function(){
    const mensal=document.getElementById('valorMensalPlano');
    const anual=document.getElementById('valorAnualPlano');
    const saida=document.getElementById('descontoAnualCalculado');
    function calcular(){
        const m=parseFloat(mensal?.value||0), a=parseFloat(anual?.value||0);
        let d=0; if(m>0){ d=Math.max(0,Math.min(100,(1-(a/(m*12)))*100)); }
        if(saida) saida.textContent=d.toLocaleString('pt-BR',{minimumFractionDigits:2,maximumFractionDigits:2})+'%';
    }
    mensal?.addEventListener('input',calcular); anual?.addEventListener('input',calcular); calcular();
})();
</script>
<?php endif; ?>


<?php if ($tab_ativa === 'modulos'): ?>
<div class="alert alert-primary border-0 shadow-sm"><strong><i class="bi bi-grid-3x3-gap-fill me-1"></i>Cadastro e Configurador de Módulos SaaS</strong><div class="small">Gerencie o catálogo técnico e comercial e defina quais módulos pertencem a cada plano.</div></div>
<div class="row g-3 mb-4">
<div class="col-md-3"><div class="card shadow-sm border-0"><div class="card-body"><small class="text-muted">MÓDULOS</small><h3><?=$totalModulosSaas?></h3></div></div></div>
<div class="col-md-3"><div class="card shadow-sm border-0"><div class="card-body"><small class="text-muted">ATIVOS</small><h3 class="text-success"><?=$totalModulosAtivos?></h3></div></div></div>
<div class="col-md-3"><div class="card shadow-sm border-0"><div class="card-body"><small class="text-muted">COM IA EXTERNA</small><h3 class="text-primary"><?=$totalModulosIa?></h3></div></div></div>
<div class="col-md-3"><div class="card shadow-sm border-0"><div class="card-body"><small class="text-muted">BETA</small><h3 class="text-warning"><?=$totalModulosBeta?></h3></div></div></div>
</div>
<div class="card shadow-sm border-0 mb-4"><div class="card-header bg-dark text-white"><i class="bi bi-plus-circle me-1"></i><?= $moduloEditar?'Editar módulo':'Novo módulo' ?></div><div class="card-body">
<form method="post" class="row g-3"><input type="hidden" name="csrf_token" value="<?=htmlspecialchars($csrf)?>"><input type="hidden" name="acao_cfg" value="salvar_modulo_saas"><input type="hidden" name="modulo_saas_id" value="<?=(int)($moduloEditar['id']??0)?>">
<div class="col-md-3"><label class="form-label">Código interno</label><input name="codigo_modulo_saas" class="form-control font-monospace" required value="<?=htmlspecialchars((string)($moduloEditar['codigo']??''))?>"></div>
<div class="col-md-5"><label class="form-label">Nome</label><input name="nome_modulo_saas" class="form-control" required value="<?=htmlspecialchars((string)($moduloEditar['nome']??''))?>"></div>
<div class="col-md-2"><label class="form-label">Categoria</label><input name="categoria_modulo_saas" class="form-control" value="<?=htmlspecialchars((string)($moduloEditar['categoria']??'operacional'))?>"></div>
<div class="col-md-2"><label class="form-label">Ordem</label><input type="number" min="0" name="ordem_modulo_saas" class="form-control" value="<?=(int)($moduloEditar['ordem_exibicao']??0)?>"></div>
<div class="col-md-8"><label class="form-label">Descrição</label><textarea name="descricao_modulo_saas" class="form-control" rows="2"><?=htmlspecialchars((string)($moduloEditar['descricao']??''))?></textarea></div>
<div class="col-md-2"><label class="form-label">Ícone Bootstrap</label><input name="icone_modulo_saas" class="form-control" value="<?=htmlspecialchars((string)($moduloEditar['icone']??'bi-box'))?>"></div>
<div class="col-md-2"><label class="form-label">Status</label><select name="status_lancamento_modulo_saas" class="form-select"><?php foreach(['producao'=>'Produção','beta'=>'Beta','desenvolvimento'=>'Em desenvolvimento','descontinuado'=>'Descontinuado'] as $v=>$l): ?><option value="<?=$v?>" <?=($moduloEditar['status_lancamento']??'producao')===$v?'selected':''?>><?=$l?></option><?php endforeach; ?></select></div>
<div class="col-12"><div class="row g-2">
<?php foreach([['essencial_modulo_saas','Essencial','modulo_essencial'],['permite_desativacao_modulo_saas','Pode ser removido','permite_desativacao'],['exige_ia_modulo_saas','Requer IA externa','exige_ia_externa'],['requer_api_modulo_saas','Requer API','requer_api'],['exibir_portal_modulo_saas','Exibir no portal','exibir_portal'],['exibir_menu_modulo_saas','Exibir no menu','exibir_menu'],['exibir_venda_modulo_saas','Exibir na venda','exibir_venda'],['ativo_modulo_saas','Ativo','ativo']] as $c): ?><div class="col-md-3"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="<?=$c[0]?>" <?=!empty($moduloEditar[$c[2]]??(in_array($c[2], ['permite_desativacao','exibir_menu','exibir_venda','ativo'], true)?1:0))?'checked':''?>><label class="form-check-label"><?=$c[1]?></label></div></div><?php endforeach; ?>
</div></div>
<div class="col-12 d-flex gap-2"><button class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Salvar módulo</button><?php if($moduloEditar): ?><a class="btn btn-outline-secondary" href="?mod=configuracoes&tab=modulos">Cancelar</a><?php endif; ?></div>
</form></div></div>
<div class="card shadow-sm border-0 mb-4"><div class="card-header bg-primary text-white">Catálogo de módulos <span class="badge bg-light text-primary float-end"><?=$totalModulosSaas?></span></div><div class="card-body p-0"><div class="table-responsive"><table class="table table-hover align-middle mb-0"><thead><tr><th>Módulo</th><th>Categoria</th><th>Características</th><th>Status</th><th class="text-end">Ações</th></tr></thead><tbody>
<?php foreach($modulosSaas as $m): ?><tr><td><i class="bi <?=htmlspecialchars($m['icone']?:'bi-box')?> me-2"></i><strong><?=htmlspecialchars($m['nome'])?></strong><br><code><?=htmlspecialchars($m['codigo'])?></code></td><td><?=htmlspecialchars(ucfirst($m['categoria']))?></td><td><?php if($m['modulo_essencial']): ?><span class="badge bg-dark">Essencial</span><?php endif; ?> <?php if($m['exige_ia_externa']): ?><span class="badge bg-primary">IA</span><?php endif; ?> <?php if(!empty($m['requer_api'])): ?><span class="badge bg-info text-dark">API</span><?php endif; ?></td><td><span class="badge <?=$m['ativo']?'bg-success':'bg-secondary'?>"><?=$m['ativo']?'Ativo':'Inativo'?></span> <span class="badge bg-light text-dark"><?=htmlspecialchars(ucfirst($m['status_lancamento']??'producao'))?></span></td><td class="text-end"><a class="btn btn-sm btn-outline-primary" href="?mod=configuracoes&tab=modulos&editar_modulo=<?=(int)$m['id']?>"><i class="bi bi-pencil"></i></a> <form method="post" class="d-inline"><input type="hidden" name="csrf_token" value="<?=htmlspecialchars($csrf)?>"><input type="hidden" name="acao_cfg" value="alterar_status_modulo_saas"><input type="hidden" name="modulo_saas_id" value="<?=(int)$m['id']?>"><input type="hidden" name="novo_status_modulo_saas" value="<?=$m['ativo']?0:1?>"><button class="btn btn-sm <?=$m['ativo']?'btn-outline-danger':'btn-outline-success'?>" onclick="return confirm('Confirmar alteração de status?')"><i class="bi <?=$m['ativo']?'bi-pause-circle':'bi-play-circle'?>"></i></button></form> <form method="post" class="d-inline"><input type="hidden" name="csrf_token" value="<?=htmlspecialchars($csrf)?>"><input type="hidden" name="acao_cfg" value="excluir_modulo_saas"><input type="hidden" name="modulo_saas_id" value="<?=(int)$m['id']?>"><button class="btn btn-sm btn-danger" onclick="return confirm('Excluir definitivamente este módulo? Módulos essenciais ou vinculados serão protegidos.')"><i class="bi bi-trash"></i></button></form></td></tr><?php endforeach; ?>
</tbody></table></div></div></div>
<div class="card shadow-sm border-0"><div class="card-header bg-dark text-white"><i class="bi bi-diagram-3 me-1"></i>Montagem dos planos por módulos</div><div class="card-body">
<form method="get" class="row g-2 mb-3"><input type="hidden" name="mod" value="configuracoes"><input type="hidden" name="tab" value="modulos"><div class="col-md-6"><select name="configurar_plano" class="form-select" onchange="this.form.submit()"><?php foreach($planosSaas as $p): ?><option value="<?=(int)$p['id']?>" <?=$configuradorPlanoId===(int)$p['id']?'selected':''?>><?=htmlspecialchars($p['nome'])?> — R$ <?=number_format((float)$p['valor_mensal'],2,',','.')?>/mês</option><?php endforeach; ?></select></div></form>
<form method="post"><input type="hidden" name="csrf_token" value="<?=htmlspecialchars($csrf)?>"><input type="hidden" name="acao_cfg" value="salvar_configuracao_plano_modulos"><input type="hidden" name="plano_configurador_id" value="<?=$configuradorPlanoId?>"><div class="table-responsive"><table class="table table-sm align-middle"><thead><tr><th>Módulo</th><th>Incluído</th><th>Obrigatório</th><th>Removível</th><th>Desconto mensal</th><th>Desconto anual</th></tr></thead><tbody>
<?php foreach($modulosSaas as $m): $v=$vinculosPlanoModulo[(int)$m['id']]??[]; $ess=!empty($m['modulo_essencial']); ?><tr><td><strong><?=htmlspecialchars($m['nome'])?></strong><br><small class="text-muted"><?=htmlspecialchars($m['categoria'])?></small></td><td><input type="checkbox" name="modulos_plano[<?=(int)$m['id']?>][incluido]" <?=$ess||!empty($v['incluido_padrao'])?'checked':''?> <?=$ess?'disabled':''?>><?php if($ess): ?><input type="hidden" name="modulos_plano[<?=(int)$m['id']?>][incluido]" value="1"><?php endif; ?></td><td><input type="checkbox" name="modulos_plano[<?=(int)$m['id']?>][obrigatorio]" <?=$ess||!empty($v['obrigatorio'])?'checked':''?> <?=$ess?'disabled':''?>><?php if($ess): ?><input type="hidden" name="modulos_plano[<?=(int)$m['id']?>][obrigatorio]" value="1"><?php endif; ?></td><td><input type="checkbox" name="modulos_plano[<?=(int)$m['id']?>][permite_remocao]" <?=!empty($v['permite_remocao'])?'checked':''?> <?=($ess||empty($m['permite_desativacao']))?'disabled':''?>></td><td><input type="number" min="0" step="0.01" class="form-control form-control-sm" name="modulos_plano[<?=(int)$m['id']?>][desconto_mensal]" value="<?=number_format((float)($v['desconto_remocao_mensal']??0),2,'.','')?>"></td><td><input type="number" min="0" step="0.01" class="form-control form-control-sm" name="modulos_plano[<?=(int)$m['id']?>][desconto_anual]" value="<?=number_format((float)($v['desconto_remocao_anual']??0),2,'.','')?>"></td></tr><?php endforeach; ?>
</tbody></table></div><button class="btn btn-success"><i class="bi bi-check2-square me-1"></i>Salvar composição do plano</button></form>
</div></div>
<?php endif; ?>


<?php if ($tab_ativa === 'portal'): ?>
<div class="alert alert-primary border-0 shadow-sm">
    <strong><i class="bi bi-person-workspace me-1"></i> Portal do Cliente — ativação controlada</strong>
    <div class="small">Ativar o módulo ou criar uma conta não publica processos, documentos, honorários, recibos ou agenda.</div>
</div>

<?php if ($portalConviteGerado): ?>
<div class="alert alert-warning shadow-sm">
    <strong>Convite gerado — copie agora.</strong>
    <div class="small mb-2">E-mail: <?=htmlspecialchars((string)$portalConviteGerado['email'])?> · expira em <?=htmlspecialchars((string)$portalConviteGerado['expira_em'])?></div>
    <div class="input-group"><input id="portalConviteUrl" class="form-control font-monospace" readonly value="<?=htmlspecialchars((string)$portalConviteGerado['url'])?>"><button type="button" class="btn btn-dark" onclick="navigator.clipboard.writeText(document.getElementById('portalConviteUrl').value)">Copiar link</button></div>
    <div class="form-text">O token não é armazenado em texto aberto e este link será exibido somente agora.</div>
</div>
<?php endif; ?>

<div class="row g-4 mb-4">
    <div class="col-xl-5"><div class="card shadow-sm border-0 h-100"><div class="card-header bg-dark text-white">1. Ativar por escritório</div><div class="card-body">
        <form method="post" class="row g-3">
            <input type="hidden" name="csrf_token" value="<?=htmlspecialchars($csrf)?>"><input type="hidden" name="acao_cfg" value="ativar_portal_escritorio">
            <div class="col-12"><label class="form-label">Escritório</label><select name="portal_escritorio_id" class="form-select" required><option value="">Selecione</option><?php foreach($portalEscritorios as $pe): ?><option value="<?=(int)$pe['id']?>"><?=htmlspecialchars($pe['nome'])?> — <?=htmlspecialchars($pe['tenant_id'])?> (<?=!empty($pe['portal_ativo'])?'Portal ativo':'Portal inativo'?>)</option><?php endforeach; ?></select></div>
            <div class="col-12"><button class="btn btn-primary" onclick="return confirm('Ativar o Portal para este escritório? Nenhum conteúdo será publicado.')"><i class="bi bi-check-circle me-1"></i>Ativar Portal</button></div>
        </form>
    </div></div></div>
    <div class="col-xl-7"><div class="card shadow-sm border-0 h-100"><div class="card-header bg-primary text-white">2. Criar conta e convite</div><div class="card-body">
        <form method="post" class="row g-3">
            <input type="hidden" name="csrf_token" value="<?=htmlspecialchars($csrf)?>"><input type="hidden" name="acao_cfg" value="criar_conta_portal">
            <div class="col-md-6"><label class="form-label">Escritório</label><select name="portal_escritorio_id" id="portalEscritorioConta" class="form-select" required><option value="">Selecione</option><?php foreach($portalEscritorios as $pe): if(empty($pe['portal_ativo'])) continue; ?><option value="<?=(int)$pe['id']?>"><?=htmlspecialchars($pe['nome'])?></option><?php endforeach; ?></select></div>
            <div class="col-md-6"><label class="form-label">Cliente ativo</label><select name="portal_cliente_id" id="portalClienteConta" class="form-select" required><option value="">Selecione</option><?php foreach($portalClientes as $pc): ?><option value="<?=htmlspecialchars($pc['id'])?>" data-escritorio="<?=(int)$pc['escritorio_id']?>" data-email="<?=htmlspecialchars((string)$pc['email'])?>"><?=htmlspecialchars($pc['nome'])?> — <?=htmlspecialchars($pc['id'])?></option><?php endforeach; ?></select></div>
            <div class="col-12"><label class="form-label">E-mail de acesso</label><input type="email" name="portal_email" id="portalEmailConta" class="form-control" maxlength="190" required></div>
            <div class="col-12"><div class="alert alert-light border small mb-0"><strong>Permissões iniciais:</strong> todas desativadas. A publicação de conteúdo será realizada apenas em etapa posterior e por ação expressa.</div></div>
            <div class="col-12"><button class="btn btn-success" onclick="return confirm('Criar a conta com todas as permissões desativadas e gerar convite válido por 48 horas?')"><i class="bi bi-envelope-check me-1"></i>Criar conta e convite</button></div>
        </form>
    </div></div></div>
</div>

<div class="card shadow-sm border-0"><div class="card-header bg-dark text-white">Contas do Portal</div><div class="card-body p-0"><div class="table-responsive"><table class="table table-hover align-middle mb-0"><thead><tr><th>Escritório</th><th>Cliente</th><th>E-mail</th><th>Permissões</th><th>Status</th><th class="text-end">Ações</th></tr></thead><tbody>
<?php if(!$portalContas): ?><tr><td colspan="6" class="text-center text-muted py-4">Nenhuma conta criada.</td></tr><?php else: foreach($portalContas as $pc): ?>
<tr><td><strong><?=htmlspecialchars($pc['escritorio_nome'])?></strong><br><code><?=htmlspecialchars($pc['tenant_id'])?></code></td><td><?=htmlspecialchars($pc['cliente_nome'])?><br><small><?=htmlspecialchars($pc['cliente_id'])?></small></td><td><?=htmlspecialchars($pc['email'])?></td><td><small><?php $qtdPermissoes=array_sum(array_map('intval',[$pc['ver_processos']??0,$pc['ver_documentos']??0,$pc['enviar_documentos']??0,$pc['ver_honorarios']??0,$pc['ver_recibos']??0,$pc['ver_agenda']??0,$pc['receber_notificacoes']??0])); ?><?=$qtdPermissoes?> de 7 habilitadas</small></td><td><span class="badge <?=($pc['status']==='ATIVA')?'bg-success':(($pc['status']==='CONVITE_PENDENTE')?'bg-warning text-dark':'bg-secondary')?>"><?=htmlspecialchars($pc['status'])?></span></td><td class="text-end">
    <?php if($pc['status']==='CONVITE_PENDENTE'): ?><form method="post" class="d-inline"><input type="hidden" name="csrf_token" value="<?=htmlspecialchars($csrf)?>"><input type="hidden" name="acao_cfg" value="reemitir_convite_portal"><input type="hidden" name="portal_conta_id" value="<?=(int)$pc['id']?>"><button class="btn btn-sm btn-outline-primary" onclick="return confirm('Revogar o convite anterior e gerar um novo?')">Novo convite</button></form><?php endif; ?>
    <?php if(in_array($pc['status'],['ATIVA','DESATIVADA'],true)): ?><form method="post" class="d-inline"><input type="hidden" name="csrf_token" value="<?=htmlspecialchars($csrf)?>"><input type="hidden" name="acao_cfg" value="alterar_status_conta_portal"><input type="hidden" name="portal_conta_id" value="<?=(int)$pc['id']?>"><input type="hidden" name="portal_novo_status" value="<?=$pc['status']==='ATIVA'?'DESATIVADA':'ATIVA'?>"><button class="btn btn-sm <?=$pc['status']==='ATIVA'?'btn-outline-danger':'btn-outline-success'?>" onclick="return confirm('Confirmar alteração do status desta conta?')"><?=$pc['status']==='ATIVA'?'Desativar':'Ativar'?></button></form><?php endif; ?>
</td></tr><?php endforeach; endif; ?></tbody></table></div></div></div>
<script>(function(){const e=document.getElementById('portalEscritorioConta'),c=document.getElementById('portalClienteConta'),m=document.getElementById('portalEmailConta');function f(){const id=e.value;for(const o of c.options){if(!o.value)continue;o.hidden=o.dataset.escritorio!==id;}if(c.selectedOptions[0]?.hidden)c.value='';m.value=c.selectedOptions[0]?.dataset.email||'';}e?.addEventListener('change',f);c?.addEventListener('change',()=>{m.value=c.selectedOptions[0]?.dataset.email||'';});f();})();</script>
<?php endif; ?>

<?php if ($tab_ativa === 'administracao'): ?>
<div class="alert alert-primary border-0 shadow-sm d-flex align-items-start gap-2">
    <i class="bi bi-shield-lock-fill fs-4"></i>
    <div>
        <strong>Administração Enterprise — acesso exclusivo MASTER</strong>
        <div class="small">Base central da operação SaaS. Nesta primeira etapa, o painel é seguro e informativo: nenhuma licença, escritório, backup ou atualização é executado automaticamente.</div>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-md-3"><div class="card shadow-sm border-0 h-100"><div class="card-body"><small class="text-muted">ESCRITÓRIOS</small><h3 class="mb-0 text-primary"><?=number_format($totalEscritoriosSaas,0,',','.')?></h3><small class="text-success"><?=$totalEscritoriosAtivos?> ativo(s)</small></div></div></div>
    <div class="col-md-3"><div class="card shadow-sm border-0 h-100"><div class="card-body"><small class="text-muted">LICENÇAS</small><h3 class="mb-0 text-success"><?=number_format($totalLicencasSaas,0,',','.')?></h3><small class="text-muted"><?=$totalLicencasAtivas?> ativa(s)</small></div></div></div>
    <div class="col-md-3"><div class="card shadow-sm border-0 h-100"><div class="card-body"><small class="text-muted">USUÁRIOS DESLIGADOS</small><h3 class="mb-0 text-warning"><?=number_format($totalUsuariosDesligados,0,',','.')?></h3><small class="text-muted">histórico preservado</small></div></div></div>
    <div class="col-md-3"><div class="card shadow-sm border-0 h-100"><div class="card-body"><small class="text-muted">SAÚDE CONSOLIDADA</small><h3 class="mb-0 <?=$percentualSaude>=85?'text-success':'text-warning'?>"><?=$percentualSaude?>%</h3><small class="text-muted">ambiente atual</small></div></div></div>
</div>

<div class="row g-4">
    <div class="col-xl-8">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-header bg-dark text-white"><i class="bi bi-grid-1x2-fill me-1"></i> Central Administrativa SaaS</div>
            <div class="card-body">
                <div class="row g-3">
                    <?php foreach ([
                        ['Central de Licenças','Planos, limites, vigência, suspensão e renovação.','bi-key-fill',$totalLicencasSaas],
                        ['Gestão de Escritórios','Cadastro multi-tenant e situação operacional.','bi-buildings-fill',$totalEscritoriosSaas],
                        ['Usuários Desligados','Consulta do histórico preservado para auditoria.','bi-person-x-fill',$totalUsuariosDesligados],
                        ['Relatórios Administrativos','Preparação para exportações PDF e Excel.','bi-file-earmark-bar-graph-fill',0],
                        ['Saúde Consolidada','Servidor, banco, segurança, disco e módulos.','bi-heart-pulse-fill',$percentualSaude],
                        ['Ferramentas de Manutenção','Rotinas controladas, auditáveis e exclusivas MASTER.','bi-tools',0],
                        ['Estrutura de Backup','Inventário de backups e futuras políticas automáticas.','bi-database-fill-down',$totalBackupsRegistrados],
                        ['Atualizações do Sistema','Controle de versões e implantação segura.','bi-cloud-arrow-down-fill',$totalAtualizacoesPendentes],
                    ] as $blocoAdmin): ?>
                    <div class="col-md-6">
                        <div class="border rounded p-3 h-100 bg-light">
                            <div class="d-flex justify-content-between align-items-start gap-2">
                                <div>
                                    <h6 class="mb-1"><i class="bi <?=$blocoAdmin[2]?> me-1 text-primary"></i><?=htmlspecialchars($blocoAdmin[0])?></h6>
                                    <p class="small text-muted mb-0"><?=htmlspecialchars($blocoAdmin[1])?></p>
                                </div>
                                <span class="badge bg-secondary"><?=is_int($blocoAdmin[3]) ? $blocoAdmin[3] : htmlspecialchars((string)$blocoAdmin[3])?></span>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="col-xl-4">
        <div class="card shadow-sm border-0 mb-4">
            <div class="card-header bg-dark text-white"><i class="bi bi-diagram-3-fill me-1"></i> Identidade SaaS</div>
            <div class="card-body small">
                <div class="mb-2"><span class="text-muted">Tenant:</span><br><code><?=htmlspecialchars($cfg['tenant_id'])?></code></div>
                <div class="mb-2"><span class="text-muted">Instalação:</span><br><code><?=htmlspecialchars($cfg['identificador_instalacao'])?></code></div>
                <div class="mb-2"><span class="text-muted">Plano atual:</span> <strong><?=htmlspecialchars(strtoupper($cfg['plano_licenca']))?></strong></div>
                <div><span class="text-muted">Status:</span> <span class="badge <?=$cfg['status_licenca']==='ativa'?'bg-success':'bg-warning text-dark'?>"><?=htmlspecialchars($cfg['status_licenca'])?></span></div>
            </div>
        </div>
        <div class="card shadow-sm border-0">
            <div class="card-header bg-dark text-white"><i class="bi bi-lock-fill me-1"></i> Segurança da Etapa</div>
            <div class="card-body small">
                <p class="mb-2"><i class="bi bi-check-circle-fill text-success me-1"></i>Acesso validado pelo MASTER técnico.</p>
                <p class="mb-2"><i class="bi bi-check-circle-fill text-success me-1"></i>Tabelas criadas sem apagar ou alterar registros existentes.</p>
                <p class="mb-2"><i class="bi bi-check-circle-fill text-success me-1"></i>Sem comunicação externa ou atualização remota.</p>
                <p class="mb-0"><i class="bi bi-check-circle-fill text-success me-1"></i>Pronto para receber a Central de Licenças no próximo arquivo homologado.</p>
            </div>
        </div>
    </div>
</div>
<div class="card shadow-sm border-0 mt-4">
    <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center flex-wrap gap-2">
        <span><i class="bi bi-key-fill me-1"></i> Central de Licenças</span>
        <span class="badge bg-light text-primary"><?=count($licencasSaas)?> licença(s)</span>
    </div>
    <div class="card-body">
        <div class="alert alert-info small border-0">
            A licença vinculada ao tenant desta instalação permanece sincronizada com as configurações antigas, garantindo compatibilidade com os módulos existentes.
        </div>
        <form method="post" class="row g-3 mb-4">
            <input type="hidden" name="csrf_token" value="<?=htmlspecialchars($csrf)?>">
            <input type="hidden" name="acao_cfg" value="salvar_licenca_saas">
            <input type="hidden" name="licenca_id" value="<?=(int)($licencaEditar['id'] ?? 0)?>">
            <div class="col-lg-4">
                <label class="form-label">Escritório / tenant</label>
                <select name="escritorio_id" class="form-select" required>
                    <option value="">Selecione</option>
                    <?php foreach ($escritoriosSaas as $escritorioLicenca): ?>
                        <option value="<?=(int)$escritorioLicenca['id']?>" <?=((int)($licencaEditar['escritorio_id'] ?? $escritorioAtualSaasId)===(int)$escritorioLicenca['id'])?'selected':''?>>
                            <?=htmlspecialchars($escritorioLicenca['nome'])?> — <?=htmlspecialchars($escritorioLicenca['tenant_id'])?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-lg-4">
                <label class="form-label">Chave da licença</label>
                <input type="text" name="chave_licenca" class="form-control font-monospace" maxlength="120" required value="<?=htmlspecialchars((string)($licencaEditar['chave_licenca'] ?? ''))?>" placeholder="ROJEX-LICENCA-...">
            </div>
            <div class="col-lg-2">
                <label class="form-label">Plano</label>
                <select name="plano_licenca_saas" class="form-select">
                    <?php foreach (['starter'=>'Starter','professional'=>'Professional','enterprise'=>'Enterprise'] as $valorPlano=>$nomePlano): ?>
                        <option value="<?=$valorPlano?>" <?=(($licencaEditar['plano'] ?? 'enterprise')===$valorPlano)?'selected':''?>><?=$nomePlano?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-lg-2">
                <label class="form-label">Status</label>
                <select name="status_licenca_saas" class="form-select">
                    <?php foreach (['teste'=>'Teste','ativa'=>'Ativa','suspensa'=>'Suspensa','expirada'=>'Expirada','cancelada'=>'Cancelada'] as $valorStatus=>$nomeStatus): ?>
                        <option value="<?=$valorStatus?>" <?=(($licencaEditar['status'] ?? 'teste')===$valorStatus)?'selected':''?>><?=$nomeStatus?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3"><label class="form-label">Limite de usuários</label><input type="number" name="limite_usuarios_saas" class="form-control" min="1" max="1000" value="<?=(int)($licencaEditar['limite_usuarios'] ?? 100)?>"></div>
            <div class="col-md-3"><label class="form-label">Armazenamento (GB)</label><input type="number" name="limite_armazenamento_saas" class="form-control" min="1" max="100000" value="<?=(int)($licencaEditar['limite_armazenamento_gb'] ?? 50)?>"></div>
            <div class="col-md-3"><label class="form-label">Ativação</label><input type="date" name="ativada_em" class="form-control" value="<?=htmlspecialchars((string)($licencaEditar['ativada_em'] ?? ''))?>"></div>
            <div class="col-md-3"><label class="form-label">Renovação</label><input type="date" name="renovacao_em" class="form-control" value="<?=htmlspecialchars((string)($licencaEditar['renovacao_em'] ?? ''))?>"></div>
            <div class="col-12"><label class="form-label">Observações administrativas</label><textarea name="observacoes_licenca" class="form-control" rows="2" maxlength="1500"><?=htmlspecialchars((string)($licencaEditar['observacoes'] ?? ''))?></textarea></div>
            <div class="col-12 d-flex gap-2">
                <button class="btn btn-primary"><i class="bi bi-save me-1"></i><?=$licencaEditar?'Atualizar licença':'Cadastrar licença'?></button>
                <?php if ($licencaEditar): ?><a href="?mod=configuracoes&tab=administracao" class="btn btn-outline-secondary">Cancelar edição</a><?php endif; ?>
            </div>
        </form>

        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light"><tr><th>Escritório</th><th>Chave</th><th>Plano</th><th>Status</th><th>Limites</th><th>Vigência</th><th class="text-end">Ações</th></tr></thead>
                <tbody>
                <?php if (!$licencasSaas): ?>
                    <tr><td colspan="7" class="text-center text-muted py-4">Nenhuma licença cadastrada.</td></tr>
                <?php else: foreach ($licencasSaas as $licencaItem):
                    $statusBadge = ['ativa'=>'success','teste'=>'info','suspensa'=>'warning','expirada'=>'danger','cancelada'=>'secondary'][$licencaItem['status']] ?? 'secondary';
                ?>
                    <tr>
                        <td><strong><?=htmlspecialchars((string)($licencaItem['escritorio_nome'] ?? 'Sem vínculo'))?></strong><br><small class="text-muted"><?=htmlspecialchars((string)($licencaItem['tenant_id'] ?? '-'))?></small></td>
                        <td><code><?=htmlspecialchars($licencaItem['chave_licenca'])?></code></td>
                        <td><?=htmlspecialchars(ucfirst($licencaItem['plano']))?></td>
                        <td><span class="badge bg-<?=$statusBadge?>"><?=htmlspecialchars($licencaItem['status'])?></span></td>
                        <td><small><?=(int)$licencaItem['limite_usuarios']?> usuário(s)<br><?=(int)$licencaItem['limite_armazenamento_gb']?> GB</small></td>
                        <td><small>Ativação: <?=htmlspecialchars($licencaItem['ativada_em'] ?: '-')?><br>Renovação: <?=htmlspecialchars($licencaItem['renovacao_em'] ?: '-')?></small></td>
                        <td class="text-end">
                            <a class="btn btn-sm btn-outline-primary" href="?mod=configuracoes&tab=administracao&editar_licenca=<?=(int)$licencaItem['id']?>" title="Editar"><i class="bi bi-pencil"></i></a>
                            <div class="dropdown d-inline-block">
                                <button class="btn btn-sm btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown">Status</button>
                                <ul class="dropdown-menu dropdown-menu-end">
                                    <?php foreach (['ativa'=>'Ativar','teste'=>'Colocar em teste','suspensa'=>'Suspender','expirada'=>'Marcar expirada','cancelada'=>'Cancelar'] as $statusAcao=>$rotuloAcao): ?>
                                    <li><form method="post"><input type="hidden" name="csrf_token" value="<?=htmlspecialchars($csrf)?>"><input type="hidden" name="acao_cfg" value="alterar_status_licenca_saas"><input type="hidden" name="licenca_id" value="<?=(int)$licencaItem['id']?>"><input type="hidden" name="novo_status" value="<?=$statusAcao?>"><button class="dropdown-item" onclick="return confirm('Confirma a alteração do status desta licença?')"><?=$rotuloAcao?></button></form></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>


<?php if ($tab_ativa === 'administracao'): ?>
<div class="card shadow-sm border-0 mt-4">
    <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center flex-wrap gap-2">
        <span><i class="bi bi-buildings-fill me-1"></i> Gestão de Escritórios — Multi-Tenant</span>
        <span class="badge bg-light text-dark"><?=count($escritoriosSaas)?> resultado(s)</span>
    </div>
    <div class="card-body">
        <div class="alert alert-warning small border-0">
            Esta etapa administra o cadastro central dos tenants. O isolamento físico/lógico dos dados dos módulos será aplicado em etapa específica de migração, sem misturar registros atuais.
        </div>

        <form method="get" class="row g-2 mb-4">
            <input type="hidden" name="mod" value="configuracoes">
            <input type="hidden" name="tab" value="administracao">
            <div class="col-lg-5"><input type="search" name="escritorio_q" class="form-control" value="<?=htmlspecialchars($escritorioBusca)?>" placeholder="Buscar por nome, tenant, documento ou responsável"></div>
            <div class="col-lg-2"><select name="escritorio_status" class="form-select"><option value="">Todos os status</option><?php foreach(['implantacao'=>'Implantação','ativo'=>'Ativo','suspenso'=>'Suspenso','bloqueado'=>'Bloqueado','encerrado'=>'Encerrado'] as $v=>$n): ?><option value="<?=$v?>" <?=$escritorioStatusFiltro===$v?'selected':''?>><?=$n?></option><?php endforeach; ?></select></div>
            <div class="col-lg-2"><select name="escritorio_plano" class="form-select"><option value="">Todos os planos</option><?php foreach(['starter'=>'Starter','professional'=>'Professional','enterprise'=>'Enterprise'] as $v=>$n): ?><option value="<?=$v?>" <?=$escritorioPlanoFiltro===$v?'selected':''?>><?=$n?></option><?php endforeach; ?></select></div>
            <div class="col-lg-3 d-flex gap-2"><button class="btn btn-outline-primary flex-grow-1"><i class="bi bi-search"></i> Filtrar</button><a href="?mod=configuracoes&tab=administracao" class="btn btn-outline-secondary">Limpar</a></div>
        </form>

        <form method="post" class="row g-3 border rounded p-3 bg-light mb-4">
            <input type="hidden" name="csrf_token" value="<?=htmlspecialchars($csrf)?>">
            <input type="hidden" name="acao_cfg" value="salvar_escritorio_saas">
            <input type="hidden" name="escritorio_saas_id" value="<?=(int)($escritorioEditar['id'] ?? 0)?>">
            <div class="col-12"><h6 class="mb-0"><?=$escritorioEditar?'Editar escritório':'Cadastrar novo escritório'?></h6></div>
            <div class="col-lg-4"><label class="form-label">Nome do escritório *</label><input name="nome_escritorio_saas" class="form-control" maxlength="180" required value="<?=htmlspecialchars((string)($escritorioEditar['nome'] ?? ''))?>"></div>
            <div class="col-lg-4"><label class="form-label">Tenant ID</label><input name="tenant_id_saas" class="form-control font-monospace" maxlength="80" value="<?=htmlspecialchars((string)($escritorioEditar['tenant_id'] ?? ''))?>" placeholder="Gerado automaticamente se vazio"></div>
            <div class="col-lg-4"><label class="form-label">CPF/CNPJ</label><input name="documento_escritorio_saas" class="form-control" maxlength="30" value="<?=htmlspecialchars((string)($escritorioEditar['documento'] ?? ''))?>"></div>
            <div class="col-lg-3"><label class="form-label">Responsável</label><input name="responsavel_escritorio_saas" class="form-control" maxlength="140" value="<?=htmlspecialchars((string)($escritorioEditar['responsavel'] ?? ''))?>"></div>
            <div class="col-lg-3"><label class="form-label">E-mail</label><input type="email" name="email_escritorio_saas" class="form-control" maxlength="140" value="<?=htmlspecialchars((string)($escritorioEditar['email'] ?? ''))?>"></div>
            <div class="col-lg-2"><label class="form-label">Telefone</label><input name="telefone_escritorio_saas" class="form-control" maxlength="40" value="<?=htmlspecialchars((string)($escritorioEditar['telefone'] ?? ''))?>"></div>
            <div class="col-lg-2"><label class="form-label">Cidade</label><input name="cidade_escritorio_saas" class="form-control" maxlength="100" value="<?=htmlspecialchars((string)($escritorioEditar['cidade'] ?? ''))?>"></div>
            <div class="col-lg-2"><label class="form-label">UF</label><input name="uf_escritorio_saas" class="form-control text-uppercase" maxlength="2" value="<?=htmlspecialchars((string)($escritorioEditar['uf'] ?? ''))?>"></div>
            <div class="col-lg-4"><label class="form-label">Subdomínio</label><input name="subdominio_escritorio_saas" class="form-control" maxlength="180" value="<?=htmlspecialchars((string)($escritorioEditar['subdominio'] ?? ''))?>" placeholder="cliente.rojex.ai"></div>
            <div class="col-lg-2"><label class="form-label">Plano</label><select name="plano_escritorio_saas" class="form-select"><?php foreach(['starter'=>'Starter','professional'=>'Professional','enterprise'=>'Enterprise'] as $v=>$n): ?><option value="<?=$v?>" <?=($escritorioEditar['plano'] ?? 'enterprise')===$v?'selected':''?>><?=$n?></option><?php endforeach; ?></select></div>
            <div class="col-lg-2"><label class="form-label">Status</label><select name="status_escritorio_saas" class="form-select"><?php foreach(['implantacao'=>'Implantação','ativo'=>'Ativo','suspenso'=>'Suspenso','bloqueado'=>'Bloqueado','encerrado'=>'Encerrado'] as $v=>$n): ?><option value="<?=$v?>" <?=($escritorioEditar['status'] ?? 'implantacao')===$v?'selected':''?>><?=$n?></option><?php endforeach; ?></select></div>
            <div class="col-lg-4"><label class="form-label">Observações</label><input name="observacoes_escritorio_saas" class="form-control" maxlength="1500" value="<?=htmlspecialchars((string)($escritorioEditar['observacoes'] ?? ''))?>"></div>
            <div class="col-12 d-flex gap-2"><button class="btn btn-dark"><i class="bi bi-save me-1"></i><?=$escritorioEditar?'Atualizar escritório':'Cadastrar escritório'?></button><?php if($escritorioEditar): ?><a class="btn btn-outline-secondary" href="?mod=configuracoes&tab=administracao">Cancelar edição</a><?php endif; ?></div>
        </form>

        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead class="table-light"><tr><th>Escritório / Tenant</th><th>Responsável</th><th>Plano</th><th>Status</th><th>Licenças</th><th>Atualização</th><th class="text-end">Ações</th></tr></thead>
                <tbody>
                <?php if(!$escritoriosSaas): ?><tr><td colspan="7" class="text-center text-muted py-4">Nenhum escritório encontrado.</td></tr>
                <?php else: foreach($escritoriosSaas as $eItem):
                    $badgeE=['ativo'=>'success','implantacao'=>'info','suspenso'=>'warning','bloqueado'=>'danger','encerrado'=>'secondary'][$eItem['status']] ?? 'secondary';
                ?>
                <tr>
                    <td><strong><?=htmlspecialchars($eItem['nome'])?></strong><br><code><?=htmlspecialchars($eItem['tenant_id'])?></code><br><small class="text-muted"><?=htmlspecialchars(trim(($eItem['cidade'] ?? '').' '.($eItem['uf'] ?? '')) ?: '-')?></small></td>
                    <td><?=htmlspecialchars($eItem['responsavel'] ?: '-')?><br><small class="text-muted"><?=htmlspecialchars($eItem['email'] ?: '-')?></small></td>
                    <td><?=htmlspecialchars(ucfirst($eItem['plano']))?></td>
                    <td><span class="badge bg-<?=$badgeE?>"><?=htmlspecialchars($eItem['status'])?></span></td>
                    <td><small><?=(int)$eItem['total_licencas']?> total<br><?=(int)$eItem['licencas_ativas']?> ativa(s)</small></td>
                    <td><small><?=!empty($eItem['atualizado_em'])?date('d/m/Y H:i',strtotime($eItem['atualizado_em'])):'-'?></small></td>
                    <td class="text-end">
                        <a href="?mod=configuracoes&tab=administracao&editar_escritorio=<?=(int)$eItem['id']?>" class="btn btn-sm btn-outline-primary" title="Editar"><i class="bi bi-pencil"></i></a>
                        <div class="dropdown d-inline-block">
                            <button class="btn btn-sm btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown">Status</button>
                            <ul class="dropdown-menu dropdown-menu-end">
                                <?php foreach(['implantacao'=>'Implantação','ativo'=>'Ativar','suspenso'=>'Suspender','bloqueado'=>'Bloquear','encerrado'=>'Encerrar'] as $v=>$n): ?>
                                <li><form method="post"><input type="hidden" name="csrf_token" value="<?=htmlspecialchars($csrf)?>"><input type="hidden" name="acao_cfg" value="alterar_status_escritorio_saas"><input type="hidden" name="escritorio_saas_id" value="<?=(int)$eItem['id']?>"><input type="hidden" name="novo_status_escritorio" value="<?=$v?>"><button class="dropdown-item" <?=$eItem['status']===$v?'disabled':''?> onclick="return confirm('Confirmar alteração do status deste escritório?')"><?=$n?></button></form></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    </td>
                </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php endif; ?>




<?php if ($tab_ativa === 'saude'): ?>
<div class="card shadow-sm border-0 mb-4">
    <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center">
        <span><i class="bi bi-heart-pulse-fill me-1"></i> Painel de Saúde Consolidado Enterprise</span>
        <span class="badge <?=$pontuacaoSaudeConsolidada>=85?'bg-success':($pontuacaoSaudeConsolidada>=65?'bg-warning text-dark':'bg-danger')?>"><?=$pontuacaoSaudeConsolidada?>%</span>
    </div>
    <div class="card-body">
        <div class="alert alert-info border-0">
            Diagnóstico técnico do ambiente atual. Itens de localhost podem aparecer como atenção e devem ser revistos antes do deploy na Hostinger.
        </div>

        <div class="row g-3 mb-4">
            <div class="col-md-3"><div class="card border-success h-100"><div class="card-body"><small class="text-muted">EXCELENTE</small><h2 class="text-success mb-0"><?=$quantidadeExcelenteSaude?></h2></div></div></div>
            <div class="col-md-3"><div class="card border-warning h-100"><div class="card-body"><small class="text-muted">ATENÇÃO</small><h2 class="text-warning mb-0"><?=$quantidadeAtencaoSaude?></h2></div></div></div>
            <div class="col-md-3"><div class="card border-danger h-100"><div class="card-body"><small class="text-muted">CRÍTICO</small><h2 class="text-danger mb-0"><?=$quantidadeCriticoSaude?></h2></div></div></div>
            <div class="col-md-3"><div class="card border-primary h-100"><div class="card-body"><small class="text-muted">TEMPO DO DIAGNÓSTICO</small><h2 class="text-primary mb-0"><?=number_format($tempoRespostaDiagnosticoMs,2,',','.')?> ms</h2></div></div></div>
        </div>

        <div class="row g-4 mb-4">
            <div class="col-xl-6">
                <div class="card h-100 border-0 bg-light">
                    <div class="card-body">
                        <h6><i class="bi bi-database me-1"></i> Banco de Dados</h6>
                        <div class="table-responsive"><table class="table table-sm mb-0">
                            <tr><th>Versão</th><td><?=htmlspecialchars($versaoBancoServidor)?></td></tr>
                            <tr><th>Tabelas</th><td><?=$totalTabelasBanco?></td></tr>
                            <tr><th>Tamanho estimado</th><td><?=sgl_formatar_bytes($tamanhoBancoBytes)?></td></tr>
                            <tr><th>Charset</th><td><?=htmlspecialchars($charsetBanco)?></td></tr>
                            <tr><th>Consulta de diagnóstico</th><td><?=number_format($tempoConsultaMs,2,',','.')?> ms</td></tr>
                        </table></div>
                    </div>
                </div>
            </div>
            <div class="col-xl-6">
                <div class="card h-100 border-0 bg-light">
                    <div class="card-body">
                        <h6><i class="bi bi-cpu me-1"></i> Servidor e PHP</h6>
                        <div class="table-responsive"><table class="table table-sm mb-0">
                            <tr><th>Servidor</th><td><?=htmlspecialchars($servidorWeb)?></td></tr>
                            <tr><th>PHP</th><td><?=htmlspecialchars(PHP_VERSION)?></td></tr>
                            <tr><th>Memória atual / pico</th><td><?=sgl_formatar_bytes($memoriaAtualBytes)?> / <?=sgl_formatar_bytes($memoriaPicoBytes)?></td></tr>
                            <tr><th>Disco livre</th><td><?=sgl_formatar_bytes($espacoLivreDisco)?></td></tr>
                            <tr><th>Timezone</th><td><?=htmlspecialchars($timezonePhp)?></td></tr>
                        </table></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead class="table-dark"><tr><th>Categoria</th><th>Verificação</th><th>Resultado</th><th>Status</th><th>Recomendação</th></tr></thead>
                <tbody>
                <?php foreach ($checksSaudeConsolidada as $checkSaude): 
                    $classeSaude = $checkSaude['nivel']==='excelente'?'bg-success':($checkSaude['nivel']==='atencao'?'bg-warning text-dark':'bg-danger');
                    $rotuloSaude = $checkSaude['nivel']==='excelente'?'Excelente':($checkSaude['nivel']==='atencao'?'Atenção':'Crítico');
                ?>
                    <tr>
                        <td><?=htmlspecialchars($checkSaude['categoria'])?></td>
                        <td><strong><?=htmlspecialchars($checkSaude['item'])?></strong></td>
                        <td><?=htmlspecialchars($checkSaude['valor'])?></td>
                        <td><span class="badge <?=$classeSaude?>"><?=$rotuloSaude?></span></td>
                        <td><small><?=htmlspecialchars($checkSaude['recomendacao'])?></small></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="alert alert-secondary small mb-0">
            Este painel é somente diagnóstico. Nenhuma configuração do servidor, banco ou hospedagem é alterada automaticamente.
        </div>
    </div>
</div>
<?php endif; ?>





<?php if ($tab_ativa === 'atualizacoes'): ?>
<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-body">
                <small class="text-muted">VERSÃO INSTALADA</small>
                <h3 class="mb-1"><?=htmlspecialchars($versaoSistemaAtual)?></h3>
                <span class="badge bg-primary"><?=htmlspecialchars($ambienteSistemaAtual)?></span>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-body">
                <small class="text-muted">PHP</small>
                <h4 class="mb-1"><?=htmlspecialchars(PHP_VERSION)?></h4>
                <small class="text-muted">Ambiente atual</small>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-body">
                <small class="text-muted">BANCO</small>
                <h6 class="mb-1 text-break"><?=htmlspecialchars($versaoBancoAtual)?></h6>
                <small class="text-muted">MySQL/MariaDB</small>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-body">
                <small class="text-muted">BACKUP RECENTE</small>
                <h5 class="mb-1"><?=$backupAtualizacaoRecente['ok']?'Disponível':'Necessário'?></h5>
                <span class="badge <?=$backupAtualizacaoRecente['ok']?'bg-success':'bg-warning text-dark'?>">
                    <?=$backupAtualizacaoRecente['ok']?'Íntegro':'Atenção'?>
                </span>
            </div>
        </div>
    </div>
</div>

<div class="card shadow-sm border-0 mb-4">
    <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center">
        <span><i class="bi bi-arrow-repeat me-1"></i> Central de Atualizações Enterprise</span>
        <span class="badge bg-light text-dark">MASTER</span>
    </div>
    <div class="card-body">
        <div class="alert alert-info border-0">
            <strong>Modo seguro:</strong> esta etapa cadastra versões, mantém o changelog e simula compatibilidade.
            Nenhum arquivo do sistema é substituído automaticamente.
        </div>

        <form method="post" class="row g-3">
            <input type="hidden" name="csrf_token" value="<?=htmlspecialchars($csrf)?>">
            <input type="hidden" name="acao_cfg" value="salvar_atualizacao">
            <input type="hidden" name="atualizacao_id" id="atualizacao_id" value="0">

            <div class="col-md-3">
                <label class="form-label">Versão *</label>
                <input type="text" name="atualizacao_versao" id="atualizacao_versao" class="form-control" placeholder="Ex.: 4.1.3" required>
            </div>
            <div class="col-md-6">
                <label class="form-label">Título *</label>
                <input type="text" name="atualizacao_titulo" id="atualizacao_titulo" class="form-control" placeholder="Nome da versão" required>
            </div>
            <div class="col-md-3">
                <label class="form-label">Tipo</label>
                <select name="atualizacao_tipo" id="atualizacao_tipo" class="form-select">
                    <option value="melhoria">Melhoria</option>
                    <option value="correcao">Correção</option>
                    <option value="seguranca">Segurança</option>
                    <option value="banco">Banco de dados</option>
                    <option value="interface">Interface</option>
                    <option value="integracao">Integração</option>
                </select>
            </div>

            <div class="col-md-4">
                <label class="form-label">Status</label>
                <select name="atualizacao_status" id="atualizacao_status" class="form-select">
                    <option value="planejada">Planejada</option>
                    <option value="disponivel">Disponível</option>
                    <option value="homologacao">Homologação</option>
                    <option value="instalada">Instalada</option>
                    <option value="cancelada">Cancelada</option>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label">Impacto</label>
                <select name="atualizacao_impacto" id="atualizacao_impacto" class="form-select">
                    <option value="baixo">Baixo</option>
                    <option value="medio">Médio</option>
                    <option value="alto">Alto</option>
                    <option value="critico">Crítico</option>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label">Publicação</label>
                <input type="datetime-local" name="atualizacao_publicada_em" id="atualizacao_publicada_em" class="form-control">
            </div>

            <div class="col-md-4">
                <label class="form-label">PHP mínimo</label>
                <input type="text" name="atualizacao_php_minimo" id="atualizacao_php_minimo" class="form-control" value="8.0.0">
            </div>
            <div class="col-md-4">
                <label class="form-label">Banco mínimo</label>
                <input type="text" name="atualizacao_banco_minimo" id="atualizacao_banco_minimo" class="form-control" placeholder="Ex.: MariaDB 10.4">
            </div>
            <div class="col-md-2">
                <label class="form-label">Arquivos estimados</label>
                <input type="number" name="atualizacao_arquivos_estimados" id="atualizacao_arquivos_estimados" class="form-control" min="0" value="0">
            </div>
            <div class="col-md-2">
                <label class="form-label">Tamanho (MB)</label>
                <input type="number" step="0.01" name="atualizacao_tamanho_mb" id="atualizacao_tamanho_mb" class="form-control" min="0" value="0">
            </div>

            <div class="col-12">
                <label class="form-label">Descrição</label>
                <textarea name="atualizacao_descricao" id="atualizacao_descricao" class="form-control" rows="2"></textarea>
            </div>
            <div class="col-md-6">
                <label class="form-label">Changelog</label>
                <textarea name="atualizacao_changelog" id="atualizacao_changelog" class="form-control" rows="5" placeholder="- Nova funcionalidade&#10;- Correção realizada"></textarea>
            </div>
            <div class="col-md-6">
                <label class="form-label">Requisitos e observações</label>
                <textarea name="atualizacao_requisitos" id="atualizacao_requisitos" class="form-control" rows="5"></textarea>
            </div>

            <div class="col-md-6">
                <div class="form-check mt-2">
                    <input class="form-check-input" type="checkbox" name="atualizacao_obrigatoria" value="1" id="atualizacao_obrigatoria">
                    <label class="form-check-label" for="atualizacao_obrigatoria">
                        Atualização obrigatória
                    </label>
                </div>
            </div>
            <div class="col-md-6 d-flex gap-2 justify-content-md-end">
                <button type="button" class="btn btn-outline-secondary" onclick="rojexLimparAtualizacao()">
                    Limpar
                </button>
                <button class="btn btn-primary">
                    <i class="bi bi-save me-1"></i> Salvar atualização
                </button>
            </div>
        </form>
    </div>
</div>

<?php if (is_array($atualizacaoPreview)): ?>
<div class="card shadow-sm border-primary mb-4">
    <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
        <span><i class="bi bi-clipboard-check me-1"></i> Simulação de compatibilidade — <?=htmlspecialchars($atualizacaoPreview['versao'])?></span>
        <span class="badge bg-light text-primary"><?=$atualizacaoPreview['percentual']?>%</span>
    </div>
    <div class="card-body">
        <div class="alert <?=$atualizacaoPreview['status']==='compativel'?'alert-success':($atualizacaoPreview['status']==='atencao'?'alert-warning':'alert-danger')?>">
            <strong>Status:</strong> <?=htmlspecialchars(strtoupper($atualizacaoPreview['status']))?>.
            Esta simulação não alterou arquivos, banco de dados ou versão instalada.
        </div>

        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead>
                    <tr><th>Verificação</th><th>Atual</th><th>Requisito</th><th>Status</th><th>Recomendação</th></tr>
                </thead>
                <tbody>
                <?php foreach ($atualizacaoPreview['checks'] as $checkAtualizacao): ?>
                    <tr>
                        <td class="fw-semibold"><?=htmlspecialchars($checkAtualizacao['titulo'])?></td>
                        <td><?=htmlspecialchars((string)$checkAtualizacao['atual'])?></td>
                        <td><?=htmlspecialchars((string)$checkAtualizacao['requerido'])?></td>
                        <td>
                            <span class="badge <?=$checkAtualizacao['ok']?'bg-success':'bg-warning text-dark'?>">
                                <?=$checkAtualizacao['ok']?'Aprovado':'Atenção'?>
                            </span>
                        </td>
                        <td><small><?=htmlspecialchars($checkAtualizacao['recomendacao'])?></small></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="card shadow-sm border-0">
    <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center">
        <span><i class="bi bi-clock-history me-1"></i> Histórico e changelog</span>
        <span class="badge bg-light text-dark"><?=count($atualizacoesLista)?></span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Versão</th><th>Título</th><th>Tipo</th><th>Status</th>
                        <th>Impacto</th><th>Compatibilidade</th><th>Publicação</th><th>Ações</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$atualizacoesLista): ?>
                    <tr><td colspan="8" class="text-center text-muted py-4">Nenhuma atualização cadastrada.</td></tr>
                <?php else: foreach ($atualizacoesLista as $itemAtualizacao): ?>
                    <tr>
                        <td>
                            <strong><?=htmlspecialchars($itemAtualizacao['versao'])?></strong>
                            <?php if ((int)$itemAtualizacao['obrigatoria'] === 1): ?>
                                <span class="badge bg-danger ms-1">Obrigatória</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?=htmlspecialchars($itemAtualizacao['titulo'])?>
                            <?php if (!empty($itemAtualizacao['changelog'])): ?>
                                <details class="small mt-1">
                                    <summary class="text-primary">Ver changelog</summary>
                                    <div class="border rounded p-2 mt-1 bg-light" style="white-space:pre-wrap"><?=htmlspecialchars($itemAtualizacao['changelog'])?></div>
                                </details>
                            <?php endif; ?>
                        </td>
                        <td><span class="badge bg-secondary"><?=htmlspecialchars($itemAtualizacao['tipo'] ?: 'melhoria')?></span></td>
                        <td>
                            <?php
                            $classeStatusAtualizacao = match ((string)$itemAtualizacao['status']) {
                                'instalada' => 'bg-success',
                                'disponivel' => 'bg-primary',
                                'homologacao' => 'bg-warning text-dark',
                                'cancelada' => 'bg-danger',
                                default => 'bg-secondary',
                            };
                            ?>
                            <span class="badge <?=$classeStatusAtualizacao?>"><?=htmlspecialchars($itemAtualizacao['status'])?></span>
                        </td>
                        <td><?=htmlspecialchars($itemAtualizacao['impacto'] ?: 'baixo')?></td>
                        <td>
                            <?php
                            $compatibilidade = (string)($itemAtualizacao['compatibilidade_status'] ?? '');
                            $classeCompatibilidade = $compatibilidade === 'compativel'
                                ? 'bg-success'
                                : ($compatibilidade === 'incompativel' ? 'bg-danger' : 'bg-secondary');
                            ?>
                            <span class="badge <?=$classeCompatibilidade?>"><?=htmlspecialchars($compatibilidade ?: 'não verificada')?></span>
                        </td>
                        <td>
                            <small>
                                <?=$itemAtualizacao['publicada_em']
                                    ? htmlspecialchars(date('d/m/Y H:i', strtotime($itemAtualizacao['publicada_em'])))
                                    : '—'?>
                            </small>
                        </td>
                        <td>
                            <div class="d-flex flex-wrap gap-1">
                                <button
                                    type="button"
                                    class="btn btn-sm btn-outline-secondary"
                                    onclick='rojexEditarAtualizacao(<?=json_encode([
                                        "id" => (int)$itemAtualizacao["id"],
                                        "versao" => (string)$itemAtualizacao["versao"],
                                        "titulo" => (string)$itemAtualizacao["titulo"],
                                        "descricao" => (string)($itemAtualizacao["descricao"] ?? ""),
                                        "tipo" => (string)($itemAtualizacao["tipo"] ?? "melhoria"),
                                        "status" => (string)$itemAtualizacao["status"],
                                        "impacto" => (string)($itemAtualizacao["impacto"] ?? "baixo"),
                                        "php_minimo" => (string)($itemAtualizacao["versao_php_minima"] ?? "8.0.0"),
                                        "banco_minimo" => (string)($itemAtualizacao["versao_banco_minima"] ?? ""),
                                        "arquivos" => (int)($itemAtualizacao["arquivos_estimados"] ?? 0),
                                        "tamanho_mb" => round(((int)($itemAtualizacao["tamanho_estimado_bytes"] ?? 0)) / 1048576, 2),
                                        "publicada_em" => $itemAtualizacao["publicada_em"]
                                            ? date("Y-m-d\\TH:i", strtotime($itemAtualizacao["publicada_em"]))
                                            : "",
                                        "changelog" => (string)($itemAtualizacao["changelog"] ?? ""),
                                        "requisitos" => (string)($itemAtualizacao["requisitos"] ?? ""),
                                        "obrigatoria" => (int)$itemAtualizacao["obrigatoria"],
                                    ], JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT)?>)'>
                                    <i class="bi bi-pencil"></i>
                                </button>

                                <form method="post" class="d-inline">
                                    <input type="hidden" name="csrf_token" value="<?=htmlspecialchars($csrf)?>">
                                    <input type="hidden" name="acao_cfg" value="simular_atualizacao">
                                    <input type="hidden" name="atualizacao_id" value="<?=(int)$itemAtualizacao['id']?>">
                                    <button class="btn btn-sm btn-outline-primary" title="Simular compatibilidade">
                                        <i class="bi bi-search"></i>
                                    </button>
                                </form>

                                <form method="post" class="d-inline">
                                    <input type="hidden" name="csrf_token" value="<?=htmlspecialchars($csrf)?>">
                                    <input type="hidden" name="acao_cfg" value="alterar_status_atualizacao">
                                    <input type="hidden" name="atualizacao_id" value="<?=(int)$itemAtualizacao['id']?>">
                                    <select name="novo_status_atualizacao" class="form-select form-select-sm" onchange="this.form.submit()">
                                        <?php foreach (['planejada','disponivel','homologacao','instalada','cancelada'] as $statusOpcao): ?>
                                            <option value="<?=$statusOpcao?>" <?=$itemAtualizacao['status']===$statusOpcao?'selected':''?>><?=$statusOpcao?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
function rojexLimparAtualizacao() {
    const ids = [
        'atualizacao_id','atualizacao_versao','atualizacao_titulo','atualizacao_descricao',
        'atualizacao_changelog','atualizacao_requisitos','atualizacao_banco_minimo',
        'atualizacao_arquivos_estimados','atualizacao_tamanho_mb','atualizacao_publicada_em'
    ];
    ids.forEach((id) => {
        const el = document.getElementById(id);
        if (el) el.value = id === 'atualizacao_id' ? '0' : (id === 'atualizacao_arquivos_estimados' || id === 'atualizacao_tamanho_mb' ? '0' : '');
    });
    document.getElementById('atualizacao_php_minimo').value = '8.0.0';
    document.getElementById('atualizacao_tipo').value = 'melhoria';
    document.getElementById('atualizacao_status').value = 'planejada';
    document.getElementById('atualizacao_impacto').value = 'baixo';
    document.getElementById('atualizacao_obrigatoria').checked = false;
}

function rojexEditarAtualizacao(dados) {
    document.getElementById('atualizacao_id').value = dados.id || 0;
    document.getElementById('atualizacao_versao').value = dados.versao || '';
    document.getElementById('atualizacao_titulo').value = dados.titulo || '';
    document.getElementById('atualizacao_descricao').value = dados.descricao || '';
    document.getElementById('atualizacao_tipo').value = dados.tipo || 'melhoria';
    document.getElementById('atualizacao_status').value = dados.status || 'planejada';
    document.getElementById('atualizacao_impacto').value = dados.impacto || 'baixo';
    document.getElementById('atualizacao_php_minimo').value = dados.php_minimo || '8.0.0';
    document.getElementById('atualizacao_banco_minimo').value = dados.banco_minimo || '';
    document.getElementById('atualizacao_arquivos_estimados').value = dados.arquivos || 0;
    document.getElementById('atualizacao_tamanho_mb').value = dados.tamanho_mb || 0;
    document.getElementById('atualizacao_publicada_em').value = dados.publicada_em || '';
    document.getElementById('atualizacao_changelog').value = dados.changelog || '';
    document.getElementById('atualizacao_requisitos').value = dados.requisitos || '';
    document.getElementById('atualizacao_obrigatoria').checked = Number(dados.obrigatoria || 0) === 1;
    window.scrollTo({top: 0, behavior: 'smooth'});
}
</script>
<?php endif; ?>


<?php if ($tab_ativa === 'backup'): ?>
<div class="card shadow-sm border-0 mb-4">
    <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center flex-wrap gap-2">
        <span><i class="bi bi-cloud-arrow-up-fill me-1"></i> Estrutura de Backup Enterprise</span>
        <span class="badge bg-light text-dark">MASTER</span>
    </div>
    <div class="card-body">
        <div class="alert alert-info border-0">
            <strong>Armazenamento protegido:</strong> os arquivos são gravados em
            <code>storage/backups</code>, com bloqueio de acesso direto pelo navegador.
            A restauração automática ainda não é executada nesta etapa.
        </div>

        <?php if (!$backupZipDisponivel): ?>
        <div class="alert alert-warning">
            A extensão <strong>ZipArchive</strong> não está disponível. O backup do banco em SQL funcionará,
            mas os backups de arquivos e o backup completo dependerão da ativação da extensão ZIP no PHP.
        </div>
        <?php endif; ?>

        <form method="post" class="row g-3 align-items-end">
            <input type="hidden" name="csrf_token" value="<?=htmlspecialchars($csrf)?>">
            <input type="hidden" name="acao_cfg" value="simular_backup">

            <div class="col-md-8">
                <label class="form-label fw-semibold">Tipo de backup</label>
                <?php $backupTipoSelecionado = is_array($backupPreview) ? (string)($backupPreview['tipo'] ?? 'banco') : 'banco'; ?>
                <select name="backup_tipo" class="form-select">
                    <option value="banco" <?=$backupTipoSelecionado==='banco'?'selected':''?>>Banco de dados — arquivo SQL</option>
                    <option value="arquivos" <?=$backupTipoSelecionado==='arquivos'?'selected':''?>>Arquivos operacionais — arquivo ZIP</option>
                    <option value="completo" <?=$backupTipoSelecionado==='completo'?'selected':''?>>Completo — banco e arquivos em ZIP</option>
                </select>
                <div class="form-text">Primeiro será exibida uma simulação, sem criação de arquivos.</div>
            </div>

            <div class="col-md-4">
                <label class="form-label invisible" aria-hidden="true">Ação</label>
                <button class="btn btn-primary w-100">
                    <i class="bi bi-eye me-1"></i> Simular backup
                </button>
                <div class="form-text invisible" aria-hidden="true">Alinhamento</div>
            </div>
        </form>
    </div>
</div>

<?php if (is_array($backupPreview)): ?>
<div class="card shadow-sm border-primary mb-4">
    <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
        <span><i class="bi bi-clipboard-check me-1"></i> Prévia do backup</span>
        <span class="badge bg-light text-primary">válida por 30 minutos</span>
    </div>
    <div class="card-body">
        <div class="row g-3 mb-4">
            <div class="col-md-3">
                <div class="border rounded p-3 h-100">
                    <?php
                    $backupRotulos = [
                        'banco' => 'Banco de dados (SQL)',
                        'arquivos' => 'Arquivos operacionais (ZIP)',
                        'completo' => 'Completo (banco + arquivos)',
                    ];
                    $backupTipoPreview = (string)($backupPreview['tipo'] ?? 'banco');
                    ?>
                    <small class="text-muted">TIPO</small>
                    <h5 class="mb-0"><?=htmlspecialchars($backupRotulos[$backupTipoPreview] ?? ucfirst($backupTipoPreview))?></h5>
                </div>
            </div>
            <div class="col-md-3">
                <div class="border rounded p-3 h-100">
                    <small class="text-muted">TABELAS</small>
                    <h4 class="mb-0"><?=(int)$backupPreview['tabelas']?></h4>
                    <small><?=sgl_formatar_bytes((float)$backupPreview['estimativa_banco_bytes'])?> estimados</small>
                </div>
            </div>
            <div class="col-md-3">
                <div class="border rounded p-3 h-100">
                    <small class="text-muted">ARQUIVOS</small>
                    <h4 class="mb-0"><?=(int)$backupPreview['arquivos_quantidade']?></h4>
                    <small><?=sgl_formatar_bytes((float)$backupPreview['arquivos_bytes'])?></small>
                </div>
            </div>
            <div class="col-md-3">
                <div class="border rounded p-3 h-100">
                    <small class="text-muted">ZIP</small>
                    <h5 class="mb-0"><?=$backupPreview['zip_disponivel']?'Disponível':'Indisponível'?></h5>
                </div>
            </div>
        </div>

        <form method="post" class="row g-3 align-items-end">
            <input type="hidden" name="csrf_token" value="<?=htmlspecialchars($csrf)?>">
            <input type="hidden" name="acao_cfg" value="executar_backup">
            <input type="hidden" name="backup_hash" value="<?=htmlspecialchars((string)$backupPreview['hash'])?>">

            <div class="col-md-8">
                <label class="form-label fw-semibold">Confirmação obrigatória</label>
                <input type="text" name="confirmacao_backup" class="form-control" autocomplete="off" placeholder="Digite BACKUP">
                <div class="form-text">O backup será gerado com os parâmetros exatos desta simulação.</div>
            </div>

            <div class="col-md-4">
                <label class="form-label invisible" aria-hidden="true">Ação</label>
                <button class="btn btn-success w-100" onclick="return confirm('Confirma a criação do backup?')">
                    <i class="bi bi-cloud-arrow-up me-1"></i> Criar backup
                </button>
                <div class="form-text invisible" aria-hidden="true">Alinhamento</div>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php if (is_array($backupUltimoResultado)): ?>
<div class="alert alert-success shadow-sm">
    <h6 class="alert-heading"><i class="bi bi-check-circle-fill me-1"></i> Backup criado e verificado</h6>
    <div class="row g-2 small">
        <div class="col-md-3">Tipo: <strong><?=htmlspecialchars($backupUltimoResultado['tipo'])?></strong></div>
        <div class="col-md-3">Arquivo: <strong><?=htmlspecialchars($backupUltimoResultado['arquivo'])?></strong></div>
        <div class="col-md-3">Tamanho: <strong><?=sgl_formatar_bytes((float)$backupUltimoResultado['tamanho'])?></strong></div>
        <div class="col-md-3">Itens: <strong><?=(int)$backupUltimoResultado['quantidade']?></strong></div>
    </div>
    <div class="small mt-2"><strong>SHA-256:</strong> <code><?=htmlspecialchars($backupUltimoResultado['hash'])?></code></div>
</div>
<?php endif; ?>

<div class="row g-4">
    <div class="col-xl-4">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-header bg-dark text-white"><i class="bi bi-shield-lock me-1"></i> Segurança do backup</div>
            <div class="card-body small">
                <p><i class="bi bi-check-circle-fill text-success me-1"></i>Acesso exclusivo do MASTER.</p>
                <p><i class="bi bi-check-circle-fill text-success me-1"></i>CSRF e confirmação textual obrigatórios.</p>
                <p><i class="bi bi-check-circle-fill text-success me-1"></i>Hash SHA-256 gerado após a criação.</p>
                <p><i class="bi bi-check-circle-fill text-success me-1"></i>Arquivos armazenados fora das pastas públicas usuais.</p>
                <p class="mb-0"><i class="bi bi-check-circle-fill text-success me-1"></i>Nenhuma restauração é executada automaticamente.</p>
            </div>
        </div>
    </div>

    <div class="col-xl-8">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center">
                <span><i class="bi bi-clock-history me-1"></i> Histórico de backups</span>
                <span class="badge bg-light text-dark"><?=count($backupsRecentes)?></span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Data</th><th>Escopo</th><th>Arquivo</th><th>Tamanho</th>
                                <th>Status</th><th>Integridade</th><th>Ação</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (!$backupsRecentes): ?>
                            <tr><td colspan="7" class="text-center text-muted py-4">Nenhum backup registrado.</td></tr>
                        <?php else: foreach ($backupsRecentes as $backupItem): ?>
                            <tr>
                                <td><small><?=htmlspecialchars(date('d/m/Y H:i', strtotime($backupItem['criado_em'])))?></small></td>
                                <td><span class="badge bg-secondary"><?=htmlspecialchars($backupItem['escopo'] ?: '-')?></span></td>
                                <td>
                                    <small class="d-block text-break"><?=htmlspecialchars($backupItem['nome_original'] ?: basename((string)$backupItem['arquivo']))?></small>
                                    <small class="text-muted"><?=htmlspecialchars($backupItem['responsavel_nome'] ?: 'Sistema')?></small>
                                </td>
                                <td><?=sgl_formatar_bytes((float)($backupItem['tamanho_bytes'] ?? 0))?></td>
                                <td>
                                    <span class="badge <?=$backupItem['status']==='concluido'?'bg-success':'bg-danger'?>">
                                        <?=htmlspecialchars($backupItem['status'])?>
                                    </span>
                                </td>
                                <td>
                                    <?php
                                    $statusIntegridade = (string)($backupItem['verificacao_status'] ?? '');
                                    $classeIntegridade = $statusIntegridade === 'integro' ? 'bg-success' : ($statusIntegridade === 'ausente' || $statusIntegridade === 'divergente' ? 'bg-danger' : 'bg-secondary');
                                    ?>
                                    <span class="badge <?=$classeIntegridade?>"><?=htmlspecialchars($statusIntegridade ?: 'não verificado')?></span>
                                </td>
                                <td>
                                    <?php if (!empty($backupItem['arquivo'])): ?>
                                    <form method="post" class="d-inline">
                                        <input type="hidden" name="csrf_token" value="<?=htmlspecialchars($csrf)?>">
                                        <input type="hidden" name="acao_cfg" value="verificar_backup">
                                        <input type="hidden" name="backup_id" value="<?=(int)$backupItem['id']?>">
                                        <button class="btn btn-sm btn-outline-primary">
                                            <i class="bi bi-shield-check"></i> Verificar
                                        </button>
                                    </form>
                                    <?php else: ?>
                                    <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>


<?php if ($tab_ativa === 'manutencao'): ?>
<div class="card shadow-sm border-0 mb-4">
    <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center flex-wrap gap-2">
        <span><i class="bi bi-tools me-1"></i> Ferramentas de Manutenção Enterprise</span>
        <span class="badge bg-light text-dark">MASTER</span>
    </div>
    <div class="card-body">
        <div class="alert alert-warning border-0">
            <strong>Execução controlada:</strong> nenhuma ação é realizada durante a simulação.
            Para executar, é obrigatório revisar a prévia e digitar <code>MANUTENCAO</code>.
        </div>

        <form method="post" class="row g-3">
            <input type="hidden" name="csrf_token" value="<?=htmlspecialchars($csrf)?>">
            <input type="hidden" name="acao_cfg" value="simular_manutencao">

            <div class="col-12">
                <label class="form-label fw-semibold">Rotinas para simular</label>
                <div class="row g-3">
                    <?php foreach ([
                        'temporarios' => ['bi-file-earmark-x','Arquivos temporários','Mapeia apenas extensões temporárias em diretórios previamente autorizados.'],
                        'logs_antigos' => ['bi-clock-history','Logs antigos','Calcula eventos anteriores ao período informado, preservando no mínimo os 1.000 mais recentes.'],
                        'analisar_banco' => ['bi-search','Analisar banco','Executa ANALYZE TABLE para atualizar estatísticas do otimizador.'],
                        'otimizar_banco' => ['bi-database-gear','Otimizar banco','Executa OPTIMIZE TABLE somente após confirmação explícita.'],
                        'permissoes' => ['bi-shield-check','Verificar permissões','Confere existência e gravação das pastas operacionais sem alterá-las.'],
                    ] as $valorRotina => $dadosRotina): ?>
                    <div class="col-md-6 col-xl-4">
                        <label class="border rounded p-3 h-100 d-block bg-light">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="manutencao_acoes[]" value="<?=$valorRotina?>" id="rotina_<?=$valorRotina?>">
                                <span class="form-check-label fw-semibold">
                                    <i class="bi <?=$dadosRotina[0]?> me-1 text-primary"></i><?=$dadosRotina[1]?>
                                </span>
                            </div>
                            <div class="small text-muted mt-2"><?=$dadosRotina[2]?></div>
                        </label>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="col-md-4">
                <label class="form-label">Excluir logs anteriores a</label>
                <select name="manutencao_dias_logs" class="form-select">
                    <option value="90">90 dias</option>
                    <option value="180">180 dias</option>
                    <option value="365" selected>1 ano</option>
                    <option value="730">2 anos</option>
                    <option value="1825">5 anos</option>
                </select>
                <div class="form-text">Os 1.000 eventos mais recentes são sempre preservados.</div>
            </div>

            <div class="col-md-4">
                <label class="form-label">Idade mínima dos temporários</label>
                <select name="manutencao_idade_temporarios" class="form-select">
                    <option value="24">24 horas</option>
                    <option value="72" selected>72 horas</option>
                    <option value="168">7 dias</option>
                    <option value="720">30 dias</option>
                </select>
            </div>

            <div class="col-md-4">
                <label class="form-label invisible" aria-hidden="true">Ação</label>
                <button class="btn btn-primary w-100">
                    <i class="bi bi-eye me-1"></i> Simular manutenção
                </button>
                <div class="form-text invisible" aria-hidden="true">Alinhamento</div>
            </div>
        </form>
    </div>
</div>

<?php if (is_array($manutencaoPreview)): ?>
<div class="card shadow-sm border-primary mb-4">
    <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
        <span><i class="bi bi-clipboard-check me-1"></i> Prévia da manutenção — Dry Run</span>
        <span class="badge bg-light text-primary">válida por 30 minutos</span>
    </div>
    <div class="card-body">
        <div class="row g-3 mb-4">
            <div class="col-md-3">
                <div class="border rounded p-3 h-100">
                    <small class="text-muted">TEMPORÁRIOS</small>
                    <h4 class="mb-0"><?=(int)($manutencaoPreview['temporarios']['quantidade'] ?? 0)?></h4>
                    <small><?=sgl_formatar_bytes((float)($manutencaoPreview['temporarios']['bytes'] ?? 0))?></small>
                </div>
            </div>
            <div class="col-md-3">
                <div class="border rounded p-3 h-100">
                    <small class="text-muted">LOGS ANTIGOS</small>
                    <h4 class="mb-0"><?=number_format((int)($manutencaoPreview['logs_antigos'] ?? 0),0,',','.')?></h4>
                    <small>candidatos à limpeza</small>
                </div>
            </div>
            <div class="col-md-3">
                <div class="border rounded p-3 h-100">
                    <small class="text-muted">TABELAS</small>
                    <h4 class="mb-0"><?=count($manutencaoPreview['tabelas'] ?? [])?></h4>
                    <small>para análise/otimização</small>
                </div>
            </div>
            <div class="col-md-3">
                <div class="border rounded p-3 h-100">
                    <small class="text-muted">ROTINAS</small>
                    <h4 class="mb-0"><?=count($manutencaoPreview['acoes'] ?? [])?></h4>
                    <small><?=htmlspecialchars(implode(', ', $manutencaoPreview['acoes'] ?? []))?></small>
                </div>
            </div>
        </div>

        <?php if (!empty($manutencaoPreview['permissoes'])): ?>
        <div class="table-responsive mb-4">
            <table class="table table-sm align-middle">
                <thead><tr><th>Pasta</th><th>Existe</th><th>Gravável</th></tr></thead>
                <tbody>
                <?php foreach ($manutencaoPreview['permissoes'] as $pasta => $situacao): ?>
                    <tr>
                        <td><code><?=htmlspecialchars($pasta)?></code></td>
                        <td><?=$situacao['existe']?'<span class="badge bg-success">Sim</span>':'<span class="badge bg-danger">Não</span>'?></td>
                        <td><?=$situacao['gravavel']?'<span class="badge bg-success">Sim</span>':'<span class="badge bg-warning text-dark">Não</span>'?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <form method="post" class="row g-3 align-items-end">
            <input type="hidden" name="csrf_token" value="<?=htmlspecialchars($csrf)?>">
            <input type="hidden" name="acao_cfg" value="executar_manutencao">
            <input type="hidden" name="manutencao_hash" value="<?=htmlspecialchars((string)$manutencaoPreview['hash'])?>">
            <div class="col-md-8">
                <label class="form-label fw-semibold">Confirmação obrigatória</label>
                <input type="text" name="confirmacao_manutencao" class="form-control" autocomplete="off" placeholder="Digite MANUTENCAO">
                <div class="form-text">A execução utilizará exatamente os parâmetros desta simulação.</div>
            </div>
            <div class="col-md-4">
                <label class="form-label invisible" aria-hidden="true">Ação</label>
                <button class="btn btn-danger w-100" onclick="return confirm('Confirma a execução das rotinas simuladas?')">
                    <i class="bi bi-play-circle me-1"></i> Executar manutenção
                </button>
                <div class="form-text invisible" aria-hidden="true">Alinhamento</div>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php if (is_array($manutencaoUltimoResultado)): ?>
<div class="alert <?=$manutencaoUltimoResultado['falhas']?'alert-warning':'alert-success'?> shadow-sm">
    <h6 class="alert-heading"><i class="bi bi-check-circle me-1"></i> Resultado da última execução</h6>
    <div class="row g-2 small">
        <div class="col-md-3">Temporários: <strong><?=(int)$manutencaoUltimoResultado['temporarios_excluidos']?></strong></div>
        <div class="col-md-3">Espaço: <strong><?=sgl_formatar_bytes((float)$manutencaoUltimoResultado['temporarios_bytes'])?></strong></div>
        <div class="col-md-3">Logs: <strong><?=(int)$manutencaoUltimoResultado['logs_excluidos']?></strong></div>
        <div class="col-md-3">Tabelas: <strong><?=(int)$manutencaoUltimoResultado['tabelas_analisadas']?> analisadas / <?=(int)$manutencaoUltimoResultado['tabelas_otimizadas']?> otimizadas</strong></div>
    </div>
    <?php if (!empty($manutencaoUltimoResultado['falhas'])): ?>
        <hr><ul class="mb-0 small"><?php foreach (array_slice($manutencaoUltimoResultado['falhas'],0,10) as $falha): ?><li><?=htmlspecialchars($falha)?></li><?php endforeach; ?></ul>
    <?php endif; ?>
</div>
<?php endif; ?>

<div class="row g-4">
    <div class="col-xl-5">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-header bg-dark text-white"><i class="bi bi-shield-lock me-1"></i> Escopo de segurança</div>
            <div class="card-body small">
                <p><i class="bi bi-check-circle-fill text-success me-1"></i>Somente o MASTER pode simular ou executar.</p>
                <p><i class="bi bi-check-circle-fill text-success me-1"></i>CSRF e confirmação textual obrigatórios.</p>
                <p><i class="bi bi-check-circle-fill text-success me-1"></i>Temporários limitados a diretórios e extensões autorizados.</p>
                <p><i class="bi bi-check-circle-fill text-success me-1"></i>Sessões PHP, documentos, uploads jurídicos e backups não são removidos.</p>
                <p class="mb-0"><i class="bi bi-check-circle-fill text-success me-1"></i>Todas as execuções são registradas no LOG e no histórico de manutenção.</p>
            </div>
        </div>
    </div>

    <div class="col-xl-7">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center">
                <span><i class="bi bi-clock-history me-1"></i> Histórico recente</span>
                <span class="badge bg-light text-dark"><?=count($manutencoesRecentes)?></span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light"><tr><th>Data</th><th>Tipo</th><th>Modo</th><th>Status</th><th>Responsável</th></tr></thead>
                        <tbody>
                        <?php if (!$manutencoesRecentes): ?>
                            <tr><td colspan="5" class="text-center text-muted py-4">Nenhuma manutenção registrada.</td></tr>
                        <?php else: foreach ($manutencoesRecentes as $historicoManutencao): ?>
                            <tr>
                                <td><small><?=htmlspecialchars(date('d/m/Y H:i', strtotime($historicoManutencao['criado_em'])))?></small></td>
                                <td><?=htmlspecialchars($historicoManutencao['tipo'])?></td>
                                <td><span class="badge bg-secondary"><?=htmlspecialchars($historicoManutencao['modo'])?></span></td>
                                <td><span class="badge <?=$historicoManutencao['status']==='concluida'?'bg-success':'bg-warning text-dark'?>"><?=htmlspecialchars($historicoManutencao['status'])?></span></td>
                                <td><?=htmlspecialchars($historicoManutencao['executado_por_nome'] ?: 'Sistema')?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>


<?php if ($tab_ativa === 'relatorios'): ?>
<div class="card shadow-sm border-0 mb-4">
    <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center">
        <span><i class="bi bi-file-earmark-bar-graph-fill me-1"></i> Relatórios Administrativos Enterprise</span>
        <span class="badge bg-primary">Acesso MASTER</span>
    </div>
    <div class="card-body">
        <div class="alert alert-info border-0">
            Gere relatórios consolidados da operação SaaS. A exportação em PDF abre a tela de impressão do navegador; selecione <strong>Salvar como PDF</strong>. O Excel é baixado em formato compatível com o Microsoft Excel.
        </div>

        <form method="GET" class="row g-3 align-items-end mb-4">
            <input type="hidden" name="mod" value="configuracoes">
            <input type="hidden" name="tab" value="relatorios">
            <div class="col-md-4">
                <label class="form-label">Relatório</label>
                <select name="relatorio_tipo" class="form-select">
                    <option value="resumo" <?=$relatorioTipo==='resumo'?'selected':''?>>Resumo consolidado</option>
                    <option value="escritorios" <?=$relatorioTipo==='escritorios'?'selected':''?>>Escritórios SaaS</option>
                    <option value="licencas" <?=$relatorioTipo==='licencas'?'selected':''?>>Licenças SaaS</option>
                    <option value="usuarios" <?=$relatorioTipo==='usuarios'?'selected':''?>>Usuários</option>
                    <option value="desligados" <?=$relatorioTipo==='desligados'?'selected':''?>>Usuários desligados</option>
                    <option value="saude" <?=$relatorioTipo==='saude'?'selected':''?>>Saúde do sistema</option>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Data inicial</label>
                <input type="date" name="relatorio_data_inicio" class="form-control" value="<?=htmlspecialchars($relatorioDataInicio)?>">
            </div>
            <div class="col-md-3">
                <label class="form-label">Data final</label>
                <input type="date" name="relatorio_data_fim" class="form-control" value="<?=htmlspecialchars($relatorioDataFim)?>">
            </div>
            <div class="col-md-2 d-grid">
                <button class="btn btn-outline-primary"><i class="bi bi-funnel me-1"></i> Aplicar</button>
            </div>
        </form>

        <div class="row g-3 mb-4">
            <div class="col-md-3"><div class="card border-0 bg-light h-100"><div class="card-body"><small class="text-muted">ESCRITÓRIOS</small><h3 class="mb-0"><?=count($escritoriosSaas)?></h3></div></div></div>
            <div class="col-md-3"><div class="card border-0 bg-light h-100"><div class="card-body"><small class="text-muted">LICENÇAS</small><h3 class="mb-0"><?=count($licencasSaas)?></h3></div></div></div>
            <div class="col-md-3"><div class="card border-0 bg-light h-100"><div class="card-body"><small class="text-muted">USUÁRIOS ATIVOS</small><h3 class="mb-0"><?=$totalAtivos?></h3></div></div></div>
            <div class="col-md-3"><div class="card border-0 bg-light h-100"><div class="card-body"><small class="text-muted">SAÚDE</small><h3 class="mb-0 <?=$percentualSaude>=85?'text-success':'text-warning'?>"><?=$percentualSaude?>%</h3></div></div></div>
        </div>

        <?php
        $queryRelatorio = http_build_query([
            'mod' => 'configuracoes',
            'tab' => 'relatorios',
            'relatorio_tipo' => $relatorioTipo,
            'relatorio_data_inicio' => $relatorioDataInicio,
            'relatorio_data_fim' => $relatorioDataFim,
        ]);
        ?>
        <div class="d-flex flex-wrap gap-2 mb-4">
            <button type="button" class="btn btn-danger" onclick="rojexGerarPdfRelatorio()">
                <i class="bi bi-file-earmark-pdf-fill me-1"></i> Gerar PDF
            </button>
            <button type="button" class="btn btn-success" onclick="rojexExportarExcelRelatorio()">
                <i class="bi bi-file-earmark-excel-fill me-1"></i> Exportar Excel
            </button>
            <a href="?mod=configuracoes&tab=relatorios" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-counterclockwise me-1"></i> Limpar filtros
            </a>
        </div>



        <div id="rojex-relatorio-exportacao" class="d-none">
            <div class="cabecalho-relatorio">
                <h1>ROJEX.AI — <?=htmlspecialchars($tituloRelatorio, ENT_QUOTES, 'UTF-8')?></h1>
                <div class="meta"><?=htmlspecialchars($periodoDescricao, ENT_QUOTES, 'UTF-8')?> | Emitido em <?=date('d/m/Y H:i')?></div>
            </div>
            <?=rojex_relatorio_html_tabela($cabecalhosRelatorio, $linhasRelatorio)?>
            <div class="rodape-relatorio">Relatório administrativo emitido pelo ROJEX.AI ERP Jurídico Enterprise.</div>
        </div>

        <script>
        function rojexHtmlRelatorioCompleto() {
            const origem = document.getElementById('rojex-relatorio-exportacao');
            if (!origem) return '';
            return `<!doctype html><html lang="pt-BR"><head><meta charset="UTF-8">
                <title><?=htmlspecialchars($tituloRelatorio, ENT_QUOTES, 'UTF-8')?></title>
                <style>
                    @page { size: A4 landscape; margin: 10mm; }
                    * { box-sizing: border-box; }
                    body { margin: 0; font-family: Arial, sans-serif; color: #222; font-size: 10px; background: #fff; }
                    h1 { color: #1a3c5e; font-size: 22px; margin: 0 0 5px; }
                    .meta { margin-bottom: 14px; color: #555; }
                    table { width: 100%; border-collapse: collapse; table-layout: auto; }
                    th, td { border: 1px solid #aaa; padding: 5px; text-align: left; vertical-align: top; overflow-wrap: anywhere; }
                    th { background: #1a3c5e; color: #fff; }
                    tr { break-inside: avoid; page-break-inside: avoid; }
                    .rodape-relatorio { margin-top: 12px; font-size: 8px; color: #666; }
                </style></head><body>${origem.innerHTML}</body></html>`;
        }

        function rojexGerarPdfRelatorio() {
            const html = rojexHtmlRelatorioCompleto();
            if (!html) return;
            const janela = window.open('', '_blank', 'width=1200,height=850');
            if (!janela) {
                alert('Permita a abertura de pop-ups para gerar o PDF.');
                return;
            }
            janela.document.open();
            janela.document.write(html);
            janela.document.close();
            janela.focus();
            setTimeout(function () { janela.print(); }, 500);
        }

        function rojexExportarExcelRelatorio() {
            const html = rojexHtmlRelatorioCompleto();
            if (!html) return;
            const blob = new Blob(['\ufeff' + html], {type: 'application/vnd.ms-excel;charset=utf-8;'});
            const url = URL.createObjectURL(blob);
            const link = document.createElement('a');
            link.href = url;
            link.download = '<?=htmlspecialchars($nomeArquivoRelatorio, ENT_QUOTES, 'UTF-8')?>.xls';
            document.body.appendChild(link);
            link.click();
            link.remove();
            setTimeout(function () { URL.revokeObjectURL(url); }, 1000);
        }
        </script>

        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle">
                <thead class="table-dark">
                    <tr><th>Relatório disponível</th><th>Conteúdo</th><th>Origem</th></tr>
                </thead>
                <tbody>
                    <tr><td>Resumo consolidado</td><td>KPIs administrativos e situação geral</td><td>Configurações, SaaS, usuários e saúde</td></tr>
                    <tr><td>Escritórios SaaS</td><td>Tenant, plano, status, responsável e licenças</td><td>escritorios_saas</td></tr>
                    <tr><td>Licenças SaaS</td><td>Chave, vínculo, limites, status e renovação</td><td>licencas_saas</td></tr>
                    <tr><td>Usuários</td><td>Cadastro, perfil, status e último acesso</td><td>usuarios</td></tr>
                    <tr><td>Usuários desligados</td><td>Desligamento, responsável, data e auditoria</td><td>usuarios + usuarios_historico</td></tr>
                    <tr><td>Saúde do sistema</td><td>PHP, banco, HTTPS, disco e controles de segurança</td><td>Diagnóstico local</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>


<?php if ($tab_ativa === 'desligados'): ?>
<div class="card shadow-sm border-0 mb-4">
    <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center">
        <span><i class="bi bi-person-x-fill me-1"></i> Histórico de Usuários Desligados</span>
        <span><?=count($usuariosDesligados)?> desligado(s) localizado(s)</span>
    </div>
    <div class="card-body">
        <div class="alert alert-info border-0 small">
            Os cadastros permanecem preservados para auditoria e eventual comprovação futura. Senhas nunca são armazenadas no histórico.
        </div>
        <form method="get" class="row g-3 align-items-end mb-4">
            <input type="hidden" name="mod" value="configuracoes"><input type="hidden" name="tab" value="desligados">
            <div class="col-lg-4"><label class="form-label">Pesquisar</label><input type="search" name="desligado_q" class="form-control" value="<?=htmlspecialchars($desligadoBusca)?>" placeholder="Nome, login, e-mail, perfil ou responsável"></div>
            <div class="col-lg-2"><label class="form-label">Data inicial</label><input type="date" name="desligado_data_inicio" class="form-control" value="<?=htmlspecialchars($desligadoDataInicio)?>"></div>
            <div class="col-lg-2"><label class="form-label">Data final</label><input type="date" name="desligado_data_fim" class="form-control" value="<?=htmlspecialchars($desligadoDataFim)?>"></div>
            <div class="col-lg-2"><label class="form-label">Evento</label><select name="desligado_acao" class="form-select"><option value="">Todos</option><option value="ENCERRAMENTO_DE_VINCULO" <?=$desligadoAcao==='ENCERRAMENTO_DE_VINCULO'?'selected':''?>>Encerramento</option></select></div>
            <div class="col-lg-2 d-flex gap-2"><button class="btn btn-outline-primary flex-grow-1"><i class="bi bi-search"></i> Filtrar</button><a href="?mod=configuracoes&tab=desligados" class="btn btn-outline-secondary">Limpar</a></div>
        </form>

        <h6 class="mb-3">Cadastros atualmente desligados</h6>
        <div class="table-responsive mb-4"><table class="table table-hover align-middle">
            <thead class="table-light"><tr><th>Usuário</th><th>Perfil / Departamento</th><th>Desligamento</th><th>Responsável</th><th>Último acesso</th><th>Status</th></tr></thead>
            <tbody>
            <?php if(!$usuariosDesligados): ?><tr><td colspan="6" class="text-center text-muted py-4">Nenhum usuário desligado encontrado.</td></tr>
            <?php else: foreach($usuariosDesligados as $u): ?>
            <tr>
                <td><strong><?=htmlspecialchars((string)$u['nome'])?></strong><br><small class="text-muted"><?=htmlspecialchars((string)$u['usuario'])?> · <?=htmlspecialchars((string)($u['email'] ?: '-'))?></small></td>
                <td><?=htmlspecialchars((string)$u['perfil'])?><br><small class="text-muted"><?=htmlspecialchars((string)($u['departamento'] ?? '-'))?><?=!empty($u['cargo'])?' · '.htmlspecialchars((string)$u['cargo']):''?></small></td>
                <td><?=!empty($u['desligado_em'])?date('d/m/Y H:i',strtotime($u['desligado_em'])):'-'?></td>
                <td><?=htmlspecialchars((string)($u['desligado_por_nome'] ?? 'Não identificado'))?></td>
                <td><?=!empty($u['ultimo_login'])?date('d/m/Y H:i',strtotime($u['ultimo_login'])):'-'?></td>
                <td><span class="badge bg-secondary"><?=htmlspecialchars((string)($u['vinculo_status'] ?? 'inativo'))?></span></td>
            </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table></div>

        <h6 class="mb-3">Snapshots de auditoria</h6>
        <div class="accordion" id="accordionHistoricoDesligados">
        <?php if(!$historicoUsuariosDesligados): ?><div class="text-center text-muted py-4">Nenhum snapshot histórico encontrado.</div>
        <?php else: foreach($historicoUsuariosDesligados as $h): $snap=$h['snapshot']; ?>
            <div class="accordion-item">
                <h2 class="accordion-header"><button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#hist<?=$h['id']?>">
                    <span class="me-3"><strong><?=htmlspecialchars((string)($snap['nome'] ?? 'Usuário #'.$h['usuario_id']))?></strong></span>
                    <span class="badge bg-secondary me-3"><?=htmlspecialchars((string)$h['acao'])?></span>
                    <small class="text-muted"><?=date('d/m/Y H:i',strtotime($h['criado_em']))?> · por <?=htmlspecialchars((string)($h['realizado_por_nome'] ?? 'Sistema'))?></small>
                </button></h2>
                <div id="hist<?=$h['id']?>" class="accordion-collapse collapse" data-bs-parent="#accordionHistoricoDesligados"><div class="accordion-body">
                    <div class="row g-3 small">
                        <?php foreach(['usuario'=>'Login','email'=>'E-mail','perfil'=>'Perfil','telefone'=>'Telefone','cargo'=>'Cargo','departamento'=>'Departamento','vinculo_status'=>'Vínculo','desligado_em'=>'Desligado em'] as $k=>$rot): ?>
                        <div class="col-md-3"><span class="text-muted"><?=$rot?></span><br><strong><?=htmlspecialchars((string)($snap[$k] ?? '-'))?></strong></div>
                        <?php endforeach; ?>
                        <div class="col-12"><span class="text-muted">Observações preservadas</span><br><?=nl2br(htmlspecialchars((string)($snap['observacoes'] ?? '-')))?></div>
                        <div class="col-12 text-muted">IP do evento: <?=htmlspecialchars((string)($h['ip'] ?? '-'))?> · ID original: <?=(int)$h['usuario_id']?></div>
                    </div>
                </div></div>
            </div>
        <?php endforeach; endif; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($tab_ativa === 'lixeira'): ?>
<div class="card shadow-sm border-0">
    <div class="card-header bg-danger text-white d-flex justify-content-between align-items-center">
        <span><i class="bi bi-trash3 me-1"></i> Lixeira Enterprise</span>
        <span><?= (int)$totalLixeira ?> item(ns) no total</span>
    </div>
    <div class="card-body">
        <div class="alert alert-warning border-0 shadow-sm small">
            <strong>Atenção:</strong> restaurar devolve o registro ao módulo de origem. A exclusão permanente não pode ser desfeita e pode falhar quando o registro possuir vínculos obrigatórios.
        </div>

        <form method="GET" class="row g-3 align-items-end mb-4">
            <input type="hidden" name="mod" value="configuracoes">
            <input type="hidden" name="tab" value="lixeira">
            <div class="col-md-5">
                <label class="form-label">Pesquisar na lixeira</label>
                <input type="search" name="lixeira_q" class="form-control" value="<?=htmlspecialchars($lixeira_busca)?>" placeholder="Descrição, módulo ou ID">
            </div>
            <div class="col-md-3">
                <label class="form-label">Módulo</label>
                <select name="lixeira_modulo" class="form-select">
                    <option value="">Todos os módulos</option>
                    <?php foreach ($lixeira_modulos as $tabela => $tipo): ?>
                        <option value="<?=htmlspecialchars($tabela)?>" <?=$lixeira_modulo===$tabela?'selected':''?>><?=htmlspecialchars($tipo)?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Por página</label>
                <select name="lixeira_por_pagina" class="form-select">
                    <?php foreach ([10,25,50,100] as $quantidade): ?>
                        <option value="<?=$quantidade?>" <?=$lixeira_por_pagina===$quantidade?'selected':''?>><?=$quantidade?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2 d-flex gap-2">
                <button class="btn btn-primary flex-fill"><i class="bi bi-search"></i> Filtrar</button>
                <a href="?mod=configuracoes&tab=lixeira" class="btn btn-outline-secondary" title="Limpar filtros"><i class="bi bi-x-lg"></i></a>
            </div>
        </form>

        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
            <div class="text-muted small">Exibindo <?=count($lixeira_itens)?> de <?=$lixeira_total_filtrado?> item(ns) encontrado(s).</div>
            <?php if ($totalLixeira > 0): ?>
            <button type="button" class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#modalEsvaziarLixeira">
                <i class="bi bi-trash3-fill me-1"></i>Esvaziar lixeira
            </button>
            <?php endif; ?>
        </div>

        <?php if(empty($lixeira_itens)): ?>
            <div class="text-center py-5 text-muted">
                <i class="bi bi-trash3 fs-1 d-block mb-3 opacity-25"></i>
                <p class="mb-0"><?= $totalLixeira > 0 ? 'Nenhum item corresponde aos filtros aplicados.' : 'A lixeira está vazia.' ?></p>
            </div>
        <?php else: ?>
        <form method="POST" id="formLixeiraLote">
            <input type="hidden" name="csrf_token" value="<?=htmlspecialchars($csrf)?>">
            <input type="hidden" name="acao_cfg" id="acaoCfgLixeira" value="acao_lixeira_lote">
            <input type="hidden" name="tabela" id="tabelaIndividual">
            <input type="hidden" name="item_id" id="itemIdIndividual">

            <div class="d-flex gap-2 mb-3 flex-wrap">
                <select name="acao_lote" class="form-select form-select-sm" style="max-width:230px">
                    <option value="">Ação com selecionados</option>
                    <option value="restaurar">Restaurar selecionados</option>
                    <option value="excluir">Excluir permanentemente</option>
                </select>
                <button type="submit" class="btn btn-sm btn-primary" onclick="document.getElementById('acaoCfgLixeira').value='acao_lixeira_lote';return confirmarAcaoLote();">
                    <i class="bi bi-check2-square me-1"></i>Aplicar
                </button>
            </div>

            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead class="table-light">
                        <tr>
                            <th style="width:42px"><input type="checkbox" class="form-check-input" id="marcarTodosLixeira"></th>
                            <th>ID</th><th>Módulo</th><th>Descrição</th><th>Excluído por</th><th>Data da exclusão</th><th class="text-end">Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach($lixeira_itens as $item): ?>
                        <tr>
                            <td><input type="checkbox" class="form-check-input item-lixeira-check" name="itens[]" value="<?=htmlspecialchars($item['tabela'].'|'.$item['id'])?>"></td>
                            <td><code><?=htmlspecialchars($item['id'])?></code></td>
                            <td><span class="badge bg-secondary"><?=htmlspecialchars($item['tipo'])?></span></td>
                            <td><strong><?=htmlspecialchars($item['nome'])?></strong></td>
                            <td><?=htmlspecialchars($item['excluido_por'] ?? 'Não identificado')?></td>
                            <td><?=!empty($item['excluido_em']) ? date('d/m/Y H:i', strtotime($item['excluido_em'])) : '-'?></td>
                            <td class="text-end">
                                <button type="submit" class="btn btn-sm btn-success"
                                    onclick="return prepararAcaoIndividual('restaurar_item_lixeira','<?=htmlspecialchars($item['tabela'], ENT_QUOTES)?>','<?=htmlspecialchars($item['id'], ENT_QUOTES)?>');">
                                    <i class="bi bi-arrow-counterclockwise"></i> Restaurar
                                </button>
                                <button type="submit" class="btn btn-sm btn-outline-danger"
                                    onclick="return prepararAcaoIndividual('excluir_item_lixeira','<?=htmlspecialchars($item['tabela'], ENT_QUOTES)?>','<?=htmlspecialchars($item['id'], ENT_QUOTES)?>');">
                                    <i class="bi bi-trash3"></i> Excluir
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </form>

        <?php if ($lixeira_total_paginas > 1): ?>
        <nav aria-label="Paginação da lixeira">
            <ul class="pagination pagination-sm justify-content-center mb-0">
                <?php $baseLixeira = ['mod'=>'configuracoes','tab'=>'lixeira','lixeira_q'=>$lixeira_busca,'lixeira_modulo'=>$lixeira_modulo,'lixeira_por_pagina'=>$lixeira_por_pagina]; ?>
                <li class="page-item <?=$lixeira_pagina<=1?'disabled':''?>">
                    <a class="page-link" href="?<?=http_build_query(array_merge($baseLixeira, ['lixeira_pagina'=>max(1,$lixeira_pagina-1)]))?>">Anterior</a>
                </li>
                <?php for ($pag=max(1,$lixeira_pagina-2); $pag<=min($lixeira_total_paginas,$lixeira_pagina+2); $pag++): ?>
                    <li class="page-item <?=$pag===$lixeira_pagina?'active':''?>">
                        <a class="page-link" href="?<?=http_build_query(array_merge($baseLixeira, ['lixeira_pagina'=>$pag]))?>"><?=$pag?></a>
                    </li>
                <?php endfor; ?>
                <li class="page-item <?=$lixeira_pagina>=$lixeira_total_paginas?'disabled':''?>">
                    <a class="page-link" href="?<?=http_build_query(array_merge($baseLixeira, ['lixeira_pagina'=>min($lixeira_total_paginas,$lixeira_pagina+1)]))?>">Próxima</a>
                </li>
            </ul>
        </nav>
        <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<div class="modal fade" id="modalEsvaziarLixeira" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?=htmlspecialchars($csrf)?>">
                <input type="hidden" name="acao_cfg" value="esvaziar_lixeira">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title"><i class="bi bi-exclamation-triangle me-1"></i>Esvaziar lixeira</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Esta ação tentará excluir permanentemente todos os <strong><?= (int)$totalLixeira ?></strong> registros da lixeira.</p>
                    <p class="text-danger fw-semibold">A operação não poderá ser desfeita.</p>
                    <label class="form-label">Digite <code>ESVAZIAR</code> para confirmar</label>
                    <input type="text" name="confirmacao" class="form-control" autocomplete="off" required pattern="ESVAZIAR">
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button class="btn btn-danger"><i class="bi bi-trash3-fill me-1"></i>Excluir tudo permanentemente</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($tab_ativa === 'logs'): ?>
<div class="card shadow-sm border-primary mb-4 d-print-none">
    <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
        <span><i class="bi bi-file-earmark-zip-fill me-1"></i> Backup e Arquivamento do LOG por Escritório</span>
        <span class="badge bg-light text-primary">Sprint 4.6.5</span>
    </div>
    <div class="card-body">
        <div class="alert alert-info">
            <strong>Fluxo obrigatório:</strong> gerar ZIP → verificar integridade → baixar → digitar
            <code>ARQUIVAR</code>. A exclusão usa somente os IDs gravados no backup e sempre repete
            o filtro por <code>tenant_id</code> e <code>escritorio_id</code>.
        </div>
        <form method="POST" class="row g-3 align-items-end mb-4">
            <input type="hidden" name="csrf_token" value="<?=htmlspecialchars($csrf)?>">
            <input type="hidden" name="acao_cfg" value="gerar_log_backup">
            <div class="col-lg-6">
                <label class="form-label fw-semibold">Escritório (seleção única obrigatória)</label>
                <select name="log_backup_escritorio_id" class="form-select" required>
                    <option value="">Selecione um escritório</option>
                    <?php foreach ($logBackupEscritorios as $escritorioBackupOpcao): ?>
                        <option value="<?=(int)$escritorioBackupOpcao['id']?>">
                            <?=htmlspecialchars((string)$escritorioBackupOpcao['nome'])?>
                            — <?=htmlspecialchars((string)$escritorioBackupOpcao['tenant_id'])?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-lg-2 col-md-4">
                <label class="form-label fw-semibold">Período inicial</label>
                <input type="date" name="log_backup_data_inicio" class="form-control" required>
            </div>
            <div class="col-lg-2 col-md-4">
                <label class="form-label fw-semibold">Período final</label>
                <input type="date" name="log_backup_data_fim" class="form-control" required>
            </div>
            <div class="col-lg-2 col-md-4">
                <button class="btn btn-primary w-100" onclick="return confirm('Gerar ZIP isolado para o escritório e período selecionados?')">
                    <i class="bi bi-file-earmark-zip me-1"></i> Gerar ZIP
                </button>
            </div>
        </form>

        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>ID / Escritório</th>
                        <th>Período</th>
                        <th>Registros</th>
                        <th>SHA-256</th>
                        <th>Status</th>
                        <th>Controles obrigatórios</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$logBackupsRecentes): ?>
                    <tr><td colspan="6" class="text-center text-muted py-4">Nenhum backup isolado do LOG foi gerado.</td></tr>
                <?php endif; ?>
                <?php foreach ($logBackupsRecentes as $logBackupItem): ?>
                    <?php
                    $logBackupVerificado = !empty($logBackupItem['verificado_em']);
                    $logBackupBaixado = !empty($logBackupItem['download_em']);
                    $logBackupArquivado = !empty($logBackupItem['arquivado_em'])
                        || (string)$logBackupItem['status'] === 'ARQUIVADO';
                    ?>
                    <tr>
                        <td>
                            <strong>#<?=(int)$logBackupItem['id']?> — <?=htmlspecialchars((string)$logBackupItem['escritorio_nome'])?></strong>
                            <br><code><?=htmlspecialchars((string)$logBackupItem['tenant_id'])?></code>
                            <small class="text-muted"> / <?=(int)$logBackupItem['escritorio_id']?></small>
                        </td>
                        <td>
                            <small>
                                <?=!empty($logBackupItem['periodo_inicio']) ? date('d/m/Y', strtotime($logBackupItem['periodo_inicio'])) : '-'?>
                                a
                                <?=!empty($logBackupItem['periodo_fim']) ? date('d/m/Y', strtotime($logBackupItem['periodo_fim'])) : '-'?>
                            </small>
                        </td>
                        <td>
                            <span class="badge bg-secondary"><?=(int)$logBackupItem['total_registros']?></span>
                            <br><small><?=sgl_formatar_bytes((float)$logBackupItem['tamanho_bytes'])?></small>
                        </td>
                        <td><code class="small text-break"><?=htmlspecialchars((string)$logBackupItem['sha256'])?></code></td>
                        <td>
                            <span class="badge <?=$logBackupArquivado?'bg-dark':($logBackupBaixado?'bg-success':($logBackupVerificado?'bg-info text-dark':'bg-warning text-dark'))?>">
                                <?=htmlspecialchars((string)$logBackupItem['status'])?>
                            </span>
                            <div class="small mt-1">
                                <?=$logBackupVerificado?'✓ Verificado':'○ Verificar'?><br>
                                <?=$logBackupBaixado?'✓ Download iniciado':'○ Baixar'?><br>
                                <?=$logBackupArquivado?'✓ Arquivado':'○ Arquivar'?>
                            </div>
                        </td>
                        <td style="min-width:260px">
                            <?php if (!$logBackupArquivado): ?>
                                <div class="d-flex gap-2 flex-wrap mb-2">
                                    <form method="POST">
                                        <input type="hidden" name="csrf_token" value="<?=htmlspecialchars($csrf)?>">
                                        <input type="hidden" name="acao_cfg" value="verificar_log_backup">
                                        <input type="hidden" name="backup_id" value="<?=(int)$logBackupItem['id']?>">
                                        <button class="btn btn-sm btn-outline-primary"><i class="bi bi-shield-check me-1"></i>Verificar</button>
                                    </form>
                                    <?php if ($logBackupVerificado): ?>
                                        <a class="btn btn-sm btn-success"
                                           href="?mod=configuracoes&tab=logs&acao_cfg=baixar_log_backup&backup_id=<?=(int)$logBackupItem['id']?>">
                                            <i class="bi bi-download me-1"></i>Baixar ZIP
                                        </a>
                                    <?php endif; ?>
                                </div>
                                <?php if ($logBackupVerificado && $logBackupBaixado): ?>
                                    <form method="POST" class="input-group input-group-sm"
                                          onsubmit="return confirm('Confirma o arquivamento? Somente os IDs deste backup serão removidos do LOG ativo.')">
                                        <input type="hidden" name="csrf_token" value="<?=htmlspecialchars($csrf)?>">
                                        <input type="hidden" name="acao_cfg" value="arquivar_log_backup">
                                        <input type="hidden" name="backup_id" value="<?=(int)$logBackupItem['id']?>">
                                        <input type="text" name="confirmacao_arquivar" class="form-control"
                                               placeholder="Digite ARQUIVAR" required pattern="ARQUIVAR" autocomplete="off">
                                        <button class="btn btn-danger"><i class="bi bi-archive me-1"></i>Arquivar</button>
                                    </form>
                                <?php else: ?>
                                    <small class="text-muted">O arquivamento será liberado após verificação e download.</small>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="text-success"><i class="bi bi-check-circle-fill me-1"></i>Concluído</span>
                                <br><small class="text-muted">ZIP temporário removido do servidor.</small>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="card shadow-sm border-0 mb-4 d-print-none">
    <div class="card-header bg-dark text-white"><i class="bi bi-funnel me-1"></i> Filtros e Relatório do LOG</div>
    <div class="card-body">
        <form method="GET" class="row g-3 align-items-end">
            <input type="hidden" name="mod" value="configuracoes">
            <input type="hidden" name="tab" value="logs">

            <div class="col-md-2">
                <label class="form-label">Data inicial</label>
                <input type="date" name="log_data_inicio" class="form-control" value="<?=htmlspecialchars($logDataInicio)?>">
            </div>
            <div class="col-md-2">
                <label class="form-label">Data final</label>
                <input type="date" name="log_data_fim" class="form-control" value="<?=htmlspecialchars($logDataFim)?>">
            </div>
            <div class="col-md-3">
                <label class="form-label">Usuário</label>
                <select name="log_usuario" class="form-select">
                    <option value="0">Todos os usuários</option>
                    <?php foreach ($usuarios as $usuarioFiltro): ?>
                        <option value="<?=(int)$usuarioFiltro['id']?>" <?=$logUsuario===(int)$usuarioFiltro['id']?'selected':''?>>
                            <?=htmlspecialchars($usuarioFiltro['nome'] . ' (' . $usuarioFiltro['usuario'] . ')')?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Módulo</label>
                <select name="log_modulo" class="form-select">
                    <option value="">Todos os módulos</option>
                    <?php foreach ($logModulosDisponiveis as $moduloFiltro): ?>
                        <option value="<?=htmlspecialchars($moduloFiltro)?>" <?=$logModulo===$moduloFiltro?'selected':''?>>
                            <?=htmlspecialchars($moduloFiltro)?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3 d-flex gap-2">
                <button class="btn btn-primary flex-fill"><i class="bi bi-search me-1"></i>Filtrar</button>
                <a href="?mod=configuracoes&tab=logs" class="btn btn-outline-secondary" title="Limpar filtros"><i class="bi bi-x-lg"></i></a>
                <button type="button" class="btn btn-danger" onclick="gerarPdfLogs()" title="Abrir impressão para salvar em PDF">
                    <i class="bi bi-file-earmark-pdf me-1"></i>Gerar PDF
                </button>
            </div>
        </form>

        <div class="form-text mt-3">
            Sem datas informadas, o relatório inclui o LOG desde o primeiro registro. O botão PDF abre a impressão do navegador, permitindo selecionar <strong>Salvar como PDF</strong>.
        </div>
    </div>
</div>

<div class="row g-4 d-print-none">
    <div class="col-lg-8">
        <div class="card shadow-sm border-0">
            <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center">
                <span><i class="bi bi-clock-history me-1"></i> Logs filtrados</span>
                <span class="badge bg-light text-dark"><?=count($logsRelatorio)?> encontrado(s)</span>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light"><tr><th>Data</th><th>Quem fez</th><th>Perfil</th><th>Ação</th><th>Módulo</th><th>IP</th><th>Detalhes</th></tr></thead>
                    <tbody>
                    <?php if(empty($logs)): ?><tr><td colspan="7" class="text-center py-4 text-muted">Nenhum log encontrado.</td></tr><?php endif; ?>
                    <?php foreach($logs as $log): ?>
                        <?php $quem = $log['responsavel_nome'] ?: ($log['responsavel_login'] ?: 'Sistema'); ?>
                        <tr>
                            <td><?=date('d/m/Y H:i', strtotime($log['criado_em']))?></td>
                            <td><strong><?=htmlspecialchars($quem)?></strong></td>
                            <td><?=htmlspecialchars($log['responsavel_perfil'] ?? '-')?></td>
                            <td><strong><?=htmlspecialchars($log['acao'])?></strong></td>
                            <td><?=htmlspecialchars($log['tabela'] ?? '-')?></td>
                            <td><small><?=htmlspecialchars($log['ip'] ?? '-')?></small></td>
                            <td><small><?=htmlspecialchars($log['detalhes'] ?? '-')?></small></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if (count($logsRelatorio) > 100): ?>
                <div class="card-footer text-muted small">A tela exibe os 100 registros mais recentes. O PDF inclui todos os <?=count($logsRelatorio)?> registros filtrados.</div>
            <?php endif; ?>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card shadow-sm border-0">
            <div class="card-header bg-dark text-white"><i class="bi bi-clipboard-data me-1"></i> Inventário de Atualizações</div>
            <div class="table-responsive"><table class="table table-sm align-middle mb-0">
                <thead class="table-light"><tr><th>Responsável</th><th>Módulo</th><th>Total</th><th>Último</th></tr></thead><tbody>
                <?php if(empty($inventarioLogs)): ?><tr><td colspan="4" class="text-center py-4 text-muted">Sem inventário de logs.</td></tr><?php endif; ?>
                <?php foreach($inventarioLogs as $inv): ?><tr>
                    <td><strong><?=htmlspecialchars($inv['usuario_nome'])?></strong><br><small class="text-muted"><?=htmlspecialchars($inv['perfil'])?></small></td>
                    <td><?=htmlspecialchars($inv['modulo'])?></td>
                    <td><span class="badge bg-primary"><?=(int)$inv['total']?></span></td>
                    <td><small><?= $inv['ultimo_registro'] ? date('d/m/Y H:i', strtotime($inv['ultimo_registro'])) : '-' ?></small></td>
                </tr><?php endforeach; ?>
                </tbody></table></div>
        </div>
    </div>
</div>

<div id="relatorioLogsImpressao" class="d-none d-print-block">
    <div class="text-center mb-4">
        <h2>ROJEX.AI — Relatório de Auditoria</h2>
        <p class="mb-1"><strong>Escritório:</strong> <?=htmlspecialchars($cfg['nome_escritorio'])?></p>
        <p class="mb-1"><strong>Período:</strong>
            <?= $logDataInicio !== '' ? date('d/m/Y', strtotime($logDataInicio)) : 'Início dos registros' ?>
            até
            <?= $logDataFim !== '' ? date('d/m/Y', strtotime($logDataFim)) : date('d/m/Y') ?>
        </p>
        <p><strong>Total:</strong> <?=count($logsRelatorio)?> registro(s)</p>
    </div>

    <table class="table table-bordered table-sm">
        <thead>
            <tr><th>Data</th><th>Responsável</th><th>Perfil</th><th>Ação</th><th>Módulo</th><th>Registro</th><th>IP</th><th>Detalhes</th></tr>
        </thead>
        <tbody>
        <?php foreach ($logsRelatorio as $log): ?>
            <?php $quemRelatorio = $log['responsavel_nome'] ?: ($log['responsavel_login'] ?: 'Sistema'); ?>
            <tr>
                <td><?=date('d/m/Y H:i:s', strtotime($log['criado_em']))?></td>
                <td><?=htmlspecialchars($quemRelatorio)?></td>
                <td><?=htmlspecialchars($log['responsavel_perfil'] ?? '-')?></td>
                <td><?=htmlspecialchars($log['acao'])?></td>
                <td><?=htmlspecialchars($log['tabela'] ?? '-')?></td>
                <td><?=htmlspecialchars($log['registro_id'] ?? '-')?></td>
                <td><?=htmlspecialchars($log['ip'] ?? '-')?></td>
                <td><?=htmlspecialchars($log['detalhes'] ?? '-')?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <div class="mt-4 small">
        Relatório emitido em <?=date('d/m/Y H:i:s')?> pelo usuário MASTER
        <?=htmlspecialchars($_SESSION['nome'] ?? $_SESSION['username'] ?? '')?>.
    </div>
</div>
<?php endif; ?>
</div>


<style>
@media print {
    body * { visibility: hidden !important; }
    #relatorioLogsImpressao, #relatorioLogsImpressao * { visibility: visible !important; }
    #relatorioLogsImpressao {
        display: block !important;
        position: absolute;
        left: 0;
        top: 0;
        width: 100%;
        font-size: 9px;
        color: #000;
        background: #fff;
    }
    #relatorioLogsImpressao table { width: 100%; border-collapse: collapse; }
    #relatorioLogsImpressao th, #relatorioLogsImpressao td {
        border: 1px solid #777 !important;
        padding: 3px !important;
        vertical-align: top;
        word-break: break-word;
    }
    @page { size: A4 landscape; margin: 8mm; }
}
</style>

<script>
function gerarPdfLogs(){
    window.print();
}
function prevLogo(input){const f=input.files[0];if(!f)return;const r=new FileReader();r.onload=e=>{document.getElementById('prev_img').src=e.target.result;document.getElementById('prev_wrap').style.display='block';};r.readAsDataURL(f);}
function syncCor(id){const color=document.getElementById(id);const txt=document.getElementById(id+'_txt');if(color&&txt)txt.value=color.value;}
function syncTxt(id){
    const txt=document.getElementById(id+'_txt');
    const color=document.getElementById(id);
    if(!txt||!color)return;
    const v=txt.value;
    if(/^#[0-9A-Fa-f]{6}$/.test(v)){color.value=v;}
}
function atualizarPreviewTema(){
    const preview=document.getElementById('previewTemaEnterprise');
    if(!preview)return;
    const valor=id=>document.getElementById(id)?.value||'';
    const primaria=valor('cor_primaria');
    const secundaria=valor('cor_secundaria');
    const accent=valor('cor_accent');
    const fundo=valor('cor_fundo');
    const texto=valor('cor_texto');
    const modo=valor('tema_modo');
    const densidade=valor('tema_densidade');
    const bordas=valor('tema_bordas');
    const fonte=valor('tema_fonte_percentual')||'100';
    const raio=bordas==='retas'?'0px':(bordas==='arredondadas'?'18px':'8px');
    const padding=densidade==='compacta'?'12px':'20px';
    preview.style.borderRadius=raio;
    preview.style.fontSize=fonte+'%';
    preview.querySelector('.preview-tema-menu').style.cssText=`background:${primaria};color:#fff;padding:${densidade==='compacta'?'10px':'14px'};`;
    preview.querySelector('.preview-tema-badge').style.background=accent;
    const corpo=preview.querySelector('.preview-tema-corpo');
    corpo.style.cssText=`background:${modo==='escuro'?'#151a21':fundo};color:${modo==='escuro'?'#f5f5f5':texto};padding:${padding};`;
    const card=preview.querySelector('.preview-tema-card');
    card.style.cssText=`background:${modo==='escuro'?'#222a35':'#ffffff'};color:${modo==='escuro'?'#f5f5f5':texto};padding:${padding};border-radius:${raio};border-left:4px solid ${secundaria}!important;`;
    const btn=preview.querySelector('.preview-tema-btn');
    btn.style.cssText=`background:${secundaria};color:#fff;border-radius:${raio};`;
    const muted=preview.querySelector('.preview-tema-muted');
    muted.style.opacity='.7';
    const label=document.getElementById('temaFonteValor');
    if(label)label.textContent=fonte+'%';
}
document.addEventListener('DOMContentLoaded', atualizarPreviewTema);

const marcarTodosLixeira = document.getElementById('marcarTodosLixeira');
if (marcarTodosLixeira) {
    marcarTodosLixeira.addEventListener('change', function () {
        document.querySelectorAll('.item-lixeira-check').forEach(cb => cb.checked = this.checked);
    });
}

function confirmarAcaoLote() {
    const selecionados = document.querySelectorAll('.item-lixeira-check:checked').length;
    const acao = document.querySelector('#formLixeiraLote select[name="acao_lote"]')?.value || '';
    if (!selecionados) { alert('Selecione ao menos um item.'); return false; }
    if (!acao) { alert('Escolha uma ação em lote.'); return false; }
    return acao === 'excluir'
        ? confirm('Excluir permanentemente os itens selecionados? Esta ação não pode ser desfeita.')
        : confirm('Restaurar os itens selecionados?');
}

function prepararAcaoIndividual(acao, tabela, id) {
    document.getElementById('acaoCfgLixeira').value = acao;
    document.getElementById('tabelaIndividual').value = tabela;
    document.getElementById('itemIdIndividual').value = id;
    return acao === 'excluir_item_lixeira'
        ? confirm('Excluir este item permanentemente? Esta ação não pode ser desfeita.')
        : confirm('Restaurar este item ao módulo de origem?');
}
</script>
