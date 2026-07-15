<?php
/**
 * ROJEX.AI — Relatório Enterprise de Recibos.
 * Endpoint independente para impressão e PDF sem o layout principal.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/integracoes.php';
require_once __DIR__ . '/../../core/Empresa.php';

iniciarSessaoSegura();
exigirLogin('../../auth/login.php');

if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Cache-Control: private, no-store, no-cache, must-revalidate');
}

$conn = conectar();
$empresa = new Empresa($conn);
date_default_timezone_set($empresa->timezone());

function recRelH(mixed $valor): string
{
    return htmlspecialchars((string)($valor ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function recRelMoeda(mixed $valor): string
{
    return 'R$ ' . number_format((float)($valor ?? 0), 2, ',', '.');
}

function recRelData(?string $data): string
{
    if ($data === null || $data === '' || $data === '0000-00-00') return '-';
    $timestamp = strtotime($data);
    return $timestamp ? date('d/m/Y', $timestamp) : '-';
}

$q = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 160, 'UTF-8');
$status = trim((string)($_GET['status'] ?? ''));
if (!in_array($status, ['Emitido', 'Cancelado'], true)) $status = '';

$where = ['COALESCE(deletado, 0) = 0'];
$params = [];
$types = '';

if ($q !== '') {
    $like = '%' . $q . '%';
    $where[] = '(numero LIKE ? OR nome_cliente LIKE ? OR cpf_cnpj LIKE ? OR processo_numero LIKE ? OR referente LIKE ?)';
    for ($i = 0; $i < 5; $i++) {
        $params[] = $like;
        $types .= 's';
    }
}
if ($status !== '') {
    $where[] = 'status = ?';
    $params[] = $status;
    $types .= 's';
}

$sql = "SELECT id, numero, nome_cliente, cpf_cnpj, processo_numero, referente,
               forma_pagamento, valor, data_emissao, data_pagamento, status
          FROM recibos
         WHERE " . implode(' AND ', $where) . "
         ORDER BY data_emissao DESC, numero DESC";
$stmt = $conn->prepare($sql);
if (!$stmt) {
    http_response_code(500);
    exit('Não foi possível preparar o relatório de recibos.');
}
if ($types !== '') $stmt->bind_param($types, ...$params);
$stmt->execute();
$res = $stmt->get_result();
$registros = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
$stmt->close();

$totais = ['registros' => count($registros), 'emitidos' => 0, 'cancelados' => 0, 'valor_emitido' => 0.0];
foreach ($registros as $registro) {
    if ((string)$registro['status'] === 'Emitido') {
        $totais['emitidos']++;
        $totais['valor_emitido'] += (float)$registro['valor'];
    } else {
        $totais['cancelados']++;
    }
}

$filtros = [];
if ($q !== '') $filtros[] = 'Busca: ' . $q;
if ($status !== '') $filtros[] = 'Status: ' . $status;

$usuario = trim((string)($_SESSION['nome'] ?? $_SESSION['username'] ?? 'Usuário'));
$perfil = trim((string)($_SESSION['perfil'] ?? 'Usuário'));
$logoSrc = '../../' . ltrim($empresa->logoPrincipal(), '/');
$corPrimaria = $empresa->corPrimaria();
$corSecundaria = $empresa->corSecundaria();
$corAccent = $empresa->corAccent();

if (function_exists('sgl_registrar_log')) {
    try {
        sgl_registrar_log(
            $conn,
            'Relatório de recibos emitido',
            'recibos',
            null,
            'Relatório Enterprise de recibos aberto para impressão ou PDF.',
            [
                'tipo_acao' => 'RELATORIO',
                'modulo' => 'Recibos',
                'origem' => 'modules/relatorios/recibos.php',
                'resultado' => 'SUCESSO',
                'nivel' => 'INFO',
                'dados_novos' => ['quantidade_registros' => count($registros), 'filtros' => $filtros],
            ]
        );
    } catch (Throwable $e) {
        error_log('[ROJEX RELATÓRIO RECIBOS] ' . $e->getMessage());
    }
}
$conn->close();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Relatório de Recibos — <?= recRelH($empresa->nomeEscritorio()) ?></title>
<style>
:root{--primary:<?= recRelH($corPrimaria) ?>;--secondary:<?= recRelH($corSecundaria) ?>;--accent:<?= recRelH($corAccent) ?>;--border:#d9dee7;--muted:#667085;--surface:#fff;--surface-alt:#f5f7fa;--text:#17202a}
*{box-sizing:border-box}body{margin:0;background:#eef1f5;color:var(--text);font-family:Arial,Helvetica,sans-serif;font-size:12px}.toolbar{max-width:1180px;margin:16px auto 0;display:flex;justify-content:flex-end;gap:8px}.btn{border:0;border-radius:7px;padding:10px 14px;font-weight:700;cursor:pointer;text-decoration:none;display:inline-block}.btn-primary{background:var(--secondary);color:#fff}.btn-light{background:#fff;color:#344054;border:1px solid var(--border)}.report{max-width:1180px;margin:12px auto 24px;background:#fff;padding:22px 24px;box-shadow:0 8px 28px rgba(16,24,40,.10)}.header{display:flex;align-items:center;gap:18px;padding-bottom:14px;border-bottom:3px solid var(--primary)}.logo{width:74px;height:74px;object-fit:contain}.header-main{flex:1}.system{font-size:11px;font-weight:800;color:var(--secondary);letter-spacing:.08em;text-transform:uppercase}.office{font-size:20px;font-weight:800;margin-top:3px}.title{font-size:17px;font-weight:800;margin-top:5px}.meta{text-align:right;color:var(--muted);line-height:1.5}.filters{margin-top:12px;padding:9px 11px;background:var(--surface-alt);border:1px solid var(--border);border-radius:7px;color:#475467}.summary{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin:14px 0}.card{border:1px solid var(--border);border-radius:8px;padding:11px;background:#fff}.card .label{font-size:10px;text-transform:uppercase;color:var(--muted);font-weight:700}.card .value{font-size:17px;font-weight:800;margin-top:4px}.table-wrap{overflow:visible}table{width:100%;border-collapse:collapse;font-size:10.5px}thead{display:table-header-group}th{background:var(--primary);color:#fff;text-align:left;padding:8px 6px;border:1px solid var(--primary)}td{padding:7px 6px;border:1px solid var(--border);vertical-align:top}tbody tr:nth-child(even){background:#fafbfc}.num{text-align:right;white-space:nowrap}.center{text-align:center}.status{font-weight:700}.footer{margin-top:14px;padding-top:10px;border-top:1px solid var(--border);display:flex;justify-content:space-between;color:var(--muted);font-size:10px}.empty{text-align:center;padding:24px;color:var(--muted)}
@page{size:A4 landscape;margin:10mm}@media print{body{background:#fff}.toolbar{display:none}.report{max-width:none;margin:0;padding:0;box-shadow:none}.summary{break-inside:avoid}.card{break-inside:avoid}tr{break-inside:avoid}.footer{position:relative}}
@media(max-width:800px){.summary{grid-template-columns:repeat(2,1fr)}.header{align-items:flex-start}.meta{display:none}.report{margin:0;padding:14px}.toolbar{padding:0 10px}}
</style>
</head>
<body>
<div class="toolbar"><a href="../../index.php?mod=recibos" class="btn btn-light">Voltar</a><button type="button" class="btn btn-primary" onclick="window.print()">Imprimir / Salvar PDF</button></div>
<main class="report">
<header class="header">
<img src="<?= recRelH($logoSrc) ?>" class="logo" alt="Logo">
<div class="header-main"><div class="system"><?= recRelH($empresa->nomeSistema()) ?></div><div class="office"><?= recRelH($empresa->nomeEscritorio()) ?></div><div class="title">Relatório de Recibos</div></div>
<div class="meta">Emitido em <?= date('d/m/Y H:i') ?><br>Responsável: <?= recRelH($usuario) ?><br>Perfil: <?= recRelH($perfil) ?></div>
</header>
<?php if ($filtros): ?><div class="filters"><strong>Filtros aplicados:</strong> <?= recRelH(implode(' · ', $filtros)) ?></div><?php endif; ?>
<section class="summary">
<div class="card"><div class="label">Registros</div><div class="value"><?= (int)$totais['registros'] ?></div></div>
<div class="card"><div class="label">Emitidos</div><div class="value"><?= (int)$totais['emitidos'] ?></div></div>
<div class="card"><div class="label">Valor emitido</div><div class="value"><?= recRelMoeda($totais['valor_emitido']) ?></div></div>
<div class="card"><div class="label">Cancelados</div><div class="value"><?= (int)$totais['cancelados'] ?></div></div>
</section>
<div class="table-wrap"><table><thead><tr><th>Número</th><th>Cliente</th><th>CPF/CNPJ</th><th>Referente</th><th>Processo</th><th>Forma</th><th>Emissão</th><th class="num">Valor</th><th class="center">Status</th></tr></thead><tbody>
<?php if (!$registros): ?><tr><td colspan="9" class="empty">Nenhum recibo localizado para os filtros informados.</td></tr><?php else: foreach ($registros as $r): ?>
<tr><td><?= recRelH($r['numero']) ?></td><td><?= recRelH($r['nome_cliente']) ?></td><td><?= recRelH($r['cpf_cnpj'] ?: '-') ?></td><td><?= recRelH($r['referente']) ?></td><td><?= recRelH($r['processo_numero'] ?: '-') ?></td><td><?= recRelH($r['forma_pagamento'] ?: '-') ?></td><td><?= recRelData($r['data_emissao']) ?></td><td class="num"><?= recRelMoeda($r['valor']) ?></td><td class="center status"><?= recRelH($r['status']) ?></td></tr>
<?php endforeach; endif; ?>
</tbody></table></div>
<footer class="footer"><span><?= recRelH($empresa->poweredBy()) ?></span><span>Documento administrativo — uso interno</span></footer>
</main>
</body>
</html>
