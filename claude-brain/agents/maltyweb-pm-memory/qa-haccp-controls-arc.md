# QA / HACCP QUALITY-CONTROLS ARC — `/modules/qa.php`

> Salle de contrôle (QA/QC) family. **NON-FISCAL observation layer — NEVER feeds COGS / stock / WAC / BOM / beer-tax.** This is the first real build on the `qa` page (previously an inactive ref_pages placeholder with pre-written tour copy). SHIPPED + LIVE on the VPS 2026-06-15.
>
> Last updated: 2026-06-15.

## STATUS — DONE & LIVE 2026-06-15 (commits LOCAL-only, NOT pushed)

All shipped on PM-recommended defaults; operator granted "go for it". Work is fully LIVE on the VPS. **maltytask + maltyweb commits are LOCAL-only, NOT pushed** — a parallel cage session's commits are interleaved in the shared clone, so pushing would carry their in-flight work. Deferred to Kouros. (Same shared-tree hazard class as the index BUILD-LAUNCH CHECKLIST §commit-with-pathspec / §deploy-pushes-working-tree.)

## WHERE IT SITS IN THE DERIVATION TREE

Salle de contrôle / QA-QC family of Le Zeppelin. Pure DOWNSTREAM **observation** off existing canonical events — it READS production facts, writes only its own observation rows, and **NOTHING reads back into the SOT chain**. Dropdowns derive by FK from the real canonical tables (no string copy, no parallel store):
- net-content reading → `bd_packaging_v2` LEFT JOIN `ref_skus` (label "Beer — date — runtype (sku)")
- cleaning-efficacy check → `bd_cip_events` JOIN `ref_cip_types`
- bottle-reception check → `inv_deliveries` (status not cancelled/tombstoned)
- packaging-material MI → `ref_mi WHERE category_id=8` (Packaging; confirmed via `ref_mi_categories`)

## WHAT SHIPPED

### Migrations (maltyweb repo; committed locally, applied on VPS)
- **358_qa_net_content_readings.sql**
- **359_qa_cleaning_efficacy_checks.sql**
- **360_qa_bottle_reception_checks.sql**
  - 3 `source`-class tables, `corrections_policy='allowed'`, `schema_meta` rows written, `writer_script='/modules/qa.php'`.
  - FK types verified live: packaging/cip/delivery = **BIGINT UNSIGNED**; mi/user = **INT UNSIGNED**.
  - Commit `0b52f13`.
- **361_activate_qa_page.sql** — `UPDATE ref_pages SET is_active=1, href='/modules/qa.php' WHERE page_key='qa'` (id 11). Applied. Commit `eac31da`.
  - ⚠️ **Mig-number collision (harmless):** a parallel session also created `361_ord_orders_bc_*.sql`. migrate.php tracks by filename, so both coexist; theirs left untouched, theirs to commit.

### Handlers (async JSON, mirror `rm-stocktake-line-add.php`)
`public/api/qa-net-content.php`, `qa-cleaning-efficacy.php`, `qa-bottle-reception.php`. Envelope: `{ok:true,id,...}` / `{ok:false,error}` / `{ok:false,reason:'expired',csrf}` / duplicate→`{ok:true,duplicate:true}`. Each does csrf_verify + `require_page_access('qa')` + audit revision + row_hash idempotency + read-`??`-then-validate + ENUM whitelist + FK-existence checks.
- Shared helper **`parse_nullable_decimal()`** added to existing **`app/db-write-helpers.php`** — single canonical def, NOT copied (honors "call the accessor, never copy its literal").
- Commits `3ede620` then `01728e2` (PRG→JSON refactor).

### UI
`public/modules/qa.php` (747L) + `public/css/qa.css` (297L) + `public/js/qa.js` (314L). 3 stacked panels (net-content / cleaning-efficacy / bottle-reception), optional `?view=net|cip|recep`, async fetch + DOM prepend, escHtml, external CSS/JS (no inline). Commit `8734849`.

### Tour
Tour card for `qa` shipped by tour-steward (commit `f8478da`; also added a bonus journal-saisies card). Tour gap CRITICAL 2→1. **Remaining critical = `financier`** — steward correctly flagged it `PM-RATIFY` as sensitive COGS class; NOT covered here, belongs to the COGS-fiche arc (it owns that card).

### Smoke (webapp-testing — PASSED)
All panels render; dropdowns populated (A:50, B:30, C:50/125); 3 write round-trips OK; validation rejects bad enum (400 + JSON, NOT 500); test rows self-cleaned (COUNT=0); test account id=16 elevated→reverted.

## DESIGN DEFAULTS LOCKED (operator may revisit — all non-destructive)
- Units: weight=**g**, volume=**mL**; single `measured_value` + `measure_type` discriminator (NOT two columns).
- `surface_label` = **free text** (no `ref_qa_surfaces` lookup built).
- Targets: **per-reading** `target_value` + `tolerance_abs` (NO central `qa_targets` master).
- `is_conforming` **derived at write**.

## OPEN / FOLLOW-UP
1. Panel C `mi` dropdown shows the WHOLE Packaging category (125 options) — tighten to glass/bottle-only if operator asks.
2. Feature A inline capture on the conditionnement fiche (Q3 phase-2) NOT built — standalone qa-page capture only, as planned.
3. Optional `ref_qa_surfaces` lookup + central `qa_targets` master = future normalization, not built.
4. `financier` tour card still open (its arc owns it — COGS-fiche / financier-cogs-fiche.md).
5. Push: commits are LOCAL-only pending Kouros (cage-session interleave).

## ✅ SHIPPED + LIVE 2026-06-19 — 4th panel "Analyse de l'eau" (HACCP CCP-1 / `CAPA-2026-005`)
> **AS-BUILT (Opus-verified on VPS 2026-06-19):** the PENDING BUILD below was BUILT, applied, and deployed. Files all timestamped Jun-19 10:23–10:31 on VPS.
> - **Migrations 403/404/405** (NOT 402 — a parallel session took `402_refold-shipping-title-norm.sql`; the build correctly took the next free numbers). All applied (`schema_migrations` confirms) + present LOCAL untracked. `403_ref_water_sample_points` / `404_ref_water_parameters` / `405_qa_water_analysis`.
> - **Tables verified:** ref_water_sample_points = 6 rows (PS-1/3/5 active; PS-2/4/6 is_active=0 per `le cas échéant` ruling — HONORED), ref_water_parameters = 10 rows, qa_water_analysis = 0 rows (clean, no test residue). PS-3 is_ccp=1 (CCP-1) ✓.
> - **schema_meta** all 3 rows present: qa_water_analysis=source/allowed; both lookups=reference/allowed. ✓ classifier col `table_class` used.
> - **Endpoint `public/api/qa-water-analysis.php`** deployed (16 243 B), `php8.1 -l` clean, POST → 302 auth-redirect (NOT 500/404). `?view=eau` page → 302 (healthy). 4th `qa-tab` wired (13 tab refs in qa.php), `?view=eau` switch + form `#qa-form-eau` action=`/api/qa-water-analysis.php`, `initEauParamToggle()` presence/absence ↔ numeric toggle in qa.js.
> - **RULE 3 (tour):** NO new tour card needed — water analysis is a 4th tab WITHIN the existing `qa` page (no new ref_pages row, as the verdict predicted). `tour-gap-check.php` = critical=0 minor=0 (clean). The qa tour copy still describes only the 3 original controls; refreshing it to mention water is OPTIONAL polish (not a RULE-3 gate), gated on operator validating the PS list.
> 🔴 **OPEN:** (1) commits LOCAL-only / NOT pushed — `qa.php`/`qa.css`/`qa.js` modified + 4 untracked files (migs 403/404/405 + qa-water-analysis.php); shared-tree interleave, commit by PATHSPEC. (2) OPERATOR-GATE STILL OPEN: definitive PS list + parameter action-limits must be validated by operator against his hydraulic schema before this is treated as audit-ready (PS-2/4/6 stay inactive until then). (3) Optional: qa tour copy refresh to mention the 4th control; minimal lookup admin-CRUD (v1 is seed-by-SQL).
>
> ---
> ### Original design ruling (PM, 2026-06-19) — kept for provenance
> 🆕 PENDING BUILD (PM design RULED 2026-06-19) — 4th panel "Analyse de l'eau" (HACCP CCP-1 / `CAPA-2026-005`)
> AUDIT DRIVER: La Nébuleuse HACCP — commune water analyses stop at the meter; NO internal analysis at sensitive points = "point faible déterminant". Inspector requires an internal water-analysis programme DERIVED FROM the risk analysis (CCP-1 open item, logged MA-01 register). Operator wants it in maltyweb. Companion doc AE-01 defines sensitive points PS-1..PS-6 + parameters.
>
> **PM VERDICT: 4th panel on `qa.php` (`?view=eau`), NOT a separate surface.** Same QA observation family / security pattern / async-JSON handler shape. `qa` already is_active=1, already preset-granted, already tour-covered → NO ref_pages row, NO preset grant, NO tour-steward dispatch (RULE 3 satisfied; optional copy refresh only). **NON-FISCAL — NEVER feeds COGS/stock/WAC/BOM/beer-tax** (CARDINAL preserved).
>
> 🔑 STRUCTURAL DIFFERENCE from the existing 3 panels: water analysis has NO upstream canonical event (no `bd_water_*`) → its dimensions are NEW reference facts you must SEED, not FK-derive. That is WHY lookups (not ENUM/free-text) are correct here, not over-engineering.
>
> **TABLES (3):**
> - `ref_water_sample_points` (table_class='reference', corrections_policy='allowed', admin-editable): code (PS-1…), label, description, `is_ccp` BOOL (PS-3=CCP-1), `derived_from_risk`/risk-basis note (inspector's actual demand = visible), sort_order, is_active, notes. SEED PS-1..6 FROM AE-01 (legit seed-from-explicit-statement, NOT guessing); **PS-2/4/6 `le cas échéant` → seed is_active=0** until operator confirms his hydraulic schema (softener/filter/tank UNCONFIRMED — do NOT hard-bake a guessed installation; ENUM rejected for exactly this).
> - `ref_water_parameters` (reference/allowed): code, label, `unit`, `default_action_limit` DECIMAL NULL, **`limit_operator` ENUM('lte','gte','range','presence_absence')** (micro=presence/absence, physico-chem=value-vs-limit/range e.g. pH 6.5–9.5 → conformity logic DATA-DRIVEN not per-param if), `limit_basis` (OSEC/OPBD/AE-01 citation — every default limit needs a citation or ships NULL+operator-fills; inventing from "typical brewery" = the guessing to avoid), is_active. 10 params: E.coli, entérocoques, germes aérobies mésophiles, Pseudomonas aeruginosa, Legionella, nitrate, dureté, chlore résiduel, conductivité, pH.
> - `qa_water_analysis` (table_class='source', corrections_policy='allowed', writer '/modules/qa.php', NEVER-COGS note): one row per (sample_point × parameter × sampled_at). FKs `sample_point_id_fk`/`parameter_id_fk`/`created_by_fk` = INT UNSIGNED (convention). Cols: measured_value DECIMAL + measured_text (presence/absence), unit, **`action_limit` SNAPSHOT-on-write** (preserves audit truth if lookup limit later edited), `is_conforming` BOOL **NULL** (derived at write per limit_operator; NULL when no limit — semantic NULL, fine), lab_name, method, sampled_at DATETIME, report_ref, comments, row_hash UNIQUE (idempotency), created_at.
>
> **MIG NUMBER: 402 (verified live 2026-06-19 — applied through 400 "Pending:0", BUT `401_planning_working_days_and_brew_cap.sql` exists on disk unapplied/in-flight = parallel session; 401 TAKEN-untracked).** Re-`migrate.php --status` + `ls db/migrations` at build-start; take first number above EVERYTHING on disk. FK-target lookups land before observation table. ⚠️ schema_meta classifier col = **`table_class`** (NOT source_class).
>
> **REUSE:** `parse_nullable_decimal()` (app/db-write-helpers.php — call don't copy); handler envelope + security pattern from `qa-cleaning-efficacy.php` (mirror); `?view=` switch + prepend JS from qa.js; qa.css panels. **BUILD FRESH:** 2 lookups + obs table + handler `qa-water-analysis.php` + 4th panel + minimal lookup admin-CRUD (or seed-by-SQL v1, CRUD fast-follow). This is the first concrete instance of the deferred "ref_qa_surfaces/qa_targets normalization" — consistent direction.
>
> 🔴 OPERATOR-GATE: definitive PS list MUST be validated by operator against his hydraulic schema before go-live. EQUIP coder+sql+ui+webapp-testing.
