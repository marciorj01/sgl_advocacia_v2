# Fase 2.3 — Módulo Processos

## Arquivo alterado
- `modules/processos.php`

## Melhorias implementadas
- Layout profissional seguindo o padrão do Dashboard e Clientes.
- Cards de resumo: total, em andamento, prazos em 7 dias e valor total das causas.
- Pesquisa inteligente por número, cliente, tipo, comarca e fase.
- Filtros por status, tipo de processo e prazo.
- Prevenção de processo duplicado pelo número.
- Uso de prepared statements nas principais operações.
- CSRF em cadastro, edição e exclusão lógica.
- Exclusão lógica preservando histórico.
- Registro de log em `logs_sistema`, quando a tabela existir.
- Melhor exibição de fase atual e prazos vencidos.

## Observações
- Não foi necessário alterar o banco de dados.
- O módulo continua compatível com a estrutura atual do sistema.
- Esta etapa prepara a integração futura com Agenda, Honorários, Financeiro e Recibos.
