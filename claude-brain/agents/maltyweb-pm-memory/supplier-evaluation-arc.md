# SUPPLIER EVALUATION (Évaluation des fournisseurs / EF-01) ARC

> **PLANNING-ONLY as of 2026-06-19. NOT BUILT.** Document EF-01 likely delivered first to close the audit finding; ERP build returns to operator for an explicit go/scope decision before any builder is dispatched.

## DRIVER
- B-Corp label requirement + HACCP/autocontrôle PRP "approvisionnement" (supplier approval). Audit finding: "pas d'évaluation des fournisseurs … point faible déterminant à éliminer." Operator's "dernier point" in a series of audit closures (water analysis mig 403/404/405, CAPA register, etc.).
- Companion procedure doc **EF-01** (operator-authored): weighted grid, two pillars — (A) food-safety/quality (ISO22000/FSSC/IFS/bio certs, COA/specs, delivery conformity, lead-time, traçabilité/allergènes, NC handling); (B) B-Corp/ESG (environnement, social, code de conduite fournisseur, éthique/gouvernance, sourcing local). Scoring → classification **Agréé / Agréé sous surveillance / Non agréé** with a food-safety KNOCK-OUT. Cadence: initial referencing + périodique (annuel critiques / biennal non-critiques) + event-driven (sur NC majeure).

## VERIFIED LIVE STATE (2026-06-19)
- **NO evaluation/scoring table exists.** Tables present: `ref_suppliers`, `ref_supplier_aliases`, `ref_supplier_field_pins`, `ref_supplier_gls`, `ref_supplier_proposals`, `ref_supplier_summary`. Nothing to extend — build fresh.
- `ref_suppliers` cols LIVE: id(int uns PK), supplier_id(vc64), name, gl_account, category, currency, is_active, notes, row_hash, last_seen_at, imported_at, last_modified_by enum('ingest','web'), parser_key, country char(2), vat_number, vat_regime enum, hors_perimetre_cogs, sporadique, commissioning_state enum('draft','active','retired'). **NO `critical` flag. NO `merged_into_id`/`confirmed_by_admin_at`** (governance model proposed them; only a subset of cols landed). 136 suppliers, ALL commissioning_state='active' (draft pool was curated through).
- **NO `fournisseur` / `supplier` row in `ref_pages`.** The Fournisseur fiche is a SECTION/surface under Salle des Machines (Capacités family), NOT a standalone page_key. So no ref_pages activation / tour-card RULE-3 trigger for adding an evaluation TAB to that existing surface — but confirm exact host before building.
- Criticality DERIVATION is feasible from canonical data: `inv_deliveries.supplier_fk → inv_deliveries.ingredient_fk (resolved MI FK → ref_mi.id) → ref_mi.category_id → ref_mi_categories.name`. NB: `inv_deliveries` resolves MI via `ingredient_fk` (resolved) / `mi_id_raw` (raw) — there is **NO `mi_id_fk`** col; `category_raw` also carried per line. Food-contact categories for KO/critical lens ≈ Malt(1)/Hops(2)/Yeast(3)/Brewing Adjunct(4)/Brewing Mineral(11)/Packaging(8) [direct food contact: bottles/caps/cans]. Process/Cleaning Chemical (5/7) = indirect. Operator must ratify the food-contact category set — do NOT guess it.
- **MIG HEAD = 406 applied; next free = 407** (re-`--status` at build start; parallel sessions lead the number; 397 already landed here).

## PM PLAN (delivered to operator 2026-06-19)
1. **Placement:** new `supplier_evaluations` table (one row per evaluation EVENT, FK→ref_suppliers.id) + `supplier_evaluation_criteria` child rows (one per criterion: pillar, code, label, weight, score, evidence-source) — child rows over a JSON blob so criteria are queryable/reportable and the grid definition can version. Surface as a **TAB/section on the existing Fournisseur fiche** (house-consistent: same family, evaluation is supplier metadata). NOT a separate page.
2. **Auto-feed (curate evidence, don't retype — mirrors fiche governance):** delivery conformity + lead-time + last-delivery from `inv_deliveries`; NC history once a supplier-NC surface exists (none yet — flag as gap, manual-entry v1); criticality auto-derived (above). COA presence is NOT yet structured → manual v1.
3. **Criticality:** DERIVE (food-contact MI category ⇒ critical) as the DEFAULT, with a MANUAL OVERRIDE flag on ref_suppliers (`criticality` enum or `is_critical` + pin) — derived-with-override, never pure-manual (long tail) nor pure-derived (edge cases: a logistics supplier handling food, a one-off). Override follows the "creation ≠ trust" / pin discipline.
4. **Schema/convention:** classification = **source/reference table, corrections_policy decision below**. Evaluation rows are an OBSERVATION/assessment layer — like QA/HACCP, **NON-FISCAL: NEVER feeds COGS/COP/WAC/BOM/beer-tax/stock.** It reads supplier+delivery facts, writes only its own assessment rows. So schema_meta class = `source`, corrections_policy `allowed` (assessments get corrected/restated; an evaluation is a dated event, supersede via new row not in-place rewrite for the validity record — but a typo correction is allowed). It is COGS-ADJACENT only in that it hangs off the canonical supplier store — the ADDITIVE rule (below) keeps it from perturbing fiscal cols.
5. **Classification ENUM** `evaluation_result enum('agree','agree_sous_surveillance','non_agree')` + `food_safety_ko tinyint` (the knock-out that forces non_agree regardless of weighted score). evaluator(user FK), evaluated_at, valid_until (cadence-driven), comment.

## RISK FLAGS
- **ADDITIVE ONLY — must not perturb fiscal columns or ingest/pin governance.** Supplier store is canonical + COGS-adjacent (entity resolution, multi-GL routing, hors_perimetre_cogs). The evaluation layer adds tables + (at most) one criticality col; it must NOT touch vat_regime/gl_account/hors_perimetre_cogs/parser_key or the pin/ingest write paths.
- **NO parallel store:** evaluation reads supplier identity from ref_suppliers by FK; criticality reads MI categories by the existing JOIN — do NOT snapshot supplier name/GL/category into the evaluation table (string-copy divergence).
- **NC surface gap:** food-safety pillar wants NC handling history; no supplier-NC table exists today. v1 = manual NC count entry; a future `supplier_nc` / CAPA-link is the proper source. Flag, don't fake.
- **Criticality category set is operator-ratified, not guessed** (food-safety-impacting — a wrong critical flag drives wrong cadence/audit posture). Seed from operator's explicit food-contact category list.
- **Validity/cadence is data, not hardcode:** annuel/biennal periods + criterion weights live in a settings/grid-definition table (system_settings or a `supplier_evaluation_grid`), never hardcoded — so the auditor-approved grid can evolve.

## v1 vs FULL (scope choice for operator)
- **DOC-FIRST (recommended immediate):** deliver EF-01 procedure now → closes the auditor finding (evaluation procedure EXISTS + defined). Zero ERP risk. Then bring the build back for an explicit go.
- **Minimal safe v1 (ERP):** `supplier_evaluations` + `supplier_evaluation_criteria` + criticality derive/override col; read-only auto-feed (delivery conformity, lead-time, criticality) pre-populated; manual entry for certs/COA/NC/ESG; classification + KO; valid_until + "⚑ à réévaluer" intake queue (mirrors the "à valider" draft pool pattern). Surface as a Fournisseur-fiche tab. NO fiscal touch.
- **Full build (follow-up):** grid-definition versioning; structured COA/cert document links; supplier-NC/CAPA surface feeding the food-safety pillar; periodic-review cron emitting "à réévaluer" alerts (clone the credential-expiry/recap cron shape); reporting/export for the auditor.

EQUIP (when built): sql+coder+ui (fiche tab is a rendered surface) + webapp-testing (deployed-page smoke). Tour: confirm whether the Fournisseur surface is a ref_pages row before assuming RULE-3 applies.

---

## 🔨 FULL-BUILD READY PLAN (operator chose FULL BUILD, 2026-06-19) — DDL + WAVES (PLAN ONLY, awaiting category ratification then Wave 1 dispatch)

### LIVE FACTS RE-VERIFIED 2026-06-19 (read-only probe)
- **MIG HEAD: 408 applied** (files go to `408_pl_plan_items_customer_fk.sql`; 407=`ref_customers_serving_tank_flag`, 408 parallel-session). **next free = 409** — but PARALLEL SESSIONS LEAD; re-`migrate.php --status` + `ls db/migrations` at Wave-1 start, take whatever is actually free.
- **Doc store EXISTS = `doc_files`** (PK `id` BIGINT UNSIGNED, UUID `file_id` VARCHAR(255) UNI, file_name/local_path/file_hash/mime_type/source_folder/is_active). FK the COA/cert link table to **`doc_files.id`** (dual-key rule: FK→`.id`, NEVER the UUID). Operator-upload path = **`doc_uploads`** (id BIGINT, user_id, drive_file_id, pipeline_status enum) → pipeline lands bytes in `doc_files`. So a manual cert upload flows doc_uploads→pipeline→doc_files; the link table references the resulting doc_files.id. v1 may also allow a lighter direct upload — but reuse doc_files, do NOT build a parallel cert store.
- **NO CAPA / MA-01 / corrective-action table exists** (grep capa|ma01|corrective|nonconf = 0). MA-01 is DOC-ONLY. ⇒ `supplier_nc` CANNOT FK to a CAPA register. v1 carries a **soft text ref `capa_ref VARCHAR(64)` + `capa_register='MA-01'`** (the doc id in the paper register), with a `-- FUTURE FK` comment for when a CAPA table lands. Do NOT invent a CAPA table in this build (out of scope; would be a guessed parallel store).
- **Host fiche = `public/modules/salle-fournisseurs.php`** (555 lines; `require_page_access('approvisionnement')`; admin=full / manager=read+propose / opérateur→redirect). Fiche is **JS-HYDRATED**: registry list on left, `#sf-fiche` panel rendered fully CLIENT-SIDE by `public/js/salle-fournisseurs.js` (47KB) from a `window.SF_*` payload the PHP prints; AJAX write endpoints = `public/api/sf-*.php` (e.g. `sf-validate-supplier.php`). **There is NO GET fiche-data endpoint today** — fiche builds from the page-printed payload. ⇒ evaluation tab = a new JS-rendered panel inside `#sf-fiche`, fed EITHER by extending the page payload OR (cleaner for the heavier eval/criteria/NC data) a NEW `public/api/sf-supplier-evaluation.php?supplier_id=` GET endpoint. RECOMMEND the dedicated GET endpoint (keeps the page payload lean; eval data is tab-on-demand).
- **NO `fournisseur`/`supplier`/`capacités` ref_pages row** — salle-fournisseurs is NOT in ref_pages (gated via the `approvisionnement` key, comment says "not in topbar yet — intentional"). ⇒ **RULE-3 tour does NOT trigger** (no new ref_pages row). Confirm no topbar/nav change is wanted; if operator later promotes it to a real page_key, tour applies then.
- **ref_suppliers has NO criticality col** (no crit/food/eval cols). Add ONE additive col (below). 136 suppliers, all commissioning_state='active'.
- **Criticality-derive JOIN VERIFIED LIVE:** `inv_deliveries d JOIN ref_mi m ON m.id=d.ingredient_fk JOIN ref_mi_categories rmc ON rmc.id=m.category_id`. ⚠️ `inv_deliveries` has **`ingredient_fk` (resolved MI id) + `mi_id_raw`/`category_raw` — NO `mi_id_fk` col.** Confirmed counts: Malt→2 supp, Hops→5, Yeast→2, Brewing Adjunct→3, Packaging→11, Brewing Mineral→1.

### 1) DDL — every new table (MySQL 8; no IF NOT EXISTS on ALTER; FK types EXACT; schema_meta row each)

**`supplier_evaluation_grids`** — grid-definition versioning (weights are DATA, evolve without rescoring past evals).
- `id` SMALLINT UNSIGNED AUTO_INCREMENT PK
- `version_label` VARCHAR(40) NOT NULL (e.g. 'EF-01 v1.0')
- `effective_from` DATE NOT NULL
- `is_active` TINYINT(1) NOT NULL DEFAULT 0 (exactly one active; enforce in handler, not CHECK)
- `pillar_a_max` SMALLINT UNSIGNED NOT NULL DEFAULT 22, `pillar_b_max` SMALLINT UNSIGNED NOT NULL DEFAULT 13
- `agree_min_pct` DECIMAL(5,2) NOT NULL DEFAULT 75.00, `surveillance_min_pct` DECIMAL(5,2) NOT NULL DEFAULT 50.00
- `notes` VARCHAR(500) NULL, `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP, `created_by` INT UNSIGNED NULL (→users.id, soft)
- UNIQUE `uniq_grid_version (version_label)`. schema_meta: **reference / allowed**.

**`supplier_evaluation_grid_criteria`** — the A1-A6/B1-B5 rows for a grid version (weights data-driven).
- `id` INT UNSIGNED AUTO_INCREMENT PK
- `grid_id_fk` SMALLINT UNSIGNED NOT NULL → `supplier_evaluation_grids.id` (EXACT SMALLINT UNSIGNED) ON DELETE CASCADE
- `pillar` ENUM('A','B') NOT NULL
- `code` VARCHAR(8) NOT NULL ('A1'…'A6','B1'…'B5')
- `label` VARCHAR(200) NOT NULL ('Certification système /4', …)
- `max_score` SMALLINT UNSIGNED NOT NULL (A1=4,A2=4,A3=4,A4=3,A5=4,A6=3,B1=3,B2=3,B3=2,B4=2,B5=3)
- `evidence_source` ENUM('auto_delivery','auto_criticality','manual_cert','manual_coa','manual_nc','manual_esg','manual') NOT NULL DEFAULT 'manual' (drives which criteria the UI auto-fills vs prompts)
- `is_food_safety_ko` TINYINT(1) NOT NULL DEFAULT 0 (A-pillar food-safety criteria that can trigger the knock-out)
- `display_order` SMALLINT UNSIGNED NOT NULL
- UNIQUE `uniq_grid_code (grid_id_fk, code)`. schema_meta: **reference / allowed**.

**`supplier_evaluations`** — one row per evaluation EVENT (the assessment header).
- `id` INT UNSIGNED AUTO_INCREMENT PK
- `supplier_id_fk` INT UNSIGNED NOT NULL → `ref_suppliers.id` (EXACT INT UNSIGNED) ON DELETE CASCADE
- `grid_id_fk` SMALLINT UNSIGNED NOT NULL → `supplier_evaluation_grids.id` (which grid version scored this — so past evals never rescore when the grid evolves) ON DELETE RESTRICT
- `evaluation_type` ENUM('initial','annuel','biennal','evenementiel') NOT NULL
- `pillar_a_score` SMALLINT UNSIGNED NULL, `pillar_b_score` SMALLINT UNSIGNED NULL (computed from criteria; NULL while draft)
- `total_pct` DECIMAL(5,2) NULL
- `food_safety_ko` TINYINT(1) NOT NULL DEFAULT 0 (the knock-out flag → forces non_agree)
- `result` ENUM('agree','agree_sous_surveillance','non_agree','draft') NOT NULL DEFAULT 'draft'
- `evaluated_at` DATE NULL (date of assessment; NULL while draft), `valid_until` DATE NULL (cadence-driven next-review date)
- `evaluator_user_id` INT UNSIGNED NULL → users.id (soft), `comment` TEXT NULL
- `status` ENUM('draft','final') NOT NULL DEFAULT 'draft' (draft editable; final = sealed assessment, supersede via NEW row)
- `superseded_by_id` INT UNSIGNED NULL → self (restate chain; old row retained, never in-place rewrite of a final eval)
- `created_at`/`updated_at` TIMESTAMP. row_hash CHAR(64) NULL idempotency optional.
- INDEX `ix_se_supplier (supplier_id_fk, evaluated_at)`, INDEX `ix_se_valid (valid_until, status)` (cron scans this). schema_meta: **source / allowed** (assessments; typo-correct allowed, validity-restate via new row).

**`supplier_evaluation_criteria`** — child scores, one per criterion per evaluation.
- `id` INT UNSIGNED AUTO_INCREMENT PK
- `evaluation_id_fk` INT UNSIGNED NOT NULL → `supplier_evaluations.id` (EXACT) ON DELETE CASCADE
- `grid_criterion_id_fk` INT UNSIGNED NOT NULL → `supplier_evaluation_grid_criteria.id` (EXACT) ON DELETE RESTRICT (snapshots WHICH criterion def, not its label — read label by JOIN, NO string copy)
- `score` SMALLINT UNSIGNED NULL (0..max_score; NULL=not yet scored), `score_source` ENUM('auto','manual') NOT NULL DEFAULT 'manual'
- `evidence_note` VARCHAR(500) NULL
- UNIQUE `uniq_eval_criterion (evaluation_id_fk, grid_criterion_id_fk)`. schema_meta: **source / allowed**.

**`supplier_cert_documents`** — structured COA/cert links (reuse doc_files, NO parallel store).
- `id` INT UNSIGNED AUTO_INCREMENT PK
- `supplier_id_fk` INT UNSIGNED NOT NULL → `ref_suppliers.id` ON DELETE CASCADE
- `doc_file_id_fk` BIGINT UNSIGNED NULL → `doc_files.id` (EXACT BIGINT UNSIGNED; dual-key rule → `.id` not UUID) ON DELETE SET NULL (NULL allowed: a cert recorded as held-on-paper before upload)
- `doc_type` ENUM('cert_iso22000','cert_fssc','cert_ifs','cert_bio','cert_brc','coa','spec_sheet','code_conduite','esg_report','other') NOT NULL
- `reference_label` VARCHAR(200) NULL (cert number / lab ref), `issued_on` DATE NULL, `expires_on` DATE NULL (feeds re-review + a future expiry watchdog row)
- `linked_evaluation_id_fk` INT UNSIGNED NULL → `supplier_evaluations.id` ON DELETE SET NULL (which eval cited this evidence; soft)
- `is_active` TINYINT(1) NOT NULL DEFAULT 1, `created_at`/`created_by`
- INDEX `ix_scd_supplier (supplier_id_fk, doc_type)`. schema_meta: **reference / allowed**.

**`supplier_nc`** — supplier non-conformities (feeds A6 food-safety pillar; soft CAPA link until a register exists).
- `id` INT UNSIGNED AUTO_INCREMENT PK
- `supplier_id_fk` INT UNSIGNED NOT NULL → `ref_suppliers.id` ON DELETE CASCADE
- `detected_on` DATE NOT NULL, `nc_type` ENUM('food_safety','quality','delivery','documentation','esg','other') NOT NULL
- `severity` ENUM('mineure','majeure','critique') NOT NULL
- `description` TEXT NOT NULL
- `delivery_id_fk` INT UNSIGNED NULL → `inv_deliveries.id` (EXACT — confirm inv_deliveries PK type at build; soft link to the offending delivery) ON DELETE SET NULL
- `capa_register` VARCHAR(20) NULL DEFAULT 'MA-01', `capa_ref` VARCHAR(64) NULL (-- FUTURE: replace with capa_id_fk when a CAPA register table lands; NO FK now — MA-01 is doc-only)
- `status` ENUM('open','in_progress','closed') NOT NULL DEFAULT 'open', `closed_on` DATE NULL, `resolution` TEXT NULL
- `triggered_evaluation` TINYINT(1) NOT NULL DEFAULT 0 (a majeure/critique NC arms an événementiel re-eval)
- `created_at`/`created_by`
- INDEX `ix_snc_supplier (supplier_id_fk, detected_on)`, INDEX `ix_snc_open (status, severity)`. schema_meta: **source / allowed**.

**ALTER `ref_suppliers` — additive ONLY (one col, derived-with-override):**
- `ADD COLUMN criticality ENUM('critique','non_critique') NULL` (NULL = not yet ratified/derived; default to derived value via a one-shot seed AFTER operator ratifies the category set; manual override via a pin / governance write follows the creation≠trust discipline). Do NOT add a second flag — one ENUM, NULL-as-unset. Do NOT touch vat_regime/gl_account/hors_perimetre_cogs/parser_key.
- schema_meta unaffected (ref_suppliers already classified). The col is governance metadata; criticality drives cadence (critique=annuel, non_critique=biennal).

**Grid seed (Wave 1, in the migration):** insert EF-01 v1.0 grid + its 11 criteria rows with the exact max_scores above and `is_food_safety_ko=1` on the A food-safety criteria the operator designates (A1/A2/A3/A5/A6 are candidates — confirm which single criterion (or set) is the KO trigger from EF-01; the doc says "critère éliminatoire food-safety" → likely A3 conformité livraisons + A2 COA OR a dedicated KO. ASK operator which criteria carry the KO; do NOT guess.).

### 2) WAVE SEQUENCE
- **Wave 1 — SQL foundation (BLOCKING, serial).** ONE migration: 6 new tables + ref_suppliers ALTER + grid+criteria seed + 6 schema_meta rows. EQUIP **sql+coder**. Gate: `migrate.php --status` clean, all FK types match, schema_meta rows present. NB criticality seed (the derive UPDATE) is a SEPARATE step AFTER operator ratifies categories — Wave 1 lands the col NULL; do not auto-seed criticality until ratification.
- **Wave 2 — handlers / write endpoints (depends W1).** `public/api/sf-evaluation-save.php` (create/update eval header + criteria; recompute pillar scores + total_pct + KO + result server-side; require_login+role gate (admin write / manager propose)+csrf_verify+log_revision+snapshot+PRG), `sf-nc-save.php`, `sf-cert-link.php`, `sf-criticality-override.php`. Plus a READ endpoint `sf-supplier-evaluation.php?supplier_id=` (auto-feed: delivery conformity/lead-time from inv_deliveries + derived criticality + eval history + NC list + certs). EQUIP **coder+sql**. Auto-feed compute lives here (server-side, read-only over canonical tables). Can build the read endpoint + write endpoints in PARALLEL (distinct files).
- **Wave 3 — Fournisseur-fiche evaluation TAB UI (depends W2 read endpoint).** New JS-rendered panel in `#sf-fiche` (salle-fournisseurs.js + salle-fournisseurs.css; PHP page only adds the tab shell + nav). Renders the grid (auto-filled criteria pre-scored, manual criteria as inputs), classification badge (Agréé/sous-surveillance/non-agréé + KO banner), NC list, cert links, criticality (derived + override toggle), valid_until + "⚑ à réévaluer" state. EQUIP **ui+coder** (CSS in /public/css, JS in /public/js — never inline). Single-owner rule on salle-fournisseurs.js/.css.
- **Wave 4 — periodic-review cron + auditor export (depends W1+W2; PARALLEL with W3).** Cron `scripts/send-supplier-review-reminders.php` (CLONE `scripts/send-credential-expiry-reminders.php` — same flock/user=maltytask/--dry-run-default/deployed-DISABLED/send_mail()+mail_account_template() shape; scans `supplier_evaluations` WHERE status='final' AND valid_until ≤ today+lead → emails "à réévaluer" + writes/refreshes a dashboard/intake signal). Export `public/api/supplier-evaluation-export.php` (CLONE `public/api/cogs-comprehensive-csv.php` — pure-PHP fputcsv, no xlsx, operator standing ruling; one row per supplier × latest eval + criteria + result + valid_until + NC count, for the auditor). EQUIP **coder+sql** (cron), **coder+sql** (export). Cron and export are independent files → parallel.
- **Wave 5 — smoke + verify (depends W1-W4).** EQUIP **webapp-testing** (READ-ONLY vs prod): fiche tab renders for a sample supplier, score recompute + KO behave, cert link resolves a doc_files row, NC entry persists, export downloads, cron `--dry-run` emits the right "à réévaluer" set. Opus independently spot-checks: a hand-scored eval matches the engine's total_pct + classification; KO forces non_agree regardless of score; criticality derive matches the JOIN counts.

**Parallelizable:** W2 read-endpoint ∥ W2 write-endpoints; W3 ∥ W4. Everything gates on W1.

### ✅ BUILD STATUS — FULL BUILD SHIPPED + LIVE (all 5 waves, smoke 8/8 PASS) 2026-06-19
**DONE/LIVE. Carry-forward: commits LOCAL-only (NOT pushed) — push deferred to Kouros; operator enables cron + sets system_settings recipient/lead; first real evaluation campaign is operator/quality-team work.**

**As-built migs/DDL (as-built deviations from the PLAN, captured):**
- **Mig 409** = the 6 tables EXACTLY per the DDL above (`supplier_evaluation_grids`[SMALLINT PK] / `_grid_criteria` / `supplier_evaluations` / `supplier_evaluation_criteria` / `supplier_cert_documents`[FK `doc_file_id_fk`→doc_files.id BIGINT] / `supplier_nc`[`capa_ref` soft text + `capa_register` col]) + `ref_suppliers.criticality ENUM` additive + **EF-01 grid seed (Pilier A 22-max / B 13-max, thresholds 75/50)** + 6 schema_meta rows. **Mig 410** = KO flags on the seeded criteria.
- 🔴 **As-built correction to the DDL: `inv_deliveries.id` is BIGINT (not INT)** → `supplier_nc.delivery_id_fk` landed **BIGINT UNSIGNED** (the DDL above said "confirm inv_deliveries PK type at build" — it was BIGINT). Any future FK to inv_deliveries.id must be BIGINT UNSIGNED.
- **KO criteria ratified by operator** = **A2 (COA) + A5 (traçabilité)** are the `is_food_safety_ko` criteria (resolves the "ASK operator which criterion is the KO" open in the DDL grid-seed note). A KO on either forces result='non_agree' regardless of weighted score.

**Criticality (ratified + seeded one-off):**
- **Critical category set RATIFIED** = {Malt, Hops, Yeast, Brewing Adjunct, Brewing Mineral, **Packaging (ALL)**, **Cleaning Chemical (CIP)**}. Process Chemical + Taproom stay **non-critique** (resolves the §4 grey-zone asks: ALL packaging counts; CIP IS critique by residue risk; Process Chemical is NOT).
- **One-off seed result: 24 critique / 35 non_critique / 77 NULL.** The 77 NULL = no-delivery suppliers → get a MANUAL override as used (derived-with-override working as designed; no-delivery edge resolved by override not by widening the set).

**Wave 2 (endpoints) — shared pure scoring engine:**
- `app/supplier-eval-helpers.php`::`supplier_eval_compute()` = **server-side score authority** — applicable-max % (handles "sans objet" criteria), **KO-forces-non_agree**, threshold tiers. Single source for the math; UI is display-only.
- Handlers: `sf-evaluation-save` (supersession chain, `valid_until` set by criticality cadence — critique=annuel/non_critique=biennal), `sf-nc-save`, `sf-cert-link`, `sf-criticality-override` (writes ref_suppliers.criticality ONLY — additive guard honoured), read `sf-supplier-evaluation` (autofeed: delivery_count, last_delivery, MI categories, is_critical_derived, NC counts). All mirror the existing `sf-validate-supplier` shape (JSON `{ok,error}` envelope, `is_admin` gate).

**Wave 4 — cron + export (SHIPPED):**
- Cron `scripts/send-supplier-review-reminders.php` (clone of the credential-expiry watchdog; `--dry-run` default; **deployed DISABLED**; dry-run emitted the 24 critique never-evaluated in bucket C). Recipient = `system_settings.ops.supplier_review_alert_recipient` → admin fallback; lead = `ops.supplier_review_lead_days` (default 60).
- Export `public/api/supplier-evaluation-export.php` = pure-PHP CSV, 11 cols, critique-first ordering, all suppliers (operator standing CSV ruling honoured).

**Wave 5 — smoke 8/8 PASS:** render, happy-path (hand-calc **68.57% == displayed** — Opus independent spot-check satisfied), KO (A2=0 → 88.57% raw forced to non_agree + banner), supersession tag, criticality override+restore, NC, cert, CSV export. **All test rows self-cleaned** (COUNTs=0), criticality distribution restored 24/35/77, throwaway admin purged, no real user touched.

**Commits (maltyweb, LOCAL-only — NOT pushed; push deferred to Kouros; parallel planning/expeditions session commits interleaved in the shared clone):**
- `d0a11dc` (mig 409/410) · `561ba4f` (handlers) · `7b10538` (UI) · `b522ec8` (cron+export).

**🔴 CARRY-FORWARD OPEN:**
- Push deferred (shared clone has interleaved parallel-session commits) → Kouros pushes.
- Operator enables the cron + sets `system_settings.ops.supplier_review_alert_recipient` / `supplier_review_lead_days`.
- 24 critique suppliers flagged "à évaluer" — first real campaign is operator/quality-team work.
- **A6 (NC handling) score stays MANUAL** — autofeed shows NC count as evidence but does NOT auto-score A6.
- 77 NULL-criticality (no-delivery) suppliers get manual override as used.
- `supplier_nc.capa_ref` is soft text (MA-01 doc-only) — if a CAPA DB register is ever built, wire it (the `-- FUTURE FK` hook).
- Companion doc: **EF-01** (`Procedure-Evaluation-Fournisseurs-2026.pdf`).

#### Original landing note (W1+W2 — superseded by the FULL-BUILD block above, kept for the endpoint paths)
- **W1 (SQL foundation) + W2 (endpoints) = LANDED/LIVE.** All five endpoints exist on the VPS+repo: read `public/api/sf-supplier-evaluation.php` + writers `sf-evaluation-save.php` / `sf-nc-save.php` / `sf-cert-link.php` / `sf-criticality-override.php`.
- **✅ W3 (Fournisseur-fiche Évaluation UI) = SHIPPED + LIVE 2026-06-19.** Render layer only — NO PHP changes (smoke = 302→login, not 500). Files deployed + md5-verified local↔VPS:
  - `public/js/salle-fournisseurs.js` md5 `98707eeb28d3f75ab063821efdeda83b` (1758 lines, +667)
  - `public/css/salle-fournisseurs.css` md5 `061b0ed9994b20bdef214786562e4048` (1772 lines, +495)
  - **As-built:** new `renderEvalSection(supplierId)` async fn appended to `#sf-doc-paper` inside the fiche, called at end of `openFiche(id)` (js L583); `renderEvalContent()` orchestrates 6 subsections; `wireEvalEvents(sectionEl,data,supplierId)` wires button handlers post-render; **re-fetch pattern** — every successful write re-calls `renderEvalSection(supplierId)` (re-fetch + re-render). Stacked `sf-eval-section` after governance (NO new sub-nav/tab-strip), as planned.
  - **6 subsections LIVE:** A `renderEvalStatus` (criticality badge critique/non_critique/undefined + derived-vs-manual label + admin override toggle→`sf-criticality-override.php` + latest-result badge agree/agree_sous_surveillance/non_agree/draft + total_pct% + food_safety_ko banner + "à évaluer" flag); B `renderAutofeed` (read-only: livraisons count, dernière livraison, MI-catégorie chips, NC ouvertes/total — only non-null metrics); C `renderEvalForm` (11-criterion grid grouped Pilier A/B, score select 0..max + sans objet, evidence note, KO tag on is_ko_flag criteria, evaluation_type + evaluated_at + explicit_ko + comment, "Enregistrer brouillon"/"Finaliser"→`sf-evaluation-save.php`, **live pillar subtotal display**); D `renderEvalHistory` (past evals + "remplacée" supersede tag); E `renderNcSection` (NC list + severity chips + collapsible add-form→`sf-nc-save.php`); F `renderCertsSection` (cert list + expiry highlight red/amber + collapsible link-form→`sf-cert-link.php`).
  - **Discipline honoured:** zero inline styles; all dynamic values through `escHtml()`; collapsibles via `.open` class toggle. Server remains SCORE AUTHORITY (display-only client; KO/result reflected from endpoint).
- **🔴 W4 (periodic-review cron + auditor CSV export) = STILL PENDING** — `scripts/send-supplier-review-reminders.php` (clone `send-credential-expiry-reminders.php`) + `public/api/supplier-evaluation-export.php` (clone `cogs-comprehensive-csv.php`). PARALLEL-eligible; gates only on W1+W2 (both done). EQUIP coder+sql.
- **🔴 W5 (smoke + verify) = STILL PENDING** — webapp-testing READ-ONLY prod pass + Opus independent spot-checks (hand-scored eval == engine total_pct/classification; KO forces non_agree; criticality derive matches JOIN counts). EQUIP webapp-testing. Page Tailscale/auth-gated → recommend authenticated manager-login UAT to operator (curl smoke only confirms 302→login, not the rendered tab).

### HOST-PAGE FACTS (verified live 2026-06-19 — for the W3 builder)
- **NOT a tab-strip page.** No design-system `tab-strip`/`role="tab"`/`aria-selected`/PRG. The fiche is a single JS-rendered "kraft paper" doc: `<div id="sf-fiche">` (php L513) hydrated CLIENT-SIDE by `salle-fournisseurs.js` as ONE `ficheEl.innerHTML = \`<div class="sf-doc-paper">…\`` template (~L504) of **stacked sections** (sf-doc-head → statsStrip → sf-doc-fields → `sf-gl-section` ~L274 → `sf-gov-section` ~L313 → validate btn). **NO in-fiche sub-nav exists.** ⇒ Évaluation = a NEW stacked section `sf-eval-section` (mirror `sf-gov-section`) appended after governance; fed ON DEMAND from `sf-supplier-evaluation.php?supplier_id=`. A real sub-nav/tab-strip would be a NEW pattern for this page — only if operator wants it; flag to PM to record.
- **Body class = `body.salle-fournisseurs`** (php L333 `<body class="home salle-fournisseurs" data-role=…>`). CSS file scopes EVERYTHING under `body.salle-fournisseurs .sf-*` with explicit no-leakage contract → new rules MUST be `body.salle-fournisseurs .sf-eval-…`.
- **CSRF = `window.SF_CSRF`** (php L551 `csrf_token()`) read in JS as `const CSRF = window.SF_CSRF` (L26); POSTed as form field **`csrf`** (NOT a header). Server: `require app/csrf.php` + `csrf_verify($_POST['csrf'] ?? null)` (pattern in sf-evaluation-save.php L31/L55). **AJAX/JSON envelope `{ok,error}`, NO PRG/redirect** — on success re-fetch read endpoint + re-render section.
- **Design vocab to REUSE (no new hex):** sections `.sf-<x>-section`/`.sf-<x>-head`/`.sf-<x>-section-title`; provenance badges `sf-prov`+`sf-prov-{locked,verified,gap,auto}` (use auto/verified for auto-filled criteria, gap for unscored manual); micro btns `sf-btn-micro`; tags `sf-gl-modal-tag`/`sf-gl-excluded-tag` idiom → make `sf-eval-badge` w/ result modifiers; CSS vars `--dock`/`--oak`/`--ember` (ember=critical → KO banner + non_agree); `completenessRing()`/`sf-ring` SVG precedent for score progress; bars `sf-gl-bar-*` precedent for pillar A/B; helpers `escHtml()`/`fmtDate()` already in file — reuse, don't re-inline.
- **Read endpoint keys** (sf-supplier-evaluation.php): `{ok, supplier:{id,name,criticality}, grid:{id,code,version,criteria:[{id,pillar,code,label,max_score,is_ko_flag,display_order}]}, latest_evaluation:<header+criteria_scores>|null, nc:[…], certs:[… joined doc_files file_name], deliveries:<conformity/lead-time>|derived-only}`. Manager+admin read; admin write (sf-evaluation-save = "Admin uniquement").
- **SCORE AUTHORITY = server.** Render total_pct/pillar scores/result/KO from endpoint output; do NOT recompute classification client-side (display only). KO forces non_agree — reflect from server.
- **Deploy:** surgical per-file scp (3 files) — deploy.sh pushes whole dirty tree (parallel sessions live); `php8.1 -l` deployed PHP before fpm reload; md5 local↔VPS ×3; commit by PATHSPEC. RULE-3 tour does NOT trigger (not in ref_pages). EQUIP ui+coder(+webapp-testing structural/asset smoke; page Tailscale/auth-gated → recommend manager-login UAT to operator).

### 3) PATTERNS TO CLONE (paths)
- **Periodic-review cron** ← `scripts/send-credential-expiry-reminders.php` (the ops_credential_expiry watchdog, BUILT 2026-06-16, 17KB) — near-exact shape: flock, user=maltytask, lead-day stages, `--dry-run` default, deployed DISABLED + pre-flight header, reuses `send_mail()` + `mail_account_template()`. Recipient precedence: row → system_setting → admins.
- **Alert surface:** primary = **email** (the cron, like credential-expiry/recap). SECONDARY = a **"⚑ à réévaluer" intake state ON the fiche** (mirrors the "à valider" draft-pool pattern — the eval row with `valid_until ≤ today` renders a flag in the registry list). Do NOT use doc_review_queue (that's document-triage-typed; a supplier re-review is not a doc-classify event — a parallel-purpose misuse). A dashboard KPI card is OPTIONAL future (could add a ref_kpi_trackers tracker "fournisseurs à réévaluer" later — out of v1 full-build scope unless operator asks).
- **Auditor export** ← `public/api/cogs-comprehensive-csv.php` (pure-PHP fputcsv, Content-Type text/csv + Content-Disposition attachment, no xlsx dependency — operator standing CSV ruling). Lighter sibling `public/api/cogs-fiche-csv.php` if a single-section export suffices.

### 4) CRITICALITY RATIFICATION — candidate category list (operator yes/no BEFORE the seed)
JOIN path CONFIRMED: `inv_deliveries.supplier_fk → ingredient_fk → ref_mi.category_id → ref_mi_categories` (NO mi_id_fk; ingredient_fk is the resolved MI id). Live `ref_mi_categories` (24 rows). **Candidate FOOD-CONTACT / food-safety-critical set (put to operator as a checklist):**
- **1 Malt** ✓ (direct ingredient) — 2 suppliers
- **2 Hops** ✓ — 5 suppliers
- **3 Yeast** ✓ — 2 suppliers
- **4 Brewing Adjunct** ✓ — 3 suppliers
- **11 Brewing Mineral** ✓ (water treatment salts, ingested) — 1 supplier
- **8 Packaging** ✓ DIRECT FOOD CONTACT (bottles/caps/cans touch the beer) — 11 suppliers — **operator must confirm: ALL packaging, or only primary-contact (bottle/can/cap) vs secondary (cartons/labels/film)?** The category doesn't sub-split contact vs non-contact — flag this as the one nuance to resolve.
- **GREY / operator-decide:** 5 Process Chemical (CO₂, filtration aids — some contact product), 7 Cleaning Chemical (CIP — indirect, residue risk), 35 Taproom & Foodtour (food service). Default: 5/7 = NON-critique (indirect) unless operator says CIP residue makes them critical.
- **CLEARLY non-critique:** 6 Sales, 9 Logistics, 10 Utilities, 12 Transport, 13 NonBeer, 14 Maintenance, 15 R&D, 31 Cautions, 33 Immobilisation, 34 Tax, 36 Cliches, 37 Matériel prod, 39 Frais admin, 40 Inspections.
- **RATIFICATION ASK:** "Critique = food-contact ⇒ these category ids: {1,2,3,4,11,8?} — confirm each, especially whether ALL Packaging (8) counts or only primary-contact, and whether Process Chemical (5)/Cleaning Chemical (7) are in or out." Once ratified → one-shot UPDATE seeds `ref_suppliers.criticality` from the JOIN (any supplier with ≥1 delivery line in a critical category = critique), then manual override available. Edge cases (logistics supplier handling food, one-off) resolved by override, NOT by widening the category set.

### 5) RISK / DIVERGENCE FLAGS (full-build specific)
- **ADDITIVE ONLY — one col on ref_suppliers** (`criticality` ENUM, NULL-as-unset). MUST NOT touch vat_regime/gl_account/hors_perimetre_cogs/parser_key/commissioning_state or the pin/ingest write paths. The eval layer is a NON-FISCAL observation layer: NEVER feeds COGS/COP/WAC/BOM/beer-tax/stock. Encode that in schema_meta + code comments (like Planning/QA).
- **NO parallel supplier store:** eval/NC/cert tables read identity from ref_suppliers by FK; criticality reads MI category by the live JOIN. Do NOT snapshot supplier name/GL/category/MI-category into eval tables — read by JOIN every time (string-copy = silent divergence).
- **NO parallel doc store:** cert links FK `doc_files.id` (the canonical store). Do NOT build a `supplier_documents` blob/file store. Uploads route doc_uploads→pipeline→doc_files.
- **NO guessed CAPA table:** MA-01 is doc-only; `supplier_nc.capa_ref` is a soft text ref, NO FK. Building a CAPA register is a SEPARATE arc — don't infer its schema here.
- **Grid versioning is the anti-rescore guard:** each eval pins `grid_id_fk`; criteria pin `grid_criterion_id_fk`. When the grid evolves, a NEW grid version row is added (old retained, is_active flips) — past evals keep their old grid + scores. NEVER mutate a grid_criteria row's max_score in place after evals reference it (would silently rescore history) — add a new grid version.
- **Score recompute is server-side, never trust client totals:** the save handler recomputes pillar_a/b_score, total_pct, KO, result from the criteria rows + the pinned grid maxes. KO (food_safety_ko) forces result='non_agree' regardless of total_pct — assert this in Wave 5.
- **`inv_deliveries` has NO mi_id_fk** — the auto-feed + criticality JOIN MUST use `ingredient_fk` (resolved) not a non-existent mi_id_fk. A builder copying a stale JOIN from elsewhere will silently get 0 rows.
- **RULE-3 tour: does NOT trigger** (no new ref_pages row — salle-fournisseurs isn't in ref_pages). If operator later promotes it to a real page_key + topbar, tour applies then.
- **MIG HEAD 408; next free 409 — RE-VERIFY at Wave-1 start** (parallel sessions lead; 397 was a known pending-parallel landmine — never re-number/apply another session's file).
- **Commit by PATHSPEC** (shared dirty tree, parallel sessions); deploy surgically per-file (deploy.sh pushes the whole working tree). md5 local↔VPS after deploy.
