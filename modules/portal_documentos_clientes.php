<?php
if (!defined('ROJEX_PORTAL_DOC_INTERNO')) {
    define('ROJEX_PORTAL_DOC_INTERNO', true);
}

$conn = conectar();
$tenantId = function_exists('rojexTenantId') ? trim((string)rojexTenantId()) : trim((string)($_SESSION['tenant_id'] ?? ''));
$escritorioId = function_exists('rojexEscritorioId') ? (int)rojexEscritorioId() : (int)($_SESSION['escritorio_id'] ?? 0);

if ($tenantId === '' || $escritorioId <= 0) {
    echo '<div class="alert alert-danger">Contexto do escritório indisponível.</div>';
    return;
}

if (empty($_SESSION['csrf_portal_docs_interno'])) {
    $_SESSION['csrf_portal_docs_interno'] = bin2hex(random_bytes(32));
}
$csrf = (string)$_SESSION['csrf_portal_docs_interno'];
$mensagem = '';
$erro = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $token = (string)($_POST['csrf_token'] ?? '');
    if (!hash_equals($csrf, $token)) {
        $erro = 'Sessão expirada. Atualize a página e tente novamente.';
    } else {
        $envioId = (int)($_POST['envio_id'] ?? 0);
        $acao = (string)($_POST['acao'] ?? '');
        $mapa = [
            'aprovar' => 'APROVADO',
            'solicitar_correcao' => 'CORRECAO_SOLICITADA',
            'arquivar' => 'ARQUIVADO',
        ];
        if ($envioId <= 0 || !isset($mapa[$acao])) {
            $erro = 'Ação inválida.';
        } else {
            $novoStatus = $mapa[$acao];
            $stmt = $conn->prepare(
                "SELECT id, status, titulo, cliente_id, processo_id, nome_original
                   FROM portal_documentos_envios
                  WHERE id = ? AND tenant_id = ? AND escritorio_id = ? LIMIT 1"
            );
            $stmt->bind_param('isi', $envioId, $tenantId, $escritorioId);
            $stmt->execute();
            $registro = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$registro) {
                $erro = 'Documento não encontrado para este escritório.';
            } else {
                $statusAnterior = (string)$registro['status'];
                $stmt = $conn->prepare(
                    "UPDATE portal_documentos_envios
                        SET status = ?, analisado_em = NOW()
                      WHERE id = ? AND tenant_id = ? AND escritorio_id = ?"
                );
                $stmt->bind_param('sisi', $novoStatus, $envioId, $tenantId, $escritorioId);
                $stmt->execute();
                $alterados = $stmt->affected_rows;
                $stmt->close();

                if ($alterados >= 0) {
                    if (function_exists('sgl_registrar_log')) {
                        sgl_registrar_log(
                            $conn,
                            'Análise de documento enviado pelo cliente',
                            'portal_documentos_envios',
                            (string)$envioId,
                            'Status do documento alterado pela equipe do escritório.',
                            [
                                'tipo_acao' => 'EDICAO',
                                'modulo' => 'Portal do Cliente - Documentos',
                                'origem' => 'modules/portal_documentos_clientes.php',
                                'dados_anteriores' => ['status' => $statusAnterior],
                                'dados_novos' => ['status' => $novoStatus],
                            ]
                        );
                    }
                    $mensagem = 'Situação do documento atualizada com sucesso.';
                    $_SESSION['csrf_portal_docs_interno'] = bin2hex(random_bytes(32));
                    $csrf = (string)$_SESSION['csrf_portal_docs_interno'];
                } else {
                    $erro = 'Não foi possível atualizar o documento.';
                }
            }
        }
    }
}

$statusFiltro = strtoupper(trim((string)($_GET['status'] ?? '')));
$busca = trim((string)($_GET['q'] ?? ''));
$permitidos = ['', 'AGUARDANDO_ANALISE', 'APROVADO', 'CORRECAO_SOLICITADA', 'ARQUIVADO'];
if (!in_array($statusFiltro, $permitidos, true)) { $statusFiltro = ''; }

$sql = "SELECT pde.id, pde.cliente_id, pde.processo_id, pde.titulo, pde.descricao_cliente,
               pde.nome_original, pde.extensao, pde.mime_type, pde.tamanho_bytes,
               pde.hash_sha256, pde.status, pde.criado_em, pde.analisado_em,
               c.nome AS cliente_nome, p.numero_processo
          FROM portal_documentos_envios pde
          INNER JOIN clientes c ON c.id = pde.cliente_id
                               AND c.tenant_id = pde.tenant_id
                               AND c.escritorio_id = pde.escritorio_id
          LEFT JOIN processos p ON p.id = pde.processo_id
                              AND p.tenant_id = pde.tenant_id
                              AND p.escritorio_id = pde.escritorio_id
         WHERE pde.tenant_id = ? AND pde.escritorio_id = ?";
$params = [$tenantId, $escritorioId];
$types = 'si';
if ($statusFiltro !== '') { $sql .= " AND pde.status = ?"; $params[] = $statusFiltro; $types .= 's'; }
if ($busca !== '') {
    $sql .= " AND (c.nome LIKE ? OR pde.titulo LIKE ? OR pde.nome_original LIKE ? OR p.numero_processo LIKE ?)";
    $like = '%' . $busca . '%';
    array_push($params, $like, $like, $like, $like); $types .= 'ssss';
}
$sql .= " ORDER BY CASE WHEN pde.status = 'AGUARDANDO_ANALISE' THEN 0 ELSE 1 END, pde.criado_em DESC LIMIT 300";
$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$res = $stmt->get_result();
$documentos = [];
while ($row = $res->fetch_assoc()) { $documentos[] = $row; }
$stmt->close();

function rojexDocPortalStatus(string $status): array {
    return match ($status) {
        'APROVADO' => ['Aprovado', 'success'],
        'CORRECAO_SOLICITADA' => ['Correção solicitada', 'warning'],
        'ARQUIVADO' => ['Arquivado', 'secondary'],
        default => ['Aguardando análise', 'primary'],
    };
}
function rojexDocPortalTamanho(int $bytes): string {
    if ($bytes >= 1048576) return number_format($bytes / 1048576, 2, ',', '.') . ' MB';
    return number_format(max(0, $bytes) / 1024, 1, ',', '.') . ' KB';
}
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
    <div>
        <h2 class="mb-1"><i class="bi bi-inbox me-2"></i>Documentos enviados pelos clientes</h2>
        <p class="text-muted mb-0">Analise os arquivos recebidos pelo Portal do Cliente.</p>
    </div>
    <span class="badge text-bg-primary fs-6"><?= count($documentos) ?> registro(s)</span>
</div>

<?php if ($mensagem !== ''): ?><div class="alert alert-success"><?= htmlspecialchars($mensagem) ?></div><?php endif; ?>
<?php if ($erro !== ''): ?><div class="alert alert-danger"><?= htmlspecialchars($erro) ?></div><?php endif; ?>

<div class="card shadow-sm border-0 mb-4"><div class="card-body">
<form method="get" class="row g-3 align-items-end">
    <input type="hidden" name="mod" value="portal_documentos_clientes">
    <div class="col-lg-6"><label class="form-label fw-semibold">Buscar</label><input class="form-control" name="q" value="<?= htmlspecialchars($busca) ?>" placeholder="Cliente, título, arquivo ou processo"></div>
    <div class="col-lg-3"><label class="form-label fw-semibold">Situação</label><select class="form-select" name="status">
        <option value="">Todas</option>
        <?php foreach (['AGUARDANDO_ANALISE'=>'Aguardando análise','APROVADO'=>'Aprovado','CORRECAO_SOLICITADA'=>'Correção solicitada','ARQUIVADO'=>'Arquivado'] as $k=>$v): ?>
        <option value="<?= $k ?>" <?= $statusFiltro === $k ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?>
    </select></div>
    <div class="col-lg-3 d-flex gap-2"><button class="btn btn-primary flex-grow-1"><i class="bi bi-search"></i> Filtrar</button><a href="?mod=portal_documentos_clientes" class="btn btn-outline-secondary">Limpar</a></div>
</form></div></div>

<?php if (!$documentos): ?>
<div class="alert alert-info"><i class="bi bi-info-circle me-2"></i>Nenhum documento enviado pelos clientes foi encontrado.</div>
<?php else: ?>
<div class="row g-3">
<?php foreach ($documentos as $doc): [$rotulo,$cor] = rojexDocPortalStatus((string)$doc['status']); ?>
<div class="col-12"><div class="card shadow-sm border-0"><div class="card-body">
    <div class="d-flex flex-wrap justify-content-between gap-3">
        <div class="flex-grow-1">
            <div class="d-flex flex-wrap align-items-center gap-2 mb-2"><h5 class="mb-0"><?= htmlspecialchars((string)$doc['titulo']) ?></h5><span class="badge text-bg-<?= $cor ?>"><?= $rotulo ?></span></div>
            <div class="text-muted small mb-2"><strong>Cliente:</strong> <?= htmlspecialchars((string)$doc['cliente_nome']) ?><?php if (!empty($doc['numero_processo'])): ?> · <strong>Processo:</strong> <?= htmlspecialchars((string)$doc['numero_processo']) ?><?php endif; ?></div>
            <?php if (trim((string)$doc['descricao_cliente']) !== ''): ?><p class="mb-2"><?= nl2br(htmlspecialchars((string)$doc['descricao_cliente'])) ?></p><?php endif; ?>
            <div class="small text-muted"><i class="bi bi-file-earmark"></i> <?= htmlspecialchars((string)$doc['nome_original']) ?> · <?= rojexDocPortalTamanho((int)$doc['tamanho_bytes']) ?> · enviado em <?= date('d/m/Y H:i', strtotime((string)$doc['criado_em'])) ?></div>
        </div>
        <div class="d-flex flex-wrap align-content-start gap-2">
            <a class="btn btn-outline-primary" href="portal_documento_arquivo.php?id=<?= (int)$doc['id'] ?>" target="_blank" rel="noopener"><i class="bi bi-download"></i> Baixar</a>
            <form method="post" class="d-flex flex-wrap gap-2">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>"><input type="hidden" name="envio_id" value="<?= (int)$doc['id'] ?>">
                <button name="acao" value="aprovar" class="btn btn-success" onclick="return confirm('Marcar este documento como aprovado?')"><i class="bi bi-check-circle"></i> Aprovar</button>
                <button name="acao" value="solicitar_correcao" class="btn btn-warning" onclick="return confirm('Solicitar correção deste documento?')"><i class="bi bi-arrow-repeat"></i> Correção</button>
                <button name="acao" value="arquivar" class="btn btn-outline-secondary" onclick="return confirm('Arquivar este documento?')"><i class="bi bi-archive"></i> Arquivar</button>
            </form>
        </div>
    </div>
</div></div></div>
<?php endforeach; ?>
</div>
<?php endif; $conn->close(); ?>
