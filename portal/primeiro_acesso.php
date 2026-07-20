<?php
/**
 * portal/primeiro_acesso.php
 * Convite e definição da primeira senha do Portal do Cliente ROJEX.AI.
 * Sprint 4.7.4 — PHP 8+, MySQL/MariaDB, XAMPP e Hostinger.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/portal_auth.php';

rojexPortalIniciarSessao();

function rojexPortalPrimeiroH(string $valor): string
{
    return htmlspecialchars($valor, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function rojexPortalPrimeiroToken(string $token): string
{
    $token = trim($token);
    return preg_match('/^[a-f0-9]{64}$/i', $token) ? strtolower($token) : '';
}

function rojexPortalPrimeiroTenant(string $tenant): string
{
    $tenant = trim($tenant);
    return preg_match('/^[A-Za-z0-9._-]{1,80}$/', $tenant) ? $tenant : '';
}

function rojexPortalPrimeiroMarca(mysqli $conn, array $convite): array
{
    $cfg = [];
    $escritorioId = (int)$convite['escritorio_id'];
    $tenantId = (string)$convite['tenant_id'];
    $stmt = $conn->prepare(
        "SELECT chave,valor FROM escritorios_configuracoes_saas
          WHERE escritorio_id=? AND tenant_id=?
            AND chave IN ('nome_escritorio','logo_arquivo','cor_primaria','cor_secundaria','cor_accent')"
    );
    if ($stmt) {
        $stmt->bind_param('is', $escritorioId, $tenantId);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($res && ($row = $res->fetch_assoc())) $cfg[(string)$row['chave']] = trim((string)$row['valor']);
        $stmt->close();
    }
    $cor = static fn(string $v, string $p): string => preg_match('/^#[0-9a-fA-F]{6}$/', $v) ? $v : $p;
    $logo = (string)($cfg['logo_arquivo'] ?? '');
    if ($logo !== '' && (str_contains($logo, '..') || str_starts_with($logo, '/') || !preg_match('#^[A-Za-z0-9_./-]+\.(?:png|jpe?g|gif|webp|svg)$#i', $logo))) $logo = '';
    return [
        'nome' => (string)($cfg['nome_escritorio'] ?? $convite['escritorio_nome']),
        'logo' => $logo,
        'primaria' => $cor((string)($cfg['cor_primaria'] ?? ''), '#163a5f'),
        'secundaria' => $cor((string)($cfg['cor_secundaria'] ?? ''), '#2c6fad'),
        'accent' => $cor((string)($cfg['cor_accent'] ?? ''), '#f0a500'),
    ];
}

$token = rojexPortalPrimeiroToken((string)($_POST['token'] ?? $_GET['token'] ?? ''));
$tenant = rojexPortalPrimeiroTenant((string)($_POST['tenant'] ?? $_GET['tenant'] ?? ''));
$erro = '';
$sucesso = false;
$convite = null;
$conn = null;
$marca = ['nome'=>'Portal do Cliente','logo'=>'','primaria'=>'#163a5f','secundaria'=>'#2c6fad','accent'=>'#f0a500'];

try {
    $conn = conectar();
    if ($token === '' || $tenant === '') {
        $erro = 'O convite informado é inválido. Solicite um novo link ao seu escritório.';
    } else {
        $tokenHash = hash('sha256', $token);
        $stmt = $conn->prepare(
            "SELECT pt.id AS token_id,pt.conta_id,pt.tenant_id,pt.escritorio_id,pt.cliente_id,pt.expira_em,
                    pc.email,pc.status,pc.primeiro_acesso_pendente,c.nome AS cliente_nome,
                    e.nome AS escritorio_nome,e.subdominio
               FROM portal_clientes_tokens pt
               INNER JOIN portal_clientes_contas pc ON pc.id=pt.conta_id AND pc.tenant_id=pt.tenant_id AND pc.escritorio_id=pt.escritorio_id AND pc.cliente_id=pt.cliente_id
               INNER JOIN clientes c ON c.id=pc.cliente_id AND c.tenant_id=pc.tenant_id AND c.escritorio_id=pc.escritorio_id AND c.deletado=0 AND c.status='Ativo'
               INNER JOIN escritorios_saas e ON e.id=pc.escritorio_id AND e.tenant_id=pc.tenant_id AND e.status='ativo'
               INNER JOIN escritorios_modulos_saas em ON em.escritorio_id=e.id AND em.ativo=1
               INNER JOIN modulos_saas m ON m.id=em.modulo_id AND m.codigo='portal_cliente' AND m.ativo=1 AND m.status_lancamento='producao'
              WHERE pt.token_hash=? AND pt.tenant_id=? AND pt.tipo='CONVITE'
                AND pt.utilizado_em IS NULL AND pt.revogado_em IS NULL AND pt.expira_em>NOW()
                AND pc.status='CONVITE_PENDENTE' AND pc.primeiro_acesso_pendente=1
              LIMIT 1"
        );
        if (!$stmt) throw new RuntimeException('Não foi possível validar o convite.');
        $stmt->bind_param('ss', $tokenHash, $tenant); $stmt->execute();
        $convite = $stmt->get_result()->fetch_assoc() ?: null; $stmt->close();
        if (!$convite) $erro = 'Este convite é inválido, expirou ou já foi utilizado. Solicite um novo link ao seu escritório.';
        else $marca = rojexPortalPrimeiroMarca($conn, $convite);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $convite && $erro === '') {
        $senha = (string)($_POST['senha'] ?? '');
        $confirmar = (string)($_POST['confirmar_senha'] ?? '');
        if (!rojexPortalValidarCsrf((string)($_POST['csrf_token'] ?? ''))) {
            $erro = 'Sessão expirada. Atualize a página e tente novamente.';
        } elseif (strlen($senha) < 8 || strlen($senha) > 128 || !preg_match('/[A-Z]/', $senha) || !preg_match('/[a-z]/', $senha) || !preg_match('/[0-9]/', $senha)) {
            $erro = 'A senha deve ter de 8 a 128 caracteres, com letra maiúscula, minúscula e número.';
        } elseif (!hash_equals($senha, $confirmar)) {
            $erro = 'A confirmação da senha não confere.';
        } else {
            $senhaHash = password_hash($senha, PASSWORD_DEFAULT);
            if (!is_string($senhaHash) || $senhaHash === '') throw new RuntimeException('Não foi possível proteger a nova senha.');
            $tokenId = (int)$convite['token_id']; $contaId = (int)$convite['conta_id'];
            $escritorioId = (int)$convite['escritorio_id']; $clienteId = (string)$convite['cliente_id']; $tenantId = (string)$convite['tenant_id'];
            $conn->begin_transaction();
            try {
                $stmt = $conn->prepare("SELECT id FROM portal_clientes_tokens WHERE id=? AND conta_id=? AND tenant_id=? AND escritorio_id=? AND cliente_id=? AND token_hash=? AND tipo='CONVITE' AND utilizado_em IS NULL AND revogado_em IS NULL AND expira_em>NOW() LIMIT 1 FOR UPDATE");
                $stmt->bind_param('iisiss', $tokenId, $contaId, $tenantId, $escritorioId, $clienteId, $tokenHash); $stmt->execute();
                $valido = $stmt->get_result()->fetch_assoc(); $stmt->close();
                if (!$valido) throw new RuntimeException('O convite deixou de ser válido.');
                $stmt = $conn->prepare("UPDATE portal_clientes_contas SET senha_hash=?,status='ATIVA',primeiro_acesso_pendente=0,email_verificado_em=NOW(),senha_definida_em=NOW(),falhas_consecutivas=0,bloqueado_ate=NULL WHERE id=? AND tenant_id=? AND escritorio_id=? AND cliente_id=? AND status='CONVITE_PENDENTE'");
                $stmt->bind_param('sisis', $senhaHash, $contaId, $tenantId, $escritorioId, $clienteId); $stmt->execute();
                if ($stmt->affected_rows !== 1) { $stmt->close(); throw new RuntimeException('A conta não pôde ser ativada.'); } $stmt->close();
                $stmt = $conn->prepare("UPDATE portal_clientes_tokens SET utilizado_em=NOW() WHERE id=? AND conta_id=? AND tenant_id=? AND escritorio_id=? AND cliente_id=? AND token_hash=? AND tipo='CONVITE' AND utilizado_em IS NULL AND revogado_em IS NULL AND expira_em>NOW()");
                $stmt->bind_param('iisiss', $tokenId, $contaId, $tenantId, $escritorioId, $clienteId, $tokenHash); $stmt->execute();
                if ($stmt->affected_rows !== 1) { $stmt->close(); throw new RuntimeException('O convite não pôde ser consumido com segurança.'); } $stmt->close();
                $stmt = $conn->prepare("UPDATE portal_clientes_tokens SET revogado_em=NOW() WHERE conta_id=? AND tenant_id=? AND escritorio_id=? AND cliente_id=? AND id<>? AND tipo='CONVITE' AND utilizado_em IS NULL AND revogado_em IS NULL");
                $stmt->bind_param('isisi', $contaId, $tenantId, $escritorioId, $clienteId, $tokenId); $stmt->execute(); $stmt->close();
                $conn->commit(); $sucesso = true; $erro = '';
                rojexPortalRotacionarCsrf();
            } catch (Throwable $e) { $conn->rollback(); throw $e; }
        }
    }
} catch (Throwable $e) {
    error_log('[ROJEX PORTAL][PRIMEIRO ACESSO] ' . $e->getMessage());
    $erro = 'Não foi possível concluir a definição da senha. Solicite um novo convite ao seu escritório.';
}

if ($conn instanceof mysqli) $conn->close();
$csrf = rojexPortalTokenCsrf();
$logoUrl = '';
foreach ([
    '../assets/img/logo_rojex_ai.png',
    '../assets/img/logo_rojex.png',
    '../assets/img/logo.png',
    '../assets/img/logo_custom.png',
] as $logoRojexCandidato) {
    if (is_file(__DIR__ . '/' . $logoRojexCandidato)) {
        $logoUrl = $logoRojexCandidato;
        break;
    }
}
$iniciais = mb_strtoupper(mb_substr(trim((string)$marca['nome']), 0, 2, 'UTF-8'), 'UTF-8');
$loginUrl = 'login.php' . (!empty($convite['subdominio']) ? '?escritorio=' . rawurlencode((string)$convite['subdominio']) : '');
?>
<!DOCTYPE html><html lang="pt-BR"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>Primeiro acesso - <?=rojexPortalPrimeiroH((string)$marca['nome'])?></title>
<style>:root{--p:<?=rojexPortalPrimeiroH($marca['primaria'])?>;--s:<?=rojexPortalPrimeiroH($marca['secundaria'])?>;--a:<?=rojexPortalPrimeiroH($marca['accent'])?>}*{box-sizing:border-box}body{margin:0;min-height:100vh;padding:24px;display:grid;place-items:center;font-family:Inter,"Segoe UI",Arial,sans-serif;color:#17212b;background:linear-gradient(145deg,#07111b,var(--p),#08121c)}.shell{width:100%;max-width:960px;display:grid;grid-template-columns:.9fr 1.1fr;overflow:hidden;border-radius:26px;background:#fff;box-shadow:0 34px 90px #0006}.brand{padding:48px;color:#fff;background:linear-gradient(155deg,var(--p),#07111b)}.logo{width:200px;min-height:105px;display:grid;place-items:center;padding:14px;margin-bottom:32px;border:1px solid #ffffff30;border-radius:18px;background:#ffffff16}.logo img{max-width:100%;max-height:105px}.mono{font-size:38px;font-weight:900;color:var(--a)}.brand h1{font-size:32px}.brand p{line-height:1.65;color:#ffffffc7}.panel{padding:48px}.eyebrow{color:var(--s);font-size:13px;font-weight:900;letter-spacing:.12em;text-transform:uppercase}h2{margin:8px 0 10px}.sub{color:#667085;line-height:1.5}.msg{margin:20px 0;padding:14px;border-radius:12px}.err{color:#842029;background:#fff0f1;border:1px solid #f3b7bd}.ok{color:#0f5132;background:#eaf8f0;border:1px solid #a7ddbd}.field{margin:17px 0}label{display:block;margin-bottom:7px;font-weight:750}input{width:100%;padding:13px 14px;border:1px solid #d9e0e7;border-radius:12px;font:inherit}button,.button{display:inline-block;width:100%;padding:14px;border:0;border-radius:12px;text-align:center;text-decoration:none;color:#fff;background:linear-gradient(135deg,var(--s),var(--p));font:inherit;font-weight:850;cursor:pointer}.help{font-size:13px;color:#667085;line-height:1.5}@media(max-width:760px){body{padding:12px}.shell{grid-template-columns:1fr}.brand{padding:28px}.brand p{display:none}.panel{padding:30px 24px}}</style></head><body><main class="shell"><section class="brand"><div class="logo"><?php if($logoUrl!==''):?><img src="<?=rojexPortalPrimeiroH($logoUrl)?>" alt="Logomarca"><?php else:?><span class="mono"><?=rojexPortalPrimeiroH($iniciais)?></span><?php endif;?></div><h1><?=rojexPortalPrimeiroH((string)$marca['nome'])?></h1><p>Crie sua senha pessoal para acessar com segurança as informações disponibilizadas pelo escritório.</p></section><section class="panel"><div class="eyebrow">Portal do Cliente</div><h2>Defina sua primeira senha</h2><p class="sub">Este convite é pessoal, temporário e pode ser utilizado uma única vez.</p>
<?php if($sucesso):?><div class="msg ok"><strong>Senha definida com sucesso.</strong><br>Sua conta está ativa e pronta para o primeiro acesso.</div><a class="button" href="<?=rojexPortalPrimeiroH($loginUrl)?>">Ir para o login</a>
<?php else:?><?php if($erro!==''):?><div class="msg err" role="alert"><?=rojexPortalPrimeiroH($erro)?></div><?php endif;?><?php if($convite && $erro===''):?><p class="help">Conta de <strong><?=rojexPortalPrimeiroH((string)$convite['cliente_nome'])?></strong> · <?=rojexPortalPrimeiroH((string)$convite['email'])?></p><form method="post" autocomplete="off"><input type="hidden" name="csrf_token" value="<?=rojexPortalPrimeiroH($csrf)?>"><input type="hidden" name="token" value="<?=rojexPortalPrimeiroH($token)?>"><input type="hidden" name="tenant" value="<?=rojexPortalPrimeiroH($tenant)?>"><div class="field"><label for="senha">Nova senha</label><input type="password" id="senha" name="senha" minlength="8" maxlength="128" autocomplete="new-password" required></div><div class="field"><label for="confirmar">Confirmar nova senha</label><input type="password" id="confirmar" name="confirmar_senha" minlength="8" maxlength="128" autocomplete="new-password" required></div><p class="help">Use ao menos 8 caracteres, incluindo letra maiúscula, minúscula e número.</p><button type="submit">Ativar minha conta</button></form><?php elseif(!$convite):?><a class="button" href="login.php">Voltar ao login</a><?php endif;?><?php endif;?></section></main></body></html>
