# Planning Page Arc — schema, engine, conventions (load when touching planning)

> Surface: `public/modules/planning.php` (908 lines) + engine `app/planning-eligibility.php` (405 lines). Phase 1 (manual week calendar) + Phase 2 (dynamic process-eligibility) SHIPPED+COMMITTED 2026-06-15 (`da202a9`, mig **364** `364_planning_tables.sql`). `ref_pages.planning` is **is_active=0** (domain='general') — NOT reachable yet; no tour card due until activated (RULE 3 predicate needs is_active=1). MIG HEAD verified 364, 0 pending (2026-06-15). All facts below VPS/source-verified 2026-06-15.

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
- `status` ENUM('proposed','planned','done','cancelled') NOT NULL DEFAULT 'planned'  ← **Phase 3 suggestions write 'proposed'** (the ENUM was provisioned for exactly this; 'planned' is the only value used by Phase 1)
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
**Phase 3 hook:** this is the read side Phase 3 turns into suggestions — eligible-but-not-yet-planned slots become `source='predictive', status='proposed'` rows.

## pkg_type mapping — NOT from ref_skus.format/run_type
There is **no format→pkg_type helper**. The planning page derives offerable pkg_types from **commissioned fillers**, keyed on `ref_process_machines.machine_type`:
`const PKG_TYPE_MAP = ['filler_bottle'=>'bottling','filler_can'=>'canning','filler_keg'=>'kegging','filler_cuv'=>'serving_tank']` — only types with an active filler are offered (capability-gating). Labels: `PKG_TYPE_LABELS` (Embouteillage/Mise en cannette/Mise en fût/Tank de service). If Phase 3 must map a SKU/run_type → pkg_type (e.g. to size a suggestion), there is NO existing bridge — build one and gate it on the same active-filler set; do NOT invent format strings.

## Write conventions (Phase 1 POST handler, mirror these)
- require_login + role gate (`$canWort`/`$canLog`) + `csrf_verify` + PRG (`redirect_to` preserving `?week=`) + `log_revision($pdo,$me,'pl_plan_items',$id,$before,$after,'normal',null)` on every write.
- INSERT writes literals `source='manual', status='planned'`; seq = MAX(seq)+1 per plan_date.
- delete_item = soft delete (is_active=0), scope-gated by section.
- Query-param read with `??` default THEN validate (week regex `^\d{4}-\d{2}-\d{2}$`).
- All section-specific cols left NULL when not applicable (semantic NULL, fine).

## Phase 3 architectural notes / warnings
- **source='predictive' + status='proposed' is the designed lane** — ENUMs already carry both; no migration needed for the lane itself.
- **Engine is pure-read by design (CARDINAL).** Phase 3's suggestion-WRITER must be a SEPARATE surface (handler/cron), NOT inside planning-eligibility.php — keep the engine a pure projection. Suggestions are the delta: eligible slot with no matching planned/proposed item.
- **Divergence guard:** a 'proposed' row that the operator accepts should flip to status='planned' (and likely source stays 'predictive' for provenance) — do NOT create a second 'planned' duplicate. Dedup proposals against existing items on (plan_date, section, recipe_id_fk/beer, batch, wort_process/pkg_type).
- **Refuse-don't-NULL:** if a suggestion can't resolve a recipe_id, fall to beer_free_text (engine already supports beer-name fallback) — never write a half-identified COGS-irrelevant row that later confuses occupancy matching.
- **is_active=0 on the page** → Phase 3 can build/test but the surface stays hidden; when operator activates, RULE 3 fires → dispatch maltyweb-tour-steward for a tour card.
- Engine recomputes per-call (no cache); a proposal-generator that calls it per week is fine for a 7-day window.

system_settings (for reference): UNIQUE (section,key_name); value_text XOR value_num (CHECK); read via app/settings.php::system_setting(). commissioning_settings is a SEPARATE table (same shape) holding min_days_after_racking under section='packaging'.
