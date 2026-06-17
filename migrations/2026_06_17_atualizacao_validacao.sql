-- 2026_06_17_atualizacao_validacao.sql
-- Armazena as validações do checklist da tela Atualizações de forma COMPARTILHADA
-- (antes era localStorage, por navegador). Uma linha = item validado.

CREATE TABLE IF NOT EXISTS tb_atualizacao_validacao (
  AV_ID           INT AUTO_INCREMENT PRIMARY KEY,
  AV_ATUALIZACAO  VARCHAR(60)  NOT NULL,
  AV_ITEM         INT          NOT NULL,
  AV_USUARIO      VARCHAR(150) NULL,
  AV_VALIDADO_EM  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_av (AV_ATUALIZACAO, AV_ITEM)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
