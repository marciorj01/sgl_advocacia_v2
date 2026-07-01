CREATE DATABASE IF NOT EXISTS sistema_sgl CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE sistema_sgl;

CREATE TABLE IF NOT EXISTS advogados (
    id VARCHAR(10) PRIMARY KEY,
    nome VARCHAR(100) NOT NULL,
    oab VARCHAR(30) NOT NULL,
    especialidade VARCHAR(50),
    telefone VARCHAR(20),
    email VARCHAR(100),
    data_cadastro DATE,
    status ENUM('Ativo','Inativo') DEFAULT 'Ativo',
    observacoes TEXT
);

CREATE TABLE IF NOT EXISTS clientes (
    id VARCHAR(10) PRIMARY KEY,
    nome VARCHAR(100) NOT NULL,
    cpf_cnpj VARCHAR(20) UNIQUE NOT NULL,
    tipo_pessoa ENUM('Física','Jurídica') DEFAULT 'Física',
    rg VARCHAR(20),
    data_nascimento DATE,
    estado_civil VARCHAR(20),
    profissao VARCHAR(50),
    telefone VARCHAR(20),
    celular VARCHAR(20),
    whatsapp VARCHAR(20),
    email VARCHAR(100),
    email_secundario VARCHAR(100),
    cep VARCHAR(10),
    logradouro VARCHAR(100),
    numero VARCHAR(10),
    complemento VARCHAR(50),
    bairro VARCHAR(50),
    cidade VARCHAR(50),
    estado CHAR(2),
    advogado_id VARCHAR(10),
    tipo_processo VARCHAR(50),
    data_cadastro DATE,
    status ENUM('Ativo','Em análise','Inativo','Encerrado') DEFAULT 'Ativo',
    indicacao VARCHAR(100),
    observacoes TEXT,
    FOREIGN KEY (advogado_id) REFERENCES advogados(id)
);

CREATE TABLE IF NOT EXISTS processos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    numero_processo VARCHAR(50) UNIQUE NOT NULL,
    cliente_id VARCHAR(10),
    tipo_processo VARCHAR(50),
    vara_tribunal VARCHAR(100),
    comarca VARCHAR(100),
    advogado_id VARCHAR(10),
    data_distribuicao DATE,
    fase_atual VARCHAR(50),
    valor_causa DECIMAL(12,2),
    proximo_prazo DATE,
    status ENUM('Em Andamento','Suspenso','Arquivado','Encerrado') DEFAULT 'Em Andamento',
    ultima_movimentacao DATE,
    observacoes TEXT,
    FOREIGN KEY (cliente_id) REFERENCES clientes(id),
    FOREIGN KEY (advogado_id) REFERENCES advogados(id)
);

CREATE TABLE IF NOT EXISTS honorarios (
    id VARCHAR(10) PRIMARY KEY,
    cliente_id VARCHAR(10),
    processo_numero VARCHAR(50),
    tipo_honorario VARCHAR(50),
    valor_total DECIMAL(12,2),
    qtd_parcelas INT DEFAULT 1,
    valor_parcela DECIMAL(12,2),
    parcela_atual INT DEFAULT 1,
    data_vencimento DATE,
    forma_pagamento VARCHAR(30),
    status ENUM('Pendente','Parcial','Pago') DEFAULT 'Pendente',
    valor_pago DECIMAL(12,2) DEFAULT 0,
    observacoes TEXT,
    FOREIGN KEY (cliente_id) REFERENCES clientes(id)
);

CREATE TABLE IF NOT EXISTS agenda (
    id VARCHAR(10) PRIMARY KEY,
    data_evento DATE NOT NULL,
    horario TIME,
    tipo_compromisso VARCHAR(50),
    cliente_id VARCHAR(10),
    processo_numero VARCHAR(50),
    local_evento VARCHAR(100),
    advogado_id VARCHAR(10),
    status ENUM('Pendente','Realizado','Cancelado') DEFAULT 'Pendente',
    prazo_fatal ENUM('Sim','Não') DEFAULT 'Não',
    observacoes TEXT,
    FOREIGN KEY (cliente_id) REFERENCES clientes(id),
    FOREIGN KEY (advogado_id) REFERENCES advogados(id)
);

CREATE TABLE IF NOT EXISTS contas_pagar (
    id VARCHAR(10) PRIMARY KEY,
    descricao VARCHAR(100) NOT NULL,
    categoria VARCHAR(50),
    fornecedor VARCHAR(100),
    valor DECIMAL(12,2),
    data_vencimento DATE,
    data_pagamento DATE,
    forma_pagamento VARCHAR(30),
    status ENUM('Pendente','Pago','Cancelado') DEFAULT 'Pendente',
    mes_referencia VARCHAR(7),
    observacoes TEXT
);

CREATE TABLE IF NOT EXISTS contas_receber (
    id VARCHAR(10) PRIMARY KEY,
    cliente_id VARCHAR(10),
    descricao VARCHAR(100),
    valor DECIMAL(12,2),
    data_vencimento DATE,
    data_recebimento DATE,
    forma_recebimento VARCHAR(30),
    status ENUM('Pendente','Recebido','Cancelado') DEFAULT 'Pendente',
    mes_referencia VARCHAR(7),
    observacoes TEXT,
    FOREIGN KEY (cliente_id) REFERENCES clientes(id)
);