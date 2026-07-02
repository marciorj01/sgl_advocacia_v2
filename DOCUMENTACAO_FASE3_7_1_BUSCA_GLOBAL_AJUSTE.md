# FASE 3.7.1 — Ajuste da Busca Global

## Objetivo
Ajustar a Busca Global Inteligente para melhorar a usabilidade e corrigir a pesquisa por CPF/CNPJ/telefone digitados somente com números.

## Alterações
- Removido o segundo campo de busca dentro da tela da Busca Global, mantendo apenas o campo superior do sistema.
- Busca por CPF/CNPJ, telefone, WhatsApp, OAB e número de processo agora ignora pontos, traços, barras, parênteses, espaços e outros separadores.
- Exemplo: pesquisar `14941814878` encontra `149.418.148-78`.
- Exemplo: pesquisar `12345678000199` encontra `12.345.678/0001-99`.

## Arquivos alterados
- `modules/busca_global.php`

## Banco de dados
Não houve alteração de banco.
