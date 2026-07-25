# ROJEX.AI — CHAT 57

## RC2.3 — Banco e regras do Financeiro Corporativo MASTER SaaS

**Data:** 24/07/2026  
**SQL entregue:** `rc2_financeiro_corporativo.sql`  
**Status:** pronto para teste local; não executado no banco oficial.

## 1. Auditoria da baseline RC1

Fonte auditada: `sistema_sgl_novo(18).sql`.

| Tabela legada | Chave primária real | Engine / collation | Uso no RC2 |
|---|---|---|---|
| `escritorios_saas` | `BIGINT(20)` assinado | InnoDB / utf8mb4_unicode_ci | FK `escritorio_id` |
| `licencas_saas` | `BIGINT(20)` assinado | InnoDB / utf8mb4_unicode_ci | FK `licenca_id` |
| `planos_saas` | `BIGINT(20) UNSIGNED` | InnoDB / utf8mb4_unicode_ci | FK `plano_id` |
| `modulos_saas` | `BIGINT(20) UNSIGNED` | InnoDB / utf8mb4_unicode_ci | FK `modulo_id` |
| `usuarios` | `INT(11)` assinado | InnoDB / utf8mb4_unicode_ci | FKs de auditoria |
| `logs_sistema` | `BIGINT(20)` assinado | InnoDB / utf8mb4_unicode_ci | permanece como LOG Enterprise oficial |

Decisão: os tipos das novas colunas relacionais reproduzem exatamente os tipos legados. Nenhuma tabela RC1 foi alterada para acomodar o novo módulo.

## 2. Estrutura criada

Foram definidas as 18 tabelas previstas:

1. `saas_financeiro_assinaturas`
2. `saas_financeiro_assinatura_itens`
3. `saas_financeiro_cobrancas`
4. `saas_financeiro_cobranca_itens`
5. `saas_financeiro_pagamentos`
6. `saas_financeiro_pagamento_alocacoes`
7. `saas_financeiro_creditos`
8. `saas_financeiro_credito_utilizacoes`
9. `saas_financeiro_descontos`
10. `saas_financeiro_negociacoes`
11. `saas_financeiro_negociacao_cobrancas`
12. `saas_financeiro_categorias`
13. `saas_financeiro_centros_custo`
14. `saas_financeiro_contas`
15. `saas_financeiro_lancamentos`
16. `saas_financeiro_transferencias`
17. `saas_financeiro_configuracoes`
18. `saas_financeiro_status_historico`

Padrões aplicados:

- `CREATE TABLE IF NOT EXISTS`;
- InnoDB, `utf8mb4`, `utf8mb4_unicode_ci`;
- valores monetários em `DECIMAL(15,2)`;
- quantidades fracionadas em `DECIMAL(15,4)`;
- status/tipos em `VARCHAR`, sem `ENUM`;
- códigos públicos únicos;
- índices para tenant, escritório, licença, plano, assinatura, cobrança, pagamento, status, competência e datas;
- `ON DELETE RESTRICT` para histórico financeiro;
- `ON DELETE CASCADE` apenas em composições internas sem movimento autônomo;
- usuários de auditoria com `ON DELETE SET NULL`;
- nenhuma informação sensível de cartão ou credencial de gateway.

## 3. Idempotência e segurança

O arquivo usa:

- 18 comandos `CREATE TABLE IF NOT EXISTS`;
- `INSERT IGNORE` para categorias, centros de custo e configurações;
- chaves únicas nos códigos estruturais;
- transação para a instalação inicial;
- consultas finais somente de leitura.

Varredura do arquivo:

- `DROP TABLE`: 0;
- `TRUNCATE TABLE`: 0;
- `DELETE FROM`: 0;
- `ALTER TABLE`: 0;
- tabelas RC1 modificadas: 0;
- referências a novas tabelas antes da criação: 0.

## 4. Impacto zero no RC1

O SQL não altera nem apaga:

- `escritorios_saas`;
- `licencas_saas`;
- `planos_saas`;
- `modulos_saas`;
- `logs_sistema`;
- tabelas do financeiro operacional dos escritórios;
- JSON legado de `licencas_saas.observacoes`.

As novas tabelas apenas referenciam chaves homologadas por FKs compatíveis. A futura suspensão, reativação ou mudança de licença continuará proibida por SQL direto e deverá ocorrer por serviço PHP controlado.

## 5. Arquitetura inicial dos serviços PHP

### `AssinaturaService`

Responsabilidade: criar, ativar, renovar, solicitar cancelamento e encerrar contratos recorrentes.

Métodos principais: `criar()`, `adicionarItem()`, `recalcularTotais()`, `ativar()`, `solicitarCancelamento()`, `cancelar()`, `renovar()` e `obterPorEscritorio()`.

Transação obrigatória: assinatura + itens + histórico de status + LOG Enterprise.

### `CobrancaService`

Responsabilidade: gerar mensalidades, anuidades e cobranças avulsas sem duplicidade.

Métodos: `gerarRecorrente()`, `gerarAvulsa()`, `emitir()`, `recalcularSaldo()`, `marcarVencidas()`, `cancelar()` e `obterEmAberto()`.

Validação crítica: chave lógica assinatura, tipo, competência e período.

### `PagamentoService`

Responsabilidade: registrar recebimentos e alocá-los em uma ou várias cobranças.

Métodos: `registrar()`, `confirmar()`, `compensar()`, `alocar()`, `cancelarAlocacao()`, `estornarParcial()` e `estornarTotal()`.

Transação obrigatória: pagamento + alocações + atualização de cobranças + lançamento de caixa + histórico + LOG.

### `CreditoService`

Responsabilidade: criar e consumir créditos favoráveis ao escritório.

Métodos: `criar()`, `utilizar()`, `cancelarUtilizacao()`, `cancelarCredito()` e `consultarSaldo()`.

Regra crítica: nunca reduzir saldo sem inserir `credito_utilizacoes` na mesma transação.

### `DescontoService`

Responsabilidade: validar descontos comerciais e seus limites.

Métodos: `criar()`, `aprovar()`, `validarAplicabilidade()`, `calcular()`, `registrarUso()` e `inativar()`.

### `NegociacaoService`

Responsabilidade: formalizar acordos e parcelamentos preservando saldos e status anteriores.

Métodos: `criar()`, `adicionarCobranca()`, `simularParcelas()`, `aprovar()`, `gerarCobrancasNegociadas()` e `cancelar()`.

### `InadimplenciaService`

Responsabilidade: classificar atraso e preparar alertas ou elegibilidade de suspensão.

Métodos: `analisar()`, `listarFaixas()`, `registrarAlertas()`, `marcarInadimplente()` e `indicarSuspensao()`.

Nesta fase, `indicarSuspensao()` apenas registra recomendação; não altera licença automaticamente.

### `RenovacaoService`

Responsabilidade: preparar renovação contratual e cobrança seguinte.

Métodos: `listarElegiveis()`, `simular()`, `renovarAssinatura()` e `gerarCobrancaRenovacao()`.

### `CancelamentoService`

Responsabilidade: coordenar cancelamento comercial, financeiro e futuro reflexo controlado na licença.

Métodos: `solicitar()`, `calcularDataEfetiva()`, `confirmar()`, `cancelarCobrancasFuturas()` e `encerrar()`.

### `LicencaFinanceiraService`

Responsabilidade: única ponte permitida entre Financeiro Corporativo e `licencas_saas`.

Métodos: `avaliarSuspensao()`, `suspenderControladamente()`, `reativarControladamente()` e `sincronizarRenovacao()`.

Toda alteração deverá exigir MASTER, transação, motivo, histórico e LOG Enterprise.

### `FluxoCaixaCorporativoService`

Responsabilidade: receitas, despesas, ajustes, transferências e saldos corporativos.

Métodos: `criarLancamento()`, `realizar()`, `cancelar()`, `estornar()`, `transferir()` e `calcularSaldo()`.

### `IndicadoresSaasService`

Responsabilidade: calcular MRR, ARR, ticket médio, inadimplência, churn, LTV e receita reconhecida.

Métodos: `calcularMRR()`, `calcularARR()`, `calcularTicketMedio()`, `calcularChurn()`, `calcularLTV()`, `calcularInadimplencia()` e `resumoExecutivo()`.

### `FinanceiroCorporativoLogService`

Responsabilidade: padronizar gravação no `logs_sistema` e no histórico funcional.

Métodos: `registrarEvento()`, `registrarMudancaStatus()`, `registrarFalha()` e `contextoMaster()`.

## 6. Regras transversais do backend

- somente MASTER acessará operações corporativas;
- toda consulta por escritório deverá validar conjuntamente `escritorio_id` e `tenant_id`;
- valores recebidos do cliente nunca serão usados como totais finais sem recálculo no servidor;
- toda movimentação composta usará transação e rollback em falha;
- pagamentos e movimentações confirmadas não serão excluídos fisicamente;
- correções ocorrerão por cancelamento, estorno ou compensação;
- snapshots de plano, descrição e preço serão preservados;
- o LOG Enterprise será registrado após a persistência, dentro da estratégia transacional definida;
- erros esperados: entidade inexistente, tenant incompatível, status inválido, duplicidade, saldo insuficiente, valor não positivo e conflito de concorrência.

## 7. Teste no phpMyAdmin local

1. Pare qualquer rotina automática que escreva no banco local.
2. Abra o phpMyAdmin do XAMPP.
3. Selecione um banco de homologação ou crie uma cópia do banco oficial local.
4. Exporte um backup SQL completo antes do teste.
5. Acesse **Importar**.
6. Escolha `rc2_financeiro_corporativo.sql`.
7. Mantenha o conjunto de caracteres como UTF-8.
8. Execute a importação.
9. Confirme que a consulta final retorna `18` tabelas.
10. Confirme `18` categorias, `9` centros de custo e `10` configurações.
11. Execute o mesmo arquivo uma segunda vez.
12. Confirme ausência de erro e que as contagens estruturais não foram duplicadas.
13. Verifique que as telas e tabelas do financeiro operacional dos escritórios continuam inalteradas.
14. Não execute no banco oficial até a homologação deste teste.

## 8. Consultas adicionais de conferência

```sql
SHOW TABLES LIKE 'saas_financeiro_%';

SELECT COUNT(*) FROM saas_financeiro_categorias;
SELECT COUNT(*) FROM saas_financeiro_centros_custo;
SELECT COUNT(*) FROM saas_financeiro_configuracoes;

SELECT table_name, engine, table_collation
FROM information_schema.tables
WHERE table_schema = DATABASE()
  AND table_name LIKE 'saas_financeiro_%'
ORDER BY table_name;
```

## 9. Próxima decisão

O arquivo está pronto somente para importação em banco local de homologação. A execução no banco oficial depende de autorização expressa após o teste no phpMyAdmin.
