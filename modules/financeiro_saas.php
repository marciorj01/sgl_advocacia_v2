<?php
declare(strict_types=1);

/**
 * ROJEX.AI — Dashboard Financeiro Executivo MASTER SaaS
 * CHAT 61 — RC2.7
 *
 * Módulo exclusivamente de leitura.
 * Não contém SQL e não duplica regras de negócio.
 */

if (!function_exists('rojexEhMasterSaas') || !rojexEhMasterSaas()) {
    http_response_code(403);
    echo '<div class="alert alert-danger shadow-sm"><strong>Acesso negado:</strong> módulo exclusivo do MASTER.</div>';
    return;
}

require_once __DIR__ . '/../services/financeiro_corporativo/IndicadoresSaasService.php';
require_once __DIR__ . '/../services/financeiro_corporativo/FluxoCaixaCorporativoService.php';

if (!function_exists('financeiroSaasDataValida')) {
    function financeiroSaasDataValida(?string $data): bool
    {
        if ($data === null || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $data)) {
            return false;
        }

        $objeto = DateTimeImmutable::createFromFormat('!Y-m-d', $data);
        return $objeto instanceof DateTimeImmutable && $objeto->format('Y-m-d') === $data;
    }
}

if (!function_exists('financeiroSaasMoeda')) {
    function financeiroSaasMoeda(mixed $valor): string
    {
        return 'R$ ' . number_format((float)$valor, 2, ',', '.');
    }
}

if (!function_exists('financeiroSaasNumero')) {
    function financeiroSaasNumero(mixed $valor): float
    {
        return is_numeric($valor) ? (float)$valor : 0.0;
    }
}

if (!function_exists('financeiroSaasInteiro')) {
    function financeiroSaasInteiro(mixed $valor): int
    {
        return is_numeric($valor) ? (int)$valor : 0;
    }
}

if (!function_exists('financeiroSaasDados')) {
    function financeiroSaasDados(array $retorno, array $padrao = []): array
    {
        return !empty($retorno['sucesso']) && isset($retorno['dados']) && is_array($retorno['dados'])
            ? $retorno['dados']
            : $padrao;
    }
}

if (!function_exists('financeiroSaasItens')) {
    function financeiroSaasItens(array $retorno): array
    {
        $dados = financeiroSaasDados($retorno);
        return isset($dados['itens']) && is_array($dados['itens']) ? $dados['itens'] : [];
    }
}

if (!function_exists('financeiroSaasJson')) {
    function financeiroSaasJson(mixed $valor): string
    {
        return (string)json_encode(
            $valor,
            JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
            | JSON_HEX_TAG
            | JSON_HEX_AMP
            | JSON_HEX_APOS
            | JSON_HEX_QUOT
        );
    }
}

if (!function_exists('financeiroSaasMesLabel')) {
    function financeiroSaasMesLabel(string $competencia): string
    {
        $data = DateTimeImmutable::createFromFormat('!Y-m', $competencia);
        return $data instanceof DateTimeImmutable ? $data->format('m/Y') : $competencia;
    }
}

$hoje = new DateTimeImmutable('today');
$inicioPadrao = $hoje->modify('-11 months')->modify('first day of this month')->format('Y-m-d');
$fimPadrao = $hoje->modify('last day of this month')->format('Y-m-d');

$inicio = isset($_GET['inicio']) ? trim((string)$_GET['inicio']) : $inicioPadrao;
$fim = isset($_GET['fim']) ? trim((string)$_GET['fim']) : $fimPadrao;

$avisoFiltro = '';
if (!financeiroSaasDataValida($inicio) || !financeiroSaasDataValida($fim)) {
    $inicio = $inicioPadrao;
    $fim = $fimPadrao;
    $avisoFiltro = 'O período informado era inválido e foi restaurado para os últimos 12 meses.';
} elseif ($fim < $inicio) {
    $inicio = $inicioPadrao;
    $fim = $fimPadrao;
    $avisoFiltro = 'A data final não pode ser anterior à inicial. O período padrão foi restaurado.';
}

$limiteHistorico = (new DateTimeImmutable($inicio))->modify('+36 months');
if ($limiteHistorico < new DateTimeImmutable($fim)) {
    $inicio = $inicioPadrao;
    $fim = $fimPadrao;
    $avisoFiltro = 'O dashboard aceita no máximo 36 meses por consulta. O período padrão foi restaurado.';
}

$falhas = [];
$retornos = [];

try {
    $indicadoresService = IndicadoresSaasService::criarComConexaoOficial();
    $fluxoService = FluxoCaixaCorporativoService::criarComConexaoOficial();

    $retornos['resumo'] = $indicadoresService->resumo($inicio, $fim);
    $retornos['receita_mensal'] = $indicadoresService->receitaMensal($inicio, $fim);
    $retornos['mrr_arr'] = $indicadoresService->evolucaoMrrArr($inicio, $fim);
    $retornos['planos'] = $indicadoresService->receitaPorPlano();
    $retornos['escritorios'] = $indicadoresService->receitaPorEscritorio($inicio, $fim);
    $retornos['periodicidades'] = $indicadoresService->receitaPorPeriodicidade();
    $retornos['status_assinaturas'] = $indicadoresService->assinaturasPorStatus();
    $retornos['inadimplencia'] = $indicadoresService->inadimplenciaMensal($inicio, $fim);
    $retornos['churn'] = $indicadoresService->churn($inicio, $fim);
    $retornos['conversao'] = $indicadoresService->conversaoTrial($inicio, $fim);
    $retornos['fluxo'] = $fluxoService->fluxo($inicio, $fim);
} catch (Throwable $e) {
    error_log('[ROJEX FINANCEIRO SAAS] ' . $e->getMessage());
    $falhas[] = 'Não foi possível inicializar todos os serviços financeiros.';
}

foreach ($retornos as $nome => $retorno) {
    if (empty($retorno['sucesso'])) {
        $falhas[] = (string)($retorno['mensagem'] ?? ('Falha ao carregar ' . $nome . '.'));
    }
}

$resumo = financeiroSaasDados($retornos['resumo'] ?? []);
$assinaturas = isset($resumo['assinaturas']) && is_array($resumo['assinaturas'])
    ? $resumo['assinaturas']
    : [];
$cobrancas = isset($resumo['cobrancas']) && is_array($resumo['cobrancas'])
    ? $resumo['cobrancas']
    : [];
$inadimplenciaResumo = isset($resumo['inadimplencia']) && is_array($resumo['inadimplencia'])
    ? $resumo['inadimplencia']
    : [];
$churn = financeiroSaasDados($retornos['churn'] ?? []);
$conversao = financeiroSaasDados($retornos['conversao'] ?? []);

$receitaMensal = financeiroSaasItens($retornos['receita_mensal'] ?? []);
$mrrArr = financeiroSaasItens($retornos['mrr_arr'] ?? []);
$planos = financeiroSaasItens($retornos['planos'] ?? []);
$escritorios = financeiroSaasItens($retornos['escritorios'] ?? []);
$periodicidades = financeiroSaasItens($retornos['periodicidades'] ?? []);
$statusAssinaturas = financeiroSaasItens($retornos['status_assinaturas'] ?? []);
$inadimplenciaHistorica = financeiroSaasItens($retornos['inadimplencia'] ?? []);
$fluxoItens = financeiroSaasItens($retornos['fluxo'] ?? []);

$fluxoMensal = [];
foreach ($fluxoItens as $item) {
    $data = trim((string)($item['data'] ?? ''));
    if ($data === '') {
        continue;
    }

    $competencia = substr($data, 0, 7);
    if (!isset($fluxoMensal[$competencia])) {
        $fluxoMensal[$competencia] = [
            'competencia' => $competencia,
            'entradas_previstas' => 0.0,
            'saidas_previstas' => 0.0,
            'entradas_realizadas' => 0.0,
            'saidas_realizadas' => 0.0,
        ];
    }

    $natureza = (string)($item['natureza'] ?? '');
    $previsto = financeiroSaasNumero($item['previsto'] ?? 0);
    $realizado = financeiroSaasNumero($item['realizado'] ?? 0);

    if ($natureza === 'entrada') {
        $fluxoMensal[$competencia]['entradas_previstas'] += $previsto;
        $fluxoMensal[$competencia]['entradas_realizadas'] += $realizado;
    } elseif ($natureza === 'saida') {
        $fluxoMensal[$competencia]['saidas_previstas'] += $previsto;
        $fluxoMensal[$competencia]['saidas_realizadas'] += $realizado;
    }
}
ksort($fluxoMensal);
$fluxoMensal = array_values($fluxoMensal);

$cards = [
    ['titulo' => 'MRR', 'valor' => financeiroSaasMoeda($resumo['mrr'] ?? 0), 'icone' => 'bi-arrow-repeat', 'classe' => 'primary'],
    ['titulo' => 'ARR', 'valor' => financeiroSaasMoeda($resumo['arr'] ?? 0), 'icone' => 'bi-calendar2-check', 'classe' => 'primary'],
    ['titulo' => 'Receita recebida', 'valor' => financeiroSaasMoeda($resumo['receita_recebida'] ?? 0), 'icone' => 'bi-cash-stack', 'classe' => 'success'],
    ['titulo' => 'Receita prevista', 'valor' => financeiroSaasMoeda($resumo['receita_prevista'] ?? 0), 'icone' => 'bi-graph-up', 'classe' => 'info'],
    ['titulo' => 'Contas a receber', 'valor' => financeiroSaasMoeda($cobrancas['valor_aberto'] ?? 0), 'icone' => 'bi-wallet2', 'classe' => 'warning'],
    ['titulo' => 'Cobranças em aberto', 'valor' => number_format(financeiroSaasInteiro($cobrancas['abertas'] ?? 0), 0, ',', '.'), 'icone' => 'bi-receipt', 'classe' => 'warning'],
    ['titulo' => 'Cobranças vencidas', 'valor' => number_format(financeiroSaasInteiro($cobrancas['vencidas'] ?? 0), 0, ',', '.'), 'icone' => 'bi-exclamation-triangle', 'classe' => 'danger'],
    ['titulo' => 'Créditos disponíveis', 'valor' => financeiroSaasMoeda($resumo['creditos_disponiveis'] ?? 0), 'icone' => 'bi-gift', 'classe' => 'success'],
    ['titulo' => 'Assinaturas ativas', 'valor' => number_format(financeiroSaasInteiro($assinaturas['ativas'] ?? 0), 0, ',', '.'), 'icone' => 'bi-patch-check', 'classe' => 'success'],
    ['titulo' => 'Assinaturas trial', 'valor' => number_format(financeiroSaasInteiro($assinaturas['trials'] ?? 0), 0, ',', '.'), 'icone' => 'bi-hourglass-split', 'classe' => 'info'],
    ['titulo' => 'Conversão trial', 'valor' => number_format(financeiroSaasNumero($conversao['percentual'] ?? 0), 2, ',', '.') . '%', 'icone' => 'bi-funnel', 'classe' => 'primary'],
    ['titulo' => 'Churn', 'valor' => number_format(financeiroSaasNumero($churn['percentual'] ?? 0), 2, ',', '.') . '%', 'icone' => 'bi-person-dash', 'classe' => 'danger'],
    ['titulo' => 'Ticket médio', 'valor' => financeiroSaasMoeda($resumo['ticket_medio'] ?? 0), 'icone' => 'bi-ticket-perforated', 'classe' => 'primary'],
    ['titulo' => 'Inadimplência', 'valor' => financeiroSaasMoeda($inadimplenciaResumo['valor'] ?? 0), 'icone' => 'bi-shield-exclamation', 'classe' => 'danger'],
];

$chartData = [
    'receitaMensal' => [
        'labels' => array_map(fn(array $i): string => financeiroSaasMesLabel((string)($i['competencia'] ?? '')), $receitaMensal),
        'valores' => array_map(fn(array $i): float => financeiroSaasNumero($i['receita'] ?? 0), $receitaMensal),
    ],
    'mrrArr' => [
        'labels' => array_map(fn(array $i): string => financeiroSaasMesLabel((string)($i['competencia'] ?? '')), $mrrArr),
        'mrr' => array_map(fn(array $i): float => financeiroSaasNumero($i['mrr'] ?? 0), $mrrArr),
        'arr' => array_map(fn(array $i): float => financeiroSaasNumero($i['arr'] ?? 0), $mrrArr),
    ],
    'fluxo' => [
        'labels' => array_map(fn(array $i): string => financeiroSaasMesLabel((string)($i['competencia'] ?? '')), $fluxoMensal),
        'entradasPrevistas' => array_map(fn(array $i): float => financeiroSaasNumero($i['entradas_previstas'] ?? 0), $fluxoMensal),
        'saidasPrevistas' => array_map(fn(array $i): float => financeiroSaasNumero($i['saidas_previstas'] ?? 0), $fluxoMensal),
        'entradasRealizadas' => array_map(fn(array $i): float => financeiroSaasNumero($i['entradas_realizadas'] ?? 0), $fluxoMensal),
        'saidasRealizadas' => array_map(fn(array $i): float => financeiroSaasNumero($i['saidas_realizadas'] ?? 0), $fluxoMensal),
    ],
    'planos' => [
        'labels' => array_map(fn(array $i): string => (string)($i['plano'] ?? 'Sem plano'), $planos),
        'valores' => array_map(fn(array $i): float => financeiroSaasNumero($i['valor_contratado'] ?? 0), $planos),
    ],
    'periodicidades' => [
        'labels' => array_map(fn(array $i): string => ucfirst((string)($i['periodicidade'] ?? 'Não informada')), $periodicidades),
        'valores' => array_map(fn(array $i): float => financeiroSaasNumero($i['mrr_equivalente'] ?? 0), $periodicidades),
    ],
    'statusAssinaturas' => [
        'labels' => array_map(fn(array $i): string => ucfirst((string)($i['status'] ?? 'Não informado')), $statusAssinaturas),
        'valores' => array_map(fn(array $i): int => financeiroSaasInteiro($i['quantidade'] ?? 0), $statusAssinaturas),
    ],
    'inadimplencia' => [
        'labels' => array_map(fn(array $i): string => financeiroSaasMesLabel((string)($i['competencia'] ?? '')), $inadimplenciaHistorica),
        'valores' => array_map(fn(array $i): float => financeiroSaasNumero($i['valor'] ?? 0), $inadimplenciaHistorica),
        'quantidades' => array_map(fn(array $i): int => financeiroSaasInteiro($i['quantidade'] ?? 0), $inadimplenciaHistorica),
    ],
];
?>


<section aria-labelledby="financeiroSaasTitulo">
    <div class="fs-dashboard-header mb-4">
        <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3">
            <div>
                <div class="small text-uppercase fw-bold opacity-75 mb-1">ROJEX.AI Plataforma SaaS</div>
                <h1 class="h3 mb-1" id="financeiroSaasTitulo">
                    <i class="bi bi-graph-up-arrow me-2" aria-hidden="true"></i>
                    Dashboard Financeiro Executivo
                </h1>
                <p class="mb-0 opacity-75">
                    Indicadores corporativos consolidados da plataforma, sem carregar dados operacionais de tenant.
                </p>
            </div>
            <div class="text-lg-end">
                <div class="small opacity-75">Período analisado</div>
                <strong><?= htmlspecialchars(date('d/m/Y', strtotime($inicio)), ENT_QUOTES, 'UTF-8') ?></strong>
                a
                <strong><?= htmlspecialchars(date('d/m/Y', strtotime($fim)), ENT_QUOTES, 'UTF-8') ?></strong>
            </div>
        </div>
    </div>

    <?php if ($avisoFiltro !== ''): ?>
        <div class="alert alert-warning shadow-sm">
            <i class="bi bi-exclamation-triangle me-2" aria-hidden="true"></i>
            <?= htmlspecialchars($avisoFiltro, ENT_QUOTES, 'UTF-8') ?>
        </div>
    <?php endif; ?>

    <?php if ($falhas): ?>
        <div class="alert alert-danger shadow-sm">
            <strong>Alguns dados não puderam ser carregados.</strong>
            <ul class="mb-0 mt-2">
                <?php foreach (array_unique($falhas) as $falha): ?>
                    <li><?= htmlspecialchars($falha, ENT_QUOTES, 'UTF-8') ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <div class="card fs-filter-card mb-4">
        <div class="card-body">
            <form method="get" class="row g-3 align-items-end">
                <input type="hidden" name="mod" value="financeiro_saas">

                <div class="col-12 col-md-4">
                    <label for="fsInicio" class="form-label fw-semibold">Data inicial</label>
                    <input
                        type="date"
                        class="form-control"
                        id="fsInicio"
                        name="inicio"
                        value="<?= htmlspecialchars($inicio, ENT_QUOTES, 'UTF-8') ?>"
                        required
                    >
                </div>

                <div class="col-12 col-md-4">
                    <label for="fsFim" class="form-label fw-semibold">Data final</label>
                    <input
                        type="date"
                        class="form-control"
                        id="fsFim"
                        name="fim"
                        value="<?= htmlspecialchars($fim, ENT_QUOTES, 'UTF-8') ?>"
                        required
                    >
                </div>

                <div class="col-12 col-md-4 fs-filter-actions">
                    <div class="d-flex flex-column flex-sm-row gap-2">
                        <button type="submit" class="btn btn-primary flex-fill">
                            <i class="bi bi-funnel me-1" aria-hidden="true"></i> Aplicar período
                        </button>
                        <a href="?mod=financeiro_saas" class="btn btn-outline-secondary">
                            Limpar
                        </a>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <?php foreach ($cards as $card): ?>
            <div class="col-12 col-sm-6 col-xl-3">
                <article class="card fs-kpi-card fs-<?= htmlspecialchars($card['classe'], ENT_QUOTES, 'UTF-8') ?>">
                    <div class="card-body">
                        <div class="d-flex align-items-start justify-content-between gap-3">
                            <div class="min-w-0">
                                <div class="fs-kpi-title mb-2">
                                    <?= htmlspecialchars($card['titulo'], ENT_QUOTES, 'UTF-8') ?>
                                </div>
                                <div class="fs-kpi-value">
                                    <?= htmlspecialchars($card['valor'], ENT_QUOTES, 'UTF-8') ?>
                                </div>
                            </div>
                            <span class="fs-kpi-icon text-<?= htmlspecialchars($card['classe'], ENT_QUOTES, 'UTF-8') ?>">
                                <i class="bi <?= htmlspecialchars($card['icone'], ENT_QUOTES, 'UTF-8') ?>" aria-hidden="true"></i>
                            </span>
                        </div>
                    </div>
                </article>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="row g-4">
        <?php
        $graficos = [
            ['id' => 'fsReceitaMensal', 'titulo' => 'Receita mensal', 'icone' => 'bi-bar-chart-line'],
            ['id' => 'fsMrrArr', 'titulo' => 'Evolução do MRR e ARR', 'icone' => 'bi-graph-up'],
            ['id' => 'fsFluxoCaixa', 'titulo' => 'Fluxo de caixa', 'icone' => 'bi-arrow-left-right'],
            ['id' => 'fsReceitaPlano', 'titulo' => 'Receita por plano', 'icone' => 'bi-box-seam'],
            ['id' => 'fsPeriodicidade', 'titulo' => 'Receita por periodicidade', 'icone' => 'bi-calendar3'],
            ['id' => 'fsStatusAssinaturas', 'titulo' => 'Assinaturas por status', 'icone' => 'bi-pie-chart'],
            ['id' => 'fsInadimplencia', 'titulo' => 'Inadimplência', 'icone' => 'bi-exclamation-octagon'],
        ];
        ?>
        <?php foreach ($graficos as $grafico): ?>
            <div class="col-12 col-xl-6">
                <article class="card fs-chart-card h-100">
                    <div class="card-header py-3">
                        <i class="bi <?= htmlspecialchars($grafico['icone'], ENT_QUOTES, 'UTF-8') ?> me-2" aria-hidden="true"></i>
                        <?= htmlspecialchars($grafico['titulo'], ENT_QUOTES, 'UTF-8') ?>
                    </div>
                    <div class="card-body">
                        <div class="fs-chart-wrap">
                            <canvas id="<?= htmlspecialchars($grafico['id'], ENT_QUOTES, 'UTF-8') ?>"></canvas>
                        </div>
                    </div>
                </article>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="row g-4 mt-1">
        <div class="col-12 col-xl-6">
            <article class="card fs-table-card h-100">
                <div class="card-header py-3">
                    <i class="bi bi-building me-2" aria-hidden="true"></i>
                    Receita por escritório
                </div>
                <div class="card-body p-0">
                    <?php if (!$escritorios): ?>
                        <div class="fs-empty">Nenhuma receita por escritório encontrada no período.</div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>Escritório</th>
                                        <th class="text-end">Receita</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach (array_slice($escritorios, 0, 15) as $item): ?>
                                        <tr>
                                            <td><?= htmlspecialchars((string)($item['escritorio'] ?? 'Não identificado'), ENT_QUOTES, 'UTF-8') ?></td>
                                            <td class="text-end fw-semibold"><?= htmlspecialchars(financeiroSaasMoeda($item['receita'] ?? 0), ENT_QUOTES, 'UTF-8') ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </article>
        </div>

        <div class="col-12 col-xl-6">
            <article class="card fs-table-card h-100">
                <div class="card-header py-3">
                    <i class="bi bi-clipboard-data me-2" aria-hidden="true"></i>
                    Resumo de risco financeiro
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-12 col-sm-6">
                            <div class="border rounded-3 p-3 h-100">
                                <div class="text-secondary small">Valor vencido</div>
                                <div class="h5 mb-0 text-danger"><?= htmlspecialchars(financeiroSaasMoeda($cobrancas['valor_vencido'] ?? 0), ENT_QUOTES, 'UTF-8') ?></div>
                            </div>
                        </div>
                        <div class="col-12 col-sm-6">
                            <div class="border rounded-3 p-3 h-100">
                                <div class="text-secondary small">Cobranças inadimplentes</div>
                                <div class="h5 mb-0 text-danger"><?= number_format(financeiroSaasInteiro($inadimplenciaResumo['quantidade'] ?? 0), 0, ',', '.') ?></div>
                            </div>
                        </div>
                        <div class="col-12 col-sm-6">
                            <div class="border rounded-3 p-3 h-100">
                                <div class="text-secondary small">Cancelamentos no período</div>
                                <div class="h5 mb-0"><?= number_format(financeiroSaasInteiro($churn['cancelamentos'] ?? 0), 0, ',', '.') ?></div>
                            </div>
                        </div>
                        <div class="col-12 col-sm-6">
                            <div class="border rounded-3 p-3 h-100">
                                <div class="text-secondary small">Trials convertidos</div>
                                <div class="h5 mb-0"><?= number_format(financeiroSaasInteiro($conversao['convertidos'] ?? 0), 0, ',', '.') ?></div>
                            </div>
                        </div>
                    </div>
                </div>
            </article>
        </div>
    </div>
</section>

<script type="application/json" id="financeiroSaasChartData">
<?= financeiroSaasJson($chartData) ?>
</script>
