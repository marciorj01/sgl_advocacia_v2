<?php
/** Download seguro de documento publicado no Portal do Cliente. */
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/portal_auth.php';

rojexPortalIniciarSessao();
$conn = conectar();
rojexPortalExigirLogin($conn, 'login.php');

$contaId = rojexPortalContaId();
$tenantId = rojexPortalTenantId();
$escritorioId = rojexPortalEscritorioId();
$clienteId = rojexPortalClienteId();
$permissoes = is_array($_SESSION['portal_permissoes'] ?? null) ? $_SESSION['portal_permissoes'] : [];
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if ($contaId === null || $tenantId === null || $escritorioId === null || $clienteId === null || empty($permissoes['ver_documentos']) || !$id) {
    http_response_code(403);
    exit('Acesso não autorizado.');
}

$stmt = $conn->prepare(
    "SELECT pd.id AS publicacao_id, pd.documento_id, da.nome_original, da.caminho,
            da.mime_type, da.tamanho_bytes, da.hash_arquivo
       FROM portal_documentos_publicacoes pd
       INNER JOIN documentos_arquivos da
               ON da.id = pd.documento_id
              AND da.tenant_id = pd.tenant_id
              AND da.escritorio_id = pd.escritorio_id
              AND da.deletado = 0
              AND da.status = 'Ativo'
      WHERE pd.id = ? AND pd.tenant_id = ? AND pd.escritorio_id = ?
        AND pd.cliente_id = ? AND pd.publicado = 1 LIMIT 1"
);
$stmt->bind_param('isis', $id, $tenantId, $escritorioId, $clienteId);
$stmt->execute();
$doc = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$doc) {
    $conn->close();
    http_response_code(404);
    exit('Documento não encontrado.');
}

$caminhoRelativo = ltrim(str_replace('\\', '/', (string)$doc['caminho']), '/');
$base = realpath(dirname(__DIR__));
$arquivo = realpath(dirname(__DIR__) . '/' . $caminhoRelativo);
if ($base === false || $arquivo === false || !is_file($arquivo) || !str_starts_with($arquivo, $base . DIRECTORY_SEPARATOR)) {
    $conn->close();
    http_response_code(404);
    exit('Arquivo indisponível.');
}

$hashEsperado = trim((string)($doc['hash_arquivo'] ?? ''));
if ($hashEsperado !== '' && !hash_equals(strtolower($hashEsperado), strtolower(hash_file('sha256', $arquivo)))) {
    $conn->close();
    http_response_code(409);
    exit('A integridade do arquivo não pôde ser confirmada.');
}

$conn->begin_transaction();
$stmt = $conn->prepare("UPDATE portal_documentos_publicacoes SET primeira_visualizacao_em = COALESCE(primeira_visualizacao_em, NOW()), ultimo_download_em = NOW() WHERE id = ? AND tenant_id = ? AND escritorio_id = ? AND cliente_id = ?");
$stmt->bind_param('isis', $id, $tenantId, $escritorioId, $clienteId);
$stmt->execute();
$stmt->close();

$acao = 'Cliente baixou documento publicado no Portal';
$tabela = 'portal_documentos_publicacoes';
$registroId = (string)$id;
$detalhes = 'Download seguro realizado pelo cliente do Portal.';
$ip = rojexPortalIpCliente();
$nome = trim((string)($_SESSION['portal_cliente_nome'] ?? 'Cliente do Portal'));
$login = 'portal:' . $clienteId;
$perfil = 'CLIENTE_PORTAL';
$tipo = 'DOWNLOAD';
$modulo = 'Portal do Cliente';
$dados = json_encode(['documento_id'=>(int)$doc['documento_id'],'nome_original'=>(string)$doc['nome_original']], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
$origem = 'portal/download_documento.php';
$resultado = 'SUCESSO';
$nivel = 'INFO';
$sessao = session_id();
$ua = rojexPortalUserAgent();
$escopo = 'TENANT';
$stmt = $conn->prepare("INSERT INTO logs_sistema (tenant_id,escritorio_id,escopo,usuario_id,acao,tabela,registro_id,detalhes,ip,usuario_nome,usuario_login,usuario_perfil,tipo_acao,modulo,dados_novos,origem,resultado,nivel,sessao_id,user_agent) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
if ($stmt) {
    $stmt->bind_param('sisissssssssssssssss', $tenantId,$escritorioId,$escopo,$contaId,$acao,$tabela,$registroId,$detalhes,$ip,$nome,$login,$perfil,$tipo,$modulo,$dados,$origem,$resultado,$nivel,$sessao,$ua);
    $stmt->execute();
    $stmt->close();
}
$conn->commit();
$conn->close();

$nomeDownload = preg_replace('/[\r\n"\\\/]+/', '_', (string)$doc['nome_original']);
$mime = trim((string)$doc['mime_type']) ?: 'application/octet-stream';
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($arquivo));
header("Content-Disposition: attachment; filename*=UTF-8''" . rawurlencode($nomeDownload));
readfile($arquivo);
exit;
