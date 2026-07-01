-- ============================================================
-- SISTEMA SGL ADVOCACIA — INSTALAÇÃO COMPLETA FASE 1
-- Compatível com MySQL/MariaDB — PHP 8+
-- Banco padrão local: sistema_sgl
-- Acesso inicial: usuário admin / senha admin123
-- IMPORTANTE: altere a senha após o primeiro login.
-- ============================================================

CREATE DATABASE IF NOT EXISTS sistema_sgl
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE sistema_sgl;

SET FOREIGN_KEY_CHECKS = 0;

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
    ultimo_login DATETIME NULL,
    INDEX idx_usuario_ativo (usuario, ativo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS advogados (
    id VARCHAR(10) PRIMARY KEY,
    nome VARCHAR(120) NOT NULL,
    oab VARCHAR(30) NOT NULL,
    especialidade VARCHAR(80) NULL,
    telefone VARCHAR(30) NULL,
    email VARCHAR(120) NULL,
    data_cadastro DATE NULL,
    status ENUM('Ativo','Inativo','Excluído') DEFAULT 'Ativo',
    observacoes TEXT NULL,
    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_adv_status (status),
    INDEX idx_adv_nome (nome)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS clientes (
    id VARCHAR(10) PRIMARY KEY,
    nome VARCHAR(120) NOT NULL,
    cpf_cnpj VARCHAR(25) NOT NULL,
    tipo_pessoa ENUM('Física','Jurídica') DEFAULT 'Física',
    rg VARCHAR(30) NULL,
    data_nascimento DATE NULL,
    estado_civil VARCHAR(40) NULL,
    profissao VARCHAR(80) NULL,
    telefone VARCHAR(30) NULL,
    celular VARCHAR(30) NULL,
    whatsapp VARCHAR(30) NULL,
    email VARCHAR(120) NULL,
    email_secundario VARCHAR(120) NULL,
    cep VARCHAR(12) NULL,
    logradouro VARCHAR(150) NULL,
    numero VARCHAR(20) NULL,
    complemento VARCHAR(80) NULL,
    bairro VARCHAR(80) NULL,
    cidade VARCHAR(80) NULL,
    estado CHAR(2) NULL,
    advogado_id VARCHAR(10) NULL,
    tipo_processo VARCHAR(80) NULL,
    data_cadastro DATE NULL,
    status ENUM('Ativo','Em análise','Inativo','Encerrado','Excluído') DEFAULT 'Ativo',
    indicacao VARCHAR(120) NULL,
    observacoes TEXT NULL,
    deletado TINYINT(1) NOT NULL DEFAULT 0,
    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_clientes_cpf_cnpj (cpf_cnpj),
    INDEX idx_cli_nome (nome),
    INDEX idx_cli_deletado (deletado),
    INDEX idx_cli_status (status),
    CONSTRAINT fk_clientes_advogados FOREIGN KEY (advogado_id) REFERENCES advogados(id) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS processos (
    id VARCHAR(10) PRIMARY KEY,
    numero_processo VARCHAR(60) NOT NULL UNIQUE,
    cliente_id VARCHAR(10) NULL,
    tipo_processo VARCHAR(80) NULL,
    vara VARCHAR(120) NULL,
    vara_tribunal VARCHAR(120) NULL,
    comarca VARCHAR(120) NULL,
    advogado_id VARCHAR(10) NULL,
    data_distribuicao DATE NULL,
    fase_atual VARCHAR(80) NULL,
    valor_causa DECIMAL(12,2) DEFAULT 0,
    proximo_prazo DATE NULL,
    data_audiencia DATE NULL,
    status ENUM('Em Andamento','Suspenso','Arquivado','Encerrado','Excluído') DEFAULT 'Em Andamento',
    ultima_movimentacao DATE NULL,
    observacoes TEXT NULL,
    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_proc_cliente (cliente_id),
    INDEX idx_proc_advogado (advogado_id),
    INDEX idx_proc_status (status),
    INDEX idx_proc_prazo (proximo_prazo),
    CONSTRAINT fk_processos_clientes FOREIGN KEY (cliente_id) REFERENCES clientes(id) ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_processos_advogados FOREIGN KEY (advogado_id) REFERENCES advogados(id) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS honorarios (
    id VARCHAR(10) PRIMARY KEY,
    cliente_id VARCHAR(10) NULL,
    nome_cliente VARCHAR(120) NULL,
    processo_numero VARCHAR(60) NULL,
    numero_processo VARCHAR(60) NULL,
    tipo_honorario VARCHAR(80) NULL,
    valor_total DECIMAL(12,2) DEFAULT 0,
    qtd_parcelas INT DEFAULT 1,
    valor_parcela DECIMAL(12,2) DEFAULT 0,
    parcela_atual INT DEFAULT 1,
    data_vencimento DATE NULL,
    forma_pagamento VARCHAR(80) NULL,
    status ENUM('Pendente','Parcial','Pago','Quitada','Cancelado') DEFAULT 'Pendente',
    valor_pago DECIMAL(12,2) DEFAULT 0,
    valor_pendente DECIMAL(12,2) DEFAULT 0,
    observacoes TEXT NULL,
    deletado TINYINT(1) NOT NULL DEFAULT 0,
    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_hon_cliente (cliente_id),
    INDEX idx_hon_status (status),
    INDEX idx_hon_deletado (deletado),
    CONSTRAINT fk_honorarios_clientes FOREIGN KEY (cliente_id) REFERENCES clientes(id) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
    INDEX idx_hp_status (status_pagamento),
    CONSTRAINT fk_hp_honorarios FOREIGN KEY (honorario_id) REFERENCES honorarios(id) ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS agenda (
    id VARCHAR(10) PRIMARY KEY,
    data_evento DATE NOT NULL,
    horario TIME NULL,
    tipo_compromisso VARCHAR(80) NULL,
    cliente_id VARCHAR(10) NULL,
    nome_cliente VARCHAR(120) NULL,
    processo_numero VARCHAR(60) NULL,
    numero_processo VARCHAR(60) NULL,
    local VARCHAR(150) NULL,
    local_evento VARCHAR(150) NULL,
    advogado_id VARCHAR(10) NULL,
    status ENUM('Pendente','Realizado','Cancelado') DEFAULT 'Pendente',
    prazo_fatal ENUM('Sim','Não') DEFAULT 'Não',
    observacoes TEXT NULL,
    deletado TINYINT(1) NOT NULL DEFAULT 0,
    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_ag_data (data_evento),
    INDEX idx_ag_deletado (deletado),
    CONSTRAINT fk_agenda_clientes FOREIGN KEY (cliente_id) REFERENCES clientes(id) ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_agenda_advogados FOREIGN KEY (advogado_id) REFERENCES advogados(id) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS contas_pagar (
    id VARCHAR(10) PRIMARY KEY,
    descricao VARCHAR(150) NULL,
    categoria VARCHAR(80) NULL,
    fornecedor VARCHAR(120) NULL,
    valor DECIMAL(12,2) DEFAULT 0,
    qtd_parcelas INT DEFAULT 1,
    valor_parcela DECIMAL(12,2) DEFAULT 0,
    valor_pago DECIMAL(12,2) DEFAULT 0,
    valor_pendente DECIMAL(12,2) DEFAULT 0,
    data_vencimento DATE NULL,
    data_pagamento DATE NULL,
    forma_pagamento VARCHAR(80) NULL,
    status ENUM('Pendente','Parcial','Pago','Quitada','Cancelado') DEFAULT 'Pendente',
    mes_referencia VARCHAR(7) NULL,
    observacoes TEXT NULL,
    deletado TINYINT(1) NOT NULL DEFAULT 0,
    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_cp_vencimento (data_vencimento),
    INDEX idx_cp_status (status),
    INDEX idx_cp_deletado (deletado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS contas_pagar_parcelas (
    id VARCHAR(20) PRIMARY KEY,
    conta_id VARCHAR(10) NOT NULL,
    parcela_numero INT NOT NULL,
    valor_parcela DECIMAL(12,2) NOT NULL DEFAULT 0,
    data_vencimento DATE NULL,
    forma_pagamento VARCHAR(80) NULL,
    status_pagamento VARCHAR(30) DEFAULT 'Pendente',
    valor_pago DECIMAL(12,2) DEFAULT 0,
    saldo_devedor DECIMAL(12,2) DEFAULT 0,
    observacoes TEXT NULL,
    INDEX idx_cpp_conta (conta_id),
    INDEX idx_cpp_status (status_pagamento),
    CONSTRAINT fk_cpp_cp FOREIGN KEY (conta_id) REFERENCES contas_pagar(id) ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS contas_receber (
    id VARCHAR(10) PRIMARY KEY,
    cliente_id VARCHAR(10) NULL,
    descricao VARCHAR(150) NULL,
    valor DECIMAL(12,2) DEFAULT 0,
    qtd_parcelas INT DEFAULT 1,
    valor_parcela DECIMAL(12,2) DEFAULT 0,
    valor_pago DECIMAL(12,2) DEFAULT 0,
    valor_pendente DECIMAL(12,2) DEFAULT 0,
    data_vencimento DATE NULL,
    data_recebimento DATE NULL,
    forma_recebimento VARCHAR(80) NULL,
    status ENUM('Pendente','Parcial','Recebido','Pago','Quitada','Cancelado') DEFAULT 'Pendente',
    mes_referencia VARCHAR(7) NULL,
    observacoes TEXT NULL,
    deletado TINYINT(1) NOT NULL DEFAULT 0,
    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_cr_cliente (cliente_id),
    INDEX idx_cr_vencimento (data_vencimento),
    INDEX idx_cr_status (status),
    INDEX idx_cr_deletado (deletado),
    CONSTRAINT fk_cr_clientes FOREIGN KEY (cliente_id) REFERENCES clientes(id) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS contas_receber_parcelas (
    id VARCHAR(20) PRIMARY KEY,
    conta_id VARCHAR(10) NOT NULL,
    parcela_numero INT NOT NULL,
    valor_parcela DECIMAL(12,2) NOT NULL DEFAULT 0,
    data_vencimento DATE NULL,
    forma_pagamento VARCHAR(80) NULL,
    status_pagamento VARCHAR(30) DEFAULT 'Pendente',
    valor_pago DECIMAL(12,2) DEFAULT 0,
    saldo_devedor DECIMAL(12,2) DEFAULT 0,
    observacoes TEXT NULL,
    INDEX idx_crp_conta (conta_id),
    INDEX idx_crp_status (status_pagamento),
    CONSTRAINT fk_crp_cr FOREIGN KEY (conta_id) REFERENCES contas_receber(id) ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS configuracoes (
    chave VARCHAR(60) NOT NULL,
    valor TEXT NOT NULL,
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
    INDEX idx_logs_acao (acao),
    CONSTRAINT fk_logs_usuarios FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO usuarios (id, nome, usuario, email, senha, perfil, ativo) VALUES
(1, 'Administrador', 'admin', NULL, '$2y$12$WorfRFEMbQnv4eSv1APdTOMezdnfMiz/R.pGpa4/g6VkFjwyFVK9K', 'Administrador', 1);

INSERT IGNORE INTO configuracoes (chave, valor) VALUES
('cor_primaria', '#1a3c5e'),
('cor_secundaria', '#2c6fad'),
('cor_accent', '#f0a500'),
('logo_arquivo', '');

SET FOREIGN_KEY_CHECKS = 1;

SELECT 'Instalação concluída. Acesse com usuário admin e senha admin123.' AS resultado;
