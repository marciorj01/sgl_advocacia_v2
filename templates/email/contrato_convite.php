<?php declare(strict_types=1); ?>
<!doctype html><html lang="pt-BR"><body style="font-family:Arial,sans-serif;color:#1f2937;line-height:1.5">
<h2>Contrato digital ROJEX.AI</h2>
<p>Olá, <?=htmlspecialchars((string)$nome,ENT_QUOTES,'UTF-8')?>.</p>
<p>Seu contrato está disponível para leitura e aceite eletrônico.</p>
<p><a href="<?=htmlspecialchars((string)$link,ENT_QUOTES,'UTF-8')?>" style="display:inline-block;padding:12px 18px;background:#0d6efd;color:#fff;text-decoration:none;border-radius:6px">Visualizar e aceitar contrato</a></p>
<p>O link expira em <?=htmlspecialchars((string)$expira_em,ENT_QUOTES,'UTF-8')?> e será inutilizado após o aceite.</p>
<p>Equipe ROJEX.AI</p></body></html>
