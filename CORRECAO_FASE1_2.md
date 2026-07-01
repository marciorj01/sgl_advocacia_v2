# Correção Fase 1.2

## Ajuste realizado

Corrigido erro no Dashboard em `modules/dashboard.php`.

O Dashboard tentava consultar a coluna `acao` na tabela `processos`, porém o banco correto possui a coluna `tipo_processo`.

Foi ajustado o SELECT para usar:

```sql
tipo_processo AS acao
```

Assim mantemos compatibilidade com o layout existente sem alterar estrutura do banco.

## Arquivo alterado

- `modules/dashboard.php`

## Banco de dados

Não precisa importar novamente.
