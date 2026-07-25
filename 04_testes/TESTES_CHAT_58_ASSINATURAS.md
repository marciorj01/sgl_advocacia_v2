# ROTEIRO DE TESTES — ASSINATURAS

## Pré-requisitos

- Banco local com as 18 tabelas do Financeiro Corporativo importadas.
- Sessão autenticada como `Administrador Master`.
- Escritório, licença e plano válidos cadastrados.
- Executar primeiro em banco de homologação ou cópia local.

## Casos obrigatórios

1. Criar assinatura mensal em rascunho sem itens.
2. Criar assinatura anual com itens de módulo.
3. Confirmar snapshot do código e nome do plano.
4. Impedir criação para escritório inexistente.
5. Impedir licença de outro escritório.
6. Impedir plano inexistente ou inativo.
7. Impedir desconto maior que o valor contratado.
8. Adicionar item em assinatura rascunho.
9. Remover item logicamente e confirmar `ativo = 0`.
10. Confirmar recálculo de `valor_itens` e `valor_final`.
11. Impedir alteração de itens em assinatura cancelada ou encerrada.
12. Atualizar dados enquanto estiver em rascunho.
13. Ativar assinatura.
14. Iniciar trial com datas válidas.
15. Impedir trial com fim anterior ao início.
16. Converter trial para ativa.
17. Suspender assinatura ativa.
18. Solicitar cancelamento com motivo.
19. Cancelar assinatura e preservar histórico.
20. Encerrar assinatura sem excluir o escritório ou a licença.
21. Consultar assinatura por código público.
22. Listar assinaturas por escritório.
23. Confirmar registros em `logs_sistema`.
24. Confirmar registros em `saas_financeiro_status_historico`.
25. Simular falha durante criação com itens e confirmar rollback integral.
26. Testar usuário não MASTER e confirmar `ACESSO_NEGADO`.
27. Confirmar que nenhum erro interno do banco aparece no retorno público.

## Resultado esperado

Todas as operações devem retornar o padrão:

```php
[
    'sucesso' => true|false,
    'mensagem' => '...',
    'dados' => [],
    'codigo' => '...'
]
```

Nenhum teste deve alterar o financeiro operacional dos escritórios.
