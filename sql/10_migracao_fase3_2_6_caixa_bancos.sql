-- FASE 3.2.6 — Caixa, bancos e fechamento financeiro
-- Esta migração é opcional: o módulo Financeiro também cria/ajusta a estrutura automaticamente.

CREATE TABLE IF NOT EXISTS bancos_caixa (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(120) NOT NULL,
    tipo VARCHAR(40) DEFAULT 'Conta Corrente',
    banco VARCHAR(120) NULL,
    agencia VARCHAR(40) NULL,
    conta VARCHAR(60) NULL,
    saldo_inicial DECIMAL(12,2) DEFAULT 0,
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_bancos_ativo (ativo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE contas_pagar ADD COLUMN banco_id INT NULL;
ALTER TABLE contas_receber ADD COLUMN banco_id INT NULL;
