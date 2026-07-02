# DOCUMENTAÇÃO — FASE 2.5 — AGENDA

## Objetivo
Remodelar o módulo Agenda para controlar audiências, prazos, reuniões, atendimentos, perícias, sustentações orais e lembretes com padrão visual e técnico compatível com os módulos já melhorados.

## Arquivos alterados
- `modules/agenda.php`

## Arquivos incluídos
- `sql/04_migracao_fase2_5_agenda.sql`
- `DOCUMENTACAO_FASE2_5_AGENDA.md`

## Melhorias implementadas
- Cards de indicadores: total, compromissos de hoje, próximos 7 dias e prazos fatais.
- Pesquisa inteligente por cliente, processo, local, advogado ou tipo.
- Filtros por status, tipo e período.
- Novos tipos: Perícia e Sustentação Oral.
- Status Confirmado incluído no banco.
- Integração com Clientes, Processos e Advogados.
- Processo vinculado carregado automaticamente conforme o cliente selecionado.
- Proteção CSRF nos formulários.
- Validação de dados no servidor.
- Tratamento de erros sem exibir detalhes internos do banco ao usuário.
- Lixeira com restauração e exclusão permanente.

## Observação técnica
A migração `04_migracao_fase2_5_agenda.sql` é necessária porque o banco anterior não aceitava o status `Confirmado` no ENUM da tabela `agenda`.
