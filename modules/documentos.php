<?php
/**
 * Fase 3.4 — Documentos e Arquivos
 * Upload seguro de documentos vinculados a cliente/processo.
 */

$conn = conectar();

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
if (empty($_SESSION['csrf_documentos'])) {
    $_SESSION['csrf_documentos'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_documentos'];

function sgl_doc_coluna_existe(mysqli $conn, string $tabela, string $coluna): bool {
    $tabela = $conn->real_escape_string($tabela);
    $coluna = $conn->real_escape_string($coluna);
    $res = $conn->query("SHOW COLUMNS FROM `{$tabela}` LIKE '{$coluna}'");
    return $res && $res->num_rows > 0;
}

function sgl_doc_garantir_tabela(mysqli $conn): void {
    $conn->query("CREATE TABLE IF NOT EXISTS documentos_arquivos (
        id INT AUTO_INCREMENT PRIMARY KEY,
        codigo VARCHAR(20) NOT NULL UNIQUE,
        titulo VARCHAR(180) NOT NULL,
        categoria VARCHAR(80) NOT NULL DEFAULT 'Documento geral',
        cliente_id VARCHAR(10) NULL,
        processo_id VARCHAR(10) NULL,
        numero_processo VARCHAR(80) NULL,
        descricao TEXT NULL,
        nome_original VARCHAR(255) NOT NULL,
        nome_arquivo VARCHAR(255) NOT NULL,
        caminho VARCHAR(255) NOT NULL,
        extensao VARCHAR(20) NULL,
        mime_type VARCHAR(120) NULL,
        tamanho_bytes BIGINT DEFAULT 0,
        hash_arquivo VARCHAR(64) NULL,
        usuario_id INT NULL,
        usuario_nome VARCHAR(150) NULL,
        status VARCHAR(30) NOT NULL DEFAULT 'Ativo',
        deletado TINYINT(1) NOT NULL DEFAULT 0,
        criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_doc_cliente (cliente_id),
        INDEX idx_doc_processo (processo_id),
        INDEX idx_doc_categoria (categoria),
        INDEX idx_doc_deletado (deletado)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

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

sgl_doc_garantir_tabela($conn);
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
            $stmt = $conn->prepare("UPDATE documentos_arquivos SET deletado = 1, status = 'Excluído' WHERE id = ?");
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $stmt->close();
            if (function_exists('sgl_registrar_log')) sgl_registrar_log($conn, 'EXCLUIR_DOCUMENTO', 'documentos_arquivos', (string)$id, 'Documento movido para lixeira.');
            $mensagem = 'Documento movido para a lixeira.';
        }
        if ($acao === 'upload') {
            $titulo = trim($_POST['titulo'] ?? '');
            $categoria = trim($_POST['categoria'] ?? 'Outros');
            $cliente_id = trim($_POST['cliente_id'] ?? '');
            $processo_id = trim($_POST['processo_id'] ?? '');
            $descricao = trim($_POST['descricao'] ?? '');
            if ($titulo === '') $erro = 'Informe um título para o documento.';
            if (empty($_FILES['arquivo']) || $_FILES['arquivo']['error'] !== UPLOAD_ERR_OK) $erro = $erro ?: 'Selecione um arquivo válido.';
            if (!$erro) {
                $file = $_FILES['arquivo'];
                $max = 15 * 1024 * 1024; // 15 MB
                if ($file['size'] > $max) {
                    $erro = 'Arquivo muito grande. Limite atual: 15 MB.';
                } else {
                    $original = $file['name'];
                    $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
                    if (!in_array($ext, sgl_doc_ext_permitidas(), true)) {
                        $erro = 'Tipo de arquivo não permitido.';
                    } else {
                        $finfo = new finfo(FILEINFO_MIME_TYPE);
                        $mime = $finfo->file($file['tmp_name']) ?: 'application/octet-stream';
                        $dirRel = 'uploads/documentos/' . date('Y') . '/' . date('m');
                        $dirAbs = __DIR__ . '/../' . $dirRel;
                        if (!is_dir($dirAbs)) mkdir($dirAbs, 0775, true);
                        $safeName = bin2hex(random_bytes(16)) . '.' . $ext;
                        $destAbs = $dirAbs . '/' . $safeName;
                        $destRel = $dirRel . '/' . $safeName;
                        if (!move_uploaded_file($file['tmp_name'], $destAbs)) {
                            $erro = 'Não foi possível salvar o arquivo.';
                        } else {
                            $codigo = sgl_doc_novo_codigo($conn);
                            $hash = hash_file('sha256', $destAbs);
                            $numero_processo = null;
                            if ($processo_id !== '') {
                                $stmtP = $conn->prepare("SELECT numero_processo FROM processos WHERE id = ? LIMIT 1");
                                $stmtP->bind_param('s', $processo_id);
                                $stmtP->execute();
                                $numero_processo = ($stmtP->get_result()->fetch_assoc()['numero_processo'] ?? null);
                                $stmtP->close();
                            }
                            $uid = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
                            $unome = $_SESSION['nome'] ?? $_SESSION['username'] ?? 'Usuário';
                            $cliente_id_db = $cliente_id !== '' ? $cliente_id : null;
                            $processo_id_db = $processo_id !== '' ? $processo_id : null;
                            $stmt = $conn->prepare("INSERT INTO documentos_arquivos (codigo,titulo,categoria,cliente_id,processo_id,numero_processo,descricao,nome_original,nome_arquivo,caminho,extensao,mime_type,tamanho_bytes,hash_arquivo,usuario_id,usuario_nome) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
                            $tamanho = (int)$file['size'];
                            $stmt->bind_param('ssssssssssssisis', $codigo,$titulo,$categoria,$cliente_id_db,$processo_id_db,$numero_processo,$descricao,$original,$safeName,$destRel,$ext,$mime,$tamanho,$hash,$uid,$unome);
                            $stmt->execute();
                            $novoId = $stmt->insert_id;
                            $stmt->close();
                            if (function_exists('sgl_registrar_log')) sgl_registrar_log($conn, 'UPLOAD_DOCUMENTO', 'documentos_arquivos', (string)$novoId, "Upload {$codigo}: {$original}");
                            $mensagem = 'Documento enviado com segurança e vinculado ao cadastro selecionado.';
                        }
                    }
                }
            }
        }
    }
}

$clientes = [];
$res = $conn->query("SELECT id, nome FROM clientes WHERE COALESCE(deletado,0)=0 ORDER BY nome LIMIT 500");
while ($r = $res->fetch_assoc()) $clientes[] = $r;

$processos = [];
$sqlProc = "SELECT p.id, p.numero_processo, p.cliente_id, COALESCE(c.nome,'') AS cliente_nome FROM processos p LEFT JOIN clientes c ON c.id = p.cliente_id ORDER BY p.numero_processo LIMIT 500";
$res = $conn->query($sqlProc);
while ($r = $res->fetch_assoc()) $processos[] = $r;

$q = trim($_GET['q'] ?? '');
$fcat = trim($_GET['categoria'] ?? '');
$fcli = trim($_GET['cliente_id'] ?? '');
$fproc = trim($_GET['processo_id'] ?? '');
$where = ['d.deletado = 0'];
$params = [];
$types = '';
if ($q !== '') { $like = '%' . $q . '%'; $where[] = '(d.codigo LIKE ? OR d.titulo LIKE ? OR d.nome_original LIKE ? OR d.descricao LIKE ? OR d.numero_processo LIKE ?)'; array_push($params,$like,$like,$like,$like,$like); $types .= 'sssss'; }
if ($fcat !== '') { $where[] = 'd.categoria = ?'; $params[]=$fcat; $types.='s'; }
if ($fcli !== '') { $where[] = 'd.cliente_id = ?'; $params[]=$fcli; $types.='s'; }
if ($fproc !== '') { $where[] = 'd.processo_id = ?'; $params[]=$fproc; $types.='s'; }
$sql = "SELECT d.*, COALESCE(c.nome,'-') AS cliente_nome FROM documentos_arquivos d LEFT JOIN clientes c ON c.id = d.cliente_id WHERE " . implode(' AND ', $where) . " ORDER BY d.criado_em DESC LIMIT 200";
$stmt = $conn->prepare($sql);
if ($params) $stmt->bind_param($types, ...$params);
$stmt->execute();
$documentos = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$totalDocs = (int)($conn->query("SELECT COUNT(*) total FROM documentos_arquivos WHERE deletado=0")->fetch_assoc()['total'] ?? 0);
$totalMes = (int)($conn->query("SELECT COUNT(*) total FROM documentos_arquivos WHERE deletado=0 AND YEAR(criado_em)=YEAR(CURDATE()) AND MONTH(criado_em)=MONTH(CURDATE())")->fetch_assoc()['total'] ?? 0);
$totalProvas = (int)($conn->query("SELECT COUNT(*) total FROM documentos_arquivos WHERE deletado=0 AND categoria='Prova'")->fetch_assoc()['total'] ?? 0);
$armazenamento = (int)($conn->query("SELECT COALESCE(SUM(tamanho_bytes),0) total FROM documentos_arquivos WHERE deletado=0")->fetch_assoc()['total'] ?? 0);
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
