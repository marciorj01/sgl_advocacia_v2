<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/auth.php';
iniciarSessaoSegura();
exigirLogin('auth/login.php');
$conn = conectar();

$geradoId = (int)($_GET['gerado_id'] ?? 0);
if ($geradoId > 0) {
    $stmt = $conn->prepare("SELECT * FROM modelos_documentos_gerados WHERE id=? LIMIT 1");
    $stmt->bind_param('i', $geradoId); $stmt->execute(); $gerado = $stmt->get_result()->fetch_assoc(); $stmt->close();
    if (!$gerado) { http_response_code(404); exit('Documento gerado não encontrado.'); }
    $formato = $_GET['formato'] ?? 'html';
    $filename = preg_replace('/[^a-zA-Z0-9_-]+/', '_', $gerado['titulo']) ?: 'documento_gerado';
    if ($formato === 'doc') {
        header('Content-Type: application/msword; charset=utf-8');
        header('Content-Disposition: attachment; filename="'.$filename.'.doc"');
    }
    ?><!doctype html><html lang="pt-br"><head><meta charset="utf-8"><title><?= htmlspecialchars($gerado['titulo']) ?></title><style>body{font-family:Georgia,serif;margin:45px;line-height:1.72;color:#111}.doc{max-width:850px;margin:0 auto}.head{text-align:center;margin-bottom:35px}.meta{font-family:Arial,sans-serif;color:#666;font-size:12px}.body{white-space:pre-wrap;font-size:16px}@media print{body{margin:25mm}.noprint{display:none}}</style></head><body><div class="noprint" style="text-align:right;margin-bottom:12px"><button onclick="window.print()">Imprimir / Salvar PDF</button> <a href="modelo_gerar.php?gerado_id=<?= (int)$geradoId ?>&formato=doc">Word</a></div><div class="doc"><div class="head"><h2><?= htmlspecialchars($gerado['titulo']) ?></h2><div class="meta">Histórico nº <?= (int)$geradoId ?> · <?= date('d/m/Y', strtotime($gerado['gerado_em'])) ?></div></div><div class="body"><?= htmlspecialchars($gerado['conteudo_final']) ?></div></div></body></html><?php exit;
}

function mg_endereco(array $c): string { $p=[]; foreach(['logradouro','numero','complemento','bairro'] as $k) if(!empty($c[$k])) $p[]=$c[$k]; $cidadeUf=trim(($c['cidade']??'').(!empty($c['estado'])?'/'.$c['estado']:'')); if($cidadeUf && $cidadeUf !== '/') $p[]=$cidadeUf; return implode(', ', $p); }
function mg_mes(): string { $m=[1=>'janeiro','fevereiro','março','abril','maio','junho','julho','agosto','setembro','outubro','novembro','dezembro']; return $m[(int)date('n')] ?? date('m'); }
function mg_vars(?array $c, ?array $p): array { return [
 'cliente_nome'=>$c['nome']??'NOME DO CLIENTE','cliente_cpf_cnpj'=>$c['cpf_cnpj']??'CPF/CNPJ DO CLIENTE','cliente_endereco'=>$c?mg_endereco($c):'ENDEREÇO DO CLIENTE','cliente_cidade'=>$c['cidade']??'CIDADE','cliente_uf'=>$c['estado']??'UF','cliente_telefone'=>$c['telefone']??($c['whatsapp']??'TELEFONE'),'cliente_email'=>$c['email']??'E-MAIL',
 'processo_numero'=>$p['numero_processo']??'NÚMERO DO PROCESSO','processo_tipo'=>$p['tipo_processo']??'TIPO DO PROCESSO','processo_comarca'=>$p['comarca']??'COMARCA','processo_fase'=>$p['fase_atual']??'FASE ATUAL','processo_valor'=>isset($p['valor_causa'])?'R$ '.number_format((float)$p['valor_causa'],2,',','.'):'VALOR DA CAUSA',
 'escritorio_nome'=>'SGL Advocacia','data_atual'=>date('d/m/Y'),'mes_extenso'=>mg_mes(),'ano_atual'=>date('Y'),'valor_honorarios'=>'VALOR DOS HONORÁRIOS','valor_recebido'=>'VALOR RECEBIDO','valor_pendente'=>'VALOR PENDENTE','forma_pagamento'=>'FORMA DE PAGAMENTO'
]; }
function mg_apply(string $txt,array $vars): string { foreach($vars as $k=>$v) $txt=str_replace('{{'.$k.'}}',(string)$v,$txt); return $txt; }

$id=(int)($_GET['id']??0); $clienteId=(int)($_GET['cliente_id']??0); $processoId=(int)($_GET['processo_id']??0); $formato=$_GET['formato']??'html';
$stmt=$conn->prepare("SELECT * FROM modelos_documentos WHERE id=? AND COALESCE(deletado,0)=0 LIMIT 1"); $stmt->bind_param('i',$id); $stmt->execute(); $modelo=$stmt->get_result()->fetch_assoc(); $stmt->close();
if(!$modelo){ http_response_code(404); exit('Modelo não encontrado.'); }
$cliente=null; $processo=null;
if($processoId){ $stmt=$conn->prepare("SELECT p.*, c.nome AS cliente_nome FROM processos p LEFT JOIN clientes c ON c.id=p.cliente_id WHERE p.id=? LIMIT 1"); $stmt->bind_param('i',$processoId); $stmt->execute(); $processo=$stmt->get_result()->fetch_assoc(); $stmt->close(); if($processo && !$clienteId) $clienteId=(int)$processo['cliente_id']; }
if($clienteId){ $stmt=$conn->prepare("SELECT id,nome,cpf_cnpj,telefone,whatsapp,email,logradouro,numero,complemento,bairro,cidade,estado FROM clientes WHERE id=? LIMIT 1"); $stmt->bind_param('i',$clienteId); $stmt->execute(); $cliente=$stmt->get_result()->fetch_assoc(); $stmt->close(); }
$conteudo=mg_apply($modelo['conteudo'], mg_vars($cliente,$processo));
$filename = preg_replace('/[^a-zA-Z0-9_-]+/', '_', $modelo['titulo']) ?: 'documento';
if($formato==='doc'){
    header('Content-Type: application/msword; charset=utf-8');
    header('Content-Disposition: attachment; filename="'.$filename.'.doc"');
}
?><!doctype html><html lang="pt-br"><head><meta charset="utf-8"><title><?= htmlspecialchars($modelo['titulo']) ?></title><style>body{font-family:Georgia,serif;margin:45px;line-height:1.72;color:#111}.doc{max-width:850px;margin:0 auto}.head{text-align:center;margin-bottom:35px}.meta{font-family:Arial,sans-serif;color:#666;font-size:12px}.body{white-space:pre-wrap;font-size:16px}@media print{body{margin:25mm}.noprint{display:none}}</style></head><body><div class="noprint" style="text-align:right;margin-bottom:12px"><button onclick="window.print()">Imprimir / Salvar PDF</button></div><div class="doc"><div class="head"><h2><?= htmlspecialchars($modelo['titulo']) ?></h2><div class="meta"><?= htmlspecialchars($modelo['codigo']) ?> · <?= htmlspecialchars($modelo['categoria']) ?> · <?= date('d/m/Y') ?></div></div><div class="body"><?= htmlspecialchars($conteudo) ?></div></div></body></html>
