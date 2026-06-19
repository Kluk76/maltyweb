# SKU/BOM validation — Canonical rules #1-#4, resolver altitude map, walkthrough framework, sequencing, open calls

## WHY THIS ARC
Precise financials are impossible without per-SKU BOM truth. The machinery (bindings/choices/templates/composite-slots/recompile/BOM-Review surface) is ALL SHIPPED — what's missing is (a) a SETTLED liquid-composition model so the liquid side can be REBUILT (F2 was deferred — the existing 1280 `source='Brewing'` liquid rows are a pre-canonical BSF-import built observed-only with NO gap-fill), (b) the eshop-scotch bucket modeled IN the production BOM at the right altitude, (c) this canonical RULE doc, (d) a few review-surface columns. Validate liquid + packaging + their combination, fix the DB at the right altitude, persist the per-SKU truth in DB + the RULES in memory.

## 🟢 CANONICAL RULE #1 — LIQUID-COMPOSITION MODEL (SETTLES F2; rule CONFIRMED + BASIS DECIDED by Kouros 2026-06-07 — fully settled)
**Per-SKU liquid line = `Σ over ingredients of (liquid_basis_per_hl(recipe,mi) × ref_skus.hl_per_unit)`** where the per-HL basis is a TWO-SOURCE precedence:
1. **OBSERVED-ONLY for malt + hops — NEVER gap-filled from ref_recipe (REFINED + AUTHORITATIVE, Kouros 2026-06-07).** Basis = the observed aggregate of `(observed_qty ÷ cool_final_volume_hl)` for that recipe, aggregated via the **VOLUME-WEIGHTED TRAILING AVERAGE** (DECIDED — see basis block below). The operator has ALWAYS entered malt/hops on the brewing + dry-hop forms → **observed IS the truth for malt/hops, full stop.**
   - ⛔ **A recipe-listed hop/malt that is ABSENT from observed brewing/dry-hop data is NOT added to the BOM.** It is an **intentional brew-day deviation** → it becomes a **KPI flag (recipe-vs-actual drift) — a tracked feature, NOT a correction.** The liquid compiler MUST NOT pull ANY MALT_/HOPS_ line from ref_recipe that isn't in observed. (Sharpens [[feedback_observed_malt_hops_canonical_no_gapfill]].)
   - Observed drift is INTENTIONAL + canonical (ALT's 5 observed hops vs recipe's 2 = correct; the compiler emits 5, and the 2 recipe-only-or-extra appear on the drift KPI, not the BOM).
2. **ref_recipe GAP-FILLS only NON-malt/NON-hops ingredients** — minerals / process-aids / phosphoric / finings / yeast-vit. The observed parsed `category` ENUM is `malt|hops_kettle|hops_dry` ONLY → it PHYSICALLY cannot carry PROC_PHOSPHORIQUE, salts, etc. For these: **observed wins where the form captured it; ref_recipe fills where it didn't.** This is the ONLY place a theoretical substitute for observed is allowed. **This slice is cost-AFFECTING** (gate-4 proved +4158 CHF/month rides on it). ALT's dropped phosphoric = this exact defect (liquid built observed-only, NO gap-fill pass).
   - **Finings (Clarex / Dehaze / Nagardo) are inconsistently entered by the operator** → their inclusion is decided **PER-BEER during the walkthrough**, NOT by a blanket gap-fill rule. (They are a gap-fill candidate, but the operator confirms presence beer-by-beer.)
3. **Zero-observed beers fall FULLY to ref_recipe.** ALT has 0 v2 brewing records → its ENTIRE liquid basis = `ref_recipe_ingredients` (all 7 lines incl. phosphoric — incl. its malt/hops, since there's no observed to win). Precedence degrades gracefully: observed-where-present (malt/hops + captured non-malt/hops) → ref_recipe-gap-fill-for-uncaptured-non-malt/hops → ref_recipe-entirely when observed empty.
4. **Dimension discipline:** canonicalize WITHIN dimension (mass kg↔g, volume l↔ml), NEVER cross. Observed is mass-only; volume MIs (ml phosphoric) only ever come from ref_recipe. 1000× phantom drift is the #1 risk on each axis (sku-decomposition-tree §STEP-③ history = exactly this bug).

**The rule, stated once:**
> For malt/hops: `liquid_basis_per_hl = observed_aggregate_per_hl IF observed exists FOR THIS RECIPE; ELSE ref_recipe_ingredients.qty_per_hl (zero-observed beer only)`. **A recipe malt/hops line with NO observed counterpart is NEVER on the BOM — it is a drift KPI.** For non-malt/hops: `observed_per_hl IF captured; ELSE ref_recipe_ingredients.qty_per_hl IF present; ELSE absent (finings: per-beer operator call)`. Then `qty_per_unit = basis_per_hl × ref_skus.hl_per_unit`.

### ✅ BASIS AGGREGATOR — DECIDED by Kouros 2026-06-07 = VOLUME-WEIGHTED TRAILING AVERAGE (option a, the proper one)
For a beer's standard per-HL malt/hops/etc., the observed aggregator is the **VOLUME-WEIGHTED TRAILING AVERAGE**, defined as:
- **Window:** last ~5–8 brews OR trailing 12 months, **whichever yields ≥3 brews.**
- **Weighting:** each brew's per-HL contribution is weighted by that brew's **batch HL** (available in `bd_brewing` → feasible from the start). NOT a flat mean, NOT a median.
- **Outlier rejection:** reject brews ~2 MAD from the median before averaging.
- **Recompute cadence:** monthly.
- **⚠️ DISCONTINUITY GUARD (added 2026-06-07, SPY walk):** the trailing window must NEVER blend across a **liquid-discontinuity boundary** — seasonal vintage changes AND **DK era-changes** (DK = "By Danny Khezzar": SPY had a massive recipe change at the DK switch; DIB's peach-tea started there). Window starts at the most recent discontinuity, even if that yields <3 brews (then take what exists; if 0, fall to ref_recipe). Same guard class for both consumers (BOM cost + drift aggregator).

**⚠️ COUPLING — THIS SAME STATISTIC FEEDS BOTH consumers (Kouros's explicit note):** (1) the `compile_sku_bom_liquid` BOM-cost basis (F2), AND (2) the recipe-vs-actual DRIFT aggregator. The drift view's prior **"median of last 5" basis is SUPERSEDED** — both records now use volume-weighted-trailing. Any drift-surface build must read the SAME aggregator, not a parallel statistic (a divergent basis between the two = a one-fact-two-stores smell). → also recorded in recipe-ingredients-drift-surface.md drift-view section.

**F2 IS NOW FULLY UNBLOCKED** — rule ① (composition precedence) + the basis aggregator are BOTH settled → `compile_sku_bom_liquid` can be dispatched. (This supersedes the old "median of last-5" that Rule #1 previously stated as settled, and resolves former open-call #1/#4.)

### ✅ DRY-HOP OBSERVED BRANCH — SHIPPED 2026-06-07 (commit `888473b`, maltyweb)
`compile_sku_bom_liquid` now includes a **third observed branch** for dry-hop additions:
- **Root cause fixed:** 100% of dry-hop was silently dropped because `bd_brewing_ingredients_parsed` `hops_dry` rows carry `source_table='bd_fermenting'` and their `source_id` → `bd_fermenting_v2.id` (NOT `bd_brewing_ingredients_v2.id`), so the §2b JOIN always missed them.
- **Two dry-hop source paths now loaded:**
  - §2b2 — v1 path: `bd_brewing_ingredients_parsed` `hops_dry` JOINed to `bd_fermenting_v2` via `bip.source_id = bf.id`; `GROUP BY (recipe_id, batch, mi_id)` anti-fan-out.
  - §2b3 — v2 path: `bd_fermenting_v2` `event_type='DryHop'` with `dh_mi_id_fk`; same GROUP BY.
  - §2b4 — merge: **v2 wins per batch** (never mix); v1 fills remaining batches.
- **Stage tags:** all brewhouse lines tagged `stage='brewhouse'`; dry-hop lines `stage='dry_hop'`. `proposedByRecipe[recipe_id]` is now `['brewhouse'=>..., 'dry_hop'=>..., 'coverage'=>...]`.
- **Composite keying:** `'bh:<mi_id>'` / `'dh:<mi_id>'` allows the same hop (e.g. CASCADE) in both kettle and dry-hop stages without PHP array collision.
- **Floor guard** extended to `$dhMerged` (same allowed-batch set as brewing branches).
- **Sanity check** per MI: 0–1500 g/HL warning via `error_log` (DOA/MOO total >1500 per recipe = 6 varieties summed, individual MIs all within range — verified clean).
- **HARD CONSTRAINT verified:** `ref_sku_bom` 2135 rows / 2250.003568 CHF byte-identical after dry-run.
- **Gates all passed:** (a) zero writes ✅  (b) CHF/HL present per recipe ✅  (c) no double-count ✅  (d) SPY floor 2025-10-07 ✅  (e) all lines tagged ✅  (f) DIV/EST=0 dry-hop ✅  (g) ALT PROC_PHOSPHORIQUE via recipe_gapfill ✅
- **Quantification (g/HL, volume-weighted trailing):** ALT 289 g/HL, EMB ~1118 g/HL (8 vars), DOA ~2103 g/HL (6 vars), MOO ~1068, STI ~670, SPY ~601, ZEP ~1098.
- **Coverage split per recipe:** ALT/DIV/EST/ZEP = v1-only or no-HL; EMB/DOA/STI/SPY/MOO = v2 data available; newer recipes = v2-only.
- **EST dry-hop:** 9 v1 rows exist but no `bd_brewing_cooling` HL data for those batches → correctly excluded (can't compute g/HL without volume).
- **NEXT STEP (operator):** review `data/sku-bom-liquid-preview.json` and gate the `--apply` (which will write the proposed liquid rows into `ref_sku_bom`). The apply path is still `--apply NOT IMPLEMENTED` in the CLI — must be implemented before live write.

**Composites:** each member's liquid derives from that MEMBER's OWN per-HL basis (rule above) × `slot_hl`, NOT a flat re-read of ref_recipe. This SUBSUMES the old F2 recommendation ("derive each member's per-HL from its own single-SKU liquid") — now there is ONE rule for solo + composite.

**REBUILD DECISION = YES, rebuild ref_sku_bom liquid for ALL SKUs + fill the 17 missing + retag `bom_source='liquid'`.** Justification:
- The 1280 existing liquid rows = BSF-import, observed-only, NO gap-fill → EVERY beer with a phosphoric/mineral/process-aid in its recipe has the ALT defect (systematic COGS understatement, not a one-off).
- 17 SKUs brewery-wide have packaging rows + ZERO liquid rows (-X cages, -B12s, -4PBs, a few -BU; ALT-X + ALTB12 among them) → silent COGS holes (sell at zero liquid cost).
- The existing brewing rows are mistagged `bom_source=''` not `'liquid'` → blocks a clean DELETE-target for a safe rebuild-in-place; retag is a prerequisite anyway.

**SEQUENCING GATE (load-bearing):** the rebuild needs a LIQUID COMPILER that DOES NOT EXIST yet. `compile_sku_bom_packaging` is packaging-only by HARD gate (`packagingOnly=true` throws otherwise; F2 deferred). So:
1. Settle the rule (this ruling; Kouros sign-off).
2. **Build `compile_sku_bom_liquid`** as a sibling in `app/sku-bom-compile.php` — reads the 2-source precedence, emits `bom_source='liquid'`, **refuse-don't-NULL on unresolved MI** (RQ row, NEVER a NULL line — the operator's `mi_id=NULL` defect must NEVER be re-created), mirrors the packaging compiler's per-SKU transaction + snapshot-before + parity gate. Equip ui+sql+coder.
3. **Dry-run full rebuild to errors=0**; prove cost-deltas explained (phosphoric/mineral RECOVERIES = expected POSITIVE deltas; malt/hops deltas should be ~0 since observed already won — flag any that aren't).
4. Apply, retag `'liquid'`, fill the 17.

⛔ **The walkthrough DEPENDS on this — do NOT walk on the current BSF-import liquid (you'd validate data you're about to replace). Order: settle rule → build liquid compiler → THEN walk SKUs.**

## 🟢 CANONICAL RULE #2 — THE THREE SCOTCH/STICKER BUCKETS (operator-authoritative, Kouros 2026-06-07 — CONFIRMED EXHAUSTIVE)
**Bucket question CLOSED by Kouros 2026-06-07: "there isn't branded eshop items — eshop items are all generic for now." NO format is both branded AND eshop → NO 4th bucket. The 3 buckets below are exhaustive.** (Resolves former open-call #3.)
Branded sticker + branded scotch are NOT "all bottle recipes" — they belong to specific formats. THREE buckets:
1. **BRANDED (sticker + branded scotch) → ONLY `-B` (24-pack BOTTLE box, fmt 1) + `-C` (24-pack CAN box, fmt 7).** `-C` is the can parallel of `-B` (ZEPC is the only `-C` today). Scotch A/B per recipe via `uses_branded_scotch` (see SCOTCH MODEL in packaging-bom-model/README.md): A=branded scotch alone, B=PKG_SCOTCH_TRANSP + PKG_STICKER_[beer].
2. **ESHOP SCOTCH (generic eshop scotch ONLY, NO branded sticker) → `-B12` (eshop/taproom pack, NOT wholesale), `-4PB` (4-pack-bottle), Pack-Découverte composites, + other eshop packs.** They're packed in mixed eshop boxes / renfort eshop. Carry **`LOG_SCOTCH_ESHOP` (id 201)**, NO branded sticker. **ALTB12 currently carrying `PKG_STICKER_ALT` is a DEFECT** (wrong bucket — clear it, replace with eshop scotch).
3. **NEITHER → everything else: `-4` (6×4 carton), `-X` (cage), `-BU`/`-CU` (singles), `-F` (keg), draft pours.** No scotch/sticker slot at all (4-carton's scotch slot was DELETED mig 141; -X carries contents only).

## 🟢 PACKAGING RESOLVER ALTITUDE MAP (where each component class lives — edit at altitude, NEVER the resolved ref_sku_bom row)
Resolver precedence per slot HIGHEST→LOWEST: `ref_sku_packaging_choices` (per-SKU Tier-1) → `ref_recipe_packaging_bindings` (per-recipe, role-keyed) → template default (`ref_packaging_bom_templates` + `ref_packaging_items`). (DIV33C is the cautionary tale: editing the resolved row alone gets clobbered by recompile.)

| Component class | Altitude | Table / mechanism |
|---|---|---|
| **Body + cap** (PKG_BOT_PIVO, PKG_BOT_CROWN_CAPS, can body/lid) | **Template default (FORMAT-generic)** | `ref_packaging_items` slot (`bottle`/`crown_caps`/`can`/`can_lids`) on the format's `ref_packaging_bom_templates` row; `default_mi_id_fk` fixed; container-layer VOLUME on this line. ← THIS is the "format layer" the body/cap resolve from. Admin-rare. |
| **Recipe art** (label, can-art, sticker, holder, outer_tray, scotch) | **Recipe binding** | `ref_recipe_packaging_bindings` role ENUM(`label,can,sticker,holder,outer_tray,scotch`); ONE row per (recipe,role) → fixes ALL the recipe's SKUs in that role |
| **Per-SKU divergence / white-label brand** | **Per-SKU choice (Tier-1)** | `ref_sku_packaging_choices` (sku_id, slot_name, mi_id_fk) — DIV33C can body, BLA4 brand, ALTB12 eshop scotch |
| **Composite overwrap** | **Per-SKU choice on the composite** | `ref_sku_packaging_choices` slot `outer_box`/`scotch_eshop`/`verre` (PD8→PKG_PACK_DEC; PAL/PAC→PKG_CARTON12_ESHOP+LOG_SCOTCH_ESHOP) |

**Heuristic (= the EDIT-ALTITUDE DECISION RULE on bom-review.php):** wrong for EVERY SKU of this recipe in this role → binding. Just THIS SKU → choice. Structural for the WHOLE format → template/items (admin-gate). MI price/identity → ref_mi.

### ⚠️ ESHOP-SCOTCH ALTITUDE — THE PACKAGING GAP TO CLOSE (bucket 2)
`LOG_SCOTCH_ESHOP` (id 201) is TODAY modeled as a **Stage-2 / sales-fulfilment concern, EXPLICITLY NOT in the production BOM** (packaging-bom-model/README.md §LOG_SCOTCH_ESHOP). Kouros's ruling **PROMOTES it INTO the production BOM** for the eshop-pack formats. The mechanism already exists (PD8/PAL/PAC carry `scotch_eshop → 201` as composite choices). Extend it to -B12 + -4PB.
- **PM RECOMMENDATION (decision to put to Kouros):** model eshop-scotch as a **FORMAT-LEVEL TEMPLATE DEFAULT** on format 2 (B12) + format 4 (4PB) — slot `scotch_eshop`, qty 1, `we_supply_only` — because it's format-uniform (every eshop pack of that format gets it), exactly like body/cap. This makes it AUTOMATIC + removes the per-SKU defect class (no ALTB12-type omissions). Per-SKU choice stays available for exceptions. Pack-Découverte composites keep their existing choice rows.
- **ALTB12 fix:** clear the wrong `PKG_STICKER_ALT` (bucket-1 leak into a bucket-2 SKU) AND ensure eshop scotch resolves (via the template default once added).

## 🟢 WALKTHROUGH FRAMEWORK — FORMAT-ARCHETYPE first, then recipe art, then liquid (3 nested loops, each maps to a DB altitude)
Validate by FORMAT ARCHETYPE (≈15-20 suffixes: F/BU/4/4PB/B/B12/X/C/CU/4C/12C/P25/P50/composites), NOT beer-by-beer — packaging is format-driven; the body/cap/scotch/sticker/outer structure is a property of the FORMAT, shared across all beers in it. Beer-by-beer re-validates the same structure 20× + misses format-wide gaps.
- **Phase A — per FORMAT archetype: validate STRUCTURE once.** Per archetype confirm at template/items altitude: container body + cap/lid slots (format-generic); which decoration roles the format carries (scotch? sticker? holder? outer_tray? outer_box?); which BUCKET (1/2/3) → set branded-vs-eshop-vs-neither scotch ONCE per format. Output = a clean per-format slot template. Fixes the skeleton for every beer in the format.
- **Phase B — per RECIPE: validate ART overrides.** Walk each recipe's bindings (label, can-art, sticker, scotch branded-vs-transp via `uses_branded_scotch`, holder, outer_tray). Where ALT's `PKG_STICKER_ALT` placement, white-label brand overrides get validated. ONE pass per recipe (a binding fixes all the recipe's SKUs in that role).
- **Phase C — per RECIPE: validate LIQUID (AFTER the liquid compiler is built).** Confirm composed liquid (observed malt/hops + ref_recipe gap-fill minerals/process-aids) per recipe; phosphoric recovery verified here. Per recipe, scaled to each SKU by hl_per_unit automatically.
- **Phase D — per SKU: spot-check the COMBINATION.** For a representative SKU of each (recipe × format), confirm liquid + packaging resolve together (resolved `ref_sku_bom` rows on BOM Review), parity 0, cost sane.

Nesting: **Format archetype (structure → template/items) → Recipe (art → bindings + liquid → recipe_ingredients/observed) → SKU (combination → resolved ref_sku_bom).** Three loops, each on a distinct altitude, least-repetitive + most rigorous.

**START ON ALTERNATIVE (recipe_id=6) — PACKAGING WALK STARTS NOW, LIVE (Kouros 2026-06-07):** the PACKAGING side (Phase A format-structure + Phase B recipe-art) needs NO build — walk it live, **simplest-format-first.** Walk ALT's formats (ALT4/ALT4PB/ALTB/ALTB12/ALT-X/ALTBU/ALTF) → fix ALTB12 bucket-2 eshop scotch → confirm bindings. **Phase C (LIQUID) is still gated on the F2 liquid compiler** (which is gated on Kouros's basis a/b pick) → settle ALT liquid (zero-observed → FULL ref_recipe incl. PROC_PHOSPHORIQUE 23.33 ml/hl) only after F2 lands.

## 🟢 DURABLE-PERSISTENCE SPLIT (confirmed = house architecture)
- **Per-SKU TRUTH → DB** (bindings / choices / templates+items / ref_recipe_ingredients / composite slots), reflected in the BOM Review surface. One fact one table. NEVER a parallel store, NEVER edit the resolved `ref_sku_bom` row.
- **The RULES → memory** (PM + operator). Canonical RULE doc = THIS file (liquid-composition rule #1 + the 3 buckets #2 + the altitude map) + packaging-bom-model/README.md (packaging scotch model detail) + sku-decomposition-tree.md F2 close. **For build agents, fold the SETTLED liquid-composition precedence + dimension discipline into the `sql` SKILL** (decided coding craft, where Sonnet loads it) — once Kouros signs off. The 3-bucket packaging rule stays in PM memory (domain data, not coding craft).

## 🟢 BOM REVIEW SURFACE — ADDITIONS NEEDED FOR THIS VALIDATION (no new compile path; fold into bom-review.php AFTER the liquid compiler lands)
1. **Liquid-source provenance per line** — tag each liquid line `observed | ref_recipe-gapfill | ref_recipe-full` (zero-observed beers). THE most important add — makes phosphoric-recovery visible + shows ALT-liquid-from-ref_recipe vs ZEP-malt-from-observed. (Today brewing rows are read-only-with-provenance but don't distinguish COMPOSITION source.)
2. **Missing-liquid flag** — surface the 17 packaging-but-zero-liquid SKUs as a defect feed (silent COGS holes today).
3. **Bucket indicator** — show which bucket (1/2/3) each SKU's format is in (branded / eshop-scotch / neither), at a glance.
4. **bom_source retag visibility** — once retagged `'liquid'`, show the clean liquid/packaging/composite_* split (the `''` mistag hides it today).
5. **Format-archetype pivot** — add a format-FIRST pivot to the browse tab so Phase-A (walk by archetype) is native, not a filter dance.

## 🚩 DIVERGENCE FLAGS to hold during the walkthrough
- mi_id=NULL liquid line = the operator's named defect — REFUSE, emit RQ, never NULL.
- Editing the resolved `ref_sku_bom` row directly = clobbered by recompile (DIV33C cautionary tale) — edit at binding/choice/template altitude.
- "Correcting" observed malt/hops toward ref_recipe = WRONG (drift is intentional; observed is canonical for malt/hops).
- Walking liquid on the CURRENT BSF-import rows = validating data about to be replaced — gate on the liquid-compiler rebuild first.
- Eshop scotch left as a Stage-2-only concern = the bucket-2 packaging gap; must be promoted into the production BOM for B12/4PB.
- Format-uniform facts (body/cap/eshop-scotch) modeled per-SKU = invites omissions (ALTB12) — prefer template default.

## 🟢 CANONICAL RULE #3 — INTRINSIC BOM vs CHANNEL-DEPENDENT FULFILLMENT LAYER (the Alternative walk's load-bearing architecture, operator-confirmed 2026-06-07)
**The per-SKU `ref_sku_bom` holds INTRINSIC content ONLY. Outer packaging + its MI consumption are a SEPARATE, CHANNEL-dependent Stage-2 FULFILLMENT layer — NOT in the per-SKU BOM.** This SHARPENS the existing intrinsic/fulfillment distinction and the §8.3 LOG_SCOTCH_ESHOP "fulfilment = Stage-2" line into a hard architectural boundary.

**What "intrinsic" means per SKU-state:**
- A loose bottle (in an `-X` CAGE) = bottle + cap + label + TEA + liquid. That is its intrinsic BOM. NO outer box, NO eshop carton, NO scotch.
- A `-B`/`-B12` BOX = the box's intrinsic contents (N bottles' intrinsic content) + the box's OWN intrinsic packaging (the cardboard box MI + branded sticker/scotch-or-label per bucket). The box IS a stock item; its box-MI is intrinsic TO the box.
- A `-X` cage is a stock STATE of loose bottles; a `-B` box is a DIFFERENT stock state of the same bottles. **A bottle exists in EITHER a cage OR a box — produced+consumed ONCE, never both.** (Reaffirms the cardinal cost-lives-once invariant.)

**What is FULFILLMENT-LAYER (NOT in per-SKU BOM — emitted by the tracker at sale time):**
- Eshop OUTER packaging is **ORDER-COMPOSITION dependent**, so it CANNOT live on a per-SKU BOM (a SKU has no knowledge of the order it ships in):
  - Single 12-pack order → `PKG_CARTON12_ESHOP` + 4× `PKG_RENFORTS_ESHOP` (bind 4/carton, price 0, warehouse-tracked) + `LOG_SCOTCH_ESHOP`.
  - **Multiple 12-packs in ONE order** (e.g. ALTB12+EMBB12 = 24 btl) → CONSOLIDATE into 1× `PKG_BOX_24_BTL_BLANC` (pack by TOTAL bottle count into FEWEST boxes — NOT per-SKU cartons).
  - Taproom 12-pack → `PKG_BOX_12_BTL_BLANC`.
- The cage-vs-box STOCK PULL itself (which physical stock a direct sale draws from) is fulfillment-layer routing.

**⚠️ RECONCILIATION WITH RULE #2 / eshop-scotch-into-production-BOM:** Rule #2 bucket-2 said eshop PACKS (`-B12`/`-4PB`/Pack-Découverte) carry `LOG_SCOTCH_ESHOP` as a real production-BOM line via a fmt-2/fmt-4 template default. Rule #3 REFINES the boundary: **the pack's OWN single-pack eshop scotch + carton (the wrap that makes a -B12 a sellable eshop unit on its own) is INTRINSIC to that SKU and stays a template default.** What moves to the FULFILLMENT layer is the ORDER-LEVEL consolidation (N×12 → fewest 24-boxes, renforts count, the order's shipping scotch) — that depends on the whole order, not the SKU. So: per-SKU template default = the single-unit eshop wrap; tracker = the multi-unit order consolidation + cage/box pull. NO double-count: if an order consolidates 2×12 into one 24-box, the tracker emits the 24-box and SUPPRESSES the two single-12 cartons. (Open item #2 below: confirm with Kouros whether a -B12 sold alone gets its carton from the template OR always from the tracker; PM rec = template for the single-unit baseline, tracker overrides on consolidation.)

### STOCK STATES (where cages and boxes sit)
| Stock state | Format | What it is | BOM altitude |
|---|---|---|---|
| **`-X` CAGE** | fmt 6 (1027 loose bottles) | intermediate WIP, NEVER sold direct; the default eshop/Shopify pull source while in stock | intrinsic: 3 contents lines (bottle+cap+label) per the X template |
| **`-B` / `-B12` BOX** | fmt 1 / fmt 2 | sellable box; the taproom pull source; the eshop FALLBACK when no cage stock | intrinsic: N×(bottle+cap+label) + the box's own outer MI (bucket 1/2/3 scotch/sticker) |
| **loose (eshop order)** | — | bottles drawn from a cage for a Shopify shipped order | NO new intrinsic; cage draw-down + tracker-emitted eshop outer pkg |

## 🟢 RULE #4 ADDENDUM — DRAFT-POUR KEG-SHARE (deterministic, FOLD NOW into P25/P50 packaging BOM)
Draft pours `-P25`/`-P50` consume a SHARE of the keg hardware at pour level (the keg is TAPPED not SOLD, so collar/safe are consumed proportionally as the keg is drawn down). **Keg-share line = `(pour_HL / 0.2) × (PKG_KEG_COLLARS + PKG_KEG_SAFE)`** (0.2 HL = 20L keg). This belongs in the **packaging BOM of P25/P50** (template/items altitude, bucket-3 "neither" formats which today carry no scotch/sticker — this ADDS a keg-share slot). **NO double-count with ALTF:** the keg is either SOLD whole (`-F`, full collar+safe on the keg SKU) OR TAPPED for pours (`-P25/-P50`, fractional share) — **channel-exclusive**, a keg is one or the other, never both. **GUARD NEEDED:** the tracker/depletion must ensure a given physical keg's pours sum to ≤ 1 keg's hardware (Σ pour shares ≤ 1.0 per keg) and that a keg routed to pours is NOT also counted as an `-F` sale. Deterministic → fold into the format-template enrichment batch now.

## STATUS (Kouros 2026-06-07)
- **Rule #1 CONFIRMED** (with the malt/hops observed-ONLY refinement + finings-per-beer). ✅
- **Buckets CONFIRMED EXHAUSTIVE** (no branded eshop → no 4th bucket). ✅
- **BASIS AGGREGATOR DECIDED = volume-weighted trailing average** (option a; window ≥3 brews over last 5–8 brews OR 12mo, batch-HL weighted, ~2 MAD outlier-reject, monthly recompute). SAME stat feeds BOTH F2 BOM cost AND the drift aggregator. ✅
- **F2 liquid-compiler build FULLY UNBLOCKED** (rule ① + basis both settled) — ready to DISPATCH. ✅
- **PACKAGING walkthrough on Alternative STARTS NOW, live, simplest-format-first** — no build needed for Phase A/B. ▶️

## 🟢 DETERMINISTIC FIX-SET FROM THE ALTERNATIVE WALK (operator-confirmed — CAN go into ref_sku_bom / templates NOW)
These are the brain-state of the walk; each is unambiguous and FOLDS into the format-template-enrichment batch (NOT gated on F2 except where noted):
1. **Bottle cost ← WAC `v_mi_cost` 0.1466 EUR (bottle-only), NOT `ref_mi.price` 0.1684** (TEA-bundled + stale). + separate `PKG_TEA_BOT_CH` @ CHF 0.02 (GL 4201, regulatory fixed 33cl=small-beer=2ct) as a FORMAT-TEMPLATE line on ALL glass-bottle SKUs. Can CAR (IGORA 0.7ct) is EMBEDDED in can WAC (not invoiced) → NO line. ⟹ this elevates backlog #9 (WAC-costing root fix) — the bottle proved `ref_mi.price` vs `v_mi_cost` is a real ~15%-class divergence ON THE HIGHEST-VOLUME MI.
2. **WAC-costing compiler switch (= backlog #9, ELEVATED):** BOTH compilers (`compile_sku_bom_packaging` + the new `compile_sku_bom_liquid`) must cost from `v_mi_cost` NOT `ref_mi.price`. **HARD CROSS-DEP: this MUST precede the F2 liquid apply** (else the liquid rebuild bakes in stale catalog prices).
3. **Box-branding = ONE-OF-3 per (beer×format)** [bucket #1 sub-rule]: branded scotch (MOO/DIV/EMB/ZEP bottles → `PKG_SCOTCH_<beer>` REPLACES transparent, NO sticker/label) | sticker 1/box (ALT/DIB/SPY/STI) | label-on-box 1× (DGD/DIP/DIG/DOC/EPH). **SYSTEMIC STICKER DEFECT (34 box SKUs):** sticker qty set to BOTTLE-COUNT (24/12) instead of **1**; branded-scotch beers' boxes wrongly carry sticker+transparent; label beers carry unused stickers. Fix qty→1 + apply the 3-way at recipe-binding altitude. DOAB theoretical (skip).
4. **Scotch (carton-wrapper tape) = 0.92 m/box ÷ 990 m roll ≈ 0.00093**; transparent default unless a branded-scotch beer.
5. **`-B12` eshop:** DROP branded sticker + ADD `LOG_SCOTCH_ESHOP` (the ALTB12 bucket-2 defect fix; rides the fmt-2 template default).
6. **Draft pours `-P25`/`-P50`:** keg-share line per RULE #4 above. **FOLD NOW.**
7. **F2 liquid dry-run done+clean** (preview ready, NOT applied) — **needs the WAC-costing switch (#2) BEFORE apply.**

## 🟢 SEQUENCING RULING (Kouros's order question — answered)
**Walk all beers' BOMs first, THEN batch-build — with TWO deterministic carve-outs done now, and a HARD cross-dep.**

**Rationale:** the packaging walk (Phase A/B, simplest-format-first) needs NO build and is where the operator surfaces the per-beer bucket/branding facts — those are exactly the inputs the template-enrichment batch needs. Building the template fixes BEFORE finishing the walk = building on a partial fact-set (you'd re-touch templates per newly-walked beer). The walk is cheap (live, no build); finishing it first makes the template-enrichment a SINGLE clean batch instead of N incremental edits. So: **keep walking.**

**BUT two things are deterministic + cross-cutting enough to do in parallel NOW (they don't depend on the per-beer walk):**
- **(ii) WAC-costing compiler switch** — purely a cost-source swap, beer-agnostic, and it GATES the F2 apply. Do it now so F2 is unblocked the moment its dry-run is signed off.
- **(iii) F2 liquid build (compiler) + dry-run** — already done/clean; the BUILD of `compile_sku_bom_liquid` is beer-agnostic. Hold the APPLY until (ii) lands.

**ORDER OF OPERATIONS:**
1. **Continue the per-beer packaging walk** (live, no build) — accumulate per-beer bucket/branding/finings facts across all beers. ← operator's current activity, RIGHT call.
2. **(ii) WAC-costing compiler switch** (parallel, now) — both compilers cost from `v_mi_cost`. Beer-agnostic. **GATES step 3.**
3. **(iii) F2 liquid APPLY** — only after (ii). Rebuild ref_sku_bom liquid all SKUs + fill 17 missing + retag `bom_source='liquid'`. (Build of the compiler can happen in parallel with the walk; APPLY waits on WAC.)
4. **(i) Format-template enrichment BATCH** — AFTER the walk completes: sticker-qty→1 + 3-way branding + TEA line + eshop-scotch template default + keg-share. One clean batch over the full validated fact-set. (Keg-share + TEA are deterministic enough to fold early if a batch is dispatched sooner, but the sticker/branding 3-way wants the full walk.)
5. **(iv) DIRECT-SALES STOCK CONSUMPTION TRACKER (= Stage-2 matrix)** — LAST, AFTER all BOMs are validated (operator's explicit "after all BOMs"). Depends on: intrinsic BOMs correct (1-4), the Shopify pickup-vs-shipped flag (the one external dependency to line up early), cage/box residual ledger table.

**CROSS-DEPENDENCY FLAGS:** (a) **WAC-costing (ii) MUST precede F2 apply (iii)** — non-negotiable. (b) Keg-share + TEA (deterministic) can fold any time; sticker/branding 3-way wants the completed walk. (c) The Shopify **pickup flag** is the long pole for the tracker — start that feed change EARLY (parallel to everything) so it's ready when the tracker is. (d) The tracker double-count guards (cage-XOR-box, order-level-pkg-once, keg-share ≤1) are the correctness gates — bake them into the tracker spec, not as an afterthought.

## OPEN OPERATOR CALLS (remaining)
1. Eshop-scotch altitude: TEMPLATE DEFAULT on fmt 2 (B12) + fmt 4 (4PB) [PM rec] vs per-SKU choice each. (Surfaces during ALT packaging walk on ALTB12.)
2. **Single-unit vs order-level eshop carton boundary** (Rule #3 reconciliation): does a `-B12` sold ALONE get its carton/renforts/scotch from the SKU template, with the tracker only OVERRIDING on multi-unit consolidation? [PM rec = yes: template = single-unit baseline, tracker suppresses+consolidates on multi-pack orders.] Surfaces when the tracker is specced.
- ~~Rule #1 sign-off~~ ✅ confirmed. ~~3-bucket exhaustiveness~~ ✅ confirmed. ~~Basis aggregator a-vs-b~~ ✅ DECIDED = volume-weighted trailing (a).
