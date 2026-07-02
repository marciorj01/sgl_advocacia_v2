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

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$modo = isset($_GET['modo']) ? trim((string)$_GET['modo']) : 'inline';
if ($modo !== 'download') {
    $modo = 'inline';
}

if ($id <= 0) {
    http_response_code(400);
    exit('Documento inválido.');
}

try {
    $conn->query("CREATE TABLE IF NOT EXISTS documentos_arquivos (
        id INT AUTO_INCREMENT PRIMARY KEY,
        codigo VARCHAR(20) NOT NULL UNIQUE,
        titulo VARCHAR(180) NOT NULL,
        categoria VARCHAR(80) NOT NULL DEFAULT 'Documento geral',
        cliente_id VARCHAR(10) NULL,
        processo_id VARCHAR(10) NULL,
        numero_processo VARCHAR(80) NULL,
        descricao TEXT NULL,
        nome_original VARCHAR(255) NOT NULL,
        nome_arquivo VARCHAR(255) NOT NULL,
        caminho VARCHAR(255) NOT NULL,
        extensao VARCHAR(20) NULL,
        mime_type VARCHAR(120) NULL,
        tamanho_bytes BIGINT DEFAULT 0,
        hash_arquivo VARCHAR(64) NULL,
        usuario_id INT NULL,
        usuario_nome VARCHAR(150) NULL,
        status VARCHAR(30) NOT NULL DEFAULT 'Ativo',
        deletado TINYINT(1) NOT NULL DEFAULT 0,
        criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_doc_cliente (cliente_id),
        INDEX idx_doc_processo (processo_id),
        INDEX idx_doc_categoria (categoria),
        INDEX idx_doc_deletado (deletado)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $stmt = $conn->prepare("SELECT id, nome_original, caminho, mime_type, tamanho_bytes FROM documentos_arquivos WHERE id = ? AND COALESCE(deletado,0) = 0 LIMIT 1");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $doc = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$doc) {
        http_response_code(404);
        exit('Documento não encontrado.');
    }

    $base = realpath(__DIR__);
    $arquivo = realpath(__DIR__ . '/' . $doc['caminho']);

    if (!$base || !$arquivo || !str_starts_with($arquivo, $base) || !is_file($arquivo)) {
        http_response_code(404);
        exit('Arquivo físico não encontrado.');
    }

    $nomeOriginal = basename((string)$doc['nome_original']);
    $nomeOriginal = str_replace(['"', "\r", "\n"], '', $nomeOriginal);
    $mime = (string)($doc['mime_type'] ?: 'application/octet-stream');
    $tamanho = filesize($arquivo);

    if (function_exists('sgl_registrar_log')) {
        sgl_registrar_log($conn, $modo === 'download' ? 'DOWNLOAD_DOCUMENTO' : 'VISUALIZAR_DOCUMENTO', 'documentos_arquivos', (string)$id, 'Arquivo acessado: ' . $nomeOriginal);
    }

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
    http_response_code(500);
    exit('Não foi possível abrir o documento.');
}
