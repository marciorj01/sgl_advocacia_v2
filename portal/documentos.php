<?php
/**
 * portal/documentos.php
 * Documentos do Portal do Cliente — RC2.13.
 */
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
$clienteNome = trim((string)($_SESSION['portal_cliente_nome'] ?? 'Cliente'));
$escritorioNome = trim((string)($_SESSION['portal_escritorio_nome'] ?? 'Escritório'));
$permissoes = is_array($_SESSION['portal_permissoes'] ?? null) ? $_SESSION['portal_permissoes'] : [];

if ($contaId === null || $tenantId === null || $escritorioId === null || $clienteId === null) {
    rojexPortalEncerrarSessao($conn, 'CONTEXTO_INVALIDO');
    header('Location: login.php', true, 302);
    exit;
}

$podeVer = !empty($permissoes['ver_documentos']);
$podeEnviar = !empty($permissoes['enviar_documentos']);
if (!$podeVer && !$podeEnviar) {
    http_response_code(403);
    exit('Acesso não autorizado.');
}

function portalDocH(string $valor): string
{
    return htmlspecialchars($valor, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function portalDocLog(mysqli $conn, string $acao, string $tabela, ?string $registroId, string $detalhes, array $dados = []): void
{
    try {
        $tenantId = rojexPortalTenantId();
        $escritorioId = rojexPortalEscritorioId();
        $contaId = rojexPortalContaId();
        $clienteNome = trim((string)($_SESSION['portal_cliente_nome'] ?? 'Cliente do Portal'));
        $clienteId = rojexPortalClienteId();
        $ip = rojexPortalIpCliente();
        $sessaoId = session_id();
        $userAgent = rojexPortalUserAgent();
        $dadosNovos = json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $origem = 'portal/documentos.php';
        $escopo = 'TENANT';
        $tipoAcao = 'DOCUMENTO';
        $modulo = 'Portal do Cliente';
        $resultado = 'SUCESSO';
        $nivel = 'INFO';
        $usuarioLogin = $clienteId !== null ? 'portal:' . $clienteId : 'portal';
        $usuarioPerfil = 'CLIENTE_PORTAL';

        $stmt = $conn->prepare(
            "INSERT INTO logs_sistema
                (tenant_id, escritorio_id, escopo, usuario_id, acao, tabela, registro_id,
                 detalhes, ip, usuario_nome, usuario_login, usuario_perfil, tipo_acao,
                 modulo, dados_novos, origem, resultado, nivel, sessao_id, user_agent)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        if ($stmt) {
            $stmt->bind_param(
                'sisissssssssssssssss',
                $tenantId, $escritorioId, $escopo, $contaId, $acao, $tabela, $registroId,
                $detalhes, $ip, $clienteNome, $usuarioLogin, $usuarioPerfil, $tipoAcao,
                $modulo, $dadosNovos, $origem, $resultado, $nivel, $sessaoId, $userAgent
            );
            $stmt->execute();
            $stmt->close();
        }
    } catch (Throwable $e) {
        error_log('[ROJEX PORTAL][LOG DOCUMENTOS] ' . $e->getMessage());
    }
}

$mensagem = '';
$erro = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$podeEnviar) {
        http_response_code(403);
        exit('Envio de documentos não autorizado.');
    }

    if (!rojexPortalValidarCsrf($_POST['csrf_token'] ?? null)) {
        $erro = 'A sessão expirou. Atualize a página e tente novamente.';
    } else {
        $titulo = trim((string)($_POST['titulo'] ?? ''));
        $descricao = trim((string)($_POST['descricao'] ?? ''));
        $processoId = trim((string)($_POST['processo_id'] ?? ''));
        $arquivo = $_FILES['arquivo'] ?? null;

        if ($titulo === '' || mb_strlen($titulo, 'UTF-8') > 180) {
            $erro = 'Informe um título válido com até 180 caracteres.';
        } elseif (!is_array($arquivo) || ($arquivo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $erro = 'Selecione um arquivo válido.';
        } else {
            $tamanho = (int)($arquivo['size'] ?? 0);
            $nomeOriginal = basename((string)($arquivo['name'] ?? ''));
            $temporario = (string)($arquivo['tmp_name'] ?? '');
            $extensao = strtolower(pathinfo($nomeOriginal, PATHINFO_EXTENSION));
            $permitidos = [
                'pdf' => ['application/pdf'],
                'doc' => ['application/msword', 'application/CDFV2', 'application/octet-stream'],
                'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/octet-stream'],
                'jpg' => ['image/jpeg'],
                'jpeg' => ['image/jpeg'],
                'png' => ['image/png'],
            ];

            if ($tamanho <= 0 || $tamanho > 15 * 1024 * 1024) {
                $erro = 'O arquivo deve ter no máximo 15 MB.';
            } elseif (!isset($permitidos[$extensao])) {
                $erro = 'Formato não permitido. Use PDF, DOC, DOCX, JPG, JPEG ou PNG.';
            } elseif (!is_uploaded_file($temporario)) {
                $erro = 'O upload não foi reconhecido pelo servidor.';
            } else {
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mime = (string)$finfo->file($temporario);
                if (!in_array($mime, $permitidos[$extensao], true)) {
                    $erro = 'O conteúdo do arquivo não corresponde ao formato informado.';
                }
            }

            if ($erro === '' && $processoId !== '') {
                $stmt = $conn->prepare(
                    "SELECT id FROM processos
                      WHERE id = ? AND tenant_id = ? AND escritorio_id = ? AND cliente_id = ?
                        AND status != 'Excluído' LIMIT 1"
                );
                $stmt->bind_param('ssis', $processoId, $tenantId, $escritorioId, $clienteId);
                $stmt->execute();
                $valido = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if (!$valido) {
                    $erro = 'O processo selecionado não pertence a este cliente.';
                }
            }

            if ($erro === '') {
                $tenantSeguro = preg_replace('/[^A-Za-z0-9_-]/', '_', $tenantId);
                $clienteSeguro = preg_replace('/[^A-Za-z0-9_-]/', '_', $clienteId);
                $diretorioRelativo = 'storage/portal_documentos/' . $tenantSeguro . '/escritorio_' . $escritorioId . '/cliente_' . $clienteSeguro;
                $diretorioFisico = dirname(__DIR__) . '/' . $diretorioRelativo;

                if (!is_dir($diretorioFisico) && !mkdir($diretorioFisico, 0750, true) && !is_dir($diretorioFisico)) {
                    $erro = 'Não foi possível preparar o armazenamento seguro.';
                } else {
                    $nomeArquivo = date('Ymd_His') . '_' . bin2hex(random_bytes(16)) . '.' . $extensao;
                    $destino = $diretorioFisico . '/' . $nomeArquivo;
                    $caminhoRelativo = $diretorioRelativo . '/' . $nomeArquivo;

                    if (!move_uploaded_file($temporario, $destino)) {
                        $erro = 'Não foi possível concluir o envio do arquivo.';
                    } else {
                        @chmod($destino, 0640);
                        $hash = hash_file('sha256', $destino);
                        try {
                            $conn->begin_transaction();
                            $processoDb = $processoId !== '' ? $processoId : null;
                            $stmt = $conn->prepare(
                                "INSERT INTO portal_documentos_envios
                                    (tenant_id, escritorio_id, conta_id, cliente_id, processo_id,
                                     titulo, descricao_cliente, nome_original, nome_arquivo, caminho,
                                     extensao, mime_type, tamanho_bytes, hash_sha256, status)
                                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'AGUARDANDO_ANALISE')"
                            );
                            $stmt->bind_param(
                                'siisssssssssis',
                                $tenantId, $escritorioId, $contaId, $clienteId, $processoDb,
                                $titulo, $descricao, $nomeOriginal, $nomeArquivo, $caminhoRelativo,
                                $extensao, $mime, $tamanho, $hash
                            );
                            $stmt->execute();
                            $envioId = (int)$conn->insert_id;
                            $stmt->close();

                            portalDocLog($conn, 'Cliente enviou documento pelo Portal', 'portal_documentos_envios', (string)$envioId, 'Documento recebido para análise do escritório.', [
                                'cliente_id' => $clienteId,
                                'processo_id' => $processoDb,
                                'nome_original' => $nomeOriginal,
                                'mime_type' => $mime,
                                'tamanho_bytes' => $tamanho,
                                'hash_sha256' => $hash,
                                'status' => 'AGUARDANDO_ANALISE',
                            ]);

                            $conn->commit();
                            rojexPortalRotacionarCsrf();
                            $mensagem = 'Documento enviado com segurança. O escritório já poderá analisá-lo.';
                        } catch (Throwable $e) {
                            $conn->rollback();
                            @unlink($destino);
                            error_log('[ROJEX PORTAL][UPLOAD DOCUMENTO] ' . $e->getMessage());
                            $erro = 'Não foi possível registrar o documento. Tente novamente.';
                        }
                    }
                }
            }
        }
    }
}

$processos = [];
$stmt = $conn->prepare(
    "SELECT id, numero_processo FROM processos
      WHERE tenant_id = ? AND escritorio_id = ? AND cliente_id = ? AND status != 'Excluído'
      ORDER BY id DESC LIMIT 200"
);
$stmt->bind_param('sis', $tenantId, $escritorioId, $clienteId);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) { $processos[] = $row; }
$stmt->close();

$publicados = [];
if ($podeVer) {
    $stmt = $conn->prepare(
        "SELECT pd.id AS publicacao_id, pd.titulo_publico, pd.descricao_publica, pd.processo_id,
                pd.publicado_em, pd.ultimo_download_em,
                da.id AS documento_id, da.nome_original, da.extensao, da.mime_type,
                da.tamanho_bytes, da.hash_arquivo, da.criado_em
           FROM portal_documentos_publicacoes pd
           INNER JOIN documentos_arquivos da
                   ON da.id = pd.documento_id
                  AND da.tenant_id = pd.tenant_id
                  AND da.escritorio_id = pd.escritorio_id
                  AND da.deletado = 0
                  AND da.status = 'Ativo'
          WHERE pd.tenant_id = ? AND pd.escritorio_id = ? AND pd.cliente_id = ? AND pd.publicado = 1
          ORDER BY COALESCE(pd.publicado_em, pd.criado_em) DESC"
    );
    $stmt->bind_param('sis', $tenantId, $escritorioId, $clienteId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) { $publicados[] = $row; }
    $stmt->close();
}

$enviados = [];
if ($podeEnviar) {
    $stmt = $conn->prepare(
        "SELECT id, processo_id, titulo, nome_original, extensao, tamanho_bytes, status, criado_em, analisado_em
           FROM portal_documentos_envios
          WHERE tenant_id = ? AND escritorio_id = ? AND conta_id = ? AND cliente_id = ?
          ORDER BY criado_em DESC LIMIT 100"
    );
    $stmt->bind_param('siis', $tenantId, $escritorioId, $contaId, $clienteId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) { $enviados[] = $row; }
    $stmt->close();
}

$conn->close();
$csrf = rojexPortalTokenCsrf();
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow">
<title>Documentos — Portal do Cliente</title>
<style>
:root{--navy:#102f4c;--blue:#246ca8;--ink:#17212b;--muted:#667085;--line:#dce3ea;--ok:#137a4b;--danger:#a32020}*{box-sizing:border-box}body{margin:0;background:#f3f6f9;color:var(--ink);font-family:Inter,"Segoe UI",Arial,sans-serif}header{background:linear-gradient(135deg,#0b2238,var(--navy));color:#fff}.top{max-width:1120px;margin:auto;padding:18px 22px;display:flex;align-items:center;justify-content:space-between;gap:16px}.top a{color:#fff;text-decoration:none;font-weight:800}.top small{display:block;color:#ffffffb5;margin-top:4px}main{max-width:1120px;margin:28px auto;padding:0 22px}.card{background:#fff;border:1px solid var(--line);border-radius:18px;padding:24px;box-shadow:0 10px 28px #1d355710;margin-bottom:20px}h1,h2{margin-top:0}.muted{color:var(--muted);line-height:1.5}.alert{padding:14px 16px;border-radius:12px;margin-bottom:18px}.ok{background:#e5f7ee;color:#0f5132;border:1px solid #bce4cf}.err{background:#fff0f0;color:#842029;border:1px solid #f0c2c2}.grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}.field{display:grid;gap:7px}.field.full{grid-column:1/-1}label{font-weight:800;font-size:14px}input,select,textarea{width:100%;padding:12px;border:1px solid #cfd8e2;border-radius:10px;font:inherit;background:#fff}textarea{min-height:100px;resize:vertical}.btn{display:inline-flex;justify-content:center;align-items:center;padding:11px 16px;border:0;border-radius:10px;background:var(--blue);color:#fff;text-decoration:none;font-weight:900;cursor:pointer}.item{border:1px solid var(--line);border-radius:14px;padding:17px;margin-top:12px}.head{display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap}.meta{display:flex;gap:12px;flex-wrap:wrap;margin-top:10px;color:var(--muted);font-size:13px}.badge{padding:5px 9px;border-radius:999px;background:#edf3f8;font-size:12px;font-weight:900}.empty{padding:22px;text-align:center;color:var(--muted);border:1px dashed var(--line);border-radius:12px}.back{padding:9px 12px;border:1px solid #ffffff50;border-radius:9px}@media(max-width:760px){.grid{grid-template-columns:1fr}.top,main{padding-left:15px;padding-right:15px}.card{padding:18px}.field.full{grid-column:auto}}
</style>
</head>
<body>
<header><div class="top"><div><strong>Documentos</strong><small><?=portalDocH($escritorioNome)?> · <?=portalDocH($clienteNome)?></small></div><a class="back" href="index.php">← Voltar ao Portal</a></div></header>
<main>
<?php if($mensagem!==''):?><div class="alert ok"><?=portalDocH($mensagem)?></div><?php endif;?>
<?php if($erro!==''):?><div class="alert err"><?=portalDocH($erro)?></div><?php endif;?>

<?php if($podeEnviar):?>
<section class="card"><h1>Enviar documento ao escritório</h1><p class="muted">Envie PDF, DOC, DOCX, JPG ou PNG, com até 15 MB. O arquivo será armazenado com nome aleatório, hash SHA-256 e isolamento por escritório e cliente.</p>
<form method="post" enctype="multipart/form-data" class="grid">
<input type="hidden" name="csrf_token" value="<?=portalDocH($csrf)?>">
<div class="field"><label for="titulo">Título *</label><input id="titulo" name="titulo" maxlength="180" required></div>
<div class="field"><label for="processo_id">Processo relacionado</label><select id="processo_id" name="processo_id"><option value="">Sem vínculo específico</option><?php foreach($processos as $p):?><option value="<?=portalDocH((string)$p['id'])?>"><?=portalDocH((string)$p['numero_processo'])?></option><?php endforeach;?></select></div>
<div class="field full"><label for="descricao">Descrição</label><textarea id="descricao" name="descricao" maxlength="3000" placeholder="Explique brevemente o conteúdo do documento."></textarea></div>
<div class="field full"><label for="arquivo">Arquivo *</label><input id="arquivo" type="file" name="arquivo" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png" required></div>
<div class="field full"><button class="btn" type="submit">Enviar documento com segurança</button></div>
</form></section>
<?php endif;?>

<?php if($podeVer):?>
<section class="card"><h2>Documentos enviados pelo escritório</h2><p class="muted">Somente documentos publicados expressamente pelo escritório aparecem aqui.</p>
<?php if(!$publicados):?><div class="empty">Nenhum documento foi publicado para você.</div><?php else: foreach($publicados as $doc):?>
<article class="item"><div class="head"><strong><?=portalDocH((string)($doc['titulo_publico'] ?: $doc['nome_original']))?></strong><span class="badge"><?=portalDocH(strtoupper((string)$doc['extensao']))?></span></div><?php if(!empty($doc['descricao_publica'])):?><p class="muted"><?=nl2br(portalDocH((string)$doc['descricao_publica']))?></p><?php endif;?><div class="meta"><span><?=number_format(((int)$doc['tamanho_bytes'])/1024,1,',','.')?> KB</span><span>Publicado em <?=date('d/m/Y H:i',strtotime((string)($doc['publicado_em'] ?: $doc['criado_em'])))?></span></div><p><a class="btn" href="download_documento.php?id=<?=(int)$doc['publicacao_id']?>">Baixar com segurança</a></p></article>
<?php endforeach; endif;?></section>
<?php endif;?>

<?php if($podeEnviar):?>
<section class="card"><h2>Meus envios</h2><?php if(!$enviados):?><div class="empty">Você ainda não enviou documentos.</div><?php else: foreach($enviados as $envio):?>
<article class="item"><div class="head"><strong><?=portalDocH((string)$envio['titulo'])?></strong><span class="badge"><?=portalDocH(str_replace('_',' ',(string)$envio['status']))?></span></div><div class="meta"><span><?=portalDocH((string)$envio['nome_original'])?></span><span><?=number_format(((int)$envio['tamanho_bytes'])/1024,1,',','.')?> KB</span><span>Enviado em <?=date('d/m/Y H:i',strtotime((string)$envio['criado_em']))?></span></div></article>
<?php endforeach; endif;?></section>
<?php endif;?>
</main></body></html>
