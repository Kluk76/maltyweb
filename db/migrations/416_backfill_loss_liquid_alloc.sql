-- db/migrations/416_backfill_loss_liquid_alloc.sql
--
-- What: Backfill bd_packaging_v2.loss_liquid_other_units_alloc for all
--       existing rows (is_tombstoned=0, reuses_packaging_id_fk IS NULL).
--
-- Rules:
--   keg/cuv : _alloc = loss_liquid_other_units (identity; NULL stays NULL)
--   bot/can : allocate Σ(group.loss_liquid_other_units) proportional to
--             each row's gross-filled HL = prod_total_units / units_per_pack
--             × hl_per_unit. Equal-split fallback when Σ(gross HL) = 0.
--   singletons (group count = 1): trivially correct via either formula.
--
-- Group key:
--   recipe_id_fk (NULL-safe), batch, event_date, format_family
--   where format_family = 'bot' | 'can' (keg/cuv excluded from pooling).
--
-- Idempotent: WHERE clause scopes to loss_liquid_other_units_alloc IS NULL,
--   so re-running is a no-op on already-backfilled rows.
--
-- Audit: INSERT into audit_row_revisions BEFORE the UPDATE (per mig-200
--   pattern). Captures before_json (NULL) → after_json (computed value).
--
-- PDO-safe: no standalone SELECT statements. Only INSERT/UPDATE/SET.
--
-- Rollback:
--   UPDATE bd_packaging_v2 SET loss_liquid_other_units_alloc = NULL
--    WHERE id IN (
--      SELECT target_pk FROM audit_row_revisions
--       WHERE target_table = 'bd_packaging_v2'
--         AND comment = 'backfill_loss_liquid_alloc_mig416'
--    );
--   DELETE FROM audit_row_revisions
--    WHERE target_table = 'bd_packaging_v2'
--      AND comment = 'backfill_loss_liquid_alloc_mig416';

-- ============================================================================
-- STEP 1: Audit INSERT — capture before/after for every row about to change.
-- ============================================================================
-- We INSERT audit rows first (fail-safe paper trail even if UPDATE fails).
-- Scoped to rows that will actually be written: is_tombstoned=0,
-- reuses_packaging_id_fk IS NULL, loss_liquid_other_units_alloc IS NULL.
-- Rows where loss_liquid_other_units IS NULL get _alloc=NULL → before=NULL,
-- after=NULL → no meaningful audit row; we still INSERT for completeness
-- (same pattern as mig-200) but the after_json will show NULL.
-- ============================================================================

INSERT INTO audit_row_revisions
  (user_id, username, target_table, target_pk, action, before_json, after_json, comment)
WITH base AS (
  SELECT
    p.id,
    p.run_type,
    p.recipe_id_fk,
    p.event_date,
    p.loss_liquid_other_units,
    p.prod_total_units,
    s.units_per_pack,
    s.hl_per_unit,
    -- format family: bot | can | NULL (keg/cuv)
    CASE
      WHEN p.run_type = 'bot'                     THEN 'bot'
      WHEN p.run_type IN ('can', 'can33')         THEN 'can'
      ELSE NULL
    END AS fmt_family,
    -- batch: COALESCE of neb_batch and contract_batch (empty-string sentinel)
    COALESCE(NULLIF(p.neb_batch, ''), NULLIF(p.contract_batch, '')) AS batch
  FROM bd_packaging_v2 p
  LEFT JOIN ref_skus s ON s.id = p.sku_id_fk
  WHERE p.is_tombstoned = 0
    AND p.reuses_packaging_id_fk IS NULL
    AND p.loss_liquid_other_units_alloc IS NULL
),
gross AS (
  SELECT
    b.*,
    -- gross filled HL for this row (0 when meta missing)
    CASE
      WHEN b.fmt_family IS NULL THEN 0  -- keg/cuv: not used in split
      WHEN b.units_per_pack IS NULL OR b.units_per_pack <= 0 OR b.hl_per_unit IS NULL THEN 0
      ELSE (b.prod_total_units / b.units_per_pack) * b.hl_per_unit
    END AS row_gross_hl
  FROM base b
),
grp AS (
  SELECT
    g.*,
    -- group sums for bot/can rows only
    CASE
      WHEN g.fmt_family IS NULL THEN NULL
      ELSE SUM(COALESCE(g.loss_liquid_other_units, 0))
             OVER (PARTITION BY g.recipe_id_fk, g.batch, g.event_date, g.fmt_family)
    END AS grp_loss_sum,
    CASE
      WHEN g.fmt_family IS NULL THEN NULL
      ELSE SUM(g.row_gross_hl)
             OVER (PARTITION BY g.recipe_id_fk, g.batch, g.event_date, g.fmt_family)
    END AS grp_gross_hl_sum,
    CASE
      WHEN g.fmt_family IS NULL THEN NULL
      ELSE COUNT(*)
             OVER (PARTITION BY g.recipe_id_fk, g.batch, g.event_date, g.fmt_family)
    END AS grp_count
  FROM gross g
),
computed AS (
  SELECT
    g.id,
    g.loss_liquid_other_units,
    -- _alloc computation
    -- When the group has no loss at all (grp_loss_sum = 0): identity passthrough
    --   (NULL raw stays NULL; 0 stays 0) — nothing to distribute.
    -- When the group has loss > 0: EVERY row in the group receives its proportional
    --   share, even rows whose own raw loss is NULL. This preserves the invariant
    --   Σ(_alloc) == Σ(raw) within each group.
    CASE
      -- keg/cuv: identity passthrough (NULL stays NULL)
      WHEN g.fmt_family IS NULL THEN g.loss_liquid_other_units
      -- bot/can, no group loss: identity (NULL stays NULL, 0 stays 0)
      WHEN COALESCE(g.grp_loss_sum, 0) = 0 THEN g.loss_liquid_other_units
      -- bot/can, group has loss — proportional split by gross HL
      WHEN g.grp_gross_hl_sum > 0
        THEN ROUND(g.grp_loss_sum * (g.row_gross_hl / g.grp_gross_hl_sum), 3)
      -- equal-split fallback (all rows have zero gross HL)
      ELSE ROUND(g.grp_loss_sum / g.grp_count, 3)
    END AS alloc_val
  FROM grp g
)
SELECT
  0                                                              AS user_id,
  'migration_416'                                                AS username,
  'bd_packaging_v2'                                              AS target_table,
  c.id                                                           AS target_pk,
  'update'                                                       AS action,
  JSON_OBJECT('loss_liquid_other_units_alloc', NULL)             AS before_json,
  JSON_OBJECT('loss_liquid_other_units_alloc', c.alloc_val)      AS after_json,
  'backfill_loss_liquid_alloc_mig416'                            AS comment
FROM computed c;

-- ============================================================================
-- STEP 2: Backfill UPDATE.
-- ============================================================================
-- Uses the same CTE logic as the audit INSERT above.
-- Single-pass UPDATE covering keg/cuv (identity) and bot/can (group-split).
-- Idempotent: WHERE loss_liquid_other_units_alloc IS NULL ensures re-run = no-op.
-- ============================================================================

UPDATE bd_packaging_v2 p
LEFT JOIN ref_skus s ON s.id = p.sku_id_fk
JOIN (
  WITH base2 AS (
    SELECT
      p2.id,
      p2.run_type,
      p2.recipe_id_fk,
      p2.event_date,
      p2.loss_liquid_other_units,
      p2.prod_total_units,
      s2.units_per_pack,
      s2.hl_per_unit,
      CASE
        WHEN p2.run_type = 'bot'                   THEN 'bot'
        WHEN p2.run_type IN ('can', 'can33')       THEN 'can'
        ELSE NULL
      END AS fmt_family,
      COALESCE(NULLIF(p2.neb_batch, ''), NULLIF(p2.contract_batch, '')) AS batch
    FROM bd_packaging_v2 p2
    LEFT JOIN ref_skus s2 ON s2.id = p2.sku_id_fk
    WHERE p2.is_tombstoned = 0
      AND p2.reuses_packaging_id_fk IS NULL
      AND p2.loss_liquid_other_units_alloc IS NULL
  ),
  gross2 AS (
    SELECT
      b.*,
      CASE
        WHEN b.fmt_family IS NULL THEN 0
        WHEN b.units_per_pack IS NULL OR b.units_per_pack <= 0 OR b.hl_per_unit IS NULL THEN 0
        ELSE (b.prod_total_units / b.units_per_pack) * b.hl_per_unit
      END AS row_gross_hl
    FROM base2 b
  ),
  grp2 AS (
    SELECT
      g.*,
      CASE
        WHEN g.fmt_family IS NULL THEN NULL
        ELSE SUM(COALESCE(g.loss_liquid_other_units, 0))
               OVER (PARTITION BY g.recipe_id_fk, g.batch, g.event_date, g.fmt_family)
      END AS grp_loss_sum,
      CASE
        WHEN g.fmt_family IS NULL THEN NULL
        ELSE SUM(g.row_gross_hl)
               OVER (PARTITION BY g.recipe_id_fk, g.batch, g.event_date, g.fmt_family)
      END AS grp_gross_hl_sum,
      CASE
        WHEN g.fmt_family IS NULL THEN NULL
        ELSE COUNT(*)
               OVER (PARTITION BY g.recipe_id_fk, g.batch, g.event_date, g.fmt_family)
      END AS grp_count
    FROM gross2 g
  )
  SELECT
    g.id,
    CASE
      -- keg/cuv: identity passthrough
      WHEN g.fmt_family IS NULL THEN g.loss_liquid_other_units
      -- bot/can, no group loss: identity (NULL stays NULL)
      WHEN COALESCE(g.grp_loss_sum, 0) = 0 THEN g.loss_liquid_other_units
      -- bot/can, group has loss — proportional split
      WHEN g.grp_gross_hl_sum > 0
        THEN ROUND(g.grp_loss_sum * (g.row_gross_hl / g.grp_gross_hl_sum), 3)
      -- equal-split fallback
      ELSE ROUND(g.grp_loss_sum / g.grp_count, 3)
    END AS alloc_val
  FROM grp2 g
) calc ON calc.id = p.id
SET p.loss_liquid_other_units_alloc = calc.alloc_val
WHERE p.loss_liquid_other_units_alloc IS NULL
  AND p.is_tombstoned = 0
  AND p.reuses_packaging_id_fk IS NULL;

-- end migration 416
