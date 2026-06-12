# Financier — Fiche COGS (variation de stock) build

> Read when a task touches the Financier page (`public/modules/financier.php`), the Fiche COGS / variation-de-stock tab, the `cogs_fiche_*` / `ref_cogs_fiche_categories` tables, or the monthly-compile engine `scripts/cogs-monthly-compile.ts` (maltytask repo). Verified live 2026-06-11.

## ✅ STATUS HEADLINE (2026-06-12 — MAY CLOSE FULLY FINALIZED: SHIPPED + RATIFIED + VERIFIED + COMMITTED + PUSHED)
**The financier-fiche arc keystone is CLOSED.** May 2026 COGS-fiche close is done end-to-end. **Engine COMMITTED + PUSHED — `423a660 feat(cogs): finalize May-2026 close — RM unit-consistency diagnostic + basis-change line` is HEAD on maltytask `main`; `origin/main..HEAD` empty; working tree clean.** (Correction to a prior brief that said the engine was uncommitted at base `fcad639` — a concurrent session committed + pushed it; the deferred commit is DONE, nothing left to commit.) The `cogs_fiche_monthly` May rows (written 06:45 2026-06-12) are **RATIFIED BY KOUROS and INDEPENDENTLY VERIFIED by Opus** (gate), all checks PASS.
- **May headline (DB, published, verified):** RM **282 368.74** · WIP **21 814.28** · FG **47 701.56** · TOTAL **351 884.58** · OPENING **394 367.74** · VARIATION **−42 483.16** · BASIS_ADJ **+1 808.82**. 12 rows. (rm+wip+fg=total ✓; total−opening=variation ✓.)
- **Opus verification record (all PASS):** (1) 12 rows, rm+wip+fg=351 884.58=total ✓; (2) total−opening=−42 483.16=variation ✓; (3) **April-seed continuity EXACT — April seed SUM(total_chf)=394 367.74 == May opening ✓ (the key fiscal invariant)**; (4) Yeast RM=1 496.03, g→kg hardening held (engine diagnostics label naive=CORRECT / conv=WRONG; 27 unit-mismatch items SURFACED as diagnostics, NOT applied) ✓; (5) **engine dry-run reproduces the published DB rows TO THE CENT → HEAD engine `423a660` == what's published, no drift** ✓; (6) basis adjustment +1 808.82 = FG/F2 restatement ONLY (3 pure-RM categories correctly carry 0) ✓.
- **Noted to Kouros (NOT a blocker):** engine May valuation diverges from legacy EXT (RM ≈ −47k vs EXT) — EXPECTED; engine supersedes EXT. Correctness rests on the April-seed tie-out + internal identities, NOT on EXT match.
- **basis_adjustment is COMPUTED BY THE ENGINE, not an operator input:** `computeBasisAdjustment()` revalues April FG at CURRENT ref_sku_bom cost − seeded April fg_chf; FG/F2 portion only, RM/yeast = "non isolable — base héritée". The +1 808.82 is an OUTPUT; no CLI flag / config row for it.
- **g→kg/loadRM hardening is DONE in-engine:** RM flows through `loadRmStock` (lib/rm-stock-mysql.js); formula = `finalQty × costChf` (NO conversionFactor — finalQty already in pricing unit); SANITY GUARD throws if Yeast RM ≥ 50 000 (catches conversionFactor creep); 27 unit-mismatch items = diagnostics only.
- **B4/B5 financier UI + Saisies PF card = COMMITTED** maltyweb `ad9c14b`.
- **⏭ NEXT MONTHLY CLOSE = JUNE** — gated on June FG census at month-end (operator enters via Saisies → PF card → `expeditions.php?view=stocktake`). Then engine `--apply 2026-06`, reconcile (opening = May close 351 884.58), auto-publishes on the Fiche tab. Closed-periods-never-recomputed remains standing policy — May is now immutable.

## ⚡ (SUPERSEDED — May close now FULLY FINALIZED, see headline above) STATUS HEADLINE (2026-06-11) — awaiting operator May FG census then git commit
Full pipeline is LIVE on the VPS (deployed, NOT yet git-committed — awaiting Kouros's go). Migs 330 + 332 applied. Fiche COGS tab (5th tab) renders April, ties to the cent, smoke-tested 13/14 on app.maltytask.ch. Engine built + May dry-run validated. **Only remaining work is operator-gated:** Kouros enters the May-31 FG census via the now-surfaced stocktake form → then finalize `basis_adjustment_chf`, run engine `--apply` for 2026-05, reconcile, publish. Detail below.

## Page placement (Le Zeppelin IA)
- **`financier.php` = "Pôle Financier", `ref_pages` id=239, page_key=`financier`, label "Financier", `min_role='manager'`, `domain='general'`, is_active=1.** Belongs to **Le Cockpit** (commercial/financial family). Read-only fiscal hub — NEVER recalculates fiscal numbers; consumes pre-computed JSON artifacts (`interfaces/cogs-report-data.json` COP, `interfaces/sales-cogs-data.json` COGS/Marge) + lazy `/api/financier-data.php`.

## Page architecture (verified 2026-06-11)
- **Body class:** `<body class="home financier">`. CSS scoped `body.financier …`.
- **CSS file:** `public/css/financier.css` (linked at financier.php:330 with `?v=filemtime`). app.css linked first (line 329). NO inline CSS — add Fiche styles HERE.
- **JS:** `public/js/financier.js` (+ `kpi-charts.js`). Linked at bottom with `?v=filemtime`.
- **Hydration = PHP→window.JSON→JS** (NOT server-side rendered tables; server pre-renders panel shells + a default slice, JS hydrates). `window.FIN_COP`, `FIN_COP_MONTHS`, `FIN_SALES_TREND`, `FIN_SALES_MONTHS`, `FIN_SALES_DEFAULT_SLICE`, `FIN_GL_MONTHS` etc. injected in a `<script>` block (financier.php:886+) with `$JSON_FLAGS`. Heavy per-SKU detail is lazy-fetched per month via the API endpoint. Follow this pattern for the Fiche: inject seed/monthly series as `window.FIN_FICHE_*`, render in financier.js.

## Tab pattern (verified — 4 tabs today)
- `<nav class="fin-tabs" role="tablist">` with `.fin-tab` buttons carrying `data-tab="<key>"`, `aria-controls="fin-panel-<key>"`, `id="fin-tab-<key>"`, `aria-selected`. Active = `.fin-tab--active`.
- Panels: `<section class="fin-panel" id="fin-panel-<key>" hidden role="tabpanel" aria-labelledby="fin-tab-<key>">`. First panel = `.fin-panel--active` (not hidden).
- **Existing 4 tabs:** `cop` (default/active), `cogs`, `marge`, `sku`. (`sku` = "Coût par SKU", absorbed the retired standalone sku-costs.php per scrapping #32.)
- **Tab-switch JS (financier.js ~L505):** ONE delegated loop over `.fin-tab` — on click toggles `.fin-tab--active` + aria-selected on tabs, then `p.hidden = (p.id !== 'fin-panel-'+target)` + `.fin-panel--active` on panels. Plus a `keydown` arrow-nav handler (~L527). **A 5th "Fiche" tab = add the button + panel + reuse this exact loop (no JS rewrite needed).**
- **Reusable table classes:** `.fin-table` (+ `.fin-table-scroll` wrapper), `.fin-grid-table .fin-table-compact` (+ `.fin-grid-scroll`) for the GL accounting views. Reuse these for the Fiche table — do NOT invent new table classes.

## CSV export convention (app-wide)
- **Comma separator, double-quote enclosure:** `fputcsv($out, [...], ',', '"')`. Canonical exporter = `public/modules/warehouse-export.php` (GL recap CSV, `Content-Type: text/csv; charset=utf-8`, filename `…-{period}-{today}.csv`). financier.php has NO CSV export yet — if Fiche adds one, mirror warehouse-export.php (comma + double-quote, NOT semicolon). NB: `charges-bc.php` only *ingests* CSV (accept=".csv"), doesn't export.

## Tables (LIVE — migs 330 + 332 APPLIED on VPS 2026-06-11; applied=339, 0 pending)
**Correction to stale index note:** mig 332 was reported "NOT applied" when a parallel dashboard commit (`fb2778a`) swept it in — it is now APPLIED. Do NOT re-create.

### `ref_cogs_fiche_categories` (mig 330) — 12 rows, the fiche category master
`id`(PK uint), `category_key`(varchar32 UNIQUE), `label_fr`(varchar64), `inv_gl`(varchar16), `charge_gl`(varchar16), `display_order`(smallint, MUL), `is_brewing`(tinyint def 0), `is_active`(tinyint def 1), created_at, updated_at.
12 categories in display order: Malt(1200.1/4101,brewing) · Hops(1200.2/4102,brewing) · Ingredients/Ingrédients(1200.3/4104,brewing) · Yeast/Levures(1200.4/4103,brewing) · Cartons(1201.1/4207) · Inliner(1201.2/4205) · Capsules(1201.3/4200) · Verres(1203.0/6607) · Bouteilles(1204/4200) · Etiquettes(1205/4206) · Canettes(1207/4202) · CapsFuts(1221/4209). First 4 `is_brewing=1`, rest packaging.

### `cogs_fiche_seed` (mig 332) — 12 rows = the EXT-April-signed anchor (one per category for month 2026-04)
`id`, `month_key`(char7 MUL), `category_key`(varchar32 MUL), `rm_chf` `wip_chf` `fg_chf`(**DECIMAL(14,4) — chosen for cent-perfect sums**), `total_chf`, `opening_chf`, `variation_chf`, `seed_source`(varchar64 def 'EXT-April-signed'), `seeded_at`. **This is the immutable April-2026 signed anchor (opening of May = closing of April).** e.g. Malt total 54351.88 / opening 50604 / variation +3747.88. schema_meta: `cogs_fiche_seed` = **reference / allowed**.
- **April seed TIE-OUT PASSED to the cent** (cross-read against EXT): ΣJ (Total Inventaire) = 394 367.74 · ΣK = 431 354.00 · ΣL (Variation) = −36 986.26 · ΣRM = 329 852.08 · ΣWIP = 22 364.27 · ΣFG = 42 151.39. The EXT cross-read caught a −356.625 half-rounding on Levures — DECIMAL(14,4) is why it ties.

### `cogs_fiche_monthly` (mig 332) — 0 rows (EMPTY until May `--apply`)
Same shape as seed + `basis_adjustment_chf`(decimal14,4 NULL), `computed_at`(datetime), `row_hash`(char64 UNIQUE — idempotency key). Computed per-month per-category variation-de-stock rows land here. schema_meta: `cogs_fiche_monthly` = **derived / blocked_with_redirect**, writer = `cogs-monthly-compile.ts`. **Currently EMPTY — the May `--apply` is the first row-write into it; the Fiche tab reads seed (April) ∪ monthly (May onward).**

### mig 332 provenance notes (don't re-derive)
- **mig 332 was RENUMBERED from 331** — a concurrent `331_kpi_grouped_bar_viztype.sql` (the MON TABLEAU #5 grouped-bar work) had already taken 331. The file on disk is `332_cogs_fiche_seed_and_monthly.sql`.
- **mig 332 DROPPED `cogs_fiche_opening_anchor`** (a table mig 330 had created) and removed its `schema_meta` row — the opening is now resolved live by UNION(seed ∪ monthly), so a separate anchor table was a redundant parallel store. Do NOT re-create `cogs_fiche_opening_anchor`.

## Engine — `scripts/cogs-monthly-compile.ts` (maltytask repo, TS) — ✅ COMMITTED + PUSHED `423a660`, May `--apply` LANDED + VERIFIED
Computes RM/WIP/FG per category → `cogs_fiche_monthly`. Guards:
- **Opening resolver reads UNION(seed ∪ monthly)** — opening of month N = closing of N-1 from whichever home holds it (April from seed, May+ from monthly).
- **Immutability guard:** refuses to recompute ANY month present in `cogs_fiche_seed` (closed-periods-never-recomputed = standing policy).
- **FG-MISSING guard:** refuses to fabricate a zero close when the FG census for the month is absent (no fabricated zero close).
- **May dry-run validated:** RM Yeast = 1 496 (sane — **confirms the earlier g→kg yeast blowup is a CLOSED-PERIOD-ONLY artifact**, not live), WIP computes, opening resolves from the April seed.
- **✅ UNIT-INVARIANT HARDENED (was LATENT):** engine now flows RM through `loadRmStock` (lib/rm-stock-mysql.js), formula `finalQty × costChf` (finalQty already in pricing unit, NO conversionFactor), plus a sanity guard throwing if Yeast RM ≥ 50 000 and a unit-consistency DIAGNOSTIC (27 mismatch items surfaced, labelled naive=CORRECT/conv=WRONG, NOT applied). Verified May: Yeast RM=1 496.03. Resolved in `423a660`.

## Build verdict / sequencing for the Fiche tab
- The Fiche tab is render-only over `cogs_fiche_seed` + `cogs_fiche_monthly`; both are canonical single-home tables, no parallel store. SOUND.
- **GATE:** `cogs_fiche_monthly` is EMPTY. A tab built today shows only April seed. Confirm with the operator whether the monthly-compute pipeline lands first (so the tab has >1 month) or the tab ships seed-only as a v1. Don't fabricate May rows.
- Categories/GLs come from `ref_cogs_fiche_categories` — JOIN, never hardcode the 12 labels or GLs.
- EQUIP ui+sql+coder (+webapp-testing for deployed-page smoke).

## ✅ B4 + B5 SHIPPED + DEPLOYED + SMOKE-TESTED (2026-06-11) — deployed not yet git-committed
**Tasks B4 (Finance fiche tab) + B5 (CSV download) DONE, on the VPS, smoke-tested 13/14 on app.maltytask.ch.** April renders, ties to 394 367.74 / −36 986.26, 12 rows + GLs, provenance chip ("Clôture signée (référence)" vs "Calculé"), incomplete-month banner. **Two bugs found + fixed during smoke:** (1) CSV 500 — duplicate `:month` named param under native prepares → fixed; (2) false incomplete-banner on seed months → now gated on `provenance ≠ seed`. **NOT yet git-committed — awaiting Kouros's go.** 4 files:
- **`public/modules/financier.php`** (edited): L199 `fin_fmt_chf(float):string` helper (number_format, space thousands, 2 dec). L323–391 Fiche PDO data block (UNION over `cogs_fiche_seed` ∪ `cogs_fiche_monthly` × `ref_cogs_fiche_categories`) → `$ficheMonths/$ficheData/$ficheTotals/$ficheLatestKey/$ficheIsIncomplete/$ficheProvenance/$ficheBasisRows`. L448–452 5th nav tab button `data-tab="fiche"` (reuses the existing delegated tab loop — no JS rewrite, as the pattern predicted). L919+ `#fin-panel-fiche` section **PHP-rendered table PER MONTH, server-side, NO JS recompute** (`hidden` attr correct). L1095–1101 7 `window.FIN_FICHE_*` globals injected.
- **`public/js/financier.js`** (edited): L1579+ Module E IIFE — month switcher (show/hide `.fin-fiche-month-block`), provenance-chip update, incomplete-month warning, CSV href update on select change. **Switcher only — table itself is server-rendered, NOT hydrated.**
- **`public/css/financier.css`** (edited): ~120 lines before `@media` — `.fin-fiche-controls`, `.fin-fiche-provenance-chip` (seed/computed variants), `.fin-fiche-csv-btn`, `.fin-fiche-warn`, column sizing, variation coloring, total row, basis row.
- **`public/api/cogs-fiche-csv.php`** (NEW): auth-gated `require_page_access('financier')`, validates `?month=YYYY-MM` against actual DB months, **re-queries server-side (never echoes client floats)**, UTF-8 BOM for Excel, comma+double-quote CSV (mirrors warehouse-export convention as documented), totals + basis-adjustment rows.
- Both PHP files pass `php -l` (VPS PHP) AND deployed-page smoke (13/14) — done.
- **April 2026 anchor totals confirmed from `cogs_fiche_seed`:** Valeur Stock (RM) 329 852.08 / Total Inventaire 394 367.74 / Variation Stock −36 986.26 CHF.
- **Architecture verdict: SOUND.** Render-only over the two canonical single-home tables; CSV re-queries server-side (no float-laundering); categories JOINed not hardcoded; tab reuses delegated loop. As-built matches the documented pattern.

## ✅ Saisies entry-point — 6th card "Inventaire produits finis (PF)" (2026-06-11, deployed + smoke-tested)
New card on the Saisies page → `expeditions.php?view=stocktake` (operator-gated). This surfaces the FG-census stocktake form the operator needs to enter the May-31 count. Deployed + smoke-tested. (This is the form Kouros uses to unblock the May publish below.)

## Kouros ratifications (2026-06-11) — standing decisions
- (a) **Seed April from EXT + engine proves-on-May** (don't recompute April; prove the engine by recomputing May from April opening).
- (b) The one-time WAC/F2 basis change is shown as a **separate "Ajustement de base (non récurrent)"** line (= `basis_adjustment_chf`), not folded into the recurring variation.
- (c) **Closed-periods-never-recomputed is standing policy** (mirrored by the engine's immutability guard).

## ✅ DONE (was "STILL PENDING") — May close finalized 2026-06-12
May-31 FG census entered (`inv_fg_stocktake` month_closed=2026-05, 34 rows); `basis_adjustment_chf` = +1 808.82 (FG/F2; RM/yeast = "non isolable — base héritée"); engine `--apply 2026-05` landed 12 rows into `cogs_fiche_monthly`; reconciled (opening=April seed 394 367.74); published on the Fiche tab; engine committed+pushed `423a660`. **Next monthly close = JUNE (gated on June FG census at month-end) — see headline.**

## 🎫 TOUR (PM tour-steward standing duty)
`scripts/tour-gap-check.php` flags `financier` + `journal-saisies` as **pre-existing CRITICAL gaps** (NOT caused by this arc's changes — both lacked tour cards before). The new **Fiche COGS tab may want a tour card** — note for the next `maltyweb-tour-steward` dispatch. financier is a sensitive class (COGS/COP) → steward STOPS pre-deploy + returns `PM-RATIFY: financier` for PM ratification.
