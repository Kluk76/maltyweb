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

(B.5 was DONE via mig 442 — see Tier C below; the audit's original "Stage-1 mechanical map / Vrac-blocked" framing was wrong, corrected in the Tier C block. B.6/B.7/B.8 remain proposal — see "TIER B — REMAINING PROPOSAL" below.)

## ✅ TIER C — APPLIED + COMMITTED 2026-06-23 (mig `442_db_consolidation_tier_c.sql`, commit `1c6918c`; Kouros said "Vrac first, then the rest of Tier C")
🔴 The audit's B.5/C premises were several WRONG — coordinator live-verified + corrected before build (recorded so a future pass doesn't repeat the audit's errors):
- **Vrac = ALREADY CORRECT, no action.** Only 1 SKU has format='Vrac' (JASPL "1 litre (vrac)"), ALREADY at **format_id=24** (`L — Vrac (litre)`, run_type NULL). Bulk litre legitimately has no run_type. Audit's "Vrac is one of the 6 NULLs / needs a ruling" was WRONG — nothing to rule.
- **B.5 — the 6 NULL-format_id rows were NOT clean Bot/Can** (audit's "mechanical map" premise dead): 4 INACTIVE (PACKDECX8=Shopify alias of PD8; PAD, PBD=discontinued composite packs) + 3 ACTIVE (EPH24P=Shopify alias of EPH24PB; EXP12C/EXP24C="Pack Explorer" multipacks beer_raw='Mixed', NO ref_packaging_formats row). String-format mapping would have MISLABELLED multipacks as plain bottle/can. AS-BUILT per PM decision-tree: **minted 2 composite format rows for Pack Explorer (EXP12C→#25, EXP24C→#26)** convention-matched to PD8/PAL/PAC/XMAS (`is_composite=1, run_type NULL, display_family multipack, catalog_id=16`) — this was the **build-now-AFTER-the-PD8-modeling-probe** branch (probe showed composites carry a real format row → minting #25/#26 = alignment to existing convention, NOT an unverified capability mint; the gate held); wired EXP12C/EXP24C to #25/#26; **alias inheritance** EPH24P→EPH24PB(#4), PACKDECX8→PD8(#19); PAD/PBD left NULL (genuinely discontinued, non-alias).
- **C.10 — DOCUMENT-ONLY (no ENUM restate):** ref_packaging_formats.run_type documented canonical via schema_meta note. bd_packaging_v2.run_type ENUM KEPT as-is (form ergonomics). App-layer label already consolidated onto `run_type_container_label()` (app/sku_catalog.php).
- **C.11 — KEEP as documented cache (0 disagreements across 71 name-linked rows → NO live beer-tax bug, pure modeling cleanup):** ref_recipes.subtype/classification KEPT (not dropped) as documented denormalized cache; ref_beer_types stays canonical. Created reconciliation guard view **`v_recipe_beertype_classification_drift`** (inline in 442, schema_meta derived/blocked) to flag any FUTURE ref_recipes↔ref_beer_types disagreement.
- **ref_customers.default_transporter_id_fk / default_delivery_site_id_fk** (100%/97.7% NULL, no PHP readers) — **KEPT: Kouros confirmed PLANNED feature, not dropped** (PM lean was keep). Operator ruling closed.

🔴 **DURABLE CONVENTION (process note from the 442 build):** the building agent first wrongly applied the C.11 view OUT-OF-BAND, mis-citing the migrate.php "no SELECT/view" anti-pattern (#10). CORRECTED: migs 387/417/326 create views INLINE and apply fine. **`CREATE OR REPLACE VIEW` is SAFE inline in migrate.php**; the 250/251 PDO-2014 poison was specifically **CREATE VIEW *followed by DML that JOINs the view* in the same exec() chain**, NOT CREATE VIEW + a non-referencing INSERT. 442 has the view inline (387 shape). → fold into conventions-and-helpers / migrate.php discipline.

## 🟡 STILL DEFERRED — PROPOSAL (gated on PM / Kouros)
- **C.9 Stage-2 format de-dup — THE PRIZE + the ONLY COGS-MOVER (DEFERRED, not abandoned):** retire ref_skus.format / bd_packaging.format VARCHARs, re-key ref_cogs_targets on FK/canonical run_type. 🔴 **The only reader of ref_cogs_targets is maltytask Node `lib/cogs-targets.js`** (the COGS-variance consumer) — NOT any maltyweb PHP. So it's a **cross-repo change** (maltyweb DDL + maltytask Node read-path rewrite). PM verdict: **DEFER, fold into the next maltytask COGS-pipeline touch** (do the Node rewrite once, not as a standalone 2-repo migration); benefit (kill one drift vector on a low-churn operator-set targets table) is disproportionate to doing it standalone now. 🔴 ref_skus.format / bd_packaging.format VARCHARs **STAY for now** — documented as the legacy-Node read surface (not silent drift). When built: **PM computes the COGS-target variance independently for a sealed month both ways (string-keyed vs format_id-keyed), assert per-format numbers byte-identical before/after** — sub-agent gate-pass insufficient. ref_cogs_targets is commissioning/capacity-derived in the Le Zeppelin chain — re-key must preserve the target↔format binding.
- **5 unlinked Contract recipes (Nylo, Brasserie 28 Blonde/IPA/Triple, Toccalmatto-Stria) — needs KOUROS:** they carry their own classification with NO ref_beer_types parent → no reconciliation anchor. Backfill the missing ref_beer_types rows, but **confirm type/subtype per recipe with Kouros — do NOT guess the tax category** (classification drives beer-tax routing). Small narrow ask.

## 🟡 TIER B — REMAINING PROPOSAL (B.5 DONE via 442 above; rest not offered)
- B.6 drop ~36 prefix-redundant indexes (EXPLAIN-gate inv_sales_ledger first; FK-relies-on-index trap).
- B.7 verify NULL sku_id_fk split (inv_sales_order_lines 43.3%, inv_sales_ledger 24.9%) — confirm 100% non-beer/credit, not unresolved beer SKUs.
- B.8 investigate 53 kpi_sku_seasonal_index.recipe_id orphans (likely EPH re-vintage IDs — QUERY don't guess); fix rebuild producer FIRST, THEN add FK.

## Standing gates
- C.9 COGS re-key → PM independent variance verification before any re-keyed number reaches operator; fold into next maltytask COGS-pipeline touch (cross-repo).
- 5 unlinked Contract recipes → Kouros confirms type/subtype (no guessing tax category) before ref_beer_types backfill.
- Audit report PERSISTED → `docs/db-quality-audit-2026-06-23.md` (commit `e444cdb`).
