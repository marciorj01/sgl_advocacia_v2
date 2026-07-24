<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/integracoes.php';

iniciarSessaoSegura();

$conn = conectar();
exigirLogin('../../auth/login.php');

$aba = ($_GET['aba'] ?? 'cp') === 'cr' ? 'cr' : 'cp';
$rotulo = $aba === 'cp' ? 'contas_a_pagar' : 'contas_a_receber';

/**
 * Encerra a exportação com falha segura, sem gerar arquivo vazio ou registrar
 * sucesso indevido.
 */
function rojexFalharExportacaoFinanceira(mysqli $conn, string $mensagem, int $status = 500): never
{
    error_log('[ROJEX EXPORTAÇÃO FINANCEIRA] ' . $mensagem);

    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: text/plain; charset=UTF-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('X-Content-Type-Options: nosniff');
    }

    $conn->close();
    exit($status === 403 ? 'Acesso negado.' : 'Não foi possível gerar a exportação financeira.');
}

/**
 * Neutraliza valores que poderiam ser interpretados pelo Excel como fórmula.
 */
function xlsValorSeguro(mixed $valor): string
{
    $texto = (string)($valor ?? '');

    if ($texto !== '' && preg_match('/^[\t\r\n ]*[=+\-@]/u', $texto) === 1) {
        $texto = "'" . $texto;
    }

    return htmlspecialchars(
        $texto,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

function xlsData(mixed $valor): string
{
    $valor = trim((string)($valor ?? ''));

    if ($valor === '' || $valor === '0000-00-00') {
        return '';
    }

    $timestamp = strtotime($valor);
    return $timestamp === false ? '' : date('d/m/Y', $timestamp);
}

$usuarioId = (int)($_SESSION['user_id'] ?? 0);
$perfil = (string)($_SESSION['perfil'] ?? '');
$modoPlataforma = function_exists('rojexModoPlataforma') && rojexModoPlataforma();
$ehMaster = function_exists('rojexUsuarioEhMasterSaas')
    && rojexUsuarioEhMasterSaas($conn, $usuarioId, $perfil);

if ($modoPlataforma) {
    if (!$ehMaster) {
        rojexFalharExportacaoFinanceira($conn, 'Usuário de plataforma sem permissão MASTER tentou exportar Financeiro.', 403);
    }

    $tenantId = null;
    $escritorioId = null;
} else {
    if (!function_exists('rojexContextoTenantValido') || !rojexContextoTenantValido()) {
        rojexFalharExportacaoFinanceira($conn, 'Contexto Multi-Tenant inválido.', 403);
    }

    $tenantId = function_exists('rojexTenantId') ? rojexTenantId() : null;
    $escritorioId = function_exists('rojexEscritorioId') ? rojexEscritorioId() : null;

    if ($tenantId === null || $tenantId === '' || $escritorioId === null || $escritorioId <= 0) {
        rojexFalharExportacaoFinanceira($conn, 'Tenant ou escritório não identificado.', 403);
    }
}

if ($aba === 'cp') {
    $sql = "SELECT cp.*, b.nome AS banco_nome
              FROM contas_pagar cp
              LEFT JOIN bancos_caixa b
                     ON b.id = cp.banco_id
                    AND b.tenant_id = cp.tenant_id
                    AND b.escritorio_id = cp.escritorio_id
             WHERE COALESCE(cp.deletado, 0) = 0";

    if (!$modoPlataforma) {
        $sql .= " AND cp.tenant_id = ? AND cp.escritorio_id = ?";
    }

    $sql .= " ORDER BY cp.data_vencimento DESC, cp.id DESC";
} else {
    $sql = "SELECT cr.*, c.nome AS cliente_nome, b.nome AS banco_nome
              FROM contas_receber cr
              LEFT JOIN clientes c
                     ON c.id = cr.cliente_id
                    AND c.tenant_id = cr.tenant_id
                    AND c.escritorio_id = cr.escritorio_id
              LEFT JOIN bancos_caixa b
                     ON b.id = cr.banco_id
                    AND b.tenant_id = cr.tenant_id
                    AND b.escritorio_id = cr.escritorio_id
             WHERE COALESCE(cr.deletado, 0) = 0";

    if (!$modoPlataforma) {
        $sql .= " AND cr.tenant_id = ? AND cr.escritorio_id = ?";
    }

    $sql .= " ORDER BY cr.data_vencimento DESC, cr.id DESC";
}

$stmt = $conn->prepare($sql);

if (!$stmt) {
    rojexFalharExportacaoFinanceira($conn, 'Falha ao preparar consulta: ' . $conn->error);
}

if (!$modoPlataforma) {
    $tenantParam = (string)$tenantId;
    $escritorioParam = (int)$escritorioId;
    $stmt->bind_param('si', $tenantParam, $escritorioParam);
}

if (!$stmt->execute()) {
    $erro = $stmt->error;
    $stmt->close();
    rojexFalharExportacaoFinanceira($conn, 'Falha ao executar consulta: ' . $erro);
}

$resultado = $stmt->get_result();
$dados = [];

while ($row = $resultado->fetch_assoc()) {
    $dados[] = $row;
}

$stmt->close();

$sufixoEscopo = $modoPlataforma
    ? 'master_global'
    : 'escritorio_' . (int)$escritorioId;

$arquivo = sprintf(
    'rojex_%s_%s_%s.xls',
    $rotulo,
    $sufixoEscopo,
    date('Ymd_His')
);

if (function_exists('sgl_registrar_log')) {
    sgl_registrar_log(
        $conn,
        'Exportou relatório financeiro para Excel',
        $aba === 'cp' ? 'contas_pagar' : 'contas_receber',
        null,
        sprintf(
            'Exportação com %d registro(s). Escopo: %s.',
            count($dados),
            $modoPlataforma ? 'MASTER global' : 'tenant/escritório'
        ),
        [
            'tipo_acao' => 'EXPORTACAO',
            'modulo' => 'Financeiro',
            'origem' => 'Exportação Excel',
            'resultado' => 'SUCESSO',
            'nivel' => 'INFO',
            'dados_novos' => [
                'aba' => $aba,
                'quantidade' => count($dados),
                'escopo' => $modoPlataforma ? 'master_global' : 'tenant',
                'tenant_id' => $tenantId,
                'escritorio_id' => $escritorioId,
                'arquivo' => $arquivo,
            ],
        ]
    );
}

if (headers_sent($arquivoOrigem, $linhaOrigem)) {
    rojexFalharExportacaoFinanceira(
        $conn,
        sprintf('Headers já enviados em %s:%d.', $arquivoOrigem, $linhaOrigem)
    );
}

header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $arquivo . '"');
header('Content-Transfer-Encoding: binary');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
header('X-Content-Type-Options: nosniff');

echo "\xEF\xBB\xBF";
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<style>
table { border-collapse: collapse; }
th { background:#17324d; color:#fff; font-weight:bold; border:1px solid #9ca3af; }
td { border:1px solid #d1d5db; }

/* Propriedades proprietárias do Microsoft Excel */
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
<?php if ($aba === 'cp'): ?>
    <?php foreach ($dados as $row): ?>
    <tr>
    <td><?= xlsValorSeguro($row['id']) ?></td>
    <td><?= xlsValorSeguro($row['descricao'] ?? '') ?></td>
    <td><?= xlsValorSeguro($row['categoria'] ?? '') ?></td>
    <td><?= xlsValorSeguro($row['fornecedor'] ?? '') ?></td>
    <td><?= xlsValorSeguro($row['banco_nome'] ?? '') ?></td>
    <td class="money"><?= number_format((float)($row['valor'] ?? 0), 2, '.', '') ?></td>
    <td class="money"><?= number_format((float)($row['valor_pago'] ?? 0), 2, '.', '') ?></td>
    <td class="money"><?= number_format((float)($row['valor_pendente'] ?? 0), 2, '.', '') ?></td>
    <td class="date"><?= xlsData($row['data_vencimento'] ?? '') ?></td>
    <td class="date"><?= xlsData($row['data_pagamento'] ?? '') ?></td>
    <td><?= xlsValorSeguro($row['forma_pagamento'] ?? '') ?></td>
    <td><?= xlsValorSeguro($row['status'] ?? '') ?></td>
    <td><?= xlsValorSeguro($row['mes_referencia'] ?? '') ?></td>
    <td><?= xlsValorSeguro($row['observacoes'] ?? '') ?></td>
    </tr>
    <?php endforeach; ?>
<?php else: ?>
    <?php foreach ($dados as $row): ?>
    <tr>
    <td><?= xlsValorSeguro($row['id']) ?></td>
    <td><?= xlsValorSeguro($row['descricao'] ?? '') ?></td>
    <td><?= xlsValorSeguro($row['cliente_nome'] ?? '') ?></td>
    <td><?= xlsValorSeguro($row['banco_nome'] ?? '') ?></td>
    <td class="money"><?= number_format((float)($row['valor'] ?? 0), 2, '.', '') ?></td>
    <td class="money"><?= number_format((float)($row['valor_pago'] ?? 0), 2, '.', '') ?></td>
    <td class="money"><?= number_format((float)($row['valor_pendente'] ?? 0), 2, '.', '') ?></td>
    <td class="date"><?= xlsData($row['data_vencimento'] ?? '') ?></td>
    <td class="date"><?= xlsData($row['data_recebimento'] ?? '') ?></td>
    <td><?= xlsValorSeguro($row['forma_recebimento'] ?? '') ?></td>
    <td><?= xlsValorSeguro($row['status'] ?? '') ?></td>
    <td><?= xlsValorSeguro($row['mes_referencia'] ?? '') ?></td>
    <td><?= xlsValorSeguro($row['observacoes'] ?? '') ?></td>
    </tr>
    <?php endforeach; ?>
<?php endif; ?>
</tbody>
</table>
</body>
</html>
<?php
$conn->close();