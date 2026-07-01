-- Fase 2.2 — Ajustes do módulo Clientes
-- Permite cadastrar clientes sem CPF/CNPJ no primeiro atendimento sem violar índice único.

USE sgl_advocacia;

ALTER TABLE clientes
    MODIFY cpf_cnpj VARCHAR(25) NULL;

UPDATE clientes
SET cpf_cnpj = NULL
WHERE cpf_cnpj = '';

SET @idx_cidade := (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'clientes' AND index_name = 'idx_cli_cidade'
);
SET @sql_cidade := IF(@idx_cidade = 0, 'ALTER TABLE clientes ADD INDEX idx_cli_cidade (cidade)', 'SELECT 1');
PREPARE stmt_cidade FROM @sql_cidade;
EXECUTE stmt_cidade;
DEALLOCATE PREPARE stmt_cidade;

SET @idx_criado := (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'clientes' AND index_name = 'idx_cli_criado_em'
);
SET @sql_criado := IF(@idx_criado = 0, 'ALTER TABLE clientes ADD INDEX idx_cli_criado_em (criado_em)', 'SELECT 1');
PREPARE stmt_criado FROM @sql_criado;
EXECUTE stmt_criado;
DEALLOCATE PREPARE stmt_criado;
