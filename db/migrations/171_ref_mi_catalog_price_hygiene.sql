-- db/migrations/171_ref_mi_catalog_price_hygiene.sql
--
-- What: Correct ref_mi.price for 6 MIs to reflect last-purchase price in native currency.
--
-- Why:  ref_mi.price carries stale g→kg / wrong-unit residuals from earlier ingest passes.
--       These would feed a wrong COGS fallback under the planned Phase-D formula:
--       COALESCE(WAC, catalog_price). Accountant rule: catalog = LAST-PURCHASE price,
--       stored in the MI's NATIVE currency (converted at point of use, not at storage).
--       NO WAC/COP impact — neither reads ref_mi.price today. Data hygiene only.
--
-- Targets (old price → new price; currency unchanged; source delivery noted):
--   id=65  YEAST_US05            EUR: 0.105400  → 109.000000  (del 426: 1.0 kg @ 109.00 EUR)
--   id=66  YEAST_W3470           EUR: 0.179400  → 179.400000  (del 425: 1.0 kg @ 179.40 EUR)
--   id=194 YEAST_FARMHOUSE       EUR: 2.545000  → 221.600000  (del 427: 0.5 kg @ 110.80 EUR → 221.60 EUR/kg)
--   id=196 YEAST_LALLEMAND_VERDANT EUR: 0.197800 → 197.800000 (del 429: 1.0 kg @ 197.80 EUR)
--   id=197 PROC_ALIGAL2_LIQ      CHF: 550.000000 → 0.370000  (del 444: 4155 kg @ 0.37 CHF/kg — un-folded per A1)
--   id=238 YEAST_LALLEMAND_POMONA EUR: NO CHANGE — see note below.
--
-- POMONA (id=238) branch taken: NO-UPDATE.
--   Live verification 2026-05-27: inv_deliveries WHERE ingredient_fk=238 → count=0.
--   No purchase history exists → no last-purchase basis for catalog price.
--   ref_mi.price stays at 0.233000 EUR pending first delivery or manual operator set.
--
-- Convention: each UPDATE guards on the old price value for idempotency.
--   last_modified_by='web' marks rows as human-curated so re-ingest won't clobber.
--   row_hash NOT recomputed — mi_id is stable, sha256(mi_id) is unchanged.
--   currency column NOT changed — always native currency per accountant rule.
--
-- Risk: LOW — no schema changes. No WAC/COP impact. Pure ref data hygiene.
--       No schema_meta row needed (data-only, no new table).
--       MySQL 8 syntax. No bare SELECT (migrate.php $pdo->exec()).
--
-- All old prices verified live 2026-05-27 against ref_mi:
--   id=65  price=0.105400 ✓   id=66  price=0.179400 ✓
--   id=194 price=2.545000 ✓   id=196 price=0.197800 ✓
--   id=197 price=550.000000 ✓ id=238 price=0.233000 (unchanged — no delivery history)
--
-- Rollback (reverse UPDATEs to old prices):
--   -- UPDATE ref_mi SET price=0.105400,   last_modified_by='web' WHERE id=65  AND price=109.000000;
--   -- UPDATE ref_mi SET price=0.179400,   last_modified_by='web' WHERE id=66  AND price=179.400000;
--   -- UPDATE ref_mi SET price=2.545000,   last_modified_by='web' WHERE id=194 AND price=221.600000;
--   -- UPDATE ref_mi SET price=0.197800,   last_modified_by='web' WHERE id=196 AND price=197.800000;
--   -- UPDATE ref_mi SET price=550.000000, last_modified_by='web' WHERE id=197 AND price=0.370000;
--   -- (id=238 not modified — no rollback needed)

-- =============================================================================
-- id=65  YEAST_US05 — last purchase del 426: 1.0 kg @ 109.00 EUR → 109.000000 EUR/kg
-- =============================================================================
UPDATE ref_mi
   SET price            = 109.000000,
       last_modified_by = 'web'
 WHERE id               = 65
   AND price            = 0.105400;

-- =============================================================================
-- id=66  YEAST_W3470 — last purchase del 425: 1.0 kg @ 179.40 EUR → 179.400000 EUR/kg
-- =============================================================================
UPDATE ref_mi
   SET price            = 179.400000,
       last_modified_by = 'web'
 WHERE id               = 66
   AND price            = 0.179400;

-- =============================================================================
-- id=194 YEAST_FARMHOUSE — last purchase del 427: 0.5 kg @ 110.80 EUR = 221.60 EUR/kg
-- =============================================================================
UPDATE ref_mi
   SET price            = 221.600000,
       last_modified_by = 'web'
 WHERE id               = 194
   AND price            = 2.545000;

-- =============================================================================
-- id=196 YEAST_LALLEMAND_VERDANT — last purchase del 429: 1.0 kg @ 197.80 EUR → 197.800000 EUR/kg
-- =============================================================================
UPDATE ref_mi
   SET price            = 197.800000,
       last_modified_by = 'web'
 WHERE id               = 196
   AND price            = 0.197800;

-- =============================================================================
-- id=197 PROC_ALIGAL2_LIQ — last purchase del 444: 4155 kg @ 0.37 CHF/kg
--   Un-folded per accountant A1 decision: ECO fee (fk591) is now its own non-inventoried
--   line; ALIGAL2_LIQ per-kg cost = 0.37 CHF/kg (NOT 0.38 — last-purchase basis only).
-- =============================================================================
UPDATE ref_mi
   SET price            = 0.370000,
       last_modified_by = 'web'
 WHERE id               = 197
   AND price            = 550.000000;

-- =============================================================================
-- id=238 YEAST_LALLEMAND_POMONA — NO UPDATE
--   Live check 2026-05-27: inv_deliveries WHERE ingredient_fk=238 → count=0.
--   No purchase history → no last-purchase basis. Catalog price stays 0.233000 EUR
--   pending first delivery or explicit manual set by operator.
-- =============================================================================
