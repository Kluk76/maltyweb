-- db/migrations/182_ref_recipes_qc_thresholds.sql
-- What: Per-recipe QC threshold infrastructure for the racking form.
--       Three parts:
--         (A) ALTER ref_recipes — add co2_target / co2_tolerance (recipe spec, operator-tunable)
--             and 4 nullable volume-override cols (operator can pin warn/outlier bands per recipe).
--         (B) Correlated UPDATE — seed co2_target = ROUND(AVG(bbt_co2), 2) from clean racking
--             history (n >= 3); co2_tolerance = 0.75 for every seeded recipe.
--         (C) INSERT commissioning_settings — global fallback bands for o2, pressure,
--             co2, and volume. These are authoritative for o2/pressure (always used) and
--             fallback for co2/volume when per-recipe data is absent.
--         (D) CREATE OR REPLACE VIEW v_recipe_vol_band — one row per recipe with n >= 3
--             non-tombstoned, non-zero racked_vol_hl history entries (excluding known-bad
--             NULL-destination Diversion rows 60/66/278). Bounds are σ-derived and clamped
--             to [25, MAX(ref_bbt.capacity_hl)] = [25, 240].
--
-- Why:  Replace hardcoded thresholds in bd_qc_flag() / bd_soft_warnings() with:
--         - recipe-specific volume bands from observed history (v_recipe_vol_band)
--         - recipe-specific CO₂ targets stored on ref_recipes
--         - global o2/pressure/co2-fallback/vol-fallback from commissioning_settings
--         Phase 2 (form wiring) will consume qc_thresholds_for_recipes() in app/qc-thresholds.php.
--
-- Risk: LOW.
--       (A) ADD COLUMN NULL ALGORITHM=INSTANT — no rewrite, no lock.
--       (B) Correlated UPDATE on ref_recipes (≤80 rows) — fast, idempotent.
--           Second run only overwrites already-seeded values (no harm).
--       (C) INSERT ... SELECT ... WHERE NOT EXISTS on commissioning_settings — idempotent
--           (no UNIQUE on (section,key_name); the NOT EXISTS guard is the idempotency mechanism).
--       (D) CREATE OR REPLACE VIEW — no lock, no data.
--       ref_recipes is classified `reference / allowed` in schema_meta — corrections allowed.
--       commissioning_settings is classified `config / allowed` — writes allowed.
--       No new schema_meta rows needed (we ALTER existing, not ADD new tables).
--
-- Rollback:
--   ALTER TABLE ref_recipes
--     DROP COLUMN racked_vol_outlier_hi,
--     DROP COLUMN racked_vol_outlier_lo,
--     DROP COLUMN racked_vol_warn_hi,
--     DROP COLUMN racked_vol_warn_lo,
--     DROP COLUMN co2_tolerance,
--     DROP COLUMN co2_target;
--   DELETE FROM commissioning_settings WHERE key_name LIKE 'qc_%';
--   DROP VIEW IF EXISTS v_recipe_vol_band;

-- ── A. Add columns to ref_recipes (INSTANT: nullable, appended at end) ─────────

ALTER TABLE ref_recipes
  ADD COLUMN `co2_target`           DECIMAL(5,2) NULL
    COMMENT 'Per-recipe CO₂ target (g/L). Seeded from historical mean (n>=3). NULL = no history → global fallback. Operator tunes.',
  ADD COLUMN `co2_tolerance`        DECIMAL(5,2) NULL
    COMMENT 'CO₂ tolerance band half-width (g/L). Warn = [target±tol], Outlier = [target±2×tol]. Default 0.75. NULL when no target.',
  ADD COLUMN `racked_vol_warn_lo`   DECIMAL(6,2) NULL
    COMMENT 'Operator override: lower warn bound for racked_vol_hl (HL). NULL = use v_recipe_vol_band or global fallback.',
  ADD COLUMN `racked_vol_warn_hi`   DECIMAL(6,2) NULL
    COMMENT 'Operator override: upper warn bound for racked_vol_hl (HL). NULL = use v_recipe_vol_band or global fallback.',
  ADD COLUMN `racked_vol_outlier_lo` DECIMAL(6,2) NULL
    COMMENT 'Operator override: lower outlier bound for racked_vol_hl (HL). NULL = use v_recipe_vol_band or global fallback.',
  ADD COLUMN `racked_vol_outlier_hi` DECIMAL(6,2) NULL
    COMMENT 'Operator override: upper outlier bound for racked_vol_hl (HL). NULL = use v_recipe_vol_band or global fallback.',
  ALGORITHM=INSTANT;

-- ── B. Seed co2_target / co2_tolerance from clean racking history ───────────────
-- Correlated subquery: for each recipe, average its racking history (tombstoned=0,
-- bbt_co2 IS NOT NULL). Only updates when the history count >= 3 (n<3 → leave NULL).
-- co2_tolerance seeded to 0.75 for every recipe that gets a target.
-- ONLY_FULL_GROUP_BY-safe: all columns in subquery are aggregated or group-key.

UPDATE ref_recipes r
   SET r.co2_target = (
         SELECT ROUND(AVG(rack.bbt_co2), 2)
           FROM bd_racking_v2 rack
          WHERE (rack.neb_recipe_id_fk = r.id OR rack.contract_recipe_id_fk = r.id)
            AND rack.is_tombstoned = 0
            AND rack.bbt_co2 IS NOT NULL
         HAVING COUNT(*) >= 3
       ),
       r.co2_tolerance = CASE
         WHEN (
           SELECT COUNT(*)
             FROM bd_racking_v2 rack
            WHERE (rack.neb_recipe_id_fk = r.id OR rack.contract_recipe_id_fk = r.id)
              AND rack.is_tombstoned = 0
              AND rack.bbt_co2 IS NOT NULL
         ) >= 3 THEN 0.75
         ELSE NULL
       END;

-- ── C. Seed global QC bands in commissioning_settings ───────────────────────────
-- Keys use a consistent qc_ prefix; section = 'qc_thresholds'.
-- All four band types: o2, pressure, co2_fallback, vol_fallback.
-- Each band has 4 keys: _warn_lo, _warn_hi, _outlier_lo, _outlier_hi.
-- Seed values match current hardcoded values in bd_qc_flag().
-- commissioning_settings has no UNIQUE on (section, key_name), so idempotency is
-- achieved with INSERT ... SELECT ... WHERE NOT EXISTS: each candidate row is inserted
-- only if no (section, key_name) match already exists. Re-running is a safe no-op.

INSERT INTO commissioning_settings
  (section, key_name, label_fr, description_fr, value_num, unit_fr, default_num, is_active, updated_by)
SELECT
  v.section, v.key_name, v.label_fr, v.description_fr,
  v.value_num, v.unit_fr, v.default_num, 1, 'migration_182'
FROM (
  -- O₂ global band (ppb) — always used (no per-recipe override path)
  SELECT 'qc_thresholds' section, 'qc_o2_warn_lo'       key_name, 'O₂ — seuil avertissement bas'     label_fr, 'Valeur en dessous de laquelle O₂ est normal (ppb). Typiquement 0.'                description_fr, 0.00   value_num, 'ppb'  unit_fr, 0.00   default_num
  UNION ALL
  SELECT 'qc_thresholds', 'qc_o2_warn_hi',      'O₂ — seuil avertissement haut',    'Valeur en dessous de laquelle O₂ est normal (ppb). Seuil warn=50.',                       50.00,  'ppb',  50.00
  UNION ALL
  SELECT 'qc_thresholds', 'qc_o2_outlier_lo',   'O₂ — seuil outlier bas',           'Valeur en dessous de laquelle O₂ est outlier (ppb). Typiquement 0.',                       0.00,  'ppb',   0.00
  UNION ALL
  SELECT 'qc_thresholds', 'qc_o2_outlier_hi',   'O₂ — seuil outlier haut',          'Valeur au-dessus de laquelle O₂ est outlier (ppb). Seuil outlier=200.',                  200.00,  'ppb', 200.00
  -- Pressure global band (bar) — always used
  UNION ALL
  SELECT 'qc_thresholds', 'qc_pressure_warn_lo',   'Pression BBT — avertissement bas',  'Pression BBT en dessous de laquelle un avertissement est émis (bar).',                  0.80,  'bar',   0.80
  UNION ALL
  SELECT 'qc_thresholds', 'qc_pressure_warn_hi',   'Pression BBT — avertissement haut', 'Pression BBT au-dessus de laquelle un avertissement est émis (bar).',                  2.50,  'bar',   2.50
  UNION ALL
  SELECT 'qc_thresholds', 'qc_pressure_outlier_lo','Pression BBT — outlier bas',         'Pression BBT en dessous de laquelle la valeur est considérée outlier (bar).',          0.00,  'bar',   0.00
  UNION ALL
  SELECT 'qc_thresholds', 'qc_pressure_outlier_hi','Pression BBT — outlier haut',        'Pression BBT au-dessus de laquelle la valeur est considérée outlier (bar).',           3.50,  'bar',   3.50
  -- CO₂ global fallback band (g/L) — used when ref_recipes.co2_target IS NULL
  UNION ALL
  SELECT 'qc_thresholds', 'qc_co2_warn_lo',    'CO₂ — avertissement bas (fallback global)',  'CO₂ en dessous du seuil avertissement (g/L). Fallback quand co2_target absent.',   3.50,  'g/L',   3.50
  UNION ALL
  SELECT 'qc_thresholds', 'qc_co2_warn_hi',    'CO₂ — avertissement haut (fallback global)', 'CO₂ au-dessus du seuil avertissement (g/L). Fallback quand co2_target absent.',   5.00,  'g/L',   5.00
  UNION ALL
  SELECT 'qc_thresholds', 'qc_co2_outlier_lo', 'CO₂ — outlier bas (fallback global)',        'CO₂ en dessous du seuil outlier (g/L). Fallback quand co2_target absent.',         2.50,  'g/L',   2.50
  UNION ALL
  SELECT 'qc_thresholds', 'qc_co2_outlier_hi', 'CO₂ — outlier haut (fallback global)',       'CO₂ au-dessus du seuil outlier (g/L). Fallback quand co2_target absent.',          6.00,  'g/L',   6.00
  -- Volume global fallback band (HL) — used when v_recipe_vol_band has no row (n<3)
  UNION ALL
  SELECT 'qc_thresholds', 'qc_vol_warn_lo',    'Volume soutiré — avertissement bas (fallback global)',  'Volume en dessous du seuil avertissement (HL). Fallback quand n<3.',  25.00,  'HL',   25.00
  UNION ALL
  SELECT 'qc_thresholds', 'qc_vol_warn_hi',    'Volume soutiré — avertissement haut (fallback global)', 'Volume au-dessus du seuil avertissement (HL). Fallback quand n<3.',  240.00, 'HL',  240.00
  UNION ALL
  SELECT 'qc_thresholds', 'qc_vol_outlier_lo', 'Volume soutiré — outlier bas (fallback global)',        'Volume en dessous du seuil outlier (HL). Fallback quand n<3.',         25.00,  'HL',   25.00
  UNION ALL
  SELECT 'qc_thresholds', 'qc_vol_outlier_hi', 'Volume soutiré — outlier haut (fallback global)',       'Volume au-dessus du seuil outlier (HL). Fallback quand n<3.',          240.00, 'HL',  240.00
) v
WHERE NOT EXISTS (
  SELECT 1 FROM commissioning_settings cs
   WHERE cs.section = v.section AND cs.key_name = v.key_name
);

-- ── D. Derived view: v_recipe_vol_band ────────────────────────────────────────
-- One row per recipe with n >= 3 non-tombstoned, positive racked_vol_hl entries.
-- Excludes known-bad NULL-destination Diversion rows (ids 60, 66, 278).
-- Bounds: warn = [mean − 2σ, mean + 2σ], outlier = [mean − 3σ, mean + 3σ].
-- Clamped to [25, 240] where 25 = single-brew floor, 240 = MAX(ref_bbt.capacity_hl).
-- Recipe identity via COALESCE(neb_recipe_id_fk, contract_recipe_id_fk).
-- ONLY_FULL_GROUP_BY-safe: every SELECT column is aggregate or the GROUP BY key.

CREATE OR REPLACE VIEW v_recipe_vol_band AS
SELECT
    COALESCE(r.neb_recipe_id_fk, r.contract_recipe_id_fk)         AS recipe_id,
    COUNT(*)                                                        AS n,
    ROUND(AVG(r.racked_vol_hl), 4)                                 AS mean_vol,
    ROUND(STDDEV_SAMP(r.racked_vol_hl), 4)                         AS stddev_vol,
    -- warn = [mean - 2σ, mean + 2σ], clamped to [25, 240]
    GREATEST(25.00, ROUND(AVG(r.racked_vol_hl) - 2 * STDDEV_SAMP(r.racked_vol_hl), 2)) AS warn_lo,
    LEAST(240.00,   ROUND(AVG(r.racked_vol_hl) + 2 * STDDEV_SAMP(r.racked_vol_hl), 2)) AS warn_hi,
    -- outlier = [mean - 3σ, mean + 3σ], clamped to [25, 240]
    GREATEST(25.00, ROUND(AVG(r.racked_vol_hl) - 3 * STDDEV_SAMP(r.racked_vol_hl), 2)) AS outlier_lo,
    LEAST(240.00,   ROUND(AVG(r.racked_vol_hl) + 3 * STDDEV_SAMP(r.racked_vol_hl), 2)) AS outlier_hi
FROM bd_racking_v2 r
WHERE r.is_tombstoned = 0
  AND r.racked_vol_hl IS NOT NULL
  AND r.racked_vol_hl > 0
  AND r.id NOT IN (60, 66, 278)
  AND (r.neb_recipe_id_fk IS NOT NULL OR r.contract_recipe_id_fk IS NOT NULL)
GROUP BY COALESCE(r.neb_recipe_id_fk, r.contract_recipe_id_fk)
HAVING COUNT(*) >= 3;
