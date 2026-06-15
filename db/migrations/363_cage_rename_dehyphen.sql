-- db/migrations/363_cage_rename_dehyphen.sql
--
-- What:  Rename the 8 cage SKUs: drop the hyphen from the suffix.
--        ALT-X → ALTX, DIV-X → DIVX, DOA-X → DOAX, EMB-X → EMBX,
--        MOO-X → MOOX, SPY-X → SPYX, STI-X → STIX, ZEP-X → ZEPX.
--
--        The cage detection was already moved to stocktake_scope='cage'
--        (mig 362 + prior work), so no functional suffix-detection logic
--        remains in the DB layer — only the display string changes.
--
--        Two live code paths in salle-de-controle.php (lines ~201, ~2546)
--        that constructed prefix.'-X' are fixed in the same commit as
--        this migration (changed to prefix.'X').
--
-- Tables updated:
--   1. ref_skus.sku_code          — 8 master rows (stocktake_scope='cage')
--   2. inv_fg_stocktake.sku       — 9 historical stocktake rows
--
-- Pre-flight snapshot:
--   data/snapshots/ref_skus-cage-rename-pre-mig363-20260615_150417.json
--   (8 rows, taken 2026-06-15 15:04:17)
--
-- Idempotency:
--   The audit INSERT is guarded by NOT EXISTS on comment='mig363_cage_rename_dehyphen'.
--   The UPDATEs filter on LIKE '%-X'; after first apply those rows match
--   '%X' (no hyphen), so re-running becomes a clean no-op.
--
-- Rollback:
--   UPDATE ref_skus rs
--     JOIN audit_row_revisions a ON a.target_table='ref_skus'
--       AND a.target_pk = rs.id
--       AND a.comment = 'mig363_cage_rename_dehyphen'
--    SET rs.sku_code = JSON_UNQUOTE(JSON_EXTRACT(a.before_json,'$.sku_code'))
--   WHERE 1;
--   UPDATE inv_fg_stocktake SET sku = REPLACE(sku,'X','-X')
--    WHERE sku IN ('ALTX','DIVX','DOAX','EMBX','MOOX','SPYX','STIX','ZEPX');
--   DELETE FROM audit_row_revisions WHERE comment='mig363_cage_rename_dehyphen';
--
-- Date   : 2026-06-15
-- Author : migration_363

-- ── STEP 1: Audit ref_skus before rename ────────────────────────────────────
INSERT INTO audit_row_revisions
  (user_id, username, target_table, target_pk, action, before_json, after_json, comment)
SELECT 0, 'migration_363', 'ref_skus', rs.id, 'update',
  JSON_OBJECT('sku_code', rs.sku_code),
  JSON_OBJECT('sku_code', REPLACE(rs.sku_code, '-X', 'X')),
  'mig363_cage_rename_dehyphen'
FROM ref_skus rs
WHERE rs.stocktake_scope = 'cage'
  AND rs.sku_code LIKE '%-X'
  AND NOT EXISTS (
    SELECT 1 FROM audit_row_revisions a
     WHERE a.target_table = 'ref_skus'
       AND a.target_pk    = rs.id
       AND a.comment      = 'mig363_cage_rename_dehyphen'
  );

-- ── STEP 2: Rename cage SKUs in ref_skus ────────────────────────────────────
UPDATE ref_skus
   SET sku_code = REPLACE(sku_code, '-X', 'X')
 WHERE stocktake_scope = 'cage'
   AND sku_code LIKE '%-X';

-- ── STEP 3: Rename denormalized sku strings in inv_fg_stocktake ─────────────
-- 9 historical stocktake rows carry the old '-X' suffix string verbatim.
-- row_hash is a STORED GENERATED column — it recomputes automatically on UPDATE.
UPDATE inv_fg_stocktake
   SET sku = REPLACE(sku, '-X', 'X')
 WHERE sku LIKE '%-X';
