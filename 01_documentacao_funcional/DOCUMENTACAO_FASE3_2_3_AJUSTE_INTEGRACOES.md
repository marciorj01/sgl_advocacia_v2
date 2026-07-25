# Fase 3.2.3 — Ajuste profundo de integrações

## Correções aplicadas

- Correção do cadastro de processos quando nenhum advogado é selecionado.
- Normalização do campo `advogado_id` para `NULL` quando vazio ou inválido, evitando erro de chave estrangeira.
- Criação/garantia automática da tabela `logs_sistema`.
- Registro de logs para ações críticas em processos, financeiro e recibos.
- Ajuste do Dashboard para listar honorários pendentes/vencidos de forma mais flexível.
- Recibo redesenhado para impressão em duas vias na mesma folha A4.
- Mantida compatibilidade com MariaDB/XAMPP e com a arquitetura modular atual.

## Arquivos alterados

- `config/integracoes.php`
- `modules/financeiro.php`
- `modules/processos.php`
- `modules/dashboard.php`
- `modules/recibos.php`

## SQL incluído

- `sql/09_migracao_fase3_2_3_logs_ajustes.sql`

A importação do SQL é opcional, pois a estrutura é garantida automaticamente pelos módulos.
