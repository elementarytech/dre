-- =============================================================
-- 2026-06-09 — Suporte ao estado PARCIAL na conciliação OFX
-- =============================================================
-- Expande a coluna COM_CONCILIADO para acomodar 'PARCIAL' além de
-- 'SIM' e 'NAO'. O sistema passa a calcular automaticamente o estado
-- baseado na soma dos vínculos vs valor do movimento:
--   - 'NAO'     → sem vínculos ativos
--   - 'PARCIAL' → tem vínculos, mas soma < valor do movimento
--   - 'SIM'     → tem vínculos, soma cobre o valor (tolerância 0,5 ct)
--
-- A função PHP recalcularStatusMovimento() (em conciliacao_helpers.php)
-- toma essas decisões. A ação vincular_lancamentos_em_lote agora aceita
-- alocações parciais (antes obrigava soma == valor).
--
-- Aplicado em produção em: 09/06/2026
-- =============================================================

ALTER TABLE tb_conciliacao_ofx_movimento
  MODIFY COLUMN COM_CONCILIADO VARCHAR(10) NOT NULL DEFAULT 'NAO';
