# Fase 3.8.1 — Correção Central Inteligente

## Ajuste realizado

Correção da consulta de Contas a Receber na Central Inteligente.

O módulo anterior tentava acessar a coluna `cr.codigo`, mas a tabela `contas_receber` usa o próprio campo `id` como código operacional, por exemplo `CR001`, `CR002`.

## Melhorias aplicadas

- Substituição de `cr.codigo` por `cr.id AS codigo`.
- Compatibilidade com bancos onde `contas_receber.cliente_nome` não existe.
- Compatibilidade com bancos onde `valor_pendente` pode não existir.
- Central Inteligente passa a abrir sem erro em estruturas anteriores do banco.

## Arquivo alterado

- `modules/central_inteligente.php`

## Banco de dados

Não é necessário importar SQL.
