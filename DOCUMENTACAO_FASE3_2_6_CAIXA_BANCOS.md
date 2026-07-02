# Fase 3.2.6 — Caixa, Bancos, Logs e Recibo A5

## Objetivo
Ajustar a reta final do módulo financeiro para trabalhar com fechamento de caixa real, separando visão gerencial do Dashboard e relatórios de fechamento.

## Alterações
- Dashboard: botões de fechamento agora abrem relatórios específicos no Financeiro.
- Financeiro: novo relatório de Fechamento de Caixa do Dia.
- Financeiro: novo relatório de Fechamento de Caixa Mensal.
- Financeiro: cadastro de Bancos/Caixa/PIX/Conta Corrente/Poupança/Cartão.
- Contas a pagar e a receber: campo Banco/Caixa para identificar onde entrou ou saiu o dinheiro.
- Logs: reforço para exibir nome, login, perfil e IP do usuário logado.
- Recibos: impressão ajustada para A5.

## Observação sobre impressão A5
No navegador, ao imprimir, mantenha o tamanho do papel como A5 quando a impressora oferecer essa opção. O CSS já força `@page` em A5, mas algumas impressoras/Chrome podem manter A4 nas preferências do driver.

## Migração
O sistema cria a estrutura automaticamente ao abrir o Financeiro. O arquivo `sql/10_migracao_fase3_2_6_caixa_bancos.sql` é opcional e serve para conferência/manual.
