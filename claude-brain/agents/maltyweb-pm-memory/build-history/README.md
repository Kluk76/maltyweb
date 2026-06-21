# Build history — Stage-1 packaging pipeline + UI arc (completed arcs, audit trail)

> Load when: you need the as-built chronology of the Stage-1 SKU/BOM build, the commit chain, the resolved divergences, or the operational lessons. This is the historical/completed-arc record — the LEAN INDEX holds only the current build-state + RESUME POINT.

## COMMIT CHAIN (maltyweb `main`)
- `ffd003c` — migrations 131–140 + Salle des Machines page/css/js + vessels-card retirement (14 files). 139 committed but undeployed/unapplied (gated until NULL=0).
- `56361e1` — Recettes Formats subtab + scotch either/or model (migration 141).
- `5f69e34` — cuv serving-tank card + gate (migration 142).
- `ba817ed` — `ref_sku_bom` recompute service (`compile_sku_bom_packaging`) + migration 143 + migration-139-applied note.
- `539bad3` — live-BOM UI: packaging-form dynamic active-SKU picker + on-save recompute trigger. RULE 2 commit-stage reviewer exercised for the FIRST time (1 should-fix + nits, all addressed pre-commit).

## STAGE-1 SEQUENCE (§8.4 — all steps DONE)
1. ✅ Migration 141 (scotch either/or) — applied, committed `56361e1`. Detail in packaging-bom-model/README.md.
2. ✅ Scotch-model fix in Formats UI — applied, committed `56361e1`.
3. ✅ Cuv serving-tank card + cuv gating (migration 142) — applied, committed `5f69e34`. Detail in packaging-bom-model/README.md.
4. ✅ Pre-seed bindings via backfill — 30 rows (label 15, can 11, sticker 4, scotch 0). PM-verified, operator-approved.
5. ✅ `ref_sku_bom` packaging-only recompute service — SHIPPED + RUN, NULL 26→0, 6 RQ rows, migration 139 then applied. Committed `ba817ed`. Detail in packaging-bom-model/README.md.
ORDER CONFIRMED with one correction: a migration WAS needed for scotch (step 1 before step 2 — UI reads slots from `ref_packaging_items`). Steps 1-2 and step 3 independent + parallelizable.

## DB SCHEMA — applied migrations (BOM/SKU arc)
- `ref_packaging_bom_templates` (131, seeded 132), `ref_packaging_items.slot_scope` (133), `ref_skus.bom_template_id` (134), `ref_recipe_packaging_bindings` (135), `ref_sku_aliases` (136: EPH24P→EPH24PB, PACKDECX8→PD8), `ref_sku_composite_slots` +units_per_recipe/+member_format_id (137), SPY12C50→12C/0.06 (138). Equipment seed (140): `ref_process_machines` (6 machines) + `ref_filler_containers` (bottling→33cl glass; canning→33+50cl alu; kegging→20L keg).
- **139 APPLIED + ENFORCED:** `chk_rsb_mi_id_not_null` CHECK on `ref_sku_bom` = `(mi_id IS NOT NULL) OR (bom_source='liquid')` (PM-verified information_schema). Refuse-don't-NULL hard floor. Was gated until the recompute cleared the 26 NULL printed-can lines.
- **141 APPLIED** — scotch either/or model (packaging-bom-model/README.md).
- **142 APPLIED** — cuv serving tanks + gate-in (packaging-bom-model/README.md).
- **143 APPLIED** — `doc_review_queue.type` ENUM +`sku-bom-unresolved` (same-list-plus-one MODIFY, idempotent; no schema_meta change).
- **144** — `ref_sku_bom.volume_hl` column applied, but NOT recorded in `schema_migrations` (CREATE VIEW denied — see volume-dimension.md build-state; orchestrator fixing privilege + idempotency 2026-05-26).

## RESOLVED DIVERGENCES (§7 — were operator-pending, now built)
1. **scotch had no recipe-level SoT** — RESOLVED: scotch is 24-box-only, either/or (A branded vs B TRANSP+sticker), NOT "all bottle recipes". `uses_branded_scotch` column exists on `ref_recipes`. Built in migration 141 + UI. Detail in packaging-bom-model/README.md.
2. **cuv (V) gated OUT of the activation UI** — RESOLVED: built the in-house serving-tank capacity card + wired cuv gating IN via a real filler_cuv↔CUV_LINER `ref_filler_containers` row (migration 142). Gate stays sacrosanct (no bypass path). Detail in packaging-bom-model/README.md.

## OPERATIONAL LESSON — interrupted migration recovery (§8.7, bit us twice 2026-05-25; folded into `sql` skill anti-pattern #20)
A build agent's result was lost to a harness "internal error" TWICE; in one case `migrate.php` (over SSH) was interrupted mid-file — leaving migration 142 PARTIALLY applied (MySQL DDL auto-commits, so the ENUM-extend + 3 INSERTs persisted while CREATE TABLE + seed did NOT, and `schema_migrations` was NOT recorded → a naive re-run would have DUPLICATED the 3 INSERTs). Durable conventions:
- **(a) When a build/apply step's result is LOST, do NOT assume nothing ran — probe the live DB/repo state before retrying.** Auto-committed DDL may already be in place; a blind retry duplicates.
- **(b) Write migrations idempotent FROM THE START** — guard every INSERT (ON DUPLICATE KEY / INSERT IGNORE for keyed; INSERT…SELECT…WHERE NOT EXISTS for unkeyed); ENUM MODIFY to the same list is safe; CREATE IF NOT EXISTS. After applying, VERIFY `schema_migrations` recorded the file (count=1) and re-run once to confirm clean no-op. Do not trust "the apply command returned".

## STAGE-1 REMAINING BACKLOG (§8.9 — prioritized; what's left to close Stage-1)
Stage-1's packaging pipeline is COMPLETE (steps 1–5 + migration 139) AND the UI arc's terminal piece (packaging-form picker) + the BOM on-save trigger are SHIPPED. Remaining:
1. **Operator UI to-dos for the 6 RQ rows (via Formats subtab):** set EPH1-4 24-box (BC) sticker bindings — **needs an EPH box-sticker MI created first** (no `PKG_STICKER_EPH*` exists); create a `PKG_4PACK_CAN_ZEP` MI then bind ZEP's `holder` (ZEP4PC) + `outer_tray` (ZEP4C). Once bound, the on-save trigger recompiles those SKUs automatically (clears the RQ rows).
2. **Operator sets scotch A/B bindings for DIV/EMB/MOO/ZEP 24-boxes via the UI.** Currently UNBOUND → recompute defaults them to TRANSP (config B). Set the binding BEFORE those 24-box SKUs are recompiled (they were NOT in the affected-25 set, so no harm yet).
3. **Browser-QA pending — 3+1 surfaces** (DB wiring verified, RENDERED UI not): (a) Salle de contrôle → Formats subtab (activation grid + scotch A/B exclusivity + gap flags), (b) Salle des Machines "Cuves de service" card, (c) the `filler_cuv` machine in the machines grid, (d) the packaging-form dynamic active-SKU picker (`form-packaging.php`): tank-select → tiles, "à assigner" tray, row-0 pre-fill. **NB: until the picker is QA'd, scrapping-backlog #9 is NOT a scrap candidate.**
4. **Recompute triggers — ✅ ON-SAVE HALF DONE (`539bad3`).** ⏳ STILL PENDING: the nightly cron (`scripts/sku-bom-compile-cli.php` exists but is NOT scheduled — the backstop to the on-save hook). = computation-layer Phase 0 #1 leverage action.
5. **DEFERRED — full observed-liquid recompute (§8.8 F2):** needs the canonical observed-liquid per-HL source decision settled first. Today `compile_sku_bom_packaging` (packagingOnly=true) leaves legacy liquid untouched. Observed wins; ref_recipe gap-fill only.
6. ✅ DONE — packaging-form dynamic active-SKU picker (committed `539bad3`). Tank-select → activated formats as full-text `ref_packaging_formats.display_name` tiles (`ref_skus` has NO display_name); NULL-format SKUs → "à assigner" tray (PACKDECX8/PBD/COLLAB4PACK recipe-less + EPH24P recipe 63); `format_id` UI-only (`bd_packaging_v2` has `sku_id_fk`, NO `format_id`); POST contract unchanged (still writes through FORMAT_SUFFIXES/RUN_TYPE_LABELS selects). Browser-QA pending (#3d).
7. **Carry-overs:** client-side serving tanks (6×10 + 3×5 @ client) DEFERRED (location enum ready in `ref_serving_tanks`, add as location='client', no migration). Backfill log-fix (`backfill-recipe-packaging-bindings.ts`) uncommitted in maltytask (needs operator approval to commit).

## ON-SAVE TRIGGER + PICKER as-built (committed `539bad3`)
- On-save: `salle-de-controle.php` POST handlers call `compile_sku_bom_packaging()` (dryRun=false, packagingOnly=true) for the affected recipe AFTER commit, via `sdc_recompile_recipe_packaging()` + `sdc_flash_bom_result()`. Failure caught locally, never loses the committed binding. Nightly cron is the backstop (still unscheduled).
- Picker: `public/modules/form-packaging.php` + `public/css/packaging-form.css` + `public/js/packaging-form.js` (⚠ assets are `packaging-form.*`, NOT `form-packaging.*`). Query = `ref_skus × ref_packaging_formats`, is_active=1 + non-composite + run_type<>''. RULE 2 reviewer found: querySelector→value scan; dropped dead `run_type IS NOT NULL`; `hidden` attr on mosaic; deduped 3× BOM-flash into `sdc_flash_bom_result()`.

## PAGE INVENTORY (modules)
LIVE = `salle-des-machines`, `salle-de-controle` (Recettes/Biochem/Conditionnement), `salle-fournisseurs` (supplier governance), `approvisionnement`, `packaging`, `form-packaging`, `form-racking`, `warehouse`, `tanks`, `wort`, `triage`, `saisies`, `sku-costs`/`sku-cost-detail`, `reglages-generaux`. MOCKS (served static) = `_design/le-cockpit.html` (the Zeppelin hub, topbar "Le Zeppelin"), `_design/salle-recettes.html`.

## CROSS-CUTTING / BACKLOG (non-Stage-1)
- **Site-wide UI recolour to light kraft palette** (same sketches/lines/fonts, lighter colours — `ui` skill; global `:root` tokens in app.css + Option-B SVG tanks; 15 files). DONE — committed `3c57e5b`, deployed + PM-verified on VPS.
- **`admin/settings/*` retirement:** see scrapping-backlog.md (items 1–6). `vessels.php` CARD retired in `ffd003c`; FILE kept as fallback. `devices` STAYS.
- **Generic-BOM template/slot-scope management UI** (add client_supply variants, edit scopes) — to build, likely a Conditionnement→BOM section of Salle des Machines.


## Build-changelog (rolling, append-only — newest first)

> Archived 2026-05-27 from the LEAN INDEX during the index-lean refactor. Each session's dated build narration lives HERE now, not in the index header line — newest entries prepend to the top of this section.


**The dated changelog entries are split by period into sibling files (newest first):**
- [changelog-2026-06/](changelog-2026-06/README.md) — 2026-06 changelog, split by time-window (grew past 80KB, split during the 2026-06-21 compaction): brewing-lookup standalone page (mig 425), 06-21 four-build tranche (mig 424), Planning Phase-2 (mig 364), QA/HACCP (migs 358-361), v1 bd_* decommission, single-source COGS repoint + Financier v1, Mon tableau v2, F2 liquid compiler, fermenting state-gated selector (mig 253), plus the archived former-index snapshots (mig-head banner + RESUME-POINT/BUILD-STATE).
- [changelog-2026-05-29-to-31.md](changelog-2026-05-29-to-31.md) — DIG/QDG consolidation (mig 217); autonomous mother-shell Phase 1 (mig 215) + Phase 2-5 (10 atoms, mig 216); fermenting pilot 4 P-A/P-B/P-C; racking pilot 3; F2 vessel-commissioning closure (migs 210/211/212).
- [changelog-2026-05-27-to-28.md](changelog-2026-05-27-to-28.md) — session-framework infra atoms 3-6; three SKU identity layers + bd_packaging_v2 sku_id_fk mis-route bug; PPB C8 hardening trio + C8 KPI; ZEP6C compiler-gate hardening (mig 191); bd_packaging_v2 SKU resolution + EPH BC→C fold (migs 187/188/189); RawDB→MySQL re-baseline; #44 format→SKU batch (migs 185/186); racking C1/C2/C3a.
- **former-index-archive (verbatim pre-2026-06-05 index narration)** — split into [archive/](archive/) during the 2026-06-21 compaction (the consolidated flat file grew past 80KB; itself merged 2026-06-19 from the former last-updated + resume-point snapshots). Archival; superseded by the thematic topic files + the live §SHIPPED-ARCS pointers in the main index:
  - [archive/former-index-part1a-changelog.md](archive/former-index-part1a-changelog.md) — PART 1 block A: rolling "Last updated" changelog (former index line 5), first monolith block + the 2026-06-11 CIP-cadence archival note.
  - [archive/former-index-part1b-changelog.md](archive/former-index-part1b-changelog.md) — PART 1 block B: rolling "Last updated" changelog, second monolith block + tail.
  - [archive/former-index-part2-resume-point.md](archive/former-index-part2-resume-point.md) — PART 2: former RESUME POINT detailed narration + landed-arc blocks (former index lines 16-91).
- ~~index-snapshot-pre-2026-06-05/~~ — **never materialised** (the directory was promised in the 2026-06-05 refactor note but never actually created). The verbatim pre-2026-06-05 index narration lives in `former-index-archive.md` (above) — use it to recover the full inline narration of an arc the lean index now only points to. Dangling pointer corrected during the 2026-06-15 index compaction.

_Earlier-than-2026-05-27 build narration: this directory begins at the 2026-05-27 index-lean refactor; pre-that chronology is captured in the structural sections above (COMMIT CHAIN / STAGE-1 SEQUENCE / DB SCHEMA / RESOLVED DIVERGENCES)._
