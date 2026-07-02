# Fase 2.8 — Configurações

## Objetivo
Finalizar o menu Configurações com padrão administrativo profissional.

## Arquivos alterados
- `modules/configuracoes.php`
- `config/tema.php`
- `index.php`

## Arquivos incluídos
- `sql/06_migracao_fase2_8_configuracoes.sql`
- `DOCUMENTACAO_FASE2_8_CONFIGURACOES.md`

## Funcionalidades implementadas
- Dados do escritório.
- Upload e remoção de logomarca.
- Tema com cores personalizadas.
- Preparação para modo escuro.
- Gestão básica de usuários.
- Redefinição de senha pelo administrador.
- Ativação e desativação de usuários.
- Parâmetros gerais do sistema.
- Resumo das principais tabelas do banco.
- Lixeira central compatível com módulos já criados.
- Logs/auditoria inicial.

## Segurança
- CSRF em formulários.
- Prepared statements nas ações críticas.
- Validação de e-mail, senha e cores.
- Upload restrito a JPG/PNG com limite de 2 MB.
- Proteção para não desativar o próprio usuário logado.

## Observação
O módulo cria automaticamente as tabelas auxiliares mínimas, mas o SQL de migração foi incluído para manter histórico técnico e permitir instalação controlada.
