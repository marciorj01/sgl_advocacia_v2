<?php
/**
 * Módulo Advogados — Fase 2.4
 * CRUD profissional com filtros, cards, validação, CSRF e consultas preparadas.
 */

$conn = conectar();

// Correção preventiva Fase 5: garante colunas usadas por este módulo sem apagar dados.
@$conn->query("ALTER TABLE advogados ADD COLUMN IF NOT EXISTS cpf VARCHAR(20) NULL AFTER nome");
@$conn->query("ALTER TABLE advogados ADD COLUMN IF NOT EXISTS oab_uf CHAR(2) NULL AFTER oab");
@$conn->query("ALTER TABLE advogados ADD COLUMN IF NOT EXISTS deletado TINYINT(1) NOT NULL DEFAULT 0 AFTER observacoes");

function adv_scalar(mysqli $conn, string $sql, int $default = 0): int {
    $res = @$conn->query($sql);
    if (!$res) { return $default; }
    $row = $res->fetch_assoc();
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

function advogadoExisteCampo(mysqli $conn, string $campo, string $valor, ?string $ignorarId = null): bool {
    $valor = trim($valor);
    if ($valor === '') return false;
    $permitidos = ['cpf', 'email'];
    if (!in_array($campo, $permitidos, true)) return false;

    $sql = "SELECT id FROM advogados WHERE {$campo} = ? AND deletado = 0";
    $params = [$valor];
    $types = 's';
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

function advogadoExisteOab(mysqli $conn, string $oab, string $uf, ?string $ignorarId = null): bool {
    $oab = trim($oab);
    $uf = trim($uf);
    if ($oab === '') return false;
    $sql = 'SELECT id FROM advogados WHERE oab = ? AND COALESCE(oab_uf, \'\') = ? AND deletado = 0';
    $params = [$oab, $uf];
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

function salvarAdvogado(mysqli $conn, string $id, array $a): bool {
    $sql = "INSERT INTO advogados (
        id, nome, cpf, oab, oab_uf, especialidade, telefone, email, status, observacoes, data_cadastro
    ) VALUES (?, ?, NULLIF(?, ''), ?, ?, ?, ?, ?, ?, ?, CURDATE())";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param(
        'ssssssssss',
        $id, $a['nome'], $a['cpf'], $a['oab'], $a['oab_uf'], $a['especialidade'],
        $a['telefone'], $a['email'], $a['status'], $a['observacoes']
    );
    return $stmt->execute();
}

function atualizarAdvogado(mysqli $conn, string $id, array $a): bool {
    $sql = "UPDATE advogados SET
        nome = ?, cpf = NULLIF(?, ''), oab = ?, oab_uf = ?, especialidade = ?,
        telefone = ?, email = ?, status = ?, observacoes = ?
        WHERE id = ? AND deletado = 0";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param(
        'ssssssssss',
        $a['nome'], $a['cpf'], $a['oab'], $a['oab_uf'], $a['especialidade'],
        $a['telefone'], $a['email'], $a['status'], $a['observacoes'], $id
    );
    return $stmt->execute();
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

        if (advogadoExisteCampo($conn, 'cpf', $a['cpf'], $idAtual)) {
            $erros[] = 'Já existe outro advogado cadastrado com este CPF.';
        }
        if (advogadoExisteCampo($conn, 'email', $a['email'], $idAtual)) {
            $erros[] = 'Já existe outro advogado cadastrado com este e-mail.';
        }
        if (advogadoExisteOab($conn, $a['oab'], $a['oab_uf'], $idAtual)) {
            $erros[] = 'Já existe outro advogado cadastrado com esta OAB/UF.';
        }

        if ($erros) {
            $msg = '<div class="alert alert-danger"><strong>Confira os dados:</strong><br>' . implode('<br>', array_map('h', $erros)) . '</div>';
            $acao = isset($_POST['atualizar_advogado']) ? 'editar' : 'novo';
            $advogado_editar = $a;
            if ($idAtual) $advogado_editar['id'] = $idAtual;
        } elseif (isset($_POST['salvar_advogado'])) {
            $id = gerarIdAdvogado($conn);
            if (salvarAdvogado($conn, $id, $a)) {
                $msg = "<div class='alert alert-success'>✅ Advogado <strong>" . h($id) . "</strong> cadastrado com sucesso.</div>";
                $acao = 'listar';
            } else {
                $msg = '<div class="alert alert-danger">Erro ao salvar advogado: ' . h($conn->error) . '</div>';
                $acao = 'novo';
                $advogado_editar = $a;
            }
        } else {
            $id = (string)($_POST['id'] ?? '');
            if (atualizarAdvogado($conn, $id, $a)) {
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
        $stmt = $conn->prepare("UPDATE advogados SET deletado = 1, status = 'Excluído' WHERE id = ?");
        $stmt->bind_param('s', $id);
        $stmt->execute();
        $msg = "<div class='alert alert-warning'>🗑️ Advogado <strong>" . h($id) . "</strong> movido para a lixeira.</div>";
    }
    $acao = 'listar';
}

if ($acao === 'editar' && isset($_GET['id']) && $advogado_editar === null) {
    $id_editar = (string)$_GET['id'];
    $stmt = $conn->prepare('SELECT * FROM advogados WHERE id = ? AND deletado = 0 LIMIT 1');
    $stmt->bind_param('s', $id_editar);
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

$where = ['deletado = 0'];
$params = [];
$types = '';

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

$totalAdvogados = adv_scalar($conn, "SELECT COUNT(*) AS total FROM advogados WHERE COALESCE(deletado,0) = 0");
$ativos = adv_scalar($conn, "SELECT COUNT(*) AS total FROM advogados WHERE COALESCE(deletado,0) = 0 AND status = 'Ativo'");
$inativos = adv_scalar($conn, "SELECT COUNT(*) AS total FROM advogados WHERE COALESCE(deletado,0) = 0 AND status = 'Inativo'");
$novosMes = adv_scalar($conn, "SELECT COUNT(*) AS total FROM advogados WHERE COALESCE(deletado,0) = 0 AND data_cadastro >= DATE_FORMAT(CURDATE(), '%Y-%m-01')");

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
$especialidadesQuery = $conn->query("SELECT DISTINCT especialidade FROM advogados WHERE deletado = 0 AND especialidade IS NOT NULL AND especialidade <> '' ORDER BY especialidade ASC LIMIT 100");
if ($especialidadesQuery) {
    while ($esp = $especialidadesQuery->fetch_assoc()) {
        $valor = trim((string)$esp['especialidade']);
        if ($valor !== '') {
            $especialidadesCadastradas[] = $valor;
        }
    }
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
