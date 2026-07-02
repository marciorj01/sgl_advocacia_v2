-- FASE 3.2 - Integração Honorários -> Financeiro -> Recibos
-- Execute no phpMyAdmin caso queira deixar o banco preparado antes de usar os módulos.
-- Os módulos também tentam criar essas colunas automaticamente para manter compatibilidade com XAMPP/MariaDB.

ALTER TABLE contas_receber ADD COLUMN honorario_id VARCHAR(20) NULL;
ALTER TABLE contas_receber ADD COLUMN parcela_id VARCHAR(20) NULL;
ALTER TABLE contas_receber ADD COLUMN origem VARCHAR(50) NULL;
ALTER TABLE recibos ADD COLUMN conta_receber_id VARCHAR(20) NULL;
