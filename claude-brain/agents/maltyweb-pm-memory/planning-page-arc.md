# Planning Page Arc — schema, engine, conventions (load when touching planning)

> Surface: `public/modules/planning.php` + engine `app/planning-eligibility.php` (PURE-READ) + **Phase-3 producer `app/planning-predict.php`**. Phase 1 (manual week calendar) + Phase 2 (dynamic process-eligibility) SHIPPED+COMMITTED 2026-06-15 (`da202a9`, mig **364** `364_planning_tables.sql`). **Phase 3 (predictive suggestions) SHIPPED+COMMITTED 2026-06-15 (`d102a0d`, mig 365 `365_planning_suggest_settings.sql` — APPLIED on VPS).** `ref_pages.planning` is **is_active=0** (domain='general') — NOT reachable yet; orchestrator activates after final verification; no tour card due until activated (RULE 3 predicate needs is_active=1). MIG HEAD verified **365**, 0 pending (2026-06-15). All facts below VPS/source-verified 2026-06-15.

## Table DDL (live)

**pl_plan_days** — one row per planned date (header/note).
- `id` BIGINT UNSIGNED PK · `plan_date` DATE NOT NULL **UNIQUE (uniq_plan_date)** · `site_id_fk` INT UNSIGNED → ref_sites ON DELETE SET NULL · `note` VARCHAR(500) · `created_by_user_id_fk` INT UNSIGNED → users ON DELETE SET NULL · `created_at`/`updated_at` timestamps.

**pl_plan_items** — one row per planned action (the Phase-3 write target).
- `id` BIGINT UNSIGNED PK
- `plan_date` DATE NOT NULL (NOT an FK to pl_plan_days — keyed by date value; idx_pitems_date, idx_pitems_date_section_seq)
- `section` ENUM('wort','packaging','logistics') NOT NULL
- `seq` INT NOT NULL DEFAULT 0
- `wort_process` ENUM('brewing','racking','kze','dry_hopping') NULL (only for section=wort)
- `recipe_id_fk` INT UNSIGNED NULL → ref_recipes ON DELETE SET NULL
- `batch` VARCHAR(32) NULL
- `beer_free_text` VARCHAR(120) NULL (fallback when no recipe_id)
- `cct_number` INT NULL · `bbt_number` INT NULL
- `pkg_type` ENUM('bottling','canning','kegging','serving_tank') NULL (only for section=packaging)
- `target_volume_hl` DECIMAL(8,2) NULL
- `logistics_text` VARCHAR(1000) NULL (only for section=logistics)
- `hors_process` TINYINT(1) NOT NULL DEFAULT 0 · `hors_process_reason` VARCHAR(255) NULL
- `source` ENUM('manual','predictive') NOT NULL DEFAULT 'manual'  ← **Phase 3 writes 'predictive'**
- `status` ENUM('proposed','planned','done','cancelled') NOT NULL DEFAULT 'planned'  ← **Phase 3 suggestions write 'proposed'** (the ENUM was provisioned for exactly this; Phase 1 uses 'planned', Phase 3 writes 'proposed' then accept flips → 'planned')
- `suggest_reason` VARCHAR(255) NULL  ← **added by mig 365** — human-readable why-this-was-suggested string stamped by Phase-3 producer; rendered as a hint on proposed cards.
- `linked_event_table` VARCHAR(40) NULL · `linked_event_id` BIGINT UNSIGNED NULL (reserved — not yet populated)
- `is_active` TINYINT(1) NOT NULL DEFAULT 1 (soft-delete flag; all reads filter is_active=1; delete_item sets 0)
- `created_by_user_id_fk` INT UNSIGNED → users ON DELETE SET NULL · created_at/updated_at

## Eligibility engine — `app/planning-eligibility.php`
**CARDINAL: PURE-READ, zero writes anywhere.** Entry `planning_week_eligibility(PDO $pdo, DateTimeImmutable $weekStart): array`.
Forward-replay: seeds CCT/BBT working state from `TankSimulator->run(weekStart)` snapshot (+ derives cold_crash_date from bd_fermenting_v2 ColdCrash, racked_on from bd_racking_v2), then for each of 7 days computes eligibility BEFORE applying that day's pl_plan_items, THEN mutates working state from in-plan items (even hors_process=1 propagate occupancy — a planned racking unlocks packaging on later days; a planned brewing occupies a CCT; a planned packaging drains a BBT).
Returns keyed by 'YYYY-MM-DD':
```
['racking'=>[['recipe_id'=>int|null,'beer','batch','cct_number','garde_met'=>true,'source'], ...],
 'kze'=>[[...,'bbt_number','source'], ...],          // ALL BBT occupants
 'dry_hopping'=>[[...,'cct_number','source'], ...],   // ALL CCT occupants
 'packaging'=>[['recipe_id','beer','batch','bbt_number','racked_on','source'], ...],
 'brewing'=>['cct_conflicts'=>[int, ...]]]            // currently-occupied CCT numbers
```
Gates: racking = cold_crash_date set AND effective_garde known AND day−cold_crash ≥ garde AND not already in BBT (same recipe_id+batch). packaging = racked_on set AND day−racked_on ≥ `min_days_after_racking` (commissioning_settings section='packaging'). kze = any BBT occupant; dry_hopping = any CCT occupant. garde via `planning_load_garde_map()` → yeast_eligibility_join_fragment()/select_expressions() (app/yeast-eligibility.php), returns ['by_rid'=>[rid=>garde|null], 'by_beer'=>[name=>garde|null]]. `source` per slot = 'real' (from sim) or 'in_plan' (from a prior-day plan item that created the occupancy).
**Phase 3 consumer:** `planning_generate_suggestions()` (in `app/planning-predict.php`, NOT here — engine stays pure-read) calls this engine; eligible-but-not-yet-planned slots become `source='predictive', status='proposed'` rows.

## pkg_type mapping — NOT from ref_skus.format/run_type
There is **no format→pkg_type helper**. The planning page derives offerable pkg_types from **commissioned fillers**, keyed on `ref_process_machines.machine_type`:
`const PKG_TYPE_MAP = ['filler_bottle'=>'bottling','filler_can'=>'canning','filler_keg'=>'kegging','filler_cuv'=>'serving_tank']` — only types with an active filler are offered (capability-gating). Labels: `PKG_TYPE_LABELS` (Embouteillage/Mise en cannette/Mise en fût/Tank de service). If Phase 3 must map a SKU/run_type → pkg_type (e.g. to size a suggestion), there is NO existing bridge — build one and gate it on the same active-filler set; do NOT invent format strings.

## Write conventions (Phase 1 POST handler, mirror these)
- require_login + role gate (`$canWort`/`$canLog`) + `csrf_verify` + PRG (`redirect_to` preserving `?week=`) + `log_revision($pdo,$me,'pl_plan_items',$id,$before,$after,'normal',null)` on every write.
- INSERT writes literals `source='manual', status='planned'`; seq = MAX(seq)+1 per plan_date.
- delete_item = soft delete (is_active=0), scope-gated by section.
- Query-param read with `??` default THEN validate (week regex `^\d{4}-\d{2}-\d{2}$`).
- All section-specific cols left NULL when not applicable (semantic NULL, fine).

## Phase 3 — AS-BUILT (SHIPPED 2026-06-15, `d102a0d`, mig 365)
Producer: **`app/planning-predict.php` — `planning_generate_suggestions(PDO $pdo, DateTimeImmutable $weekStart, int $targetWeeks): array`** (SEPARATE surface; the engine stays a pure projection — CARDINAL honored).
Algorithm (as built):
1. `fg_stock_compute()` → aggregate per recipe: **worst_semaines = MIN across that recipe's formats** (worst-case coverage, deliberately NOT MAX).
2. Filter recipes whose worst_semaines is below target weeks (`$targetWeeks` ← system_settings section='stock', key='plan_suggest_target_weeks', value=3.0w, mig 365).
3. `planning_week_eligibility($pdo,$weekStart)` (the pure-read engine) for the week's slot map.
4. Per under-stocked recipe: **packaging proposal if a BBT for it is packaging-eligible; else brewing proposal** — brewing does per-iteration CCT allocation from a running `$allocatedCcts` set; logs `brewing_skipped`/`no_free_cct` when no free CCT.
5. INSERT `pl_plan_items` with `source='predictive', status='proposed'`, `suggest_reason` populated. **Dedup against existing ACTIVE items for the week** (no second copy of an already-planned/proposed action). `log_revision()` on every insert.
Page handlers (`public/modules/planning.php`): **generate_suggestions** (calls producer, flashes result) · **accept_proposal** (status 'proposed'→'planned', role-gated, log_revision) · **reject_proposal** (is_active=0, log_revision). `delete_item` now **EXCLUDES status='proposed'** — proposed items MUST go through reject (preserves audit trail). GET path adds `$proposedItems` + `$hasProposals`. Render: dashed card, "Proposé" badge, suggest_reason hint, Accept/Reject controls. `public/js/planning.js` adds confirm() on `.pl-proposed-reject`. `public/css/planning.css` proposed-card styles use **--oak palette tokens only**.
**CARDINAL RULE preserved (verified):** Planning reads canonical state, writes ONLY to pl_* tables — never feeds COGS/COP/WAC/BOM/beer-tax/inventory.

### Standing notes / guards (carry forward)
- Engine remains pure-read; the producer is the only suggestion writer. Never push suggestion logic back into planning-eligibility.php.
- Accept flips status 'proposed'→'planned' in place (source stays 'predictive' for provenance) — no duplicate 'planned' row.
- Refuse-don't-NULL: a suggestion that can't resolve recipe_id falls to beer_free_text (engine supports the beer-name fallback) — never a half-identified row.
- **is_active=0 on the page** → built+committed but surface stays hidden; orchestrator activates after final verification → THEN RULE 3 fires → PM dispatches maltyweb-tour-steward for a tour card. (general/non-admin domain ⇒ a tour card IS due once active.)
- Engine recomputes per-call (no cache); producer calling it once per week is fine for a 7-day window.

system_settings (for reference): UNIQUE (section,key_name); value_text XOR value_num (CHECK); read via app/settings.php::system_setting(). commissioning_settings is a SEPARATE table (same shape) holding min_days_after_racking under section='packaging'.
