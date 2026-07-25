# Correção Fase 2.5.1 — Agenda

## Problema corrigido
O módulo Agenda apresentou erro porque a tabela `agenda` ainda não possuía a coluna `deletado` e outras colunas usadas pela nova tela.

## Ajustes
- `modules/agenda.php` recebeu verificação automática de estrutura para evitar quebra caso a migração ainda não tenha sido executada.
- `sql/04_migracao_fase2_5_agenda.sql` foi refeito como SQL puro e seguro para phpMyAdmin.

## Observação
No phpMyAdmin, importe apenas arquivos `.sql`. Arquivos `.md` são documentação e não devem ser importados no banco.
