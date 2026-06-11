# Packaging page dashboard — full build map (load when building/altering packaging.php)

> Arc started 2026-05-31. **🟢 ALL ATOMS A0+A1+A1.1+A2+A3+A4 BUILT + DEPLOYED LIVE on VPS — UNCOMMITTED, awaiting operator browser eyeball before commit (2026-05-31).** A1.1 corrective fix DONE (Neb/Contract join now recipe_id_fk→ref_recipes.classification; reconciliation gate PM-VERIFIED LIVE: Neb 2004/53,903.05 + Contract 240/4,983.23 = 2244/58,886.28, 0 orphans). This file = the CORRECTED data-source map + KPI derivations + AS-BUILT state. Lean-index pointer + the 🔴 CORRECTIONS block live under main index §ARC PACKAGING PAGE DASHBOARD. **All facts below PM-VERIFIED LIVE 2026-05-31** unless tagged otherwise.

## 🟢 AS-BUILT STATE (2026-05-31, live + uncommitted; agent a8f974d35aa9f33d4/a34b6899e84608082)
> Files (commit with EXPLICIT `git add` — tree has other sessions' work): `app/svg-tanks.php`, `app/packaging-stats.php`, `public/modules/packaging.php`, `public/js/packaging.js`, `public/css/app.css`. NO migration (read-only dashboard; all cols pre-existed). require_login only (inventory/cost carve-out, NOT role-gated).
- **A0 card restyle:** `.home .tank-card` dark→kraft (`--bg-elev`/`--bg-side` hover), `--hop-soft`→`--hop`, `--ink-faint`→`--ink-mute`, 2× `#cfc6b2`→`var(--tank-empty)` in svg-tanks.php. Shared svg-tanks.php→tanks.php audited OK (no regression on the other consumer).
- **A1+A1.1 data layer `app/packaging-stats.php`:** Neb/Contract split via recipe_id_fk→ref_recipes.classification (the corrected key). Reconciliation gate PM-VERIFIED LIVE EXACTLY.
- **A2 KPIs 1–4** in packaging.php + NEW `public/js/packaging.js`: (1) Neb HL/month; (2) Neb HL per EXACT SKU/month [sku_id_fk→ref_skus.sku_code, Neb-only, 4 NULL→'(SKU manquant)' surfaced not dropped — refuse-don't-NULL honored]; (3) Neb HL per format; (4) Contract HL per format. Inline-SVG stacked bars (wort-kpis.js technique), `window.PKG_STATS` hydration, year via existing `pkg_year` GET.
- **A3** `pkg_current_week_events()` + `pkg_qa_metrics()`: current-week table (date/SKU/format/units/HL/avgO2/avgCO2/%losses) + QA grid with n= denominators.
- **A4** `pkg_quarterly_hl()` + `pkg_monthly_hl_ytd()`: quarterly grouped bars current-vs-prior-year (+%Δ, +HLΔ) + cumulative YTD line current-vs-prior. 2026 YTD +671 HL (+13.7%) vs 2025. Implements the operator-wanted same-period-prior-year comparison (% + volume) pattern.

## ✅ CIP CADENCE ON BBT CARDS — SHIPPED + DEPLOYED; cadence MODEL corrected + resolver BUG fixed 2026-06-11 (NOT committed; smoke pending)
> Surfaces the EXISTING `app/cip-cadence.php` BBT-vessel resolver (the racking BBT-cadence resolver — NOT `app/cct-cip-cadence.php`) on the packaging.php BBT grid (req 3a: BBT stays at TOP). Deployed via SCOPED scp (not bin/deploy.sh — shared dirty tree). **Resolver is single-source; JS only re-presents resolver output, never re-derives.**
>
> ### 🔴 AUTHORITATIVE CADENCE MODEL (operator-confirmed 2026-06-11 — the correct rule)
> A BBT cycle is: **full CIP → 6 blends → acid CIP → 6 blends → full CIP → reset.** Exactly ONE acid leg between fulls; a full every **12 blends**; both `commissioning_settings.cip_cadence_*_after` thresholds = **6** (each is a PER-LEG budget, not a since-full budget).
> - **"Blends since last CIP" = `racks_since_acid`** (the acid counter resets at ANY CIP — acid or full). Do NOT use `racks_since_full` as the per-leg counter.
> - **Cycle position** is read by comparing dates: `last_acid_cip_at > last_full_cip_at` ⇒ **leg 2** (acid already done, next = **full**); else **leg 1** (next = **acid**).
> - `recommended_action` follows cycle position. `severity` = **critical** for a due full OR any cadence ≥ 2 legs (12 blends) overdue; **warn** for a due acid; **ok** if since-last-CIP < 6.
>
> ### 🐛 RESOLVER BUG (FIXED + redeployed 2026-06-11)
> Old logic fired `full_recommended` off `racks_since_full >= threshold_full` — which trips the instant the **acid** is due (~6 blends), mislabelling an acid leg as a full. Corrected to the date-comparison cycle-position model above. **Durable lesson: a "since-full" counter cannot encode a per-leg cadence with an intervening acid — must track since-ANY-CIP + cycle phase.**
>
> ### Resolver output — 4 NEW additive keys (all existing keys preserved)
> `since_last_cip` (=racks_since_acid), `last_cip_type` ('acid'|'full'|null), `last_cip_at` (YYYY-MM-DD), `next_cip_type` ('acid'|'full').
>
> ### Build artifacts
> 1. **DATA FIX — mig 328** `db/migrations/328_fix_future_dated_bbt_cip_dates.sql` (APPLIED, 0 pending; separate git state). Corrected 10 future-dated `bd_cip_events.cip_date` BBT rows (Aug/Oct/Dec 2026 garbage) → parent racking `event_date` via UPDATE…JOIN bd_racking_v2 ON racking_id. **DURABLE MIGRATION GOTCHA: the resolver's `COALESCE(STR_TO_DATE(...))` 4-format guard throws MySQL err 1411 in an UPDATE (strict mode escalates STR_TO_DATE NULL→error in data-change stmts; FINE in a SELECT). Fix = `cip_date REGEXP '^[0-9]{4}-'` pre-filter + single `STR_TO_DATE(...,'%Y-%m-%d %H:%i:%s')`.**
> 2. **UI `packaging.php`:** non-maint BBT cards = `<button class="tank-card-btn tank-card" data-bbt="N">` (maint stay `<div>`). Card-face badge: **label from `next_cip_type`** ("CIP complet"/"CIP acide"), **colour-class from `severity`** — type and urgency are DECOUPLED (an overdue acid shows "CIP acide" in red). ok = no badge (refuse-don't-NULL). Injects `window.BBT_CIP_DETAILS` (keyed by bbt# = cadence fields ⨝ occupancy beer/batch/remaining_hl) + `window.BBT_CIP_CADENCE` (severity+bbt_number, read by session-framework badge). New `<dialog id="bbt-detail-modal">`.
> 3. **`public/js/bbt-detail-modal.js`** rebuilt to operator-approved **single-counter + cycle-dots** layout: ONE 6-segment "depuis le dernier CIP X/6" bar, Dernier/Prochain rows, `✓ acide → ◌ complet` cycle dots. **Dropped the confusing dual "/6" counters.** IIFE, no chart, mirrors cct-detail-modal.js open/close/escHtml/event-delegation; presents resolver output only.
> 4. **`public/css/bbt-detail-modal.css`** updated for the 6-seg bar + cycle-dot states (app.css tokens only, 0 hex).
>
> ### Consumer impact — confirmed unaffected
> Racking-attestation cadence badge in `public/js/session-framework.js` (~line 300) reads only `severity`+`bbt_number` off `window.BBT_CIP_CADENCE` → unaffected by the new keys, and now warns at the correct times.
>
> ### Live resolver verification (8 BBTs, 2026-06-11)
> **BBT#8** = `last_cip_type=acid`, `since_last_cip=8`, `next_cip_type=full`, `full_recommended/critical` (acid done, full overdue by 2 legs). The earlier "17 since full / full_recommended" reading was `racks_since_full` under the OLD buggy model; the new model correctly reads the intervening acid and reports an overdue **full**. All other 7 BBTs = leg 1, within budget, none/ok.
>
> **STILL PENDING:** (1) **authenticated browser smoke NOT done** — 302-on-anon doesn't exercise the post-auth body; operator to eyeball the live page (click a BBT card) or Playwright-with-login. (2) **git commit by pathspec** — 4 files now (`app/cip-cadence.php`, `public/modules/packaging.php`, `public/js/bbt-detail-modal.js`, `public/css/bbt-detail-modal.css`); mig 328 already in its own state; pending operator go-ahead. Do NOT sweep parallel-session dirt. No ref_pages change → NO tour-steward dispatch. Architecturally clean: single-source resolver, refuse-don't-NULL honored, zero COGS/tax surface.

## ✅ OBSERVED YEAST ON BBT CARDS — SHIPPED + DEPLOYED + SMOKE-VERIFIED 2026-06-11 (render-only; NO mig, NO writes; left UNCOMMITTED per operator)
> BBT occupied-card now surfaces the **observed yeast (strain + generation) from `bd_brewing_brewday_v2`**, matching the CCT board's yeast nomenclature. Render-only, downstream of the tank-sim occupancy; **independent of the paused strain-classification arc — reads OBSERVED brewday data, NOT the `ref_recipes` strain link** (observed-wins, same precedence as the rest of the BBT/CCT cards).
> - **Derivation = verbatim copy of the CCT board query** (`tanks.php` L338-345, v2-canonical): `SELECT yeast AS bd_yeast, yeast_gen AS bd_yeast_gen FROM bd_brewing_brewday_v2 WHERE beer=:beer AND batch=:batch ORDER BY event_date DESC LIMIT 1`. Statement prepared ONCE (`$bbtYeastStmt`, packaging.php ~L105) before the BBT supplemental loop, executed per-iteration on `$rawBeer`+`$batch`, stored as `'yeast'`+`'yeast_gen'` into `$bbtOccMap[$num]`. Same single-source nomenclature as the CCT board — do NOT re-derive or invent a parallel yeast read.
> - **Render:** occupied-card `else:` block (~L483) emits a guarded muted chip `<span class="tank-card__yeast tanks-mute">Levure · {strain} (G{gen})</span>`, htmlspecialchars-escaped, ONLY when strain non-empty (refuse-don't-NULL — no chip on empty), placed between the blend line and the rack date. Blend cards show both blend line + yeast chip cleanly. Empty BBT #1 → no chip.
> - **CSS:** `public/css/app.css` ~L3943 (after `.home .tank-card__sub`) new `.home .tank-card__yeast` — JetBrains Mono 10px, matches the sibling muted-sub style. No inline CSS (house rule).
> - **Verification:** `php -l` clean on deployed file; `bin/deploy.sh --apply` succeeded (4 parallel-session dirty-tree files confirmed byte-identical local↔VPS, no-op rsync — clean deploy, no foreign-dirt leak this run); **Playwright render smoke = all 7 occupied BBTs carry the chip with real DB-matching values** (e.g. Zepp #212 → Levure · W34/70 (G12); US-05 G1, Verdant G2). No 500.
> - **Files (2, render-only, UNSTAGED/uncommitted per operator):** `public/modules/packaging.php`, `public/css/app.css`. NO migration, NO DB writes, no ref_pages change → NO tour-steward dispatch.
> - **⚠️ DATA-ENTRY SMELL (future cleanup, NOT this build):** one occupied BBT's observed strain renders as the literal `"New Yeast"` (gen 7) — a placeholder/free-text value sitting in `bd_brewing_brewday_v2.yeast` for that batch, not a real strain. Render is faithful (observed-wins); fix belongs at the brewday-form data-entry root, not in the render layer.

## 🔴 KEY AS-BUILT FACTS (corrections to prior memory — PM-VERIFIED LIVE 2026-05-31)
1. **QA O2/CO2 table = `bd_packaging_co2o2_measures` — PM-VERIFIED LIVE cols: {id BIGINT UNS, packaging_id_fk BIGINT UNS, reading_index TINYINT UNS, co2_gl DECIMAL(6,3), o2_ppb DECIMAL(8,2), measured_at datetime(6), source VARCHAR(32), imported_at datetime(6)}. O2 is ppB not ppm. EXTREMELY sparse: 11 rows / 3 events / all 2026.** Corrects any earlier "~1240 rows / o2_ppm" note — that was WRONG. Rendered honest with n= denominators, "—" when absent. Scope to bottle/can fills.
2. **Losses tracked in UNITS, not HL** (`loss_kpi_hl` empty pre-2026). OPERATOR-LOCKED % losses formula: losses_units = Σ(loss_liquid_other_units, loss_4pack_btl/can_units, loss_wrap_btl/can_units, loss_label_btl_units, loss_crown_cork_units, loss_can_lid_units, loss_container_btl/can_units, loss_keg_collar_units, loss_uncapped_units, loss_half_filled_units, loss_untaxed_full_units, loss_keg_save_units, unsaleable_units); total = prod_total_units + losses; % = losses/total. **QA/library samples EXCLUDED both sides; `loss_keg_liquid_l` EXCLUDED (it's litres, not units).** 2026 ≈ 3.66%.
3. **STANDING PAGE REQUIREMENTS (operator, now permanent for packaging.php):** (a) **BBT current fill-state ALWAYS at TOP of the page** — reorder DONE (BBT section is now the first content block, ~line 363, ABOVE stats+KPIs). Any future packaging.php edit MUST keep BBT first. (b) Same-period prior-year comparison (% + volume) is a wanted pattern — baked into A4, reuse it.
4. **Operator decision (locked):** per-SKU = Nébuleuse ONLY, NO fabricated contract SKU codes (the honest asymmetry the build map flagged is accepted); Contract = FULL treatment (own per-format/month + quarterly).

## What packaging.php is today
- Vessel-card page rendering BBT + CCT via `require_once app/svg-tanks.php` → `svg_bbt($fillRatio,$state,$num,$beer)`; reads `ref_bbt` + tank-sim map directly (NOT a shared partial). CSS classes `tank-card`/`tank-card--bbt-occ` in the `app.css` bundle. Has a `pkg_year` GET filter scaffold (min 2021..currentYear).
- A0 restyle DONE: `.home .tank-card` dark-slab → kraft (`--bg-elev` surface, `--bg-side` hover, kraft box-shadow); 2 low-contrast tokens fixed (`--hop-soft`→`--hop`, `--ink-faint`→`--ink-mute`); 2 hardcoded `#cfc6b2` fills in svg-tanks.php → `--tank-empty`. tanks.php (shares svg-tanks.php) unaffected. Smoke 302, no 500.
- Login-gated only (`require_login()`); inventory/cost surfaces NOT role-gated (standing carve-out).

## Canonical data source (V2-ONLY)
- **`bd_packaging_v2` is the ONLY event source.** Drop any v1 read / cross-source dedup (closes de-wire item #3, RULE-1 scrapping win — log it). NO new event table, NO parallel store; every KPI = ONE read chain.
- **Grand total (reuse-filtered, v2-only): 2244 ev / 58,886.28 HL.** By year: 2021 8,252.87 / 2022 11,207.02 / 2023 10,776.07 / 2024 11,300.27 / 2025 11,775.64 / 2026 5,574.41 (partial).
- **`reuses_packaging_id_fk IS NULL` filter on EVERY HL/FG/tax/output aggregate** (mig 238 — a reused cuve produced nothing; else the BBT7-class double-count).
- Read the STORED `vendable_hl` column (0 NULL) — NOT the `v_bd_packaging_v2_vendable` view (still has the 244-contract-NULL drift, open item B).

## 🔴 NEB/CONTRACT — THE CORRECTED DERIVATION (authoritative; prior memory was WRONG on key AND terminal table)
- **Key = `bd_packaging_v2.recipe_id_fk → ref_recipes.id`, discriminator = `ref_recipes.classification ENUM('Neb','Contract')`.**
- recipe_id_fk = **100% covered (2244/2244, ZERO orphans)** = authoritative key. sku_id_fk = only **89% (2000/2244)** → mis-buckets contract; NEVER use it for the split.
- **`ref_beer_types.recipe_id_fk` does NOT exist** (PM-confirmed live — ref_beer_types keyed by `beer_name`: cols id/beer_name/type/subtype/notes/vintage/sku_prefix/sku_code/…). Do NOT route the split through ref_beer_types. The OLD chain "sku_id_fk→ref_skus→ref_recipes→ref_beer_types.recipe_id_fk" was wrong on TWO counts (wrong key + nonexistent col).
- Reuse-filtered split (PM-verified live): **Neb 2004 ev / 53,903.05 HL ; Contract 240 ev / 4,983.23 HL** (Contract ≈ 8.5%, NOT the ~13% earlier mis-stated to operator).
- `ref_recipes.classification` distinct (whole table): **Neb 34 recipes, Contract 42 recipes.**
- **sku_id_fk-NULL is NOT a clean contract proxy:** the 244 NULL-sku rows = **240 Contract + 4 Neb** (PM-verified). The 4 Neb leak-ins are exactly why classification-via-recipe_id_fk is the ONLY safe split — sku_id_fk-NULL would mis-label 4 Neb events as contract. rm-retro-prereq-chain.md's "244 cuv == 244 sku-null contract" line conflates three different facts; keep cuv-run-type, sku-null, and Contract-classification distinct.
- Contract gets FULL treatment per operator: own per-format + per-month + quarterly views mirroring Nébuleuse.

## EXACT-SKU-CODE derivation (operator wants ZEPF/ZEP4/ZEPC… grain) — THE SAFE METHOD
**CRITICAL: run_type does NOT determine the SKU code.** One run_type maps to MANY codes: `bot` → ZEP4/ZEPB/ZEPBU/ZEP-X/ZEP4PB/ZEPB12; `can` → ZEPC/ZEP4C/ZEP6C/ZEP12C/ZEPCU/ZEP4PC; `keg` → ZEPF/ZEPP25/ZEPP50. So **`sku_prefix||run_type-suffix` CANNOT reconstruct the exact code** (collapses ZEP4≠ZEPB). REJECT run_type-composition AND neb_beer text-parsing for exact grain.
- **Canonical method = `bd_packaging_v2.sku_id_fk → ref_skus.sku_code` DIRECTLY.** `ref_skus` (PM-verified cols): `id, sku_code VARCHAR(16), recipe_id, beer_raw, format VARCHAR(16), format_id, unit_label, hl_per_unit DECIMAL(10,5), units_per_pack, is_active, is_packaging_line, is_direct_sales, bom_template_id, …`. `sku_code` IS the exact per-format-per-recipe code (ZEPF=Keg, ZEPV=Cuve de service, ZEPC=Can, ZEP4=Bot, ZEP6C=Can, ZEP12C=Can, ZEPBU=Bot, ZEPCU=Can, ZEPP25/ZEPP50=Keg, ZEP-X=Bot). **Join col is `ref_skus.recipe_id`** (NOT `recipe_id_fk`).
- **The 89% coverage hole is the Contract lane, NOT a Neb problem.** PM-verified: **Neb sku_id_fk coverage = 2000/2004 (99.8%)** — only **4 Neb rows** have NULL sku_id_fk (refuse-don't-NULL cases). The 240 Contract events have NULL sku_id_fk by design (no Nébuleuse SKU). So:
  - **Neb per-SKU KPI:** `sku_id_fk → ref_skus.sku_code` — near-complete. The 4 NULL-sku Neb rows → ReviewQueue/surface (refuse-don't-NULL), do NOT silently drop from the per-SKU chart.
  - **Contract per-SKU KPI:** no Nébuleuse sku_code exists; group Contract by `(recipe → ref_recipes.recipe_short_name/name)` × run_type-family, optionally finer via `ref_packaging_clients` (client_fk, cuv-gated). Label as contract-recipe×format, NOT a fabricated ZEP-style code.
- **DECISION FOR OPERATOR (flag it):** per-SKU exact grain is intrinsically a NEB concept (sku_code only exists for Neb). For Contract, "exact SKU" = contract-recipe × format-family. Confirm operator accepts that asymmetry — it's the honest model (never fabricate contract SKU codes; COGS-adjacent never-guess rule).

## run_type → format family (display only) — house convention, code-level, CLEAN 1:1
run_type distinct = **exactly bot/keg/cuv/can/can33 (5 values), NO `tank`, no can50.** PM-verified run_type→ref_skus.format is clean: bot→Bot(793), can→Can(195), can33→Can33(2), keg→Keg(766), cuv→Cuve de service(244).
| run_type | display family (FR) | ref_skus.format | volume note |
|---|---|---|---|
| `bot`    | Bouteille           | Bot | use `vendable_hl`, don't recompute |
| `can`    | Canette             | Can | |
| `can33`  | Canette             | Can33 | |
| `keg`    | Fût                 | Keg | 0.20 HL/keg |
| `cuv`    | Cuve de service     | Cuve de service | serving tank 5/10/30 HL |
- Keep the family map in ONE assoc array in `app/packaging-stats.php` — no scatter, no hardcoded ids. This is DISPLAY-family only; it is NOT the SKU-code key (that's sku_id_fk→sku_code).
- **Cuv(V) ≠ Keg(F):** distinct code (ZEPV vs ZEPF). Never collapse. fmt_suffix-BC-folds-to-C is a legacy-rawdb concern, largely irrelevant here since you read sku_code verbatim.
- **⚠️ OUTPUT-METRIC CORRECTION (operator 2026-06-01):** for "packaging OUTPUT / HL packaged" KPIs the basis is **GROSS PACKAGED = prod_total_units × format volume**, NOT `vendable_hl` (which is net of filling losses). The operator tracker IS gross. Cans confirm: gross prod-only 363.60 HL = operator exact; vendable 356.2 is net. So for OUTPUT you DO compute HL from prod_total × per-unit/format volume. Reserve `SUM(vendable_hl)` (reuse-filtered) for genuinely sellable-output / FG-valuation KPIs. **All 4 formats (bot/keg/can/cuv) reconcile EXACTLY to the operator tracker — same-source inputs, residual = bug** (bot 1,834.72 / keg 2,828.2 / can 363.60 / cuv 315.00 → total 5,341.52 HL gross; ALL FOUR confirmed EXACT 2026-06-01). Keg = all 20 L (no 30/50L — count gap, not volume mix). **✅ CUV RESOLVED — cuv already stores explicit litres: ZEPV/EMBV/MOOV `ref_skus` = `hl_per_unit=0.01, units_per_pack=1` → 1 unit = 1 litre, operator enters actual variable/partial litres per fill. So unit-basis is CORRECT for cuv (a unit IS a litre); the earlier "cuv needs explicit per-event fill-litres / unit-basis is wrong" note was a wrong assumption and is RETRACTED.** See INDEX §UNIFIED PARALLEL-RUN FIX (cuve correction).

## QA / losses derivations
- O2/CO2 = `bd_packaging_co2o2_measures` child table {co2_gl DECIMAL(6,3), **o2_ppb DECIMAL(8,2) (ppB not ppm!)**, measured_at, source, imported_at}. **EXTREMELY sparse: 11 rows / 3 events / all 2026 (PM-VERIFIED LIVE).** PREFER over legacy scalars. AVG over pairs in window, show `n=` denominators (honest partial coverage; never average NULL as 0; "—" when absent). Scope to bottle/can fills (keg/cuv don't carry these). NB the IN-TANK lot-day-read arc (index §IN-TANK CO₂/O₂, mig 240 planned) will re-anchor these per lot-day — if it lands first, derive on the anchor only (`tank_reading_session_id_fk IS NULL`).
- **Losses are tracked in UNITS, not HL** (`loss_kpi_hl` empty pre-2026 — do NOT rely on it for the % KPI). Operator-locked unit formula → see §KEY AS-BUILT FACTS #2 (the explicit Σ list of disposition *_units cols; QA/library samples + `loss_keg_liquid_l` EXCLUDED). % = losses_units / (prod_total_units + losses_units). 2026 ≈ 3.66%.
- **OUTPUT vs TAX-BASE vs FG vs LOSS (CORRECTED 2026-06-01):** "HL packaged / packaging OUTPUT" the operator means = **GROSS PACKAGED = prod_total_units × format vol** (NOT vendable_hl — prior line was wrong; cans gross 363.60 = operator exact, vendable 356.2 net of losses). FG HL = vendable only; beer-tax base = vendable+invendable+taproom−untaxed_full; loss KPI = the loss bucket. Label each KPI with its measure; OUTPUT defaults to GROSS.
- **8 NEGATIVE vendable_hl rows** (reuse-filtered, sum −48.15 HL) — likely corrections/credits. Show them, don't silently absorb; "incl. N correction rows" honesty note on HL totals.

## event_date / month / quarter grain
- event_date = DATE(submitted_at), 0 NULL (fixed mig 235). Month = `DATE_FORMAT(event_date,'%Y-%m')`; quarter = `CONCAT(YEAR,'-Q',QUARTER(event_date))`. Year filter via existing `pkg_year` scaffold.

## "Yearly output rhythm" KPI — intended semantic (PM reading)
Operator's "quarter + yearly output rhythm" in this house = **seasonality + YoY pace**, two views:
1. **Per-quarter HL bars grouped/colored by year** (Q1-Q4 × 2021-2026) — intra-year rhythm AND same-quarter YoY at a glance.
2. **Cumulative YTD HL line, current year vs prior year(s)** — "are we ahead of last year's pace?" 2026 partial (5,574 HL through ~May) reads against prior-year YTD-at-same-date.
- Default to (1)+(2); NOT rolling-12-mo unless operator asks (house framing = fiscal-year + quarter, year filter already scaffolded). Confirm if ambiguous.

## Atom sequence — 🟢 ALL DONE + LIVE + UNCOMMITTED (see §AS-BUILT STATE)
- **A1.1** ✅ corrective join fix; reconciliation gate PM-VERIFIED LIVE EXACTLY (Neb 2004/53,903.05 + Contract 240/4,983.23 = 2244/58,886.28, 0 orphans).
- **A2** ✅ KPIs 1-4 + new public/js/packaging.js, inline-SVG stacked bars, window.PKG_STATS hydration.
- **A3** ✅ current-week table + QA grid (co2o2, n= denominators). **A4** ✅ quarterly grouped bars YoY + YTD line (2026 +671 HL / +13.7%).

## Divergence flags specific to this build
- No v1 read; no parallel events table.
- vendable_hl computed-on-write; trust stored col, not a recompute; not the view (244-NULL drift).
- **Neb/Contract ONLY via recipe_id_fk→classification.** sku_id_fk is for the SKU CODE, never the split (sku-null leaks 4 Neb into contract).
- **Per-SKU code via sku_id_fk→ref_skus.sku_code (join col = ref_skus.recipe_id, NOT recipe_id_fk).** Never reconstruct sku_code from run_type (collapses distinct codes).
- The 4 NULL-sku Neb rows → surface/ReviewQueue, never silent blank/guess (COGS-adjacent).
- cuv(V) ≠ keg(F): distinct code, never collapse.
- cuv-run-type ≠ Contract-classification — overlapping but distinct facts.
- Collation: bd_* = utf8mb4_0900_ai_ci vs ref_* = utf8mb4_unicode_ci — explicit COLLATE on any STRING join; id-FK joins (recipe_id_fk, sku_id_fk) integer = safe.
