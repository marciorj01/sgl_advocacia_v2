-- ============================================================
-- SISTEMA SGL ADVOCACIA — MIGRAÇÃO FASE 1 PARA BANCO EXISTENTE
-- Execute somente se você já criou o banco antigo e quer ajustar a base.
-- Para instalação limpa, prefira sql/00_instalacao_completa_sgl.sql
-- ============================================================

USE sistema_sgl;

CREATE TABLE IF NOT EXISTS usuarios (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(120) NOT NULL,
    usuario VARCHAR(60) NOT NULL UNIQUE,
    email VARCHAR(120) NULL,
    senha VARCHAR(255) NOT NULL,
    perfil ENUM('Administrador','Advogado','Atendente','Financeiro','Usuário') NOT NULL DEFAULT 'Usuário',
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    ultimo_login DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO usuarios (id, nome, usuario, email, senha, perfil, ativo) VALUES
(1, 'Administrador', 'admin', NULL, '$2y$12$WorfRFEMbQnv4eSv1APdTOMezdnfMiz/R.pGpa4/g6VkFjwyFVK9K', 'Administrador', 1);

CREATE TABLE IF NOT EXISTS configuracoes (
    chave VARCHAR(60) NOT NULL,
    valor TEXT NOT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (chave)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO configuracoes (chave, valor) VALUES
('cor_primaria', '#1a3c5e'),
('cor_secundaria', '#2c6fad'),
('cor_accent', '#f0a500'),
('logo_arquivo', '');

-- Observação: ALTER TABLE ADD COLUMN IF NOT EXISTS depende da versão do MySQL/MariaDB.
-- Se sua versão não aceitar, execute somente as linhas das colunas que ainda não existem.
ALTER TABLE clientes ADD COLUMN IF NOT EXISTS deletado TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE agenda ADD COLUMN IF NOT EXISTS deletado TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE agenda ADD COLUMN IF NOT EXISTS nome_cliente VARCHAR(120) NULL;
ALTER TABLE agenda ADD COLUMN IF NOT EXISTS local VARCHAR(150) NULL;
ALTER TABLE processos ADD COLUMN IF NOT EXISTS vara VARCHAR(120) NULL;
ALTER TABLE processos ADD COLUMN IF NOT EXISTS data_audiencia DATE NULL;
ALTER TABLE honorarios ADD COLUMN IF NOT EXISTS nome_cliente VARCHAR(120) NULL;
ALTER TABLE honorarios ADD COLUMN IF NOT EXISTS numero_processo VARCHAR(60) NULL;
ALTER TABLE honorarios ADD COLUMN IF NOT EXISTS valor_pendente DECIMAL(12,2) DEFAULT 0;
ALTER TABLE honorarios ADD COLUMN IF NOT EXISTS deletado TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE honorarios_parcelas ADD COLUMN IF NOT EXISTS data_pagamento DATE NULL;
ALTER TABLE contas_pagar ADD COLUMN IF NOT EXISTS qtd_parcelas INT DEFAULT 1;
ALTER TABLE contas_pagar ADD COLUMN IF NOT EXISTS valor_parcela DECIMAL(12,2) DEFAULT 0;
ALTER TABLE contas_pagar ADD COLUMN IF NOT EXISTS valor_pago DECIMAL(12,2) DEFAULT 0;
ALTER TABLE contas_pagar ADD COLUMN IF NOT EXISTS valor_pendente DECIMAL(12,2) DEFAULT 0;
ALTER TABLE contas_pagar ADD COLUMN IF NOT EXISTS deletado TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE contas_receber ADD COLUMN IF NOT EXISTS qtd_parcelas INT DEFAULT 1;
ALTER TABLE contas_receber ADD COLUMN IF NOT EXISTS valor_parcela DECIMAL(12,2) DEFAULT 0;
ALTER TABLE contas_receber ADD COLUMN IF NOT EXISTS valor_pago DECIMAL(12,2) DEFAULT 0;
ALTER TABLE contas_receber ADD COLUMN IF NOT EXISTS valor_pendente DECIMAL(12,2) DEFAULT 0;
ALTER TABLE contas_receber ADD COLUMN IF NOT EXISTS deletado TINYINT(1) NOT NULL DEFAULT 0;

SELECT 'Migração Fase 1 concluída. Acesso inicial: admin / admin123.' AS resultado;
