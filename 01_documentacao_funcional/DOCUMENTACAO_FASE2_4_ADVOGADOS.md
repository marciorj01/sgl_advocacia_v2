# Fase 2.4 — Módulo Advogados

## Arquivo alterado
- `modules/advogados.php`

## SQL incluído
- `sql/03_migracao_fase2_4_advogados.sql`

## Melhorias aplicadas
- Layout padronizado com os módulos Clientes e Processos.
- Cards de indicadores: total, ativos, inativos e novos no mês.
- Pesquisa inteligente por ID, nome, CPF, OAB, telefone, e-mail e especialidade.
- Filtro por status e especialidade.
- Cadastro com campos de CPF, OAB, UF da OAB, especialidade, telefone, e-mail e observações.
- Máscaras de CPF, telefone e UF da OAB no navegador.
- Validação de e-mail, CPF, status e UF.
- Proteção CSRF para salvar, atualizar e excluir.
- Consultas SQL preparadas para reduzir risco de SQL Injection.
- Exclusão lógica com campo `deletado`.
- Preparado para integração futura com processos, agenda, honorários e auditoria.

## Como testar
1. Substitua os arquivos do projeto.
2. Importe `sql/03_migracao_fase2_4_advogados.sql` no phpMyAdmin.
3. Abra o menu **Advogados**.
4. Cadastre um advogado de teste.
5. Edite, pesquise, filtre e exclua logicamente o registro.
