-- 2026_06_17_rendimentos_btg.sql
-- Categoriza como RENDIMENTO os creditos de rendimento da conta remunerada
-- (ex.: BTG "VALOR DE RENDIMENTO REMUNERADA") que ficaram como NORMAL e apareciam
-- como pendentes na conciliacao. Movimentos RENDIMENTO sao internos (juros da
-- aplicacao) e saem da lista de pendentes. Reversivel (trocar por NORMAL).

UPDATE tb_conciliacao_ofx_movimento
SET COM_NATUREZA = 'RENDIMENTO'
WHERE COM_NATUREZA = 'NORMAL'
  AND COALESCE(COM_CONCILIADO,'NAO') <> 'SIM'
  AND (COM_DESCRICAO LIKE '%RENDIMENTO REMUNERAD%'
       OR COM_DESCRICAO LIKE '%VALOR DE RENDIMENTO%');
