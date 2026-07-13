<?php
/**
 * modules/cij/academia.php
 * Academia ROJEX.AI — Centro de Capacitação Enterprise
 * Sprint 4.1.4 — Etapa 11
 *
 * Objetivos:
 * - Oferecer treinamento interno por perfil e por módulo.
 * - Permitir pesquisa local segura em conteúdos fixos.
 * - Preparar integração futura com IA externa.
 * - Não criar nem alterar tabelas nesta etapa.
 */

if (!isset($conn) || !($conn instanceof mysqli)) {
    $conn = conectar();
}

$arquivoIa = __DIR__ . '/../../config/ia.php';
if (is_file($arquivoIa)) {
    require_once $arquivoIa;
}

function cij_academia_h($valor): string
{
    return htmlspecialchars((string)($valor ?? ''), ENT_QUOTES, 'UTF-8');
}

function cij_academia_ia_disponivel(): bool
{
    return function_exists('sgl_ia_disponivel') && sgl_ia_disponivel();
}

function cij_academia_conteudos(): array
{
    return [
        [
            'categoria' => 'Primeiros Passos',
            'titulo' => 'Conhecendo o ROJEX.AI',
            'perfil' => 'Todos',
            'palavras' => 'início sistema menus módulos navegação visão geral',
            'conteudo' => "O ROJEX.AI é um ERP Jurídico Enterprise organizado por áreas operacionais. O fluxo recomendado começa no cadastro correto dos dados, segue para processos, agenda, financeiro, documentos e uso das ferramentas do CIJ.\n\nAntes de iniciar o uso diário, confirme o perfil do usuário, as permissões e os dados do escritório."
        ],
        [
            'categoria' => 'Primeiros Passos',
            'titulo' => 'Fluxo recomendado de utilização',
            'perfil' => 'Todos',
            'palavras' => 'fluxo rotina cliente processo agenda honorários documentos',
            'conteudo' => "1. Cadastre o cliente.\n2. Cadastre ou vincule o advogado.\n3. Crie o processo.\n4. Registre agenda e prazos.\n5. Lance honorários e movimentações financeiras.\n6. Organize documentos.\n7. Utilize o CIJ para análise, revisão, pesquisa e estratégia.\n8. Confira o LOG quando necessário."
        ],
        [
            'categoria' => 'Perfis',
            'titulo' => 'Treinamento do usuário MASTER',
            'perfil' => 'MASTER',
            'palavras' => 'master administração licenças escritórios backup manutenção usuários segurança',
            'conteudo' => "O usuário MASTER administra usuários, licenças, escritórios, histórico de desligados, saúde do sistema, manutenção, backup, atualizações e relatórios. Deve evitar alterações sem conferência, manter backups recentes e revisar os registros do LOG Enterprise."
        ],
        [
            'categoria' => 'Perfis',
            'titulo' => 'Treinamento do Administrador',
            'perfil' => 'Administrador',
            'palavras' => 'administrador gestão equipe escritório relatórios configurações',
            'conteudo' => "O Administrador gerencia rotinas do escritório conforme suas permissões. Deve conferir cadastros, agenda, financeiro, documentos e produtividade. Ações exclusivas do MASTER permanecem restritas."
        ],
        [
            'categoria' => 'Perfis',
            'titulo' => 'Treinamento do Advogado',
            'perfil' => 'Advogado',
            'palavras' => 'advogado processos prazos peças documentos pesquisa estratégia',
            'conteudo' => "O advogado utiliza o ROJEX.AI para acompanhar processos, agenda, documentos, honorários e ferramentas do CIJ. Toda análise automática deve ser revisada antes de uso jurídico, protocolo ou comunicação ao cliente."
        ],
        [
            'categoria' => 'Perfis',
            'titulo' => 'Treinamento do Usuário Comum',
            'perfil' => 'Usuário comum',
            'palavras' => 'usuário comum cadastro consulta rotina permissões',
            'conteudo' => "O usuário comum deve atuar somente nas áreas autorizadas. Não deve alterar permissões, desativar usuários ou executar rotinas administrativas restritas. Sempre confirme dados antes de salvar."
        ],
        [
            'categoria' => 'Módulos',
            'titulo' => 'Dashboard',
            'perfil' => 'Todos',
            'palavras' => 'dashboard resumo indicadores cards atalhos',
            'conteudo' => "O Dashboard apresenta indicadores e atalhos principais. Use-o para visão rápida, mas confirme os dados nos módulos de origem antes de tomar decisões."
        ],
        [
            'categoria' => 'Módulos',
            'titulo' => 'Clientes',
            'perfil' => 'Todos',
            'palavras' => 'clientes cadastro cpf cnpj contato endereço',
            'conteudo' => "Cadastre nome, CPF/CNPJ, contatos e endereço com atenção. Evite duplicidades. Antes de criar novo registro, use a pesquisa por nome ou documento."
        ],
        [
            'categoria' => 'Módulos',
            'titulo' => 'Advogados',
            'perfil' => 'Administrador',
            'palavras' => 'advogados oab cadastro responsável',
            'conteudo' => "Mantenha OAB, contatos e status atualizados. Vincule corretamente o advogado aos processos e compromissos."
        ],
        [
            'categoria' => 'Módulos',
            'titulo' => 'Processos',
            'perfil' => 'Advogado',
            'palavras' => 'processos número cnj fase prazo cliente advogado',
            'conteudo' => "Informe número do processo, partes, cliente, advogado, fase, comarca, vara e prazo. Atualize a fase processual sempre que houver movimentação relevante."
        ],
        [
            'categoria' => 'Módulos',
            'titulo' => 'Agenda',
            'perfil' => 'Todos',
            'palavras' => 'agenda audiência prazo reunião perícia compromisso',
            'conteudo' => "Registre data, horário, tipo, cliente, processo, local, responsável, status e se o prazo é fatal. Revise a agenda do dia e dos próximos sete dias diariamente."
        ],
        [
            'categoria' => 'Módulos',
            'titulo' => 'Financeiro',
            'perfil' => 'Administrador',
            'palavras' => 'financeiro contas pagar receber bancos caixa recibos',
            'conteudo' => "Registre contas a pagar e receber, formas de pagamento, bancos/caixa, recebimentos e pagamentos. Confira saldo pendente, vencimentos e comprovantes."
        ],
        [
            'categoria' => 'Módulos',
            'titulo' => 'Documentos',
            'perfil' => 'Todos',
            'palavras' => 'documentos upload pdf imagem prova arquivo',
            'conteudo' => "Envie documentos com título, categoria e vínculo correto. Preserve arquivos originais e utilize a análise documental apenas como apoio."
        ],
        [
            'categoria' => 'Módulos',
            'titulo' => 'Modelos Jurídicos',
            'perfil' => 'Advogado',
            'palavras' => 'modelos peças versões favoritos documentos',
            'conteudo' => "Mantenha modelos revisados, categorizados e versionados. Utilize favoritos para acesso rápido e atualize o conteúdo sempre que necessário."
        ],
        [
            'categoria' => 'Módulos',
            'titulo' => 'Configurações e Administração',
            'perfil' => 'MASTER',
            'palavras' => 'configurações administração usuários backup saúde manutenção atualizações',
            'conteudo' => "As áreas administrativas concentram dados do escritório, usuários, licenças, manutenção, saúde, backup, atualizações e relatórios. Ações críticas devem ser realizadas apenas pelo MASTER."
        ],
        [
            'categoria' => 'Módulos',
            'titulo' => 'Centro de Inteligência Jurídica',
            'perfil' => 'Advogado',
            'palavras' => 'cij assistente gerador revisor contratos documentos pesquisa biblioteca estratégia ia',
            'conteudo' => "O CIJ reúne assistente jurídico, gerador de peças, revisor, análise de contratos, análise documental, pesquisa jurídica, biblioteca, IA financeira, estratégia jurídica e IA administrativa. As respostas locais e externas exigem validação profissional."
        ],
        [
            'categoria' => 'Boas Práticas',
            'titulo' => 'Segurança e permissões',
            'perfil' => 'Todos',
            'palavras' => 'segurança senha permissões master usuário acesso',
            'conteudo' => "Não compartilhe credenciais. Use somente o perfil autorizado. O MASTER deve revisar acessos e desligamentos. Registros sensíveis devem permanecer protegidos."
        ],
        [
            'categoria' => 'Boas Práticas',
            'titulo' => 'Uso responsável da IA',
            'perfil' => 'Todos',
            'palavras' => 'ia inteligência artificial revisão conferir fontes',
            'conteudo' => "A IA é ferramenta de apoio. Não utilize automaticamente leis, precedentes, fatos ou valores sem conferência. Em documentos jurídicos, valide conteúdo, fontes, prazos e estratégia."
        ],
        [
            'categoria' => 'FAQ',
            'titulo' => 'Por que uma resposta pode vir incompleta?',
            'perfil' => 'Todos',
            'palavras' => 'resposta incompleta modo local ia não conectada',
            'conteudo' => "No modo local seguro, o ROJEX.AI organiza os dados sem consultar uma IA externa. Quando a API estiver configurada, as respostas poderão ser mais elaboradas, mas continuarão exigindo conferência."
        ],
        [
            'categoria' => 'FAQ',
            'titulo' => 'Como recuperar um registro excluído?',
            'perfil' => 'Todos',
            'palavras' => 'lixeira restaurar excluir registro',
            'conteudo' => "Abra a Lixeira no módulo correspondente ou em Configurações. Restaure o registro quando permitido. A exclusão permanente deve ser usada com cautela."
        ],
    ];
}

function cij_academia_filtrar(array $conteudos, string $busca, string $categoria, string $perfil): array
{
    $buscaNormalizada = mb_strtolower(trim($busca), 'UTF-8');

    return array_values(array_filter($conteudos, function (array $item) use ($buscaNormalizada, $categoria, $perfil): bool {
        if ($categoria !== '' && ($item['categoria'] ?? '') !== $categoria) {
            return false;
        }

        if ($perfil !== '' && ($item['perfil'] ?? '') !== $perfil && ($item['perfil'] ?? '') !== 'Todos') {
            return false;
        }

        if ($buscaNormalizada === '') {
            return true;
        }

        $texto = mb_strtolower(
            implode(' ', [
                $item['categoria'] ?? '',
                $item['titulo'] ?? '',
                $item['perfil'] ?? '',
                $item['palavras'] ?? '',
                $item['conteudo'] ?? '',
            ]),
            'UTF-8'
        );

        return str_contains($texto, $buscaNormalizada);
    }));
}

function cij_academia_relatorio(array $resultados, string $busca, string $categoria, string $perfil): string
{
    $linhas = [];
    $linhas[] = 'ACADEMIA ROJEX.AI — RELATÓRIO DE CONTEÚDO';
    $linhas[] = '';
    $linhas[] = 'Pesquisa: ' . ($busca !== '' ? $busca : 'Sem termo específico');
    $linhas[] = 'Categoria: ' . ($categoria !== '' ? $categoria : 'Todas');
    $linhas[] = 'Perfil: ' . ($perfil !== '' ? $perfil : 'Todos');
    $linhas[] = 'Conteúdos encontrados: ' . count($resultados);
    $linhas[] = '';

    foreach ($resultados as $item) {
        $linhas[] = mb_strtoupper((string)($item['titulo'] ?? ''), 'UTF-8');
        $linhas[] = 'Categoria: ' . ($item['categoria'] ?? '-');
        $linhas[] = 'Perfil: ' . ($item['perfil'] ?? '-');
        $linhas[] = trim((string)($item['conteudo'] ?? ''));
        $linhas[] = '';
        $linhas[] = str_repeat('-', 70);
        $linhas[] = '';
    }

    if ($resultados === []) {
        $linhas[] = 'Nenhum conteúdo compatível foi localizado.';
    }

    $linhas[] = 'OBSERVAÇÃO';
    $linhas[] = 'Este material é orientativo e deve acompanhar as regras internas, permissões e procedimentos oficiais do escritório.';

    return implode("\n", $linhas);
}

$conteudos = cij_academia_conteudos();
$busca = trim((string)($_GET['busca'] ?? ''));
$categoria = trim((string)($_GET['categoria'] ?? ''));
$perfil = trim((string)($_GET['perfil'] ?? ''));
$visualizar = max(0, (int)($_GET['visualizar'] ?? 0));

$resultados = cij_academia_filtrar($conteudos, $busca, $categoria, $perfil);
$itemVisualizado = $resultados[$visualizar] ?? null;
$relatorio = cij_academia_relatorio($resultados, $busca, $categoria, $perfil);

$categorias = array_values(array_unique(array_column($conteudos, 'categoria')));
$perfis = array_values(array_unique(array_column($conteudos, 'perfil')));
sort($categorias);
sort($perfis);

if (($busca !== '' || $categoria !== '' || $perfil !== '') && function_exists('sgl_registrar_log')) {
    sgl_registrar_log(
        $conn,
        'CONSULTA_ACADEMIA_CIJ',
        'cij_academia',
        '0',
        'Busca: ' . ($busca !== '' ? $busca : '-')
            . '; Categoria: ' . ($categoria !== '' ? $categoria : '-')
            . '; Perfil: ' . ($perfil !== '' ? $perfil : '-')
    );
}
?>

<div class="container-fluid">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
        <div>
            <h2 class="mb-1 fw-bold text-primary">
                <i class="bi bi-mortarboard me-2"></i>Academia ROJEX.AI
            </h2>
            <p class="text-muted mb-0">
                Centro de capacitação, manual inteligente, boas práticas e apoio operacional.
            </p>
        </div>
        <a href="?mod=cij" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i>Voltar ao CIJ
        </a>
    </div>

    <div class="alert <?= cij_academia_ia_disponivel() ? 'alert-success' : 'alert-warning' ?> border-0 shadow-sm">
        <?php if (cij_academia_ia_disponivel()): ?>
            <strong>IA conectada:</strong> a Academia está preparada para respostas assistidas em etapa futura.
        <?php else: ?>
            <strong>Modo local seguro:</strong> a Academia utiliza conteúdos internos fixos e pesquisáveis.
        <?php endif; ?>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-sm-6 col-xl-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <small class="text-muted d-block">CONTEÚDOS</small>
                    <div class="display-6 fw-bold"><?= count($conteudos) ?></div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <small class="text-muted d-block">CATEGORIAS</small>
                    <div class="display-6 fw-bold text-primary"><?= count($categorias) ?></div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <small class="text-muted d-block">PERFIS</small>
                    <div class="display-6 fw-bold text-success"><?= count($perfis) ?></div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <small class="text-muted d-block">RESULTADOS</small>
                    <div class="display-6 fw-bold text-warning"><?= count($resultados) ?></div>
                </div>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-dark text-white">
            <i class="bi bi-search me-2"></i>Pesquisa da Academia
        </div>
        <div class="card-body">
            <form method="GET" class="row g-3 align-items-end">
                <input type="hidden" name="mod" value="cij">
                <input type="hidden" name="ferramenta" value="academia">

                <div class="col-lg-5">
                    <label class="form-label fw-semibold">Pesquisar assunto</label>
                    <input
                        type="text"
                        name="busca"
                        class="form-control"
                        value="<?= cij_academia_h($busca) ?>"
                        placeholder="Ex.: agenda, financeiro, usuários, IA, documentos...">
                </div>

                <div class="col-md-4 col-lg-3">
                    <label class="form-label fw-semibold">Categoria</label>
                    <select name="categoria" class="form-select">
                        <option value="">Todas</option>
                        <?php foreach ($categorias as $opcao): ?>
                            <option value="<?= cij_academia_h($opcao) ?>" <?= $categoria === $opcao ? 'selected' : '' ?>>
                                <?= cij_academia_h($opcao) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-4 col-lg-2">
                    <label class="form-label fw-semibold">Perfil</label>
                    <select name="perfil" class="form-select">
                        <option value="">Todos</option>
                        <?php foreach ($perfis as $opcao): ?>
                            <option value="<?= cij_academia_h($opcao) ?>" <?= $perfil === $opcao ? 'selected' : '' ?>>
                                <?= cij_academia_h($opcao) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-4 col-lg-2 d-grid">
                    <button class="btn btn-primary">
                        <i class="bi bi-search me-1"></i>Pesquisar
                    </button>
                </div>

                <div class="col-12">
                    <a href="?mod=cij&ferramenta=academia" class="btn btn-outline-secondary btn-sm">
                        <i class="bi bi-x-circle me-1"></i>Limpar filtros
                    </a>
                </div>
            </form>
        </div>
    </div>

    <?php if ($itemVisualizado): ?>
        <div class="card border-0 shadow-sm mb-4" id="conteudoAcademia">
            <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center flex-wrap gap-2">
                <span><i class="bi bi-book me-2"></i><?= cij_academia_h($itemVisualizado['titulo']) ?></span>
                <span class="badge bg-light text-dark"><?= cij_academia_h($itemVisualizado['perfil']) ?></span>
            </div>
            <div class="card-body">
                <div class="mb-3">
                    <span class="badge bg-dark"><?= cij_academia_h($itemVisualizado['categoria']) ?></span>
                </div>
                <textarea id="conteudoAcademiaCij" class="form-control" rows="14"><?= cij_academia_h($itemVisualizado['conteudo']) ?></textarea>
                <div class="d-flex flex-wrap gap-2 mt-3">
                    <button type="button" class="btn btn-outline-primary btn-sm" id="btnCopiarConteudoAcademia">
                        <i class="bi bi-clipboard me-1"></i>Copiar conteúdo
                    </button>
                    <a href="?mod=cij&ferramenta=academia&busca=<?= urlencode($busca) ?>&categoria=<?= urlencode($categoria) ?>&perfil=<?= urlencode($perfil) ?>" class="btn btn-outline-secondary btn-sm">
                        <i class="bi bi-x-circle me-1"></i>Fechar
                    </a>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <div class="row g-4 mb-4">
        <?php if ($resultados === []): ?>
            <div class="col-12">
                <div class="card border-0 shadow-sm">
                    <div class="card-body text-center text-muted py-5">
                        <i class="bi bi-search fs-1 d-block mb-3 opacity-25"></i>
                        <h5 class="fw-bold">Nenhum conteúdo localizado</h5>
                        <p class="mb-0">Tente outro termo, categoria ou perfil.</p>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <?php foreach ($resultados as $indice => $item): ?>
            <div class="col-md-6 col-xl-4">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body d-flex flex-column">
                        <div class="d-flex justify-content-between gap-2 mb-3">
                            <span class="badge bg-primary"><?= cij_academia_h($item['categoria']) ?></span>
                            <span class="badge bg-light text-dark border"><?= cij_academia_h($item['perfil']) ?></span>
                        </div>
                        <h5 class="fw-bold text-primary"><?= cij_academia_h($item['titulo']) ?></h5>
                        <p class="text-muted flex-grow-1">
                            <?= cij_academia_h(mb_strimwidth(
                                str_replace(["\r", "\n"], ' ', $item['conteudo']),
                                0,
                                180,
                                '...',
                                'UTF-8'
                            )) ?>
                        </p>
                        <a
                            href="?mod=cij&ferramenta=academia&busca=<?= urlencode($busca) ?>&categoria=<?= urlencode($categoria) ?>&perfil=<?= urlencode($perfil) ?>&visualizar=<?= $indice ?>"
                            class="btn btn-outline-primary btn-sm align-self-start">
                            <i class="bi bi-book-half me-1"></i>Abrir conteúdo
                        </a>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center flex-wrap gap-2">
            <span><i class="bi bi-file-earmark-text me-2"></i>Relatório da Academia</span>
            <span class="badge bg-primary"><?= count($resultados) ?> conteúdo(s)</span>
        </div>
        <div class="card-body">
            <div class="d-flex flex-wrap gap-2 mb-3">
                <button type="button" class="btn btn-outline-primary btn-sm" id="btnCopiarAcademia">
                    <i class="bi bi-clipboard me-1"></i>Copiar
                </button>
                <button type="button" class="btn btn-outline-success btn-sm" id="btnWordAcademia">
                    <i class="bi bi-file-earmark-word me-1"></i>Baixar Word
                </button>
                <button type="button" class="btn btn-outline-secondary btn-sm" id="btnImprimirAcademia">
                    <i class="bi bi-printer me-1"></i>Imprimir / PDF
                </button>
            </div>

            <textarea id="relatorioAcademiaCij" class="form-control" rows="24"><?= cij_academia_h($relatorio) ?></textarea>

            <div class="alert alert-info mt-3 mb-0">
                <i class="bi bi-info-circle me-1"></i>
                A Academia ROJEX.AI utiliza conteúdo interno orientativo. Procedimentos oficiais do escritório sempre prevalecem.
            </div>
        </div>
    </div>
</div>

<script>
(function(){
    function htmlSeguro(texto) {
        const div = document.createElement('div');
        div.textContent = texto;
        return div.innerHTML;
    }

    const relatorio = document.getElementById('relatorioAcademiaCij');
    const copiar = document.getElementById('btnCopiarAcademia');
    const word = document.getElementById('btnWordAcademia');
    const imprimir = document.getElementById('btnImprimirAcademia');

    copiar?.addEventListener('click', async function(){
        if (!relatorio) return;
        try {
            await navigator.clipboard.writeText(relatorio.value);
            const original = copiar.innerHTML;
            copiar.innerHTML = '<i class="bi bi-check-lg me-1"></i>Copiado';
            setTimeout(function(){ copiar.innerHTML = original; }, 1800);
        } catch (e) {
            relatorio.select();
            document.execCommand('copy');
        }
    });

    word?.addEventListener('click', function(){
        if (!relatorio) return;
        const conteudo = htmlSeguro(relatorio.value).replace(/\n/g, '<br>');
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
        link.download = 'academia_rojex.doc';
        document.body.appendChild(link);
        link.click();
        link.remove();
        URL.revokeObjectURL(url);
    });

    imprimir?.addEventListener('click', function(){
        if (!relatorio) return;
        const janela = window.open('', '_blank', 'width=1000,height=800');

        if (!janela) {
            alert('Autorize pop-ups para imprimir ou salvar em PDF.');
            return;
        }

        const conteudo = htmlSeguro(relatorio.value).replace(/\n/g, '<br>');

        janela.document.write(`<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Academia ROJEX.AI</title>
<style>
@page { size: A4; margin: 3cm 2cm 2cm 3cm; }
body { font-family: "Times New Roman", serif; font-size: 12pt; line-height: 1.5; text-align: justify; color: #000; }
.rodape { margin-top: 25pt; padding-top: 8pt; border-top: 1px solid #999; text-align: center; font-size: 9pt; color: #555; }
</style>
</head>
<body>
<div>${conteudo}</div>
<div class="rodape">Academia ROJEX.AI — Material orientativo interno.</div>
<script>window.onload=function(){window.print();};<\/script>
</body>
</html>`);
        janela.document.close();
    });

    const conteudo = document.getElementById('conteudoAcademiaCij');
    const copiarConteudo = document.getElementById('btnCopiarConteudoAcademia');

    copiarConteudo?.addEventListener('click', async function(){
        if (!conteudo) return;
        try {
            await navigator.clipboard.writeText(conteudo.value);
            const original = copiarConteudo.innerHTML;
            copiarConteudo.innerHTML = '<i class="bi bi-check-lg me-1"></i>Copiado';
            setTimeout(function(){ copiarConteudo.innerHTML = original; }, 1800);
        } catch (e) {
            conteudo.select();
            document.execCommand('copy');
        }
    });

    document.getElementById('conteudoAcademia')?.scrollIntoView({
        behavior: 'smooth',
        block: 'start'
    });
})();
</script>
