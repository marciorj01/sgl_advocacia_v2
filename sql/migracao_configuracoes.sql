-- ============================================================
-- MIGRAÇÃO — Módulo de Configurações (White Label, Tema, Lixeira, Backup)
-- Sistema SGL v1.1
-- Execute este script UMA ÚNICA VEZ no banco de dados sistema_sgl
-- Compatível com MySQL 5.7+ e MariaDB 10.3+
-- ============================================================

USE sistema_sgl;

-- ──────────────────────────────────────────────────────────────
-- 1. Tabela de configurações do sistema
-- ──────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS configuracoes (
    chave      VARCHAR(60)  NOT NULL,
    valor      TEXT         NOT NULL,
    updated_at TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (chave)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Armazena configurações de white label, tema e preferências do sistema.';

-- Inserir valores padrão (não sobrescreve se já existir)
INSERT IGNORE INTO configuracoes (chave, valor) VALUES
    ('cor_primaria',   '#1a3c5e'),
    ('cor_secundaria', '#2c6fad'),
    ('cor_accent',     '#f0a500'),
    ('logo_arquivo',   '');

-- ──────────────────────────────────────────────────────────────
-- 2. Soft-delete: adicionar status 'Excluído' como opção válida
--    nas tabelas que serão gerenciadas pela lixeira.
--    ATENÇÃO: ALTER TABLE MODIFY pode variar se você já tiver
--    constraints ou ENUMs diferentes. Verifique antes de executar.
-- ──────────────────────────────────────────────────────────────

-- Advogados: adiciona 'Excluído' ao ENUM de status
ALTER TABLE advogados
    MODIFY COLUMN status ENUM('Ativo','Inativo','Excluído') DEFAULT 'Ativo'
    COMMENT 'Excluído = soft-delete (lixeira)';

-- Clientes: adiciona 'Excluído' ao ENUM de status
ALTER TABLE clientes
    MODIFY COLUMN status ENUM('Ativo','Em análise','Inativo','Encerrado','Excluído') DEFAULT 'Ativo'
    COMMENT 'Excluído = soft-delete (lixeira)';

-- Processos: adiciona 'Excluído' ao ENUM de status
ALTER TABLE processos
    MODIFY COLUMN status ENUM('Em Andamento','Suspenso','Arquivado','Encerrado','Excluído') DEFAULT 'Em Andamento'
    COMMENT 'Excluído = soft-delete (lixeira)';

-- ──────────────────────────────────────────────────────────────
-- 3. Verificar resultado
-- ──────────────────────────────────────────────────────────────
SELECT 'Migração concluída com sucesso!' AS resultado;
SELECT chave, valor, updated_at FROM configuracoes ORDER BY chave;
