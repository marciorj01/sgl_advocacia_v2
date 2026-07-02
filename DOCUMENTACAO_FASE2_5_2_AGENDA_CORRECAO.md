# Correção Fase 2.5.2 — Agenda

## Correção aplicada

A versão anterior usava `ALTER TABLE ... ADD COLUMN IF NOT EXISTS`. Em algumas instalações do XAMPP/MySQL/MariaDB esse comando não é aceito corretamente, fazendo com que a coluna `deletado` não fosse criada.

## Ajuste técnico

O módulo `modules/agenda.php` agora verifica a existência das colunas com `SHOW COLUMNS` e cria apenas as colunas ausentes usando `ALTER TABLE ADD COLUMN` compatível.

Também foi corrigida a consulta dos processos, pois a tabela `processos` em algumas bases ainda não possui a coluna `deletado`.

## Arquivo alterado

- `modules/agenda.php`

## Banco de dados

Não é necessário importar SQL novamente. Ao abrir o menu Agenda, o próprio módulo tentará ajustar a estrutura da tabela `agenda`.
