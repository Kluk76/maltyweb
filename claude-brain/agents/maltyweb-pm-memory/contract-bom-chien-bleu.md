# Contract BOM — Chien Bleu (May 2026 close) + the canonical CONTRACT-SKU/recharge encoding pattern

> Load when: building/costing a CONTRACT customer's SKUs (Chien Bleu, BLZ, MeltingPote, Brasserie du Château, BadFish, etc.), the per-material material-responsibility split, contract-COGS / recharge routing, the bulk-wort (Moût Chaud) sale, ART-* generic BC sku_code resolution, or whenever a sold contract line "must be computed". Verified read-only vs live DB 2026-06-08. Cross-ref: sku-bom-validation-walkthrough/README.md (RULE #1 liquid model, RULE #3 intrinsic-vs-fulfillment), packaging-bom-model/README.md §CONTRACT PACKAGING (sku_id_fk NULL by design on bd_packaging — that is the PACKAGING-EVENT axis; THIS file is the SALES/COST axis), [[contract-packaging-cogs-per-client]] (orchestrator memory).

## THE CHIEN BLEU MAY 2026 FOOTPRINT (verified live — customer_no 1486 = "Brasserie du Chien Bleu Sàrl"; ref_customers id 385)
inv_sales_bc rows, period 2026-05, source maysales.xlsx (all carry `sku_id_fk=NULL`, unresolved):
| BC sku_code | desc | qty | unit | CHF | liter_equiv | recipe | ref_sku |
|---|---|---|---|---|---|---|---|
| JASPF | Jasper Fut | 76 | F(keg) | 4009.00 | 1520 | 21 (Chien Bleu - Jasper) | id 308, fmt 15 keg, hl 0.20, active, bom_template_id NULL |
| BAMF | Bamse Fut | 90 | F(keg) | 4747.50 | 1800 | 20 (Chien Bleu - Bamse) | id 309, fmt 15 keg, hl 0.20, active |
| ART-000119 | JASPER - BOUTEILLE | 98 | 24B | 2798.88 | 776.16 | 21 | TO CREATE (bottle 24-box) |
| ART-000120 | BAMSE - BOUTEILLE | 73 | 24B | 2084.88 | 578.16 | 20 | TO CREATE |
| ART-000118 | MOUT CHAUD (bulk sale) | 600 | L | 600.00 | 600 | **21 (Jasper) — NOT recipe 22** | TO CREATE (bulk-litre SKU on recipe 21) |

Recipes verified: 20/21/22 all `classification='Contract'`, subtype NULL.

## MATERIAL-RESPONSIBILITY RULE — Chien Bleu (operator-stated 2026-06-08, verbatim intent)
- **KEGS (JASPF/BAMF): LIQUID ONLY.** Keg, fittings, fillings = client-supplied → NO keg-hardware BOM line. (Matches the existing 308/309 bom-rows = NONE; F2 liquid-only is exactly right.)
- **BOTTLES (ART-119/120): LIQUID + the 24-box "bot brun" + crown corks, MINUS corks THIS RUN.** Corks were existing un-inventoried stock → DEFER this run. Glass bottle itself = client-supplied (operator listed only box + corks as Néb cost). ⚠️ CONFIRM the empty-bottle-is-client's reading before applying.
- **MOÛT CHAUD (ART-118, 600L bulk wort): liquid/wort cost only, pre-fermentation.** Operator did NOT yet rule — PM rec below; awaiting confirmation.

## ⚠️ CRITICAL LIVE-DB FACTS (verified 2026-06-08 — supersede stale memory)
1. **`ref_sku_aliases` DOES exist** (id/alias/canonical_sku_id/notes/created_at) — alias→canonical_sku_id. 15+ rows (EPH24P, PACKDECX8, FR* eshop codes, COLLAB24, EPH*BC folds). This is the SKU-ALIAS layer.
2. **BUT the BC-sales resolver does NOT consult it.** `scripts/python/ingest_bc_sales.py` `load_ref_skus()` (line 122-129) does an **EXACT match on `ref_skus.sku_code` ONLY** at INGEST time → writes `inv_sales_bc.sku_id_fk`. No alias fallback, no read-time JOIN. So aliasing ART-* today resolves NOTHING on the BC path until the resolver is patched. (Shopify/order path may differ — not the BC consumer.)
3. **`inv_sales_bc.sku_id_fk` is a populated FK column**, NOT a read-time `sku_code` JOIN. The user's premise ("resolution JOINs ref_skus.sku_code = inv_sales_bc.sku_code") is WRONG for this table — resolution is at ingest, into the FK col.
4. **CONTRACT LIQUID IS FULLY DERIVABLE for 20/21** (the load-bearing answer). Observed brewing via the F2 compiler's own §2b path (`bd_brewing_ingredients_parsed` v1 JOIN `bd_brewing_ingredients_v2` header):
   - recipe 20 (Bamse): 4 malts + 4 kettle-hops observed; `bd_brewing_cooling` basis 303.3 HL / 10 brews.
   - recipe 21 (Jasper): 6 malts + 5 kettle-hops observed; 428.9 HL / 14 brews.
   - recipe 22 (Moût Chaud): 30.2 HL cooling / 1 brew but **NO parsed malt/hops lines** AND no ref_recipe_ingredients → liquid NOT observable-parsed; needs a basis decision (see Moût Chaud below).
5. **`compile_sku_bom_liquid` has NO classification filter** — default scope = ALL active solo SKUs with `recipe_id IS NOT NULL` (line 2165-2173). So 308/309 + future bottle SKUs ARE auto-in-scope; the compiler is recipe-agnostic and works for contract recipes unchanged. The "v1-only contract brews out of F2 scope" comment is about DRY-HOP attribution only — the kettle malt/hops path resolves cleanly.
   - ⚠️ **BUT the current regenerated F2 dry-run preview (`/var/www/maltytask/data/sku-bom-liquid-preview.json`, 18 beers) does NOT contain JASPF/BAMF/recipe 20/21** — preview predates the 308/309 SKU creation. REGENERATE the dry-run after the contract SKUs exist; the contract liquid will then appear.

## REAL MIs (verified — do NOT guess)
- **24-box "bot brun" = `PKG_BOX_24_BTL_BRUN` id 163**, ref_mi.price 0.420 CHF, gl 4207, active. `v_mi_cost` cost_chf 0.420 basis=**catalog** (no WAC delivery yet → catalog price holds; if a real invoice lands it'll go wac). ⚠️ NOT id 108 `PKG_24_BTL_BRUN` (DEPRECATED, is_active=0) — use 163.
- **Crown cork = `PKG_BOT_CROWN_CAPS` id 93**, 0.0075 EUR, gl 4200, active. `v_mi_cost` cost_chf 0.006993 basis=wac. (DEFERRED this run.)
- Bottle body `PKG_BOT_PIVO` id 91 wac 0.13854 CHF — **NOT a Chien Bleu cost this run** (glass = client-supplied per the rule).
- Liquid is NOT an MI — it's computed by `compile_sku_bom_liquid` (CHF/HL × hl_per_unit, observed-aggregate), emitted as `bom_source='liquid'` rows.

## FORMATS (verified)
- 24-box bottle = `ref_packaging_formats` id 1 (suffix B, 24×33cl, hl_per_unit 0.0792, run_type bot). → ART-119/120 bottle SKUs key here.
- NO bulk-litre / wort "format" exists. Moût Chaud needs either a new format row (suffix e.g. WORTL, hl_per_unit 0.01 = 1L) OR is modeled outside the SKU/format machinery (see below).

## PM RULING — SKU RESOLUTION FOR ART-000118/119/120 (the canonical / least-debt path)
**Option (a)-modified = create REAL canonical SKUs + alias the ART codes via ref_sku_aliases + PATCH the BC resolver to consult aliases.** Reasoning:
- Pure (a) (sku_code literally 'ART-000119') = ugly, opaque, and pollutes the catalog with BC-internal codes — REJECTED.
- Pure (b) (alias-only) does NOT work today because the BC resolver ignores ref_sku_aliases — so the alias resolves nothing until code changes anyway.
- Canonical: mint **JASPB (recipe 21, fmt 1)** + **BAMB (recipe 20, fmt 1)** as real SKUs (naming mirrors JASPF/BAMF keg siblings); add `ref_sku_aliases` rows ART-000119→JASPB, ART-000120→BAMB; then PATCH `ingest_bc_sales.py load_ref_skus`/resolution to fall back to `ref_sku_aliases` on exact-miss (one-time, benefits ALL future generic BC codes). Re-run the BC ingest (idempotent on row_hash) to populate sku_id_fk.
- This keeps ONE fact one table (the SKU is canonical; the ART code is an alias), pays down the resolver-debt once, and is the same alias pattern already used for EPH/COLLAB/FR codes.

## ✅ PM RULING — ART-000118 = JASPER BULK (operator CONFIRMED 2026-06-08, SUPERSEDES the recipe-22 Moût-Chaud plan)
**ART-000118 600 L bulk sale = JASPER (recipe 21) IN BULK** — surplus left in tank after b28 packaging (Jasper racked 35.9 HL, packaged ~23 HL → ~12.9 HL surplus; Bamse only ~3.75 HL → ruled out). **Recipe 22 (Moût Chaud) is NO LONGER the costing recipe** — the earlier recipe-22-derivability problem is MOOT (recipe 21 liquid IS fully F2-derivable, see §CRITICAL fact 4). Cost = **Jasper liquid CHF/HL × 6 HL** (600 L = 6 HL). Liquid-only (it's bulk beer pulled from the same brew — NO packaging, the buyer takes it in bulk).
- **SKU model (PM verdict = option (a), the LEAST-DEBT path): a new "bulk litre" `ref_packaging_formats` row with hl_per_unit=0.010000 + NO `ref_packaging_items`/no `we_supply` template, then a JASPL SKU on recipe 21.** Zero packaging slots → F2 emits liquid-only automatically; 600 L × 0.01 = 6 HL. NOT a journal line (keeps it inside the SKU/BOM machinery so it self-computes + the ART alias resolves uniformly). NOT a reuse of an existing format (none is 1 L / bulk).
- **⚠️ LATENT LANDMINE (the one real divergence flag) — verified live:** the bulk format has NO `catalog_id` + NO `we_supply` template, so it FAILS the packaging buildability gate (`compile_sku_bom_packaging` → line 685 "not found (no we_supply template)", parity_ok=false, +1 error). The **nightly cron does NOT hit it** (cron auto-detect scope = NULL-mi_id-packaging-rows + composites + COLLABs; the bulk SKU has none). **BUT `sdc_recompile_recipe_packaging($pdo, 21)` selects ALL active recipe-21 SKUs** (line 2010-2014) → it WOULD pass the bulk SKU to the packaging compiler the day anyone edits Jasper packaging in bom-review / salle-de-controle, surfacing a spurious error. MITIGATION (pick one, record the choice): (a) accept the benign error (it's noise, not a write — packaging compiler refuses, never NULLs); (b) cleaner — guard the bulk format so it's skipped: the durable fix is a sentinel the recompile-SKU-set query can exclude (e.g. recipe-21 SKU-set excludes formats with `catalog_id IS NULL AND derived_from_format_id IS NULL`). PM rec = ship with (a) + a tracked TODO for (b) IF the spurious error ever bothers the operator. DO NOT bolt a fake we_supply template onto the bulk format (that would invent a packaging fact that doesn't exist).
- **Verified the F2 liquid compiler handles hl_per_unit=0.01 correctly:** scope = active + recipe_id NOT NULL + not-composite (no buildability gate, no template needed — fact 5); qty_per_unit = round(per_hl × 0.01, 6), cost = round(cost_per_hl × 0.01, 6); the ONLY guard is `hl_per_unit<=0.0` (0.01 passes); 6-dp rounding is clean at Jasper's ~50-150 CHF/HL scale (per-litre ≈ 0.5-1.5 CHF). No division-by-format, no precision break.
- **is_packaging_line is COSMETIC** — referenced NOWHERE in app/public PHP; does not gate compilation. (JASPF/BAMF carry =1; set the bulk SKU =0 for honesty, value is non-load-bearing.)
- ART-000118 alias → the new JASPL sku id (replaces the dead MOUTC plan). ref_sku_aliases.notes carries the bulk-Jasper provenance.

## CONTRACT-COGS ROUTING — what "computed" means + where it lands (Q4)
The standing rule [[contract-packaging-cogs-per-client]] / walkthrough §CONTRACT-PACKAGING: contract beers are EXCLUDED from Nébuleuse's OWN-PRODUCT COGS rollup, because the SOLD unit is the client's product, not a Néb SKU. BUT Néb genuinely incurs cost (liquid we brew + box we buy) it recharges to the client. That cost must be COMPUTED + visible without polluting own-product COGS.
- **Mechanism:** tag the contract SKU's `ref_sku_bom` rows / the SKU itself as CONTRACT (recipe.classification='Contract' already does this; a `ref_skus` contract flag or the recipe FK carries it) → the COGS rollup FILTERS contract out of own-product, and a SEPARATE **contract-recharge bucket** surfaces the cost-of-materials-supplied vs the recharge revenue (margin on the contract). 
- ⚠️ **GAP (verified live): the contract-recharge bucket does NOT yet exist as a surface**, and `ref_clients` has NO `materials_supplied_by` flag (cols id/name/notes/created_at only). For a per-SKU contract BOM that costs only OUR-supplied materials, the material-responsibility split is encoded by **which BOM lines are present** (include only the MIs we supply — liquid+box for bottles; liquid-only for kegs) — NOT by a per-line responsibility tag (no such column). This is the cleanest encoding TODAY and matches RULE #3 (per-SKU BOM = intrinsic content; only include what's real-to-us).
- **"Computed" concretely =** the contract SKU resolves a real `ref_sku_bom` (liquid + the materials we supply) at the right altitude, `inv_sales_bc.sku_id_fk` resolves to it, and the sale × BOM-cost surfaces in a contract-recharge view (TO BUILD) separate from product COGS. Until that view exists, the cost is at least CAPTURED on the SKU BOM (not lost) and excluded from own-product by the classification filter.

## CROWN-CORK DEFERRAL — encode so it resurfaces (Q5)
Do NOT silently omit. **Pattern = a ReviewQueue-style row** (self-sufficient, per RQ discipline) of type `bom-deferred-component` keyed on (sku/recipe, mi_id_fk=93 PKG_BOT_CROWN_CAPS) with note "Chien Bleu bottle run May-2026: corks from un-inventoried existing stock, deferred; ADD as BOM line from next run". A zero-qty BOM line is WRONG (compiler recompile clobbers it; and a 0-qty line reads as "we confirmed zero", not "deferred"). The RQ row is the resurfacing channel + is operator-visible. Alternatively a `ref_recipe_ingredients`/binding note flag if a deferred-component surface exists — but RQ is the proven pattern. ⚠️ If no `bom-deferred-component` RQ type exists, this needs the bootstrap; simplest interim = a `doc_review_queue` row of an existing generic type + the note, OR a tracked TODO in this memory file (recorded HERE so it cannot be lost): **OPEN — add PKG_BOT_CROWN_CAPS (id 93) to Chien Bleu bottle BOM (JASPB/BAMB) from the NEXT bottle run; corks deferred for May-2026 run per operator.**

## BUILD SEQUENCE (Chien Bleu May — to make all 5 lines compute)
1. **Confirm 2 operator reads BEFORE building:** (a) empty glass bottle = client-supplied (Néb pays only box+corks)? (b) Moût Chaud costing basis (grist statement vs source-recipe CHF/HL).
2. **Keg liquid (308/309): NO new build** — they're already in F2 default scope. REGENERATE the F2 dry-run so JASPF/BAMF appear; verify liquid-only, sane CHF/HL; apply with the F2 batch. (Keg = liquid only per rule = exactly the F2 output. ✅)
3. **Mint bottle SKUs JASPB (r21,fmt1) + BAMB (r20,fmt1)** (canonical seeding, mirror keg siblings) + add `ref_sku_aliases` ART-000119→JASPB, ART-000120→BAMB.
4. **Bottle packaging BOM = LIQUID (F2) + box `PKG_BOX_24_BTL_BRUN` id 163 ×1/box, NO glass, NO cap (corks deferred → RQ row).** Encode the material split by line-presence (include box; omit bottle+cap). Use the packaging-choice/binding altitude, never the resolved row.
5. **Moût Chaud (ART-118 → recipe 22):** per the operator basis decision — seed ref_recipe_ingredients OR source-recipe CHF/HL × 6HL; create a bulk SKU or journal it; alias ART-000118.
6. **PATCH `ingest_bc_sales.py` to consult ref_sku_aliases** on exact-miss; re-run BC ingest (idempotent) → sku_id_fk populates for all 5 lines.
7. **VERIFY:** all 5 inv_sales_bc Chien Bleu rows resolve sku_id_fk; contract SKUs carry a real ref_sku_bom (liquid + box for bottles, liquid-only for kegs); cost excluded from own-product COGS (classification filter); RQ/TODO holds the deferred cork.

## DIVERGENCE FLAGS to hold
- mi_id=NULL BOM line on any contract SKU = the named defect — REFUSE, RQ, never NULL.
- Inventing recipe 22's grist from a pattern/price = guessing a COGS mapping — FORBIDDEN; surface it.
- Aliasing ART-* without patching the BC resolver = a no-op (resolver ignores aliases) — the patch is load-bearing.
- Encoding material-responsibility as a per-line tag = inventing a column that doesn't exist; encode by line-PRESENCE.
- Letting contract material-cost vanish (excluded from own-product but with no recharge bucket) = the cost is "not computed" — at minimum capture it on the SKU BOM; the recharge-view is a follow-up build.
- Zero-qty BOM line for the deferred cork = clobbered by recompile + reads as confirmed-zero — use the RQ/TODO channel.
