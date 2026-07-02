# DOCUMENTAÇÃO — FASE 4.0 — IA PARA ADVOGADOS

## Objetivo
Criar o módulo **IA Jurídica** no SGL Advocacia para auxiliar o advogado na criação de rascunhos, revisão de textos, resumos processuais, estratégias, contratos e checklists.

## Importante
O módulo funciona em dois modos:

1. **Modo rascunho**: não usa API externa. Gera um prompt completo e organizado para copiar e usar no ChatGPT.
2. **Modo API**: quando configuradas as variáveis de ambiente `SGL_OPENAI_API_KEY` e `SGL_OPENAI_MODEL`, o sistema chama a OpenAI API pelo endpoint Responses.

## Arquivos alterados
- `index.php`

## Arquivos incluídos
- `modules/ia_juridica.php`
- `config/ia.php`
- `DOCUMENTACAO_FASE4_0_IA_JURIDICA.md`

## Funcionalidades
- Menu **IA Jurídica**.
- Assistente de petições.
- Revisão de texto jurídico.
- Resumo jurídico/processual.
- Estratégia processual.
- Checklist de documentos.
- Geração/revisão de contratos.
- Integração com cliente, processo e modelos jurídicos.
- Histórico de consultas em `ia_consultas`.
- Log de uso da IA.

## Segurança
- A chave de API não fica gravada no código.
- Use variáveis de ambiente em produção.
- A resposta é sempre considerada rascunho para revisão profissional.
- O módulo não substitui análise jurídica humana.

## Configuração futura na Hostinger
Configurar as variáveis de ambiente:

```text
SGL_OPENAI_API_KEY=chave_da_api
SGL_OPENAI_MODEL=modelo_escolhido
```

Enquanto isso não for configurado, o módulo continuará funcionando em modo rascunho.
