# FASE 3.1 — Gerador de Recibos

## Objetivo
Adicionar ao SGL Advocacia um módulo inicial de recibos com emissão, listagem, impressão e opção de salvar em PDF pelo navegador.

## Arquivos alterados
- `index.php`
- `modules/recibos.php`

## Arquivos incluídos
- `sql/07_migracao_fase3_1_recibos.sql`
- `DOCUMENTACAO_FASE3_1_RECIBOS.md`

## Funcionalidades implementadas
- Novo menu **Recibos**.
- Cadastro manual ou vinculado a cliente existente.
- Numeração automática no padrão `REC-AAAA-0001`.
- Valor, data, forma de pagamento, processo e referente.
- Impressão e salvamento em PDF usando o navegador.
- Cancelamento de recibos.
- Lixeira lógica.
- CSRF em operações sensíveis.
- Criação automática da tabela caso o SQL ainda não tenha sido importado.

## Próximas melhorias planejadas
- Gerar recibo com um clique a partir de honorários/parcelas.
- Integrar recibos ao financeiro.
- QR Code de validação pública.
- Modelo visual avançado com assinatura digitalizada.
- Histórico completo no perfil do cliente.
