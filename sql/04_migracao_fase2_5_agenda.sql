-- =========================================================
-- SGL ADVOCACIA - MIGRAÇÃO FASE 2.5 - AGENDA
-- Execute este arquivo no phpMyAdmin, na aba SQL, dentro do banco correto.
-- Banco esperado: sgl_advocacia ou sistema_sgl, conforme sua instalação local.
-- =========================================================

ALTER TABLE agenda
    ADD COLUMN IF NOT EXISTS tipo_compromisso VARCHAR(80) NULL AFTER horario;

ALTER TABLE agenda
    ADD COLUMN IF NOT EXISTS cliente_id VARCHAR(10) NULL AFTER tipo_compromisso;

ALTER TABLE agenda
    ADD COLUMN IF NOT EXISTS nome_cliente VARCHAR(120) NULL AFTER cliente_id;

ALTER TABLE agenda
    ADD COLUMN IF NOT EXISTS numero_processo VARCHAR(60) NULL AFTER nome_cliente;

ALTER TABLE agenda
    ADD COLUMN IF NOT EXISTS local VARCHAR(150) NULL AFTER numero_processo;

ALTER TABLE agenda
    ADD COLUMN IF NOT EXISTS advogado_id VARCHAR(10) NULL AFTER local;

ALTER TABLE agenda
    ADD COLUMN IF NOT EXISTS status VARCHAR(30) NOT NULL DEFAULT 'Pendente' AFTER advogado_id;

ALTER TABLE agenda
    ADD COLUMN IF NOT EXISTS prazo_fatal VARCHAR(3) NOT NULL DEFAULT 'Não' AFTER status;

ALTER TABLE agenda
    ADD COLUMN IF NOT EXISTS observacoes TEXT NULL AFTER prazo_fatal;

ALTER TABLE agenda
    ADD COLUMN IF NOT EXISTS deletado TINYINT(1) NOT NULL DEFAULT 0 AFTER observacoes;

ALTER TABLE agenda
    ADD COLUMN IF NOT EXISTS criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP AFTER deletado;

ALTER TABLE agenda
    ADD COLUMN IF NOT EXISTS atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER criado_em;

CREATE INDEX IF NOT EXISTS idx_agenda_data_evento ON agenda (data_evento);
CREATE INDEX IF NOT EXISTS idx_agenda_deletado ON agenda (deletado);
CREATE INDEX IF NOT EXISTS idx_agenda_status ON agenda (status);
CREATE INDEX IF NOT EXISTS idx_agenda_tipo ON agenda (tipo_compromisso);
CREATE INDEX IF NOT EXISTS idx_agenda_prazo_fatal ON agenda (prazo_fatal);
