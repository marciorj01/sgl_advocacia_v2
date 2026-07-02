# DOCUMENTAÇÃO — FASE 4.2 — Centro de Produção de Documentos Jurídicos

## Objetivo
Transformar o módulo Modelos em um centro de produção de documentos jurídicos, com biblioteca padrão, variáveis clicáveis, geração por cliente/processo, exportação e histórico.

## Melhorias implementadas

- Importação de biblioteca padrão de modelos jurídicos.
- Modelos iniciais para Previdenciário, Cível, Trabalhista, Contratual e Geral.
- Botão “Importar biblioteca padrão”.
- Editor com atalhos rápidos: negrito, itálico, lista e assinatura.
- Variáveis clicáveis para preencher dados automaticamente.
- Geração de documento com cliente e processo.
- Exportação para Word.
- Impressão/salvar PDF pelo navegador.
- Histórico dos últimos documentos gerados.
- Abertura de documento gerado a partir do histórico.

## Arquivos alterados

- modules/modelos.php
- modelo_gerar.php

## Observação
Não é necessário importar SQL. As tabelas e colunas são verificadas automaticamente pelo próprio módulo.

## Fluxo recomendado

1. Entrar em Jurídico > Modelos.
2. Clicar em “Importar biblioteca padrão”.
3. Escolher um modelo.
4. Clicar em gerar.
5. Selecionar cliente e processo.
6. Aplicar variáveis.
7. Exportar em Word ou imprimir/salvar PDF.
8. Salvar no histórico.
