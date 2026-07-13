<?php
/**
 * modules/cij/pesquisa.php
 * Pesquisa Jurídica — ROJEX.AI Enterprise
 * Sprint 4.1.4 — Etapa 6
 *
 * Objetivos:
 * - Estruturar pesquisas por tema, área, palavras-chave e tipo de fonte.
 * - Operar em modo local seguro, reutilizando config/base_conhecimento.php.
 * - Usar IA externa quando configurada em config/ia.php.
 * - Gerar relatório editável, copiável e exportável.
 * - Não alterar banco de dados nesta etapa.
 */

if (!isset($conn) || !($conn instanceof mysqli)) {
    $conn = conectar();
}

$arquivoConfigIa = __DIR__ . '/../../config/ia.php';
if (is_file($arquivoConfigIa)) {
    require_once $arquivoConfigIa;
}

$variaveisAntesBase = array_keys(get_defined_vars());
$arquivoBaseConhecimento = __DIR__ . '/../../config/base_conhecimento.php';
if (is_file($arquivoBaseConhecimento)) {
    require_once $arquivoBaseConhecimento;
}
$variaveisDepoisBase = get_defined_vars();

function cij_pesquisa_h($valor): string
{
    return htmlspecialchars((string)($valor ?? ''), ENT_QUOTES, 'UTF-8');
}

function cij_pesquisa_normalizar(string $texto): string
{
    $texto = str_replace(["\r\n", "\r"], "\n", trim($texto));
    $texto = preg_replace('/[ \t]+/u', ' ', $texto);
    $texto = preg_replace("/\n{3,}/u", "\n\n", (string)$texto);
    return trim((string)$texto);
}

function cij_pesquisa_texto_busca(string $texto): string
{
    $texto = mb_strtolower(cij_pesquisa_normalizar($texto), 'UTF-8');
    $convertido = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $texto);
    return is_string($convertido) ? $convertido : $texto;
}

function cij_pesquisa_ia_disponivel(): bool
{
    return function_exists('sgl_ia_disponivel') && sgl_ia_disponivel();
}

function cij_pesquisa_chamar_ia(string $promptSistema, string $promptUsuario): array
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

function cij_pesquisa_extrair_strings($valor, string $caminho = '', int $nivel = 0): array
{
    if ($nivel > 6) {
        return [];
    }

    $itens = [];

    if (is_string($valor)) {
        $texto = cij_pesquisa_normalizar($valor);
        if (mb_strlen($texto, 'UTF-8') >= 20) {
            $itens[] = [
                'titulo' => $caminho !== '' ? $caminho : 'Base de conhecimento',
                'conteudo' => $texto,
            ];
        }
        return $itens;
    }

    if (!is_array($valor)) {
        return [];
    }

    foreach ($valor as $chave => $conteudo) {
        $rotulo = is_string($chave) ? trim($chave) : '';
        $novoCaminho = $caminho;
        if ($rotulo !== '') {
            $novoCaminho = $caminho !== '' ? $caminho . ' — ' . $rotulo : $rotulo;
        }

        if (is_array($conteudo)) {
            $tituloDireto = '';
            foreach (['titulo', 'tema', 'nome', 'assunto', 'categoria'] as $campoTitulo) {
                if (isset($conteudo[$campoTitulo]) && is_scalar($conteudo[$campoTitulo])) {
                    $tituloDireto = trim((string)$conteudo[$campoTitulo]);
                    break;
                }
            }

            $textoDireto = '';
            foreach (['conteudo', 'texto', 'descricao', 'resumo', 'fundamento', 'orientacao', 'resposta'] as $campoTexto) {
                if (isset($conteudo[$campoTexto]) && is_scalar($conteudo[$campoTexto])) {
                    $textoDireto .= ($textoDireto !== '' ? "\n" : '') . trim((string)$conteudo[$campoTexto]);
                }
            }

            if (mb_strlen($textoDireto, 'UTF-8') >= 20) {
                $itens[] = [
                    'titulo' => $tituloDireto !== '' ? $tituloDireto : ($novoCaminho ?: 'Base de conhecimento'),
                    'conteudo' => cij_pesquisa_normalizar($textoDireto),
                ];
            }

            $itens = array_merge($itens, cij_pesquisa_extrair_strings($conteudo, $novoCaminho, $nivel + 1));
        } else {
            $itens = array_merge($itens, cij_pesquisa_extrair_strings($conteudo, $novoCaminho, $nivel + 1));
        }
    }

    return $itens;
}

function cij_pesquisa_base_local(array $variaveisAntes, array $variaveisDepois): array
{
    $ignorar = [
        'GLOBALS', '_GET', '_POST', '_COOKIE', '_FILES', '_SERVER', '_ENV', '_REQUEST', '_SESSION',
        'conn', 'arquivoConfigIa', 'arquivoBaseConhecimento', 'variaveisAntesBase', 'variaveisDepoisBase',
    ];

    $novas = array_diff(array_keys($variaveisDepois), $variaveisAntes);
    $candidatas = $novas;

    // Se a base já tiver sido carregada anteriormente por require_once,
    // também considera variáveis com nomes relacionados à base jurídica.
    foreach (array_keys($variaveisDepois) as $nomeVariavel) {
        if (preg_match('/(base|conhecimento|jurid|cij|tema|fundamento|tese)/iu', (string)$nomeVariavel)) {
            $candidatas[] = $nomeVariavel;
        }
    }

    $candidatas = array_values(array_unique($candidatas));
    $itens = [];

    foreach ($candidatas as $nome) {
        if (in_array($nome, $ignorar, true) || !array_key_exists($nome, $variaveisDepois)) {
            continue;
        }

        $valor = $variaveisDepois[$nome];
        if (is_array($valor) || is_string($valor)) {
            $itens = array_merge($itens, cij_pesquisa_extrair_strings($valor, (string)$nome));
        }
    }

    $unicos = [];
    foreach ($itens as $item) {
        $chave = md5(($item['titulo'] ?? '') . '|' . ($item['conteudo'] ?? ''));
        $unicos[$chave] = $item;
    }

    return array_values($unicos);
}

function cij_pesquisa_termos(string $tema, string $palavrasChave, string $area): array
{
    $texto = trim($tema . ' ' . $palavrasChave . ' ' . $area);
    $partes = preg_split('/[\s,;|\/]+/u', cij_pesquisa_texto_busca($texto), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $stopwords = ['a', 'o', 'as', 'os', 'de', 'da', 'do', 'das', 'dos', 'e', 'em', 'no', 'na', 'nos', 'nas', 'para', 'por', 'com', 'sem', 'um', 'uma', 'direito'];

    $termos = [];
    foreach ($partes as $parte) {
        $parte = trim($parte);
        if (mb_strlen($parte, 'UTF-8') < 3 || in_array($parte, $stopwords, true)) {
            continue;
        }
        $termos[$parte] = true;
    }

    return array_keys($termos);
}

function cij_pesquisa_buscar_base(array $base, array $termos, int $limite = 8): array
{
    if (empty($base) || empty($termos)) {
        return [];
    }

    $resultados = [];
    foreach ($base as $item) {
        $busca = cij_pesquisa_texto_busca(($item['titulo'] ?? '') . ' ' . ($item['conteudo'] ?? ''));
        $pontuacao = 0;
        $localizados = [];

        foreach ($termos as $termo) {
            if (str_contains($busca, $termo)) {
                $pontuacao += substr_count($busca, $termo) + 2;
                $localizados[] = $termo;
            }
        }

        if ($pontuacao > 0) {
            $item['pontuacao'] = $pontuacao;
            $item['termos'] = array_values(array_unique($localizados));
            $resultados[] = $item;
        }
    }

    usort($resultados, static function (array $a, array $b): int {
        return ($b['pontuacao'] ?? 0) <=> ($a['pontuacao'] ?? 0);
    });

    return array_slice($resultados, 0, $limite);
}

function cij_pesquisa_linhas_investigacao(string $area, string $tema, array $fontes): array
{
    $linhas = [
        'Delimitar os fatos juridicamente relevantes, os sujeitos envolvidos, o pedido e o período dos acontecimentos.',
        'Separar fatos comprovados, fatos controvertidos e informações que ainda dependem de documento ou testemunho.',
        'Identificar a legislação vigente aplicável e conferir alterações, revogações, vigência e normas especiais.',
        'Pesquisar entendimentos jurisprudenciais favoráveis e desfavoráveis, priorizando tribunais competentes e decisões recentes.',
        'Comparar os elementos do caso com os requisitos legais, precedentes e distribuição do ônus da prova.',
        'Registrar a fonte oficial, o órgão, o número, a data de julgamento/publicação e o endereço de consulta de cada referência.',
    ];

    $areaNormalizada = cij_pesquisa_texto_busca($area);
    $especificas = [
        'civil' => 'Verificar responsabilidade, inadimplemento, boa-fé objetiva, extensão do dano, nexo causal, prescrição e prova do prejuízo.',
        'consumidor' => 'Verificar relação de consumo, vulnerabilidade, responsabilidade do fornecedor, inversão do ônus da prova e práticas abusivas.',
        'trabalhista' => 'Verificar vínculo, subordinação, jornada, verbas, instrumentos coletivos, prescrição e distribuição do ônus probatório.',
        'familia' => 'Verificar interesse de incapazes, capacidade contributiva, necessidade, guarda, convivência, patrimônio e prova documental.',
        'previdenciario' => 'Verificar qualidade de segurado, carência, período contributivo, incapacidade, documentos médicos e regras de transição.',
        'penal' => 'Verificar tipicidade, materialidade, autoria, cadeia de custódia, excludentes, nulidades e garantias processuais.',
        'administrativo' => 'Verificar competência, legalidade, motivação, contraditório, ampla defesa, prazos e controle do ato administrativo.',
        'tributario' => 'Verificar hipótese de incidência, sujeito passivo, lançamento, decadência, prescrição, imunidades e legalidade da cobrança.',
        'empresarial' => 'Verificar estrutura societária, poderes, obrigações, títulos, insolvência, contratos empresariais e responsabilidade patrimonial.',
        'imobiliario' => 'Verificar matrícula, posse, propriedade, ônus, cadeia dominial, contrato, registro, tributos e situação urbanística.',
    ];

    foreach ($especificas as $chave => $linha) {
        if (str_contains($areaNormalizada, $chave)) {
            array_unshift($linhas, $linha);
            break;
        }
    }

    if (mb_strlen(trim($tema), 'UTF-8') > 8) {
        array_unshift($linhas, 'Questão central: definir quais normas, precedentes e teses respondem diretamente ao tema “' . trim($tema) . '”.');
    }

    if (!empty($fontes)) {
        $linhas[] = 'Escopo solicitado de fontes: ' . implode(', ', $fontes) . '.';
    }

    return $linhas;
}

function cij_pesquisa_prompt_ia(
    string $tema,
    string $area,
    string $palavrasChave,
    array $tiposFonte,
    string $legislacaoInformada,
    string $jurisprudenciaInformada,
    string $contexto
): string {
    return "Realize uma pesquisa jurídica estruturada em português do Brasil.\n\n"
        . "TEMA: " . ($tema ?: '[não informado]') . "\n"
        . "ÁREA DO DIREITO: " . ($area ?: '[não informada]') . "\n"
        . "PALAVRAS-CHAVE: " . ($palavrasChave ?: '[não informadas]') . "\n"
        . "TIPOS DE FONTE: " . (!empty($tiposFonte) ? implode(', ', $tiposFonte) : '[não informados]') . "\n"
        . "LEGISLAÇÃO INDICADA PELO USUÁRIO: " . ($legislacaoInformada ?: '[não informada]') . "\n"
        . "JURISPRUDÊNCIA INDICADA PELO USUÁRIO: " . ($jurisprudenciaInformada ?: '[não informada]') . "\n"
        . "CONTEXTO DO CASO: " . ($contexto ?: '[não informado]') . "\n\n"
        . "REGRAS OBRIGATÓRIAS:\n"
        . "1. Não invente artigos, leis, súmulas, números de processos, julgados, datas, órgãos ou citações.\n"
        . "2. Quando não tiver segurança sobre uma referência, descreva apenas a linha de pesquisa e marque-a como pendente de conferência em fonte oficial.\n"
        . "3. Diferencie legislação, jurisprudência, doutrina, teses favoráveis, teses contrárias e pontos controvertidos.\n"
        . "4. Indique termos de busca e filtros recomendados para portais oficiais.\n"
        . "5. Organize em relatório com: questão jurídica, palavras-chave, fontes, fundamentos, teses, contrapontos, provas relevantes, riscos e próximos passos.\n"
        . "6. Finalize com aviso de conferência obrigatória pelo advogado.\n";
}

$areasDireito = [
    'Civil', 'Consumidor', 'Família e Sucessões', 'Trabalhista', 'Previdenciário',
    'Penal', 'Processual Civil', 'Processual Penal', 'Administrativo', 'Tributário',
    'Empresarial', 'Imobiliário', 'Constitucional', 'Digital e Proteção de Dados', 'Outro',
];

$fontesDisponiveis = [
    'Legislação', 'Jurisprudência', 'Súmulas e precedentes', 'Doutrina',
    'Teses e fundamentos', 'Atos normativos', 'Fontes locais do ROJEX.AI',
];

$tema = trim((string)($_POST['tema_pesquisa'] ?? ''));
$area = trim((string)($_POST['area_direito'] ?? ''));
$palavrasChave = trim((string)($_POST['palavras_chave'] ?? ''));
$tiposFonte = $_POST['tipos_fonte'] ?? [];
$tiposFonte = is_array($tiposFonte) ? array_values(array_intersect($fontesDisponiveis, array_map('strval', $tiposFonte))) : [];
$legislacaoInformada = trim((string)($_POST['legislacao_informada'] ?? ''));
$jurisprudenciaInformada = trim((string)($_POST['jurisprudencia_informada'] ?? ''));
$contextoCaso = trim((string)($_POST['contexto_caso'] ?? ''));

$pesquisado = false;
$relatorio = '';
$modoResposta = '';
$erroIa = '';
$mensagemValidacao = '';
$resultadosBase = [];
$linhasInvestigacao = [];
$termosBusca = [];

$baseLocal = cij_pesquisa_base_local($variaveisAntesBase, $variaveisDepoisBase);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao_pesquisa'] ?? '') === 'pesquisar') {
    if (mb_strlen($tema, 'UTF-8') < 5) {
        $mensagemValidacao = 'Informe um tema jurídico com pelo menos 5 caracteres.';
    } elseif ($area === '') {
        $mensagemValidacao = 'Selecione a área do Direito.';
    } elseif (empty($tiposFonte)) {
        $mensagemValidacao = 'Selecione ao menos um tipo de fonte.';
    } else {
        $pesquisado = true;
        $termosBusca = cij_pesquisa_termos($tema, $palavrasChave, $area);
        $resultadosBase = cij_pesquisa_buscar_base($baseLocal, $termosBusca);
        $linhasInvestigacao = cij_pesquisa_linhas_investigacao($area, $tema, $tiposFonte);

        $promptSistema = 'Você é o módulo de Pesquisa Jurídica do ROJEX.AI Enterprise. Responda em português do Brasil. Não invente fontes, leis, artigos, súmulas, processos ou julgados. Quando não houver segurança, forneça estratégia de pesquisa e exija conferência em fonte oficial.';
        $promptUsuario = cij_pesquisa_prompt_ia(
            $tema,
            $area,
            $palavrasChave,
            $tiposFonte,
            $legislacaoInformada,
            $jurisprudenciaInformada,
            $contextoCaso
        );

        $retornoIa = cij_pesquisa_chamar_ia($promptSistema, $promptUsuario);

        if (($retornoIa['ok'] ?? false) === true) {
            $relatorio = trim((string)($retornoIa['texto'] ?? ''));
            $modoResposta = 'Pesquisa assistida por IA';
        } else {
            $erroIa = trim((string)($retornoIa['erro'] ?? 'IA externa indisponível ou não configurada.'));
            $linhas = [];
            $linhas[] = 'RELATÓRIO DE PESQUISA JURÍDICA';
            $linhas[] = '';
            $linhas[] = 'Tema: ' . $tema;
            $linhas[] = 'Área do Direito: ' . $area;
            $linhas[] = 'Palavras-chave: ' . ($palavrasChave ?: implode(', ', $termosBusca));
            $linhas[] = 'Tipos de fonte: ' . implode(', ', $tiposFonte);
            $linhas[] = '';
            $linhas[] = 'QUESTÃO E CONTEXTO';
            $linhas[] = $contextoCaso !== '' ? $contextoCaso : 'O contexto detalhado do caso não foi informado. A pesquisa permanece geral e deve ser ajustada aos fatos concretos.';
            $linhas[] = '';
            $linhas[] = 'LINHAS DE INVESTIGAÇÃO';
            foreach ($linhasInvestigacao as $linha) {
                $linhas[] = '- ' . $linha;
            }
            $linhas[] = '';
            $linhas[] = 'LEGISLAÇÃO';
            $linhas[] = $legislacaoInformada !== ''
                ? 'Referência informada pelo usuário: ' . $legislacaoInformada . '. Conferir texto vigente e fonte oficial.'
                : 'Pesquisar normas vigentes relacionadas ao tema e confirmar alterações, revogações, regulamentações e normas especiais em fonte oficial.';
            $linhas[] = '';
            $linhas[] = 'JURISPRUDÊNCIA';
            $linhas[] = $jurisprudenciaInformada !== ''
                ? 'Referência informada pelo usuário: ' . $jurisprudenciaInformada . '. Confirmar inteiro teor, órgão julgador, data e situação do precedente.'
                : 'Pesquisar decisões favoráveis e desfavoráveis, com prioridade para tribunais competentes, recorte temporal adequado e aderência fática.';
            $linhas[] = '';
            $linhas[] = 'TESES E FUNDAMENTOS A DESENVOLVER';
            $linhas[] = '- Tese principal: vincular os fatos comprovados aos requisitos da norma aplicável.';
            $linhas[] = '- Tese subsidiária: formular solução alternativa caso o fundamento principal não seja acolhido.';
            $linhas[] = '- Contrapontos: antecipar argumentos da parte adversa, distinções e precedentes desfavoráveis.';
            $linhas[] = '- Prova: relacionar cada afirmação relevante ao documento, testemunho, perícia ou registro correspondente.';
            $linhas[] = '';
            $linhas[] = 'TERMOS SUGERIDOS PARA BUSCA';
            $linhas[] = implode(' | ', !empty($termosBusca) ? $termosBusca : [$tema]);

            if (!empty($resultadosBase)) {
                $linhas[] = '';
                $linhas[] = 'RESULTADOS DA BASE LOCAL ROJEX.AI';
                foreach ($resultadosBase as $indice => $item) {
                    $linhas[] = ($indice + 1) . '. ' . ($item['titulo'] ?? 'Referência local');
                    $linhas[] = cij_pesquisa_normalizar((string)($item['conteudo'] ?? ''));
                    $linhas[] = '';
                }
            } else {
                $linhas[] = '';
                $linhas[] = 'BASE LOCAL ROJEX.AI';
                $linhas[] = 'Nenhuma referência local diretamente compatível foi localizada. Isso não significa inexistência de fundamento jurídico.';
            }

            $linhas[] = '';
            $linhas[] = 'PRÓXIMOS PASSOS';
            $linhas[] = '1. Confirmar legislação no portal oficial competente.';
            $linhas[] = '2. Pesquisar jurisprudência com filtros de tribunal, órgão, período e assunto.';
            $linhas[] = '3. Ler o inteiro teor das referências selecionadas.';
            $linhas[] = '4. Registrar fontes, datas de consulta e trechos pertinentes.';
            $linhas[] = '5. Submeter as conclusões à revisão do advogado responsável.';
            $linhas[] = '';
            $linhas[] = 'AVISO';
            $linhas[] = 'Este relatório local organiza a pesquisa, mas não substitui consulta a fontes oficiais nem revisão jurídica profissional.';

            $relatorio = implode("\n", $linhas);
            $modoResposta = 'Pesquisa local segura';
        }

        if (function_exists('sgl_registrar_log')) {
            sgl_registrar_log(
                $conn,
                'PESQUISA_JURIDICA_CIJ',
                'cij_pesquisa',
                '0',
                $area . ' - ' . $tema . ' - ' . $modoResposta
            );
        }
    }
}
?>

<div class="container-fluid">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
        <div>
            <h2 class="mb-1 fw-bold text-primary">
                <i class="bi bi-search me-2"></i>Pesquisa Jurídica
            </h2>
            <p class="text-muted mb-0">
                Organização de legislação, jurisprudência, teses, fundamentos e estratégias de pesquisa.
            </p>
        </div>
        <a href="?mod=cij" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i>Voltar ao CIJ
        </a>
    </div>

    <div class="alert <?= cij_pesquisa_ia_disponivel() ? 'alert-success' : 'alert-warning' ?> border-0 shadow-sm">
        <?php if (cij_pesquisa_ia_disponivel()): ?>
            <strong>IA conectada:</strong> a pesquisa utilizará a API configurada, com proibição de inventar fontes ou referências.
        <?php else: ?>
            <strong>Modo pesquisa local:</strong> a ferramenta organizará linhas de investigação e consultará a base local disponível, sem criar leis ou julgados inexistentes.
        <?php endif; ?>
    </div>

    <?php if ($mensagemValidacao !== ''): ?>
        <div class="alert alert-danger border-0 shadow-sm">
            <i class="bi bi-exclamation-triangle me-1"></i><?= cij_pesquisa_h($mensagemValidacao) ?>
        </div>
    <?php endif; ?>

    <?php if ($erroIa !== '' && $pesquisado): ?>
        <div class="alert alert-info border-0 shadow-sm">
            <strong>Observação técnica:</strong> <?= cij_pesquisa_h($erroIa) ?>
        </div>
    <?php endif; ?>

    <div class="row g-4">
        <div class="col-xl-5">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-dark text-white">
                    <i class="bi bi-journal-text me-2"></i>Parâmetros da pesquisa
                </div>
                <div class="card-body">
                    <form method="POST" class="row g-3">
                        <input type="hidden" name="acao_pesquisa" value="pesquisar">

                        <div class="col-12">
                            <label class="form-label fw-semibold">Tema jurídico</label>
                            <input type="text" name="tema_pesquisa" class="form-control" required
                                   value="<?= cij_pesquisa_h($tema) ?>"
                                   placeholder="Ex.: responsabilidade civil por infiltração em imóvel locado">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Área do Direito</label>
                            <select name="area_direito" class="form-select" required>
                                <option value="">Selecione...</option>
                                <?php foreach ($areasDireito as $opcao): ?>
                                    <option value="<?= cij_pesquisa_h($opcao) ?>" <?= $area === $opcao ? 'selected' : '' ?>>
                                        <?= cij_pesquisa_h($opcao) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Palavras-chave</label>
                            <input type="text" name="palavras_chave" class="form-control"
                                   value="<?= cij_pesquisa_h($palavrasChave) ?>"
                                   placeholder="Ex.: dano, locador, reparo, indenização">
                        </div>

                        <div class="col-12">
                            <label class="form-label fw-semibold d-block">Tipos de fonte</label>
                            <div class="row g-2">
                                <?php foreach ($fontesDisponiveis as $fonte): ?>
                                    <div class="col-md-6">
                                        <div class="form-check border rounded p-2 ps-4 h-100">
                                            <input class="form-check-input" type="checkbox" name="tipos_fonte[]"
                                                   id="fonte<?= md5($fonte) ?>" value="<?= cij_pesquisa_h($fonte) ?>"
                                                   <?= in_array($fonte, $tiposFonte, true) ? 'checked' : '' ?>>
                                            <label class="form-check-label" for="fonte<?= md5($fonte) ?>">
                                                <?= cij_pesquisa_h($fonte) ?>
                                            </label>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <div class="col-12">
                            <label class="form-label fw-semibold">Legislação já conhecida</label>
                            <textarea name="legislacao_informada" class="form-control" rows="3"
                                      placeholder="Informe apenas referências que deseja conferir. O sistema não presumirá validade ou vigência."><?= cij_pesquisa_h($legislacaoInformada) ?></textarea>
                        </div>

                        <div class="col-12">
                            <label class="form-label fw-semibold">Jurisprudência já conhecida</label>
                            <textarea name="jurisprudencia_informada" class="form-control" rows="3"
                                      placeholder="Ex.: tribunal, número, súmula ou entendimento que deverá ser conferido."><?= cij_pesquisa_h($jurisprudenciaInformada) ?></textarea>
                        </div>

                        <div class="col-12">
                            <label class="form-label fw-semibold">Contexto do caso ou dúvida jurídica</label>
                            <textarea name="contexto_caso" class="form-control" rows="7"
                                      placeholder="Descreva os fatos essenciais, a dúvida, o objetivo e eventuais documentos disponíveis."><?= cij_pesquisa_h($contextoCaso) ?></textarea>
                        </div>

                        <div class="col-12 d-grid">
                            <button class="btn btn-primary btn-lg">
                                <i class="bi bi-search me-1"></i>Realizar pesquisa
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-xl-7">
            <?php if (!$pesquisado): ?>
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-header bg-dark text-white">
                        <i class="bi bi-journals me-2"></i>Resultado da pesquisa
                    </div>
                    <div class="card-body d-flex align-items-center justify-content-center">
                        <div class="text-center text-muted py-5">
                            <i class="bi bi-search fs-1 d-block mb-3 opacity-25"></i>
                            <h5 class="fw-bold">Nenhuma pesquisa realizada</h5>
                            <p class="mb-0">Informe o tema, a área e as fontes desejadas.</p>
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <div class="row g-4 mb-4">
                    <div class="col-lg-6">
                        <div class="card border-0 shadow-sm h-100">
                            <div class="card-header bg-dark text-white">
                                <i class="bi bi-key me-2"></i>Termos organizados
                            </div>
                            <div class="card-body">
                                <?php if (!empty($termosBusca)): ?>
                                    <div class="d-flex flex-wrap gap-2">
                                        <?php foreach ($termosBusca as $termo): ?>
                                            <span class="badge bg-primary-subtle text-primary border border-primary-subtle px-3 py-2">
                                                <?= cij_pesquisa_h($termo) ?>
                                            </span>
                                        <?php endforeach; ?>
                                    </div>
                                <?php else: ?>
                                    <p class="text-muted mb-0">Não foi possível separar palavras-chave relevantes.</p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <div class="col-lg-6">
                        <div class="card border-0 shadow-sm h-100">
                            <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center">
                                <span><i class="bi bi-database-check me-2"></i>Base local</span>
                                <span class="badge bg-primary"><?= count($resultadosBase) ?> resultado(s)</span>
                            </div>
                            <div class="card-body">
                                <?php if (!empty($resultadosBase)): ?>
                                    <p class="mb-0">Foram localizadas referências compatíveis em <code>config/base_conhecimento.php</code>.</p>
                                <?php else: ?>
                                    <p class="text-muted mb-0">Nenhuma referência local diretamente compatível foi encontrada.</p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <?php if (!empty($resultadosBase)): ?>
                    <div class="card border-0 shadow-sm mb-4">
                        <div class="card-header bg-dark text-white">
                            <i class="bi bi-database me-2"></i>Referências encontradas na base local
                        </div>
                        <div class="list-group list-group-flush">
                            <?php foreach ($resultadosBase as $item): ?>
                                <div class="list-group-item py-3">
                                    <div class="d-flex justify-content-between align-items-start gap-3">
                                        <div>
                                            <h6 class="fw-bold mb-1"><?= cij_pesquisa_h($item['titulo'] ?? 'Referência local') ?></h6>
                                            <p class="mb-1 text-muted"><?= cij_pesquisa_h(mb_strimwidth((string)($item['conteudo'] ?? ''), 0, 420, '...', 'UTF-8')) ?></p>
                                            <?php if (!empty($item['termos'])): ?>
                                                <small>Correspondência: <?= cij_pesquisa_h(implode(', ', $item['termos'])) ?></small>
                                            <?php endif; ?>
                                        </div>
                                        <span class="badge bg-secondary"><?= (int)($item['pontuacao'] ?? 0) ?></span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <span><i class="bi bi-file-earmark-bar-graph me-2"></i>Relatório editável</span>
                        <span class="badge bg-primary"><?= cij_pesquisa_h($modoResposta) ?></span>
                    </div>
                    <div class="card-body">
                        <div class="d-flex flex-wrap gap-2 mb-3">
                            <button type="button" class="btn btn-outline-primary btn-sm" id="btnCopiarPesquisa">
                                <i class="bi bi-clipboard me-1"></i>Copiar
                            </button>
                            <button type="button" class="btn btn-outline-success btn-sm" id="btnWordPesquisa">
                                <i class="bi bi-file-earmark-word me-1"></i>Baixar Word
                            </button>
                            <button type="button" class="btn btn-outline-secondary btn-sm" id="btnImprimirPesquisa">
                                <i class="bi bi-printer me-1"></i>Imprimir / PDF
                            </button>
                            <a href="?mod=cij&ferramenta=pesquisa" class="btn btn-outline-dark btn-sm">
                                <i class="bi bi-plus-circle me-1"></i>Nova pesquisa
                            </a>
                        </div>

                        <textarea id="relatorioPesquisaCij" class="form-control" rows="30"><?= cij_pesquisa_h($relatorio) ?></textarea>

                        <div class="alert alert-warning mt-3 mb-0">
                            <i class="bi bi-exclamation-triangle me-1"></i>
                            Confira legislação, julgados, vigência, inteiro teor e aderência ao caso em fontes oficiais antes do uso profissional.
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if ($pesquisado): ?>
<script>
(function(){
    const campo = document.getElementById('relatorioPesquisaCij');
    const btnCopiar = document.getElementById('btnCopiarPesquisa');
    const btnWord = document.getElementById('btnWordPesquisa');
    const btnImprimir = document.getElementById('btnImprimirPesquisa');

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
            link.download = 'pesquisa_juridica_rojex.doc';
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
<title>Pesquisa Jurídica — ROJEX.AI</title>
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
<div class="rodape">Pesquisa de apoio técnico. Conferência obrigatória em fontes oficiais e revisão por advogado.</div>
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
