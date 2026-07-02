<?php
/**
 * SGL Advocacia - Modelos Jurídicos
 * Repositório de modelos de contratos, petições, procurações, termos e documentos.
 */
$conn = conectar();

$csrf = $_SESSION['csrf_token'] ?? bin2hex(random_bytes(32));
$_SESSION['csrf_token'] = $csrf;

function sgl_modelos_garantir_tabela(mysqli $conn): void {
    $conn->query("CREATE TABLE IF NOT EXISTS modelos_documentos (
        id INT AUTO_INCREMENT PRIMARY KEY,
        codigo VARCHAR(20) NOT NULL UNIQUE,
        titulo VARCHAR(180) NOT NULL,
        categoria VARCHAR(80) NOT NULL DEFAULT 'Outros',
        area_direito VARCHAR(80) NULL,
        conteudo LONGTEXT NOT NULL,
        observacoes TEXT NULL,
        status ENUM('Ativo','Inativo') NOT NULL DEFAULT 'Ativo',
        criado_por INT NULL,
        atualizado_por INT NULL,
        deletado TINYINT(1) NOT NULL DEFAULT 0,
        criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_modelos_categoria (categoria),
        INDEX idx_modelos_status (status),
        INDEX idx_modelos_deletado (deletado)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function sgl_modelos_codigo(mysqli $conn): string {
    $res = $conn->query("SELECT codigo FROM modelos_documentos WHERE codigo LIKE 'MOD%' ORDER BY CAST(SUBSTRING(codigo,4) AS UNSIGNED) DESC LIMIT 1");
    if (!$res || $res->num_rows === 0) return 'MOD001';
    $num = (int)substr((string)$res->fetch_assoc()['codigo'], 3) + 1;
    return 'MOD' . str_pad((string)$num, 3, '0', STR_PAD_LEFT);
}

function sgl_modelos_categorias(): array {
    return ['Contrato','Petição','Procuração','Declaração','Termo','Notificação','Requerimento','Recibo','Documento administrativo','Outros'];
}

function sgl_modelos_areas(): array {
    return ['Previdenciário','Trabalhista','Cível','Família','Consumidor','Criminal','Tributário','Empresarial','Imobiliário','Administrativo','Bancário','Contratual','LGPD','Geral'];
}

function sgl_modelos_aplicar_variaveis(string $texto, array $vars): string {
    foreach ($vars as $k => $v) {
        $texto = str_replace('{{' . $k . '}}', (string)$v, $texto);
    }
    return $texto;
}

sgl_modelos_garantir_tabela($conn);
$mensagem = null;
$erro = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf'] ?? '';
    if (!hash_equals($csrf, $token)) {
        $erro = 'Falha de segurança. Atualize a página e tente novamente.';
    } else {
        $acao = $_POST['acao'] ?? '';
        if ($acao === 'salvar') {
            $id = (int)($_POST['id'] ?? 0);
            $titulo = trim($_POST['titulo'] ?? '');
            $categoria = trim($_POST['categoria'] ?? 'Outros');
            $area = trim($_POST['area_direito'] ?? 'Geral');
            $conteudo = trim($_POST['conteudo'] ?? '');
            $observacoes = trim($_POST['observacoes'] ?? '');
            $status = ($_POST['status'] ?? 'Ativo') === 'Inativo' ? 'Inativo' : 'Ativo';
            $uid = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;

            if ($titulo === '' || $conteudo === '') {
                $erro = 'Informe título e conteúdo do modelo.';
            } else {
                if ($id > 0) {
                    $stmt = $conn->prepare("UPDATE modelos_documentos SET titulo=?, categoria=?, area_direito=?, conteudo=?, observacoes=?, status=?, atualizado_por=? WHERE id=?");
                    $stmt->bind_param('ssssssii', $titulo, $categoria, $area, $conteudo, $observacoes, $status, $uid, $id);
                    $stmt->execute();
                    $stmt->close();
                    if (function_exists('sgl_registrar_log')) sgl_registrar_log($conn, 'ATUALIZAR_MODELO', 'modelos_documentos', (string)$id, "Modelo atualizado: {$titulo}");
                    $mensagem = 'Modelo atualizado com sucesso.';
                } else {
                    $codigo = sgl_modelos_codigo($conn);
                    $stmt = $conn->prepare("INSERT INTO modelos_documentos (codigo,titulo,categoria,area_direito,conteudo,observacoes,status,criado_por,atualizado_por) VALUES (?,?,?,?,?,?,?,?,?)");
                    $stmt->bind_param('sssssssii', $codigo, $titulo, $categoria, $area, $conteudo, $observacoes, $status, $uid, $uid);
                    $stmt->execute();
                    $novoId = $stmt->insert_id;
                    $stmt->close();
                    if (function_exists('sgl_registrar_log')) sgl_registrar_log($conn, 'CRIAR_MODELO', 'modelos_documentos', (string)$novoId, "Modelo criado: {$codigo} - {$titulo}");
                    $mensagem = 'Modelo cadastrado com sucesso.';
                }
            }
        }

        if ($acao === 'excluir') {
            $id = (int)($_POST['id'] ?? 0);
            $stmt = $conn->prepare("UPDATE modelos_documentos SET deletado=1 WHERE id=?");
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $stmt->close();
            if (function_exists('sgl_registrar_log')) sgl_registrar_log($conn, 'EXCLUIR_MODELO', 'modelos_documentos', (string)$id, 'Modelo movido para lixeira.');
            $mensagem = 'Modelo movido para a lixeira.';
        }

        if ($acao === 'duplicar') {
            $id = (int)($_POST['id'] ?? 0);
            $stmt = $conn->prepare("SELECT * FROM modelos_documentos WHERE id=? LIMIT 1");
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $modelo = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($modelo) {
                $codigo = sgl_modelos_codigo($conn);
                $titulo = 'Cópia de ' . $modelo['titulo'];
                $uid = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
                $stmt = $conn->prepare("INSERT INTO modelos_documentos (codigo,titulo,categoria,area_direito,conteudo,observacoes,status,criado_por,atualizado_por) VALUES (?,?,?,?,?,?,?,?,?)");
                $stmt->bind_param('sssssssii', $codigo, $titulo, $modelo['categoria'], $modelo['area_direito'], $modelo['conteudo'], $modelo['observacoes'], $modelo['status'], $uid, $uid);
                $stmt->execute();
                $novoId = $stmt->insert_id;
                $stmt->close();
                if (function_exists('sgl_registrar_log')) sgl_registrar_log($conn, 'DUPLICAR_MODELO', 'modelos_documentos', (string)$novoId, "Modelo duplicado a partir do ID {$id}");
                $mensagem = 'Modelo duplicado com sucesso.';
            }
        }
    }
}

$editar = null;
$editarId = isset($_GET['editar']) ? (int)$_GET['editar'] : 0;
if ($editarId > 0) {
    $stmt = $conn->prepare("SELECT * FROM modelos_documentos WHERE id=? AND deletado=0 LIMIT 1");
    $stmt->bind_param('i', $editarId);
    $stmt->execute();
    $editar = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

$visualizar = null;
$visualizarId = isset($_GET['visualizar']) ? (int)$_GET['visualizar'] : 0;
if ($visualizarId > 0) {
    $stmt = $conn->prepare("SELECT * FROM modelos_documentos WHERE id=? AND deletado=0 LIMIT 1");
    $stmt->bind_param('i', $visualizarId);
    $stmt->execute();
    $visualizar = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

$clientes = [];
$res = $conn->query("SELECT id, nome, cpf_cnpj, logradouro, numero, complemento, bairro, cidade, estado FROM clientes WHERE COALESCE(deletado,0)=0 ORDER BY nome LIMIT 500");
if ($res) while ($r = $res->fetch_assoc()) $clientes[] = $r;
$processos = [];
$res = $conn->query("SELECT p.id, p.numero_processo, p.cliente_id, p.tipo_processo, p.comarca, COALESCE(c.nome,'') AS cliente_nome FROM processos p LEFT JOIN clientes c ON c.id=p.cliente_id ORDER BY p.numero_processo LIMIT 500");
if ($res) while ($r = $res->fetch_assoc()) $processos[] = $r;

$q = trim($_GET['q'] ?? '');
$fcategoria = trim($_GET['categoria'] ?? '');
$farea = trim($_GET['area_direito'] ?? '');
$fstatus = trim($_GET['status'] ?? '');
$where = ['deletado=0'];
$params = [];
$types = '';
if ($q !== '') { $like = '%' . $q . '%'; $where[] = '(codigo LIKE ? OR titulo LIKE ? OR conteudo LIKE ? OR observacoes LIKE ?)'; array_push($params,$like,$like,$like,$like); $types .= 'ssss'; }
if ($fcategoria !== '') { $where[] = 'categoria=?'; $params[]=$fcategoria; $types.='s'; }
if ($farea !== '') { $where[] = 'area_direito=?'; $params[]=$farea; $types.='s'; }
if ($fstatus !== '') { $where[] = 'status=?'; $params[]=$fstatus; $types.='s'; }
$sql = "SELECT * FROM modelos_documentos WHERE " . implode(' AND ', $where) . " ORDER BY atualizado_em DESC LIMIT 200";
$stmt = $conn->prepare($sql);
if ($params) $stmt->bind_param($types, ...$params);
$stmt->execute();
$modelos = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$total = (int)($conn->query("SELECT COUNT(*) total FROM modelos_documentos WHERE deletado=0")->fetch_assoc()['total'] ?? 0);
$ativos = (int)($conn->query("SELECT COUNT(*) total FROM modelos_documentos WHERE deletado=0 AND status='Ativo'")->fetch_assoc()['total'] ?? 0);
$peticoes = (int)($conn->query("SELECT COUNT(*) total FROM modelos_documentos WHERE deletado=0 AND categoria='Petição'")->fetch_assoc()['total'] ?? 0);
$contratos = (int)($conn->query("SELECT COUNT(*) total FROM modelos_documentos WHERE deletado=0 AND categoria='Contrato'")->fetch_assoc()['total'] ?? 0);

if ($visualizar) {
    $clienteSelecionado = null;
    $processoSelecionado = null;
    $clienteIdPreview = trim($_GET['cliente_id'] ?? '');
    $processoIdPreview = trim($_GET['processo_id'] ?? '');
    foreach ($clientes as $c) if ((string)$c['id'] === $clienteIdPreview) $clienteSelecionado = $c;
    foreach ($processos as $p) if ((string)$p['id'] === $processoIdPreview) $processoSelecionado = $p;
    if (!$clienteSelecionado && $processoSelecionado) foreach ($clientes as $c) if ((string)$c['id'] === (string)$processoSelecionado['cliente_id']) $clienteSelecionado = $c;
    $vars = [
        'cliente_nome' => $clienteSelecionado['nome'] ?? 'NOME DO CLIENTE',
        'cliente_cpf_cnpj' => $clienteSelecionado['cpf_cnpj'] ?? 'CPF/CNPJ DO CLIENTE',
        'cliente_endereco' => trim(($clienteSelecionado['logradouro'] ?? '') . (empty($clienteSelecionado['numero']) ? '' : ', ' . $clienteSelecionado['numero']) . (empty($clienteSelecionado['complemento']) ? '' : ' - ' . $clienteSelecionado['complemento']) . (empty($clienteSelecionado['bairro']) ? '' : ' - ' . $clienteSelecionado['bairro'])) ?: 'ENDEREÇO DO CLIENTE',
        'cliente_cidade' => $clienteSelecionado['cidade'] ?? 'CIDADE',
        'cliente_uf' => $clienteSelecionado['estado'] ?? 'UF',
        'processo_numero' => $processoSelecionado['numero_processo'] ?? 'NÚMERO DO PROCESSO',
        'processo_tipo' => $processoSelecionado['tipo_processo'] ?? 'TIPO DO PROCESSO',
        'processo_comarca' => $processoSelecionado['comarca'] ?? 'COMARCA',
        'data_atual' => date('d/m/Y'),
        'escritorio_nome' => $nome_escritorio ?? 'SGL Advocacia',
    ];
    $conteudoFinal = sgl_modelos_aplicar_variaveis((string)$visualizar['conteudo'], $vars);
    ?>
    <style>
        .modelo-print-page { background:#fff; max-width: 850px; margin:0 auto; padding:28px 34px; border:1px solid #e5e7eb; }
        .modelo-print-body { white-space: pre-wrap; font-family: 'Times New Roman', serif; font-size: 12pt; line-height: 1.55; color:#111; }
        @media print {
            body * { visibility: hidden !important; }
            .modelo-print-area, .modelo-print-area * { visibility: visible !important; }
            .modelo-print-area { position:absolute; left:0; top:0; width:100%; }
            .modelo-print-actions { display:none !important; }
            .modelo-print-page { border:0; max-width:none; padding:18mm 20mm; }
        }
    </style>
    <div class="modelo-print-area">
        <div class="modelo-print-actions d-flex justify-content-between align-items-center mb-3">
            <a href="?mod=modelos" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> Voltar</a>
            <div class="d-flex gap-2">
                <a class="btn btn-outline-primary" href="?mod=modelos&editar=<?= (int)$visualizar['id'] ?>"><i class="bi bi-pencil"></i> Editar modelo</a>
                <button onclick="window.print()" class="btn btn-primary"><i class="bi bi-printer"></i> Imprimir / Salvar PDF</button>
            </div>
        </div>
        <div class="card border-0 shadow-sm mb-3 modelo-print-actions">
            <div class="card-body">
                <form method="get" class="row g-2 align-items-end">
                    <input type="hidden" name="mod" value="modelos">
                    <input type="hidden" name="visualizar" value="<?= (int)$visualizar['id'] ?>">
                    <div class="col-md-5"><label class="form-label">Preencher com cliente</label><select name="cliente_id" class="form-select"><option value="">Sem cliente</option><?php foreach($clientes as $c): ?><option value="<?= htmlspecialchars($c['id']) ?>" <?= $clienteIdPreview===(string)$c['id']?'selected':'' ?>><?= htmlspecialchars($c['nome']) ?></option><?php endforeach; ?></select></div>
                    <div class="col-md-5"><label class="form-label">Preencher com processo</label><select name="processo_id" class="form-select"><option value="">Sem processo</option><?php foreach($processos as $p): ?><option value="<?= htmlspecialchars($p['id']) ?>" <?= $processoIdPreview===(string)$p['id']?'selected':'' ?>><?= htmlspecialchars($p['numero_processo'].' - '.$p['cliente_nome']) ?></option><?php endforeach; ?></select></div>
                    <div class="col-md-2 d-grid"><button class="btn btn-outline-primary"><i class="bi bi-magic"></i> Aplicar</button></div>
                </form>
                <small class="text-muted d-block mt-2">Variáveis disponíveis: {{cliente_nome}}, {{cliente_cpf_cnpj}}, {{processo_numero}}, {{data_atual}}, {{escritorio_nome}}.</small>
            </div>
        </div>
        <div class="modelo-print-page">
            <div class="text-center mb-4">
                <h3 class="fw-bold mb-1"><?= htmlspecialchars($visualizar['titulo']) ?></h3>
                <small class="text-muted"><?= htmlspecialchars($visualizar['codigo']) ?> · <?= htmlspecialchars($visualizar['categoria']) ?> · <?= htmlspecialchars($visualizar['area_direito'] ?? '') ?></small>
            </div>
            <div class="modelo-print-body"><?= htmlspecialchars($conteudoFinal) ?></div>
        </div>
    </div>
    <?php
    return;
}
?>

<div class="d-flex justify-content-between align-items-start mb-4">
    <div>
        <h2 class="fw-bold text-primary mb-1"><i class="bi bi-journal-text"></i> Modelos Jurídicos</h2>
        <p class="text-muted mb-0">Biblioteca de contratos, petições, procurações, termos e documentos reutilizáveis do escritório.</p>
    </div>
    <a href="?mod=modelos&novo=1" class="btn btn-primary"><i class="bi bi-plus-circle"></i> Novo modelo</a>
</div>

<?php if ($mensagem): ?><div class="alert alert-success"><i class="bi bi-check-circle"></i> <?= htmlspecialchars($mensagem) ?></div><?php endif; ?>
<?php if ($erro): ?><div class="alert alert-danger"><i class="bi bi-exclamation-triangle"></i> <?= htmlspecialchars($erro) ?></div><?php endif; ?>

<div class="row g-3 mb-4">
    <div class="col-md-3"><div class="card border-0 shadow-sm"><div class="card-body"><small class="text-muted">TOTAL DE MODELOS</small><h3 class="fw-bold mb-0"><?= $total ?></h3></div></div></div>
    <div class="col-md-3"><div class="card border-0 shadow-sm"><div class="card-body"><small class="text-muted">MODELOS ATIVOS</small><h3 class="fw-bold text-success mb-0"><?= $ativos ?></h3></div></div></div>
    <div class="col-md-3"><div class="card border-0 shadow-sm"><div class="card-body"><small class="text-muted">PETIÇÕES</small><h3 class="fw-bold text-primary mb-0"><?= $peticoes ?></h3></div></div></div>
    <div class="col-md-3"><div class="card border-0 shadow-sm"><div class="card-body"><small class="text-muted">CONTRATOS</small><h3 class="fw-bold text-warning mb-0"><?= $contratos ?></h3></div></div></div>
</div>

<?php if ($editar || isset($_GET['novo'])): ?>
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-dark text-white fw-bold"><i class="bi bi-pencil-square"></i> <?= $editar ? 'Editar modelo' : 'Novo modelo' ?></div>
    <div class="card-body">
        <form method="post" class="row g-3">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
            <input type="hidden" name="acao" value="salvar">
            <input type="hidden" name="id" value="<?= (int)($editar['id'] ?? 0) ?>">
            <div class="col-md-6"><label class="form-label">Título</label><input class="form-control" name="titulo" required value="<?= htmlspecialchars($editar['titulo'] ?? '') ?>" placeholder="Ex.: Contrato de Honorários Previdenciário"></div>
            <div class="col-md-2"><label class="form-label">Categoria</label><select class="form-select" name="categoria"><?php foreach(sgl_modelos_categorias() as $cat): ?><option value="<?= htmlspecialchars($cat) ?>" <?= (($editar['categoria'] ?? '')===$cat)?'selected':'' ?>><?= htmlspecialchars($cat) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-2"><label class="form-label">Área</label><select class="form-select" name="area_direito"><?php foreach(sgl_modelos_areas() as $area): ?><option value="<?= htmlspecialchars($area) ?>" <?= (($editar['area_direito'] ?? '')===$area)?'selected':'' ?>><?= htmlspecialchars($area) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-2"><label class="form-label">Status</label><select class="form-select" name="status"><option value="Ativo" <?= (($editar['status'] ?? 'Ativo')==='Ativo')?'selected':'' ?>>Ativo</option><option value="Inativo" <?= (($editar['status'] ?? '')==='Inativo')?'selected':'' ?>>Inativo</option></select></div>
            <div class="col-12"><label class="form-label">Conteúdo do modelo</label><textarea class="form-control" name="conteudo" rows="14" required placeholder="Digite aqui o modelo. Use variáveis como {{cliente_nome}}, {{cliente_cpf_cnpj}}, {{processo_numero}}, {{data_atual}}, {{escritorio_nome}}."><?= htmlspecialchars($editar['conteudo'] ?? '') ?></textarea></div>
            <div class="col-12"><label class="form-label">Observações internas</label><textarea class="form-control" name="observacoes" rows="2"><?= htmlspecialchars($editar['observacoes'] ?? '') ?></textarea></div>
            <div class="col-12 d-flex gap-2 justify-content-end"><a href="?mod=modelos" class="btn btn-outline-secondary">Cancelar</a><button class="btn btn-primary"><i class="bi bi-save"></i> Salvar modelo</button></div>
        </form>
    </div>
</div>
<?php endif; ?>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-body">
        <form method="get" class="row g-2 align-items-end">
            <input type="hidden" name="mod" value="modelos">
            <div class="col-md-4"><label class="form-label">Pesquisa inteligente</label><input class="form-control" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Título, código, texto, observação"></div>
            <div class="col-md-2"><label class="form-label">Categoria</label><select class="form-select" name="categoria"><option value="">Todas</option><?php foreach(sgl_modelos_categorias() as $cat): ?><option value="<?= htmlspecialchars($cat) ?>" <?= $fcategoria===$cat?'selected':'' ?>><?= htmlspecialchars($cat) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-2"><label class="form-label">Área</label><select class="form-select" name="area_direito"><option value="">Todas</option><?php foreach(sgl_modelos_areas() as $area): ?><option value="<?= htmlspecialchars($area) ?>" <?= $farea===$area?'selected':'' ?>><?= htmlspecialchars($area) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-2"><label class="form-label">Status</label><select class="form-select" name="status"><option value="">Todos</option><option value="Ativo" <?= $fstatus==='Ativo'?'selected':'' ?>>Ativo</option><option value="Inativo" <?= $fstatus==='Inativo'?'selected':'' ?>>Inativo</option></select></div>
            <div class="col-md-2 d-grid"><button class="btn btn-outline-primary"><i class="bi bi-search"></i> Buscar</button></div>
        </form>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center">
        <strong><i class="bi bi-list-ul"></i> Biblioteca de Modelos</strong>
        <span><?= count($modelos) ?> registro(s)</span>
    </div>
    <div class="table-responsive">
        <table class="table align-middle mb-0">
            <thead class="table-light"><tr><th>Código</th><th>Título</th><th>Categoria</th><th>Área</th><th>Status</th><th>Atualizado</th><th class="text-end">Ações</th></tr></thead>
            <tbody>
            <?php if (!$modelos): ?>
                <tr><td colspan="7" class="text-center text-muted py-4">Nenhum modelo encontrado.</td></tr>
            <?php endif; ?>
            <?php foreach($modelos as $m): ?>
                <tr>
                    <td class="fw-bold"><?= htmlspecialchars($m['codigo']) ?></td>
                    <td><div class="fw-semibold"><?= htmlspecialchars($m['titulo']) ?></div><small class="text-muted"><?= htmlspecialchars(mb_strimwidth(strip_tags($m['observacoes'] ?? ''), 0, 90, '...')) ?></small></td>
                    <td><?= htmlspecialchars($m['categoria']) ?></td>
                    <td><?= htmlspecialchars($m['area_direito'] ?? '-') ?></td>
                    <td><span class="badge <?= $m['status']==='Ativo'?'bg-success':'bg-secondary' ?>"><?= htmlspecialchars($m['status']) ?></span></td>
                    <td><?= date('d/m/Y H:i', strtotime($m['atualizado_em'])) ?></td>
                    <td class="text-end">
                        <a class="btn btn-sm btn-outline-primary" href="?mod=modelos&visualizar=<?= (int)$m['id'] ?>" title="Visualizar/Gerar"><i class="bi bi-eye"></i></a>
                        <a class="btn btn-sm btn-warning" href="?mod=modelos&editar=<?= (int)$m['id'] ?>" title="Editar"><i class="bi bi-pencil"></i></a>
                        <form method="post" class="d-inline" onsubmit="return confirm('Deseja duplicar este modelo?')"><input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>"><input type="hidden" name="acao" value="duplicar"><input type="hidden" name="id" value="<?= (int)$m['id'] ?>"><button class="btn btn-sm btn-outline-secondary" title="Duplicar"><i class="bi bi-copy"></i></button></form>
                        <form method="post" class="d-inline" onsubmit="return confirm('Mover este modelo para a lixeira?')"><input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>"><input type="hidden" name="acao" value="excluir"><input type="hidden" name="id" value="<?= (int)$m['id'] ?>"><button class="btn btn-sm btn-outline-danger" title="Excluir"><i class="bi bi-trash"></i></button></form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="alert alert-info mt-4 mb-0">
    <strong>Variáveis padrão:</strong> {{cliente_nome}}, {{cliente_cpf_cnpj}}, {{cliente_endereco}}, {{cliente_cidade}}, {{cliente_uf}}, {{processo_numero}}, {{processo_tipo}}, {{processo_comarca}}, {{data_atual}}, {{escritorio_nome}}.
</div>
