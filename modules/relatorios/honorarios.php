<?php
/**
 * ROJEX.AI — Relatório Enterprise de Honorários.
 * Endpoint independente do layout principal para impressão/PDF limpos.
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

function honRelH(mixed $valor): string
{
    return htmlspecialchars((string)($valor ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function honRelMoeda(mixed $valor): string
{
    return 'R$ ' . number_format((float)($valor ?? 0), 2, ',', '.');
}

function honRelData(?string $data): string
{
    if ($data === null || $data === '' || $data === '0000-00-00') {
        return '-';
    }

    $timestamp = strtotime($data);
    return $timestamp ? date('d/m/Y', $timestamp) : '-';
}

$busca = mb_substr(trim((string)($_GET['busca'] ?? '')), 0, 160, 'UTF-8');
$status = trim((string)($_GET['status'] ?? ''));
$tipo = trim((string)($_GET['tipo'] ?? ''));
$vencimento = trim((string)($_GET['vencimento'] ?? ''));

$statusPermitidos = ['Pendente', 'Parcial', 'Pago', 'Cancelado'];
$tiposPermitidos = ['Contrato', 'Êxito', 'Consultoria', 'Acordo', 'Outro'];
$vencimentosPermitidos = ['vencidos', 'hoje', '7dias'];

if (!in_array($status, $statusPermitidos, true)) {
    $status = '';
}
if (!in_array($tipo, $tiposPermitidos, true)) {
    $tipo = '';
}
if (!in_array($vencimento, $vencimentosPermitidos, true)) {
    $vencimento = '';
}

$where = ['h.deletado = 0'];
$params = [];
$types = '';

if ($status !== '') {
    $where[] = 'h.status = ?';
    $params[] = $status;
    $types .= 's';
}
if ($tipo !== '') {
    $where[] = 'h.tipo_honorario = ?';
    $params[] = $tipo;
    $types .= 's';
}
if ($vencimento === 'vencidos') {
    $where[] = "h.data_vencimento < CURDATE() AND h.status NOT IN ('Pago','Quitada','Cancelado')";
} elseif ($vencimento === 'hoje') {
    $where[] = 'h.data_vencimento = CURDATE()';
} elseif ($vencimento === '7dias') {
    $where[] = 'h.data_vencimento BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)';
}
if ($busca !== '') {
    $like = '%' . $busca . '%';
    $where[] = '(h.id LIKE ? OR h.cliente_id LIKE ? OR h.nome_cliente LIKE ? OR h.numero_processo LIKE ? OR h.tipo_honorario LIKE ? OR h.status LIKE ?)';
    for ($i = 0; $i < 6; $i++) {
        $params[] = $like;
        $types .= 's';
    }
}

$sql = "SELECT
            h.id,
            h.nome_cliente,
            h.numero_processo,
            h.tipo_honorario,
            h.valor_total,
            h.qtd_parcelas,
            h.data_vencimento,
            h.forma_pagamento,
            h.status,
            COALESCE((
                SELECT SUM(hp.valor_pago)
                FROM honorarios_parcelas hp
                WHERE hp.honorario_id = h.id
            ), h.valor_pago, 0) AS total_pago,
            COALESCE((
                SELECT SUM(hp.saldo_devedor)
                FROM honorarios_parcelas hp
                WHERE hp.honorario_id = h.id
            ), h.valor_pendente, 0) AS total_saldo
        FROM honorarios h
        WHERE " . implode(' AND ', $where) . "
        ORDER BY CAST(SUBSTRING(h.id, 4) AS UNSIGNED) DESC";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    http_response_code(500);
    exit('Não foi possível preparar o relatório.');
}
if ($types !== '') {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$resultado = $stmt->get_result();
$registros = $resultado ? $resultado->fetch_all(MYSQLI_ASSOC) : [];
$stmt->close();

$totais = [
    'registros' => count($registros),
    'valor_total' => 0.0,
    'valor_pago' => 0.0,
    'saldo' => 0.0,
    'pendentes' => 0,
    'vencidos' => 0,
];

$hoje = date('Y-m-d');
foreach ($registros as $registro) {
    $totais['valor_total'] += (float)$registro['valor_total'];
    $totais['valor_pago'] += (float)$registro['total_pago'];
    $totais['saldo'] += (float)$registro['total_saldo'];

    if (in_array((string)$registro['status'], ['Pendente', 'Parcial'], true)) {
        $totais['pendentes']++;
    }
    if (
        !empty($registro['data_vencimento'])
        && (string)$registro['data_vencimento'] < $hoje
        && !in_array((string)$registro['status'], ['Pago', 'Quitada', 'Cancelado'], true)
    ) {
        $totais['vencidos']++;
    }
}

$filtrosAtivos = [];
if ($busca !== '') $filtrosAtivos[] = 'Busca: ' . $busca;
if ($status !== '') $filtrosAtivos[] = 'Status: ' . $status;
if ($tipo !== '') $filtrosAtivos[] = 'Tipo: ' . $tipo;
if ($vencimento !== '') {
    $rotulosVencimento = [
        'vencidos' => 'Vencidos',
        'hoje' => 'Vencimento hoje',
        '7dias' => 'Próximos 7 dias',
    ];
    $filtrosAtivos[] = 'Vencimento: ' . ($rotulosVencimento[$vencimento] ?? $vencimento);
}

$usuario = trim((string)($_SESSION['nome'] ?? $_SESSION['username'] ?? 'Usuário'));
$perfil = trim((string)($_SESSION['perfil'] ?? 'Usuário'));
$logoRelativo = ltrim($empresa->logoPrincipal(), '/');
$logoSrc = '../../' . $logoRelativo;
$corPrimaria = $empresa->corPrimaria();
$corSecundaria = $empresa->corSecundaria();
$corAccent = $empresa->corAccent();

if (function_exists('sgl_registrar_log')) {
    try {
        sgl_registrar_log(
            $conn,
            'Relatório de honorários emitido',
            'honorarios',
            null,
            'Relatório Enterprise de honorários aberto para impressão ou PDF.',
            [
                'tipo_acao' => 'RELATORIO',
                'modulo' => 'Honorários',
                'origem' => 'modules/relatorios/honorarios.php',
                'resultado' => 'SUCESSO',
                'nivel' => 'INFO',
                'dados_novos' => [
                    'quantidade_registros' => count($registros),
                    'filtros' => $filtrosAtivos,
                ],
            ]
        );
    } catch (Throwable $e) {
        error_log('[ROJEX RELATÓRIO HONORÁRIOS] ' . $e->getMessage());
    }
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Relatório de Honorários — <?= honRelH($empresa->nomeEscritorio()) ?></title>
    <style>
        :root {
            --primary: <?= honRelH($corPrimaria) ?>;
            --secondary: <?= honRelH($corSecundaria) ?>;
            --accent: <?= honRelH($corAccent) ?>;
            --border: #d9dee7;
            --muted: #667085;
            --surface: #ffffff;
            --surface-alt: #f5f7fa;
            --text: #17202a;
        }

        * { box-sizing: border-box; }
        body {
            margin: 0;
            background: #eef1f5;
            color: var(--text);
            font-family: Arial, Helvetica, sans-serif;
            font-size: 12px;
        }
        .toolbar {
            max-width: 1180px;
            margin: 16px auto 0;
            display: flex;
            justify-content: flex-end;
            gap: 8px;
        }
        .toolbar button {
            border: 1px solid var(--primary);
            background: var(--primary);
            color: #fff;
            padding: 9px 14px;
            border-radius: 7px;
            cursor: pointer;
            font-weight: 700;
        }
        .toolbar .secondary {
            background: #fff;
            color: var(--primary);
        }
        .report {
            width: min(1180px, calc(100% - 32px));
            margin: 12px auto 24px;
            background: var(--surface);
            box-shadow: 0 8px 30px rgba(16, 24, 40, .12);
            padding: 26px;
        }
        .header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
            border-bottom: 3px solid var(--primary);
            padding-bottom: 14px;
        }
        .brand { display: flex; align-items: center; gap: 14px; }
        .brand img {
            width: 72px;
            height: 72px;
            object-fit: contain;
            border-radius: 8px;
            border: 1px solid var(--border);
        }
        .brand h1 { margin: 0 0 4px; font-size: 22px; color: var(--primary); }
        .brand p { margin: 2px 0; color: var(--muted); }
        .meta { text-align: right; line-height: 1.55; color: var(--muted); }
        .meta strong { color: var(--text); }
        .title { margin: 22px 0 12px; }
        .title h2 { margin: 0; font-size: 20px; }
        .filters {
            margin-top: 7px;
            color: var(--muted);
            font-size: 11px;
        }
        .summary {
            display: grid;
            grid-template-columns: repeat(6, minmax(0, 1fr));
            gap: 9px;
            margin: 16px 0 20px;
        }
        .summary-card {
            border: 1px solid var(--border);
            border-top: 4px solid var(--secondary);
            border-radius: 7px;
            padding: 10px;
            background: var(--surface-alt);
            min-height: 68px;
        }
        .summary-card span {
            display: block;
            color: var(--muted);
            font-size: 9px;
            text-transform: uppercase;
            letter-spacing: .04em;
            margin-bottom: 7px;
        }
        .summary-card strong { font-size: 15px; }
        table { width: 100%; border-collapse: collapse; table-layout: fixed; }
        thead { display: table-header-group; }
        th {
            background: var(--primary);
            color: #fff;
            padding: 8px 6px;
            text-align: left;
            font-size: 9px;
            text-transform: uppercase;
        }
        td {
            border-bottom: 1px solid var(--border);
            padding: 7px 6px;
            vertical-align: top;
            overflow-wrap: anywhere;
        }
        tbody tr:nth-child(even) { background: var(--surface-alt); }
        .number { text-align: right; white-space: nowrap; }
        .center { text-align: center; }
        .status {
            display: inline-block;
            border: 1px solid var(--border);
            border-radius: 999px;
            padding: 3px 7px;
            font-size: 9px;
            font-weight: 700;
        }
        .empty { padding: 32px; text-align: center; color: var(--muted); }
        .totals-row td {
            font-weight: 700;
            background: #eef3f8;
            border-top: 2px solid var(--primary);
        }
        .footer {
            margin-top: 18px;
            padding-top: 10px;
            border-top: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            color: var(--muted);
            font-size: 10px;
        }
        @page { size: A4 landscape; margin: 10mm; }
        @media print {
            body { background: #fff; }
            .toolbar { display: none !important; }
            .report {
                width: 100%;
                margin: 0;
                padding: 0;
                box-shadow: none;
            }
            tr { break-inside: avoid; page-break-inside: avoid; }
            .summary-card { break-inside: avoid; }
        }
        @media (max-width: 900px) {
            .summary { grid-template-columns: repeat(2, 1fr); }
            .header { align-items: flex-start; }
        }
    </style>
</head>
<body>
<div class="toolbar">
    <button type="button" class="secondary" onclick="window.close()">Fechar</button>
    <button type="button" onclick="window.print()">Imprimir / Salvar PDF</button>
</div>

<main class="report">
    <header class="header">
        <div class="brand">
            <img src="<?= honRelH($logoSrc) ?>" alt="Logo do escritório">
            <div>
                <h1><?= honRelH($empresa->nomeEscritorio()) ?></h1>
                <p><?= honRelH($empresa->razaoSocial()) ?></p>
                <p><?= honRelH($empresa->nomeSistema()) ?> · ERP Jurídico Enterprise</p>
            </div>
        </div>
        <div class="meta">
            <div><strong>Emitido em:</strong> <?= date('d/m/Y H:i:s') ?></div>
            <div><strong>Usuário:</strong> <?= honRelH($usuario) ?></div>
            <div><strong>Perfil:</strong> <?= honRelH($perfil) ?></div>
        </div>
    </header>

    <section class="title">
        <h2>Relatório de Honorários</h2>
        <div class="filters">
            <?= $filtrosAtivos ? honRelH(implode(' · ', $filtrosAtivos)) : 'Sem filtros — todos os honorários ativos.' ?>
        </div>
    </section>

    <section class="summary">
        <div class="summary-card"><span>Registros</span><strong><?= (int)$totais['registros'] ?></strong></div>
        <div class="summary-card"><span>Valor contratado</span><strong><?= honRelMoeda($totais['valor_total']) ?></strong></div>
        <div class="summary-card"><span>Total pago</span><strong><?= honRelMoeda($totais['valor_pago']) ?></strong></div>
        <div class="summary-card"><span>Saldo em aberto</span><strong><?= honRelMoeda($totais['saldo']) ?></strong></div>
        <div class="summary-card"><span>Pendentes / parciais</span><strong><?= (int)$totais['pendentes'] ?></strong></div>
        <div class="summary-card"><span>Vencidos</span><strong><?= (int)$totais['vencidos'] ?></strong></div>
    </section>

    <table>
        <colgroup>
            <col style="width:7%"><col style="width:18%"><col style="width:17%"><col style="width:9%">
            <col style="width:10%"><col style="width:10%"><col style="width:10%"><col style="width:8%"><col style="width:11%">
        </colgroup>
        <thead>
            <tr>
                <th>Código</th>
                <th>Cliente</th>
                <th>Processo</th>
                <th>Tipo</th>
                <th class="number">Valor total</th>
                <th class="number">Pago</th>
                <th class="number">Saldo</th>
                <th class="center">Parcelas</th>
                <th>Status / vencimento</th>
            </tr>
        </thead>
        <tbody>
        <?php if (!$registros): ?>
            <tr><td colspan="9" class="empty">Nenhum honorário encontrado para os filtros informados.</td></tr>
        <?php else: ?>
            <?php foreach ($registros as $registro): ?>
                <tr>
                    <td><strong><?= honRelH($registro['id']) ?></strong></td>
                    <td><?= honRelH($registro['nome_cliente']) ?></td>
                    <td><?= honRelH($registro['numero_processo'] ?: 'Não vinculado') ?></td>
                    <td><?= honRelH($registro['tipo_honorario']) ?></td>
                    <td class="number"><?= honRelMoeda($registro['valor_total']) ?></td>
                    <td class="number"><?= honRelMoeda($registro['total_pago']) ?></td>
                    <td class="number"><?= honRelMoeda($registro['total_saldo']) ?></td>
                    <td class="center"><?= (int)$registro['qtd_parcelas'] ?></td>
                    <td>
                        <span class="status"><?= honRelH($registro['status']) ?></span><br>
                        <small><?= honRelData((string)$registro['data_vencimento']) ?></small>
                    </td>
                </tr>
            <?php endforeach; ?>
            <tr class="totals-row">
                <td colspan="4">TOTAL DO RELATÓRIO</td>
                <td class="number"><?= honRelMoeda($totais['valor_total']) ?></td>
                <td class="number"><?= honRelMoeda($totais['valor_pago']) ?></td>
                <td class="number"><?= honRelMoeda($totais['saldo']) ?></td>
                <td colspan="2"></td>
            </tr>
        <?php endif; ?>
        </tbody>
    </table>

    <footer class="footer">
        <span><?= honRelH($empresa->poweredBy()) ?></span>
        <span>Documento gerado eletronicamente pelo ROJEX.AI.</span>
    </footer>
</main>
</body>
</html>
