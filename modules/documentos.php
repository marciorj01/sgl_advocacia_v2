<?php
/**
 * Fase 3.4 — Documentos e Arquivos
 * Upload seguro de documentos vinculados a cliente/processo.
 */

$conn = conectar();
require_once __DIR__ . '/../config/integracoes.php';

if (!function_exists('rojexContextoTenantValido') || !rojexContextoTenantValido()) {
    $conn->close();
    throw new RuntimeException('Contexto Multi-Tenant inválido para o módulo Documentos.');
}

$tenantId = function_exists('rojexTenantId')
    ? trim((string)rojexTenantId())
    : trim((string)($_SESSION['tenant_id'] ?? ''));

$escritorioId = function_exists('rojexEscritorioId')
    ? (int)rojexEscritorioId()
    : (int)($_SESSION['escritorio_id'] ?? 0);

if ($tenantId === '' || $escritorioId <= 0) {
    $conn->close();
    throw new RuntimeException('Tenant ou escritório não identificado para o módulo Documentos.');
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
if (empty($_SESSION['csrf_documentos'])) {
    $_SESSION['csrf_documentos'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_documentos'];

function sgl_doc_tenant_id(): string {
    return function_exists('rojexTenantId')
        ? trim((string)rojexTenantId())
        : trim((string)($_SESSION['tenant_id'] ?? ''));
}

function sgl_doc_escritorio_id(): int {
    return function_exists('rojexEscritorioId')
        ? (int)rojexEscritorioId()
        : (int)($_SESSION['escritorio_id'] ?? 0);
}

/*
 * A estrutura de documentos_arquivos é mantida por migração SQL.
 * DDL e backfills não são executados durante requisições HTTP.
 * Migração: migrations/20260718_ra06_documentos_runtime_ddl.sql
 */

function sgl_doc_novo_codigo(mysqli $conn): string {
    $ano = date('Y');
    $prefixo = 'DOC-' . $ano . '-';
    $stmt = $conn->prepare("SELECT codigo FROM documentos_arquivos WHERE codigo LIKE CONCAT(?, '%') ORDER BY id DESC LIMIT 1");
    $stmt->bind_param('s', $prefixo);
    $stmt->execute();
    $res = $stmt->get_result();
    $seq = 1;
    if ($row = $res->fetch_assoc()) {
        $seq = ((int)substr($row['codigo'], -5)) + 1;
    }
    $stmt->close();
    return $prefixo . str_pad((string)$seq, 5, '0', STR_PAD_LEFT);
}

function sgl_doc_formatar_tamanho(int $bytes): string {
    if ($bytes >= 1048576) return number_format($bytes / 1048576, 2, ',', '.') . ' MB';
    if ($bytes >= 1024) return number_format($bytes / 1024, 2, ',', '.') . ' KB';
    return $bytes . ' B';
}

function sgl_doc_ext_permitidas(): array {
    return ['pdf','jpg','jpeg','png','webp','doc','docx','xls','xlsx','txt','odt','rtf'];
}

function sgl_doc_categoria_lista(): array {
    return ['Documento pessoal','Procuração','Contrato','Petição','Prova','Audiência','Financeiro','Recibo','Documento do processo','Outros'];
}


function sgl_doc_buscar_auditoria(mysqli $conn, int $id): ?array {
    $stmt = $conn->prepare(
        "SELECT * FROM documentos_arquivos
         WHERE tenant_id = ? AND escritorio_id = ? AND id = ?
         LIMIT 1"
    );
    if (!$stmt) {
        return null;
    }

    $tenant = sgl_doc_tenant_id();
    $escritorio = sgl_doc_escritorio_id();
    $stmt->bind_param('sii', $tenant, $escritorio, $id);
    $stmt->execute();
    $res = $stmt->get_result();
    $doc = ($res && $res->num_rows > 0) ? $res->fetch_assoc() : null;
    $stmt->close();

    return $doc;
}

function sgl_doc_registrar_log(
    mysqli $conn,
    string $acao,
    ?string $registroId,
    string $detalhes,
    array $contexto = []
): void {
    if (!function_exists('sgl_registrar_log')) {
        return;
    }

    sgl_registrar_log(
        $conn,
        $acao,
        'documentos_arquivos',
        $registroId,
        $detalhes,
        array_merge(
            [
                'modulo' => 'Documentos',
                'origem' => 'Módulo Documentos',
                'resultado' => 'SUCESSO',
                'nivel' => 'INFO',
            ],
            $contexto
        )
    );
}

$mensagem = null;
$erro = null;

// Visualização/download agora é feita pelo endpoint limpo: documento_arquivo.php

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf'] ?? '';
    if (!hash_equals($csrf, $token)) {
        $erro = 'Falha de segurança. Atualize a página e tente novamente.';
    } else {
        $acao = $_POST['acao'] ?? '';
        if ($acao === 'excluir') {
            $id = (int)($_POST['id'] ?? 0);
            $dadosAnteriores = $id > 0 ? sgl_doc_buscar_auditoria($conn, $id) : null;

            $stmt = $conn->prepare(
                "UPDATE documentos_arquivos
                 SET deletado = 1, status = 'Excluído'
                 WHERE tenant_id = ? AND escritorio_id = ? AND id = ? AND deletado = 0"
            );
            $stmt->bind_param('sii', $tenantId, $escritorioId, $id);
            $ok = $stmt->execute();
            $afetadas = $stmt->affected_rows;
            $stmt->close();

            if ($ok && $afetadas > 0) {
                sgl_doc_registrar_log(
                    $conn,
                    'Documento movido para a lixeira',
                    (string)$id,
                    'Exclusão lógica do documento.',
                    [
                        'tipo_acao' => 'EXCLUSAO',
                        'origem' => 'Lista de documentos',
                        'nivel' => 'AVISO',
                        'dados_anteriores' => $dadosAnteriores,
                        'dados_novos' => sgl_doc_buscar_auditoria($conn, $id),
                    ]
                );
                $mensagem = 'Documento movido para a lixeira.';
            } else {
                sgl_doc_registrar_log(
                    $conn,
                    'Falha ao mover documento para a lixeira',
                    $id > 0 ? (string)$id : null,
                    'O registro não foi alterado.',
                    [
                        'tipo_acao' => 'EXCLUSAO',
                        'origem' => 'Lista de documentos',
                        'resultado' => 'FALHA',
                        'nivel' => 'ERRO',
                        'dados_anteriores' => $dadosAnteriores,
                    ]
                );
                $erro = 'Não foi possível mover o documento para a lixeira.';
            }
        }
        if ($acao === 'upload') {
            $titulo = trim($_POST['titulo'] ?? '');
            $categoria = trim($_POST['categoria'] ?? 'Outros');
            $cliente_id = trim($_POST['cliente_id'] ?? '');
            $processo_id = trim($_POST['processo_id'] ?? '');
            $descricao = trim($_POST['descricao'] ?? '');
            if ($titulo === '') {
                $erro = 'Informe um título para o documento.';
                sgl_doc_registrar_log(
                    $conn,
                    'Falha no upload de documento',
                    null,
                    'Upload recusado: título não informado.',
                    [
                        'tipo_acao' => 'INCLUSAO',
                        'origem' => 'Upload de documentos',
                        'resultado' => 'NEGADO',
                        'nivel' => 'AVISO',
                    ]
                );
            }
            if (empty($_FILES['arquivo']) || $_FILES['arquivo']['error'] !== UPLOAD_ERR_OK) {
                $erro = $erro ?: 'Selecione um arquivo válido.';
                sgl_doc_registrar_log(
                    $conn,
                    'Falha no upload de documento',
                    null,
                    'Upload recusado: arquivo ausente ou inválido.',
                    [
                        'tipo_acao' => 'INCLUSAO',
                        'origem' => 'Upload de documentos',
                        'resultado' => 'NEGADO',
                        'nivel' => 'AVISO',
                    ]
                );
            }
            if (!$erro) {
                $file = $_FILES['arquivo'];
                $max = 15 * 1024 * 1024; // 15 MB
                if ($file['size'] > $max) {
                    $erro = 'Arquivo muito grande. Limite atual: 15 MB.';
                    sgl_doc_registrar_log(
                        $conn,
                        'Falha no upload de documento',
                        null,
                        'Upload recusado: arquivo acima de 15 MB.',
                        [
                            'tipo_acao' => 'INCLUSAO',
                            'origem' => 'Upload de documentos',
                            'resultado' => 'NEGADO',
                            'nivel' => 'AVISO',
                            'dados_novos' => [
                                'nome_original' => (string)($file['name'] ?? ''),
                                'tamanho_bytes' => (int)($file['size'] ?? 0),
                            ],
                        ]
                    );
                } else {
                    $original = $file['name'];
                    $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
                    if (!in_array($ext, sgl_doc_ext_permitidas(), true)) {
                        $erro = 'Tipo de arquivo não permitido.';
                        sgl_doc_registrar_log(
                            $conn,
                            'Falha no upload de documento',
                            null,
                            'Upload recusado: extensão não permitida.',
                            [
                                'tipo_acao' => 'INCLUSAO',
                                'origem' => 'Upload de documentos',
                                'resultado' => 'NEGADO',
                                'nivel' => 'AVISO',
                                'dados_novos' => [
                                    'nome_original' => $original,
                                    'extensao' => $ext,
                                ],
                            ]
                        );
                    } else {
                        $finfo = new finfo(FILEINFO_MIME_TYPE);
                        $mime = $finfo->file($file['tmp_name']) ?: 'application/octet-stream';
                        $tenantPasta = substr(hash('sha256', $tenantId . '|' . $escritorioId), 0, 24);
                        $dirRel = 'uploads/documentos/' . $tenantPasta . '/' . date('Y') . '/' . date('m');
                        $dirAbs = __DIR__ . '/../' . $dirRel;
                        if (!is_dir($dirAbs)) mkdir($dirAbs, 0775, true);
                        $safeName = bin2hex(random_bytes(16)) . '.' . $ext;
                        $destAbs = $dirAbs . '/' . $safeName;
                        $destRel = $dirRel . '/' . $safeName;
                        if (!move_uploaded_file($file['tmp_name'], $destAbs)) {
                            $erro = 'Não foi possível salvar o arquivo.';
                            sgl_doc_registrar_log(
                                $conn,
                                'Falha no upload de documento',
                                null,
                                'Falha ao mover o arquivo para o diretório definitivo.',
                                [
                                    'tipo_acao' => 'INCLUSAO',
                                    'origem' => 'Upload de documentos',
                                    'resultado' => 'FALHA',
                                    'nivel' => 'ERRO',
                                    'dados_novos' => [
                                        'nome_original' => $original,
                                        'extensao' => $ext,
                                        'mime_type' => $mime,
                                    ],
                                ]
                            );
                        } else {
                            $codigo = sgl_doc_novo_codigo($conn);
                            $hash = hash_file('sha256', $destAbs);
                            $numero_processo = null;

                            if ($cliente_id !== '') {
                                $stmtCli = $conn->prepare(
                                    "SELECT id FROM clientes
                                     WHERE tenant_id = ? AND escritorio_id = ? AND id = ?
                                       AND COALESCE(deletado,0) = 0
                                     LIMIT 1"
                                );
                                $stmtCli->bind_param('sis', $tenantId, $escritorioId, $cliente_id);
                                $stmtCli->execute();
                                $clienteValido = $stmtCli->get_result()->fetch_assoc();
                                $stmtCli->close();

                                if (!$clienteValido) {
                                    @unlink($destAbs);
                                    $erro = 'O cliente selecionado não pertence a este escritório.';
                                }
                            }

                            if (!$erro && $processo_id !== '') {
                                $stmtP = $conn->prepare(
                                    "SELECT numero_processo, cliente_id
                                     FROM processos
                                     WHERE tenant_id = ? AND escritorio_id = ? AND id = ?
                                     LIMIT 1"
                                );
                                $stmtP->bind_param('sis', $tenantId, $escritorioId, $processo_id);
                                $stmtP->execute();
                                $processoValido = $stmtP->get_result()->fetch_assoc();
                                $stmtP->close();

                                if (!$processoValido) {
                                    @unlink($destAbs);
                                    $erro = 'O processo selecionado não pertence a este escritório.';
                                } else {
                                    $numero_processo = $processoValido['numero_processo'] ?? null;
                                    $clienteDoProcesso = trim((string)($processoValido['cliente_id'] ?? ''));
                                    if ($cliente_id !== '' && $clienteDoProcesso !== '' && $clienteDoProcesso !== $cliente_id) {
                                        @unlink($destAbs);
                                        $erro = 'O processo selecionado não pertence ao cliente informado.';
                                    }
                                }
                            }

                            if ($erro) {
                                sgl_doc_registrar_log(
                                    $conn,
                                    'Falha no upload de documento',
                                    null,
                                    $erro,
                                    [
                                        'tipo_acao' => 'INCLUSAO',
                                        'origem' => 'Upload de documentos',
                                        'resultado' => 'NEGADO',
                                        'nivel' => 'AVISO',
                                        'dados_novos' => [
                                            'cliente_id' => $cliente_id ?: null,
                                            'processo_id' => $processo_id ?: null,
                                        ],
                                    ]
                                );
                            } else {
                            $uid = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
                            $unome = $_SESSION['nome'] ?? $_SESSION['username'] ?? 'Usuário';
                            $cliente_id_db = $cliente_id !== '' ? $cliente_id : null;
                            $processo_id_db = $processo_id !== '' ? $processo_id : null;
                            $stmt = $conn->prepare(
                                "INSERT INTO documentos_arquivos
                                 (tenant_id, escritorio_id, codigo, titulo, categoria, cliente_id, processo_id,
                                  numero_processo, descricao, nome_original, nome_arquivo, caminho, extensao,
                                  mime_type, tamanho_bytes, hash_arquivo, usuario_id, usuario_nome)
                                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
                            );
                            $tamanho = (int)$file['size'];
                            $stmt->bind_param(
                                'sissssssssssssisis',
                                $tenantId,
                                $escritorioId,
                                $codigo,
                                $titulo,
                                $categoria,
                                $cliente_id_db,
                                $processo_id_db,
                                $numero_processo,
                                $descricao,
                                $original,
                                $safeName,
                                $destRel,
                                $ext,
                                $mime,
                                $tamanho,
                                $hash,
                                $uid,
                                $unome
                            );
                            $okInsert = $stmt->execute();
                            $novoId = $stmt->insert_id;
                            $stmt->close();

                            if ($okInsert && $novoId > 0) {
                                sgl_doc_registrar_log(
                                    $conn,
                                    'Documento incluído',
                                    (string)$novoId,
                                    "Upload seguro {$codigo}: {$original}",
                                    [
                                        'tipo_acao' => 'INCLUSAO',
                                        'origem' => 'Upload de documentos',
                                        'dados_novos' => sgl_doc_buscar_auditoria($conn, $novoId),
                                    ]
                                );
                                $mensagem = 'Documento enviado com segurança e vinculado ao cadastro selecionado.';
                            } else {
                                @unlink($destAbs);
                                sgl_doc_registrar_log(
                                    $conn,
                                    'Falha no upload de documento',
                                    null,
                                    'Arquivo físico removido porque o registro não foi salvo no banco.',
                                    [
                                        'tipo_acao' => 'INCLUSAO',
                                        'origem' => 'Upload de documentos',
                                        'resultado' => 'FALHA',
                                        'nivel' => 'ERRO',
                                        'dados_novos' => [
                                            'codigo' => $codigo,
                                            'titulo' => $titulo,
                                            'categoria' => $categoria,
                                            'cliente_id' => $cliente_id_db,
                                            'processo_id' => $processo_id_db,
                                            'nome_original' => $original,
                                            'extensao' => $ext,
                                            'mime_type' => $mime,
                                            'tamanho_bytes' => $tamanho,
                                            'hash_arquivo' => $hash,
                                        ],
                                    ]
                                );
                                $erro = 'Não foi possível registrar o documento no banco de dados.';
                            }
                            }
                        }
                    }
                }
            }
        }
    }
}

$clientes = [];
$stmtCliLista = $conn->prepare(
    "SELECT id, nome FROM clientes
     WHERE tenant_id = ? AND escritorio_id = ? AND COALESCE(deletado,0)=0
     ORDER BY nome LIMIT 500"
);
$stmtCliLista->bind_param('si', $tenantId, $escritorioId);
$stmtCliLista->execute();
$res = $stmtCliLista->get_result();
while ($r = $res->fetch_assoc()) $clientes[] = $r;
$stmtCliLista->close();

$processos = [];
$sqlProc = "SELECT p.id, p.numero_processo, p.cliente_id, COALESCE(c.nome,'') AS cliente_nome
            FROM processos p
            LEFT JOIN clientes c
              ON c.id = p.cliente_id
             AND c.tenant_id = p.tenant_id
             AND c.escritorio_id = p.escritorio_id
            WHERE p.tenant_id = ? AND p.escritorio_id = ?
            ORDER BY p.numero_processo LIMIT 500";
$stmtProcLista = $conn->prepare($sqlProc);
$stmtProcLista->bind_param('si', $tenantId, $escritorioId);
$stmtProcLista->execute();
$res = $stmtProcLista->get_result();
while ($r = $res->fetch_assoc()) $processos[] = $r;
$stmtProcLista->close();

$q = trim($_GET['q'] ?? '');
$fcat = trim($_GET['categoria'] ?? '');
$fcli = trim($_GET['cliente_id'] ?? '');
$fproc = trim($_GET['processo_id'] ?? '');
$where = ['d.tenant_id = ?', 'd.escritorio_id = ?', 'd.deletado = 0'];
$params = [$tenantId, $escritorioId];
$types = 'si';
if ($q !== '') { $like = '%' . $q . '%'; $where[] = '(d.codigo LIKE ? OR d.titulo LIKE ? OR d.nome_original LIKE ? OR d.descricao LIKE ? OR d.numero_processo LIKE ?)'; array_push($params,$like,$like,$like,$like,$like); $types .= 'sssss'; }
if ($fcat !== '') { $where[] = 'd.categoria = ?'; $params[]=$fcat; $types.='s'; }
if ($fcli !== '') { $where[] = 'd.cliente_id = ?'; $params[]=$fcli; $types.='s'; }
if ($fproc !== '') { $where[] = 'd.processo_id = ?'; $params[]=$fproc; $types.='s'; }
$sql = "SELECT d.*, COALESCE(c.nome,'-') AS cliente_nome
        FROM documentos_arquivos d
        LEFT JOIN clientes c
          ON c.id = d.cliente_id
         AND c.tenant_id = d.tenant_id
         AND c.escritorio_id = d.escritorio_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY d.criado_em DESC LIMIT 200";
$stmt = $conn->prepare($sql);
if ($params) $stmt->bind_param($types, ...$params);
$stmt->execute();
$documentos = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$stmtStats = $conn->prepare(
    "SELECT
        COUNT(*) AS total_docs,
        COALESCE(SUM(CASE WHEN YEAR(criado_em)=YEAR(CURDATE()) AND MONTH(criado_em)=MONTH(CURDATE()) THEN 1 ELSE 0 END),0) AS total_mes,
        COALESCE(SUM(CASE WHEN categoria='Prova' THEN 1 ELSE 0 END),0) AS total_provas,
        COALESCE(SUM(tamanho_bytes),0) AS armazenamento
     FROM documentos_arquivos
     WHERE tenant_id = ? AND escritorio_id = ? AND deletado = 0"
);
$stmtStats->bind_param('si', $tenantId, $escritorioId);
$stmtStats->execute();
$rowStats = $stmtStats->get_result()->fetch_assoc() ?: [];
$stmtStats->close();

$totalDocs = (int)($rowStats['total_docs'] ?? 0);
$totalMes = (int)($rowStats['total_mes'] ?? 0);
$totalProvas = (int)($rowStats['total_provas'] ?? 0);
$armazenamento = (int)($rowStats['armazenamento'] ?? 0);
?>

<div class="d-flex justify-content-between align-items-start mb-4">
    <div>
        <h2 class="fw-bold text-primary mb-1"><i class="bi bi-file-earmark-arrow-up"></i> Documentos e Arquivos</h2>
        <p class="text-muted mb-0">Guarde documentos pessoais, provas, contratos, procurações e arquivos vinculados a clientes/processos.</p>
    </div>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalUpload"><i class="bi bi-cloud-arrow-up"></i> Novo arquivo</button>
</div>

<?php if ($mensagem): ?><div class="alert alert-success"><i class="bi bi-check-circle"></i> <?= htmlspecialchars($mensagem) ?></div><?php endif; ?>
<?php if ($erro): ?><div class="alert alert-danger"><i class="bi bi-exclamation-triangle"></i> <?= htmlspecialchars($erro) ?></div><?php endif; ?>

<div class="row g-3 mb-4">
    <div class="col-md-3"><div class="card border-0 shadow-sm"><div class="card-body"><small class="text-muted">TOTAL DE ARQUIVOS</small><h3 class="fw-bold mb-0"><?= $totalDocs ?></h3></div></div></div>
    <div class="col-md-3"><div class="card border-0 shadow-sm"><div class="card-body"><small class="text-muted">ENVIADOS NO MÊS</small><h3 class="fw-bold text-primary mb-0"><?= $totalMes ?></h3></div></div></div>
    <div class="col-md-3"><div class="card border-0 shadow-sm"><div class="card-body"><small class="text-muted">PROVAS</small><h3 class="fw-bold text-warning mb-0"><?= $totalProvas ?></h3></div></div></div>
    <div class="col-md-3"><div class="card border-0 shadow-sm"><div class="card-body"><small class="text-muted">ARMAZENAMENTO</small><h3 class="fw-bold text-success mb-0"><?= sgl_doc_formatar_tamanho($armazenamento) ?></h3></div></div></div>
</div>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-body">
        <form method="get" class="row g-2 align-items-end">
            <input type="hidden" name="mod" value="documentos">
            <div class="col-md-4"><label class="form-label">Pesquisa inteligente</label><input class="form-control" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Título, código, processo, descrição ou arquivo"></div>
            <div class="col-md-2"><label class="form-label">Categoria</label><select class="form-select" name="categoria"><option value="">Todas</option><?php foreach(sgl_doc_categoria_lista() as $cat): ?><option value="<?= htmlspecialchars($cat) ?>" <?= $fcat===$cat?'selected':'' ?>><?= htmlspecialchars($cat) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-3"><label class="form-label">Cliente</label><select class="form-select" name="cliente_id"><option value="">Todos</option><?php foreach($clientes as $c): ?><option value="<?= htmlspecialchars($c['id']) ?>" <?= $fcli===$c['id']?'selected':'' ?>><?= htmlspecialchars($c['nome']) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-2"><label class="form-label">Processo</label><select class="form-select" name="processo_id"><option value="">Todos</option><?php foreach($processos as $p): ?><option value="<?= htmlspecialchars($p['id']) ?>" <?= $fproc===$p['id']?'selected':'' ?>><?= htmlspecialchars($p['numero_processo']) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-1 d-grid"><button class="btn btn-outline-primary"><i class="bi bi-search"></i></button></div>
        </form>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-dark text-white d-flex justify-content-between"><strong><i class="bi bi-list-ul"></i> Arquivos cadastrados</strong><span><?= count($documentos) ?> registro(s)</span></div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead><tr><th>Código</th><th>Documento</th><th>Categoria</th><th>Cliente</th><th>Processo</th><th>Tamanho</th><th>Enviado por</th><th>Ações</th></tr></thead>
            <tbody>
            <?php if (!$documentos): ?>
                <tr><td colspan="8" class="text-center text-muted py-4">Nenhum documento encontrado.</td></tr>
            <?php endif; ?>
            <?php foreach($documentos as $d): ?>
                <tr>
                    <td><span class="badge bg-secondary"><?= htmlspecialchars($d['codigo']) ?></span></td>
                    <td><strong><?= htmlspecialchars($d['titulo']) ?></strong><br><small class="text-muted"><?= htmlspecialchars($d['nome_original']) ?></small></td>
                    <td><?= htmlspecialchars($d['categoria']) ?></td>
                    <td><?= htmlspecialchars($d['cliente_nome']) ?></td>
                    <td><?= htmlspecialchars($d['numero_processo'] ?: '-') ?></td>
                    <td><?= sgl_doc_formatar_tamanho((int)$d['tamanho_bytes']) ?></td>
                    <td><small><?= htmlspecialchars($d['usuario_nome'] ?: '-') ?><br><?= date('d/m/Y H:i', strtotime($d['criado_em'])) ?></small></td>
                    <td class="text-nowrap">
                        <a class="btn btn-sm btn-success" target="_blank" href="documento_arquivo.php?id=<?= (int)$d['id'] ?>&modo=inline" title="Visualizar"><i class="bi bi-eye"></i></a>
                        <a class="btn btn-sm btn-outline-primary" href="documento_arquivo.php?id=<?= (int)$d['id'] ?>&modo=download" title="Baixar"><i class="bi bi-download"></i></a>
                        <form method="post" class="d-inline" onsubmit="return confirm('Mover este documento para a lixeira?');">
                            <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
                            <input type="hidden" name="acao" value="excluir">
                            <input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
                            <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="modal fade" id="modalUpload" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <form class="modal-content" method="post" enctype="multipart/form-data">
      <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
      <input type="hidden" name="acao" value="upload">
      <div class="modal-header bg-primary text-white"><h5 class="modal-title"><i class="bi bi-cloud-arrow-up"></i> Enviar documento</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <div class="row g-3">
          <div class="col-md-8"><label class="form-label">Título do documento *</label><input class="form-control" name="titulo" required placeholder="Ex.: RG do cliente, Contrato assinado, Prova WhatsApp"></div>
          <div class="col-md-4"><label class="form-label">Categoria</label><select class="form-select" name="categoria"><?php foreach(sgl_doc_categoria_lista() as $cat): ?><option><?= htmlspecialchars($cat) ?></option><?php endforeach; ?></select></div>
          <div class="col-md-6"><label class="form-label">Cliente</label><select class="form-select" name="cliente_id"><option value="">Não vincular</option><?php foreach($clientes as $c): ?><option value="<?= htmlspecialchars($c['id']) ?>"><?= htmlspecialchars($c['nome']) ?></option><?php endforeach; ?></select></div>
          <div class="col-md-6"><label class="form-label">Processo</label><select class="form-select" name="processo_id"><option value="">Não vincular</option><?php foreach($processos as $p): ?><option value="<?= htmlspecialchars($p['id']) ?>"><?= htmlspecialchars($p['numero_processo']) ?> <?= $p['cliente_nome'] ? ' - '.htmlspecialchars($p['cliente_nome']) : '' ?></option><?php endforeach; ?></select></div>
          <div class="col-12"><label class="form-label">Arquivo *</label><input class="form-control" type="file" name="arquivo" required accept=".pdf,.jpg,.jpeg,.png,.webp,.doc,.docx,.xls,.xlsx,.txt,.odt,.rtf"><small class="text-muted">Permitidos: PDF, imagens, Word, Excel e texto. Limite: 15 MB.</small></div>
          <div class="col-12"><label class="form-label">Descrição/observações</label><textarea class="form-control" name="descricao" rows="3" placeholder="Detalhe onde este documento será usado, contexto da prova, observações internas..."></textarea></div>
        </div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-primary"><i class="bi bi-save"></i> Salvar documento</button></div>
    </form>
  </div>
</div>
