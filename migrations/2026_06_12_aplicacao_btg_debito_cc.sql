-- 2026_06_12_aplicacao_btg_debito_cc.sql
-- Espelho da migration de resgate: trata a APLICAÇÃO AUTOMÁTICA do BTG.
-- Quando entra dinheiro, o BTG aplica na conta remunerada gerando o par interno
-- "DÉBITO NA CONTA CORRENTE" (-) / "APLICAÇÃO CONTA REMUNERADA" (+), que soma zero.
-- O lado da aplicação (crédito) já é APLICACAO pelo regex; aqui marcamos o
-- "DÉBITO NA CONTA CORRENTE" (contrapartida) como APLICACAO quando o par existe,
-- para ele sair da lista de pendentes. Reversível (trocar APLICACAO por NORMAL).

UPDATE tb_conciliacao_ofx_movimento c
JOIN tb_conciliacao_ofx_movimento a
  ON a.COM_BANCO_FK = c.COM_BANCO_FK
 AND a.COM_CONTA_REF = c.COM_CONTA_REF
 AND a.COM_DATA_MOVIMENTO = c.COM_DATA_MOVIMENTO
 AND a.COM_VALOR > 0
 AND ABS(a.COM_VALOR - ABS(c.COM_VALOR)) < 0.01
 AND a.COM_DESCRICAO LIKE '%APLICA%CONTA REMUNERADA%'
SET c.COM_NATUREZA = 'APLICACAO'
WHERE c.COM_NATUREZA = 'NORMAL'
  AND c.COM_VALOR < 0
  AND c.COM_DESCRICAO LIKE '%D_BITO NA CONTA CORRENTE%';
