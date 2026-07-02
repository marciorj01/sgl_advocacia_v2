-- FASE 2.6 — HONORÁRIOS
-- Migração segura para bancos que ainda não possuem todas as colunas usadas pelo módulo.
-- Execute este arquivo no banco do SGL pelo phpMyAdmin.

SET @db := DATABASE();

SET @sql := IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='honorarios' AND COLUMN_NAME='deletado') = 0,
  'ALTER TABLE honorarios ADD COLUMN deletado TINYINT(1) NOT NULL DEFAULT 0',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='honorarios' AND COLUMN_NAME='valor_pendente') = 0,
  'ALTER TABLE honorarios ADD COLUMN valor_pendente DECIMAL(12,2) DEFAULT 0',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='honorarios' AND COLUMN_NAME='numero_processo') = 0,
  'ALTER TABLE honorarios ADD COLUMN numero_processo VARCHAR(60) NULL',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS honorarios_parcelas (
    id VARCHAR(20) PRIMARY KEY,
    honorario_id VARCHAR(10) NOT NULL,
    cliente_id VARCHAR(10) NULL,
    nome_cliente VARCHAR(120) NULL,
    numero_processo VARCHAR(60) NULL,
    parcela_numero INT NOT NULL,
    valor_parcela DECIMAL(12,2) NOT NULL DEFAULT 0,
    data_vencimento DATE NULL,
    forma_pagamento VARCHAR(80) NULL,
    status_pagamento VARCHAR(30) DEFAULT 'Pendente',
    valor_pago DECIMAL(12,2) DEFAULT 0,
    saldo_devedor DECIMAL(12,2) DEFAULT 0,
    data_pagamento DATE NULL,
    observacoes TEXT NULL,
    INDEX idx_hp_honorario (honorario_id),
    INDEX idx_hp_status (status_pagamento)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

UPDATE honorarios
SET valor_pendente = GREATEST(COALESCE(valor_total,0) - COALESCE(valor_pago,0), 0)
WHERE valor_pendente IS NULL OR valor_pendente = 0;
