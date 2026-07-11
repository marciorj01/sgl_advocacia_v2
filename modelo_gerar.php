<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/auth.php';

$integracoes = __DIR__ . '/config/integracoes.php';
if (is_file($integracoes)) {
    require_once $integracoes;
}

iniciarSessaoSegura();
exigirLogin('auth/login.php');
$conn = conectar();

/**
 * Registra eventos de visualização e exportação no LOG Enterprise.
 * A geração continua funcionando mesmo quando a integração de LOG não estiver disponível.
 */
function mg_registrar_log(
    mysqli $conn,
    string $acao,
    string $tabela,
    ?string $registroId,
    string $detalhes,
    array $contexto = []
): void {
    if (!function_exists('sgl_registrar_log')) {
        return;
    }

    try {
        sgl_registrar_log(
            $conn,
            $acao,
            $tabela,
            $registroId,
            $detalhes,
            array_merge(
                [
                    'modulo' => 'Modelos Jurídicos',
                    'origem' => 'Geração de documentos',
                    'resultado' => 'SUCESSO',
                    'nivel' => 'INFO',
                ],
                $contexto
            )
        );
    } catch (Throwable $e) {
        // O LOG não pode impedir a visualização ou exportação do documento.
    }
}

function mg_formato_valido(?string $formato): string
{
    return strtolower((string)$formato) === 'doc' ? 'doc' : 'html';
}

function mg_nome_arquivo(string $titulo, string $fallback = 'documento'): string
{
    $titulo = trim($titulo);

    if (function_exists('iconv')) {
        $convertido = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $titulo);
        if (is_string($convertido) && $convertido !== '') {
            $titulo = $convertido;
        }
    }

    $nome = preg_replace('/[^a-zA-Z0-9_-]+/', '_', $titulo);
    $nome = trim((string)$nome, '_-');

    return $nome !== '' ? $nome : $fallback;
}

function mg_endereco(array $cliente): string
{
    $partes = [];

    foreach (['logradouro', 'numero', 'complemento', 'bairro'] as $campo) {
        if (!empty($cliente[$campo])) {
            $partes[] = trim((string)$cliente[$campo]);
        }
    }

    $cidadeUf = trim(
        (string)($cliente['cidade'] ?? '') .
        (!empty($cliente['estado']) ? '/' . $cliente['estado'] : '')
    );

    if ($cidadeUf !== '' && $cidadeUf !== '/') {
        $partes[] = $cidadeUf;
    }

    return implode(', ', array_filter($partes));
}

function mg_mes(): string
{
    $meses = [
        1 => 'janeiro',
        2 => 'fevereiro',
        3 => 'março',
        4 => 'abril',
        5 => 'maio',
        6 => 'junho',
        7 => 'julho',
        8 => 'agosto',
        9 => 'setembro',
        10 => 'outubro',
        11 => 'novembro',
        12 => 'dezembro',
    ];

    return $meses[(int)date('n')] ?? date('m');
}

function mg_vars(?array $cliente, ?array $processo): array
{
    return [
        'cliente_nome' => $cliente['nome'] ?? 'NOME DO CLIENTE',
        'cliente_cpf_cnpj' => $cliente['cpf_cnpj'] ?? 'CPF/CNPJ DO CLIENTE',
        'cliente_endereco' => $cliente ? mg_endereco($cliente) : 'ENDEREÇO DO CLIENTE',
        'cliente_cidade' => $cliente['cidade'] ?? 'CIDADE',
        'cliente_uf' => $cliente['estado'] ?? 'UF',
        'cliente_telefone' => $cliente['telefone'] ?? ($cliente['whatsapp'] ?? 'TELEFONE'),
        'cliente_email' => $cliente['email'] ?? 'E-MAIL',
        'processo_numero' => $processo['numero_processo'] ?? 'NÚMERO DO PROCESSO',
        'processo_tipo' => $processo['tipo_processo'] ?? 'TIPO DO PROCESSO',
        'processo_comarca' => $processo['comarca'] ?? 'COMARCA',
        'processo_fase' => $processo['fase_atual'] ?? 'FASE ATUAL',
        'processo_valor' => isset($processo['valor_causa'])
            ? 'R$ ' . number_format((float)$processo['valor_causa'], 2, ',', '.')
            : 'VALOR DA CAUSA',
        // Mantido por compatibilidade com os modelos já cadastrados.
        'escritorio_nome' => 'SGL Advocacia',
        'data_atual' => date('d/m/Y'),
        'mes_extenso' => mg_mes(),
        'ano_atual' => date('Y'),
        'valor_honorarios' => 'VALOR DOS HONORÁRIOS',
        'valor_recebido' => 'VALOR RECEBIDO',
        'valor_pendente' => 'VALOR PENDENTE',
        'forma_pagamento' => 'FORMA DE PAGAMENTO',
    ];
}

function mg_apply(string $texto, array $variaveis): string
{
    foreach ($variaveis as $codigo => $valor) {
        $texto = str_replace('{{' . $codigo . '}}', (string)$valor, $texto);
    }

    return $texto;
}

function mg_saida_documento(
    string $titulo,
    string $conteudo,
    string $metadados,
    string $formato,
    string $nomeArquivo,
    ?string $linkWord = null
): void {
    if ($formato === 'doc') {
        header('Content-Type: application/msword; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $nomeArquivo . '.doc"');
        header('X-Content-Type-Options: nosniff');
    } else {
        header('Content-Type: text/html; charset=utf-8');
    }

    ?><!doctype html>
    <html lang="pt-br">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title><?= htmlspecialchars($titulo, ENT_QUOTES, 'UTF-8') ?></title>
        <style>
            body{font-family:Georgia,serif;margin:45px;line-height:1.72;color:#111;background:#f5f6f8}
            .actions{max-width:850px;margin:0 auto 15px;text-align:right;font-family:Arial,sans-serif}
            .actions button,.actions a{display:inline-block;border:1px solid #1f6fb2;border-radius:6px;background:#1f6fb2;color:#fff;padding:8px 13px;text-decoration:none;cursor:pointer;font-size:14px}
            .actions a{background:#fff;color:#1f6fb2}
            .doc{max-width:850px;margin:0 auto;background:#fff;padding:45px;box-shadow:0 8px 25px rgba(0,0,0,.08)}
            .head{text-align:center;margin-bottom:35px}
            .meta{font-family:Arial,sans-serif;color:#666;font-size:12px}
            .body{white-space:pre-wrap;font-size:16px;overflow-wrap:anywhere}
            @media print{body{margin:25mm;background:#fff}.noprint{display:none!important}.doc{max-width:none;padding:0;box-shadow:none}}
        </style>
    </head>
    <body>
        <?php if ($formato === 'html'): ?>
            <div class="actions noprint">
                <button type="button" onclick="window.print()">Imprimir / Salvar PDF</button>
                <?php if ($linkWord): ?>
                    <a href="<?= htmlspecialchars($linkWord, ENT_QUOTES, 'UTF-8') ?>">Word</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <div class="doc">
            <div class="head">
                <h2><?= htmlspecialchars($titulo, ENT_QUOTES, 'UTF-8') ?></h2>
                <div class="meta"><?= htmlspecialchars($metadados, ENT_QUOTES, 'UTF-8') ?></div>
            </div>
            <div class="body"><?= htmlspecialchars($conteudo, ENT_QUOTES, 'UTF-8') ?></div>
        </div>
    </body>
    </html><?php
}

$formato = mg_formato_valido($_GET['formato'] ?? 'html');
$geradoId = max(0, (int)($_GET['gerado_id'] ?? 0));

/* Documento anteriormente salvo no histórico. */
if ($geradoId > 0) {
    $stmt = $conn->prepare(
        "SELECT id, modelo_id, cliente_id, processo_id, titulo, conteudo_final, gerado_por, gerado_em
         FROM modelos_documentos_gerados
         WHERE id = ?
         LIMIT 1"
    );

    if (!$stmt) {
        http_response_code(500);
        exit('Não foi possível consultar o documento gerado.');
    }

    $stmt->bind_param('i', $geradoId);
    $stmt->execute();
    $resultado = $stmt->get_result();
    $gerado = $resultado ? $resultado->fetch_assoc() : null;
    $stmt->close();

    if (!$gerado) {
        mg_registrar_log(
            $conn,
            'Falha ao abrir documento gerado',
            'modelos_documentos_gerados',
            (string)$geradoId,
            'Documento do histórico não encontrado.',
            [
                'tipo_acao' => 'CONSULTA',
                'resultado' => 'FALHA',
                'nivel' => 'ERRO',
            ]
        );

        http_response_code(404);
        exit('Documento gerado não encontrado.');
    }

    $nomeArquivo = mg_nome_arquivo((string)$gerado['titulo'], 'documento_gerado');
    $acaoLog = $formato === 'doc'
        ? 'Documento histórico exportado em Word'
        : 'Documento histórico aberto';

    mg_registrar_log(
        $conn,
        $acaoLog,
        'modelos_documentos_gerados',
        (string)$geradoId,
        $formato === 'doc'
            ? "Exportação em Word do documento histórico: {$gerado['titulo']}."
            : "Visualização do documento histórico: {$gerado['titulo']}.",
        [
            'tipo_acao' => $formato === 'doc' ? 'EXPORTACAO' : 'CONSULTA',
            'dados_novos' => [
                'formato' => $formato,
                'modelo_id' => (int)($gerado['modelo_id'] ?? 0),
                'cliente_id' => $gerado['cliente_id'] !== null ? (int)$gerado['cliente_id'] : null,
                'processo_id' => $gerado['processo_id'] !== null ? (int)$gerado['processo_id'] : null,
                'titulo' => (string)$gerado['titulo'],
            ],
        ]
    );

    $dataGeracao = !empty($gerado['gerado_em'])
        ? date('d/m/Y H:i', strtotime((string)$gerado['gerado_em']))
        : '-';

    $linkWord = 'modelo_gerar.php?' . http_build_query([
        'gerado_id' => $geradoId,
        'formato' => 'doc',
    ]);

    mg_saida_documento(
        (string)$gerado['titulo'],
        (string)$gerado['conteudo_final'],
        'Histórico nº ' . $geradoId . ' · ' . $dataGeracao,
        $formato,
        $nomeArquivo,
        $linkWord
    );
    exit;
}

/* Geração diretamente a partir de um modelo ativo. */
$id = max(0, (int)($_GET['id'] ?? 0));
$clienteId = max(0, (int)($_GET['cliente_id'] ?? 0));
$processoId = max(0, (int)($_GET['processo_id'] ?? 0));

if ($id <= 0) {
    http_response_code(400);
    exit('Modelo inválido.');
}

$stmt = $conn->prepare(
    "SELECT *
     FROM modelos_documentos
     WHERE id = ?
       AND COALESCE(deletado, 0) = 0
     LIMIT 1"
);

if (!$stmt) {
    http_response_code(500);
    exit('Não foi possível consultar o modelo.');
}

$stmt->bind_param('i', $id);
$stmt->execute();
$resultado = $stmt->get_result();
$modelo = $resultado ? $resultado->fetch_assoc() : null;
$stmt->close();

if (!$modelo) {
    mg_registrar_log(
        $conn,
        'Falha ao gerar documento',
        'modelos_documentos',
        (string)$id,
        'Modelo não encontrado ou enviado para a lixeira.',
        [
            'tipo_acao' => 'CONSULTA',
            'resultado' => 'FALHA',
            'nivel' => 'ERRO',
        ]
    );

    http_response_code(404);
    exit('Modelo não encontrado.');
}

$cliente = null;
$processo = null;

if ($processoId > 0) {
    $stmt = $conn->prepare(
        "SELECT p.*, c.nome AS cliente_nome
         FROM processos p
         LEFT JOIN clientes c ON c.id = p.cliente_id
         WHERE p.id = ?
         LIMIT 1"
    );

    if ($stmt) {
        $stmt->bind_param('i', $processoId);
        $stmt->execute();
        $resultado = $stmt->get_result();
        $processo = $resultado ? $resultado->fetch_assoc() : null;
        $stmt->close();

        if ($processo && $clienteId <= 0) {
            $clienteId = (int)($processo['cliente_id'] ?? 0);
        }
    }
}

if ($clienteId > 0) {
    $stmt = $conn->prepare(
        "SELECT id, nome, cpf_cnpj, telefone, whatsapp, email,
                logradouro, numero, complemento, bairro, cidade, estado
         FROM clientes
         WHERE id = ?
           AND COALESCE(deletado, 0) = 0
         LIMIT 1"
    );

    if ($stmt) {
        $stmt->bind_param('i', $clienteId);
        $stmt->execute();
        $resultado = $stmt->get_result();
        $cliente = $resultado ? $resultado->fetch_assoc() : null;
        $stmt->close();
    }
}

$conteudo = mg_apply((string)$modelo['conteudo'], mg_vars($cliente, $processo));
$nomeArquivo = mg_nome_arquivo((string)$modelo['titulo']);
$acaoLog = $formato === 'doc'
    ? 'Documento de modelo exportado em Word'
    : 'Documento preparado para impressão ou PDF';

mg_registrar_log(
    $conn,
    $acaoLog,
    'modelos_documentos',
    (string)$id,
    $formato === 'doc'
        ? "Modelo exportado em Word: {$modelo['titulo']}."
        : "Documento preparado a partir do modelo: {$modelo['titulo']}.",
    [
        'tipo_acao' => $formato === 'doc' ? 'EXPORTACAO' : 'CONSULTA',
        'dados_novos' => [
            'formato' => $formato,
            'modelo_id' => $id,
            'cliente_id' => $clienteId > 0 ? $clienteId : null,
            'processo_id' => $processoId > 0 ? $processoId : null,
            'codigo' => (string)($modelo['codigo'] ?? ''),
            'titulo' => (string)$modelo['titulo'],
        ],
    ]
);

$linkWord = 'modelo_gerar.php?' . http_build_query([
    'id' => $id,
    'cliente_id' => $clienteId,
    'processo_id' => $processoId,
    'formato' => 'doc',
]);

$metadados = implode(' · ', array_filter([
    (string)($modelo['codigo'] ?? ''),
    (string)($modelo['categoria'] ?? ''),
    date('d/m/Y'),
]));

mg_saida_documento(
    (string)$modelo['titulo'],
    $conteudo,
    $metadados,
    $formato,
    $nomeArquivo,
    $linkWord
);
