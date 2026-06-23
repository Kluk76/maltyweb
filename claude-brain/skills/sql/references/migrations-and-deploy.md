# SQL migrations & deploy mechanics

Loaded on demand from the `sql` skill when writing a migration, deploying it, or writing a backfill script. Pairs with anti-patterns #18 (MySQL-8 `IF NOT EXISTS`), #19 (record manual applies), #20 (DDL is not transactional).

## Pre-apply pre-flight for new migrations

Before letting `migrate.php` run a NEW migration file, run these checks:

1. **`migrate.php --status`** — shows applied + pending. Catches drift between repo and prod. If prod is unexpectedly N migrations behind, OR if a migration is missing from prod despite being committed long ago, investigate before adding more on top.
   ```bash
   ssh maltyweb 'sudo php /var/www/maltytask/scripts/migrate.php --status'
   ```

2. **FK type compatibility** — for any new table with FK constraints, verify each target PK type:
   ```sql
   SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE
     FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA='maltytask'
      AND TABLE_NAME IN ('ref_x','ref_y','ref_z')
      AND COLUMN_NAME='id';
   ```
   FK column type must EXACTLY match (anti-pattern #3). `ref_*.id` is `INT UNSIGNED` — your FK column must be `INT UNSIGNED`, not `BIGINT UNSIGNED`.

3. **Pre-existing data check** — if the migration adds a FK constraint to an EXISTING column, verify no rows have invalid values:
   ```sql
   SELECT COUNT(*) AS broken_fk
     FROM child_table c LEFT JOIN parent_table p ON p.id = c.parent_col
    WHERE c.parent_col IS NOT NULL AND p.id IS NULL;
   ```
   `> 0` = data fix needed before the FK can be added.

4. **Syntax compatibility** — MySQL 8 doesn't support `ADD COLUMN IF NOT EXISTS` (anti-pattern #18). Don't copy-paste from MariaDB tutorials.

5. **schema_meta row** — for a new TABLE, plan to INSERT its `schema_meta` row in the same migration (table_class, corrections_policy, writer_script, upstream_hint). New columns on an existing table don't need a schema_meta change.

6. **`bin/deploy.sh --apply` rsyncs the entire working tree** to `/var/www/maltytask`. If you have mid-flight work alongside what you want to ship, do a SELECTIVE rsync of just the migration files + just the consumer code files (see "Deploy mechanics" below). Or stash unrelated files first.

7. **Verify `audit_row_revisions.action` ENUM membership when the migration writes audit rows.** The ENUM is `('insert','update')` ONLY — `'delete'` is NOT a member. A DELETE is captured as `action='update'` with `after_json='{"_tombstone":"deleted_by_<migN>"}'` and `before_json` carrying the full pre-delete row state for rollback. Writing `action='delete'` raises `SQLSTATE[01000] Warning: 1265 Data truncated for column 'action' at row 1` and fails the statement (caught live on mig 217 first apply — DIG/QDG consolidation, fixed inline by switching audit-before-delete steps to tombstone-update). Verify the ENUM if you're unsure:
   ```sql
   SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA='maltytask' AND TABLE_NAME='audit_row_revisions' AND COLUMN_NAME='action';
   -- Expect: enum('insert','update')
   ```
   Defensive `WHERE <old_state_predicate>` clauses on every step make partial-apply recovery clean — re-attempting an already-committed step INSERT/UPDATEs 0 rows instead of duplicating.

8. **Classify the risk before applying.** LOW changes go straight through; HIGH changes require a pre-check query + on-demand backup + explicit operator sign-off in the commit message:

   | Change | Risk | Guard |
   |---|---|---|
   | ADD COLUMN (nullable / default), ADD non-unique INDEX, DROP INDEX, widen type (INT→BIGINT, VARCHAR(50)→(100)) | LOW | INSTANT/INPLACE; no data transform |
   | ADD COLUMN NOT NULL **without** default | HIGH | backfill first, or add nullable then enforce |
   | ADD NOT NULL / CHECK to an existing column | HIGH | `SELECT COUNT(*) WHERE col IS NULL` (or violates) = 0 first |
   | Narrow type (VARCHAR(100)→(50), BIGINT→INT) | HIGH | `SELECT MAX(LEN(col))` / `MAX(col)` pre-check — truncation/overflow |
   | DROP COLUMN / DROP TABLE / RENAME | HIGH | dump to snapshot first; RENAME breaks apps that hardcode the name |

9. **No `ALGORITHM=`/`LOCK=` hints on ENUM `MODIFY` or `ADD ... CHECK` — and remember multi-statement DDL is NON-atomic.** Two distinct gotchas, both proven live on `bd_cip_events` (mig 230, `target_code` ENUM append + `chk_cip_target` widen — cost two failed applies):
   - **ENUM append (`MODIFY COLUMN ... ENUM(...)` adding a value at the END) is metadata-only *in theory* but this server rejects `ALGORITHM=INSTANT` for it** with `ERROR 1845 ... ALGORITHM=INSTANT is not supported. Try ALGORITHM=COPY`. Don't specify an algorithm at all — let MySQL pick (it falls back to COPY; fine on small tables). And `ALGORITHM=INSTANT` can never carry a `LOCK` clause (`ERROR 1221: Incorrect usage of ALGORITHM=INSTANT and LOCK=NONE`), so dropping the hint also avoids that trap.
   - **`ADD CONSTRAINT ... CHECK` validates existing rows, so it cannot be `ALGORITHM=INSTANT`** (same 1845). Make it its own statement with no algorithm hint (or `ALGORITHM=COPY`). To *change* a CHECK, `DROP CONSTRAINT <name>` then `ADD CONSTRAINT <name> CHECK (...)` as two separate statements.
   - **Widening an ENUM whose values a CHECK constraint *also* enumerates: you MUST recreate that CHECK in the same migration, or the new values still fail at INSERT.** This is the nastiest of the cluster because the ENUM `MODIFY` *succeeds* and the schema *looks* correct — `SHOW COLUMNS` shows the widened ENUM, `--status` is green — but the column is still gated by a stale CHECK that literally lists the old value-set, so every INSERT of a new value is rejected (`ERROR 3819: Check constraint '<name>' is violated`) with no hint that the column type was ever changed. The two objects encode the *same* domain in two places; widening one without the other leaves them disagreeing. Proven live on **mig 259** (`bd_fermenting_v2`): widening `dh_category` `ENUM('hops_dry')` → `ENUM('hops_dry','adjunct','mineral','process')` was silently re-rejected by `chk_fermenting_event_payload`, whose DryHop arm hardcoded `dh_category = 'hops_dry'`; the fix was to `DROP CONSTRAINT chk_fermenting_event_payload` + re-`ADD` it with the DryHop arm widened to `dh_category IN ('hops_dry','adjunct','mineral','process')` in the same file. **Pre-flight habit:** before widening any ENUM, grep `SHOW CREATE TABLE` for the column name in CHECK clauses — if a CHECK references it, recreate that CHECK too, and when you do, diff the *other* arms of the recreated CHECK byte-for-byte against the original so you only widen the one arm you mean to (the other branches must stay identical, or you silently relax an unrelated invariant).
   - **DDL auto-commits per statement → a multi-statement migration that fails midway leaves the earlier statements COMMITTED.** On mig 230, step 1 (`DROP CONSTRAINT chk_cip_target`) committed on each failed run, leaving the table with NO `chk_cip_target` until it was hand-restored before re-running. `migrate.php` records the migration only on FULL success, so a partial-apply is invisible to `--status` but real in the schema. Recovery pattern: manually restore each already-committed object to its pre-migration state (re-`ADD` the dropped constraint, etc.), fix the file, then re-run. Sequence the file so the *least* destructive / most-easily-restored statement runs first where you can, and verify the live schema after any failed apply before assuming a clean slate.

10. **No ROW-RETURNING statement in a `migrate.php` file — it poisons the NEXT statement with error 2014.** `migrate.php` runs each `.sql` through a single PDO `exec()` and then INSERTs into `schema_migrations` on the same connection. Any statement that *actually returns rows* — a bare `SELECT`, `SHOW`, `EXPLAIN`, `CALL` of a procedure that selects — leaves an *unfetched* result pending on the connection. The very next statement (the migration's own next line, or migrate.php's `schema_migrations` INSERT) then fails with `SQLSTATE[HY000] 2014: Cannot execute queries while other unbuffered queries are active`. Same single-`exec()` root cause as the multi-statement-DDL gotcha (#9), but the trigger is a pending result set, so it bites even when every statement is individually valid.
    - **`CREATE OR REPLACE VIEW ... AS SELECT` is NOT one of these — it is DDL, returns no rows, and applies cleanly INLINE.** Proven empirically: migs **387** (`CREATE INDEX` → `CREATE OR REPLACE VIEW` → `INSERT schema_meta`, all inline, recorded applied ✓), **417**, **326**, and **442** all define views inline and apply through `migrate.php`. **Write view migrations inline like 387** — define the view, then register it in `schema_meta` with a following `INSERT ... VALUES` (387's exact shape, safe because the INSERT doesn't return rows either).
    - **Correction (2026-06-23) — do NOT generalise "views can't go in migrations":** migs 250/251 claim `CREATE OR REPLACE VIEW` poisons `migrate.php` and were applied out-of-band, but **250 is view-ONLY** — if `CREATE VIEW` left a pending result it would break 250's own trailing `schema_migrations` INSERT, yet the applied-inline 387/417/326 prove it does not. 250's out-of-band path was a *preemptive, untested assumption* that then propagated through migration headers (and once misled a build agent into needlessly splitting a view out of mig 442). The real 2014 trigger is a row-returning statement (above), full stop. The one genuine view gotcha is the 251 case: a `CREATE OR REPLACE VIEW` *immediately followed by DML that JOINs that same view* in one `exec()` — if you hit that, split the view and the view-referencing DML into separate migrations (view first), not because the view leaks a result set but because the freshly-created view may not be visible to the joined DML in the same batch.
    - **Genuine mitigation (only for true row-returning statements you cannot avoid):** apply out-of-band via `php -r`/`mysql` so each runs on a clean cursor, then INSERT the `schema_migrations` row manually and note it in the commit. Better: just don't put a bare `SELECT`/`SHOW`/`EXPLAIN` in a migration in the first place.

### MI merge / tombstone-and-relink playbook

When two `ref_mi` rows turn out to model ONE physical ingredient (a duplicate MI — e.g. `HOPS_ELDORADO` id 572 vs `HOPS_EL_DORADO` id 34, merged in mig 254), don't delete the loser. Deleting it orphans its FK children and erases the audit trail. **Keep the canonical id, tombstone the loser, repoint every FK at the keeper.** This pattern recurs — bake it as a migration, not a manual cleanup, so it's auditable and re-runnable.

The shape (all of it inside one `START TRANSACTION; … COMMIT;` so a mid-step failure rolls back cleanly):

1. **Tombstone the loser** — `UPDATE ref_mi SET is_active=0, is_inventoried=0 WHERE id=<loser>`. It stays in the table so historical FK references and audit rows remain resolvable; the flags drop it out of the warehouse/inventory surfaces (which filter `is_inventoried=1`) and the active-MI pickers.
2. **Repoint every FK that pointed at the loser to the keeper.** The relink checklist — the surfaces mig 254 had to move, which is the canonical set to walk for any MI merge:
   - `inv_deliveries.ingredient_fk` → keeper id, **and rewrite the raw string** `inv_deliveries.mi_id_raw` to the keeper's code (so a future re-ingest of the same delivery resolves to the keeper, not back to the dead alias).
   - `inv_rm_stocktake.mi_id_fk` → keeper id (see the row_hash gotcha below).
   - `inv_rm_stocktake_lines.mi_id_fk` → keeper id.
   - `ref_mi_aliases.mi_id_fk` → move all the loser's supplier-code aliases onto the keeper. **An alias insert that case-insensitively matches an existing keeper alias is a NO-OP — do not create a duplicate** (mig 254: inserting `'Eldorado'` was skipped because the keeper already had `'ElDorado'`; the default collation is case-insensitive so a UNIQUE on `alias` already enforces this).
3. **Audit every touched row.** One `audit_row_revisions` row per mutated row, `comment LIKE 'mig<NNN>_%'`, using the tombstone-via-`action='update'` convention from pre-flight #7. **Count the rows fanning out per child** — mig 254 wrote **10** (2 deliveries + 1 stocktake + 1 stocktake-line + 5 aliases + 1 tombstone), not the 9 a naive "one per table" count predicts. Reconcile your expected audit count against the actual touched-row count, or you'll think the migration half-failed.

Verify after apply: loser shows `is_active=0 AND is_inventoried=0`; **zero** pointers remain at the loser across all the FK surfaces above *and* the raw string; and `v_mi_wac` on the keeper now unions both suppliers' live deliveries (mig 254 keeper: `delivery_rows=2, supplier_count=2, qty_remaining_total=205, wac_chf=13.85`).

#### ⚠️ The uniq_row_hash-after-repoint gotcha (rollup-fed stocktake tables)

`inv_rm_stocktake.row_hash` is **NOT NULL + UNIQUE**. When you repoint `mi_id_fk` on a stocktake row you cannot NULL the hash, and leaving the loser's old hash is stale and wrong-keyed. The fix is **not** to recompute the real hash inside the migration (you'd be duplicating rollup logic) — set a **deterministic per-row UNIQUE sentinel** that can't collide and gets cleanly overwritten the next time the rollup runs:

```sql
UPDATE inv_rm_stocktake
   SET mi_id_fk = <keeper>,
       row_hash = SHA2(CONCAT('mig<NNN>-relink-', id), 256)
 WHERE mi_id_fk = <loser>;
```

This works because `rm_recompute_rollup(mi_code, mi_id, period)` upserts on `(mi_id, period)` and **recomputes `row_hash` from the data** — so the sentinel is transient. Proven live on mig 254: re-running `rm_recompute_rollup('HOPS_EL_DORADO', 34, '2026-05')` reclaimed the relinked row with **no 1062 duplicate-key error**, `counted_qty` stayed 170, and the hash flipped from the sentinel to the recomputed value.

**Know which hash the row owns before you touch it.** A stocktake row whose `row_hash` *is* the rollup's `(mi_id, period)` content key gets the sentinel (it'll be rewritten). A child line whose hash is a stable per-line nonce that the rollup does NOT rewrite — `inv_rm_stocktake_lines.row_hash` — must be **left as-is**; only move its `mi_id_fk`. Sentinelling a nonce'd hash that nothing recomputes would strand a meaningless value forever. The rule generalizes: before repointing a row in any table with a UNIQUE content-hash, ask whether a downstream recompute owns that hash. If yes → sentinel; if no → leave the hash, move only the FK.

### Reviewer gate — grep the diff for breaking changes

Distinct from the author checklist above: when *reviewing* a migration diff, scan for destructive ops and confirm PHP/ingest consumers won't break mid-deploy:
```bash
git diff <base> HEAD -- db/migrations/ | grep -Ei "DROP TABLE|DROP COLUMN|RENAME (TABLE|COLUMN)|TRUNCATE|CHANGE COLUMN|DROP INDEX|NOT NULL"
grep -rl "inv_deliveries" /home/kluk/projects/maltyweb/public/modules/ --include="*.php"   # who consumes the affected table?
```
Any `DROP COLUMN` or NOT-NULL-without-default on a PHP-consumed table → must be Expand-Contract (see `performance-ops.md` "Online / INSTANT DDL"), not in-place.

## Deploy mechanics for migrations

To apply a NEW migration without sweeping unrelated working-tree changes:

```bash
# 1. selective rsync of just the migration files you want to ship
cd /home/kluk/projects/maltyweb && rsync -avz --rsync-path="sudo rsync" -e "ssh -o BatchMode=yes" \
  db/migrations/NNN_*.sql \
  ubuntu@83.228.215.243:/var/www/maltytask/db/migrations/

# 2. apply via migrate.php on VPS (auto-records in schema_migrations)
ssh ubuntu@83.228.215.243 'sudo php /var/www/maltytask/scripts/migrate.php'

# 3. verify
ssh ubuntu@83.228.215.243 'sudo php /var/www/maltytask/scripts/migrate.php --status'
```

For applying a migration that's ALREADY been run manually (e.g. by a Sonnet agent's heredoc): just record it.

```bash
ssh ubuntu@83.228.215.243 'mysql -u maltytask_app -p"..." maltytask -e "INSERT IGNORE INTO schema_migrations (filename, applied_at) VALUES (\"NNN_xxx.sql\", NOW())"'
```

## Migration template

```sql
-- db/migrations/NNN_purpose.sql
-- What: brief description
-- Why: business reason
-- Risk: any data-loss potential?
-- Rollback: how to undo

CREATE TABLE IF NOT EXISTS new_table (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ref_id INT UNSIGNED NOT NULL COMMENT 'FK to ref_x.id (INT UNSIGNED — match parent type)',
  amount_chf DECIMAL(14,4) NOT NULL,
  status ENUM('active','pending','consumed') NOT NULL DEFAULT 'active',
  notes TEXT NULL,
  row_hash CHAR(64) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_new_table_ref FOREIGN KEY (ref_id) REFERENCES ref_x(id),
  UNIQUE KEY uk_row_hash (row_hash),
  KEY idx_ref (ref_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

For ALTER TABLE add-column:
```sql
ALTER TABLE existing_table
  ADD COLUMN new_col VARCHAR(64) NULL COMMENT 'what it stores',
  ADD CONSTRAINT fk_existing_new FOREIGN KEY (new_col) REFERENCES other(code);
```

**Separate DDL from DML — one concern per file** (compounds with anti-pattern #20: DDL can't roll back, so don't bury a backfill inside a schema change):
- `NNN_schema_<purpose>.sql` — DDL only (CREATE/ALTER/DROP). No DML.
- `NNN_seed_<purpose>.sql` — reference/lookup INSERTs, idempotent via `INSERT IGNORE` / `ON DUPLICATE KEY`.
- `NNN_backfill_<purpose>.sql` — UPDATEs to existing rows, preceded by a comment with the pre-check query + expected row count.

The naming makes the risk category visible at a glance and keeps each file independently re-runnable.

**Rollback discipline.** Every migration carries its down-path, in the header comment or a `_rollback.sql` sibling:
```sql
-- NNN_schema_add_foo.sql (UP)
ALTER TABLE ref_mi ADD COLUMN foo_score TINYINT UNSIGNED NULL COMMENT '...';
-- ROLLBACK: ALTER TABLE ref_mi DROP COLUMN foo_score;
```
For backfills, the rollback is a delete keyed by `row_hash` / a timestamp sentinel — not the inverse UPDATE (rarely reconstructable). Once the CONTRACT step (drop old column) has run, rollback is a *new forward* migration, not an undo — which is exactly why Expand and Contract live in separate files.

## Backfill TS template

```ts
import { mkdirSync, writeFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { withConnection, closePool } from '../lib/repos/mysql-pool.js';
import { logAudit } from '../lib/audit-log.js';

const APPLY = process.argv.includes('--apply');

async function main() {
  await withConnection(async (conn) => {
    // 1. Read what needs changing
    const [candidates] = await conn.query(
      `SELECT id, ... FROM target_table WHERE condition`
    );
    console.log(`Found ${(candidates as any[]).length} candidates`);
    console.table((candidates as any[]).slice(0, 5));

    if (!APPLY) { console.log('\n[DRY-RUN] re-run with --apply'); return; }

    // 2. Snapshot BEFORE write
    const ts = new Date().toISOString().replace(/[:.]/g, '-').slice(0, 19);
    const snapDir = resolve(__dirname, '..', 'data', 'snapshots');
    mkdirSync(snapDir, { recursive: true });
    writeFileSync(
      resolve(snapDir, `target_table-${ts}.json`),
      JSON.stringify(candidates, null, 2)
    );

    // 3. Apply + audit log per row
    for (const r of candidates as any[]) {
      await conn.query(`UPDATE target_table SET ... WHERE id = ?`, [r.id]);
      logAudit({
        actor:  'your-script-name',
        action: 'what-changed',
        entity: 'target_table',
        entityId: String(r.id),
        before: { /* old values */ },
        after:  { /* new values */ },
        meta:   { reason: 'Operator decision YYYY-MM-DD' },
      });
    }
  });
  await closePool();
}
main().catch(e => { console.error(e); process.exit(1); });
```

CLI script lives at `scripts/_<purpose>.ts`. Underscore prefix = one-shot, will be deleted post-run. No underscore = permanent script.

### Bulk-load path (initial migration / seed loads only)

The per-row `UPDATE … WHERE id=?` loop above is right for **correction** backfills (small N, snapshot + audit-per-row scrutiny). For an **initial migration/seed** of many rows it's a round-trip per row — use the bulk path instead:

- **Multi-row INSERT** (one statement, N value tuples) beats N statements ~10× on round-trips; batch 200–1000 rows, each batch in a single transaction (`SET autocommit=0` … commit per batch) to avoid an fsync per row.
- For large imports `LOAD DATA LOCAL INFILE` is fastest; inside the load transaction only you may `SET unique_checks=0; SET foreign_key_checks=0;`, then re-enable and verify counts — never leave them off.
- Keep the distinction explicit: don't bulk-load a 5-row correction, and don't audit-loop a 200k-row import (there the source file *is* the snapshot).
