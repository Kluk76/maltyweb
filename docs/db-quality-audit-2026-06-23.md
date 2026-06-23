# maltytask DB — Schema Quality Audit & Consolidation Proposal

**Date:** 2026-06-23 · **Scope:** READ-ONLY / PROPOSE-ONLY (no DDL/DML applied) · **Auditor:** senior DB architect (Claude)
**Method:** live `information_schema` + targeted COUNT/orphan/NULL probes via `sudo -u www-data php8.1` on the maltyweb VPS; `schema_meta` (the DB's own table-classification table) used as the oracle; convention guardrails confirmed with the `maltyweb-pm` agent so intended designs are not flagged.

---

## Executive summary

**Size & shape.** 142 base tables + ~16 views. Largest by storage: `comm_messages` (100 MB, 2 009 rows — large BLOB/email bodies), `inv_sales_ledger` (18 MB, 44 127 rows), `inv_consumption` (12.7 MB, 16 283), `audit_row_revisions` (12 MB, 22 016). Everything else is small (< 8 MB). This is a **small, well-tended DB**, not a sprawling legacy heap.

**Overall health: GOOD, with a few systematic hygiene debts.** The fundamentals are strong:
- **Referential integrity is excellent.** Every FK column carries an explicit constraint with a deliberate `ON DELETE` rule; **0 orphan rows** on all 7 critical fiscal FKs probed (`inv_sales_ledger.sku_id_fk`, `inv_consumption.mi_id_fk`, `ref_sku_bom.{mi_id,sku_id}`, `inv_deliveries.ingredient_fk`, `bd_packaging_v2.sku_id_fk`). **0 FK columns missing an index.**
- **FK type discipline is clean** — INT→INT / BIGINT→BIGINT throughout, matching the documented convention (`ref_*.id` INT UNSIGNED, `bd_*`/`doc_*`/`inv_*`/event BIGINT UNSIGNED). The one documented exception (`ref_packaging_formats.id` BIGINT) is consistently honored by its consumers.
- **Self-documenting:** all but 5 base tables are classified in `schema_meta`; the 5 unclassified are all dated backup/probe leftovers.

**The debts cluster into three themes**, all instances of the operator's stated symptom (one fact stored in many places, free to drift):
1. **A triple-modeled "packaging format" identity** — stored as a free VARCHAR in 3 tables, a 5-value ENUM in 5 tables, and an FK in others, with **three divergent vocabularies already live** (`'Bot'` vs `'bot'` vs `'Cuve de service'` vs `'Cuv'`). This is the data-level root of the `run_type→label` duplication that prompted the audit.
2. **Repeated ENUM definitions** for shared dimensions (`run_type`, beer `subtype`, beer `type`/`classification`) defined independently on 2–7 tables each — change one, the others silently disagree.
3. **Collation drift** on the 3 newest tables (`utf8mb4_0900_ai_ci` instead of the project standard `utf8mb4_unicode_ci`) — a latent JOIN-failure trap.

**Top 5 highest-value consolidations** (detail + DDL below):

| # | Consolidation | Impact | Risk |
|---|---|---|---|
| 1 | Reconcile + retire `ref_skus.format` / `ref_cogs_targets.format` / `bd_packaging.format` VARCHARs against the `format_id` FK + canonical `run_type` (fix the 6 NULL `format_id` first) | High — kills the drift class that motivated the audit | Med |
| 2 | Fix 3-table collation drift → `utf8mb4_unicode_ci` | Med — prevents a real future JOIN-failure | Low |
| 3 | Drop the duplicate FK constraint `ref_skus.fk_sku_recipe` (identical twin of `fk_skus_recipe`) | Low effort, removes a redundant index + write cost | Low |
| 4 | Add the missing FK on `kpi_sku_seasonal_index.recipe_id` (and clean its **53 orphan rows** pointing at non-existent recipes) | Med — silent integrity hole in a live KPI table | Low–Med |
| 5 | Drop 10 exact-duplicate + ~36 prefix-redundant indexes | Low — write-amplification + clutter cleanup | Low |

---

## Findings by dimension

### Dimension 1 — Parallel / divergent stores

#### 1.1 Packaging-format identity is triple-modeled with three divergent vocabularies — **HIGH**

**What.** The "packaging format / run type" concept is stored three different ways across the schema:
- **FK (canonical):** `ref_skus.format_id` → `ref_packaging_formats.id`; `ref_packaging_formats.run_type` is the canonical 5-value ENUM.
- **Free VARCHAR label:** `ref_skus.format` `varchar(16)`, `ref_cogs_targets.format` `varchar(32)`, `bd_packaging.format` `varchar(16)` (v1 table).
- **ENUM:** `run_type ENUM('bot','can','can33','keg','cuv')` independently on `bd_packaging_v2`, `dbc_container_types`, `dbc_packaging_format_templates`, `ref_packaging_formats`, + 2 views.

**Evidence (the vocabularies have already drifted):**
```
ref_skus.format            = {Bot, Can, Can33, Cuve de service, Keg, Vrac}
ref_cogs_targets.format    = {Bot, Can, Cuv, Keg}          ← 'Cuv' (≠ 'Cuve de service'), no 'Can33', no 'Vrac'
ref_packaging_formats.run_type = {bot, can, can33, keg, cuv}  ← lowercase, 'cuv' not 'Cuve de service'
```
`ref_skus`: 207 rows, `format` populated on all 207, but **6 rows have `format_id IS NULL`** while still carrying a string `format` (`PAD`, `PACKDECX8`, `PBD`, `EPH24P`, `EXP12C`, `EXP24C` — all `'Bot'`/`'Can'`). Those 6 are the unresolved-link smell: a label with no FK.

**Why it's a problem.** Three vocabularies for one concept means any consumer that reads the VARCHAR sees a different spelling than one reading the ENUM or joining the FK. A `run_type`→French-label map (the symptom that prompted this audit) has to special-case `'Bot'`/`'bot'`/`'Cuve de service'`/`'Cuv'`/`'Vrac'`/`'cuv'`. COGS-target variance keys on `ref_cogs_targets.format` whose vocabulary doesn't even cover all formats. This is the canonical "parallel store drifts" failure.

**Proposed fix (migration-ready sketch, staged — PM flagged the VARCHAR may still be a live read, so reconcile-then-gate, do NOT drop blind):**
```sql
-- Stage 1 (safe): resolve the 6 NULL format_id from the string label, via canonical map
--   map {Bot→bot, Can→can, Can33→can33, Keg→keg, Cuve de service→cuv, Vrac→<decide>}
UPDATE ref_skus s
  JOIN ref_packaging_formats f
    ON f.run_type = CASE s.format
         WHEN 'Bot' THEN 'bot' WHEN 'Can' THEN 'can' WHEN 'Can33' THEN 'can33'
         WHEN 'Keg' THEN 'keg' WHEN 'Cuve de service' THEN 'cuv' END
  SET s.format_id = f.id
 WHERE s.format_id IS NULL;     -- dry-run COUNT first; 'Vrac' needs an operator decision

-- Stage 2 (after grep proves no live reader of the VARCHAR): retire the denormalized labels
--   ALTER TABLE ref_skus DROP COLUMN format;            -- replace reads with JOIN ref_packaging_formats
--   ALTER TABLE ref_cogs_targets <re-key on format_id FK or canonical run_type>;
```

**Risk:** Medium. Stage 1 is low-risk and high-value. Stage 2 requires a code grep (`ref_skus.format`, `cogs_targets.format`, `bd_packaging.format` reads) + operator sign-off — `ref_cogs_targets` feeds COGS variance, so re-keying moves a reported number. **Needs operator decision** on the `'Vrac'` SKU (no matching `run_type`) and whether `ref_cogs_targets` should key on `format_id`.

#### 1.2 No other accidental parallel stores found — **(clean)**

Probed the other `*_raw` / label-vs-FK pairs the PM flagged as intentional and confirmed they are **legitimate provenance, not drift**: `inv_deliveries.mi_id_raw` (re-resolution input), `ref_skus.beer_raw`/`unit_label` (no-JOIN display), `ref_mi.mi_id` / `ref_suppliers.supplier_id` (legacy VARCHAR codes kept as stable external identifiers alongside the INT PK). Do not touch.

---

### Dimension 2 — Redundant reference / lookup data

#### 2.1 `run_type` ENUM defined independently on 5 tables + 2 views — **MED**

**What.** `ENUM('bot','can','can33','keg','cuv')` is redeclared verbatim on `bd_packaging_v2.run_type`, `dbc_container_types.run_type`, `dbc_packaging_format_templates.default_run_type`, `ref_packaging_formats.run_type`, and reflected in `v_bd_packaging_v2_vendable` / `v_sku_volume`.

**Why.** There is no single source for the run-type value set. Adding a 6th format (e.g. a new can size) means a coordinated `ALTER` on 4+ tables; miss one and inserts silently truncate/reject. `ref_packaging_formats` already exists and *is* the natural canonical home (it has the `run_type` column + the format catalog).

**Proposed fix.** Treat `ref_packaging_formats` as the run-type dimension of record. Where a table needs run-type, prefer `format_id` FK (and read `run_type` via JOIN) over an independent ENUM. Where an ENUM must stay for ergonomics (e.g. `bd_packaging_v2` operator form), document `ref_packaging_formats.run_type` as the master list and add a comment/CHECK that the ENUM members must equal it. **Risk:** Med — touches a form-write table; needs review, not mechanical.

#### 2.2 Beer `subtype` and `type`/`classification` ENUMs duplicated across `ref_beer_types` ↔ `ref_recipes` — **MED**

**What.**
- `subtype ENUM('Core','EPH','CollabIn','CollabOut','WhiteLabel','Archive')` on **both** `ref_beer_types.subtype` and `ref_recipes.subtype`.
- Beer Neb/Contract type: `ref_beer_types.type ENUM('Neb','Contract')` and `ref_recipes.classification ENUM('Neb','Contract')` — **same value set, different column name.**

**Why.** Per project doctrine, `ref_beer_types` is *canonical for ALL beer metadata*. Storing the same classification independently on `ref_recipes` is a denormalized copy that can disagree with the canonical table, and the column-name divergence (`type` vs `classification`) hides the duplication. (Not yet quantified whether they currently agree — see "needs verification" below.)

**Proposed fix.** Confirm `ref_beer_types` is the single source; on `ref_recipes`, either drop `subtype`/`classification` and read via the recipe→beer-type link, or document them as a denormalized cache with a reconciliation check. **Risk:** Med — both are read by beer-tax/COGS routing; **needs operator/PM decision** on which table owns the value. Verify current agreement before any change.

#### 2.3 `last_modified_by ENUM('ingest','web')` on ~12 `ref_*`/`inv_*` tables — **(accept, document)**

This is a deliberate per-table provenance flag (protects rows from re-ingest overwrite), not redundant reference data. No action; noted so a future pass doesn't re-flag it.

---

### Dimension 3 — Normalization smells

#### 3.1 `bd_packaging` (v1) — 58-column wide table — **(known, gated)**

`schema_meta` itself notes "58-column wide table; CTO §3.7 candidate for split." This is a v1 table on the decommission path; the v2 successor (`bd_packaging_v2`) is the live target. **No action recommended now** — folds into the v1 `bd_*` drop arc (Dimension 5).

#### 3.2 Derived tables are correctly modeled as derived — **(clean)**

`inv_consumption`, `ref_sku_bom`, `wac_snapshots`, `cop_monthly`/`cogs_monthly`/`mi_weighted_prices_monthly`, the `ref_recipe_profile*` family, and the `v_*` views are all classified `derived` in `schema_meta` with `blocked_with_redirect` correction policies pointing at their recompute scripts. The computed/raw boundary is explicit and respected. No stored-computed-value-that-should-be-a-view smells found in the reference layer.

---

### Dimension 4 — NULL discipline

Quantified NULL counts on every **nullable, constraint-backed** FK across all populated tables. Most high-NULL columns are **semantic** (legacy-imported rows that predate `session_id_fk`/`submitted_by_user_id_fk`; optional `source_email_id_fk`/`transporter_id_fk`; self-referential `parent_session_id_fk`). Those are fine. The ones that read as **accidental/unresolved smells**:

| Column | NULLs / total | Read | Why a smell |
|---|---|---|---|
| `ref_customers.default_transporter_id_fk` | 3064 / 3064 (**100%**) | — | Column has **never been populated** on any of 3 064 customers. Either dead (drop) or an unfinished feature (backfill). |
| `ref_customers.default_delivery_site_id_fk` | 2995 / 3064 (97.7%) | — | Same — near-totally unused; decide dead-vs-unfinished. |
| `inv_sales_order_lines.sku_id_fk` | 2957 / 6829 (43.3%) | High | Documented partly semantic (non-beer lines: vouchers/merch). But 43% is high — verify the non-NULL/NULL split matches the non-beer line count; residual unresolved beer lines would be a real gap. |
| `inv_sales_ledger.sku_id_fk` | 11 150 / 44 866 (24.9%) | High | Same question on the 44k-row B2B quantity SoR. 25% SKU-unresolved on the canonical sales-quantity table is worth quantifying against the known non-beer/credit-line population. |
| `ref_mi.consumption_unit` | 319 / 380 (83.9%) | Med | FK→`ref_units.code`; 84% of MIs have no consumption unit. Likely semantic (packaging/service MIs aren't consumed by unit), but high enough to confirm it's intentional. |

**Proposed fix.** For the two `ref_customers.default_*` columns: decide drop (if the feature was abandoned) or backfill-at-master-data-root. For the two `sku_id_fk` columns: run a breakdown of NULLs by line type to confirm 100% of NULLs are genuinely non-beer/credit (semantic) and none are unresolved beer SKUs (accidental). **Risk:** Low. **Needs verification** (one breakdown query each) before deciding drop-vs-resolve; the `ref_customers.default_*` drop **needs operator decision**.

---

### Dimension 5 — Referential integrity

#### 5.1 `kpi_sku_seasonal_index.recipe_id` — no FK constraint + 53 orphan rows — **MED**

**What/Evidence.** `recipe_id INT UNSIGNED NOT NULL` has **no FK constraint** to `ref_recipes`, and **53 of 795 rows point at a `recipe_id` that does not exist in `ref_recipes`**.

**Why.** A `derived` table (rebuilt by a script) but the orphans mean the seasonal-index KPI is computing rows keyed to recipes that aren't in the master — silently dropped or mis-joined at read time. Even derived tables should not carry dangling identity.

**Proposed fix.**
```sql
-- 1. investigate the 53 (are they retired/EPH re-IDed recipes? tombstoned?)
SELECT recipe_id, COUNT(*) FROM kpi_sku_seasonal_index k
 WHERE NOT EXISTS (SELECT 1 FROM ref_recipes r WHERE r.id=k.recipe_id)
 GROUP BY recipe_id;
-- 2. fix the rebuild script to only emit resolvable recipe_ids (root cause), then:
-- ALTER TABLE kpi_sku_seasonal_index
--   ADD CONSTRAINT fk_kpi_seasonal_recipe FOREIGN KEY (recipe_id) REFERENCES ref_recipes(id);
```
**Risk:** Med — must fix the producer (so the next rebuild doesn't re-orphan) **before** adding the constraint, or the rebuild will fail. **Needs verification** of what the 53 recipe_ids are (EPH re-vintage IDs is the likely cause given the codebase's known EPH re-ID behavior — do not guess; query).

#### 5.2 Other FK-less identity columns — **(verified mostly clean)**

Of 91 columns matching `*_fk`/`*_id` without a constraint, after excluding views and snapshot tables, nearly all are legitimately not FKs: external system IDs (`shopify_*`, `bc_line_id`, `external_order_id`, `gmail_*`, `drive_file_id`), the canonical `doc_files.file_id` UUID, legacy VARCHAR codes (`ref_mi.mi_id`, `ref_suppliers.supplier_id`), and audit-table `user_id` columns (audit logs deliberately don't FK so they survive user deletion). Only `kpi_sku_seasonal_index.recipe_id` (5.1) is a genuine missing-FK finding. `inv_consumption.source_row_id` and `pl_plan_items.linked_event_id` are polymorphic (point at different source tables by `source_event`/`pkg_type`) — correctly left FK-less; document as intentional.

#### 5.3 `doc_files` dual-key — no mis-joins — **(clean)**

Confirmed the only VARCHAR `*file_id*` columns are `doc_files.file_id` (the UUID itself) and `doc_uploads.drive_file_id` (a genuine Drive ID). Every FK named `file_id`/`file_id_fk` in the constraint set targets `doc_files.id` (BIGINT), per convention. No UUID-vs-INT mis-join.

---

### Dimension 6 — ENUM / charset / collation consistency

#### 6.1 Collation drift on the 3 newest tables — **MED (low risk, high leverage)**

**What/Evidence.** Project standard is `utf8mb4_unicode_ci`. Three base tables drifted to `utf8mb4_0900_ai_ci`:
- `inv_repack_events` (cols `repack_key`, `to_kind`, `note`)
- `inv_side_stock_ledger` (cols `accrual_key`, `fiscal_year`, `movement_type`, `note`)
- `ref_customer_identity` (cols `relation`, `notes`, `created_by`)

(Several `v_*` views also surface `utf8mb4_general_ci` — those inherit from expression/literal collation and are harmless; the base-table drift is the real item.)

**Why.** This is anti-pattern #2 in the project's own SQL playbook: a JOIN between a `_0900_ai_ci` column and a `_unicode_ci` column throws *Illegal mix of collations* (error 1267) or silently can't use an index. `inv_side_stock_ledger`/`inv_repack_events` join into the FG-stock/COGS surfaces; `ref_customer_identity` joins `ref_customers`. These are latent failures waiting for the first cross-collation string JOIN.

**Proposed fix.**
```sql
ALTER TABLE inv_repack_events    CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE inv_side_stock_ledger CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE ref_customer_identity CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```
**Risk:** Low — all 3 are tiny (0 / 13 / 40 rows), so `CONVERT TO` is instant and safe. Mechanical. (`inv_repack_events` has 0 rows; `inv_side_stock_ledger` 13; `ref_customer_identity` 40.)

#### 6.2 ENUM divergence — covered in 1.1/2.1/2.2

The packaging-format and beer-classification ENUMs (above) are the substantive ENUM-consistency findings. Two positive notes: the order-lifecycle ENUMs `ord_orders.status` and `ord_order_status_events.status` **agree exactly** (good); the `doc_review_queue.type` ENUM is large (28 values) but legitimately so (it's the RQ type registry) — not a smell.

#### 6.3 No "should-be-ENUM but is free VARCHAR" findings of note

The free VARCHARs that *should* be constrained are the `format` columns (1.1) — already captured. Otherwise the schema uses ENUMs liberally and appropriately.

---

### Dimension 7 — Index hygiene

#### 7.1 Duplicate FK constraint on `ref_skus.recipe_id` — **LOW (easy win)**

**What/Evidence.** `ref_skus.recipe_id` carries **two functionally identical FK constraints**: `fk_skus_recipe` AND `fk_sku_recipe`, both → `ref_recipes(id)`. Each FK forces its own index, so there are two redundant indexes + double the FK-check work on every `ref_skus` write.

**Fix.**
```sql
ALTER TABLE ref_skus DROP FOREIGN KEY fk_sku_recipe;   -- keep fk_skus_recipe; drop its twin + freed index
```
**Risk:** Low. Mechanical (verify which constraint name owns the surviving index first).

#### 7.2 Exact-duplicate indexes — 10 instances — **LOW**

Same table, identical column set, two index names:
- 8× v1 `bd_*` tables: `idx_sheet_row_index` == `uq_sri` (both on `sheet_row_index`). The `idx_` is fully redundant of the unique.
- `bd_tank_readings`: `idx_tank_read_lotday` == `uq_tank_read_neb` (both `recipe_id_fk,neb_batch,read_date`).
- `pl_plan_days`: `idx_pdays_date` == `uniq_plan_date` (both `plan_date`).

**Fix:** drop the non-unique `idx_*` twin in each (keep the UNIQUE). The 8 `bd_*` ones fold into the v1-drop arc anyway. **Risk:** Low.

#### 7.3 Prefix-redundant indexes — ~36 instances — **LOW**

A single-column (or shorter composite) index whose columns are the exact leftmost prefix of a wider composite UNIQUE on the same table — the wider index already serves the prefix lookups, so the narrow one is dead weight on writes. Representative examples (full list of 36 captured in audit):
`inv_sales_ledger.idx_sl_sku` ⊂ `idx_sl_sku_posting`; `inv_sales_ledger.idx_sl_customer` ⊂ `ix_isl_revcheck`; `doc_invoice_lines.idx_doc_invoice_lines_invoice_id` ⊂ `uq_invoice_line`; `ref_sku_bom.idx_sku_id` ⊂ `uniq_sku_ing_src_slot`; `inv_consumption.idx_mi_date` ⊂ `uk_dedup`; plus ~10 `ref_*` FK single-col indexes ⊂ their composite UNIQUE; plus `user_*`/`pl_*` selection tables.

**Caveat (verify before dropping any one):** a redundant prefix index is only safe to drop if the wider index's **leading columns exactly match** and no query needs the narrow index for a *covering* read with a different sort. For the FK single-col indexes (e.g. `ref_supplier_gls.idx_supplier_fk` ⊂ `uk_supplier_gl`), confirm the FK constraint isn't *relying* on that specific index name (MySQL will block dropping an index a FK needs unless an equivalent leading-prefix index exists — here the composite UNIQUE provides it, so it's allowed, but verify per-table).

**Fix:** drop the narrow index where the composite UNIQUE shares its leading prefix. **Risk:** Low, but do it as a reviewed batch with `EXPLAIN` spot-checks on the hottest tables (`inv_sales_ledger`), not a blind sweep.

#### 7.4 5 undocumented leftover tables — **LOW (cleanup)**

Not in `schema_meta`, all dated backup/probe artifacts (PM-confirmed safe to flag):
`_alt2_probe` (0 rows, diagnostic probe), `_snap_refskus_cuv_hlrevert_20260610` (3), `_snap_skubom_cuv_hlrevert_20260610` (37), `bd_packaging_v2_contractfix_snapshot_20260609_153201` (162), `bd_packaging_v2_lossfix_snapshot_20260609143950` (790).

**Fix:** archive/drop after a retention window. **Risk:** Low. These are explicitly pre-write snapshots from June corrections; once the corrections are soaked-in they're disposable.

---

## Prioritized action list (ranked impact ÷ risk)

### A. Safe / mechanical (low risk, do now)
1. **Collation: `CONVERT` the 3 drifted tables** to `utf8mb4_unicode_ci` (6.1). Tiny tables, instant, removes a latent JOIN failure. *Best impact-per-risk in the whole audit.*
2. **Drop duplicate FK `ref_skus.fk_sku_recipe`** (7.1). One statement.
3. **Drop 10 exact-duplicate indexes** (7.2) — start with the 2 non-`bd_*` ones (`bd_tank_readings`, `pl_plan_days`); the 8 `bd_*` fold into the v1 drop.
4. **Drop/archive the 5 leftover snapshot/probe tables** (7.4) after confirming the June corrections are soaked.

### B. Needs review (low-med risk, batch with EXPLAIN + code grep)
5. **Stage-1 of the format reconcile: resolve the 6 NULL `ref_skus.format_id`** from the string label via the canonical map (1.1). High value, low risk; the `'Vrac'` SKU needs a decision (see C).
6. **Drop the ~36 prefix-redundant indexes** (7.3) as a reviewed batch, `EXPLAIN`-checking `inv_sales_ledger` first.
7. **Verify + close the NULL-`sku_id_fk` question** on `inv_sales_order_lines` (43%) and `inv_sales_ledger` (25%) — confirm 100% of NULLs are non-beer/credit lines, not unresolved beer SKUs (4).
8. **Investigate the 53 `kpi_sku_seasonal_index` orphans**, fix the rebuild script, then add the FK (5.1).

### C. Needs operator / PM decision (consolidation with business semantics)
9. **Stage-2 format de-duplication (1.1):** retire `ref_skus.format` / `bd_packaging.format` VARCHARs and re-key `ref_cogs_targets` on the FK/canonical `run_type`. Moves a COGS-variance number; requires code grep + sign-off + a ruling on `'Vrac'`. *Highest strategic value — this is the root of the symptom that triggered the audit.*
10. **`run_type` ENUM consolidation (2.1):** establish `ref_packaging_formats` as the single run-type source; prefer FK-via-JOIN over redeclared ENUMs.
11. **Beer `subtype`/`type` consolidation (2.2):** rule on whether `ref_beer_types` owns the classification and `ref_recipes.subtype`/`classification` become derived/cached or are dropped. Verify current agreement first.
12. **`ref_customers.default_transporter_id_fk` / `default_delivery_site_id_fk` (4):** decide drop (abandoned feature) vs backfill (unfinished feature) — 100% / 97.7% NULL.

### D. Deliberately NOT recommended (false positives ruled out)
- v1 `bd_*` table **drops** — still live/wired; gated behind the operator's decommission arc (3 pending v1 readers + Sheets-ingest rewire). Flag for that arc, do not drop here.
- COGS/COP shells (`cop_monthly`, `cogs_monthly`, `mi_weighted_prices_monthly`), `qa_*`, `supplier_*` eval tables, `inv_repack_events`/`inv_fg_transfers` — intentional empty shells awaiting their pipeline; `wac_snapshots`/`ops_oversell_snapshot`/`debug_corrections`/`phantom_cleanup_log` — load-bearing despite "snap/debug/log" names.
- Provenance/display columns (`inv_deliveries.mi_id_raw`, `ref_skus.beer_raw`/`unit_label`, legacy `mi_id`/`supplier_id` codes) — intentional.
- `ref_packaging_formats.id` BIGINT exception — documented, consistently honored.

---

## Verification notes / open items (do not assert without these)
- **5.1** — identity of the 53 orphan `recipe_id`s (likely EPH re-vintage IDs; confirm by query before adding FK).
- **4** — NULL-`sku_id_fk` breakdown by line type on both sales tables.
- **2.2** — current agreement between `ref_beer_types` and `ref_recipes` classification before choosing the owner.
- **1.1 / C9** — the `'Vrac'` SKU has no matching `run_type` member; needs an operator ruling.
- All cleanup of the `/tmp/audit-*.php` probe scripts on the VPS was completed; **nothing was applied to the DB.**
