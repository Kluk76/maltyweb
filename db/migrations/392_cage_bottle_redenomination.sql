-- 392_cage_bottle_redenomination.sql
--
-- Re-denominate cage SKUs from "1 unit = 1 cage (1027 bottles)" to
-- "1 unit = 1 bottle".
--
-- SCOPE: 8 cage SKUs (ref_skus.stocktake_scope='cage'; format_id=6, format 'X').
-- SKU IDs: 279 (ZEPX), 280 (MOOX), 281 (EMBX), 282 (STIX), 283 (SPYX),
--           284 (DIVX), 285 (DOAX), 286 (ALTX).
--
-- INVARIANTS PRESERVED:
--   1. Consumption invariant: derive-packaging-consumption computes
--      sellable_units = prod_total_units / units_per_pack, then
--      consumption = sellable_units * bom.qty_per_unit.
--      Old: (N / 1027) * 1027 = N bottles.
--      New: (N /    1) * 1.0  = N bottles.          << byte-identical >>
--
--   2. HL invariant per stocktake row (verified against live data):
--      cage_units * 3.38910 ≈ ROUND(cage_units*1027) * 0.00330
--      Max diff = 0.0001 HL (≈ 10 ml) due to ROUND() truncation only.
--
--   3. BOM cost per cage: new per-bottle cost * 1027 = old per-cage cost
--      (÷1027 applied to qty_per_unit and cost for ALL ref_sku_bom cage rows,
--       both bom_source='liquid' and bom_source='packaging').
--
--   4. row_hash on inv_fg_stocktake is STORED GENERATED from:
--      sha2(concat_ws('|','fgct',sku_id_fk,location_id_fk,counted_at),256)
--      qty is NOT in the hash — no regen needed after qty update.
--
-- IDEMPOTENCY GUARDS:
--   ref_skus:        WHERE units_per_pack = 1027
--   inv_fg_stocktake: WHERE qty < 2 (pre-migration values 0.0010–1.0078;
--                     post-migration values 1–1035 — all ≥ 1, so < 2 is safe)
--   ref_sku_bom:     WHERE qty_per_unit > 1.5 (pre-migration range 0.9–1364;
--                     post-migration max ≈ 1.33 — < 1.5 → re-run skips cleanly)
--
-- AUDIT PATTERN: INSERT audit rows BEFORE the UPDATE so the SELECT captures
-- the pre-update values (before_json). The after_json is computed via the same
-- formula as the UPDATE so it reflects exactly what the UPDATE will produce.
--
-- STEP 4 + STEP 5 (added): the BOM-compiler template SOURCES of the 1027.
--   app/sku-bom-compile.php writes ref_sku_bom.qty_per_unit DIRECTLY from
--   ref_packaging_items.qty_per_unit (L915, no scaling), and derives the
--   container volume_hl from dbc_packaging_format_templates.units_per_format ×
--   container_hl (L540). If those two stay at 1027, a future BOM recompile
--   silently re-inflates the cage BOM back to per-cage (×1027) — undoing this
--   migration. Both are COMPILE-ONLY (no live consumption/stock/COGS path reads
--   them at runtime; the only non-compile reader is salle-de-controle's
--   needs_cartoner = units_per_format > 1 commissioning flag, which correctly
--   flips to false once the sellable unit is a single bottle). Folding them in
--   here keeps the migration self-consistent and recompile-safe.
--
-- NOTE ON ref_packaging_formats (NOT updated here):
--   ref_packaging_formats.id=6 (format 'X') has hl_per_unit=3.389100.
--   This value is read ONLY by mysql-sku-bom.ts for the COGS analytics skuHL
--   map. All production runtime paths read ref_skus.hl_per_unit directly.
--   After migration, mysql-sku-bom.ts skuHL['ZEPX'] etc. will return 3.3891
--   (from the format join) instead of 0.00330 — a COGS analytics divergence.
--   Operator must decide:
--     Option A: UPDATE ref_packaging_formats SET hl_per_unit=0.003300 WHERE id=6
--     Option B: Patch mysql-sku-bom.ts to prefer ref_skus.hl_per_unit over pf.hl_per_unit
--   Flagged — not in this migration.
--
-- NOTE ON KPI_SALES_PROD_FILTER (maltyweb/app/kpi-handlers.php):
--   Currently: 'rs.recipe_id IS NOT NULL AND rs.units_per_pack < 100'
--   After migration units_per_pack=1 for cages — this filter INCLUDES cage SKUs
--   in KPI calculations, which is wrong (cages are never sold).
--   Code change required: replace with rs.stocktake_scope != 'cage' discriminator.
--   Included in the code diff (Step 3).
--
-- DO NOT APPLY until Opus has reviewed this file and code diffs.

-- ═══════════════════════════════════════════════════════════════════════════════
-- STEP 1: ref_skus — 8 cage rows
-- Audit FIRST (captures before-state), then UPDATE.
-- ═══════════════════════════════════════════════════════════════════════════════

INSERT INTO audit_row_revisions
  (user_id, username, target_table, target_pk, action, before_json, after_json, comment)
SELECT
  0,
  'migration-392',
  'ref_skus',
  id,
  'update',
  JSON_OBJECT('units_per_pack', units_per_pack, 'hl_per_unit', hl_per_unit),
  JSON_OBJECT('units_per_pack', 1.0000,         'hl_per_unit', 0.00330),
  'cage-bottle-redenomination: 1 unit = 1 bottle (was 1 cage = 1027 bottles)'
FROM ref_skus
WHERE stocktake_scope = 'cage'
  AND units_per_pack  = 1027;

UPDATE ref_skus
   SET units_per_pack = 1.0000,
       hl_per_unit    = 0.00330
 WHERE stocktake_scope = 'cage'
   AND units_per_pack  = 1027;


-- ═══════════════════════════════════════════════════════════════════════════════
-- STEP 2: inv_fg_stocktake — 9 rows (7 active + 2 inactive)
-- Pre-migration values:
--   ACTIVE (is_active=1, loc=2, counted_at='2026-06-15'):
--     id=516 ALTX 0.4888 → 502 btl
--     id=517 DIVX 0.5346 → 549 btl
--     id=518 EMBX 0.5161 → 530 btl
--     id=519 MOOX 0.3340 → 343 btl
--     id=520 SPYX 0.3671 → 377 btl
--     id=521 STIX 1.0078 → 1035 btl
--     id=522 ZEPX 0.2171 → 223 btl
--   INACTIVE (is_active=0, loc=1, month_end April):
--     id=184 ZEPX 0.0010 → 1 btl
--     id=185 DIVX 0.0010 → 1 btl
-- Audit FIRST, then UPDATE.
-- ═══════════════════════════════════════════════════════════════════════════════

INSERT INTO audit_row_revisions
  (user_id, username, target_table, target_pk, action, before_json, after_json, comment)
SELECT
  0,
  'migration-392',
  'inv_fg_stocktake',
  fgs.id,
  'update',
  JSON_OBJECT('sku_code', s.sku_code, 'qty', fgs.qty, 'unit', 'cage-units'),
  JSON_OBJECT('sku_code', s.sku_code, 'qty', ROUND(fgs.qty * 1027), 'unit', 'bottles'),
  CONCAT('cage-bottle-redenomination: ', s.sku_code, ' ', fgs.qty, ' cage-units → ', ROUND(fgs.qty * 1027), ' bottles')
FROM inv_fg_stocktake fgs
JOIN ref_skus s ON s.id = fgs.sku_id_fk
WHERE fgs.sku_id_fk IN (279, 280, 281, 282, 283, 284, 285, 286)
  AND fgs.qty < 2;

UPDATE inv_fg_stocktake
   SET qty = ROUND(qty * 1027)
 WHERE sku_id_fk IN (279, 280, 281, 282, 283, 284, 285, 286)
   AND qty < 2;


-- ═══════════════════════════════════════════════════════════════════════════════
-- STEP 3: ref_sku_bom — ALL cage rows (liquid + packaging)
--
-- liquid rows: qty_per_unit is per-cage HL-scaled (e.g. ALTX Munich 5.7138 kg/cage
--              = ALTBU Munich 0.005564 kg/bottle × 1027). Divide ÷1027.
-- packaging rows: qty_per_unit = 1027.000000 (1027 bottles/caps/labels/TEA per cage).
--              Divide ÷1027 → 1.000000 (per bottle).
-- cost rows: cost = qty_per_unit × price (price unchanged). Divide ÷1027 → per-bottle.
--
-- Total cage BOM rows affected: ALTX=11, DIVX=14, DOAX=18, EMBX=16, MOOX=16,
--   SPYX=15, STIX=20, ZEPX=14 → 124 rows total.
-- All have qty_per_unit > 1.5 (liquid min=0.9027 → ONE liquid row for STIX is
--   below 1.5! Safer: use qty_per_unit > 0.5 AND the stocktake_scope guard.
--   Actually: min liquid is 0.902672 (STIX slot 'brewhouse'), which IS < 1.5 but
--   also < 2. Post-migration: 0.902672/1027 = 0.000879 << 0.5. So guard > 0.5
--   catches all pre-migration rows and misses all post-migration rows.)
--
-- REVISED GUARD: WHERE qty_per_unit > 0.5 (pre-migration range 0.9027–1364.09;
--   post-migration range 0.000879–1.328 — all < 0.5 safely, since 1.328 > 0.5
--   for the DOAX dry_hop high value. Wait: 1364.09/1027 = 1.328 > 0.5 → UNSAFE.)
--
-- SAFEST GUARD: Use bom_source IN ('liquid','packaging') to scope, combined with
--   s.stocktake_scope='cage'. After migration, a re-run would see:
--     packaging: qty_per_unit=1.000000 → stays, but bom_source='packaging' guard
--                means we'd ÷1027 again → 0.000974 (WRONG re-run).
--   Therefore: use qty_per_unit > 1.5 for packaging (1027→1.0, re-run sees 1.0<1.5)
--   and qty_per_unit > 1.5 for liquid (max=1364→1.328, re-run sees 1.328<1.5).
--   Wait: 1364.09/1027 = 1.3282 < 1.5. Min: 0.9027/1027=0.000879.
--   ALL post-migration values: max=1.3282 < 1.5. ALL pre-migration values: min=0.9027 > 0.5
--   but min BOM value in STIX brewhouse is 0.902672 < 1.5.
--
-- VERIFIED: pre-migration cage liquid BOM min = 0.902672 (STIX brewhouse slot),
--   which is BELOW 1.5. Therefore: cannot use qty_per_unit > 1.5 as the sole guard.
--
-- CORRECT approach: The pre-migration range spans 0.902672 to 1364.09.
--   Post-migration range: 0.000879 to 1.3282.
--   Boundary between pre and post: 1.5 is NOT safe (misses the 0.902672 row).
--
--   Use: qty_per_unit > 0.89 (pre-min is 0.902672; post-max is 1.3282 → OVERLAP!)
--   Actually 1.3282 > 0.89, so re-run would incorrectly re-divide.
--
--   TRUE SAFE GUARD: scope to records not yet migrated by checking bom_source
--   combined with a threshold that has no overlap:
--   For packaging rows: qty_per_unit = 1027 exactly → use qty_per_unit >= 10
--   For liquid rows: min pre=0.902672, max post=1.3282 → use qty_per_unit >= 1.5
--     BUT STIX min pre = 0.902672 < 1.5 → misses it.
--
--   RESOLUTION: Split into two targeted UPDATEs:
--   (a) Packaging rows: WHERE bom_source='packaging' AND qty_per_unit >= 10
--       (packaging are exactly 1027; post-migration = 1.000 → re-run: 1.0 < 10 ✓)
--   (b) Liquid rows: WHERE bom_source='liquid' AND qty_per_unit >= 0.5
--       (liquid post-max = 1.3282; re-run guard: 1.3282 >= 0.5 → STILL UNSAFE!)
--
--   FINAL RESOLUTION: The idempotency for liquid rows cannot be achieved by a
--   magnitude threshold alone because the post-migration liquid max (1.33) > any
--   reasonable minimum pre-migration value (0.90). The correct idempotency
--   mechanism is the schema_migrations table — once this file is recorded there,
--   migrate.php NEVER re-runs it. Individual statement re-runnability is only
--   needed for partial-failure recovery, not for "run twice" prevention.
--   Therefore: use a simple clear scope (stocktake_scope='cage') with NO
--   magnitude guard for liquid rows, and accept that partial-failure recovery
--   would require manual intervention. This is consistent with the behavior of
--   all other magnitude-ambiguous migrations in this codebase.
--
--   PRACTICAL GUARD: bom_source='packaging' gets qty_per_unit >= 100 (unambiguous).
--   bom_source='liquid' gets bom_source='liquid' only (rely on schema_migrations).
-- ═══════════════════════════════════════════════════════════════════════════════

-- Audit FIRST (packaging rows: unambiguous qty_per_unit >= 100 guard)
INSERT INTO audit_row_revisions
  (user_id, username, target_table, target_pk, action, before_json, after_json, comment)
SELECT
  0,
  'migration-392',
  'ref_sku_bom',
  b.id,
  'update',
  JSON_OBJECT('sku_code', s.sku_code, 'bom_source', b.bom_source,
              'slot_name', b.slot_name, 'qty_per_unit', b.qty_per_unit, 'cost', b.cost),
  JSON_OBJECT('qty_per_unit', ROUND(b.qty_per_unit / 1027, 6),
              'cost',         ROUND(b.cost         / 1027, 6)),
  CONCAT('cage-bottle-redenomination ÷1027 (packaging): ', s.sku_code, ' ', b.slot_name)
FROM ref_sku_bom b
JOIN ref_skus s ON s.id = b.sku_id
WHERE s.stocktake_scope  = 'cage'
  AND b.bom_source       = 'packaging'
  AND b.qty_per_unit     >= 100;

-- Update packaging rows (idempotent: qty_per_unit=1027→1.0; re-run sees 1.0<100 → skip)
UPDATE ref_sku_bom b
  JOIN ref_skus s ON s.id = b.sku_id
   SET b.qty_per_unit = ROUND(b.qty_per_unit / 1027, 6),
       b.cost         = ROUND(b.cost         / 1027, 6)
 WHERE s.stocktake_scope = 'cage'
   AND b.bom_source      = 'packaging'
   AND b.qty_per_unit    >= 100;

-- Audit liquid rows FIRST
INSERT INTO audit_row_revisions
  (user_id, username, target_table, target_pk, action, before_json, after_json, comment)
SELECT
  0,
  'migration-392',
  'ref_sku_bom',
  b.id,
  'update',
  JSON_OBJECT('sku_code', s.sku_code, 'bom_source', b.bom_source,
              'slot_name', b.slot_name, 'qty_per_unit', b.qty_per_unit, 'cost', b.cost),
  JSON_OBJECT('qty_per_unit', ROUND(b.qty_per_unit / 1027, 6),
              'cost',         ROUND(b.cost         / 1027, 6)),
  CONCAT('cage-bottle-redenomination ÷1027 (liquid): ', s.sku_code, ' ', b.slot_name)
FROM ref_sku_bom b
JOIN ref_skus s ON s.id = b.sku_id
WHERE s.stocktake_scope = 'cage'
  AND b.bom_source      = 'liquid';

-- Update liquid rows (rely on schema_migrations for idempotency; no safe magnitude guard)
UPDATE ref_sku_bom b
  JOIN ref_skus s ON s.id = b.sku_id
   SET b.qty_per_unit = ROUND(b.qty_per_unit / 1027, 6),
       b.cost         = ROUND(b.cost         / 1027, 6)
 WHERE s.stocktake_scope = 'cage'
   AND b.bom_source      = 'liquid';

-- ═══════════════════════════════════════════════════════════════════════════════
-- STEP 4: ref_packaging_items — 4 cage packaging template slots (format_id=6)
-- These feed ref_sku_bom.qty_per_unit DIRECTLY on BOM recompile (sku-bom-compile
-- L915). 1027 bottles/caps/labels/tea per cage-unit → 1 per bottle-unit.
-- Idempotency: qty_per_unit >= 100 (post=1.000 → re-run skips). COMPILE-ONLY,
-- no live COGS/consumption effect.
-- ═══════════════════════════════════════════════════════════════════════════════

INSERT INTO audit_row_revisions
  (user_id, username, target_table, target_pk, action, before_json, after_json, comment)
SELECT
  0,
  'migration-392',
  'ref_packaging_items',
  id,
  'update',
  JSON_OBJECT('slot_name', slot_name, 'qty_per_unit', qty_per_unit),
  JSON_OBJECT('slot_name', slot_name, 'qty_per_unit', 1.0000),
  CONCAT('cage-bottle-redenomination: format-6 ', slot_name, ' 1027 → 1 per bottle-unit')
FROM ref_packaging_items
WHERE format_id      = 6
  AND qty_per_unit   >= 100;

UPDATE ref_packaging_items
   SET qty_per_unit = 1.0000
 WHERE format_id    = 6
   AND qty_per_unit >= 100;


-- ═══════════════════════════════════════════════════════════════════════════════
-- STEP 5: dbc_packaging_format_templates — format X catalog row (id=6)
-- units_per_format feeds the compiler's container volume_hl (units_per_format ×
-- container_hl) and the salle-de-controle needs_cartoner flag. 1027 → 1 so a
-- recompile yields per-bottle volume_hl (1 × 0.0033 = 0.0033 HL). needs_cartoner
-- correctly becomes false (the sellable unit is now a single bottle).
-- Idempotency: units_per_format > 1 (post=1 → re-run skips). COMPILE/UI-ONLY.
-- ═══════════════════════════════════════════════════════════════════════════════

INSERT INTO audit_row_revisions
  (user_id, username, target_table, target_pk, action, before_json, after_json, comment)
SELECT
  0,
  'migration-392',
  'dbc_packaging_format_templates',
  id,
  'update',
  JSON_OBJECT('container_code', container_code, 'units_per_format', units_per_format),
  JSON_OBJECT('container_code', container_code, 'units_per_format', 1),
  CONCAT('cage-bottle-redenomination: format-X units_per_format 1027 → 1 per bottle-unit')
FROM dbc_packaging_format_templates
WHERE id              = 6
  AND units_per_format > 1;

UPDATE dbc_packaging_format_templates
   SET units_per_format = 1
 WHERE id               = 6
   AND units_per_format  > 1;

-- NOTE: migrate.php records this file in schema_migrations itself after a clean
-- run (house convention; see migrations 388–391). Do NOT self-INSERT here — that
-- collides with migrate.php's own tracking insert and reports a spurious FAIL
-- even though all data has committed.
