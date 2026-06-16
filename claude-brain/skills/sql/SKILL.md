---
name: sql
description: Write, refactor, or debug SQL — MySQL DDL/DML/queries — for the canonical maltytask database, maltyweb migrations under `db/migrations/`, and PHP read paths in `/modules/*.php`. Equip it (in addition to `coder`/`parser-coder`/`ui`) whenever a task names a table, a migration number, a SQL keyword, or a DB error — writing or fixing a query/upsert (SELECT/INSERT/UPDATE/DELETE, JOIN, GROUP BY, ON DUPLICATE KEY, INSERT IGNORE); a schema change (ALTER, column, FK, CHECK, index, ENUM, collation); a backfill or data correction (dry-run, count, leave-NULL); or diagnosing a MySQL-8 error (ONLY_FULL_GROUP_BY, collation or FK-type mismatch, ENUM truncation, deadlock). Carries the maltytask write discipline (withConnection/logAudit/snapshot, --dry-run default, EUR→CHF 0.945, row_hash idempotency, period YYYY-MM) and InnoDB performance craft (EXPLAIN ANALYZE, composite/covering indexes, online DDL, deadlock retry) in the body. Skip for pure non-SQL coding, parsing, UI, or prose.
---

# SQL

This skill encodes lessons from ~80 SQL bug-or-fix iterations on the maltytask DB + maltyweb PHP modules. **Read this core once at task start.** The depth lives in `references/` — pull the file that matches your task. Re-consult the anti-patterns before declaring done.

## Reference files (load on demand)

| When your task is… | Read |
|---|---|
| Debugging a query/migration that misbehaves, or final review | `references/anti-patterns.md` — 33 real bugs, symptom→fix (indexed) |
| Building/altering a derived/materialized surface (COGS/COP/WAC/BOM/RM) | `references/computation-layer.md` — 4-layer model, matrix template, NON-goals, **LIVE LANDMINES** |
| Writing a migration, deploying it, or a backfill script | `references/migrations-and-deploy.md` — pre-flight, deploy mechanics, templates |
| Merging a duplicate MI / repointing FKs off a row you're retiring | `references/migrations-and-deploy.md` — "MI merge / tombstone-and-relink playbook" (tombstone-don't-delete, the FK relink checklist, the uniq_row_hash sentinel gotcha) |
| Indexing, EXPLAIN, locking/deadlock, online DDL, observability, backups | `references/performance-ops.md` |
| Looking up a table's columns/types | `references/tables.md` — high-traffic cheat sheet (authoritative: `SELECT * FROM schema_meta`); also carries the **raw-append-child → rollup-parent** schema pattern (`inv_rm_stocktake_lines → inv_rm_stocktake`) |

**Deep InnoDB reference layer, vendored from PlanetScale @b156f4c — read on demand:**
- deadlock or lock-wait diagnosis → `references/innodb/deadlocks.md` + `references/innodb/row-locking-gotchas.md`
- slow query / EXPLAIN work → `references/innodb/explain-analysis.md` + `references/innodb/query-optimization-pitfalls.md`
- index design → `references/innodb/composite-indexes.md` + `references/innodb/covering-indexes.md` + `references/innodb/index-maintenance.md`
- schema/type decisions → `references/innodb/data-types.md` + `references/innodb/primary-keys.md` + `references/innodb/character-sets.md` + `references/innodb/json-column-patterns.md`
- large-table DDL → `references/innodb/online-ddl.md` + `references/innodb/partitioning.md`
- replication/connection issues → `references/innodb/replication-lag.md` + `references/innodb/connection-management.md`
- isolation/transaction semantics → `references/innodb/isolation-levels.md`
- full-text search → `references/innodb/fulltext-indexes.md`
- N+1 queries → `references/innodb/n-plus-one.md`

**Precedence rule:** where innodb/ refs conflict with this skill's own anti-patterns or write discipline (e.g. our deadlock-retry-1213/1205 recipe, EXPLAIN ANALYZE practice, the `utf8mb4_unicode_ci` collation standard for this DB, DECIMAL(14,4) money precision, INT-vs-BIGINT PK sizing per table class), **this skill's own rules win** — the innodb layer is background theory; our files encode what production actually hit.

⚠️ **Before building any COGS/COP/RM/WAC/BOM surface, read `references/computation-layer.md` first** — it carries the active LIVE LANDMINES (`cop_monthly`/`cogs_monthly`/`mi_weighted_prices_monthly` are empty shells while Node still writes the Sheet; `inv_consumption` has zero packaging rows).

## Project manager — align with `maltyweb-pm`
A standing **`maltyweb-pm`** project-manager agent (`~/.claude/agents/maltyweb-pm.md`; knowledge base `~/.claude/agents/maltyweb-pm-memory.md`) is the keeper of the canonical SQL build schema, the Le Zeppelin derivation tree (the source-of-original-truth from which every computation derives by FK), the migration roadmap, and the data-model conventions (schema_meta classification, FK type cheat sheet, no parallel stores, refuse-don't-NULL, never-guess-COGS). For any maltyweb DB/migration work: align with it — where the table sits in the derivation tree, that it doesn't create a parallel store, FK/schema_meta discipline. After a migration is applied or a schema fact changes, **its memory must be updated** (the orchestrator/agent that landed the change does this).

## When to equip

- Any SELECT/INSERT/UPDATE/DELETE on the canonical `maltytask` DB
- maltyweb `db/migrations/NNN_*.sql` work — schema additions, ALTER TABLEs, seeds, backfills
- PHP page SQL inside `/home/kluk/projects/maltyweb/public/modules/*.php`
- Backfill TS/JS scripts in `/home/kluk/projects/maltytask/scripts/` that touch tables
- Report builders + ad-hoc verification queries
- Reviewing another agent's SQL diff before commit

This skill **layers** with `coder` or `parser-coder` — equip both when doing TS+SQL or parser+SQL work.

## DB landscape (canonical since 2026-05-12)

The MySQL `maltytask` DB on the maltyweb VPS is the sole canonical store. SSH tunnel for local: `ssh -L 13306:127.0.0.1:3306 maltyweb -N`. Connection config in `~/.config/maltytask/db.env` (local) or `/var/www/maltytask/config/db.env` (VPS).

Connection in TS via `lib/repos/mysql-pool.ts`:
```ts
import { withConnection, closePool } from '../lib/repos/mysql-pool.js';
await withConnection(async (conn) => {
  const [rows] = await conn.query('SELECT ...');
});
await closePool();   // critical at end of one-shot CLI scripts (tsx hangs otherwise)
```

Connection in PHP via `app/db.php::maltytask_pdo()` (PDO MySQL). For a quick **ad-hoc VPS probe / verification query** (no tunnel, no scp), run an inline PHP one-liner — but it MUST be under sudo, because `config/db.env` is readable only by `www-data` (a bare `php -r` as the `ubuntu` SSH user dies with `parse_ini_file(...db.env): Permission denied`):
```bash
ssh maltyweb "cd /var/www/maltytask && sudo -u www-data php -r 'require \"app/db.php\"; \$pdo=maltytask_pdo(); var_dump(\$pdo->query(\"SELECT COUNT(*) FROM ref_skus\")->fetchColumn());'"
```
`-u www-data` matches the app runtime user and leaves no root-owned temp files; plain `sudo php` (root) also reads db.env fine.

**Pool hygiene (single VPS).** The TS pool (`connectionLimit: 10`) + PHP-FPM workers + cron scripts must sum under the server's `max_connections` (`SHOW VARIABLES LIKE 'max_connections'`) — it's a real ceiling here. Set a `connectTimeout`; consider a per-statement `SELECT /*+ MAX_EXECUTION_TIME(3000) */ …` hint on PHP read paths so a runaway report can't pin a connection. For parameterized statements prefer `conn.execute` over `conn.query` — see `references/performance-ops.md`.

**Never** embed credentials in CLI commands — the auto-mode classifier blocks them (and they leak to shell history / process listings).

**Table classification — `schema_meta` is the canonical source (since migration 080, 2026-05-22).** Every table in the DB is classified there:

```sql
SELECT table_name, table_class, corrections_policy, writer_script, upstream_hint
  FROM schema_meta
 WHERE table_name = '<your-target-table>';
```

Columns:
- `table_class` — `reference` / `lookup` / `source` / `derived` / `audit` / `config` / `system`
- `corrections_policy` — `allowed` / `allowed_with_side_effect` / `blocked` / `blocked_with_redirect`
- `writer_script` — canonical writer (e.g. `parse_bd_ingredients.py`, `build-sku-bom.js`, `compute-weighted-prices.ts`)
- `upstream_hint` — free text shown to operator when edits are blocked; for derived tables, points at the recompute script

**Before any write to a table, check schema_meta.** If `derived` + `blocked_with_redirect`: fix the upstream source instead. If `allowed_with_side_effect`: your write must trigger the documented side-effect (e.g. `bd_brewing_ingredients_parsed.mi_id_fk` correction must also upsert into `ref_mi_aliases` to survive re-parse). The DB Browser UI enforces this via `app/schema-meta.php::schema_meta_for_table()`; backend scripts should respect the same contract. (Classification snapshot + per-class table list lives in `references/tables.md`.) New tables added by future migrations should INSERT their `schema_meta` row in the same migration.

**FK target type cheat sheet** — `ref_*.id` are `INT UNSIGNED` (small lookup/reference tables, max ~10k rows ever). `bd_*` / `doc_*` / `inv_*` / audit / event tables use `BIGINT UNSIGNED`. **Exception:** `ref_packaging_formats.id` is BIGINT (drift introduced by the SKU builder author 2026-05-21 — leave for now, may be normalized later). FK columns MUST match target PK type exactly — see anti-pattern #3.

## Master-data derivation & NULL discipline (Salle des Machines = validation root)

The **Salle des Machines / Salle de contrôle** is the single master-data validation root: the totality of maltytask's computing (COGS, BOM, SKU, capacity computing, parser supplier-resolution) **derives from it** by FK. Two standing rules for any schema / query / migration:

- **Minimize NULL — ideally none in identity/FK columns.** A NULL FK is an *unresolved-link smell*, not an acceptable default. Resolve it at the master-data root (e.g. parsers link to `ref_suppliers` via `parser_key` → `supplier_fk` is always set when a parser matched), not with one-off per-row backfill patches. When you see a NULL FK, ask "which master surface should guarantee this?" and fix there.
- **Keep only *semantic* NULL, and model it explicitly.** Some NULLs are a deliberate "not applicable" (reusable keg → no container MI; genuinely-absent TTC on an HT-only invoice). Those are fine — but make the absence intentional (documented nullable / sentinel / flag), never a fake value. Distinguish *accidental/unresolved* NULL (eliminate) from *semantic* NULL (keep, documented).
- **Derive, don't duplicate.** Computed/derived tables read master data via FK + JOIN; never copy identity strings (they go stale on rename). Every new nullable column must justify itself against both rules above in review. (The full derived/materialized-surface doctrine is in `references/computation-layer.md`.)

## Anti-patterns — index (full detail in `references/anti-patterns.md`)

Match a symptom to a number, then open the file. **Re-consult before declaring done.**

1. `ONLY_FULL_GROUP_BY` trap — non-aggregated column not functionally dependent → `ANY_VALUE()`; move per-row math inside the aggregate
2. Collation mismatch on JOIN — `utf8mb4_0900_ai_ci` (`bd_*`) vs `_unicode_ci` (`inv_*`/`ref_*`) → explicit `COLLATE` on the join
3. FK type mismatch — `BIGINT UNSIGNED` vs `INT UNSIGNED` → match parent PK exactly (`ref_*.id` is INT UNSIGNED)
4. ENUM value length truncation — `VARCHAR(N)` silently truncates over-long values → size + 30% or real `ENUM`
5. PHP PDO `:named` vs `?` placeholder collision → pick one style per query
6. Ambiguous column when self-or-twin JOINing → qualify every column with its alias
7. `INSERT IGNORE` silently swallows the row you meant to update → `ON DUPLICATE KEY UPDATE` / DELETE+INSERT
8. DECIMAL precision drift for money → `DECIMAL(14,6)` unit price, `(14,4)` totals; mysql2 returns strings
9. PHP query-param NULL fall-through → read-with-default FIRST, then validate
10. Reading `qty_remaining` as if FIFO-depleted → run `_phase2c-fifo-deplete.ts` first
11. EUR currency NOT converted to CHF → `IF(currency='EUR', 0.945, 1)`
12. Unit conversion grams ↔ kilograms → convert via `ref_units` at query time
13. Pack-size NOT applied at ingest → parsers consult `ref_mi_invoicing_units` via `lib/pack-size.js`
14. Brew-count multiplier for batch ingredients → multiply per-brew qty by `n_brews`
15. NULL division → `COALESCE(a / NULLIF(b, 0), 0)`
16. `is_inventoried` filter missing → `WHERE m.is_inventoried = 1` on warehouse pages — and the flag is NOT GL-derivable (read it directly; never key inventoried state off `gl_account`, see anti-patterns #16)
17. `expected_qty` fallback leakage → `counted_qty` only for the live warehouse (don't use `final_qty` either)
18. MariaDB-only `IF NOT EXISTS` on ALTER TABLE in MySQL 8 → strip it (idempotency comes from `schema_migrations`)
19. Migration applied via raw `mysql` not recorded in `schema_migrations` → record it, or prefer `migrate.php`
20. DDL is not transactional — design migrations idempotent for partial failure / interrupted runs
21. Scoping a batch op by a PROXY table instead of the consumer's own gate → dry-run to `errors=0`
22. VARCHAR column compared to an INT literal → whole-column DOUBLE cast (err 1292) → quote the literal to match column type
23. The broken row-index bridge — joining two materializations of the same upstream on their position columns (`sheet_row_index`↔`source_sheet_row_index`) when each numbers independently (~45% correct) → join on the NATURAL business key + prove a clean 1:1 bijection (rolled-back dry-run, refuse `n>1`/conflicting) before any backfill write
24. Soft-delete flag (`is_tombstoned`/`is_active`) added but not enforced in every read path (view + all PHP sites incl. shared `_where()` helpers + cross-repo Node/TS) → flag is inert, rows stay live → grep every consumer and patch atomically
25. Event-sourced replay: a DRAIN/release step gates on the STATIC brew-time binding instead of CURRENT occupancy → out-of-order or late events corrupt state. In an occupancy/inventory replay (e.g. tank sim: `loadBatchCCT` maps `beer|batch → the vessel it was brewed into`), every drain/decrement/evict MUST verify the vessel's CURRENT occupant matches the event's identity before mutating — `if (state[vessel].occupant == (recipe_id, batch))` — else no-op. A late rack-out form drains a vessel a NEWER batch has since refilled (observed: CCT2 Stirling-171 + CCT1 Diversion-46 nulled by stale Speakeasy-62 rack-out). Key occupancy on the FK identity `(recipe_id_fk, batch)`, not a name (`beer`), since batch numbers recur across recipes; carry recipe_id on every event + state slot, with `(beer,batch)` fallback only for sparse edges. Guard EACH event step symmetrically — one branch's missing guard (CCT) while a sibling has it (BBT) is the bug. Verify via a BEFORE/AFTER full-replay diff: assert ONLY the known-corrupted slots change, rest byte-identical, before shipping a read-side fix
26. Corrective UPSERT must preserve the original natural-key timestamp → a "Corriger une saisie" (`?edit=<id>`) re-POST that stamps a FRESH `submitted_at` (part of the NK) gets no `ON DUPLICATE KEY` collision → `bd_upsert` INSERTs a silent DUPLICATE instead of UPDATE-in-place, doubling the event + its COGS/tax/occupancy qty. Reuse the original (`$submittedAt = $isEditMode ? $editSubmittedAt : date(...)`), strict-regex the hidden `edit_submitted_at`/`edit_id`, refuse the edit if the row's session is `status!='open'` (never reopen a closed/fiscal session), skip the lifecycle advance in edit mode, and prove it with the synthetic 1-row(preserved)/2-row(wrong) self-cleaning test. Check `schema_meta.corrections_policy='allowed'` before wiring any edit surface
27. **Route / detect-phase / firewall a form on the canonical FK `(recipe_id_fk, batch)`, NEVER on a free-text identity label (`beer` / `beer_raw` / short-code) that can fragment across spellings.** The READ-side companion to #25 (which is the replay/write side). On an operator session form, the selector card → lot-form handoff and any phase-detection / firewall / CCT-or-tank lookup must key on the canonical recipe FK carried by the card (`data-recipe-id` → hidden `recipe_id_fk`), with the free-text label kept only as a *fallback* for legacy rows that genuinely lack the FK. The bug: when the same physical `(recipe, batch)` carries multiple spellings in the label column and a read keys on the label, events split across spellings and the form mis-detects phase / mis-fires the firewall / opens a NULL-recipe session (observed: fermenting EPH2/26 events split across `beer_raw` spellings, phase stuck `in_progress`, CCT unresolved). **The bug only bites where fragmentation AND label-keying COINCIDE** — a label-keyed read whose label column is canonical (e.g. a recipe-name sourced from a `ref_recipes` dropdown, or a contract lane that has no recipe_id by design) is theoretically-imperfect-but-safe; don't churn it. **Fix = re-key the read on `(recipe_id_fk, batch)` when recipe_id is known, label fallback only when it's NULL; carry recipe_id on the card and USE it (don't re-derive identity from the string).** Prove it with a full batch-census dry-run: replay every `(recipe, batch)` pair through the new keying and assert **0 regressions** vs the old resolution before shipping (fermenting: 812 pairs, 0 regressions, EPH2/26 flips `in_progress`→`end`). Audit sibling forms when you fix one — racking/packaging/brewing were checked and found canonical-FK-clean (cards carry `data-recipe-id`, `<select>`s are tank/cause not identity, brewing *originates* the canonical name from `ref_recipes`)
28. **A WRITE-TIME validation/cadence/duplicate gate MUST skip edit/correction mode** — the `if(…) redirect_to($base)` block that decides whether THIS submission lands. The same lot-form serves first-entry AND `?edit=` corrections (#26's corrective-upsert), so an unconditional gate fires **against the row being edited**: correcting yesterday's purge is blocked by "a purge already exists for this batch within N days" (it's the edited row). Fix: FIRST line of the gate is an edit bypass — `if ($eventType==='Purge' && !$isEditMode){ …cadence query + 409… }` — PLUS defense-in-depth `… AND id <> ?` (self-exclude `edit_id`) even on first-entry so a corrective UPSERT that resolves to the same PK never counts itself. NOT "weaken the gate": first-entry still gets the full check; the bypass is scoped to corrections, which restate an already-accepted fact. Brewing's confirm-overwrite guard is the correct shape (gates NEW accidental overwrites, lets an explicitly-confirmed re-submit through). Racking has NO edit mode (insert-only; daily-shell end-phase UPDATE keyed on linked row id, not cadence-re-validated) → SAFE by absence
29. **A "minimum-interval / too-recent / cadence" gate MUST measure the gap on the business `event_date`, NEVER on `submitted_at`/`NOW()`/`time()`** — the interval is a property of when events *physically happened*, not when the row was *recorded*. Record-time keying is **backfill-hostile**: backfilling a week of same-batch purges in one sitting stamps every row with today's `submitted_at` → gap reads "0 days apart" for every pair → the 2nd…Nth rows all trip "too recent" and the batch's history is un-enterable (and it mis-measures live too). Fix: compute gap on `event_date`, key the lookup on the canonical FK (#27), exclude the edited row (#28). **Already-clean SQL gates (audited same pass, recorded so they aren't re-discovered): racking garde** (`DATEDIFF(CURDATE(), MAX(f.event_date)) >= effective_garde`) and **packaging `min_days_after_racking`** (`r.event_date <= DATE_SUB(CURDATE(), INTERVAL ? DAY)`) — both compare `event_date`, both are GET-side **candidate-list builders** that populate the eligible-lot picker (never run on a correction → #28 N/A), and their `CURDATE()` is the legitimate "as-of-today" upper anchor, distinct from measuring an inter-event interval. **Whole-family caveat (shared with #27): a gate is buggy only where fault AND trigger COINCIDE.** #28 bites only if corrections flow through the gate; #29 only if same-batch backfill happens. A GET candidate-list builder has neither trigger → SAFE even though it "compares a date" — do NOT churn it. The fermenting purge gate had all four (write-time × edit reuses handler × record-time key × backfill) → fixed (A1 edit-skip / A2 event_date gap / A3 recipe_id_fk key + self-exclude / A4 override+reason). Fix one form's write-time gate → audit every sibling's write-time gates the same pass
30. **`ADD COLUMN … GENERATED ALWAYS AS (…) STORED` via `ALTER TABLE` throws err 1215 when the expression references a column that is an FK with a cascading referential action (`ON DELETE SET NULL`/`CASCADE`)** — MySQL 8 (8.0.46, observed mig 303) can't add a STORED generated column whose value it could not maintain when the cascade fires, and `ALTER` is stricter than inline-at-`CREATE`. `VIRTUAL` is allowed but can't reliably back a UNIQUE index. **This breaks the #194 "shadow generated column for a UNIQUE on a nullable/conditional natural key" trick the moment the key expression touches an FK column** (e.g. an idempotency key `CONCAT(bd_packaging_id_fk,':',sku_id_fk)` where `bd_packaging_id_fk` is `ON DELETE SET NULL`). **Fix = a plain nullable column written by the application** (`accrual_key = "$rowId:$skuId"` for the rows you want uniqued, `NULL` for all others) **+ a UNIQUE on it** — NULLs never collide in a MySQL UNIQUE, so only the targeted rows are deduped, identical semantics to the generated version. Cost: the app must keep it in sync (set it on insert; **NULL it on tombstone** so the slot frees for a legitimate re-bank — a correction lifecycle that re-derives the same `(parent, child)` would otherwise collide). Idempotency pattern that needs no key at all for run-derived rows: **tombstone-prior-then-reinsert** — on every write of a parent row, `UPDATE … SET is_tombstoned=1, <key>=NULL WHERE parent_fk=? AND movement_type IN(<derived>) AND is_tombstoned=0` then insert fresh; inherently idempotent across insert AND edit, no qty-staleness, respects tombstone-only. (mig 303 `inv_side_stock_ledger`.)
31. **The `bd_*` brewing/fermentation tables exist in v1 + `_v2` generations — read ONLY `_v2`; the v1 sibling is FORBIDDEN and being erased ASAP (operator standing order 2026-06-11).** v1 keeps getting read by mistake; it "cannot happen anymore." Before declaring any `bd_*` read correct, **grep for the v1 names** (`bd_brewing_brewday|bd_brewing_cooling|bd_brewing_gravity|bd_brewing_ingredients|bd_brewing_ingredients_parsed|bd_brewing_timings|bd_fermenting|bd_packaging|bd_racking` *without* `_v2`) and repoint any hit. v1 is NOT a safe fallback: it carries **duplicate brew rows + missing brews** (`bd_brewing_cooling` rid=51 b52 had a dup → inflated volume; v2 deduped/complete), so **v2 is the *corrected* source, not a lossy one** — repointing COGS/tax reads (sku-bom batch-HL, beer-tax OG) *moves money*; emit a before/after delta and get operator sign-off. Mapping: `bd_brewing_cooling` → `bd_brewing_gravity_v2 WHERE event_type='Cooling'` (`cool_final_gravity`→`final_gravity`, `cool_final_volume_hl`→`final_volume`; **no `event_date` col**, proxy `DATE(submitted_at)`). Keying gotchas (companion to #27): `recipe_id_fk` is **NULL on recent `bd_brewing_timings_v2` rows** → key timings on `(beer, batch)`; `start_ferm` is **100% NULL in v2** (v2 form writes `brew_start`/`brew_end`/`event_date`) → anchor fermentation day-0 on `COALESCE(MAX(STR_TO_DATE(start_ferm,'%d.%m.%Y %H:%i:%s')), MIN(event_date))`, never read v1 timings for it; EPH recipes re-ID per vintage so v1 `*_recipe_id` is stale vs v2 `recipe_id_fk` (join by v2 FK or beer name). **KEEP (NOT v1 despite no `_v2`):** `bd_packaging_readings` (child of `bd_packaging_v2`), `bd_cip_events`, `bd_tank_readings`. PM owns the live reader checklist + drop plan: `maltyweb-pm-memory/v1-bd-tables-decommission.md`. (tanks.php fully de-v1'd 2026-06-11, commits f398163 + e063ddf.)

32. **Don't reuse a canonical DISPLAY view's `WHERE` for a DIFFERENT analytic question — and before SUMming a ledger for a quantity, PROVE which `doc_type`/entry-type rows are the real movement.** Two coupled traps that move money. (a) **View-reuse divergence:** a view built for purpose A encodes filters that silently drop rows purpose B needs. `v_sales_ledger_order_lines` (mig 326) is a B2B order-line *display* lens: `WHERE doc_type='shipment' AND sale_class NOT IN ('eshop','taproom',…) AND posting_date < cutover`. Reusing that `WHERE` for "total finished-goods stock burn" understates depletion massively — **eshop (94%) and taproom (100%) of goods-out post as `doc_type='invoice'`, which the display view excludes** — so coverage/runway reads falsely healthy (the dangerous direction). (b) **Ledger netting:** before `SUM`ing signed quantities, verify which `doc_type`/entry-type rows are the REAL physical movement. A *same-sign sibling* type may be a **mirror** (double-counts → exclude) OR an **independent movement** (must include) — prove which; never assume. Here it's a BC item-ledger: `shipment` and `invoice` are both outbound (negative) but **mutually exclusive per movement** (each physical movement posts once under its natural document type), and `bc_source_no` is **customer-grained, not order-grained** (≈8 docs/source) so it is NOT a mirror key. ⇒ physical depletion = `−SUM(qty_signed)` across **ALL** doc_types (net of credit/return), NOT shipment-only. **Verify before coding:** break the SUM out by year × doc_type (shipment-only annuals were stable, all-types had a 2022 correction spike → the spike is bookkeeping noise *outside* the trailing window, the channels are the real signal); break out by `sale_class × doc_type` (this is what exposed the eshop/taproom invoice-only goods-out); sample raw rows to confirm `source_no` grain. When you DO diverge from the canonical view on purpose, **comment the divergence in-code** (`-- all doc_types intentionally; diverges from v_sales_ledger_order_lines display lens — different questions, must not be unified`) so nobody "reconciles" the two later. (2026-06-11, `seasonal-burn` / `fg_stock_compute` burn-quantity semantics.)

33. **A ledger credit/avoir line is NOT a physical event — same-day booking reversals pollute any event-derived count (companion to #32, the returns side).** Deriving a *physical return* count from `inv_sales_ledger` credit/return_receipt rows over-counted ~3.6× because ~72% of credit lines are BC same-day booking reversals (post shipment → credit it → re-invoice: same `posting_date`+`customer_id_fk`+`sku_id_fk`, opposing sign), indistinguishable by `doc_type_raw`, no reversed-doc FK. **Exclude them structurally via a NOT EXISTS on the same-day opposing-sign sibling, encapsulated in ONE canonical VIEW** (`v_physical_returns`, mig 387) so every consumer (rate/watchlist/queue/KPI) shares the definition; index `(customer_id_fk, sku_id_fk, posting_date, doc_type)`. #32 SUMs all doc_types because reversals net to zero for *depletion*; here each reversal credit counts on its own, so it must be filtered for a *gross count*. Promote read-time detection to an ingest-time `is_reversal` flag when you control the load. Verify by quantifying the drop (~70%) before/after + spot-checking one big "returner"'s same-day triples. (2026-06-16, returned-FG metric.)

## Maltytask write discipline

For any UPDATE/DELETE/INSERT against a canonical table:

1. **`--dry-run` is the DEFAULT.** `--apply` must be explicit.
2. **Snapshot before write:** dump affected rows to `data/snapshots/<table>-<purpose>-<ts>.json`.
3. **Audit-log per change:**
   ```ts
   logAudit({
     actor: 'phase2-backfill',
     action: 'qty-delivered-updated',
     entity: 'inv_deliveries',
     entityId: String(row.id),
     before: { qty_delivered: old, unit_price: oldPrice },
     after:  { qty_delivered: new, unit_price: newPrice },
     meta:   { reason: 'Pack-size 5 applied; total preserved' },
   });
   ```
4. **Idempotency:** re-running with `--apply` must be a no-op (UNIQUE on row_hash or content-key check).
5. **No `--no-verify` / `--force` flags** unless operator explicitly approved.

For new migrations in `db/migrations/` — sequential `NNN_<purpose>.sql`, comment header, applied via `bin/deploy.sh --apply` + `migrate.php`, algorithm chosen deliberately for ALTERs on big tables — see `references/migrations-and-deploy.md` (pre-flight, deploy mechanics, templates) and `references/performance-ops.md` ("Online / INSTANT DDL").

## Verification recipes

After any SQL change, run these in order:

1. **PHP syntax check** (after editing `.php` files):
   ```bash
   ssh maltyweb 'php -l /var/www/maltytask/public/modules/YOUR_FILE.php'
   ```
   Local PHP isn't installed; do this remotely after `bin/deploy.sh --apply`.

2. **Dry-run COUNT/SUM** (before backfill):
   - How many rows match? Is the count sensible?
   - What's the SUM(amount_chf) of the affected rows? Does it match expectations?

3. **Spot-check 3 rows** at random + 2 known-good rows + 2 edge cases (NULL, large, small).

4. **Re-run idempotency:** apply the same script twice — second run must report 0 changes.

5. **Cross-source reconciliation** when migrating BSF→MySQL: row count + total HL/CHF should match within 1%. Differences > 1% need explanation, not hand-waving.

6. **Page load test** for PHP changes:
   ```bash
   curl -s -o /dev/null -w '%{http_code}\n' https://your-maltyweb-host/modules/warehouse.php?view=wip
   # 200 expected, 500 means DB error or PHP fatal
   ```

## Industry standards (defaults I follow)

- **Money in DECIMAL(14,4) or finer.** Never FLOAT.
- **All money in single currency at storage.** Convert at write time (e.g. EUR→CHF via `eur_to_chf` column on row).
- **Foreign keys are explicit.** Every cross-table reference gets a `CONSTRAINT fk_<child>_<parent>` declaration. ON DELETE behaviour explicit (RESTRICT default, CASCADE rare).
- **TIMESTAMP columns** for created_at + updated_at on every mutable table.
- **`utf8mb4_unicode_ci`** as the project standard collation. Don't drift.
- **No SELECT \* in PHP / TS production code.** Enumerate columns. Stable order = stable PDO fetch shape.
- **LIMIT without ORDER BY is non-deterministic.** Always pair them.
- **EXISTS over IN** for subqueries returning > 10 rows.
- **CTEs (WITH) for readability** when a subquery is referenced 2+ times.
- **Reserved words quoted with backticks** (e.g. `` `order`, `desc`, `key` ``) — or rename the column.
- **Indexes on every FK** column (MySQL adds it for the constraint, but composite indexes for common JOIN+WHERE patterns need explicit `KEY idx_x_y (col_x, col_y)`).
- **`conn.execute` (server-prepared) over `conn.query`** for anything parameterized.
- **`EXPLAIN ANALYZE` before shipping** any query with ≥2 joins, a window function, or a subquery.
- **CHECK constraints `ENFORCED`**, never `NOT ENFORCED`. Functional/generated columns over shadow-column-plus-trigger.
- **Multi-row per natural key with a nullable discriminator** (e.g. moving one row per `(recipe, hop)` → one row per `(recipe, hop, stage)`): do NOT put `COALESCE(enum,'…')` expressions inline in the UNIQUE — an ENUM inside a functional key part is brittle in MySQL 8. Instead add `GENERATED ALWAYS AS (COALESCE(<discriminator>,'<sentinel>')) STORED` shadow columns and put the UNIQUE on `(natural_key…, <gen cols>)`. A `COALESCE(int_col, -1)` sentinel forces the generated column **SIGNED** (an UNSIGNED column rejects `-1`). This preserves the old one-row guarantee for unclassified rows (they all collapse into the single sentinel bucket) while allowing one row per discriminator once set. Pair with an ENFORCED CHECK for the conditional-NOT-NULL invariant (`time NOT NULL ⟺ stage='boil'`). (2026-06-01, `ref_recipe_ingredients` hop-stage, mig 253.) **But see bug #30: if the key expression references an FK column with a cascading referential action, `ADD COLUMN … STORED` via `ALTER` throws err 1215 — fall back to a plain app-written column + UNIQUE (NULL on tombstone), or the tombstone-prior-then-reinsert pattern.**
- **Two-lane shared dimension where the UNIQUE *is* the inheritance mechanism.** When a value is a property of a coarse natural key shared by many child rows (e.g. one BBT CO₂/O₂ read per `(beer, batch, day)`, inherited by every SKU/format packaged from that tank that day), model it as its own dimension table, NOT a column on the child. Give it the UNIQUE on the coarse key and let the writer rely on it: first child of the lot-day INSERTs the read, every later child catches `ER_DUP_ENTRY` and reads the existing row back (the UNIQUE collision *is* the "inherit" signal — no separate lookup-then-insert race). For an entity with two mutually-exclusive identity lanes (neb keyed `(recipe_id_fk, neb_batch, read_date)` vs contract keyed `(contract_beer, contract_batch, read_date)`), use **two separate UNIQUE indexes**, one per lane, and leave the other lane's columns NULL — MySQL treats NULLs as distinct so the lanes never false-collide. Carry a traceability column (`bbt_source_fk`) that is explicitly **NOT** part of the key (same lot-day across two physical tanks must surface/refuse, not silently split). This table has no `is_tombstoned` — child-row tombstoning lives on the fact table, not the shared dimension. (2026-06-01, `bd_tank_readings`, migs 243/257.)

## When asked to verify someone else's SQL

Spot-check checklist (anti-pattern numbers reference `references/anti-patterns.md`):

- [ ] Every column qualified with table alias?
- [ ] LIMIT paired with ORDER BY?
- [ ] LEFT JOIN columns that appear in SELECT → wrapped in ANY_VALUE() if there's a GROUP BY?
- [ ] All VARCHAR JOIN columns same collation, or COLLATE clauses explicit?
- [ ] FK column types match parent PK type (INT UNSIGNED vs BIGINT UNSIGNED)?
- [ ] Money columns DECIMAL with ≥ 4 decimals?
- [ ] Idempotent: UNIQUE/UPSERT or content-key check before INSERT?
- [ ] `is_inventoried = 1` filter on warehouse-flavored queries?
- [ ] EUR rows converted to CHF via `IF(currency='EUR', 0.945, 1)`?
- [ ] Pack-size multiplier applied for batch-ingredient aggregations?
- [ ] NULLIF(denominator, 0) on every division?
- [ ] `counted_qty` only (no expected_qty fallback) for stocktake anchor?
- [ ] Snapshot + logAudit() for every UPDATE/DELETE?
- [ ] `--dry-run` flag is the default; `--apply` is explicit?
- [ ] Re-running the same script is a no-op?
- [ ] **`schema_meta.corrections_policy` respected?** — if the write targets a `derived` / `blocked` / `blocked_with_redirect` table, what business is this script in writing to it? Should the upstream source be edited instead?
- [ ] **No `ADD COLUMN IF NOT EXISTS` / `ADD KEY IF NOT EXISTS`** in MySQL 8 migrations?
- [ ] Ran `EXPLAIN ANALYZE`? No `type: ALL` on a table that should use a key; no `filesort`+`temporary` pair?
- [ ] Composite index column order matches the WHERE-equality-then-ORDER-BY shape (leftmost-prefix)?
- [ ] `conn.execute` (not `query`) for the parameterized hot path?
- [ ] Deadlock/lock-wait retry (1213/1205) around any `FOR UPDATE` transaction?
- [ ] CHECK constraints `ENFORCED` (not `NOT ENFORCED`)? ON DELETE behaviour justified, no CASCADE toward `inv_*`?
- [ ] Multi-statement migration: each statement independently idempotent (DDL can't roll back)?

If ANY box is unticked, surface it before approving the diff.

## What NOT to do

- **NEVER** `git add -A` for migration commits — name files. See `feedback_git_add_specific_files_not_dash_A`.
- **NEVER** push to main without explicit per-push approval; the auto-mode classifier may block, even though some commits are pre-approved.
- **NEVER** embed DB password in a CLI command (auto-mode classifier blocks them).
- **NEVER** truncate `inv_deliveries` / `inv_consumption` / `wac_snapshots` — these are 10-year retention. Use DELETE WHERE for targeted cleanup.
- **NEVER** apply a migration with side effects without dry-run first.
- **NEVER** skip the snapshot. "Just this once" is when it bites.
