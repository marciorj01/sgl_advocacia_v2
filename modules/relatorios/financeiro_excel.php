<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/integracoes.php';

iniciarSessaoSegura();
exigirLogin('../../auth/login.php');

$conn = conectar();
$aba = ($_GET['aba'] ?? 'cp') === 'cr' ? 'cr' : 'cp';
$rotulo = $aba === 'cp' ? 'contas_a_pagar' : 'contas_a_receber';
$arquivo = 'rojex_' . $rotulo . '_' . date('Ymd_His') . '.xls';

if (!headers_sent()) {
    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $arquivo . '"');
    header('Cache-Control: max-age=0, no-cache, no-store, must-revalidate');
    header('Pragma: public');
}

function xlsH(mixed $v): string {
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function xlsData(mixed $v): string {
    $v = trim((string)($v ?? ''));
    return $v === '' || $v === '0000-00-00' ? '' : date('d/m/Y', strtotime($v));
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

$dados = [];
$res = $conn->query($sql);
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $dados[] = $row;
    }
}

if (function_exists('sgl_registrar_log')) {
    sgl_registrar_log(
        $conn,
        'Exportou relatório financeiro para Excel',
        $aba === 'cp' ? 'contas_pagar' : 'contas_receber',
        null,
        'Exportação com ' . count($dados) . ' registro(s).',
        [
            'tipo_acao' => 'EXPORTACAO',
            'modulo' => 'Financeiro',
            'origem' => 'Exportação Excel',
            'resultado' => 'SUCESSO',
            'nivel' => 'INFO',
        ]
    );
}

echo "\xEF\xBB\xBF";
?>
<html>
<head>
<meta charset="UTF-8">
<style>
table { border-collapse: collapse; }
th { background:#17324d; color:#fff; font-weight:bold; border:1px solid #9ca3af; }
td { border:1px solid #d1d5db; }
.money { mso-number-format:"\0022R$\0022\ #,##0.00"; }
.date { mso-number-format:"dd/mm/yyyy"; }
</style>
</head>
<body>
<table>
<thead>
<?php if ($aba === 'cp'): ?>
<tr>
<th>ID</th><th>Descrição</th><th>Categoria</th><th>Fornecedor</th><th>Banco/Caixa</th>
<th>Valor</th><th>Valor pago</th><th>Saldo pendente</th><th>Vencimento</th><th>Pagamento</th>
<th>Forma de pagamento</th><th>Status</th><th>Mês referência</th><th>Observações</th>
</tr>
<?php else: ?>
<tr>
<th>ID</th><th>Descrição</th><th>Cliente</th><th>Banco/Caixa</th>
<th>Valor</th><th>Valor recebido</th><th>Saldo pendente</th><th>Vencimento</th><th>Recebimento</th>
<th>Forma de recebimento</th><th>Status</th><th>Mês referência</th><th>Observações</th>
</tr>
<?php endif; ?>
</thead>
<tbody>
<?php if ($aba === 'cp'): foreach ($dados as $row): ?>
<tr>
<td><?= xlsH($row['id']) ?></td>
<td><?= xlsH($row['descricao'] ?? '') ?></td>
<td><?= xlsH($row['categoria'] ?? '') ?></td>
<td><?= xlsH($row['fornecedor'] ?? '') ?></td>
<td><?= xlsH($row['banco_nome'] ?? '') ?></td>
<td class="money"><?= number_format((float)($row['valor'] ?? 0), 2, '.', '') ?></td>
<td class="money"><?= number_format((float)($row['valor_pago'] ?? 0), 2, '.', '') ?></td>
<td class="money"><?= number_format((float)($row['valor_pendente'] ?? 0), 2, '.', '') ?></td>
<td class="date"><?= xlsData($row['data_vencimento'] ?? '') ?></td>
<td class="date"><?= xlsData($row['data_pagamento'] ?? '') ?></td>
<td><?= xlsH($row['forma_pagamento'] ?? '') ?></td>
<td><?= xlsH($row['status'] ?? '') ?></td>
<td><?= xlsH($row['mes_referencia'] ?? '') ?></td>
<td><?= xlsH($row['observacoes'] ?? '') ?></td>
</tr>
<?php endforeach; else: foreach ($dados as $row): ?>
<tr>
<td><?= xlsH($row['id']) ?></td>
<td><?= xlsH($row['descricao'] ?? '') ?></td>
<td><?= xlsH($row['cliente_nome'] ?? '') ?></td>
<td><?= xlsH($row['banco_nome'] ?? '') ?></td>
<td class="money"><?= number_format((float)($row['valor'] ?? 0), 2, '.', '') ?></td>
<td class="money"><?= number_format((float)($row['valor_pago'] ?? 0), 2, '.', '') ?></td>
<td class="money"><?= number_format((float)($row['valor_pendente'] ?? 0), 2, '.', '') ?></td>
<td class="date"><?= xlsData($row['data_vencimento'] ?? '') ?></td>
<td class="date"><?= xlsData($row['data_recebimento'] ?? '') ?></td>
<td><?= xlsH($row['forma_recebimento'] ?? '') ?></td>
<td><?= xlsH($row['status'] ?? '') ?></td>
<td><?= xlsH($row['mes_referencia'] ?? '') ?></td>
<td><?= xlsH($row['observacoes'] ?? '') ?></td>
</tr>
<?php endforeach; endif; ?>
</tbody>
</table>
</body>
</html>
<?php $conn->close(); ?>
