-- db/migrations/172_ref_mi_catalog_price_hygiene_be134_simcoe.sql
--
-- What: Correct ref_mi.price for 2 more MIs to last-purchase price in native currency
--       (follow-up to migration 171; same accountant-ruled basis).
--
-- Why:  A catalog-vs-WAC divergence scan (2026-05-27) surfaced two stale residuals
--       that 171 did not cover:
--         • YEAST_SAFALE_BE134 (id=64): catalog 62.70 EUR/kg is the PER-BRICK price
--           (0.5 kg brick) mislabelled as per-kg. Its single delivery (313) is
--           0.5 kg @ 125.40 EUR/kg = 62.70 EUR/brick. Last-purchase per-kg = 125.40 EUR.
--         • HOPS_SIMCOE (id=46): catalog 16.132700 EUR/kg matches NO actual purchase
--           (deliveries: 14.00, 14.00, then 28.32 EUR/kg on 2026-03-03). It is a stale
--           pre-normalization residual. Last-purchase (most recent, 2026-03-03) = 28.32 EUR/kg.
--       Accountant rule (per 171): catalog = LAST-PURCHASE price, NATIVE currency,
--       converted at point of use. NO WAC/COP impact — neither reads ref_mi.price, and
--       both MIs have Active deliveries so WAC (not catalog) values them today. This is
--       pure ref-data hygiene that also de-risks the future Phase-D COALESCE(WAC, catalog).
--
--       NOT in scope (needs operator judgment, deliberately deferred):
--         • LOG_SCOTCH_ESHOP (id=201): catalog 2.50 CHF vs two same-day deliveries
--           (3.24 and 5.06 CHF/unit on 2025-12-15) — ambiguous which is "last-purchase".
--
-- Convention (per 171): each UPDATE guards on the old price for idempotency;
--   last_modified_by='web' marks rows human-curated so re-ingest won't clobber;
--   row_hash NOT recomputed (mi_id stable); currency column NOT changed (native).
--
-- Risk: LOW — no schema changes, no WAC/COP impact, fully reversible.
--       No schema_meta row (data-only). MySQL 8 syntax. No bare SELECT.
--
-- All old prices verified live 2026-05-27 against ref_mi:
--   id=64  price=62.700000  EUR  ✓
--   id=46  price=16.132700  EUR  ✓
--
-- Rollback:
--   -- UPDATE ref_mi SET price=62.700000,  last_modified_by='web' WHERE id=64 AND price=125.400000;
--   -- UPDATE ref_mi SET price=16.132700,  last_modified_by='web' WHERE id=46 AND price=28.320000;

-- =============================================================================
-- id=64 YEAST_SAFALE_BE134 — per-brick (0.5 kg @ 62.70) → per-kg 125.40 EUR
--   Source: del 313 (2026-03-24, 0.5 kg @ 125.40 EUR/kg = 62.70 EUR total).
-- =============================================================================
UPDATE ref_mi
   SET price            = 125.400000,
       last_modified_by = 'web'
 WHERE id               = 64
   AND price            = 62.700000;

-- =============================================================================
-- id=46 HOPS_SIMCOE — stale residual (16.1327 matches no purchase) → last-purchase 28.32 EUR
--   Source: del 294 (2026-03-03, 200 kg @ 28.32 EUR/kg) — most recent purchase.
-- =============================================================================
UPDATE ref_mi
   SET price            = 28.320000,
       last_modified_by = 'web'
 WHERE id               = 46
   AND price            = 16.132700;
