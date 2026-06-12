-- 2026_06_12_resgate_btg_aplicacao.sql
-- Recategoriza movimentos OFX de RESGATE AUTOMÁTICO DE APLICAÇÃO (BTG conta
-- remunerada) já importados como NORMAL, para que saiam da lista de pendentes
-- de conciliação (igual a importações novas, que já passam pela nova lógica).
--
-- Contexto: cada gasto no BTG gera 3 movimentos — a compra/PIX real + um par
-- interno "CRÉDITO NA CONTA CORRENTE" (+) / "RESGATE CONTA REMUNERADA" (-) de
-- mesmo valor/dia, que soma zero (o BTG resgata da aplicação p/ cobrir o gasto).
-- Esse par NÃO é receita/despesa e não deve ser conciliado contra lançamentos.
--
-- Reversível: para desfazer, troque 'APLICACAO' por 'NORMAL' nos mesmos filtros.

-- 1) Lado do resgate (débito) — identificação direta pela descrição.
UPDATE tb_conciliacao_ofx_movimento
SET COM_NATUREZA = 'APLICACAO'
WHERE COM_NATUREZA = 'NORMAL'
  AND (COM_DESCRICAO LIKE '%RESGATE CONTA REMUNERADA%'
       OR COM_DESCRICAO LIKE '%CONTA REMUNERADA%');

-- 2) Lado do crédito (contrapartida) — só quando existe o resgate par
--    (mesmo valor/dia/conta). Evita classificar errado crédito legítimo.
UPDATE tb_conciliacao_ofx_movimento c
JOIN tb_conciliacao_ofx_movimento r
  ON r.COM_BANCO_FK = c.COM_BANCO_FK
 AND r.COM_CONTA_REF = c.COM_CONTA_REF
 AND r.COM_DATA_MOVIMENTO = c.COM_DATA_MOVIMENTO
 AND r.COM_VALOR < 0
 AND ABS(ABS(r.COM_VALOR) - c.COM_VALOR) < 0.01
 AND r.COM_DESCRICAO LIKE '%RESGATE CONTA REMUNERADA%'
SET c.COM_NATUREZA = 'APLICACAO'
WHERE c.COM_NATUREZA = 'NORMAL'
  AND c.COM_VALOR > 0
  AND c.COM_DESCRICAO LIKE '%CR_DITO NA CONTA CORRENTE%';
