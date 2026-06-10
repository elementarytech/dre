-- =============================================================
-- 2026-06-09 — Detecção de transferências internas e categorização
--              de movimentos no OFX (Briefing consolidado)
-- =============================================================
-- 1) Cadastro de documentos do grupo (CNPJs/CPFs que representam "casa")
-- 2) Colunas COM_NATUREZA e COM_DOCUMENTO_CONTRAPARTE em tb_conciliacao_ofx_movimento
-- 3) Tabela de pares cruzados de transferência interna
-- 4) Auto-populate dos CNPJs das empresas ativas
--
-- Aplicado em produção em: 09/06/2026
-- MySQL 8.x (REGEXP_REPLACE requer 8.0+)
-- =============================================================

-- 1) Cadastro de documentos do grupo
CREATE TABLE IF NOT EXISTS tb_grupo_documento (
    GDO_CODIGO_PK BIGINT NOT NULL AUTO_INCREMENT,
    GDO_TIPO ENUM('PJ','PF') NOT NULL,
    GDO_DOCUMENTO VARCHAR(20) NOT NULL,        -- só dígitos, sem formatação
    GDO_NOME VARCHAR(200) NOT NULL,
    GDO_STATUS ENUM('ATIVO','INATIVO') NOT NULL DEFAULT 'ATIVO',
    GDO_OBSERVACAO TEXT NULL,
    GDO_DATA_CADASTRO DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    GDO_USUARIO VARCHAR(120) NULL,
    PRIMARY KEY (GDO_CODIGO_PK),
    UNIQUE KEY uk_gdo_doc (GDO_DOCUMENTO),
    INDEX idx_gdo_status (GDO_STATUS)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2) Colunas novas em movimentos OFX para classificar natureza
ALTER TABLE tb_conciliacao_ofx_movimento
    ADD COLUMN COM_NATUREZA ENUM(
        'NORMAL',
        'TRANSFERENCIA_INTERNA',
        'APLICACAO',
        'RENDIMENTO',
        'TARIFA'
    ) NOT NULL DEFAULT 'NORMAL' AFTER COM_TIPO,
    ADD COLUMN COM_DOCUMENTO_CONTRAPARTE VARCHAR(20) NULL AFTER COM_NATUREZA,
    ADD INDEX idx_com_natureza (COM_NATUREZA);

-- 3) Tabela de pares cruzados de transferência interna
CREATE TABLE IF NOT EXISTS tb_transferencia_interna (
    TFI_CODIGO_PK BIGINT NOT NULL AUTO_INCREMENT,
    TFI_MOV_ORIGEM_FK BIGINT NOT NULL,          -- débito (saída)
    TFI_MOV_DESTINO_FK BIGINT NOT NULL,         -- crédito (entrada)
    TFI_VALOR DECIMAL(15,2) NOT NULL,
    TFI_DATA_DETECCAO DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    TFI_MODO_DETECCAO ENUM('AUTOMATICO','MANUAL') NOT NULL DEFAULT 'AUTOMATICO',
    TFI_STATUS ENUM('ATIVO','CANCELADO') NOT NULL DEFAULT 'ATIVO',
    TFI_USUARIO VARCHAR(120) NULL,
    PRIMARY KEY (TFI_CODIGO_PK),
    UNIQUE KEY uk_tfi_origem (TFI_MOV_ORIGEM_FK, TFI_STATUS),
    UNIQUE KEY uk_tfi_destino (TFI_MOV_DESTINO_FK, TFI_STATUS),
    INDEX idx_tfi_status (TFI_STATUS)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 4) Auto-populate: copia CNPJs das empresas ativas pro grupo
INSERT INTO tb_grupo_documento (GDO_TIPO, GDO_DOCUMENTO, GDO_NOME, GDO_USUARIO)
SELECT 'PJ',
       REGEXP_REPLACE(EMP_CNPJ, '[^0-9]', ''),
       COALESCE(NULLIF(EMP_NOME_FANTASIA, ''), EMP_RAZAO_SOCIAL),
       'auto-populate (migration)'
FROM tb_empresa
WHERE COALESCE(EMP_STATUS, 'ATIVO') = 'ATIVO'
  AND EMP_CNPJ IS NOT NULL
  AND LENGTH(REGEXP_REPLACE(EMP_CNPJ, '[^0-9]', '')) IN (11, 14)
ON DUPLICATE KEY UPDATE GDO_NOME = VALUES(GDO_NOME);
