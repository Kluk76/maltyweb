# Planning Page Arc — schema, engine, conventions (load when touching planning) — ✅ ARC CLOSED 2026-06-15

> **✅ SHIPPED & LIVE 2026-06-15 — all three phases built, deployed, verified, committed.** Surface: `public/modules/planning.php` + engine `app/planning-eligibility.php` (PURE-READ) + **Phase-3 producer `app/planning-predict.php`**. Phase 1 (manual week calendar) + Phase 2 (dynamic process-eligibility): mig **364** (`364_planning_tables.sql` — `pl_plan_days`+`pl_plan_items`, INTENT-layer, schema_meta source class). Phase 3 (predictive suggestions): mig **365** (`365_planning_suggest_settings.sql` — `suggest_reason` col + system_settings `stock`/`plan_suggest_target_weeks`=3.0w). **Preset access: mig 367** (`367_*` — grants `planning` to access presets manager / production_operator / logistics_operator; idempotent via `uniq_preset_page` + `INSERT IGNORE`).
> **Commits:** `da202a9` (P1+P2 superset after a history reorg/merge) · `d102a0d` (P3) · `beec798` (mig 367 + tour card). MIG HEAD = **367**.
> `ref_pages.planning` is now **is_active=1** (domain='general'). Tables a **clean slate (0 rows — test fixtures cleaned)**. **CARDINAL RULE enforced in schema_meta + code comments: Planning is INTENT, never read by COGS/COP/WAC/BOM/beer-tax/inventory.** All facts below VPS/source-verified 2026-06-15.

## 🔴 DURABLE LEARNING — a new ref_pages row is INVISIBLE until added to a user's access PRESET (applies to ALL future new pages)
`user_can_access()` only falls through to the **role-floor** for preset-LESS users (i.e. admins). For any onboarded user (who has a preset), a freshly-added/activated `ref_pages` row returns **403** until that page is added to their access preset via `ref_access_preset_pages`. **Adding the page to the relevant access presets (by migration) is a REQUIRED ship step, not optional.** This bit the Planning arc: operators/managers got 403 after `is_active=1` until mig 367 granted the page to the presets. → Ship checklist for any new page: (1) `ref_pages` row + is_active, (2) `ref_access_preset_pages` grant rows for every preset that should see it, (3) RULE 3 tour card.

## Access policy (Kouros decided 2026-06-15) — prod/logistics presets only
- **manager preset → WRITE**, section-gated: `canWort = is_admin || manager_can('production')`, `canLog = is_admin || manager_can('logistics')`. NB the `manager_can` hierarchy makes production ⊇ logistics, so production managers ALSO get logistics write.
- **production_operator + logistics_operator → READ-ONLY** (granted view, no write controls).
- **sales_manager + marketing presets INTENTIONALLY NOT granted.** Currently-excluded humans: **Louis Maechler & Thierry Stierli** (tagged manager/logistics but sitting on the sales_manager preset) and **Olivier Barral** (operator on the marketing preset) — these three have **NO access** to Planning. Revisit at onboarding if access is desired (open follow-up).

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

## Eligibility engine — `app/planning-eligibility.php` (✅ VERIFIED LIVE 2026-06-15)
**CARDINAL: PURE-READ, zero writes anywhere.** Entry `planning_week_eligibility(PDO $pdo, DateTimeImmutable $weekStart): array`.
**LIVE-VERIFIED behaviour:** forward-replay over `TankSimulator->run()` snapshot + in-plan items keyed on (recipe_id,batch). Confirmed: a CCT beer is ABSENT from packaging-eligible; inserting an in-plan racking UNLOCKS it for packaging from `racked_on + commissioning_settings(packaging, min_days_after_racking=1)` onward; racking respects garde via yeast-eligibility (`effective_garde` = COALESCE(override → family)); all gates overridable via `hors_process` + reason; **server-side re-check on POST**.
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

## Phase 3 — AS-BUILT (SHIPPED 2026-06-15, `d102a0d`, mig 365 — ✅ VERIFIED LIVE)
Producer: **`app/planning-predict.php` — `planning_generate_suggestions(PDO $pdo, DateTimeImmutable $weekStart, int $targetWeeks): array`** (SEPARATE surface; the engine stays a pure projection — CARDINAL honored).
**LIVE-VERIFIED:** `fg_stock_compute` coverage → proposes packaging (eligible beers) OR brewing (low-coverage, no eligible liquid); writes `status='proposed' source='predictive'` with `suggest_reason`; manager Accept/Edit/Reject; **never auto-commits**; dedup per beer+section+week; **NO cron** (an optional disabled cron is noted as future). Commit-stage review caught + fixed **2 criticals**: (a) a `$freeCct` shared across brewing proposals (each proposal must allocate its own free CCT), (b) a best→worst-coverage filter inversion (must filter on WORST-coverage format).
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
- Engine recomputes per-call (no cache); producer calling it once per week is fine for a 7-day window.

## ✅ Tour (RULE 3 satisfied)
`maltyweb-tour-steward` shipped the **Planning Visite guidée card** (description + icon + vignette) when `is_active=1` fired the RULE 3 predicate — gap closed. (Separately, the steward drafted a **`financier` tour card held for PM ratification** — that belongs to the **COGS-fiche arc**, NOT Planning; ratify it there.)

## Final state + OPEN follow-ups (2026-06-15)
- **State:** page LIVE (`is_active=1`), `pl_plan_days`/`pl_plan_items` a clean slate (0 rows; test fixtures cleaned).
- (a) **3 excluded users** (Louis Maechler, Thierry Stierli, Olivier Barral) pending an onboarding decision on whether to grant Planning access.
- (b) **Optional predictive cron** — disabled by default (mirrors the fulfilment ships-cron pattern); build only on operator request.
- (c) **Real authenticated browser UAT of the page JS** (dropdowns / generate / accept-reject) — recommended to Kouros; needs a manager login (a subagent can't auth as a manager).

## 🟡 Heads-up (NOT from this arc) — tank-simulator.php in-flight refactor
`app/tank-simulator.php` has a **large uncommitted refactor in the shared working tree from a PARALLEL session** (CCT-destination racking + BBT-overwrite fix). It **preserves the `run()` contract**, so the eligibility engine is unaffected — but flag it as IN-FLIGHT if you sequence anything on the sim. (Pairs with the deploy-pushes-the-working-tree hazard: a deploy from this clone carries that uncommitted refactor live.)

## §MULTI-PROCESS-PER-DAY — overlap shifts (Kouros 2026-06-16, ARC RE-OPENED as continuation)
**Requirement:** a single planning day must hold MULTIPLE items per section at once — overlap shifts do racking + brewing + dry-hopping (all section=wort) AND bottling + kegging (both section=packaging) on the same day.

**🔑 PM verdict — VPS-verified 2026-06-16: ALREADY SUPPORTED END-TO-END. NO migration, NO eligibility-engine change.** Verified against live VPS files:
- **Schema:** `pl_plan_items` is already N-rows-per-(plan_date,section) — `plan_date` is keyed by value (NOT FK to pl_plan_days), `seq` INT, idx `idx_pitems_date_section_seq`. No 1-per-section UNIQUE anywhere. Nothing to migrate.
- **Handlers (`public/modules/planning.php` add_wort l.161 / add_packaging l.300 / add_logistics l.415):** each already does `SELECT COALESCE(MAX(seq),0)+1 ... WHERE plan_date=? AND section=? AND is_active=1` then INSERTs a NEW row — pure append, never an upsert/replace. Adding a 2nd wort item to a day just makes seq=2. Soft-delete per item (is_active=0), scope-gated.
- **Render (l.783+):** groups `$itemsByDaySection[date][section][]` then `foreach ($dayItems['wort'] ?? [] as $item)` / same for packaging/logistics → renders a LIST of `.pl-item-card`s, each with its own delete (and accept/reject for proposed), with the `.pl-add-form` placed AFTER the list. This IS the "list of added items + an add-another row" house pattern (matches saisies.php / expeditions.php multi-row surfaces).
- **Eligibility engine (`app/planning-eligibility.php`):** ALREADY multi-item-deterministic. SELECT `ORDER BY plan_date, section, seq, id` (l.201); indexed `$planByDay[date][section][]` (l.207). Forward replay (l.214): for each day it (a) computes eligibility from working CCT/BBT state BEFORE applying that day's items, then (b) applies ALL wort items via `foreach ($dayPlanItems['wort'] ?? [] as $pi)` (l.313, seq order) THEN ALL packaging items `foreach (... ['packaging'] ...)` (l.377). **Intra-day order is fixed = compute-elig → all wort (seq) → all packaging; replay is stable.** So the deterministic intra-day ordering the operator asked about (brewing-then-racking same day) EXISTS — wort items apply in seq order, so the operator controls relative order via add order, and packaging always sees the post-wort tank state. Brewing occupies a CCT, racking drains CCT→BBT, packaging drains BBT — all compose correctly across multiple same-day items.

**Conclusion:** the shipped page very likely ALREADY does what Kouros wants. The honest next step is the still-open follow-up (c): an authenticated manager browser UAT. Only genuinely-possible gaps are UX polish, NOT architecture:
1. After submitting one add-form, does the operator land back cleanly able to add the next (PRG preserves `?week=`; confirm the add-form re-renders empty and focus isn't lost)?
2. When 3+ cards stack in one narrow day-column, is the density readable on a floor tablet (CSS — `planning.css`)?

If a real gap surfaces → `planning.php` + `planning.css` + `planning.js` ONLY. Do NOT add a migration, do NOT touch the eligibility engine, do NOT add a batch/group table (one fact = one row, append-by-seq is the model). CARDINAL still holds (Planning = INTENT only). EQUIP ui+coder (+webapp-testing for the manager UAT).

---

system_settings (for reference): UNIQUE (section,key_name); value_text XOR value_num (CHECK); read via app/settings.php::system_setting(). commissioning_settings is a SEPARATE table (same shape) holding min_days_after_racking under section='packaging'.
