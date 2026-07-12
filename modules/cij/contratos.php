<?php
/**
 * modules/cij/contratos.php
 * Análise de Contratos — ROJEX.AI Enterprise
 * Sprint 4.1.4 — Etapa 4
 *
 * Objetivos:
 * - Analisar contratos em modo local seguro.
 * - Identificar estrutura, lacunas e riscos contratuais.
 * - Usar IA externa quando configurada em config/ia.php.
 * - Não alterar banco de dados.
 */

if (!isset($conn) || !($conn instanceof mysqli)) {
    $conn = conectar();
}

$arquivoConfigIa = __DIR__ . '/../../config/ia.php';
if (is_file($arquivoConfigIa)) {
    require_once $arquivoConfigIa;
}

function cij_contratos_h($valor): string
{
    return htmlspecialchars((string)($valor ?? ''), ENT_QUOTES, 'UTF-8');
}

function cij_contratos_ia_disponivel(): bool
{
    return function_exists('sgl_ia_disponivel') && sgl_ia_disponivel();
}

function cij_contratos_chamar_ia(string $promptSistema, string $promptUsuario): array
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

function cij_contratos_normalizar(string $texto): string
{
    $texto = str_replace(["\r\n", "\r"], "\n", trim($texto));
    $texto = preg_replace('/[ \t]+/u', ' ', $texto);
    $texto = preg_replace("/\n{3,}/u", "\n\n", (string)$texto);
    return trim((string)$texto);
}

function cij_contratos_contem(string $texto, array $termos): bool
{
    $texto = mb_strtolower($texto, 'UTF-8');

    foreach ($termos as $termo) {
        if (str_contains($texto, mb_strtolower($termo, 'UTF-8'))) {
            return true;
        }
    }

    return false;
}

function cij_contratos_checklist(string $tipoContrato): array
{
    $tipo = mb_strtolower(trim($tipoContrato), 'UTF-8');

    $base = [
        'Qualificação das partes' => ['contratante', 'contratado', 'locador', 'locatário', 'comprador', 'vendedor', 'cpf', 'cnpj'],
        'Objeto' => ['objeto do contrato', 'do objeto', 'objeto'],
        'Obrigações das partes' => ['obrigações', 'obrigacoes', 'deveres', 'responsabilidades'],
        'Valor' => ['valor', 'preço', 'preco', 'remuneração', 'remuneracao'],
        'Forma de pagamento' => ['pagamento', 'parcela', 'vencimento', 'pix', 'transferência', 'transferencia'],
        'Prazo/Vigência' => ['prazo', 'vigência', 'vigencia'],
        'Rescisão' => ['rescisão', 'rescisao', 'encerramento'],
        'Multa/Penalidade' => ['multa', 'penalidade'],
        'Foro' => ['foro', 'comarca'],
        'Assinaturas' => ['assinatura', 'assinam', 'testemunhas'],
    ];

    $especificos = [
        'locação comercial' => [
            'Imóvel/Endereço' => ['imóvel', 'imovel', 'endereço', 'endereco'],
            'Aluguel' => ['aluguel', 'locativo'],
            'Reajuste' => ['reajuste', 'igp-m', 'ipca', 'índice', 'indice'],
            'Garantia locatícia' => ['caução', 'caucao', 'fiador', 'seguro-fiança', 'seguro fianca'],
            'Encargos' => ['iptu', 'condomínio', 'condominio', 'energia', 'água', 'agua'],
            'Benfeitorias' => ['benfeitoria', 'benfeitorias'],
        ],
        'prestação de serviços' => [
            'Escopo dos serviços' => ['serviços', 'servicos', 'escopo', 'atividades'],
            'Entregas/Prazos' => ['entrega', 'cronograma', 'prazo'],
            'Responsabilidade técnica' => ['responsabilidade', 'responsável', 'responsavel'],
            'Confidencialidade' => ['confidencialidade', 'sigilo'],
            'Propriedade intelectual' => ['propriedade intelectual', 'direitos autorais'],
        ],
        'compra e venda' => [
            'Bem negociado' => ['bem', 'produto', 'imóvel', 'imovel', 'veículo', 'veiculo'],
            'Preço' => ['preço', 'preco', 'valor'],
            'Entrega/Posse' => ['entrega', 'posse', 'tradição', 'tradicao'],
            'Garantia' => ['garantia', 'vício', 'vicio'],
            'Transferência de propriedade' => ['transferência', 'transferencia', 'propriedade'],
        ],
        'honorários advocatícios' => [
            'Serviços jurídicos' => ['serviços jurídicos', 'servicos juridicos', 'patrocínio', 'patrocinio'],
            'Honorários contratuais' => ['honorários', 'honorarios'],
            'Honorários de êxito' => ['êxito', 'exito', 'percentual'],
            'Custas e despesas' => ['custas', 'despesas', 'diligências', 'diligencias'],
            'Revogação/Renúncia' => ['revogação', 'revogacao', 'renúncia', 'renuncia'],
        ],
        'confissão de dívida' => [
            'Origem da dívida' => ['origem da dívida', 'origem da divida', 'débito', 'debito'],
            'Valor confessado' => ['valor', 'quantia'],
            'Parcelamento' => ['parcela', 'parcelamento'],
            'Vencimento antecipado' => ['vencimento antecipado'],
            'Garantia' => ['garantia', 'aval', 'fiador'],
        ],
    ];

    return array_merge($base, $especificos[$tipo] ?? []);
}

function cij_contratos_analisar(string $texto, string $tipoContrato): array
{
    $normalizado = cij_contratos_normalizar($texto);
    $palavras = preg_split('/\s+/u', $normalizado, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $totalPalavras = count($palavras);
    $totalCaracteres = mb_strlen($normalizado, 'UTF-8');

    $checklistDef = cij_contratos_checklist($tipoContrato);
    $checklist = [];
    $presentes = 0;

    foreach ($checklistDef as $nome => $termos) {
        $ok = cij_contratos_contem($normalizado, $termos);
        $checklist[] = ['nome' => $nome, 'ok' => $ok];
        if ($ok) {
            $presentes++;
        }
    }

    $estrutura = (int)round(($presentes / max(count($checklist), 1)) * 100);

    $riscos = [];
    $nivelRisco = 0;

    $regrasRisco = [
        ['Ausência de cláusula de rescisão.', ['rescisão', 'rescisao'], 3],
        ['Ausência de definição de foro.', ['foro', 'comarca'], 2],
        ['Ausência de prazo ou vigência.', ['prazo', 'vigência', 'vigencia'], 3],
        ['Ausência de cláusula de multa ou penalidade.', ['multa', 'penalidade'], 2],
        ['Ausência de forma de pagamento claramente definida.', ['pagamento', 'parcela', 'vencimento'], 3],
        ['Ausência de assinatura ou testemunhas.', ['assinatura', 'assinam', 'testemunhas'], 3],
        ['Ausência de cláusula de responsabilidades/obrigações.', ['obrigações', 'obrigacoes', 'responsabilidades'], 3],
    ];

    foreach ($regrasRisco as [$mensagem, $termos, $peso]) {
        if (!cij_contratos_contem($normalizado, $termos)) {
            $riscos[] = $mensagem;
            $nivelRisco += $peso;
        }
    }

    $tipo = mb_strtolower($tipoContrato, 'UTF-8');

    if ($tipo === 'locação comercial') {
        if (!cij_contratos_contem($normalizado, ['reajuste', 'igp-m', 'ipca', 'índice', 'indice'])) {
            $riscos[] = 'Locação: não foi localizado critério de reajuste.';
            $nivelRisco += 3;
        }
        if (!cij_contratos_contem($normalizado, ['caução', 'caucao', 'fiador', 'seguro-fiança', 'seguro fianca'])) {
            $riscos[] = 'Locação: não foi localizada garantia locatícia.';
            $nivelRisco += 2;
        }
        if (!cij_contratos_contem($normalizado, ['iptu', 'condomínio', 'condominio'])) {
            $riscos[] = 'Locação: encargos como IPTU ou condomínio não foram claramente distribuídos.';
            $nivelRisco += 2;
        }
    }

    if ($tipo === 'prestação de serviços') {
        if (!cij_contratos_contem($normalizado, ['confidencialidade', 'sigilo'])) {
            $riscos[] = 'Prestação de serviços: não foi localizada cláusula de confidencialidade.';
            $nivelRisco += 2;
        }
        if (!cij_contratos_contem($normalizado, ['propriedade intelectual', 'direitos autorais'])) {
            $riscos[] = 'Prestação de serviços: não foi localizada regra sobre propriedade intelectual.';
            $nivelRisco += 2;
        }
    }

    $percentualRisco = min(100, $nivelRisco * 7);
    $qualidade = max(0, min(100, (int)round(($estrutura * 0.75) + ((100 - $percentualRisco) * 0.25))));

    if ($percentualRisco <= 20) {
        $classificacaoRisco = 'Baixo';
        $tipoBadge = 'success';
    } elseif ($percentualRisco <= 45) {
        $classificacaoRisco = 'Moderado';
        $tipoBadge = 'warning';
    } else {
        $classificacaoRisco = 'Alto';
        $tipoBadge = 'danger';
    }

    if (empty($riscos)) {
        $riscos[] = 'Nenhum risco estrutural relevante foi identificado pelo modo local.';
    }

    return [
        'texto_normalizado' => $normalizado,
        'checklist' => $checklist,
        'estrutura' => $estrutura,
        'qualidade' => $qualidade,
        'risco_percentual' => $percentualRisco,
        'risco_classificacao' => $classificacaoRisco,
        'tipo_badge' => $tipoBadge,
        'riscos' => $riscos,
        'total_palavras' => $totalPalavras,
        'total_caracteres' => $totalCaracteres,
    ];
}

function cij_contratos_prompt_ia(string $texto, string $tipoContrato, string $parteProtegida): string
{
    return "Analise o contrato abaixo em português do Brasil.\n\n"
        . "TIPO DE CONTRATO: " . ($tipoContrato ?: '[não informado]') . "\n"
        . "PARTE A SER PROTEGIDA: " . ($parteProtegida ?: '[não informada]') . "\n\n"
        . "REGRAS OBRIGATÓRIAS:\n"
        . "1. Não invente fatos, cláusulas, datas, valores, leis ou jurisprudência.\n"
        . "2. Identifique cláusulas ausentes, ambíguas, contraditórias ou potencialmente desfavoráveis.\n"
        . "3. Diferencie claramente risco baixo, moderado e alto.\n"
        . "4. Não declare validade definitiva do contrato.\n"
        . "5. Apresente sugestões como apoio técnico para revisão do advogado.\n"
        . "6. Entregue um RELATÓRIO DE ANÁLISE CONTRATUAL com: resumo, pontos positivos, riscos, cláusulas ausentes e recomendações.\n\n"
        . "CONTRATO:\n"
        . $texto;
}

$tiposContrato = [
    'Locação Comercial',
    'Prestação de Serviços',
    'Compra e Venda',
    'Honorários Advocatícios',
    'Confissão de Dívida',
    'Contrato Personalizado',
];

$partesProtegidas = [
    'Análise equilibrada',
    'Contratante',
    'Contratado',
    'Locador',
    'Locatário',
    'Comprador',
    'Vendedor',
    'Credor',
    'Devedor',
];

$tipoContrato = trim((string)($_POST['tipo_contrato'] ?? ''));
$parteProtegida = trim((string)($_POST['parte_protegida'] ?? 'Análise equilibrada'));
$textoContrato = trim((string)($_POST['texto_contrato'] ?? ''));

$analisado = false;
$analise = null;
$relatorio = '';
$modoResposta = '';
$erroIa = '';
$mensagemValidacao = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao_contratos'] ?? '') === 'analisar_contrato') {
    if ($tipoContrato === '') {
        $mensagemValidacao = 'Selecione o tipo de contrato.';
    } elseif ($textoContrato === '') {
        $mensagemValidacao = 'Cole ou digite o contrato que deseja analisar.';
    } elseif (mb_strlen($textoContrato, 'UTF-8') < 80) {
        $mensagemValidacao = 'O contrato deve possuir pelo menos 80 caracteres para análise.';
    } else {
        $analisado = true;
        $analise = cij_contratos_analisar($textoContrato, $tipoContrato);

        $promptSistema = 'Você é o Analista de Contratos do ROJEX.AI Enterprise. Responda em português do Brasil. Não invente leis, jurisprudências, cláusulas ou dados ausentes. Sua análise é apoio técnico e exige revisão final de advogado.';
        $promptUsuario = cij_contratos_prompt_ia($textoContrato, $tipoContrato, $parteProtegida);
        $retornoIa = cij_contratos_chamar_ia($promptSistema, $promptUsuario);

        if (($retornoIa['ok'] ?? false) === true) {
            $relatorio = trim((string)($retornoIa['texto'] ?? ''));
            $modoResposta = 'Análise por IA';
        } else {
            $erroIa = trim((string)($retornoIa['erro'] ?? 'IA externa indisponível ou não configurada.'));

            $linhas = [];
            $linhas[] = 'RELATÓRIO DE ANÁLISE CONTRATUAL';
            $linhas[] = '';
            $linhas[] = 'Tipo de contrato: ' . $tipoContrato;
            $linhas[] = 'Parte analisada/protegida: ' . $parteProtegida;
            $linhas[] = 'Qualidade contratual: ' . (int)$analise['qualidade'] . '%';
            $linhas[] = 'Estrutura localizada: ' . (int)$analise['estrutura'] . '%';
            $linhas[] = 'Nível de risco: ' . $analise['risco_classificacao'] . ' (' . (int)$analise['risco_percentual'] . '%)';
            $linhas[] = '';
            $linhas[] = 'PONTOS DE ATENÇÃO';
            foreach ($analise['riscos'] as $risco) {
                $linhas[] = '- ' . $risco;
            }
            $linhas[] = '';
            $linhas[] = 'RECOMENDAÇÃO';
            $linhas[] = 'Revisar os pontos indicados e confirmar a adequação das cláusulas à operação, às partes e à legislação aplicável antes de assinatura ou uso externo.';
            $linhas[] = '';
            $linhas[] = 'CONTRATO ANALISADO';
            $linhas[] = $analise['texto_normalizado'];

            $relatorio = implode("\n", $linhas);
            $modoResposta = 'Análise local segura';
        }

        if (function_exists('sgl_registrar_log')) {
            sgl_registrar_log(
                $conn,
                'ANALISE_CONTRATO_CIJ',
                'cij_contratos',
                '0',
                ($tipoContrato ?: 'Contrato') . ' - ' . $modoResposta
            );
        }
    }
}
?>

<div class="container-fluid">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
        <div>
            <h2 class="mb-1 fw-bold text-primary">
                <i class="bi bi-file-earmark-text me-2"></i>Análise de Contratos
            </h2>
            <p class="text-muted mb-0">
                Auditoria estrutural e identificação de riscos, lacunas e pontos de atenção contratuais.
            </p>
        </div>
        <a href="?mod=cij" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i>Voltar ao CIJ
        </a>
    </div>

    <div class="alert <?= cij_contratos_ia_disponivel() ? 'alert-success' : 'alert-warning' ?> border-0 shadow-sm">
        <?php if (cij_contratos_ia_disponivel()): ?>
            <strong>IA conectada:</strong> a análise contratual utilizará a API configurada no ROJEX.AI.
        <?php else: ?>
            <strong>Modo análise local:</strong> a API externa ainda não está configurada. O sistema fará auditoria estrutural sem inventar conteúdo jurídico.
        <?php endif; ?>
    </div>

    <?php if ($mensagemValidacao !== ''): ?>
        <div class="alert alert-danger border-0 shadow-sm">
            <i class="bi bi-exclamation-triangle me-1"></i><?= cij_contratos_h($mensagemValidacao) ?>
        </div>
    <?php endif; ?>

    <?php if ($erroIa !== '' && $analisado): ?>
        <div class="alert alert-info border-0 shadow-sm">
            <strong>Observação técnica:</strong> <?= cij_contratos_h($erroIa) ?>
        </div>
    <?php endif; ?>

    <div class="row g-4">
        <div class="col-xl-5">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-dark text-white">
                    <i class="bi bi-file-earmark-text me-2"></i>Contrato para análise
                </div>
                <div class="card-body">
                    <form method="POST" class="row g-3">
                        <input type="hidden" name="acao_contratos" value="analisar_contrato">

                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Tipo de contrato</label>
                            <select name="tipo_contrato" class="form-select" required>
                                <option value="">Selecione...</option>
                                <?php foreach ($tiposContrato as $opcao): ?>
                                    <option value="<?= cij_contratos_h($opcao) ?>" <?= $tipoContrato === $opcao ? 'selected' : '' ?>>
                                        <?= cij_contratos_h($opcao) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Parte a proteger</label>
                            <select name="parte_protegida" class="form-select">
                                <?php foreach ($partesProtegidas as $opcao): ?>
                                    <option value="<?= cij_contratos_h($opcao) ?>" <?= $parteProtegida === $opcao ? 'selected' : '' ?>>
                                        <?= cij_contratos_h($opcao) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-12">
                            <label class="form-label fw-semibold">Texto do contrato</label>
                            <textarea
                                name="texto_contrato"
                                class="form-control"
                                rows="25"
                                required
                                placeholder="Cole aqui o contrato completo para análise."><?= cij_contratos_h($textoContrato) ?></textarea>
                            <div class="form-text">O contrato não será salvo no banco nesta etapa.</div>
                        </div>

                        <div class="col-12 d-grid">
                            <button class="btn btn-primary btn-lg">
                                <i class="bi bi-search me-1"></i>Analisar contrato
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-xl-7">
            <?php if (!$analisado || !$analise): ?>
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-header bg-dark text-white">
                        <i class="bi bi-clipboard-data me-2"></i>Resultado da análise
                    </div>
                    <div class="card-body d-flex align-items-center justify-content-center">
                        <div class="text-center text-muted py-5">
                            <i class="bi bi-file-earmark-text fs-1 d-block mb-3 opacity-25"></i>
                            <h5 class="fw-bold">Nenhum contrato analisado</h5>
                            <p class="mb-0">Informe o contrato ao lado e clique em analisar contrato.</p>
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <div class="card border-0 shadow-sm mb-4">
                    <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <span><i class="bi bi-speedometer2 me-2"></i>Diagnóstico contratual</span>
                        <span class="badge bg-<?= cij_contratos_h($analise['tipo_badge']) ?>">
                            <?= cij_contratos_h($modoResposta) ?>
                        </span>
                    </div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-md-4 text-center">
                                <div class="display-4 fw-bold text-<?= cij_contratos_h($analise['tipo_badge']) ?>">
                                    <?= (int)$analise['qualidade'] ?>%
                                </div>
                                <div class="fw-semibold">Qualidade contratual</div>
                            </div>
                            <div class="col-md-8">
                                <div class="row g-3">
                                    <div class="col-sm-6">
                                        <div class="border rounded p-3 h-100">
                                            <small class="text-muted d-block">Estrutura</small>
                                            <strong><?= (int)$analise['estrutura'] ?>%</strong>
                                        </div>
                                    </div>
                                    <div class="col-sm-6">
                                        <div class="border rounded p-3 h-100">
                                            <small class="text-muted d-block">Nível de risco</small>
                                            <strong class="text-<?= cij_contratos_h($analise['tipo_badge']) ?>">
                                                <?= cij_contratos_h($analise['risco_classificacao']) ?>
                                            </strong>
                                        </div>
                                    </div>
                                    <div class="col-sm-6">
                                        <div class="border rounded p-3 h-100">
                                            <small class="text-muted d-block">Palavras</small>
                                            <strong><?= (int)$analise['total_palavras'] ?></strong>
                                        </div>
                                    </div>
                                    <div class="col-sm-6">
                                        <div class="border rounded p-3 h-100">
                                            <small class="text-muted d-block">Caracteres</small>
                                            <strong><?= (int)$analise['total_caracteres'] ?></strong>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row g-4 mb-4">
                    <div class="col-lg-6">
                        <div class="card border-0 shadow-sm h-100">
                            <div class="card-header bg-dark text-white">
                                <i class="bi bi-list-check me-2"></i>Checklist contratual
                            </div>
                            <div class="list-group list-group-flush">
                                <?php foreach ($analise['checklist'] as $item): ?>
                                    <div class="list-group-item d-flex justify-content-between align-items-center">
                                        <span><?= cij_contratos_h($item['nome']) ?></span>
                                        <?php if ($item['ok']): ?>
                                            <span class="badge bg-success"><i class="bi bi-check-lg me-1"></i>Localizado</span>
                                        <?php else: ?>
                                            <span class="badge bg-warning text-dark"><i class="bi bi-exclamation-lg me-1"></i>Revisar</span>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>

                    <div class="col-lg-6">
                        <div class="card border-0 shadow-sm h-100">
                            <div class="card-header bg-dark text-white">
                                <i class="bi bi-shield-exclamation me-2"></i>Riscos e pontos de atenção
                            </div>
                            <div class="card-body">
                                <ul class="mb-0">
                                    <?php foreach ($analise['riscos'] as $risco): ?>
                                        <li class="mb-2"><?= cij_contratos_h($risco) ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <span><i class="bi bi-file-earmark-bar-graph me-2"></i>Relatório editável</span>
                        <span class="badge bg-primary"><?= cij_contratos_h($modoResposta) ?></span>
                    </div>
                    <div class="card-body">
                        <div class="d-flex flex-wrap gap-2 mb-3">
                            <button type="button" class="btn btn-outline-primary btn-sm" id="btnCopiarContrato">
                                <i class="bi bi-clipboard me-1"></i>Copiar
                            </button>
                            <button type="button" class="btn btn-outline-success btn-sm" id="btnWordContrato">
                                <i class="bi bi-file-earmark-word me-1"></i>Baixar Word
                            </button>
                            <button type="button" class="btn btn-outline-secondary btn-sm" id="btnImprimirContrato">
                                <i class="bi bi-printer me-1"></i>Imprimir / PDF
                            </button>
                            <a href="?mod=cij&ferramenta=contratos" class="btn btn-outline-dark btn-sm">
                                <i class="bi bi-plus-circle me-1"></i>Nova análise
                            </a>
                        </div>

                        <textarea id="relatorioContratoCij" class="form-control" rows="25"><?= cij_contratos_h($relatorio) ?></textarea>

                        <div class="alert alert-warning mt-3 mb-0">
                            <i class="bi bi-exclamation-triangle me-1"></i>
                            Esta análise é apoio técnico. A validação e a responsabilidade final permanecem com o advogado.
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if ($analisado && $analise): ?>
<script>
(function(){
    const campo = document.getElementById('relatorioContratoCij');
    const btnCopiar = document.getElementById('btnCopiarContrato');
    const btnWord = document.getElementById('btnWordContrato');
    const btnImprimir = document.getElementById('btnImprimirContrato');

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
body { font-family: "Times New Roman", serif; font-size: 12pt; line-height: 1.5; text-align: justify; }
</style>
</head>
<body><div>${conteudo}</div></body>
</html>`;

            const blob = new Blob(['\ufeff', html], {type: 'application/msword'});
            const url = URL.createObjectURL(blob);
            const link = document.createElement('a');
            link.href = url;
            link.download = 'analise_contratual_rojex.doc';
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
<title>Análise Contratual — ROJEX.AI</title>
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
<div class="rodape">Relatório de apoio técnico. Revisão obrigatória por advogado antes do uso externo.</div>
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
