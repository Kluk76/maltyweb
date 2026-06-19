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

**Conclusion (PRE-BUILD):** the shipped page ALREADY did what Kouros wanted at the data/engine layer. The verdict held: the gap was UX-polish only, NOT architecture.

### ✅ SHIPPED 2026-06-16 — UX HARDENING (P0+P1) — render-layer ONLY, NO schema, NO engine. Commit `f0361d4` on main (maltyweb). Deployed to VPS (targeted rsync + chown www-data; php -l clean; page 302 auth-redirect no-fatal; assets 200). **NOT git-pushed yet** (held, consistent with prior planning-arc commits awaiting review/merge).
Files touched — `public/modules/planning.php` + `public/css/planning.css` + `public/js/planning.js` ONLY. (Verdict vindicated: zero schema, zero eligibility-engine change.)

**P0:**
- Add-forms collapsed behind a `＋ Ajouter` trigger; reopen the SAME day/section after the PRG round-trip via `sessionStorage`.
- Full-width grid — reset the `.home .main` centering that was constraining the calendar.
- Touch targets ≥44px (× delete / add btn / accept-reject) — floor-tablet compliance.
- No-JS field-visibility bug fixed: `.pl-nonbrewing-fields` now SERVER-RENDERED hidden to match the default brewing selection (previously visible until JS ran).

**P1:**
- Hors-process cards get an ember left-border.
- Empty eligibility dropdown now EXPLAINS why ("garde / état cuve") instead of rendering blank.
- Proposed (predictive) cards get a 2px border + ⟳ glyph.
- Per-form day-label.
- Em-dash empty-state for read-only sections.
- Delete-confirm moved OUT of inline `onsubmit` into delegated JS.
- `.pl-section` dropped `flex:1` → sizes to content.

**P2 polish DEFERRED** pending Kouros's manager-login UAT: beer-name/process hierarchy, `<label>`s, `role=grid`→`group`, flash auto-dismiss.

🔴 **STILL-OPEN follow-up (= the original (c)): authenticated MANAGER browser UAT.** The JS interactions (trigger toggle, sessionStorage reopen, eligibility dropdown, accept/reject) were REASONED about, not clicked — no manager session available to the build agent. UAT must verify these live before P2 / before push. EQUIP webapp-testing once a manager session exists (or Kouros clicks through).

If further gaps surface → `planning.php` + `planning.css` + `planning.js` ONLY. Do NOT add a migration, do NOT touch the eligibility engine, do NOT add a batch/group table (one fact = one row, append-by-seq is the model). CARDINAL still holds (Planning = INTENT only). EQUIP ui+coder (+webapp-testing for the manager UAT).

---

## §FULLER PRODUCTION PLANNER — ✅ SHIPPED + DEPLOYED + COMMITTED 2026-06-19 (commit `521ccbf`, maltytask main, NOT pushed — awaiting operator "push"; mig 401 APPLIED on VPS)
**AS-BUILT (all 3 rounds folded into ONE commit `521ccbf`):** the round-3 ruling below is now BUILT. The planner now factors tank space, packaging output capacity, anticipated racks + dry-hops, and serving-tank count. Producer no longer proposes only brewing+packaging demand.

**What landed:**
- **`db/migrations/401_planning_working_days_and_brew_cap.sql` (APPLIED on VPS):** seeds 9 `system_settings` rows section='production_targets' — `workday_mon..workday_sun` (Mon–Fri=1, Sat/Sun=0), `max_brews_per_day`=5, `max_packaging_runs_per_day`=4. Idempotent `INSERT...ON DUPLICATE KEY UPDATE` preserving `value_num`.
- **`public/modules/salle-de-controle.php` (?sec=objectifs):** whitelist + validation (workday∈{0,1}, ≥1 working-day guard, caps≥1) + render — 7-day toggle card + the two numeric cap editors, reusing the existing `update_production_target` handler.
- **`app/planning-eligibility.php`:** additive per-day `occupancy` block (`cct_occupied`=array_keys(workingCct), `bbt_occupied`=array_keys(workingBbt)) at the `$result[$dayStr]` assignment. Pure-read PRESERVED; existing keys untouched (the ONE sanctioned engine change from the ruling — exactly as scoped).
- **`app/planning-predict.php`:** FULL refactor to 4 ordered per-process passes (**racking → packaging/serving → brewing → dry-hop**) with an in-producer occupancy ledger seeded from the engine block. Weekly budgets via `production_targets_compute` netted against already-planned items. Working-day round-robin spread + per-day caps. REAL fleet reads: `ref_cct`(18)/`ref_bbt`(8)/`ref_serving_tanks`(8 in_house+active) — **hardcoded CCT 1..10 bug FIXED.** Dry-hop gate: `ref_recipe_ingredients.hop_addition_stage='dry_hop'` (col is `recipe_id`, NOT recipe_id_fk) ∩ NOT IN `bd_fermenting_v2` event_type='DryHop' (literal verified). **KZE auto-propose REFUSED** (manual-only, as ruled). Capacity deferrals → `decisions[]` (NOT silent).

**VERIFIED LIVE (Opus, rollback harness then deleted):** week 2026-06-15 → 7 proposals spread Mon–Thu (brews on 3 distinct days within cap, packaging load-balanced) — Monday-dump fixed; brewing used CCTs 1/3/5 from the real 18-fleet.

🔴 **KNOWN v1 LIMITATIONS (keep flagged to future-me + Kouros):**
1. Serving-tank free-count assumes empty-at-week-start (TankSimulator serving-tank dest still a TODO ~L648) — over-estimate, as the ruling predicted.
2. Dry-hop coverage PARTIAL (9 spec-flagged recipes vs ~12+ observed) — under-proposes BY DESIGN (refuse-don't-guess).
3. Packaging throughput = HL/week budget only (`speed_units_h` débit path deferred — out of scope, as ruled).

🔴 **STILL-OPEN GATE (carried from P0/P1 + new surfaces): authenticated MANAGER browser UAT** — the planning-page interactions + the new Suggérer-un-plan output + the salle-de-controle `?sec=objectifs` toggles. Opus can verify structurally/rollback but cannot click as a manager. Needs Kouros (or a manager session) to click through. EQUIP webapp-testing once a manager session exists.

🔴 **PUSH STILL PENDING:** `521ccbf` is on maltytask main LOCAL only — operator hasn't said "push". Commit-by-pathspec discipline held.

---

## §FULLER PRODUCTION PLANNER — Round-4 REFINEMENTS — ✅ SHIPPED (commit `6eb25a7`, maltytask main, NOT pushed; part of the full planner chain). PM ruling 2026-06-19. All 3 confined to `app/planning-predict.php` (+1 label in `public/modules/planning.php`); NO schema/migration/engine change; pure-read CARDINAL preserved.

**1. Core-only filter — recipe_id self-filter on `ref_recipes`, NOT a name-join to ref_beer_types.**
- 🔴 **VERIFIED: there is NO FK between `ref_recipes` and `ref_beer_types`** (checked information_schema both directions — ref_recipes FKs only → ref_yeast_strains, ref_clients; nothing references ref_beer_types). The only link would be `ref_recipes.name = ref_beer_types.beer_name` = **forbidden name-match**. DO NOT touch ref_beer_types for this.
- **Canonical source = `ref_recipes`'s OWN columns** `classification` `enum('Neb','Contract')` + `subtype` `enum('Core','EPH','CollabIn','CollabOut','WhiteLabel','Archive')` (SAME ENUM domains as ref_beer_types.type/subtype, owned at the recipe row the producer already keys on). Live active-recipe pop: Neb/Core=9 (Alternative,Diversion,Diversion Blanche,Double Oat,Embuscade,Moonshine,Speakeasy,Stirling,Zepp), Neb/EPH=4 (EPH1-4), Neb/CollabIn=3, Neb/Archive=3, Contract/NULL=42. No NULLs in Core+EPH set.
- **Core-set SQL (parameterized):** `SELECT id FROM ref_recipes WHERE is_active=1 AND classification='Neb' AND subtype IN (:subtypes)` — `:subtypes` = `('Core')` strict OR `('Core','EPH')`. `classification='Neb'` load-bearing (excludes 42 Contract). Build into `$coreRecipeIds=[recipe_id=>true]`, load next to `predict_load_dry_hop_recipe_ids` ~L224.
- **Placement:** ONE gate at Step-3 `$byRecipe` build (~L272, after `$rid=(int)$rid;`): `if(!isset($coreRecipeIds[$rid]))continue;` — cascades to all 4 passes (no per-pass edits).
- 🟡 AWAIT Kouros: strict-Core vs Core+EPH. PM lean = Core+EPH (EPH are seasonal core-line; excluding blinds seasonal restock) but it's a business call — build helper to take either list.
- 🔴 LATENT DIVERGENCE (backlog, NOT Round-4): ref_recipes AND ref_beer_types BOTH carry classification/type+subtype with no FK = two tables one fact; agree today, will drift. Watch item. → derivation-tree-and-schema.md if it surfaces.

**2. Soutirage → Transferts — DISPLAY label only; enum `racking` STAYS; PLANNER-ONLY scope (3 spots).** Rename was ALREADY half-done elsewhere (`form-racking.php` header note; `mon-tableau.php:282` already renders `'racking'=>'Transferts'` w/ the exact operator ruling comment). Change exactly: `public/modules/planning.php:78` (`'racking'=>'Soutirage'`→`'Transferts'`); `app/planning-predict.php` L510 reason `'aucun BBT libre pour soutirage'` + L518 `'Garde atteinte — soutirage CCT→BBT...'` (soutirage→transfert in the reason text). **DO NOT touch:** `racking-phase-submit.php:286 $rackType='soutirage'` (stored DATA value, not label); `form-packaging.php`/`salle-de-controle.php` "soutirage" hits (prose about the physical step / threshold setting / Saisie Soutirage form name); `sessions.php:1258`+`public/modules/sessions.php:63` (`'racking'=>'Soutirage'` — session-board, DIFFERENT surface, already inconsistent w/ mon-tableau). 🟡 FLAG to Kouros: session-board still says Soutirage = optional app-wide terminology-standardization follow-up ticket, NOT Round-4.

**3. Bottling/canning same-day mutual exclusion — producer placement, HARDCODED (physics, not a setting).** Shared line: bottling+canning CANNOT same day; kegging parallels anything; serving_tank unconstrained. `pkg_type` ENUM verified exact `('bottling','canning','kegging','serving_tank')`. Pure producer-side (engine stays pure-read). Pattern mirrors existing `pkgCountByDate`/`max_packaging_runs_per_day` cap: seed `$pkgTypeByDate[date]=['bottling'=>bool,'canning'=>bool]` in the SAME Step-9 existing-rows loop (~L401-418, which already reads pkg_type for budget) — MUST see already-PLANNED items not just proposed; guard in Pass-2 slot loop (~L648, beside the cap check) skip-day on conflict; set on successful place (~L701). No free day → reuse existing `$pkgSlot===null` deferred decisions[] branch with accurate reason ("ligne partagée occupée"). Next-working-day fallout is automatic ($eligSlots pre-sorted). ENCODE as `MUTEX_PKG_PAIRS=[['bottling','canning']]` const (one-line-edit extensibility, self-documenting) — NOT scattered ifs, NOT a system_setting (structural plant fact; a toggle could produce physically-impossible plans). Architecturally = temporal extension of the cartoner/filler capability-gating contract (plan mirrors what commissioned equipment can do on a given day). EQUIP coder+sql(+webapp-testing for the still-open manager UAT).

---

### §FULLER PRODUCTION PLANNER — round-3 PM ruling (2026-06-19, ORIGINAL design — kept for provenance; now BUILT per AS-BUILT above)
Kouros: planner must factor tank space, packaging output capacity, anticipate racks + dry-hops, compute serving tanks. Today producer ONLY proposes brewing (demand) + packaging (demand). VERIFIED against live schema. Build all in `app/planning-predict.php` + ONE additive engine change.

**Trigger class per process:**
- Brewing/Packaging = demand-driven (unchanged).
- **Racking = process-driven**: engine `racking` list non-empty for day AND **free BBT exists** (new gate) → propose regardless of sales. Engine already computes readiness (garde+cold_crash+not-in-BBT); producer just stops ignoring it.
- **Dry-hop = process-driven, GATED — REFUSE-DON'T-GUESS.** Engine `dry_hopping` list = ALL CCT (over-proposes). Canonical "is dry-hopped" signal = **`ref_recipe_ingredients.hop_addition_stage='dry_hop'` (is_active=1) — only 9 of 61 active recipes.** `ref_recipe_profile_hops.stage='dry_hop'` is EMPTY (0 — nightly compute writes only stage='kettle'; do NOT rely on it). Observed `bd_fermenting_v2 DryHop` = 288 events ~12+ recipes = retrospective per-batch, NOT a forward trigger (do NOT use as trigger — guessing). RULE: propose dry-hop ONLY for recipe-spec-flagged recipe ∩ batch has NO `bd_fermenting_v2 DryHop` event yet. NO day-window rule (no canonical window exists). Under-propose, never wrong-propose; SURFACE partial coverage (9 vs 12+ divergence) to Kouros.
- **KZE = REFUSE auto-propose.** Engine `kze` list = ALL BBT (over-proposes). NO canonical "is KZE'd" flag anywhere in schema. Leave manual-only in the dropdown (already works). Same justification as dry-hop-without-signal.
- **Serving-tank fill = process-driven, capacity-gated by free serving-tank count** (see below).

**Tank fleets (VERIFIED live, all in Salle des Machines; status ENUM active/maintenance/retired; cols number/capacity_hl/status):** `ref_cct`=18 (fermenters; producer hardcodes 1..10 — WRONG, fix), `ref_bbt`=8, `ref_yt`=3 (yeast tanks, catalog 8, NOT serving), **`ref_serving_tanks`=8 = THE serving fleet** (extra col `location enum('in_house','client')` — gate free-count on `location='in_house' AND status='active'`; client tanks live at customer, not fillable). `ref_cuv` ABSENT, `ref_brewhouse_vessels`=0. NEVER hardcode fleet size — read active fleet.

**Occupancy reuse (VERIFIED):** `TankSimulator->run()` returns ONLY `['cct'=>,'bbt'=>]`; **serving tanks EXPLICITLY NOT modelled** (literal TODO ~L648-649). CCT free = active CCT − engine `brewing.cct_conflicts` − this-run allocations (producer already does via `$allocatedCctsByDate`). BBT free = derive from engine working state. **ONE sanctioned engine change (additive): add per-day `occupancy` block (`cct_occupied`/`bbt_occupied`/`bbt_eligible_count`) to return — existing keys untouched, pure-read preserved.** Do NOT have producer re-derive tank state from raw tables (divergent reader, forbidden).

**Serving-tank capacity rule:** NO HL budget (cuv excluded from production_targets), NO débit, sim doesn't model occupancy → gate on **physical free serving-tank count** (`ref_serving_tanks` in_house+active), one fill = one tank in producer working model. v1 LIMITATION (flag to Kouros): free-count starts "all in_house active" each run (assumes empty at week start, over-estimate). Proper fix = extend TankSimulator serving-tank dest (the existing TODO) — separate larger piece; do NOT bodge a `bd_packaging_v2` occupancy reader.

**Packaging throughput rule:** KEEP weekly HL budget (`production_targets_compute` bottle/can/keg_hl.week) as v1 ceiling — it already encodes throughput (annual÷48). Do NOT build `speed_units_h` débit path (needs a NEW shift-hours setting + units↔HL conversion = new sub-system, out of scope). DO add `max_packaging_runs_per_day` system_setting (section='production_targets', mirrors max_brews_per_day) + per-day packaging cap.

**Occupancy-feedback model:** engine plan query has NO status filter → applies BOTH proposed+planned, but producer calls engine ONCE before inserts. **RULE: one eligibility pass + in-producer working-occupancy ledger; do NOT re-run engine per proposal.** Apply proposals in unlock order so feedback composes: **racking (frees CCT, occupies BBT) → packaging/serving (frees BBT) → brewing (occupies CCT) → dry-hop (neutral)**. CRITICAL refactor: today's producer treats packaging/brewing as per-recipe either/or (`if eligSlots… else brewing`) — WRONG once racking added; a recipe can warrant racking + brewing + sibling-batch packaging independently. Restructure into per-PROCESS passes over the working model, not per-recipe if/else.

**Build order:** (1) engine additive occupancy block; (2) producer fleet reads from ref_cct/ref_bbt/ref_serving_tanks active; (3) working-occupancy ledger seeded from engine block; (4) per-process passes; (5) `max_packaging_runs_per_day` setting + per-day pkg cap. Mig: 1 system_setting row, NO new table (no schema_meta needed). Re-`migrate.php --status` at build-start. EQUIP coder+sql+webapp-testing (manager-login UAT — outstanding from P0/P1). CARDINAL unchanged: INTENT-only, writes status='proposed', feeds nothing into COGS/COP/WAC/BOM/beer-tax/inventory.

---

system_settings (for reference): UNIQUE (section,key_name); value_text XOR value_num (CHECK); read via app/settings.php::system_setting(). commissioning_settings is a SEPARATE table (same shape) holding min_days_after_racking under section='packaging'.

---

## §SERVING-TANK SUGGESTIONS — ✅ SHIPPED + DEPLOYED + COMMITTED 2026-06-19 (maltytask main, NOT pushed — awaiting operator "push"). The Round-5 ruling below is now BUILT.

**AS-BUILT — commits (maltytask main, unpushed):**
- **`2e02d1d` mig 407** `ref_customers.is_serving_tank_client TINYINT(1) NOT NULL DEFAULT 0` + `serving_tank_cadence_days SMALLINT UNSIGNED NULL` (NULL=derive). Seeded 5 clients `WHERE id IN (845,2612,1827,1848,6)`. **APPLIED on VPS.**
- **`652bbae` mig 408** `pl_plan_items.customer_id_fk INT UNSIGNED NULL` FK→ref_customers ON DELETE SET NULL (serving-tank client identity; NULL for ALL other rows). **APPLIED on VPS.** 🔴 **NOT in the round-5 ruling — operator ADDED it deliberately:** without per-client identity the per-(section,recipe) dedup collapses multiple same-week same-beer (Zepp) clients into ONE row → operator can't tell which fill is whose. Durable FK, consistent with `inv_sales_ledger.customer_id_fk`. PM ratifies: correct call — a Zepp fill for Arches and a Zepp fill for Docks are TWO distinct facts; identity belongs on the row.
- **`982f298`** producer Pass 2.5 (client-recurrence) + `planning.php` render.

**OPERATOR DECISIONS locked (answers to the 5 cadence questions in the ruling):**
1. **Cadence = DERIVED ONLY** (median inter-fill interval). The `serving_tank_cadence_days` override col EXISTS but is IGNORED by the producer for now (future hook).
2. **Multi-beer = MOOT** — "on ne fait plus que ZEPV" (only Zepp cuve now). Producer derives beer from the client's MOST-RECENT cuve fill (NOT hardcoded) → yields Zepp today, auto-adapts if that changes.
3. **Horizon = due within the planned week (+ overdue):** `next_expected = last_fill + median_cadence`; propose if `<= weekEnd`.
4. **Content = client + beer + estimated volume** (median HL of last 6 fills as `target_volume_hl`).

**AS-BUILT producer (`app/planning-predict.php`):**
- New `predict_load_serving_tank_clients()` helper (reads `ref_customers WHERE is_serving_tank_client=1 AND is_active=1` — NEVER hardcodes the 5).
- **Pass 2.5 (client-recurrence)** inserted BETWEEN the packaging and brewing passes.
- FG-coverage pass now **bare-`continue`s on `pkg_type='serving_tank'`** (no longer emits serving_tank from the coverage path — recurrence pass owns it).
- Free-serving-tank count **DEMOTED to a weekly secondary cap** (per ruling — kept, not deleted).
- Per-client dedup via `existingServingTankCustomers`.
- Respects working-days + `max_packaging_runs_per_day` (cuv fill = a packaging run).
- `serving_tank` STAYS OUTSIDE `MUTEX_PKG_PAIRS` (cuv parallels freely — unchanged, correct).

**AS-BUILT render (`public/modules/planning.php`):** batch-fetches customer names and renders the client on serving-tank cards.

**VERIFIED LIVE (Opus rollback harness, then deleted):** Les Docks due 2026-06-19, cadence 23j, 10hl Zepp, `customer_id_fk` set; clients with <2 fills SKIPPED (can't derive cadence); v1 empty-at-week-start limitation surfaced in `decisions[]`.

🔴 **OPEN FOLLOW-UPS (carry forward):**
1. **Customer-fiche UI to toggle `is_serving_tank_client`** — ✅ SHIPPED 2026-06-19 (mig 411 + expeditions.php?view=clients, fiche commit `43abfce`, PUSHED; +3 cols count/size_hl/budget_hl + monthly réel-vs-budget). See §SERVING-TANK CLIENT FICHE (Round-6) AS-BUILT.
2. **Manual serving_tank add-form has NO client picker** — manual rows stay `customer_id_fk` NULL. (Separate from Round-6 fiche; still open.)
3. **`serving_tank_cadence_days` override UNUSED** (derived-only per operator) — wire if they later want a fixed cadence.
4. **v1 empty-at-week-start free-count limitation** — TankSimulator serving-dest TODO ~L648 STILL unbuilt (shared with the round-3 v1 limitation #1).
5. 🔴 **MASTER OPEN GATE — authenticated MANAGER browser UAT of the WHOLE planner (all 5 rounds):** Suggérer-un-plan output incl. serving-tank cards + the salle-de-controle objectifs settings/toggles. Structurally + rollback verified; needs a manager click-through. (This is the same master UAT gate carried since P0/P1 — now spans all 5 rounds.)

✅ **FULL PLANNER COMMIT CHAIN on main (ALL PUSHED 2026-06-19):** `521ccbf`(r1-3) → `6eb25a7`(r4) → `2e02d1d`(mig 407) → `652bbae`(mig 408) → `982f298`(producer+render); pushed `73b5634..982f298`. Round-6 fiche `43abfce` (mig 411) pushed `fa50eb5..43abfce`.

---

## §SERVING-TANK CLIENT FICHE (Round-6) — ✅ SHIPPED + DEPLOYED + COMMITTED + PUSHED 2026-06-19 (maltyweb main; mig 411 APPLIED)
**AS-BUILT (closes open-followup #1 + #2). KEY DEVIATION from the original ruling below: budget is MONTHLY (not annual) AND is NOT informational-only — Kouros wants budget-vs-réel COMPARÉ on the fiche.**
- **Mig `411_ref_customers_serving_tank_fiche.sql` (APPLIED):** `ALTER ref_customers ADD serving_tank_count TINYINT UNSIGNED NULL, ADD serving_tank_size_hl DECIMAL(6,2) NULL, ADD serving_tank_budget_hl DECIMAL(8,2) NULL`. **`serving_tank_budget_hl` = PER MONTH** (deviation from PM-lean ANNUAL — operator decided monthly). App-owned curated; 🔴 NEVER add these (or is_serving_tank_client / serving_tank_cadence_days) to the BC-sync UPDATE allowlist. **Mig number = 411, NOT 410** — parallel sessions took 409/410 (re-`--status` always proves the point: the number leads).
- **Fiche = `public/modules/expeditions.php?view=clients`** (as PM located). Added: SELECT of the 4 cols; inline-edit forms (toggle `is_serving_tank_client` + numeric count/size/budget) mirroring the `client_update` whitelist+validation+log_revision shape; **+ a computed RÉEL DU MOIS line** = current-calendar-month cuve-de-service HL from `inv_sales_ledger` (JOIN ref_skus format='Cuve de service', qty_signed<0, ÷100=HL, GROUP BY customer) shown vs the MONTHLY budget with an over-budget indicator. Render gated on `can_write_expeditions`. **Serving-tank flag kept INDEPENDENT of BC-owned `is_active`** (the caveat held).
- **Réel smoke (June 2026, live):** Arches 30.00 hl, Jardins de Louis 35.70 hl, etc. The 4 cols seeded NULL (operator fills via fiche).
- **Planner (`planning-predict.php`) NOT changed this round** — count/size/budget still not consumed by the engine (capture + display only; matches the Q2 "v1 = capture, v2 = wire" lean, except budget is now displayed-vs-réel rather than purely informational).

**Commits — all on maltyweb main, ALL PUSHED:** planner chain `521ccbf`→`6eb25a7`→`2e02d1d`→`652bbae`→`982f298` (pushed earlier `73b5634..982f298`), then fiche `43abfce` (mig 411 + expeditions.php), pushed `fa50eb5..43abfce`. **🔴 NOTE: the whole planner commit chain that the index/§AS-BUILT recorded as "UNPUSHED, awaiting operator push" is now PUSHED.**

🔴 **OPEN FOLLOW-UPS (carry on arc):**
- **V2 planner wiring of budget/size/count** — pace-check (Σ proposed+actual vs MONTHLY budget → over/under-pace in `decisions[]`); `serving_tank_size_hl` caps a single fill; `serving_tank_count` bounds same-day fills. Deferred per Round-6 ruling; now carries MONTHLY budget semantics.
- **MASTER GATE STILL OPEN — authenticated MANAGER browser UAT of:** (a) the WHOLE planner all 5 rounds incl. serving-tank cards; (b) the salle-de-controle objectifs settings/toggles; (c) the NEW serving-tank fiche block (toggle + count/size/budget + réel-vs-budget). Structurally + rollback verified only; needs a manager click-through.
- **Manual serving_tank add-form still has NO client picker** (rows stay customer-NULL) — separate, still open. (Add/remove of a customer AS a serving-tank client is now RESOLVED — the fiche toggle does it.)
- `serving_tank_cadence_days` override col still UNUSED; v1 empty-at-week-start free-count limitation still open (TankSimulator serving-dest TODO ~L648).

---

### §SERVING-TANK CLIENT FICHE (Round-6) — ORIGINAL PM ruling 2026-06-19 (kept for provenance; superseded by AS-BUILT above)
Kouros expanded the field set: per serving-tank client the fiche must set is_serving_tank_client (toggle) + **number of tanks** + **size of tanks** + **budgeted HL**. Build = mig (additive cols on ref_customers) + render+handler on the EXISTING customer fiche (no new page). NO engine/predict change in this round (the new fields are operator metadata; planner wiring is a SEPARATE later step — see Q2).

**1. MODELING — SCALAR cols on ref_customers, NOT a child table.** Evidence settles it: per-client cuve fills are SINGLE-SIZE in practice — each client's fills cluster on ONE nominal tank size (Arches/Docks≈1000L; Rincette≈500L; Jetée≈500L w/ occasional double=2 tanks; Jardins 500–1700L = multiple 500-ish fills). Variance is # of tanks filled per visit, NOT mixed tank sizes. A child table (one row/tank) is over-modeling for v1; the operator stated "size" singular. **ADD to ref_customers (additive, classified table → NO schema_meta):**
- `serving_tank_count TINYINT UNSIGNED NULL` (# tanks at client; NULL=unknown)
- `serving_tank_size_hl DECIMAL(6,2) NULL` (nominal per-tank size in HL; e.g. 10.00 for a 1000L tank)
- `serving_tank_budget_hl DECIMAL(8,2) NULL` (budgeted HL — period per Q2)
All NULL-default, app-owned. Migration = ONE `ALTER ref_customers ADD … , ADD … , ADD …`; MySQL-8 (no IF NOT EXISTS); seed NOT required (operator fills via fiche). Re-`migrate.php --status` at build-start (head ≥408; next free is whatever --status shows). **If mixed sizes ever appear → promote to `ref_customer_serving_tanks` child (id, customer_id_fk, size_hl, label) — flagged as future, do NOT build now.**

**2. BUDGETED-HL semantics + planner wiring — MUST ASK Kouros the period; default the rest.**
- The number is ambiguous on period. **ASK Kouros: is serving_tank_budget_hl ANNUAL, MONTHLY, or per-fill?** PM lean = ANNUAL contracted volume (matches how a cuve client is sold — "X HL/year"), but it's a business fact, do NOT guess.
- **v1 wiring = INFORMATIONAL ONLY on the fiche (option c).** Do NOT touch planning-predict.php this round. The producer's proposal volume stays median-of-last-6-fills (already shipped). Reason: the planner's serving-tank pass is brand-new + unUAT'd; folding budget into target_volume_hl or a per-period cap is a SECOND behaviour change that should land AFTER the master manager UAT and AFTER the period semantics are pinned. Record budget as a field now; wire later as its own ruling.
- **When wired (future):** (a) target_volume_hl per fill is BETTER left as the median (real behaviour) — budget is a yearly envelope, not a per-fill size; (b) the natural use = a per-period CAP / pace check (sum proposed+actual HL for the client vs budget; flag over/under-pace in decisions[]) — NOT a hard replacement of the derived fill volume; (c) serving_tank_size_hl SHOULD cap a single fill (a fill ≤ one tank's HL) once # tanks is known — but that too is future-wiring, not v1. Tell Kouros: v1 captures the data, v2 wires the cap + pace check.

**3. WHERE THE FICHE IS EDITED — `public/modules/expeditions.php?view=clients` (Le Cockpit). There IS an existing customer-edit UI; mirror it, do NOT build a new page.**
- **Customer-load SELECT:** `expeditions.php:2351-2363` (`SELECT c.id, c.name, c.trade_channel, c.is_private, c.default_transporter_id_fk, c.needs_review, c.is_active, c.notes, c.email, c.city, c.canton, t.name … FROM ref_customers c …`). 🔴 The new 4 cols are NOT in this SELECT — ADD `c.is_serving_tank_client, c.serving_tank_count, c.serving_tank_size_hl, c.serving_tank_budget_hl` to it.
- **Render block to MIRROR:** the per-client card holds multiple inline-edit forms each POSTing `action=client_update` with a hidden `field` — `field=trade_channel` at **expeditions.php:6951**, `field=default_transporter_id_fk` at **:6993**, `field=is_active` at **:7046**. Mirror EXACTLY this form shape for the 4 new fields (toggle for the flag like is_active; numeric inputs for count/size/budget).
- **POST handler to EXTEND:** `client_update` handler at **expeditions.php:271** — editable-field whitelist `$editableFields` at **:273-276** (ADD the 4 col names), per-field validation chain **:286-330** (ADD: flag→0/1 like is_active:310; count→TINYINT range 0..255 or NULL; size_hl/budget_hl→non-negative DECIMAL or NULL via the `?? default THEN validate` rule [[feedback_php_query_param_validate_after_default]]), UPDATE `… SET \`{$field}\` = ?` at **:333-336** (generic, works as-is once whitelisted), `log_revision(...,'ref_customers',...)` at **:338**. CSRF at **:131**, allowed-actions list at **:145** (client_update already present).
- **Role gate:** **expeditions.php:136** `can_write_expeditions($me)` → `app/auth.php:330-337` = is_admin OR role='operator' OR `manager_can('logistics')`. Serving-tank-client management is a logistics/commercial concern → this gate is CORRECT, no change. (NB this is broader than admin-only; acceptable for client metadata.)
- 🔴 Tour (RULE 3): expeditions.php is already an active page w/ a tour card; adding fields to an existing card does NOT need a new tour card. No ref_pages change.

**4. BC-SYNC CLOBBER — SAFE BY DESIGN, no protection needed beyond keeping the cols off the sync allowlist.** Verified: `scripts/python/sync_bc_customers.py` is the BC→ref_customers writer (daily drift-reconciliation). Its UPDATE allowlists are BOUNDED and explicit — `_apply_match_full` (L663-689) and `apply_drift_auto` (L729-743) write ONLY address_line1/2, city, postal_code, country_code, phone, email, is_active, bc_customer_no, bc_last_synced_at, updated_by='sync_bc_customers'. The code even carries the comment "NEVER touches curated fields (name, trade_channel, etc.)". So is_serving_tank_client + the 3 new tank cols (like trade_channel, sale_class, is_private, is_serving_tank_client already) are APP-OWNED and the sync will not clobber them. **Ruling: the new cols are curated/app-owned; the sync's bounded allowlist already protects them. The ONLY discipline = NEVER add these cols to the sync's UPDATE allowlist.** (Same governance as the existing curated fields — the sync model is allowlist-write, not full-row-overwrite, so app-owned cols are safe.) ⚠️ One caveat: `is_active` IS BC-owned (sync writes it) — do NOT conflate the serving-tank flag with is_active; they're independent.

EQUIP sql+coder+ui+webapp-testing (manager-login UAT — the master gate already outstanding for the whole planner). Mig: ONE ALTER ref_customers (3 new cols; the flag + cadence already exist from mig 407). Commit by PATHSPEC.

### §SERVING-TANK SUGGESTIONS — Round-5 PM ruling (2026-06-19, ORIGINAL design — kept for provenance; now BUILT per AS-BUILT above)
Kouros: serving-tank (cuve de service) fills must be DEMAND-driven by a fixed set of recurring cuve-de-service CLIENTS + their cadence, NOT by physical free-tank count (the v1 gate `predict_load_free_serving_tank_count` is wrong as a TRIGGER). New 3rd trigger class = **client-recurrence-driven** (distinct from demand/FG-coverage and process/tank-state).

**Customers live in `ref_customers`** (canonical; PK id INT UNSIGNED, has trade_channel on_trade/off_trade + sale_class enum, NO serving-tank flag). The 5 named clients (ALL resolved by LEDGER BILL-TO TEST, never by name — each has 2-4 INACTIVE name-duplicate stubs w/ no bc + zero sales that would mis-match):
- **845** Carte Blanche SA / Les Arches (bc 2009) — 98 Cuve lines, dominant
- **2612** Association Les Jardins de Louis (bc 3872) — new since 2026-05-28
- **1827** La Rincette Sàrl (bc 3053)
- **1848** Jetée de la compagnie (bc 3075) — multi-beer (Zepp/Embuscade/Moonshine)
- **6** Les Docks (bc 1010)

**Serving-tank sale marker = `ref_skus.format='Cuve de service'`** — 3 SKUs only: EMBV(recipe 32 Embuscade)/MOOV(recipe 44 Moonshine)/ZEPV(recipe 57 Zepp), all `hl_per_unit=0.01` → **1 ledger unit = 1 LITRE** (÷100 for HL). 🔴 `inv_sales_ledger.qty_signed` is NEGATIVE for sales, positive=returns/reversals → filter `<0` or net same-day. 🔴 format≠recurring-client: SAME 3 SKUs bought by ~14 ONE-SHOT FESTIVALS (Paléo/Blues/Fête de la Musique) → format identifies a sale, the FLAG identifies recurrence. Canonical fills query:
`SELECT l.posting_date,s.recipe_id,s.beer_raw,l.qty_signed FROM inv_sales_ledger l JOIN ref_skus s ON s.id=l.sku_id_fk AND s.format='Cuve de service' WHERE l.customer_id_fk=:cid AND l.qty_signed<0 ORDER BY l.posting_date`.

**Cadence model:** DERIVABLE = per-client median inter-fill interval (Arches~weekly@1000L 18mo, Jetée~weekly multi-beer, Docks~3-4wk, Rincette~2-3wk; Jardins too new); next_expected=last_fill+cadence; beer=most-recent (mode). ASK Kouros (5): (1) derived-rolling vs fixed-per-client cadence; (2) new-client default cadence until N≥4 fills; (3) per-client vs per-(client,beer) [Jetée edge]; (4) horizon=within-planned-week vs soonest-due; (5) propose volume/tank or just client+beer.

**Canonical client-list home = FLAG ON `ref_customers`** (NOT a parallel ref table, NOT a system_setting name-list = re-introduces name-match trap). Migration scope (small, no schema_meta — col add on classified table): `ALTER ref_customers ADD is_serving_tank_client TINYINT(1) NOT NULL DEFAULT 0, ADD serving_tank_cadence_days SMALLINT UNSIGNED NULL` (NULL=derive); seed `WHERE id IN (845,2612,1827,1848,6)` w/ log_revision; producer reads `WHERE is_serving_tank_client=1 AND is_active=1` (NEVER hardcode 5). Operator maintains via a checkbox on the customer fiche (small follow-up, separate from predict engine).

**Free-tank gate ruling: DEMOTE primary-trigger→secondary CAP, do NOT delete.** Trigger = clients due this week; cap = free serving-tank count bounds proposals/week, surplus→decisions[] "aucune cuve de service libre" (msg exists L668). Keep v1 limitation note (free-count assumes empty-at-week-start; TankSimulator serving-dest TODO ~L648 UNCHANGED — do NOT bodge a bd_packaging_v2 reader). Still respects working-days + max_packaging_runs_per_day (cuv fill = a packaging run). **serving_tank STAYS OUTSIDE MUTEX_PKG_PAIRS** (only bottling/canning) — cuv parallels freely (correct, unchanged).

Build: confine to mig (ALTER ref_customers + seed) + `app/planning-predict.php` (replace coverage-driven serving_tank branch w/ client-recurrence pass; keep free-count as cap, working-day/cap/mutex behaviour) + customer-fiche checkbox follow-up. NO engine change (planning-eligibility.php pure-read; reading inv_sales_ledger for a trigger is a READ — CARDINAL intact, still INTENT-only status='proposed', feeds nothing into COGS/COP/WAC/BOM/beer-tax). EQUIP coder+sql(+webapp-testing manager UAT, outstanding). Re-`migrate.php --status` at build-start.
