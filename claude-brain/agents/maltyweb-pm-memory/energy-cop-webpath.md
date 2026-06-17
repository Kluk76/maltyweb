# Energy / Utilities COP web-path — SHIPPED + LIVE + Opus-verified 2026-06-17

> Created 2026-06-17 as the locked build brief; **SHIPPED+LIVE+Opus-verified same day**. As-built below; the original brief follows for provenance. Kouros built BOTH (a) monthly meter-reading saisie + (b) PHP-native estimator into the financier COP, together.

## ✅ AS-BUILT (2026-06-17, LIVE on app.maltytask.ch, Opus-verified)

🔴 **Shipped as mig 396, NOT 395** (the brief said 395 — parallel sessions moved the number; HEAD also past 392). File `396_energy_cop_webpath_foundations.sql`.

**Mig 396 — APPLIED LIVE.** Created+seeded:
- `ref_utility_tariffs` — 1 row, JSON `tariff_json`, effective_from 2026-01-01, byte-faithful from `data/utility-tariffs.json` versions[0]. + schema_meta.
- `ops_utility_closures` — 31 rows seeded from `data/utility-closures.json` (5 `actual-invoice` + 26 `rolling-at-closure`). + schema_meta.
- `ref_pages` page_key `saisie-energie`, label **'Index énergie'**, icon ⚡, min_role **manager**, domain **general**, category **finance**, sort 128 / category_sort 40. Granted to **manager(id=1)** + **finance_viewer(id=10)** presets (required ship step — preset-CANNOT-bypass-floor, but these are preset grants so page is visible to those preset-holders).
- **DECISION LOCKED: source ENUM stays `('invoice','estimate')`** — manual = `source='estimate'` + `last_modified_by='web'`, NO new ENUM value (as ratified in brief). Tariffs canonicalized to the MySQL table (rejected JSON-on-VPS + system_settings).

**② saisie-energie page — LIVE.** `public/modules/saisie-energie.php` + `public/css/saisie-energie.css` + `public/js/saisie-energie.js`. Captures the 4 CUMULATIVE meter indexes (eau m³, gaz m³, élec jour/HP, élec nuit/HC) → upsert `inv_energydata` on `period` UNIQUE, `source='estimate'`/`last_modified_by='web'`, COALESCE-preserves blank fields + peak_kw/reactive_kvarh (absent from the write). **Refuse-with-notice if existing row source='invoice'** (PM rec adopted — invoice/real wins over manual/estimate). row_hash recipe = `sha256("manual-meter|{period}|{eau}|{gaz}|{jour}|{nuit}")` — PREFIXED, zero collision with ingest hashes. UX: prev-month index hint + live JS delta per field; read-only history table w/ source badge + MoM delta + soft warn on negative/outlier delta. Helpers (all real): require_page_access, current_user, csrf_token/csrf_verify, log_revision, flash_set/pop, redirect_to, maltytask_pdo. 🔴 NO snapshot helper exists for `inv_energydata` → `log_revision` ONLY (acceptable — append-trace via revisions).

**③ estimator — LIVE.** `app/utilities-estimate.php` → `utilities_estimate_month(PDO,$monthKey,$readonly=true)`, ports `lib/utilities.js` 1:1 (computeConsumption ×10.6079 gas m³ + ×15 elec, computeGas/Water/Electricity, resolvePeakKW 4-branch reading/writing `ops_utility_closures`, toByMonthHT). r2 rounding via `floor($n*100+0.5)/100` to match JS Math.round. closedMonths derived live = all `ops_utility_closures` periods. Readonly flag gates the snapshot UPSERT (harness ran readonly).

**④ PARITY GATE PASSED — max abs delta 0.0000 CHF** across 5 months (2025-12, 2026-01..04) × 3 components (gas/waterSewage/electricity) PHP vs Node. Canary 2026-02 gas 4968 m³ ×10.6079=52699.83 kWh→7509.92 CHF confirmed. **Opus independently re-ran the PHP estimator on VPS — matched.**

**④ financier switch — LIVE + logged-in-smoke-verified.** `financier-data.php` (`fin_cop_operational_month/_ytd` gained `?PDO` param; override glLines 4700←gas+water HT / 4702←electricity HT via new `_fin_cop_try_estimate`; 4701 waste + `fin_cop_booked_totals` UNTOUCHED) + `financier.php` (utilities.total ← `fin_utilities_live_estimate` w/ JSON fallback). Live smoke: COP **April 2026 = 4700 8615.93 / 4702 4932.14 / total 13548.07 / 4701 0** — exactly the estimator; **May 2026 = 0** (no readings yet, correct). Replaces the dead stale JSON (`cogs-report-data.json` utilities.total was 0 for 2026-05/06).

### 🔴 DATA-GAP FINDING (follow-up, NOT a migration defect; PRE-EXISTING — Node does identical, parity 0.0000 proves it)
Closed months 2026-01/02/03 have `actual-invoice` snapshots in `ops_utility_closures` (peak 63/70.5/72) but their `inv_energydata` rows LACK peak_kw (only 2026-04 has peak_kw=66 on the row). `resolvePeakKW` branch-1 keys on the ROW's col-G peak_kw — an actual-invoice closure whose row lost peak_kw is IGNORED → recomputes rolling mean (=66, the only populated peak) → those 3 closed months use peak 66 instead of their real invoiced peaks → electricity off ~30-60 CHF/month (~1%). **Recommended fix = backfill `inv_energydata.peak_kw` for 2026-01/02/03 from the seeded closures** (then branch-1 honors them). 🔴 **Awaiting Kouros decision.**

## 🔴 FOLLOW-UP ARC (scoped 2026-06-17, NOT built) — (A) audit-breakdown panel + (B) estimate must ENTER COGS surfaces

> Kouros's two new requirements: **(A)** show the FULL calculation breakdown of the anticipated energy cost on the saisie-energie page (operator must trace index_now−index_prev → conso → ×coeff → ×rate → CHF, line by line). **(B)** the anticipated estimate must ENTER THE COGS figures (not just the financier COP tiles) until real SIE/SIL invoices arrive.

### (B) — THE COGS-SURFACE MAP (verified live 2026-06-17)
"COGS" is represented by these surfaces. Verdict per surface:
| Surface | File | Reads utilities from | Live estimate? | Action |
|---|---|---|---|---|
| **Financier COP board** (on-screen tiles) | `public/api/financier-data.php` (`_fin_cop_try_estimate`→`utilities_estimate_month`, overrides glLines 4700/4702) + `public/modules/financier.php` (`fin_utilities_live_estimate`) | LIVE estimator | ✅ DONE | none |
| **COGS-complet comprehensive CSV §3 COP** | `public/api/cogs-comprehensive-csv.php` L127-141 `$cop = $monthEntry['cop']` from `cogs-report-data.json`; L292/317/333 emits 'utilities' from `$cop` | **STALE JSON (utilities=0 for 2026-05/06)** | ❌ NO → **UNDER-REPORTS COP** | **FIX (B-core): replace the §3 utilities line (and the COP grand-total) with `utilities_estimate_month($pdo,$month,true)` gas+water HT (4700) / elec HT (4702), mirroring `_fin_cop_try_estimate`. The other §3 COP sections (brewing/packaging/indirect/rd) still come from the JSON — leave them; only utilities is the energy web-path's concern.** |
| **KPI handlers COP tiles** (`kpi_handler_utilities`, COP-section KPIs) | `app/kpi-handlers.php` L950 `cogs-report-data.json`; L1220 sections incl. 'utilities' | **STALE JSON** | ❌ NO | **FIX (B-secondary): point `kpi_handler_utilities` (+ any COP-total KPI that sums 'utilities') at `utilities_estimate_month`. Lower priority than the CSV — KPI tiles are operational viz, the CSV is the CFO fiscal export. Confirm scope w/ Opus.** |
| **COGS FICHE** (`cogs_fiche_*`) | `app/cogs-fiche-compute.php` L77-80 EXPLICITLY EXCLUDES category 'utilities'→null | n/a | ✅ correctly EXCLUDED | **NONE — utilities does NOT belong in the fiche.** Fiche = RM+WIP+FG inventory-variation. Utilities is a period charge, never capitalized into stock. Confirmed: WIP = `inv_tank_balances × brew_cost_per_hl × liquid-BOM proportion`, distributed only over Malt/Hops/Ingredients/Yeast; utilities excluded at L174 of `cogs-monthly-compile.ts`. |
| **WIP/FG valuation via brew_cost_per_hl** | `ref_beer_types.brew_cost_per_hl` (BSF col BREW_COST=17, seed-ref-beer-types.ts L115) = MATERIAL ingredient cost/HL ONLY | n/a | ✅ utilities NOT in it | **NONE — energy is NOT capitalized into inventory valuation.** The per-HL brewing cost that values WIP/FG is ingredient material only; utilities never feeds it. So the estimate does NOT touch inventory valuation — purely the period COP expense surfaces (financier + CSV + KPI). |
| **Sealed/snapshot COGS** (`cogs_fiche_sealed`) | fiche seal — same exclusion as fiche | n/a | ✅ excluded | NONE |
| **per-SKU COGS / sku-cost** | `app/sku-bom-compile.php` | BOM material only, no utilities | ✅ N/A | NONE — per-SKU is material BOM, utilities is a plant-level period charge not allocated per SKU in this model |

**NET: only TWO surfaces under-report and need the estimate wired: (1) cogs-comprehensive-csv.php §3 COP [primary — CFO fiscal export], (2) kpi-handlers utilities COP tiles [secondary]. The fiche / WIP-FG / per-SKU correctly do NOT carry utilities — energy is a period COP charge, never inventory-capitalized.** This is consistent with the COGS-VARIABLE = COP definition (utilities is a COP section) and with the fiche being inventory-variation-only.

### (B) ARCHITECTURE DECISION — RATIFIED
Choose **(i) point each PHP COGS consumer at `utilities_estimate_month` live** (NOT (ii) materialize a table, NOT (iii) keep Node). Rationale:
- `inv_energydata` × `ref_utility_tariffs` ARE the single source already; `utilities_estimate_month` is a PURE function over them. Materializing a monthly-cost table (ii) would create a SECOND derived store that can drift from the live compute = the divergence smell we forbid. The estimator IS the single derivation; every consumer calls it.
- "Invoice actuals override estimate" is preserved structurally — it lives in the DATA (`source` flips to 'invoice', peak_kw/reactive populate; `resolvePeakKW` prefers actuals; `ops_utility_closures` freezes closed months). Calling the live estimator everywhere inherits that automatically. A materialized table would need re-materialization on every invoice landing = more drift surface.
- Closed-month immutability: the closure snapshot already freezes peak/reactive; the estimator is deterministic for a closed month. (NB v1 estimate-stands; literal-invoiced-CHF override is the deferred v2.)
- Recompute cost is trivial (a few small SELECTs) — no perf reason to materialize.
- **Reuse the EXISTING `_fin_cop_try_estimate` shape** (financier-data.php L1047): it already returns `{gas_water, electricity}` HT and gracefully returns null when no tariff/no readings. Extract it to a SHARED helper (e.g. in `app/utilities-estimate.php`: `utilities_cop_ht_for_month($pdo,$month): ?array`) so the CSV + KPI handlers + financier all call ONE accessor — DO NOT inline-copy the override logic into the CSV (canonical-accessor-call-not-copy rule). When the Node COP pipeline is later retired, all three already read live.

### (A) — AUDIT-BREAKDOWN PANEL design (saisie-energie.php)
- `utilities_estimate_month($pdo,$month,true)` ALREADY returns per-component `breakdown` arrays (gas/water/electricity) + consumption deltas + peakKW + peakSource + total — so (A) is a RENDER task over data the estimator already emits; NO new compute.
- **Placement: inline collapsible panel** on saisie-energie.php, per selected/most-recent month, read-only — house pattern is a collapsible `<details>`/accordion section under the history table (NOT a modal; this is reference-audit content the operator scans, not a transient dialog). Reuse the `ui` skill's aged-oak/kraft fiche styling; CSS in `public/css/saisie-energie.css`, NO inline.
- **What to show (genuinely auditable, line by line):**
  1. **Consumption derivation** per meter: `index_now − index_prev = raw delta` → `× coefficient (gas ×10.6079 kWh/m³, elec ×15, water ×1)` → `consumption in kWh / m³`. Show the coefficient explicitly so the operator sees WHY a 4968 m³ gas delta becomes 52 699.83 kWh.
  2. **Per-component tariff line items** from the `breakdown` array: each tariff line (energy charge, grid/network, taxes, the split water TVA 2.6%/8.1%, the TVA-exempt cantonal/communal elec lines) with its rate and CHF subtotal.
  3. **HT subtotals** per component (gas HT, waterSewage HT, electricity HT).
  4. **peakKW + its source label** (peakSource: actual-invoice / frozen-snapshot / rolling-mean / fallback) — so the operator sees which peak basis fed the electricity demand charge. (This visibly exposes the DATA-GAP above: months showing peakSource='rolling-mean' where an invoice peak exists are the backfill candidates.)
  5. **Grand total HT** = gas.ht + waterSewage.ht + electricity.ht (the exact number that flows to COP) — label it "Coût énergie anticipé (HT) — entre dans le COP/COGS jusqu'à la facture".
- Make the ×coefficient and ×rate steps VISIBLE arithmetic, not just the final CHF — that is the whole point ("on peut toujours contrôler comment tu arrives à la valeur").

### EQUIP + SEQUENCE (follow-up arc)
- **(A) breakdown panel:** EQUIP `ui` + `coder` (+ `webapp-testing` smoke). Pure render over existing estimator output. Ship independently; no migration. RULE 2 review before commit.
- **(B) CSV §3 + KPI utilities → live estimate:** EQUIP `coder` + `sql` (+ `webapp-testing` smoke of the CSV download + a COP KPI tile). 🔴 **COGS-BEARING — Opus parity-verifies independently:** before the new CSV/KPI utilities number reaches the operator, Opus hand-confirms the §3 utilities figure for a month WITH readings (e.g. April 2026 = gas+water HT 8615.93 / elec 4932.14 / total 13548.07 — the live financier already shows these; the CSV must now match) AND a month WITHOUT readings (May 2026 = 0, must be 0 not stale-JSON-noise). Before/after invariant: financier COP utilities == CSV §3 utilities == KPI utilities for the same month.
- **Strict sequence:** (B) FIRST extract the shared accessor `utilities_cop_ht_for_month` (refactor `_fin_cop_try_estimate` to call it — no behaviour change, financier stays green) → wire CSV §3 → wire KPI handlers → Opus parity-verify. (A) can ship in PARALLEL (independent render). Both gated behind the still-pending **git commit of the SHIPPED energy work** (commit that first so the tree is clean-ish; PATHSPEC only).
- 🔴 Confirm the comprehensive CSV's COP grand-total line (`'TOTAL COP (variables)'`, L406) re-sums from the live utilities, not the stale JSON total.

### STILL OPEN (from the SHIPPED arc)
- (a) **git commit pending** — work is DEPLOYED but UNCOMMITTED in BOTH repos: maltyweb mig396 + saisie-energie.{php,css,js} + app/utilities-estimate.php + financier.php + financier-data.php. Commit by PATHSPEC (shared dirty tree — parallel sessions; never `-A`, never bare `git commit`). 🔴 financier-data.php lives at `public/api/financier-data.php` (NOT `app/` as an earlier index banner said).
- (b) **tour-steward card for saisie-energie** (RULE 3 — migrate hook flagged the gap) — HELD until commit (steward won't deploy on a dirty tree). PM to dispatch maltyweb-tour-steward (manager-visible, domain=general → needs a card; non-sensitive → steward can auto-deploy once tree clean).
- (c) **peak_kw backfill decision** (above).
- (d) **RULE 1 scrap-log:** `data/utility-tariffs.json` + `data/utility-closures.json` now superseded-for-web-path (Node still reads them until COP pipeline retired). Already gated "Node COP pipeline retired" — confirm logged in scrapping-backlog.md.

---

# (ORIGINAL BUILD BRIEF — provenance) Energy / Utilities COP web-path — LOCKED BUILD DIRECTION

> Created 2026-06-17. Kouros DECIDED: build BOTH (a) monthly meter-reading saisie + (b) PHP-native anticipated-energy estimator into the financier COP, together. This file holds the ratified architecture + the concrete build brief. Verify against live DB at build-start (`migrate.php --status`, `SHOW COLUMNS FROM inv_energydata`).

## THE GAP (audit, 2026-06-17)
- Energy COP is computed **off-VPS** by Node `lib/utilities.js` → `build-cogs-report.js` → `interfaces/cogs-report-data.json`.
- Financier reads `$cop['utilities']['total']` from that JSON artifact (`public/modules/financier.php` L135; path `/var/www/maltytask/interfaces/cogs-report-data.json`). **Artifact was STALE (Jun 10) at audit.**
- NO PHP utilities compute exists anywhere on the VPS (grep `computeGasCost` etc. = 0 hits).
- NO meter-reading input surface in the app — `inv_energydata` is written only by `ingest-sie-invoice` (SIE/SIL invoices) via the validation-gate `--commit` path.

## CANONICAL DB FACTS (verified live 2026-06-17)
`inv_energydata` (period CHAR(7) UNIQUE 'YYYY-MM'; one row per month):
- `eau_m3` DEC(14,3), `gaz_kwh` DEC(14,3), `elec_jour_kwh` DEC(14,3), `elec_nuit_kwh` DEC(14,3) — **ALL CUMULATIVE METER INDEXES** (monotone increasing; consumption[M] = index[M] − index[M−1]).
- `peak_kw` DEC(10,3) NULL, `reactive_kvarh` DEC(12,3) NULL — **invoice-only** (SIE), NOT in the manual form.
- `source` ENUM('invoice','estimate') NOT NULL DEFAULT 'invoice'.
- `row_hash` CHAR(64) UNIQUE NOT NULL.
- `last_modified_by` ENUM('ingest','web') NOT NULL DEFAULT 'ingest'.
- created_at / updated_at.

### 🔴🔴 CRITICAL UNIT TRAP — column names LIE about storage
Despite the `gaz_kwh` / `*_kwh` column NAMES, `lib/utilities.js` stores+reads **RAW METER INDEXES**, not kWh:
- `eau_m3` = m³ (×1, genuine).
- `gaz_kwh` = **m³ on the gas meter** (raw). kWh = delta × `meterCoefficient_kWhPerM3` (10.6079) applied DOWNSTREAM in `computeConsumption`.
- `elec_jour_kwh` / `elec_nuit_kwh` = **CT-scaled meter count** (raw). kWh = delta × `meterCoefficient` (15) applied DOWNSTREAM.
The manual form therefore captures the SAME raw indexes the operator reads off the physical meters: eau m³, **gaz m³** (NOT kWh — the operator reads the gas meter in m³), élec nuit/jour CT-counts. DO NOT pre-multiply in the form. The estimator applies the coefficients (port of `computeConsumption`). Label the gas field "Index gaz (m³)" and the elec fields by what the meter shows (kWh CT-count). Confirm meter-face units with Kouros at build — but storage parity with Node is non-negotiable (the parity gate enforces it).

## ① TARIFF SOURCE — RATIFIED: canonical MySQL table `ref_utility_tariffs`, versioned by effective date
REJECTED (ii) system_settings block — the tariff is a structured, multi-section, effective-dated reference dataset (gas/water/sewage/electricity each with ~10 fields), not a flat key/value; cramming it into system_settings violates "one fact, one canonical table" and makes versioning + audit ugly. REJECTED copying the JSON to the VPS — that creates a PARALLEL off-DB store (the exact divergence smell). The tariff becomes a **canonical maltyweb table**, SCD-by-effective-date, seeded from `data/utility-tariffs.json` (the ONLY non-guessed source — operator's own extracted invoice rates).

Schema (mig 395):
```
CREATE TABLE ref_utility_tariffs (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  effective_from DATE NOT NULL,           -- 'YYYY-MM-01'
  tariff_json  JSON NOT NULL,             -- the {gas,water,sewage,electricity} block, byte-for-byte the JSON version object
  source_note  VARCHAR(255) NULL,         -- "SIE #8666 + SIL #8701, Feb 2026"
  created_by   VARCHAR(64) NOT NULL DEFAULT 'seed',
  created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_effective_from (effective_from)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```
- **JSON column, not 40 scalar columns** — DELIBERATE: keeps byte-for-byte parity with the Node tariff object so the PHP estimator port is a 1:1 translation and the parity gate is exact; the tariff sub-structure is read-only reference (operator edits whole versions, never single fields in v1). A future "tariff editor" UI can stay JSON-backed.
- Lookup = `tariffsFor(monthKey)`: newest `effective_from <= monthKey-01`, fallback oldest. Mirrors `lib/utilities.js::tariffsFor`.
- schema_meta row REQUIRED: classification `reference`, corrections_policy `allowed` (admin-only edits via direct SQL in v1; no UI yet — flag as a v2 follow-up).
- Seed in the SAME migration: INSERT the single existing version (effective_from 2026-01-01) with `tariff_json` = the exact `versions[0]` object from `data/utility-tariffs.json`. **This is seeding from canonical operator data, NOT guessing.**
- 🔴 RULE 1 scrap: once live + parity-proven, `data/utility-tariffs.json` is SUPERSEDED as the runtime source for the web-path. Node `lib/utilities.js` keeps reading the JSON until the Node pipeline is itself retired — DO NOT delete the JSON; log it in scrapping-backlog with gate "Node COP pipeline retired".

## ② METER-READING SAISIE — build brief
- **New ref_pages row** (mig 395, same migration): page_key `saisie-energie`, label "Index énergie", icon e.g. ⚡, href `/modules/saisie-energie.php`, **min_role `manager`** (the operator who reads meters monthly is manager-tier; finance owns energy COP — NOT a floor-operator task), domain `general`, category_key `finance` (sits beside Financier; verify topbar grouping), is_active 1, sort just after financier (~126). **min_role ENUM has NO 'manager-finance'** — there is no such role; finance access = manager role + presets. Grant the page to the relevant presets in the SAME migration (INSERT IGNORE into `ref_access_preset_pages`): `manager` + `finance_viewer` (id=10) at least. 🔴 STANDING RULE (planning-arc burn): a new ref_pages row is INVISIBLE to preset-holders until granted — the preset grant is a REQUIRED ship step, not optional.
- **Page file:** `public/modules/saisie-energie.php`. CSS in `public/css/saisie-energie.css`, JS in `public/js/saisie-energie.js` (live delta compute) — NEVER inline.
- **POST endpoint:** in-page POST (PRG) or `public/api/saisie-energie-save.php`. Discipline: `require_login` + role gate (`user_can_access`) + `csrf_verify` + snapshot-before-write + `log_revision` + PRG redirect. **Corriger-loop tolerant** (operator standing fact): partial saves must be idempotent — accept that a month row may be re-submitted with one field at a time; upsert must not clobber a previously-saved sibling field with NULL (use COALESCE / only-overwrite-non-NULL semantics, or require all 4 fields per submit — Kouros to confirm; default to per-field-preserving upsert).
- **Upsert SQL** into `inv_energydata`:
  ```sql
  INSERT INTO inv_energydata (period, eau_m3, gaz_kwh, elec_nuit_kwh, elec_jour_kwh, source, last_modified_by, row_hash)
  VALUES (:period, :eau, :gaz, :nuit, :jour, 'estimate', 'web', :row_hash)
  ON DUPLICATE KEY UPDATE
    eau_m3 = COALESCE(VALUES(eau_m3), eau_m3),
    gaz_kwh = COALESCE(VALUES(gaz_kwh), gaz_kwh),
    elec_nuit_kwh = COALESCE(VALUES(elec_nuit_kwh), elec_nuit_kwh),
    elec_jour_kwh = COALESCE(VALUES(elec_jour_kwh), elec_jour_kwh),
    last_modified_by = 'web',
    updated_at = CURRENT_TIMESTAMP;
    -- DELIBERATELY NOT touching peak_kw / reactive_kvarh / source-when-already-invoice
  ```
  🔴 **PRESERVE peak_kw / reactive_kvarh** — the form never sends them; the UPDATE clause must not list them. 🔴 **PRESERVE invoice precedence:** if a row already exists with `source='invoice'` (SIE landed), the manual save must NOT downgrade it to 'estimate' nor overwrite its meter values — either skip-on-invoice-exists or only fill NULLs and leave source='invoice'. Decide: a manual save on an invoice-sourced month should be REFUSED or no-op with an operator notice ("ce mois est déjà saisi depuis la facture SIE/SIL"). Recommend refuse-with-notice.
  - `row_hash` = sha256 of the canonical field tuple (match the ingest hashing so re-saves are stable). Verify the exact hash recipe `ingest-sie-invoice` uses and replicate it — a mismatched hash recipe creates two rows or a UNIQUE collision.
- **SOURCE ENUM decision — RATIFIED: use existing `'estimate'`, NO migration for the ENUM.** Manual meter reads ARE the estimate basis (they feed the estimator, exactly as the Node model treats meter-derived costs as estimates until the invoice lands). Adding a 'manual-meter' value buys nothing the `last_modified_by='web'` column doesn't already give us (that column ALREADY distinguishes web-saved from ingest-saved). Provenance question "was this row hand-keyed or invoice-derived?" is answered by `source` ('invoice' vs 'estimate') AND `last_modified_by` ('ingest' vs 'web') together. So: manual saves write `source='estimate', last_modified_by='web'`. SIE invoices write `source='invoice', last_modified_by='ingest'`. Clean, no ENUM migration.
- **UX (Kouros's questions, ANSWERED):**
  - YES show last month's cumulative index as a hint per field + compute the **consumption delta live** (this month − last month) in JS as the operator types — catches transposition / meter-rollover errors at entry. This is the highest-value guard (cumulative indexes are unforgiving; a fat-fingered index silently corrupts COP).
  - YES list a **history table** of past periods (period, the 4 indexes, source badge invoice/estimate, computed delta, last_modified_by) — read-only, most-recent-first. Lets the operator sanity-check the monotone trend.
  - Add a soft validation: warn (don't block) if a new index < previous month's index (meter never goes backward except rollover) or if delta is wildly off the trailing-3-month mean.

## ③ PHP ESTIMATOR — build brief
- **New file:** `app/utilities-estimate.php` — function `utilities_estimate_month(PDO $pdo, string $monthKey): array` returning `{gas:{ht,tva,ttc,breakdown}, waterSewage:{...}, electricity:{...}, consumption:{...}, peakKW, peakSource, reactive_kVArh}` — byte-for-byte port of the Node compute. Precedent: `app/cogs-fiche-compute.php` is the EXACT pattern (in-app PHP compute replacing a Node/TS engine, with a parity oracle). Mirror its structure.
- **Reads:** `inv_energydata` (all rows, ORDER BY period — needs the full series for cumulative deltas + rolling-mean peak) + `ref_utility_tariffs` (from ①).
- **Port these Node functions** 1:1 (from `lib/utilities.js`):
  - `tariffsFor(monthKey)` — newest effective_from ≤ month.
  - `computeConsumption(readings, tariffs)` — deltas; **applies meterCoefficient_kWhPerM3 to gas delta and meterCoefficient (×15) to elec deltas** (THE unit trap — port exactly).
  - `computeGasCost` / `computeWaterCost` / `computeElectricityCost` — port the formulas + TVA rules verbatim (note water has split TVA 2.6%/8.1%, solidarity untaxed; elec has TVA-exempt cantonal+communale-spécifique lines; `r2()` = round to 2dp — replicate the rounding boundary EXACTLY or parity drifts by centimes).
  - `resolvePeakKW(readings, closedMonths, fallback)` — the closure-aware priority (actual-invoice wins → frozen snapshot for closed months → rolling-12-mean → tariff default 70.5). This is the one with state (the closure snapshot) — see closure store below.
  - `toByMonthHT(costs)` — gas.ht / electricity.ht / waterSewage.ht (COP uses **HT**, never TTC — house rule).
- **Financier switch:** `public/modules/financier.php` currently sets `'utilities' => ['total' => $cop['utilities']['total'] ?? null]` from the JSON artifact. Change the COP build (in `financier.php` or a `app/financier-data.php` helper if extracted) so the utilities total = `utilities_estimate_month($pdo, $monthKey)` summed (gas.ht + waterSewage.ht + electricity.ht) for the displayed month, INSTEAD of the stale JSON. Keep the JSON read as a fallback ONLY if the estimate is unavailable (no tariff/no readings) — but the estimate is now the live source. 🔴 This is COGS/COP-bearing: Opus verifies the financier utilities tile against a hand-computed reference BEFORE it reaches the operator (standing COGS-bearing-claim discipline).
- **"Invoice actuals override estimate" preserved in PHP:** the override already lives in the DATA, not the compute — when SIE lands, the row's `source` flips to 'invoice' and peak_kw/reactive_kvarh populate. The estimator's `resolvePeakKW` already prefers a populated peak_kw ("actual-invoice wins"). For the COST itself: a closed/invoiced month should show the invoice-derived cost, not a re-estimate. DECISION: the estimator computes from meter deltas regardless; the invoice's authority is expressed through (a) peak_kw/reactive being actuals and (b) the closure snapshot freezing the month. If Kouros wants the literal invoiced CHF to override the computed CHF for invoiced months, that needs an invoiced-total column on inv_energydata — FLAG as a possible v2; in v1 the estimate stands and the invoice refines peak/reactive only (matches current Node behavior).
- **Closure snapshot — RATIFIED: new MySQL table `ops_utility_closures` replacing `data/utility-closures.json`.** Same reasoning as the tariff: an off-DB JSON on the VPS is a parallel store; the snapshot is canonical state (frozen peak/reactive per closed month) that the estimator MUTATES, so it MUST be in MySQL. Model on `cogs_fiche_sealed` (mig 375 — append/immutable closure pattern). Schema (mig 395, same migration):
  ```
  CREATE TABLE ops_utility_closures (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    period        CHAR(7) NOT NULL,                -- 'YYYY-MM'
    peak_kw       DECIMAL(10,3) NOT NULL,
    reactive_kvarh DECIMAL(12,3) NOT NULL DEFAULT 0,
    snapshot_source ENUM('actual-invoice','rolling-at-closure') NOT NULL,
    frozen_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_period (period)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
  ```
  - schema_meta row REQUIRED (classification `operational`/`snapshot`, corrections_policy as appropriate — frozen rows shouldn't move once 'actual-invoice').
  - Port `loadClosureSnapshot`/`saveClosureSnapshot` to SELECT/UPSERT this table. "closed months" set = financier's `fin_last_closed_month` / sealed-fiche months (reuse `app/finance-period.php`); a month is closed when the fiche is sealed.
  - 🔴 IMMUTABILITY: an `actual-invoice` snapshot row must never be overwritten by a `rolling-at-closure` re-estimate (the Node code already guards this — `if (snap && snap.source !== 'actual-invoice')`). Replicate in PHP. This is the "closed-month peak-kW snapshot immutability" the audit named.
  - Seed `ops_utility_closures` from the existing `data/utility-closures.json` (~30 rows) in the migration — canonical operator-frozen data, NOT guessing.
  - RULE 1 scrap: `data/utility-closures.json` superseded for the web-path; keep until Node pipeline retired.

## ④ PARITY GATE — exact acceptance
For each test month, the PHP estimator's `{gas.ht, waterSewage.ht, electricity.ht, and the COP utilities total}` MUST equal the Node `lib/utilities.js::loadAndComputeUtilities` output **within ±0.01 CHF per component** (rounding tolerance — both use round-to-2dp; aim for exact match, allow 1 centime for float-order), computed against the SAME `inv_energydata` rows and the SAME tariff version. Build a parity harness (mirror `~/maltytask-run/run-fiche.sh` / the cogs-fiche parity pattern): run Node `loadAndComputeUtilities` → dump per-month HT; run PHP `utilities_estimate_month` per month → diff. **GATE: max abs delta = 0.00 (or ≤0.01 explained by rounding) across ALL test months before the financier switch is wired.**
- **Months to test:** the months with full data + invoice actuals: **2026-04** (latest, has peak_kw 66 + reactive 829.5 — exercises the actual-invoice peak path), **2026-03 / 2026-02 / 2026-01** (peak NULL → exercises rolling-mean + closure-snapshot path; 2026-02 has a frozen closure 70.5/858), and **2025-12** (closure snapshot present). That set exercises all four `resolvePeakKW` branches (actual / frozen-snapshot / rolling / fallback) plus the cumulative-delta + coefficient math. Spot-check 2026-04 by hand against the Feb-invoice-verified figures in the `lib/utilities.js` header comment.
- 🔴 The unit trap is the #1 parity risk: if PHP forgets to ×10.6079 (gas) or ×15 (elec), gas/elec HT diverge by an order of magnitude. The gate catches it; the canary is gas consumption ≈ (gaz delta) × 10.6079 ≈ matches the header's "4968 m³ × 10.6079 = 52700 kWh" worked example.

## ⑤ EQUIP + SEQUENCE
- **EQUIP ①+② (migration + saisie page):** `sql` + `coder` + `ui` + `webapp-testing` (smoke the page + corriger-loop + delta-compute JS live).
- **EQUIP ③+④ (estimator + parity + financier switch):** `coder` + `sql` (+ `ui` for the financier tile if its render changes; + `webapp-testing` smoke of the financier page post-switch).
- **SEQUENCE (strict):**
  1. **Mig 395 first** (one migration): `ref_utility_tariffs` + seed; `ops_utility_closures` + seed; `ref_pages` row `saisie-energie` + preset grants; 3 schema_meta rows. Re-`migrate.php --status` immediately before assigning the number (parallel sessions — number may have moved past 394).
  2. **②② saisie page** (depends only on mig + inv_energydata) — can ship + go live independently; gives the operator the input surface immediately. RULE 3: dispatch tour-steward for the new `saisie-energie` page (manager-visible, domain=general → needs a card). RULE 2 review before commit.
  3. **③ estimator + ④ parity harness** in parallel-ish, but **④ GATE must PASS before ⑤ the financier switch is wired.** Estimator reads the mig-395 tables; parity proves it against Node.
  4. **Financier switch** last, only after parity=0. Opus independently verifies the financier utilities tile (COGS-bearing).
  5. RULE 1: log `utility-tariffs.json` + `utility-closures.json` as scrapped-for-web-path (gate: Node COP pipeline retirement) in scrapping-backlog.md.

## OPEN DECISIONS FOR KOUROS (surface, don't guess)
1. Manual save on an already-invoice-sourced month → refuse-with-notice (PM rec) vs fill-NULLs-only.
2. Per-field-preserving upsert (corriger-loop) vs require all 4 fields per submit.
3. Gas meter face unit — confirm operator reads m³ (the model assumes m³; label accordingly).
4. v2: literal-invoiced-CHF override column on inv_energydata (vs estimate-stands-with-actual-peak in v1) — PM rec defer.
5. v2: tariff-editor UI (v1 = direct SQL admin edit of `ref_utility_tariffs`).

## DERIVATION-TREE PLACEMENT
Upstream: physical meters → manual saisie (`source='estimate'`) OR SIE/SIL invoice (`source='invoice'`) → `inv_energydata` (canonical fact owner) × `ref_utility_tariffs` (canonical tariff) → `app/utilities-estimate.php` → financier COP utilities line → COP grand-total/perHL. `ops_utility_closures` freezes peak/reactive at month-close (immutability). NO new fact store created — `inv_energydata` remains the single owner of meter facts; the form is just a second WRITER (web) alongside the existing ingest writer, into the SAME table. NOT a parallel store. ✅ Architecturally clean.
