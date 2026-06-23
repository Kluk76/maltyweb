# Same-day census tiebreak resolver — FG per-site stock (app/fg-stock.php)

> SHIPPED+DEPLOYED+LIVE-VERIFIED 2026-06-23. Fixes the "stock restant par site shows a
> negative / missing balance for a SKU produced AND transferred the same day as its census"
> bug. ZEPB@Zgeg −270→+34. **DEPLOYED-NOT-COMMITTED as of ship** (Kouros OK pending; a
> whole-tree `bin/deploy.sh` from a parallel session would REVERT — commit by pathspec).
> Verified in prod by Kouros directly, not on agent word.

## What "zgeg" / the surface is
- "Zgeg" = colloquial canonical display name of `ref_sites` **id=1, site_type='production'** (Renens). "Zeubi"=id2 warehouse, id3 Taproom (pos), id4 Nausikraft (consignment). NOT a beer/SKU/MI.
- The user-facing surface = **`/modules/expeditions.php ?view=stock`** PF-stock board: per-SKU per-site balance chip (`exp-st-loc-breakdown`, e.g. `Zgeg:8 Zeubi:30`) + "Répartition par site" drill, all fed by **`fg_stock_location_snapshot()`**. The `?view=mouvements` tab is the inter-site transfer LOG only (reads `inv_stock_movements`, NO running balance — by design; the balance is the computed snapshot, not the flux table). Common confusion: operator on Mouvements expecting a solde.
- Compute twin = **`fg_stock_compute()`** (global per-SKU physique). Snapshot MUST stay byte-symmetric with compute or the HARD invariant Σ(all snapshot location qty) == Σ(compute physique) breaks (docstring L1244-1248).
- 🔴 Architecture boundary HELD: `inv_stock_movements` stays a pure flux store — do NOT add a denormalised running-balance column. Balance is a COMPUTED view (snapshot fn). One fact, one place.

## Root cause (the asymmetry class)
Every depletion/inflow leg in BOTH functions has TWO branches:
1. **SKU PRESENT in the relevant census** → tiebreak on TIMESTAMP: `event_date>anchor.counted_at OR (event_date==counted_at AND event_ts>anchor_ts)`. ✅ same-day handled (fixed by `d3fb954`).
2. **SKU ABSENT from the census** → historically bare **DATE-strict** comparison, NO hourly tiebreak → same-day events silently dropped.
The same-day fix `d3fb954` was applied to branch 1 only, never branch 2. `197062d` (2026-06-17) fixed the "packaged the DAY AFTER a stale census" floor (use prod-site census not global anchor) but NOT "packaged the SAME DAY as the census" for an absent SKU. So each leg got its happy-path fixed and its fallback left open → the "re-debug in a loop" feeling.

**Logical point that resolves it:** a SKU absent from a COMPLETE census = 0 at the census instant → any same-day event must be POSTERIOR to the count (else it'd have been counted). So the absent-branch should tiebreak the same-day event against the SITE census timestamp (MAX(created_at) of the site's latest census), exactly like the present-branch but keyed at site level instead of the missing SKU row.

## The ZEPB live case (verified 2026-06-23, the regression fixture)
- ZEPB = ref_skus **id=58**. Zgeg (site1) HAD a 2026-06-22 census created_at **06:43:46** (27 rows) — but ZEPB was **absent** from it (its latest Zgeg rows were 06-08 / 05-31) → ZEPB=0 at Zgeg 06:43.
- Prod `bd_packaging_v2 id=6807`: ZEPB event_date 06-22, submitted_at **11:47:14**, prod_total_units 7303 → floored +304 (after 06:43 census). REJECTED by branch-2 bug.
- Transfer `inv_stock_movements id=103`: ZEPB −270 from site1→site2, moved_on 06-22, created_at **12:44:07**. ADMITTED via unit-gate (Zeubi census 06-22 08:42 < 12:44 → toPass true).
- Result before fix: 0 (census, absent) + 0 (prod wrongly jetée) − 270 = **−270**. True ≈ 0 + 304 − 270 = **+34**.
- ⚠️ Note: the census EXISTED with a usable ts (06:43) — the audit's "no anchor / no timestamp" framing was slightly off; there WAS a site-census ts to tiebreak on, which made the fix clean.

## The resolver (AS-BUILT)
Pure, no DB, deterministic, side-effect-free; top of app/fg-stock.php above `fg_prod_since_anchor`.
`fg_event_is_post_census($eventDate, $eventTs, $censusDate, $censusTs, $globalFloor, $inclusiveFallback=false)`
- `$censusDate!==null`: after day → true; before → false; same day → `$eventTs>$censusTs` when both ts present, else false (conservative "no morning baseline" = the documented accepted residual).
- `$censusDate===null` (SKU not counted at this site): `$inclusiveFallback ? eventDate>=globalFloor : eventDate>globalFloor` (encodes the existing 3-tier >= vs > semantics).
- `$eventTs=null` cleanly degrades to date-only (used by B2B expédié: requested_date has no ship-time; created_at is back-dateable entry-time, correctly NOT a tiebreak).
- Companion `fg_norm_ts()` truncates timestamps to 19 chars (strip microseconds): `submitted_at` has `.000000` µs, census `created_at` does not → naive string compare `'12:44:07.000000' > '12:44:07'` mis-sorts. MUST normalize both sides before comparing. (Edge case E from the design — real, now handled.)

## Exhaustive leg/branch inventory — AS-BUILT verdicts
Routed = now calls the resolver. KEPT = deliberately NOT routed (different semantics, comments added).
| Leg | Branch | Status AS-BUILT |
|-----|--------|-----------------|
| Production (fg_prod_since_anchor, shared by both fns) | SKU PRESENT | ROUTED (behaviour unchanged) |
| Production | **SKU ABSENT** | **FIXED** — now fetches `MAX(created_at)` of prod-site census (prodCensusTs), passes (prodCensusFloor, prodCensusTs) to resolver → same-day prod submitted AFTER census now counts |
| Production | no prod site (degenerate) | n/a (La Néb has site1) |
| Transfer from-side / to-side | anchor present | ROUTED (snapshot; unit-gate `$rowPass=$fromPass\|\|$toPass` PRESERVED) |
| Transfer from/to | **no anchor (fallback)** | 🔴 centralized BUT still `> global anchor` strict — per-site census ts NOT yet used (RESIDUAL below) |
| Eshop (compute Step5 + snapshot Leg2) | anchor present | ROUTED |
| Eshop | **no anchor (fallback)** | 🔴 same residual — strict `> anchorDate` |
| Repack open/assembled (compute Step5.5 + snapshot) | anchor present | ROUTED (feature-gated `repack_depletion_live()`, lockstep both fns) |
| Repack | **no anchor (fallback)** | 🔴 same residual |
| B2B expédié 3-tier (compute Step4 + snapshot Leg1) | all 3 tiers | KEPT date-only inclusive `>=` — routing would BREAK same-day-inclusive semantics (requested_date is a date, no ship-time). Comments added. |
| Taproom (compute Step6 + snapshot Leg3) | month grain | KEPT — `period > anchorMonth` CHAR(7); no finer ts exists, same-day collision impossible. |
| Returns restock (compute Step6.7 + snapshot Leg4) | global warehouse-attributed | KEPT — `origin_posting_date` is a BC posting DATE, no ts, globally attributed not per-site. |

## Lockstep guarantee
- (a) Single shared resolver = structural lockstep: compute & snapshot pass identical args → can't drift.
- (b) Permanent read-only probe **`tests/fg-stock-invariant-probe.php`** (env `FG_STOCK_FILE` to point at a non-deployed file; runs on VPS as www-data) asserts Σcards==Σphysique per-SKU AND total. Per-SKU matters: a +X/−X across two sites nets to zero in the grand total but is still a per-site bug (the unit-gate failure class).
- Transfer unit-gate untouched (resolver only changes what each side's pass evaluates to, not the apply-both-or-neither rule).

## RESULT VERIFIED LIVE
ZEPB@Zgeg −270→+34; prod_qty 0→304; invariant PASS on 60 SKU (10035→10339, delta +304 = exactly the recovered prod); no other site/SKU moved.

## 🔴 OPEN RESIDUAL — 2nd-pass follow-up (class NOT 100% closed)
The "SKU absent" FALLBACKS of TRANSFER / ESHOP / REPACK were centralized through the resolver but still pass `globalFloor` with NO per-site census ts (unlike prod, which now passes its site census ts). So "same-day event + SKU absent from THAT site's own census" stays latent in those three legs. Low live risk: transfers neutralized by the unit-gate; eshop = warehouse site almost always counted. **TO FINISH:** fetch each site's census (date+ts) and feed it into those fallbacks, exactly as the prod branch now does. Until then the asymmetry class is fixed for the prod leg (the one that bit us) but not categorically closed for all legs.

## Edge cases (honest, from the design)
- Census taken END of day AFTER prod+transfer → SKU then PRESENT in census at net qty → branch-1 ts-tiebreak correctly does NOT re-add (prod_ts < census_ts). Resolver handles BOTH directions because it compares timestamps not dates. ✅
- Absent SKU + late census = genuinely 0 → resolver excludes earlier-ts prod correctly. But "operator forgot to count" (census-completeness gap) is unrecoverable by any algorithm — irreducible residual.
- `submitted_at` ≠ physical fill-time → a run filled+counted but submitted later double-counts. Pre-existing ACCEPTED residual on every leg (L109-111), not worsened. Unfixable without a true production timestamp on bd_packaging_v2.
- NULL/empty census created_at or event ts → resolver same-day branch returns false (conservative). Verify no active prod-site census rows have NULL created_at.

## Interim data-patch ruling (recorded — was NOT used; code fix shipped instead)
- Fabricating a census row (Zgeg ZEPB 06-22) = REJECTED (corrupts the FG SoT, values into COGS, not cleanly reversible, violates "census = complete census, never fabricate").
- Re-dating transfer 103 to J+1 = INEFFECTIVE (root cause is the rejected +304 prod, not the −270 transfer).
- Only safe reversible cosmetic would have been tombstone-103 (shows 0 not −270, audited, un-tombstone reversible) — but the right move was ship the contained logic fix (no migration). Recorded as the doctrine: when a stock display is wrong because of a MISSING positive, there is no clean data patch — fix the logic.

## EQUIP for any follow-up
coder + sql + ui + webapp-testing. 🔴 COGS/stock-bearing → Opus independently verifies the figure + per-SKU Σ invariant before it reaches the operator (sub-agent "probe PASS" is a claim). Surgical per-file rsync of app/fg-stock.php (shared dirty tree — never whole-tree deploy); md5-verify local↔VPS; `php8.1 -l` deployed file before php-fpm reload.
