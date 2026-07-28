<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/integracoes.php';
iniciarSessaoSegura();
exigirLogin('auth/login.php');

$tenantId = function_exists('rojexTenantId') ? trim((string)rojexTenantId()) : trim((string)($_SESSION['tenant_id'] ?? ''));
$escritorioId = function_exists('rojexEscritorioId') ? (int)rojexEscritorioId() : (int)($_SESSION['escritorio_id'] ?? 0);
$id = (int)($_GET['id'] ?? 0);
if ($tenantId === '' || $escritorioId <= 0 || $id <= 0) { http_response_code(400); exit('Solicitação inválida.'); }

$conn = conectar();
$stmt = $conn->prepare("SELECT id, nome_original, caminho, mime_type, tamanho_bytes, hash_sha256 FROM portal_documentos_envios WHERE id = ? AND tenant_id = ? AND escritorio_id = ? LIMIT 1");
$stmt->bind_param('isi', $id, $tenantId, $escritorioId);
$stmt->execute();
$doc = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$doc) { $conn->close(); http_response_code(404); exit('Documento não encontrado.'); }

$relativo = ltrim(str_replace('\\', '/', (string)$doc['caminho']), '/');
$raiz = realpath(__DIR__ . '/storage/portal_documentos');
$arquivo = realpath(__DIR__ . '/' . $relativo);
if ($raiz === false || $arquivo === false || !is_file($arquivo) || !str_starts_with($arquivo, $raiz . DIRECTORY_SEPARATOR)) { $conn->close(); http_response_code(404); exit('Arquivo indisponível.'); }
$hashEsperado = trim((string)$doc['hash_sha256']);
if ($hashEsperado !== '' && !hash_equals(strtolower($hashEsperado), strtolower((string)hash_file('sha256', $arquivo)))) { $conn->close(); http_response_code(409); exit('Falha na verificação de integridade.'); }

if (function_exists('sgl_registrar_log')) {
    sgl_registrar_log($conn, 'Download de documento enviado pelo cliente', 'portal_documentos_envios', (string)$id, 'Arquivo acessado pela equipe do escritório.', [
        'tipo_acao'=>'EVENTO','modulo'=>'Portal do Cliente - Documentos','origem'=>'portal_documento_arquivo.php','dados_novos'=>['nome_original'=>(string)$doc['nome_original']]
    ]);
}
$conn->close();

$nome = preg_replace('/[\r\n\"]+/', '_', basename((string)$doc['nome_original']));
$mime = trim((string)$doc['mime_type']) ?: 'application/octet-stream';
while (ob_get_level() > 0) { ob_end_clean(); }
header('X-Content-Type-Options: nosniff');
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($arquivo));
header("Content-Disposition: attachment; filename=\"{$nome}\"; filename*=UTF-8''" . rawurlencode($nome));
header('Cache-Control: private, no-store, max-age=0');
readfile($arquivo);
exit;
