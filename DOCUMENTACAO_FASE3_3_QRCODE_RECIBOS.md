# DOCUMENTAÇÃO — FASE 3.3 — QR Code nos Recibos

## Objetivo
Adicionar validação de autenticidade aos recibos emitidos pelo SGL Advocacia.

## Implementado
- QR Code no recibo em formato A5.
- Link público de validação por chave única.
- Página `validar_recibo.php` para consulta externa do recibo.
- Exibição de número, cliente, valor, referente, datas, processo e status.

## Arquivos alterados
- `modules/recibos.php`

## Arquivos incluídos
- `validar_recibo.php`
- `DOCUMENTACAO_FASE3_3_QRCODE_RECIBOS.md`

## Observação técnica
O QR Code usa imagem gerada por serviço externo a partir da URL de validação. Em produção, após subir para a Hostinger, o QR Code apontará para o domínio real do sistema.

## Próxima fase recomendada
Fase 3.4 — Documentos e Uploads por cliente/processo.
