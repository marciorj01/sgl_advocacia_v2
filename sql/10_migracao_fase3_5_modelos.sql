CREATE TABLE IF NOT EXISTS modelos_documentos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    codigo VARCHAR(20) NOT NULL UNIQUE,
    titulo VARCHAR(180) NOT NULL,
    categoria VARCHAR(80) NOT NULL DEFAULT 'Outros',
    area_direito VARCHAR(80) NULL,
    conteudo LONGTEXT NOT NULL,
    observacoes TEXT NULL,
    status ENUM('Ativo','Inativo') NOT NULL DEFAULT 'Ativo',
    criado_por INT NULL,
    atualizado_por INT NULL,
    deletado TINYINT(1) NOT NULL DEFAULT 0,
    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_modelos_categoria (categoria),
    INDEX idx_modelos_status (status),
    INDEX idx_modelos_deletado (deletado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
