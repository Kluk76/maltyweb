-- db/migrations/275_liquid_basis_floor_date.sql
--
-- What: Add ref_recipes.liquid_basis_floor_date — a per-recipe hard floor for the
--       F2 (compile_sku_bom_liquid) trailing-average window. Brews whose event_date
--       is BEFORE this date are excluded from the liquid-basis computation, regardless
--       of the trailing-N window. Used for recipe discontinuities where blending
--       pre- and post-change brews would produce a meaningless average.
--
-- Seeds two known DK (By Danny Khezzar) era discontinuities:
--
--   recipe_id=26 (Diversion Blanche / DIB): floor = 2025-10-07.
--     Evidence: ADJ_PEACH_TEA (mi_id_fk=72) first appears in inv_consumption for DIB
--     at consumed_at=2025-10-07 (batch 4). Batches 1-3 (2024-12-18…2025-07-15) are
--     the pre-DK / pre-peach-tea formulation. Floor seeded at batch 4's event_date.
--     Source: SELECT mi_id_fk, consumed_at FROM inv_consumption WHERE mi_id_fk=72
--             ORDER BY consumed_at → first DIB row = 2025-10-07.
--
--   recipe_id=51 (Speakeasy / SPY): floor = 2025-10-07.
--     Evidence: malt grist changes durably at batch 57 (2025-10-07).
--     Pre-DK era (batches 12…55): MALT_CARAPILS + MALT_PILSENER (+/- MALT_WHEAT traces).
--     Transition (batch 56, 2025-08-26): MALT_PILSENER alone (one-brew anomaly/clean-out).
--     DK era (batch 57+, 2025-10-07 onward): MALT_OAT_FLAKES + MALT_PILSENER + MALT_WHEAT.
--     The grist change is durable (batches 57-65 all carry the new set consistently).
--     Floor seeded at batch 57's event_date = 2025-10-07.
--     ⚠️  PENDING OPERATOR CONFIRMATION at review: the batch-57 date is data-derived
--         (objective grist-set change) but the operator should confirm this maps correctly
--         to the commercial DK era start. The floor can be adjusted via a direct UPDATE
--         if the operator identifies a different boundary brew.
--
-- Note: no schema_meta row needed — only a column is added to an existing reference table.
--
-- MySQL 8: no ADD COLUMN IF NOT EXISTS — idempotency via schema_migrations tracking only.
--
-- ref_recipes.id is INT UNSIGNED (verified: fk targets INT UNSIGNED).

-- ── Column ────────────────────────────────────────────────────────────────────

ALTER TABLE ref_recipes
  ADD COLUMN liquid_basis_floor_date DATE NULL
    COMMENT 'Hard floor for the F2 trailing window — brews before this date are excluded from the liquid basis; used for recipe discontinuities like the DK era-change. NULL = no floor (full trailing window applies).';

-- ── Seeds ─────────────────────────────────────────────────────────────────────

-- DIB: first DK/peach-tea brew (evidence: inv_consumption ADJ_PEACH_TEA, batch 4)
UPDATE ref_recipes
   SET liquid_basis_floor_date = '2025-10-07'
 WHERE id = 26;

-- SPY: first DK-grist brew (evidence: malt-set change at batch 57 — PENDING OPERATOR CONFIRMATION)
UPDATE ref_recipes
   SET liquid_basis_floor_date = '2025-10-07'
 WHERE id = 51;
