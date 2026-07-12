<?php
/**
 * modules/cij/gerador.php
 * Gerador de Peças Jurídicas — ROJEX.AI Enterprise
 * Sprint 4.1.4 — CIJ Operacional — Gerador de Peças Enterprise.
 *
 * Objetivo:
 * - Preservar a arquitetura atual do CIJ.
 * - Não alterar banco de dados nesta etapa.
 * - Usar IA externa quando configurada em config/ia.php.
 * - Manter fallback local seguro quando a API não estiver disponível.
 */

if (!isset($conn) || !($conn instanceof mysqli)) {
    $conn = conectar();
}

$arquivoConfigIa = __DIR__ . '/../../config/ia.php';
if (is_file($arquivoConfigIa)) {
    require_once $arquivoConfigIa;
}

$arquivoBaseConhecimento = __DIR__ . '/../../config/base_conhecimento.php';
if (is_file($arquivoBaseConhecimento)) {
    require_once $arquivoBaseConhecimento;
}

function cij_gerador_h($valor): string
{
    return htmlspecialchars((string)($valor ?? ''), ENT_QUOTES, 'UTF-8');
}

function cij_gerador_table_exists(mysqli $conn, string $tabela): bool
{
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $tabela)) {
        return false;
    }

    try {
        $stmt = $conn->prepare("
            SELECT COUNT(*) AS total
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
        ");
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

function cij_gerador_rows(mysqli $conn, string $sql): array
{
    try {
        $res = $conn->query($sql);
        if (!$res) {
            return [];
        }

        $dados = [];
        while ($row = $res->fetch_assoc()) {
            $dados[] = $row;
        }

        return $dados;
    } catch (Throwable $e) {
        return [];
    }
}

function cij_gerador_one(mysqli $conn, string $sql): ?array
{
    $rows = cij_gerador_rows($conn, $sql);
    return $rows[0] ?? null;
}


function cij_gerador_valor(array $dados, array $chaves, string $padrao = ''): string
{
    foreach ($chaves as $chave) {
        if (isset($dados[$chave]) && trim((string)$dados[$chave]) !== '') {
            return trim((string)$dados[$chave]);
        }
    }
    return $padrao;
}

function cij_gerador_endereco_cliente(?array $cliente): string
{
    if (!$cliente) {
        return '[endereço do cliente]';
    }

    $partes = [];
    foreach (['logradouro', 'endereco', 'rua', 'numero', 'complemento', 'bairro'] as $campo) {
        if (!empty($cliente[$campo])) {
            $partes[] = trim((string)$cliente[$campo]);
        }
    }

    $cidade = cij_gerador_valor($cliente, ['cidade', 'municipio']);
    $uf = cij_gerador_valor($cliente, ['estado', 'uf']);
    $cidadeUf = trim($cidade . ($uf !== '' ? '/' . $uf : ''));
    if ($cidadeUf !== '' && $cidadeUf !== '/') {
        $partes[] = $cidadeUf;
    }

    return $partes ? implode(', ', array_unique($partes)) : '[endereço do cliente]';
}

function cij_gerador_dados_cliente(?array $cliente, string $clienteReferencia = ''): array
{
    if (!$cliente) {
        return [
            'nome' => $clienteReferencia !== '' ? $clienteReferencia : '[nome do cliente]',
            'cpf_cnpj' => '[CPF/CNPJ]',
            'telefone' => '[telefone/whatsapp]',
            'email' => '[e-mail]',
            'endereco' => '[endereço do cliente]',
        ];
    }

    return [
        'nome' => cij_gerador_valor($cliente, ['nome', 'razao_social'], '[nome do cliente]'),
        'cpf_cnpj' => cij_gerador_valor($cliente, ['cpf_cnpj', 'cpf', 'cnpj'], '[CPF/CNPJ]'),
        'telefone' => cij_gerador_valor($cliente, ['whatsapp', 'telefone', 'celular'], '[telefone/whatsapp]'),
        'email' => cij_gerador_valor($cliente, ['email', 'e_mail'], '[e-mail]'),
        'endereco' => cij_gerador_endereco_cliente($cliente),
    ];
}

function cij_gerador_dados_processo(?array $processo): array
{
    if (!$processo) {
        return [
            'numero' => '[número do processo, se houver]',
            'tipo' => '[tipo/assunto do processo]',
            'comarca' => '[comarca/foro]',
            'fase' => '[fase atual]',
            'status' => '[status]',
            'prazo' => '[próximo prazo, se houver]',
            'valor' => '[valor da causa, se houver]',
        ];
    }

    $valor = cij_gerador_valor($processo, ['valor_causa', 'valor']);
    if ($valor !== '' && is_numeric(str_replace(',', '.', $valor))) {
        $valor = 'R$ ' . number_format((float)str_replace(',', '.', $valor), 2, ',', '.');
    }

    $prazo = cij_gerador_valor($processo, ['proximo_prazo', 'prazo', 'data_prazo']);
    if ($prazo !== '' && strtotime($prazo)) {
        $prazo = date('d/m/Y', strtotime($prazo));
    }

    return [
        'numero' => cij_gerador_valor($processo, ['numero_processo', 'processo', 'numero'], '[número do processo, se houver]'),
        'tipo' => cij_gerador_valor($processo, ['tipo_processo', 'tipo', 'assunto'], '[tipo/assunto do processo]'),
        'comarca' => cij_gerador_valor($processo, ['comarca', 'foro', 'vara'], '[comarca/foro]'),
        'fase' => cij_gerador_valor($processo, ['fase_atual', 'fase'], '[fase atual]'),
        'status' => cij_gerador_valor($processo, ['status', 'situacao'], '[status]'),
        'prazo' => $prazo !== '' ? $prazo : '[próximo prazo, se houver]',
        'valor' => $valor !== '' ? $valor : '[valor da causa, se houver]',
    ];
}

function cij_gerador_disponivel(): bool
{
    return function_exists('sgl_ia_disponivel') && sgl_ia_disponivel();
}

function cij_gerador_chamar_ia(string $promptSistema, string $promptUsuario): array
{
    if (function_exists('sgl_ia_chamar_openai')) {
        return sgl_ia_chamar_openai($promptSistema, $promptUsuario);
    }

    return [
        'ok' => false,
        'texto' => '',
        'erro' => 'Função de IA não encontrada em config/ia.php.'
    ];
}

function cij_gerador_prompt(
    string $tipo,
    string $area,
    string $clienteReferencia,
    string $descricaoCaso,
    string $objetivo,
    string $tom,
    ?array $clienteSistema,
    ?array $processoSistema
): string {
    $contexto = [];
    $contexto[] = 'Data: ' . date('d/m/Y');
    $contexto[] = 'Sistema: ROJEX.AI Enterprise';
    $contexto[] = 'Módulo: Centro de Inteligência Jurídica — Gerador de Peças';
    $contexto[] = 'Tipo de documento: ' . ($tipo ?: '[não informado]');
    $contexto[] = 'Área do Direito: ' . ($area ?: '[não informada]');
    $contexto[] = 'Tom redacional: ' . ($tom ?: 'Técnico, objetivo, jurídico e profissional');

    $dadosCliente = cij_gerador_dados_cliente($clienteSistema, $clienteReferencia);
    $dadosProcesso = cij_gerador_dados_processo($processoSistema);

    if ($clienteSistema) {
        $contexto[] = 'DADOS DO CLIENTE VINCULADO:';
        $contexto[] = '- Nome: ' . $dadosCliente['nome'];
        $contexto[] = '- CPF/CNPJ: ' . $dadosCliente['cpf_cnpj'];
        $contexto[] = '- Telefone/WhatsApp: ' . $dadosCliente['telefone'];
        $contexto[] = '- E-mail: ' . $dadosCliente['email'];
        $contexto[] = '- Endereço: ' . $dadosCliente['endereco'];
    }

    if ($processoSistema) {
        $contexto[] = 'DADOS DO PROCESSO VINCULADO:';
        $contexto[] = '- Número: ' . $dadosProcesso['numero'];
        $contexto[] = '- Tipo/assunto: ' . $dadosProcesso['tipo'];
        $contexto[] = '- Comarca/foro: ' . $dadosProcesso['comarca'];
        $contexto[] = '- Fase atual: ' . $dadosProcesso['fase'];
        $contexto[] = '- Status: ' . $dadosProcesso['status'];
        $contexto[] = '- Próximo prazo: ' . $dadosProcesso['prazo'];
        $contexto[] = '- Valor da causa: ' . $dadosProcesso['valor'];
    }

    if ($clienteReferencia !== '') {
        $contexto[] = 'Cliente/referência informada manualmente: ' . $clienteReferencia;
    }

    if ($objetivo !== '') {
        $contexto[] = 'Objetivo da peça: ' . $objetivo;
    }

    $contexto[] = "Descrição do caso:\n" . ($descricaoCaso ?: '[descrever fatos, documentos, riscos, prazos e pedidos]');

    return "Crie uma minuta jurídica completa em português do Brasil com base no contexto abaixo.\n\n"
        . "Regras obrigatórias:\n"
        . "1. Não invente fatos, documentos, números de processo, jurisprudências, artigos ou dados pessoais.\n"
        . "2. Quando faltar informação essencial, use [informar].\n"
        . "3. Estruture a peça com linguagem jurídica clara, profissional e revisável.\n"
        . "4. Inclua alertas de revisão quando houver risco técnico ou falta de dados.\n"
        . "5. A resposta será usada como rascunho para revisão obrigatória de advogado.\n\n"
        . "CONTEXTO:\n"
        . implode("\n", $contexto);
}

function cij_gerador_rascunho_local(
    string $tipo,
    string $area,
    string $clienteReferencia,
    string $descricaoCaso,
    string $objetivo,
    ?array $clienteSistema,
    ?array $processoSistema
): string {
    $dadosCliente = cij_gerador_dados_cliente($clienteSistema, $clienteReferencia);
    $dadosProcesso = cij_gerador_dados_processo($processoSistema);
    $clienteNome = $dadosCliente['nome'];
    $cpfCnpj = $dadosCliente['cpf_cnpj'];
    $endereco = $dadosCliente['endereco'];
    $telefone = $dadosCliente['telefone'];
    $email = $dadosCliente['email'];
    $numeroProcesso = $dadosProcesso['numero'];
    $comarca = $dadosProcesso['comarca'];
    $fase = $dadosProcesso['fase'];
    $statusProcesso = $dadosProcesso['status'];
    $prazoProcesso = $dadosProcesso['prazo'];
    $valorProcesso = $dadosProcesso['valor'];
    $fatos = $descricaoCaso ?: '[descrever os fatos principais, documentos existentes, prazos, riscos e objetivo jurídico]';
    $data = date('d/m/Y');

    return "MINUTA JURÍDICA — {$tipo}\n\n"
        . "Área do Direito: " . ($area ?: '[informar]') . "\n"
        . "Cliente: {$clienteNome}\n"
        . "CPF/CNPJ: {$cpfCnpj}\n"
        . "Processo: {$numeroProcesso}\n"
        . "Comarca/Foro: {$comarca}\n"
        . "Fase atual: {$fase}\n\n"
        . "1. OBJETIVO DA PEÇA\n"
        . ($objetivo ?: '[informar o objetivo jurídico da peça]') . "\n\n"
        . "2. SÍNTESE DOS FATOS\n"
        . "{$fatos}\n\n"
        . "3. FUNDAMENTAÇÃO JURÍDICA — BASE PARA REVISÃO\n"
        . "A fundamentação deverá ser construída conforme a legislação aplicável, documentos disponíveis, provas existentes e estratégia definida pelo advogado responsável. "
        . "Não foram inseridos artigos, precedentes ou jurisprudências específicas neste modo local para evitar informações não conferidas.\n\n"
        . "4. PEDIDOS / PROVIDÊNCIAS SUGERIDAS\n"
        . "a) Recebimento da presente manifestação/documento;\n"
        . "b) Reconhecimento dos fatos e direitos demonstrados, conforme documentação do caso;\n"
        . "c) Produção ou juntada das provas cabíveis;\n"
        . "d) Intimações, citações ou providências processuais/administrativas pertinentes;\n"
        . "e) Demais pedidos adequados à estratégia do advogado.\n\n"
        . "5. DOCUMENTOS E INFORMAÇÕES PENDENTES\n"
        . "[ ] Qualificação completa das partes\n"
        . "[ ] Procuração\n"
        . "[ ] Documentos pessoais\n"
        . "[ ] Comprovantes/documentos específicos do caso\n"
        . "[ ] Dados do processo, prazos e movimentações atualizadas\n\n"
        . "6. ALERTA DE REVISÃO\n"
        . "Este texto é um rascunho inteligente local. Deve ser revisado por advogado antes de uso externo, protocolo, envio ao cliente ou assinatura.\n\n"
        . "Data: {$data}\n\n"
        . "__________________________________\n"
        . "Advogado(a)\n"
        . "OAB [informar]";
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
    'Documento Personalizado'
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
    'Contratual'
];

$clientesSistema = [];
if (function_exists('rojex_kb_tabela_existe') && rojex_kb_tabela_existe($conn, 'clientes')) {
    $colunasClientes = rojex_kb_colunas_tabela($conn, 'clientes');
    $filtroClientes = in_array('deletado', $colunasClientes, true)
        ? ' WHERE COALESCE(deletado, 0) = 0'
        : ' WHERE 1 = 1';

    $clientesSistema = rojex_kb_consultar(
        $conn,
        'SELECT id, nome, ' . (in_array('cpf_cnpj', $colunasClientes, true) ? 'cpf_cnpj' : "'' AS cpf_cnpj") .
        ' FROM clientes' . $filtroClientes . ' ORDER BY nome ASC LIMIT 300'
    );
}

$processosSistema = [];
if (function_exists('rojex_kb_tabela_existe') && rojex_kb_tabela_existe($conn, 'processos')) {
    $colunasProcessos = rojex_kb_colunas_tabela($conn, 'processos');
    $temClientesGerador = rojex_kb_tabela_existe($conn, 'clientes')
        && in_array('cliente_id', $colunasProcessos, true);

    $joinClienteGerador = $temClientesGerador
        ? ' LEFT JOIN clientes c ON c.id = p.cliente_id'
        : '';

    $filtroProcessos = in_array('deletado', $colunasProcessos, true)
        ? ' WHERE COALESCE(p.deletado, 0) = 0'
        : ' WHERE 1 = 1';

    $processosSistema = rojex_kb_consultar(
        $conn,
        'SELECT p.id, '
        . (in_array('cliente_id', $colunasProcessos, true) ? 'p.cliente_id' : "NULL AS cliente_id") . ', '
        . (in_array('numero_processo', $colunasProcessos, true) ? 'p.numero_processo' : "'' AS numero_processo") . ', '
        . (in_array('tipo_processo', $colunasProcessos, true) ? 'p.tipo_processo' : "'' AS tipo_processo") . ', '
        . (in_array('comarca', $colunasProcessos, true) ? 'p.comarca' : "'' AS comarca") . ', '
        . (in_array('fase_atual', $colunasProcessos, true) ? 'p.fase_atual' : "'' AS fase_atual") . ', '
        . (in_array('status', $colunasProcessos, true) ? 'p.status' : "'' AS status") . ', '
        . ($temClientesGerador ? "COALESCE(c.nome, '') AS cliente_nome" : "'' AS cliente_nome")
        . ' FROM processos p' . $joinClienteGerador . $filtroProcessos
        . ' ORDER BY p.id DESC LIMIT 300'
    );
}

$tipo = trim((string)($_POST['tipo_documento'] ?? ''));
$area = trim((string)($_POST['area_direito'] ?? ''));
$clienteReferencia = trim((string)($_POST['cliente_referencia'] ?? ''));
$descricaoCaso = trim((string)($_POST['descricao_caso'] ?? ''));
$objetivo = trim((string)($_POST['objetivo_peca'] ?? ''));
$tom = trim((string)($_POST['tom_redacional'] ?? 'Técnico, objetivo, jurídico e profissional'));
$clienteId = trim((string)($_POST['cliente_id'] ?? '0'));
$processoId = (int)($_POST['processo_id'] ?? 0);

$gerado = false;
$respostaIa = '';
$modoResposta = '';
$erroIa = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao_gerador'] ?? '') === 'gerar_peca_ia') {
    $gerado = true;

    if ($tipo === '' || $area === '' || $descricaoCaso === '') {
        $gerado = false;
        $erroIa = 'Preencha o tipo de documento, a área do Direito e a descrição do caso.';
    }

    $clienteSistema = null;
    $processoSistema = null;

    if (
        $clienteId !== ''
        && $clienteId !== '0'
        && function_exists('rojex_kb_tabela_existe')
        && rojex_kb_tabela_existe($conn, 'clientes')
    ) {
        $colunasClienteSelecionado = rojex_kb_colunas_tabela($conn, 'clientes');
        $filtroClienteSelecionado = in_array('deletado', $colunasClienteSelecionado, true)
            ? ' AND COALESCE(deletado, 0) = 0'
            : '';

        $clienteSistema = rojex_kb_consultar_um(
            $conn,
            'SELECT * FROM clientes WHERE id = ?' . $filtroClienteSelecionado . ' LIMIT 1',
            's',
            [$clienteId]
        );
    }

    if (
        $processoId > 0
        && function_exists('rojex_kb_tabela_existe')
        && rojex_kb_tabela_existe($conn, 'processos')
    ) {
        $colunasProcessoSelecionado = rojex_kb_colunas_tabela($conn, 'processos');
        $temClienteProcesso = rojex_kb_tabela_existe($conn, 'clientes')
            && in_array('cliente_id', $colunasProcessoSelecionado, true);
        $joinClienteProcesso = $temClienteProcesso
            ? ' LEFT JOIN clientes c ON c.id = p.cliente_id'
            : '';
        $campoNomeCliente = $temClienteProcesso
            ? ", COALESCE(c.nome, '') AS cliente_nome"
            : ", '' AS cliente_nome";
        $filtroProcessoSelecionado = in_array('deletado', $colunasProcessoSelecionado, true)
            ? ' AND COALESCE(p.deletado, 0) = 0'
            : '';

        $processoSistema = rojex_kb_consultar_um(
            $conn,
            'SELECT p.*' . $campoNomeCliente
            . ' FROM processos p' . $joinClienteProcesso
            . ' WHERE p.id = ?' . $filtroProcessoSelecionado . ' LIMIT 1',
            'i',
            [$processoId]
        );

        if (!$clienteSistema && !empty($processoSistema['cliente_id'])) {
            $clienteProcessoId = (string)$processoSistema['cliente_id'];
            $colunasClienteProcesso = rojex_kb_colunas_tabela($conn, 'clientes');
            $filtroClienteProcesso = in_array('deletado', $colunasClienteProcesso, true)
                ? ' AND COALESCE(deletado, 0) = 0'
                : '';

            $clienteSistema = rojex_kb_consultar_um(
                $conn,
                'SELECT * FROM clientes WHERE id = ?' . $filtroClienteProcesso . ' LIMIT 1',
                's',
                [$clienteProcessoId]
            );
        }
    }

    if ($gerado) {
    $promptSistema = 'Você é o assistente jurídico do ROJEX.AI Enterprise. Responda em português do Brasil. Não invente fatos, artigos, jurisprudências ou dados ausentes. Use [informar] quando faltar dado. O texto é sempre rascunho para revisão obrigatória de advogado.';
    $promptUsuario = cij_gerador_prompt($tipo, $area, $clienteReferencia, $descricaoCaso, $objetivo, $tom, $clienteSistema, $processoSistema);

    $retornoIa = cij_gerador_chamar_ia($promptSistema, $promptUsuario);

    if (($retornoIa['ok'] ?? false) === true) {
        $respostaIa = (string)($retornoIa['texto'] ?? '');
        $modoResposta = 'IA conectada';
    } else {
        $erroIa = (string)($retornoIa['erro'] ?? 'API indisponível ou não configurada.');
        $respostaIa = cij_gerador_rascunho_local($tipo, $area, $clienteReferencia, $descricaoCaso, $objetivo, $clienteSistema, $processoSistema);
        $modoResposta = 'Rascunho inteligente local';
    }

    if (function_exists('sgl_registrar_log')) {
        sgl_registrar_log(
            $conn,
            'GEROU_PECA_CIJ',
            'cij_gerador',
            '0',
            ($tipo ?: 'Documento') . ' - ' . $modoResposta
        );
    }
    }
}
?>

<div class="container-fluid">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
        <div>
            <h2 class="mb-1 fw-bold text-primary">
                <i class="bi bi-pencil-square me-2"></i>Gerador Inteligente de Peças
            </h2>
            <p class="text-muted mb-0">
                Criação assistida de petições, contratos, notificações, pareceres e documentos jurídicos com IA ou rascunho local seguro.
            </p>
        </div>
        <a href="?mod=cij" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i>Voltar ao CIJ
        </a>
    </div>

    <div class="alert <?= cij_gerador_disponivel() ? 'alert-success' : 'alert-warning' ?> border-0 shadow-sm">
        <?php if (cij_gerador_disponivel()): ?>
            <strong>IA conectada:</strong> o Gerador de Peças está pronto para usar a API configurada no ROJEX.AI.
        <?php else: ?>
            <strong>Modo rascunho inteligente:</strong> a API externa ainda não está ativa ou configurada. O sistema continuará gerando estrutura jurídica local com segurança.
        <?php endif; ?>
    </div>

    <?php if ($erroIa !== ''): ?>
        <div class="alert alert-info border-0 shadow-sm">
            <strong>Observação técnica:</strong> <?= cij_gerador_h($erroIa) ?>
        </div>
    <?php endif; ?>

    <div class="row g-4">
        <div class="col-lg-5">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-dark text-white">
                    <i class="bi bi-file-earmark-plus me-2"></i>Dados para criação da peça
                </div>
                <div class="card-body">
                    <form method="POST" class="row g-3">
                        <input type="hidden" name="acao_gerador" value="gerar_peca_ia">

                        <div class="col-12">
                            <label class="form-label fw-semibold">Tipo de documento</label>
                            <select name="tipo_documento" class="form-select" required>
                                <option value="">Selecione...</option>
                                <?php foreach ($tiposDocumento as $opcao): ?>
                                    <option value="<?= cij_gerador_h($opcao) ?>" <?= $tipo === $opcao ? 'selected' : '' ?>>
                                        <?= cij_gerador_h($opcao) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-12">
                            <label class="form-label fw-semibold">Área do Direito</label>
                            <select name="area_direito" class="form-select" required>
                                <option value="">Selecione...</option>
                                <?php foreach ($areasDireito as $opcao): ?>
                                    <option value="<?= cij_gerador_h($opcao) ?>" <?= $area === $opcao ? 'selected' : '' ?>>
                                        <?= cij_gerador_h($opcao) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <?php if (!empty($clientesSistema)): ?>
                            <div class="col-12">
                                <label class="form-label fw-semibold">Cliente cadastrado</label>
                                <select name="cliente_id" class="form-select">
                                    <option value="0">Não vincular cliente</option>
                                    <?php foreach ($clientesSistema as $clienteOpcao): ?>
                                        <option value="<?= cij_gerador_h($clienteOpcao['id']) ?>" <?= $clienteId === (string)$clienteOpcao['id'] ? 'selected' : '' ?>>
                                            <?= cij_gerador_h($clienteOpcao['nome'] . (!empty($clienteOpcao['cpf_cnpj']) ? ' - ' . $clienteOpcao['cpf_cnpj'] : '')) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($processosSistema)): ?>
                            <div class="col-12">
                                <label class="form-label fw-semibold">Processo cadastrado</label>
                                <select name="processo_id" class="form-select">
                                    <option value="0">Não vincular processo</option>
                                    <?php foreach ($processosSistema as $processoOpcao): ?>
                                        <option value="<?= (int)$processoOpcao['id'] ?>" data-cliente="<?= cij_gerador_h($processoOpcao['cliente_id'] ?? '0') ?>" <?= $processoId === (int)$processoOpcao['id'] ? 'selected' : '' ?>>
                                            <?= cij_gerador_h(($processoOpcao['numero_processo'] ?: 'Sem número') . ' - ' . ($processoOpcao['cliente_nome'] ?: 'Sem cliente')) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        <?php endif; ?>

                        <div class="col-12">
                            <label class="form-label fw-semibold">Cliente / referência manual</label>
                            <input type="text" name="cliente_referencia" class="form-control" value="<?= cij_gerador_h($clienteReferencia) ?>" placeholder="Ex.: João da Silva, processo 0000000-00.0000.0.00.0000">
                            <div class="form-text">Use este campo quando não quiser vincular cliente/processo cadastrado.</div>
                        </div>

                        <div class="col-12">
                            <label class="form-label fw-semibold">Objetivo da peça</label>
                            <input type="text" name="objetivo_peca" class="form-control" value="<?= cij_gerador_h($objetivo) ?>" placeholder="Ex.: elaborar petição inicial, contestar pedido, revisar contrato...">
                        </div>

                        <div class="col-12">
                            <label class="form-label fw-semibold">Descreva o caso</label>
                            <textarea name="descricao_caso" class="form-control" rows="8" required placeholder="Informe os fatos principais, pedidos, documentos existentes, riscos, prazos e objetivo da peça."><?= cij_gerador_h($descricaoCaso) ?></textarea>
                        </div>

                        <div class="col-12">
                            <label class="form-label fw-semibold">Tom redacional</label>
                            <input type="text" name="tom_redacional" class="form-control" value="<?= cij_gerador_h($tom) ?>">
                        </div>

                        <div class="col-12 d-grid">
                            <button class="btn btn-primary btn-lg">
                                <i class="bi bi-magic me-1"></i>Gerar peça inteligente
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-7">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-file-text me-2"></i>Resultado gerado</span>
                    <?php if ($gerado): ?>
                        <span class="badge bg-primary"><?= cij_gerador_h($modoResposta) ?></span>
                    <?php else: ?>
                        <span class="badge bg-secondary">Aguardando dados</span>
                    <?php endif; ?>
                </div>
                <div class="card-body">
                    <?php if (!$gerado): ?>
                        <div class="text-center text-muted py-5">
                            <i class="bi bi-file-earmark-text fs-1 d-block mb-3 opacity-25"></i>
                            <h5 class="fw-bold">Nenhuma peça gerada ainda</h5>
                            <p class="mb-0">Preencha os dados ao lado e clique em gerar peça inteligente.</p>
                        </div>
                    <?php else: ?>
                        <div class="d-flex flex-wrap gap-2 mb-3">
                            <button type="button" class="btn btn-outline-primary btn-sm" id="btnCopiarPeca">
                                <i class="bi bi-clipboard me-1"></i>Copiar
                            </button>
                            <button type="button" class="btn btn-outline-success btn-sm" id="btnBaixarPeca">
                                <i class="bi bi-file-earmark-word me-1"></i>Baixar Word
                            </button>
                            <button type="button" class="btn btn-outline-secondary btn-sm" id="btnImprimirPeca">
                                <i class="bi bi-printer me-1"></i>Imprimir / PDF
                            </button>
                            <a href="?mod=cij&ferramenta=gerador" class="btn btn-outline-dark btn-sm">
                                <i class="bi bi-plus-circle me-1"></i>Nova peça
                            </a>
                        </div>

                        <label for="resultadoGeradorIA" class="form-label fw-semibold">Minuta editável</label>
                        <textarea id="resultadoGeradorIA" class="form-control" rows="24" style="line-height:1.65;"><?= cij_gerador_h($respostaIa) ?></textarea>
                        <div class="form-text">Revise e ajuste a minuta diretamente neste campo antes de copiar, baixar ou imprimir.</div>

                        <div class="alert alert-warning mt-3 mb-0">
                            <i class="bi bi-exclamation-triangle me-1"></i>
                            Conteúdo gerado como rascunho. Revisão obrigatória por advogado antes de protocolo, envio ao cliente ou assinatura.
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm mt-4">
        <div class="card-header bg-dark text-white">
            <i class="bi bi-diagram-3 me-2"></i>Fluxo Enterprise do Gerador de Peças
        </div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-3"><div class="border rounded p-3 h-100"><strong>1. Dados do caso</strong><br><small class="text-muted">Cliente, processo, fatos e objetivos.</small></div></div>
                <div class="col-md-3"><div class="border rounded p-3 h-100"><strong>2. IA redacional</strong><br><small class="text-muted">Criação da minuta jurídica.</small></div></div>
                <div class="col-md-3"><div class="border rounded p-3 h-100"><strong>3. Revisão humana</strong><br><small class="text-muted">Validação pelo advogado.</small></div></div>
                <div class="col-md-3"><div class="border rounded p-3 h-100"><strong>4. Biblioteca</strong><br><small class="text-muted">Salvar modelo aprovado em etapa futura.</small></div></div>
            </div>
        </div>
    </div>
</div>

<script>
(function(){
    const clienteSelect = document.querySelector('select[name="cliente_id"]');
    const processoSelect = document.querySelector('select[name="processo_id"]');
    if (!clienteSelect || !processoSelect) return;

    function filtrarProcessos(){
        const clienteId = clienteSelect.value || '0';
        let primeiroVisivel = null;
        Array.from(processoSelect.options).forEach(function(opt){
            if (opt.value === '0') { opt.hidden = false; return; }
            const optCliente = opt.getAttribute('data-cliente') || '0';
            const mostrar = clienteId === '0' || optCliente === clienteId;
            opt.hidden = !mostrar;
            if (mostrar && !primeiroVisivel) primeiroVisivel = opt;
        });
        const selecionado = processoSelect.options[processoSelect.selectedIndex];
        if (selecionado && selecionado.hidden) {
            processoSelect.value = '0';
        }
    }

    clienteSelect.addEventListener('change', filtrarProcessos);
    processoSelect.addEventListener('change', function(){
        const selecionado = processoSelect.options[processoSelect.selectedIndex];
        if (!selecionado || selecionado.value === '0') return;
        const clienteDoProcesso = selecionado.getAttribute('data-cliente') || '0';
        if (clienteDoProcesso !== '0') {
            clienteSelect.value = clienteDoProcesso;
            filtrarProcessos();
            processoSelect.value = selecionado.value;
        }
    });
    filtrarProcessos();

    const resultado = document.getElementById('resultadoGeradorIA');
    const btnCopiar = document.getElementById('btnCopiarPeca');
    const btnBaixar = document.getElementById('btnBaixarPeca');
    const btnImprimir = document.getElementById('btnImprimirPeca');

    if (resultado && btnCopiar) {
        btnCopiar.addEventListener('click', async function(){
            try {
                await navigator.clipboard.writeText(resultado.value);
                const textoOriginal = btnCopiar.innerHTML;
                btnCopiar.innerHTML = '<i class="bi bi-check2 me-1"></i>Copiado';
                setTimeout(function(){ btnCopiar.innerHTML = textoOriginal; }, 1800);
            } catch (e) {
                resultado.select();
                document.execCommand('copy');
            }
        });
    }

    if (resultado && btnBaixar) {
        btnBaixar.addEventListener('click', function(){
            const texto = resultado.value.trim();
            if (texto === '') {
                alert('Não há conteúdo para baixar.');
                return;
            }

            const escaparHtml = function(valor){
                return valor
                    .replace(/&/g, '&amp;')
                    .replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;')
                    .replace(/"/g, '&quot;')
                    .replace(/'/g, '&#039;');
            };

            const linhas = texto.replace(/\r\n/g, '\n').replace(/\r/g, '\n').split('\n');
            const blocos = linhas.map(function(linha){
                const limpa = linha.trim();
                if (limpa === '') return '<p class="espaco">&nbsp;</p>';

                const ehTitulo = /^(MINUTA|EXCELENTÍSSIMO|EXCELENTISSIMO|AO JUÍZO|AO JUIZO|[0-9]+\.|[IVXLCDM]+\.)/i.test(limpa)
                    || (limpa.length <= 90 && limpa === limpa.toUpperCase());
                const ehLista = /^(\[[ xX]?\]|[a-z]\)|[-•])\s*/.test(limpa);
                const classe = ehTitulo ? 'titulo' : (ehLista ? 'lista' : 'paragrafo');
                return '<p class="' + classe + '">' + escaparHtml(limpa) + '</p>';
            }).join('');

            const htmlWord = `<!DOCTYPE html>
<html xmlns:o="urn:schemas-microsoft-com:office:office"
      xmlns:w="urn:schemas-microsoft-com:office:word"
      xmlns="http://www.w3.org/TR/REC-html40">
<head>
<meta charset="UTF-8">
<meta name="ProgId" content="Word.Document">
<meta name="Generator" content="ROJEX.AI Enterprise">
<title>Minuta Jurídica ROJEX.AI</title>
<style>
@page WordSection1 {
    size: 595.3pt 841.9pt;
    margin: 85.05pt 56.7pt 56.7pt 85.05pt;
}
div.WordSection1 { page: WordSection1; }
body {
    font-family: "Times New Roman", serif;
    font-size: 12pt;
    line-height: 1.5;
    color: #000;
}
p {
    margin: 0 0 6pt 0;
    text-align: justify;
}
p.paragrafo {
    text-indent: 1.25cm;
}
p.titulo {
    text-indent: 0;
    font-weight: bold;
    margin-top: 12pt;
    margin-bottom: 6pt;
}
p.lista {
    text-indent: 0;
    margin-left: 0;
}
p.espaco {
    margin: 0;
    line-height: 6pt;
}
.rodape {
    margin-top: 18pt;
    padding-top: 6pt;
    border-top: 1px solid #999;
    font-family: Arial, sans-serif;
    font-size: 8pt;
    text-align: center;
    color: #555;
}
</style>
</head>
<body>
<div class="WordSection1">
${blocos}
<p class="rodape">Documento gerado como minuta. Revisão obrigatória por advogado antes de protocolo, assinatura ou envio externo.</p>
</div>
</body>
</html>`;

            const blob = new Blob(['\ufeff', htmlWord], {type: 'application/msword;charset=utf-8'});
            const url = URL.createObjectURL(blob);
            const link = document.createElement('a');
            link.href = url;
            link.download = 'minuta-juridica-rojex-ai.doc';
            document.body.appendChild(link);
            link.click();
            link.remove();
            URL.revokeObjectURL(url);
        });
    }


    if (resultado && btnImprimir) {
        btnImprimir.addEventListener('click', function(){
            const texto = resultado.value.trim();
            if (texto === '') {
                alert('Não há conteúdo para imprimir.');
                return;
            }

            const escaparHtml = function(valor){
                return valor
                    .replace(/&/g, '&amp;')
                    .replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;')
                    .replace(/"/g, '&quot;')
                    .replace(/'/g, '&#039;');
            };

            const linhas = texto.replace(/\r\n/g, '\n').replace(/\r/g, '\n').split('\n');
            const conteudo = linhas.map(function(linha){
                const limpa = linha.trim();
                if (limpa === '') return '<p class="espaco">&nbsp;</p>';

                const ehTitulo = /^(MINUTA|EXCELENTÍSSIMO|EXCELENTISSIMO|AO JUÍZO|AO JUIZO|[0-9]+\.|[IVXLCDM]+\.)/i.test(limpa)
                    || (limpa.length <= 90 && limpa === limpa.toUpperCase());
                const ehLista = /^(\[[ xX]?\]|[a-z]\)|[-•])\s*/.test(limpa);
                const classe = ehTitulo ? 'titulo' : (ehLista ? 'lista' : 'paragrafo');
                return '<p class="' + classe + '">' + escaparHtml(limpa) + '</p>';
            }).join('');

            const janela = window.open('', '_blank', 'width=980,height=760');
            if (!janela) {
                alert('O navegador bloqueou a janela de impressão. Permita pop-ups para este endereço e tente novamente.');
                return;
            }

            janela.document.open();
            janela.document.write(`<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Minuta Jurídica — ROJEX.AI</title>
    <style>
        @page {
            size: A4 portrait;
            margin: 3cm 2cm 2cm 3cm;
        }

        * {
            box-sizing: border-box;
        }

        html, body {
            margin: 0;
            padding: 0;
            background: #fff;
            color: #000;
        }

        body {
            font-family: "Times New Roman", Times, serif;
            font-size: 12pt;
            line-height: 1.5;
        }

        .documento {
            width: 100%;
            min-height: 100%;
            text-align: justify;
            overflow-wrap: break-word;
            word-break: normal;
        }

        .cabecalho-impressao {
            display: none;
        }

        .conteudo {
            white-space: normal;
        }

        .conteudo p {
            margin: 0 0 6pt 0;
            text-align: justify;
        }

        .conteudo p.paragrafo {
            text-indent: 1.25cm;
        }

        .conteudo p.titulo {
            text-indent: 0;
            font-weight: bold;
            margin-top: 12pt;
            margin-bottom: 6pt;
        }

        .conteudo p.lista {
            text-indent: 0;
        }

        .conteudo p.espaco {
            margin: 0;
            line-height: 6pt;
        }

        .rodape-revisao {
            margin-top: 24px;
            padding-top: 10px;
            border-top: 1px solid #999;
            font-family: Arial, sans-serif;
            font-size: 8.5pt;
            color: #555;
            text-align: center;
        }

        @media screen {
            body {
                background: #e9ecef;
                padding: 24px;
            }

            .documento {
                max-width: 21cm;
                min-height: 29.7cm;
                margin: 0 auto;
                padding: 3cm 2cm 2cm 3cm;
                background: #fff;
                box-shadow: 0 2px 18px rgba(0,0,0,.18);
            }

            .cabecalho-impressao {
                display: flex;
                justify-content: space-between;
                align-items: center;
                margin-bottom: 20px;
                padding-bottom: 10px;
                border-bottom: 1px solid #bbb;
                font-family: Arial, sans-serif;
                font-size: 9pt;
                color: #555;
            }
        }

        @media print {
            .documento {
                min-height: auto;
            }

            .rodape-revisao {
                break-inside: avoid;
            }
        }
    </style>
</head>
<body>
    <main class="documento">
        <div class="cabecalho-impressao">
            <strong>ROJEX.AI — Minuta Jurídica</strong>
            <span>Pré-visualização para impressão</span>
        </div>
        <div class="conteudo">${conteudo}</div>
        <div class="rodape-revisao">
            Documento gerado como minuta. Revisão obrigatória por advogado antes de protocolo, assinatura ou envio externo.
        </div>
    </main>
    <script>
        window.addEventListener('load', function(){
            setTimeout(function(){ window.print(); }, 250);
        });
    <\/script>
</body>
</html>`);
            janela.document.close();
        });
    }
})();
</script>

