<?php
/**
 * Endpoint AJAX para salvar o valor pago de uma parcela individual 
 * e recalcular os totais do honorário global correspondente.
 */

// Define o fuso horário padrão do Brasil para a data de pagamento
date_default_timezone_set('America/Sao_Paulo');

header('Content-Type: application/json; charset=utf-8');

try {
    // Ajuste o caminho do banco conforme a estrutura real do seu sistema
    require_once __DIR__ . '/../config/database.php'; 
    require_once __DIR__ . '/../config/integracoes.php';
    $conn = conectar();
    if (!$conn) {
        throw new Exception('Falha ao conectar ao banco de dados');
    }
} catch (Exception $e) {
    echo json_encode(['ok' => false, 'erro' => 'Erro de conexão: ' . $e->getMessage()]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'erro' => 'Método não permitido.']);
    exit;
}

$parcela_id = $_POST['parcela_id'] ?? '';
$valor_pago_raw = $_POST['valor_pago'] ?? '0';

if (empty($parcela_id)) {
    echo json_encode(['ok' => false, 'erro' => 'ID da parcela não informado.']);
    exit;
}

// Funções Auxiliares de Tratamento e Formatação
function brlParaFloatAjax($valor) {
    $v = trim($valor);
    if ($v === '') return 0.0;
    $v = str_replace(['R$', ' '], '', $v);
    if (strpos($v, ',') !== false) {
        $v = str_replace('.', '', $v);
        $v = str_replace(',', '.', $v);
    }
    return is_numeric($v) ? (float) $v : 0.0;
}

function fmtBrlAjax($v) {
    return 'R$ ' . number_format((float)$v, 2, ',', '.');
}

$valor_pago = brlParaFloatAjax($valor_pago_raw);

// 1. Busca os dados atuais da parcela
$res = $conn->query("SELECT * FROM honorarios_parcelas WHERE id = '" . $conn->real_escape_string($parcela_id) . "'");
if (!$res || $res->num_rows === 0) {
    echo json_encode(['ok' => false, 'erro' => 'Parcela não encontrada no banco.']);
    exit;
}

$parcela = $res->fetch_assoc();
$honorario_id = $parcela['honorario_id'];
$valor_parcela = (float)$parcela['valor_parcela'];

// 2. Calcula Novo Saldo e Status da Parcela
if ($valor_pago <= 0.001) {
    $status_parcela = 'Pendente';
    $saldo_devedor = $valor_parcela;
    $data_pagamento_sql = "NULL"; // Sem pagamento = Sem data
} elseif ($valor_pago < $valor_parcela - 0.01) {
    $status_parcela = 'Parcial';
    $saldo_devedor = $valor_parcela - $valor_pago;
    $data_pagamento_sql = "'" . date('Y-m-d') . "'"; // Pago hoje
} else {
    $status_parcela = 'Pago';
    $saldo_devedor = 0.0;
    $data_pagamento_sql = "'" . date('Y-m-d') . "'"; // Pago hoje
}

// 3. Atualiza a parcela individual incluindo a DATA DO PAGAMENTO
$p_id_sql = $conn->real_escape_string($parcela_id);
$update_p = "UPDATE honorarios_parcelas SET 
                valor_pago = '$valor_pago', 
                saldo_devedor = '$saldo_devedor', 
                status_pagamento = '$status_parcela',
                data_pagamento = $data_pagamento_sql
             WHERE id = '$p_id_sql'";

if (!$conn->query($update_p)) {
    echo json_encode(['ok' => false, 'erro' => 'Erro ao atualizar parcela: ' . $conn->error]);
    exit;
}

// 4. Recalcula os totais globais na tabela `honorarios`
$h_id_sql = $conn->real_escape_string($honorario_id);
$res_totais = $conn->query("
    SELECT 
        COALESCE(SUM(valor_parcela), 0) AS total_contrato,
        COALESCE(SUM(valor_pago), 0) AS total_pago,
        COALESCE(SUM(saldo_devedor), 0) AS total_saldo
    FROM honorarios_parcelas
    WHERE honorario_id = '$h_id_sql'
");

$totais = $res_totais->fetch_assoc();
$total_pago_hon = (float)$totais['total_pago'];
$total_saldo_hon = (float)$totais['total_saldo'];

$status_hon = 'Pendente';
if ($total_saldo_hon <= 0.01) {
    $status_hon = 'Pago';
} elseif ($total_pago_hon > 0) {
    $status_hon = 'Parcial';
}

$conn->query("
    UPDATE honorarios SET 
        valor_pago = '$total_pago_hon',
        valor_pendente = '$total_saldo_hon',
        status = '$status_hon'
    WHERE id = '$h_id_sql'
");

if (function_exists('sgl_sincronizar_honorario_financeiro')) {
    sgl_sincronizar_honorario_financeiro($conn, $honorario_id);
}

// 5. Retorna o JSON mapeado para atualizar a tela sem dar F5
$labels = ['Pendente' => 'Devedor', 'Parcial' => 'Parcial', 'Pago' => 'Quitada'];
$badges = ['Pendente' => 'bg-danger', 'Parcial' => 'bg-warning text-dark', 'Pago' => 'bg-success'];
$dots   = ['Pendente' => '🔴', 'Parcial' => '🟡', 'Pago' => '🟢'];

echo json_encode([
    'ok' => true,
    'valor_pago_fmt' => fmtBrlAjax($valor_pago),
    'saldo_fmt'      => fmtBrlAjax($saldo_devedor),
    'status_label'   => $labels[$status_parcela],
    'status_badge'   => $badges[$status_parcela],
    'status_dot'     => $dots[$status_parcela],
    
    // Dados globais do cabeçalho do Honorário
    'hon_valor_pago_fmt'     => fmtBrlAjax($total_pago_hon),
    'hon_valor_pendente_fmt' => fmtBrlAjax($total_saldo_hon),
    'hon_status'             => $status_hon
]);
exit;