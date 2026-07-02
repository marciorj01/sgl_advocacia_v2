# Fase 3.6 — Layout Profissional, Modelos e Dashboard

## Objetivo
Corrigir o módulo Modelos Jurídicos, melhorar a navegação lateral para suportar o crescimento do sistema e ajustar o cálculo do Saldo Estimado no Dashboard.

## Arquivos alterados
- `index.php`
- `modules/modelos.php`
- `modules/dashboard.php`

## Melhorias aplicadas

### 1. Menu lateral profissional
- Logo reduzida para liberar espaço vertical.
- Perfil do usuário mais compacto.
- Menus agrupados por área:
  - Cadastros
  - Jurídico
  - Financeiro
  - Administração
- Grupos recolhíveis com Bootstrap.
- Rodapé do menu sem sobrepor os itens inferiores.
- Layout mais preparado para novos módulos, como IA Jurídica.

### 2. Módulo Modelos Jurídicos
- Corrigida a conexão com banco.
- O módulo agora usa o mesmo padrão dos demais módulos: `conectar()`.

### 3. Dashboard financeiro
- O Saldo Estimado agora considera também despesas efetivamente pagas no mês.
- Fórmula ajustada:
  - Recebido no mês
  - + Total a receber
  - - Despesas em aberto
  - - Despesas já pagas no mês

## Observação
Não é necessário importar SQL nesta etapa.
