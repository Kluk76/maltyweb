-- db/migrations/178_v_mi_cost_resolver.sql
--
-- What: Phase-D cost-source unification — three objects:
--         1. ref_fx         — FX rate table (CHF base; EUR seeded at 0.945)
--         2. v_mi_cost      — thin per-MI cost resolver: wac > catalog > no_basis
--         3. v_mi_cost_gap  — no_basis MIs that are actually in an active SKU BOM
--                             or held in RM stock (i.e. a real COGS gap)
--
-- Why:  The hardcoded EUR_CHF=0.945 constant is scattered across lib/config.js,
--       warehouse.php, and compute scripts. ref_fx replaces it with an operator-editable
--       table so future rate changes propagate to every consumer in one UPDATE.
--
--       v_mi_cost is the single authoritative cost answer for every MI: prefer the
--       live WAC from v_mi_wac (already in CHF, from Active deliveries); fall back to
--       the ref_mi catalog price converted via ref_fx; mark "no_basis" (NULL cost_chf)
--       when neither exists. Consumers branch on cost_basis — NEVER treat NULL cost_chf
--       as zero (0 is a valid free-sample cost; NULL means unknown).
--
--       v_mi_cost_gap surfaces the actionable subset: MIs with no cost basis that are
--       referenced in an active SKU BOM or physically held in RM stock. These are real
--       COGS gaps. Empty result = healthy. The view is NOT a review-queue surface —
--       it is a read-only diagnostic for the operator dashboard and cost audit scripts.
--
-- Collation note:
--       ref_mi.currency is utf8mb4_unicode_ci (verified 2026-05-27). ref_fx.currency
--       is declared utf8mb4_unicode_ci to match, preventing error 1267 on the
--       fx.currency = m.currency JOIN.
--
-- Live-verified facts (read-only queries, 2026-05-27):
--       - ref_mi.currency has exactly two distinct non-empty values: EUR, CHF.
--         No third currency exists — safe to seed ref_fx with only those two.
--       - v_mi_wac.wac_chf is already in CHF (no additional FX multiply on wac branch).
--       - v_mi_wac join key: mi_id_fk (INT UNSIGNED = ref_mi.id).
--       - ref_sku_bom.mi_id is INT UNSIGNED (FK to ref_mi.id, per column name in schema).
--       - ref_sku_bom.sku_id is INT UNSIGNED, ref_skus.id is INT UNSIGNED — match.
--       - inv_rm_stocktake.mi_id_fk is INT UNSIGNED — matches ref_mi.id.
--       - inv_rm_stocktake.final_qty is DECIMAL(14,4).
--       - 178 is the next free migration number (177 = last applied, pending = 0).
--
-- Risk: LOW — one CREATE TABLE IF NOT EXISTS (new table, no data impact); two CREATE OR
--       REPLACE VIEW (metadata-only, INSTANT); three schema_meta upserts (keyed INSERT).
--       No existing tables or views modified. No data written except ref_fx seeds.
--
-- Rollback:
--   DROP VIEW IF EXISTS v_mi_cost_gap;
--   DROP VIEW IF EXISTS v_mi_cost;
--   DROP TABLE IF EXISTS ref_fx;
--   DELETE FROM schema_meta WHERE table_name IN ('ref_fx', 'v_mi_cost', 'v_mi_cost_gap');
--
-- NOTE: CREATE TABLE / CREATE OR REPLACE VIEW / INSERT ... ON DUPLICATE KEY UPDATE are
--   all migrate.php-safe (DDL + keyed DML via $pdo->exec()). No bare standalone SELECT
--   statements appear in this file — the SELECTs inside CREATE VIEW bodies are part of
--   DDL and are not standalone result-set statements.

-- ============================================================================
-- OBJECT 1: ref_fx — FX rate table (replaces hardcoded 0.945 constant)
-- ============================================================================

CREATE TABLE IF NOT EXISTS ref_fx (
  currency     CHAR(3)        NOT NULL
                              COLLATE utf8mb4_unicode_ci
                              COMMENT 'ISO 4217 currency code (matches ref_mi.currency collation)',
  rate_to_chf  DECIMAL(12,6)  NOT NULL
                              COMMENT 'Multiplier to convert 1 unit of this currency to CHF',
  note         VARCHAR(255)   NULL
                              COMMENT 'Human note — source, date, context',
  updated_at   TIMESTAMP      NOT NULL
                              DEFAULT CURRENT_TIMESTAMP
                              ON UPDATE CURRENT_TIMESTAMP
                              COMMENT 'Last rate update timestamp',
  PRIMARY KEY (currency)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
  COMMENT='FX conversion rates for catalog-price → CHF. Operator-editable. Seeded at Phase-D cutover rates matching lib/config.js EUR_CHF + warehouse.php constant.';

-- Seed EUR and CHF idempotently.
-- ON DUPLICATE KEY UPDATE: a subsequent operator rate change is an explicit UPDATE to
-- rate_to_chf; this seed never silently overwrites a live operator edit because the
-- rate_to_chf=VALUES(rate_to_chf) re-applies the seed value only on re-migration
-- (which migrate.php prevents by tracking applied migrations). Safe.
INSERT INTO ref_fx (currency, rate_to_chf, note)
VALUES
  ('CHF', 1.000000, 'Base currency — always 1.'),
  ('EUR', 0.945000, 'Phase-D cutover rate — matches lib/config.js EUR_CHF and warehouse.php constant. Update via direct UPDATE ref_fx SET rate_to_chf=? WHERE currency=''EUR'' when rate changes.')
ON DUPLICATE KEY UPDATE
  rate_to_chf = VALUES(rate_to_chf),
  note        = VALUES(note);

-- schema_meta row for ref_fx
INSERT INTO schema_meta
    (table_name, table_class, corrections_policy, writer_script, upstream_hint)
VALUES
    ('ref_fx', 'reference', 'allowed',
     'migration 178',
     'FX rates for catalog-price → CHF conversion. Operator-editable: UPDATE ref_fx SET rate_to_chf=<new> WHERE currency=''EUR''. EUR seeded at 0.945 (Phase-D cutover, matches lib/config.js). Future: source from Données générales system settings.')
ON DUPLICATE KEY UPDATE
    table_class        = VALUES(table_class),
    corrections_policy = VALUES(corrections_policy),
    writer_script      = VALUES(writer_script),
    upstream_hint      = VALUES(upstream_hint);

-- ============================================================================
-- OBJECT 2: v_mi_cost — thin per-MI cost resolver (wac > catalog > no_basis)
-- ============================================================================
--
-- Covers ALL ref_mi rows (no is_inventoried filter — cost resolution is needed
-- for services and non-inventoried MIs too, e.g. for invoice auditing).
--
-- cost_basis precedence:
--   'wac'       — live WAC from v_mi_wac (already CHF; no FX multiply)
--   'catalog'   — ref_mi.price IS NOT NULL (incl. 0), converted via ref_fx (CHF×1 or EUR×0.945).
--                 price=0 is a VALID cost (free sample / bundled) → cost_chf=0, NOT a gap.
--   'no_basis'  — no WAC and price IS NULL (truly unknown) → cost_chf IS NULL
--
-- NULL cost_chf means UNKNOWN. Consumers MUST NOT substitute 0.
-- 0 cost_chf is a valid stored value meaning "free sample / no acquisition cost".
--
-- FX fallback: if ref_fx has no row for m.currency, COALESCE falls back to
--   CASE WHEN m.currency='CHF' THEN 1 ELSE NULL END — CHF rows always resolve;
--   unknown-currency catalog costs null out safely.
--
-- This view is the L1.5 hot path: two LEFT JOINs, no aggregation, no subqueries.
-- It is safe to reference from PHP read paths and TS report builders.
-- Cap at 1 level of nesting below consumers (this view itself reads v_mi_wac,
-- which is also L1.5 — that's exactly 2 levels deep, the hard cap).

CREATE OR REPLACE VIEW v_mi_cost AS
SELECT
    m.id                AS mi_id_fk,
    m.mi_id             AS mi_id,
    m.name              AS mi_name,
    m.pricing_unit      AS pricing_unit,
    m.currency          AS currency,

    -- cost_basis: which source won
    CASE
        WHEN w.wac_chf IS NOT NULL    THEN 'wac'
        WHEN m.price IS NOT NULL      THEN 'catalog'   -- incl. price=0 (free sample / bundled = valid 0 cost)
        ELSE                               'no_basis'  -- price IS NULL = truly unknown
    END AS cost_basis,

    -- cost_chf: resolved cost in CHF.
    --   wac branch:     wac_chf is already in CHF (inv_deliveries.total_chf basis).
    --   catalog branch: m.price converted to CHF via ref_fx (EUR×0.945, CHF×1.0).
    --                   If ref_fx has no row for m.currency, cost_chf goes NULL —
    --                   the safe no_basis behaviour, not a silent zero.
    --   no_basis:       NULL (unknown cost — never substitute 0).
    CASE
        WHEN w.wac_chf IS NOT NULL    THEN w.wac_chf
        WHEN m.price IS NOT NULL      THEN
            m.price * COALESCE(
                fx.rate_to_chf,
                CASE WHEN m.currency = 'CHF' THEN 1.000000 ELSE NULL END
            )
        ELSE NULL
    END AS cost_chf

FROM ref_mi m
LEFT JOIN v_mi_wac w ON w.mi_id_fk = m.id
LEFT JOIN ref_fx   fx ON fx.currency COLLATE utf8mb4_unicode_ci
                       = m.currency  COLLATE utf8mb4_unicode_ci;

-- schema_meta row for v_mi_cost
INSERT INTO schema_meta
    (table_name, table_class, corrections_policy, writer_script, upstream_hint)
VALUES
    ('v_mi_cost', 'derived', 'blocked_with_redirect',
     'migration 178',
     'Per-MI cost resolver. cost_basis: wac (live v_mi_wac) > catalog (ref_mi.price × ref_fx) > no_basis (NULL). To change: fix inv_deliveries (qty_delivered/total_chf) for wac, or ref_mi.price + ref_fx.rate_to_chf for catalog. no_basis cost_chf is NULL by design — consumers must refuse-don''t-zero.')
ON DUPLICATE KEY UPDATE
    table_class        = VALUES(table_class),
    corrections_policy = VALUES(corrections_policy),
    writer_script      = VALUES(writer_script),
    upstream_hint      = VALUES(upstream_hint);

-- ============================================================================
-- OBJECT 3: v_mi_cost_gap — no_basis MIs with actual BOM or stock usage
-- ============================================================================
--
-- Surfaces only the ACTIONABLE no_basis subset: MIs with no WAC and no catalog
-- price that are:
--   (a) referenced in at least one active SKU BOM (in_active_bom=1), OR
--   (b) held in RM stock per the latest stocktake period (rm_on_hand > 0)
--
-- Dormant MIs (no BOM, no stock, no recent deliveries) do NOT appear.
-- Existence in ref_mi alone is never sufficient to surface a gap.
--
-- gap_kind:
--   'bom+stock' — in an active BOM AND has physical stock (highest priority)
--   'bom'       — in an active BOM but no stocktake stock
--   'stock'     — has physical stock but not in any active BOM
--
-- "Latest period" per MI: correlated subquery on inv_rm_stocktake using MAX(period)
-- where is_active=1 — avoids window functions for maximum compatibility and clarity.
-- Performance: inv_rm_stocktake is small (one row per MI × period); the correlated
-- MAX is equivalent to a derived-table approach at this table size.
--
-- This view is NOT a review-queue surface. It is a read-only diagnostic.
-- An empty result means every actively-used MI has a resolvable cost. That is healthy.
-- Resolve gaps by adding an Active delivery (→ WAC picks it up) or seeding ref_mi.price.

CREATE OR REPLACE VIEW v_mi_cost_gap AS
SELECT
    m.id            AS mi_id_fk,
    m.mi_id         AS mi_id,
    m.name          AS mi_name,
    m.pricing_unit  AS pricing_unit,

    -- 1 if this MI appears in at least one active SKU BOM; 0 otherwise
    (b.mi_id IS NOT NULL)        AS in_active_bom,

    -- On-hand quantity from the latest is_active stocktake period for this MI.
    -- COALESCE(,0) so gap_kind arithmetic works; NULL on_hand = never counted = 0.
    COALESCE(s.on_hand, 0)       AS rm_on_hand,

    -- gap_kind: why this MI surfaces (bom+stock is the highest-priority gap)
    CASE
        WHEN b.mi_id IS NOT NULL AND COALESCE(s.on_hand, 0) > 0 THEN 'bom+stock'
        WHEN b.mi_id IS NOT NULL                                 THEN 'bom'
        ELSE                                                          'stock'
    END AS gap_kind

FROM ref_mi m

-- WAC check: LEFT JOIN v_mi_wac; filter w.mi_id_fk IS NULL means no WAC
LEFT JOIN v_mi_wac w ON w.mi_id_fk = m.id

-- Active BOM check: any active SKU that references this MI
LEFT JOIN (
    SELECT DISTINCT bom.mi_id
      FROM ref_sku_bom bom
      JOIN ref_skus    sk  ON sk.id = bom.sku_id
     WHERE sk.is_active = 1
) b ON b.mi_id = m.id

-- On-hand from latest active stocktake period for this MI
LEFT JOIN (
    SELECT
        t.mi_id_fk,
        t.final_qty AS on_hand
      FROM inv_rm_stocktake t
     WHERE t.is_active = 1
       AND t.period = (
               SELECT MAX(t2.period)
                 FROM inv_rm_stocktake t2
                WHERE t2.mi_id_fk  = t.mi_id_fk
                  AND t2.is_active = 1
           )
) s ON s.mi_id_fk = m.id

-- Filter to no_basis MIs only (no WAC, no positive catalog price)
WHERE w.mi_id_fk          IS NULL          -- no WAC (no Active deliveries with qty_remaining>0)
  AND m.price IS NULL                      -- no catalog fallback at all → no_basis (price=0 is a valid free cost, NOT a gap)
  -- AND actually used or held — dormant unused no_basis MIs do NOT surface here
  AND (
        b.mi_id IS NOT NULL                -- in at least one active SKU BOM
     OR COALESCE(s.on_hand, 0) > 0        -- OR physically held in latest stocktake
  );

-- schema_meta row for v_mi_cost_gap
INSERT INTO schema_meta
    (table_name, table_class, corrections_policy, writer_script, upstream_hint)
VALUES
    ('v_mi_cost_gap', 'derived', 'blocked_with_redirect',
     'migration 178',
     'Cost-gap report: no_basis MIs that are in an active SKU BOM or held in RM stock — a real COGS gap. Empty result = healthy. Resolve by adding an Active delivery (WAC) or seeding ref_mi.price (catalog). NOT a review-queue surface.')
ON DUPLICATE KEY UPDATE
    table_class        = VALUES(table_class),
    corrections_policy = VALUES(corrections_policy),
    writer_script      = VALUES(writer_script),
    upstream_hint      = VALUES(upstream_hint);
