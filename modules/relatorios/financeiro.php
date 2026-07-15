<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/integracoes.php';
require_once __DIR__ . '/../../core/Empresa.php';

iniciarSessaoSegura();
exigirLogin('../../auth/login.php');

$conn = conectar();
$empresa = new Empresa($conn);
date_default_timezone_set($empresa->timezone());

$aba = ($_GET['aba'] ?? 'cp') === 'cr' ? 'cr' : 'cp';
$titulo = $aba === 'cp' ? 'Relatório de Contas a Pagar' : 'Relatório de Contas a Receber';

function hFinRel(mixed $v): string {
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function brlFinRel(mixed $v): string {
    return 'R$ ' . number_format((float)($v ?? 0), 2, ',', '.');
}
function dataFinRel(mixed $v): string {
    $v = trim((string)($v ?? ''));
    return $v === '' || $v === '0000-00-00' ? '-' : date('d/m/Y', strtotime($v));
}

if ($aba === 'cp') {
    $sql = "SELECT cp.*, b.nome AS banco_nome
              FROM contas_pagar cp
              LEFT JOIN bancos_caixa b ON b.id = cp.banco_id
             WHERE COALESCE(cp.deletado,0)=0
             ORDER BY cp.data_vencimento DESC, cp.id DESC";
} else {
    $sql = "SELECT cr.*, c.nome AS cliente_nome, b.nome AS banco_nome
              FROM contas_receber cr
              LEFT JOIN clientes c ON c.id = cr.cliente_id
              LEFT JOIN bancos_caixa b ON b.id = cr.banco_id
             WHERE COALESCE(cr.deletado,0)=0
             ORDER BY cr.data_vencimento DESC, cr.id DESC";
}

$lista = [];
$res = $conn->query($sql);
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $lista[] = $row;
    }
}

$totalValor = 0.0;
$totalPago = 0.0;
$totalPendente = 0.0;
foreach ($lista as $row) {
    $totalValor += (float)($row['valor'] ?? 0);
    $totalPago += (float)($row['valor_pago'] ?? 0);
    $totalPendente += (float)($row['valor_pendente'] ?? 0);
}

if (function_exists('sgl_registrar_log')) {
    sgl_registrar_log(
        $conn,
        'Emitiu relatório financeiro',
        $aba === 'cp' ? 'contas_pagar' : 'contas_receber',
        null,
        $titulo . ' com ' . count($lista) . ' registro(s).',
        [
            'tipo_acao' => 'RELATORIO',
            'modulo' => 'Financeiro',
            'origem' => 'Relatório Enterprise',
            'resultado' => 'SUCESSO',
            'nivel' => 'INFO',
        ]
    );
}

$usuario = (string)($_SESSION['nome'] ?? $_SESSION['username'] ?? 'Usuário');
$perfil = (string)($_SESSION['perfil'] ?? 'Usuário');
$logo = '../../' . ltrim($empresa->logoPrincipal(), '/');
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<title><?= hFinRel($titulo) ?> — <?= hFinRel($empresa->nomeSistema()) ?></title>
<style>
@page { size: A4 landscape; margin: 12mm; }
* { box-sizing: border-box; }
body { font-family: Arial, Helvetica, sans-serif; margin:0; color:#1f2937; background:#f3f4f6; }
.toolbar { display:flex; justify-content:space-between; align-items:center; padding:14px 18px; background:#fff; border-bottom:1px solid #d1d5db; }
.toolbar a,.toolbar button { border:1px solid #cbd5e1; background:#fff; padding:9px 13px; border-radius:7px; text-decoration:none; color:#111827; cursor:pointer; font-weight:700; }
.page { max-width:1200px; margin:18px auto; background:#fff; padding:20px; box-shadow:0 8px 26px rgba(0,0,0,.08); }
.header { display:grid; grid-template-columns:90px 1fr 250px; gap:16px; align-items:center; border-bottom:3px solid <?= hFinRel($empresa->corPrimaria()) ?>; padding-bottom:12px; }
.logo { max-width:82px; max-height:68px; object-fit:contain; }
.header h1 { margin:0; font-size:21px; color:<?= hFinRel($empresa->corPrimaria()) ?>; }
.header p { margin:3px 0; color:#4b5563; font-size:12px; }
.meta { text-align:right; font-size:11px; color:#4b5563; line-height:1.5; }
.cards { display:grid; grid-template-columns:repeat(4,1fr); gap:10px; margin:16px 0; }
.card { border:1px solid #dbe2ea; border-radius:8px; padding:11px; }
.card .label { font-size:10px; text-transform:uppercase; color:#6b7280; font-weight:700; }
.card .value { margin-top:4px; font-size:17px; font-weight:800; }
table { width:100%; border-collapse:collapse; font-size:10px; }
thead th { background:<?= hFinRel($empresa->corPrimaria()) ?>; color:#fff; padding:7px 6px; text-align:left; }
tbody td { padding:6px; border-bottom:1px solid #e5e7eb; vertical-align:top; }
tbody tr:nth-child(even) { background:#f8fafc; }
.num { text-align:right; white-space:nowrap; }
.center { text-align:center; }
.footer { display:flex; justify-content:space-between; border-top:1px solid #d1d5db; margin-top:16px; padding-top:8px; font-size:9px; color:#6b7280; }
@media print {
  body { background:#fff; -webkit-print-color-adjust:exact; print-color-adjust:exact; }
  .toolbar { display:none; }
  .page { margin:0; padding:0; box-shadow:none; max-width:none; }
  tr { break-inside:avoid; }
}
</style>
</head>
<body>
<div class="toolbar">
  <a href="../../index.php?mod=financeiro&aba=<?= hFinRel($aba) ?>">← Voltar ao Financeiro</a>
  <button onclick="window.print()">Imprimir / Salvar PDF</button>
</div>
<div class="page">
  <div class="header">
    <div><img class="logo" src="<?= hFinRel($logo) ?>" alt="Logo"></div>
    <div>
      <h1><?= hFinRel($titulo) ?></h1>
      <p><?= hFinRel($empresa->nomeEscritorio()) ?></p>
      <p><?= hFinRel($empresa->razaoSocial()) ?></p>
    </div>
    <div class="meta">
      Emitido em: <strong><?= date('d/m/Y H:i') ?></strong><br>
      Usuário: <strong><?= hFinRel($usuario) ?></strong><br>
      Perfil: <?= hFinRel($perfil) ?><br>
      Registros: <?= count($lista) ?>
    </div>
  </div>

  <div class="cards">
    <div class="card"><div class="label">Quantidade</div><div class="value"><?= count($lista) ?></div></div>
    <div class="card"><div class="label">Valor total</div><div class="value"><?= brlFinRel($totalValor) ?></div></div>
    <div class="card"><div class="label"><?= $aba === 'cp' ? 'Total pago' : 'Total recebido' ?></div><div class="value"><?= brlFinRel($totalPago) ?></div></div>
    <div class="card"><div class="label">Saldo pendente</div><div class="value"><?= brlFinRel($totalPendente) ?></div></div>
  </div>

  <table>
    <thead>
    <?php if ($aba === 'cp'): ?>
      <tr>
        <th>ID</th><th>Descrição</th><th>Categoria</th><th>Fornecedor</th><th>Banco/Caixa</th>
        <th class="num">Valor</th><th class="num">Pago</th><th class="num">Saldo</th>
        <th>Vencimento</th><th>Pagamento</th><th>Status</th>
      </tr>
    <?php else: ?>
      <tr>
        <th>ID</th><th>Descrição</th><th>Cliente</th><th>Banco/Caixa</th>
        <th class="num">Valor</th><th class="num">Recebido</th><th class="num">Saldo</th>
        <th>Vencimento</th><th>Recebimento</th><th>Forma</th><th>Status</th>
      </tr>
    <?php endif; ?>
    </thead>
    <tbody>
    <?php if (!$lista): ?>
      <tr><td colspan="11" class="center">Nenhum registro encontrado.</td></tr>
    <?php elseif ($aba === 'cp'): foreach ($lista as $row): ?>
      <tr>
        <td><?= hFinRel($row['id']) ?></td>
        <td><?= hFinRel($row['descricao'] ?? '-') ?></td>
        <td><?= hFinRel($row['categoria'] ?? '-') ?></td>
        <td><?= hFinRel($row['fornecedor'] ?? '-') ?></td>
        <td><?= hFinRel($row['banco_nome'] ?? '-') ?></td>
        <td class="num"><?= brlFinRel($row['valor'] ?? 0) ?></td>
        <td class="num"><?= brlFinRel($row['valor_pago'] ?? 0) ?></td>
        <td class="num"><?= brlFinRel($row['valor_pendente'] ?? 0) ?></td>
        <td><?= dataFinRel($row['data_vencimento'] ?? '') ?></td>
        <td><?= dataFinRel($row['data_pagamento'] ?? '') ?></td>
        <td><?= hFinRel($row['status'] ?? '-') ?></td>
      </tr>
    <?php endforeach; else: foreach ($lista as $row): ?>
      <tr>
        <td><?= hFinRel($row['id']) ?></td>
        <td><?= hFinRel($row['descricao'] ?? '-') ?></td>
        <td><?= hFinRel($row['cliente_nome'] ?? '-') ?></td>
        <td><?= hFinRel($row['banco_nome'] ?? '-') ?></td>
        <td class="num"><?= brlFinRel($row['valor'] ?? 0) ?></td>
        <td class="num"><?= brlFinRel($row['valor_pago'] ?? 0) ?></td>
        <td class="num"><?= brlFinRel($row['valor_pendente'] ?? 0) ?></td>
        <td><?= dataFinRel($row['data_vencimento'] ?? '') ?></td>
        <td><?= dataFinRel($row['data_recebimento'] ?? '') ?></td>
        <td><?= hFinRel($row['forma_recebimento'] ?? '-') ?></td>
        <td><?= hFinRel($row['status'] ?? '-') ?></td>
      </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table>

  <div class="footer">
    <span><?= hFinRel($empresa->nomeSistema()) ?> — ERP Jurídico Enterprise</span>
    <span><?= hFinRel($empresa->poweredBy()) ?></span>
    <span><?= date('d/m/Y H:i') ?></span>
  </div>
</div>
</body>
</html>
<?php $conn->close(); ?>
