# ROTEIRO DE TESTES — CHAT 58

Executar somente no ambiente local de homologação, com backup prévio.

## Preparação

1. Confirmar as 18 tabelas `saas_financeiro_*`.
2. Entrar como usuário MASTER.
3. Manter uma cópia do banco antes dos testes.
4. Executar cada cenário dentro de dados de teste identificáveis.

## Cenários mínimos

### Assinaturas
- Criar rascunho com e sem itens.
- Validar escritório, licença e plano inexistentes.
- Iniciar e converter trial.
- Ativar, suspender, solicitar cancelamento e encerrar.
- Conferir snapshots e histórico de status.

### Cobranças
- Criar mensalidade e anuidade.
- Emitir e marcar vencida.
- Impedir desconto maior que o valor original.
- Cancelar sem exclusão física.

### Pagamentos e créditos
- Registrar, confirmar e compensar.
- Alocar integral e parcialmente.
- Alocar um pagamento em várias cobranças.
- Impedir alocação acima dos saldos.
- Criar e utilizar crédito.
- Estornar e conferir histórico.

### Negociação
- Vincular várias cobranças.
- Aprovar, rejeitar e cancelar.
- Confirmar preservação dos estados anteriores.

### Fluxo de caixa
- Criar receita, despesa e ajuste.
- Realizar e cancelar lançamento.
- Registrar transferência e confirmar que não entra como receita/despesa operacional.

### Segurança
- Tentar operar sem perfil MASTER.
- Tentar cruzar escritório/tenant.
- Testar IDs inexistentes, datas inválidas e valores negativos.
- Forçar erro no meio de uma operação composta e confirmar rollback.

### Indicadores
- Conferir MRR, ARR, receita recebida, prevista, inadimplência, churn, ticket médio e conversão de trial com cálculo manual de amostra.

## Critério de homologação

Todos os cenários devem retornar o padrão `sucesso`, `mensagem`, `dados` e `codigo`, sem SQL, stack trace, credenciais ou caminhos internos na resposta.
