<?php
/**
 * SGL Advocacia - Fase 4.1
 * Biblioteca Jurídica Profissional: modelos, variáveis, favoritos, versionamento e geração.
 */
$conn = conectar();
require_once __DIR__ . '/../config/integracoes.php';
$csrf = gerarTokenCsrf();

function sgl_mod_coluna_existe(mysqli $conn, string $tabela, string $coluna): bool {
    $tabela = preg_replace('/[^a-zA-Z0-9_]/', '', $tabela);
    $colunaEsc = $conn->real_escape_string($coluna);
    $res = $conn->query("SHOW COLUMNS FROM `{$tabela}` LIKE '{$colunaEsc}'");
    return $res && $res->num_rows > 0;
}
function sgl_mod_garantir_coluna(mysqli $conn, string $tabela, string $coluna, string $definicao): void {
    if (!sgl_mod_coluna_existe($conn, $tabela, $coluna)) {
        if (!$conn->query("ALTER TABLE `{$tabela}` ADD COLUMN {$definicao}")) {
            throw new RuntimeException(
                "Falha ao garantir a coluna {$tabela}.{$coluna}: " . $conn->error
            );
        }
    }
}
function sgl_modelos_garantir_tabelas(mysqli $conn): void {
    $conn->query("CREATE TABLE IF NOT EXISTS modelos_documentos (
        id INT AUTO_INCREMENT PRIMARY KEY,
        codigo VARCHAR(20) NOT NULL UNIQUE,
        titulo VARCHAR(180) NOT NULL,
        categoria VARCHAR(80) NOT NULL DEFAULT 'Outros',
        area_direito VARCHAR(80) NULL,
        conteudo LONGTEXT NOT NULL,
        observacoes TEXT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'Ativo',
        criado_por INT NULL,
        atualizado_por INT NULL,
        deletado TINYINT(1) NOT NULL DEFAULT 0,
        criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_modelos_categoria (categoria),
        INDEX idx_modelos_status (status),
        INDEX idx_modelos_deletado (deletado)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    // Compatibilidade completa com bancos antigos/restaurados: garante as colunas usadas pelo módulo principal.
    sgl_mod_garantir_coluna($conn, 'modelos_documentos', 'codigo', "codigo VARCHAR(20) NULL AFTER id");
    sgl_mod_garantir_coluna($conn, 'modelos_documentos', 'titulo', "titulo VARCHAR(180) NOT NULL DEFAULT 'Modelo sem título' AFTER codigo");
    sgl_mod_garantir_coluna($conn, 'modelos_documentos', 'categoria', "categoria VARCHAR(80) NOT NULL DEFAULT 'Outros' AFTER titulo");
    sgl_mod_garantir_coluna($conn, 'modelos_documentos', 'area_direito', "area_direito VARCHAR(80) NULL AFTER categoria");
    sgl_mod_garantir_coluna($conn, 'modelos_documentos', 'conteudo', "conteudo LONGTEXT NULL AFTER area_direito");
    sgl_mod_garantir_coluna($conn, 'modelos_documentos', 'observacoes', "observacoes TEXT NULL AFTER conteudo");
    sgl_mod_garantir_coluna($conn, 'modelos_documentos', 'status', "status VARCHAR(20) NOT NULL DEFAULT 'Ativo' AFTER observacoes");
    sgl_mod_garantir_coluna($conn, 'modelos_documentos', 'favorito', "favorito TINYINT(1) NOT NULL DEFAULT 0 AFTER status");
    sgl_mod_garantir_coluna($conn, 'modelos_documentos', 'versao_atual', "versao_atual INT NOT NULL DEFAULT 1 AFTER favorito");
    sgl_mod_garantir_coluna($conn, 'modelos_documentos', 'criado_por', "criado_por INT NULL AFTER versao_atual");
    sgl_mod_garantir_coluna($conn, 'modelos_documentos', 'atualizado_por', "atualizado_por INT NULL AFTER criado_por");
    sgl_mod_garantir_coluna($conn, 'modelos_documentos', 'ultimo_uso_em', "ultimo_uso_em DATETIME NULL AFTER atualizado_por");
    sgl_mod_garantir_coluna($conn, 'modelos_documentos', 'deletado', "deletado TINYINT(1) NOT NULL DEFAULT 0 AFTER ultimo_uso_em");
    sgl_mod_garantir_coluna($conn, 'modelos_documentos', 'criado_em', "criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP AFTER deletado");
    sgl_mod_garantir_coluna($conn, 'modelos_documentos', 'atualizado_em', "atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER criado_em");

    $conn->query("CREATE TABLE IF NOT EXISTS modelos_documentos_versoes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        modelo_id INT NOT NULL,
        versao INT NOT NULL,
        titulo VARCHAR(180) NOT NULL,
        categoria VARCHAR(80) NOT NULL,
        area_direito VARCHAR(80) NULL,
        conteudo LONGTEXT NOT NULL,
        observacoes TEXT NULL,
        criado_por INT NULL,
        criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_modelo_versao (modelo_id, versao)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Compatibilidade com bancos antigos/restaurados: garante a estrutura completa do versionamento.
    sgl_mod_garantir_coluna($conn, 'modelos_documentos_versoes', 'modelo_id', "modelo_id INT NOT NULL DEFAULT 0 AFTER id");
    sgl_mod_garantir_coluna($conn, 'modelos_documentos_versoes', 'versao', "versao INT NOT NULL DEFAULT 1 AFTER modelo_id");
    sgl_mod_garantir_coluna($conn, 'modelos_documentos_versoes', 'titulo', "titulo VARCHAR(180) NOT NULL DEFAULT 'Modelo sem título' AFTER versao");
    sgl_mod_garantir_coluna($conn, 'modelos_documentos_versoes', 'categoria', "categoria VARCHAR(80) NOT NULL DEFAULT 'Outros' AFTER titulo");
    sgl_mod_garantir_coluna($conn, 'modelos_documentos_versoes', 'area_direito', "area_direito VARCHAR(80) NULL AFTER categoria");
    sgl_mod_garantir_coluna($conn, 'modelos_documentos_versoes', 'conteudo', "conteudo LONGTEXT NULL AFTER area_direito");
    sgl_mod_garantir_coluna($conn, 'modelos_documentos_versoes', 'observacoes', "observacoes TEXT NULL AFTER conteudo");
    sgl_mod_garantir_coluna($conn, 'modelos_documentos_versoes', 'criado_por', "criado_por INT NULL AFTER observacoes");
    sgl_mod_garantir_coluna($conn, 'modelos_documentos_versoes', 'criado_em', "criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP AFTER criado_por");

    $conn->query("CREATE TABLE IF NOT EXISTS modelos_documentos_gerados (
        id INT AUTO_INCREMENT PRIMARY KEY,
        modelo_id INT NOT NULL,
        cliente_id INT NULL,
        processo_id INT NULL,
        titulo VARCHAR(180) NOT NULL,
        conteudo_final LONGTEXT NOT NULL,
        gerado_por INT NULL,
        gerado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_gerados_modelo (modelo_id),
        INDEX idx_gerados_cliente (cliente_id),
        INDEX idx_gerados_processo (processo_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Compatibilidade com bancos restaurados/antigos: garante colunas usadas abaixo.
    sgl_mod_garantir_coluna($conn, 'modelos_documentos_gerados', 'modelo_id', "modelo_id INT NOT NULL DEFAULT 0 AFTER id");
    sgl_mod_garantir_coluna($conn, 'modelos_documentos_gerados', 'cliente_id', "cliente_id INT NULL AFTER modelo_id");
    sgl_mod_garantir_coluna($conn, 'modelos_documentos_gerados', 'processo_id', "processo_id INT NULL AFTER cliente_id");
    sgl_mod_garantir_coluna($conn, 'modelos_documentos_gerados', 'titulo', "titulo VARCHAR(180) NOT NULL DEFAULT 'Documento gerado' AFTER processo_id");
    sgl_mod_garantir_coluna($conn, 'modelos_documentos_gerados', 'conteudo_final', "conteudo_final LONGTEXT NULL AFTER titulo");
    sgl_mod_garantir_coluna($conn, 'modelos_documentos_gerados', 'gerado_por', "gerado_por INT NULL AFTER conteudo_final");
    sgl_mod_garantir_coluna($conn, 'modelos_documentos_gerados', 'gerado_em', "gerado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP AFTER gerado_por");
}
function sgl_modelos_codigo(mysqli $conn): string {
    $res = $conn->query("SELECT codigo FROM modelos_documentos WHERE codigo LIKE 'MOD%' ORDER BY CAST(SUBSTRING(codigo,4) AS UNSIGNED) DESC LIMIT 1");
    if (!$res || $res->num_rows === 0) return 'MOD001';
    $num = (int)substr((string)$res->fetch_assoc()['codigo'], 3) + 1;
    return 'MOD' . str_pad((string)$num, 3, '0', STR_PAD_LEFT);
}
function sgl_modelos_categorias(): array { return ['Contrato','Petição','Procuração','Declaração','Termo','Notificação','Requerimento','Recurso','Manifestação','Parecer','Documento administrativo','Outros']; }
function sgl_modelos_areas(): array { return ['Previdenciário','Trabalhista','Cível','Família','Consumidor','Criminal','Tributário','Empresarial','Imobiliário','Administrativo','Bancário','Contratual','LGPD','Digital','Geral']; }


function sgl_modelos_buscar_auditoria(mysqli $conn, int $id): ?array {
    $stmt = $conn->prepare("SELECT * FROM modelos_documentos WHERE id = ? LIMIT 1");
    if (!$stmt) return null;

    $stmt->bind_param('i', $id);
    $stmt->execute();
    $res = $stmt->get_result();
    $modelo = ($res && $res->num_rows > 0) ? $res->fetch_assoc() : null;
    $stmt->close();

    return $modelo;
}

function sgl_modelos_buscar_gerado_auditoria(mysqli $conn, int $id): ?array {
    $stmt = $conn->prepare("SELECT * FROM modelos_documentos_gerados WHERE id = ? LIMIT 1");
    if (!$stmt) return null;

    $stmt->bind_param('i', $id);
    $stmt->execute();
    $res = $stmt->get_result();
    $gerado = ($res && $res->num_rows > 0) ? $res->fetch_assoc() : null;
    $stmt->close();

    return $gerado;
}

function sgl_modelos_registrar_log(
    mysqli $conn,
    string $acao,
    string $tabela,
    ?string $registroId,
    string $detalhes,
    array $contexto = []
): void {
    if (!function_exists('sgl_registrar_log')) return;

    sgl_registrar_log(
        $conn,
        $acao,
        $tabela,
        $registroId,
        $detalhes,
        array_merge(
            [
                'modulo' => 'Modelos Jurídicos',
                'origem' => 'Módulo Modelos Jurídicos',
                'resultado' => 'SUCESSO',
                'nivel' => 'INFO',
            ],
            $contexto
        )
    );
}

function sgl_modelos_templates_padrao(): array {
    return [
        ['Procuração Previdenciária INSS','Procuração','Previdenciário',"PROCURAÇÃO\n\nOUTORGANTE: {{cliente_nome}}, CPF/CNPJ nº {{cliente_cpf_cnpj}}, residente e domiciliado(a) em {{cliente_endereco}}.\n\nPelo presente instrumento, nomeia e constitui seu bastante procurador o advogado responsável para representá-lo(a) perante o INSS, Justiça Federal e demais órgãos competentes, especialmente para requerimentos, recursos, cumprimento de exigências, consulta de processos e prática de todos os atos necessários.\n\n{{cliente_cidade}}/{{cliente_uf}}, {{data_atual}}.\n\n__________________________________\n{{cliente_nome}}"],
        ['Declaração de Hipossuficiência','Declaração','Previdenciário',"DECLARAÇÃO DE HIPOSSUFICIÊNCIA\n\nEu, {{cliente_nome}}, CPF/CNPJ nº {{cliente_cpf_cnpj}}, declaro, para os devidos fins, que não possuo condições financeiras de arcar com custas, despesas processuais e honorários sem prejuízo do próprio sustento e de minha família.\n\n{{cliente_cidade}}/{{cliente_uf}}, {{data_atual}}.\n\n__________________________________\n{{cliente_nome}}"],
        ['Contrato de Honorários Advocaticios','Contrato','Contratual',"CONTRATO DE HONORÁRIOS ADVOCATÍCIOS\n\nCONTRATANTE: {{cliente_nome}}, CPF/CNPJ nº {{cliente_cpf_cnpj}}, endereço {{cliente_endereco}}.\n\nCONTRATADO: {{escritorio_nome}}.\n\nOBJETO: Prestação de serviços advocatícios referente ao processo/assunto {{processo_tipo}}, nº {{processo_numero}}, comarca {{processo_comarca}}.\n\nHONORÁRIOS: {{valor_honorarios}}.\n\nE por estarem justos e contratados, firmam o presente instrumento.\n\n{{cliente_cidade}}/{{cliente_uf}}, {{data_atual}}."],
        ['Petição Inicial Previdenciária - BPC/LOAS','Petição','Previdenciário',"EXCELENTÍSSIMO(A) SENHOR(A) DOUTOR(A) JUIZ(A) FEDERAL\n\n{{cliente_nome}}, CPF nº {{cliente_cpf_cnpj}}, residente em {{cliente_endereco}}, por seu advogado, vem propor a presente AÇÃO PREVIDENCIÁRIA em face do INSS.\n\nI - DOS FATOS\nO(a) Autor(a) busca a concessão/restabelecimento de benefício assistencial/previdenciário.\n\nII - DO DIREITO\nA pretensão encontra amparo na legislação previdenciária e nos princípios constitucionais aplicáveis.\n\nIII - DOS PEDIDOS\nRequer a citação do INSS, produção de provas e procedência da ação.\n\nDá-se à causa o valor de {{processo_valor}}.\n\n{{cliente_cidade}}/{{cliente_uf}}, {{data_atual}}."],
        ['Requerimento Administrativo INSS','Requerimento','Previdenciário',"AO INSTITUTO NACIONAL DO SEGURO SOCIAL - INSS\n\nREQUERENTE: {{cliente_nome}}\nCPF: {{cliente_cpf_cnpj}}\nENDEREÇO: {{cliente_endereco}}\n\nVem respeitosamente requerer a análise do benefício/serviço previdenciário, juntando documentos necessários e solicitando a regular tramitação administrativa.\n\nTermos em que, pede deferimento.\n\n{{cliente_cidade}}/{{cliente_uf}}, {{data_atual}}."],
        ['Notificação Extrajudicial','Notificação','Cível',"NOTIFICAÇÃO EXTRAJUDICIAL\n\nNOTIFICANTE: {{cliente_nome}}, CPF/CNPJ nº {{cliente_cpf_cnpj}}.\n\nPelo presente, fica Vossa Senhoria formalmente notificada para regularizar a situação descrita nos documentos e informações apresentados, no prazo legal/cabível, sob pena de adoção das medidas judiciais pertinentes.\n\n{{cliente_cidade}}/{{cliente_uf}}, {{data_atual}}."],
        ['Acordo Extrajudicial','Termo','Trabalhista',"TERMO DE ACORDO EXTRAJUDICIAL\n\nAs partes resolvem celebrar o presente acordo referente a {{processo_tipo}}, processo nº {{processo_numero}}, estabelecendo as condições abaixo.\n\nValor/condições: {{valor_honorarios}}\nForma de pagamento: {{forma_pagamento}}\n\nE por estarem de acordo, assinam.\n\n{{data_atual}}."],
        ['Petição de Juntada de Documentos','Petição','Geral',"EXCELENTÍSSIMO(A) SENHOR(A) DOUTOR(A) JUIZ(A)\n\nProcesso nº {{processo_numero}}\n\n{{cliente_nome}}, já qualificado(a), vem respeitosamente requerer a juntada dos documentos anexos, para que produzam seus jurídicos e legais efeitos.\n\nTermos em que, pede deferimento.\n\n{{cliente_cidade}}/{{cliente_uf}}, {{data_atual}}."],
        ['Manifestação sobre Exigência','Manifestação','Previdenciário',"MANIFESTAÇÃO / CUMPRIMENTO DE EXIGÊNCIA\n\nInteressado(a): {{cliente_nome}}\nCPF: {{cliente_cpf_cnpj}}\nProcesso/Protocolo: {{processo_numero}}\n\nEm atenção à exigência apresentada, vem juntar documentos e prestar esclarecimentos necessários para continuidade da análise.\n\n{{cliente_cidade}}/{{cliente_uf}}, {{data_atual}}."],
        ['Procuração Judicial Geral','Procuração','Geral',"PROCURAÇÃO AD JUDICIA\n\nOUTORGANTE: {{cliente_nome}}, CPF/CNPJ nº {{cliente_cpf_cnpj}}, residente em {{cliente_endereco}}.\n\nNomeia e constitui seu(sua) advogado(a), com poderes para o foro em geral, conforme cláusula ad judicia, podendo propor ações, contestar, recorrer, transigir, receber e dar quitação, firmar compromisso e praticar todos os atos necessários.\n\n{{cliente_cidade}}/{{cliente_uf}}, {{data_atual}}."],
        ['Declaração de Residência','Declaração','Geral',"DECLARAÇÃO DE RESIDÊNCIA\n\nEu, {{cliente_nome}}, CPF/CNPJ nº {{cliente_cpf_cnpj}}, declaro residir no endereço: {{cliente_endereco}}, {{cliente_cidade}}/{{cliente_uf}}.\n\nPor ser verdade, firmo a presente.\n\n{{cliente_cidade}}/{{cliente_uf}}, {{data_atual}}."],
        ['Checklist Documental Previdenciário','Documento administrativo','Previdenciário',"CHECKLIST DOCUMENTAL - PREVIDENCIÁRIO\n\nCliente: {{cliente_nome}}\nCPF: {{cliente_cpf_cnpj}}\n\n[ ] RG e CPF\n[ ] Comprovante de residência\n[ ] CTPS\n[ ] CNIS\n[ ] Documentos médicos, se houver\n[ ] Procuração\n[ ] Declaração de hipossuficiência\n[ ] Documentos específicos do caso\n\nData: {{data_atual}}."]
    ];
}
function sgl_modelos_importar_padrao(mysqli $conn, ?int $uid): int {
    $importados = 0;
    foreach (sgl_modelos_templates_padrao() as $tpl) {
        [$titulo,$categoria,$area,$conteudo] = $tpl;
        $stmt = $conn->prepare("SELECT id FROM modelos_documentos WHERE titulo=? AND deletado=0 LIMIT 1");
        $stmt->bind_param('s', $titulo); $stmt->execute(); $existe = $stmt->get_result()->fetch_assoc(); $stmt->close();
        if ($existe) continue;
        $codigo = sgl_modelos_codigo($conn);
        $obs = 'Modelo padrão importado pela Biblioteca Jurídica Profissional.';
        $status = 'Ativo'; $fav = 0;
        $stmt = $conn->prepare("INSERT INTO modelos_documentos (codigo,titulo,categoria,area_direito,conteudo,observacoes,status,favorito,criado_por,atualizado_por) VALUES (?,?,?,?,?,?,?,?,?,?)");
        $stmt->bind_param('sssssssiii', $codigo, $titulo, $categoria, $area, $conteudo, $obs, $status, $fav, $uid, $uid);
        $stmt->execute(); $stmt->close(); $importados++;
    }
    return $importados;
}

function sgl_modelos_variaveis(): array {
    return [
        'Cliente' => [
            'cliente_nome'=>'Nome do cliente','cliente_cpf_cnpj'=>'CPF/CNPJ','cliente_endereco'=>'Endereço completo','cliente_cidade'=>'Cidade','cliente_uf'=>'UF','cliente_telefone'=>'Telefone','cliente_email'=>'E-mail'
        ],
        'Processo' => [
            'processo_numero'=>'Número do processo','processo_tipo'=>'Tipo do processo','processo_comarca'=>'Comarca','processo_fase'=>'Fase atual','processo_valor'=>'Valor da causa'
        ],
        'Escritório' => [
            'escritorio_nome'=>'Nome do escritório','data_atual'=>'Data atual','mes_extenso'=>'Mês por extenso','ano_atual'=>'Ano atual'
        ],
        'Financeiro' => [
            'valor_honorarios'=>'Valor de honorários','valor_recebido'=>'Valor recebido','valor_pendente'=>'Valor pendente','forma_pagamento'=>'Forma de pagamento'
        ]
    ];
}
function sgl_modelos_mes_extenso(): string {
    $meses = [1=>'janeiro','fevereiro','março','abril','maio','junho','julho','agosto','setembro','outubro','novembro','dezembro'];
    return $meses[(int)date('n')] ?? date('m');
}
function sgl_modelos_endereco_cliente(array $c): string {
    $partes = [];
    foreach (['logradouro','numero','complemento','bairro'] as $k) if (!empty($c[$k])) $partes[] = $c[$k];
    $cidadeUf = trim(($c['cidade'] ?? '') . (!empty($c['estado']) ? '/' . $c['estado'] : ''));
    if ($cidadeUf !== '/') $partes[] = $cidadeUf;
    return implode(', ', array_filter($partes));
}
function sgl_modelos_aplicar_variaveis(string $texto, array $vars): string {
    foreach ($vars as $k => $v) $texto = str_replace('{{' . $k . '}}', (string)$v, $texto);
    return $texto;
}
function sgl_modelos_registrar_versao(mysqli $conn, array $modelo, ?int $uid): void {
    $versao = (int)($modelo['versao_atual'] ?? 1);
    $stmt = $conn->prepare("INSERT INTO modelos_documentos_versoes (modelo_id,versao,titulo,categoria,area_direito,conteudo,observacoes,criado_por) VALUES (?,?,?,?,?,?,?,?)");
    $stmt->bind_param('iisssssi', $modelo['id'], $versao, $modelo['titulo'], $modelo['categoria'], $modelo['area_direito'], $modelo['conteudo'], $modelo['observacoes'], $uid);
    $stmt->execute(); $stmt->close();
}
function sgl_modelos_vars_contexto(array $cliente = null, array $processo = null): array {
    return [
        'cliente_nome' => $cliente['nome'] ?? 'NOME DO CLIENTE',
        'cliente_cpf_cnpj' => $cliente['cpf_cnpj'] ?? 'CPF/CNPJ DO CLIENTE',
        'cliente_endereco' => $cliente ? sgl_modelos_endereco_cliente($cliente) : 'ENDEREÇO DO CLIENTE',
        'cliente_cidade' => $cliente['cidade'] ?? 'CIDADE',
        'cliente_uf' => $cliente['estado'] ?? 'UF',
        'cliente_telefone' => $cliente['telefone'] ?? ($cliente['whatsapp'] ?? 'TELEFONE'),
        'cliente_email' => $cliente['email'] ?? 'E-MAIL',
        'processo_numero' => $processo['numero_processo'] ?? 'NÚMERO DO PROCESSO',
        'processo_tipo' => $processo['tipo_processo'] ?? 'TIPO DO PROCESSO',
        'processo_comarca' => $processo['comarca'] ?? 'COMARCA',
        'processo_fase' => $processo['fase_atual'] ?? 'FASE ATUAL',
        'processo_valor' => isset($processo['valor_causa']) ? 'R$ ' . number_format((float)$processo['valor_causa'],2,',','.') : 'VALOR DA CAUSA',
        'escritorio_nome' => 'SGL Advocacia',
        'data_atual' => date('d/m/Y'),
        'mes_extenso' => sgl_modelos_mes_extenso(),
        'ano_atual' => date('Y'),
        'valor_honorarios' => 'VALOR DOS HONORÁRIOS',
        'valor_recebido' => 'VALOR RECEBIDO',
        'valor_pendente' => 'VALOR PENDENTE',
        'forma_pagamento' => 'FORMA DE PAGAMENTO'
    ];
}

sgl_modelos_garantir_tabelas($conn);
$mensagem = null; $erro = null;
$uid = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validarTokenCsrf($_POST['csrf'] ?? '')) {
        $erro = 'Falha de segurança. Atualize a página e tente novamente.';
        sgl_modelos_registrar_log(
            $conn,
            'Tentativa inválida no módulo de modelos',
            'modelos_documentos',
            null,
            'Ação bloqueada por token CSRF inválido.',
            [
                'tipo_acao' => 'EVENTO',
                'resultado' => 'NEGADO',
                'nivel' => 'AVISO',
            ]
        );
    } else {
        $acao = $_POST['acao'] ?? '';
        if ($acao === 'salvar') {
            $id = (int)($_POST['id'] ?? 0);
            $titulo = trim($_POST['titulo'] ?? '');
            $categoria = trim($_POST['categoria'] ?? 'Outros');
            $area = trim($_POST['area_direito'] ?? 'Geral');
            $conteudo = trim($_POST['conteudo'] ?? '');
            $observacoes = trim($_POST['observacoes'] ?? '');
            $status = ($_POST['status'] ?? 'Ativo') === 'Inativo' ? 'Inativo' : 'Ativo';
            $favorito = isset($_POST['favorito']) ? 1 : 0;
            if ($titulo === '' || $conteudo === '') {
                $erro = 'Informe título e conteúdo do modelo.';
                sgl_modelos_registrar_log(
                    $conn,
                    'Falha ao salvar modelo',
                    'modelos_documentos',
                    $id > 0 ? (string)$id : null,
                    'Operação recusada porque título ou conteúdo não foi informado.',
                    [
                        'tipo_acao' => $id > 0 ? 'EDICAO' : 'INCLUSAO',
                        'resultado' => 'NEGADO',
                        'nivel' => 'AVISO',
                    ]
                );
            } elseif ($id > 0) {
                $modeloAntigo = sgl_modelos_buscar_auditoria($conn, $id);

                if (!$modeloAntigo) {
                    $erro = 'Modelo não encontrado para edição.';
                    sgl_modelos_registrar_log(
                        $conn,
                        'Falha ao atualizar modelo',
                        'modelos_documentos',
                        (string)$id,
                        'O modelo não foi localizado.',
                        [
                            'tipo_acao' => 'EDICAO',
                            'resultado' => 'FALHA',
                            'nivel' => 'ERRO',
                        ]
                    );
                } else {
                    sgl_modelos_registrar_versao($conn, $modeloAntigo, $uid);
                    $novaVersao = ((int)($modeloAntigo['versao_atual'] ?? 1)) + 1;

                    $stmt = $conn->prepare("UPDATE modelos_documentos SET titulo=?, categoria=?, area_direito=?, conteudo=?, observacoes=?, status=?, favorito=?, versao_atual=?, atualizado_por=? WHERE id=?");
                    $stmt->bind_param('ssssssiiii', $titulo, $categoria, $area, $conteudo, $observacoes, $status, $favorito, $novaVersao, $uid, $id);
                    $ok = $stmt->execute();
                    $stmt->close();

                    if ($ok) {
                        sgl_modelos_registrar_log(
                            $conn,
                            'Modelo atualizado',
                            'modelos_documentos',
                            (string)$id,
                            "Modelo atualizado: {$titulo}",
                            [
                                'tipo_acao' => 'EDICAO',
                                'origem' => 'Edição de modelos',
                                'dados_anteriores' => $modeloAntigo,
                                'dados_novos' => sgl_modelos_buscar_auditoria($conn, $id),
                            ]
                        );
                        $mensagem = 'Modelo atualizado e versão anterior preservada.';
                    } else {
                        sgl_modelos_registrar_log(
                            $conn,
                            'Falha ao atualizar modelo',
                            'modelos_documentos',
                            (string)$id,
                            'A atualização não foi concluída.',
                            [
                                'tipo_acao' => 'EDICAO',
                                'resultado' => 'FALHA',
                                'nivel' => 'ERRO',
                                'dados_anteriores' => $modeloAntigo,
                            ]
                        );
                        $erro = 'Não foi possível atualizar o modelo.';
                    }
                }
            } else {
                $codigo = sgl_modelos_codigo($conn);
                $stmt = $conn->prepare("INSERT INTO modelos_documentos (codigo,titulo,categoria,area_direito,conteudo,observacoes,status,favorito,criado_por,atualizado_por) VALUES (?,?,?,?,?,?,?,?,?,?)");
                $stmt->bind_param('sssssssiii', $codigo, $titulo, $categoria, $area, $conteudo, $observacoes, $status, $favorito, $uid, $uid);
                $ok = $stmt->execute();
                $novoId = $stmt->insert_id;
                $stmt->close();

                if ($ok && $novoId > 0) {
                    sgl_modelos_registrar_log(
                        $conn,
                        'Modelo incluído',
                        'modelos_documentos',
                        (string)$novoId,
                        "Modelo criado: {$codigo} - {$titulo}",
                        [
                            'tipo_acao' => 'INCLUSAO',
                            'origem' => 'Cadastro de modelos',
                            'dados_novos' => sgl_modelos_buscar_auditoria($conn, $novoId),
                        ]
                    );
                    $mensagem = 'Modelo cadastrado com sucesso.';
                } else {
                    sgl_modelos_registrar_log(
                        $conn,
                        'Falha ao incluir modelo',
                        'modelos_documentos',
                        null,
                        'O modelo não foi gravado no banco.',
                        [
                            'tipo_acao' => 'INCLUSAO',
                            'resultado' => 'FALHA',
                            'nivel' => 'ERRO',
                            'dados_novos' => [
                                'codigo' => $codigo,
                                'titulo' => $titulo,
                                'categoria' => $categoria,
                                'area_direito' => $area,
                                'status' => $status,
                                'favorito' => $favorito,
                            ],
                        ]
                    );
                    $erro = 'Não foi possível cadastrar o modelo.';
                }
            }
        }
        if ($acao === 'excluir') {
            $id = (int)($_POST['id'] ?? 0);
            $dadosAnteriores = sgl_modelos_buscar_auditoria($conn, $id);

            $stmt = $conn->prepare("UPDATE modelos_documentos SET deletado=1 WHERE id=? AND deletado=0");
            $stmt->bind_param('i', $id);
            $ok = $stmt->execute();
            $afetadas = $stmt->affected_rows;
            $stmt->close();

            if ($ok && $afetadas > 0) {
                sgl_modelos_registrar_log(
                    $conn,
                    'Modelo movido para a lixeira',
                    'modelos_documentos',
                    (string)$id,
                    'Exclusão lógica do modelo.',
                    [
                        'tipo_acao' => 'EXCLUSAO',
                        'origem' => 'Biblioteca de modelos',
                        'nivel' => 'AVISO',
                        'dados_anteriores' => $dadosAnteriores,
                        'dados_novos' => sgl_modelos_buscar_auditoria($conn, $id),
                    ]
                );
                $mensagem = 'Modelo movido para a lixeira.';
            } else {
                sgl_modelos_registrar_log(
                    $conn,
                    'Falha ao mover modelo para a lixeira',
                    'modelos_documentos',
                    (string)$id,
                    'O registro não foi alterado.',
                    [
                        'tipo_acao' => 'EXCLUSAO',
                        'resultado' => 'FALHA',
                        'nivel' => 'ERRO',
                        'dados_anteriores' => $dadosAnteriores,
                    ]
                );
                $erro = 'Não foi possível mover o modelo para a lixeira.';
            }
        }
        if ($acao === 'duplicar') {
            $id = (int)($_POST['id'] ?? 0);
            $modelo = sgl_modelos_buscar_auditoria($conn, $id);

            if ($modelo) {
                $codigo = sgl_modelos_codigo($conn);
                $titulo = 'Cópia de ' . $modelo['titulo'];
                $stmt = $conn->prepare("INSERT INTO modelos_documentos (codigo,titulo,categoria,area_direito,conteudo,observacoes,status,favorito,criado_por,atualizado_por) VALUES (?,?,?,?,?,?,?,?,?,?)");
                $fav = 0;
                $stmt->bind_param('sssssssiii', $codigo, $titulo, $modelo['categoria'], $modelo['area_direito'], $modelo['conteudo'], $modelo['observacoes'], $modelo['status'], $fav, $uid, $uid);
                $ok = $stmt->execute();
                $novoId = $stmt->insert_id;
                $stmt->close();

                if ($ok && $novoId > 0) {
                    sgl_modelos_registrar_log(
                        $conn,
                        'Modelo duplicado',
                        'modelos_documentos',
                        (string)$novoId,
                        "Modelo duplicado a partir do ID {$id}.",
                        [
                            'tipo_acao' => 'INCLUSAO',
                            'origem' => 'Duplicação de modelos',
                            'dados_anteriores' => $modelo,
                            'dados_novos' => sgl_modelos_buscar_auditoria($conn, $novoId),
                        ]
                    );
                    $mensagem = 'Modelo duplicado com sucesso.';
                } else {
                    $erro = 'Não foi possível duplicar o modelo.';
                }
            } else {
                $erro = 'Modelo de origem não encontrado.';
            }
        }
        if ($acao === 'favorito') {
            $id = (int)($_POST['id'] ?? 0);
            $dadosAnteriores = sgl_modelos_buscar_auditoria($conn, $id);
            $ok = $conn->query("UPDATE modelos_documentos SET favorito = IF(COALESCE(favorito,0)=1,0,1) WHERE id=" . $id);

            if ($ok && $conn->affected_rows > 0) {
                $dadosNovos = sgl_modelos_buscar_auditoria($conn, $id);
                $ativado = !empty($dadosNovos['favorito']);

                sgl_modelos_registrar_log(
                    $conn,
                    $ativado ? 'Modelo marcado como favorito' : 'Modelo removido dos favoritos',
                    'modelos_documentos',
                    (string)$id,
                    $ativado ? 'Favorito ativado.' : 'Favorito removido.',
                    [
                        'tipo_acao' => 'EDICAO',
                        'origem' => 'Biblioteca de modelos',
                        'dados_anteriores' => $dadosAnteriores,
                        'dados_novos' => $dadosNovos,
                    ]
                );
                $mensagem = 'Favorito atualizado.';
            } else {
                $erro = 'Não foi possível atualizar o favorito.';
            }
        }
        if ($acao === 'importar_padrao') {
            $qtde = sgl_modelos_importar_padrao($conn, $uid);
            sgl_modelos_registrar_log(
                $conn,
                'Biblioteca padrão importada',
                'modelos_documentos',
                null,
                "Modelos padrão importados: {$qtde}.",
                [
                    'tipo_acao' => 'SINCRONIZACAO',
                    'origem' => 'Importação da biblioteca padrão',
                    'dados_novos' => [
                        'quantidade_importada' => $qtde,
                    ],
                ]
            );
            $mensagem = $qtde > 0 ? "{$qtde} modelo(s) padrão importado(s) com sucesso." : 'Os modelos padrão já estavam cadastrados.';
        }
        if ($acao === 'salvar_gerado') {
            $modeloId = (int)($_POST['modelo_id'] ?? 0);
            $clienteId = (int)($_POST['cliente_id'] ?? 0) ?: null;
            $processoId = (int)($_POST['processo_id'] ?? 0) ?: null;
            $tituloGerado = trim($_POST['titulo_gerado'] ?? 'Documento gerado');
            $conteudoFinal = trim($_POST['conteudo_final'] ?? '');
            if ($modeloId && $conteudoFinal !== '') {
                $stmt = $conn->prepare("INSERT INTO modelos_documentos_gerados (modelo_id,cliente_id,processo_id,titulo,conteudo_final,gerado_por) VALUES (?,?,?,?,?,?)");
                $stmt->bind_param('iiissi', $modeloId, $clienteId, $processoId, $tituloGerado, $conteudoFinal, $uid);
                $ok = $stmt->execute();
                $geradoId = $stmt->insert_id;
                $stmt->close();

                if ($ok && $geradoId > 0) {
                    $conn->query("UPDATE modelos_documentos SET ultimo_uso_em=NOW() WHERE id=".(int)$modeloId);

                    sgl_modelos_registrar_log(
                        $conn,
                        'Documento gerado a partir de modelo',
                        'modelos_documentos_gerados',
                        (string)$geradoId,
                        "Documento gerado a partir do modelo {$modeloId}.",
                        [
                            'tipo_acao' => 'INCLUSAO',
                            'origem' => 'Geração de documentos',
                            'dados_novos' => sgl_modelos_buscar_gerado_auditoria($conn, $geradoId),
                        ]
                    );
                    $mensagem = 'Documento gerado salvo no histórico.';
                } else {
                    sgl_modelos_registrar_log(
                        $conn,
                        'Falha ao gerar documento a partir de modelo',
                        'modelos_documentos_gerados',
                        null,
                        'O histórico do documento não foi salvo.',
                        [
                            'tipo_acao' => 'INCLUSAO',
                            'resultado' => 'FALHA',
                            'nivel' => 'ERRO',
                            'dados_novos' => [
                                'modelo_id' => $modeloId,
                                'cliente_id' => $clienteId,
                                'processo_id' => $processoId,
                                'titulo' => $tituloGerado,
                            ],
                        ]
                    );
                    $erro = 'Não foi possível salvar o documento gerado.';
                }
            }
        }
    }
}

$clientes = [];
$res = $conn->query("SELECT id, nome, cpf_cnpj, telefone, whatsapp, email, logradouro, numero, complemento, bairro, cidade, estado FROM clientes WHERE COALESCE(deletado,0)=0 ORDER BY nome LIMIT 500");
if ($res) while ($r = $res->fetch_assoc()) $clientes[] = $r;
$processos = [];
$res = $conn->query("SELECT p.id, p.numero_processo, p.cliente_id, p.tipo_processo, p.comarca, p.fase_atual, p.valor_causa, COALESCE(c.nome,'') AS cliente_nome FROM processos p LEFT JOIN clientes c ON c.id=p.cliente_id ORDER BY p.numero_processo LIMIT 500");
if ($res) while ($r = $res->fetch_assoc()) $processos[] = $r;

$editar = null; $editarId = isset($_GET['editar']) ? (int)$_GET['editar'] : 0;
if ($editarId > 0) { $stmt=$conn->prepare("SELECT * FROM modelos_documentos WHERE id=? AND deletado=0 LIMIT 1"); $stmt->bind_param('i',$editarId); $stmt->execute(); $editar=$stmt->get_result()->fetch_assoc(); $stmt->close(); }
$visualizar = null; $visualizarId = isset($_GET['visualizar']) ? (int)$_GET['visualizar'] : 0;
if ($visualizarId > 0) { $stmt=$conn->prepare("SELECT * FROM modelos_documentos WHERE id=? AND deletado=0 LIMIT 1"); $stmt->bind_param('i',$visualizarId); $stmt->execute(); $visualizar=$stmt->get_result()->fetch_assoc(); $stmt->close(); }

$q=trim($_GET['q'] ?? ''); $fcategoria=trim($_GET['categoria'] ?? ''); $farea=trim($_GET['area_direito'] ?? ''); $fstatus=trim($_GET['status'] ?? ''); $ffav=trim($_GET['favorito'] ?? '');
$where=['deletado=0']; $params=[]; $types='';
if ($q !== '') { $like='%'.$q.'%'; $where[]='(codigo LIKE ? OR titulo LIKE ? OR conteudo LIKE ? OR observacoes LIKE ?)'; array_push($params,$like,$like,$like,$like); $types.='ssss'; }
if ($fcategoria !== '') { $where[]='categoria=?'; $params[]=$fcategoria; $types.='s'; }
if ($farea !== '') { $where[]='area_direito=?'; $params[]=$farea; $types.='s'; }
if ($fstatus !== '') { $where[]='status=?'; $params[]=$fstatus; $types.='s'; }
if ($ffav === '1') { $where[]='favorito=1'; }
$sql="SELECT * FROM modelos_documentos WHERE ".implode(' AND ',$where)." ORDER BY favorito DESC, atualizado_em DESC LIMIT 200";
$stmt=$conn->prepare($sql); if($params) $stmt->bind_param($types,...$params); $stmt->execute(); $modelos=$stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();
$total=(int)($conn->query("SELECT COUNT(*) total FROM modelos_documentos WHERE deletado=0")->fetch_assoc()['total'] ?? 0);
$ativos=(int)($conn->query("SELECT COUNT(*) total FROM modelos_documentos WHERE deletado=0 AND status='Ativo'")->fetch_assoc()['total'] ?? 0);
$peticoes=(int)($conn->query("SELECT COUNT(*) total FROM modelos_documentos WHERE deletado=0 AND categoria='Petição'")->fetch_assoc()['total'] ?? 0);
$contratos=(int)($conn->query("SELECT COUNT(*) total FROM modelos_documentos WHERE deletado=0 AND categoria='Contrato'")->fetch_assoc()['total'] ?? 0);
$favoritos=(int)($conn->query("SELECT COUNT(*) total FROM modelos_documentos WHERE deletado=0 AND favorito=1")->fetch_assoc()['total'] ?? 0);
$gerados=(int)($conn->query("SELECT COUNT(*) total FROM modelos_documentos_gerados")->fetch_assoc()['total'] ?? 0);
$docsGerados = [];
$res = $conn->query("SELECT g.*, m.codigo, c.nome AS cliente_nome, p.numero_processo FROM modelos_documentos_gerados g LEFT JOIN modelos_documentos m ON m.id=g.modelo_id LEFT JOIN clientes c ON c.id=g.cliente_id LEFT JOIN processos p ON p.id=g.processo_id ORDER BY g.gerado_em DESC LIMIT 8");
if ($res) while ($r = $res->fetch_assoc()) $docsGerados[] = $r;


if ($visualizar) {
    $clienteIdPreview = trim($_GET['cliente_id'] ?? ''); $processoIdPreview = trim($_GET['processo_id'] ?? '');
    $clienteSelecionado=null; $processoSelecionado=null;
    foreach($processos as $p) if((string)$p['id']===$processoIdPreview) $processoSelecionado=$p;
    foreach($clientes as $c) if((string)$c['id']===$clienteIdPreview || ($processoSelecionado && (string)$c['id']===(string)$processoSelecionado['cliente_id'])) $clienteSelecionado=$c;
    $vars=sgl_modelos_vars_contexto($clienteSelecionado,$processoSelecionado);
    $conteudoFinal=sgl_modelos_aplicar_variaveis($visualizar['conteudo'],$vars);
    ?>
    <style>
        .modelo-doc-page{max-width:900px;margin:0 auto;background:white;border-radius:14px;padding:38px;box-shadow:0 10px 30px rgba(0,0,0,.08);}
        .modelo-doc-body{white-space:pre-wrap;line-height:1.72;font-family:Georgia,serif;font-size:16px;color:#111;}
        @media print{.no-print,.sgl-sidebar,.sgl-topbar{display:none!important}.sgl-main{padding:0!important}.modelo-doc-page{box-shadow:none;border-radius:0;margin:0;max-width:100%;padding:35px}.modelo-doc-body{font-size:13pt}}
    </style>
    <div class="no-print d-flex justify-content-between align-items-center mb-3">
        <a href="?mod=modelos" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> Voltar</a>
        <div class="d-flex gap-2">
            <a class="btn btn-outline-primary" target="_blank" href="modelo_gerar.php?id=<?= (int)$visualizar['id'] ?>&cliente_id=<?= htmlspecialchars($clienteIdPreview) ?>&processo_id=<?= htmlspecialchars($processoIdPreview) ?>&formato=html"><i class="bi bi-printer"></i> Imprimir/PDF</a>
            <a class="btn btn-outline-success" href="modelo_gerar.php?id=<?= (int)$visualizar['id'] ?>&cliente_id=<?= htmlspecialchars($clienteIdPreview) ?>&processo_id=<?= htmlspecialchars($processoIdPreview) ?>&formato=doc"><i class="bi bi-file-earmark-word"></i> Word</a>
            <form method="post" class="d-inline"><input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>"><input type="hidden" name="acao" value="salvar_gerado"><input type="hidden" name="modelo_id" value="<?= (int)$visualizar['id'] ?>"><input type="hidden" name="cliente_id" value="<?= htmlspecialchars($clienteIdPreview) ?>"><input type="hidden" name="processo_id" value="<?= htmlspecialchars($processoIdPreview) ?>"><input type="hidden" name="titulo_gerado" value="<?= htmlspecialchars($visualizar['titulo']) ?>"><input type="hidden" name="conteudo_final" value="<?= htmlspecialchars($conteudoFinal) ?>"><button class="btn btn-primary"><i class="bi bi-save"></i> Salvar histórico</button></form>
        </div>
    </div>
    <div class="card border-0 shadow-sm no-print mb-3"><div class="card-body">
        <form class="row g-3 align-items-end" method="get"><input type="hidden" name="mod" value="modelos"><input type="hidden" name="visualizar" value="<?= (int)$visualizar['id'] ?>">
            <div class="col-md-5"><label class="form-label">Preencher com cliente</label><select name="cliente_id" class="form-select"><option value="">Sem cliente</option><?php foreach($clientes as $c): ?><option value="<?= (int)$c['id'] ?>" <?= $clienteIdPreview===(string)$c['id']?'selected':'' ?>><?= htmlspecialchars($c['nome']) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-5"><label class="form-label">Preencher com processo</label><select name="processo_id" class="form-select"><option value="">Sem processo</option><?php foreach($processos as $p): ?><option value="<?= (int)$p['id'] ?>" <?= $processoIdPreview===(string)$p['id']?'selected':'' ?>><?= htmlspecialchars(($p['numero_processo'] ?: 'Sem número').' - '.$p['cliente_nome']) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-2 d-grid"><button class="btn btn-outline-primary"><i class="bi bi-magic"></i> Aplicar</button></div>
        </form>
    </div></div>
    <div class="modelo-doc-page">
        <div class="text-center mb-4"><h2 class="fw-bold mb-1"><?= htmlspecialchars($visualizar['titulo']) ?></h2><small class="text-muted"><?= htmlspecialchars($visualizar['codigo']) ?> · Versão <?= (int)($visualizar['versao_atual'] ?? 1) ?> · <?= htmlspecialchars($visualizar['categoria']) ?></small></div>
        <div class="modelo-doc-body"><?= htmlspecialchars($conteudoFinal) ?></div>
    </div>
    <?php return; }
?>

<style>
.modelos-var-panel{background:#fff;border-radius:14px;box-shadow:0 6px 20px rgba(0,0,0,.06);padding:16px;position:sticky;top:12px}.var-chip{border:1px solid #d7e6ff;background:#f4f8ff;color:#0b5ed7;border-radius:999px;padding:5px 10px;margin:3px;display:inline-block;font-size:.82rem;cursor:pointer}.modelo-toolbar .btn{white-space:nowrap}.modelo-row-title{font-weight:800;color:#0b3158}.modelo-muted{color:#6c757d;font-size:.88rem}.modelo-card-head{background:#1f2328;color:#fff;border-radius:12px 12px 0 0;padding:12px 16px;font-weight:700}
</style>

<div class="d-flex justify-content-between align-items-start mb-4">
    <div><h2 class="fw-bold text-primary mb-1"><i class="bi bi-journal-text"></i> Modelos Jurídicos</h2><p class="text-muted mb-0">Biblioteca profissional de contratos, petições, procurações, termos e documentos reutilizáveis.</p></div>
    <div class="d-flex gap-2"><form method="post" class="d-inline"><input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>"><input type="hidden" name="acao" value="importar_padrao"><button class="btn btn-outline-primary"><i class="bi bi-cloud-download"></i> Importar biblioteca padrão</button></form><a href="?mod=modelos&novo=1" class="btn btn-primary"><i class="bi bi-plus-circle"></i> Novo modelo</a></div>
</div>
<?php if($mensagem): ?><div class="alert alert-success"><i class="bi bi-check-circle"></i> <?= htmlspecialchars($mensagem) ?></div><?php endif; ?>
<?php if($erro): ?><div class="alert alert-danger"><i class="bi bi-exclamation-triangle"></i> <?= htmlspecialchars($erro) ?></div><?php endif; ?>

<div class="row g-3 mb-4">
    <div class="col-md-2"><div class="card border-0 shadow-sm"><div class="card-body"><small class="text-muted">TOTAL</small><h3 class="fw-bold mb-0"><?= $total ?></h3></div></div></div>
    <div class="col-md-2"><div class="card border-0 shadow-sm"><div class="card-body"><small class="text-muted">ATIVOS</small><h3 class="fw-bold text-success mb-0"><?= $ativos ?></h3></div></div></div>
    <div class="col-md-2"><div class="card border-0 shadow-sm"><div class="card-body"><small class="text-muted">PETIÇÕES</small><h3 class="fw-bold text-primary mb-0"><?= $peticoes ?></h3></div></div></div>
    <div class="col-md-2"><div class="card border-0 shadow-sm"><div class="card-body"><small class="text-muted">CONTRATOS</small><h3 class="fw-bold text-warning mb-0"><?= $contratos ?></h3></div></div></div>
    <div class="col-md-2"><div class="card border-0 shadow-sm"><div class="card-body"><small class="text-muted">FAVORITOS</small><h3 class="fw-bold text-danger mb-0"><?= $favoritos ?></h3></div></div></div>
    <div class="col-md-2"><div class="card border-0 shadow-sm"><div class="card-body"><small class="text-muted">GERADOS</small><h3 class="fw-bold text-info mb-0"><?= $gerados ?></h3></div></div></div>
</div>

<?php if(isset($_GET['novo']) || $editar): ?>
<div class="row g-3 mb-4">
    <div class="col-xl-8">
        <div class="card border-0 shadow-sm"><div class="modelo-card-head"><i class="bi bi-pencil-square"></i> <?= $editar ? 'Editar modelo' : 'Novo modelo' ?></div><div class="card-body">
            <form method="post"><input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>"><input type="hidden" name="acao" value="salvar"><input type="hidden" name="id" value="<?= (int)($editar['id'] ?? 0) ?>">
                <div class="row g-3">
                    <div class="col-md-6"><label class="form-label">Título</label><input name="titulo" class="form-control" required value="<?= htmlspecialchars($editar['titulo'] ?? '') ?>"></div>
                    <div class="col-md-3"><label class="form-label">Categoria</label><select name="categoria" class="form-select"><?php foreach(sgl_modelos_categorias() as $cat): ?><option <?= (($editar['categoria'] ?? '')===$cat)?'selected':'' ?>><?= htmlspecialchars($cat) ?></option><?php endforeach; ?></select></div>
                    <div class="col-md-3"><label class="form-label">Área</label><select name="area_direito" class="form-select"><?php foreach(sgl_modelos_areas() as $area): ?><option <?= (($editar['area_direito'] ?? '')===$area)?'selected':'' ?>><?= htmlspecialchars($area) ?></option><?php endforeach; ?></select></div>
                    <div class="col-md-3"><label class="form-label">Status</label><select name="status" class="form-select"><option <?= (($editar['status'] ?? 'Ativo')==='Ativo')?'selected':'' ?>>Ativo</option><option <?= (($editar['status'] ?? '')==='Inativo')?'selected':'' ?>>Inativo</option></select></div>
                    <div class="col-md-3 d-flex align-items-end"><div class="form-check mb-2"><input class="form-check-input" type="checkbox" name="favorito" id="favorito" <?= !empty($editar['favorito'])?'checked':'' ?>><label class="form-check-label" for="favorito">Marcar como favorito</label></div></div>
                    <div class="col-12"><label class="form-label">Conteúdo do modelo</label><div class="btn-group btn-group-sm mb-2" role="group"><button type="button" class="btn btn-outline-secondary fmt-btn" data-before="**" data-after="**"><b>N</b></button><button type="button" class="btn btn-outline-secondary fmt-btn" data-before="_" data-after="_"><i>I</i></button><button type="button" class="btn btn-outline-secondary fmt-btn" data-before="\n• " data-after="">Lista</button><button type="button" class="btn btn-outline-secondary fmt-btn" data-before="\n__________________________________\n" data-after="">Assinatura</button></div><textarea id="conteudoModelo" name="conteudo" class="form-control" rows="14" required placeholder="Digite o modelo e clique nas variáveis ao lado para inserir campos automáticos."><?= htmlspecialchars($editar['conteudo'] ?? '') ?></textarea><div class="form-text">Use as variáveis à direita para automatizar dados do cliente, processo, escritório e financeiro.</div></div>
                    <div class="col-12"><label class="form-label">Observações internas</label><textarea name="observacoes" class="form-control" rows="3"><?= htmlspecialchars($editar['observacoes'] ?? '') ?></textarea></div>
                </div>
                <div class="d-flex justify-content-end gap-2 mt-3"><a href="?mod=modelos" class="btn btn-outline-secondary">Cancelar</a><button class="btn btn-primary"><i class="bi bi-save"></i> Salvar modelo</button></div>
            </form>
        </div></div>
    </div>
    <div class="col-xl-4"><div class="modelos-var-panel"><h6 class="fw-bold"><i class="bi bi-braces"></i> Biblioteca de Variáveis</h6><p class="text-muted small">Clique para inserir no texto.</p><?php foreach(sgl_modelos_variaveis() as $grupo=>$vars): ?><div class="mb-3"><div class="fw-bold small text-primary mb-1"><?= htmlspecialchars($grupo) ?></div><?php foreach($vars as $cod=>$rotulo): ?><span class="var-chip" data-var="{{<?= htmlspecialchars($cod) ?>}}" title="<?= htmlspecialchars($rotulo) ?>">{{<?= htmlspecialchars($cod) ?>}}</span><?php endforeach; ?></div><?php endforeach; ?><hr><div class="small text-muted">Esses marcadores serão substituídos automaticamente ao gerar o documento.</div></div></div>
</div>
<?php endif; ?>

<div class="card border-0 shadow-sm mb-4"><div class="card-body"><form class="row g-3 align-items-end" method="get"><input type="hidden" name="mod" value="modelos">
    <div class="col-md-4"><label class="form-label">Pesquisa inteligente</label><input name="q" class="form-control" value="<?= htmlspecialchars($q) ?>" placeholder="Título, código, texto, observação"></div>
    <div class="col-md-2"><label class="form-label">Categoria</label><select name="categoria" class="form-select"><option value="">Todas</option><?php foreach(sgl_modelos_categorias() as $cat): ?><option value="<?= htmlspecialchars($cat) ?>" <?= $fcategoria===$cat?'selected':'' ?>><?= htmlspecialchars($cat) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-2"><label class="form-label">Área</label><select name="area_direito" class="form-select"><option value="">Todas</option><?php foreach(sgl_modelos_areas() as $area): ?><option value="<?= htmlspecialchars($area) ?>" <?= $farea===$area?'selected':'' ?>><?= htmlspecialchars($area) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-2"><label class="form-label">Status</label><select name="status" class="form-select"><option value="">Todos</option><option <?= $fstatus==='Ativo'?'selected':'' ?>>Ativo</option><option <?= $fstatus==='Inativo'?'selected':'' ?>>Inativo</option></select></div>
    <div class="col-md-1"><label class="form-label">Favoritos</label><select name="favorito" class="form-select"><option value="">Todos</option><option value="1" <?= $ffav==='1'?'selected':'' ?>>Sim</option></select></div>
    <div class="col-md-1 d-grid"><button class="btn btn-primary"><i class="bi bi-search"></i></button></div>
</form></div></div>

<div class="card border-0 shadow-sm"><div class="modelo-card-head d-flex justify-content-between"><span><i class="bi bi-list"></i> Biblioteca de Modelos</span><span><?= count($modelos) ?> registro(s)</span></div><div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>CÓDIGO</th><th>TÍTULO</th><th>CATEGORIA</th><th>ÁREA</th><th>VERSÃO</th><th>STATUS</th><th>ATUALIZADO</th><th class="text-end">AÇÕES</th></tr></thead><tbody>
<?php if(!$modelos): ?><tr><td colspan="8" class="text-center text-muted py-4">Nenhum modelo encontrado.</td></tr><?php endif; ?>
<?php foreach($modelos as $m): ?><tr>
    <td><span class="fw-bold"><?= htmlspecialchars($m['codigo']) ?></span><?php if(!empty($m['favorito'])): ?> <i class="bi bi-star-fill text-warning"></i><?php endif; ?></td>
    <td><div class="modelo-row-title"><?= htmlspecialchars($m['titulo']) ?></div><div class="modelo-muted"><?= htmlspecialchars(mb_strimwidth(strip_tags($m['observacoes'] ?? ''),0,80,'...')) ?></div></td>
    <td><?= htmlspecialchars($m['categoria']) ?></td><td><?= htmlspecialchars($m['area_direito'] ?? '') ?></td><td>v<?= (int)($m['versao_atual'] ?? 1) ?></td><td><span class="badge bg-<?= ($m['status']==='Ativo')?'success':'secondary' ?>"><?= htmlspecialchars($m['status']) ?></span></td><td><?= !empty($m['atualizado_em']) ? date('d/m/Y H:i', strtotime($m['atualizado_em'])) : '-' ?></td>
    <td class="text-end"><div class="btn-group modelo-toolbar">
        <a class="btn btn-sm btn-outline-primary" href="?mod=modelos&visualizar=<?= (int)$m['id'] ?>" title="Gerar"><i class="bi bi-magic"></i></a>
        <a class="btn btn-sm btn-outline-warning" href="?mod=modelos&editar=<?= (int)$m['id'] ?>" title="Editar"><i class="bi bi-pencil"></i></a>
        <form method="post" class="d-inline"><input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>"><input type="hidden" name="acao" value="favorito"><input type="hidden" name="id" value="<?= (int)$m['id'] ?>"><button class="btn btn-sm btn-outline-secondary" title="Favorito"><i class="bi bi-star"></i></button></form>
        <form method="post" class="d-inline"><input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>"><input type="hidden" name="acao" value="duplicar"><input type="hidden" name="id" value="<?= (int)$m['id'] ?>"><button class="btn btn-sm btn-outline-info" title="Duplicar"><i class="bi bi-files"></i></button></form>
        <form method="post" class="d-inline" onsubmit="return confirm('Mover este modelo para a lixeira?')"><input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>"><input type="hidden" name="acao" value="excluir"><input type="hidden" name="id" value="<?= (int)$m['id'] ?>"><button class="btn btn-sm btn-outline-danger" title="Excluir"><i class="bi bi-trash"></i></button></form>
    </div></td>
</tr><?php endforeach; ?>
</tbody></table></div></div>


<?php if(!empty($docsGerados)): ?>
<div class="card border-0 shadow-sm mt-4"><div class="modelo-card-head d-flex justify-content-between"><span><i class="bi bi-clock-history"></i> Últimos documentos gerados</span><span><?= count($docsGerados) ?> recente(s)</span></div><div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>Documento</th><th>Cliente</th><th>Processo</th><th>Gerado em</th><th class="text-end">Ação</th></tr></thead><tbody><?php foreach($docsGerados as $dg): ?><tr><td><strong><?= htmlspecialchars($dg['titulo']) ?></strong><br><small class="text-muted"><?= htmlspecialchars($dg['codigo'] ?? '') ?></small></td><td><?= htmlspecialchars($dg['cliente_nome'] ?? '-') ?></td><td><?= htmlspecialchars($dg['numero_processo'] ?? '-') ?></td><td><?= !empty($dg['gerado_em']) ? date('d/m/Y H:i', strtotime($dg['gerado_em'])) : '-' ?></td><td class="text-end"><a href="modelo_gerar.php?gerado_id=<?= (int)$dg['id'] ?>&formato=html" target="_blank" class="btn btn-sm btn-outline-primary"><i class="bi bi-eye"></i> Abrir</a></td></tr><?php endforeach; ?></tbody></table></div></div>
<?php endif; ?>

<div class="alert alert-info mt-4"><strong>Fluxo recomendado:</strong> cadastre o modelo com variáveis, clique em gerar, selecione cliente/processo, revise o documento final e exporte em Word ou PDF.</div>

<script>
function inserirNoEditor(txtBefore, txtAfter='') { const ta=document.getElementById('conteudoModelo'); if(!ta) return; const s=ta.selectionStart||0,e=ta.selectionEnd||0; const sel=ta.value.substring(s,e); const novo=txtBefore+sel+txtAfter; ta.value=ta.value.substring(0,s)+novo+ta.value.substring(e); ta.focus(); ta.selectionStart=s+txtBefore.length; ta.selectionEnd=s+txtBefore.length+sel.length; }
document.querySelectorAll('.var-chip').forEach(chip=>{chip.addEventListener('click',()=>inserirNoEditor(chip.dataset.var,''));});
document.querySelectorAll('.fmt-btn').forEach(btn=>{btn.addEventListener('click',()=>inserirNoEditor(btn.dataset.before||'', btn.dataset.after||''));});
</script>