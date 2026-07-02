# DOCUMENTAÇÃO — FASE 2.6 — HONORÁRIOS

## Objetivo
Melhorar o módulo de Honorários, mantendo a estrutura existente e preparando a integração futura com Financeiro e Gerador de Recibos.

## Arquivos alterados
- `modules/honorarios.php`

## Arquivos incluídos
- `sql/05_migracao_fase2_6_honorarios.sql`
- `DOCUMENTACAO_FASE2_6_HONORARIOS.md`

## Melhorias aplicadas
- Cards de indicadores financeiros do módulo.
- Filtros por pesquisa, status, tipo e vencimento.
- Correção do caminho AJAX para salvar parcelas no ambiente `sgl_advocacia`.
- Ajuste de mensagem de atualização para português.
- Preparação para vencidos, próximos 7 dias e saldo em aberto.
- Mantida a estrutura de parcelas e o CRUD já existente.

## Observação técnica
A migração SQL é preventiva. Ela garante colunas e tabela de parcelas em bancos que ainda não estejam 100% alinhados.
