<?php
/**
 * Módulo Advogados — Fase 2.4
 * CRUD profissional com filtros, cards, validação, CSRF e consultas preparadas.
 */

$conn = conectar();
require_once __DIR__ . '/../config/integracoes.php';

if (!function_exists('rojexContextoTenantValido') || !rojexContextoTenantValido()) {
    $conn->close();
    throw new RuntimeException('Contexto Multi-Tenant inválido para o módulo Advogados.');
}

$tenantId = function_exists('rojexTenantId')
    ? (string)rojexTenantId()
    : trim((string)($_SESSION['tenant_id'] ?? ''));

$escritorioId = function_exists('rojexEscritorioId')
    ? (int)rojexEscritorioId()
    : (int)($_SESSION['escritorio_id'] ?? 0);

if ($tenantId === '' || $escritorioId <= 0) {
    $conn->close();
    throw new RuntimeException('Tenant ou escritório não identificado para o módulo Advogados.');
}

// Correção preventiva Fase 5: garante colunas usadas por este módulo sem apagar dados.
@$conn->query("ALTER TABLE advogados ADD COLUMN IF NOT EXISTS cpf VARCHAR(20) NULL AFTER nome");
@$conn->query("ALTER TABLE advogados ADD COLUMN IF NOT EXISTS oab_uf CHAR(2) NULL AFTER oab");
@$conn->query("ALTER TABLE advogados ADD COLUMN IF NOT EXISTS deletado TINYINT(1) NOT NULL DEFAULT 0 AFTER observacoes");

function garantirAdvogadosMultiTenant(mysqli $conn): void {
    static $garantido = false;

    if ($garantido) {
        return;
    }

    if (function_exists('sgl_int_add_coluna')) {
        sgl_int_add_coluna($conn, 'advogados', 'tenant_id', "tenant_id VARCHAR(80) NULL AFTER id");
        sgl_int_add_coluna($conn, 'advogados', 'escritorio_id', "escritorio_id INT NULL AFTER tenant_id");
    }

    try {
        $tenantLegado = '';
        $escritorioLegado = 0;

        $stmtConfig = $conn->prepare(
            "SELECT valor
               FROM configuracoes
              WHERE chave = 'tenant_id'
              LIMIT 1"
        );

        if ($stmtConfig) {
            $stmtConfig->execute();
            $rowConfig = $stmtConfig->get_result()->fetch_assoc();
            $stmtConfig->close();
            $tenantLegado = trim((string)($rowConfig['valor'] ?? ''));
        }

        if ($tenantLegado !== '') {
            $stmtEsc = $conn->prepare(
                "SELECT id
                   FROM escritorios_saas
                  WHERE tenant_id = ?
                  LIMIT 1"
            );

            if ($stmtEsc) {
                $stmtEsc->bind_param('s', $tenantLegado);
                $stmtEsc->execute();
                $rowEsc = $stmtEsc->get_result()->fetch_assoc();
                $stmtEsc->close();
                $escritorioLegado = (int)($rowEsc['id'] ?? 0);
            }
        }

        if ($tenantLegado !== '' && $escritorioLegado > 0) {
            $stmtBackfill = $conn->prepare(
                "UPDATE advogados
                    SET tenant_id = ?,
                        escritorio_id = ?
                  WHERE tenant_id IS NULL
                     OR tenant_id = ''
                     OR escritorio_id IS NULL
                     OR escritorio_id = 0"
            );

            if ($stmtBackfill) {
                $stmtBackfill->bind_param('si', $tenantLegado, $escritorioLegado);
                $stmtBackfill->execute();
                $stmtBackfill->close();
            }
        }

        $indices = [];
        $resIndices = $conn->query("SHOW INDEX FROM advogados");
        if ($resIndices) {
            while ($idx = $resIndices->fetch_assoc()) {
                $indices[(string)$idx['Key_name']] = true;
            }
        }

        if (!isset($indices['idx_advogados_tenant'])) {
            $conn->query("ALTER TABLE advogados ADD INDEX idx_advogados_tenant (tenant_id)");
        }

        if (!isset($indices['idx_advogados_escritorio'])) {
            $conn->query("ALTER TABLE advogados ADD INDEX idx_advogados_escritorio (escritorio_id)");
        }

        if (!isset($indices['idx_advogados_tenant_cpf'])) {
            $conn->query("ALTER TABLE advogados ADD INDEX idx_advogados_tenant_cpf (tenant_id, cpf)");
        }

        if (!isset($indices['idx_advogados_tenant_oab'])) {
            $conn->query("ALTER TABLE advogados ADD INDEX idx_advogados_tenant_oab (tenant_id, oab, oab_uf)");
        }

        $garantido = true;
    } catch (Throwable $e) {
        error_log('[ROJEX ADVOGADOS MULTI-TENANT] ' . $e->getMessage());
        throw new RuntimeException(
            'Não foi possível preparar o isolamento Multi-Tenant de Advogados.',
            0,
            $e
        );
    }
}

garantirAdvogadosMultiTenant($conn);

function adv_scalar(mysqli $conn, string $sql, string $tenantId, int $default = 0): int {
    $stmt = $conn->prepare($sql);
    if (!$stmt) { return $default; }
    $stmt->bind_param('s', $tenantId);
    if (!$stmt->execute()) {
        $stmt->close();
        return $default;
    }
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int)($row['total'] ?? $default);
}

$acao = $_GET['acao'] ?? 'listar';
$msg  = '';

if (!function_exists('h')) {
    function h($valor): string {
        return htmlspecialchars((string)($valor ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

function apenasDigitosAdv(?string $valor): string {
    return preg_replace('/\D+/', '', (string)$valor);
}

function gerarIdAdvogado(mysqli $conn): string {
    $res = $conn->query("SELECT id FROM advogados WHERE id LIKE 'ADV%' ORDER BY CAST(SUBSTRING(id, 4) AS UNSIGNED) DESC LIMIT 1");
    if (!$res || $res->num_rows === 0) {
        return 'ADV001';
    }
    $ultimo = $res->fetch_assoc()['id'];
    $num = (int) substr($ultimo, 3) + 1;
    return 'ADV' . str_pad((string)$num, 3, '0', STR_PAD_LEFT);
}

function camposAdvogado(array $d = []): array {
    return [
        'nome'          => trim($d['nome'] ?? ''),
        'cpf'           => trim($d['cpf'] ?? ''),
        'oab'           => strtoupper(trim($d['oab'] ?? '')),
        'oab_uf'        => strtoupper(substr(trim($d['oab_uf'] ?? ''), 0, 2)),
        'especialidade' => trim($d['especialidade'] ?? ''),
        'telefone'      => trim($d['telefone'] ?? ''),
        'email'         => trim($d['email'] ?? ''),
        'status'        => $d['status'] ?? 'Ativo',
        'observacoes'   => trim($d['observacoes'] ?? ''),
    ];
}

function validarAdvogado(array $a): array {
    $erros = [];
    if ($a['nome'] === '') {
        $erros[] = 'O nome do advogado é obrigatório.';
    }
    if ($a['email'] !== '' && !filter_var($a['email'], FILTER_VALIDATE_EMAIL)) {
        $erros[] = 'O e-mail informado não é válido.';
    }
    if (!in_array($a['status'], ['Ativo', 'Inativo', 'Excluído'], true)) {
        $erros[] = 'Status inválido.';
    }
    $cpf = apenasDigitosAdv($a['cpf']);
    if ($cpf !== '' && strlen($cpf) !== 11) {
        $erros[] = 'CPF deve conter 11 dígitos.';
    }
    if ($a['oab_uf'] !== '' && strlen($a['oab_uf']) !== 2) {
        $erros[] = 'UF da OAB deve conter 2 letras.';
    }
    return $erros;
}

function advogadoExisteCampo(mysqli $conn, string $tenantId, string $campo, string $valor, ?string $ignorarId = null): bool {
    $valor = trim($valor);
    if ($valor === '') return false;
    $permitidos = ['cpf', 'email'];
    if (!in_array($campo, $permitidos, true)) return false;

    $sql = "SELECT id FROM advogados WHERE tenant_id = ? AND {$campo} = ? AND deletado = 0";
    $params = [$tenantId, $valor];
    $types = 'ss';
    if ($ignorarId !== null && $ignorarId !== '') {
        $sql .= ' AND id <> ?';
        $params[] = $ignorarId;
        $types .= 's';
    }
    $sql .= ' LIMIT 1';
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();
    return $res && $res->num_rows > 0;
}

function advogadoExisteOab(mysqli $conn, string $tenantId, string $oab, string $uf, ?string $ignorarId = null): bool {
    $oab = trim($oab);
    $uf = trim($uf);
    if ($oab === '') return false;
    $sql = 'SELECT id FROM advogados WHERE tenant_id = ? AND oab = ? AND COALESCE(oab_uf, \'\') = ? AND deletado = 0';
    $params = [$tenantId, $oab, $uf];
    $types = 'sss';
    if ($ignorarId !== null && $ignorarId !== '') {
        $sql .= ' AND id <> ?';
        $params[] = $ignorarId;
        $types .= 's';
    }
    $sql .= ' LIMIT 1';
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();
    return $res && $res->num_rows > 0;
}

function salvarAdvogado(mysqli $conn, string $tenantId, int $escritorioId, string $id, array $a): bool {
    $sql = "INSERT INTO advogados (
        id, tenant_id, escritorio_id, nome, cpf, oab, oab_uf, especialidade,
        telefone, email, status, observacoes, data_cadastro
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURDATE())";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param(
        'ssisssssssss',
        $id, $tenantId, $escritorioId, $a['nome'], $a['cpf'], $a['oab'], $a['oab_uf'],
        $a['especialidade'], $a['telefone'], $a['email'], $a['status'], $a['observacoes']
    );
    return $stmt->execute();
}

function atualizarAdvogado(mysqli $conn, string $tenantId, string $id, array $a): bool {
    $sql = "UPDATE advogados SET
        nome = ?, cpf = ?, oab = ?, oab_uf = ?, especialidade = ?,
        telefone = ?, email = ?, status = ?, observacoes = ?
        WHERE id = ? AND tenant_id = ? AND deletado = 0";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param(
        'sssssssssss',
        $a['nome'], $a['cpf'], $a['oab'], $a['oab_uf'], $a['especialidade'],
        $a['telefone'], $a['email'], $a['status'], $a['observacoes'], $id, $tenantId
    );
    return $stmt->execute();
}


function buscarAdvogadoAuditoria(mysqli $conn, string $tenantId, string $id): ?array {
    $stmt = $conn->prepare('SELECT * FROM advogados WHERE id = ? AND tenant_id = ? LIMIT 1');
    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('ss', $id, $tenantId);
    $stmt->execute();
    $res = $stmt->get_result();
    $advogado = ($res && $res->num_rows > 0) ? $res->fetch_assoc() : null;
    $stmt->close();

    return $advogado;
}

$advogado_editar = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['salvar_advogado']) || isset($_POST['atualizar_advogado']))) {
    if (!validarTokenCsrf($_POST['csrf_token'] ?? null)) {
        $msg = '<div class="alert alert-danger">Sessão expirada ou formulário inválido. Recarregue a página e tente novamente.</div>';
        $acao = isset($_POST['atualizar_advogado']) ? 'editar' : 'novo';
    } else {
        $a = camposAdvogado($_POST);
        $erros = validarAdvogado($a);
        $idAtual = $_POST['id'] ?? null;

        if (advogadoExisteCampo($conn, $tenantId, 'cpf', $a['cpf'], $idAtual)) {
            $erros[] = 'Já existe outro advogado cadastrado com este CPF.';
        }
        if (advogadoExisteCampo($conn, $tenantId, 'email', $a['email'], $idAtual)) {
            $erros[] = 'Já existe outro advogado cadastrado com este e-mail.';
        }
        if (advogadoExisteOab($conn, $tenantId, $a['oab'], $a['oab_uf'], $idAtual)) {
            $erros[] = 'Já existe outro advogado cadastrado com esta OAB/UF.';
        }

        if ($erros) {
            $msg = '<div class="alert alert-danger"><strong>Confira os dados:</strong><br>' . implode('<br>', array_map('h', $erros)) . '</div>';
            $acao = isset($_POST['atualizar_advogado']) ? 'editar' : 'novo';
            $advogado_editar = $a;
            if ($idAtual) $advogado_editar['id'] = $idAtual;
        } elseif (isset($_POST['salvar_advogado'])) {
            $id = gerarIdAdvogado($conn);
            if (salvarAdvogado($conn, $tenantId, $escritorioId, $id, $a)) {
                if (function_exists('sgl_registrar_log')) {
                    sgl_registrar_log(
                        $conn,
                        'Advogado incluído',
                        'advogados',
                        $id,
                        'Novo advogado cadastrado: ' . $a['nome'],
                        [
                            'tipo_acao' => 'INCLUSAO',
                            'modulo' => 'Advogados',
                            'origem' => 'Cadastro de advogados',
                            'resultado' => 'SUCESSO',
                            'nivel' => 'INFO',
                            'dados_novos' => buscarAdvogadoAuditoria($conn, $tenantId, $id) ?? $a,
                        ]
                    );
                }

                $msg = "<div class='alert alert-success'>✅ Advogado <strong>" . h($id) . "</strong> cadastrado com sucesso.</div>";
                $acao = 'listar';
            } else {
                $msg = '<div class="alert alert-danger">Erro ao salvar advogado: ' . h($conn->error) . '</div>';
                $acao = 'novo';
                $advogado_editar = $a;
            }
        } else {
            $id = (string)($_POST['id'] ?? '');
            $dadosAnteriores = buscarAdvogadoAuditoria($conn, $tenantId, $id);

            if ($dadosAnteriores === null) {
                $msg = '<div class="alert alert-danger">Advogado não encontrado neste escritório.</div>';
                $acao = 'listar';
            } elseif (atualizarAdvogado($conn, $tenantId, $id, $a)) {
                if (function_exists('sgl_registrar_log')) {
                    sgl_registrar_log(
                        $conn,
                        'Advogado atualizado',
                        'advogados',
                        $id,
                        'Dados do advogado atualizados: ' . $a['nome'],
                        [
                            'tipo_acao' => 'EDICAO',
                            'modulo' => 'Advogados',
                            'origem' => 'Edição de advogados',
                            'resultado' => 'SUCESSO',
                            'nivel' => 'INFO',
                            'dados_anteriores' => $dadosAnteriores,
                            'dados_novos' => buscarAdvogadoAuditoria($conn, $tenantId, $id) ?? $a,
                        ]
                    );
                }

                $msg = "<div class='alert alert-success'>✅ Advogado <strong>" . h($id) . "</strong> atualizado com sucesso.</div>";
                $acao = 'listar';
            } else {
                $msg = '<div class="alert alert-danger">Erro ao atualizar advogado: ' . h($conn->error) . '</div>';
                $acao = 'editar';
                $advogado_editar = $a;
                $advogado_editar['id'] = $id;
            }
        }
    }
}

if (isset($_GET['excluir'])) {
    if (!validarTokenCsrf($_GET['csrf_token'] ?? null)) {
        $msg = '<div class="alert alert-danger">Ação bloqueada por segurança. Tente novamente.</div>';
    } else {
        $id = (string)$_GET['excluir'];
        $dadosAnteriores = buscarAdvogadoAuditoria($conn, $tenantId, $id);

        $stmt = $conn->prepare(
            "UPDATE advogados
                SET deletado = 1,
                    status = 'Excluído'
              WHERE id = ?
                AND tenant_id = ?"
        );
        $stmt->bind_param('ss', $id, $tenantId);
        $okExcluir = $stmt->execute();
        $linhasAfetadas = $stmt->affected_rows;
        $stmt->close();

        if ($okExcluir && $linhasAfetadas > 0) {
            if (function_exists('sgl_registrar_log')) {
                sgl_registrar_log(
                    $conn,
                    'Advogado movido para a lixeira',
                    'advogados',
                    $id,
                    'Exclusão lógica do advogado: ' . (string)($dadosAnteriores['nome'] ?? $id),
                    [
                        'tipo_acao' => 'EXCLUSAO',
                        'modulo' => 'Advogados',
                        'origem' => 'Lista de advogados',
                        'resultado' => 'SUCESSO',
                        'nivel' => 'AVISO',
                        'dados_anteriores' => $dadosAnteriores,
                        'dados_novos' => buscarAdvogadoAuditoria($conn, $tenantId, $id),
                    ]
                );
            }

            $msg = "<div class='alert alert-warning'>🗑️ Advogado <strong>" . h($id) . "</strong> movido para a lixeira.</div>";
        } else {
            if (function_exists('sgl_registrar_log')) {
                sgl_registrar_log(
                    $conn,
                    'Falha ao mover advogado para a lixeira',
                    'advogados',
                    $id,
                    'O registro não foi alterado.',
                    [
                        'tipo_acao' => 'EXCLUSAO',
                        'modulo' => 'Advogados',
                        'origem' => 'Lista de advogados',
                        'resultado' => 'FALHA',
                        'nivel' => 'ERRO',
                        'dados_anteriores' => $dadosAnteriores,
                    ]
                );
            }

            $msg = "<div class='alert alert-danger'>Não foi possível mover o advogado para a lixeira.</div>";
        }
    }
    $acao = 'listar';
}

if ($acao === 'editar' && isset($_GET['id']) && $advogado_editar === null) {
    $id_editar = (string)$_GET['id'];
    $stmt = $conn->prepare(
        'SELECT * FROM advogados
          WHERE id = ?
            AND tenant_id = ?
            AND deletado = 0
          LIMIT 1'
    );
    $stmt->bind_param('ss', $id_editar, $tenantId);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res && $res->num_rows > 0) {
        $advogado_editar = $res->fetch_assoc();
    } else {
        $msg = '<div class="alert alert-danger">Advogado não encontrado.</div>';
        $acao = 'listar';
    }
}

$f = camposAdvogado($advogado_editar ?: []);
$csrf = gerarTokenCsrf();
?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
    <div>
        <h2 class="mb-1"><i class="bi bi-person-badge"></i> Advogados</h2>
        <div class="text-muted small">Cadastro, pesquisa e gestão da equipe jurídica do escritório.</div>
    </div>
    <?php if ($acao !== 'novo' && $acao !== 'editar'): ?>
        <a href="?mod=advogados&acao=novo" class="btn btn-primary"><i class="bi bi-plus-circle"></i> Novo Advogado</a>
    <?php endif; ?>
</div>

<?= $msg ?>

<?php if ($acao === 'novo' || $acao === 'editar'): ?>
<div class="card shadow-sm mb-4 border-0">
    <div class="card-header <?= $acao === 'editar' ? 'bg-warning text-dark' : 'bg-dark text-white' ?> fw-semibold">
        <?= $acao === 'editar' ? '<i class="bi bi-pencil-square"></i> Editar Advogado — ' . h($advogado_editar['id'] ?? '') : '<i class="bi bi-plus-circle"></i> Cadastrar Novo Advogado' ?>
    </div>
    <div class="card-body">
        <form method="POST" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
            <?php if ($acao === 'editar'): ?>
                <input type="hidden" name="id" value="<?= h($advogado_editar['id'] ?? '') ?>">
            <?php endif; ?>

            <div class="row g-3">
                <div class="col-lg-8">
                    <label class="form-label">Nome completo *</label>
                    <input type="text" name="nome" class="form-control" value="<?= h($f['nome']) ?>" required maxlength="120" autofocus>
                </div>
                <div class="col-lg-4">
                    <label class="form-label">CPF</label>
                    <input type="text" name="cpf" class="form-control js-cpf" value="<?= h($f['cpf']) ?>" placeholder="000.000.000-00" maxlength="14">
                </div>

                <div class="col-md-4">
                    <label class="form-label">Número OAB</label>
                    <input type="text" name="oab" class="form-control" value="<?= h($f['oab']) ?>" placeholder="Ex: 123456" maxlength="30">
                </div>
                <div class="col-md-2">
                    <label class="form-label">UF OAB</label>
                    <input type="text" name="oab_uf" class="form-control text-uppercase" value="<?= h($f['oab_uf']) ?>" placeholder="PR" maxlength="2">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Especialidade / área de atuação</label>
                    <input type="text" name="especialidade" class="form-control" value="<?= h($f['especialidade']) ?>" placeholder="Ex: Previdenciário, Cível, Trabalhista" maxlength="80">
                </div>

                <div class="col-md-4">
                    <label class="form-label">Telefone / WhatsApp</label>
                    <input type="text" name="telefone" class="form-control js-phone" value="<?= h($f['telefone']) ?>" placeholder="(41) 99999-9999" maxlength="15">
                </div>
                <div class="col-md-5">
                    <label class="form-label">E-mail profissional</label>
                    <input type="email" name="email" class="form-control" value="<?= h($f['email']) ?>" placeholder="advogado@escritorio.com" maxlength="120">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Status</label>
                    <select name="status" class="form-select">
                        <?php foreach (['Ativo', 'Inativo'] as $status): ?>
                            <option value="<?= h($status) ?>" <?= $f['status'] === $status ? 'selected' : '' ?>><?= h($status) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-12">
                    <label class="form-label">Observações</label>
                    <textarea name="observacoes" class="form-control" rows="4" placeholder="Informações internas, áreas preferenciais, observações administrativas..."><?= h($f['observacoes']) ?></textarea>
                </div>
            </div>

            <div class="d-flex flex-wrap gap-2 mt-4">
                <button type="submit" name="<?= $acao === 'editar' ? 'atualizar_advogado' : 'salvar_advogado' ?>" class="btn <?= $acao === 'editar' ? 'btn-warning' : 'btn-success' ?>">
                    <i class="bi bi-save"></i> <?= $acao === 'editar' ? 'Salvar Alterações' : 'Salvar Advogado' ?>
                </button>
                <a href="?mod=advogados" class="btn btn-outline-secondary"><i class="bi bi-x-circle"></i> Cancelar</a>
            </div>
        </form>
    </div>
</div>

<?php else: ?>

<?php
$busca = trim($_GET['busca'] ?? '');
$statusFiltro = $_GET['status'] ?? '';
$especialidadeFiltro = trim($_GET['especialidade'] ?? '');

$where = ['tenant_id = ?', 'deletado = 0'];
$params = [$tenantId];
$types = 's';

if ($busca !== '') {
    $where[] = '(id LIKE ? OR nome LIKE ? OR cpf LIKE ? OR oab LIKE ? OR oab_uf LIKE ? OR especialidade LIKE ? OR telefone LIKE ? OR email LIKE ?)';
    $like = '%' . $busca . '%';
    for ($i = 0; $i < 8; $i++) {
        $params[] = $like;
        $types .= 's';
    }
}
if (in_array($statusFiltro, ['Ativo', 'Inativo'], true)) {
    $where[] = 'status = ?';
    $params[] = $statusFiltro;
    $types .= 's';
}
if ($especialidadeFiltro !== '') {
    $where[] = 'especialidade LIKE ?';
    $params[] = '%' . $especialidadeFiltro . '%';
    $types .= 's';
}

$whereSql = 'WHERE ' . implode(' AND ', $where);

$totalAdvogados = adv_scalar(
    $conn,
    "SELECT COUNT(*) AS total
       FROM advogados
      WHERE tenant_id = ?
        AND COALESCE(deletado,0) = 0",
    $tenantId
);
$ativos = adv_scalar(
    $conn,
    "SELECT COUNT(*) AS total
       FROM advogados
      WHERE tenant_id = ?
        AND COALESCE(deletado,0) = 0
        AND status = 'Ativo'",
    $tenantId
);
$inativos = adv_scalar(
    $conn,
    "SELECT COUNT(*) AS total
       FROM advogados
      WHERE tenant_id = ?
        AND COALESCE(deletado,0) = 0
        AND status = 'Inativo'",
    $tenantId
);
$novosMes = adv_scalar(
    $conn,
    "SELECT COUNT(*) AS total
       FROM advogados
      WHERE tenant_id = ?
        AND COALESCE(deletado,0) = 0
        AND data_cadastro >= DATE_FORMAT(CURDATE(), '%Y-%m-01')",
    $tenantId
);

$especialidadesPadrao = [
    'Administrativo',
    'Ambiental',
    'Bancário',
    'Cível',
    'Consumidor',
    'Contratual',
    'Criminal',
    'Digital / LGPD',
    'Eleitoral',
    'Empresarial',
    'Família e Sucessões',
    'Imobiliário',
    'Médico e Saúde',
    'Previdenciário',
    'Propriedade Intelectual',
    'Público',
    'Tributário',
    'Trabalhista',
    'Outros'
];

$especialidadesCadastradas = [];
$stmtEspecialidades = $conn->prepare(
    "SELECT DISTINCT especialidade
       FROM advogados
      WHERE tenant_id = ?
        AND deletado = 0
        AND especialidade IS NOT NULL
        AND especialidade <> ''
      ORDER BY especialidade ASC
      LIMIT 100"
);
if ($stmtEspecialidades) {
    $stmtEspecialidades->bind_param('s', $tenantId);
    $stmtEspecialidades->execute();
    $especialidadesQuery = $stmtEspecialidades->get_result();
    while ($esp = $especialidadesQuery->fetch_assoc()) {
        $valor = trim((string)$esp['especialidade']);
        if ($valor !== '') {
            $especialidadesCadastradas[] = $valor;
        }
    }
    $stmtEspecialidades->close();
}
$especialidadesFiltro = array_values(array_unique(array_merge($especialidadesPadrao, $especialidadesCadastradas)));
sort($especialidadesFiltro, SORT_NATURAL | SORT_FLAG_CASE);

$sqlLista = "SELECT id, nome, cpf, oab, oab_uf, especialidade, telefone, email, status, data_cadastro
             FROM advogados {$whereSql}
             ORDER BY status ASC, nome ASC
             LIMIT 200";
$stmtLista = $conn->prepare($sqlLista);
$lista = false;
if ($stmtLista) {
    if ($params) {
        $stmtLista->bind_param($types, ...$params);
    }
    $stmtLista->execute();
    $lista = $stmtLista->get_result();
}
$encontrados = $lista ? $lista->num_rows : 0;
?>

<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="card shadow-sm border-0 h-100"><div class="card-body">
            <div class="text-muted small text-uppercase">Total de advogados</div>
            <div class="fs-2 fw-bold"><?= h($totalAdvogados) ?></div>
        </div></div>
    </div>
    <div class="col-md-3">
        <div class="card shadow-sm border-0 h-100"><div class="card-body">
            <div class="text-muted small text-uppercase">Advogados ativos</div>
            <div class="fs-2 fw-bold text-success"><?= h($ativos) ?></div>
        </div></div>
    </div>
    <div class="col-md-3">
        <div class="card shadow-sm border-0 h-100"><div class="card-body">
            <div class="text-muted small text-uppercase">Inativos</div>
            <div class="fs-2 fw-bold text-secondary"><?= h($inativos) ?></div>
        </div></div>
    </div>
    <div class="col-md-3">
        <div class="card shadow-sm border-0 h-100"><div class="card-body">
            <div class="text-muted small text-uppercase">Novos no mês</div>
            <div class="fs-2 fw-bold text-primary"><?= h($novosMes) ?></div>
        </div></div>
    </div>
</div>

<div class="card shadow-sm border-0 mb-3">
    <div class="card-body">
        <form method="GET" class="row g-2 align-items-end">
            <input type="hidden" name="mod" value="advogados">
            <div class="col-lg-5">
                <label class="form-label small mb-1">Pesquisa inteligente</label>
                <input type="text" name="busca" class="form-control" placeholder="Nome, ID, CPF, OAB, telefone ou e-mail" value="<?= h($busca) ?>">
            </div>
            <div class="col-lg-2">
                <label class="form-label small mb-1">Status</label>
                <select name="status" class="form-select">
                    <option value="">Todos</option>
                    <option value="Ativo" <?= $statusFiltro === 'Ativo' ? 'selected' : '' ?>>Ativos</option>
                    <option value="Inativo" <?= $statusFiltro === 'Inativo' ? 'selected' : '' ?>>Inativos</option>
                </select>
            </div>
            <div class="col-lg-3">
                <label class="form-label small mb-1">Especialidade</label>
                <select name="especialidade" class="form-select">
                    <option value="">Todas</option>
                    <?php foreach ($especialidadesFiltro as $esp): ?>
                        <option value="<?= h($esp) ?>" <?= $especialidadeFiltro === $esp ? 'selected' : '' ?>><?= h($esp) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-lg-1 d-grid">
                <button class="btn btn-outline-primary" type="submit"><i class="bi bi-search"></i> Buscar</button>
            </div>
            <div class="col-lg-1 d-grid">
                <a href="?mod=advogados" class="btn btn-outline-secondary"><i class="bi bi-x-lg"></i></a>
            </div>
        </form>
    </div>
</div>

<div class="card shadow-sm border-0">
    <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center">
        <span><i class="bi bi-list-ul"></i> Lista de Advogados</span>
        <span class="small"><?= h($encontrados) ?> registro(s) encontrado(s)</span>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>ID</th>
                    <th>Nome</th>
                    <th>OAB</th>
                    <th>CPF</th>
                    <th>Especialidade</th>
                    <th>Contato</th>
                    <th>Status</th>
                    <th class="text-end">Ações</th>
                </tr>
            </thead>
            <tbody>
            <?php if ($lista && $lista->num_rows > 0): ?>
                <?php if ($lista): while ($row = $lista->fetch_assoc()): ?>
                <tr>
                    <td class="fw-semibold"><?= h($row['id']) ?></td>
                    <td>
                        <strong><?= h($row['nome']) ?></strong>
                        <?php if (!empty($row['email'])): ?><div class="small text-muted"><?= h($row['email']) ?></div><?php endif; ?>
                    </td>
                    <td><?= h(trim(($row['oab'] ?? '') . ' ' . ($row['oab_uf'] ?? '')) ?: '-') ?></td>
                    <td><?= h($row['cpf'] ?: '-') ?></td>
                    <td><?= h($row['especialidade'] ?: '-') ?></td>
                    <td><?= h($row['telefone'] ?: '-') ?></td>
                    <td>
                        <span class="badge bg-<?= $row['status'] === 'Ativo' ? 'success' : 'secondary' ?>">
                            <?= h($row['status']) ?>
                        </span>
                    </td>
                    <td class="text-end">
                        <a href="?mod=advogados&acao=editar&id=<?= urlencode($row['id']) ?>" class="btn btn-sm btn-warning"><i class="bi bi-pencil-square"></i> Editar</a>
                        <a href="?mod=advogados&excluir=<?= urlencode($row['id']) ?>&csrf_token=<?= urlencode($csrf) ?>" class="btn btn-sm btn-danger" onclick="return confirm('Deseja mover este advogado para a lixeira?')"><i class="bi bi-trash"></i></a>
                    </td>
                </tr>
                <?php endwhile; endif; ?>
            <?php else: ?>
                <tr><td colspan="8" class="text-center text-muted py-5">Nenhum advogado encontrado.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.js-phone').forEach(function (input) {
        input.setAttribute('inputmode', 'numeric');
        input.addEventListener('input', function (e) {
            let v = e.target.value.replace(/\D/g, '').substring(0, 11);
            if (v.length > 10) v = v.replace(/^(\d{2})(\d{5})(\d{4})$/, '($1) $2-$3');
            else if (v.length > 6) v = v.replace(/^(\d{2})(\d{4})(\d{0,4})$/, '($1) $2-$3');
            else if (v.length > 2) v = v.replace(/^(\d{2})(\d{0,5})$/, '($1) $2');
            else if (v.length > 0) v = v.replace(/^(\d{0,2})$/, '($1');
            e.target.value = v;
        });
    });

    document.querySelectorAll('.js-cpf').forEach(function (input) {
        input.setAttribute('inputmode', 'numeric');
        input.addEventListener('input', function (e) {
            let v = e.target.value.replace(/\D/g, '').substring(0, 11);
            if (v.length > 9) v = v.replace(/^(\d{3})(\d{3})(\d{3})(\d{0,2})$/, '$1.$2.$3-$4');
            else if (v.length > 6) v = v.replace(/^(\d{3})(\d{3})(\d{0,3})$/, '$1.$2.$3');
            else if (v.length > 3) v = v.replace(/^(\d{3})(\d{0,3})$/, '$1.$2');
            e.target.value = v;
        });
    });

    document.querySelectorAll('input[name="oab_uf"]').forEach(function (input) {
        input.addEventListener('input', function (e) {
            e.target.value = e.target.value.replace(/[^a-zA-Z]/g, '').toUpperCase().substring(0, 2);
        });
    });
});
</script>