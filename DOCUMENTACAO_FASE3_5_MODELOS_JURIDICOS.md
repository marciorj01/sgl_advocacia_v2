# DOCUMENTAÇÃO — FASE 3.5 — MODELOS JURÍDICOS

## Objetivo
Criar uma biblioteca de modelos jurídicos para contratos, petições, procurações, termos, declarações, notificações e outros documentos do escritório.

## Implementado
- Novo menu: **Modelos**.
- Cadastro de modelos com categoria, área do direito, status, conteúdo e observações internas.
- Pesquisa inteligente por código, título, conteúdo e observações.
- Filtros por categoria, área e status.
- Edição, exclusão lógica e duplicação de modelos.
- Visualização e impressão/PDF do modelo.
- Preenchimento automático por cliente e processo.
- Variáveis dinâmicas no conteúdo.
- Logs de criação, edição, duplicação e exclusão.

## Variáveis disponíveis
- `{{cliente_nome}}`
- `{{cliente_cpf_cnpj}}`
- `{{cliente_endereco}}`
- `{{cliente_cidade}}`
- `{{cliente_uf}}`
- `{{processo_numero}}`
- `{{processo_tipo}}`
- `{{processo_comarca}}`
- `{{data_atual}}`
- `{{escritorio_nome}}`

## Arquivos alterados
- `index.php`

## Arquivos incluídos
- `modules/modelos.php`
- `sql/10_migracao_fase3_5_modelos.sql`
- `DOCUMENTACAO_FASE3_5_MODELOS_JURIDICOS.md`

## Observação
A tabela é criada automaticamente pelo módulo. O SQL foi incluído para documentação e futura instalação limpa.
