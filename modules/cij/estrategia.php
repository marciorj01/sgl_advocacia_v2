<?php
/**
 * modules/cij/estrategia.php
 * Estratégia Jurídica Inteligente — ROJEX.AI Enterprise
 * Sprint 4.1.4 — Etapa 9
 *
 * Objetivos:
 * - Estruturar o raciocínio estratégico do caso.
 * - Funcionar em modo local seguro.
 * - Utilizar config/ia.php quando a IA externa estiver configurada.
 * - Não criar nem alterar tabelas nesta etapa.
 */

if (!isset($conn) || !($conn instanceof mysqli)) {
    $conn = conectar();
}

$arquivoIa = __DIR__ . '/../../config/ia.php';
if (is_file($arquivoIa)) {
    require_once $arquivoIa;
}

function cij_estrategia_h($valor): string
{
    return htmlspecialchars((string)($valor ?? ''), ENT_QUOTES, 'UTF-8');
}

function cij_estrategia_ia_disponivel(): bool
{
    return function_exists('sgl_ia_disponivel') && sgl_ia_disponivel();
}

function cij_estrategia_chamar_ia(string $promptSistema, string $promptUsuario): array
{
    if (!function_exists('sgl_ia_chamar_openai')) {
        return [
            'ok' => false,
            'texto' => '',
            'erro' => 'Função oficial de IA não encontrada em config/ia.php.',
        ];
    }

    return sgl_ia_chamar_openai($promptSistema, $promptUsuario);
}

function cij_estrategia_lista(string $texto): array
{
    $partes = preg_split('/[\r\n;]+/u', $texto) ?: [];
    $itens = [];

    foreach ($partes as $parte) {
        $parte = trim((string)$parte);
        $parte = preg_replace('/^[\-\*\•\d\.\)\s]+/u', '', $parte);
        if ($parte !== '') {
            $itens[] = $parte;
        }
    }

    return array_values(array_unique($itens));
}

function cij_estrategia_relatorio_local(array $dados): string
{
    $fatos = cij_estrategia_lista($dados['fatos'] ?? '');
    $documentos = cij_estrategia_lista($dados['documentos'] ?? '');
    $provas = cij_estrategia_lista($dados['provas'] ?? '');
    $pedidos = cij_estrategia_lista($dados['pedidos'] ?? '');
    $riscosInformados = cij_estrategia_lista($dados['riscos'] ?? '');

    $linhas = [];
    $linhas[] = 'RELATÓRIO DE ESTRATÉGIA JURÍDICA — ROJEX.AI';
    $linhas[] = '';
    $linhas[] = 'ÁREA DO DIREITO';
    $linhas[] = ($dados['area'] ?? '') ?: 'Não informada';
    $linhas[] = '';
    $linhas[] = 'FASE PROCESSUAL';
    $linhas[] = ($dados['fase'] ?? '') ?: 'Não informada';
    $linhas[] = '';
    $linhas[] = 'RESUMO ESTRATÉGICO';
    $linhas[] = 'O caso deve ser analisado a partir dos fatos narrados, dos documentos disponíveis, da fase processual e dos pedidos pretendidos. A estratégia deve priorizar coerência entre fatos, prova, fundamento jurídico e resultado pretendido.';
    $linhas[] = '';

    $linhas[] = 'PONTOS FORTES';
    if ($documentos !== []) {
        foreach ($documentos as $item) {
            $linhas[] = '- Documento potencialmente útil: ' . $item;
        }
    }
    if ($provas !== []) {
        foreach ($provas as $item) {
            $linhas[] = '- Prova indicada: ' . $item;
        }
    }
    if ($documentos === [] && $provas === []) {
        $linhas[] = '- Nenhum ponto forte probatório foi claramente identificado com os dados fornecidos.';
    }
    $linhas[] = '';

    $linhas[] = 'PONTOS FRACOS';
    if ($fatos === []) {
        $linhas[] = '- Fatos insuficientemente detalhados.';
    }
    if ($documentos === []) {
        $linhas[] = '- Ausência de documentos claramente informados.';
    }
    if ($provas === []) {
        $linhas[] = '- Provas ainda não especificadas.';
    }
    if ($pedidos === []) {
        $linhas[] = '- Pedidos ou objetivo jurídico não foram delimitados.';
    }
    if ($fatos !== [] && $documentos !== [] && $provas !== [] && $pedidos !== []) {
        $linhas[] = '- Conferir se cada afirmação relevante possui suporte documental, testemunhal, pericial ou registral.';
    }
    $linhas[] = '';

    $linhas[] = 'RISCOS PROCESSUAIS';
    if ($riscosInformados !== []) {
        foreach ($riscosInformados as $item) {
            $linhas[] = '- ' . $item;
        }
    } else {
        $linhas[] = '- Possível insuficiência de prova.';
        $linhas[] = '- Possível controvérsia sobre ônus da prova.';
        $linhas[] = '- Necessidade de verificar prescrição, decadência, competência, legitimidade e interesse processual.';
        $linhas[] = '- Possibilidade de entendimento jurisprudencial desfavorável.';
    }
    $linhas[] = '';

    $linhas[] = 'PROVAS RECOMENDADAS';
    $linhas[] = '- Organizar documentos por fato controvertido.';
    $linhas[] = '- Identificar testemunhas com conhecimento direto dos acontecimentos.';
    $linhas[] = '- Avaliar necessidade de perícia, inspeção, ata notarial, ofício ou exibição de documentos.';
    $linhas[] = '- Preservar arquivos originais, metadados, mensagens e cadeia de custódia quando aplicável.';
    $linhas[] = '';

    $linhas[] = 'ESTRATÉGIAS POSSÍVEIS';
    $linhas[] = 'Estratégia A — Tese principal';
    $linhas[] = '- Sustentar a pretensão central com os fatos mais bem comprovados e a norma diretamente aplicável.';
    $linhas[] = '';
    $linhas[] = 'Estratégia B — Tese subsidiária';
    $linhas[] = '- Formular solução alternativa para o caso de não acolhimento integral da tese principal.';
    $linhas[] = '';
    $linhas[] = 'Estratégia C — Solução consensual ou redução de risco';
    $linhas[] = '- Avaliar acordo, composição, reconhecimento parcial ou medida preventiva quando juridicamente adequado.';
    $linhas[] = '';

    $linhas[] = 'POSSÍVEIS ARGUMENTOS DA PARTE CONTRÁRIA';
    $linhas[] = '- Negação dos fatos ou impugnação da versão apresentada.';
    $linhas[] = '- Contestação da autenticidade, integridade ou pertinência das provas.';
    $linhas[] = '- Alegação de prescrição, decadência, ilegitimidade, incompetência ou ausência de interesse.';
    $linhas[] = '- Interpretação jurídica ou jurisprudencial divergente.';
    $linhas[] = '';

    $linhas[] = 'CONTRAMEDIDAS';
    $linhas[] = '- Vincular cada fato relevante a uma prova específica.';
    $linhas[] = '- Antecipar preliminares e demonstrar requisitos processuais.';
    $linhas[] = '- Diferenciar precedentes desfavoráveis pela situação fática e jurídica.';
    $linhas[] = '- Apresentar tese subsidiária e pedidos sucessivos quando cabível.';
    $linhas[] = '';

    $linhas[] = 'PRÓXIMOS PASSOS';
    $linhas[] = '- Confirmar cronologia completa dos fatos.';
    $linhas[] = '- Conferir documentos, autenticidade, origem e integridade.';
    $linhas[] = '- Identificar fatos incontroversos e controvertidos.';
    $linhas[] = '- Verificar prazos, prescrição, decadência e competência.';
    $linhas[] = '- Pesquisar legislação e jurisprudência atualizadas em fontes oficiais.';
    $linhas[] = '- Definir tese principal, tese subsidiária, pedidos e provas.';
    $linhas[] = '- Revisar a estratégia antes do protocolo ou da audiência.';
    $linhas[] = '';

    $linhas[] = 'OBSERVAÇÃO';
    $linhas[] = 'Esta análise é estratégica e preliminar. Deve ser revisada por profissional habilitado e confrontada com os documentos, legislação e jurisprudência aplicáveis.';

    return implode("\n", $linhas);
}

function cij_estrategia_prompt(array $dados): string
{
    return "Elabore uma estratégia jurídica profissional em português do Brasil.\n\n"
        . "REGRAS OBRIGATÓRIAS:\n"
        . "1. Use somente as informações fornecidas.\n"
        . "2. Não invente fatos, documentos, leis, precedentes ou números de processos.\n"
        . "3. Quando faltar informação, aponte a lacuna expressamente.\n"
        . "4. Diferencie fato, prova, risco, tese e recomendação.\n"
        . "5. Organize a resposta exatamente nestes blocos:\n"
        . "RESUMO ESTRATÉGICO\n"
        . "PONTOS FORTES\n"
        . "PONTOS FRACOS\n"
        . "RISCOS PROCESSUAIS\n"
        . "PROVAS RECOMENDADAS\n"
        . "ESTRATÉGIAS POSSÍVEIS\n"
        . "POSSÍVEIS ARGUMENTOS DA PARTE CONTRÁRIA\n"
        . "CONTRAMEDIDAS\n"
        . "PRÓXIMOS PASSOS\n"
        . "OBSERVAÇÃO\n\n"
        . "DADOS DO CASO:\n"
        . json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}

$area = trim((string)($_POST['area_direito'] ?? ''));
$fase = trim((string)($_POST['fase_processual'] ?? ''));
$fatos = trim((string)($_POST['fatos'] ?? ''));
$documentos = trim((string)($_POST['documentos'] ?? ''));
$provas = trim((string)($_POST['provas'] ?? ''));
$pedidos = trim((string)($_POST['pedidos'] ?? ''));
$riscos = trim((string)($_POST['riscos'] ?? ''));
$observacoes = trim((string)($_POST['observacoes'] ?? ''));

$relatorio = '';
$modoResposta = '';
$erroIa = '';
$mensagem = '';

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['acao_estrategia'] ?? '') === 'analisar'
) {
    if ($fatos === '') {
        $mensagem = 'Informe os fatos principais do caso.';
    } else {
        $dadosCaso = [
            'area_do_direito' => $area,
            'fase_processual' => $fase,
            'fatos' => $fatos,
            'documentos_existentes' => $documentos,
            'provas_disponiveis' => $provas,
            'pedidos_ou_objetivos' => $pedidos,
            'riscos_ja_identificados' => $riscos,
            'observacoes_adicionais' => $observacoes,
        ];

        $retornoIa = cij_estrategia_chamar_ia(
            'Você é o módulo Estratégia Jurídica Inteligente do ROJEX.AI Enterprise. Atue como ferramenta de apoio profissional, sem inventar informações e sem substituir a análise do advogado responsável.',
            cij_estrategia_prompt($dadosCaso)
        );

        if (($retornoIa['ok'] ?? false) === true) {
            $relatorio = trim((string)($retornoIa['texto'] ?? ''));
            $modoResposta = 'Análise por IA';
        } else {
            $erroIa = trim((string)($retornoIa['erro'] ?? 'IA externa indisponível.'));
            $relatorio = cij_estrategia_relatorio_local([
                'area' => $area,
                'fase' => $fase,
                'fatos' => $fatos,
                'documentos' => $documentos,
                'provas' => $provas,
                'pedidos' => $pedidos,
                'riscos' => $riscos,
                'observacoes' => $observacoes,
            ]);
            $modoResposta = 'Análise local segura';
        }

        if (function_exists('sgl_registrar_log')) {
            sgl_registrar_log(
                $conn,
                'ESTRATEGIA_JURIDICA_CIJ',
                'cij_estrategia',
                '0',
                'Estratégia gerada em modo: ' . $modoResposta
            );
        }
    }
}
?>

<div class="container-fluid">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
        <div>
            <h2 class="mb-1 fw-bold text-primary">
                <i class="bi bi-diagram-3 me-2"></i>Estratégia Jurídica Inteligente
            </h2>
            <p class="text-muted mb-0">
                Estruture pontos fortes, riscos, provas, teses, contramedidas e próximos passos.
            </p>
        </div>
        <a href="?mod=cij" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i>Voltar ao CIJ
        </a>
    </div>

    <div class="alert <?= cij_estrategia_ia_disponivel() ? 'alert-success' : 'alert-warning' ?> border-0 shadow-sm">
        <?php if (cij_estrategia_ia_disponivel()): ?>
            <strong>IA conectada:</strong> a estratégia poderá utilizar a API oficial configurada no ROJEX.AI.
        <?php else: ?>
            <strong>Modo local seguro:</strong> o sistema organizará a estratégia sem inventar fatos, leis ou precedentes.
        <?php endif; ?>
    </div>

    <?php if ($mensagem !== ''): ?>
        <div class="alert alert-danger border-0 shadow-sm">
            <?= cij_estrategia_h($mensagem) ?>
        </div>
    <?php endif; ?>

    <?php if ($erroIa !== '' && $relatorio !== ''): ?>
        <div class="alert alert-info border-0 shadow-sm">
            <strong>Observação técnica:</strong> <?= cij_estrategia_h($erroIa) ?>
        </div>
    <?php endif; ?>

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-dark text-white">
            <i class="bi bi-clipboard-data me-2"></i>Dados estratégicos do caso
        </div>
        <div class="card-body">
            <form method="POST" class="row g-3">
                <input type="hidden" name="acao_estrategia" value="analisar">

                <div class="col-md-6">
                    <label class="form-label fw-semibold">Área do Direito</label>
                    <select name="area_direito" class="form-select">
                        <option value="">Selecione...</option>
                        <?php
                        $areas = [
                            'Cível', 'Trabalhista', 'Previdenciário', 'Família',
                            'Consumidor', 'Criminal', 'Tributário', 'Empresarial',
                            'Administrativo', 'Imobiliário', 'Bancário',
                            'Contratual', 'Digital', 'LGPD', 'Geral'
                        ];
                        ?>
                        <?php foreach ($areas as $opcao): ?>
                            <option value="<?= cij_estrategia_h($opcao) ?>" <?= $area === $opcao ? 'selected' : '' ?>>
                                <?= cij_estrategia_h($opcao) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-6">
                    <label class="form-label fw-semibold">Fase processual</label>
                    <select name="fase_processual" class="form-select">
                        <option value="">Selecione...</option>
                        <?php
                        $fases = [
                            'Atendimento inicial', 'Análise pré-processual',
                            'Negociação', 'Petição inicial', 'Contestação',
                            'Réplica', 'Instrução', 'Audiência', 'Sentença',
                            'Recurso', 'Execução', 'Cumprimento de sentença',
                            'Procedimento administrativo'
                        ];
                        ?>
                        <?php foreach ($fases as $opcao): ?>
                            <option value="<?= cij_estrategia_h($opcao) ?>" <?= $fase === $opcao ? 'selected' : '' ?>>
                                <?= cij_estrategia_h($opcao) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-12">
                    <label class="form-label fw-semibold">Fatos principais</label>
                    <textarea
                        name="fatos"
                        class="form-control"
                        rows="6"
                        required
                        placeholder="Descreva a cronologia, as partes, o conflito e os acontecimentos principais."><?= cij_estrategia_h($fatos) ?></textarea>
                </div>

                <div class="col-md-6">
                    <label class="form-label fw-semibold">Documentos existentes</label>
                    <textarea
                        name="documentos"
                        class="form-control"
                        rows="5"
                        placeholder="Ex.: contrato, mensagens, laudo, recibos, notificações..."><?= cij_estrategia_h($documentos) ?></textarea>
                </div>

                <div class="col-md-6">
                    <label class="form-label fw-semibold">Provas disponíveis</label>
                    <textarea
                        name="provas"
                        class="form-control"
                        rows="5"
                        placeholder="Ex.: testemunhas, perícia, gravações, ata notarial..."><?= cij_estrategia_h($provas) ?></textarea>
                </div>

                <div class="col-md-6">
                    <label class="form-label fw-semibold">Pedidos ou objetivos</label>
                    <textarea
                        name="pedidos"
                        class="form-control"
                        rows="4"
                        placeholder="Indique o resultado pretendido e os pedidos principais ou subsidiários."><?= cij_estrategia_h($pedidos) ?></textarea>
                </div>

                <div class="col-md-6">
                    <label class="form-label fw-semibold">Riscos já identificados</label>
                    <textarea
                        name="riscos"
                        class="form-control"
                        rows="4"
                        placeholder="Ex.: prescrição, ausência de prova, perícia, entendimento desfavorável..."><?= cij_estrategia_h($riscos) ?></textarea>
                </div>

                <div class="col-12">
                    <label class="form-label fw-semibold">Observações adicionais</label>
                    <textarea
                        name="observacoes"
                        class="form-control"
                        rows="3"
                        placeholder="Inclua informações relevantes que não se enquadram nos campos anteriores."><?= cij_estrategia_h($observacoes) ?></textarea>
                </div>

                <div class="col-12 d-flex justify-content-end">
                    <button class="btn btn-primary btn-lg">
                        <i class="bi bi-cpu me-1"></i>Gerar estratégia
                    </button>
                </div>
            </form>
        </div>
    </div>

    <?php if ($relatorio === ''): ?>
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-dark text-white">
                <i class="bi bi-lightbulb me-2"></i>Resultado estratégico
            </div>
            <div class="card-body text-center text-muted py-5">
                <i class="bi bi-diagram-3 fs-1 d-block mb-3 opacity-25"></i>
                <h5 class="fw-bold">Nenhuma estratégia gerada</h5>
                <p class="mb-0">Preencha os dados do caso e clique em gerar estratégia.</p>
            </div>
        </div>
    <?php else: ?>
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center flex-wrap gap-2">
                <span><i class="bi bi-file-earmark-text me-2"></i>Relatório estratégico editável</span>
                <span class="badge bg-primary"><?= cij_estrategia_h($modoResposta) ?></span>
            </div>
            <div class="card-body">
                <div class="d-flex flex-wrap gap-2 mb-3">
                    <button type="button" class="btn btn-outline-primary btn-sm" id="btnCopiarEstrategia">
                        <i class="bi bi-clipboard me-1"></i>Copiar
                    </button>
                    <button type="button" class="btn btn-outline-success btn-sm" id="btnWordEstrategia">
                        <i class="bi bi-file-earmark-word me-1"></i>Baixar Word
                    </button>
                    <button type="button" class="btn btn-outline-secondary btn-sm" id="btnImprimirEstrategia">
                        <i class="bi bi-printer me-1"></i>Imprimir / PDF
                    </button>
                    <a href="?mod=cij&ferramenta=estrategia" class="btn btn-outline-dark btn-sm">
                        <i class="bi bi-plus-circle me-1"></i>Nova estratégia
                    </a>
                </div>

                <textarea id="relatorioEstrategiaCij" class="form-control" rows="28"><?= cij_estrategia_h($relatorio) ?></textarea>

                <div class="alert alert-warning mt-3 mb-0">
                    <i class="bi bi-exclamation-triangle me-1"></i>
                    A estratégia é ferramenta de apoio e deve ser validada pelo advogado responsável.
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php if ($relatorio !== ''): ?>
<script>
(function(){
    const campo = document.getElementById('relatorioEstrategiaCij');
    const copiar = document.getElementById('btnCopiarEstrategia');
    const word = document.getElementById('btnWordEstrategia');
    const imprimir = document.getElementById('btnImprimirEstrategia');

    if (!campo) return;

    function htmlSeguro(texto) {
        const div = document.createElement('div');
        div.textContent = texto;
        return div.innerHTML;
    }

    copiar?.addEventListener('click', async function(){
        try {
            await navigator.clipboard.writeText(campo.value);
            const original = copiar.innerHTML;
            copiar.innerHTML = '<i class="bi bi-check-lg me-1"></i>Copiado';
            setTimeout(function(){ copiar.innerHTML = original; }, 1800);
        } catch (e) {
            campo.select();
            document.execCommand('copy');
        }
    });

    word?.addEventListener('click', function(){
        const conteudo = htmlSeguro(campo.value).replace(/\n/g, '<br>');
        const html = `<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>
@page { size: A4; margin: 3cm 2cm 2cm 3cm; }
body { font-family: "Times New Roman", serif; font-size: 12pt; line-height: 1.5; text-align: justify; }
</style>
</head>
<body>${conteudo}</body>
</html>`;

        const blob = new Blob(['\ufeff', html], {type: 'application/msword'});
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = url;
        link.download = 'estrategia_juridica_rojex.doc';
        document.body.appendChild(link);
        link.click();
        link.remove();
        URL.revokeObjectURL(url);
    });

    imprimir?.addEventListener('click', function(){
        const janela = window.open('', '_blank', 'width=1000,height=800');

        if (!janela) {
            alert('Autorize pop-ups para imprimir ou salvar em PDF.');
            return;
        }

        const conteudo = htmlSeguro(campo.value).replace(/\n/g, '<br>');

        janela.document.write(`<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Estratégia Jurídica — ROJEX.AI</title>
<style>
@page { size: A4; margin: 3cm 2cm 2cm 3cm; }
body { font-family: "Times New Roman", serif; font-size: 12pt; line-height: 1.5; text-align: justify; color: #000; }
.rodape { margin-top: 25pt; padding-top: 8pt; border-top: 1px solid #999; text-align: center; font-size: 9pt; color: #555; }
</style>
</head>
<body>
<div>${conteudo}</div>
<div class="rodape">Documento estratégico de apoio — revisão profissional obrigatória.</div>
<script>window.onload=function(){window.print();};<\/script>
</body>
</html>`);
        janela.document.close();
    });
})();
</script>
<?php endif; ?>
