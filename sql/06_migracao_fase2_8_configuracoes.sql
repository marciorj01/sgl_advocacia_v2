-- FASE 2.8 — CONFIGURAÇÕES
-- Execute apenas se desejar garantir as tabelas auxiliares. O módulo também cria a base mínima automaticamente.

CREATE TABLE IF NOT EXISTS configuracoes (
    chave VARCHAR(80) NOT NULL,
    valor TEXT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (chave)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS logs_sistema (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    usuario_id INT NULL,
    acao VARCHAR(120) NOT NULL,
    tabela VARCHAR(80) NULL,
    registro_id VARCHAR(30) NULL,
    detalhes TEXT NULL,
    ip VARCHAR(45) NULL,
    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_logs_usuario (usuario_id),
    INDEX idx_logs_acao (acao)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO configuracoes (chave, valor) VALUES
('nome_escritorio', 'SGL Advocacia'),
('cor_primaria', '#1a3c5e'),
('cor_secundaria', '#2c6fad'),
('cor_accent', '#f0a500'),
('tema_modo', 'claro'),
('dias_alerta_prazos', '7'),
('itens_por_pagina', '25'),
('modo_debug', '0');
