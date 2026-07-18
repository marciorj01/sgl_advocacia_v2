<?php
/**
 * Fase 3.4.1 — Endpoint seguro para visualizar/baixar documentos.
 *
 * Motivo: o módulo documentos.php é carregado dentro do index.php, que já envia HTML.
 * Arquivos binários/PDF/imagens precisam ser entregues por um endpoint limpo,
 * antes de qualquer saída HTML, para evitar erro de header e caracteres corrompidos.
 */

declare(strict_types=1);

date_default_timezone_set('America/Sao_Paulo');

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/integracoes.php';

iniciarSessaoSegura();
exigirLogin('auth/login.php');

$conn = conectar();

if (!function_exists('rojexContextoTenantValido') || !rojexContextoTenantValido()) {
    http_response_code(403);
    exit('Contexto Multi-Tenant inválido.');
}

$tenantId = function_exists('rojexTenantId')
    ? trim((string)rojexTenantId())
    : trim((string)($_SESSION['tenant_id'] ?? ''));

$escritorioId = function_exists('rojexEscritorioId')
    ? (int)rojexEscritorioId()
    : (int)($_SESSION['escritorio_id'] ?? 0);

if ($tenantId === '' || $escritorioId <= 0) {
    http_response_code(403);
    exit('Tenant ou escritório não identificado.');
}


function sgl_doc_endpoint_log(
    mysqli $conn,
    string $acao,
    ?string $registroId,
    string $detalhes,
    array $contexto = []
): void {
    if (!function_exists('sgl_registrar_log')) {
        return;
    }

    sgl_registrar_log(
        $conn,
        $acao,
        'documentos_arquivos',
        $registroId,
        $detalhes,
        array_merge(
            [
                'modulo' => 'Documentos',
                'origem' => 'Endpoint de documentos',
                'resultado' => 'SUCESSO',
                'nivel' => 'INFO',
            ],
            $contexto
        )
    );
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$modo = isset($_GET['modo']) ? trim((string)$_GET['modo']) : 'inline';
if ($modo !== 'download') {
    $modo = 'inline';
}

if ($id <= 0) {
    sgl_doc_endpoint_log(
        $conn,
        'Tentativa inválida de acesso a documento',
        null,
        'Acesso recusado por identificador inválido.',
        [
            'tipo_acao' => 'EVENTO',
            'resultado' => 'NEGADO',
            'nivel' => 'AVISO',
            'dados_novos' => [
                'id_informado' => $id,
                'modo' => $modo,
            ],
        ]
    );

    http_response_code(400);
    exit('Documento inválido.');
}

try {
    $stmt = $conn->prepare(
        "SELECT id, tenant_id, escritorio_id, codigo, titulo, categoria, cliente_id, processo_id,
                numero_processo, nome_original, caminho, extensao, mime_type, tamanho_bytes, hash_arquivo
         FROM documentos_arquivos
         WHERE tenant_id = ? AND escritorio_id = ? AND id = ?
           AND COALESCE(deletado,0) = 0
         LIMIT 1"
    );
    $stmt->bind_param('sii', $tenantId, $escritorioId, $id);
    $stmt->execute();
    $doc = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$doc) {
        sgl_doc_endpoint_log(
            $conn,
            'Tentativa de acesso a documento inexistente',
            (string)$id,
            'Documento não localizado ou já enviado à lixeira.',
            [
                'tipo_acao' => 'EVENTO',
                'resultado' => 'NEGADO',
                'nivel' => 'AVISO',
                'dados_novos' => [
                    'modo' => $modo,
                ],
            ]
        );

        http_response_code(404);
        exit('Documento não encontrado.');
    }

    $base = realpath(__DIR__);
    $arquivo = realpath(__DIR__ . '/' . $doc['caminho']);

    if (!$base || !$arquivo || !str_starts_with($arquivo, $base) || !is_file($arquivo)) {
        sgl_doc_endpoint_log(
            $conn,
            'Falha ao acessar arquivo físico',
            (string)$id,
            'Registro localizado, mas o arquivo físico não está disponível ou o caminho é inválido.',
            [
                'tipo_acao' => 'EVENTO',
                'resultado' => 'FALHA',
                'nivel' => 'ERRO',
                'dados_anteriores' => $doc,
                'dados_novos' => [
                    'modo' => $modo,
                    'arquivo_resolvido' => $arquivo ?: null,
                ],
            ]
        );

        http_response_code(404);
        exit('Arquivo físico não encontrado.');
    }

    $nomeOriginal = basename((string)$doc['nome_original']);
    $nomeOriginal = str_replace(['"', "\r", "\n"], '', $nomeOriginal);
    $mime = (string)($doc['mime_type'] ?: 'application/octet-stream');
    $tamanho = filesize($arquivo);

    sgl_doc_endpoint_log(
        $conn,
        $modo === 'download' ? 'Documento baixado' : 'Documento visualizado',
        (string)$id,
        ($modo === 'download' ? 'Download realizado: ' : 'Visualização realizada: ') . $nomeOriginal,
        [
            'tipo_acao' => 'EVENTO',
            'origem' => $modo === 'download' ? 'Download de documento' : 'Visualização de documento',
            'dados_novos' => [
                'id' => (int)$doc['id'],
                'tenant_id' => (string)$doc['tenant_id'],
                'escritorio_id' => (int)$doc['escritorio_id'],
                'codigo' => (string)($doc['codigo'] ?? ''),
                'titulo' => (string)($doc['titulo'] ?? ''),
                'categoria' => (string)($doc['categoria'] ?? ''),
                'cliente_id' => $doc['cliente_id'] ?? null,
                'processo_id' => $doc['processo_id'] ?? null,
                'numero_processo' => $doc['numero_processo'] ?? null,
                'nome_original' => $nomeOriginal,
                'extensao' => $doc['extensao'] ?? null,
                'mime_type' => $mime,
                'tamanho_bytes' => $tamanho,
                'hash_arquivo' => $doc['hash_arquivo'] ?? null,
                'modo' => $modo,
            ],
        ]
    );

    if (ob_get_level()) {
        while (ob_get_level()) {
            ob_end_clean();
        }
    }

    header('Content-Type: ' . $mime);
    header('Content-Length: ' . $tamanho);
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('Pragma: public');
    header('Content-Disposition: ' . $modo . '; filename="' . addslashes($nomeOriginal) . '"');

    readfile($arquivo);
    exit;
} catch (Throwable $e) {
    error_log('[SGL DOCUMENTO ARQUIVO] ' . $e->getMessage());

    try {
        sgl_doc_endpoint_log(
            $conn,
            'Falha técnica ao abrir documento',
            $id > 0 ? (string)$id : null,
            'O endpoint não conseguiu concluir a entrega do arquivo.',
            [
                'tipo_acao' => 'EVENTO',
                'resultado' => 'FALHA',
                'nivel' => 'ERRO',
                'dados_novos' => [
                    'modo' => $modo,
                    'erro_tecnico' => mb_substr($e->getMessage(), 0, 500, 'UTF-8'),
                ],
            ]
        );
    } catch (Throwable $eLog) {
        error_log('[SGL DOCUMENTO ARQUIVO LOG] ' . $eLog->getMessage());
    }

    http_response_code(500);
    exit('Não foi possível abrir o documento.');
}
