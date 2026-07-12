<?php
/**
 * modules/cij/revisor.php
 * Revisor Jurídico — ROJEX.AI Enterprise
 * Sprint 4.1.4 — Etapa 3
 *
 * Objetivos:
 * - Revisar textos jurídicos em modo local seguro.
 * - Usar IA externa quando configurada em config/ia.php.
 * - Não alterar banco de dados.
 * - Preservar compatibilidade com PHP 8+, MySQL, XAMPP e Hostinger.
 */

if (!isset($conn) || !($conn instanceof mysqli)) {
    $conn = conectar();
}

$arquivoConfigIa = __DIR__ . '/../../config/ia.php';
if (is_file($arquivoConfigIa)) {
    require_once $arquivoConfigIa;
}

function cij_revisor_h($valor): string
{
    return htmlspecialchars((string)($valor ?? ''), ENT_QUOTES, 'UTF-8');
}

function cij_revisor_ia_disponivel(): bool
{
    return function_exists('sgl_ia_disponivel') && sgl_ia_disponivel();
}

function cij_revisor_chamar_ia(string $promptSistema, string $promptUsuario): array
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

function cij_revisor_normalizar_texto(string $texto): string
{
    $texto = str_replace(["\r\n", "\r"], "\n", trim($texto));
    $texto = preg_replace('/[ \t]+/u', ' ', $texto);
    $texto = preg_replace("/\n{3,}/u", "\n\n", (string)$texto);
    return trim((string)$texto);
}

function cij_revisor_contem(string $texto, array $termos): bool
{
    $texto = mb_strtolower($texto, 'UTF-8');

    foreach ($termos as $termo) {
        if (str_contains($texto, mb_strtolower($termo, 'UTF-8'))) {
            return true;
        }
    }

    return false;
}

function cij_revisor_checklist_por_tipo(string $tipoDocumento): array
{
    $tipo = mb_strtolower(trim($tipoDocumento), 'UTF-8');

    $checklists = [
        'petição inicial' => [
            'Endereçamento' => ['excelentíssimo', 'excelentissima', 'ao juízo', 'à vara', 'ao tribunal'],
            'Qualificação das partes' => ['nacionalidade', 'estado civil', 'profissão', 'cpf', 'cnpj', 'residente e domiciliado'],
            'Exposição dos fatos' => ['dos fatos', 'síntese dos fatos', 'historico dos fatos', 'histórico dos fatos'],
            'Fundamentação jurídica' => ['do direito', 'fundamentação', 'fundamentacao', 'fundamentos jurídicos', 'fundamentos juridicos'],
            'Pedidos' => ['dos pedidos', 'diante do exposto', 'requer', 'requer-se'],
            'Valor da causa' => ['valor da causa'],
            'Provas' => ['protesta por provas', 'produção de provas', 'producao de provas', 'juntada'],
            'Fechamento' => ['termos em que', 'pede deferimento', 'nestes termos'],
            'Assinatura/OAB' => ['oab', 'advogado', 'advogada'],
        ],
        'contestação' => [
            'Endereçamento' => ['excelentíssimo', 'ao juízo', 'à vara', 'ao tribunal'],
            'Identificação do processo' => ['processo nº', 'processo n°', 'autos nº', 'autos n°'],
            'Síntese da demanda' => ['síntese', 'dos fatos', 'da demanda'],
            'Preliminares' => ['preliminar', 'preliminares'],
            'Mérito' => ['do mérito', 'merito'],
            'Impugnação específica' => ['impugna', 'impugnação', 'impugnacao'],
            'Provas' => ['provas', 'produção de provas', 'producao de provas'],
            'Pedidos finais' => ['diante do exposto', 'requer', 'improcedência', 'improcedencia'],
            'Assinatura/OAB' => ['oab', 'advogado', 'advogada'],
        ],
        'réplica' => [
            'Endereçamento' => ['excelentíssimo', 'ao juízo', 'à vara', 'ao tribunal'],
            'Identificação do processo' => ['processo nº', 'processo n°', 'autos nº', 'autos n°'],
            'Síntese da contestação' => ['contestação', 'contestacao', 'síntese'],
            'Impugnação das preliminares' => ['preliminar', 'preliminares'],
            'Impugnação do mérito' => ['mérito', 'merito', 'impugna'],
            'Provas' => ['provas', 'documentos', 'juntada'],
            'Pedidos' => ['diante do exposto', 'requer'],
            'Assinatura/OAB' => ['oab', 'advogado', 'advogada'],
        ],
        'recurso' => [
            'Endereçamento' => ['excelentíssimo', 'ao tribunal', 'à turma', 'ao juízo'],
            'Identificação do processo' => ['processo nº', 'processo n°', 'autos nº', 'autos n°'],
            'Cabimento' => ['cabimento', 'cabível', 'cabivel'],
            'Tempestividade' => ['tempestividade', 'tempestivo'],
            'Preparo/Gratuidade' => ['preparo', 'gratuidade', 'justiça gratuita', 'justica gratuita'],
            'Razões recursais' => ['razões', 'razoes', 'fundamentos do recurso'],
            'Pedidos' => ['provimento', 'reforma', 'anulação', 'anulacao', 'requer'],
            'Fechamento' => ['pede deferimento', 'nestes termos', 'termos em que'],
            'Assinatura/OAB' => ['oab', 'advogado', 'advogada'],
        ],
        'contrato' => [
            'Qualificação das partes' => ['contratante', 'contratado', 'partes', 'cpf', 'cnpj'],
            'Objeto' => ['objeto do contrato', 'do objeto', 'objeto'],
            'Obrigações' => ['obrigações', 'obrigacoes', 'deveres das partes'],
            'Valor e pagamento' => ['valor', 'pagamento', 'remuneração', 'remuneracao'],
            'Prazo e vigência' => ['prazo', 'vigência', 'vigencia'],
            'Rescisão' => ['rescisão', 'rescisao'],
            'Multa/Penalidade' => ['multa', 'penalidade'],
            'Foro' => ['foro', 'comarca'],
            'Assinaturas' => ['assinatura', 'assinam', 'testemunhas'],
        ],
        'procuração' => [
            'Outorgante' => ['outorgante', 'nome do cliente', 'cpf', 'cnpj'],
            'Outorgado' => ['outorgado', 'procurador', 'advogado'],
            'Poderes' => ['poderes', 'confere poderes', 'nomeia e constitui'],
            'Órgãos/Finalidade' => ['inss', 'judicial', 'extrajudicial', 'administrativo', 'tribunal'],
            'Documento pessoal' => ['cpf', 'cnpj', 'rg'],
            'Local' => ['cidade', 'comarca', 'uf'],
            'Data' => ['data', '/202', 'de 20'],
            'Assinatura do outorgante' => ['assinatura', 'nome do cliente'],
            'OAB do advogado' => ['oab'],
        ],
        'parecer jurídico' => [
            'Identificação da consulta' => ['consulta', 'consulente', 'objeto do parecer'],
            'Relatório' => ['relatório', 'relatorio', 'síntese', 'sintese'],
            'Fundamentação' => ['fundamentação', 'fundamentacao', 'análise jurídica', 'analise juridica'],
            'Conclusão' => ['conclusão', 'conclusao', 'opina-se', 'entendimento'],
            'Ressalvas' => ['ressalva', 'limitação', 'limitacao'],
            'Data' => ['data', '/202', 'de 20'],
            'Assinatura/OAB' => ['oab', 'advogado', 'advogada'],
        ],
        'notificação extrajudicial' => [
            'Notificante' => ['notificante'],
            'Notificado' => ['notificado', 'destinatário', 'destinatario'],
            'Descrição dos fatos' => ['dos fatos', 'ocorrido', 'inadimplemento', 'descumprimento'],
            'Exigência/Providência' => ['requer', 'exige', 'providência', 'providencia'],
            'Prazo para cumprimento' => ['prazo', 'dias'],
            'Advertência' => ['sob pena', 'medidas judiciais', 'consequências', 'consequencias'],
            'Local e data' => ['data', 'cidade', '/202'],
            'Assinatura' => ['assinatura', 'oab', 'advogado'],
        ],
        'manifestação' => [
            'Endereçamento' => ['excelentíssimo', 'ao juízo', 'à vara', 'ao tribunal'],
            'Identificação do processo' => ['processo nº', 'processo n°', 'autos nº', 'autos n°'],
            'Objeto da manifestação' => ['vem manifestar', 'manifestação', 'manifestacao'],
            'Fundamentação' => ['fundamentação', 'fundamentacao', 'do direito'],
            'Pedidos' => ['diante do exposto', 'requer'],
            'Fechamento' => ['pede deferimento', 'nestes termos'],
            'Assinatura/OAB' => ['oab', 'advogado', 'advogada'],
        ],
    ];

    return $checklists[$tipo] ?? [
        'Identificação do documento' => ['título', 'titulo', 'assunto', 'objeto'],
        'Partes envolvidas' => ['cliente', 'parte', 'contratante', 'requerente', 'outorgante'],
        'Conteúdo principal' => ['fatos', 'objeto', 'finalidade', 'fundamentação', 'fundamentacao'],
        'Providência ou conclusão' => ['requer', 'conclusão', 'conclusao', 'providência', 'providencia'],
        'Data' => ['data', '/202', 'de 20'],
        'Assinatura/Responsável' => ['assinatura', 'oab', 'advogado', 'advogada'],
    ];
}

function cij_revisor_analisar_local(string $texto, string $tipoDocumento): array
{
    $textoNormalizado = cij_revisor_normalizar_texto($texto);
    $palavras = preg_split('/\s+/u', $textoNormalizado, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $totalPalavras = count($palavras);
    $totalCaracteres = mb_strlen($textoNormalizado, 'UTF-8');

    $frases = preg_split('/(?<=[.!?;:])\s+/u', $textoNormalizado, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $frasesLongas = 0;

    foreach ($frases as $frase) {
        $qtd = count(preg_split('/\s+/u', trim($frase), -1, PREG_SPLIT_NO_EMPTY) ?: []);
        if ($qtd > 35) {
            $frasesLongas++;
        }
    }

    $linhas = preg_split('/\n/u', $textoNormalizado) ?: [];
    $linhasCaixaAlta = 0;
    $paragrafosVaziosExcessivos = preg_match_all("/\n{3,}/u", $texto);

    foreach ($linhas as $linha) {
        $linhaLimpa = trim($linha);
        if (
            mb_strlen($linhaLimpa, 'UTF-8') >= 12
            && preg_match('/[A-ZÁÉÍÓÚÂÊÔÃÕÇ]/u', $linhaLimpa)
            && mb_strtoupper($linhaLimpa, 'UTF-8') === $linhaLimpa
        ) {
            $linhasCaixaAlta++;
        }
    }

    $frequencias = [];
    foreach ($palavras as $palavra) {
        $palavra = mb_strtolower(trim($palavra, " \t\n\r\0\x0B.,;:!?()[]{}\"'"), 'UTF-8');
        if (mb_strlen($palavra, 'UTF-8') < 5) {
            continue;
        }
        $frequencias[$palavra] = ($frequencias[$palavra] ?? 0) + 1;
    }
    arsort($frequencias);

    $repeticoes = [];
    foreach ($frequencias as $palavra => $quantidade) {
        if ($quantidade >= 5) {
            $repeticoes[] = $palavra . ' (' . $quantidade . 'x)';
        }
        if (count($repeticoes) >= 6) {
            break;
        }
    }

    $estruturaTipo = cij_revisor_checklist_por_tipo($tipoDocumento);
    $checklist = [];
    $presentes = 0;

    foreach ($estruturaTipo as $nome => $termos) {
        $ok = cij_revisor_contem($textoNormalizado, $termos);
        $checklist[] = ['nome' => $nome, 'ok' => $ok];
        if ($ok) {
            $presentes++;
        }
    }

    $qualidadeEstrutural = (int)round(($presentes / max(count($checklist), 1)) * 100);

    $redacao = 100;
    if ($frasesLongas > 0) {
        $redacao -= min(25, $frasesLongas * 5);
    }
    if ($linhasCaixaAlta > 0) {
        $redacao -= min(20, $linhasCaixaAlta * 4);
    }
    if (count($repeticoes) > 0) {
        $redacao -= min(20, count($repeticoes) * 3);
    }
    if ($totalPalavras < 40) {
        $redacao -= 20;
    }
    $redacao = max(0, min(100, $redacao));

    $organizacao = 100;
    if ($paragrafosVaziosExcessivos > 0) {
        $organizacao -= min(20, $paragrafosVaziosExcessivos * 5);
    }
    if (count($linhas) <= 2 && $totalPalavras > 80) {
        $organizacao -= 25;
    }
    if ($totalPalavras > 120 && count($linhas) < 5) {
        $organizacao -= 15;
    }
    $organizacao = max(0, min(100, $organizacao));

    $qualidadeJuridica = $qualidadeEstrutural;
    if ($totalPalavras < 50) {
        $qualidadeJuridica -= 10;
    }
    $qualidadeJuridica = max(0, min(100, $qualidadeJuridica));

    $pontuacao = (int)round(
        ($qualidadeEstrutural * 0.45)
        + ($qualidadeJuridica * 0.25)
        + ($redacao * 0.20)
        + ($organizacao * 0.10)
    );

    if ($pontuacao >= 85) {
        $classificacao = 'Excelente base para revisão final';
        $tipo = 'success';
    } elseif ($pontuacao >= 70) {
        $classificacao = 'Boa estrutura, com pontos de atenção';
        $tipo = 'primary';
    } elseif ($pontuacao >= 50) {
        $classificacao = 'Necessita revisão técnica';
        $tipo = 'warning';
    } else {
        $classificacao = 'Estrutura insuficiente ou incompleta';
        $tipo = 'danger';
    }

    $alertas = [];

    if ($totalPalavras < 40) {
        $alertas[] = 'O texto possui poucas palavras para uma análise estrutural completa.';
    }

    if ($frasesLongas > 0) {
        $alertas[] = "Foram identificada(s) {$frasesLongas} frase(s) com mais de 35 palavras. Considere dividi-las.";
    }

    if ($linhasCaixaAlta > 0) {
        $alertas[] = "Foram identificada(s) {$linhasCaixaAlta} linha(s) extensas em caixa alta.";
    }

    if (!empty($repeticoes)) {
        $alertas[] = 'Palavras com repetição elevada: ' . implode(', ', $repeticoes) . '.';
    }

    foreach ($checklist as $item) {
        if (!$item['ok']) {
            $alertas[] = 'Não foi localizado claramente o requisito: ' . $item['nome'] . '.';
        }
    }

    if (empty($alertas)) {
        $alertas[] = 'Nenhum alerta estrutural relevante foi identificado pelo modo local.';
    }

    return [
        'texto_normalizado' => $textoNormalizado,
        'total_palavras' => $totalPalavras,
        'total_caracteres' => $totalCaracteres,
        'frases_longas' => $frasesLongas,
        'linhas_caixa_alta' => $linhasCaixaAlta,
        'repeticoes' => $repeticoes,
        'checklist' => $checklist,
        'qualidade_estrutural' => $qualidadeEstrutural,
        'qualidade_juridica' => $qualidadeJuridica,
        'redacao' => $redacao,
        'organizacao' => $organizacao,
        'pontuacao' => $pontuacao,
        'classificacao' => $classificacao,
        'tipo' => $tipo,
        'alertas' => $alertas,
    ];
}

function cij_revisor_prompt_ia(
    string $texto,
    string $tipoDocumento,
    string $areaDireito,
    string $nivelRevisao
): string {
    return "Revise o texto jurídico abaixo em português do Brasil.\n\n"
        . "TIPO DE DOCUMENTO: " . ($tipoDocumento ?: '[não informado]') . "\n"
        . "ÁREA DO DIREITO: " . ($areaDireito ?: '[não informada]') . "\n"
        . "NÍVEL DE REVISÃO: " . ($nivelRevisao ?: 'Completa') . "\n\n"
        . "REGRAS OBRIGATÓRIAS:\n"
        . "1. Não invente fatos, datas, documentos, artigos, jurisprudências, números ou dados pessoais.\n"
        . "2. Preserve o sentido e a estratégia do texto original.\n"
        . "3. Corrija ortografia, pontuação, concordância, clareza e organização.\n"
        . "4. Não acrescente fundamento legal específico que não possa ser validado.\n"
        . "5. Use [REVISAR] ou [INFORMAR] quando faltar dado essencial.\n"
        . "6. Entregue primeiro a versão revisada integral e depois uma seção chamada 'PONTOS DE ATENÇÃO'.\n"
        . "7. O resultado é rascunho e depende de validação de advogado.\n\n"
        . "TEXTO ORIGINAL:\n"
        . $texto;
}

$tiposDocumento = [
    'Petição Inicial',
    'Contestação',
    'Réplica',
    'Recurso',
    'Contrato',
    'Procuração',
    'Parecer Jurídico',
    'Notificação Extrajudicial',
    'Manifestação',
    'Documento Personalizado',
];

$areasDireito = [
    'Cível',
    'Trabalhista',
    'Previdenciário',
    'Família',
    'Penal',
    'Empresarial',
    'Tributário',
    'Consumidor',
    'Administrativo',
    'Bancário',
    'Imobiliário',
    'Contratual',
    'Outra',
];

$niveisRevisao = [
    'Português e clareza',
    'Estrutura jurídica',
    'Completa',
];

$tipoDocumento = trim((string)($_POST['tipo_documento'] ?? ''));
$areaDireito = trim((string)($_POST['area_direito'] ?? ''));
$nivelRevisao = trim((string)($_POST['nivel_revisao'] ?? 'Completa'));
$textoOriginal = trim((string)($_POST['texto_original'] ?? ''));

$analisado = false;
$analiseLocal = null;
$textoRevisado = '';
$modoResposta = '';
$erroIa = '';
$mensagemValidacao = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao_revisor'] ?? '') === 'revisar_texto') {
    if ($tipoDocumento === '') {
        $mensagemValidacao = 'Selecione o tipo de documento.';
    } elseif ($textoOriginal === '') {
        $mensagemValidacao = 'Cole ou digite o texto que deseja revisar.';
    } elseif (mb_strlen($textoOriginal, 'UTF-8') < 40) {
        $mensagemValidacao = 'O texto deve possuir pelo menos 40 caracteres para análise.';
    } else {
        $analisado = true;
        $analiseLocal = cij_revisor_analisar_local($textoOriginal, $tipoDocumento);

        $promptSistema = 'Você é o Revisor Jurídico do ROJEX.AI Enterprise. Responda em português do Brasil. Não invente fatos, dados, legislação ou jurisprudência. Preserve o sentido do texto e sinalize lacunas. Toda resposta é rascunho para revisão obrigatória de advogado.';
        $promptUsuario = cij_revisor_prompt_ia(
            $textoOriginal,
            $tipoDocumento,
            $areaDireito,
            $nivelRevisao
        );

        $retornoIa = cij_revisor_chamar_ia($promptSistema, $promptUsuario);

        if (($retornoIa['ok'] ?? false) === true) {
            $textoRevisado = trim((string)($retornoIa['texto'] ?? ''));
            $modoResposta = 'Revisão por IA';
        } else {
            $erroIa = trim((string)($retornoIa['erro'] ?? 'IA externa indisponível ou não configurada.'));
            $textoRevisado = (string)($analiseLocal['texto_normalizado'] ?? $textoOriginal);
            $modoResposta = 'Revisão local segura';
        }

        if (function_exists('sgl_registrar_log')) {
            sgl_registrar_log(
                $conn,
                'REVISAO_JURIDICA_CIJ',
                'cij_revisor',
                '0',
                ($tipoDocumento ?: 'Documento') . ' - ' . $modoResposta
            );
        }
    }
}
?>

<div class="container-fluid">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
        <div>
            <h2 class="mb-1 fw-bold text-primary">
                <i class="bi bi-file-earmark-check me-2"></i>Revisor Jurídico
            </h2>
            <p class="text-muted mb-0">
                Revisão estrutural, textual e jurídica de minutas, peças, contratos e documentos.
            </p>
        </div>
        <a href="?mod=cij" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i>Voltar ao CIJ
        </a>
    </div>

    <div class="alert <?= cij_revisor_ia_disponivel() ? 'alert-success' : 'alert-warning' ?> border-0 shadow-sm">
        <?php if (cij_revisor_ia_disponivel()): ?>
            <strong>IA conectada:</strong> o Revisor Jurídico utilizará a API configurada no ROJEX.AI.
        <?php else: ?>
            <strong>Modo revisão local:</strong> a API externa ainda não está configurada. O sistema fará análise estrutural e textual sem inventar conteúdo jurídico.
        <?php endif; ?>
    </div>

    <?php if ($mensagemValidacao !== ''): ?>
        <div class="alert alert-danger border-0 shadow-sm">
            <i class="bi bi-exclamation-triangle me-1"></i><?= cij_revisor_h($mensagemValidacao) ?>
        </div>
    <?php endif; ?>

    <?php if ($erroIa !== '' && $analisado): ?>
        <div class="alert alert-info border-0 shadow-sm">
            <strong>Observação técnica:</strong> <?= cij_revisor_h($erroIa) ?>
        </div>
    <?php endif; ?>

    <div class="row g-4">
        <div class="col-xl-5">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-dark text-white">
                    <i class="bi bi-file-text me-2"></i>Documento para revisão
                </div>
                <div class="card-body">
                    <form method="POST" class="row g-3">
                        <input type="hidden" name="acao_revisor" value="revisar_texto">

                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Tipo de documento</label>
                            <select name="tipo_documento" class="form-select" required>
                                <option value="">Selecione...</option>
                                <?php foreach ($tiposDocumento as $opcao): ?>
                                    <option value="<?= cij_revisor_h($opcao) ?>" <?= $tipoDocumento === $opcao ? 'selected' : '' ?>>
                                        <?= cij_revisor_h($opcao) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Área do Direito</label>
                            <select name="area_direito" class="form-select">
                                <option value="">Selecione...</option>
                                <?php foreach ($areasDireito as $opcao): ?>
                                    <option value="<?= cij_revisor_h($opcao) ?>" <?= $areaDireito === $opcao ? 'selected' : '' ?>>
                                        <?= cij_revisor_h($opcao) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-12">
                            <label class="form-label fw-semibold">Nível de revisão</label>
                            <select name="nivel_revisao" class="form-select">
                                <?php foreach ($niveisRevisao as $opcao): ?>
                                    <option value="<?= cij_revisor_h($opcao) ?>" <?= $nivelRevisao === $opcao ? 'selected' : '' ?>>
                                        <?= cij_revisor_h($opcao) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-12">
                            <label class="form-label fw-semibold">Texto jurídico</label>
                            <textarea
                                name="texto_original"
                                class="form-control"
                                rows="22"
                                required
                                placeholder="Cole aqui a peça, contrato, parecer, manifestação ou outro documento jurídico."><?= cij_revisor_h($textoOriginal) ?></textarea>
                            <div class="form-text">
                                O texto não será salvo no banco nesta etapa.
                            </div>
                        </div>

                        <div class="col-12 d-grid">
                            <button class="btn btn-primary btn-lg">
                                <i class="bi bi-search me-1"></i>Revisar documento
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-xl-7">
            <?php if (!$analisado || !$analiseLocal): ?>
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-header bg-dark text-white">
                        <i class="bi bi-clipboard-check me-2"></i>Resultado da revisão
                    </div>
                    <div class="card-body d-flex align-items-center justify-content-center">
                        <div class="text-center text-muted py-5">
                            <i class="bi bi-file-earmark-check fs-1 d-block mb-3 opacity-25"></i>
                            <h5 class="fw-bold">Nenhum documento revisado</h5>
                            <p class="mb-0">Informe o texto ao lado e clique em revisar documento.</p>
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <div class="card border-0 shadow-sm mb-4">
                    <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <span><i class="bi bi-speedometer2 me-2"></i>Diagnóstico consolidado</span>
                        <span class="badge bg-<?= cij_revisor_h($analiseLocal['tipo']) ?>">
                            <?= cij_revisor_h($modoResposta) ?>
                        </span>
                    </div>
                    <div class="card-body">
                        <div class="row g-3 align-items-center">
                            <div class="col-md-4 text-center">
                                <div class="display-4 fw-bold text-<?= cij_revisor_h($analiseLocal['tipo']) ?>">
                                    <?= (int)$analiseLocal['pontuacao'] ?>%
                                </div>
                                <div class="fw-semibold"><?= cij_revisor_h($analiseLocal['classificacao']) ?></div>
                            </div>
                            <div class="col-md-8">
                                <div class="progress mb-3" style="height: 18px;">
                                    <div
                                        class="progress-bar bg-<?= cij_revisor_h($analiseLocal['tipo']) ?>"
                                        role="progressbar"
                                        style="width: <?= (int)$analiseLocal['pontuacao'] ?>%;"
                                        aria-valuenow="<?= (int)$analiseLocal['pontuacao'] ?>"
                                        aria-valuemin="0"
                                        aria-valuemax="100"></div>
                                </div>
                                <div class="row g-2 small mb-3">
                                    <div class="col-sm-6"><strong>Palavras:</strong> <?= (int)$analiseLocal['total_palavras'] ?></div>
                                    <div class="col-sm-6"><strong>Caracteres:</strong> <?= (int)$analiseLocal['total_caracteres'] ?></div>
                                    <div class="col-sm-6"><strong>Frases longas:</strong> <?= (int)$analiseLocal['frases_longas'] ?></div>
                                    <div class="col-sm-6"><strong>Linhas em caixa alta:</strong> <?= (int)$analiseLocal['linhas_caixa_alta'] ?></div>
                                </div>

                                <div class="row g-3">
                                    <div class="col-sm-6">
                                        <div class="border rounded p-2 h-100">
                                            <small class="text-muted d-block">Qualidade estrutural</small>
                                            <strong><?= (int)$analiseLocal['qualidade_estrutural'] ?>%</strong>
                                        </div>
                                    </div>
                                    <div class="col-sm-6">
                                        <div class="border rounded p-2 h-100">
                                            <small class="text-muted d-block">Qualidade jurídica</small>
                                            <strong><?= (int)$analiseLocal['qualidade_juridica'] ?>%</strong>
                                        </div>
                                    </div>
                                    <div class="col-sm-6">
                                        <div class="border rounded p-2 h-100">
                                            <small class="text-muted d-block">Redação</small>
                                            <strong><?= (int)$analiseLocal['redacao'] ?>%</strong>
                                        </div>
                                    </div>
                                    <div class="col-sm-6">
                                        <div class="border rounded p-2 h-100">
                                            <small class="text-muted d-block">Organização</small>
                                            <strong><?= (int)$analiseLocal['organizacao'] ?>%</strong>
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
                                <i class="bi bi-list-check me-2"></i>Checklist estrutural
                            </div>
                            <div class="list-group list-group-flush">
                                <?php foreach ($analiseLocal['checklist'] as $item): ?>
                                    <div class="list-group-item d-flex justify-content-between align-items-center">
                                        <span><?= cij_revisor_h($item['nome']) ?></span>
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
                                <i class="bi bi-exclamation-diamond me-2"></i>Pontos de atenção
                            </div>
                            <div class="card-body">
                                <ul class="mb-0">
                                    <?php foreach ($analiseLocal['alertas'] as $alerta): ?>
                                        <li class="mb-2"><?= cij_revisor_h($alerta) ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <span><i class="bi bi-pencil-square me-2"></i>Texto revisado/editável</span>
                        <span class="badge bg-primary"><?= cij_revisor_h($modoResposta) ?></span>
                    </div>
                    <div class="card-body">
                        <div class="d-flex flex-wrap gap-2 mb-3">
                            <button type="button" class="btn btn-outline-primary btn-sm" id="btnCopiarRevisao">
                                <i class="bi bi-clipboard me-1"></i>Copiar
                            </button>
                            <button type="button" class="btn btn-outline-success btn-sm" id="btnWordRevisao">
                                <i class="bi bi-file-earmark-word me-1"></i>Baixar Word
                            </button>
                            <button type="button" class="btn btn-outline-secondary btn-sm" id="btnImprimirRevisao">
                                <i class="bi bi-printer me-1"></i>Imprimir / PDF
                            </button>
                            <a href="?mod=cij&ferramenta=revisor" class="btn btn-outline-dark btn-sm">
                                <i class="bi bi-plus-circle me-1"></i>Nova revisão
                            </a>
                        </div>

                        <label class="form-label fw-semibold">Conteúdo revisado</label>
                        <textarea id="textoRevisadoCij" class="form-control" rows="24"><?= cij_revisor_h($textoRevisado) ?></textarea>
                        <div class="form-text">
                            Revise e ajuste o conteúdo antes de copiar, exportar, imprimir ou utilizar externamente.
                        </div>

                        <div class="alert alert-warning mt-3 mb-0">
                            <i class="bi bi-exclamation-triangle me-1"></i>
                            Resultado gerado como apoio. A conferência e a responsabilidade final permanecem com o advogado.
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if ($analisado && $analiseLocal): ?>
<script>
(function(){
    const campo = document.getElementById('textoRevisadoCij');
    const btnCopiar = document.getElementById('btnCopiarRevisao');
    const btnWord = document.getElementById('btnWordRevisao');
    const btnImprimir = document.getElementById('btnImprimirRevisao');

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
p { text-indent: 1.25cm; margin: 0 0 8pt 0; }
</style>
</head>
<body><div>${conteudo}</div></body>
</html>`;

            const blob = new Blob(['\ufeff', html], {type: 'application/msword'});
            const url = URL.createObjectURL(blob);
            const link = document.createElement('a');
            link.href = url;
            link.download = 'revisao_juridica_rojex.doc';
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
<title>Revisão Jurídica — ROJEX.AI</title>
<style>
@page { size: A4 portrait; margin: 3cm 2cm 2cm 3cm; }
html, body { background: #fff; }
body {
    font-family: "Times New Roman", serif;
    font-size: 12pt;
    line-height: 1.5;
    text-align: justify;
    color: #000;
    margin: 0;
}
.documento { white-space: normal; }
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
<div class="documento">${conteudo}</div>
<div class="rodape">Documento revisado como minuta. Conferência obrigatória por advogado antes do uso externo.</div>
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
