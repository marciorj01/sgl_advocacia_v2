# Fase 3.2.1 — Ajuste Financeiro, Recibos e Dashboard

## Problema identificado
Ao cadastrar uma Conta a Receber diretamente no Financeiro e alterar o status para Recebido, o sistema:

- marcava visualmente como recebido;
- não preenchia `valor_pago` e `valor_pendente` corretamente;
- não gerava recibo automático;
- não mostrava opção de gerar/ver recibo nas ações;
- não refletia corretamente no Dashboard.

Também foi feita revisão preventiva em Contas a Pagar.

## Correções aplicadas

### Contas a Receber
- Ao salvar como `Recebido`, o sistema agora:
  - define `valor_pago = valor`;
  - define `valor_pendente = 0`;
  - preenche `data_recebimento` automaticamente quando vazia;
  - gera recibo automaticamente;
  - exibe botão para visualizar recibo já gerado;
  - exibe botão para gerar recibo se a conta estiver recebida mas sem recibo.

### Contas a Pagar
- Criado botão rápido para marcar uma despesa como paga.
- Ao salvar uma conta como `Pago`, parcelas e conta principal ficam quitadas.
- `valor_pago`, `valor_pendente`, `status` e `data_pagamento` são atualizados de forma consistente.

### Dashboard
- Dashboard passa a garantir a estrutura financeira necessária antes de consultar indicadores.
- Recebimentos manuais do Financeiro passam a aparecer no Dashboard.
- Contas oriundas de Honorários são evitadas na soma manual para reduzir risco de duplicidade quando Honorários também são somados separadamente.

## Arquivos alterados
- `modules/financeiro.php`
- `modules/dashboard.php`
- `config/integracoes.php`

## Banco de dados
Não é necessário importar SQL. As colunas necessárias são verificadas automaticamente pelo sistema.

## Testes recomendados
1. Criar Conta a Receber de R$ 1.000,00 com status Recebido.
2. Confirmar se aparece o botão de recibo na listagem.
3. Abrir menu Recibos e conferir se o recibo foi criado.
4. Abrir Dashboard e verificar se o valor entrou no recebido do mês.
5. Criar Conta a Pagar e usar o botão rápido de pagamento.
