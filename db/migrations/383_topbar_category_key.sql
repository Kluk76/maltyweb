-- Migration 383: add category_key and category_sort to ref_pages for topbar consolidation
-- Option B: free-presentation enum, no FK, no schema_meta row (ref_pages already has one).
-- The domain column is left 100% untouched (load-bearing for visite-guidée + reglages editor).

ALTER TABLE ref_pages
  ADD COLUMN category_key  VARCHAR(32)  NULL          AFTER domain,
  ADD COLUMN category_sort SMALLINT     NOT NULL DEFAULT 0 AFTER category_key;

-- ── NULL (standalone or brand target) ───────────────────────────────────────
-- mon-tableau: brand/home target — category_key NULL, not a standalone button
-- zeppelin:    standalone primary button
-- saisies:     standalone primary button
UPDATE ref_pages SET category_key = NULL, category_sort = 0
WHERE page_key IN ('mon-tableau', 'zeppelin', 'saisies');

-- ── production ───────────────────────────────────────────────────────────────
UPDATE ref_pages SET category_key = 'production', category_sort = 10 WHERE page_key = 'wort';
UPDATE ref_pages SET category_key = 'production', category_sort = 20 WHERE page_key = 'fermentation';
UPDATE ref_pages SET category_key = 'production', category_sort = 30 WHERE page_key = 'packaging';
UPDATE ref_pages SET category_key = 'production', category_sort = 40 WHERE page_key = 'planning';

-- ── logistique ───────────────────────────────────────────────────────────────
UPDATE ref_pages SET category_key = 'logistique', category_sort = 10 WHERE page_key = 'approvisionnement';
UPDATE ref_pages SET category_key = 'logistique', category_sort = 20 WHERE page_key = 'email-orders';
UPDATE ref_pages SET category_key = 'logistique', category_sort = 30 WHERE page_key = 'expeditions';
UPDATE ref_pages SET category_key = 'logistique', category_sort = 40 WHERE page_key = 'tap-shop';
UPDATE ref_pages SET category_key = 'logistique', category_sort = 50 WHERE page_key = 'warehouse';
UPDATE ref_pages SET category_key = 'logistique', category_sort = 60 WHERE page_key = 'rm-comparison';
UPDATE ref_pages SET category_key = 'logistique', category_sort = 70 WHERE page_key = 'triage';

-- ── qualite ──────────────────────────────────────────────────────────────────
UPDATE ref_pages SET category_key = 'qualite', category_sort = 10 WHERE page_key = 'qa';

-- ── finance ──────────────────────────────────────────────────────────────────
UPDATE ref_pages SET category_key = 'finance', category_sort = 10 WHERE page_key = 'financier';
UPDATE ref_pages SET category_key = 'finance', category_sort = 20 WHERE page_key = 'charges-bc';
UPDATE ref_pages SET category_key = 'finance', category_sort = 30 WHERE page_key = 'bom-review';

-- ── pilotage ─────────────────────────────────────────────────────────────────
UPDATE ref_pages SET category_key = 'pilotage', category_sort = 10 WHERE page_key = 'sb-board';
UPDATE ref_pages SET category_key = 'pilotage', category_sort = 20 WHERE page_key = 'sb-guerre';
UPDATE ref_pages SET category_key = 'pilotage', category_sort = 30 WHERE page_key = 'journal-saisies';

-- ── systeme ──────────────────────────────────────────────────────────────────
UPDATE ref_pages SET category_key = 'systeme', category_sort = 10 WHERE page_key = 'settings';
UPDATE ref_pages SET category_key = 'systeme', category_sort = 20 WHERE page_key = 'ingest';
UPDATE ref_pages SET category_key = 'systeme', category_sort = 30 WHERE page_key = 'db-browser';

-- Inactive pages get NULL category_key (no nav assignment needed)
-- (invoice-validate, sku-costs — already NULL by default, no explicit UPDATE needed)
