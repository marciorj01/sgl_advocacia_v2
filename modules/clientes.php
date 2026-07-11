<?php
/**
 * Módulo Clientes — Fase 2.2
 * CRUD profissional com validação, filtros, paginação, CSRF e consultas preparadas.
 */

$conn = conectar();
require_once __DIR__ . '/../config/integracoes.php';
$acao = $_GET['acao'] ?? 'listar';
$msg  = '';

if (!function_exists('h')) {
    function h($valor): string {
        return htmlspecialchars((string)($valor ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

function apenasDigitos(?string $valor): string {
    return preg_replace('/\D+/', '', (string)$valor);
}

function gerarIdCliente(mysqli $conn): string {
    $res = $conn->query("SELECT id FROM clientes WHERE id LIKE 'CLI%' ORDER BY CAST(SUBSTRING(id, 4) AS UNSIGNED) DESC LIMIT 1");
    if (!$res || $res->num_rows === 0) {
        return 'CLI001';
    }
    $ultimo = $res->fetch_assoc()['id'];
    $num = (int) substr($ultimo, 3) + 1;
    return 'CLI' . str_pad((string)$num, 3, '0', STR_PAD_LEFT);
}

function camposCliente(array $d = []): array {
    return [
        'nome'             => trim($d['nome'] ?? ''),
        'cpf_cnpj'         => trim($d['cpf_cnpj'] ?? ''),
        'tipo_pessoa'      => $d['tipo_pessoa'] ?? 'Física',
        'rg'               => trim($d['rg'] ?? ''),
        'data_nascimento'  => trim($d['data_nascimento'] ?? ''),
        'estado_civil'     => trim($d['estado_civil'] ?? ''),
        'profissao'        => trim($d['profissao'] ?? ''),
        'telefone'         => trim($d['telefone'] ?? ''),
        'celular'          => trim($d['celular'] ?? ''),
        'whatsapp'         => trim($d['whatsapp'] ?? ''),
        'email'            => trim($d['email'] ?? ''),
        'email_secundario' => trim($d['email_secundario'] ?? ''),
        'cep'              => trim($d['cep'] ?? ''),
        'logradouro'       => trim($d['logradouro'] ?? ''),
        'numero'           => trim($d['numero'] ?? ''),
        'complemento'      => trim($d['complemento'] ?? ''),
        'bairro'           => trim($d['bairro'] ?? ''),
        'cidade'           => trim($d['cidade'] ?? ''),
        'estado'           => strtoupper(substr(trim($d['estado'] ?? ''), 0, 2)),
        'status'           => $d['status'] ?? 'Ativo',
        'indicacao'        => trim($d['indicacao'] ?? ''),
        'observacoes'      => trim($d['observacoes'] ?? ''),
    ];
}

function validarCliente(array $c): array {
    $erros = [];
    if ($c['nome'] === '') {
        $erros[] = 'O nome do cliente é obrigatório.';
    }
    if ($c['email'] !== '' && !filter_var($c['email'], FILTER_VALIDATE_EMAIL)) {
        $erros[] = 'O e-mail principal não é válido.';
    }
    if ($c['email_secundario'] !== '' && !filter_var($c['email_secundario'], FILTER_VALIDATE_EMAIL)) {
        $erros[] = 'O e-mail secundário não é válido.';
    }
    if (!in_array($c['tipo_pessoa'], ['Física', 'Jurídica'], true)) {
        $erros[] = 'Tipo de pessoa inválido.';
    }
    if (!in_array($c['status'], ['Ativo', 'Em análise', 'Inativo', 'Encerrado', 'Excluído'], true)) {
        $erros[] = 'Status inválido.';
    }
    $doc = apenasDigitos($c['cpf_cnpj']);
    if ($doc !== '' && !in_array(strlen($doc), [11, 14], true)) {
        $erros[] = 'CPF/CNPJ deve ter 11 ou 14 dígitos.';
    }
    return $erros;
}

function clienteExisteDocumento(mysqli $conn, string $cpfCnpj, ?string $ignorarId = null): bool {
    if (trim($cpfCnpj) === '') return false;
    $sql = 'SELECT id FROM clientes WHERE cpf_cnpj = ? AND deletado = 0';
    $params = [$cpfCnpj];
    $types = 's';
    if ($ignorarId !== null) {
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

function salvarCliente(mysqli $conn, string $id, array $c): bool {
    $sql = "INSERT INTO clientes (
        id, nome, cpf_cnpj, tipo_pessoa, rg, data_nascimento, estado_civil, profissao,
        telefone, celular, whatsapp, email, email_secundario, cep, logradouro, numero,
        complemento, bairro, cidade, estado, status, indicacao, observacoes, data_cadastro
    ) VALUES (?, ?, ?, ?, ?, NULLIF(?, ''), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURDATE())";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param(
        'sssssssssssssssssssssss',
        $id, $c['nome'], $c['cpf_cnpj'], $c['tipo_pessoa'], $c['rg'], $c['data_nascimento'],
        $c['estado_civil'], $c['profissao'], $c['telefone'], $c['celular'], $c['whatsapp'],
        $c['email'], $c['email_secundario'], $c['cep'], $c['logradouro'], $c['numero'],
        $c['complemento'], $c['bairro'], $c['cidade'], $c['estado'], $c['status'],
        $c['indicacao'], $c['observacoes']
    );
    return $stmt->execute();
}

function atualizarCliente(mysqli $conn, string $id, array $c): bool {
    $sql = "UPDATE clientes SET
        nome = ?, cpf_cnpj = NULLIF(?, ''), tipo_pessoa = ?, rg = ?, data_nascimento = NULLIF(?, ''),
        estado_civil = ?, profissao = ?, telefone = ?, celular = ?, whatsapp = ?, email = ?,
        email_secundario = ?, cep = ?, logradouro = ?, numero = ?, complemento = ?, bairro = ?,
        cidade = ?, estado = ?, status = ?, indicacao = ?, observacoes = ?
        WHERE id = ? AND deletado = 0";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param(
        'sssssssssssssssssssssss',
        $c['nome'], $c['cpf_cnpj'], $c['tipo_pessoa'], $c['rg'], $c['data_nascimento'],
        $c['estado_civil'], $c['profissao'], $c['telefone'], $c['celular'], $c['whatsapp'],
        $c['email'], $c['email_secundario'], $c['cep'], $c['logradouro'], $c['numero'],
        $c['complemento'], $c['bairro'], $c['cidade'], $c['estado'], $c['status'],
        $c['indicacao'], $c['observacoes'], $id
    );
    return $stmt->execute();
}


function buscarClienteAuditoria(mysqli $conn, string $id): ?array {
    $stmt = $conn->prepare('SELECT * FROM clientes WHERE id = ? LIMIT 1');
    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('s', $id);
    $stmt->execute();
    $res = $stmt->get_result();
    $cliente = ($res && $res->num_rows > 0) ? $res->fetch_assoc() : null;
    $stmt->close();

    return $cliente;
}

$cliente_editar = null;

// SALVAR / ATUALIZAR
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['salvar_cliente']) || isset($_POST['atualizar_cliente']))) {
    if (!validarTokenCsrf($_POST['csrf_token'] ?? null)) {
        $msg = '<div class="alert alert-danger">Sessão expirada ou formulário inválido. Recarregue a página e tente novamente.</div>';
        $acao = isset($_POST['atualizar_cliente']) ? 'editar' : 'novo';
    } else {
        $c = camposCliente($_POST);
        $erros = validarCliente($c);
        $idAtual = $_POST['id'] ?? null;

        if (clienteExisteDocumento($conn, $c['cpf_cnpj'], $idAtual)) {
            $erros[] = 'Já existe outro cliente cadastrado com este CPF/CNPJ.';
        }

        if ($erros) {
            $msg = '<div class="alert alert-danger"><strong>Confira os dados:</strong><br>' . implode('<br>', array_map('h', $erros)) . '</div>';
            $acao = isset($_POST['atualizar_cliente']) ? 'editar' : 'novo';
            $cliente_editar = $c;
            if ($idAtual) $cliente_editar['id'] = $idAtual;
        } elseif (isset($_POST['salvar_cliente'])) {
            $id = gerarIdCliente($conn);
            if (salvarCliente($conn, $id, $c)) {
                if (function_exists('sgl_registrar_log')) {
                    sgl_registrar_log(
                        $conn,
                        'Cliente incluído',
                        'clientes',
                        $id,
                        'Novo cliente cadastrado: ' . $c['nome'],
                        [
                            'tipo_acao' => 'INCLUSAO',
                            'modulo' => 'Clientes',
                            'origem' => 'Cadastro de clientes',
                            'resultado' => 'SUCESSO',
                            'nivel' => 'INFO',
                            'dados_novos' => buscarClienteAuditoria($conn, $id) ?? $c,
                        ]
                    );
                }

                $msg = "<div class='alert alert-success'>✅ Cliente <strong>" . h($id) . "</strong> cadastrado com sucesso.</div>";
                $acao = 'listar';
            } else {
                $msg = '<div class="alert alert-danger">Erro ao salvar cliente: ' . h($conn->error) . '</div>';
                $acao = 'novo';
                $cliente_editar = $c;
            }
        } else {
            $id = (string)($_POST['id'] ?? '');
            $dadosAnteriores = buscarClienteAuditoria($conn, $id);

            if (atualizarCliente($conn, $id, $c)) {
                if (function_exists('sgl_registrar_log')) {
                    sgl_registrar_log(
                        $conn,
                        'Cliente atualizado',
                        'clientes',
                        $id,
                        'Dados do cliente atualizados: ' . $c['nome'],
                        [
                            'tipo_acao' => 'EDICAO',
                            'modulo' => 'Clientes',
                            'origem' => 'Edição de clientes',
                            'resultado' => 'SUCESSO',
                            'nivel' => 'INFO',
                            'dados_anteriores' => $dadosAnteriores,
                            'dados_novos' => buscarClienteAuditoria($conn, $id) ?? $c,
                        ]
                    );
                }

                $msg = "<div class='alert alert-success'>✅ Cliente <strong>" . h($id) . "</strong> atualizado com sucesso.</div>";
                $acao = 'listar';
            } else {
                $msg = '<div class="alert alert-danger">Erro ao atualizar cliente: ' . h($conn->error) . '</div>';
                $acao = 'editar';
                $cliente_editar = $c;
                $cliente_editar['id'] = $id;
            }
        }
    }
}

// SOFT DELETE
if (isset($_GET['excluir'])) {
    if (!validarTokenCsrf($_GET['csrf_token'] ?? null)) {
        $msg = '<div class="alert alert-danger">Ação bloqueada por segurança. Tente novamente.</div>';
    } else {
        $id = (string)$_GET['excluir'];
        $dadosAnteriores = buscarClienteAuditoria($conn, $id);

        $stmt = $conn->prepare("UPDATE clientes SET deletado = 1, status = 'Excluído' WHERE id = ?");
        $stmt->bind_param('s', $id);
        $okExcluir = $stmt->execute();
        $linhasAfetadas = $stmt->affected_rows;
        $stmt->close();

        if ($okExcluir && $linhasAfetadas > 0) {
            if (function_exists('sgl_registrar_log')) {
                sgl_registrar_log(
                    $conn,
                    'Cliente movido para a lixeira',
                    'clientes',
                    $id,
                    'Exclusão lógica do cliente: ' . (string)($dadosAnteriores['nome'] ?? $id),
                    [
                        'tipo_acao' => 'EXCLUSAO',
                        'modulo' => 'Clientes',
                        'origem' => 'Lista de clientes',
                        'resultado' => 'SUCESSO',
                        'nivel' => 'AVISO',
                        'dados_anteriores' => $dadosAnteriores,
                        'dados_novos' => buscarClienteAuditoria($conn, $id),
                    ]
                );
            }

            $msg = "<div class='alert alert-warning'>🗑️ Cliente <strong>" . h($id) . "</strong> movido para a lixeira.</div>";
        } else {
            if (function_exists('sgl_registrar_log')) {
                sgl_registrar_log(
                    $conn,
                    'Falha ao mover cliente para a lixeira',
                    'clientes',
                    $id,
                    'O registro não foi alterado.',
                    [
                        'tipo_acao' => 'EXCLUSAO',
                        'modulo' => 'Clientes',
                        'origem' => 'Lista de clientes',
                        'resultado' => 'FALHA',
                        'nivel' => 'ERRO',
                        'dados_anteriores' => $dadosAnteriores,
                    ]
                );
            }

            $msg = "<div class='alert alert-danger'>Não foi possível mover o cliente para a lixeira.</div>";
        }
    }
    $acao = 'listar';
}

// CARREGAR EDIÇÃO
if ($acao === 'editar' && isset($_GET['id']) && $cliente_editar === null) {
    $id_editar = (string)$_GET['id'];
    $stmt = $conn->prepare('SELECT * FROM clientes WHERE id = ? AND deletado = 0 LIMIT 1');
    $stmt->bind_param('s', $id_editar);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res && $res->num_rows > 0) {
        $cliente_editar = $res->fetch_assoc();
    } else {
        $msg = '<div class="alert alert-danger">Cliente não encontrado.</div>';
        $acao = 'listar';
    }
}

$f = camposCliente($cliente_editar ?: []);
$csrf = gerarTokenCsrf();
?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
    <div>
        <h2 class="mb-1"><i class="bi bi-people"></i> Clientes</h2>
        <div class="text-muted small">Cadastro, pesquisa e gestão dos clientes do escritório.</div>
    </div>
    <a href="?mod=clientes&acao=novo" class="btn btn-primary"><i class="bi bi-plus-circle"></i> Novo Cliente</a>
</div>

<?= $msg ?>

<?php if ($acao === 'novo' || $acao === 'editar'): ?>
<div class="card mb-4">
    <div class="card-header <?= $acao === 'editar' ? 'bg-warning text-dark' : 'bg-primary text-white' ?>">
        <?= $acao === 'editar' ? '✏️ Editar Cliente — ' . h($cliente_editar['id'] ?? '') : 'Cadastrar Novo Cliente' ?>
    </div>
    <div class="card-body">
        <form method="POST" autocomplete="off" id="formCliente">
            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
            <?php if ($acao === 'editar'): ?>
                <input type="hidden" name="id" value="<?= h($cliente_editar['id'] ?? '') ?>">
            <?php endif; ?>

            <h6 class="fw-bold text-muted mb-3">📋 Dados Pessoais</h6>
            <div class="row g-3 mb-4">
                <div class="col-md-5">
                    <label class="form-label">Nome Completo *</label>
                    <input type="text" name="nome" class="form-control" value="<?= h($f['nome']) ?>" required maxlength="120">
                </div>
                <div class="col-md-3">
                    <label class="form-label">CPF / CNPJ</label>
                    <input type="text" name="cpf_cnpj" id="cpf_cnpj" class="form-control" value="<?= h($f['cpf_cnpj']) ?>" maxlength="18">
                    <div class="form-text">Aceita CPF ou CNPJ.</div>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Tipo Pessoa</label>
                    <select name="tipo_pessoa" class="form-select">
                        <option value="Física" <?= $f['tipo_pessoa'] === 'Física' ? 'selected' : '' ?>>Física</option>
                        <option value="Jurídica" <?= $f['tipo_pessoa'] === 'Jurídica' ? 'selected' : '' ?>>Jurídica</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Status</label>
                    <select name="status" class="form-select">
                        <?php foreach (['Ativo', 'Em análise', 'Inativo', 'Encerrado'] as $status): ?>
                            <option value="<?= h($status) ?>" <?= $f['status'] === $status ? 'selected' : '' ?>><?= h($status) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">RG</label>
                    <input type="text" name="rg" class="form-control" value="<?= h($f['rg']) ?>" maxlength="30">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Data Nascimento</label>
                    <input type="date" name="data_nascimento" class="form-control" value="<?= h($f['data_nascimento']) ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Estado Civil</label>
                    <input type="text" name="estado_civil" class="form-control" value="<?= h($f['estado_civil']) ?>" maxlength="40">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Profissão</label>
                    <input type="text" name="profissao" class="form-control" value="<?= h($f['profissao']) ?>" maxlength="80">
                </div>
            </div>

            <h6 class="fw-bold text-muted mb-3"><i class="bi bi-telephone"></i> Contato</h6>
            <div class="row g-3 mb-4">
                <div class="col-md-4"><label class="form-label">Telefone</label><input type="text" name="telefone" class="form-control telefone" value="<?= h($f['telefone']) ?>"></div>
                <div class="col-md-4"><label class="form-label">Celular</label><input type="text" name="celular" class="form-control telefone" value="<?= h($f['celular']) ?>"></div>
                <div class="col-md-4"><label class="form-label">WhatsApp</label><input type="text" name="whatsapp" class="form-control telefone" value="<?= h($f['whatsapp']) ?>"></div>
                <div class="col-md-6"><label class="form-label">E-mail</label><input type="email" name="email" class="form-control" value="<?= h($f['email']) ?>" maxlength="120"></div>
                <div class="col-md-6"><label class="form-label">E-mail Secundário</label><input type="email" name="email_secundario" class="form-control" value="<?= h($f['email_secundario']) ?>" maxlength="120"></div>
            </div>

            <h6 class="fw-bold text-muted mb-3"><i class="bi bi-geo-alt"></i> Endereço</h6>
            <div class="row g-3 mb-4">
                <div class="col-md-3"><label class="form-label">CEP</label><input type="text" name="cep" class="form-control" id="cep" value="<?= h($f['cep']) ?>" maxlength="9"></div>
                <div class="col-md-6"><label class="form-label">Logradouro</label><input type="text" name="logradouro" id="logradouro" class="form-control" value="<?= h($f['logradouro']) ?>" maxlength="150"></div>
                <div class="col-md-3"><label class="form-label">Número</label><input type="text" name="numero" class="form-control" value="<?= h($f['numero']) ?>" maxlength="20"></div>
                <div class="col-md-4"><label class="form-label">Complemento</label><input type="text" name="complemento" class="form-control" value="<?= h($f['complemento']) ?>" maxlength="80"></div>
                <div class="col-md-3"><label class="form-label">Bairro</label><input type="text" name="bairro" id="bairro" class="form-control" value="<?= h($f['bairro']) ?>" maxlength="80"></div>
                <div class="col-md-4"><label class="form-label">Cidade</label><input type="text" name="cidade" id="cidade" class="form-control" value="<?= h($f['cidade']) ?>" maxlength="80"></div>
                <div class="col-md-1"><label class="form-label">UF</label><input type="text" name="estado" id="estado" class="form-control text-uppercase" value="<?= h($f['estado']) ?>" maxlength="2"></div>
            </div>

            <div class="row g-3 mb-4">
                <div class="col-md-6">
                    <label class="form-label">Indicação / Origem</label>
                    <input type="text" name="indicacao" class="form-control" value="<?= h($f['indicacao']) ?>" maxlength="120" placeholder="Ex.: Instagram, indicação de cliente, Google...">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Observações</label>
                    <textarea name="observacoes" class="form-control" rows="2"><?= h($f['observacoes']) ?></textarea>
                </div>
            </div>

            <div class="d-flex gap-2 mt-4">
                <button type="submit" name="<?= $acao === 'editar' ? 'atualizar_cliente' : 'salvar_cliente' ?>" class="btn <?= $acao === 'editar' ? 'btn-warning' : 'btn-success' ?>">
                    <i class="bi bi-save"></i> <?= $acao === 'editar' ? 'Salvar Alterações' : 'Salvar Cliente' ?>
                </button>
                <a href="?mod=clientes" class="btn btn-secondary">Cancelar</a>
            </div>
        </form>
    </div>
</div>

<?php else: ?>
<?php
$busca = trim($_GET['busca'] ?? '');
$statusFiltro = trim($_GET['status'] ?? '');
$cidadeFiltro = trim($_GET['cidade'] ?? '');
$pagina = max(1, (int)($_GET['pagina'] ?? 1));
$porPagina = 15;
$offset = ($pagina - 1) * $porPagina;

$condicoes = ['deletado = 0'];
$params = [];
$types = '';

if ($busca !== '') {
    $condicoes[] = '(id LIKE ? OR nome LIKE ? OR cpf_cnpj LIKE ? OR telefone LIKE ? OR celular LIKE ? OR whatsapp LIKE ? OR email LIKE ?)';
    $like = '%' . $busca . '%';
    for ($i = 0; $i < 7; $i++) { $params[] = $like; $types .= 's'; }
}
if ($statusFiltro !== '') {
    $condicoes[] = 'status = ?';
    $params[] = $statusFiltro;
    $types .= 's';
}
if ($cidadeFiltro !== '') {
    $condicoes[] = 'cidade LIKE ?';
    $params[] = '%' . $cidadeFiltro . '%';
    $types .= 's';
}
$where = 'WHERE ' . implode(' AND ', $condicoes);

$stmtTotal = $conn->prepare("SELECT COUNT(*) AS total FROM clientes $where");
if ($params) $stmtTotal->bind_param($types, ...$params);
$stmtTotal->execute();
$totalRegistros = (int)($stmtTotal->get_result()->fetch_assoc()['total'] ?? 0);
$totalPaginas = max(1, (int)ceil($totalRegistros / $porPagina));

$stmtLista = $conn->prepare("SELECT id, nome, cpf_cnpj, telefone, celular, whatsapp, email, cidade, estado, status, criado_em
                             FROM clientes $where ORDER BY criado_em DESC, id DESC LIMIT ? OFFSET ?");
$paramsLista = $params;
$typesLista = $types . 'ii';
$paramsLista[] = $porPagina;
$paramsLista[] = $offset;
$stmtLista->bind_param($typesLista, ...$paramsLista);
$stmtLista->execute();
$lista = $stmtLista->get_result();

$resumo = $conn->query("SELECT
    COUNT(*) AS total,
    SUM(CASE WHEN status = 'Ativo' THEN 1 ELSE 0 END) AS ativos,
    SUM(CASE WHEN MONTH(criado_em) = MONTH(CURDATE()) AND YEAR(criado_em) = YEAR(CURDATE()) THEN 1 ELSE 0 END) AS novos_mes
    FROM clientes WHERE deletado = 0")->fetch_assoc();

$queryBase = http_build_query(['mod' => 'clientes', 'busca' => $busca, 'status' => $statusFiltro, 'cidade' => $cidadeFiltro]);
?>

<div class="row g-3 mb-4">
    <div class="col-md-4"><div class="card"><div class="card-body"><div class="text-muted small">TOTAL DE CLIENTES</div><div class="fs-4 fw-bold"><?= (int)($resumo['total'] ?? 0) ?></div></div></div></div>
    <div class="col-md-4"><div class="card"><div class="card-body"><div class="text-muted small">CLIENTES ATIVOS</div><div class="fs-4 fw-bold text-success"><?= (int)($resumo['ativos'] ?? 0) ?></div></div></div></div>
    <div class="col-md-4"><div class="card"><div class="card-body"><div class="text-muted small">NOVOS NO MÊS</div><div class="fs-4 fw-bold text-primary"><?= (int)($resumo['novos_mes'] ?? 0) ?></div></div></div></div>
</div>

<div class="card mb-3">
    <div class="card-body">
        <form class="row g-2 align-items-end" method="GET">
            <input type="hidden" name="mod" value="clientes">
            <div class="col-md-5">
                <label class="form-label">Pesquisa inteligente</label>
                <input type="text" name="busca" id="buscaCliente" class="form-control" placeholder="Nome, ID, CPF/CNPJ, telefone, WhatsApp ou e-mail" value="<?= h($busca) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label">Status</label>
                <select name="status" class="form-select">
                    <option value="">Todos</option>
                    <?php foreach (['Ativo', 'Em análise', 'Inativo', 'Encerrado'] as $st): ?>
                        <option value="<?= h($st) ?>" <?= $statusFiltro === $st ? 'selected' : '' ?>><?= h($st) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Cidade</label>
                <input type="text" name="cidade" class="form-control" value="<?= h($cidadeFiltro) ?>" placeholder="Filtrar por cidade">
            </div>
            <div class="col-md-2 d-flex gap-2">
                <button class="btn btn-outline-primary w-100" type="submit"><i class="bi bi-search"></i> Buscar</button>
                <a href="?mod=clientes" class="btn btn-outline-secondary" title="Limpar filtros"><i class="bi bi-x-lg"></i></a>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center">
        <span><i class="bi bi-list-ul"></i> Lista de Clientes</span>
        <small><?= $totalRegistros ?> registro(s) encontrado(s)</small>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover table-striped mb-0" id="tabelaClientes">
                <thead class="table-light">
                    <tr>
                        <th>ID</th><th>Nome</th><th>CPF/CNPJ</th><th>Contato</th><th>E-mail</th><th>Cidade/UF</th><th>Status</th><th class="text-end">Ações</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($lista && $lista->num_rows > 0): ?>
                    <?php while ($row = $lista->fetch_assoc()): ?>
                    <?php
                        $badge = match ($row['status']) {
                            'Ativo' => 'success',
                            'Em análise' => 'warning text-dark',
                            'Inativo' => 'secondary',
                            'Encerrado' => 'dark',
                            default => 'light text-dark'
                        };
                    ?>
                    <tr>
                        <td><span class="badge bg-light text-dark border"><?= h($row['id']) ?></span></td>
                        <td><strong><?= h($row['nome']) ?></strong><br><small class="text-muted">Desde <?= date('d/m/Y', strtotime($row['criado_em'])) ?></small></td>
                        <td><?= h($row['cpf_cnpj'] ?: '-') ?></td>
                        <td>
                            <?= h($row['celular'] ?: ($row['telefone'] ?: '-')) ?>
                            <?php if (!empty($row['whatsapp'])): ?><br><small class="text-success"><i class="bi bi-whatsapp"></i> <?= h($row['whatsapp']) ?></small><?php endif; ?>
                        </td>
                        <td><?= h($row['email'] ?: '-') ?></td>
                        <td><?= h(trim(($row['cidade'] ?: '') . '/' . ($row['estado'] ?: ''), '/')) ?: '-' ?></td>
                        <td><span class="badge bg-<?= $badge ?>"><?= h($row['status']) ?></span></td>
                        <td class="text-end text-nowrap">
                            <a href="?mod=clientes&acao=editar&id=<?= urlencode($row['id']) ?>" class="btn btn-sm btn-warning"><i class="bi bi-pencil"></i></a>
                            <a href="?mod=clientes&excluir=<?= urlencode($row['id']) ?>&csrf_token=<?= h($csrf) ?>" class="btn btn-sm btn-danger" onclick="return confirm('Deseja mover este cliente para a lixeira?')"><i class="bi bi-trash"></i></a>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr><td colspan="8" class="text-center text-muted py-4">Nenhum cliente encontrado.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php if ($totalPaginas > 1): ?>
    <div class="card-footer d-flex justify-content-between align-items-center">
        <span class="small text-muted">Página <?= $pagina ?> de <?= $totalPaginas ?></span>
        <nav>
            <ul class="pagination pagination-sm mb-0">
                <li class="page-item <?= $pagina <= 1 ? 'disabled' : '' ?>"><a class="page-link" href="?<?= h($queryBase) ?>&pagina=<?= max(1, $pagina - 1) ?>">Anterior</a></li>
                <li class="page-item <?= $pagina >= $totalPaginas ? 'disabled' : '' ?>"><a class="page-link" href="?<?= h($queryBase) ?>&pagina=<?= min($totalPaginas, $pagina + 1) ?>">Próxima</a></li>
            </ul>
        </nav>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const maskPhone = function(input) {
        let v = input.value.replace(/\D/g, '').substring(0, 11);
        if (v.length > 10) v = v.replace(/^(\d{2})(\d{5})(\d{4})$/, '($1) $2-$3');
        else if (v.length > 6) v = v.replace(/^(\d{2})(\d{4})(\d{0,4})$/, '($1) $2-$3');
        else if (v.length > 2) v = v.replace(/^(\d{2})(\d{0,5})$/, '($1) $2');
        else if (v.length > 0) v = v.replace(/^(\d{0,2})$/, '($1');
        input.value = v;
    };
    document.querySelectorAll('.telefone').forEach(input => {
        input.setAttribute('inputmode', 'numeric');
        input.addEventListener('input', () => maskPhone(input));
    });

    const doc = document.getElementById('cpf_cnpj');
    if (doc) {
        doc.addEventListener('input', function () {
            let v = this.value.replace(/\D/g, '').substring(0, 14);
            if (v.length <= 11) {
                v = v.replace(/(\d{3})(\d)/, '$1.$2').replace(/(\d{3})(\d)/, '$1.$2').replace(/(\d{3})(\d{1,2})$/, '$1-$2');
            } else {
                v = v.replace(/^(\d{2})(\d)/, '$1.$2').replace(/^(\d{2})\.(\d{3})(\d)/, '$1.$2.$3').replace(/\.(\d{3})(\d)/, '.$1/$2').replace(/(\d{4})(\d)/, '$1-$2');
            }
            this.value = v;
        });
    }

    const cep = document.getElementById('cep');
    if (cep) {
        cep.addEventListener('input', function () {
            let v = this.value.replace(/\D/g, '').substring(0, 8);
            if (v.length > 5) v = v.replace(/^(\d{5})(\d{0,3})$/, '$1-$2');
            this.value = v;
        });
        cep.addEventListener('blur', function () {
            const cleanCep = this.value.replace(/\D/g, '');
            if (cleanCep.length !== 8) return;
            fetch(`https://viacep.com.br/ws/${cleanCep}/json/`)
                .then(res => res.json())
                .then(dados => {
                    if (!dados.erro) {
                        document.getElementById('logradouro').value = dados.logradouro || '';
                        document.getElementById('bairro').value = dados.bairro || '';
                        document.getElementById('cidade').value = dados.localidade || '';
                        document.getElementById('estado').value = dados.uf || '';
                    }
                })
                .catch(() => {});
        });
    }

    const busca = document.getElementById('buscaCliente');
    const tabela = document.getElementById('tabelaClientes');
    if (busca && tabela) {
        busca.addEventListener('input', function () {
            const termo = this.value.toLowerCase();
            tabela.querySelectorAll('tbody tr').forEach(tr => {
                tr.style.display = tr.textContent.toLowerCase().includes(termo) ? '' : 'none';
            });
        });
    }
});
</script>

<?php $conn->close(); ?>