# SQL performance & operations

Loaded on demand from the `sql` skill. The anti-patterns catalog is about *correctness*; this file is about *performance and operability* — the things the codebase currently does blind (zero EXPLAIN, all `conn.query`, no deadlock retry, no observability). On a single VPS these bite at production scale.

## Query-plan discipline (read the plan, don't guess)

Run `EXPLAIN ANALYZE` (MySQL 8 — real timing + actual-vs-estimated rows) on any query with ≥2 joins, a window function, or a subquery **before** it ships to a PHP page or report builder.

Red flags in the plan:
- `type: ALL` — full table scan on a table that should hit an index
- `Using filesort` **and** `Using temporary` together — usually a missing composite index for the GROUP BY/ORDER BY
- `rows` estimate >10× off actual — stale stats (`ANALYZE TABLE t`) or a non-sargable predicate (function on the indexed column, leading `%` LIKE)

```sql
EXPLAIN ANALYZE
SELECT ... FROM inv_deliveries d JOIN ref_mi m ON m.id = d.mi_id_fk
 WHERE m.is_inventoried = 1 AND d.status = 'Active';
-- a "Table scan on d" here = missing index on (status, mi_id_fk)
```

## Indexing strategy

"Index every FK" is not enough. Doctrine:

- **Composite column order = equality columns first, then the range/sort column.** `WHERE status='Active' AND mi_id_fk=? ORDER BY delivery_date` → `KEY (status, mi_id_fk, delivery_date)`. Leftmost-prefix rule: that one index also serves `WHERE status=?` alone.
- **Covering index** for a hot read selecting few columns — include them so the plan shows `Using index` (no row lookup): WAC reads of `(mi_id_fk, period, wac_chf, qty_remaining_at_close)`.
- **Selectivity:** never index a low-cardinality column alone (`is_inventoried` TINYINT, `currency`) — it earns its keep only as the *trailing* part of a composite.
- **When NOT to index:** every index taxes INSERT/UPDATE on the append-heavy `inv_*` / `bd_*` / `doc_*` tables. Don't add one you can't tie to a real query in a plan. Find dead weight: `SELECT * FROM sys.schema_unused_indexes`.
- **Redundant/overlapping indexes** accumulate as migrations add keys — a short index whose columns are an exact left-prefix of a longer composite is dead weight. Detect them without an external tool, then `DROP INDEX` the prefix (INSTANT) after confirming it's not separately used for range scans:
  ```sql
  SELECT a.TABLE_NAME, a.INDEX_NAME AS redundant,
         GROUP_CONCAT(a.COLUMN_NAME ORDER BY a.SEQ_IN_INDEX) AS redundant_cols,
         b.INDEX_NAME AS covered_by
    FROM information_schema.STATISTICS a
    JOIN information_schema.STATISTICS b
      ON b.TABLE_SCHEMA=a.TABLE_SCHEMA AND b.TABLE_NAME=a.TABLE_NAME
     AND b.INDEX_NAME<>a.INDEX_NAME AND b.SEQ_IN_INDEX=a.SEQ_IN_INDEX AND b.COLUMN_NAME=a.COLUMN_NAME
   WHERE a.TABLE_SCHEMA='maltytask'
   GROUP BY a.TABLE_NAME, a.INDEX_NAME, b.INDEX_NAME
  HAVING COUNT(*)=(SELECT COUNT(*) FROM information_schema.STATISTICS x
                    WHERE x.TABLE_SCHEMA=a.TABLE_SCHEMA AND x.TABLE_NAME=a.TABLE_NAME AND x.INDEX_NAME=a.INDEX_NAME);
  ```

## Transactions, locking & deadlock retry

The doc-files / doc-ambiguous / review-queue repos take row+gap locks via `SELECT … FOR UPDATE` inside `withTransaction`, but **nothing retries on deadlock**. Under concurrent ingest, errno 1213 (deadlock) and 1205 (lock-wait-timeout) surface as hard failures.

Wrap transaction bodies in a bounded retry — replay the **whole** transaction (its partial state was rolled back), only on those two errnos, with backoff+jitter:

```ts
for (let attempt = 1; ; attempt++) {
  try { return await withTransaction(fn); }
  catch (e: any) {
    if ((e.errno === 1213 || e.errno === 1205) && attempt < 3) {
      await sleep(50 * 2 ** attempt + Math.random() * 50); continue;
    }
    throw e;   // never retry non-lock errors
  }
}
```

**Gap-lock pitfall (default REPEATABLE READ):** `SELECT … FOR UPDATE` with a *range* or *non-unique-index* WHERE locks the gaps, blocking concurrent inserts into that range. Keep `FOR UPDATE` predicates on the **unique key** (`file_id`, `row_hash`, `queue_id`) — which the repos already do; that's *why*.

## `conn.execute` vs `conn.query` (server-side prepared statements)

In mysql2, `conn.query(sql, params)` does **client-side** escaping + string interpolation — no server-side prepare, weaker injection posture. `conn.execute(sql, params)` sends `COM_STMT_PREPARE` once, binds server-side, reuses the plan. **Default to `execute`** for any parameterized statement, especially hot read paths and per-row ingest loops. Reserve `query` for DDL, `SET`, and statements with no user input. Caveat: `execute` can't bind identifiers or `LIMIT`/`OFFSET` — whitelist those (anti-pattern #9), never interpolate.

## Online / INSTANT DDL on growing tables

The algorithm ladder, fastest → most disruptive: **`INSTANT`** (metadata-only — adding a column at the end with a default, 8.0 default where possible) → **`INPLACE`** (rebuild, allows concurrent DML — add/drop index) → **`COPY`** (locks, blocks writes — `CONVERT TO CHARACTER SET`, column-type change, add-column-not-at-end).

Force the assertion so a COPY can't slip through silently on a big table:
```sql
ALTER TABLE t ADD COLUMN x INT, ALGORITHM=INSTANT;   -- fails loudly if INSTANT impossible
```
For unavoidable rebuilds on large tables, `pt-online-schema-change` / `gh-ost` are the standard escape hatch (trigger/binlog copy + live cutover).

**Expand-Contract (the 4-phase protocol for a backward-incompatible column change).** Never change-in-place a column running code depends on; sequence it across separate migration files + deploys:
1. **EXPAND** — add the new column (nullable / default), `ALGORITHM=INSTANT`. Deploy code that dual-writes old+new.
2. **BACKFILL** — batched `UPDATE … LIMIT 5000` loop (own migration file) to populate the new column. No lock storms.
3. **TRANSITION** — deploy code that reads the new column and stops writing the old.
4. **CONTRACT** — drop the old column, in a *separate* file filed ≥1 deploy after TRANSITION is confirmed stable. This is the irreversible step — backup first.

EXPAND and CONTRACT are distinct `schema_migrations` rows on purpose: if you must roll back EXPAND, CONTRACT hasn't run yet, so nothing is lost.

## Generated columns & functional indexes

- **VIRTUAL** (computed on read, indexable, zero storage) for cheap expressions you filter/sort on; **STORED** only when the expression is expensive or backs an FK/UNIQUE that needs stability — `dedup_key AS (CONCAT(file_id_fk,':',line_index)) STORED` is correctly STORED because a UNIQUE depends on it.
- **Functional index** (8.0.13+) makes an expression sargable without a shadow column: `ADD KEY idx_yr ((SUBSTRING(period,1,4)))`, `ADD KEY ((LOWER(name)))`.
- A generated column bakes its expression in permanently (see anti-pattern #17's `final_qty` footgun) — design it as carefully as a query.
- **Sparse "partial index" (MySQL has no `CREATE INDEX … WHERE`).** To index only a hot subset, project the off-rows to NULL in a VIRTUAL column and index that — NULLs aren't stored in the B-tree, so the index stays small: `active_mi_fk INT UNSIGNED AS (IF(status='Active', mi_id_fk, NULL)) VIRTUAL, KEY idx_active_mi (active_mi_fk)`. Worth it only when the filtered subset dominates reads *and* the status column is low-cardinality — verify the Active fraction first; otherwise the plain composite `(status, mi_id_fk)` wins.
- **"One-active-X-per-(parent, child)" invariant** — for any "at most one row satisfying predicate P per (key1, key2)" rule (one open mother per (recipe, batch); one active session per (vessel_kind, vessel_number); one pending invoice per (supplier, ref)), materialize the predicate as a VIRTUAL CHAR(1) that's `'1'` when true and NULL otherwise, then `UNIQUE KEY (key1, key2, active_marker)`. MySQL treats NULL as distinct in UNIQUE indexes, so inactive rows coexist freely while active ones collide on the composite. VIRTUAL (not STORED) so UPDATEs that flip the predicate re-derive + revalidate automatically — no trigger, no app guard. Constraint: the predicate must reference only same-row columns (VIRTUAL forbids subqueries/JOINs). MySQL 8 doesn't accept `CASE WHEN…END` inline in a functional UNIQUE index — the VIRTUAL column is the workaround, not a verbosity. Live-verified pattern: migration 215 mother-shell (insert second active → 1062; flip first to inactive → insert succeeds).

## Constraints as data integrity (CHECK + FK semantics)

- **CHECK:** write them `ENFORCED` from the start — `NOT ENFORCED` is documentation that lies (migration 109 shipped three NOT ENFORCED and guaranteed nothing for weeks). Verify violation count = 0 before enforcing on existing data. Name them schema-uniquely (table-prefix). Use a CHECK for the "exactly-one-of N mutually-exclusive FKs" identity pattern — that's how you enforce the NULL discipline above.
- **FK ON DELETE:** decide per relationship. `RESTRICT` (default) for reference data you must never orphan (`ref_mi`, `ref_suppliers`). `CASCADE` only for truly-owned junction children. `SET NULL` only for *semantic*-nullable back-links. **Never CASCADE toward `inv_*` / `derived` tables** — it can mass-delete COGS history.

## JSON columns

`audit_row_revisions.before_json/after_json` and the JSONL audit log are JSON. Prefer the native `JSON` type (implicit validation + binary storage) over `TEXT`. You **cannot index a JSON column directly** — to filter inside it, promote the hot key to a generated column + index (`reason VARCHAR(64) AS (after_json->>'$.reason') VIRTUAL, KEY (reason)`); use `->>'$.path'` for unquoting extraction. Don't use JSON as a schema dodge for stable-shape data — that's what the typed `ref_*` tables are for.

## Modern CTE & window-function idioms

- **Latest-row-per-group** (replaces correlated subqueries / self-joins — e.g. anti-pattern #14's `n_brews`): `ROW_NUMBER() OVER (PARTITION BY beer, batch ORDER BY event_date DESC)`, then filter `= 1` in an outer CTE (MySQL has no `QUALIFY`).
- **Running total / FIFO** (anti-pattern #10, WAC): `SUM(qty) OVER (PARTITION BY mi_id_fk ORDER BY delivery_date ROWS UNBOUNDED PRECEDING)`.
- **Recursive CTE** for a `period='YYYY-MM'` date-spine (month-over-month gap-fill) and recipe/BOM explosion; default cap `cte_max_recursion_depth=1000`. MySQL doesn't materialize CTEs — one referenced 2+ times may be re-evaluated; push it to a temp table if the plan shows that.

## Observability & diagnostics on the VPS

When something's slow on the live single VPS:
```sql
-- top offenders, no log scraping:
SELECT DIGEST_TEXT, COUNT_STAR, ROUND(SUM_TIMER_WAIT/1e12,2) total_s,
       ROUND(AVG_TIMER_WAIT/1e9,2) avg_ms, SUM_ROWS_EXAMINED
  FROM performance_schema.events_statements_summary_by_digest
 ORDER BY SUM_TIMER_WAIT DESC LIMIT 15;
```
- Slow log on demand: `SET GLOBAL slow_query_log=ON; SET GLOBAL long_query_time=0.5;`
- `sys` helpers: `sys.statements_with_full_table_scans`, `sys.schema_unused_indexes`, `sys.innodb_lock_waits` (live deadlock/lock diagnosis).

## Security: least-privilege grants & injection surface

- **Separate DB roles** (zero GRANTs exist in migrations today): a read-mostly account for PHP read paths (`SELECT` + `INSERT`/`UPDATE` only on operator-form tables); a DDL account for `migrate.php`; write/backfill scripts behind the write account — so a compromised web layer can't `DROP` or touch `wac_snapshots`. This is `schema_meta.corrections_policy` enforced at the privilege layer.
- **Injection beyond binds:** the residual surface is **identifiers that can't be bound** — `ORDER BY` / column / table names from `$_GET` (anti-pattern #9's bug class). Identifiers go through a hard whitelist, never concatenation. Dynamic `IN (…)` lists → build `?`-placeholder tuples, never string-join values.

## Backup & recovery awareness

`backup.sh` is tiered, encrypted, off-site **mysqldump** (logical) — but there's no binlog/PITR. Know the recovery floor: with hourly logical dumps and no binary log, RPO is **~1 hour** of invoice/delivery writes. If that's unacceptable, enable `log_bin` + `binlog_format=ROW` so you can replay past the last dump.

- **Before any destructive migration** (drop/rewrite/`CONVERT TO`/type-change on a `source`/`derived` table), trigger an on-demand `backup.sh` and confirm it validated *first* — don't rely on the next scheduled dump.
- A backup you've never test-restored is a hope, not a backup. `restore.sh` exists; periodically restore into a throwaway schema and diff row counts.
