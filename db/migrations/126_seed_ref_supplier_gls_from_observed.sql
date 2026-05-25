-- db/migrations/126_seed_ref_supplier_gls_from_observed.sql
-- What:   One-time backfill — seed ref_supplier_gls from observed inv_deliveries history.
-- Why:    ref_supplier_gls was created empty (migration 122) and is the data source for the
--         Salle des Machines Fournisseurs fiche GL-footprint panel. Without this seed the
--         panel shows nothing for all existing suppliers. The reconciler was designed to
--         populate this table going forward; this migration back-fills from the full history.
-- Source: inv_deliveries (status IN ('Active','Consumed')) → ref_mi.gl_account (GL code)
--         → ref_mi_categories.name (human label). Pending rows excluded: qty/price are
--         not confirmed and the supplier_fk may still be unresolved.
-- Exclusions:
--   - 8 inv_deliveries rows with NULL supplier_fk: intentionally excluded (unresolved
--     supplier; these are legacy rows from before strict ingest was enforced).
--   - Rows with NULL ingredient_fk: 0 rows in current data — guard kept for safety.
--   - Rows where ref_mi.gl_account IS NULL (services/immobilisation lines without a GL).
--   - IMM-* and non-COGS GL codes (e.g. 6100 Maintenance, 6631/6634 Taproom, 4500 R&D,
--     1302 Cautions, 4401 Sales) WILL appear and are correct: they represent the full
--     observed footprint. An admin pass via the Salle des Machines fiche is expected to
--     toggle is_excluded_from_cogs_footprint=1 for non-COGS lines (e.g. OBI 6631/6634).
-- is_primary: set to 1 for the GL with the highest observed_delivery_count per supplier.
--   Ties broken by the numerically lowest gl_account (deterministic).
-- IDEMPOTENCY: ON DUPLICATE KEY UPDATE refreshes observed_delivery_count and gl_label,
--   leaves all other columns (is_primary, is_excluded_from_cogs_footprint, derived_from,
--   effective_from, effective_until) unchanged — safe to re-run after new deliveries land.
-- Rollback: DELETE FROM ref_supplier_gls WHERE derived_from = 'observed';
--           (manual rows with derived_from='manual' are unaffected)

-- ── Step 1: INSERT observed (supplier, GL) pairs ──────────────────────────────────────────

INSERT INTO `ref_supplier_gls`
  (`supplier_fk`, `gl_account`, `gl_label`, `is_primary`, `derived_from`, `observed_delivery_count`, `effective_from`)
SELECT
  d.supplier_fk,
  m.gl_account,
  ANY_VALUE(cat.name)     AS gl_label,
  0                       AS is_primary,
  'observed'              AS derived_from,
  COUNT(*)                AS observed_delivery_count,
  CURDATE()               AS effective_from
FROM `inv_deliveries` d
JOIN `ref_mi` m
  ON m.id = d.ingredient_fk
LEFT JOIN `ref_mi_categories` cat
  ON cat.id = m.category_id
WHERE d.status IN ('Active', 'Consumed')
  AND d.supplier_fk    IS NOT NULL
  AND d.ingredient_fk  IS NOT NULL
  AND m.gl_account     IS NOT NULL
GROUP BY
  d.supplier_fk,
  m.gl_account
ON DUPLICATE KEY UPDATE
  `observed_delivery_count` = VALUES(`observed_delivery_count`),
  `gl_label`                = VALUES(`gl_label`);

-- ── Step 2: mark is_primary = 1 for the dominant GL per supplier ──────────────────────────
-- For each supplier: the GL with the highest observed_delivery_count wins.
-- Tie-break: lowest gl_account string (alphabetical — deterministic).
-- Uses a derived table that ranks GLs per supplier, then UPDATEs matching rows.
-- No standalone SELECT — UPDATE with subquery only (migrate.php PDO exec() constraint).

UPDATE `ref_supplier_gls` rsg
JOIN (
  SELECT
    supplier_fk,
    gl_account
  FROM (
    SELECT
      supplier_fk,
      gl_account,
      observed_delivery_count,
      ROW_NUMBER() OVER (
        PARTITION BY supplier_fk
        ORDER BY observed_delivery_count DESC, gl_account ASC
      ) AS rn
    FROM `ref_supplier_gls`
    WHERE derived_from = 'observed'
      AND effective_until IS NULL
  ) ranked
  WHERE rn = 1
) primary_gl
  ON primary_gl.supplier_fk = rsg.supplier_fk
  AND primary_gl.gl_account = rsg.gl_account
SET rsg.is_primary = 1
WHERE rsg.derived_from = 'observed'
  AND rsg.effective_until IS NULL;
