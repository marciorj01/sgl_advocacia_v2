# Fase 2.2 — Módulo Clientes

## Arquivo alterado
- `modules/clientes.php`

## Arquivo SQL incluído
- `sql/02_migracao_fase2_2_clientes.sql`

## Melhorias implementadas
- Consultas preparadas para salvar, editar, excluir e pesquisar clientes.
- Token CSRF no formulário e na exclusão.
- Validação de nome obrigatório, e-mail, CPF/CNPJ e status.
- Pesquisa por nome, ID, CPF/CNPJ, telefone, WhatsApp e e-mail.
- Filtros por status e cidade.
- Paginação com 15 clientes por página.
- Cards de resumo: total de clientes, ativos e novos no mês.
- Máscaras de CPF/CNPJ, telefone, celular, WhatsApp e CEP.
- Busca automática de endereço pelo ViaCEP.
- Soft delete mantendo histórico no banco.
- Layout mais profissional e alinhado ao Dashboard.

## Observação importante
A migração `02_migracao_fase2_2_clientes.sql` permite CPF/CNPJ nulo. Isso evita erro ao cadastrar mais de um cliente sem documento informado.
