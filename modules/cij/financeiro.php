<?php
/**
 * modules/cij/financeiro.php
 * IA Financeira — ROJEX.AI Enterprise
 * Sprint 4.1.4 — Etapa 8
 *
 * Objetivos:
 * - Analisar dados financeiros internos em modo local seguro.
 * - Reutilizar a Base de Conhecimento existente.
 * - Identificar saldos, vencimentos, inadimplência e pontos de atenção.
 * - Preparar integração futura com IA externa.
 * - Não criar nem alterar tabelas nesta etapa.
 */

if (!isset($conn) || !($conn instanceof mysqli)) {
    $conn = conectar();
}

$arquivoBaseConhecimento = __DIR__ . '/../../config/base_conhecimento.php';
if (is_file($arquivoBaseConhecimento)) {
    require_once $arquivoBaseConhecimento;
}

$arquivoConfigIa = __DIR__ . '/../../config/ia.php';
if (is_file($arquivoConfigIa)) {
    require_once $arquivoConfigIa;
}

function cij_financeiro_h($valor): string
{
    return htmlspecialchars((string)($valor ?? ''), ENT_QUOTES, 'UTF-8');
}

function cij_financeiro_moeda($valor): string
{
    return 'R$ ' . number_format((float)$valor, 2, ',', '.');
}

function cij_financeiro_data_br(?string $data): string
{
    $data = trim((string)$data);
    if ($data === '' || $data === '0000-00-00') {
        return '-';
    }

    $timestamp = strtotime($data);
    return $timestamp ? date('d/m/Y', $timestamp) : $data;
}

function cij_financeiro_ia_disponivel(): bool
{
    return function_exists('sgl_ia_disponivel') && sgl_ia_disponivel();
}

function cij_financeiro_chamar_ia(string $promptSistema, string $promptUsuario): array
{
    if (function_exists('sgl_ia_chamar_openai')) {
        return sgl_ia_chamar_openai($promptSistema, $promptUsuario);
    }

    return [
        'ok' => false,
        'texto' => '',
        'erro' => 'Função de IA não encontrada em config/ia.php.',
    ];
}

function cij_financeiro_tabela_existe(mysqli $conn, string $tabela): bool
{
    if (function_exists('rojex_kb_tabela_existe')) {
        return rojex_kb_tabela_existe($conn, $tabela);
    }

    if (!preg_match('/^[a-zA-Z0-9_]+$/', $tabela)) {
        return false;
    }

    try {
        $stmt = $conn->prepare(
            'SELECT COUNT(*) AS total
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?'
        );

        if (!$stmt) {
            return false;
        }

        $stmt->bind_param('s', $tabela);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return ((int)($row['total'] ?? 0)) > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function cij_financeiro_consultar(
    mysqli $conn,
    string $sql,
    string $tipos = '',
    array $parametros = []
): array {
    if (function_exists('rojex_kb_consultar')) {
        return rojex_kb_consultar($conn, $sql, $tipos, $parametros);
    }

    try {
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return [];
        }

        if ($tipos !== '') {
            $refs = [];
            foreach ($parametros as $indice => $valor) {
                $refs[$indice] = &$parametros[$indice];
            }

            $stmt->bind_param($tipos, ...$refs);
        }

        $stmt->execute();
        $res = $stmt->get_result();
        $dados = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
        $stmt->close();

        return $dados;
    } catch (Throwable $e) {
        error_log('[CIJ Financeiro] ' . $e->getMessage());
        return [];
    }
}

function cij_financeiro_periodo_valido(string $inicio, string $fim): bool
{
    if (
        !preg_match('/^\d{4}-\d{2}-\d{2}$/', $inicio)
        || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fim)
    ) {
        return false;
    }

    return strtotime($inicio) !== false
        && strtotime($fim) !== false
        && $inicio <= $fim;
}

function cij_financeiro_dados_periodo(
    mysqli $conn,
    string $inicio,
    string $fim
): array {
    $hoje = date('Y-m-d');

    $dados = [
        'pagar_aberto' => 0.0,
        'receber_aberto' => 0.0,
        'pago_periodo' => 0.0,
        'recebido_periodo' => 0.0,
        'resultado_periodo' => 0.0,
        'saldo_estimado' => 0.0,
        'vencidas_pagar' => [],
        'vencidas_receber' => [],
        'honorarios_vencidos' => [],
        'contas_pagar_periodo' => [],
        'contas_receber_periodo' => [],
    ];

    if (function_exists('rojex_kb_resumo_financeiro')) {
        $resumo = rojex_kb_resumo_financeiro($conn, $hoje);
        $dados['pagar_aberto'] = (float)($resumo['pagar_aberto'] ?? 0);
        $dados['receber_aberto'] = (float)($resumo['receber_aberto'] ?? 0);
        $dados['saldo_estimado'] = (float)($resumo['saldo_estimado'] ?? (
            $dados['receber_aberto'] - $dados['pagar_aberto']
        ));
    } else {
        if (cij_financeiro_tabela_existe($conn, 'contas_pagar')) {
            $linhas = cij_financeiro_consultar(
                $conn,
                "SELECT COALESCE(SUM(valor_pendente), 0) AS total
                 FROM contas_pagar
                 WHERE COALESCE(deletado, 0) = 0
                   AND status IN ('Pendente', 'Parcial')"
            );
            $dados['pagar_aberto'] = (float)($linhas[0]['total'] ?? 0);
        }

        if (cij_financeiro_tabela_existe($conn, 'contas_receber')) {
            $linhas = cij_financeiro_consultar(
                $conn,
                "SELECT COALESCE(SUM(
                    CASE
                        WHEN COALESCE(valor_pendente, 0) > 0 THEN valor_pendente
                        ELSE valor
                    END
                 ), 0) AS total
                 FROM contas_receber
                 WHERE COALESCE(deletado, 0) = 0
                   AND status IN ('Pendente', 'Parcial')"
            );
            $dados['receber_aberto'] = (float)($linhas[0]['total'] ?? 0);
        }

        $dados['saldo_estimado'] = $dados['receber_aberto'] - $dados['pagar_aberto'];
    }

    if (cij_financeiro_tabela_existe($conn, 'contas_pagar')) {
        $linhas = cij_financeiro_consultar(
            $conn,
            "SELECT COALESCE(SUM(
                CASE
                    WHEN COALESCE(valor_pago, 0) > 0 THEN valor_pago
                    ELSE valor
                END
             ), 0) AS total
             FROM contas_pagar
             WHERE COALESCE(deletado, 0) = 0
               AND status IN ('Pago', 'Quitada')
               AND data_pagamento BETWEEN ? AND ?",
            'ss',
            [$inicio, $fim]
        );
        $dados['pago_periodo'] = (float)($linhas[0]['total'] ?? 0);

        $dados['vencidas_pagar'] = cij_financeiro_consultar(
            $conn,
            "SELECT id, descricao, categoria, fornecedor, valor,
                    valor_pago, valor_pendente, data_vencimento,
                    forma_pagamento, status
             FROM contas_pagar
             WHERE COALESCE(deletado, 0) = 0
               AND status IN ('Pendente', 'Parcial')
               AND data_vencimento < ?
             ORDER BY data_vencimento ASC, id ASC
             LIMIT 30",
            's',
            [$hoje]
        );

        $dados['contas_pagar_periodo'] = cij_financeiro_consultar(
            $conn,
            "SELECT id, descricao, categoria, fornecedor, valor,
                    valor_pago, valor_pendente, data_vencimento,
                    data_pagamento, forma_pagamento, status
             FROM contas_pagar
             WHERE COALESCE(deletado, 0) = 0
               AND (
                    data_vencimento BETWEEN ? AND ?
                    OR data_pagamento BETWEEN ? AND ?
               )
             ORDER BY COALESCE(data_pagamento, data_vencimento) ASC, id ASC
             LIMIT 100",
            'ssss',
            [$inicio, $fim, $inicio, $fim]
        );
    }

    if (cij_financeiro_tabela_existe($conn, 'contas_receber')) {
        $linhas = cij_financeiro_consultar(
            $conn,
            "SELECT COALESCE(SUM(
                CASE
                    WHEN COALESCE(valor_pago, 0) > 0 THEN valor_pago
                    ELSE valor
                END
             ), 0) AS total
             FROM contas_receber
             WHERE COALESCE(deletado, 0) = 0
               AND status IN ('Recebido', 'Pago', 'Quitada')
               AND data_recebimento BETWEEN ? AND ?",
            'ss',
            [$inicio, $fim]
        );
        $dados['recebido_periodo'] = (float)($linhas[0]['total'] ?? 0);

        $dados['vencidas_receber'] = cij_financeiro_consultar(
            $conn,
            "SELECT cr.id, cr.descricao, cr.valor, cr.valor_pago,
                    cr.valor_pendente, cr.data_vencimento,
                    cr.forma_recebimento, cr.status,
                    COALESCE(c.nome, '') AS cliente_nome
             FROM contas_receber cr
             LEFT JOIN clientes c ON c.id = cr.cliente_id
             WHERE COALESCE(cr.deletado, 0) = 0
               AND cr.status IN ('Pendente', 'Parcial')
               AND cr.data_vencimento < ?
             ORDER BY cr.data_vencimento ASC, cr.id ASC
             LIMIT 30",
            's',
            [$hoje]
        );

        $dados['contas_receber_periodo'] = cij_financeiro_consultar(
            $conn,
            "SELECT cr.id, cr.descricao, cr.valor, cr.valor_pago,
                    cr.valor_pendente, cr.data_vencimento,
                    cr.data_recebimento, cr.forma_recebimento,
                    cr.status, COALESCE(c.nome, '') AS cliente_nome
             FROM contas_receber cr
             LEFT JOIN clientes c ON c.id = cr.cliente_id
             WHERE COALESCE(cr.deletado, 0) = 0
               AND (
                    cr.data_vencimento BETWEEN ? AND ?
                    OR cr.data_recebimento BETWEEN ? AND ?
               )
             ORDER BY COALESCE(cr.data_recebimento, cr.data_vencimento) ASC, cr.id ASC
             LIMIT 100",
            'ssss',
            [$inicio, $fim, $inicio, $fim]
        );
    }

    if (function_exists('rojex_kb_honorarios_vencidos')) {
        $dados['honorarios_vencidos'] = rojex_kb_honorarios_vencidos(
            $conn,
            $hoje,
            30
        );
    }

    $dados['resultado_periodo'] =
        $dados['recebido_periodo'] - $dados['pago_periodo'];

    return $dados;
}

function cij_financeiro_alertas(array $dados): array
{
    $alertas = [];

    $qtdPagarVencida = count($dados['vencidas_pagar'] ?? []);
    $qtdReceberVencida = count($dados['vencidas_receber'] ?? []);
    $qtdHonorariosVencidos = count($dados['honorarios_vencidos'] ?? []);

    if ($qtdPagarVencida > 0) {
        $alertas[] = "Existem {$qtdPagarVencida} conta(s) a pagar vencida(s).";
    }

    if ($qtdReceberVencida > 0) {
        $alertas[] = "Existem {$qtdReceberVencida} conta(s) a receber vencida(s), indicando necessidade de cobrança.";
    }

    if ($qtdHonorariosVencidos > 0) {
        $alertas[] = "Existem {$qtdHonorariosVencidos} honorário(s) vencido(s) ou com saldo pendente.";
    }

    if ((float)($dados['resultado_periodo'] ?? 0) < 0) {
        $alertas[] = 'O resultado operacional do período está negativo: as saídas superaram as entradas.';
    }

    if ((float)($dados['saldo_estimado'] ?? 0) < 0) {
        $alertas[] = 'O saldo estimado entre valores a receber e a pagar está negativo.';
    }

    if ((float)($dados['pagar_aberto'] ?? 0) > (float)($dados['receber_aberto'] ?? 0)) {
        $alertas[] = 'O total a pagar em aberto é superior ao total a receber em aberto.';
    }

    if ($alertas === []) {
        $alertas[] = 'Nenhum alerta financeiro crítico foi identificado pelo modo local para os dados disponíveis.';
    }

    return $alertas;
}

function cij_financeiro_relatorio_local(
    array $dados,
    array $alertas,
    string $inicio,
    string $fim,
    string $foco
): string {
    $linhas = [];
    $linhas[] = 'RELATÓRIO DE ANÁLISE FINANCEIRA — ROJEX.AI';
    $linhas[] = '';
    $linhas[] = 'Período analisado: ' . cij_financeiro_data_br($inicio)
        . ' a ' . cij_financeiro_data_br($fim);
    $linhas[] = 'Foco da análise: ' . ($foco ?: 'Visão geral');
    $linhas[] = '';
    $linhas[] = 'RESUMO EXECUTIVO';
    $linhas[] = '- Contas a pagar em aberto: '
        . cij_financeiro_moeda($dados['pagar_aberto'] ?? 0);
    $linhas[] = '- Contas a receber em aberto: '
        . cij_financeiro_moeda($dados['receber_aberto'] ?? 0);
    $linhas[] = '- Pagamentos realizados no período: '
        . cij_financeiro_moeda($dados['pago_periodo'] ?? 0);
    $linhas[] = '- Recebimentos realizados no período: '
        . cij_financeiro_moeda($dados['recebido_periodo'] ?? 0);
    $linhas[] = '- Resultado operacional do período: '
        . cij_financeiro_moeda($dados['resultado_periodo'] ?? 0);
    $linhas[] = '- Saldo estimado: '
        . cij_financeiro_moeda($dados['saldo_estimado'] ?? 0);
    $linhas[] = '';
    $linhas[] = 'INDICADORES DE ATENÇÃO';
    $linhas[] = '- Contas a pagar vencidas: '
        . count($dados['vencidas_pagar'] ?? []);
    $linhas[] = '- Contas a receber vencidas: '
        . count($dados['vencidas_receber'] ?? []);
    $linhas[] = '- Honorários vencidos: '
        . count($dados['honorarios_vencidos'] ?? []);
    $linhas[] = '';
    $linhas[] = 'PONTOS DE ATENÇÃO';

    foreach ($alertas as $alerta) {
        $linhas[] = '- ' . $alerta;
    }

    $linhas[] = '';
    $linhas[] = 'RECOMENDAÇÕES OPERACIONAIS';
    $linhas[] = '- Priorizar a cobrança dos recebimentos e honorários mais antigos.';
    $linhas[] = '- Conferir compromissos de pagamento próximos e negociar vencimentos quando necessário.';
    $linhas[] = '- Validar os lançamentos, datas, bancos/caixas, formas de pagamento e respectivos comprovantes.';
    $linhas[] = '- Separar transferências internas de receitas e despesas operacionais.';
    $linhas[] = '- Realizar conferência financeira e contábil antes de qualquer decisão administrativa.';
    $linhas[] = '';
    $linhas[] = 'OBSERVAÇÃO';
    $linhas[] = 'Esta análise é gerencial e utiliza os registros internos disponíveis. Não substitui conferência contábil, fiscal ou bancária.';

    return implode("\n", $linhas);
}

function cij_financeiro_prompt_ia(
    array $dados,
    string $inicio,
    string $fim,
    string $foco,
    string $observacoes
): string {
    $resumo = [
        'periodo_inicio' => $inicio,
        'periodo_fim' => $fim,
        'foco' => $foco,
        'observacoes_usuario' => $observacoes,
        'pagar_aberto' => (float)($dados['pagar_aberto'] ?? 0),
        'receber_aberto' => (float)($dados['receber_aberto'] ?? 0),
        'pago_periodo' => (float)($dados['pago_periodo'] ?? 0),
        'recebido_periodo' => (float)($dados['recebido_periodo'] ?? 0),
        'resultado_periodo' => (float)($dados['resultado_periodo'] ?? 0),
        'saldo_estimado' => (float)($dados['saldo_estimado'] ?? 0),
        'qtd_pagar_vencidas' => count($dados['vencidas_pagar'] ?? []),
        'qtd_receber_vencidas' => count($dados['vencidas_receber'] ?? []),
        'qtd_honorarios_vencidos' => count($dados['honorarios_vencidos'] ?? []),
    ];

    return "Elabore uma análise financeira gerencial em português do Brasil.\n\n"
        . "REGRAS OBRIGATÓRIAS:\n"
        . "1. Use somente os números e informações fornecidos.\n"
        . "2. Não invente lançamentos, clientes, valores, causas ou previsões.\n"
        . "3. Diferencie recebimentos, pagamentos, valores em aberto e saldo estimado.\n"
        . "4. Não trate transferências internas como receita ou despesa.\n"
        . "5. Informe riscos, prioridades, recomendações e limitações.\n"
        . "6. O relatório deve conter: resumo executivo, indicadores, pontos de atenção, prioridades e recomendações.\n\n"
        . "DADOS:\n"
        . json_encode($resumo, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}

$inicioPadrao = date('Y-m-01');
$fimPadrao = date('Y-m-t');

$dataInicio = trim((string)($_POST['data_inicio'] ?? $inicioPadrao));
$dataFim = trim((string)($_POST['data_fim'] ?? $fimPadrao));
$focoAnalise = trim((string)($_POST['foco_analise'] ?? 'Visão geral'));
$observacoesUsuario = trim((string)($_POST['observacoes_financeiras'] ?? ''));

$analisado = false;
$dadosFinanceiros = null;
$alertasFinanceiros = [];
$relatorioFinanceiro = '';
$modoResposta = '';
$erroIa = '';
$mensagemValidacao = '';

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['acao_financeiro_cij'] ?? '') === 'analisar_financeiro'
) {
    if (!cij_financeiro_periodo_valido($dataInicio, $dataFim)) {
        $mensagemValidacao = 'Informe um período válido para a análise.';
    } else {
        $analisado = true;
        $dadosFinanceiros = cij_financeiro_dados_periodo(
            $conn,
            $dataInicio,
            $dataFim
        );

        $alertasFinanceiros = cij_financeiro_alertas($dadosFinanceiros);

        $promptSistema = 'Você é o Analista Financeiro do ROJEX.AI Enterprise. Responda em português do Brasil. Use somente os dados fornecidos, não invente informações e deixe claro que a análise é gerencial.';
        $promptUsuario = cij_financeiro_prompt_ia(
            $dadosFinanceiros,
            $dataInicio,
            $dataFim,
            $focoAnalise,
            $observacoesUsuario
        );

        $retornoIa = cij_financeiro_chamar_ia($promptSistema, $promptUsuario);

        if (($retornoIa['ok'] ?? false) === true) {
            $relatorioFinanceiro = trim((string)($retornoIa['texto'] ?? ''));
            $modoResposta = 'Análise por IA';
        } else {
            $erroIa = trim((string)($retornoIa['erro'] ?? 'IA externa indisponível ou não configurada.'));
            $relatorioFinanceiro = cij_financeiro_relatorio_local(
                $dadosFinanceiros,
                $alertasFinanceiros,
                $dataInicio,
                $dataFim,
                $focoAnalise
            );
            $modoResposta = 'Análise local segura';
        }

        if (function_exists('sgl_registrar_log')) {
            sgl_registrar_log(
                $conn,
                'ANALISE_FINANCEIRA_CIJ',
                'cij_financeiro',
                '0',
                'Período: ' . $dataInicio . ' a ' . $dataFim
                    . ' - ' . $modoResposta
            );
        }
    }
}
?>

<div class="container-fluid">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
        <div>
            <h2 class="mb-1 fw-bold text-primary">
                <i class="bi bi-graph-up-arrow me-2"></i>IA Financeira
            </h2>
            <p class="text-muted mb-0">
                Análise de contas, recebimentos, pagamentos, inadimplência e indicadores financeiros.
            </p>
        </div>
        <a href="?mod=cij" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i>Voltar ao CIJ
        </a>
    </div>

    <div class="alert <?= cij_financeiro_ia_disponivel() ? 'alert-success' : 'alert-warning' ?> border-0 shadow-sm">
        <?php if (cij_financeiro_ia_disponivel()): ?>
            <strong>IA conectada:</strong> o relatório poderá utilizar a API configurada no ROJEX.AI.
        <?php else: ?>
            <strong>Modo análise local:</strong> o sistema utilizará apenas os dados internos disponíveis, sem inventar valores ou previsões.
        <?php endif; ?>
    </div>

    <?php if ($mensagemValidacao !== ''): ?>
        <div class="alert alert-danger border-0 shadow-sm">
            <i class="bi bi-exclamation-triangle me-1"></i>
            <?= cij_financeiro_h($mensagemValidacao) ?>
        </div>
    <?php endif; ?>

    <?php if ($erroIa !== '' && $analisado): ?>
        <div class="alert alert-info border-0 shadow-sm">
            <strong>Observação técnica:</strong>
            <?= cij_financeiro_h($erroIa) ?>
        </div>
    <?php endif; ?>

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-dark text-white">
            <i class="bi bi-sliders me-2"></i>Parâmetros da análise
        </div>
        <div class="card-body">
            <form method="POST" class="row g-3">
                <input type="hidden" name="acao_financeiro_cij" value="analisar_financeiro">

                <div class="col-md-3">
                    <label class="form-label fw-semibold">Data inicial</label>
                    <input
                        type="date"
                        name="data_inicio"
                        class="form-control"
                        value="<?= cij_financeiro_h($dataInicio) ?>"
                        required>
                </div>

                <div class="col-md-3">
                    <label class="form-label fw-semibold">Data final</label>
                    <input
                        type="date"
                        name="data_fim"
                        class="form-control"
                        value="<?= cij_financeiro_h($dataFim) ?>"
                        required>
                </div>

                <div class="col-md-3">
                    <label class="form-label fw-semibold">Foco da análise</label>
                    <select name="foco_analise" class="form-select">
                        <?php
                        $focos = [
                            'Visão geral',
                            'Fluxo de caixa',
                            'Contas a pagar',
                            'Contas a receber',
                            'Inadimplência',
                            'Honorários',
                            'Riscos financeiros',
                        ];
                        ?>
                        <?php foreach ($focos as $opcao): ?>
                            <option value="<?= cij_financeiro_h($opcao) ?>" <?= $focoAnalise === $opcao ? 'selected' : '' ?>>
                                <?= cij_financeiro_h($opcao) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-3 d-grid">
                    <label class="form-label fw-semibold d-none d-md-block">&nbsp;</label>
                    <button class="btn btn-primary">
                        <i class="bi bi-search me-1"></i>Analisar financeiro
                    </button>
                </div>

                <div class="col-12">
                    <label class="form-label fw-semibold">Observações para a análise</label>
                    <textarea
                        name="observacoes_financeiras"
                        class="form-control"
                        rows="3"
                        placeholder="Ex.: verificar pressão de caixa, inadimplência de clientes ou pagamentos próximos."><?= cij_financeiro_h($observacoesUsuario) ?></textarea>
                </div>
            </form>
        </div>
    </div>

    <?php if (!$analisado || !$dadosFinanceiros): ?>
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-dark text-white">
                <i class="bi bi-bar-chart-line me-2"></i>Resultado da análise
            </div>
            <div class="card-body text-center text-muted py-5">
                <i class="bi bi-graph-up fs-1 d-block mb-3 opacity-25"></i>
                <h5 class="fw-bold">Nenhuma análise realizada</h5>
                <p class="mb-0">Selecione o período e clique em analisar financeiro.</p>
            </div>
        </div>
    <?php else: ?>
        <div class="row g-3 mb-4">
            <div class="col-sm-6 col-xl-3">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body">
                        <small class="text-muted d-block">A PAGAR EM ABERTO</small>
                        <div class="fs-3 fw-bold text-danger">
                            <?= cij_financeiro_moeda($dadosFinanceiros['pagar_aberto']) ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-sm-6 col-xl-3">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body">
                        <small class="text-muted d-block">A RECEBER EM ABERTO</small>
                        <div class="fs-3 fw-bold text-success">
                            <?= cij_financeiro_moeda($dadosFinanceiros['receber_aberto']) ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-sm-6 col-xl-3">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body">
                        <small class="text-muted d-block">RESULTADO DO PERÍODO</small>
                        <?php $resultadoPositivo = $dadosFinanceiros['resultado_periodo'] >= 0; ?>
                        <div class="fs-3 fw-bold text-<?= $resultadoPositivo ? 'primary' : 'danger' ?>">
                            <?= cij_financeiro_moeda($dadosFinanceiros['resultado_periodo']) ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-sm-6 col-xl-3">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body">
                        <small class="text-muted d-block">SALDO ESTIMADO</small>
                        <?php $saldoPositivo = $dadosFinanceiros['saldo_estimado'] >= 0; ?>
                        <div class="fs-3 fw-bold text-<?= $saldoPositivo ? 'success' : 'danger' ?>">
                            <?= cij_financeiro_moeda($dadosFinanceiros['saldo_estimado']) ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-4 mb-4">
            <div class="col-lg-6">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-header bg-dark text-white">
                        <i class="bi bi-speedometer2 me-2"></i>Indicadores do período
                    </div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-sm-6">
                                <div class="border rounded p-3 h-100">
                                    <small class="text-muted d-block">Pagamentos realizados</small>
                                    <strong class="text-danger">
                                        <?= cij_financeiro_moeda($dadosFinanceiros['pago_periodo']) ?>
                                    </strong>
                                </div>
                            </div>
                            <div class="col-sm-6">
                                <div class="border rounded p-3 h-100">
                                    <small class="text-muted d-block">Recebimentos realizados</small>
                                    <strong class="text-success">
                                        <?= cij_financeiro_moeda($dadosFinanceiros['recebido_periodo']) ?>
                                    </strong>
                                </div>
                            </div>
                            <div class="col-sm-4">
                                <div class="border rounded p-3 h-100">
                                    <small class="text-muted d-block">Pagar vencidas</small>
                                    <strong><?= count($dadosFinanceiros['vencidas_pagar']) ?></strong>
                                </div>
                            </div>
                            <div class="col-sm-4">
                                <div class="border rounded p-3 h-100">
                                    <small class="text-muted d-block">Receber vencidas</small>
                                    <strong><?= count($dadosFinanceiros['vencidas_receber']) ?></strong>
                                </div>
                            </div>
                            <div class="col-sm-4">
                                <div class="border rounded p-3 h-100">
                                    <small class="text-muted d-block">Honorários vencidos</small>
                                    <strong><?= count($dadosFinanceiros['honorarios_vencidos']) ?></strong>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-6">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-header bg-dark text-white">
                        <i class="bi bi-exclamation-diamond me-2"></i>Pontos de atenção
                    </div>
                    <div class="card-body">
                        <ul class="mb-0">
                            <?php foreach ($alertasFinanceiros as $alerta): ?>
                                <li class="mb-2"><?= cij_financeiro_h($alerta) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </div>
            </div>
        </div>

        <?php if (!empty($dadosFinanceiros['vencidas_receber'])): ?>
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-danger text-white">
                    <i class="bi bi-exclamation-triangle me-2"></i>Contas a receber vencidas
                </div>
                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Cliente</th>
                                <th>Descrição</th>
                                <th>Vencimento</th>
                                <th>Status</th>
                                <th class="text-end">Saldo</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($dadosFinanceiros['vencidas_receber'] as $conta): ?>
                                <?php
                                $saldo = (float)($conta['valor_pendente'] ?? 0);
                                if ($saldo <= 0) {
                                    $saldo = (float)($conta['valor'] ?? 0);
                                }
                                ?>
                                <tr>
                                    <td><?= cij_financeiro_h($conta['id'] ?? '-') ?></td>
                                    <td><?= cij_financeiro_h($conta['cliente_nome'] ?? '-') ?></td>
                                    <td><?= cij_financeiro_h($conta['descricao'] ?? '-') ?></td>
                                    <td><?= cij_financeiro_h(cij_financeiro_data_br($conta['data_vencimento'] ?? '')) ?></td>
                                    <td>
                                        <span class="badge bg-danger">
                                            <?= cij_financeiro_h($conta['status'] ?? '-') ?>
                                        </span>
                                    </td>
                                    <td class="text-end fw-bold text-danger">
                                        <?= cij_financeiro_moeda($saldo) ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>

        <?php if (!empty($dadosFinanceiros['vencidas_pagar'])): ?>
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-warning text-dark">
                    <i class="bi bi-clock-history me-2"></i>Contas a pagar vencidas
                </div>
                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Fornecedor</th>
                                <th>Descrição</th>
                                <th>Vencimento</th>
                                <th>Status</th>
                                <th class="text-end">Saldo</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($dadosFinanceiros['vencidas_pagar'] as $conta): ?>
                                <?php
                                $saldo = (float)($conta['valor_pendente'] ?? 0);
                                if ($saldo <= 0) {
                                    $saldo = (float)($conta['valor'] ?? 0);
                                }
                                ?>
                                <tr>
                                    <td><?= cij_financeiro_h($conta['id'] ?? '-') ?></td>
                                    <td><?= cij_financeiro_h($conta['fornecedor'] ?? '-') ?></td>
                                    <td><?= cij_financeiro_h($conta['descricao'] ?? '-') ?></td>
                                    <td><?= cij_financeiro_h(cij_financeiro_data_br($conta['data_vencimento'] ?? '')) ?></td>
                                    <td>
                                        <span class="badge bg-warning text-dark">
                                            <?= cij_financeiro_h($conta['status'] ?? '-') ?>
                                        </span>
                                    </td>
                                    <td class="text-end fw-bold text-danger">
                                        <?= cij_financeiro_moeda($saldo) ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>

        <div class="card border-0 shadow-sm">
            <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center flex-wrap gap-2">
                <span><i class="bi bi-file-earmark-bar-graph me-2"></i>Relatório editável</span>
                <span class="badge bg-primary"><?= cij_financeiro_h($modoResposta) ?></span>
            </div>
            <div class="card-body">
                <div class="d-flex flex-wrap gap-2 mb-3">
                    <button type="button" class="btn btn-outline-primary btn-sm" id="btnCopiarFinanceiro">
                        <i class="bi bi-clipboard me-1"></i>Copiar
                    </button>
                    <button type="button" class="btn btn-outline-success btn-sm" id="btnWordFinanceiro">
                        <i class="bi bi-file-earmark-word me-1"></i>Baixar Word
                    </button>
                    <button type="button" class="btn btn-outline-secondary btn-sm" id="btnImprimirFinanceiro">
                        <i class="bi bi-printer me-1"></i>Imprimir / PDF
                    </button>
                    <a href="?mod=financeiro" class="btn btn-outline-dark btn-sm">
                        <i class="bi bi-box-arrow-up-right me-1"></i>Abrir Financeiro
                    </a>
                    <a href="?mod=cij&ferramenta=financeiro" class="btn btn-outline-dark btn-sm">
                        <i class="bi bi-plus-circle me-1"></i>Nova análise
                    </a>
                </div>

                <textarea id="relatorioFinanceiroCij" class="form-control" rows="24"><?= cij_financeiro_h($relatorioFinanceiro) ?></textarea>

                <div class="alert alert-warning mt-3 mb-0">
                    <i class="bi bi-exclamation-triangle me-1"></i>
                    A análise utiliza os registros internos e não substitui conferência contábil, fiscal, bancária ou administrativa.
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php if ($analisado && $dadosFinanceiros): ?>
<script>
(function(){
    const campo = document.getElementById('relatorioFinanceiroCij');
    const btnCopiar = document.getElementById('btnCopiarFinanceiro');
    const btnWord = document.getElementById('btnWordFinanceiro');
    const btnImprimir = document.getElementById('btnImprimirFinanceiro');

    if (!campo) return;

    function escaparHtml(texto) {
        const div = document.createElement('div');
        div.textContent = texto;
        return div.innerHTML;
    }

    if (btnCopiar) {
        btnCopiar.addEventListener('click', async function(){
            try {
                await navigator.clipboard.writeText(campo.value);
                const original = btnCopiar.innerHTML;
                btnCopiar.innerHTML = '<i class="bi bi-check-lg me-1"></i>Copiado';
                setTimeout(function(){ btnCopiar.innerHTML = original; }, 1800);
            } catch (e) {
                campo.select();
                document.execCommand('copy');
            }
        });
    }

    if (btnWord) {
        btnWord.addEventListener('click', function(){
            const conteudo = escaparHtml(campo.value).replace(/\n/g, '<br>');
            const html = `<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>
@page { size: A4; margin: 3cm 2cm 2cm 3cm; }
body {
    font-family: "Times New Roman", serif;
    font-size: 12pt;
    line-height: 1.5;
    text-align: justify;
}
</style>
</head>
<body><div>${conteudo}</div></body>
</html>`;

            const blob = new Blob(['\ufeff', html], {type: 'application/msword'});
            const url = URL.createObjectURL(blob);
            const link = document.createElement('a');
            link.href = url;
            link.download = 'analise_financeira_rojex.doc';
            document.body.appendChild(link);
            link.click();
            link.remove();
            URL.revokeObjectURL(url);
        });
    }

    if (btnImprimir) {
        btnImprimir.addEventListener('click', function(){
            const janela = window.open('', '_blank', 'width=1000,height=800');

            if (!janela) {
                alert('O navegador bloqueou a janela de impressão. Autorize pop-ups para este sistema.');
                return;
            }

            const conteudo = escaparHtml(campo.value).replace(/\n/g, '<br>');

            janela.document.open();
            janela.document.write(`<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Análise Financeira — ROJEX.AI</title>
<style>
@page { size: A4 portrait; margin: 3cm 2cm 2cm 3cm; }
body {
    font-family: "Times New Roman", serif;
    font-size: 12pt;
    line-height: 1.5;
    text-align: justify;
    color: #000;
    margin: 0;
}
.rodape {
    margin-top: 24pt;
    padding-top: 8pt;
    border-top: 1px solid #999;
    text-align: center;
    font-size: 9pt;
    color: #555;
}
</style>
</head>
<body>
<div>${conteudo}</div>
<div class="rodape">Relatório gerencial de apoio. Conferência contábil, fiscal e bancária obrigatória.</div>
<script>
window.onload = function(){ window.print(); };
<\/script>
</body>
</html>`);
            janela.document.close();
        });
    }
})();
</script>
<?php endif; ?>
