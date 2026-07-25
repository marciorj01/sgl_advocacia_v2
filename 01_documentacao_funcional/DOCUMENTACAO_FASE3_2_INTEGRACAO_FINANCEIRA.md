# Fase 3.2 — Integração Financeira

## Objetivo

Iniciar a integração entre módulos do SGL Advocacia, conectando:

- Honorários
- Financeiro / Contas a Receber
- Recibos

## Arquivos alterados

- `config/integracoes.php` — novo arquivo central de funções de integração.
- `modules/honorarios.php` — sincroniza parcelas de honorários com contas a receber.
- `modules/ajax_salvar_parcela.php` — ao pagar parcela de honorário, atualiza financeiro e pode gerar recibo.
- `modules/financeiro.php` — botão para confirmar recebimento e gerar recibo automaticamente.

## O que foi implementado

### 1. Honorários geram contas a receber

Ao cadastrar ou atualizar honorários com parcelas, o sistema cria/atualiza contas a receber correspondentes às parcelas.

### 2. Pagamento de honorário sincroniza financeiro

Quando uma parcela é quitada no módulo Honorários, a conta a receber correspondente passa para `Recebido`.

### 3. Financeiro gera recibo

No menu Financeiro > Contas a Receber, foi incluído o botão verde de recebimento.
Ao confirmar, o sistema:

1. marca a conta como recebida;
2. registra valor pago;
3. define a data de recebimento;
4. gera recibo automático.

### 4. Recibos vinculados ao financeiro

Os recibos automáticos passam a registrar `conta_receber_id`, permitindo rastrear sua origem.

## Observação importante

O SQL `08_migracao_fase3_2_integracao_financeira.sql` é opcional. Os módulos possuem camada de compatibilidade para criar as colunas necessárias automaticamente.

Caso o phpMyAdmin informe que uma coluna já existe, ignore esse aviso.
