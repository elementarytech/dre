-- Vínculo bidirecional entre lançamentos e movimento OFX que os quitou.
-- Permite: (a) saber qual movimento OFX pagou/recebeu uma conta;
--          (b) blindar contra duplo vínculo do mesmo movimento;
--          (c) consultas reversas para auditoria.

ALTER TABLE tb_contas_pagar
    ADD COLUMN CPG_OFX_MOVIMENTO_FK BIGINT NULL DEFAULT NULL AFTER CPG_BANCO_PAGAMENTO_FK,
    ADD INDEX idx_cpg_ofx_mov (CPG_OFX_MOVIMENTO_FK);

ALTER TABLE tb_contas_receber
    ADD COLUMN CRE_OFX_MOVIMENTO_FK BIGINT NULL DEFAULT NULL AFTER CRE_BANCO_FK,
    ADD INDEX idx_cre_ofx_mov (CRE_OFX_MOVIMENTO_FK);

-- Índice auxiliar para o match acelerado por documento/FITID
ALTER TABLE tb_conciliacao_ofx_movimento
    ADD INDEX idx_com_documento (COM_DOCUMENTO);
