# FASE 3.2.5 — Fechamento de Caixa, Logs e Recibo A5

## Alterações

- Dashboard recebeu fechamento de caixa do dia e fechamento mensal.
- Logs passaram a gravar também nome, login e perfil do responsável diretamente na tabela `logs_sistema`.
- Configurações > Logs passou a exibir o responsável mesmo quando o vínculo com a tabela `usuarios` não estiver disponível.
- Recibo passou a imprimir em formato A5, ocupando meia folha A4, com layout mais compacto.

## Arquivos alterados

- `modules/dashboard.php`
- `modules/configuracoes.php`
- `modules/recibos.php`
- `config/integracoes.php`

## Observação

Não é necessário importar SQL. As colunas novas de log são criadas automaticamente se ainda não existirem.
