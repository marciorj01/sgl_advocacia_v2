# DOCUMENTAÇÃO — FASE 3.2.2 — Ajuste profundo Financeiro, Recibos e Dashboard

## Problemas corrigidos

1. Financeiro chamava a função `buscarReciboPorContaReceber()` sem ela existir no carregamento atual.
2. Contas a receber marcadas como recebidas podiam gerar recibo, mas a listagem quebrava antes de exibir o botão do recibo.
3. Contas a pagar usavam ação rápida `marcarContaPagarPaga()` sem garantia da função existir.
4. Dashboard estava subcontando valores integrados de honorários/financeiro e podia não mostrar honorários vencidos conforme variações de status.

## Alterações

- `config/integracoes.php`: adicionadas funções de compatibilidade:
  - `buscarReciboPorContaReceber()`
  - `marcarContaPagarPaga()`
- `modules/financeiro.php`: ajuste no cálculo de recebido no mês para usar `valor_pago`.
- `modules/dashboard.php`: indicadores financeiros consolidados para considerar contas a receber, honorários integrados e honorários ainda não sincronizados.

## Resultado esperado

- Conta a receber recebida deve exibir opção de recibo sem erro fatal.
- O recibo deve aparecer no módulo Recibos.
- Dashboard deve refletir os recebimentos no mês e pendências com maior consistência.
- Contas a pagar podem ser marcadas como pagas sem erro de função ausente.
