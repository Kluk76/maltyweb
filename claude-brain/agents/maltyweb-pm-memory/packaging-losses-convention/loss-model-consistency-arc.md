# LOSS-MODEL CONSISTENCY ARC — make liquid-loss decomposition correct + uniform across ALL run_type branches

> COGS / beer-tax / WIP-impacting ACROSS HISTORY. **✅ SHIPPED + COMMITTED 2026-06-09 — maltyweb `7a8b63a`, mig 293. See §SHIPPED RECORD at the bottom for the as-built (the PM-ruling text below is the pre-build plan, retained for provenance; the as-built REFINED two points: taproom KEPT in keg/cuv vendable, and the backfill scope-control gate that caught a 57-row contract over-reach).** PM ruling recorded 2026-06-09 (Kouros driving "let's make sure losses are computed properly all across"). Cross-links: packaging-losses-convention.md (the disposition convention), packaging-losses-convention/burst-bag-liquid-loss-arc.md (the keg/cuv floor — SUPERSEDED by this arc, mig 292 dead), volume-dimension.md, the -V hl_per_unit cutover gate (index #0 / #0a5).
>
> **This arc REOPENS + CORRECTS the burst-bag arc's "don't floor bottle/can — negative vendable is ALWAYS a data error" ruling. Row 1303 (a CONFIRMED catastrophic-loss input, not a typo) disproves the premise for cans. The fix is NOT to floor the can branch; it is to STOP SUBTRACTING liquid loss from vendable in BOTH branches.**

## THE SEMANTIC RULING (the decision Kouros asked for)

### What `prod_total_units` means — verified from the form, identical in both branches
The form field is **"Unités produites (total main)"** (packaging-form.js L519-520) = a COUNT of units that physically went INTO containers. The bottle/can disposition fields (`unsaleable`/`uncapped`/`half_filled`/`untaxed_full`/QA) are documented in-form as **"subtract from vendable"** (BOTTLE_DISPOSITION_FIELDS, L293) — i.e. they are a SUBSET of `prod_total_units` carved out to non-sellable dispositions. Therefore:

- **`prod_total_units` = GROSS FILLED UNITS** (good + the unit-disposition categories). It is NOT net-good. The unit-disposition subtractions (`vendable_units = prod − unsaleable − uncapped − ½·half − untaxed_full − qa_*`) are CORRECT and stay — they reallocate gross filled units into their dispositions.
- **`loss_liquid_other_units` (DECIMAL litres) is a DIFFERENT DIMENSION.** It is beer that NEVER BECAME A COUNTED FILLED UNIT — foam-out, filler residual, line purge, a burst bag, a catastrophic spill. It is liquid lost BEFORE/OUTSIDE the unit count. It is therefore **NOT part of `prod_total_units`** (a unit count of filled containers cannot contain litres that never filled a container).

### The error, stated cleanly (consistent across both branches)
Both branches currently do `vendable_hl = hlGross − loss_liquid_other/100` (and keg/cuv also subtract `loss_keg_liquid_l/100`, `taproom/100`). **Subtracting these litre losses from vendable is WRONG in BOTH branches** — `hlGross` is already the volume of GOOD FILLED UNITS (gross units minus unit-dispositions, × hl_per_unit). The litre loss is a SEPARATE pool that never entered the unit count. Subtracting it double-deducts: the good filled units are good regardless of separate spillage. When the spill is large (1303: 4324.5 L vs ~13.6 HL filled; ZEPV burst: prod=0), vendable goes NEGATIVE — a real, sellable production showing as negative output, with the real loss INVISIBLE in loss_kpi.

**Confirmed: `prod_total_units` is GROSS-of-unit-dispositions, NOT gross-of-liquid-loss. So liquid loss must NOT subtract from vendable. Kouros's hypothesis is CORRECT.**

## THE CANONICAL, CONSISTENT MODEL (confirms Kouros's hypothesis)
For EVERY run_type:
```
vendable_hl       = sellable beer actually produced = good filled units × volume
                  = (prod_total − unit_dispositions) / units_per_pack × hl_per_unit   [bottle/can]
                  = prod_total / units_per_pack × hl_per_unit                          [keg/cuv]
                  -- NO liquid-loss subtraction in EITHER branch
beer_tax_base_hl  = vendable + taxed-but-unsold:  + unsaleable (bottle/can)  + taproom (keg/cuv)
                  -- liquid losses NEVER in the tax base (pre-sale loss is untaxed)
loss_kpi_hl       = ALL beer lost
                  = [bottle/can] unit-disposition losses (unsaleable + uncapped + ½·half + untaxed_full)
                                 + loss_liquid_other_units/100                          ← NEWLY ADDED to can branch
                  = [keg/cuv]   loss_keg_liquid_l/100 + loss_liquid_other_units/100      ← loss_other NEWLY ADDED
                  -- QA library/analyses + taproom EXCLUDED (operator: not a loss)
```
**Conservation:** beer drawn from tank = vendable + loss_kpi (+ unsaleable, which is taxed-but-lost = in loss_kpi already; + taproom, segregated). Tank-sim drains `vendable + loss_kpi`. With losses no longer fighting vendable, the sim drains the right amount and nothing goes negative.

### Per-branch corrected formula (vs what shipped)
- **bottle/can:** REMOVE `− loss_liquid_other/100` from `vendableHl` (L272-273). ADD `loss_liquid_other_units/100` to `loss_kpi_hl` (L279-283). beer_tax_base unchanged (was vendable + unsaleable; vendable rises so tax rises correctly — the lost litres were wrongly depressing it). **No floor needed** — vendable can no longer go negative because nothing large is subtracted from it. (Floor is harmless as a belt-and-braces guard but the ROOT FIX makes it moot.)
- **keg/cuv:** REMOVE all three subtractions (`− loss_keg_liquid_l/100 − taproom/100 − loss_liquid_other/100`) from `vendableHl` (L294-296). **DELETE the mig-292 floor** — it becomes dead code once nothing is subtracted (prod=0 burst → vendable=0 naturally, no floor required). ADD `loss_liquid_other_units/100` to `loss_kpi_hl` (currently only `loss_keg_liquid_l/100`, L304). beer_tax_base = vendable + taproom/100 (unchanged form, but taproom is no longer subtracted-then-readded — it's just added once; net identical for taproom but vendable no longer wrongly reduced by it). **NOTE: taproom is NOT a loss and NOT general FG — it stays in tax base + segregated. Re-examine whether taproom should be IN vendable at all (it's taxed sellable-at-taproom). Per convention it is segregated OUT of general FG → keep OUT of vendable, IN tax base. So tax_base = vendable + taproom; vendable excludes taproom. CONFIRM with operator the taproom term placement when building.**

### ONE coherent model, not two hacks — answers Kouros's Q2
Yes, the mig-292 keg/cuv FLOOR should be REVISITED and REPLACED. The floor was a branch-specific patch on the SYMPTOM (negative vendable) that treated the litre loss as a legitimate vendable-reducer that just shouldn't go below 0. The ROOT model says the litre loss was never a vendable-reducer at all. **The consistent fix: stop subtracting liquid loss from vendable in BOTH branches; route ALL liquid loss to loss_kpi in BOTH branches; drop the floor.** This unifies bottle/can and keg/cuv under ONE rule and makes the floor unnecessary (vendable is structurally non-negative once nothing large is subtracted).

## BLAST RADIUS (live-verified 2026-06-09, is_tombstoned=0 + reuses IS NULL)

### loss_liquid_other_units footprint by run_type
| run_type | n rows | Σ litres | current Σvendable_hl | current Σtax_hl | current Σloss_kpi | neg_vend |
|---|---|---|---|---|---|---|
| bot   | 495 | 42 327.195 | 10 495.07 | 10 527.85 | 32.84 | 0 |
| can   | 76  | 11 778.000 | 1 981.71 | 1 985.43 | 3.72 | 1 (=1303) |
| can33 | 5   | 209.150 | 45.97 | 45.98 | 0.02 | 0 |
| keg   | 197 | 625.650 | 8 752.44 | 8 752.44 | 0.00 | 0 |
| cuv   | 8   | 915.000 | 58.95 | 58.95 | 0.00 | 0 |

This is the FULL set of rows whose vendable/tax/loss_kpi will CHANGE. ~781 rows total carry loss_liquid_other_units.

### Direction of every delta (per the corrected model)
For EVERY row with loss_liquid_other_units > 0:
- **vendable_hl RISES by loss_liquid_other/100** (the litres stop being subtracted). Σ ≈ **+550 HL across all history** (= 42327+11778+209+626+915 = 55 855 L / 100). This is packaged-HL going UP.
- **beer_tax_base_hl RISES by the same loss_liquid_other/100** (tax = vendable + taxed-extras; vendable rose). Σ ≈ **+550 HL across history**. 🔴 **BEER-TAX-IMPACTING ACROSS EVERY YEAR.**
- **loss_kpi_hl RISES by loss_liquid_other/100** (the litres are now COUNTED as loss). Today the keg/cuv loss_kpi for these is 0.00 and the can loss_kpi omits the litres entirely → loss has been UNDERSTATED. Σ loss_kpi ≈ **+550 HL newly visible** (matches the packaging-losses-convention §PERTES-DASHBOARD inline term `loss_liquid_other_units/100`, which already adds it for the dashboard — the dashboard was RIGHT, the stored cols/view were the ones missing it).
- **NET conservation:** vendable + loss_kpi was previously `(hlGross − litres) + ~0`; becomes `hlGross + litres/100... ` — no: vendable becomes hlGross (no subtraction), loss_kpi becomes litres/100. So beer-drawn = hlGross + litres/100 grows by the litres that were previously LOST FROM THE BOOKS entirely (subtracted from vendable AND not in loss_kpi). This is the correct restatement: those litres WERE drawn from the tank and lost; the old model erased them.

### Per-period deltas (bottle/can litres carved, the dominant set — full table in the audit)
Material per-year HL added to BOTH vendable AND beer-tax base (bottle/can only; keg/cuv add ~15 HL total more):
- 2023 ≈ +132 HL ; 2024 ≈ +205 HL ; 2025 ≈ +175 HL ; 2026-partial ≈ +98 HL (bottle/can). Plus keg ≈ +6.3 HL, cuv ≈ +9.2 HL spread across years.
- **🔴 OPEN-MONTH IMPACT:** 2026-04 (bot 12.376 + can 1.130 + keg ~0.05 HL) and **2026-05 (bot 14.849 + keg/cuv ~1.5 HL)** are OPEN closure months — vendable + beer-tax base + loss_kpi all shift LIVE. These compound on top of the +15 HL (2026-04) / +5 HL (2026-05) the burst-bag fix already moved. **Kouros must re-verify open-month beer-tax + COGS after apply.**

### Row 1303 — RESOLVED by this model (no separate restatement needed)
1303: can, prod=4116 (gross filled), unsaleable=138, qa 4+7, loss_liquid_other=4324.5 L. Kouros CONFIRMS the 4324.5 L is a CORRECT catastrophic-loss input.
- Under the corrected model: vendable = (4116 − 138 − 4 − 7)/units_per_pack × hl_per_unit ≈ **+19.74 HL** (the ~3967 good cans, POSITIVE — was −23.41). beer_tax_base = vendable + unsaleable ≈ 20.43 HL. **loss_kpi = unit-disp loss (0.69) + 4324.5/100 = 43.245 + 0.69 ≈ 43.93 HL** (the real catastrophic loss, NOW VISIBLE — was 0.69).
- This is EXACTLY Kouros's described target: ~19.8 HL sellable shows positive, ~43 HL loss shows AS loss. **1303 is no longer an open data-quality ticket — the model fix resolves it correctly. REMOVE it from the burst-bag arc's STILL-OPEN.** (Supersedes the burst-bag arc's "await Kouros true-loss restatement" — the input WAS the true loss; only the formula was wrong.)

## VIEW + STORED (Q4 — must change together)
Both `compute_packaging_vendable_hl()` (form-packaging.php ~L204-315, stored-on-submit) AND `v_bd_packaging_v2_vendable` (hand-maintained, A-LT2 codegen PAUSED) carry the same formula and MUST change in lockstep (stored==view is the invariant). Three edits each:
1. bottle/can vendable arm: drop `− loss_liquid_other/100`.
2. keg/cuv vendable arm: drop `− loss_keg_liquid_l/100 − taproom/100 − loss_liquid_other/100`; drop the mig-292 `GREATEST(0,…)` floor.
3. BOTH loss_kpi arms: add `+ loss_liquid_other_units/100`.
Then BACKFILL: re-recompute all ~781 affected rows' 3 stored HL cols through the FIXED compute fn (txn + log_revision + snapshot + idempotent), re-establishing stored==view.

**🔴 VERIFICATION-INDEPENDENCE from the 100× cuv view bug:** the read-path view ALSO recomputes all 245 cuv PRODUCTION rows at 100× because EMBV/MOOV/ZEPV carry un-reverted `ref_skus.hl_per_unit=1.0` (burst-bag arc CRITICAL FINDING). This is a SEPARATE bug (config, not loss-model) and it CONTAMINATES any verification done against the VIEW for cuv. **DISCIPLINE: verify this loss-model change against the STORED columns + against hand-computed per-row numbers, NOT against the live view for cuv rows** until the -V hl_per_unit revert lands. Bottle/can/keg view rows are clean (hl_per_unit correct) and can be view-verified. → SEQUENCING below gates the cuv portion.

## DOWNSTREAM CONSUMERS (Q5)
Verified consumers of vendable_hl / beer_tax_base_hl / loss_kpi_hl (maltyweb + maltytask):
- **packaging-stats.php** — `SUM(vendable_hl)` headline packaged-HL RISES by ~550 HL across history (per-month per the table). The inline beer-loss-% (L665) ALREADY adds `loss_liquid_other_units/100` to the loss numerator — AFTER this fix the litres are in `loss_kpi_hl`, so packaging-stats must STOP double-adding the legacy term (else loss counted twice). Re-point: loss numerator = `loss_kpi_hl` alone (now complete); denominator = the bigger, correct vendable. Net loss-% DROPS slightly (correct larger denominator), loss-HL unchanged.
- **kpi-handlers.php** — `SUM(vendable_hl)` monthly packaged-HL KPIs: +~550 HL across affected months; loss KPIs newly complete.
- **lib/beer-tax.js** (maltytask) — reads the VIEW (filtered is_tombstoned=0): beer-tax base RISES ~+550 HL across history. 🔴 fiscal restatement across EVERY year — Kouros's domain to verify + decide on already-filed periods.
- **build-sales-cogs / FG** — general FG = vendable only; FG RISES by the vendable delta. (Independent of the -V cuv 100× sales-COGS bug, which is a separate cost-source issue.)
- **tank-simulator.php** — drains `vendable + loss_kpi`. Today drains `(hlGross − litres) + ~0 = hlGross − litres`; after fix drains `hlGross + litres/100`. So the sim will drain MORE (the previously-stranded lost litres now leave the tank). Per the mig-292 broadened predicate (`vendable>0 OR loss_kpi>0`) the pure-loss rows already drain; this change makes EVERY loss-carrying row drain the full beer-drawn. Operator accepts a few-HL sim tolerance — but this is now ~550 HL of restated draw across history; confirm WIP impact per closed month is acceptable or whether sim re-run is gated.
- **sb-board.php / loss-metrics.php** — filter `vendable_hl>0`; the rows stay visible (vendable rises, doesn't go to 0 except true pure-loss). Loss metrics now complete.

## SEQUENCING + DEPENDENCIES
1. **Should the broad backfill WAIT on the -V hl_per_unit revert? PARTIALLY.** The loss-model code change (compute fn + view + loss_kpi) is BASIS-ROBUST for bottle/can/keg (their hl_per_unit is correct) — those ~768 rows can be recomputed + verified NOW against the view. **The 8 cuv rows + the 245 cuv production rows must be verified against STORED + hand-computed numbers, NOT the live view, until -V hl_per_unit→0.01 lands** (else the 100× contaminates verification). Cleanest: **land the loss-model fix (code + view + backfill) verifying bottle/can/keg against the view and cuv against stored/hand-computed; the cuv view-verification completes for free once the -V revert lands.** Do NOT block the whole arc on -V — but flag the cuv-view-verification as deferred-until-revert.
2. **Coordinate the cuv compute path with the liner/-V session** (same hazard as the burst-bag arc §INTERACTION): do not let two sessions edit the cuv branch of `compute_packaging_vendable_hl()` / the view / sku-61 metadata in the same window. The -V session owns hl_per_unit; THIS arc owns the loss decomposition. Sequence them, or have one session carry both.
3. This SUPERSEDES the mig-292 floor — the build DELETES the `GREATEST(0,…)` from the view (new migration, CREATE OR REPLACE VIEW) and the `bccomp` floor from the compute fn. NEXT FREE MIG = 293 (verify `migrate.php --status` first — parallel sessions).
4. RULE-2 fresh-context review over the combined diff (compute fn + view DDL + backfill + packaging-stats loss-% re-point) before commit. Opus independently verifies the before/after deltas per the COGS-bearing-claims discipline: Σvendable +~550 HL by year/month, Σbeer-tax +~550 HL, Σloss_kpi +~550 HL newly visible, 0 negative-vendable rows, 1303 → vendable ≈ +19.7 / loss_kpi ≈ +43.9, stored==view for non-cuv (cuv stored==hand-computed).

**EQUIP: coder + sql + ui.** coder = the compute-fn rewrite (drop subtractions both branches, move loss to loss_kpi, delete floor) + packaging-stats loss-% re-point + the backfill recompute. sql = the CREATE OR REPLACE VIEW migration (mirror exactly) + the txn'd recompute UPDATE + verification queries. ui = ONLY if a form-field label/help needs touching (the cuv field is already relabelled; bottle/can `loss_liquid_other_units` may want a clearer label like "Perte liquide diverse (L)" since it's now a first-class loss bucket, not a subtractor — minor). webapp-testing smoke optional (read-only) post-deploy. Opus verifies all COGS/tax/loss deltas independently.

---

## ✅ SHIPPED RECORD (2026-06-09 — maltyweb `7a8b63a`, mig 293)

**Commit `7a8b63a` "fix(packaging): liquid losses are losses, not negative production (all branches)".** 3 files: `public/modules/form-packaging.php` (compute fn), `app/packaging-stats.php` (loss-% double-add removed), `db/migrations/293_loss_model_no_liquid_subtraction.sql` (view rewrite). mig 293 applied live (verified `migrate.php --status`: 291–295 all ✓ on VPS 2026-06-09).

### Canonical model now LIVE
- Liquid losses (`loss_liquid_other_units`, `loss_keg_liquid_l`) NO LONGER subtract from vendable in ANY branch → they sum into `loss_kpi_hl`.
- **bottle/can** vendable = good filled units only (`loss_liquid_other_units` removed from the subtraction; added to loss_kpi).
- **keg/cuv** vendable = `hlGross − taproom`. **🔧 REFINEMENT vs the pre-build ruling:** the original plan said "drop all three subtractions (taproom + loss_keg_liquid_l + loss_liquid_other)". As-built KEEPS taproom — taproom is a SUBSET of production and is taxed via hlGross, so dropping it would double-count it. Only the two LIQUID-LOSS terms (`loss_keg_liquid_l`, `loss_liquid_other_units`) were removed from vendable and moved to loss_kpi. `loss_kpi_hl` gains `loss_liquid_other_units/100` in BOTH branches (the can branch had omitted it; keg/cuv had it=0).
- **beer_tax_base** structure unchanged; rises with vendable, never includes liquid loss.
- **mig-292 GREATEST/bccomp floor SUPERSEDED** — its view logic deleted inside the 293 rewrite (root fix: don't-subtract → vendable structurally non-negative). The `292_floor_keg_cuv_vendable_view.sql` file lingers on disk (dead, already-applied; do not re-run).

### Backfill (733 rows that ALREADY had computed values)
- +540.8 HL restated to vendable AND beer_tax_base; +534 HL newly visible in loss_kpi.
- By year: 2023 +94.4 / 2024 +205.4 / 2025 +156.0 / 2026 +85.1.
- Row 1303 (STI4C catastrophic can run): vendable −23.4 → +19.8; loss_kpi 0.69 → 43.9. **Row 1303 is RESOLVED by the model** (removed from the burst-bag arc STILL-OPEN list).
- 0 negative-vendable rows after; stored==view verified for bottle/can/keg.

### 🔴 CRITICAL SAVE — backfill over-reach caught + reverted
The build agent's backfill recompute hit the compute fn's **generic `$isContract` fallback** and **newly populated 57 previously-NULL CONTRACT rows** (BLZ, Abbaye de St-Maurice, MeltingPote, Septentrion, Obrist…), adding **+858 HL of contract beer** to the tax base — true backfill total was +1399 HL, not the dry-run's expected +554. **Opus caught it via an independent snapshot diff** (the agent's own gate had passed) and **REVERTED those 57 rows to NULL.** → durable do-not-repeat recorded in conventions-and-helpers.md §CODING ("a backfill/recompute must not auto-populate contract rows via the compute fn's generic fallback; scope `WHERE <col> IS NOT NULL`, verify row-count + magnitude vs an independent pre-image"). Contract packaging needs per-client SKU mapping + a contract-beer-tax-liability decision before ANY value lands.

### Fiscal de-risk
`bd_packaging_v2.beer_tax_base_hl` is read ONLY by packaging dashboards (`packaging-stats.php`, `packaging-loss-types.php`). The FILED beer tax is computed by `lib/beer-tax.js` from BREWING-side OG × volume, NOT this packaging column. So the +540.8 HL restatement is an INTERNAL-METRIC correction, almost certainly NOT a filed-declaration change. (Confirm declaration basis with the accountant, but the fiscal alarm is largely moot.)

### RULE-2 / verification notes
- The MEDIUM finding (view bottle/can beer_tax "omits unsaleable") was a **FALSE POSITIVE** — the view achieves +unsaleable by NOT-subtracting it; verified 0 stored-vs-view divergence live. Code sound.
- Pre-build finding #6 (negative vendable on taproom>prod) is structurally moot — taproom ⊆ prod.
- **cuv:** stored cols correct (verified at 0.01 tolerance); the read-path VIEW still shows 100× until the held `-V hl_per_unit` revert lands (index #0 / #0a5) → reconciles for free then. Verify cuv against STORED/hand-computed, never the view, until the revert.
- Snapshot table `bd_packaging_v2_lossfix_snapshot_20260609143950` retained on the VPS for rollback.

### OPEN follow-ups (flagged to Kouros — need his input, DO NOT auto-act)
> ⚠️ **#1 + #2 NOW RESOLVED 2026-06-09 (operator directive "mint Néb SKUs at standard formats, Néb pays contract Biersteuer, compute all").** See §CONTRACT-PACKAGING GAP CLOSED at the BOTTOM of this file for the as-executed record + the **+2958.27 HL magnitude flag for accountant review**. Only #3 (May rows 2198/2200) + the -V cutover remain. The text below is retained for provenance.
1. **57 contract rows still NULL** — needs per-client SKU mapping + a contract-beer-tax-liability decision (is Néb the taxpayer on contract beer?). **→ CANONICAL TREATMENT RULING below (§CONTRACT/COLLAB TREATMENT RULE 2026-06-09) — the tax-liability question is ALREADY ANSWERED in `lib/beer-tax.js`; remaining blockers are (a) classification of the 18 unclassified contract recipes is NOT needed (cls='Contract' is sufficient + already set), (b) per-format volume seeding for the 18 no-SKU recipes, (c) Néb's contract over-reach was these rows being keyed by the COMPUTE FN's run_type fallback, NOT a tax-rule gap.**

### ✅ CONTRACT/COLLAB TREATMENT RULE (2026-06-09 — canonical, verified vs lib/beer-tax.js + ref_recipes live)
**The treatment is ALREADY DECIDED in code; the 57-row NULL is a SKU/volume-seeding + compute-path-scope problem, NOT an unresolved fiscal question.**

| Class (ref_recipes) | Néb vendable_hl? | Néb beer_tax_base? | Néb loss_kpi? | Who pays beer tax | Code path |
|---|---|---|---|---|---|
| **Contract** (cls='Contract', 42 recipes; 18 of the 21 here) | **NO** — sold unit is the CLIENT's product, excluded from own-product FG/COGS | **YES — Néb IS the taxpayer.** Néb is the producer; beer ships 100% to client day-of-packaging; Biersteuer paid on every released unit | YES (loss is Néb's loss) | `lib/beer-tax.js loadContractPackagedHL()` taxes `vendable_hl` directly for cls='Contract'; default-no-entry = Contract |
| **CollabIn** (cls='Neb', sub='CollabIn'; DGD r31, DOC r29, here) | **YES** — it IS a Néb product (real Néb SKU: DGDB/DGDF/DGDBU, DOCB/DOCF) | **YES via SALES**, not at packaging | YES | flows via Sales tab like any Neb beer (`type !== 'Contract'` → skippedByType in loadContractPackagedHL) |
| **CollabOut / partner-brewed** (BeerTaxable=FALSE) | NO (not Néb-produced) | **NO — partner brewery already paid tax**; zeroed via BeerTypes col O exclusion | n/a | beer-tax.js L228-234 excludes |
| **WhiteLabel** (Neb subtype) | YES | YES via Sales (not packaging) | YES | same as Neb |

**The crux Q answered:** when Néb brews+packages for an external client (the 18 Contract recipes here — MeltingPote/Abbaye/BLZ/Le Traquenard/Septentrion/Obrist/Singe/Combières/Moutonoir + Chien Bleu Pomelo), **NÉB IS THE BEER-TAX PAYER on that volume** (producer-pays, client takes finished goods). So these 57 rows' vendable SHOULD compute into a tax base — BUT into `bd_packaging_v2.vendable_hl` which feeds `loadContractPackagedHL` → the FILED contract tax. The over-reach problem was NOT that they shouldn't be taxed; it was that the loss-model BACKFILL computed their volume via the **compute fn's run_type fallback** ($isContract path, form-packaging.php L219-220 — derives hl_per_unit from run_type when no SKU), whose volumes are GUESSES (generic per-run_type magnitudes), not the real per-format volume. **858 HL of guessed volume ≠ a tax-rule error; it's a data-quality error in the volume basis.**

**The 3 sub-classes among the 57 rows:**
1. **CollabIn (DGDB r31 ~24L, DOCB r29 ~17L)** — these ALREADY have real Néb SKUs (DGDB id8/DOCB id21, hl_per_unit=0.0792). They should NOT be in the contract_beer NULL-vendable lane at all — they should resolve `sku_id_fk` and compute via the normal SKU path (Sales-taxed, not packaging-taxed). If they're sitting as contract_beer rows with NULL sku_id_fk, that's a row-attribution miss, not a contract-tax question. **DGDB April ~10 HL invisibility (follow-up #2) is this same class.**
2. **Chien Bleu (Bamse/Jasper/Pomelo)** — Bamse/Jasper now HAVE Néb SKUs (BAMB/BAMF/JASPB/JASPF, created this session) + are cls='Contract'. Pomelo (r24) has NO SKU. Contract-taxed at packaging once volume resolves via the real format.
3. **No-SKU Contract (16 recipes: MeltingPote ×3, Abbaye ×4, BLZ ×2, Le Traquenard ×2, Septentrion ×2, Obrist, Singe, Combières, Moutonoir, Chien Bleu Pomelo)** — cls='Contract', NO ref_skus row → no real per-format volume → the ONLY way the backfill got a number was the run_type-fallback guess. These need a SKU/format seeded (or a per-format hl_per_unit decision) before ANY vendable lands.

**WHY they stay NULL until seeded:** computing them via the run_type fallback writes a GUESSED volume into the FILED contract beer-tax base (loadContractPackagedHL reads vendable_hl directly). That violates "never guess a COGS/tax-impacting mapping." NULL is the correct refuse-don't-guess state until each gets a real per-format volume.
2. **DGDB SKU unseeded** — ~10 HL of April production invisible.
3. **Two May-2026 bottle rows (ids 2198, 2200) with no beer assigned** — missing from the May close.
Plus the still-open upstream `-V hl_per_unit` revert (clears the cuv view + the held cost-source cutover, index #0 / #0a5).

### Process near-miss (parallel session)
The loss-model commit briefly swept the parallel WeeklyOrders session's staged mig 294 + `import_weeklyorders.php`; extracted via `git reset --soft` + path-scoped recommit, their work intact. → durable lesson in conventions-and-helpers.md §CODING (parallel-session commit hygiene: always `git commit <explicit paths>`, never bare).

---

## ✅ CONTRACT-PACKAGING GAP CLOSED + DGDB/DOCB DONE — follow-ups #1 + #2 RESOLVED (2026-06-09, DB-ONLY, NO git, Opus live-verified)
**Operator directive (then left for the day, "finish everything open"): "mint Néb SKUs at standard formats, Néb pays contract Biersteuer, compute all."** This is the canonical resolution of the §CONTRACT/COLLAB TREATMENT RULE above — and crucially it is NOT a guess: SKUs minted at STANDARD formats (real per-format volumes) + the directive is an explicit operator statement → seeding from operator-stated + canonical format data is allowed (the §refuse-don't-guess block above was about the run_type-fallback GUESS, which is now superseded by real-format volumes).

### What was executed (all DB data — `ref_skus` inserts + `bd_packaging_v2` updates; NO commit, contract fix is DB-only)
- **CollabIn (follow-up #2): DGDB (recipe 31) + DOCB (recipe 29) computed via their EXISTING real SKUs** (DGDB sku_id=8, DOCB sku_id=21, hl_per_unit 0.0792). Live-verified: DGDB vendable **10.108 HL** (closes the April ~10 HL invisibility), DOCB **3.541 HL**. CollabIn taxes via **Sales**, NOT contract-packaging (`type !== 'Contract'` → skippedByType in loadContractPackagedHL) — so DGDB/DOCB do NOT add to the contract Biersteuer base; they're now correctly attributed to a real SKU and flow the Sales path. This is the §sub-class 1 resolution.
- **20 Contract recipes (follow-up #1): 28 NEW `ref_skus` minted at standard formats** — bot fmt1 (0.0792/24), keg fmt15 (0.20/1), can33 (0.0033/1); reused existing Chien Bleu BAMB/BAMF/JASPB/JASPF; relinked + computed **162 `bd_packaging_v2` rows**. `row_hash = SHA256(recipe_id|format_id|sku_code)`.

### 🔴 MAGNITUDE FLAG — headline for Kouros's accountant review (Opus-verified live)
**Contract `bd_packaging_v2`: 162 rows, ALL with vendable populated, Σ vendable = 2958.265 HL, Σ loss_kpi = +28.247 HL** (verified: `r.classification='Contract'` GROUP BY; and ALL 162 carry non-empty `contract_beer`, non-tombstoned, vendable>0 → **ALL 2958.27 HL feeds `loadContractPackagedHL` = the contract Biersteuer base**).
- **This is ~3.4× the +858 HL contract over-reach that was discussed.** The over-reach was the loss-model backfill's run_type-fallback computing the 57 LOSS-BEARING contract rows. This session computed ALL 162 NULL contract rows for the 20 recipes (not just the 57), at REAL standard formats. **Rule-correct + operator-approved-in-principle ("compute all"), but the SIZE needs accountant review before any contract Biersteuer declaration consumes it.**
- **Fiscal de-risk holds:** `bd_packaging_v2.vendable_hl` → `loadContractPackagedHL` is the CONTRACT tax base; nothing auto-files overnight (contract tax is on-demand via reports). The FILED Néb beer tax (`lib/beer-tax.js` brewing-side OG×volume) is a separate basis. Confirm the contract-declaration basis with the accountant before consuming this 2958 HL.
- **Per-client (recipe) top movers (live-verified exact):** BLZ Company - WestCoast Pale Ale **844.6**, Chien Bleu - Jasper **334.4**, MeltingPote - Cropette **289.6**, Chien Bleu - Bamse **232.8**, MeltingPote - Plainpal **204.2**, Abbaye de St-Maurice - Candide **183.5** (Febris 136.1, Lumen 131.5).

### Rollback snapshots (both retained on VPS, live-verified present)
- `bd_packaging_v2_contractfix_snapshot_20260609_153201` (162 rows) — NOTE the **underscore before the time** (`..._20260609_153201`), not `...20260609153201`.
- `bd_packaging_v2_lossfix_snapshot_20260609143950` (790 rows) — earlier loss-model snapshot.

### Naming / config debt left for tomorrow (NOT blocking, flagged to Kouros)
- **6 minted sku_codes flagged for rename** (too generic / truncated): PALEC/PALEF (id 326/327, recipe 37), SESSC/SESSF (id 328/329, recipe 38) — Le Traquenard, too generic; LAPTB/LAPTF (id 330/331, recipe 40) — truncation. All live-verified.
- **ALL 42 contract recipes have `ref_recipes.sku_prefix = NULL`** (live-verified) — so SDC `activate_format` won't work for any contract recipe until set. (The 20 built this session are a subset of these 42.)

### STILL OPEN after this session (carried — for tomorrow's review)
1. **2 May rows 2198 / 2200** (alex, May 4, bot, prod 1760 / 2362, NO beer/format/tank-recipe) — genuinely unidentifiable from data; need alex to state which beers. Missing from May close. (= §sub-class follow-up #3, unchanged.)
2. **-V `hl_per_unit` revert + cost-source cutover** — still the parallel/liner session's lane (index #0 / #0a5); clears the 245-row cuv view 100× + unblocks the build-sales-cogs flip.

### Session commits (maltyweb): `f019526` (magnitude guard), `3d96847` (burst-bag), `7a8b63a` (loss-model). The contract fix above is **DB-only (no commit)**.

---

## 🟢 RESOLVED — REAL ISSUE WAS DATA-ENTRY + A MIS-LABELLED FIELD, NOT A REDISTRIBUTION FEATURE (2026-06-21; commit `461867a`, pushed to main, LIVE on VPS)

> **AUTHORITATIVE CLOSE-OUT — supersedes the §UNIT-LOSS REDISTRIBUTION ruling below (now DEFERRED/NOT-BUILT).** Kouros's real complaint ("recap shows 0.33% for STIB, nothing for STI4") was NOT liquid loss and NOT a missing dilution feature. Root cause, found by reading data + source:
> 1. **A MIS-LABELLED FORM FIELD.** DB col `loss_untaxed_full_units` = "full unit lost" (mig 233 — counts as loss, EXCLUDED from beer-tax base = Yves's "unité perdue"), but `public/js/packaging-form.js` LABELLED it **"Perte liquide autre"**. An operator mis-entered **4** there for Stirling STIB (id 6802) when the true unit-lost was **1** → inflated loss 0.18%→0.22%.
> 2. The recap also wasn't grouping parallel runs → all breakage showed on the single row the operator typed it into (STIB), nothing on STI4.
>
> **Terminology that caused a long detour:** "liquid loss" in our schema (`loss_liquid_other_units`) is a NARROW spillage field; **Kouros/Yves mean UNVENDABLE / UNIT losses** (invendable + unit-lost + half-filled). QA pulls (qa_analyses/qa_library) were correctly already excluded from loss_kpi.
>
> ### What actually SHIPPED (commit `461867a`, pushed to main, all LIVE on VPS)
> - **Data fix:** `bd_packaging_v2.id=6802` (Stirling b171 STIB) `loss_untaxed_full_units` **4→1**, audited (`audit_row_revisions` #21727); stored vendable / tax / loss_kpi re-materialised to match the view.
> - **Relabel:** "Perte liquide autre" → **"Unité perdue (pleine)"** in `public/js/packaging-form.js` + `public/modules/visite-guidee.php`.
> - **Recap parallel-run grouping (DISPLAY-ONLY):** `kpi_pkg_daily_recap()` (`app/kpi-handlers.php` ~L6001-6074) groups by `(recipe_id_fk, batch, event_date, fam=bot|can|run_type)`; each row shows `loss_pct = Σgroup loss_kpi / Σgroup vendable` + volume-share `loss_hl`; **headline totals unchanged.** Result: STIB & STI4 both show 0.18% (= Yves's 15/8145). NOTE: this grouping was partly authored by a PARALLEL session that had `kpi-handlers.php` dirty; it coexists with the earlier liquid double-count fix — both committed in `461867a`.
> - **Liquid work KEPT:** migs 415/416/417/419 + form-packaging recompute + the Pertes double-count fix were RETAINED (harmless, correct for liquid loss, the double-count fix is real) — part of the same commit.
>
> ### Decisions recorded
> - The heavyweight **"redistribute unit COUNTS across all history (incl. sealed months)"** build ruled below = **NOT BUILT — DEFERRED.** The display-grouping + data fix + relabel met the need. Keep the ruling on file as the path IF per-SKU STORED vendable/COGS ever needs to truly split (not just display).
> - **DURABLE LESSON (codify-worthy):** operator/operator-term "loss %" = unit/invendable losses over TOTAL parallel-run production (Yves: Σloss_units / Σ(B+4 produced)). **CONFIRM FIELD SEMANTICS vs LABELS before building** — the `loss_untaxed_full_units` ↔ "Perte liquide autre" label mismatch cost a full wrong-target build.

---

## 🟡 DEFERRED / NOT BUILT — UNIT-LOSS REDISTRIBUTION ACROSS PARALLEL RUNS — PM RULING 2026-06-19 (kept on file; the path IF per-SKU STORED split is ever needed)

> **🟡 STATUS 2026-06-21: NOT BUILT — DEFERRED.** This whole STORED-redistribution build proved UNNECESSARY: the symptom was a data-entry error (`id=6802` 4→1) + a mis-labelled field + a missing DISPLAY grouping, all fixed in `461867a` (see §RESOLVED above). This ruling stands as the blessed PLAN should a future need require splitting per-SKU STORED vendable/tax/COGS across a parallel group (not just the recap display). Do NOT execute it without a fresh operator ask + the §Q4 OPEN-MONTH/HISTORICAL fiscal re-verify.
>
> **Terminology correction.** Migs **415/416/417/419** (LIVE on VPS, all `--status ✓`) redistributed `loss_liquid_other_units` across parallel runs via a `loss_liquid_other_units_alloc` derived col + `recompute_group_liquid_alloc()` (form-packaging.php) + view COALESCE(_alloc,raw) + a loss_kpi re-materialize. **That was the WRONG field.** `loss_liquid_other_units` is a PURE loss term that (since mig 293) does NOT touch vendable or beer_tax_base — only `loss_kpi_hl`. So that redistribution was display/loss-KPI-only and fiscally inert. Kouros's actual target = the **UNIT losses** (`unsaleable_units`, `loss_half_filled_units`, `loss_untaxed_full_units`, `loss_uncapped_units`) which DO move vendable + tax + COGS. Live proof: Stirling batch 171 — STIB (id 6802, prod 5533) carries unsaleable 1 / half 26 / untaxed 4 / qa 2+5 → loss_kpi 0.0594, vendable 18.1764, tax 18.1797; STI4 (id 6803, prod 2592, same recipe/batch/date/fmt=bot) shows loss_kpi 0.0000. Operator lumped all breakage on STIB.

### Q1 — WHAT gets redistributed: the COUNTS. (verified vs live view mig 317/417 + compute fn form-packaging.php L247-289)
**Redistribute the raw unit COUNTS, so vendable + tax + COGS all follow — NOT a display-only attribution.** Display-only would leave vendable/tax mis-stated per SKU (STI4 over-stated, STIB under-stated) while a derived loss-% says otherwise = a parallel/divergent representation of one fact = the exact SoT violation we forbid. The COUNT is the canonical fact; vendable/tax/loss_kpi all derive from it by formula. Move the fact, the derivations follow consistently.

**Is moving vendable/tax-base between SKUs of the same beer/day LEGITIMATE? YES — within a parallel group only.** A half-filled/unsaleable STIB bottle is physically indistinguishable in origin from a STI4 one: same liquid, same tank-draw, same brewday, filled in parallel; which line the operator happened to type the breakage on is a DATA-ENTRY artefact, not a physical fact. The physical fact is "the batch produced N breakages of family bot." Redistributing by each run's gross-filled volume restores each SKU row to its TRUE loss rate. The group total per (recipe, batch, date, format_family) is conserved exactly — same conservation contract the `_alloc` work already proved.

### Q2 — does moving vendable_hl between parallel SKUs break downstream? NO. (each consumer verified)
- **tank-simulator.php (`app/tank-simulator.php` L1041-1064):** drains **`vendable_hl + loss_kpi_hl` PER ROW**, then keys by (recipe_id, batch). Since a count moved off STIB onto STI4 LOWERS STIB vendable and RAISES STIB loss_kpi by the SAME unit→HL amount (a unit is either vendable or loss), **per-row `vendable+loss_kpi` is invariant**, and the (recipe,batch) drain total is doubly invariant. **Drain-neutral. Safe.**
- **lib/beer-tax.js (L470-499):** reads `vendable_hl` per CONTRACT row, SUMs by (contract_beer, month). Néb beer-tax is keyed by beer×month, NOT per-SKU — redistribution WITHIN a beer×month is sum-invariant. (And the unit-loss redistribution is intra-group = intra-beer-intra-day ⊂ intra-month.) Safe. The FILED Néb tax is brewing-side OG×volume anyway (separate basis).
- **build-sales-cogs / FG stocktake:** FG physical census is PER-SKU. **This is the one place per-SKU vendable matters** — but stocktake is a COUNTED physical census (operator counts STIB vs STI4 cartons on the shelf), it does NOT read `vendable_hl`; it's reconciled against fg_stock_compute depletion. The vendable redistribution makes the COMPUTED per-SKU production MATCH the physical reality better (STI4's real good-output was 2592−its share, not 2592−0), so reconciliation IMPROVES, not breaks. COGS valuation is per previous-month COP/HL × HL; total HL per beer unchanged.
- **packaging-stats.php / kpi-handlers.php / loss-metrics.php / sb-board.php:** all SUM(vendable_hl)/SUM(loss_kpi_hl) over groupings ≥ the parallel group → sum-invariant.
**Conclusion: per-SKU vendable within a parallel group is NOT independently load-bearing downstream — every consumer either sums above the group or reads vendable+loss_kpi together. Redistribution is conservative everywhere it matters.**

### Q3 — fields IN scope: the four UNIT losses; QA EXCLUDED.
IN: `unsaleable_units`, `loss_half_filled_units`, `loss_untaxed_full_units`, `loss_uncapped_units`. These are batch-level breakage the operator lumped on one row → genuinely the batch's, spread by volume.
**EXCLUDE `qa_analyses_units` + `qa_library_units`** — per the canonical attribute model (loss-types-and-form-changes-arc.md §A-LT1: `counts_as_loss=0, affects_vendable=1, is_taxed=0`) these are DELIBERATE per-SKU samples the operator pulls from a SPECIFIC SKU (you sample the STIB you're shipping, not "the batch"). Moving them would misattribute an intentional act. They DO reduce vendable_units (a pulled bottle isn't sellable) but that reduction belongs on the row it was pulled from. Leave as-entered.
Material scraps (4pack/wrap/label/crown_cork/…) already out — they never touch vendable/tax/loss_kpi.

### Q4 — MECHANISM: reuse the shipped scaffolding, ONE generalised group-share, NOT per-field _alloc columns.
**Do NOT add `unsaleable_units_alloc` + `loss_half_filled_units_alloc` + … (4 more derived cols + 4 more COALESCE arms).** That bloats the table + the view + the compute fn for no gain. **Cleaner model: compute the group's redistribution ONCE and re-derive the three stored HL outputs per row from the group-shared counts** — i.e. extend `recompute_group_liquid_alloc()` (rename → `recompute_group_packaging_alloc()`) to, within each (recipe,batch,date,fmt_family) group: (a) sum each in-scope unit-loss field across the group, (b) re-allocate each field's group-sum proportional to each row's gross-filled volume, (c) feed the per-row reallocated counts into `compute_packaging_vendable_hl()` and UPDATE all three stored cols (`vendable_hl`, `beer_tax_base_hl`, `loss_kpi_hl`) — exactly as the existing fn already does for loss_kpi, now for all three. The split BASIS stays gross-filled HL (`prod_total_units/units_per_pack×hl_per_unit`), equal-split fallback, last-row-absorbs-remainder, all already coded.
**Decision needed on STORAGE of the reallocated counts:** the existing `_alloc` pattern stores the redistributed value in a derived col so the VIEW can recompute independently of the stored HL. For unit losses there are two options — (A) store reallocated counts in 4 new `_alloc` cols and teach the view to read them (consistent with `_alloc` precedent, view stays the SoT recompute path, but +4 cols +12 view arms); (B) treat the stored `vendable_hl/beer_tax_base_hl/loss_kpi_hl` cols as the materialized SoT for parallel groups and have the VIEW fall back to a single group-recompute — heavier view. **PM lean = a HYBRID: keep the RAW operator-entered counts untouched (audit trail of what was typed where), add ONE derived col `loss_alloc_basis` is NOT needed — instead store the three reallocated HL outputs in the existing stored cols (already consumed directly by the 3 readers per mig 419) and make the VIEW compute the redistribution inline via window functions** (the view already proved window-function group sums are fine — see mig 416/417). i.e. push the group-proportional split INTO `v_bd_packaging_v2_vendable` as window-partitioned arms, and re-materialize the stored cols (mig-419 pattern) so stored==view. This avoids ANY new column. **This is the build agent's first design task to pin down with sql — bring the exact view rewrite + a parity-vs-stored harness back to PM before applying.** Either way: reuse `recompute_group_packaging_alloc()` on-submit + a backfill mig + a re-materialize mig. Same shape as 415-419.

### Q5 — the shipped liquid-loss work (415-419): KEEP. Do not revert.
It is valid scaffolding the new build reuses (the group-key, the `recompute_group_*` fn skeleton, the window-function backfill, the re-materialize-stored-from-view pattern, the audit/idempotency discipline). It does NOT conflict — it redistributes a DIFFERENT (pure-loss) field into loss_kpi only. **It also carried a real fix** (loss_liquid_other lumped-on-main → diluted in loss_kpi/Pertes dashboard) that stands on its own merit. Reverting would lose that + the scaffolding. KEEP; the new work extends it.

### SEQUENCING
1. **sql + Opus first: pin the view model (Q4 A vs B vs hybrid) + write the parity harness** (group-sum conservation: Σvendable, Σtax, Σloss_kpi per (recipe,batch,date,fmt_family) BEFORE == AFTER, to 0.001 HL; per-row STIB/STI4 move in the predicted direction). NO apply until Opus independently verifies the Stirling-171 canary (STIB vendable 18.18→~14.0, STI4 8.55→~12.7, group total 26.73 conserved; loss_kpi total 0.0594 conserved, now split ~0.040/0.019 by volume — HAND-COMPUTE the exact numbers as the COGS-bearing gate).
2. **compute/coder: generalise `recompute_group_liquid_alloc()`→`recompute_group_packaging_alloc()`** to reallocate all 4 unit-loss fields + re-derive all 3 HL cols; keep the on-submit call site (already inside the write txn, per-touched-group). QA fields PASS THROUGH unchanged.
3. **migration: view rewrite + backfill + re-materialize stored cols** (mirror 416/417/419 exactly; next-free mig = re-`--status` at build start, ≥420). Audit-INSERT-before-UPDATE, idempotent divergence predicate, snapshot table retained.
4. **🔴 OPEN-MONTH + HISTORICAL FISCAL RE-VERIFY:** this shifts per-SKU vendable + beer_tax_base ACROSS ALL HISTORY (any batch where parallel runs existed + breakage was lumped). Group totals conserve, so beer-tax-by-beer-month and COGS-by-beer are UNCHANGED — but per-SKU FG valuation moves. Confirm with Kouros that NO sealed/filed artefact keys on per-SKU vendable below the beer×month grain before backfilling closed months. (Almost certainly safe — beer tax is per-beer, COGS per-beer — but state it.)
5. RULE-2 fresh-context review over the combined diff (fn + view DDL + backfill + re-materialize) before commit. Commit by PATHSPEC (shared dirty tree).

**EQUIP: sql + coder + webapp-testing.** sql = view rewrite (window-partitioned group split) + backfill/re-materialize migs + parity harness. coder = generalise `recompute_group_packaging_alloc()` + on-submit call. webapp-testing = post-deploy smoke of form-packaging corriger-loop (header→corriger→corriger re-fires the recompute on partial data — STANDING FORM FACT). Opus hand-computes + independently verifies the Stirling-171 canary + group-conservation invariants BEFORE any apply (COGS-bearing-claims discipline). NO `ui` (no form-field change — counts stay operator-entered as-is; redistribution is derived/invisible).
