-- Migration 318 — retire sku-costs standalone page
-- Superseded by the Financier "Coût par SKU" panel (fin-panel-sku).
-- The page file is preserved in git history; is_active=0 removes it from
-- the topbar / ref_pages-driven navigation. Reversible: set is_active=1.
-- sku-cost-detail.php (drill-down) is NOT retired — it stays linked from the panel.

UPDATE ref_pages
   SET is_active = 0
 WHERE page_key = 'sku-costs';
