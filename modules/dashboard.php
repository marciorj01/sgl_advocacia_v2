<?php
/**
 * Dashboard Executivo — SGL Advocacia
 * Fase 2.1
 *
 * Objetivo: apresentar indicadores reais do escritório sem expor erros técnicos
 * ao usuário e mantendo compatibilidade com a estrutura modular atual.
 */

date_default_timezone_set('America/Sao_Paulo');

$conn = conectar();
require_once __DIR__ . '/../config/base_conhecimento.php';
require_once __DIR__ . '/../config/integracoes.php';

try {
    if (!function_exists('rojex_kb_contexto_multi_tenant')) {
        throw new RuntimeException('A camada Multi-Tenant do Dashboard não está disponível.');
    }
    $dashboardContextoTenant = rojex_kb_contexto_multi_tenant();
} catch (Throwable $e) {
    error_log('[ROJEX DASHBOARD][CONTEXTO] ' . $e->getMessage());
    http_response_code(403);
    echo '<div class="container-fluid"><div class="alert alert-danger">'
        . '<strong>Dashboard bloqueado:</strong> '
        . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8')
        . '</div></div>';
    return;
}

if (function_exists('sgl_integracao_garantir_financeiro')) {
    sgl_integracao_garantir_financeiro($conn);
}
$hoje = date('Y-m-d');
$inicioMes = date('Y-m-01');
$fimMes = date('Y-m-t');
$inicioSemana = date('Y-m-d', strtotime('monday this week'));
$fimSemana = date('Y-m-d', strtotime('sunday this week'));

function h($valor): string
{
    return htmlspecialchars((string)($valor ?? ''), ENT_QUOTES, 'UTF-8');
}

function moeda($valor): string
{
    return 'R$ ' . number_format((float)($valor ?? 0), 2, ',', '.');
}

function dataBr(?string $data): string
{
    if (empty($data) || $data === '0000-00-00') {
        return '-';
    }
    return date('d/m/Y', strtotime($data));
}

function horaBr(?string $hora): string
{
    if (empty($hora)) {
        return '-';
    }
    return date('H:i', strtotime($hora));
}

function totalScalar(mysqli $conn, string $sql): float
{
    if (!function_exists('rojex_kb_consultar_um')) {
        throw new RuntimeException(
            'Consulta do Dashboard bloqueada: Base Multi-Tenant indisponível.'
        );
    }

    $row = rojex_kb_consultar_um($conn, $sql);
    return (float)($row['total'] ?? 0);
}

function queryRows(mysqli $conn, string $sql): array
{
    if (!function_exists('rojex_kb_consultar')) {
        throw new RuntimeException(
            'Consulta do Dashboard bloqueada: Base Multi-Tenant indisponível.'
        );
    }

    return rojex_kb_consultar($conn, $sql);
}

function dashboardTabelaExiste(mysqli $conn, string $tabela): bool
{
    $tabela = $conn->real_escape_string($tabela);
    $res = $conn->query("SHOW TABLES LIKE '{$tabela}'");
    return $res && $res->num_rows > 0;
}

function dashboardSaldoBanco(mysqli $conn, int $bancoId): float
{
    if ($bancoId <= 0) return 0.0;

    $saldo = totalScalar($conn, "SELECT COALESCE(saldo_inicial,0) AS total FROM bancos_caixa WHERE id={$bancoId}");

    $saldo += totalScalar($conn, "
        SELECT COALESCE(SUM(CASE WHEN valor_pago > 0 THEN valor_pago ELSE valor END),0) AS total
        FROM contas_receber
        WHERE deletado=0
          AND banco_id={$bancoId}
          AND (
              status IN ('Recebido','Pago','Quitada')
              OR (status = 'Parcial' AND valor_pago > 0)
          )
    ");

    $saldo -= totalScalar($conn, "
        SELECT COALESCE(SUM(CASE WHEN valor_pago > 0 THEN valor_pago ELSE valor END),0) AS total
        FROM contas_pagar
        WHERE deletado=0
          AND banco_id={$bancoId}
          AND status IN ('Pago','Quitada')
    ");

    if (dashboardTabelaExiste($conn, 'bancos_movimentacoes')) {
        $saldo += totalScalar($conn, "SELECT COALESCE(SUM(valor),0) AS total FROM bancos_movimentacoes WHERE banco_destino_id={$bancoId}");
        $saldo -= totalScalar($conn, "SELECT COALESCE(SUM(valor),0) AS total FROM bancos_movimentacoes WHERE banco_origem_id={$bancoId}");
    }

    return $saldo;
}

function badgeStatus(string $status): string
{
    $statusLimpo = trim($status);
    $mapa = [
        'Ativo' => 'success',
        'Em Andamento' => 'primary',
        'Pendente' => 'warning text-dark',
        'Parcial' => 'info text-dark',
        'Pago' => 'success',
        'Quitada' => 'success',
        'Recebido' => 'success',
        'Vencido' => 'danger',
        'Cancelado' => 'secondary',
        'Arquivado' => 'secondary',
        'Encerrado' => 'dark',
    ];
    $classe = $mapa[$statusLimpo] ?? 'secondary';
    return '<span class="badge bg-' . $classe . '">' . h($statusLimpo ?: '-') . '</span>';
}


function dashboardLinkResultadoRapido(array $resultado): string
{
    $modulo = (string)($resultado['mod'] ?? '');
    $id = (string)($resultado['id'] ?? '');
    $texto = (string)($resultado['texto'] ?? '');

    if ($modulo === '' || $id === '') {
        return '?mod=dashboard';
    }

    $idUrl = urlencode($id);

    $rotasDiretas = [
        'clientes' => '?mod=clientes&acao=editar&id=' . $idUrl,
        'advogados' => '?mod=advogados&acao=editar&id=' . $idUrl,
        'processos' => '?mod=processos&acao=editar&id=' . $idUrl,
        'agenda' => '?mod=agenda&acao=editar&id=' . $idUrl,
        'honorarios' => '?mod=honorarios&acao=editar&id=' . $idUrl,
        'recibos' => '?mod=recibos&acao=ver&id=' . $idUrl,
        'documentos' => '?mod=documentos&acao=visualizar&id=' . $idUrl,
        'modelos' => '?mod=modelos&acao=editar&id=' . $idUrl,
        'financeiro' => '?mod=financeiro&q=' . urlencode($texto !== '' ? $texto : $id),
    ];

    if (isset($rotasDiretas[$modulo])) {
        return $rotasDiretas[$modulo];
    }

    return '?mod=' . urlencode($modulo) . '&busca=' . urlencode($texto !== '' ? $texto : $id);
}

// ========================
// Indicadores financeiros
// ========================
$recebidoContasMes = totalScalar($conn, "
    SELECT COALESCE(SUM(CASE WHEN valor_pago > 0 THEN valor_pago ELSE valor END), 0) AS total
    FROM contas_receber
    WHERE deletado = 0
      AND (
          status IN ('Recebido','Pago','Quitada')
          OR (status = 'Parcial' AND valor_pago > 0)
      )
      AND COALESCE(data_recebimento, DATE(atualizado_em), data_vencimento) BETWEEN '{$inicioMes}' AND '{$fimMes}'
");

$recebidoHonorariosSemContaMes = totalScalar($conn, "
    SELECT COALESCE(SUM(hp.valor_pago), 0) AS total
    FROM honorarios_parcelas hp
    WHERE (
          hp.status_pagamento IN ('Pago','Quitada','Recebido')
          OR (hp.status_pagamento = 'Parcial' AND hp.valor_pago > 0)
      )
      AND COALESCE(hp.data_pagamento, hp.data_vencimento) BETWEEN '{$inicioMes}' AND '{$fimMes}'
      AND NOT EXISTS (
          SELECT 1 FROM contas_receber cr
          WHERE cr.parcela_id = hp.id
            AND cr.deletado = 0
            AND cr.tenant_id = hp.tenant_id
            AND cr.escritorio_id = hp.escritorio_id
      )
");

$recebidoMes = $recebidoContasMes + $recebidoHonorariosSemContaMes;

$previsaoContas = totalScalar($conn, "
    SELECT COALESCE(SUM(CASE WHEN valor_pendente > 0 THEN valor_pendente ELSE valor END), 0) AS total
    FROM contas_receber
    WHERE deletado = 0
      AND status IN ('Pendente','Parcial','Aberto','Em Aberto','Vencido')
");

$previsaoHonorariosSemConta = totalScalar($conn, "
    SELECT COALESCE(SUM(CASE WHEN hp.saldo_devedor > 0 THEN hp.saldo_devedor ELSE hp.valor_parcela END), 0) AS total
    FROM honorarios_parcelas hp
    WHERE hp.status_pagamento IN ('Pendente','Parcial','Aberto','Em Aberto','Vencido','Devedor')
      AND NOT EXISTS (
          SELECT 1 FROM contas_receber cr
          WHERE cr.parcela_id = hp.id
            AND cr.deletado = 0
            AND cr.tenant_id = hp.tenant_id
            AND cr.escritorio_id = hp.escritorio_id
      )
");

$totalAReceber = $previsaoContas + $previsaoHonorariosSemConta;

$despesasAbertas = totalScalar($conn, "
    SELECT COALESCE(SUM(CASE WHEN valor_pendente > 0 THEN valor_pendente ELSE valor END), 0) AS total
    FROM contas_pagar
    WHERE deletado = 0
      AND status IN ('Pendente','Parcial')
");

$despesasPagasMes = totalScalar($conn, "
    SELECT COALESCE(SUM(valor_pago), 0) AS total
    FROM contas_pagar
    WHERE deletado = 0
      AND status IN ('Pago','Quitada')
      AND COALESCE(data_pagamento, atualizado_em) BETWEEN '{$inicioMes}' AND '{$fimMes} 23:59:59'
");


// Fechamento profissional do CAIXA físico.
// Entradas/saídas operacionais no caixa + transferências internas envolvendo contas do tipo CAIXA.
$entradasCaixaRecebimentosHoje = totalScalar($conn, "
    SELECT COALESCE(SUM(CASE WHEN cr.valor_pago > 0 THEN cr.valor_pago ELSE cr.valor END), 0) AS total
    FROM contas_receber cr
    LEFT JOIN bancos_caixa b ON b.id = cr.banco_id
        AND b.tenant_id = cr.tenant_id
        AND b.escritorio_id = cr.escritorio_id
    WHERE cr.deletado = 0
      AND (
          cr.status IN ('Recebido','Pago','Quitada')
          OR (cr.status = 'Parcial' AND cr.valor_pago > 0)
      )
      AND COALESCE(cr.data_recebimento, DATE(cr.atualizado_em), cr.data_vencimento) = '{$hoje}'
      AND (UPPER(COALESCE(b.tipo,''))='CAIXA' OR UPPER(COALESCE(b.nome,''))='CAIXA')
");
$saidasCaixaDespesasHoje = totalScalar($conn, "
    SELECT COALESCE(SUM(CASE WHEN cp.valor_pago > 0 THEN cp.valor_pago ELSE cp.valor END), 0) AS total
    FROM contas_pagar cp
    LEFT JOIN bancos_caixa b ON b.id = cp.banco_id
        AND b.tenant_id = cp.tenant_id
        AND b.escritorio_id = cp.escritorio_id
    WHERE cp.deletado = 0
      AND cp.status IN ('Pago','Quitada')
      AND COALESCE(cp.data_pagamento, DATE(cp.atualizado_em), cp.data_vencimento) = '{$hoje}'
      AND (UPPER(COALESCE(b.tipo,''))='CAIXA' OR UPPER(COALESCE(b.nome,''))='CAIXA')
");
$transferenciasEntradaCaixaHoje = dashboardTabelaExiste($conn, 'bancos_movimentacoes') ? totalScalar($conn, "
    SELECT COALESCE(SUM(m.valor),0) AS total
    FROM bancos_movimentacoes m
    LEFT JOIN bancos_caixa b ON b.id = m.banco_destino_id
        AND b.tenant_id = m.tenant_id
        AND b.escritorio_id = m.escritorio_id
    WHERE m.data_movimento = '{$hoje}'
      AND (UPPER(COALESCE(b.tipo,''))='CAIXA' OR UPPER(COALESCE(b.nome,''))='CAIXA')
") : 0.0;
$transferenciasSaidaCaixaHoje = dashboardTabelaExiste($conn, 'bancos_movimentacoes') ? totalScalar($conn, "
    SELECT COALESCE(SUM(m.valor),0) AS total
    FROM bancos_movimentacoes m
    LEFT JOIN bancos_caixa b ON b.id = m.banco_origem_id
        AND b.tenant_id = m.tenant_id
        AND b.escritorio_id = m.escritorio_id
    WHERE m.data_movimento = '{$hoje}'
      AND (UPPER(COALESCE(b.tipo,''))='CAIXA' OR UPPER(COALESCE(b.nome,''))='CAIXA')
") : 0.0;

$entradasCaixaRecebimentosMes = totalScalar($conn, "
    SELECT COALESCE(SUM(CASE WHEN cr.valor_pago > 0 THEN cr.valor_pago ELSE cr.valor END), 0) AS total
    FROM contas_receber cr
    LEFT JOIN bancos_caixa b ON b.id = cr.banco_id
        AND b.tenant_id = cr.tenant_id
        AND b.escritorio_id = cr.escritorio_id
    WHERE cr.deletado = 0
      AND (
          cr.status IN ('Recebido','Pago','Quitada')
          OR (cr.status = 'Parcial' AND cr.valor_pago > 0)
      )
      AND COALESCE(cr.data_recebimento, DATE(cr.atualizado_em), cr.data_vencimento) BETWEEN '{$inicioMes}' AND '{$fimMes}'
      AND (UPPER(COALESCE(b.tipo,''))='CAIXA' OR UPPER(COALESCE(b.nome,''))='CAIXA')
");
$saidasCaixaDespesasMes = totalScalar($conn, "
    SELECT COALESCE(SUM(CASE WHEN cp.valor_pago > 0 THEN cp.valor_pago ELSE cp.valor END), 0) AS total
    FROM contas_pagar cp
    LEFT JOIN bancos_caixa b ON b.id = cp.banco_id
        AND b.tenant_id = cp.tenant_id
        AND b.escritorio_id = cp.escritorio_id
    WHERE cp.deletado = 0
      AND cp.status IN ('Pago','Quitada')
      AND COALESCE(cp.data_pagamento, DATE(cp.atualizado_em), cp.data_vencimento) BETWEEN '{$inicioMes}' AND '{$fimMes}'
      AND (UPPER(COALESCE(b.tipo,''))='CAIXA' OR UPPER(COALESCE(b.nome,''))='CAIXA')
");
$transferenciasEntradaCaixaMes = dashboardTabelaExiste($conn, 'bancos_movimentacoes') ? totalScalar($conn, "
    SELECT COALESCE(SUM(m.valor),0) AS total
    FROM bancos_movimentacoes m
    LEFT JOIN bancos_caixa b ON b.id = m.banco_destino_id
        AND b.tenant_id = m.tenant_id
        AND b.escritorio_id = m.escritorio_id
    WHERE m.data_movimento BETWEEN '{$inicioMes}' AND '{$fimMes}'
      AND (UPPER(COALESCE(b.tipo,''))='CAIXA' OR UPPER(COALESCE(b.nome,''))='CAIXA')
") : 0.0;
$transferenciasSaidaCaixaMes = dashboardTabelaExiste($conn, 'bancos_movimentacoes') ? totalScalar($conn, "
    SELECT COALESCE(SUM(m.valor),0) AS total
    FROM bancos_movimentacoes m
    LEFT JOIN bancos_caixa b ON b.id = m.banco_origem_id
        AND b.tenant_id = m.tenant_id
        AND b.escritorio_id = m.escritorio_id
    WHERE m.data_movimento BETWEEN '{$inicioMes}' AND '{$fimMes}'
      AND (UPPER(COALESCE(b.tipo,''))='CAIXA' OR UPPER(COALESCE(b.nome,''))='CAIXA')
") : 0.0;

// Fechamento de caixa físico: inclui dinheiro que entra/sai do CAIXA e transferências CAIXA ↔ Banco/Outros.
$entradasHoje = $entradasCaixaRecebimentosHoje + $transferenciasEntradaCaixaHoje;
$saidasHoje = $saidasCaixaDespesasHoje + $transferenciasSaidaCaixaHoje;
$saldoCaixaHoje = $entradasHoje - $saidasHoje;

$entradasMes = $entradasCaixaRecebimentosMes + $transferenciasEntradaCaixaMes;
$saidasMes = $saidasCaixaDespesasMes + $transferenciasSaidaCaixaMes;
$saldoCaixaMes = $entradasMes - $saidasMes;

$saldoEstimado = $recebidoMes + $totalAReceber - $despesasAbertas - $despesasPagasMes;

// Saldo real por Caixa/Bancos, incluindo movimentações internas.
// Transferência Caixa -> Banco não aumenta o total geral; apenas muda o saldo entre contas.
$contasCaixaBancos = [];
$saldoCaixaBancosTotal = 0.0;
if (dashboardTabelaExiste($conn, 'bancos_caixa')) {
    $contasCaixaBancos = queryRows($conn, "
        SELECT id, nome, tipo, banco, conta, ativo
        FROM bancos_caixa
        WHERE COALESCE(ativo,1)=1
        ORDER BY FIELD(tipo,'Caixa','PIX','Conta Corrente','Poupança','Cartão'), nome ASC
        LIMIT 8
    ");
    foreach ($contasCaixaBancos as &$contaBancoDash) {
        $contaBancoDash['saldo_atual'] = dashboardSaldoBanco($conn, (int)$contaBancoDash['id']);
        $saldoCaixaBancosTotal += (float)$contaBancoDash['saldo_atual'];
    }
    unset($contaBancoDash);
}
$saldoEstimado = $saldoCaixaBancosTotal + $totalAReceber - $despesasAbertas;

// ========================
// Indicadores operacionais
// ========================
$totalClientes = (int)totalScalar($conn, "SELECT COUNT(*) AS total FROM clientes WHERE deletado = 0 AND status = 'Ativo'");
$clientesNovosMes = (int)totalScalar($conn, "SELECT COUNT(*) AS total FROM clientes WHERE deletado = 0 AND data_cadastro BETWEEN '{$inicioMes}' AND '{$fimMes}'");
$totalProcessos = (int)totalScalar($conn, "SELECT COUNT(*) AS total FROM processos WHERE status <> 'Excluído'");
$processosAtivos = (int)totalScalar($conn, "SELECT COUNT(*) AS total FROM processos WHERE status = 'Em Andamento'");
$compromissosHoje = (int)totalScalar($conn, "SELECT COUNT(*) AS total FROM agenda WHERE deletado = 0 AND data_evento = '{$hoje}' AND status <> 'Cancelado'");
$audienciasHoje = (int)totalScalar($conn, "SELECT COUNT(*) AS total FROM agenda WHERE deletado = 0 AND data_evento = '{$hoje}' AND status <> 'Cancelado' AND (tipo_compromisso LIKE '%Audiência%' OR tipo_compromisso LIKE '%Audiencia%')");

$contasPagarHoje = (int)totalScalar($conn, "SELECT COUNT(*) AS total FROM contas_pagar WHERE deletado = 0 AND status IN ('Pendente','Parcial') AND data_vencimento = '{$hoje}'");
$contasPagarVencidas = (int)totalScalar($conn, "SELECT COUNT(*) AS total FROM contas_pagar WHERE deletado = 0 AND status IN ('Pendente','Parcial') AND data_vencimento < '{$hoje}'");
$honorariosVencidos = (int)totalScalar($conn, "
    SELECT COUNT(*) AS total
    FROM honorarios_parcelas hp
    LEFT JOIN honorarios h ON h.id = hp.honorario_id
        AND h.tenant_id = hp.tenant_id
        AND h.escritorio_id = hp.escritorio_id
    WHERE COALESCE(h.deletado,0) = 0
      AND hp.data_vencimento < '{$hoje}'
      AND COALESCE(hp.saldo_devedor, hp.valor_parcela, 0) > 0
      AND COALESCE(hp.status_pagamento,'Pendente') NOT IN ('Pago','Quitada','Recebido','Cancelado')
");
$prazos7Dias = (int)totalScalar($conn, "SELECT COUNT(*) AS total FROM processos WHERE status = 'Em Andamento' AND proximo_prazo BETWEEN '{$hoje}' AND DATE_ADD('{$hoje}', INTERVAL 7 DAY)");

$recebimentosSemana = totalScalar($conn, "
    SELECT COALESCE(SUM(CASE WHEN valor_pago > 0 THEN valor_pago ELSE valor END), 0) AS total
    FROM contas_receber
    WHERE deletado = 0
      AND (
          status IN ('Recebido','Pago','Quitada')
          OR (status = 'Parcial' AND valor_pago > 0)
      )
      AND COALESCE(data_recebimento, DATE(atualizado_em), data_vencimento) BETWEEN '{$inicioSemana}' AND '{$fimSemana}'
");

$despesasSemana = totalScalar($conn, "
    SELECT COALESCE(SUM(valor_pago), 0) AS total
    FROM contas_pagar
    WHERE deletado = 0
      AND status IN ('Pago','Quitada')
      AND COALESCE(data_pagamento, atualizado_em) BETWEEN '{$inicioSemana}' AND '{$fimSemana} 23:59:59'
");

// ========================
// Listagens do dashboard
// ========================
$agendaHoje = queryRows($conn, "
    SELECT id, horario, tipo_compromisso, nome_cliente, numero_processo, processo_numero, local, local_evento, status
    FROM agenda
    WHERE deletado = 0
      AND data_evento = '{$hoje}'
      AND status <> 'Cancelado'
    ORDER BY horario IS NULL, horario ASC, id ASC
    LIMIT 8
");

$prazosProximos = queryRows($conn, "
    SELECT id, numero_processo, tipo_processo, fase_atual, proximo_prazo, status
    FROM processos
    WHERE status = 'Em Andamento'
      AND proximo_prazo IS NOT NULL
      AND proximo_prazo BETWEEN '{$hoje}' AND DATE_ADD('{$hoje}', INTERVAL 15 DAY)
    ORDER BY proximo_prazo ASC
    LIMIT 6
");

$honorariosPendentes = queryRows($conn, "
    SELECT hp.id,
           COALESCE(NULLIF(hp.nome_cliente,''), NULLIF(h.nome_cliente,''), 'Cliente não informado') AS nome_cliente,
           COALESCE(NULLIF(hp.numero_processo,''), NULLIF(h.numero_processo,''), NULLIF(h.processo_numero,'')) AS numero_processo,
           hp.parcela_numero,
           hp.valor_parcela,
           CASE WHEN COALESCE(hp.saldo_devedor,0) > 0 THEN hp.saldo_devedor ELSE hp.valor_parcela END AS saldo_devedor,
           hp.data_vencimento,
           hp.status_pagamento
    FROM honorarios_parcelas hp
    LEFT JOIN honorarios h ON hp.honorario_id = h.id
        AND h.tenant_id = hp.tenant_id
        AND h.escritorio_id = hp.escritorio_id
    WHERE COALESCE(h.deletado,0) = 0
      AND hp.data_vencimento IS NOT NULL
      AND COALESCE(hp.status_pagamento,'Pendente') NOT IN ('Pago','Quitada','Recebido','Cancelado')
      AND COALESCE(hp.saldo_devedor, hp.valor_parcela, 0) > 0
    ORDER BY (hp.data_vencimento < '{$hoje}') DESC, hp.data_vencimento ASC
    LIMIT 8
");

$contasVencendo = queryRows($conn, "
    SELECT id, descricao, fornecedor, valor, valor_pendente, data_vencimento, status
    FROM contas_pagar
    WHERE deletado = 0
      AND status IN ('Pendente','Parcial')
      AND data_vencimento BETWEEN '{$hoje}' AND DATE_ADD('{$hoje}', INTERVAL 15 DAY)
    ORDER BY data_vencimento ASC
    LIMIT 6
");

$processosPorStatus = queryRows($conn, "
    SELECT status, COUNT(*) AS total
    FROM processos
    WHERE status <> 'Excluído'
    GROUP BY status
    ORDER BY total DESC
");

$processosPorTipo = queryRows($conn, "
    SELECT COALESCE(NULLIF(tipo_processo, ''), 'Não informado') AS tipo, COUNT(*) AS total
    FROM processos
    WHERE status <> 'Excluído'
    GROUP BY COALESCE(NULLIF(tipo_processo, ''), 'Não informado')
    ORDER BY total DESC
    LIMIT 5
");

$maxTipo = 1;
foreach ($processosPorTipo as $item) {
    $maxTipo = max($maxTipo, (int)$item['total']);
}



if (($_GET['acao'] ?? '') === 'resumo_processos_pdf') {
    $totalResumoProcessos = (int)totalScalar($conn, "SELECT COUNT(*) AS total FROM processos WHERE status <> 'Excluído'");
    $ativosResumoProcessos = (int)totalScalar($conn, "SELECT COUNT(*) AS total FROM processos WHERE status = 'Em Andamento'");
    $prazosResumoProcessos = (int)totalScalar($conn, "SELECT COUNT(*) AS total FROM processos WHERE status = 'Em Andamento' AND proximo_prazo BETWEEN '{$hoje}' AND DATE_ADD('{$hoje}', INTERVAL 15 DAY)");
    ?>
    <div class="container-fluid resumo-processos-relatorio">
        <style>
            @media print {
                .sidebar, .no-print, nav, header { display:none!important; }
                main { padding:0!important; margin:0!important; }
                body { background:#fff!important; }
                .resumo-processos-relatorio { font-size:12px; }
                .card { box-shadow:none!important; }
            }
            @page { size: A4 portrait; margin: 12mm; }
        </style>
        <div class="d-flex justify-content-between align-items-start mb-3 no-print">
            <div>
                <h2 class="fw-bold text-primary"><i class="bi bi-bar-chart-line"></i> Relatório — Resumo de Processos</h2>
                <p class="text-muted mb-0">Análise rápida para equipe em <?= date('d/m/Y H:i') ?>.</p>
            </div>
            <div class="d-flex gap-2">
                <button class="btn btn-primary" onclick="window.print()"><i class="bi bi-printer"></i> Imprimir / Salvar PDF</button>
                <a href="?mod=dashboard" class="btn btn-outline-secondary">Voltar</a>
            </div>
        </div>
        <div class="text-center mb-4 d-none d-print-block">
            <h2>Resumo de Processos</h2>
            <p>Emitido em <?= date('d/m/Y H:i') ?></p>
        </div>
        <div class="row g-3 mb-3">
            <div class="col-md-4"><div class="card border-0 shadow-sm"><div class="card-body"><div class="text-muted text-uppercase small">Total</div><div class="fs-3 fw-bold"><?= $totalResumoProcessos ?></div></div></div></div>
            <div class="col-md-4"><div class="card border-0 shadow-sm"><div class="card-body"><div class="text-muted text-uppercase small">Em andamento</div><div class="fs-3 fw-bold text-primary"><?= $ativosResumoProcessos ?></div></div></div></div>
            <div class="col-md-4"><div class="card border-0 shadow-sm"><div class="card-body"><div class="text-muted text-uppercase small">Prazos 15 dias</div><div class="fs-3 fw-bold text-warning"><?= $prazosResumoProcessos ?></div></div></div></div>
        </div>
        <div class="card shadow-sm border-0 mb-3">
            <div class="card-header bg-dark text-white fw-bold">Processos por status</div>
            <div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>Status</th><th class="text-end">Quantidade</th></tr></thead><tbody>
            <?php if (empty($processosPorStatus)): ?><tr><td colspan="2" class="text-center text-muted py-3">Nenhum processo cadastrado.</td></tr><?php endif; ?>
            <?php foreach ($processosPorStatus as $status): ?><tr><td><?= badgeStatus((string)$status['status']) ?></td><td class="text-end fw-bold"><?= (int)$status['total'] ?></td></tr><?php endforeach; ?>
            </tbody></table></div>
        </div>
        <div class="card shadow-sm border-0">
            <div class="card-header bg-dark text-white fw-bold">Processos por tipo</div>
            <div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>Tipo</th><th class="text-end">Quantidade</th></tr></thead><tbody>
            <?php if (empty($processosPorTipo)): ?><tr><td colspan="2" class="text-center text-muted py-3">Sem dados para exibir.</td></tr><?php endif; ?>
            <?php foreach ($processosPorTipo as $tipo): ?><tr><td><?= h($tipo['tipo']) ?></td><td class="text-end fw-bold"><?= (int)$tipo['total'] ?></td></tr><?php endforeach; ?>
            </tbody></table></div>
        </div>
    </div>
    <?php
    $conn->close();
    return;
}

$alertas = [];
if ($contasPagarVencidas > 0) {
    $alertas[] = ['classe' => 'danger', 'icone' => 'bi-exclamation-triangle-fill', 'texto' => $contasPagarVencidas . ' conta(s) a pagar vencida(s).'];
}
if ($honorariosVencidos > 0) {
    $alertas[] = ['classe' => 'warning', 'icone' => 'bi-cash-coin', 'texto' => $honorariosVencidos . ' parcela(s) de honorários vencida(s).'];
}
if ($prazos7Dias > 0) {
    $alertas[] = ['classe' => 'primary', 'icone' => 'bi-calendar2-week', 'texto' => $prazos7Dias . ' prazo(s) processual(is) nos próximos 7 dias.'];
}
if ($compromissosHoje > 0) {
    $alertas[] = ['classe' => 'info', 'icone' => 'bi-calendar-check', 'texto' => $compromissosHoje . ' compromisso(s) na agenda de hoje.'];
}
?>

<div class="container-fluid dashboard-executivo">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
        <div>
            <h2 class="mb-1">📊 Dashboard Executivo</h2>
            <p class="text-muted mb-0">Visão geral operacional e financeira do escritório.</p>
        </div>
        <span class="badge bg-dark px-3 py-2">📅 Hoje: <?= date('d/m/Y') ?></span>
    </div>


    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center">
            <span><i class="bi bi-stars me-2"></i>Central Inteligente ROJEX.AI</span>
            <span class="badge bg-primary">Painel estratégico</span>
        </div>
        <div class="card-body">
            <div class="row g-3 align-items-stretch">
                <div class="col-xl-3 col-md-6">
                    <div class="border rounded-3 p-3 h-100 bg-light">
                        <div class="small text-muted text-uppercase mb-1">Agenda de hoje</div>
                        <div class="fs-4 fw-bold <?= $compromissosHoje > 0 ? 'text-primary' : 'text-muted' ?>"><?= (int)$compromissosHoje ?></div>
                        <div class="small text-muted"><?= (int)$audienciasHoje ?> audiência(s) hoje</div>
                        <a href="?mod=agenda" class="btn btn-sm btn-outline-primary mt-2"><i class="bi bi-calendar-event me-1"></i>Abrir agenda</a>
                    </div>
                </div>
                <div class="col-xl-3 col-md-6">
                    <div class="border rounded-3 p-3 h-100 bg-light">
                        <div class="small text-muted text-uppercase mb-1">Prazos críticos</div>
                        <div class="fs-4 fw-bold <?= $prazos7Dias > 0 ? 'text-warning' : 'text-success' ?>"><?= (int)$prazos7Dias ?></div>
                        <div class="small text-muted">Processuais nos próximos 7 dias</div>
                        <a href="?mod=processos" class="btn btn-sm btn-outline-warning mt-2"><i class="bi bi-folder2-open me-1"></i>Ver processos</a>
                    </div>
                </div>
                <div class="col-xl-3 col-md-6">
                    <div class="border rounded-3 p-3 h-100 bg-light">
                        <div class="small text-muted text-uppercase mb-1">Financeiro em atenção</div>
                        <div class="fs-4 fw-bold <?= ($contasPagarVencidas + $honorariosVencidos) > 0 ? 'text-danger' : 'text-success' ?>"><?= (int)($contasPagarVencidas + $honorariosVencidos) ?></div>
                        <div class="small text-muted">Pendências vencidas identificadas</div>
                        <a href="?mod=financeiro" class="btn btn-sm btn-outline-danger mt-2"><i class="bi bi-cash-coin me-1"></i>Abrir financeiro</a>
                    </div>
                </div>
                <div class="col-xl-3 col-md-6">
                    <div class="border rounded-3 p-3 h-100 bg-light">
                        <div class="small text-muted text-uppercase mb-1">Busca global</div>
                        <div class="fs-6 fw-bold text-dark">Localizar dados rápido</div>
                        <div class="small text-muted">Clientes, processos, documentos e financeiro</div>
                        <form method="GET" action="index.php" class="mt-2">
                            <input type="hidden" name="mod" value="busca">
                            <div class="input-group input-group-sm">
                                <input type="search" name="q" class="form-control" placeholder="Cliente, CPF/CNPJ, advogado, processo, agenda ou documento" value="<?= h($_GET['q'] ?? '') ?>" required>
                                <button class="btn btn-primary" type="submit" title="Pesquisar na Busca Global Enterprise" aria-label="Pesquisar na Busca Global Enterprise"><i class="bi bi-search"></i></button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
            <div class="alert alert-info mt-3 mb-0">
                <i class="bi bi-lightbulb me-1"></i>
                A Central Inteligente consolida agenda, prazos, financeiro e busca global para orientar as próximas ações do escritório.
            </div>
        </div>
    </div>

    <?php
    $iaPergunta = trim((string)($_GET['ia_pergunta'] ?? ''));
    $iaAcao = trim((string)($_GET['ia_acao'] ?? ''));
    $iaResposta = '';
    $iaResultadosRapidos = [];
    $iaLinkCij = '?mod=cij';

    if ($iaAcao !== '') {
        switch ($iaAcao) {
            case 'agenda_hoje':
                $iaResposta = 'Hoje existem ' . (int)$compromissosHoje . ' compromisso(s) cadastrados, sendo ' . (int)$audienciasHoje . ' audiência(s).';
                break;
            case 'prazos':
                $iaResposta = 'Foram identificados ' . (int)$prazos7Dias . ' prazo(s) processual(is) nos próximos 7 dias. Use o módulo Processos para revisar os detalhes.';
                break;
            case 'financeiro':
                $iaResposta = 'Resumo financeiro: recebido no mês ' . moeda($recebidoMes) . ', total a receber ' . moeda($totalAReceber) . ', despesas em aberto ' . moeda($despesasAbertas) . ' e saldo estimado ' . moeda($saldoEstimado) . '.';
                break;
            case 'honorarios':
                $iaResposta = 'Há ' . (int)$honorariosVencidos . ' parcela(s) de honorários vencida(s). A lista de honorários pendentes aparece mais abaixo no Dashboard.';
                break;
        }
    } elseif ($iaPergunta !== '') {
        if (function_exists('sgl_busca_global')) {
            $iaResultadosRapidos = array_slice(sgl_busca_global($conn, $iaPergunta), 0, 5);
        }

        if (!empty($iaResultadosRapidos)) {
            $quantidade = count($iaResultadosRapidos);
            $iaResposta = 'Encontrei ' . $quantidade . ' resultado(s) relacionado(s) à sua consulta.';
        } else {
            $iaResposta = 'Não encontrei resultado direto na Busca Global. Consulte o CIJ para uma interpretação mais completa da pergunta.';
        }

        $iaLinkCij = '?mod=cij&pergunta=' . urlencode($iaPergunta);
    }
    ?>

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center">
            <span><i class="bi bi-robot me-2"></i>IA Jurídica ROJEX.AI</span>
            <span class="badge bg-success">Estrutura preparada</span>
        </div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-lg-7">
                    <form method="GET" action="index.php">
                        <input type="hidden" name="mod" value="dashboard">
                        <label class="form-label fw-semibold">Pergunte ao assistente jurídico</label>
                        <div class="input-group">
                            <input type="text" name="ia_pergunta" class="form-control" placeholder="Ex.: qual é o processo 1000?" value="<?= h($iaPergunta) ?>" required>
                            <button class="btn btn-primary" type="submit"><i class="bi bi-send me-1"></i>Consultar</button>
                        </div>
                        <div class="form-text">O Dashboard mostra uma resposta rápida. Para análise completa, use o Centro de Inteligência Jurídica.</div>
                    </form>

                    <div class="d-flex flex-wrap gap-2 mt-3">
                        <a href="?mod=dashboard&ia_acao=agenda_hoje" class="btn btn-sm btn-outline-primary"><i class="bi bi-calendar-check me-1"></i>Agenda de hoje</a>
                        <a href="?mod=dashboard&ia_acao=prazos" class="btn btn-sm btn-outline-warning"><i class="bi bi-clock-history me-1"></i>Prazos próximos</a>
                        <a href="?mod=dashboard&ia_acao=financeiro" class="btn btn-sm btn-outline-success"><i class="bi bi-cash-coin me-1"></i>Resumo financeiro</a>
                        <a href="?mod=dashboard&ia_acao=honorarios" class="btn btn-sm btn-outline-danger"><i class="bi bi-receipt me-1"></i>Honorários vencidos</a>
                    </div>
                </div>
                <div class="col-lg-5">
                    <div class="border rounded-3 p-3 h-100 bg-light">
                        <div class="small text-muted text-uppercase mb-2">Resposta da IA</div>
                        <?php if ($iaResposta !== ''): ?>
                            <div class="fw-semibold text-dark mb-2"><?= h($iaResposta) ?></div>

                            <?php if (!empty($iaResultadosRapidos)): ?>
                                <div class="list-group list-group-flush mb-3">
                                    <?php foreach ($iaResultadosRapidos as $resultadoRapido): ?>
                                        <a href="<?= h(dashboardLinkResultadoRapido($resultadoRapido)) ?>"
                                           class="list-group-item list-group-item-action px-0 py-2 bg-transparent">
                                            <div class="small text-primary fw-bold"><?= h($resultadoRapido['modulo'] ?? 'Resultado') ?></div>
                                            <div class="small text-dark"><?= h($resultadoRapido['texto'] ?? '') ?></div>
                                        </a>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>

                            <?php if ($iaPergunta !== ''): ?>
                                <a href="<?= h($iaLinkCij) ?>" class="btn btn-sm btn-outline-primary">
                                    <i class="bi bi-cpu me-1"></i>Ver detalhes no CIJ
                                </a>
                                <a href="?mod=dashboard" class="btn btn-sm btn-outline-secondary ms-1">
                                    <i class="bi bi-x-lg me-1"></i>Limpar
                                </a>
                            <?php endif; ?>
                        <?php else: ?>
                            <div class="text-muted">Use uma consulta rápida ou digite uma pergunta para obter um resumo no Dashboard.</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>


    <div class="row g-3 mb-4">
        <div class="col-xl-3 col-md-6">
            <div class="card text-white bg-success h-100 shadow-sm position-relative overflow-hidden">
                <div class="card-body">
                    <h6 class="text-uppercase text-white-50 small mb-2">Recebido no mês</h6>
                    <h3 class="fw-bold mb-0"><?= moeda($recebidoMes) ?></h3>
                    <small class="text-white-50">Entradas confirmadas em <?= date('m/Y') ?></small>
                    <i class="bi bi-cash-stack fs-1 position-absolute end-0 bottom-0 m-3 opacity-25"></i>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="card text-white bg-primary h-100 shadow-sm position-relative overflow-hidden">
                <div class="card-body">
                    <h6 class="text-uppercase text-white-50 small mb-2">Total a receber</h6>
                    <h3 class="fw-bold mb-0"><?= moeda($totalAReceber) ?></h3>
                    <small class="text-white-50">Honorários + contas em aberto</small>
                    <i class="bi bi-graph-up-arrow fs-1 position-absolute end-0 bottom-0 m-3 opacity-25"></i>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="card text-white bg-danger h-100 shadow-sm position-relative overflow-hidden">
                <div class="card-body">
                    <h6 class="text-uppercase text-white-50 small mb-2">Despesas em aberto</h6>
                    <h3 class="fw-bold mb-0"><?= moeda($despesasAbertas) ?></h3>
                    <small class="text-white-50">Pendentes e parciais</small>
                    <i class="bi bi-wallet2 fs-1 position-absolute end-0 bottom-0 m-3 opacity-25"></i>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="card text-white bg-<?= $saldoEstimado >= 0 ? 'info' : 'secondary' ?> h-100 shadow-sm position-relative overflow-hidden">
                <div class="card-body">
                    <h6 class="text-uppercase text-white-50 small mb-2">Saldo estimado</h6>
                    <h3 class="fw-bold mb-0"><?= moeda($saldoEstimado) ?></h3>
                    <small class="text-white-50">Caixa/Bancos + previsto - despesas abertas</small>
                    <i class="bi bi-calculator fs-1 position-absolute end-0 bottom-0 m-3 opacity-25"></i>
                </div>
            </div>
        </div>
    </div>


    <?php if (!empty($contasCaixaBancos)): ?>
    <div class="row g-3 mb-4">
        <div class="col-12">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-bank me-2"></i>Saldo real por Caixa/Bancos</span>
                    <a href="?mod=financeiro&acao=movimentacao_bancos" class="btn btn-sm btn-outline-light">Abrir movimentação</a>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <?php foreach ($contasCaixaBancos as $cb): ?>
                            <div class="col-xl-3 col-md-6">
                                <div class="border rounded-3 p-3 h-100">
                                    <div class="text-muted small text-uppercase"><?= h($cb['tipo'] ?: 'Conta') ?></div>
                                    <div class="fw-bold"><?= h($cb['nome']) ?></div>
                                    <div class="small text-muted"><?= h(trim(($cb['banco'] ?? '') . ' ' . ($cb['conta'] ?? '')) ?: 'Caixa físico') ?></div>
                                    <div class="fs-5 fw-bold mt-2 <?= ((float)$cb['saldo_atual'] >= 0) ? 'text-success' : 'text-danger' ?>"><?= moeda($cb['saldo_atual']) ?></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        <div class="col-xl-3 col-md-6">
                            <div class="border rounded-3 p-3 h-100 bg-light">
                                <div class="text-muted small text-uppercase">Total disponível</div>
                                <div class="fw-bold">Caixa + Bancos</div>
                                <div class="small text-muted">Inclui transferências internas</div>
                                <div class="fs-5 fw-bold mt-2 <?= $saldoCaixaBancosTotal >= 0 ? 'text-primary' : 'text-danger' ?>"><?= moeda($saldoCaixaBancosTotal) ?></div>
                            </div>
                        </div>
                    </div>
                    <div class="small text-muted mt-3">Transferências entre Caixa e Banco não aumentam o saldo total; elas apenas mudam o dinheiro de uma conta para outra.</div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="row g-3 mb-4">
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-cash-register me-2"></i>Fechamento de Caixa do Dia</span>
                    <a href="?mod=financeiro&acao=caixa&periodo=dia" class="btn btn-sm btn-outline-light">Abrir fechamento</a>
                </div>
                <div class="card-body">
                    <div class="row text-center g-3">
                        <div class="col-4"><small class="text-muted text-uppercase">Entradas</small><div class="fw-bold text-success fs-5"><?= moeda($entradasHoje) ?></div></div>
                        <div class="col-4"><small class="text-muted text-uppercase">Saídas</small><div class="fw-bold text-danger fs-5"><?= moeda($saidasHoje) ?></div></div>
                        <div class="col-4"><small class="text-muted text-uppercase">Saldo</small><div class="fw-bold <?= $saldoCaixaHoje >= 0 ? 'text-primary' : 'text-danger' ?> fs-5"><?= moeda($saldoCaixaHoje) ?></div></div>
                    </div>
                    <div class="small text-muted mt-3">Inclui recebimentos/despesas em CAIXA e transferências CAIXA ↔ Banco em <?= date('d/m/Y') ?>.</div>
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-calendar3 me-2"></i>Fechamento de Caixa Mensal</span>
                    <a href="?mod=financeiro&acao=caixa&periodo=mes" class="btn btn-sm btn-outline-light"><i class="bi bi-file-earmark-pdf me-1"></i>Abrir fechamento</a>
                </div>
                <div class="card-body">
                    <div class="row text-center g-3">
                        <div class="col-4"><small class="text-muted text-uppercase">Entradas</small><div class="fw-bold text-success fs-5"><?= moeda($entradasMes) ?></div></div>
                        <div class="col-4"><small class="text-muted text-uppercase">Saídas</small><div class="fw-bold text-danger fs-5"><?= moeda($saidasMes) ?></div></div>
                        <div class="col-4"><small class="text-muted text-uppercase">Resultado</small><div class="fw-bold <?= $saldoCaixaMes >= 0 ? 'text-primary' : 'text-danger' ?> fs-5"><?= moeda($saldoCaixaMes) ?></div></div>
                    </div>
                    <div class="small text-muted mt-3">Inclui movimentações internas do CAIXA no mês <?= date('m/Y') ?>.</div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-xl-3 col-md-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex align-items-center justify-content-between">
                    <div>
                        <h3 class="fw-bold mb-0"><?= $totalClientes ?></h3>
                        <small class="text-muted text-uppercase">Clientes ativos</small>
                        <div class="small text-success mt-1">+<?= $clientesNovosMes ?> no mês</div>
                    </div>
                    <span class="fs-1 text-primary opacity-50"><i class="bi bi-people"></i></span>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex align-items-center justify-content-between">
                    <div>
                        <h3 class="fw-bold mb-0"><?= $processosAtivos ?></h3>
                        <small class="text-muted text-uppercase">Processos ativos</small>
                        <div class="small text-muted mt-1"><?= $totalProcessos ?> cadastrados</div>
                    </div>
                    <span class="fs-1 text-warning opacity-75"><i class="bi bi-folder2-open"></i></span>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex align-items-center justify-content-between">
                    <div>
                        <h3 class="fw-bold <?= $contasPagarHoje > 0 ? 'text-danger' : 'text-success' ?> mb-0"><?= $contasPagarHoje ?></h3>
                        <small class="text-muted text-uppercase">Despesas vencendo hoje</small>
                        <div class="small text-danger mt-1"><?= $contasPagarVencidas ?> vencida(s)</div>
                    </div>
                    <span class="fs-1 text-danger opacity-50"><i class="bi bi-exclamation-triangle"></i></span>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex align-items-center justify-content-between">
                    <div>
                        <h3 class="fw-bold <?= $audienciasHoje > 0 ? 'text-primary' : 'text-muted' ?> mb-0"><?= $audienciasHoje ?></h3>
                        <small class="text-muted text-uppercase">Audiências hoje</small>
                        <div class="small text-muted mt-1"><?= $compromissosHoje ?> compromisso(s)</div>
                    </div>
                    <span class="fs-1 text-info opacity-75"><i class="bi bi-calendar-event"></i></span>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4 mb-4">
        <div class="col-lg-8">
            <div class="card shadow-sm h-100">
                <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-calendar-check me-2"></i>Agenda de Hoje</span>
                    <a href="?mod=agenda" class="btn btn-sm btn-outline-light">Abrir agenda</a>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Hora</th>
                                    <th>Compromisso</th>
                                    <th>Cliente</th>
                                    <th>Processo</th>
                                    <th>Local</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php if (!empty($agendaHoje)): ?>
                                <?php foreach ($agendaHoje as $item): ?>
                                    <tr>
                                        <td class="fw-bold"><?= horaBr($item['horario'] ?? '') ?></td>
                                        <td><?= h($item['tipo_compromisso'] ?: 'Compromisso') ?></td>
                                        <td><?= h($item['nome_cliente'] ?: '-') ?></td>
                                        <td><?= h($item['numero_processo'] ?: ($item['processo_numero'] ?? '-')) ?></td>
                                        <td><?= h($item['local_evento'] ?: ($item['local'] ?? '-')) ?></td>
                                        <td><?= badgeStatus((string)($item['status'] ?? '')) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="6" class="text-center text-muted py-4">Nenhum compromisso cadastrado para hoje.</td></tr>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card shadow-sm h-100">
                <div class="card-header bg-dark text-white"><i class="bi bi-bell me-2"></i>Alertas Rápidos</div>
                <div class="card-body">
                    <?php if (!empty($alertas)): ?>
                        <?php foreach ($alertas as $alerta): ?>
                            <div class="alert alert-<?= h($alerta['classe']) ?> d-flex align-items-center py-2 mb-2" role="alert">
                                <i class="bi <?= h($alerta['icone']) ?> me-2"></i>
                                <div><?= h($alerta['texto']) ?></div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="text-center text-muted py-4">
                            <i class="bi bi-check-circle fs-1 text-success"></i>
                            <p class="mb-0 mt-2">Nenhum alerta crítico no momento.</p>
                        </div>
                    <?php endif; ?>
                    <hr>
                    <div class="row text-center small">
                        <div class="col-6 border-end">
                            <div class="fw-bold text-success"><?= moeda($recebimentosSemana) ?></div>
                            <span class="text-muted">Recebido na semana</span>
                        </div>
                        <div class="col-6">
                            <div class="fw-bold text-danger"><?= moeda($despesasSemana) ?></div>
                            <span class="text-muted">Pago na semana</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4 mb-4">
        <div class="col-lg-6">
            <div class="card shadow-sm h-100">
                <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-clock-history me-2"></i>Prazos Processuais Próximos</span>
                    <a href="?mod=processos" class="btn btn-sm btn-outline-light">Ver processos</a>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr><th>Processo</th><th>Tipo</th><th>Fase</th><th>Prazo</th></tr>
                            </thead>
                            <tbody>
                            <?php if (!empty($prazosProximos)): ?>
                                <?php foreach ($prazosProximos as $proc): ?>
                                    <tr>
                                        <td><a class="fw-bold text-decoration-none" href="?mod=processos&acao=editar&id=<?= urlencode($proc['id']) ?>"><?= h($proc['numero_processo']) ?></a></td>
                                        <td><?= h($proc['tipo_processo'] ?: '-') ?></td>
                                        <td><?= h($proc['fase_atual'] ?: '-') ?></td>
                                        <td><span class="badge bg-primary"><?= dataBr($proc['proximo_prazo']) ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="4" class="text-center text-muted py-4">Nenhum prazo próximo encontrado.</td></tr>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="card shadow-sm h-100">
                <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-cash-coin me-2"></i>Honorários Pendentes</span>
                    <a href="?mod=honorarios" class="btn btn-sm btn-outline-light">Ver honorários</a>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr><th>Cliente</th><th>Parcela</th><th>Vencimento</th><th>Pendente</th></tr>
                            </thead>
                            <tbody>
                            <?php if (!empty($honorariosPendentes)): ?>
                                <?php foreach ($honorariosPendentes as $hon): ?>
                                    <?php $saldo = (float)($hon['saldo_devedor'] ?? 0) > 0 ? $hon['saldo_devedor'] : $hon['valor_parcela']; ?>
                                    <tr>
                                        <td><?= h($hon['nome_cliente'] ?: '-') ?><br><small class="text-muted"><?= h($hon['numero_processo'] ?: '') ?></small></td>
                                        <td><?= h($hon['parcela_numero']) ?></td>
                                        <td><?= dataBr($hon['data_vencimento']) ?></td>
                                        <td class="fw-bold text-danger"><?= moeda($saldo) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="4" class="text-center text-muted py-4">Nenhum honorário pendente encontrado.</td></tr>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-lg-6">
            <div class="card shadow-sm h-100">
                <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center"><span><i class="bi bi-wallet2 me-2"></i>Contas a Pagar Próximas</span><a href="?mod=financeiro&tab=pagar" class="btn btn-sm btn-outline-light">Ver contas</a></div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr><th>Descrição</th><th>Fornecedor</th><th>Vencimento</th><th>Valor</th></tr>
                            </thead>
                            <tbody>
                            <?php if (!empty($contasVencendo)): ?>
                                <?php foreach ($contasVencendo as $conta): ?>
                                    <?php $valorConta = (float)($conta['valor_pendente'] ?? 0) > 0 ? $conta['valor_pendente'] : $conta['valor']; ?>
                                    <tr>
                                        <td><?= h($conta['descricao'] ?: '-') ?></td>
                                        <td><?= h($conta['fornecedor'] ?: '-') ?></td>
                                        <td><?= dataBr($conta['data_vencimento']) ?></td>
                                        <td class="fw-bold text-danger"><?= moeda($valorConta) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="4" class="text-center text-muted py-4">Nenhuma conta a pagar nos próximos 15 dias.</td></tr>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="card shadow-sm h-100">
                <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center"><span><i class="bi bi-bar-chart-line me-2"></i>Resumo de Processos</span><a href="?mod=dashboard&acao=resumo_processos_pdf" class="btn btn-sm btn-outline-light"><i class="bi bi-file-earmark-pdf me-1"></i>Relatório PDF</a></div>
                <div class="card-body">
                    <h6 class="text-muted text-uppercase small">Por status</h6>
                    <div class="mb-3">
                        <?php if (!empty($processosPorStatus)): ?>
                            <?php foreach ($processosPorStatus as $status): ?>
                                <span class="me-2 mb-2 d-inline-block"><?= badgeStatus((string)$status['status']) ?> <strong><?= (int)$status['total'] ?></strong></span>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <p class="text-muted mb-0">Nenhum processo cadastrado.</p>
                        <?php endif; ?>
                    </div>

                    <h6 class="text-muted text-uppercase small mt-3">Principais tipos de processo</h6>
                    <?php if (!empty($processosPorTipo)): ?>
                        <?php foreach ($processosPorTipo as $tipo): ?>
                            <?php $percentual = max(5, ((int)$tipo['total'] / $maxTipo) * 100); ?>
                            <div class="mb-2">
                                <div class="d-flex justify-content-between small mb-1">
                                    <span><?= h($tipo['tipo']) ?></span>
                                    <strong><?= (int)$tipo['total'] ?></strong>
                                </div>
                                <div class="progress" style="height: 8px;">
                                    <div class="progress-bar" role="progressbar" style="width: <?= $percentual ?>%;" aria-valuenow="<?= (int)$tipo['total'] ?>" aria-valuemin="0" aria-valuemax="<?= $maxTipo ?>"></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p class="text-muted mb-0">Sem dados para exibir.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php $conn->close(); ?>