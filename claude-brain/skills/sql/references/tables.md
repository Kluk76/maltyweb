# SQL key-tables cheat sheet (2026-05-22)

Loaded on demand from the `sql` skill. **Authoritative source:** `SELECT * FROM schema_meta` returns the full classified inventory (65 rows). The selection below is the high-traffic tables that come up in most analyses — column types matter for FK declarations (anti-pattern #3) and money precision (anti-pattern #8).

| Table | Role | Notes |
|---|---|---|
| `ref_mi` | Master ingredients | id INT UNSIGNED PK; price DECIMAL(14,6); currency VARCHAR(8); `is_inventoried` TINYINT(1) (= counted-as-stock vs expensed-on-purchase; NOT GL-derivable — read directly, see anti-patterns #16); pricing_unit / consumption_unit FK ref_units(code) |
| `ref_mi_categories` | Categories + GL accounts | default_gl_account VARCHAR(16); ids 1=Malt(4101) 2=Hops(4102) 3=Yeast(4103) 4=Adjunct(4104) 5=ProcChem(4104) 11=Mineral(4104) 8=Packaging(4200) etc. |
| `ref_mi_invoicing_units` | Per-(MI × supplier × pack) | populated 2026-05-21 with 17 rows; parsers consult via `lib/pack-size.js` |
| `ref_units` | Unit registry | code PK; dimension ENUM; to_base_factor DECIMAL(18,9); seeded with g/kg/L/hL/unit/etc. |
| `ref_suppliers` | Suppliers | id PK; name VARCHAR |
| `inv_deliveries` | Delivery rows | qty_delivered DECIMAL(14,4); unit_price DECIMAL(14,6); status ENUM (Active/Pending/Consumed); qty_remaining DECIMAL(14,4); FIFO-depleted by `_phase2c-fifo-deplete.ts` |
| `inv_consumption` | Per-batch ingredient consumption | qty + unit; mi_id_fk; source_event ENUM (brewing/fermenting/racking/packaging/manual) |
| `inv_rm_stocktake` | RM physical counts — **rollup parent** | period CHAR(7) YYYY-MM; `counted_qty` DECIMAL (NULL = no active child lines, fallback to `expected_qty`; 0 = explicit stock-out); `final_qty` GENERATED = `COALESCE(counted_qty, expected_qty)`; written only by `rm_recompute_rollup()` — do NOT update directly |
| `inv_rm_stocktake_lines` | Per-pallet RM count lines — **raw-append child** | `mi_id_fk` INT UNSIGNED → `ref_mi(id)`; `period` CHAR(7); `qty` DECIMAL(14,3); `is_active` TINYINT(1) soft-delete; `row_hash` CHAR(64) UNIQUE (nonce-salted so two equal-qty pallets are two distinct lines); rollup via `rm_recompute_rollup()` on every add/soft-delete; see **raw-append-child → rollup-parent** pattern below |
| `inv_tank_balances` | Per-tank monthly snapshot | month_key CHAR(7); tank_type CHAR(3) CCT/BBT; volume_hl DECIMAL(10,3); brew_cost_per_hl DECIMAL(10,4); empty as of 2026-05-21 — use `TankSimulator` (maltyweb PHP) for live state |
| `wac_snapshots` | Period-end WAC per MI | wac_chf DECIMAL(14,6); qty_remaining_at_close DECIMAL(14,4); period CHAR(7) |
| `bd_brewing_brewday` | Brew session events | bd_beer / bd_batch (not beer/batch!); 1 row per batch (NOT per brew) |
| `bd_brewing_cooling` | Cooling event per brew | cool_beer/cool_batch/cool_brew (1-5)/cool_final_volume_hl; N rows per batch |
| `bd_brewing_ingredients_parsed` | Per-batch ingredient breakdown (**derived**, allowed_with_side_effect) | beer / batch (normalized, no prefix); qty + unit; mi_id_fk; **PER BREW — multiply by N_brews for batch total**; mi_id_fk corrections must upsert into `ref_mi_aliases` to survive re-parse — see `app/db-correct.php::dbcorrect_is_alias_trigger` |
| `bd_racking` / `bd_packaging` / `bd_fermenting` | Event tables | various prefixed columns |
| `ref_recipe_ingredients` | Per-recipe BOM | recipe_id INT UNSIGNED, mi_id_fk INT UNSIGNED, qty_per_hl DECIMAL(14,6), unit VARCHAR(8); seeded 2026-05-21 with 163 rows across 19 recipes; loader at `app/recipe-ingredients-loader.php` gap-fills against observed brewing data |
| `ref_brewhouse_size` | Nominal brewhouse capacity (config) | SCD2 versioned (effective_from/until); current row: 30.000 HL |
| `schema_meta` | Table classification (config) | 65 rows as of 2026-05-22; one row per DB table; UI + scripts read corrections_policy from here |

---

## Schema pattern: raw-append-child → rollup-parent

Use this when multiple independent real-world events (pallets, receipts, sub-measurements) contribute to a single canonical figure that consumers depend on.

**Structure:**
- **Child table** (`inv_rm_stocktake_lines`): raw-append, one row per event. Natural key `(mi_id, period)` NOT UNIQUE — two pallets of the same MI in the same period are two distinct rows. `row_hash` UNIQUE (include a microtime nonce so legitimately-duplicate-qty entries both survive). `is_active` TINYINT(1) for soft-delete. FK `mi_id_fk → ref_mi(id)` (INT UNSIGNED — see FK type cheat sheet). Composite index on `(mi_id, period, is_active)` for the rollup query. Classified `source` / `allowed` in `schema_meta`.
- **Parent table** (`inv_rm_stocktake`): natural key `(mi_id, period)` UNIQUE. `counted_qty` is written **only** by the rollup function — treat it as a derived column even though the table is `source`-classified. Never `UPDATE inv_rm_stocktake SET counted_qty = X` from a migration or backfill without going through `rm_recompute_rollup()`.

**NULL-vs-0 invariant (load-bearing for COGS/WAC consumers):**
- `counted_qty = NULL` → zero active child lines → consumer falls back to `expected_qty` (system estimate).
- `counted_qty = 0` → operator explicitly counted a stock-out (a real child row exists with `qty=0`).
- In the rollup: `SELECT COALESCE(SUM(qty), NULL) AS s, COUNT(*) AS n WHERE is_active=1` then `$counted = ($n === 0) ? null : $s`. **Never** `COALESCE(SUM, 0)` — it erases the NULL/zero distinction.
- The parent's `final_qty` column is GENERATED: `COALESCE(counted_qty, expected_qty)`.

**Soft-delete, not hard-delete:** `UPDATE SET is_active=0` then recompute. `audit_row_revisions.action` ENUM has no 'delete' — tombstone via `action='update'` + `is_active=0` in `after_json`.

**Rollup function is the single writer:** both "add" and "delete" endpoints call the same PHP `rm_recompute_rollup()` after their child write. The rollup uses `bd_upsert` on the natural key (idempotent) and emits `log_revision` for the parent row. Canonical example: `app/rm-stocktake-rollup.php`.

Already-covered in this skill: is_inventoried-vs-charges rule (#16), MI tombstone-and-relink merge playbook (migrations-and-deploy.md), row_hash-on-repoint sentinel gotcha (migrations-and-deploy.md).
