<?php
/**
 * modules/cij/documentos.php
 * Análise de Documentos e Provas — ROJEX.AI Enterprise
 * Sprint 4.1.4 — Etapa 5
 *
 * Objetivos:
 * - Analisar documentos e provas em modo local seguro.
 * - Identificar integridade, legibilidade, lacunas e pontos de atenção.
 * - Usar IA externa quando configurada em config/ia.php.
 * - Não alterar banco de dados nesta etapa.
 */

if (!isset($conn) || !($conn instanceof mysqli)) {
    $conn = conectar();
}

$arquivoConfigIa = __DIR__ . '/../../config/ia.php';
if (is_file($arquivoConfigIa)) {
    require_once $arquivoConfigIa;
}

function cij_documentos_h($valor): string
{
    return htmlspecialchars((string)($valor ?? ''), ENT_QUOTES, 'UTF-8');
}

function cij_documentos_ia_disponivel(): bool
{
    return function_exists('sgl_ia_disponivel') && sgl_ia_disponivel();
}

function cij_documentos_chamar_ia(string $promptSistema, string $promptUsuario): array
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

function cij_documentos_normalizar(string $texto): string
{
    $texto = str_replace(["\r\n", "\r"], "\n", trim($texto));
    $texto = preg_replace('/[ \t]+/u', ' ', $texto);
    $texto = preg_replace("/\n{3,}/u", "\n\n", (string)$texto);
    return trim((string)$texto);
}

function cij_documentos_contem(string $texto, array $termos): bool
{
    $texto = mb_strtolower($texto, 'UTF-8');

    foreach ($termos as $termo) {
        if (str_contains($texto, mb_strtolower($termo, 'UTF-8'))) {
            return true;
        }
    }

    return false;
}

function cij_documentos_checklist(string $categoria): array
{
    $tipo = mb_strtolower(trim($categoria), 'UTF-8');

    $base = [
        'Identificação do documento' => ['documento', 'declaração', 'declaracao', 'contrato', 'procuração', 'procuracao', 'recibo', 'laudo', 'sentença', 'sentenca'],
        'Nome das partes/pessoas' => ['nome', 'cliente', 'requerente', 'contratante', 'outorgante', 'autor', 'réu', 'reu'],
        'Data' => ['data', '/202', 'de 20', 'ano de'],
        'Assinatura' => ['assinatura', 'assinado', 'assinam', 'oab'],
        'Conteúdo compreensível' => ['objeto', 'declara', 'atesta', 'certifica', 'requer', 'comprova', 'relata'],
    ];

    $especificos = [
        'documento pessoal' => [
            'Nome completo' => ['nome'],
            'CPF/RG' => ['cpf', 'rg'],
            'Data de nascimento' => ['nascimento', 'nascido'],
            'Órgão emissor' => ['ssp', 'órgão emissor', 'orgao emissor'],
            'Validade' => ['validade', 'válido até', 'valido ate'],
        ],
        'comprovante' => [
            'Titular' => ['titular', 'nome'],
            'Endereço/Origem' => ['endereço', 'endereco', 'emitente'],
            'Data de emissão' => ['emissão', 'emissao', 'data'],
            'Valor' => ['r$', 'valor', 'total'],
        ],
        'recibo' => [
            'Recebedor' => ['recebi', 'recebedor', 'beneficiário', 'beneficiario'],
            'Pagador' => ['pagador', 'de'],
            'Valor' => ['r$', 'valor', 'quantia'],
            'Finalidade' => ['referente', 'pagamento', 'serviço', 'servico'],
            'Data e assinatura' => ['data', 'assinatura'],
        ],
        'laudo' => [
            'Profissional responsável' => ['responsável', 'responsavel', 'médico', 'medico', 'perito', 'crm', 'crea'],
            'Objeto do laudo' => ['objeto', 'finalidade', 'exame'],
            'Metodologia' => ['metodologia', 'procedimento', 'análise', 'analise'],
            'Conclusão' => ['conclusão', 'conclusao', 'parecer'],
            'Data e assinatura' => ['data', 'assinatura'],
        ],
        'petição' => [
            'Endereçamento' => ['excelentíssimo', 'ao juízo', 'à vara', 'ao tribunal'],
            'Partes' => ['autor', 'réu', 'reu', 'requerente', 'requerido'],
            'Fatos' => ['dos fatos', 'síntese dos fatos', 'sintese dos fatos'],
            'Fundamentação' => ['do direito', 'fundamentação', 'fundamentacao'],
            'Pedidos' => ['dos pedidos', 'diante do exposto', 'requer'],
            'Assinatura/OAB' => ['oab', 'advogado', 'advogada'],
        ],
        'sentença' => [
            'Identificação do processo' => ['processo', 'autos'],
            'Relatório' => ['relatório', 'relatorio'],
            'Fundamentação' => ['fundamentação', 'fundamentacao'],
            'Dispositivo' => ['dispositivo', 'julgo', 'decido'],
            'Data/Assinatura' => ['data', 'juiz', 'juíza', 'juiza'],
        ],
        'acórdão' => [
            'Tribunal/Órgão julgador' => ['tribunal', 'turma', 'câmara', 'camara'],
            'Número do processo' => ['processo', 'autos'],
            'Ementa' => ['ementa'],
            'Relatório/Voto' => ['relatório', 'relatorio', 'voto'],
            'Resultado' => ['acordam', 'decisão', 'decisao'],
        ],
        'procuração' => [
            'Outorgante' => ['outorgante', 'nome do cliente'],
            'Outorgado' => ['outorgado', 'advogado', 'procurador'],
            'Poderes' => ['poderes', 'nomeia e constitui', 'confere poderes'],
            'Data' => ['data', '/202'],
            'Assinatura' => ['assinatura', 'nome do cliente'],
        ],
        'contrato' => [
            'Partes' => ['contratante', 'contratado', 'partes'],
            'Objeto' => ['objeto'],
            'Valor/Pagamento' => ['valor', 'pagamento'],
            'Prazo' => ['prazo', 'vigência', 'vigencia'],
            'Rescisão' => ['rescisão', 'rescisao'],
            'Assinaturas' => ['assinatura', 'testemunhas'],
        ],
        'fotografia' => [
            'Descrição da imagem' => ['fotografia', 'imagem', 'foto', 'demonstra', 'mostra', 'comprova'],
            'Relação com o caso' => ['caso', 'processo', 'prova', 'dano', 'vínculo', 'vinculo', 'pagamento', 'comunicação', 'comunicacao'],
            'Local identificado' => ['local', 'endereço', 'endereco', 'cidade', 'imóvel', 'imovel'],
            'Data ou referência temporal' => ['data', '/202', 'de 20', 'dia', 'mês', 'mes', 'ano'],
            'Origem/autoria' => ['autor', 'autoria', 'origem', 'capturada por', 'tirada por', 'enviada por'],
            'Contexto preservado' => ['contexto', 'sequência', 'sequencia', 'antes', 'depois', 'arquivo original'],
        ],
    ];

    if ($tipo === 'fotografia') {
        return $especificos['fotografia'];
    }

    return array_merge($base, $especificos[$tipo] ?? []);
}

function cij_documentos_analisar(string $texto, string $categoria, string $estadoDocumento, string $descricaoProva = ''): array
{
    $normalizado = cij_documentos_normalizar($texto);
    $categoriaNormalizada = mb_strtolower(trim($categoria), 'UTF-8');
    $descricaoNormalizada = cij_documentos_normalizar($descricaoProva);

    $textoParaAnalise = $normalizado;
    if ($categoriaNormalizada === 'fotografia' && $descricaoNormalizada !== '') {
        $textoParaAnalise .= "\n\nDESCRIÇÃO DA PROVA: " . $descricaoNormalizada;
    }

    $palavras = preg_split('/\s+/u', $textoParaAnalise, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $totalPalavras = count($palavras);
    $totalCaracteres = mb_strlen($textoParaAnalise, 'UTF-8');

    $checklistDef = cij_documentos_checklist($categoria);
    $checklist = [];
    $presentes = 0;

    foreach ($checklistDef as $nome => $termos) {
        $ok = cij_documentos_contem($textoParaAnalise, $termos);
        $checklist[] = ['nome' => $nome, 'ok' => $ok];
        if ($ok) {
            $presentes++;
        }
    }

    $integridade = (int)round(($presentes / max(count($checklist), 1)) * 100);

    $legibilidade = 100;
    $textoMinusculo = mb_strtolower($normalizado, 'UTF-8');

    if ($totalPalavras < 20) {
        $legibilidade -= 25;
    }
    if (substr_count($normalizado, '___') >= 3 || substr_count($normalizado, '[ilegível]') > 0 || substr_count($textoMinusculo, 'ilegível') > 0) {
        $legibilidade -= 25;
    }
    if (preg_match_all('/\?{3,}|#{3,}|\*{3,}/u', $normalizado) > 0) {
        $legibilidade -= 15;
    }
    if (mb_strtolower($estadoDocumento, 'UTF-8') === 'parcialmente ilegível') {
        $legibilidade -= 35;
    } elseif (mb_strtolower($estadoDocumento, 'UTF-8') === 'ilegível') {
        $legibilidade = 10;
    }

    $legibilidade = max(0, min(100, $legibilidade));

    $organizacao = 100;
    $linhas = preg_split('/\n/u', $normalizado) ?: [];
    if (count($linhas) <= 2 && $totalPalavras > 100) {
        $organizacao -= 25;
    }
    if (preg_match_all("/\n{3,}/u", $texto) > 0) {
        $organizacao -= 10;
    }
    $organizacao = max(0, min(100, $organizacao));

    $alertas = [];

    foreach ($checklist as $item) {
        if (!$item['ok']) {
            $alertas[] = 'Não foi localizado claramente o requisito: ' . $item['nome'] . '.';
        }
    }

    if ($legibilidade < 70) {
        $alertas[] = 'A legibilidade do conteúdo está abaixo do ideal. Recomenda-se obter nova cópia ou imagem em melhor qualidade.';
    }

    if ($estadoDocumento === 'Incompleto') {
        $alertas[] = 'O documento foi informado como incompleto.';
    }

    if ($categoriaNormalizada !== 'fotografia') {
        if (!cij_documentos_contem($normalizado, ['assinatura', 'assinado', 'oab', 'testemunhas'])) {
            $alertas[] = 'Não foi localizada assinatura ou identificação equivalente.';
        }

        if (!cij_documentos_contem($normalizado, ['data', '/202', 'de 20'])) {
            $alertas[] = 'Não foi localizada data de emissão, assinatura ou referência temporal.';
        }
    } else {
        if ($descricaoNormalizada === '') {
            $alertas[] = 'Fotografia: informe a descrição da prova e a relação com o caso para uma avaliação adequada.';
        }

        if (!cij_documentos_contem($textoParaAnalise, ['data', '/202', 'de 20', 'dia', 'mês', 'mes', 'ano'])) {
            $alertas[] = 'Fotografia: não foi localizada data ou referência temporal. Preserve os metadados do arquivo original.';
        }

        if (!cij_documentos_contem($textoParaAnalise, ['origem', 'autoria', 'capturada por', 'tirada por', 'enviada por'])) {
            $alertas[] = 'Fotografia: a origem ou autoria da imagem não foi claramente informada.';
        }
    }

    $documentacao = (int)round(($integridade * 0.50) + ($legibilidade * 0.30) + ($organizacao * 0.20));

    if ($documentacao >= 85) {
        $classificacao = 'Documentação consistente';
        $tipoBadge = 'success';
    } elseif ($documentacao >= 65) {
        $classificacao = 'Documentação com pontos de atenção';
        $tipoBadge = 'warning';
    } else {
        $classificacao = 'Documentação insuficiente ou frágil';
        $tipoBadge = 'danger';
    }

    if (empty($alertas)) {
        $alertas[] = 'Nenhum alerta relevante foi identificado pelo modo local.';
    }

    return [
        'texto_normalizado' => $normalizado,
        'checklist' => $checklist,
        'integridade' => $integridade,
        'legibilidade' => $legibilidade,
        'organizacao' => $organizacao,
        'documentacao' => $documentacao,
        'classificacao' => $classificacao,
        'tipo_badge' => $tipoBadge,
        'alertas' => $alertas,
        'total_palavras' => $totalPalavras,
        'total_caracteres' => $totalCaracteres,
    ];
}

function cij_documentos_prompt_ia(
    string $texto,
    string $categoria,
    string $descricaoProva,
    string $estadoDocumento
): string {
    return "Analise o documento ou prova abaixo em português do Brasil.\n\n"
        . "CATEGORIA: " . ($categoria ?: '[não informada]') . "\n"
        . "ESTADO INFORMADO: " . ($estadoDocumento ?: '[não informado]') . "\n"
        . "DESCRIÇÃO/RELAÇÃO COM O CASO: " . ($descricaoProva ?: '[não informada]') . "\n\n"
        . "REGRAS OBRIGATÓRIAS:\n"
        . "1. Não invente fatos, nomes, datas, valores, assinaturas ou conteúdo ausente.\n"
        . "2. Identifique integridade, legibilidade, campos ausentes e relevância probatória.\n"
        . "3. Sinalize divergências, lacunas e riscos de uso.\n"
        . "4. Não declare autenticidade definitiva.\n"
        . "5. Entregue um RELATÓRIO DOCUMENTAL com: identificação, integridade, legibilidade, pontos fortes, lacunas, riscos e recomendações.\n\n"
        . "DOCUMENTO/PROVA:\n"
        . $texto;
}

$categorias = [
    'Contrato',
    'Procuração',
    'Documento Pessoal',
    'Comprovante',
    'Recibo',
    'Laudo',
    'Fotografia',
    'Petição',
    'Sentença',
    'Acórdão',
    'Outro',
];

$estadosDocumento = [
    'Legível',
    'Parcialmente ilegível',
    'Ilegível',
    'Incompleto',
    'Cópia simples',
    'Original digitalizado',
];

$categoria = trim((string)($_POST['categoria_documento'] ?? ''));
$estadoDocumento = trim((string)($_POST['estado_documento'] ?? 'Legível'));
$descricaoProva = trim((string)($_POST['descricao_prova'] ?? ''));
$textoDocumento = trim((string)($_POST['texto_documento'] ?? ''));
$usarOcrFotografia = isset($_POST['usar_ocr_fotografia']) && $_POST['usar_ocr_fotografia'] === '1';

$analisado = false;
$analise = null;
$relatorio = '';
$modoResposta = '';
$erroIa = '';
$mensagemValidacao = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao_documentos'] ?? '') === 'analisar_documento') {
    $ehFotografia = mb_strtolower($categoria, 'UTF-8') === 'fotografia';

    if ($categoria === '') {
        $mensagemValidacao = 'Selecione a categoria do documento.';
    } elseif ($ehFotografia && mb_strlen($descricaoProva, 'UTF-8') < 30) {
        $mensagemValidacao = 'Para fotografia, descreva a imagem e sua relação com o caso com pelo menos 30 caracteres.';
    } elseif (!$ehFotografia && $textoDocumento === '') {
        $mensagemValidacao = 'Cole, digite ou extraia o conteúdo do documento ou prova.';
    } elseif (!$ehFotografia && mb_strlen($textoDocumento, 'UTF-8') < 40) {
        $mensagemValidacao = 'O conteúdo deve possuir pelo menos 40 caracteres para análise.';
    } else {
        $analisado = true;

        $textoOriginalDocumento = $textoDocumento;

        if ($ehFotografia && !$usarOcrFotografia) {
            $textoDocumento = 'Fotografia avaliada com base na descrição fornecida. O texto do OCR foi desconsiderado por não ser necessário para esta prova visual.';
        } elseif ($ehFotografia && trim($textoDocumento) === '') {
            $textoDocumento = 'Fotografia sem texto legível. Avaliação baseada na descrição informada pelo usuário.';
        }

        $analise = cij_documentos_analisar($textoDocumento, $categoria, $estadoDocumento, $descricaoProva);

        $promptSistema = 'Você é o Analista de Documentos e Provas do ROJEX.AI Enterprise. Responda em português do Brasil. Não invente fatos, autenticidade, assinaturas ou dados ausentes. Toda análise é apoio técnico para revisão do advogado.';
        $promptUsuario = cij_documentos_prompt_ia(
            $textoDocumento,
            $categoria,
            $descricaoProva,
            $estadoDocumento
        );

        $retornoIa = cij_documentos_chamar_ia($promptSistema, $promptUsuario);

        if (($retornoIa['ok'] ?? false) === true) {
            $relatorio = trim((string)($retornoIa['texto'] ?? ''));
            $modoResposta = 'Análise por IA';
        } else {
            $erroIa = trim((string)($retornoIa['erro'] ?? 'IA externa indisponível ou não configurada.'));

            $linhas = [];
            $linhas[] = 'RELATÓRIO DE ANÁLISE DOCUMENTAL E PROBATÓRIA';
            $linhas[] = '';
            $linhas[] = 'Categoria: ' . $categoria;
            $linhas[] = 'Estado informado: ' . $estadoDocumento;
            $linhas[] = 'Descrição/relação com o caso: ' . ($descricaoProva ?: '[não informada]');
            $linhas[] = '';
            $linhas[] = 'Documentação: ' . (int)$analise['documentacao'] . '%';
            $linhas[] = 'Integridade: ' . (int)$analise['integridade'] . '%';
            $linhas[] = 'Legibilidade: ' . (int)$analise['legibilidade'] . '%';
            $linhas[] = 'Organização: ' . (int)$analise['organizacao'] . '%';
            $linhas[] = '';
            $linhas[] = 'PONTOS DE ATENÇÃO';
            foreach ($analise['alertas'] as $alerta) {
                $linhas[] = '- ' . $alerta;
            }
            $linhas[] = '';
            $linhas[] = 'RECOMENDAÇÃO';
            $linhas[] = 'Confirmar a origem, integridade, pertinência, legibilidade e validade jurídica do documento antes de juntada, protocolo ou uso como prova.';
            $linhas[] = '';
            $linhas[] = 'CONTEÚDO ANALISADO';

            if ($ehFotografia && !$usarOcrFotografia) {
                $linhas[] = 'Fotografia avaliada pela descrição e contexto informados.';
                $linhas[] = 'O texto extraído por OCR foi desconsiderado porque a imagem foi classificada como prova visual sem texto relevante.';
            } else {
                $linhas[] = $analise['texto_normalizado'];
            }

            $relatorio = implode("\n", $linhas);
            $modoResposta = 'Análise local segura';
        }

        if (function_exists('sgl_registrar_log')) {
            sgl_registrar_log(
                $conn,
                'ANALISE_DOCUMENTO_CIJ',
                'cij_documentos',
                '0',
                ($categoria ?: 'Documento') . ' - ' . $modoResposta
            );
        }
    }
}
?>

<div class="container-fluid">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
        <div>
            <h2 class="mb-1 fw-bold text-primary">
                <i class="bi bi-folder2-open me-2"></i>Análise de Documentos e Provas
            </h2>
            <p class="text-muted mb-0">
                Avaliação de integridade, legibilidade, organização, lacunas e relevância documental.
            </p>
        </div>
        <a href="?mod=cij" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i>Voltar ao CIJ
        </a>
    </div>

    <div class="alert <?= cij_documentos_ia_disponivel() ? 'alert-success' : 'alert-warning' ?> border-0 shadow-sm">
        <?php if (cij_documentos_ia_disponivel()): ?>
            <strong>IA conectada:</strong> a análise documental utilizará a API configurada no ROJEX.AI.
        <?php else: ?>
            <strong>Modo análise local:</strong> a API externa ainda não está configurada. O sistema fará avaliação estrutural sem inventar conteúdo ou autenticidade.
        <?php endif; ?>
    </div>

    <?php if ($mensagemValidacao !== ''): ?>
        <div class="alert alert-danger border-0 shadow-sm">
            <i class="bi bi-exclamation-triangle me-1"></i><?= cij_documentos_h($mensagemValidacao) ?>
        </div>
    <?php endif; ?>

    <?php if ($erroIa !== '' && $analisado): ?>
        <div class="alert alert-info border-0 shadow-sm">
            <strong>Observação técnica:</strong> <?= cij_documentos_h($erroIa) ?>
        </div>
    <?php endif; ?>

    <div class="row g-4">
        <div class="col-xl-5">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-dark text-white">
                    <i class="bi bi-file-earmark-text me-2"></i>Documento ou prova
                </div>
                <div class="card-body">
                    <form method="POST" enctype="multipart/form-data" class="row g-3">
                        <input type="hidden" name="acao_documentos" value="analisar_documento">

                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Categoria</label>
                            <select name="categoria_documento" class="form-select" required>
                                <option value="">Selecione...</option>
                                <?php foreach ($categorias as $opcao): ?>
                                    <option value="<?= cij_documentos_h($opcao) ?>" <?= $categoria === $opcao ? 'selected' : '' ?>>
                                        <?= cij_documentos_h($opcao) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Estado do documento</label>
                            <select name="estado_documento" class="form-select">
                                <?php foreach ($estadosDocumento as $opcao): ?>
                                    <option value="<?= cij_documentos_h($opcao) ?>" <?= $estadoDocumento === $opcao ? 'selected' : '' ?>>
                                        <?= cij_documentos_h($opcao) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-12">
                            <label class="form-label fw-semibold">Descrição da prova/relação com o caso</label>
                            <input
                                type="text"
                                name="descricao_prova"
                                class="form-control"
                                value="<?= cij_documentos_h($descricaoProva) ?>"
                                placeholder="Ex.: comprova pagamento, vínculo, endereço, dano ou comunicação entre as partes.">
                        </div>

                        <div class="col-12">
                            <label class="form-label fw-semibold">Arquivo para leitura/OCR</label>
                            <input
                                type="file"
                                id="arquivoDocumentoCij"
                                class="form-control"
                                accept=".txt,.pdf,.png,.jpg,.jpeg,image/png,image/jpeg,application/pdf,text/plain">
                            <div class="form-text">
                                Formatos aceitos: TXT, PDF, PNG, JPG e JPEG. O processamento ocorre no navegador e o arquivo não é salvo no banco.
                            </div>
                        </div>

                        <div class="col-12">
                            <div class="d-flex flex-wrap gap-2">
                                <button type="button" class="btn btn-outline-primary" id="btnCarregarArquivoCij">
                                    <i class="bi bi-file-earmark-arrow-up me-1"></i>Ler PDF/TXT
                                </button>
                                <button type="button" class="btn btn-outline-success" id="btnOcrDocumentoCij">
                                    <i class="bi bi-fonts me-1"></i>OCR da imagem
                                </button>
                                <button type="button" class="btn btn-outline-secondary" id="btnLimparArquivoCij">
                                    <i class="bi bi-x-circle me-1"></i>Limpar
                                </button>
                            </div>
                            <div id="statusOcrDocumentoCij" class="small text-muted mt-2">
                                Nenhum arquivo selecionado.
                            </div>
                            <div id="orientacaoFotografiaCij" class="alert alert-info py-2 px-3 mt-2 mb-0 d-none">
                                <strong>Fotografia sem texto:</strong> não use OCR. Descreva o dano, local, data, origem e relação com o caso; depois clique em <strong>Analisar documento</strong>.
                            </div>
                            <div id="previewImagemCij" class="mt-3 d-none">
                                <img id="imagemPreviewCij" alt="Pré-visualização do arquivo" class="img-fluid rounded border" style="max-height: 320px;">
                            </div>

                            <div id="opcaoOcrFotografiaCij" class="form-check mt-3 d-none">
                                <input
                                    class="form-check-input"
                                    type="checkbox"
                                    value="1"
                                    id="usarOcrFotografiaCij"
                                    name="usar_ocr_fotografia"
                                    <?= $usarOcrFotografia ? 'checked' : '' ?>>
                                <label class="form-check-label" for="usarOcrFotografiaCij">
                                    A fotografia contém texto relevante; incluir o OCR na análise.
                                </label>
                                <div class="form-text">
                                    Deixe desmarcado para fotos de rachaduras, danos, objetos, acidentes ou ambientes.
                                </div>
                            </div>

                            <div class="progress mt-2 d-none" id="progressoOcrDocumentoCij" style="height: 8px;">
                                <div class="progress-bar" id="barraOcrDocumentoCij" role="progressbar" style="width: 0%;"></div>
                            </div>
                        </div>

                        <div class="col-12">
                            <label class="form-label fw-semibold">Conteúdo do documento</label>
                            <textarea
                                name="texto_documento"
                                id="textoDocumentoCij"
                                class="form-control"
                                rows="24"
                                placeholder="Cole, digite ou extraia o texto do documento/prova. Para fotografia sem texto, este campo pode ficar vazio."><?= cij_documentos_h($textoDocumento) ?></textarea>
                            <div class="form-text">
                                TXT é lido diretamente. PDFs com texto são extraídos no navegador. Imagens usam OCR. PDFs digitalizados poderão exigir conversão para imagem em evolução futura.
                            </div>
                        </div>

                        <div class="col-12 d-grid">
                            <button class="btn btn-primary btn-lg">
                                <i class="bi bi-search me-1"></i>Analisar documento
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
                            <i class="bi bi-folder2-open fs-1 d-block mb-3 opacity-25"></i>
                            <h5 class="fw-bold">Nenhum documento analisado</h5>
                            <p class="mb-0">Informe o conteúdo ao lado e clique em analisar documento.</p>
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <div class="card border-0 shadow-sm mb-4">
                    <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <span><i class="bi bi-speedometer2 me-2"></i>Diagnóstico documental</span>
                        <span class="badge bg-<?= cij_documentos_h($analise['tipo_badge']) ?>">
                            <?= cij_documentos_h($modoResposta) ?>
                        </span>
                    </div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-md-4 text-center">
                                <div class="display-4 fw-bold text-<?= cij_documentos_h($analise['tipo_badge']) ?>">
                                    <?= (int)$analise['documentacao'] ?>%
                                </div>
                                <div class="fw-semibold"><?= cij_documentos_h($analise['classificacao']) ?></div>
                            </div>

                            <div class="col-md-8">
                                <div class="row g-3">
                                    <div class="col-sm-6">
                                        <div class="border rounded p-3 h-100">
                                            <small class="text-muted d-block">Integridade</small>
                                            <strong><?= (int)$analise['integridade'] ?>%</strong>
                                        </div>
                                    </div>
                                    <div class="col-sm-6">
                                        <div class="border rounded p-3 h-100">
                                            <small class="text-muted d-block">Legibilidade</small>
                                            <strong><?= (int)$analise['legibilidade'] ?>%</strong>
                                        </div>
                                    </div>
                                    <div class="col-sm-6">
                                        <div class="border rounded p-3 h-100">
                                            <small class="text-muted d-block">Organização</small>
                                            <strong><?= (int)$analise['organizacao'] ?>%</strong>
                                        </div>
                                    </div>
                                    <div class="col-sm-6">
                                        <div class="border rounded p-3 h-100">
                                            <small class="text-muted d-block">Palavras</small>
                                            <strong><?= (int)$analise['total_palavras'] ?></strong>
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
                                <i class="bi bi-list-check me-2"></i>Checklist documental
                            </div>
                            <div class="list-group list-group-flush">
                                <?php foreach ($analise['checklist'] as $item): ?>
                                    <div class="list-group-item d-flex justify-content-between align-items-center">
                                        <span><?= cij_documentos_h($item['nome']) ?></span>
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
                                    <?php foreach ($analise['alertas'] as $alerta): ?>
                                        <li class="mb-2"><?= cij_documentos_h($alerta) ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <span><i class="bi bi-file-earmark-bar-graph me-2"></i>Relatório editável</span>
                        <span class="badge bg-primary"><?= cij_documentos_h($modoResposta) ?></span>
                    </div>
                    <div class="card-body">
                        <div class="d-flex flex-wrap gap-2 mb-3">
                            <button type="button" class="btn btn-outline-primary btn-sm" id="btnCopiarDocumento">
                                <i class="bi bi-clipboard me-1"></i>Copiar
                            </button>
                            <button type="button" class="btn btn-outline-success btn-sm" id="btnWordDocumento">
                                <i class="bi bi-file-earmark-word me-1"></i>Baixar Word
                            </button>
                            <button type="button" class="btn btn-outline-secondary btn-sm" id="btnImprimirDocumento">
                                <i class="bi bi-printer me-1"></i>Imprimir / PDF
                            </button>
                            <a href="?mod=cij&ferramenta=documentos" class="btn btn-outline-dark btn-sm">
                                <i class="bi bi-plus-circle me-1"></i>Nova análise
                            </a>
                        </div>

                        <textarea id="relatorioDocumentoCij" class="form-control" rows="25"><?= cij_documentos_h($relatorio) ?></textarea>

                        <div class="alert alert-warning mt-3 mb-0">
                            <i class="bi bi-exclamation-triangle me-1"></i>
                            A análise não confirma autenticidade. A validação e a responsabilidade final permanecem com o advogado.
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
(function(){
    const inputArquivo = document.getElementById('arquivoDocumentoCij');
    const btnCarregar = document.getElementById('btnCarregarArquivoCij');
    const btnOcr = document.getElementById('btnOcrDocumentoCij');
    const btnLimpar = document.getElementById('btnLimparArquivoCij');
    const campoTexto = document.getElementById('textoDocumentoCij');
    const status = document.getElementById('statusOcrDocumentoCij');
    const progresso = document.getElementById('progressoOcrDocumentoCij');
    const barra = document.getElementById('barraOcrDocumentoCij');
    const categoria = document.querySelector('select[name="categoria_documento"]');
    const orientacaoFoto = document.getElementById('orientacaoFotografiaCij');
    const opcaoOcrFoto = document.getElementById('opcaoOcrFotografiaCij');
    const usarOcrFoto = document.getElementById('usarOcrFotografiaCij');
    const preview = document.getElementById('previewImagemCij');
    const imagemPreview = document.getElementById('imagemPreviewCij');

    if (!inputArquivo || !campoTexto) return;

    function atualizarOrientacaoCategoria() {
        const ehFoto = (categoria?.value || '').toLowerCase() === 'fotografia';

        if (orientacaoFoto) {
            orientacaoFoto.classList.toggle('d-none', !ehFoto);
        }

        if (opcaoOcrFoto) {
            opcaoOcrFoto.classList.toggle('d-none', !ehFoto);
        }

        if (!ehFoto && usarOcrFoto) {
            usarOcrFoto.checked = false;
        }
    }

    atualizarOrientacaoCategoria();
    categoria?.addEventListener('change', atualizarOrientacaoCategoria);

    const MAX_BYTES = 12 * 1024 * 1024;

    function setStatus(mensagem, classe = 'text-muted') {
        status.className = 'small mt-2 ' + classe;
        status.textContent = mensagem;
    }

    function setProgresso(percentual, visivel = true) {
        progresso.classList.toggle('d-none', !visivel);
        barra.style.width = Math.max(0, Math.min(100, percentual)) + '%';
    }

    function validarArquivo(arquivo) {
        if (!arquivo) {
            setStatus('Selecione um arquivo primeiro.', 'text-warning');
            return false;
        }

        if (arquivo.size > MAX_BYTES) {
            setStatus('O arquivo excede o limite de 12 MB.', 'text-danger');
            return false;
        }

        const nome = arquivo.name.toLowerCase();
        const permitido = /\.(txt|pdf|png|jpe?g)$/i.test(nome);
        if (!permitido) {
            setStatus('Formato não permitido. Use TXT, PDF, PNG, JPG ou JPEG.', 'text-danger');
            return false;
        }

        return true;
    }

    async function carregarScript(url, id) {
        if (document.getElementById(id)) return;

        await new Promise((resolve, reject) => {
            const script = document.createElement('script');
            script.id = id;
            script.src = url;
            script.onload = resolve;
            script.onerror = () => reject(new Error('Falha ao carregar biblioteca externa.'));
            document.head.appendChild(script);
        });
    }

    async function lerTxt(arquivo) {
        const texto = await arquivo.text();
        campoTexto.value = texto;
        setStatus('Arquivo TXT carregado com sucesso.', 'text-success');
    }

    async function extrairPdf(arquivo) {
        setStatus('Carregando leitor de PDF...', 'text-primary');
        setProgresso(10);

        await carregarScript(
            'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/4.10.38/pdf.min.mjs',
            'pdfjs-cij-module'
        ).catch(() => {});

        // PDF.js v4 é módulo; para compatibilidade ampla, usa a versão UMD 3.x.
        if (!window.pdfjsLib) {
            await carregarScript(
                'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js',
                'pdfjs-cij'
            );
        }

        window.pdfjsLib.GlobalWorkerOptions.workerSrc =
            'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';

        const dados = new Uint8Array(await arquivo.arrayBuffer());
        const pdf = await window.pdfjsLib.getDocument({data: dados}).promise;
        const partes = [];

        for (let pagina = 1; pagina <= pdf.numPages; pagina++) {
            setStatus(`Extraindo texto do PDF: página ${pagina} de ${pdf.numPages}...`, 'text-primary');
            setProgresso(10 + Math.round((pagina / pdf.numPages) * 80));

            const page = await pdf.getPage(pagina);
            const conteudo = await page.getTextContent();
            const textoPagina = conteudo.items
                .map(item => item.str || '')
                .join(' ')
                .replace(/\s+/g, ' ')
                .trim();

            if (textoPagina) {
                partes.push(`--- PÁGINA ${pagina} ---\n${textoPagina}`);
            }
        }

        const resultado = partes.join('\n\n').trim();

        if (!resultado) {
            throw new Error('O PDF não possui texto selecionável. Converta as páginas em imagem para usar OCR.');
        }

        campoTexto.value = resultado;
        setProgresso(100);
        setStatus('Texto do PDF extraído com sucesso.', 'text-success');
    }

    async function prepararImagemParaOcr(arquivo) {
        const bitmap = await createImageBitmap(arquivo);
        const escala = Math.max(2, Math.min(3, 2200 / Math.max(bitmap.width, bitmap.height)));

        const canvas = document.createElement('canvas');
        canvas.width = Math.round(bitmap.width * escala);
        canvas.height = Math.round(bitmap.height * escala);

        const ctx = canvas.getContext('2d', {willReadFrequently: true});
        ctx.imageSmoothingEnabled = true;
        ctx.imageSmoothingQuality = 'high';
        ctx.drawImage(bitmap, 0, 0, canvas.width, canvas.height);

        const imagem = ctx.getImageData(0, 0, canvas.width, canvas.height);
        const dados = imagem.data;

        let somaLuminosidade = 0;
        for (let i = 0; i < dados.length; i += 4) {
            somaLuminosidade += (dados[i] * 0.299) + (dados[i + 1] * 0.587) + (dados[i + 2] * 0.114);
        }

        const media = somaLuminosidade / (dados.length / 4);
        const fundoEscuro = media < 125;

        for (let i = 0; i < dados.length; i += 4) {
            let cinza = (dados[i] * 0.299) + (dados[i + 1] * 0.587) + (dados[i + 2] * 0.114);

            // Imagens jurídicas e posts costumam ter texto claro sobre fundo escuro.
            // A inversão melhora a leitura do Tesseract nesses casos.
            if (fundoEscuro) {
                cinza = 255 - cinza;
            }

            // Aumenta o contraste sem destruir totalmente as bordas das letras.
            cinza = Math.max(0, Math.min(255, ((cinza - 128) * 1.65) + 128));

            // Binarização leve para reduzir sombras, fotos e elementos decorativos.
            const valor = cinza > 165 ? 255 : (cinza < 80 ? 0 : cinza);

            dados[i] = valor;
            dados[i + 1] = valor;
            dados[i + 2] = valor;
        }

        ctx.putImageData(imagem, 0, 0);
        return canvas;
    }

    async function executarOcrImagem(arquivo) {
        setStatus('Preparando a imagem para melhorar a leitura...', 'text-primary');
        setProgresso(5);

        await carregarScript(
            'https://cdn.jsdelivr.net/npm/tesseract.js@5/dist/tesseract.min.js',
            'tesseract-cij'
        );

        if (!window.Tesseract) {
            throw new Error('Mecanismo OCR indisponível.');
        }

        const imagemPreparada = await prepararImagemParaOcr(arquivo);
        setStatus('Imagem tratada. Iniciando OCR em português...', 'text-primary');
        setProgresso(12);

        const worker = await window.Tesseract.createWorker('por', 1, {
            logger: mensagem => {
                if (mensagem.status === 'recognizing text') {
                    const percentual = 12 + Math.round((mensagem.progress || 0) * 85);
                    setProgresso(percentual);
                    setStatus(`OCR em andamento: ${Math.round((mensagem.progress || 0) * 100)}%`, 'text-primary');
                }
            }
        });

        await worker.setParameters({
            tessedit_pageseg_mode: '11',
            preserve_interword_spaces: '1',
            user_defined_dpi: '300'
        });

        const resultado = await worker.recognize(imagemPreparada);
        await worker.terminate();

        const texto = (resultado?.data?.text || '')
            .replace(/[ \t]+\n/g, '\n')
            .replace(/\n{3,}/g, '\n\n')
            .trim();

        const confianca = Number(resultado?.data?.confidence || 0);

        if (!texto) {
            throw new Error('O OCR não conseguiu identificar texto na imagem.');
        }

        campoTexto.value = texto;
        setProgresso(100);

        if (confianca < 55) {
            setStatus(
                `OCR concluído, mas com baixa confiança (${confianca.toFixed(0)}%). Revise o texto antes de analisar.`,
                'text-warning'
            );
        } else {
            setStatus(
                `OCR concluído com confiança de ${confianca.toFixed(0)}%. Revise o texto antes de analisar.`,
                'text-success'
            );
        }
    }

    inputArquivo.addEventListener('change', function(){
        const arquivo = inputArquivo.files[0];

        if (preview) preview.classList.add('d-none');
        if (imagemPreview) imagemPreview.removeAttribute('src');

        if (!arquivo) {
            setStatus('Nenhum arquivo selecionado.');
            return;
        }

        setStatus(`${arquivo.name} — ${(arquivo.size / 1024).toFixed(1)} KB`, 'text-primary');

        if (/\.(png|jpe?g)$/i.test(arquivo.name) && imagemPreview && preview) {
            const url = URL.createObjectURL(arquivo);
            imagemPreview.src = url;
            imagemPreview.onload = () => URL.revokeObjectURL(url);
            preview.classList.remove('d-none');
        }
    });

    if (btnCarregar) {
        btnCarregar.addEventListener('click', async function(){
            const arquivo = inputArquivo.files[0];
            if (!validarArquivo(arquivo)) return;

            const nome = arquivo.name.toLowerCase();

            try {
                setProgresso(0, false);

                if (nome.endsWith('.txt')) {
                    await lerTxt(arquivo);
                } else if (nome.endsWith('.pdf')) {
                    await extrairPdf(arquivo);
                } else {
                    setStatus('Imagem selecionada. Use “OCR da imagem” somente se houver texto visível. Para foto de dano ou objeto, preencha a descrição e analise sem OCR.', 'text-primary');
                }
            } catch (erro) {
                setProgresso(0, false);
                setStatus(erro.message || 'Não foi possível carregar o arquivo.', 'text-danger');
            }
        });
    }

    if (btnOcr) {
        btnOcr.addEventListener('click', async function(){
            const arquivo = inputArquivo.files[0];
            if (!validarArquivo(arquivo)) return;

            const nome = arquivo.name.toLowerCase();

            try {
                if (nome.endsWith('.txt')) {
                    await lerTxt(arquivo);
                    return;
                }

                if (nome.endsWith('.pdf')) {
                    await extrairPdf(arquivo);
                    return;
                }

                await executarOcrImagem(arquivo);

                if ((categoria?.value || '').toLowerCase() === 'fotografia' && usarOcrFoto) {
                    usarOcrFoto.checked = true;
                }
            } catch (erro) {
                setProgresso(0, false);
                setStatus(erro.message || 'Falha ao extrair o texto.', 'text-danger');
            }
        });
    }

    if (btnLimpar) {
        btnLimpar.addEventListener('click', function(){
            inputArquivo.value = '';
            campoTexto.value = '';
            if (preview) preview.classList.add('d-none');
            if (imagemPreview) imagemPreview.removeAttribute('src');
            if (usarOcrFoto) usarOcrFoto.checked = false;
            setProgresso(0, false);
            setStatus('Nenhum arquivo selecionado.');
        });
    }
})();
</script>

<?php if ($analisado && $analise): ?>
<script>
(function(){
    const campo = document.getElementById('relatorioDocumentoCij');
    const btnCopiar = document.getElementById('btnCopiarDocumento');
    const btnWord = document.getElementById('btnWordDocumento');
    const btnImprimir = document.getElementById('btnImprimirDocumento');

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
            link.download = 'analise_documental_rojex.doc';
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
<title>Análise Documental — ROJEX.AI</title>
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
<div class="rodape">Relatório de apoio técnico. Verificação obrigatória por advogado antes do uso externo.</div>
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
