-- FASE 3.2.3 — Logs e ajustes de integração
-- Pode ser importado, mas os módulos também criam a estrutura automaticamente.

CREATE TABLE IF NOT EXISTS logs_sistema (
    id INT AUTO_INCREMENT PRIMARY KEY,
    usuario_id INT NULL,
    acao VARCHAR(100) NOT NULL,
    tabela VARCHAR(80) NULL,
    registro_id VARCHAR(40) NULL,
    detalhes TEXT NULL,
    ip VARCHAR(45) NULL,
    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_logs_usuario (usuario_id),
    INDEX idx_logs_tabela (tabela),
    INDEX idx_logs_data (criado_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE recibos ADD COLUMN IF NOT EXISTS conta_receber_id VARCHAR(20) NULL;
