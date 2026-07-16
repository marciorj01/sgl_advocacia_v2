<?php
/**
 * Endpoint AJAX seguro para salvar o valor pago de uma parcela individual,
 * recalcular os totais do honorário e sincronizar o Financeiro.
 */

declare(strict_types=1);

date_default_timezone_set('America/Sao_Paulo');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

function ajaxParcelaResponder(int $statusHttp, array $dados): never
{
    http_response_code($statusHttp);

    echo json_encode(
        $dados,
        JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
        | JSON_PARTIAL_OUTPUT_ON_ERROR
    );
    exit;
}

function ajaxParcelaCodigoCorrelacao(): string
{
    try {
        return 'PAR-' . date('Ymd-His') . '-' . strtoupper(bin2hex(random_bytes(3)));
    } catch (Throwable $e) {
        return 'PAR-' . date('Ymd-His') . '-' . strtoupper(substr(uniqid('', true), -6));
    }
}

function ajaxParcelaFormatarBrl(float $valor): string
{
    return 'R$ ' . number_format($valor, 2, ',', '.');
}

/**
 * Converte valor monetário para centavos.
 *
 * Retorna null quando o formato é inválido.
 */
function ajaxParcelaValorParaCentavos(mixed $valor): ?int
{
    if (!is_scalar($valor)) {
        return null;
    }

    $texto = trim((string)$valor);

    if ($texto === '') {
        return null;
    }

    $texto = str_replace(["R$", "r$", "\xc2\xa0", ' '], '', $texto);
    $texto = preg_replace('/[^0-9,.\-]/u', '', $texto);

    if ($texto === null || $texto === '' || $texto === '-' || $texto === ',' || $texto === '.') {
        return null;
    }

    if (substr_count($texto, '-') > 1 || (str_contains($texto, '-') && !str_starts_with($texto, '-'))) {
        return null;
    }

    $negativo = str_starts_with($texto, '-');
    $texto = ltrim($texto, '-');

    $ultimaVirgula = strrpos($texto, ',');
    $ultimoPonto = strrpos($texto, '.');

    if ($ultimaVirgula !== false && $ultimoPonto !== false) {
        if ($ultimaVirgula > $ultimoPonto) {
            // Formato brasileiro: 1.234,56
            $texto = str_replace('.', '', $texto);
            $texto = str_replace(',', '.', $texto);
        } else {
            // Formato internacional: 1,234.56
            $texto = str_replace(',', '', $texto);
        }
    } elseif ($ultimaVirgula !== false) {
        // Formato brasileiro: 1234,56
        $texto = str_replace('.', '', $texto);
        $texto = str_replace(',', '.', $texto);
    } elseif ($ultimoPonto !== false) {
        $casas = strlen($texto) - $ultimoPonto - 1;

        // 1.000 é interpretado como milhar; 1000.00 como decimal.
        if ($casas === 3 && substr_count($texto, '.') >= 1) {
            $texto = str_replace('.', '', $texto);
        }
    }

    if (!preg_match('/^\d+(?:\.\d{1,2})?$/', $texto)) {
        return null;
    }

    [$inteiro, $decimal] = array_pad(explode('.', $texto, 2), 2, '');
    $decimal = str_pad($decimal, 2, '0');
    $decimal = substr($decimal, 0, 2);

    $centavos = ((int)$inteiro * 100) + (int)$decimal;

    return $negativo ? -$centavos : $centavos;
}

$codigoCorrelacao = ajaxParcelaCodigoCorrelacao();
$conn = null;
$transacaoAtiva = false;

try {
    require_once __DIR__ . '/../config/database.php';
    require_once __DIR__ . '/../config/auth.php';
    require_once __DIR__ . '/../config/integracoes.php';

    iniciarSessaoSegura();

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        header('Allow: POST');
        ajaxParcelaResponder(405, [
            'ok' => false,
            'erro' => 'Método não permitido.',
            'codigo' => $codigoCorrelacao,
        ]);
    }

    if (!usuarioLogado()) {
        ajaxParcelaResponder(401, [
            'ok' => false,
            'erro' => 'Sua sessão expirou. Entre novamente no sistema.',
            'codigo' => $codigoCorrelacao,
        ]);
    }

    if (!function_exists('rojexContextoTenantValido') || !rojexContextoTenantValido()) {
        ajaxParcelaResponder(403, [
            'ok' => false,
            'erro' => 'Contexto do escritório inválido. Entre novamente no sistema.',
            'codigo' => $codigoCorrelacao,
        ]);
    }

    $tenantId = function_exists('rojexTenantId')
        ? trim((string)rojexTenantId())
        : trim((string)($_SESSION['tenant_id'] ?? ''));

    $escritorioId = function_exists('rojexEscritorioId')
        ? (int)rojexEscritorioId()
        : (int)($_SESSION['escritorio_id'] ?? 0);

    if ($tenantId === '' || $escritorioId <= 0) {
        ajaxParcelaResponder(403, [
            'ok' => false,
            'erro' => 'Tenant ou escritório não identificado.',
            'codigo' => $codigoCorrelacao,
        ]);
    }

    if (!validarTokenCsrf($_POST['csrf_token'] ?? null)) {
        if (function_exists('sgl_registrar_log')) {
            sgl_registrar_log(
                conectar(),
                'Tentativa inválida de atualizar parcela de honorário',
                'honorarios_parcelas',
                isset($_POST['parcela_id']) ? trim((string)$_POST['parcela_id']) : null,
                'Requisição bloqueada por token CSRF inválido.',
                [
                    'tipo_acao' => 'EDICAO',
                    'modulo' => 'Honorários',
                    'origem' => 'Endpoint AJAX de parcelas',
                    'resultado' => 'NEGADO',
                    'nivel' => 'AVISO',
                    'dados_novos' => [
                        'codigo_correlacao' => $codigoCorrelacao,
                        'tenant_id' => $tenantId,
                        'escritorio_id' => $escritorioId,
                    ],
                ]
            );
        }

        ajaxParcelaResponder(403, [
            'ok' => false,
            'erro' => 'Ação bloqueada por segurança. Atualize a página e tente novamente.',
            'codigo' => $codigoCorrelacao,
        ]);
    }

    $parcelaId = strtoupper(trim((string)($_POST['parcela_id'] ?? '')));
    $valorPagoCentavos = ajaxParcelaValorParaCentavos($_POST['valor_pago'] ?? null);

    if ($parcelaId === '') {
        ajaxParcelaResponder(422, [
            'ok' => false,
            'erro' => 'ID da parcela não informado.',
            'codigo' => $codigoCorrelacao,
        ]);
    }

    if (!preg_match('/^HPC[0-9]{3,17}$/', $parcelaId)) {
        ajaxParcelaResponder(422, [
            'ok' => false,
            'erro' => 'Identificador da parcela inválido.',
            'codigo' => $codigoCorrelacao,
        ]);
    }

    if ($valorPagoCentavos === null) {
        ajaxParcelaResponder(422, [
            'ok' => false,
            'erro' => 'Informe um valor pago válido.',
            'codigo' => $codigoCorrelacao,
        ]);
    }

    if ($valorPagoCentavos < 0) {
        ajaxParcelaResponder(422, [
            'ok' => false,
            'erro' => 'O valor pago não pode ser negativo.',
            'codigo' => $codigoCorrelacao,
        ]);
    }

    $conn = conectar();

    if (!$conn instanceof mysqli) {
        throw new RuntimeException('Conexão com o banco indisponível.');
    }

    $conn->begin_transaction();
    $transacaoAtiva = true;

    $stmtParcela = $conn->prepare(
        "SELECT
            hp.id,
            hp.honorario_id,
            hp.valor_parcela,
            hp.valor_pago,
            hp.saldo_devedor,
            hp.status_pagamento,
            hp.data_pagamento,
            h.valor_pago AS honorario_valor_pago,
            h.valor_pendente AS honorario_valor_pendente,
            h.status AS honorario_status,
            h.deletado AS honorario_deletado
         FROM honorarios_parcelas hp
         INNER JOIN honorarios h
                 ON h.id = hp.honorario_id
                AND h.tenant_id = hp.tenant_id
                AND h.escritorio_id = hp.escritorio_id
         WHERE hp.id = ?
           AND hp.tenant_id = ?
           AND hp.escritorio_id = ?
           AND h.tenant_id = ?
           AND h.escritorio_id = ?
         LIMIT 1
         FOR UPDATE"
    );

    if (!$stmtParcela) {
        throw new RuntimeException('Falha ao preparar a consulta da parcela.');
    }

    $stmtParcela->bind_param('ssisi', $parcelaId, $tenantId, $escritorioId, $tenantId, $escritorioId);

    if (!$stmtParcela->execute()) {
        throw new RuntimeException('Falha ao consultar a parcela.');
    }

    $resultadoParcela = $stmtParcela->get_result();
    $parcela = $resultadoParcela ? $resultadoParcela->fetch_assoc() : null;
    $stmtParcela->close();

    if (!$parcela) {
        $conn->rollback();
        $transacaoAtiva = false;

        ajaxParcelaResponder(404, [
            'ok' => false,
            'erro' => 'Parcela não encontrada.',
            'codigo' => $codigoCorrelacao,
        ]);
    }

    if ((int)($parcela['honorario_deletado'] ?? 0) === 1) {
        $conn->rollback();
        $transacaoAtiva = false;

        ajaxParcelaResponder(409, [
            'ok' => false,
            'erro' => 'Não é possível alterar uma parcela de honorário que está na lixeira.',
            'codigo' => $codigoCorrelacao,
        ]);
    }

    $honorarioId = (string)$parcela['honorario_id'];
    $valorParcelaCentavos = (int)round(((float)$parcela['valor_parcela']) * 100);

    if ($valorParcelaCentavos <= 0) {
        throw new RuntimeException('A parcela possui valor estrutural inválido.');
    }

    if ($valorPagoCentavos > $valorParcelaCentavos) {
        $conn->rollback();
        $transacaoAtiva = false;

        ajaxParcelaResponder(422, [
            'ok' => false,
            'erro' => 'O valor pago não pode ser maior que o valor da parcela.',
            'codigo' => $codigoCorrelacao,
        ]);
    }

    $saldoCentavos = $valorParcelaCentavos - $valorPagoCentavos;

    if ($valorPagoCentavos === 0) {
        $statusParcela = 'Pendente';
        $dataPagamento = null;
    } elseif ($saldoCentavos > 1) {
        $statusParcela = 'Parcial';
        $dataPagamento = !empty($parcela['data_pagamento'])
            ? (string)$parcela['data_pagamento']
            : date('Y-m-d');
    } else {
        $statusParcela = 'Pago';
        $saldoCentavos = 0;
        $dataPagamento = !empty($parcela['data_pagamento'])
            ? (string)$parcela['data_pagamento']
            : date('Y-m-d');
    }

    $valorPago = $valorPagoCentavos / 100;
    $saldoDevedor = $saldoCentavos / 100;

    $stmtAtualizaParcela = $conn->prepare(
        "UPDATE honorarios_parcelas
         SET valor_pago = ?,
             saldo_devedor = ?,
             status_pagamento = ?,
             data_pagamento = ?
         WHERE id = ?
           AND tenant_id = ?
           AND escritorio_id = ?"
    );

    if (!$stmtAtualizaParcela) {
        throw new RuntimeException('Falha ao preparar a atualização da parcela.');
    }

    $stmtAtualizaParcela->bind_param(
        'ddssssi',
        $valorPago,
        $saldoDevedor,
        $statusParcela,
        $dataPagamento,
        $parcelaId,
        $tenantId,
        $escritorioId
    );

    if (!$stmtAtualizaParcela->execute() || $stmtAtualizaParcela->affected_rows < 0) {
        throw new RuntimeException('Falha ao atualizar a parcela.');
    }

    $stmtAtualizaParcela->close();

    $stmtTotais = $conn->prepare(
        "SELECT
            COALESCE(SUM(valor_pago), 0) AS total_pago,
            COALESCE(SUM(saldo_devedor), 0) AS total_saldo
         FROM honorarios_parcelas
         WHERE honorario_id = ?
           AND tenant_id = ?
           AND escritorio_id = ?"
    );

    if (!$stmtTotais) {
        throw new RuntimeException('Falha ao preparar o recálculo do honorário.');
    }

    $stmtTotais->bind_param('ssi', $honorarioId, $tenantId, $escritorioId);

    if (!$stmtTotais->execute()) {
        throw new RuntimeException('Falha ao recalcular o honorário.');
    }

    $resultadoTotais = $stmtTotais->get_result();
    $totais = $resultadoTotais ? $resultadoTotais->fetch_assoc() : null;
    $stmtTotais->close();

    if (!$totais) {
        throw new RuntimeException('Totais do honorário indisponíveis.');
    }

    $totalPagoHonorario = round((float)$totais['total_pago'], 2);
    $totalSaldoHonorario = round((float)$totais['total_saldo'], 2);

    $statusHonorario = 'Pendente';

    if ($totalSaldoHonorario <= 0.01) {
        $statusHonorario = 'Pago';
    } elseif ($totalPagoHonorario > 0) {
        $statusHonorario = 'Parcial';
    }

    $stmtAtualizaHonorario = $conn->prepare(
        "UPDATE honorarios
         SET valor_pago = ?,
             valor_pendente = ?,
             status = ?
         WHERE id = ?
           AND tenant_id = ?
           AND escritorio_id = ?
           AND deletado = 0"
    );

    if (!$stmtAtualizaHonorario) {
        throw new RuntimeException('Falha ao preparar a atualização do honorário.');
    }

    $stmtAtualizaHonorario->bind_param(
        'ddsssi',
        $totalPagoHonorario,
        $totalSaldoHonorario,
        $statusHonorario,
        $honorarioId,
        $tenantId,
        $escritorioId
    );

    if (!$stmtAtualizaHonorario->execute()) {
        throw new RuntimeException('Falha ao atualizar o honorário.');
    }

    if ($stmtAtualizaHonorario->affected_rows < 0) {
        throw new RuntimeException('Honorário não atualizado.');
    }

    $stmtAtualizaHonorario->close();

    $conn->commit();
    $transacaoAtiva = false;

    /*
     * A sincronização permanece após o commit porque a integração atual ainda
     * executa verificações/migrações de schema que podem causar commit implícito.
     */
    $sincronizacaoExecutada = false;

    if (function_exists('sgl_sincronizar_honorario_financeiro')) {
        try {
            sgl_sincronizar_honorario_financeiro($conn, $honorarioId);
            $sincronizacaoExecutada = true;
        } catch (Throwable $e) {
            error_log(
                '[ROJEX AJAX PARCELA][' . $codigoCorrelacao . '] '
                . 'Falha na sincronização financeira: '
                . $e->getMessage()
            );
        }
    }

    if (function_exists('sgl_registrar_log')) {
        sgl_registrar_log(
            $conn,
            'Parcela de honorário atualizada',
            'honorarios_parcelas',
            $parcelaId,
            'Valor pago da parcela atualizado pelo gerenciamento individual.',
            [
                'tipo_acao' => 'EDICAO',
                'modulo' => 'Honorários',
                'origem' => 'Endpoint AJAX de parcelas',
                'resultado' => $sincronizacaoExecutada ? 'SUCESSO' : 'PARCIAL',
                'nivel' => $sincronizacaoExecutada ? 'INFO' : 'AVISO',
                'dados_anteriores' => [
                    'parcela_id' => $parcelaId,
                    'honorario_id' => $honorarioId,
                    'valor_pago' => (float)$parcela['valor_pago'],
                    'saldo_devedor' => (float)$parcela['saldo_devedor'],
                    'status_pagamento' => (string)$parcela['status_pagamento'],
                    'data_pagamento' => $parcela['data_pagamento'],
                    'honorario_valor_pago' => (float)$parcela['honorario_valor_pago'],
                    'honorario_valor_pendente' => (float)$parcela['honorario_valor_pendente'],
                    'honorario_status' => (string)$parcela['honorario_status'],
                ],
                'dados_novos' => [
                    'parcela_id' => $parcelaId,
                    'honorario_id' => $honorarioId,
                    'valor_pago' => $valorPago,
                    'saldo_devedor' => $saldoDevedor,
                    'status_pagamento' => $statusParcela,
                    'data_pagamento' => $dataPagamento,
                    'honorario_valor_pago' => $totalPagoHonorario,
                    'honorario_valor_pendente' => $totalSaldoHonorario,
                    'honorario_status' => $statusHonorario,
                    'sincronizacao_financeira_executada' => $sincronizacaoExecutada,
                    'codigo_correlacao' => $codigoCorrelacao,
                ],
            ]
        );
    }

    $labels = [
        'Pendente' => 'Devedor',
        'Parcial' => 'Parcial',
        'Pago' => 'Quitada',
    ];

    $badges = [
        'Pendente' => 'bg-danger',
        'Parcial' => 'bg-warning text-dark',
        'Pago' => 'bg-success',
    ];

    $dots = [
        'Pendente' => '🔴',
        'Parcial' => '🟡',
        'Pago' => '🟢',
    ];

    ajaxParcelaResponder(200, [
        'ok' => true,
        'valor_pago_fmt' => ajaxParcelaFormatarBrl($valorPago),
        'saldo_fmt' => ajaxParcelaFormatarBrl($saldoDevedor),
        'status_label' => $labels[$statusParcela],
        'status_badge' => $badges[$statusParcela],
        'status_dot' => $dots[$statusParcela],
        'hon_valor_pago_fmt' => ajaxParcelaFormatarBrl($totalPagoHonorario),
        'hon_valor_pendente_fmt' => ajaxParcelaFormatarBrl($totalSaldoHonorario),
        'hon_status' => $statusHonorario,
        'aviso' => $sincronizacaoExecutada
            ? null
            : 'A parcela foi salva, mas a sincronização financeira precisa ser conferida.',
        'codigo' => $codigoCorrelacao,
    ]);
} catch (Throwable $e) {
    if ($conn instanceof mysqli && $transacaoAtiva) {
        try {
            $conn->rollback();
        } catch (Throwable $rollbackErro) {
            error_log(
                '[ROJEX AJAX PARCELA][' . $codigoCorrelacao . '] '
                . 'Falha no rollback: '
                . $rollbackErro->getMessage()
            );
        }
    }

    error_log(
        '[ROJEX AJAX PARCELA][' . $codigoCorrelacao . '] '
        . $e->getMessage()
    );

    if ($conn instanceof mysqli && function_exists('sgl_registrar_log')) {
        sgl_registrar_log(
            $conn,
            'Falha ao atualizar parcela de honorário',
            'honorarios_parcelas',
            isset($parcelaId) && $parcelaId !== '' ? $parcelaId : null,
            'A operação financeira foi revertida.',
            [
                'tipo_acao' => 'EDICAO',
                'modulo' => 'Honorários',
                'origem' => 'Endpoint AJAX de parcelas',
                'resultado' => 'FALHA',
                'nivel' => 'ERRO',
                'dados_novos' => [
                    'codigo_correlacao' => $codigoCorrelacao,
                ],
            ]
        );
    }

    ajaxParcelaResponder(500, [
        'ok' => false,
        'erro' => 'Não foi possível salvar a parcela. Tente novamente.',
        'codigo' => $codigoCorrelacao,
    ]);
}
