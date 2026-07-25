# DOCUMENTAÇÃO — FASE 3.2.8 — Ajuste Visual do Recibo A5

## Objetivo
Ajustar a impressão do recibo para o formato A5 real, com layout compacto e alinhado ao modelo solicitado.

## Arquivo alterado
- modules/recibos.php

## Melhorias aplicadas
- Página configurada em `@page { size: A5 portrait; margin: 8mm; }`.
- Medida real de impressão: 14,8 cm x 21 cm.
- Layout mais compacto, sem ocupar desnecessariamente toda a folha A4.
- Cabeçalho com logo, título e número do recibo centralizados.
- Box principal com texto do recibo.
- Dados organizados em duas colunas: forma de pagamento, processo, data e status.
- Campo de observações e chave de validação.
- Área de assinatura ajustada.

## Observação importante
Para impressão física em A5, no navegador/impressora, selecione o tamanho de papel **A5** em “Mais definições”. Se a impressora estiver configurada como A4, o navegador pode posicionar o recibo em uma folha A4.
