# DB Quality / Consolidation arc

**Origin:** 2026-06-23. Kouros asked for a full DB quality/consolidation audit, triggered by the `run_type→FR-label` duplication surfaced during the order-confirmation container-type désignation build (4 divergent inline run_type maps). Read-only/propose-only senior-DB audit dispatched → PM architectural sign-off gate → tiered proposal → operator picks scope.

**The audit report itself was authored in `/tmp/.../scratchpad/db-quality-audit.md` (EPHEMERAL — will be lost).** This file is the durable PM record of its findings + my sign-off + the build outcome. If the full report is wanted in-repo, persist it under `docs/` — flagged to coordinator 2026-06-23, not yet done.

---

## Headline health (audit verdict — DB is HEALTHY)
142 base tables + ~16 views; small, well-tended. Largest: comm_messages 100MB/2009 rows, inv_sales_ledger 18MB/44127, inv_consumption 12.7MB/16283, audit_row_revisions 12MB/22016; everything else <8MB.
- **0 orphan rows** on all 7 critical fiscal FKs (inv_sales_ledger.sku_id_fk, inv_consumption.mi_id_fk, ref_sku_bom.{mi_id,sku_id}, inv_deliveries.ingredient_fk, bd_packaging_v2.sku_id_fk). **0 FK cols missing an index.** Clean INT→INT/BIGINT→BIGINT FK typing (ref_packaging_formats.id BIGINT exception consistently honored). schema_meta complete bar 5 dated backup/probe leftovers.
- Audit correctly RULED OUT the intentional parallel/provenance stores (do NOT re-flag in future passes): inv_deliveries.mi_id_raw (re-resolution input), ref_skus.beer_raw/unit_label (no-JOIN display), ref_mi.mi_id / ref_suppliers.supplier_id (legacy VARCHAR external codes), last_modified_by ENUM('ingest','web') (re-ingest-overwrite provenance), polymorphic inv_consumption.source_row_id + pl_plan_items.linked_event_id (FK-less by design), doc_files dual-key (no UUID↔INT mis-join), the COGS/COP empty shells + qa_*/supplier_eval/inv_repack_events/inv_fg_transfers (pipeline-pending shells), wac_snapshots/ops_oversell_snapshot/debug_corrections/phantom_cleanup_log (load-bearing despite names).

## Debts cluster in 3 themes
1. **Packaging-format identity TRIPLE-MODELED** (data root of the run_type-label dup): FK `ref_skus.format_id`→`ref_packaging_formats.id` (canonical) + free VARCHAR `ref_skus.format`(16)/`ref_cogs_targets.format`(32)/`bd_packaging.format`(16, v1) + ENUM run_type on 5 tables. **3 vocabularies ALREADY DRIFTED:** ref_skus.format={Bot,Can,Can33,Cuve de service,Keg,Vrac}; ref_cogs_targets.format={Bot,Can,Cuv,Keg} (Cuv≠Cuve de service, no Can33/Vrac); ref_packaging_formats.run_type={bot,can,can33,keg,cuv} (lowercase). 6 ref_skus rows have format_id NULL but a string format (PAD/PACKDECX8/PBD/EPH24P/EXP12C/EXP24C, all Bot/Can).
2. **Repeated ENUMs:** run_type ×5 tables+2 views; beer subtype ENUM duplicated ref_beer_types.subtype↔ref_recipes.subtype; beer type ref_beer_types.type ENUM('Neb','Contract') vs ref_recipes.classification ENUM('Neb','Contract') (same set, different column name).
3. **Collation drift** on 3 newest tables → utf8mb4_0900_ai_ci vs project std utf8mb4_unicode_ci (latent error-1267 JOIN trap).

---

## ✅ TIER A — APPLIED + COMMITTED 2026-06-23 (DONE; Kouros picked Tier A now, B/C deferred)
**Migration `441_db_hygiene_tier_a.sql`, commit `d63912d` (by pathspec).** (Email-arc container-désignation committed separately `ac3d223`.) Followed PM carve-outs exactly:
- **Collation CONVERT → utf8mb4_unicode_ci** on inv_repack_events (0 rows), inv_side_stock_ledger (13), ref_customer_identity (40). Verified. 🔴 ref_customer_identity = Cobra billing-alias (mig 389), on a LIVE fiscal JOIN to ref_customers — genuine latent-error close, not cosmetic.
- **Dropped duplicate FK `ref_skus.fk_sku_recipe`** (kept fk_skus_recipe; shared backing index idx_recipe retained for survivor — no orphan index).
- **Dropped dup indexes** bd_tank_readings.idx_tank_read_lotday + pl_plan_days.idx_pdays_date (column-identical to their UNIQUE twin). 🔴 The 8 v1 bd_* idx_sheet_row_index==uq_sri dups **DEFERRED to v1-decommission arc** (PM ruling — never touch v1 bd_* outside that arc).
- **Dropped 5 leftover probe/snapshot tables** (_alt2_probe, _snap_refskus_cuv_hlrevert_20260610, _snap_skubom_cuv_hlrevert_20260610, bd_packaging_v2_contractfix_snapshot_*, bd_packaging_v2_lossfix_*) — ARCHIVED first to `/var/www/maltytask/data/snapshots/tier-a-dropped-tables-20260623.sql` (748K, 992 rows) per snapshot-before-DROP gate.
- **Migration discipline honored** (parallel-session-safe): next-free 441 taken at build-time off `--status` (local repo had only 438; DB had 440 from a parallel session); deployed ONLY the 441 file (not whole dir); re-checked --status (only 441 pending) before migrate.php; SET lock_wait_timeout=10; no SELECT in the .sql. No dirty-tree collision (A = DB DDL, dirty tree = PHP/JS).

---

## 🟡 TIER B — PROPOSAL (not offered to Kouros yet; gated on PM + the Vrac ruling)
Sequence: B-first, C-gated-on-B. Every C depends on a B verification.
- **B.5 KEYSTONE — Stage-1 format reconcile:** resolve the 6 NULL ref_skus.format_id from string label via canonical map {Bot→bot,Can→can,Can33→can33,Keg→keg,Cuve de service→cuv}. 5 map cleanly; **'Vrac' BLOCKED on operator ruling** (no run_type member). Prerequisite for C.9.
- **B.6** drop ~36 prefix-redundant indexes (narrow ⊂ leading-prefix of composite UNIQUE) — reviewed batch, EXPLAIN-gate inv_sales_ledger (44k, only hot table) first; watch FK-relies-on-index trap.
- **B.7** verify NULL sku_id_fk split: inv_sales_order_lines 43.3% (2957/6829), inv_sales_ledger 24.9% (11150/44866) — confirm 100% NULLs are non-beer/credit lines, not unresolved beer SKUs. Read-only query → decision.
- **B.8** investigate 53 kpi_sku_seasonal_index.recipe_id orphans (no FK; 53/795 point at non-existent recipes) — LIKELY EPH re-vintage IDs but QUERY don't guess; fix the rebuild producer FIRST, THEN add FK (else next rebuild fails).

## 🔴 TIER C — OPERATOR RULINGS REQUIRED (consolidation w/ business semantics; not offered yet)
- **C.9 Stage-2 format de-dup — THE PRIZE + the ONLY COGS-MOVER:** retire ref_skus.format / bd_packaging.format VARCHARs, re-key ref_cogs_targets on FK/canonical run_type. **HARD-GATED:** (a) B.5 done incl. Vrac, (b) code grep proves no live reader of the 3 varchars (MUST include the COGS-target *variance board* read path, not just write path), (c) re-key verified vs before/after COGS-variance invariant — **comes through PM for INDEPENDENT number verification before reaching operator** (sub-agent gate-pass insufficient for COGS-bearing claims). 🔴 ref_cogs_targets is commissioning/capacity-derived in the Le Zeppelin chain — re-key must preserve the target↔format binding the capacity-gating depends on.
- **Vrac ruling (PM framing, not yet put to Kouros):** Vrac (bulk/L) is pre-packaging liquid with NO packaging format → **format_id-NULL is likely SEMANTICALLY CORRECT, not a smell** (refuse-don't-NULL applies to BOM lines, not to a format that legitimately doesn't exist). Recommend: rule Vrac as legitimately format_id-NULL, do NOT invent a run_type for it.
- **C.10 run_type ENUM consolidation:** ref_packaging_formats = run-type dimension of record; consumers prefer format_id FK+JOIN over redeclared ENUMs. **KEEP bd_packaging_v2.run_type ENUM as-is** for operator-form ergonomics (document as mirroring ref_packaging_formats.run_type; don't FK-ify a hot form-write table for purity). The app-layer label target = the NEW `run_type_container_label()` accessor in app/sku_catalog.php (collapse the 4 divergent PHP maps onto it, call-not-copy). Lower urgency than C.9.
- **C.11 beer subtype/classification ownership:** ref_beer_types is canonical for ALL beer metadata (firm rule). ref_recipes.subtype/classification → documented denormalized cache w/ reconciliation check, or drop for the recipe→beer-type link. **VERIFY current agreement FIRST** — disagreement anywhere = a live beer-tax-routing bug (PD8/beer-tax keys on this) to surface before any schema change. Lowest urgency.
- **C.12 ref_customers.default_transporter_id_fk (100% NULL, 3064/3064) + default_delivery_site_id_fk (97.7% NULL)** — drop (abandoned feature) vs backfill-at-master-root (unfinished). Operator decision.

## Standing gates
- No B/C migration gets built until Kouros picks scope. C.9 COGS re-key → PM independent verification before the number reaches operator.
- Audit report ephemeral in /tmp — persist to docs/ if wanted (flagged, not done).
