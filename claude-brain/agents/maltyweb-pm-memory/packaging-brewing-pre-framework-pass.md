# PRE-FRAMEWORK PACKAGING + BREWING PASS — racking-pattern transfer scoping (2026-05-28)

> **🅿️ PARKED 2026-05-28 — INVENTORY REMAINS USEFUL FOR PILOTS.** Operator A-confirmed Sequencing A on 2026-05-28: **infra framework FIRST → racking pilot → fermenting → packaging → brewing.** This file's SEQUENCING (a single-submit pre-pass on packaging+brewing BEFORE the framework) is WRONG-AXIS and superseded. Its pattern INVENTORY (P1-P19, the per-form transfer map, the 3 operator clarifying Qs) is STILL GOLD and remains the input for pilots 5 (packaging) + 6 (brewing) — when those pilots are reached, re-home the patterns into the 3-phase shell + plug C6b-equivalent into the start-firewall card; zero data-model rework. **Do NOT build the pre-pass as written here.** Use this file for: per-form pattern reference at pilot time, the open operator Qs (Q1 brewing cooling-capture, Q2 packaging loss_cause grain, Q3 CIP-cadence-machines model — those still need answers before pilots 5/6).
>
> Original-intent description below kept for reference:
>
> Load when building/touching `public/modules/form-packaging.php` (+ `public/js/packaging-form.js`, `public/css/packaging-form.css`) OR `public/modules/form-brewing.php` (+ `public/js/form-brewing.js`, `public/css/form-brewing.css`) under the OPERATOR-REQUESTED pre-framework pass that ports the racking-session learnings (C1–C8 + QC + CIP + Cuv-dedup) BEFORE the session framework infra lands. Holds the pattern inventory, the per-form transfer map, the sequencing, the open operator Qs. ~~Hybrid order is RESPECTED: the form-agnostic resolvers (C7/C6a/C8) already landed; this pass adds the FORM-SIDE surfaces in the current single-submit model, then the session framework pilot effectively just re-homes these into phases (zero data-model rework, same posture as racking C1-C5 going into the session pilot).~~ **REVISED 2026-05-28: framework comes FIRST, pre-pass parked; patterns feed pilots 5/6 directly.**

## OPERATOR DIRECTION (verbatim, 2026-05-28)
- "après j aimerais que l on fasse packaging et brewsheets"
- "on peut utiliser pas mal d experience de notre session racking sheet pour certains modules"
- IMPLICATION: pre-framework pass on packaging + brewing now; the session framework infra arrives AFTER, and these forms then re-home into sessions like racking will (zero data-model rework if we keep everything event-sourced + use the same conventions).

## PATTERN INVENTORY — what landed in racking C1-C8 + QC + CIP + the Cuv-dedup fix
Each entry: WHAT it is — WHERE it lives — HOW racking used it — REUSABILITY verdict.

**P1 — Event-sourced loss-input columns (mig 183: loss_source_hl / loss_dest_hl / loss_cause ENUM / loss_note / interrupted_flag / interrupted_reason / dest_bbt_still_clean).** Raw event inputs on `bd_racking_v2`. Refuse-don't-NULL: NULL = semantic absence. ENUM is closed analytical domain (operator-chosen 3 buckets), NOT a hardcoded-dropdown violation. **REUSABLE shape.** Packaging already has FAR MORE granular loss inputs (~12 per-line loss_* unit cols on bd_packaging_v2); the ADDITION packaging needs is the `loss_cause`/`loss_note` PAIR for the SESSION (one root cause per packaging event covering all per-line drops). Brewing has NO loss input today and the R5 ruling explicitly stated brewing-loss is DERIVED (no new col).

**P2 — Sim-side de-hardcode of loss constants (C3a, mig 184: commissioning_settings section='pertes' 7 keys; TankSimulator reads at __construct, COALESCE-to-defaults, byte-identical-with-defaults gate).** Lives in `app/tank-simulator.php` constructor + commissioning_settings. **COGS-CRITICAL pattern.** Reusable for any future hardcoded process-constant that feeds the sim. Not directly relevant to packaging/brewing UI but the SAME `commissioning_settings`/SDC editor pattern IS the reusable mechanism for any per-form policy constant.

**P3 — `commissioning_settings` (section, key_name, value_num/value_text, default_num) as the central config home + SDC editor panel per section.** Lives in `commissioning_settings` table + `public/modules/salle-de-controle.php` per-section panels + `update_<section>_config` handlers (role-gated, two-step validate-then-write, `log_revision`). Used by: qc_thresholds, packaging.min_days_after_racking, pertes (7 keys), cip_cadence (4 keys). **REUSABLE-AS-IS for any per-form policy.** Adoption rule: a constant the operator should ever change → commissioning_settings + SDC panel; never hardcoded.

**P4 — `app/qc-thresholds.php` resolver + per-recipe override system + JS dynamic-map preload (mig 182 + the racking integration).** Globals in commissioning_settings + per-recipe overrides on ref_recipes (co2_target/tolerance + racked_vol bands) + a derived view `v_recipe_vol_band` + `qc_global_bands()` + `qc_thresholds_for_recipes(rids)` + the form serialises a `{__global, <recipe_id>: {...}}` JSON injected as `window.QC_THRESHOLDS`, JS re-keys FormFramework thresholds on card selection. **REUSABLE-AS-IS.** Already feeds FormFramework `warn/outlier` two-band per-field. Packaging has tank_co2/tank_o2 as hardcoded thresholds today; brewing has NO QC thresholds wired today.

**P5 — `app/loss-metrics.php` resolver (C7: cast_out/nominal/packaged + 5 loss %s + warn flags vs commissioning_settings; completeness gate; negative-loss=yield-bonus).** Form-agnostic resolver, already LIVE, fed from event tables + sim. **REUSABLE-AS-IS as a READ.** Both packaging and brewing forms can ask it "for the batch I'm about to submit, what's the current loss flag?" and surface a soft on-submit alert ("this batch is on track for a total-loss palier — capture a note"). C7 already carries the wort-contract `TODO`.

**P6 — `app/cip-events.php` writer + `app/partials/cip-section.php` UI + `ref_cip_types` editable + `bd_cip_events` polymorphic child (mig 175/176/177).** Lives across `app/cip-events.php`, `cip-section.php` partial, `ref_cip_types` table, `bd_cip_events` event store. **REUSABLE-AS-IS — already live on all 3 forms (racking, brewing, packaging) via the same `$cipConfig`-driven partial.** Racking uses `machines:['centri','kze','pump']` + 1 vessel (BBT, dynamic_label); brewing uses `machines:[]` + 2 vessels (CCT + YT); packaging uses `machines:['centri','kze','pump']` + 1 vessel (tank). Module is the CIP-MODULE pattern (cip-module.md) and is the reference for "one partial driven by per-form config." Packaging's `vessel.code='tank'` is generic (vessel identity inherited from bbt/cct_source_fk on the parent row, not repeated).

**P7 — C6a CIP cadence config (mig 190: commissioning_settings section='cip_cadence' 4 keys acid_after=6 / full_after=6 / acid_reset_types='2' / full_reset_types='3,4') + SDC `#sec-cip` cadence panel + `update_cip_cadence` handler.** Config-only, no in-form surface yet (the resolver + the 3 soft-flag surfaces = C6b, deferred under sessions). **The CONFIG SHAPE is reusable** (any cadence policy = "after N events since last full reset → require a class of action"), the resolver is racking-specific today but the racking-counter-vs-time model is what needs adapting per machine class. **Packaging machines (filler/canner/bottler) have CIP cadence too**, and they are TIME-based and RUN-based (e.g. "last full CIP > 14 days OR > 50 runs"), NOT purely rack-counter. Brewing brewhouse vessels have the lighter cadence — likely time-based + per-strain/recipe-change.

**P8 — FormFramework `commentTarget` extension (C3c).** Lives in `public/js/form-framework.js`: warning objects may carry `{message, level, commentTarget}`; level='outlier' forces a comment; if any outlier carries `commentTarget='loss_note'` AND `#loss_note` exists, dialog comment routes there (no-clobber: only when empty); otherwise default `fw_comment`. **CROSS-FORM by design.** Any form that wants a domain-specific text field to absorb the forced comment instead of the generic `fw_comment` just declares the warning with the target. No FormFramework change needed for new uses.

**P9 — Soft over-volume "impossible" validation (C3b).** Loss > available volume from the sim = soft flag (audit_flags + UI warn, never reject). QA-outlier posture: import-then-flag. Lives in `racking-form.js` (reads sim volumes). **REUSABLE as a PATTERN — what's "available" is per-form** (packaging: BBT residual at packaging time = can't package more than the BBT holds; brewing: CCT slot availability + brewhouse-vessel availability).

**P10 — Auto-blend/candidate UX (C5: dropdown BBT/CCT/YT + same-beer auto-surface from sim blend_info + residual auto-fill → blend_hl snapshot; hors-process gate for empty/different-beer).** Lives in `racking-form.js` + reads `TankSimulator` blend_info. **Pattern reusable but content-specific.** Packaging has an analogue: surface the candidate BBTs by available volume + race against garde_min (this already exists as the candidate list via min_days_after_racking + the PF_CANDIDATES injection — `form-packaging.php` lines 411-731). Brewing has a weaker analogue: surface available CCT slots (already a select of all is_active=1 CCTs in form-brewing.php L535, but it does NOT consult the sim to filter to UNOCCUPIED).

**P11 — Lot-faithful sim multi-lot composition (C1: TankSimulator.blend_info pro-rata drawdown + tank_bbt_composition() read API + the invariant sum(lots)==volume_hl).** Lives in `app/tank-simulator.php`. **REUSABLE-AS-IS** as the read authority for blended-BBT composition. Packaging's blended-BBT draws should attribute per-lot via this (directional: feeds the FUTURE COGS blend-ratio third-axis attribution; the path is preserved by C1 not adding new write-stores).

**P12 — TankSimulator occupancy authority (cross-form).** Lives in `app/tank-simulator.php`. Both racking AND packaging USE it as the occupancy authority. Brewing does NOT today — it lists all `status='active'` CCTs without filtering against the sim. **REUSABLE for brewing pre-flight** ("offer only UNOCCUPIED CCTs OR ones the sim says are in the right state to receive a brewday fill").

**P13 — Same-day Cuv dedup fix (Finding 1, NON-BLOCKING).** Both `TankSimulator::loadPackagingEvents()` and `app/loss-metrics.php` dedup same-day Cuv (V) packaging draws by `(beer, batch, date)`. UNDER-COUNTS packaged volume in COGS/WIP (Zepp batch 209: 74 vs ~94 HL). **OPEN OPERATOR Q: SUM vs dedup?** Non-blocking for the session sequence; fix lands on its own data-quality track, ideally folded into whichever packaged-volume-keying build lands first.

**P14 — `bd_row_hash` discipline + `$hashCols` per-form** (idempotent re-submit/re-ingest, all event-input cols must be in the hash list). Lives across all 3 forms. **CONVENTION.** Any new event-input column added to packaging/brewing MUST be added to that form's `$hashCols` (or re-submit silently dupes).

**P15 — `bd_upsert` + `log_revision` + PRG + flash + CSRF + role-gate + post-handlers** house pattern. **CONVENTION**, common to all forms.

**P16 — Hidden-required deadlock discipline.** Conditionally-shown fields carry NO static `required`; JS drives required-while-visible only (racking-form.js L694-695, packaging-form.js mirrors). **CONVENTION**, must be honored on every conditional section.

**P17 — Recent submissions footer table (last 10 web-entered).** Lives on every form. Pattern: query by `audit_flags LIKE '%web_entry%'` ORDER BY submitted_at DESC LIMIT 10. **CONVENTION** — preserve on packaging + brewing.

**P18 — C8 KPI surface on Tank Board (Pertes par batch table, `tanks.php` L1199+).** Server-rendered from `loss_metrics_for_batches()`, 20 batches by default + "Tous" toggle, soft amber/green/blue palette mapped to flag-class, drill-in row expansion via cct-detail-modal.js event-delegation idiom. **REUSABLE as a precedent** — the same shape feeds an on-submit recap card on the packaging/brewing forms (or on a future Brewsheets dashboard).

**P19 — Operator-form draft/recovery convention** (`localStorage` keyed by `<form>-draft`, FormFramework `draftKey`). Lives across all forms. **CONVENTION** preserve on both.

## PER-FORM MAP — packaging-form

State today (`form-packaging.php` 1313 lines, `packaging-form.js` 644 lines):
- LIVE writes since `539bad3` (PACKAGING_WRITE_ENABLED=true).
- CIP module wired (P6, vessel=tank, machines=centri/kze/pump).
- Tank-candidate selection from PF_CANDIDATES (sim-derived, gated by `min_days_after_racking`) + hors-process override.
- Active-SKU mosaic (PF_RECIPE_SKUS / PF_RECIPE_UNASSIGNED — recipe-scoped, format_id NOT NULL) — recently shipped.
- FormFramework with hardcoded `tank_co2`/`tank_o2` thresholds (P4 NOT yet wired).
- Multi-format rows (main + parallels) with ~12 per-line loss_* unit cols.
- Recompile-on-save (P3 family) for ref_sku_bom packaging via `compile_sku_bom_packaging()`.
- NO Pertes section/cause-ENUM/loss_note pair, NO commentTarget integration, NO QC-thresholds dynamic re-keying per recipe, NO on-submit loss-metrics surface, NO CIP-cadence soft-flag, NO TankSimulator over-volume soft-validation, NO C8 recap card.

Pattern-by-pattern mapping:

| Pattern | Packaging transfer verdict | Adaptation |
|---|---|---|
| P1 loss inputs | ADAPTED. Packaging already has per-line unit losses; ADD session-level `(loss_cause ENUM, loss_note VARCHAR(255))` on the MAIN row of bd_packaging_v2 (one pair per packaging event, same coarse 3-bucket ENUM produit/machine/humain; loss_note picks up forced-comment via commentTarget). Migration: ALTER bd_packaging_v2 ADD 2 cols + add to $hashCols. **CONFIRM with operator: should `loss_cause` be REQUIRED when ANY of the ~12 per-line loss_* > 0?** Mirror racking's "required when a volume > 0" rule. | NEW MIG (1 ALTER), 1 form section, 1 commentTarget warning |
| P2 sim de-hardcode | N/A | — |
| P3 commissioning_settings | AS-IS. Reuse for any packaging policy (e.g. `packaging.min_days_after_racking` already lives here; CIP-cadence keys live in section='cip_cadence'). | — |
| P4 qc-thresholds | ADAPTED. Packaging measures `tank_co2`/`tank_o2` — promote thresholds from JS hardcode to `commissioning_settings` section='qc_thresholds' (already exists w/ co2/o2 globals) + per-recipe overrides via `qc_thresholds_for_recipes()`. Inject `window.QC_THRESHOLDS` JSON; re-key FormFramework on tank-card click (recipe_id discovered from the selected lot). **Confirm with operator: are pre-packaging QC limits (CO₂/O₂ in BBT before packaging) the same as the racking QC (post-rack)? Probably yes — same metric, same bands — but verify they're identical for the Zepp/Diversion/etc. recipes.** | NO new migration if the global bands are the same; per-recipe overrides via the existing `ref_recipes.co2_target/tolerance` shape (mig 182). UI + JS work. |
| P5 loss-metrics | AS-IS for READ. On submit, look up `loss_metrics_for_batches([batch])` for the just-packaged batch and surface "this batch is now flagged for total-loss palier — please capture a note in loss_note" before final submit. Same on-submit warning pattern as P9. | Pure UI/read |
| P6 CIP module | AS-IS (already wired). | — |
| P7 CIP cadence — packaging machines | ADAPTED. C6a resolver targets BBT-vessel cadence; packaging cadence is for the FILLER MACHINES (centri/kze/pump and the bottler/canner/keg-filler). Likely TIME-based (last full CIP > N days) AND/OR RUN-based (since last full CIP > N runs/HL). PROPOSE: new commissioning_settings section='cip_cadence_machines' with per-machine-class keys (`<machine>_full_after_days`, `<machine>_full_after_runs`, `<machine>_acid_after_runs`); a new resolver `app/cip-cadence-machines.php` reads `bd_cip_events WHERE target_kind='machine' AND target_code=<machine>` to compute since-last-full days+runs; soft flag in the CIP partial when the operator selects a machine. **CONFIRM with operator: cadence model = time-based, run-based, or both? Per-machine-class or one global packaging cadence?** Recommend doing the CONFIG + resolver, defer the in-form soft flag until the session-framework start firewall (mirrors the C6a/C6b split). | NEW MIG (section='cip_cadence_machines'), NEW `app/cip-cadence-machines.php` resolver, SDC editor panel — DEFER the form-side surface to the session start-firewall card |
| P8 commentTarget | AS-IS. Wire the rack-style outlier-class warning that fires when `loss_metrics` flags a total-loss palier OR when any per-line loss_* exceeds a per-line palier; set `commentTarget='loss_note'` so the forced comment routes into the new MAIN-row loss_note (P1). | JS warning generator |
| P9 soft over-volume validation | ADAPTED. "Can't package more than BBT held" = sum(per-line packaged_hl) > available BBT volume from sim. Soft flag, never block (BBT short-fill = operator-recorded reality, not data error). | JS using sim read |
| P10 auto-blend / candidate UX | ALREADY DONE in part (PF_CANDIDATES is sim-filtered by min_days_after_racking + eligibility). NO auto-blend (packaging draws from ONE BBT, not multiple — no blend choice). | — |
| P11 lot-faithful sim composition | AS-IS read (the C1 sim already supports it). Directional: when COGS blend-ratio attribution lands, packaging will use `tank_bbt_composition()` to split per-line attribution across constituent lots. NOT-NOW. Just don't break the path. | — |
| P12 sim occupancy authority | AS-IS (already used). | — |
| P13 Cuv same-day dedup | FOLD into this pass if the operator answers the SUM-vs-dedup Q in time; otherwise leave on its own track. The packaging form is the natural place to ENFORCE either model (submit-side validation: if `(beer,batch,date)` already has a Cuv row and operator is submitting another, prompt "ajouter au cumul du jour OU corriger la précédente?"). | OPTIONAL — gated on operator answer |
| P14 row_hash | ADD the 2 new MAIN-row cols (loss_cause, loss_note) to `$hashCols` in form-packaging.php. CONVENTION. | 2-line change |
| P15 bd_upsert/log_revision/PRG | AS-IS. | — |
| P16 hidden-required | CONVENTION. The new Pertes-cause section conditional reveal must NOT carry static required. | — |
| P17 recent-submissions | ALREADY THERE. | — |
| P18 C8 KPI on Tank Board | AS-IS. Possibly add a brewsheets-side mini-recap; not needed on packaging. | — |
| P19 draft/recovery | ALREADY THERE. | — |

## PER-FORM MAP — brewing-form (Brewsheets)

State today (`form-brewing.php` 723 lines, `form-brewing.js` 239 lines):
- LIVE writes via `bd_upsert` to `bd_brewing_brewday_v2` (header) + `bd_brewing_ingredients_v2` (header) + `bd_brewing_ingredients_parsed_v2` (lines).
- CIP module wired (P6, machines=[], vessels=CCT+YT).
- Recipe picker (state-gated `is_active=1`), CCT picker (all `status='active'`), Yeast picker (state-gated), MI picker (brewing categories).
- **EXPLICITLY DEFERRED** (in-form notice): gravity (`bd_brewing_gravity_v2`) and timings (`bd_brewing_timings_v2`) — operator notes those need a SEPARATE per-brew form. The Cooling `final_volume` (cast-out, the R5 brewing-loss operand) is on `bd_brewing_gravity_v2` lines (event_type='Cooling').
- NO FormFramework thresholds wired today (no `FormFramework.init` call in form-brewing.js — verified).
- NO Pertes section, NO loss-metrics surface, NO sim occupancy check, NO QC-threshold integration.

Pattern-by-pattern mapping:

| Pattern | Brewing transfer verdict | Adaptation |
|---|---|---|
| P1 loss inputs | NOT-APPLICABLE (R5 ruling: brewing-loss is DERIVED = nominal − cast_out, zero schema). Confirm operator still agrees and is NOT asking for a typed brewing-loss input. | — |
| P2 sim de-hardcode | N/A | — |
| P3 commissioning_settings | AS-IS. Any brewing policy (e.g. a global mash-out target, target lautering volume) → commissioning_settings + SDC panel. | — |
| P4 qc-thresholds | ADAPTED, BUT the metrics it gates (OG-at-cooling, FW gravity, Pfannevoll gravity, Kochwurze gravity, pH, mash temp) are CAPTURED ON THE GRAVITY/TIMINGS SUB-FORM, not on the brewday form. **THIS IS THE BLOCKER FROM THE OPERATOR Q.** Three paths: (A) ship qc-thresholds on the brewday form for what it DOES capture (currently: nothing measurable — beer/batch/CCT/yeast/ingredients are all identity, not measurements), so P4 has nothing to gate today; (B) move the cooling final_volume capture INTO the brewday form (operator already flagged it as a gap and the C8 R5 ruling needs it for the derived brewing-loss flag); (C) build a small per-brew gravity sub-form (the deferred work) and put P4 there. **Recommend (B)** = add a minimal "Cooling event" sub-section to the brewday form capturing `final_volume` + OG-at-cooling for the primary brew, writing one row to `bd_brewing_gravity_v2` with `event_type='Cooling'`. Defer FW/Pfannevoll/Kochwurze (which are per-sub-brew and need the brew-number model). This UNBLOCKS C8 brewing-stage flag (R5) + opens the door to QC-thresholds on OG. | NEW MIG = ZERO if just writing to existing bd_brewing_gravity_v2; UI + handler work to add the Cooling sub-section; qc-thresholds wiring on the OG-at-cooling input |
| P5 loss-metrics | AS-IS for READ once (B) lands. On submit of the brewday + cooling capture, look up `loss_metrics_for_batches([batch])` and surface the brewing-stage palier flag (nominal vs cast_out) — exactly C8 R5 on the brewing-form, the surface the C8 build deliberately left dormant pending the cooling-capture path. | Pure UI/read, gated on path (B) |
| P6 CIP module | AS-IS (already wired). | — |
| P7 CIP cadence — brewing equipment | ADAPTED. Brewhouse vessels (mash tun / boil kettle / whirlpool) have a CIP cadence — typically time-based ("last full CIP > N days since last brewday") and/or strain-change-based ("brewing different strain than last time → full CIP required"). PROPOSE: same shape as packaging-machines (section='cip_cadence_brewhouse'). DEFER form-side surface to session start-firewall. Confirm with operator: is the brewhouse cadence the operator's concern, or only between-brews on the same CCT (which is a yeast-family/strain-change question, already mid-flight in saisie-transferts-and-yeast-family)? | DEFER pending operator confirmation that brewhouse-cadence is in scope |
| P8 commentTarget | NOT-APPLICABLE unless P1 lands. If a typed brewing-loss input is later added (deferred per R5), then yes. | — |
| P9 soft over-volume validation | ADAPTED to BREWING: "can't brew into an occupied CCT" — read sim occupancy for the selected CCT and soft-warn if occupied (per P12). Also: "cast-out volume > CCT capacity" if path (B) lands. Both soft, never block (over-fill is a real event the operator may legitimately record). | JS using sim read |
| P10 auto-blend / candidate UX | NOT-APPLICABLE (no blend). Analogous: surface CCTs filtered by sim-availability (see P12). Already partially done via state-gating `status='active'`, but does NOT filter by sim-occupancy today. | See P12 |
| P11 lot-faithful sim composition | NOT-APPLICABLE (brewing is the FILL event, no draw). | — |
| P12 sim occupancy authority | ADAPTED. Brewing CCT picker should consult sim → flag "CCT n already holds beer X batch Y" with a soft warn (don't block — operator may legitimately fill a vessel mid-cold-crash or want to record an exception). | JS + new sim-read call |
| P13 Cuv same-day dedup | N/A | — |
| P14 row_hash | If path (B) adds cols to bd_brewing_brewday_v2 (it doesn't — Cooling writes to bd_brewing_gravity_v2 which has its own hash discipline), no change. If we instead store the cooling target on the brewday row (the §1bis wort-contract CORRECTED ruling about `cooling_target_c`), that col joins $hashCols — but the wort-contract work is BACKLOG, gated. | Conditional |
| P15 bd_upsert/log_revision | AS-IS. | — |
| P16 hidden-required | CONVENTION on any conditional section. | — |
| P17 recent-submissions | ALREADY THERE. | — |
| P18 C8 KPI surface | AS-IS on tanks.php. Also add a per-submission inline "vs nominal" mini-recap on the brewday confirmation/recap. | — |
| P19 draft/recovery | ALREADY THERE. | — |

## OPERATOR-SIGNIFICANT PATTERNS — explicit answers

**(a) Pertes section + cause ENUM + loss_note + soft palier (P1+P8+P9):**
- **Packaging gets DURING-session per-line loss capture (already exists) + ADD a session-level (loss_cause ENUM, loss_note) MAIN-row pair.** During-session because the operator records loss-per-line AS THE LINES happen on the floor; the cause/note pair is the SESSION-LEVEL root-cause that ties them together (operator's earlier ruling: one operational incident per packaging event). Form-side mechanic: a Pertes section toggle (same UI pattern as racking) revealed when any per-line loss_* > 0; cause is required-while-visible-AND-any-loss-volume; loss_note is the commentTarget for the loss-palier warning fired by P5. Soft palier comes from `commissioning_settings.pertes_packaging_warn_pct` (already seeded in mig 184). NB Finding 1 (Cuv dedup) affects the packaged-volume denominator — surface that caveat in the on-submit warning until the dedup Q is resolved.
- **Brewsheets: brewing-loss is DERIVED, NOT a captured input** (R5 unchanged). The on-submit flag is `(nominal − cast_out) / nominal` vs `pertes_brewing_warn_pct`. THIS REQUIRES the cooling final_volume to be captured on the brewday form (today it's not) — see (c). NO new loss input columns on brewing.

**(b) FormFramework commentTarget (P8) — cross-form wiring:**
- **Packaging:** declare an outlier-class warning when `loss_metrics_for_batches` flags a total-loss palier OR when per-line loss_* exceeds the packaging-stage palier. `commentTarget='loss_note'` → forces the dialog comment into the new MAIN-row loss_note. NO FormFramework change needed (the mechanism is already cross-form). One JS warning generator function.
- **Brewsheets:** the brewing-stage flag fires when nominal-vs-cast_out exceeds `pertes_brewing_warn_pct`. **But there's nowhere domain-specific for the comment to route** (no brewing loss_note column today). Two options: (i) inject into the generic `fw_comment` (default behaviour, no commentTarget) — this is the simplest and respects "do NOT add a column without intent"; (ii) add a `brewing_loss_note VARCHAR(255) NULL` to bd_brewing_brewday_v2 + commentTarget — this is a real new input column for what is otherwise a derived flag, which contradicts R5. **Recommend (i)** unless the operator wants persistent brewing-loss commentary.

**(c) QC threshold system per-recipe (P4):**
- **Packaging:** `tank_co2` + `tank_o2` are captured today with HARDCODED warn/outlier bands in `packaging-form.js` L624-633. Rewire to `qc_global_bands(PDO)` + `qc_thresholds_for_recipes()` + the `window.QC_THRESHOLDS` injection pattern. Metrics are the SAME family as racking (co2/o2 of the destination/source tank). Per-recipe overrides for CO₂ already exist on `ref_recipes.co2_target/co2_tolerance` (mig 182). **Confirm with operator: identical bands for pre-packaging as for post-racking?** Likely yes (same beer, same vessel population) but operator must confirm. If yes: zero migration, just wiring. **Re-keying happens on tank-card click** (recipe_id is on the candidate metadata via the lot lookup).
- **Brewsheets:** the brewday form captures NO measurements today (only identity + ingredients + yeast). The metrics that NEED thresholds are OG-at-cooling (currently NOT captured on this form), FW/Pfannevoll/Kochwurze gravity (separate per-brew sub-form, deferred), pH (not captured), mash temp (not captured). **BLOCKER: P4 has nothing to gate on the current brewday form. Path (B) — add a Cooling sub-section capturing final_volume + OG-at-cooling, write to bd_brewing_gravity_v2 — is the prerequisite for P4 to attach to brewing.** Once (B) lands: OG (in °Plato) gets per-recipe thresholds from `ref_recipes` (currently only co2 fields; would need an OG target/tolerance pair — modest mig). Final_volume vs nominal gets the C8 brewing-stage flag (uses commissioning_settings, already seeded).

**(d) CIP cadence (P7):**
- **Packaging:** filler/canner/bottler/centri/kze/pump cadence. The racking BBT-rack-counter model is NOT the right shape for machines — packaging machines are TIME-based ("last full CIP > N days") and RUN-based ("since last full CIP > N runs OR N HL"). PROPOSE: separate `commissioning_settings` section='cip_cadence_machines' with per-machine-class keys; new `app/cip-cadence-machines.php` resolver reading `bd_cip_events WHERE target_kind='machine' AND target_code=<machine>`. SDC editor as a third panel in `#sec-cip` (alongside cip-types and cip-cadence-BBT). DEFER form-side surface to the session start-firewall card (mirrors C6a/C6b split exactly). Operator question: cadence model = time / runs / both? Per-machine or one cadence per machine class?
- **Brewsheets:** brewhouse vessels (mash tun / boil kettle / whirlpool). Likely time-based ("> N days since last full CIP") + strain-change-triggered. PROPOSE: section='cip_cadence_brewhouse'. DEFER pending operator confirmation that brewhouse cadence is in scope at all (it may not be — they may CIP every brewday anyway).

**(e) Auto-blend / candidate UX (P10):**
- **Packaging:** has the analogue today (PF_CANDIDATES surface BBTs by `min_days_after_racking`); not a multi-BBT blend (packaging draws from ONE BBT). Could ENRICH by sorting/badging candidates by available volume from the sim — minor.
- **Brewsheets:** has NO sim-driven candidate UX today; CCT picker lists all `status='active'` without occupancy filter. ADD: badge CCTs as "libre" / "occupée par X batch Y" from sim → soft warning on selection, never block (P12).

**(f) TankSimulator over-volume soft-validation (P9):**
- **Packaging:** "can't package > BBT-residual" via sum(per-line packaged_hl) vs sim. Soft warn. Direct port. The Finding-1 caveat (Cuv dedup) affects how the sim computes the residual — fine to ship the soft warn before resolving Finding 1; the warn-or-not direction is the same either way.
- **Brewsheets:** "can't brew into an occupied CCT" + "cast_out > CCT capacity" (the second gated on path (B)). Soft warn.

**(g) C8-style on-submit loss-metrics surface (P5+P18):**
- **Packaging:** YES — on submit, query `loss_metrics_for_batches([batch_being_packaged])` and surface "this batch is on track for a soft total-loss palier — capture a note" (commentTarget=loss_note). Same UX shape as a FormFramework outlier warning.
- **Brewsheets:** YES, gated on path (B) — on submit of the Cooling sub-section, surface the brewing-stage palier flag (nominal vs cast_out) with a soft warn that routes to fw_comment (option (i) in (b) above). Without (B), the brewing form has no operand to feed the metric — surface NOTHING.

## SEQUENCING — proposed pre-framework pass

**Premise:** stay in single-submit model; everything we build here is form-side and is designed to RE-HOME into the session framework with zero rework (mirrors racking's C1-C5 trajectory into the session pilot). Net throwaway = 0.

**P1 — Packaging slice (parallelizable groups inside):**
- P1.A — `loss_cause` ENUM + `loss_note` on bd_packaging_v2 MAIN row (mig + form section + commentTarget + $hashCols). 1 mig, ~150 LoC PHP+JS+CSS.
- P1.B — qc-thresholds rewire (P4): replace hardcoded warn/outlier in packaging-form.js with `window.QC_THRESHOLDS` injection + re-key on tank-card click. ZERO mig (assumes same bands as racking; operator confirms).
- P1.C — Soft over-volume soft-warn (P9) using sim residual vs sum(per-line packaged_hl). JS only.
- P1.D — On-submit loss-metrics surface (P5+P18): call `loss_metrics_for_batches` for the batch + surface outlier warning if total-loss-flagged. PHP + JS only.
- P1.E — CIP cadence machines (P7) CONFIG only: new commissioning_settings section + SDC editor + `app/cip-cadence-machines.php` resolver. DEFER in-form surface to session framework. 1 mig.
- **Sequence:** P1.A first (it gates the commentTarget for P1.D). Then P1.B || P1.C || P1.D in any order. P1.E parallel (independent).
- **Operator clarifying Q for P1:** identical CO₂/O₂ bands pre-packaging as post-racking? required-loss_cause-when-any-loss rule? Cuv-dedup answer (would change P1.C+P1.D behaviour)?

**P2 — Brewing slice (HEAVILY GATED on the cooling-capture question):**
- P2.A — Add a Cooling sub-section to the brewday form capturing `final_volume` + OG-at-cooling, writing one row to `bd_brewing_gravity_v2` with `event_type='Cooling'`. ZERO new mig (table already exists). Net new: ~100 LoC UI + handler + bd_row_hash on the gravity row. **This is the brewing prerequisite for everything else.** Without it: no QC thresholds to gate, no brewing-stage loss flag, no on-submit loss-metrics surface — the brewing pass is a CIP/identity/ingredient form only.
- P2.B — qc-thresholds wiring on OG-at-cooling (needs an OG target/tolerance pair on ref_recipes — small mig — OR start with the global brewhouse bands from commissioning_settings).
- P2.C — sim occupancy check on the CCT picker (soft warn). JS only.
- P2.D — On-submit brewing-stage palier flag (P5+R5 + C8 surface): nominal vs cast_out, vs `pertes_brewing_warn_pct`. Routes the forced comment to `fw_comment` (no domain-specific loss_note unless operator wants persistent commentary).
- P2.E — CIP cadence brewhouse CONFIG (P7), DEFERRED pending operator confirmation that brewhouse cadence is in scope.
- **Sequence:** P2.A is the gate. Then P2.B || P2.C || P2.D after. P2.E only if operator confirms scope.
- **Operator clarifying Q for P2:** is the cooling final_volume capture in scope on the brewday form (recommended), or strictly on the deferred per-sub-brew gravity form? If brewday: do we also want FW/Pfannevoll/Kochwurze (the answer is probably no — those are per-sub-brew); brewhouse cadence in scope; persistent brewing-loss commentary (= new col on bd_brewing_brewday_v2)?

**Cross-cut, NOT-NOW:** Finding 1 Cuv dedup fix; CIP cadence in-form soft-flag surfaces (C6b — start-firewall under sessions); auto-blend-style enrichment of the packaging candidate UX; wort-contract `process_type` (BACKLOG, gated on real recipe-creation).

**Parallelization:** P1 and P2 are INDEPENDENT (different forms, different tables, different migs). Parallelize between agents if bandwidth allows. P1.A and P2.A both add the first new form section; both must land cleanly.

## DOES THIS RE-ORDER THE POST-FRAMEWORK SESSION PILOT?
- **No fundamental re-ordering**, but it CHANGES THE SHAPE of pilot-5 (packaging) and pilot-6 (brewing) in the post-framework sequence. After this pre-framework pass, packaging and brewing will already have:
  - QC thresholds + recipe-aware per-card re-keying
  - Pertes / cause / loss_note (packaging only)
  - On-submit loss-metrics surface
  - CommentTarget integration
  - Soft over-volume/occupancy validation
  - CIP cadence config (form-side surface still pending for sessions)
  - Cooling final_volume captured (brewing only, if path B taken)
- So when the session framework infra arrives, pilots 5+6 reduce to: re-home the form into the 3-phase shell (start firewall, in-progress capture, end recap) + plug the CIP-cadence soft-flag into the start-firewall card (C6b-equivalent for each machine class) + wire the live session dashboard. The DATA-MODEL work is done; the CHROME is what's left. **Net effect:** packaging-pilot and brewing-pilot become MUCH cheaper after this pre-pass (mostly chrome + the start-firewall integration), and the pre-pass also de-risks the framework itself by proving the patterns at the form-side BEFORE the session shell wraps them.

## RISKS / OPERATOR-UNCERTAINTY FLAGS (what the operator likely DOESN'T realise)

1. **The brewing form has NO measurement input today.** It captures identity (beer/batch/CCT/yeast) + ingredients + CIP. The R5 brewing-loss flag and ANY brewing QC threshold REQUIRE cooling final_volume on a form (today it's on a deferred per-sub-brew gravity sub-form). Path (B) — add a Cooling sub-section to the brewday form — is the prerequisite for the racking-pattern transfer to MATTER on brewing. Without it, the brewing pre-pass is essentially "wire CIP cadence config + add a sim occupancy check on the CCT picker," everything else is dormant. **The operator likely sees "brewsheets" as analogous to racking — but racking captures volumes natively (racked_vol_hl + blend_hl + loss_*) while the brewday form captures NO volumes today.** This is the single biggest scoping question.

2. **Packaging-form QC is multi-line per packaging event (per-format losses), but the racking pertes posture is one-cause-per-session.** Reconciling: the per-line losses STAY (they're the granular truth), and we ADD ONE session-level (cause, note) pair on the MAIN row (matching racking's coarse 3-bucket coverage). The operator may want the cause to be per-line ("the 4-pack carton failure was machine, the labelling failure was human") — that's a different model. PM recommendation: one-pair-per-session matches the racking ruling + keeps the loss-metrics resolver shape; per-line cause is over-modelling for the COGS KPI. Confirm with operator.

3. **Brewing has a multi-stage brewday model (FirstWort / Pfannevoll / Kochwurze / Cooling) with stage-specific QC** (FW gravity, Pfannevoll gravity, Kochwurze gravity, Cooling gravity, all per-SUB-BREW). The brewday form today writes ONE row per brewday-DAY; the per-sub-brew model needs a different shape (a "brew" identifier within a brewday, currently noted in the in-form deferred notice). The pre-pass cannot port racking's single-event simplicity to the per-sub-brew level — that's a structural delta. If the operator wants per-sub-brew QC now, that's a much bigger build (designing the brew-number model first); if path B is just "ONE Cooling event per brewday for the primary brew," it's tractable.

4. **CIP cadence model differs by equipment class.** Racking's BBT-rack-counter model assumes "each rack into the BBT increments a counter." Packaging machines need TIME + RUN counters (different shape). Brewhouse vessels are likely time-based or strain-change-based. The operator's "use the racking experience" is RIGHT for the config+resolver pattern (commissioning_settings + SDC editor + a resolver reading bd_cip_events) but the COUNTER FORMULA is per-machine-class. We're not re-using the racking-counter SQL; we're re-using the pattern.

5. **The current packaging form's hardcoded QC thresholds are likely WRONG for non-Zepp recipes** (warn 3.5-5.0 g/L CO₂ is a Zepp-centric range; saisons/dry-hopped lagers/sours need different bands). Rewiring P4 surfaces this — if the operator hasn't been aware that packaging QC has been gating on a Zepp-tuned band for all recipes, this is a real (currently-invisible) data-quality wins.

6. **Pre-pass writes go LIVE on packaging immediately** (`PACKAGING_WRITE_ENABLED=true` since `539bad3`). Operator should know that every change to the packaging form is on the live write path; we already have the audit trail (log_revision + audit_flags) but we don't get a draft mode safety net.

## TOP 3 OPERATOR CLARIFYING QUESTIONS (prioritised)

**Q1 (BLOCKING for the brewing pass): The brewing form today captures NO measurements — only identity + ingredients + CIP. To get any racking-pattern value (R5 brewing-loss flag, on-submit loss-metrics surface, per-recipe QC thresholds), we need at minimum the Cooling final_volume on this form. Two options: (A) add ONLY a Cooling sub-section (final_volume + OG-at-cooling, one row to bd_brewing_gravity_v2 with event_type='Cooling') — UNBLOCKS C8 brewing-stage flag + opens QC thresholds; (B) defer all measurement capture to a future per-sub-brew gravity sub-form and ship the brewing pre-pass as essentially "CIP + sim CCT-occupancy check + identity QoL" only. Which way?**

**Q2 (gates the packaging Pertes design): On packaging, do you want ONE session-level (loss_cause, loss_note) pair on the MAIN row covering all per-line loss_* entries (mirrors racking — coarse 3-bucket Produit/Machine/Humain) — OR per-line cause attribution (every per-line loss_* gets its own cause)? Recommend session-level (simpler, matches the COGS KPI shape, mirrors racking). And: should loss_cause be REQUIRED when ANY per-line loss_* > 0?**

**Q3 (gates the CIP-cadence-machines config): Packaging machine CIP cadence — time-based ("last full CIP > N days"), run-based ("> N runs since last full CIP"), or both? Per-individual-machine (centri vs kze vs pump vs filler) or one cadence per machine class? Brewhouse cadence in scope at all, or do you CIP the brewhouse every brewday anyway?**

(Bonus, ungated answer needed eventually — NOT in the top 3 because not blocking the pre-pass: Q4 from Finding 1: same-day multi-run Cuv packaging = SUM or dedup? Q5: identical pre-packaging CO₂/O₂ bands as post-racking?)
