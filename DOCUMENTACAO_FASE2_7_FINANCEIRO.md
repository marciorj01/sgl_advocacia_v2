# DOCUMENTAÇÃO — FASE 2.7 — FINANCEIRO

## Objetivo
Remodelar e reforçar o módulo Financeiro, mantendo compatibilidade com a estrutura existente do SGL Advocacia.

## Arquivo alterado
- `modules/financeiro.php`

## Melhorias implementadas
- Cabeçalho do módulo padronizado com os demais módulos.
- Botões rápidos para nova despesa e novo recebimento.
- Cards executivos com indicadores financeiros:
  - A receber em aberto;
  - A pagar em aberto;
  - Recebido no mês;
  - Alertas vencidos.
- Verificação automática de compatibilidade do banco para evitar erros de colunas ausentes em instalações antigas.
- Criação automática das tabelas de parcelas financeiras caso ainda não existam.
- Correção do caminho da logo no relatório de impressão/PDF.

## Observação técnica
Nesta versão não é necessário importar SQL. O próprio módulo verifica e ajusta colunas essenciais de forma segura ao abrir a tela Financeiro.

## Próximos passos previstos
- Integração mais profunda entre Honorários e Financeiro.
- Relatórios financeiros avançados.
- Gráficos de receitas x despesas.
- Base para integração futura com Gerador de Recibos.
