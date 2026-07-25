# DOCUMENTAÇÃO — FASE 3.2.4 — Dashboard e Logs

## Objetivo
Ajustar pontos observados após testes de integração financeira, Dashboard e Configurações.

## Alterações

### Dashboard
- Adicionado botão **Ver contas** no card/tabela de Contas a Pagar Próximas.
- Adicionado botão **Relatório PDF** no card Resumo de Processos.
- O botão usa impressão do navegador, permitindo salvar a visão rápida em PDF.
- Incluído CSS específico para impressão, ocultando menu/botões e preservando o relatório.

### Configurações > Logs
- Logs passaram a exibir melhor o responsável pela atualização.
- Exibição ampliada com: data, quem fez, perfil, ação, módulo, IP e detalhes.
- Criado painel de **Inventário de Atualizações**, agrupando ações por responsável e módulo.
- Novos logs gerados a partir de Configurações incluem responsável, perfil e navegador nos detalhes.

## Observação
Logs antigos que foram gravados sem usuário vinculado continuarão aparecendo como "Sistema". Os novos registros passam a ter identificação mais completa do usuário logado.
