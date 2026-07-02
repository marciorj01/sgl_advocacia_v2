# DOCUMENTAÇÃO — FASE 3.4 — DOCUMENTOS E ARQUIVOS

## Objetivo
Criar o módulo Documentos para armazenar arquivos vinculados a clientes e processos.

## Recursos implementados
- Menu Documentos no sistema.
- Upload seguro com validação de extensão e limite de 15 MB.
- Vinculação opcional com cliente e processo.
- Categorias: Documento pessoal, Procuração, Contrato, Petição, Prova, Audiência, Financeiro, Recibo, Documento do processo e Outros.
- Listagem com filtros por pesquisa, categoria, cliente e processo.
- Download/visualização controlada pelo PHP.
- Lixeira lógica.
- Logs de upload, download e exclusão.
- Armazenamento em `uploads/documentos/AAAA/MM`.

## Segurança
- CSRF em formulários.
- Bloqueio de extensões executáveis.
- Nome físico aleatório para evitar sobrescrita e exposição.
- Hash SHA-256 do arquivo salvo.
- Controle de download sem expor caminho real diretamente.

## Banco
Tabela criada automaticamente pelo módulo e também disponível em:
`sql/09_migracao_fase3_4_documentos.sql`

## Próxima fase sugerida
Fase 3.5 — Modelos Jurídicos: contratos, petições, procurações e documentos padrão.
