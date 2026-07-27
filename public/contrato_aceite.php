<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../services/ContratoSaasService.php';

$conn = conectar();
$service = new ContratoSaasService($conn);
$token = strtolower(trim((string)($_GET['token'] ?? $_POST['token'] ?? '')));
$erro=''; $sucesso=false;
try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $service->aceitarDireto($token,$_POST); $sucesso=true;
    }
    $contrato = $sucesso ? null : $service->buscarPorToken($token);
    if (!$sucesso && !$contrato) $erro='Link inválido, expirado ou já utilizado.';
} catch(Throwable $e){$erro=$e->getMessage();$contrato=null;}
?><!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Contrato Digital ROJEX.AI</title><style>body{font-family:Arial,sans-serif;background:#f3f4f6;margin:0}.box{max-width:900px;margin:32px auto;background:#fff;padding:28px;border-radius:10px;box-shadow:0 3px 18px #0002}.contrato{border:1px solid #ddd;padding:20px;max-height:520px;overflow:auto}.ok{padding:18px;background:#ecfdf5}.erro{padding:18px;background:#fef2f2}.campo{margin:12px 0}.campo input[type=text]{width:100%;padding:10px;box-sizing:border-box}button{padding:12px 18px;background:#198754;color:white;border:0;border-radius:6px;cursor:pointer}</style></head><body><main class="box">
<?php if($sucesso): ?><div class="ok"><h1>Aceite registrado</h1><p>O contrato foi aceito eletronicamente. O provisionamento será liberado ao MASTER.</p></div>
<?php elseif($erro!==''): ?><div class="erro"><h1>Não foi possível acessar</h1><p><?=htmlspecialchars($erro,ENT_QUOTES,'UTF-8')?></p></div>
<?php else: ?><h1><?=htmlspecialchars((string)$contrato['titulo'],ENT_QUOTES,'UTF-8')?></h1><div class="contrato"><?=$contrato['conteudo_html']?></div><form method="post"><input type="hidden" name="token" value="<?=htmlspecialchars($token,ENT_QUOTES,'UTF-8')?>"><div class="campo"><label>Nome do representante</label><input type="text" name="representante_nome" value="<?=htmlspecialchars((string)$contrato['representante_nome'],ENT_QUOTES,'UTF-8')?>" required></div><div class="campo"><label>CPF/CNPJ</label><input type="text" name="representante_documento" value="<?=htmlspecialchars((string)$contrato['representante_documento'],ENT_QUOTES,'UTF-8')?>" required></div><div class="campo"><label>Qualidade do representante</label><input type="text" name="representante_qualidade" value="<?=htmlspecialchars((string)$contrato['representante_qualidade'],ENT_QUOTES,'UTF-8')?>" required></div>
<?php foreach(['confirmou_leitura'=>'Confirmo que li o contrato.','confirmou_concordancia'=>'Concordo com as condições.','autorizou_implantacao'=>'Autorizo a implantação.','aceitou_privacidade_lgpd'=>'Aceito a política de privacidade e LGPD.'] as $n=>$r): ?><div class="campo"><label><input type="checkbox" name="<?=$n?>" value="1" required> <?=$r?></label></div><?php endforeach; ?><button type="submit">Aceitar eletronicamente</button></form><?php endif; ?></main></body></html>
