# FASE 4.1 — Biblioteca Jurídica Profissional

## Objetivo
Evoluir o módulo Modelos Jurídicos para uma biblioteca profissional de contratos, petições, procurações, termos e documentos reutilizáveis.

## Implementado
- Cadastro e edição de modelos jurídicos.
- Biblioteca de variáveis clicáveis para inserir campos automáticos.
- Variáveis de cliente, processo, escritório e financeiro.
- Geração de documento com preenchimento por cliente/processo.
- Exportação em Word (.doc) e impressão/salvar como PDF pelo navegador.
- Histórico de documentos gerados.
- Favoritos.
- Versionamento automático: ao editar, a versão anterior é preservada.
- Logs de criação, edição, duplicação, exclusão e geração.

## Arquivos alterados
- modules/modelos.php

## Arquivos incluídos
- modelo_gerar.php
- DOCUMENTACAO_FASE4_1_BIBLIOTECA_JURIDICA.md

## Observação
Não é necessário importar SQL. O módulo cria/ajusta automaticamente as tabelas necessárias.
