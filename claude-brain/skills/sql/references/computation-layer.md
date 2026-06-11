# SQL computation layer — derived & materialized surfaces

Loaded on demand from the `sql` skill. **Read this before building or altering any derived/materialized surface** (KPIs, dynamic RM, eshop SKU-consumption matrix, live tank volumes, COGS/COP/WAC, SKU/BOM). Decided 2026-05-25, PM+DBA converged. It is settled architecture, not a menu.

⚠️ **The LIVE LANDMINES section at the bottom is mandatory reading before any COGS/COP/RM surface — read it first.**

Align with `maltyweb-pm` §6.5 for build-state/sequencing; this file is the durable craft. Sizing context: this DB is SMALL (~1100 consumption rows/month, ~8.8k total) — that bounds the NON-goals below; revisit only at 100× scale.

## Refresh-strategy decision rule (on-write vs scheduled)

**For a materialized cache, ALWAYS BOTH.** On-write recompute = freshness optimization (primary path). Scheduled job = correctness guarantee (thin idempotent backstop). The backstop is not redundant — it catches the 4 failure modes on-write structurally can't:
1. missed triggers / out-of-band edits (a raw `mysql` UPDATE, an ingest that bypassed the hook),
2. late-arriving past-dated source rows (an invoice for last month lands today),
3. formula-version changes that must re-flow history (the cost formula changed → every historical cell is now stale),
4. cross-entity ripple (one WAC change → every BOM + COGS + COP cell that referenced that MI).

**If a scheduled run's output differs from the last on-write output, that delta is a missed-trigger BUG → emit a `doc_review_queue` row, never silently overwrite-and-forget.** `ref_sku_bom` is the reference implementation (on-save hook in `salle-de-controle.php` + the unscheduled backstop CLI `scripts/sku-bom-compile-cli.php`).

**Pick the mode per surface:**

| Mode | When | Examples here |
|---|---|---|
| **compute-on-READ** (a VIEW) | cheap, must-be-live, never frozen | live CCT/BBT/cuv tank volumes, dynamic RM, KPI re-slices |
| **compute-on-WRITE** (hook + backstop) | bounded inputs via a known UI path, read ≫ write | `ref_sku_bom`, eshop cage-residual ledger |
| **SCHEDULED-REFRESH** (period-keyed, frozen on close) | expensive, out-of-band/late inputs, must be reproducible + signed | COGS, COP, month-end tank snapshot, WAC |

## The 4-layer model

Push computation as far DOWN this stack as it will go. Higher layers are thin.
- **L0 — base canonical tables.** Never computed. `bd_*`, `inv_deliveries`, `ref_mi`, the master data. The source of original truth.
- **L1 — generated columns for row-local math.** Push `cost = qty × price` INTO the table definition (`VIRTUAL` if cheap/indexable, `STORED` if it backs a UNIQUE/FK — see `performance-ops.md` "Generated columns & functional indexes"), NOT into PHP. Row-local arithmetic belongs in the schema, not scattered across consumers.
- **L1.5 — views for shared live logic. CAP AT 2 LEVELS DEEP.** A view that other views/queries reuse for a live derivation. **Aggregating views force MySQL into temp-table materialization; nesting views is the perf-and-debug cliff** — beyond 2 levels you can't read the plan and you can't reason about it. If you need a third level, that's the signal to materialize into an L2 table.
- **L2 — summary/rollup tables = the materialized-view emulation** (MySQL has no native matviews). Each is a scoped, idempotent FULL-rebuild with `computed_at` + `row_hash` + a `schema_meta` row + a refuse-don't-NULL CHECK. This is where COP/COGS/WAC/BOM live.
- **L3 — semantic/KPI views.** Thin re-slices over L2. Never re-materialized — if a KPI is expensive enough to want materializing, it belongs in L2, not as a fourth view layer.

## Canonical computation-matrix table template (long-format, additive facts)

The generalization of `cop_monthly` / `cogs_monthly` — copy this skeleton for any new period-keyed additive-fact rollup. Long-format (one row per fact), additive measures ONLY.

```sql
-- db/migrations/NNN_schema_<measure>_matrix.sql
-- What: period-keyed long-format computation matrix for <measure>
-- Why: materialized rollup (L2); one writer; idempotent full-rebuild per period
CREATE TABLE IF NOT EXISTS <measure>_matrix (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  period        CHAR(7) NOT NULL COMMENT 'YYYY-MM',
  entity_fk     INT UNSIGNED NOT NULL COMMENT 'FK to canonical(id) — INT UNSIGNED to match ref_* PK',
  dimension     VARCHAR(48) NOT NULL COMMENT 'the measure axis, e.g. gl_account / category / sku_format',
  measure_value DECIMAL(14,4) NOT NULL COMMENT 'additive only — never store a ratio/average here',
  computed_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'freshness — surface as "as of…" on dashboards',
  row_hash      CHAR(64) NOT NULL COMMENT 'sha256 of the content key — idempotency',
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_<measure>_entity FOREIGN KEY (entity_fk) REFERENCES <canonical>(id),
  UNIQUE KEY uk_grain (period, entity_fk, dimension),   -- the natural grain
  UNIQUE KEY uk_row_hash (row_hash),
  KEY idx_cover (period, dimension, measure_value)       -- covering for the hot KPI re-slice
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- + INSERT its schema_meta row (table_class='derived', corrections_policy='blocked_with_redirect',
--   writer_script='<the one writer>', upstream_hint='recompute via <script>')
```

- **`measure_value` is ADDITIVE only.** Store sums/counts; derive ratios/averages at the L3 view (sum÷sum), never as a stored row — averages don't roll up.
- **`UNIQUE(period, entity_fk, dimension)`** is the natural grain → enables `ON DUPLICATE KEY UPDATE` on re-run; the covering `(period, dimension, measure_value)` index serves the dashboard re-slice with `Using index`.
- **NOT for stateful ledgers.** Eshop cage residuals are stateful (a cage drains over time, opening a new cage is an event) → that's an **event-sourced ledger + a projection**, not a period matrix. Don't force a running-balance fact into the additive-matrix shape.

## Explicit NON-goals (premature at this data size — do NOT build)

- **No partitioning.** Partitioning `inv_consumption` would KILL its FKs (MySQL forbids FKs on/into partitioned tables). At ~8.8k rows it buys nothing and breaks the derivation chain.
- **No incremental / delta refresh.** A delta path doubles the formula surface (the full-rebuild formula AND the delta formula must agree forever) = a guaranteed drift source. Full scoped rebuild per period is cheap here and has ONE formula.
- **No Event-Scheduler-for-everything.** Reserve the MySQL Event Scheduler for **pure-SQL, self-contained** refreshes only. Heavy compute (anything needing RQ-emission, audit-log writes, or Phase-B Postgres portability) stays in **external cron driving the existing scripts** — so the logic is portable, testable, and observable, not trapped in a DB event body.
- **One writer per derived table**, recorded in `schema_meta.writer_script`. **No second refresh path.** Two writers for one fact is the cardinal divergence (see the live landmine below).

## Per-derived-table correctness contract (every L2 table satisfies ALL of these)

1. **Idempotent scoped full-rebuild.** `DELETE WHERE <scope>` then bulk `INSERT`, or `ON DUPLICATE KEY UPDATE` on the natural grain. **Never `INSERT IGNORE` when you mean overwrite** (anti-pattern #7 — it silently no-ops the rows you wanted to change).
2. **Refuse-don't-NULL, realized TWO ways:** (a) an unresolved input emits a self-sufficient `doc_review_queue` row (not a NULL-FK line that silently zeroes a cost component); AND (b) a schema CHECK makes the cost-zeroing NULL physically un-writable. `chk_rsb_mi_id_not_null` on `ref_sku_bom` (`(mi_id IS NOT NULL) OR (bom_source='liquid')`) is the model. Write CHECKs `ENFORCED` (see `performance-ops.md` "Constraints as data integrity").
3. **Period-immutability for accounting facts.** A signed-off month must not be silently restated by a later cron run — guard closed periods (a `closed_periods` gate or a status column the writer respects) so the backstop can't rewrite history without surfacing it.
4. **Freshness surfaced.** `computed_at` on every row, shown as "as of…" on the dashboard; plus a **staleness alarm** = the automated missed-trigger detector (on-write-vs-scheduled delta → RQ row, per the refresh rule above).

## 🔴 LIVE LANDMINES in this DB (PM-verified 2026-05-25 — read before building any COGS/COP/RM surface)

- **`cop_monthly` / `cogs_monthly` / `mi_weighted_prices_monthly` are EMPTY shells (0 rows each)** while the maltytask Node pipeline still writes the Google Sheet. A maltyweb-native builder that POPULATES these WHILE Node still runs = **two-writers-for-one-fact divergence.** Retire/redirect the Node writer FIRST; only then does the DB writer go live. (`schema_meta.writer_script` is the registry of who owns each.)
- **`inv_consumption` has ZERO `packaging` rows** (brewing 4897, sales_derived 3633, fermenting 277, **packaging 0**). This one gap silently blocks dynamic RM + COP + COGS simultaneously — the highest-leverage upstream fix. **Fix at the produce-side packaging form (the write path), NEVER paper over it in the compute layer** (a compute-layer fudge would corrupt every downstream cost). It is the known paused packaging-consumption gap.
