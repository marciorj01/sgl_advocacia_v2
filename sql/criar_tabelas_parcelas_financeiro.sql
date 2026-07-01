-- ============================================================
-- TABELAS DE PARCELAS PARA CONTAS A PAGAR E CONTAS A RECEBER
-- ============================================================

-- Tabela para armazenar as parcelas de Contas a Pagar
CREATE TABLE IF NOT EXISTS contas_pagar_parcelas (
    id VARCHAR(20) PRIMARY KEY,
    conta_id VARCHAR(20) NOT NULL,
    parcela_numero INT NOT NULL,
    valor_parcela DECIMAL(12,2) NOT NULL,
    data_vencimento DATE,
    forma_pagamento VARCHAR(100),
    status_pagamento VARCHAR(20) DEFAULT 'Pendente',
    valor_pago DECIMAL(12,2) DEFAULT 0,
    saldo_devedor DECIMAL(12,2) DEFAULT 0,
    observacoes TEXT,
    FOREIGN KEY (conta_id) REFERENCES contas_pagar(id) ON DELETE CASCADE,
    INDEX idx_conta_id (conta_id),
    INDEX idx_status (status_pagamento)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabela para armazenar as parcelas de Contas a Receber
CREATE TABLE IF NOT EXISTS contas_receber_parcelas (
    id VARCHAR(20) PRIMARY KEY,
    conta_id VARCHAR(20) NOT NULL,
    parcela_numero INT NOT NULL,
    valor_parcela DECIMAL(12,2) NOT NULL,
    data_vencimento DATE,
    forma_pagamento VARCHAR(100),
    status_pagamento VARCHAR(20) DEFAULT 'Pendente',
    valor_pago DECIMAL(12,2) DEFAULT 0,
    saldo_devedor DECIMAL(12,2) DEFAULT 0,
    observacoes TEXT,
    FOREIGN KEY (conta_id) REFERENCES contas_receber(id) ON DELETE CASCADE,
    INDEX idx_conta_id (conta_id),
    INDEX idx_status (status_pagamento)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Adicionar colunas às tabelas contas_pagar e contas_receber se ainda não existirem
ALTER TABLE contas_pagar ADD COLUMN IF NOT EXISTS qtd_parcelas INT DEFAULT 1;
ALTER TABLE contas_pagar ADD COLUMN IF NOT EXISTS valor_parcela DECIMAL(12,2) DEFAULT 0;
ALTER TABLE contas_pagar ADD COLUMN IF NOT EXISTS valor_pago DECIMAL(12,2) DEFAULT 0;
ALTER TABLE contas_pagar ADD COLUMN IF NOT EXISTS valor_pendente DECIMAL(12,2) DEFAULT 0;

ALTER TABLE contas_receber ADD COLUMN IF NOT EXISTS qtd_parcelas INT DEFAULT 1;
ALTER TABLE contas_receber ADD COLUMN IF NOT EXISTS valor_parcela DECIMAL(12,2) DEFAULT 0;
ALTER TABLE contas_receber ADD COLUMN IF NOT EXISTS valor_pago DECIMAL(12,2) DEFAULT 0;
ALTER TABLE contas_receber ADD COLUMN IF NOT EXISTS valor_pendente DECIMAL(12,2) DEFAULT 0;
