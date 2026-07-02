-- FASE 3.1 — GERADOR DE RECIBOS
-- Cria a tabela de recibos. Execute no banco do SGL pelo phpMyAdmin.

CREATE TABLE IF NOT EXISTS recibos (
    id VARCHAR(20) PRIMARY KEY,
    numero VARCHAR(30) NOT NULL UNIQUE,
    cliente_id VARCHAR(10) NULL,
    nome_cliente VARCHAR(150) NOT NULL,
    cpf_cnpj VARCHAR(25) NULL,
    processo_numero VARCHAR(80) NULL,
    honorario_id VARCHAR(20) NULL,
    parcela_id VARCHAR(20) NULL,
    data_emissao DATE NOT NULL,
    data_pagamento DATE NULL,
    referente VARCHAR(255) NOT NULL,
    forma_pagamento VARCHAR(80) NULL,
    valor DECIMAL(12,2) NOT NULL DEFAULT 0,
    observacoes TEXT NULL,
    status ENUM('Emitido','Cancelado') NOT NULL DEFAULT 'Emitido',
    chave_validacao VARCHAR(80) NULL,
    deletado TINYINT(1) NOT NULL DEFAULT 0,
    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_rec_numero (numero),
    INDEX idx_rec_cliente (cliente_id),
    INDEX idx_rec_status (status),
    INDEX idx_rec_deletado (deletado),
    INDEX idx_rec_data (data_emissao)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
