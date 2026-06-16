# SQL anti-patterns — post-mortem catalog

Loaded on demand from the `sql` skill. Each entry was a real bug or fix on the maltytask DB / maltyweb PHP modules — named for memorability, sorted by frequency. Match your symptom to an entry; the fix is encoded. **Re-consult this before declaring any SQL change done.**

## Index

1. `ONLY_FULL_GROUP_BY` trap — non-aggregated column not functionally dependent → `ANY_VALUE()`
2. Collation mismatch on JOIN — `utf8mb4_0900_ai_ci` vs `_unicode_ci` → explicit `COLLATE`
3. FK type mismatch — `BIGINT UNSIGNED` vs `INT UNSIGNED` → match parent PK exactly
4. ENUM value length truncation — `VARCHAR(N)` silently truncates → size + headroom or real `ENUM`
5. PHP PDO `:named` vs `?` placeholder collision → pick one style per query
6. Ambiguous column when self-or-twin JOINing → qualify every column with its alias
7. `INSERT IGNORE` silently swallows the row you wanted to update → `ON DUPLICATE KEY UPDATE`
8. DECIMAL precision drift for money → `DECIMAL(14,6)` unit price / `(14,4)` totals
9. PHP query-param NULL fall-through → read-with-default FIRST, then validate
10. Reading `qty_remaining` as if FIFO-depleted → run FIFO depletion first
11. EUR currency NOT converted to CHF → `IF(currency='EUR', 0.945, 1)`
12. Unit conversion grams ↔ kilograms → convert via `ref_units` at query time
13. Pack-size NOT applied at ingest → parsers consult `ref_mi_invoicing_units`
14. Brew-count multiplier for batch ingredients → multiply per-brew qty by `n_brews`
15. NULL division → `NULLIF(denominator, 0)` + `COALESCE`
16. `is_inventoried` filter missing → `WHERE m.is_inventoried = 1` on warehouse pages. **`is_inventoried` is the single semantic "counted-as-stock vs expensed-on-purchase" flag — it is NOT derivable from `gl_account`.** Live data deliberately splits many GLs across both states (e.g. GL-6285 logistics consumables and GL-4701 cerclage are expensed-on-purchase → `is_inventoried=0`, while other rows on the same GLs are stocked), so never write a `WHERE gl_account IN (…)` shortcut to decide inventoried state, and never build a GL→inventoried derivation. Read the flag directly. When an operator reclassifies a family (mig 256 flipped the 8 active GL-6285 MIs to `is_inventoried=0`), also retire any stocktake lines/rollups they had already entered (`is_active=0` on the rollup row drops it from every RM read with no `row_hash` concern, since the natural key didn't move).
17. `expected_qty` fallback leakage → `counted_qty` only for live warehouse
18. MariaDB-only `IF NOT EXISTS` on ALTER TABLE in MySQL 8 → strip it
19. Migration applied via raw `mysql` not recorded in `schema_migrations` → record it
20. DDL is not transactional — design migrations for partial failure (idempotent)
21. Scoping a batch op by a PROXY table instead of the consumer's own gate → dry-run to `errors=0`
22. VARCHAR column compared to an INT literal → whole-column DOUBLE cast, err 1292 → quote the literal
23. Joining two systems on a shared *row-index* when each numbers independently → only ~45% correct → use the NATURAL business key, and prove the map is a bijection before writing
24. Soft-delete flag (`is_tombstoned`/`is_active`) added but not enforced in every read path → flag is inert, rows stay live → grep every consumer (view + PHP + cross-repo Node/TS) and patch atomically
25. Event-sourced replay: a DRAIN/release step gates on the STATIC brew-time binding instead of CURRENT occupancy → re-derive occupancy from live events
26. A corrective UPSERT must preserve the original natural-key timestamp, or it INSERTs a duplicate instead of UPDATE-in-place → carry `edit_submitted_at`
27. Route / detect-phase / firewall a form on the canonical FK, never on a free-text identity label that fragments across spellings (READ-side companion to #25)
28. A write-time validation/cadence/duplicate gate must SKIP edit/correction mode, or every correction re-trips the gate against the row being edited → guard `if (!$isEditMode)` (and self-exclude `edit_id` as defense-in-depth)
29. A "minimum-interval / too-recent" gate must measure the gap on the **business `event_date`**, never on `submitted_at`/`NOW()`/`time()` → else it is backfill-hostile: every row entered today reads "0 days apart" and bulk backfill breaks after the first row per batch
30. Packaging parallel leg mis-attributed to a different recipe: the -4 and -B legs of one physical session share the SAME `recipe_id`; NEVER resolve the parallel leg's beer from an independent selection field that can disagree with the main — derive identity by FK from the main, then suffix-flip the format. WRITE-side companion to #27

---

### 1. `ONLY_FULL_GROUP_BY` trap (hit ≥ 3×)

MySQL 8 with `sql_mode=only_full_group_by` rejects SELECTs that mention non-aggregated, non-grouped columns even when they're functionally dependent on the GROUP BY key across a LEFT JOIN.

**Symptom:** `SQLSTATE[42000]: Syntax error or access violation: 1055 Expression #N of SELECT list is not in GROUP BY clause and contains nonaggregated column 'X' which is not functionally dependent on columns in GROUP BY clause`

**Fix:** wrap each non-aggregated column in `ANY_VALUE(...)`. Move per-row multiplications INSIDE the aggregate (e.g. `SUM(qty * price)` not `SUM(qty) * price`). Also wrap columns in ORDER BY:

```sql
-- BROKEN
SELECT m.id, m.name, m.price, SUM(d.qty) AS total
  FROM ref_mi m LEFT JOIN inv_deliveries d ON d.ingredient_fk = m.id
 GROUP BY m.id
 ORDER BY m.name;

-- FIXED
SELECT ANY_VALUE(m.id), ANY_VALUE(m.name), ANY_VALUE(m.price), SUM(d.qty) AS total
  FROM ref_mi m LEFT JOIN inv_deliveries d ON d.ingredient_fk = m.id
 GROUP BY m.id
 ORDER BY ANY_VALUE(m.name);
```

Adding all columns to GROUP BY also works but is verbose and slower.

**⚠️ `ANY_VALUE` is WRONG when the selected column is load-bearing.** It returns an *arbitrary* row among the group's duplicates — fine for display-only aggregates, but a silent-wrong-mapping bug when the value feeds something downstream (a FK written to a table, an id that drives an insert, a price used in COGS). If the GROUP BY exists to *dedupe-and-pick-one* (e.g. `GROUP BY name HAVING COUNT(*)=1` to resolve a name → unique id), `ANY_VALUE` defeats the very contract the `HAVING` was enforcing. Instead resolve deterministically and refuse on ambiguity: drop the GROUP BY, `fetchAll()`, and throw unless exactly one row.

```sql
-- BROKEN (1055): id is non-aggregated; name → unique active recipe
SELECT id FROM ref_recipes WHERE name=? AND is_active=1 GROUP BY name HAVING COUNT(*)=1
-- WRONG fix: ANY_VALUE(id) — silently picks one of several ambiguous recipes, writes a bad recipe_id_fk
-- RIGHT fix: deterministic + refuse-don't-guess (form-brewing.php, 2026-06-16)
SELECT id FROM ref_recipes WHERE name=? AND is_active=1   -- then PHP: count($rows)!==1 → throw
```

Rule of thumb: display column → `ANY_VALUE`; identity/FK/money column → deterministic select + count-check.

### 2. Collation mismatch on JOIN (hit 2026-05-21 on WIP)

Two tables created at different times may have different default collations. Joining VARCHAR columns across them throws:

**Symptom:** `Illegal mix of collations (utf8mb4_0900_ai_ci,IMPLICIT) and (utf8mb4_unicode_ci,IMPLICIT) for operation '='`

**Diagnose:**
```sql
SELECT TABLE_NAME, COLUMN_NAME, COLLATION_NAME
  FROM INFORMATION_SCHEMA.COLUMNS
 WHERE TABLE_SCHEMA='maltytask' AND COLUMN_NAME='beer';
```

Maltytask has known split: `bd_*` tables (BSF-sync origin) use `utf8mb4_0900_ai_ci`. Most `inv_*` / `ref_*` use `utf8mb4_unicode_ci`. Custom `inv_tank_balances` is `_unicode_ci`.

**Fix:** add explicit `COLLATE` clauses in the JOIN condition. Pick a single target collation (we use `utf8mb4_unicode_ci`):

```sql
JOIN bd_brewing_ingredients_parsed bip
  ON bip.beer  COLLATE utf8mb4_unicode_ci = tb.beer_name COLLATE utf8mb4_unicode_ci
 AND bip.batch COLLATE utf8mb4_unicode_ci = tb.batch     COLLATE utf8mb4_unicode_ci
```

For WHERE on a constant string, MySQL coerces, so `WHERE beer = 'Zepp'` works fine without COLLATE. Only JOINs and ORDER BY across the boundary fail.

**Long-term:** standardise all new tables on `utf8mb4_unicode_ci` (matching most existing tables). Don't try to ALTER existing tables — too risky.

### 3. FK type mismatch (hit on Phase 1 migrations 056/057)

Foreign-key columns must EXACTLY match the referenced PK type. `BIGINT UNSIGNED` and `INT UNSIGNED` are not compatible — MySQL refuses the FK constraint.

**Symptom:** `Cannot add foreign key constraint`

**Fix:** check the PK column types first:
```sql
SELECT COLUMN_NAME, COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS
 WHERE TABLE_SCHEMA='maltytask' AND TABLE_NAME='ref_mi' AND COLUMN_NAME='id';
```

`ref_mi.id` is `int unsigned`. So `mi_id_fk` in any referencing table must be `INT UNSIGNED`, not `BIGINT UNSIGNED`.

`ref_suppliers.id` may also be `int unsigned` — confirm before declaring FK columns. Use `SHOW TABLES LIKE 'ref_sup%'` to discover the actual supplier table name (variations exist).

### 4. ENUM value length truncation (hit on Phase G RQ closing)

`VARCHAR(N)` with an ENUM-like value list silently truncates inputs longer than N. The truncation produces invalid enum values that fail subsequent reads.

**Hit case:** `decision='auto-closed-stale-ref-post-reingest'` (36 chars) into VARCHAR(32) → truncated to `auto-closed-stale-ref-post-rein` → ENUM constraint fails on read.

**Fix:** Size the column to your longest realistic value + 30% headroom. For controlled vocabularies, use real `ENUM(...)` type:
```sql
status ENUM('Active','Pending','Consumed','Cancelled') NOT NULL DEFAULT 'Active'
```
ENUM enforces validation at write time, no truncation surprises.

For free-form text fields, prefer `VARCHAR(255)` or `TEXT` unless storage is tight.

### 5. PHP PDO `:named` vs `?` placeholder collision (hit 2026-05-21)

Mixing `:named` and `?` placeholders in the same PDO prepared statement throws or silently misbinds.

**Symptom:** `Invalid parameter number` or wrong values bound.

**Fix:** Pick ONE style per query. For PHP modules under `/modules/`, prefer `:named` for readability. For programmatically-built queries (VALUES lists), prefer `?` for positional control.

Example with mixed batch-list + period parameter:
```php
$params = [];
$tuples = [];
foreach ($items as $it) {
    $tuples[] = 'ROW(?, ?)';
    $params[] = $it['beer']; $params[] = $it['batch'];
}
$params[] = $period;  // for the WHERE w.period = ? at the end
$sql = "WITH wanted AS (SELECT * FROM (VALUES " . implode(',', $tuples) . ") AS v(beer, batch)) ..."
     . " WHERE w.period = ?";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);  // all positional
```

### 6. Ambiguous column when self-or-twin JOINing (hit ≥ 3×)

When the same table appears twice in a query, or when 2 tables share a column name, unqualified references throw `Column 'X' in field list is ambiguous`.

**Hit case 1:** `JOIN ref_mi m JOIN ref_mi_categories c` → `mi_id` exists in both, must qualify `m.mi_id`.
**Hit case 2:** `inv_rm_stocktake st JOIN ref_mi m` → both have `mi_id` (one is the text MI_ID, one is numeric PK). Must prefix.

**Fix:** ALWAYS qualify every column with its table alias when 2+ tables join. Even single-table queries benefit from explicit aliases — copy-paste survival.

### 7. `INSERT IGNORE` silently swallows the row you wanted to update (hit on Phase 1 WAC recompute)

`INSERT IGNORE` skips on UNIQUE/PRIMARY KEY conflict. If you wanted to overwrite, you got a silent no-op.

**Symptom:** "Inserted 30 / Dedup collisions 100" → only 30 rows ever changed, the rest were never updated.

**Fix:** for true upserts, use `ON DUPLICATE KEY UPDATE`:
```sql
INSERT INTO wac_snapshots (mi_id_fk, period, wac_chf, ...)
VALUES (?, ?, ?, ...)
ON DUPLICATE KEY UPDATE
  wac_chf = VALUES(wac_chf),
  qty_remaining_at_close = VALUES(qty_remaining_at_close),
  computed_at = NOW();
```

For full recompute, prefer `DELETE WHERE period = ?` then bulk `INSERT`. Phase 2D script (`_phase2d-recompute-wac.ts`) uses this pattern — cleaner than UPSERT for full snapshot rebuild.

### 8. DECIMAL precision drift for money (encode-then-decode)

Money columns should be `DECIMAL(14,4)` minimum. Common gotcha: `unit_price` stored in `DECIMAL(10,2)` rounds away the 4th decimal needed for low-unit prices (crown caps 0.006993 CHF/unit).

**Fix:** `DECIMAL(14,6)` for unit prices, `DECIMAL(14,4)` for totals. mysql2's `decimalNumbers: false` returns strings — repos parse via `parseFloat(...)` or Zod `z.coerce.number()` (NOT direct cast — Number truncates the string at 15 sig figs).

### 9. PHP query-param NULL fall-through (hit + memory'd)

`in_array($_GET['x'] ?? 'def', [...], true) ? $_GET['x'] : 'def'` silently assigns NULL when param absent because the success branch reads bare `$_GET['x']`, not the `??` default.

**Fix:** two-step pattern — read with default FIRST, then validate:
```php
$sortCol = $_GET['sort'] ?? 'mi_id';
if (!in_array($sortCol, ['mi_id','mi_name',...], true)) $sortCol = 'mi_id';
```

This is a category of bug that whitelist-only checks don't catch — see memory `feedback_php_query_param_validate_after_default`.

### 10. Reading `qty_remaining` as if FIFO-depleted (hit Phase 2)

`inv_deliveries.qty_remaining` is NOT automatically depleted from consumption. The legacy `deplete-deliveries.js` ran on BSF; the MySQL equivalent is `_phase2c-fifo-deplete.ts` (run as part of Phase 2 rollout).

**Fix:** when computing WAC or "what's actually in stock", run FIFO depletion FIRST so older delivery rows are marked `status='Consumed'` (qty_remaining=0). Otherwise WAC blends stale consumed prices with current ones.

### 11. EUR currency NOT converted to CHF (hit Phase 2)

`inv_deliveries.unit_price` is in `currency`; `wac_snapshots.wac_chf` is always CHF. But `ref_mi.price` can be either — check `ref_mi.currency` (78 EUR / 131 CHF / 47 NULL for inventoried).

**Fix in queries:** `m.price * IF(m.currency='EUR', 0.945, 1)` — matches the static 0.945 rate used in every `inv_deliveries.eur_to_chf` entry.

### 12. Unit conversion grams ↔ kilograms (hit Phase 1)

`inv_consumption.qty` may be stored in grams (`unit='g'`) while `ref_mi.pricing_unit='kg'`. Subtracting grams from kg silently × 1000 errors. Same for L/mL.

**Fix:** use the `ref_units` registry to convert at query time:
```sql
c.qty * COALESCE(
  CASE WHEN cu.dimension = su.dimension AND su.to_base_factor > 0
       THEN cu.to_base_factor / su.to_base_factor
       ELSE 1
  END, 1) AS qty_canonical
FROM inv_consumption c
JOIN ref_mi m       ON m.id = c.mi_id_fk
LEFT JOIN ref_units cu ON cu.code = c.unit
LEFT JOIN ref_units su ON su.code = m.pricing_unit
```

### 13. Pack-size NOT applied at ingest (hit Phase 2)

Parsers may write `qty_delivered` in invoicing units (5kg packs, 1000kg big-bags, 500g bricks) while stock is canonical kg. WAC inflated 5–1000×.

**Fix:** parsers must consult `ref_mi_invoicing_units` (mi × supplier × pack_size) via `lib/pack-size.js::applyPackSize()` before inserting `inv_deliveries`. Existing-data correction needs a separate backfill (qty × pack_size, unit_price ÷ pack_size, total unchanged).

### 14. Brew-count multiplier for batch ingredients (hit 2026-05-21 WIP)

`bd_brewing_ingredients_parsed` records ingredients for ONE BREW (operator input). A batch can have 1-5 brews. Total batch ingredients = `per_brew_qty × n_brews` where `n_brews = COUNT(*) FROM bd_brewing_cooling WHERE cool_beer=? AND cool_batch=?`.

**Fix:** every brewing-data aggregation that ties to a (beer, batch) must compute `n_brews` and multiply:
```sql
SELECT itb.beer_name, itb.batch, itb.volume_hl,
       (SELECT SUM(cool_final_volume_hl) FROM bd_brewing_cooling
         WHERE cool_beer=itb.beer_name AND cool_batch=itb.batch AND cool_final_volume_hl>0) AS total_brewed_hl,
       (SELECT COUNT(*) FROM bd_brewing_cooling
         WHERE cool_beer=itb.beer_name AND cool_batch=itb.batch AND cool_final_volume_hl>0) AS n_brews
  FROM inv_tank_balances itb
```

Then per-MI: `qty × n_brews × tank.volume_hl / total_brewed_hl`.

### 15. NULL division (hit Phase 2)

`SELECT a / b` returns NULL when `b=0`. The NULL silently propagates to outer aggregates.

**Fix:** wrap denominators in `NULLIF(b, 0)` and `COALESCE(...) ELSE`:
```sql
COALESCE(a / NULLIF(b, 0), 0)
```

### 16. `is_inventoried` filter missing (hit Phase 1)

Without `AND m.is_inventoried = 1`, the page shows maintenance/utility/rental/tax-passthrough MIs as if they were stock. They have `inv_deliveries` entries but no physical inventory presence.

**Fix:** every warehouse-flavored page query has `WHERE m.is_inventoried = 1` in the outermost SELECT.

### 17. `expected_qty` fallback leakage (hit 2026-05-21)

`inv_rm_stocktake.expected_qty` is a system-predicted GUESS (can be negative). `counted_qty` is the operator's physical count. `COALESCE(counted_qty, expected_qty)` leaks the guess when operator didn't count.

**Fix:** for the live warehouse page, use `counted_qty` only (no fallback). The `final_qty` STORED GENERATED column does the same wrong COALESCE — don't use it for display either.

### 18. MariaDB-only `IF NOT EXISTS` on ALTER TABLE in MySQL 8 (hit 2026-05-22 on migrations 073/074/075)

`ADD COLUMN IF NOT EXISTS`, `ADD KEY IF NOT EXISTS`, `DROP INDEX IF EXISTS` are MariaDB-specific extensions. MySQL 8.0 (including 8.0.45 in prod) rejects them at parse time.

**Symptom:** `SQLSTATE[42000]: Syntax error or access violation: 1064 You have an error in your SQL syntax; check the manual that corresponds to your MySQL server version for the right syntax to use near 'IF NOT EXISTS <colname> ...' at line N`

**Fix:** strip `IF NOT EXISTS` from ALTER TABLE clauses. Migration idempotency comes from `schema_migrations` tracking — `migrate.php` runs each file at most once. The ALTER doesn't need to be idempotent at the SQL level.

```sql
-- BROKEN (MariaDB-only)
ALTER TABLE t ADD COLUMN IF NOT EXISTS c INT;
ALTER TABLE t ADD KEY IF NOT EXISTS idx_c (c);

-- WORKS in MySQL 8
ALTER TABLE t ADD COLUMN c INT;
ALTER TABLE t ADD KEY idx_c (c);
```

`CREATE TABLE IF NOT EXISTS` IS standard SQL — supported by both MySQL and MariaDB. Only the ALTER TABLE clause extensions are MariaDB-only.

### 19. Migration applied via raw `mysql` not recorded in schema_migrations (hit 2026-05-21)

If you bypass `migrate.php` and apply a migration directly via `mysql -e "..."` or a Sonnet agent's heredoc, the migration runs but `schema_migrations` doesn't get the row. Next `migrate.php` run sees the file as "pending" and tries to apply it again — which fails for non-idempotent migrations (CREATE TABLE without IF NOT EXISTS, INSERT seeds with UNIQUE keys).

**Symptom:** `migrate.php --status` shows a migration as pending that has clearly already been applied (table exists, columns exist).

**Fix:** after any manual apply, record it:
```sql
INSERT INTO schema_migrations (filename, applied_at)
  VALUES ('NNN_descriptive_name.sql', NOW());
```

Or: always prefer `migrate.php`. It auto-records. Use raw `mysql` only for ad-hoc debugging / reads.

### 20. DDL is not transactional — design for partial failure (migrate.php)

`migrate.php` runs the whole `.sql` file via one `$pdo->exec()` and records the filename only *after* success. MySQL DDL **auto-commits and cannot be rolled back** — a 5-statement migration that fails on statement 3 leaves 1–2 applied, the file unrecorded, and the next run re-applies 1–2 (failing on the non-idempotent ones). **The interruption need not be a SQL error** — a dropped SSH session, a killed process, or a harness/agent crash mid-file does exactly the same: the early auto-committed DDL persists, the late statements don't, and `schema_migrations` is NOT written. (Hit 2026-05-25 on migration 142: an SSH-run `migrate.php` was cut off after the ENUM-extend + 3 INSERTs auto-committed but before the CREATE TABLE + seed — a naive re-run would have duplicated the 3 INSERTs.)

**Fix — write migrations idempotent by default, so an interrupted *or* replayed run is always safe:** every statement independently idempotent or guarded — `CREATE TABLE IF NOT EXISTS`; `ALTER … MODIFY COLUMN` to the *same* target ENUM/type list (re-applying the identical definition is a safe no-op); `INSERT … ON DUPLICATE KEY UPDATE` / `INSERT IGNORE` for keyed seeds; `INSERT … SELECT … WHERE NOT EXISTS (…)` for seeds with no UNIQUE key to catch. For an ALTER that genuinely can't be made idempotent (rare — most MODIFY/ADD-to-same-state are), isolate it in its own single-statement file. Pre-flight: if a file has >1 DDL statement and any is non-idempotent, either guard it or split it.

**Post-apply verification (mandatory after every apply):** confirm the migration was actually recorded, then re-run `migrate.php` once — a clean idempotent migration re-runs as a no-op and proves it. Do NOT trust "the apply command returned" — verify the row exists:
```sql
SELECT COUNT(*) FROM schema_migrations WHERE filename = 'NNN_name.sql';  -- must be 1
```
If it's 0 but the schema clearly changed, the run was interrupted after partial DDL: inspect the live schema to see *which* statements landed, confirm the migration is idempotent, then re-run (or hand-record per anti-pattern #19). **When a build/apply step's result is lost** (agent error, dropped connection), do NOT assume nothing ran — probe the live DB/repo state before retrying, because auto-committed DDL may already be in place.

### 21. Scoping a batch op by a PROXY table instead of the consumer's own gate (hit 2026-05-26 on sku-bom-compile volume backfill)

When you build the row set a script will process (e.g. which SKUs `sku-bom-compile-cli.php --sku <ids>` should rebuild), filter by **replicating the script's OWN selection JOINs** — never a sibling/correlated table that merely *looks* equivalent. A proxy admits rows the consumer rejects (and can drop rows it accepts), so you `--apply` with unexplained `errors=N` you didn't predict.

**Hit:** scoped the backfill via `dbc_packaging_format_templates` (the **volume-derivation catalog**). But the compiler's actual buildability gate is a 4-way INNER JOIN: `ref_skus ⋈ ref_packaging_formats ⋈ ref_recipes (so recipe_id must be NON-NULL) ⋈ ref_packaging_bom_templates (supply='we_supply' AND is_active=1)`. 11 active SKUs with `recipe_id IS NULL` (BLA4, COLLAB12/24, PAC, PACKDEC, FRPACKDEC, FRDIBB12, DIB/DIG/DIP-BU) passed the catalog proxy but the compiler rejected them ("no format_id or no we_supply template"). They no-op in dry-run, but the unexplained error count is the smell.

**Two different tables, two different jobs — don't conflate:** `dbc_packaging_format_templates` = the *volume chain* (container × units → hl). `ref_packaging_bom_templates` (+ non-null `recipe_id`) = *buildability*. Scoping a recompile is a buildability question, so use the buildability gate.

**Fix:** before `--apply`, dry-run and require `errors=0` (or every error explained). If errors appear, your scope predicate ≠ the consumer's predicate — re-derive the `--sku` list from the consumer's exact JOIN, don't hand-wave the diff.

### 22. VARCHAR column compared to an INT literal (hit 2026-05-31 on migration 228)

When a `WHERE` (or `ON`, or `CASE`) compares a `VARCHAR` column to a *numeric* literal, MySQL doesn't compare strings — it follows its type-coercion rules and casts **every row's string value to DOUBLE** to match the number's type. The instant any row holds a non-numeric string, that cast fails and the whole statement errors out. The column "looking numeric" in the rows you happen to care about is no protection: MySQL evaluates the predicate across the entire column before filtering.

**Symptom:** `SQLSTATE[22007]: ... 1292 Truncated incorrect DOUBLE value: 'W34/70'` — the quoted value is some *other* row's content, often one you weren't targeting, which is what makes this confusing.

**Hit case:** migration 228 (Pinnacle yeast merge) guarded a repoint with `WHERE bd_yeast = 11`. `bd_brewing_brewday.bd_yeast` is `VARCHAR` (it stores yeast *codes*, e.g. `'US-05'`, `'W34/70'`, `'11'`), not the strain id. MySQL cast every `bd_yeast` to DOUBLE to compare against `11`; `'W34/70'` is non-numeric → the whole UPDATE hard-failed, even though zero rows actually held `'11'`.

**Fix:** match the literal's type to the column's type. For a VARCHAR column, **quote the literal** so it's a string-to-string comparison and no cast happens:

```sql
-- BROKEN: forces every bd_yeast to DOUBLE
... WHERE bd_yeast = 11

-- FIXED: string comparison, no coercion
... WHERE bd_yeast = '11'
```

This generalizes beyond migrations: any free-text / code column (`bd_yeast`, lot numbers, batch labels, supplier SKUs) typed as VARCHAR must get **quoted literals** even when its values look like integers. Conversely, don't quote literals against genuinely numeric columns — that forces the reverse coercion and defeats indexes. The rule is simply: literal type = column type. When in doubt, check `COLUMN_TYPE` in `INFORMATION_SCHEMA.COLUMNS` first (same first move as anti-pattern #3).

### 23. The broken row-index bridge — joining two systems on a shared sequence number (hit 2026-06-01 on the v1→v2 packaging CO₂/O₂ backfill)

When two tables are different materializations of the same upstream (here: v1 `bd_packaging` = RawDB import, v2 `bd_packaging_v2` = normalized rebuild of the *same* sheet), it is tempting to join them on a row-position column that *looks* like a shared key — `bd_packaging.sheet_row_index = bd_packaging_v2.source_sheet_row_index`. **Don't.** Each surface numbers its rows under its *own* scheme; a header row, a dropped blank, a re-sort, or a one-row materialization-twin shifts everything after it. Measured on this pair, the bridge was only **~43–46% semantically correct** — and the wrong half is invisible (the join *succeeds*, it just attaches the wrong run's data). A direct `v1.id = v2.id` "it's the same import so the ids line up" join is the same trap (disagreed on 372 rows here).

**Fix — join on the NATURAL business key, never the row position.** For packaging that was `(sku via neb_beer→ref_skus.sku_code, neb_batch, event_date)` on the neb lane and `(contract_beer, contract_batch, event_date)` on the contract lane. The natural key is stable across re-materialization because it's *what the row means*, not *where it sat*.

**And prove the map is a clean bijection before the write — this proof IS the gate.** A natural-key join can still be many-to-one or one-to-many; writing a backfill through an ambiguous map silently corrupts. Run a rolled-back dry-run transaction that checks, on the exact predicate the UPDATE will use:

```sql
-- forward: how many v2 candidates does each source row map to? (want: exactly 1)
SELECT n_candidates, COUNT(*) FROM (
  SELECT p.id, (SELECT COUNT(*) FROM bd_packaging_v2 v
                 WHERE v.row_origin='main' AND v.is_tombstoned=0
                   AND v.sku_id_fk=sku.id AND v.neb_batch=p.neb_batch
                   AND v.event_date=DATE(p.submitted_at)) AS n_candidates
    FROM bd_packaging p JOIN ref_skus sku ON sku.sku_code=p.neb_beer
   WHERE p.tank_co2 IS NOT NULL
) t GROUP BY n_candidates;          -- rows with n>1 are AMBIGUOUS → refuse, don't guess
-- reverse: do two source rows collide onto one v2 row? (the bijection check)
-- conflict-aware dimension build: only emit a shared read where the source agrees
GROUP BY <natural key> HAVING COUNT(DISTINCT ROUND(co2,3))<=1 AND COUNT(DISTINCT ROUND(o2,2))<=1;
```

Write ONLY the deterministic 1:1 subset; leave the `n>1` (ambiguous) and `n=0` (no counterpart) rows **semantic-NULL** with the original v1 provenance intact — that's refuse-don't-guess applied to a backfill (the operator's standing rule on mappings; see `feedback_no_extrapolating_mi_codes_from_patterns`). On this arc: 852 of 991 in-filling parents were a true 1:1 bijection (linked); 130 had no v2 counterpart, 9 were ambiguous (8 same-day cuv serving-tank runs + 1 AM/PM bottle split) → all left unlinked. The conflict-aware `HAVING` is the same idea on the dimension side: 178 contract lot-days, 4 with disagreeing reads same `(beer,batch,day)` → skipped, never averaged into a fake shared value. Record the frozen counts in the migration header so the apply is auditable against the proof.

### 24. Soft-delete / tombstone flag added but not enforced in every read path (hit 2026-06-01 on `bd_packaging_v2.is_tombstoned`)

Adding an `is_tombstoned` (or `is_active`, `deleted_at`) column does **nothing** until every consumer filters it — and "every consumer" usually spans more codebases than you remember. On this arc the flag existed but was missing from: the vendable VIEW (`v_bd_packaging_v2_vendable`), **16** read sites in `app/packaging-stats.php` (including the `_pkg_neb_where()` helper that several queries share), and the downstream **Node** `lib/beer-tax.js` (`loadInvendablesHL`, `loadContractPackagedHL`). Result: tombstoned rows stayed live in production/vendable/beer-tax — a latent double-count (here +41.69 HL of EPH materialization twins, which is *why* mig 249 had to hard-DELETE rather than tombstone: tombstoning wouldn't have removed the count).

**Fix — when you introduce a soft-delete flag, retrofit it as one atomic change:** grep **every** repo that reads the table (`grep -rn "FROM <table>\|JOIN <table>" --include="*.php" --include="*.ts" --include="*.js" --include="*.sql"`), add `AND <alias>.is_tombstoned = 0` to each (use `CREATE OR REPLACE VIEW` with byte-identical column exprs for views so downstream column order is preserved), and confirm with a count: rows visible to consumers must drop by exactly the tombstoned count. A shared `_where()` helper is the highest-leverage fix — patch the helper, not each call site. Don't forget cross-repo Node/TS readers; the DB-side view and the app-side query are two different enforcement points and both must hold.

### 25. Event-sourced replay: a DRAIN/release step gates on the STATIC brew-time binding instead of CURRENT occupancy (hit 2026-06-03 on `app/tank-simulator.php` CCT occupancy)

In an occupancy/inventory replay, state is rebuilt by walking events in time order. The trap: a *drain/release/evict* step keyed off a STATIC binding — "this batch was brewed into CCT-N, so a rack-out of this batch empties CCT-N" — instead of off the vessel's CURRENT occupant. `loadBatchCCT` maps `beer|batch → the vessel it was brewed into` and never changes; the racking branch nulled `cctState[cct]` with **no check that the CCT still held that batch**. So a late or out-of-order rack-out drains a vessel a *newer* batch has since refilled. Observed: a late Speakeasy-62 rack-out (2026-06-02) evicting Diversion-46 from CCT1, and Stirling-171 from CCT2 — two phantom-empty tanks. The BBT branch already had the guard; the CCT branch didn't — **one sibling branch missing the guard the other has IS the bug.**

**Fix — every drain/decrement/evict must verify the vessel's CURRENT occupant matches the event's identity before mutating**, else no-op:

```php
if ($cctState[$cct] !== null && $cctState[$cct]['occupant'] === [$recipeId, $batch]) {
    $cctState[$cct] = null;   // drain only what's actually there now
}                              // otherwise: a newer batch refilled it — leave it
```

**Key occupancy on the FK identity `(recipe_id_fk, batch)`, not a name (`beer`)** — batch numbers recur across recipes, so a name-keyed slot collides two different lots. Carry `recipe_id` on every event *and* every state slot (here: `loadRackingEvents` += `COALESCE(neb_recipe_id_fk, contract_recipe_id_fk)`, `loadCoolingEvents` += `recipe_id_fk`, `loadBatchCCT` returns `{cct, recipe_id}` + `ORDER BY event_date ASC`), with a `(beer, batch)` fallback only for sparse rows that genuinely lack the FK. **Verify a read-side replay fix with a BEFORE/AFTER full-replay diff:** assert ONLY the known-corrupted slots change and every other vessel is byte-identical (here: exactly 2 tanks corrected — CCT2→Stirling-171, CCT1→Diversion-46 — all other CCT/BBT unchanged, asserts pass) before shipping. The public return shape stays frozen (`recipe_id` additive only) so downstream readers (`loss-metrics.php` keys off `bd_*_v2` directly, doesn't read sim state) are unaffected. **PORTING DEBT:** the same lesson must reach the Node `parse-tank-simulation.ts` WIP/COGS path, or the PHP-UI and Node-WIP occupancy diverge for reused batch numbers (COGS-impacting).

### 26. A corrective UPSERT must preserve the original natural-key timestamp, or it INSERTs a duplicate instead of UPDATE-in-place (hit 2026-06-03 on the fermenting `?edit=` arc; proven cross-form brewing/packaging/fermenting)

The "Corriger une saisie" pattern re-opens a past `bd_*_v2` row, prefills the operator form, and re-POSTs through the **same** idempotent handler (no new endpoint). That handler resolves new-vs-existing via `bd_upsert`, whose `ON DUPLICATE KEY UPDATE` fires only when the incoming row's **natural key** collides with the stored one. On these forms `submitted_at` is *part of that natural key*. So the load-bearing rule: in edit mode you must reuse the **original** `submitted_at`, not stamp a fresh one.

```php
// the one line the whole correction arc hinges on:
$submittedAt = $isEditMode ? $editSubmittedAt : date('Y-m-d H:i:s.u');
```

If a developer leaves the unconditional `date('Y-m-d H:i:s.u')` in place "because it's a write", the corrected row arrives with a *new* NK → no collision → `bd_upsert` **INSERTs a second row** and the operator's "fix" silently doubles the event (and any COGS/tax/occupancy quantity it carries). The original is never touched; both rows are live. This is invisible to a single happy-path test because the form *looks* like it saved.

**The original `submitted_at` is a hidden form field — validate it before you trust it.** It round-trips through the browser (`<input type="hidden" name="edit_submitted_at">`), so a garbage or attacker value would poison the NK or the comparison. Strict-regex it to the exact `Y-m-d H:i:s.u` shape (and `edit_id` to digits) on the way back in; reject anything else rather than fall through to a fresh stamp.

**Two companions that travel with this pattern (same forms):** (a) load the original row's `session_id_fk` and, if it's session-linked and the session is `status != 'open'`, **refuse** the edit ("correction verrouillée") — a correction must never reopen a closed/fiscal session; a `session_id_fk IS NULL` (legacy) row proceeds unlocked. (b) **skip the lifecycle advance** (`session_advance_phase` / step-emit) in edit mode — a correction is a value fix, not a new event, so it must not push the session forward a phase.

**The verification recipe IS the proof — a synthetic, self-cleaning 1-row/2-row test.** Insert a throwaway row, run the edit path twice and assert the row count:

```sql
-- after the PRESERVED-submitted_at path: expect exactly 1 row, id unchanged, only the edited column changed
SELECT COUNT(*), MIN(id)=MAX(id) AS id_stable FROM bd_fermenting_v2 WHERE <nk minus submitted_at>;
-- after a deliberately-WRONG fresh-submitted_at path: expect 2 rows — this demonstrates the guard's necessity
-- then DELETE the synthetic rows and assert COUNT(*) = 0  (test-fixtures-must-self-clean)
```

The preserved path proving exactly 1 row is the green light; the wrong path proving 2 is what makes the guard's value legible to the next reader. Check `schema_meta.corrections_policy = 'allowed'` for the table before wiring any edit path at all — a correction surface on a write-locked table is itself the bug. (`event_date` is safe to edit on the `bd_*_v2` family — the occupancy/cost anchors live elsewhere — so corrections may freely change it.)

### 27. Route / detect-phase / firewall a form on the canonical FK, never on a free-text identity label that fragments across spellings (hit 2026-06-03 on the fermenting beer-identity fracture; READ-side companion to #25; also the packaging-drain keying bug 2026-06-04)

#25 is the *replay/write* side of this rule; #27 is the *form/read* side. On an operator session form the chain is: a selector **card** → a **lot-form** handoff → server **phase detection / firewall / CCT-or-tank lookup** → a **write**. The trap is keying any of the READ steps on a free-text identity column — `beer`, `beer_raw`, a short-code string — instead of the canonical `recipe_id_fk` the card already carries. When the same physical `(recipe, batch)` has been written under **multiple spellings** in that label column, a label-keyed read **splits the batch's events across spellings**: phase mis-detects, the firewall mis-fires, the session opens with `recipe_id_fk = NULL`.

Observed (fermenting, 2026-06-03): batch EPH2/26's `bd_fermenting_v2` events were split across two `beer_raw` spellings. The in-progress/end `<select>` re-derived phase by counting events keyed on `beer_raw + batch`, so it under-counted, stuck the lot at `in_progress`, and left `cct` unresolved. A phantom open session (id=28, 0 linked events) showed in the recent list.

**Fix — re-key every READ step on `(recipe_id_fk, batch)` when recipe_id is known; keep the label as a *fallback* only for legacy rows that truly lack the FK:**

```php
if ($ff_recipeId !== null) {
    // canonical path — immune to label fragmentation
    $stmt = $pdo->prepare("SELECT … WHERE recipe_id_fk = ? AND batch = ? …");
    $stmt->execute([$ff_recipeId, $ff_batch]);
} else {
    // legacy fallback — only when the FK genuinely can't be resolved
    $stmt = $pdo->prepare("SELECT … WHERE beer_raw = ? AND batch = ? …");
    $stmt->execute([$ff_beer, $ff_batch]);
}
```

The card must **carry** recipe_id (`data-recipe-id`) and the form must **USE** it (hidden `recipe_id_fk`), not re-derive identity from the string. The card-click nav must propagate `recipe_id` in the URL so the lot form lands with the FK in hand. Display-filter phantom 0-linked-event open sessions out of the recent list.

**The bug only bites where fragmentation AND label-keying COINCIDE.** A label-keyed read is *safe* when its label column is canonical:
- **brewing** *originates* the pairing — the recipe `<select>` option `value` is the `ref_recipes.name` and its `data-recipe-id` syncs the hidden `recipe_id_fk`; `beer` is never free-typed, so the intra-submission `(beer,batch)` self-joins to sibling ingredient/gravity/timing rows are internally consistent. Brewing is the *upstream that prevents* fragmentation — SAFE.
- **racking** keys its candidate builder on `bfw.recipe_id_fk` (JOIN `ref_recipes`), cards carry `data-recipe-id`, `racking-form.js` sets hidden `neb_recipe_id_fk` + `applyQcThresholds(recipeId)` from the card, the override list dedups on `('cct', source_cct)`, and **phase is read straight off `op_sessions.phase`** (an authoritative server state-machine value, not a string-derived count). The `<select>`s are tank/destination/cause, never identity. SAFE — it is the canonical model the buggy form was *meant* to mirror.
- **packaging** cards carry `data-recipe-id`; the SKU mosaic, in-tank read resolver (`recipe_id_fk + neb_batch`), and session open all key on `recipe_id_fk`. Its server `resolve_packaging_sku_id()` *does* have a string step (`neb_beer → ref_skus.sku_code`) tried before the `(recipe_id, format_code)` JOIN — but in this form `bd_racking_v2.neb_beer` holds recipe *display names* ("Zepp", "EPH2", …) and **0 of the 17 distinct values collide with any `sku_code`** (probed live), so the string step never fires for the card path and always falls through to the canonical recipe-id JOIN. Theoretically-imperfect (a future name that equals a sku_code would wrong-resolve) but **currently safe** — flag, don't churn.
- a **contract lane** that has no `recipe_id_fk` by design keys on `(contract_beer, contract_batch)` — that string pair *is* the canonical contract identity, not a fragmentable label. SAFE.

**Proof gate — a full batch-census dry-run, 0 regressions.** Before shipping a read-side re-key, replay **every** `(recipe, batch)` pair through the new keying and diff against the old resolution; assert the only changes are the known-corrupted batches and **everything else is byte-identical** (fermenting: 812 pairs, 0 regressions; EPH2/26 flips `in_progress`→`end`, `cct=8` resolves). When you fix one form, **audit its siblings in the same pass** and record each as affected-or-safe-and-why (don't leave the next reader to re-discover that racking/packaging/brewing were already clean). Read-side only — no migration, write natural-key untouched, simulator untouched.

**Concrete worked example — TankSimulator packaging-drain keying (2026-06-04).** The tank simulator's PACKAGING case matched packaging events to BBT slots using `(normalized_beer_name, batch)` as the lookup key. `bd_packaging_v2.neb_beer` frequently holds **SKU codes** (`STI4`, `SPYB`, `EMB4C`, `SPY4`, etc.) instead of canonical names (`Stirling`, `Speakeasy`). `normalizeBeerName()` passes unknown strings through unchanged — so the lookup key was `STI4|170`, which never matched the racking entry's key `Stirling|170`. Result: those packaging events were **silently dropped** from the BBT depletion — 7 BBTs showed grossly overstated volumes and wrong beer identities (BBT2=SPY/62 instead of Diversion/45, BBT5=STI/166 instead of Speakeasy/64). **Fix:** key packaging drain on `(recipe_id_fk, batch)` — immune to all name fragmentation: `$batchBBT['rid:'.$recipeId.'|'.$batch]` with `(beer,batch)` name-key fallback for the rare NULL `recipe_id_fk` edge. Both keys stored; PACKAGING lookup tries `rid:` first. Same pattern is now used symmetrically across COOLING, RACKING, and PACKAGING. Verified with BEFORE/AFTER full-replay diff: only the 7 corrupted BBTs + CCT9 changed; all other tanks byte-identical. **Rule: batch numbers recur across recipes** (e.g. Zepp batch 45, Speakeasy batch 45, Diversion batch 45 are three distinct physical brews); `(recipe_id_fk, batch)` is the true composite identity; a name-only key is always wrong in a multi-recipe brewery where batch counters are per-recipe.**Also: `start_time` can be corrupt (DD/MM↔MM/DD swap from BSF-era ingest)** — for any event-sourced table, prefer `event_date` (operator-entered physical date, always correct) over `COALESCE(start_time, submitted_at)` as the canonical sort/lookup key.

### 28. A WRITE-TIME validation / cadence / duplicate gate that does not skip edit/correction mode (hit 2026-06-03 on the fermenting purge-cadence fix; audited cross-form same pass)

#27 protects the READ steps (phase-detect, candidate-list, firewall lookup). #28 and #29 are the two faults of a **WRITE-TIME gate** — the `if (…) redirect_to($base)` block that decides whether *this submission* is allowed to land. On an operator form the same lot-form is reused for first-entry AND for `?edit=` corrections (the corrective-upsert of #26). A gate written for the first-entry case will, in edit mode, **fire against the very row being edited** — the operator corrects a typo on yesterday's purge and the cadence/duplicate gate blocks the correction because "a purge already exists for this batch within N days" (it's the row they're editing) or "this `(beer,batch)` already exists" (again, the row they're editing).

**Fault:** the gate runs unconditionally. **Fix:** the FIRST line of any write-time validation/cadence/too-recent/duplicate-guard block is an edit-mode bypass —

```php
// A1: skip entirely in edit mode — a correction is not a new event.
if ($eventType === 'Purge' && !$isEditMode) {
    … cadence query + 409/redirect …
}
```

Plus **defense-in-depth: self-exclude the edited row** from the gate's own query even on the first-entry path, so a re-POST that resolves to the same PK can never count itself: `… AND id <> ?` bound to `edit_id`. A corrective UPSERT that lands on the original natural key (#26) must not be seen by the gate as a *new, additional* occurrence.

**This is NOT "weaken the gate."** First-entry submissions still get the full check. The bypass is scoped to corrections, which by definition re-state an already-accepted fact. Brewing's confirm-overwrite guard is the *correct* shape of this: it gates accidental NEW overwrites of an existing `(beer,batch)` but lets an explicitly-confirmed re-submit through — the confirmation IS the edit path. Racking has no edit mode at all (insert-only form; the daily-shell end-phase UPDATE is keyed on the linked row id, not re-validated by a cadence gate) — SAFE by absence.

### 29. A "minimum-interval / too-recent / cadence" gate keyed on `submitted_at` / `NOW()` / `time()` instead of the business `event_date` is backfill-hostile (hit 2026-06-03 on the fermenting purge-cadence fix; audited cross-form same pass)

A cadence gate exists to enforce a **physical-process interval** ("≥ N days must pass between two purges", "≥ N days between racking and packaging", "≥ garde days after cold crash"). That interval is a property of when the events *physically happened* — the `event_date` the operator types — NOT of when the row was *recorded*. Keying the gap on `submitted_at`/`NOW()`/`time()` corrupts it two ways:

1. **Backfill breaks after the first row per batch.** When the operator backfills a week of historical purges in one sitting, every row carries today's `submitted_at`. Gap-by-`submitted_at` reads "0 days apart" for every pair regardless of their real `event_date`s → the 2nd, 3rd, … backfilled rows all trip "too recent" and get rejected. The batch's history is un-enterable.
2. **It measures the wrong thing even live** — two events entered minutes apart but dated a week apart would wrongly block; one entered late for an event a month ago would wrongly pass.

**Fix — compute the gap on `event_date`, key the lookup on the canonical FK (#27), and exclude the edited row (#28):**

```php
// nearest OTHER purge for this batch, by event_date (NOT submitted_at),
// keyed on recipe_id_fk when known, label fallback only when NULL.
$sql = "SELECT event_date FROM bd_fermenting_v2
         WHERE recipe_id_fk = ? AND batch = ? AND event_type='Purge'
           AND is_tombstoned=0" . ($editId ? " AND id <> ?" : "");
// gap = abs(new_event_date − nearest_other_event_date) in days; compare to threshold.
```

The SQL-DB gates that are **already clean** (audited in the same pass, record so the next reader doesn't re-discover): **racking garde gate** (`DATEDIFF(CURDATE(), MAX(f.event_date)) >= effective_garde`, candidate-list builder on GET, keyed `recipe_id_fk + batch`) and **packaging `min_days_after_racking`** (`r.event_date <= DATE_SUB(CURDATE(), INTERVAL ? DAY)`, candidate-list builder on GET, keyed on tank + racking `event_date`). Both compare `event_date` (✅ #29), both are GET-side **candidate-list builders** that populate the eligible-lot picker — they never run on a correction submit (✅ #28 N/A), and both use `CURDATE()` (today) only as the *upper anchor* of "has enough time elapsed as of now", which is correct because eligibility-to-act-today legitimately depends on today; that is distinct from measuring the *interval between two recorded events*, which must use only their `event_date`s.

**The whole-family caveat (shared with #27): a gate is only buggy where the fault AND its trigger COINCIDE.** Fault 28 bites only if corrections actually flow through that gate; fault 29 bites only if backfill of multiple same-batch events actually happens. A candidate-list builder on GET has neither trigger, so it is SAFE even though it superficially "compares a date" — do **not** churn it. Genuinely-exploitable = (write-time gate) × (edit mode reuses the same handler) × (cadence keyed on record-time) × (operator backfills). The fermenting purge gate had all four → fixed (A1 edit-skip, A2 event_date gap, A3 recipe_id_fk key + self-exclude, A4 override-with-reason intact). When you fix one form's write-time gate, **audit every sibling form's write-time gates in the same pass** and record each affected-or-safe-and-why.

### 30. Packaging parallel-run leg mis-attributed to a different recipe (hit 2026-06-05 on `scripts/normalize-rawdb.py` Mode A; WRITE-side companion to #27)

La Nébuleuse bottles **parallel runs** — one physical packaging session fills BOTH the -4 (6×4 carton) and -B (24-pack) SKU of the **same** beer in sequence. The two legs share a single `recipe_id`; only the format suffix flips (4↔B). The legacy v1 source of truth is `bd_packaging.second_packaging` / `hl_second_packaging`.

**Problem.** `process_packaging_data` (Mode A) resolved the parallel leg's beer from a free **`Selection Recette`** source field. Operators sometimes fill that field with the wrong beer. Result: the parallel leg was bound to a *different* recipe — e.g. DIV4/45's -B leg was filed as SPYB (Speakeasy, `recipe_id=51`). Volume was captured but mis-attributed → the wrong BBT was drained in TankSimulator (BBT2 read +15 HL too full) AND `vendable_hl`/beer-tax base was credited to the wrong beer. COGS- and tax-impacting.

**Rule.** Any code that normalizes, derives, or emits a packaging parallel leg **must**:

1. **Emit both legs** (main + parallel) from the same source row.
2. **Keep the parallel leg's `recipe_id_fk` identical to the main** — unconditionally. Never re-derive it from `Selection Recette` or any other secondary field that can disagree.
3. **Derive identity from the main + suffix-flip** — the only thing that changes on the parallel leg is the format code (the suffix `4`→`B` or `B`→`4`).
4. **Reconcile against v1 `first_packaging` / `second_packaging`** as the acceptance gate; a parallel leg whose `recipe_id` disagrees with v1 is a normalizer error, not a source-data override.

Generalised principle: **derive a child/sibling record's identity by FK from its canonical parent; never by an independent lookup that can diverge.**

**Worked example (the actual bug):**

```python
# BROKEN — parallel leg resolves its own beer independently
par_recipe_id = resolve_recipe(row["Selection Recette"])   # can be WRONG beer

# FIXED — parallel leg inherits parent unconditionally
par_recipe_id = main_recipe_id                             # always
if row["Selection Recette"] and resolved != main_recipe_id:
    flags.append("sel_recette_overridden_to_main")         # flag divergence for audit
```

**Fix reference.** Mig 265 corrected 4 mis-attributed rows surgically (row_hash recomputed via the Python json scheme — normalizer-origin rows use the dual-hash-scheme convention). The normalizer Mode A was rewritten so `par_recipe_id = main_recipe_id` unconditionally; when `Selection Recette` disagrees it sets `sel_recette_overridden_to_main=True` on the row for traceability. **No re-normalize was run** — `RawDB-normalized.xlsx` is the frozen migration seed; re-running would catch up zero live data and clobber web_entry rows. Surgical DB correction is the correct channel for normalizer-origin mistakes once the seed has been uploaded.

### 31. One form card that legitimately emits MULTIPLE rows collides on the natural key — add a distinguishing token or `bd_upsert` silently clobbers one leg (hit 2026-06-05 on the packaging white-label split; the INVERSE of #26)

#26 is about edit-mode reusing the **same** `submitted_at` so a correction collides-on-purpose and UPDATEs the original. #31 is the opposite need: when a **single** operator card is split server-side into **two distinct rows** that must BOTH persist, they share most of the natural key and will collide *by accident*.

**Setup.** The packaging white-label feature lets one format card declare an additive white-label leg: on POST the handler emits the card's own Nébuleuse row PLUS a second appended row for the white-label units (`is_white_label=1`, its own `prod_total_units = wl_units`, `sku_id_fk` copied from the card). `bd_packaging_v2`'s `uq_natural_key = (submitted_at, neb_beer, neb_batch, contract_beer, contract_batch, row_origin, nebuleuse_format_suffix)`.

**Problem.** When the carved card is itself a **parallel** format line, BOTH the card leg and the white-label leg are `row_origin='parallel'`, same beer/batch/`submitted_at`, **same format suffix** → identical NK. `bd_upsert`'s `ON DUPLICATE KEY UPDATE` then fires on the second insert and **silently overwrites the first leg** — half the session's volume vanishes from `vendable_hl`/beer-tax with no error. Invisible to a main-card-only test (there the legs differ on `row_origin` = main vs parallel, so the bug hides). The dangerous case is **carve-on-a-parallel-card**.

**Rule.** Whenever one input deliberately fans out to N rows sharing a UNIQUE/natural key, give each emitted row a **distinguishing token inside the NK**. Pick a column that (a) is part of the NK, (b) carries no aggregation/grouping semantics you'd pollute, and (c) the read side tolerates. Here: append `-WL` to `nebuleuse_format_suffix` on the white-label leg (`B` → `B-WL`). Before choosing the marker column, **grep every consumer** — `nebuleuse_format_suffix` was safe because nothing aggregates on it (the `(recipe,suffix)` SKU fallback is bypassed by copying the leg's `sku_id_fk` explicitly; no report groups by it; the edit-reload strips the `-WL` for display). Had a report grouped by suffix, that marker would have polluted it — the column choice is load-bearing, not arbitrary.

**Test that actually catches it:** submit a card that is *already a parallel format line* with a carve-out, then assert BOTH rows survive as distinct PKs (the NK genuinely differs on the marker, neither was upserted away). A happy-path main-card test passes while the bug is live.

**Additive vs subtractive footnote (same incident).** The split was first built subtractive (card total − wl = remainder) then changed to **additive** on operator round-trip (card field = the Neb run as-is; wl is a separate leg added on top; session total = card + wl). Additive is cleaner: it collapses into the existing parallel-leg "ADD not subtract" convention with zero new arithmetic, so every consumer that already `SUM`s per-row `prod_total_units` is correct for free — but it makes #31 *more* exposed, because under additive both legs carry their own full qty as `row_origin='parallel'` and the suffix marker is the **sole** thing keeping them apart.

### 33. A ledger credit/avoir line is NOT a physical event — same-day booking reversals pollute any event-derived metric (hit 2026-06-16 on the returned-FG metric; corrected a "return rate" 3.6× too high). Companion to #32 (which covers the burn/depletion side; this is the returns side).

**Relation to #32.** #32 establishes that for *net depletion* you SUM all doc_types because credit/return rows net correctly (a ±N reversal pair self-cancels — burn is unaffected, leave it). This entry is the inverse hazard: when you want the *gross physical return count* (not a net), those same self-cancelling reversal credits must be EXCLUDED, because each is counted on its own as a "return" that never happened.

**Setup.** `inv_sales_ledger` is the BC item-ledger mirror: `doc_type IN ('shipment','invoice','credit','return_receipt')`, `qty_signed` negative for sales / positive for credits. The returned-FG arc derived "physical returns" = beer-SKU credit/return_receipt lines (`qty_signed>0`, `ref_skus.recipe_id IS NOT NULL`). Return rate came out 6.6% — operator's gut said "that's too many."

**Problem.** **~72% of those credit lines (10,662 of 14,712 units / 365d) were SAME-DAY BOOKING REVERSALS, not physical returns.** Business Central's normal posting workflow is post a shipment → immediately credit it → re-invoice (price fix, doc correction, re-bill) — three lines, **same `posting_date`, same `customer_id_fk`, same `sku_id_fk`**, the credit's `+qty` exactly offsetting the shipment's `−qty`. Counting the credit as a "return" double-counts a clerical churn that never moved a keg. The reversal is **indistinguishable by `doc_type_raw`** (both real returns and reversal credits read "Avoir vente"), and there is **no reversed-document FK** (`bc_source_no` is a customer number — 0 joins). So the only signal is the structural same-day opposing-sign triple.

**Why it's a class, not a one-off.** Any metric built on a financial ledger's *credit/contra/reversal* rows inherits this: the ledger faithfully records bookkeeping motion (corrections, re-bills, rebates), which is a superset of the *physical* event you want. AR write-offs, inventory adjustment reversals, refunded-then-rebilled invoices — same shape. The ledger is correct; your event filter is naive.

**Rule.** When deriving a *physical* / *operational* count from a *financial* ledger, exclude bookkeeping reversals structurally, and **encapsulate the filter in ONE canonical SQL VIEW** so every consumer (rate, watchlist, queue, KPI) reads the same definition — never copy the WHERE clause into each query (it drifts; see the canonical-list-call rule).

```sql
CREATE OR REPLACE VIEW v_physical_returns AS
SELECT l.*
FROM inv_sales_ledger l
WHERE l.doc_type IN ('credit','return_receipt')
  AND l.qty_signed > 0
  AND l.sku_id_fk IS NOT NULL
  -- exclude same-day booking reversals: a shipment/invoice on the SAME day,
  -- SAME customer, SAME sku with the opposing sign = clerical churn, not a return
  AND NOT EXISTS (
    SELECT 1 FROM inv_sales_ledger s
    WHERE s.posting_date    = l.posting_date
      AND s.customer_id_fk  = l.customer_id_fk
      AND s.sku_id_fk       = l.sku_id_fk
      AND s.doc_type IN ('shipment','invoice')
  )
  AND NOT EXISTS ( /* rebate-tagged lines — financial-only, not physical */ … );
```

Index the reversal check: `(customer_id_fk, sku_id_fk, posting_date, doc_type)` (the correlated subquery probes exactly that tuple — covering composite, ~1505 rows pass on a 44k-row ledger). Result: rate **6.60% → 1.818%** (14,712 → 4,050 real units), and a per-customer watchlist that had been topped by a 82.3% "returner" (pure reversals) cleared to genuine over-shippers.

**Read-time now → ingest-time later.** Same-day-opposing-sign detection at *read* is correct but recomputed every query. When you control ingest (here: the BC-API auto-pull, a later arc), promote it to a stored fact — an `is_reversal` / `reversed_doc_no` flag stamped at load — and flip the view body to read the flag. The view is the seam that lets you change the *how* without touching any consumer.

**Verification recipe.** Quantify the pollution before and after as a sanity gate: `SELECT SUM(qty) FROM <naive>` vs `SELECT SUM(qty) FROM v_physical_returns` — if the view drops ~70% you've confirmed the reversal hypothesis, not silently under-counted real returns. Spot-check one big "returner": pull its raw same-day triples and eyeball that the credits pair to shipments.
