<?php
$conn = conectar();
require_once __DIR__ . '/../config/integracoes.php';
if (function_exists('sgl_garantir_logs')) { sgl_garantir_logs($conn); }
$acao = $_GET['acao'] ?? 'listar';
$msg = '';

function hRec($v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }
function brlRec($v): string { return 'R$ ' . number_format((float)($v ?? 0), 2, ',', '.'); }
function dataRec($d): string { return empty($d) ? '-' : date('d/m/Y', strtotime($d)); }
function brlParaFloatRec(string $valor): float {
    $v = trim(str_replace(['R$', ' '], '', $valor));
    if ($v === '') return 0.0;
    if (strpos($v, ',') !== false) $v = str_replace(',', '.', str_replace('.', '', $v));
    return is_numeric($v) ? (float)$v : 0.0;
}
function sglReciboColunaExiste(mysqli $conn, string $coluna): bool {
    $coluna = $conn->real_escape_string($coluna);
    $res = $conn->query("SHOW COLUMNS FROM `recibos` LIKE '{$coluna}'");
    return $res && $res->num_rows > 0;
}

function sglReciboGarantirColuna(mysqli $conn, string $coluna, string $definicao): void {
    if (!sglReciboColunaExiste($conn, $coluna)) {
        $conn->query("ALTER TABLE `recibos` ADD COLUMN {$definicao}");
    }
}

function sglReciboTabela(mysqli $conn): void {
    $conn->query("CREATE TABLE IF NOT EXISTS recibos (
        id VARCHAR(20) PRIMARY KEY,
        numero VARCHAR(30) NOT NULL UNIQUE,
        cliente_id VARCHAR(10) NULL,
        nome_cliente VARCHAR(150) NOT NULL,
        cpf_cnpj VARCHAR(25) NULL,
        processo_numero VARCHAR(80) NULL,
        honorario_id VARCHAR(20) NULL,
        parcela_id VARCHAR(20) NULL,
        data_emissao DATE NOT NULL,
        data_pagamento DATE NULL,
        referente VARCHAR(255) NOT NULL,
        forma_pagamento VARCHAR(80) NULL,
        valor DECIMAL(12,2) NOT NULL DEFAULT 0,
        observacoes TEXT NULL,
        status ENUM('Emitido','Cancelado') NOT NULL DEFAULT 'Emitido',
        chave_validacao VARCHAR(80) NULL,
        deletado TINYINT(1) NOT NULL DEFAULT 0,
        criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_rec_numero (numero),
        INDEX idx_rec_cliente (cliente_id),
        INDEX idx_rec_status (status),
        INDEX idx_rec_deletado (deletado),
        INDEX idx_rec_data (data_emissao)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Compatibilidade com bancos criados nas fases anteriores.
    sglReciboGarantirColuna($conn, 'processo_numero', "processo_numero VARCHAR(80) NULL AFTER cpf_cnpj");
    sglReciboGarantirColuna($conn, 'honorario_id', "honorario_id VARCHAR(20) NULL AFTER processo_numero");
    sglReciboGarantirColuna($conn, 'parcela_id', "parcela_id VARCHAR(20) NULL AFTER honorario_id");
    sglReciboGarantirColuna($conn, 'data_emissao', "data_emissao DATE NULL AFTER parcela_id");
    sglReciboGarantirColuna($conn, 'data_pagamento', "data_pagamento DATE NULL AFTER data_emissao");
    sglReciboGarantirColuna($conn, 'referente', "referente VARCHAR(255) NULL AFTER data_pagamento");
    sglReciboGarantirColuna($conn, 'observacoes', "observacoes TEXT NULL AFTER valor");
    sglReciboGarantirColuna($conn, 'chave_validacao', "chave_validacao VARCHAR(80) NULL AFTER status");
    sglReciboGarantirColuna($conn, 'deletado', "deletado TINYINT(1) NOT NULL DEFAULT 0 AFTER chave_validacao");

    if (sglReciboColunaExiste($conn, 'data_recibo')) {
        $conn->query("UPDATE recibos SET data_emissao = COALESCE(data_emissao, data_recibo) WHERE data_emissao IS NULL");
    }
    if (sglReciboColunaExiste($conn, 'descricao')) {
        $conn->query("UPDATE recibos SET referente = COALESCE(NULLIF(referente,''), descricao, 'Honorários advocatícios') WHERE referente IS NULL OR referente = ''");
    } else {
        $conn->query("UPDATE recibos SET referente = 'Honorários advocatícios' WHERE referente IS NULL OR referente = ''");
    }
    $conn->query("UPDATE recibos SET data_emissao = CURDATE() WHERE data_emissao IS NULL");
}
function gerarIdRecibo(mysqli $conn): string {
    $res = $conn->query("SELECT id FROM recibos ORDER BY CAST(SUBSTRING(id, 4) AS UNSIGNED) DESC LIMIT 1");
    if (!$res || $res->num_rows === 0) return 'REC001';
    $num = (int)substr($res->fetch_assoc()['id'], 3) + 1;
    return 'REC' . str_pad((string)$num, 3, '0', STR_PAD_LEFT);
}
function gerarNumeroRecibo(mysqli $conn): string {
    $ano = date('Y');
    $prefixo = 'REC-' . $ano . '-';
    $stmt = $conn->prepare("SELECT numero FROM recibos WHERE numero LIKE CONCAT(?, '%') ORDER BY numero DESC LIMIT 1");
    $stmt->bind_param('s', $prefixo);
    $stmt->execute();
    $res = $stmt->get_result();
    if (!$res || $res->num_rows === 0) return $prefixo . '0001';
    $ultimo = $res->fetch_assoc()['numero'];
    $seq = (int)substr($ultimo, -4) + 1;
    return $prefixo . str_pad((string)$seq, 4, '0', STR_PAD_LEFT);
}
function getClientesRec(mysqli $conn): array {
    $dados = [];
    $res = $conn->query("SELECT id, nome, cpf_cnpj FROM clientes WHERE COALESCE(deletado,0)=0 ORDER BY nome ASC");
    if ($res) while($r=$res->fetch_assoc()) $dados[]=$r;
    return $dados;
}

function urlValidacaoRecibo(array $rec): string {
    $chave = (string)($rec['chave_validacao'] ?? '');
    if (function_exists('getBaseUrl')) {
        return rtrim(getBaseUrl(), '/') . '/validar_recibo.php?chave=' . urlencode($chave);
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? '') === '443');
    $scheme = $https ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
    return $scheme . '://' . $host . ($dir && $dir !== '/' ? $dir : '') . '/validar_recibo.php?chave=' . urlencode($chave);
}
function qrUrlRecibo(string $url): string {
    return 'https://api.qrserver.com/v1/create-qr-code/?size=110x110&margin=1&data=' . urlencode($url);
}

function getRecibo(mysqli $conn, string $id): ?array {
    $stmt = $conn->prepare("SELECT * FROM recibos WHERE id=? LIMIT 1");
    $stmt->bind_param('s', $id);
    $stmt->execute();
    $res = $stmt->get_result();
    return ($res && $res->num_rows) ? $res->fetch_assoc() : null;
}

sglReciboTabela($conn);
$csrf = gerarTokenCsrf();
$clientes = getClientesRec($conn);

if (isset($_GET['cancelar'])) {
    if (!validarTokenCsrf($_GET['csrf_token'] ?? null)) {
        $msg = '<div class="alert alert-danger">Token de segurança inválido.</div>';
    } else {
        $id = (string)$_GET['cancelar'];
        $stmt = $conn->prepare("UPDATE recibos SET status='Cancelado' WHERE id=?");
        $stmt->bind_param('s', $id); $stmt->execute();
        $msg = '<div class="alert alert-warning">Recibo cancelado com sucesso.</div>';
        if (function_exists('sgl_registrar_log')) { sgl_registrar_log($conn, 'Cancelou recibo', 'recibos', $id); }
    }
    $acao = 'listar';
}
if (isset($_GET['excluir'])) {
    if (!validarTokenCsrf($_GET['csrf_token'] ?? null)) {
        $msg = '<div class="alert alert-danger">Token de segurança inválido.</div>';
    } else {
        $id = (string)$_GET['excluir'];
        $stmt = $conn->prepare("UPDATE recibos SET deletado=1 WHERE id=?");
        $stmt->bind_param('s', $id); $stmt->execute();
        $msg = '<div class="alert alert-warning">Recibo movido para a lixeira.</div>';
        if (function_exists('sgl_registrar_log')) { sgl_registrar_log($conn, 'Moveu recibo para lixeira', 'recibos', $id); }
    }
    $acao = 'listar';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validarTokenCsrf($_POST['csrf_token'] ?? null)) {
        $msg = '<div class="alert alert-danger">Token de segurança inválido. Atualize a página e tente novamente.</div>';
        $acao = 'listar';
    } else {
        $id = trim($_POST['id'] ?? '');
        $cliente_id = trim($_POST['cliente_id'] ?? '');
        $nome_cliente = trim($_POST['nome_cliente'] ?? '');
        $cpf_cnpj = trim($_POST['cpf_cnpj'] ?? '');
        if ($cliente_id !== '') {
            $stmt = $conn->prepare("SELECT nome, cpf_cnpj FROM clientes WHERE id=? LIMIT 1");
            $stmt->bind_param('s', $cliente_id); $stmt->execute();
            $cli = $stmt->get_result()->fetch_assoc();
            if ($cli) { $nome_cliente = $cli['nome']; $cpf_cnpj = $cli['cpf_cnpj']; }
        }
        $processo_numero = trim($_POST['processo_numero'] ?? '');
        $honorario_id = trim($_POST['honorario_id'] ?? '');
        $parcela_id = trim($_POST['parcela_id'] ?? '');
        $data_emissao = $_POST['data_emissao'] ?: date('Y-m-d');
        $data_pagamento = $_POST['data_pagamento'] ?: null;
        $referente = trim($_POST['referente'] ?? '');
        $forma_pagamento = trim($_POST['forma_pagamento'] ?? '');
        $valor = brlParaFloatRec((string)($_POST['valor'] ?? '0'));
        $observacoes = trim($_POST['observacoes'] ?? '');

        if ($nome_cliente === '' || $referente === '' || $valor <= 0) {
            $msg = '<div class="alert alert-danger">Informe cliente, referente e valor maior que zero.</div>';
            $acao = $id ? 'editar' : 'novo';
            $_GET['id'] = $id;
        } else {
            if ($id === '') {
                $id = gerarIdRecibo($conn);
                $numero = gerarNumeroRecibo($conn);
                $chave = hash('sha256', $numero . $nome_cliente . microtime(true));
                $stmt = $conn->prepare("INSERT INTO recibos (id, numero, cliente_id, nome_cliente, cpf_cnpj, processo_numero, honorario_id, parcela_id, data_emissao, data_pagamento, referente, forma_pagamento, valor, observacoes, chave_validacao) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param('ssssssssssssdss', $id, $numero, $cliente_id, $nome_cliente, $cpf_cnpj, $processo_numero, $honorario_id, $parcela_id, $data_emissao, $data_pagamento, $referente, $forma_pagamento, $valor, $observacoes, $chave);
                $stmt->execute();
                $msg = '<div class="alert alert-success">Recibo emitido com sucesso.</div>';
                if (function_exists('sgl_registrar_log')) { sgl_registrar_log($conn, 'Emitiu recibo', 'recibos', $id, $numero); }
            } else {
                $stmt = $conn->prepare("UPDATE recibos SET cliente_id=?, nome_cliente=?, cpf_cnpj=?, processo_numero=?, honorario_id=?, parcela_id=?, data_emissao=?, data_pagamento=?, referente=?, forma_pagamento=?, valor=?, observacoes=? WHERE id=?");
                $stmt->bind_param('ssssssssssdss', $cliente_id, $nome_cliente, $cpf_cnpj, $processo_numero, $honorario_id, $parcela_id, $data_emissao, $data_pagamento, $referente, $forma_pagamento, $valor, $observacoes, $id);
                $stmt->execute();
                $msg = '<div class="alert alert-success">Recibo atualizado com sucesso.</div>';
                if (function_exists('sgl_registrar_log')) { sgl_registrar_log($conn, 'Atualizou recibo', 'recibos', $id); }
            }
            $acao = 'listar';
        }
    }
}

if ($acao === 'imprimir'):
    $id = (string)($_GET['id'] ?? '');
    $rec = getRecibo($conn, $id);
    if (!$rec) { echo '<div class="alert alert-danger">Recibo não encontrado.</div>'; return; }
    $urlValidacao = urlValidacaoRecibo($rec);
    $qrValidacao = qrUrlRecibo($urlValidacao);
?>
<style>
/* RECIBO EM A5 REAL (14,8cm x 21cm) - layout compacto para impressão/PDF */
@page { size: A5 portrait; margin: 8mm; }
@media print {
    .sidebar, .btn, .no-print, nav, header, .navbar { display:none!important; }
    html, body { margin:0!important; padding:0!important; background:#fff!important; }
    main, .content, .container-fluid { margin:0!important; padding:0!important; width:auto!important; background:#fff!important; }
    .recibo-a5-page { width:132mm!important; min-height:auto!important; margin:0 auto!important; padding:0!important; box-shadow:none!important; border:none!important; }
    .recibo-print { width:132mm!important; max-width:132mm!important; min-height:auto!important; margin:0!important; padding:0!important; border:none!important; box-shadow:none!important; }
}
.recibo-a5-page{width:148mm;max-width:148mm;margin:0 auto;background:#fff;padding:8mm;box-sizing:border-box;border:1px solid #e5e7eb;}
.recibo-print{width:132mm;max-width:132mm;margin:0 auto;background:#fff;color:#111;font-family:Arial, Helvetica, sans-serif;font-size:10.8pt;line-height:1.28;box-sizing:border-box;}
.recibo-header{display:grid;grid-template-columns:24mm 1fr 24mm;align-items:center;margin-bottom:4mm;}
.recibo-qr{width:20mm;height:20mm;object-fit:contain;float:right;}
.recibo-qr-label{font-size:6.8pt;color:#555;text-align:center;margin-top:1mm;}
.recibo-logo{max-width:18mm!important;max-height:18mm!important;object-fit:contain;}
.recibo-title{letter-spacing:1px;font-size:18pt;font-weight:800;color:#0d3b66;text-align:center;line-height:1.1;margin:0;}
.recibo-subtitle{text-align:center;color:#555;font-size:9.5pt;margin-top:1.5mm;}
.recibo-box{border:1px solid #e2e8f0;border-radius:4px;padding:3mm 4mm;background:#fbfbfb;margin-bottom:3mm;}
.recibo-info{display:grid;grid-template-columns:1fr 1fr;gap:2.5mm 5mm;border:1px solid #e2e8f0;border-radius:4px;padding:3mm 4mm;margin-bottom:3mm;}
.recibo-info div{min-height:10mm;}
.recibo-obs{border:1px solid #e2e8f0;border-radius:4px;padding:3mm 4mm;margin-bottom:4mm;}
.recibo-validacao{color:#666;font-size:8.5pt;margin-top:2mm;border-top:1px dashed #cbd5e1;padding-top:2mm;}
.assinatura-wrap{text-align:center;margin-top:13mm;}
.assinatura-recibo{border-top:1px solid #333;display:inline-block;width:72mm;padding-top:2mm;text-align:center;font-size:9pt;}
</style>
<div class="d-flex justify-content-between align-items-center mb-3 no-print">
    <h3 class="fw-bold"><i class="bi bi-receipt"></i> Recibo <?= hRec($rec['numero']) ?></h3>
    <div><button onclick="window.print()" class="btn btn-primary"><i class="bi bi-printer"></i> Imprimir em A5 (14,8 × 21 cm)</button> <a href="?mod=recibos" class="btn btn-outline-secondary">Voltar</a></div>
</div>
<div class="recibo-a5-page shadow-sm">
    <div class="recibo-print">
        <div class="recibo-header">
            <div><img src="<?= hRec($logo_src ?? 'assets/img/logo_custom.png') ?>" class="recibo-logo"></div>
            <div>
                <div class="recibo-title">RECIBO</div>
                <div class="recibo-subtitle">Nº <?= hRec($rec['numero']) ?> · Emitido em <?= dataRec($rec['data_emissao']) ?></div>
            </div>
            <div class="text-end"><img src="<?= hRec($qrValidacao) ?>" class="recibo-qr" alt="QR Code de validação"><div class="recibo-qr-label">Validar</div></div>
        </div>

        <div class="recibo-box">
            Recebemos de <strong><?= hRec($rec['nome_cliente']) ?></strong><?= $rec['cpf_cnpj'] ? ', inscrito(a) no CPF/CNPJ nº <strong>'.hRec($rec['cpf_cnpj']).'</strong>' : '' ?>, a importância de <strong><?= brlRec($rec['valor']) ?></strong>, referente a <strong><?= hRec($rec['referente']) ?></strong>.
        </div>

        <div class="recibo-info">
            <div><strong>Forma de pagamento:</strong><br><?= hRec($rec['forma_pagamento'] ?: '-') ?></div>
            <div><strong>Processo:</strong><br><?= hRec($rec['processo_numero'] ?: '-') ?></div>
            <div><strong>Data do pagamento:</strong><br><?= dataRec($rec['data_pagamento']) ?></div>
            <div><strong>Status:</strong><br><?= hRec($rec['status']) ?></div>
        </div>

        <?php if(!empty($rec['observacoes'])): ?>
        <div class="recibo-obs"><strong>Observações:</strong> <?= nl2br(hRec($rec['observacoes'])) ?></div>
        <?php endif; ?>

        <div class="recibo-validacao">Chave de validação: <?= hRec(substr((string)$rec['chave_validacao'],0,32)) ?><br>Validação online: <?= hRec($urlValidacao) ?></div>

        <div class="assinatura-wrap">
            <div class="assinatura-recibo">Assinatura</div>
        </div>
    </div>
</div>
<?php return; endif; ?>
<?php
$edit = null;
if (in_array($acao, ['novo','editar'], true)) {
    if ($acao === 'editar') $edit = getRecibo($conn, (string)($_GET['id'] ?? ''));
    $v = $edit ?? [
        'id'=>'','cliente_id'=>'','nome_cliente'=>'','cpf_cnpj'=>'','processo_numero'=>'','honorario_id'=>'','parcela_id'=>'','data_emissao'=>date('Y-m-d'),'data_pagamento'=>date('Y-m-d'),'referente'=>'','forma_pagamento'=>'PIX','valor'=>'','observacoes'=>''
    ];
?>
<div class="d-flex justify-content-between align-items-start mb-4">
    <div><h2 class="fw-bold text-primary"><i class="bi bi-receipt"></i> <?= $acao==='editar'?'Editar Recibo':'Novo Recibo' ?></h2><p class="text-muted mb-0">Emissão profissional de recibos vinculados a clientes, processos e honorários.</p></div>
    <a href="?mod=recibos" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> Voltar</a>
</div>
<?= $msg ?>
<div class="card shadow-sm border-0"><div class="card-body">
<form method="POST" class="row g-3">
    <input type="hidden" name="csrf_token" value="<?= hRec($csrf) ?>"><input type="hidden" name="id" value="<?= hRec($v['id']) ?>">
    <div class="col-md-6"><label class="form-label">Cliente cadastrado</label><select name="cliente_id" class="form-select"><option value="">Selecione ou preencha manualmente</option><?php foreach($clientes as $c): ?><option value="<?= hRec($c['id']) ?>" <?= $v['cliente_id']===$c['id']?'selected':'' ?>><?= hRec($c['nome']) ?> — <?= hRec($c['cpf_cnpj']) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-4"><label class="form-label">Nome manual</label><input name="nome_cliente" class="form-control" value="<?= hRec($v['nome_cliente']) ?>"></div>
    <div class="col-md-2"><label class="form-label">CPF/CNPJ</label><input name="cpf_cnpj" class="form-control" value="<?= hRec($v['cpf_cnpj']) ?>"></div>
    <div class="col-md-3"><label class="form-label">Data emissão</label><input type="date" name="data_emissao" class="form-control" value="<?= hRec($v['data_emissao']) ?>" required></div>
    <div class="col-md-3"><label class="form-label">Data pagamento</label><input type="date" name="data_pagamento" class="form-control" value="<?= hRec($v['data_pagamento']) ?>"></div>
    <div class="col-md-3"><label class="form-label">Forma de pagamento</label><select name="forma_pagamento" class="form-select"><?php foreach(['PIX','Dinheiro','Transferência','Cartão','Boleto','Outro'] as $fp): ?><option <?= $v['forma_pagamento']===$fp?'selected':'' ?>><?= $fp ?></option><?php endforeach; ?></select></div>
    <div class="col-md-3"><label class="form-label">Valor</label><input name="valor" class="form-control" value="<?= $v['valor'] ? brlRec($v['valor']) : '' ?>" placeholder="R$ 0,00" required></div>
    <div class="col-md-4"><label class="form-label">Nº processo</label><input name="processo_numero" class="form-control" value="<?= hRec($v['processo_numero']) ?>"></div>
    <div class="col-md-4"><label class="form-label">ID honorário</label><input name="honorario_id" class="form-control" value="<?= hRec($v['honorario_id']) ?>" placeholder="Opcional"></div>
    <div class="col-md-4"><label class="form-label">ID parcela</label><input name="parcela_id" class="form-control" value="<?= hRec($v['parcela_id']) ?>" placeholder="Opcional"></div>
    <div class="col-12"><label class="form-label">Referente a</label><input name="referente" class="form-control" value="<?= hRec($v['referente']) ?>" placeholder="Ex.: pagamento de honorários advocatícios" required></div>
    <div class="col-12"><label class="form-label">Observações</label><textarea name="observacoes" class="form-control" rows="3"><?= hRec($v['observacoes']) ?></textarea></div>
    <div class="col-12 text-end"><button class="btn btn-primary btn-lg"><i class="bi bi-check-circle"></i> Salvar Recibo</button></div>
</form>
</div></div>
<?php return; } ?>
<?php
$q = trim($_GET['q'] ?? ''); $status = trim($_GET['status'] ?? '');
$where = "deletado=0"; $params=[]; $types='';
if ($q !== '') { $like='%'.$q.'%'; $where .= " AND (numero LIKE ? OR nome_cliente LIKE ? OR cpf_cnpj LIKE ? OR processo_numero LIKE ? OR referente LIKE ?)"; $params=array_merge($params,[$like,$like,$like,$like,$like]); $types.='sssss'; }
if ($status !== '') { $where .= " AND status=?"; $params[]=$status; $types.='s'; }
$stmt = $conn->prepare("SELECT * FROM recibos WHERE $where ORDER BY data_emissao DESC, numero DESC");
if ($params) $stmt->bind_param($types, ...$params);
$stmt->execute(); $lista=$stmt->get_result();
$totEmitidos = $conn->query("SELECT COUNT(*) c FROM recibos WHERE deletado=0 AND status='Emitido'")->fetch_assoc()['c'] ?? 0;
$valorMes = $conn->query("SELECT COALESCE(SUM(valor),0) v FROM recibos WHERE deletado=0 AND status='Emitido' AND MONTH(data_emissao)=MONTH(CURDATE()) AND YEAR(data_emissao)=YEAR(CURDATE())")->fetch_assoc()['v'] ?? 0;
$cancelados = $conn->query("SELECT COUNT(*) c FROM recibos WHERE deletado=0 AND status='Cancelado'")->fetch_assoc()['c'] ?? 0;
?>
<div class="d-flex justify-content-between align-items-start mb-4">
    <div><h2 class="fw-bold text-primary"><i class="bi bi-receipt"></i> Recibos</h2><p class="text-muted mb-0">Geração, controle, impressão e histórico de recibos do escritório.</p></div>
    <a href="?mod=recibos&acao=novo" class="btn btn-primary"><i class="bi bi-plus-circle"></i> Novo Recibo</a>
</div>
<?= $msg ?>
<div class="row g-3 mb-4">
    <div class="col-md-4"><div class="card border-0 shadow-sm"><div class="card-body"><small class="text-muted">RECIBOS EMITIDOS</small><h3 class="fw-bold text-success"><?= (int)$totEmitidos ?></h3></div></div></div>
    <div class="col-md-4"><div class="card border-0 shadow-sm"><div class="card-body"><small class="text-muted">VALOR EMITIDO NO MÊS</small><h3 class="fw-bold text-primary"><?= brlRec($valorMes) ?></h3></div></div></div>
    <div class="col-md-4"><div class="card border-0 shadow-sm"><div class="card-body"><small class="text-muted">CANCELADOS</small><h3 class="fw-bold text-danger"><?= (int)$cancelados ?></h3></div></div></div>
</div>
<form class="card border-0 shadow-sm mb-4"><div class="card-body row g-2 align-items-end">
    <input type="hidden" name="mod" value="recibos">
    <div class="col-md-8"><label class="form-label">Pesquisa inteligente</label><input name="q" class="form-control" value="<?= hRec($q) ?>" placeholder="Número, cliente, CPF/CNPJ, processo ou referente"></div>
    <div class="col-md-2"><label class="form-label">Status</label><select name="status" class="form-select"><option value="">Todos</option><option <?= $status==='Emitido'?'selected':'' ?>>Emitido</option><option <?= $status==='Cancelado'?'selected':'' ?>>Cancelado</option></select></div>
    <div class="col-md-2 d-grid"><button class="btn btn-outline-primary"><i class="bi bi-search"></i> Buscar</button></div>
</div></form>
<div class="card shadow-sm border-0"><div class="card-header bg-dark text-white d-flex justify-content-between"><strong><i class="bi bi-list-ul"></i> Lista de Recibos</strong><span><?= $lista ? $lista->num_rows : 0 ?> registro(s)</span></div><div class="table-responsive"><table class="table table-hover align-middle mb-0"><thead><tr><th>Nº</th><th>Cliente</th><th>Referente</th><th>Valor</th><th>Emissão</th><th>Status</th><th class="text-end">Ações</th></tr></thead><tbody>
<?php if($lista && $lista->num_rows): while($row=$lista->fetch_assoc()): ?>
<tr><td class="fw-semibold"><?= hRec($row['numero']) ?></td><td><?= hRec($row['nome_cliente']) ?><br><small class="text-muted"><?= hRec($row['cpf_cnpj']) ?></small></td><td><?= hRec($row['referente']) ?><br><small class="text-muted"><?= hRec($row['processo_numero'] ?: '-') ?></small></td><td class="fw-bold"><?= brlRec($row['valor']) ?></td><td><?= dataRec($row['data_emissao']) ?></td><td><span class="badge bg-<?= $row['status']==='Emitido'?'success':'danger' ?>"><?= hRec($row['status']) ?></span></td><td class="text-end text-nowrap"><a href="?mod=recibos&acao=imprimir&id=<?= urlencode($row['id']) ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-printer"></i></a> <a href="?mod=recibos&acao=editar&id=<?= urlencode($row['id']) ?>" class="btn btn-sm btn-warning"><i class="bi bi-pencil"></i></a> <a href="?mod=recibos&cancelar=<?= urlencode($row['id']) ?>&csrf_token=<?= hRec($csrf) ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('Cancelar este recibo?')"><i class="bi bi-x-circle"></i></a> <a href="?mod=recibos&excluir=<?= urlencode($row['id']) ?>&csrf_token=<?= hRec($csrf) ?>" class="btn btn-sm btn-danger" onclick="return confirm('Mover este recibo para a lixeira?')"><i class="bi bi-trash"></i></a></td></tr>
<?php endwhile; else: ?><tr><td colspan="7" class="text-center text-muted py-4">Nenhum recibo encontrado.</td></tr><?php endif; ?>
</tbody></table></div></div>
