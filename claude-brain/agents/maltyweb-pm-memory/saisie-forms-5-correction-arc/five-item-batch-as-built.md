# Saisie-forms — 5-item correction batch (as-built)

> Part of the saisie-forms 5-correction arc. Parent index: [README.md](README.md).

## ✅ SHIPPED + LIVE + VERIFIED — universal "Corriger" on past submissions, hard-UPDATE = truth (2026-06-04)
Operator: "add the modification option on past submitted forms, exactly like the other forms; modifications do HARD changes in DB (modified data = truth); make ALL modification modules in ALL forms act that way." **As-built deployed via `bin/deploy.sh --apply`, 4 files lint-clean, NO migration. PM-verified. Working tree UNCOMMITTED (operator commits the session).** Net: all 4 saisie forms now have hard-overwrite edit ("modified row = truth") with correct update-audit; racking was the only gap and it's closed.
- **AS-BUILT — Part A (racking edit):** `public/modules/form-racking.php` gained `?edit=<bd_racking_v2 id>` mode. Entry = a "Corriger" column added to the form's OWN inline recent-submissions table (~L1815; no partial delegation, no new page). Full-form prefill, "Modifier Soutirage" + identity strip, hidden `edit_submitted_at` (strict-regex `Y-m-d H:i:s(.u)` validated) + hidden `edit_id`. NK = `[submitted_at, neb_beer, neb_batch, contract_beer, contract_batch, seq]`; original `submitted_at` preserved → `bd_upsert` UPDATEs in place (no dupe). `racked_vol_hl` RE-DERIVED from the edited flowmeter via the existing COALESCE(end−start, manual) path (mig 258), not passed through. row_hash recomputed from edited POST values via form-racking.php's single live hasher (the 3-block byte-identical footgun did NOT apply — shell path is dead).
- **AS-BUILT — Part B (uniform audit precision, all 4 forms):** the MAIN-upsert edit path now fetches the pre-image and passes it as `$before` to `log_revision` → edits log `action='update'` with real before_json (+ `,correction` appended to audit_flags), across form-racking, form-brewing, form-packaging, and **fermenting-phase-submit** (the `bd_upsert` ~L854, NOT the already-correct DryHop shrink-tombstone call). New submissions keep `$before=null` → `action='insert'`. Confirms the Part-B-CONFIRMED-REAL ruling: `log_revision` derives `$action=($before===null)?'insert':'update'` (app/db-write-helpers.php L248).
- **VERIFICATION (self-cleaning synthetic edits per form, ALL PASSED):** UPDATE-in-place (id stable, NK preserved, zero dupes); `action='update'` + non-null before_json; row_hash changed/consistent; racking flowmeter re-derive (129.5−100=29.5, end<start refused, manual fallback null); a fresh-`submitted_at` control proved the preserve-guard. All synthetic data + their audit rows DELETEd same session. No helper scripts left; no git commit.
- **CIP-GATE LANDMINE — independently confirmed NON-BLOCKING (no action needed):** `racking-form.js` gates submit on `cipMachineActive()` (≥1 machine CIP checkbox). `app/partials/cip-section.php` re-checks those machine checkboxes from the `existing` CIP events (`$anchorIsDone/$isDone/$exInline` → checked), and `cip_events_for('racking',id)` returns the 5-machine structure for every racking (incl. backfills) with done-state → editing a normally-submitted racking does NOT trip the gate. Confirmed for the real use case.
- **No migration** (Part A/B add no columns). Structure now matches brewing/packaging exactly — the corrected-as-built finding stands: live racking writer is monolithic `form-racking.php`, the `partials/racking-phase-*` + `racking-phase-submit.php` two-phase shell is dead (session_id_fk NULL on all 404 rows). The reusable mechanics live in §SAISIE EDIT-MODE CORRECTION PATTERN below.

## ✅ FINAL STATE — ALL 5 ITEMS SHIPPED + LIVE + PM-VERIFIED (2026-06-02)
- **4 commits on maltyweb main, COMMITTED-NOT-PUSHED (local ahead of origin/main by 4):**
  - `68ae0b4` #2 racking flowmeter (mig 258).
  - `039d4e3` shared module `public/js/multi-submit-reads.js` + #4 turbidity (mode=average).
  - `b42e8b7` #3 in-filling CO₂/O₂ refactored to module serialize mode.
  - `619b422` #5 all 17 per-format pertes → SUM widgets + #1 qa_analyses auto-derive.
- **Mig 258 APPLIED LIVE** (`258_racking_flowmeter_counter.sql`, schema_migrations 2026-06-02 18:14). PM-verified on VPS: `bd_racking_v2.flowmeter_start_hl` + `flowmeter_end_hl` both `decimal(8,1)` NULL=YES. Only schema change in the batch.
- **Shared module shipped:** `public/js/multi-submit-reads.js` (vanilla IIFE; modes serialize / sum / average; min-1; contiguous re-index; blank-excluded aggregate; pre-submit sync; `destroy()` fixed) + `msr-` scoped CSS (NOT inline — house rule honored).
- **Item #1 reverses the 2026-06-01 never-derive ruling PER OPERATOR** (deliberate, recorded). `qa_analyses_units` now auto-derived read-only = count of in-filling reads, MAIN-row only (parallel rows = 0). Server change one line at `form-packaging.php:723`; compute path untouched; FORWARD-ONLY (~966 historical hand-entered rows unchanged).
- **Verification (Kouros):** `php -l` clean all changed PHP; `node --check` clean all JS; headless module test (avg=0.6, sum 3+5+2=10, blank-excluded, serialize re-index contiguous); compute INVARIANT TEST 13/13 PASS (vendable_hl / beer_tax_base_hl / loss_kpi_hl byte-unchanged — the COGS/tax gate for #5+#1 held); both forms clean 302 unauth, zero php-fpm errors; VPS checksums match local; ownership `maltytask:www-data 644`.
- **PLACEMENT DECISION (recorded):** flowmeter_start captured on racking **in_progress** phase (not the literal firewall 'start' phase, which doesn't write to `bd_racking_v2`); flowmeter_end on end phase (end UPDATE recomputes `racked_vol_hl` via COALESCE: derived end−start when both present, manual fallback otherwise, negative refused). 3 `$hashCols` blocks updated identically (form-racking.php + in_progress + end). If operator later wants it literally on the start screen, that's a follow-up.
- **NOT YET DONE (operator-dependent, the only open thread):** real authenticated round-trip submit on each form — operator enters reads/pertes/flowmeter and we confirm the DB rows land. This is first-use confirmation, not a build gap.
- **PUSH PENDING:** 4 commits not yet pushed to origin (operator hasn't asked — house rule: push only on request).
- **NOTE (not a divergence):** untracked `app/packaging-loss-types.php` in the working tree belongs to the PAUSED A-LT data-driven-loss-types arc (codegen for `v_bd_packaging_v2_vendable` from `ref_packaging_loss_types`), referenced by nothing in HEAD — leftover scaffolding from that separate arc, not part of this batch.

## Migration number
- **Next free = 258.** Verified 2026-06-02 against live `schema_migrations` (max applied = `257_backfill_historical_intank_contract_lane.sql`, 2026-06-01 14:02) AND repo `db/migrations/` (highest file = 257; note a benign DOUBLE-252 already in repo+applied — `252_create_inv_rm_stocktake_lines.sql` + `252_cuve_late_may_2026_data_correction.sql`; not our concern). Only item #2 needs a migration. Re-run `php scripts/migrate.php --status` at build-start per pre-flight rule (drift caught every time historically).

## LOCKED DECISIONS

### Item #1 — QA "mesures pertes" qa_analyses_units AUTO-DERIVE (reverses 2026-06-01 never-derive ruling; operator reopened)
- 1:1: each O2/CO2 read row = 1 full unit destroyed (full BOM: liquid+label+container+cap). `qa_analyses_units` = count of read rows.
- Counts ANY read row entered (even CO2-only or O2-only); bottle sacrificed regardless.
- FORWARD-ONLY: ~966 hand-entered rows UNTOUCHED. Auto-derive on new/edited sessions only.
- FULLY LOCKED: read-only, NO operator/admin override. Always == read-row count.
- It's a BUILD not a regression-fix: field is currently plain manual (`$fQaAnalyses` from `$f['qa_analyses_units']`), no auto-sum wired.
- **🐛 FOLLOW-ON BUG (pre-existing in this same item, surfaced 2026-06-05 during the WL round-trip; FIXED).** The read-only `qa_analyses_units` auto-derive (count of in-filling CO₂/O₂ read pairs) did NOT recompute after a DRAFT RESTORE. Root: FormFramework `loadDraft` sets input values WITHOUT dispatching `change`/`input` events, and the only post-restore `syncQaAnalysesDisplay()` call was gated inside the edit-mode block → on a draft restore the display kept the stale (usually 0) count. **Fix: call `syncQaAnalysesDisplay()` (a) after `FormFramework.init()` and (b) on the co2o2-mount `input` event.** Shipped in commit `619b422` ("qa_analyses auto-derive #1") — it was live BEFORE the WL deploy; operator caught the gap during WL testing. General lesson for derived-readonly fields: any auto-derived display MUST be resynced after a programmatic value-set (draft restore / edit-load), because framework setters don't fire DOM events.

### Item #2 — Racking flowmeter (NEEDS MIG 258)
- ADDITIVE: `racked_vol_hl = flowmeter_end − flowmeter_start` (read-only) WHEN both present; manual `racked_vol_hl` kept as FALLBACK when a reading is missing.
- Store BOTH raw start+end (immutable event facts) AND the derived delta.
- Counter xxxxx.x hl (max 99999.9), never resets/wraps mid-session; still guard+flag negative deltas.
- Cols: `flowmeter_start_hl` / `flowmeter_end_hl` `DECIMAL(8,1)` on `bd_racking_v2`. Add to `$hashCols` in BOTH paths. Wire into legacy `form-racking.php` AND shell `racking-phase-submit.php` (start/end partials).

### Item #3 — O2/CO2 reads multi-submit module
- Reusable module (like RM inventory) but BATCHED (rows held in form, written on submit) on the STANDARD packaging form — because `packaging_v2_id` doesn't exist until submit.
- FORWARD-COMPATIBLE so it drops into the packaging daily-shell when pilot 5 is built. Packaging daily-shell does NOT exist yet (confirmed) — wire on standard form now.
- Populates EXISTING `bd_packaging_readings` (no new reads DB).

### Item #4 — Turbidity multi-submit on racking
- Same module, min 1 input, computes SESSION AVERAGE.
- Store ONLY the average into existing `bd_racking_v2.avg_turbidity`. NO child table, NO migration.

### Item #5 — ALL pertes multi-submit
- ALL pertes/disposition fields → multi-entry SUMMED (bottle/can unit losses, keg litre losses, QA/library — everything) EXCEPT `qa_analyses_units` (now auto-derived per #1).
- min 1 input. Stored value = SUM of entries.
- MUST produce IDENTICAL stored numbers AND identical downstream vendable_hl / beer_tax_base_hl / loss_kpi_hl as typing the total directly — only entry UX changes, never the computed result.

## AS-FOUND CODE MAP (verified 2026-06-02)

### Packaging — `public/modules/form-packaging.php`
- **O2/CO2 multi-read ALREADY BUILT.** POST shape `co2o2[N][co2|o2]`, up to 20 pairs, batched, fully-blank rows skipped (`$co2o2Pairs`, lines 386-408). Written to `bd_packaging_readings` via idempotent DELETE-then-INSERT keyed on `$mainPackagingId` (lines 1119-1140, cols `(packaging_id, packaging_v2_id, reading_idx, o2, co2)`). JS UI ALREADY BUILT in `public/js/packaging-form.js` (`pf-co2o2-list`, `pf-add-co2o2`, add/remove rows, `MAX_CO2O2_ROWS` cap). ⇒ **Item #3 is LARGELY ALREADY DONE** — verify/harden the existing module + factor it into the shared module shape, don't build from scratch.
- **qa_analyses_units write path:** `$fQaAnalyses = $f['qa_analyses_units']` (line 723), stored on row (line 893) AND fed into `compute_packaging_vendable_hl()` partialRow (line 817) where it is **SUBTRACTED from vendable_units** (line 264) ⇒ directly drives `vendable_hl`/`beer_tax_base_hl`/`loss_kpi_hl`. So item #1 auto-derive must replace the SOURCE of `$fQaAnalyses` with the read-row count, leaving the compute untouched.
- **KEY ARCHITECTURAL TENSION (item #1):** `co2o2Pairs` are SESSION-LEVEL, keyed to `$mainPackagingId` (the MAIN format row; line 1052-1053). But `qa_analyses_units` is stored PER-FORMAT-ROW. ⇒ auto-derived count can only correctly attach to the MAIN row. Parallel/format rows must get qa_analyses_units=0 (or NULL) under auto-derive — NOT a copy of the session count (would multi-count the sacrificed bottles). This is the one non-trivial design call in #1; the read=destroyed-bottle is a single physical event tied to the main run.
- Edit-mode re-seed: `$pfStickyInFilling` loads existing readings keyed on main row id (line 1260, 1399-1412) — must round-trip through the shared module on edit.

### Racking — `public/modules/form-racking.php` + `public/api/racking-phase-submit.php`
- NO flowmeter cols exist yet (grep clean). `avg_turbidity` ALREADY EXISTS (`post_decimal`, line 155/417, in `$hashCols`). `racked_vol_hl` is manual `post_decimal` (line 152/415).
- **`$hashCols` is DUPLICATED** in form-racking.php (lines 365-385) AND racking-phase-submit.php (in_progress lines 292-310 + end-phase lines 472+). Comment in submit handler: "must use same canonical order as form-racking.php $hashCols". ⇒ **flowmeter cols MUST be added to ALL THREE hashCols blocks in the SAME position** or in_progress/end/legacy row-hashes diverge → bd_upsert idempotency breaks (phantom duplicate rows). This is the #1 footgun for item #2.
- Shell racking partials exist: `public/modules/partials/racking-phase-start.php` / `racking-phase-end.php` / `-in-progress.php` / `-recent.php`; submit handler `public/api/racking-phase-submit.php` (in_progress INSERT, end UPDATE). Flowmeter inputs: start reading at phase-start, end reading at phase-end.

## SHARED MODULE
- `public/js/multi-submit-reads.js`, config-driven modes: (a) batched-serialize (#3 co2o2 → co2o2[N][...]), (b) running-sum (#5 pertes → single hidden total OR sum on submit), (c) running-average (#4 turbidity → single avg). CSS in `public/css`. The existing `pf-co2o2-*` JS is the de-facto v0 of mode (a) — generalize from it. SHIPPED 2026-06-02 (commit 039d4e3), proven on #4 turbidity (avg mode, headless smoke passed). #2 flowmeter shipped mig 258 / commit 68ae0b4.

## PROGRESS 2026-06-02
- #2 (flowmeter) LANDED+LIVE: mig 258, all 3 $hashCols blocks, in_progress captures start / end captures end + recomputes via COALESCE. commit 68ae0b4.
- shared module + #4 (turbidity avg) LANDED+LIVE: commit 039d4e3.
- REMAINING: #3 (co2o2 → serialize), #5 (all pertes → sum), #1 (qa_analyses auto-derive). Sequence stays #3 → #5 → #1.

## CRITICAL CORRECTION — TWO co2/o2 subsystems on packaging-form.js (memory was under-described)
The packaging form has **TWO PHYSICALLY DISTINCT co2/o2 paths** that #3 must NOT conflate (this is the in-tank vs in-filling split, per the CO₂/O₂ dimension-vs-loss ruling):
1. **IN-TANK gate read** (single pair, NOT the multi-read module): `pf-tank-co2`/`pf-tank-o2` inputs; resolveTankRead/setTankReadInheritMode/clearTankReadInheritMode/updateTankReadState (JS ~906-1014). Writes to `bd_tank_readings` (Step A, ~`$tankReadMode==='own'`), FK'd via `tank_read_id_fk`. The override block `pf-co2o2-override-block` + `pf-tank-reading-override-checkbox`/`-reason-row` belongs to THIS subsystem (the inherit/override gate, JS 1016-1034), DESPITE the misleading `co2o2` id. #3 MUST NOT TOUCH this.
2. **IN-FILLING multi-reads** (the actual #3 target): `.pf-co2o2-row` / `co2o2_N_co2` / `co2o2_N_o2`, addCo2O2Row, MAX_CO2O2_ROWS, seeded 3 rows. POST `co2o2[N][co2|o2]` → `$co2o2Pairs` (PHP 386-408) → Step D write to `bd_packaging_readings` (PHP 1119-1140) keyed on `$mainPackagingId`. This is the loss-lane.
3. **INVERSION GUARD** (JS 1036-1110): submit-listener over `.pf-co2o2-row`; co2Flag/o2Flag mirror `db-write-helpers.php bd_qc_flag` (co2 outlier <2.5||>6.0, elevated <3.5||>5.0; o2 outlier >200, elevated ≥50); if swapped severity < as-entered → window.confirm → `co2o2InversionConfirmed=true` + programmatic form.submit() bypass. Operates ONLY on in-filling rows. #3 MUST PRESERVE: re-implement as rowValidator/onSubmit hook, keep the confirm-don't-block + bypass-flag + focus-first-inverted behavior byte-identical.
4. **Edit-mode re-seed** (PF_EDIT_STICKY_FILLING = in-filling pairs array; PF_EDIT_STICKY_TANK = single in-tank pair). In-filling round-trips through #3's module; in-tank stays on the gate subsystem.

## ITEM #1 auto-derive — EXACT insertion point (verified 2026-06-02)
- `$fQaAnalyses` set per-format-row at PHP **line 723** (`$f['qa_analyses_units']`). Consumed by `compute_packaging_vendable_hl` partialRow (line ~817) and stored on row (line ~893). In the compute fn (bottle/can branch ONLY, lines 240-300) it is `bcsub`-tracted from vendableUnits (line 264). KEG/CUV branch does NOT use qa_analyses at all.
- Auto-derive plan: `$co2o2Pairs` is already parsed at line 386-408 (BEFORE the format loop at 657). So `count($co2o2Pairs)` is available when building each row. Override `$fQaAnalyses` = `count($co2o2Pairs)` ONLY for the MAIN row (`$fOrigin==='main'`), force `0`/null for parallel. This must be applied at BOTH the partialRow build (line ~817, so the compute sees it) AND the stored row (line ~893) — same value, both places, or stored ≠ computed-input.
- $mainPackagingId is the row id of the row with `origin==='main'`, assigned in the write loop at line 1052-1053 — but that's POST-compute, too late for #1. The main/parallel discriminant available at row-build time is `$fOrigin` (read from `formats[N][row_origin]`, validated ∈ {main,parallel} at line 734). USE `$fOrigin==='main'`, NOT $mainPackagingId, to gate the auto-derive.
- Counts ANY blank-excluded read pair (CO2-only or O2-only still counts — match #1 ruling). `$co2o2Pairs` already skips fully-blank rows (386-408), so `count()` is correct IF a CO2-only/O2-only row survives that filter — VERIFY the filter keeps half-filled pairs before wiring (footgun: if the parse drops a row missing one value, the count under-reports the sacrificed bottles).

## ITEM #5 — COMPLETE pertes inventory (verified PHP 707-733)
Multi-entry SUM candidates (operator: ALL except qa_analyses_units). Two groups by COGS/tax role:
- **BEER-disposition (FEED compute_packaging_vendable_hl → COGS/tax-critical, INVARIANT TEST mandatory):** `unsaleable_units`, `loss_uncapped_units`, `loss_half_filled_units` (counts ×0.5!), `loss_untaxed_full_units`, `qa_library_units`, `loss_liquid_other_units`; keg/cuv: `loss_keg_liquid_l`, `taproom_keg_l`. These are the ones the compute fn reads (240-300).
- **MATERIAL-scrap (stored, NOT subtracted from vendable — decision 6):** `loss_4pack_btl_units`, `loss_4pack_can_units`, `loss_wrap_btl_units`, `loss_wrap_can_units`, `loss_label_btl_units`, `loss_keg_collar_units`, `loss_crown_cork_units`, `loss_can_lid_units`, `loss_keg_save_units`, `loss_container_btl_units`, `loss_container_can_units`. Still multi-entry SUM (operator said all), but NO compute impact → lower blast radius.
- DO NOT make multi-entry: `prod_total_units` / `qte_unites` (production counts, not pertes), `qa_analyses_units` (now auto-derived #1). No rate/percentage fields exist among pertes — all are integer unit counts except `loss_keg_liquid_l`/`taproom_keg_l`/`loss_liquid_other_units` (litres/decimal) which still SUM cleanly.
- INVARIANT: the summed stored value must be byte-identical to direct-total entry. `loss_half_filled_units` ×0.5 happens INSIDE compute (line 261) — sum the RAW count, never pre-halve in the widget.

## BUILD SEQUENCE (confirmed)
#2 (flowmeter, isolated, needs mig 258) → shared module proven on #4 (turbidity, no mig, simplest sink) → #3 (co2o2, mostly refactor of existing) → #5 (all pertes, COGS/tax-critical, biggest blast radius) → #1 LAST (auto-derive, depends on #3's read module being final + is COGS/tax-critical).

