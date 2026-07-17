<?php
/**
 * portal/index.php
 * Página inicial segura do Portal do Cliente — Sprint 4.7.4.
 * Nenhum conteúdo jurídico é publicado automaticamente nesta etapa.
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
    exit();
}

$conn->close();

function rojexPortalIndexH(string $valor): string
{
    return htmlspecialchars($valor, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$logoUrl = '';
foreach ([
    '../assets/img/logo_rojex_ai.png',
    '../assets/img/logo_rojex.png',
    '../assets/img/logo.png',
    '../assets/img/logo_custom.png',
] as $candidato) {
    if (is_file(__DIR__ . '/' . $candidato)) {
        $logoUrl = $candidato;
        break;
    }
}

$rotulosPermissoes = [
    'ver_processos' => 'Processos',
    'ver_documentos' => 'Documentos',
    'enviar_documentos' => 'Envio de documentos',
    'ver_honorarios' => 'Honorários',
    'ver_recibos' => 'Recibos',
    'ver_agenda' => 'Agenda',
    'receber_notificacoes' => 'Notificações',
];
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex,nofollow">
    <title>Portal do Cliente — <?=rojexPortalIndexH($escritorioNome)?></title>
    <style>
        :root{--navy:#102f4c;--blue:#246ca8;--gold:#f0a500;--ink:#17212b;--muted:#667085;--line:#dce3ea}
        *{box-sizing:border-box}body{margin:0;min-height:100vh;font-family:Inter,"Segoe UI",Arial,sans-serif;color:var(--ink);background:#f3f6f9}
        header{background:linear-gradient(135deg,#0b2238,var(--navy));color:#fff;box-shadow:0 8px 28px #102f4c30}
        .top{max-width:1180px;margin:auto;padding:18px 24px;display:flex;align-items:center;justify-content:space-between;gap:20px}
        .brand{display:flex;align-items:center;gap:16px}.brand img{width:74px;height:62px;object-fit:contain;padding:5px;border-radius:12px;background:#fff}.brand strong{display:block;font-size:19px}.brand small{color:#ffffffb8}
        .secure{padding:9px 13px;border:1px solid #ffffff30;border-radius:999px;background:#ffffff12;font-size:13px;font-weight:700}
        main{max-width:1180px;margin:34px auto;padding:0 24px}.welcome{display:grid;grid-template-columns:1.3fr .7fr;gap:24px;margin-bottom:24px}
        .card{padding:26px;border:1px solid var(--line);border-radius:18px;background:#fff;box-shadow:0 10px 28px #1d355710}.eyebrow{color:var(--blue);font-size:12px;font-weight:900;letter-spacing:.12em;text-transform:uppercase}h1{margin:8px 0 10px;font-size:31px}.muted{color:var(--muted);line-height:1.6}
        .contexto{display:grid;gap:10px}.contexto div{padding-bottom:10px;border-bottom:1px solid var(--line)}.contexto div:last-child{border:0}.contexto small{display:block;color:var(--muted);margin-bottom:4px}.contexto code{font-size:12px;word-break:break-all}
        .notice{display:flex;gap:14px;align-items:flex-start;margin-bottom:24px;padding:18px 20px;border:1px solid #f0d58b;border-radius:16px;color:#694d00;background:#fff8e2}.notice b{display:block;margin-bottom:5px}.grid{display:grid;grid-template-columns:repeat(3,1fr);gap:16px}.module{min-height:125px;padding:20px;border:1px solid var(--line);border-radius:16px;background:#fff}.module h3{margin:0 0 8px;font-size:17px}.badge{display:inline-block;margin-top:9px;padding:6px 9px;border-radius:999px;font-size:12px;font-weight:800}.off{color:#56606d;background:#edf1f5}.on{color:#0f5132;background:#e3f6eb}
        footer{padding:30px;text-align:center;color:#7b8794;font-size:12px}@media(max-width:800px){.welcome{grid-template-columns:1fr}.grid{grid-template-columns:1fr}.secure{display:none}.top,main{padding-left:16px;padding-right:16px}}
    </style>
</head>
<body>
<header><div class="top"><div class="brand"><?php if($logoUrl!==''):?><img src="<?=rojexPortalIndexH($logoUrl)?>" alt="ROJEX.AI"><?php endif;?><div><strong>Portal do Cliente</strong><small><?=rojexPortalIndexH($escritorioNome)?></small></div></div><div class="secure">🔒 Sessão segura e exclusiva</div></div></header>
<main>
    <section class="welcome"><div class="card"><div class="eyebrow">Acesso confirmado</div><h1>Olá, <?=rojexPortalIndexH($clienteNome)?>.</h1><p class="muted">Sua conta foi autenticada com sucesso. O escritório controla individualmente quais informações poderão aparecer neste Portal.</p></div><aside class="card contexto"><div><small>Escritório</small><strong><?=rojexPortalIndexH($escritorioNome)?></strong></div><div><small>Cliente</small><strong><?=rojexPortalIndexH($clienteId)?></strong></div><div><small>Contexto protegido</small><code><?=rojexPortalIndexH($tenantId)?> / <?=$escritorioId?></code></div></aside></section>
    <div class="notice"><span>ℹ️</span><div><b>Conta ativada com segurança</b>Nenhum processo, documento, honorário, recibo ou compromisso foi publicado automaticamente. A liberação de conteúdo será implementada e testada nas próximas etapas.</div></div>
    <section class="grid"><?php foreach($rotulosPermissoes as $chave=>$rotulo): $ativa=!empty($permissoes[$chave]);?><article class="module"><h3><?=rojexPortalIndexH($rotulo)?></h3><div class="muted">Acesso administrado pelo escritório.</div><span class="badge <?=$ativa?'on':'off'?>"><?=$ativa?'Permitido':'Não liberado'?></span></article><?php endforeach;?></section>
</main><footer>Tecnologia ROJEX.AI · Portal Jurídico Multi-Tenant</footer>
</body></html>
