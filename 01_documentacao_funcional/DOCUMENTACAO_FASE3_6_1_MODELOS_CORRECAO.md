# Fase 3.6.1 — Correção do módulo Modelos

Correção aplicada no módulo Modelos Jurídicos.

## Ajuste

O módulo tentava consultar a coluna `endereco` na tabela `clientes`, porém a estrutura real do sistema usa campos separados:

- `logradouro`
- `numero`
- `complemento`
- `bairro`
- `cidade`
- `estado`

A consulta foi ajustada para montar o endereço completo a partir desses campos.

## Arquivo alterado

- `modules/modelos.php`

## SQL

Não precisa importar SQL.
