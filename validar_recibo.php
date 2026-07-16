<?php
declare(strict_types=1);
require_once __DIR__ . '/config/database.php';
$conn = conectar();

function hVal($v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }
function brlVal($v): string { return 'R$ ' . number_format((float)($v ?? 0), 2, ',', '.'); }
function dataVal($d): string { return empty($d) ? '-' : date('d/m/Y', strtotime($d)); }

$chave = trim((string)($_GET['chave'] ?? ''));
$recibo = null;
$erro = '';

if ($chave === '') {
    $erro = 'Chave de validação não informada.';
} elseif (!preg_match('/^[a-f0-9]{64}$/i', $chave)) {
    $erro = 'Formato de chave de validação inválido.';
} else {
    $stmt = $conn->prepare(
        "SELECT
            r.numero,
            r.nome_cliente,
            r.cpf_cnpj,
            r.processo_numero,
            r.data_emissao,
            r.data_pagamento,
            r.referente,
            r.forma_pagamento,
            r.valor,
            r.status,
            r.chave_validacao,
            r.tenant_id,
            r.escritorio_id,
            COALESCE(NULLIF(e.nome_fantasia,''), NULLIF(e.razao_social,''), 'Escritório responsável') AS escritorio_nome
         FROM recibos r
         LEFT JOIN escritorios_saas e
           ON e.id = r.escritorio_id
          AND e.tenant_id = r.tenant_id
         WHERE r.chave_validacao=?
           AND COALESCE(r.deletado,0)=0
         LIMIT 1"
    );
    $stmt->bind_param('s', $chave);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res && $res->num_rows > 0) {
        $recibo = $res->fetch_assoc();
    } else {
        $erro = 'Recibo não encontrado ou chave inválida.';
    }
}
$conn->close();
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Validação de Recibo | ROJEX.AI</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
body{background:#f4f6f8;font-family:Arial, Helvetica, sans-serif}.card-validacao{max-width:760px;margin:40px auto;border:0;border-radius:14px;box-shadow:0 10px 30px rgba(15,23,42,.12)}.selo{width:72px;height:72px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:34px;margin:auto}.ok{background:#dcfce7;color:#15803d}.erro{background:#fee2e2;color:#b91c1c}.titulo{color:#0d3b66;font-weight:800}
</style>
</head>
<body>
<div class="container py-4">
  <div class="card card-validacao">
    <div class="card-body p-4 p-md-5 text-center">
      <?php if ($recibo): ?>
        <div class="selo ok mb-3">✓</div>
        <h1 class="h3 titulo">Recibo válido</h1>
        <p class="text-muted">Este recibo foi localizado e validado na plataforma ROJEX.AI.</p>
        <p class="fw-semibold mb-0"><?= hVal($recibo['escritorio_nome'] ?? 'Escritório responsável') ?></p>
        <div class="table-responsive mt-4 text-start">
          <table class="table table-bordered align-middle">
            <tr><th style="width:220px">Número</th><td><?= hVal($recibo['numero']) ?></td></tr>
            <tr><th>Cliente</th><td><?= hVal($recibo['nome_cliente']) ?><?= $recibo['cpf_cnpj'] ? ' — CPF/CNPJ: '.hVal($recibo['cpf_cnpj']) : '' ?></td></tr>
            <tr><th>Referente</th><td><?= hVal($recibo['referente']) ?></td></tr>
            <tr><th>Valor</th><td><strong><?= brlVal($recibo['valor']) ?></strong></td></tr>
            <tr><th>Forma de pagamento</th><td><?= hVal($recibo['forma_pagamento'] ?: '-') ?></td></tr>
            <tr><th>Data de emissão</th><td><?= dataVal($recibo['data_emissao']) ?></td></tr>
            <tr><th>Data de pagamento</th><td><?= dataVal($recibo['data_pagamento']) ?></td></tr>
            <tr><th>Processo</th><td><?= hVal($recibo['processo_numero'] ?: '-') ?></td></tr>
            <tr><th>Status</th><td><span class="badge <?= $recibo['status']==='Emitido'?'bg-success':'bg-warning text-dark' ?>"><?= hVal($recibo['status']) ?></span></td></tr>
          </table>
        </div>
        <small class="text-muted">Chave: <?= hVal(substr((string)$recibo['chave_validacao'],0,32)) ?></small>
      <?php else: ?>
        <div class="selo erro mb-3">!</div>
        <h1 class="h3 titulo">Validação não encontrada</h1>
        <p class="text-muted mb-0"><?= hVal($erro) ?></p>
      <?php endif; ?>
    </div>
  </div>
</div>
</body>
</html>
