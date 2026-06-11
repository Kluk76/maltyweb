# Computation-layer architecture (decided 2026-05-25 — settled, not a proposal)

> Load when: building/changing ANY downstream computed surface — KPIs, dynamic RM, eshop SKU-consumption matrix, live tank volumes, COGS/COP, WAC, SKU/BOM, or any new materialized cache / rollup matrix. Decided by PM + DBA consult (converged). Durable reusable craft (4-layer model, matrix-table template, NON-goals, per-table correctness contract) lives in the **`sql` skill, "Computation layer" section** — do NOT duplicate it here; this file holds the build-state + sequencing.

NB sizing: this DB is SMALL (~1100 consumption rows/month, ~8.8k total). That bounds the NON-goals below.

## RULE — on-write vs scheduled: ALWAYS BOTH for a materialized cache
("on-write beats nightly cron?" → both.) On-write recompute = freshness optimization (primary). Scheduled job = correctness guarantee (thin idempotent backstop). The backstop catches the 4 failure modes on-write can't:
1. missed triggers / out-of-band edits,
2. late-arriving past-dated source rows,
3. formula-version changes that must re-flow history,
4. cross-entity ripple (one WAC change → all BOM+COGS+COP cells).

**If a scheduled run differs from the last on-write, that delta is a missed-trigger BUG → emit a review-queue row, never bury it.**

**EXTENSION 2026-05-28 — THIRD PATH for session-grouped events: the lock-cascade.** For event rows that carry a non-NULL `session_id_fk` (i.e. they belong to an `op_sessions` lifecycle envelope), BOTH on-write recompute (per-section save during `phase='in_progress'`) AND the scheduled-backstop (python-ingest catch-all for session-less rows) still apply, but a THIRD path is added: **`finalize_session($id)` at the moment the session is locked** (`phase`→`closed`, `status`→`closed`) is the CANONICAL refresh moment for ALL downstream materializations that depend on this session's events. The cascade re-runs the per-section recompute over every event row in the session + invalidates/refreshes the downstream materializations (SKU-BOM recompile if it sourced these events, `inv_consumption` rollups for packaging/brewing, COGS/COP/PPB if the period has already been touched), in ONE transaction. Idempotent: re-running `finalize_session` on an already-closed session is a no-op when recompute output matches stored values. **Sweep crons for form-derived columns are RETIRED at the form-submit COMPUTE-ON-SUBMIT retrofit + the lock-cascade landing** — their residual role is python-ingest backstop ONLY (catch-all for `session_id_fk IS NULL` rows from legacy Sheets ingest). See session-model-arc.md §LOCK-CASCADE CONTRACT + conventions-and-helpers.md §COMPUTE-ON-SUBMIT.

`ref_sku_bom` is the reference impl: on-save hook LIVE (`539bad3`); its nightly backstop CLI (`scripts/sku-bom-compile-cli.php`) is **BUILT BUT UNSCHEDULED — scheduling it is the #1 leverage action** (= the cron half of the Stage-1 backlog item 4).

## DECISION RULE — pick the mode per surface
- **compute-on-READ (view):** cheap, must-be-live, never frozen → live CCT/BBT/cuv tank volumes, dynamic RM, KPI re-slices.
- **compute-on-WRITE:** bounded inputs via a known UI path, read ≫ write → `ref_sku_bom` (done), eshop cage-residual ledger.
- **SCHEDULED-REFRESH (period-keyed, frozen on close):** expensive, out-of-band/late inputs, must be reproducible + signed → COGS, COP, month-end tank snapshot, WAC.

## 🔴 TWO LIVE LANDMINES (PM-verified live 2026-05-25 — load-bearing)
- **(a) `cop_monthly` / `cogs_monthly` / `mi_weighted_prices_monthly` are EMPTY shells (0 rows each)** while the maltytask Node pipeline still writes the Sheet. A maltyweb-native COGS/COP/WAC builder that POPULATES these WHILE Node still runs = **two-writers-for-one-fact divergence** (the cardinal sin). **The Node writer MUST be retired/redirected BEFORE the DB builder goes live.** One writer per derived table, recorded in `schema_meta.writer_script`.
- **(b) `inv_consumption` has ZERO packaging rows** (PM-verified: brewing 4897, sales_derived 3633, fermenting 277, **packaging 0**, total 8807). This single gap silently blocks dynamic RM + COP + COGS SIMULTANEOUSLY → **HIGHEST-leverage upstream fix.** Fix at the PRODUCE-SIDE packaging form (the produce-side packaging consumption write), **never paper over in the compute layer.** This IS the known paused packaging-consumption gap — same gap as `feedback_packaging_consumption_pipeline_gap` + the RM-retro pause (`project_rm_retro_paused_2026_05_22`: Dec/Jan/Feb/Mar RM blocked on exactly this). The Stage-1 packaging-form picker (`539bad3`) is the UI that feeds it; closing the write path here is what unblocks the retro months.

## PHASED PATH (the computation-layer arc — distinct from the Stage-1 backlog)
- **Phase 0:** (i) **schedule the `ref_sku_bom` nightly cron** [#1 leverage — CLI exists, unscheduled]; (ii) write the **matrix-table migration skeleton** (the canonical computation-matrix template from the `sql` skill); (iii) surface `computed_at` "as of…" freshness on dashboards.
- **Phase 1:** (i) convert the orphaned **`ref_supplier_summary` → a view** as the learning case [PM-verified: `derived`, `blocked_with_redirect`, writer "(unknown — possibly a removed cron)", 131 rows — a derived table with no live writer = the safest first view conversion]; (ii) express **dynamic-RM + live-tank-volume as parameterized views** so PHP + Node share ONE definition.
- **Phase 2:** (i) **closed-period immutability guard** so a cron can't silently restate a signed-off month; (ii) **staleness detector** = automated missed-trigger alarm (the on-write-vs-scheduled delta check); (iii) cost CHECKs on other summaries (mirror `chk_rsb_mi_id_not_null`).
- **Phase 3:** KPI views → eshop cage matrix (Stage-2) → liquid-side BOM recompute (the deferred §8.8 F2, see packaging-bom-model.md).

(Full durable craft — 4-layer model L0→L3, the long-format computation-matrix template, the explicit NON-goals (no partitioning / no incremental-delta / no Event-Scheduler-for-everything), and the per-derived-table correctness contract — is in the `sql` skill, "Computation layer" section.)
