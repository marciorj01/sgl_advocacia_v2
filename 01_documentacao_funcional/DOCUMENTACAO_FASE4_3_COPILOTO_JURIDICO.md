# Fase 4.3 — IA Jurídica Integrada / Copiloto Jurídico

## Objetivo
Transformar o módulo de IA em um copiloto jurídico integrado aos dados do SGL Advocacia.

## Recursos implementados
- Geração de rascunhos jurídicos com cliente, processo e modelo vinculados.
- Modo rascunho inteligente mesmo sem API externa.
- Petições, contratos, resumos, revisões, estratégias, checklists, linguagem simples para cliente e teses/pedidos.
- Histórico de consultas de IA.
- Salvamento do resultado no histórico de documentos gerados da Biblioteca Jurídica.
- Botões para copiar, imprimir/salvar PDF e salvar no histórico.
- Preparação para API externa quando o sistema for para produção.

## Observação
Enquanto a API não estiver configurada, o sistema gera um rascunho local estruturado. Após publicação na Hostinger, configurar as variáveis `SGL_OPENAI_API_KEY` e `SGL_OPENAI_MODEL` para respostas automáticas completas.
