-- Fase 2.4 - Melhorias no módulo Advogados
-- Execute este script no banco sgl_advocacia antes de testar o módulo Advogados.

ALTER TABLE advogados
    ADD COLUMN IF NOT EXISTS cpf VARCHAR(20) NULL AFTER nome,
    ADD COLUMN IF NOT EXISTS oab_uf CHAR(2) NULL AFTER oab,
    ADD COLUMN IF NOT EXISTS deletado TINYINT(1) NOT NULL DEFAULT 0 AFTER observacoes;

UPDATE advogados
SET data_cadastro = COALESCE(data_cadastro, DATE(criado_em), CURDATE())
WHERE data_cadastro IS NULL;

UPDATE advogados
SET deletado = 1
WHERE status = 'Excluído';

CREATE INDEX IF NOT EXISTS idx_adv_deletado ON advogados (deletado);
CREATE INDEX IF NOT EXISTS idx_adv_cpf ON advogados (cpf);
CREATE INDEX IF NOT EXISTS idx_adv_oab_uf ON advogados (oab, oab_uf);
