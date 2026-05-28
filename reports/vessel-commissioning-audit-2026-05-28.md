# Vessel Commissioning Verification Audit
**Date:** 2026-05-28  
**Scope:** Read-only. No writes. No migrations.  
**DB:** maltytask MySQL on VPS (ubuntu@83.228.215.243)

---

## How to Re-Run Any Query

```bash
# Template (substitute <SQL HERE>)
ssh maltyweb 'sudo -u www-data /var/www/maltytask/.venv/bin/python3' << 'PYEOF'
import pymysql, configparser, json
cfg = configparser.RawConfigParser()
with open("/var/www/maltytask/config/db.env") as f:
    cfg.read_string("[root]\n" + f.read())
s = cfg["root"]
conn = pymysql.connect(host=s["DB_HOST"], port=int(s["DB_PORT"]), user=s["DB_USER"],
    password=s["DB_PASSWORD"], database=s["DB_NAME"], cursorclass=pymysql.cursors.DictCursor)
cur = conn.cursor()
cur.execute("<SQL HERE>")
print(json.dumps(cur.fetchall(), default=str))
PYEOF
```

---

## Pre-Flight: Row Count vs Expected

| Table | Expected | Actual | Status |
|---|---|---|---|
| ref_cct | 18 | 18 | MATCH |
| ref_bbt | 8 | 8 | MATCH |
| ref_yt | 3 | 3 | MATCH |
| ref_serving_tanks | 8 | 8 | MATCH |
| ref_brewhouse_size | 1 | 1 | MATCH |
| ref_brewhouse_vessels | 0 | 0 | MATCH |
| ref_filler_containers | 5 | 5 | MATCH |
| ref_process_machines | 7 | 7 | MATCH |
| ref_packaging_formats | 22 | 22 | MATCH |
| ref_packaging_bom_templates | 16 | 16 | MATCH |
| ref_packaging_items | 65 | 65 | MATCH |
| ref_container_mi | 3 | 3 | MATCH |
| ref_packaging_format_mis | 34 | 34 | MATCH |
| dbc_container_types | 9 | 9 | MATCH |
| dbc_equipment_types | 7 | 7 | MATCH |
| dbc_packaging_format_templates | 16 | 16 | MATCH |

All 16 pre-flight counts match PM's expectations exactly. No state drift detected.

---

## SECTION 1: LIQUID-VESSEL CHAIN

### (a) Commissioned-but-Unused

**Check 1 — CCT with 0 events last 12 months:** PASS (no items)

All 18 CCTs show active usage in `bd_brewing_brewday_v2` within the last 12 months. Minimum: CCT 9 (6 events), CCT 11 (7 events). CCT 18 (7 events, last 2026-05-22). No idle commissioned CCTs.

**BBT commissioned-but-unused (12 months):** PASS (no items)

All 8 BBTs appear as rack destinations in `bd_racking_v2`. BBT 1 (25 events), BBT 8 (37 events — most active, 240 HL large tank).

**YT commissioned-but-unused (12 months):** PASS (no items)

All 3 YTs appear in `bd_brewing_brewday_v2` as pitching sources: YT1 (16 events), YT2 (12 events), YT3 (7 events). YTs do not appear as racking destinations — this is correct; they are yeast propagation vessels, not racking targets.

**Serving tanks commissioned-but-unused:** INFORMATIONAL

All 8 in-house serving tanks (ref_serving_tanks) are `status=active`. No production-table FK links them to individual `bd_packaging_v2` events (both `bbt_source_fk` and `cct_source_fk` are 100% NULL in bd_packaging_v2 — the cuv fill flow uses `nebuleuse_format_suffix='V'`). 244 active cuv runs over all time confirm the cuv chain operates. No deferred client-side (location='client') rows exist.

| Table | Row ID | Identifier | Evidence | Suggested action | ALREADY-KNOWN? |
|---|---|---|---|---|---|
| — | — | All CCTs, BBTs, YTs active | 18 CCTs / 8 BBTs / 3 YTs all show events in 12 mo | None | N/A |

### (b) Used-but-Uncommissioned

**Check 2 — LIQUID side:** PASS (no items)

Zero CCT numbers in `bd_brewing_brewday_v2` that are missing from `ref_cct`.  
Zero BBT numbers in `bd_racking_v2` that are missing from `ref_bbt`.  
Zero YT numbers in `bd_brewing_brewday_v2` or `bd_racking_v2` that are missing from `ref_yt`.  
Zero CCT numbers in `bd_racking_v2` (source side) that are missing from `ref_cct`.

### (c) catalog_id NULL — Liquid side

No liquid vessel tables (`ref_cct`, `ref_bbt`, `ref_yt`, `ref_serving_tanks`) have a `catalog_id` column. Not applicable for the liquid chain. The `catalog_id` field exists only on the packaging-format side.

### (d) format_id NULL — Liquid side

Not applicable. `format_id` is a packaging-chain concept. No liquid vessel tables carry this column.

---

## SECTION 2: PACKAGING-FORMAT-GATING CHAIN

### (a) Commissioned-but-Unused

**Check 5 — Packaging formats with 0 bd_packaging_v2 events in last 12 months:**

15 of 22 active formats had zero production events in 12 months. The 7 with events were: B (99), 4 (114), 4C (19), C (8), F (146), V (115), 33C (2).

| Table | Row ID | Identifier | Evidence (count/date range) | Suggested action | ALREADY-KNOWN? |
|---|---|---|---|---|---|
| ref_packaging_formats | 2 | B12 — 12-pack bottle box | 0 events in 12 mo | Verify if still planned; consider deactivating if not | No |
| ref_packaging_formats | 4 | 4PB — 4-pack loose bottles | 0 events in 12 mo | Verify; deactivate if retired | No |
| ref_packaging_formats | 5 | BU — Bottle unit single 33cl | 0 events in 12 mo | Verify; deactivate if retired | No |
| ref_packaging_formats | 6 | X — Pallet crate 1027×33cl | 0 events in 12 mo | Verify; deactivate if retired | No |
| ref_packaging_formats | 8 | BC — 24-pack can box B variant | 0 events; 0 active SKUs | BC→C fold confirmed; scrap candidate | ALREADY-KNOWN, intentional (minefield #7) |
| ref_packaging_formats | 10 | 6C — 6-pack tray of cans | 0 events; 0 active SKUs; catalog_id=NULL | Missing catalog template — see Check 3 finding | No |
| ref_packaging_formats | 11 | 12C — 12-pack can box | 0 events in 12 mo | Verify | No |
| ref_packaging_formats | 12 | 4PC — 4-pack loose cans | 0 events in 12 mo | Verify | No |
| ref_packaging_formats | 14 | CU — Can unit single 50cl | 0 events in 12 mo | Verify; deactivate if retired | No |
| ref_packaging_formats | 16 | P25 — Draft pour 25cl | 0 events in 12 mo; catalog_id=NULL (intentional: no physical package step) | Draft-pour format, not a physical run; suppress in commissioned-but-unused warnings | No |
| ref_packaging_formats | 17 | P50 — Draft pour 50cl | 0 events in 12 mo; catalog_id=NULL (intentional) | Same as P25 | No |
| ref_packaging_formats | 19 | PD8 — Pack Découverte 8 | 0 events in 12 mo; composite format | Composite pack — not a physical line run; suppress | No |
| ref_packaging_formats | 20 | XMASPACK — Xmas Pack | 0 events in 12 mo; composite | Seasonal; suppress or deactivate if not planned | No |
| ref_packaging_formats | 21 | PAL — Pack Louis | 0 events in 12 mo; composite | Verify status | No |
| ref_packaging_formats | 23 | PAC — Pack du Chef | 0 events in 12 mo; composite; PAC minefield #3 | ALREADY-KNOWN, intentional (minefield #3) | ALREADY-KNOWN |

**Operator note on B12 / 4PB / BU / X / 12C / 4PC / CU / 6C:** These 8 non-composite, non-draft, non-BC formats are active in `ref_packaging_formats`, pass the compiler gate (all have valid `catalog_id`), yet had zero production events in 12 months. They are not in the minefield list. Operator should confirm whether any are permanently retired (candidates for `is_active=0`).

### (b) Used-but-Uncommissioned

**Check 6 — PACKAGING side:** PASS (no items)

Zero format codes in `bd_packaging_v2.nebuleuse_format_suffix` are absent from `ref_packaging_formats`. All codes seen in production (B, 4, 4C, C, F, V, 33C) have commissioned format rows.

**Note:** 5 packaging events in the last 12 months have `nebuleuse_format_suffix=NULL`. All 5 are contract-run rows with `contract_beer` set and no `neb_beer` — correct by design.

### (c) catalog_id NULL where it should be commissioned

**Check 3 — ref_packaging_formats with catalog_id IS NULL:**

| Table | Row ID | Identifier | Evidence | Suggested action | ALREADY-KNOWN? |
|---|---|---|---|---|---|
| ref_packaging_formats | 10 | 6C — 6-pack tray of cans (24×50cl) | is_active=1; no matching template in dbc_packaging_format_templates; 0 events 12 mo; 0 active SKUs | No 6-pack template exists in dbc catalog. Create `SIX_CAN_50` template or deactivate format 10. | No — **NEW FINDING** |
| ref_packaging_formats | 16 | P25 — Draft pour 25cl | catalog_id=NULL intentional; 26 active SKUs; draft taproom format (no physical package line) | Intentional. Suppress in commissioning warnings. | No — expected design |
| ref_packaging_formats | 17 | P50 — Draft pour 50cl | catalog_id=NULL intentional; 26 active SKUs | Same as P25 | No — expected design |

**6C is the one genuine commissioning gap:** format 6C is active, has a BOM template (id=11), and appears in the gate-eligible position — but its `catalog_id` is NULL because no `dbc_packaging_format_templates` row covers a 6-can unit. The compiler JOIN on `f.catalog_id = t.id` will silently exclude any SKU with format_id=10 from the buildable set. No active SKUs currently point to 6C, so there is no immediate BOM disruption — but if a 6C SKU is ever activated, the compiler will reject it with no warning.

### (d) format_id NULL — gate-rejected

**Check 4 — ref_skus with format_id IS NULL:**

| Table | Row ID | Identifier | Evidence | Suggested action | ALREADY-KNOWN? |
|---|---|---|---|---|---|
| ref_skus | 277 | PAD — is_active=0 | Inactive; format_id=NULL; alias points to PD8 (id=137) via ref_sku_aliases | Already an alias; no action needed | ALREADY-KNOWN (minefield #2 equivalent — deactivated) |
| ref_skus | 288 | PACKDECX8 — is_active=1 | Active in ref_skus; alias in ref_sku_aliases → PD8 (id=137); no format_id; no composite slots | Alias SKU; format_id intentionally NULL (resolves via alias table). Compiler correctly excludes it. Gate-safe. | ALREADY-KNOWN (alias surface) |
| ref_skus | 294 | PBD — is_active=0 | Inactive; format_id=NULL | Minefield #2 deactivated SKU | ALREADY-KNOWN, intentional |
| ref_skus | 306 | EPH24P — is_active=1 | Active in ref_skus; alias in ref_sku_aliases → EPH24PB (id not shown but canonical_sku_id=75); format_id=NULL | Alias SKU; gate-safe. Compiler excludes it via format_id=NULL; alias resolves it. | ALREADY-KNOWN (alias surface) |

**Summary:** 2 active SKUs (PACKDECX8, EPH24P) have `format_id=NULL`. Both are intentional alias entries in `ref_sku_aliases`; the canonical SKUs they map to (PD8, EPH24PB) have valid `format_id`. No new rogue gaps found.

---

## Checklist Results (20 Items)

| # | Check | Result | Key Finding |
|---|---|---|---|
| 1 | Commissioned-but-unused LIQUID (CCT/BBT/YT/cuv) | PASS | All 18 CCTs, 8 BBTs, 3 YTs active. No idle vessels. |
| 2 | Used-but-uncommissioned LIQUID | PASS | 0 unregistered vessel numbers in any bd_* table |
| 3 | catalog_id NULL on ref_packaging_formats | FAIL (1 genuine) | 6C (id=10) is active, is_active=1, but catalog_id=NULL — no matching dbc template. P25/P50 NULL is intentional. |
| 4 | format_id NULL on ref_skus (gate-rejected) | PASS (known) | Only PACKDECX8 + EPH24P (alias SKUs) and PAD/PBD (inactive). All expected. |
| 5 | Commissioned-but-unused PACKAGING | INFORMATIONAL | 15 of 22 formats had 0 events in 12 mo. 8 are unexplained (B12/4PB/BU/X/12C/4PC/CU/6C) — operator review needed. |
| 6 | Used-but-uncommissioned PACKAGING | PASS | 0 unregistered format codes in bd_packaging_v2 |
| 7 | Gate parity compiler vs SDC | PASS | Both _compiler_gated_format_ids() and sdc_gated_format_ids() have byte-identical SQL. Reconstructed query yields 15 format IDs: {1,2,3,4,5,6,7,8,9,11,12,13,14,15,18}. |
| 8a | BOM templates with 0 active SKUs | INFORMATIONAL | All 16 templates show 0 active SKUs via bom_template_id FK. This is because bom_template_id on ref_skus is only set at SDC activation time; existing/imported SKUs have NULL. Compiler joins via format_id (not bom_template_id), so no operational impact. |
| 8b | Active formats with active SKUs but no active we_supply template | FAIL (known) | P25, P50, PD8, XMASPACK, PAL, PAC have active SKUs but no we_supply template. P25/P50 = draft-pour (correct, no BOM needed). Composites = handled by composite compile path, not the we_supply gate. |
| 9 | ref_container_mi coverage | FAIL (partial) | BOT_GLASS_33 → MI 91 ✓, CAN_ALU_33 → MI 617 ✓, CAN_ALU_50 → MI 616 ✓. KEG_INOX_20 (active filler) has NO mi_id_fk in ref_container_mi. CUV_LINER (active filler) has NO mi_id_fk. Keg/cuv are reusable vessels — NULL is intentional per design. No missing bottle/can MIs. |
| 10 | ref_filler_containers.is_active honesty | PASS | All 5 active filler_container rows link to active process machines. Each active container type maps to ≥1 active format: BOT_GLASS_33 → 6 formats, CAN_ALU_33 → 1 format (33C), CAN_ALU_50 → 6 formats, KEG_INOX_20 → 1 format (F), CUV_LINER → 1 format (V). |
| 11 | ref_brewhouse_size sanity | PASS | Single row: 30.00 HL, effective_from 2026-05-21, no effective_until. Consistent with stated brew size. |
| 12 | ref_packaging_items NULL default_mi_id_fk | INFORMATIONAL | 27 of 65 items have NULL default_mi_id_fk. Of these, 8 are `slot_scope='always'` on `slot_name='can'` — all can formats (BC, C, 4C, 6C, 12C, 4PC, CU, 33C). The can slot is intentionally NULL at template level because the exact can MI is recipe/brand-specific and resolved via ref_recipe_packaging_bindings or ref_sku_packaging_choices. All labelled_only/we_supply_only NULLs are brand-specific (labels, stickers). |
| 13 | ref_recipe_packaging_bindings orphans | PASS | 30 bindings. 0 point to inactive or missing recipes. 0 recipes with bindings have 0 active SKUs. |
| 14 | CCT/BBT/YT flow integrity | PASS (note) | All YTs appear in bd_brewing_brewday_v2 as pitching sources (YT1: 16, YT2: 12, YT3: 7 events in 12 mo). YTs do NOT appear in bd_racking_v2 — correct design (YTs = yeast propagation, not racking targets). BBTs all have racking events. |
| 15 | Format 8 (BC) — 0 active SKUs | PASS | Format BC (id=8): 0 active SKUs, 0 events. BC→C fold is complete. |
| 16 | Contract-run NULLs | PASS (with drift note) | 244 rows with sku_id_fk=NULL (not 240 as expected). All 244 have contract_beer or contract_batch set. 0 rogue NULLs (neb_beer set but no sku_id_fk and no contract info). COUNT DRIFTED +4 since PM's last read. |
| 17 | Mig 204 effectiveness | PASS | BLO4, BLO4C, BLOF, PBD, ZEP6C: last packaging events all before deactivation date. 0 bd_packaging_v2 events after 2026-05-21 for any of these SKUs. |
| 18 | dbc_container_types coverage | INFORMATIONAL | 5 of 9 container types are commissioned (have active filler). BOT_GLASS_50, BOT_PET_100, KEG_INOX_30 are active in dbc_container_types but have no active ref_filler_containers row. KEG_PET_20 is is_active=0. BOT_GLASS_50 and KEG_INOX_30 may be deferred-pending containers. |
| 19 | ref_packaging_formats.template alignment | PASS | 0 active formats with non-NULL catalog_id that point to a missing dbc_packaging_format_templates row. All 19 non-null catalog_ids resolve. |
| 20 | Legacy mi_match cleanup | PASS | ref_sku_bom WHERE source='mi_match' = 0. The 2026-05-26 DELETE was effective and has not regressed. |

---

## Clean-Bill Section (Checks That PASSED Fully)

1. **Count parity (all 16 tables)** — exact match with PM expectations; no state drift.  
2. **Check 2: Used-but-uncommissioned LIQUID** — 0 unregistered vessel numbers in any bd_* table.  
3. **Check 6: Used-but-uncommissioned PACKAGING** — 0 unregistered format codes in bd_packaging_v2.  
4. **Check 7: Gate parity compiler ↔ SDC** — SQL is byte-identical between both PHP functions; reconstructed SQL output is identical set of 15 format IDs.  
5. **Check 10: ref_filler_containers.is_active honesty** — all 5 active fillers link to active machines; each has ≥1 active format coverage.  
6. **Check 11: ref_brewhouse_size sanity** — single row, 30.00 HL, consistent.  
7. **Check 13: ref_recipe_packaging_bindings orphans** — 0 orphans; all 30 bindings tied to active recipes with active SKUs.  
8. **Check 14: CCT/BBT/YT flow integrity** — YTs appear in brewday (correct channel), not in racking (correct design). BBTs all active in racking.  
9. **Check 15: Format 8 (BC) = 0 active SKUs** — confirmed, BC→C fold complete.  
10. **Check 16: Contract-run NULLs** — 0 rogue NULLs; all 244 null-sku rows have contract info.  
11. **Check 17: Mig 204 effectiveness** — 0 post-deactivation events for BLO4/BLO4C/BLOF/PBD/ZEP6C.  
12. **Check 19: format → catalog_id → dbc FK integrity** — all non-null catalog_ids resolve.  
13. **Check 20: mi_match cleanup** — 0 rows; DELETE confirmed durable.  

---

## Findings Summary (Operator Action Required)

### FINDING F1 — NEW, operator review needed
**6C format (id=10) has catalog_id=NULL — no matching dbc_packaging_format_templates row.**  
Impact: Any SKU assigned to format 6C would be silently excluded from the compiler gate and produce no BOM. Currently 0 active SKUs use 6C, so there is no immediate COGS disruption.  
Action: Either (a) create a `SIX_TRAY_CAN_50` template in `dbc_packaging_format_templates` and assign it to format 10, or (b) deactivate format 10 if 6-pack trays are not planned.

### FINDING F2 — NEW, operator review needed
**8 non-composite, non-draft active formats had 0 production events in 12 months: B12, 4PB, BU, X, 12C, 4PC, CU, and 6C.**  
These are all commissioned (valid catalog_ids, active fillers), pass the compiler gate, but show no real production. If any are permanently retired, set `is_active=0` to reduce noise in format dropdowns and audit checks.

### FINDING F3 — NEW, count drift
**bd_packaging_v2 contract-run rows with sku_id_fk=NULL = 244, not 240.**  
All 244 correctly have contract_beer/contract_batch set (no rogue NULLs). The +4 drift since PM's last live read is simply new contract runs processed. No action needed, but update PM memory to 244.

### FINDING F4 — NEW, denormalization gap (no operational impact)
**ref_skus.bom_template_id is NULL for all 154 active SKUs.**  
The compiler does not use `bom_template_id` in its buildability or BOM resolution path — it joins on `format_id` directly. The column is only set at SKC activation time via the SDC UI (`public/modules/salle-de-controle.php`) and is never backfilled for imported/pre-existing SKUs. This creates a gap for any UI widget that uses `bom_template_id` as a shortcut display (e.g. `public/js/salle-de-controle.js` line 147). Functional impact: zero on COGS. Display impact: the SDC UI may show blank bom-template for all existing SKUs until re-activated via the UI. Backfill is straightforward: `UPDATE ref_skus s JOIN ref_packaging_bom_templates bt ON bt.format_id=s.format_id AND bt.supply='we_supply' AND bt.is_active=1 SET s.bom_template_id=bt.id WHERE s.bom_template_id IS NULL` (single-template-per-format assumption; safe for current 1:1 mapping).

### FINDING F5 — [POST-RESOLVED, mig 207 — 2026-05-28]
**dbc_container_types BOT_GLASS_50 (id=2), BOT_PET_100 (id=3), and KEG_INOX_30 (id=7) were is_active=1 in the catalog but had no active ref_filler_containers row.**  

Operator-confirmed resolution: speculative seed rows from mig 115; no in-house OR contracted-out commissioning planned. The contracted-out lane (`ref_packaging_bom_templates.supply='client_supply'`) is dormant at 0 rows and gates on the same INNER-JOIN chain anyway — these 3 are un-buildable AND un-contractable. Set `is_active=0` (matches KEG_PET_20 id=9 precedent).

Applied via `db/migrations/207_deactivate_speculative_dbc_container_types.sql`. Post-apply state: dbc_container_types active = {1, 4, 5, 6, 8} (5 rows); inactive = {2, 3, 7, 9} (4 rows). All 5 ref_filler_containers entries still resolve.

---

## ALREADY-KNOWN Items (Minefield Tags)

| Minefield # | Description | Confirmed? |
|---|---|---|
| 1 | ref_brewhouse_vessels = 0 rows | Confirmed, 0 rows found |
| 2 | PBD/ZEP6C/BLO4/BLO4C/BLOF/BLA4 inactive SKUs | Confirmed, all is_active=0. BLA4 (id=4) also inactive. |
| 3 | PAC composite unmapped format_mis edges | Confirmed, format id=23 catalog_id=16 (COMPOSITE_MIXED). Not flagged. |
| 4 | PKG_CAGE activable-root missing | Not tested (outside scope of ref_packaging_items query set) |
| 5 | ref_packaging_format_mis (34 rows) DORMANT | Confirmed exists, not flagged |
| 6 | 240 contract-run rows with sku_id_fk=NULL | Confirmed correct design (count now 244, +4 new contract runs) |
| 7 | Format 8 (BC) with 0 active SKUs | Confirmed, scrap candidate |
| 8 | ref_serving_tanks client-side rows = 0 | Confirmed, all 8 rows are location='in_house' |
| 9 | speed_units_h=0 on cuv filler | Not explicitly checked (machine id=7 has speed_units_h=NULL, not 0) |
| 10 | PKG_CAN_ALU_33 (id=617) NULL price | Not in scope of this audit (ref_mi price field) |
| 11 | 24 legacy source='mi_match' rows DELETEd 2026-05-26 | Confirmed 0 rows remain (Check 20 PASS) |
| 12 | 4 EPH BC→C aliases on ref_sku_aliases | Confirmed: EPH1BC/EPH2BC/EPH3BC/EPH4BC in ref_sku_aliases pointing to canonical C SKUs |

---

## Queries Run Total: 38 discrete queries across 12 sessions
## PASS: 13 | FAIL/Finding: 5 (F1–F5) | ALREADY-KNOWN: 12 | Informational (no action): 5

---

## Post-audit triage state (2026-05-28)

| Finding | Status | Resolution |
|---|---|---|
| F1 (6C catalog_id NULL) | OPEN — pending operator decision | Deactivate format 10 OR add `SIX_TRAY_CAN_50` template |
| F2 (8 idle commissioned formats) | OPEN — pending operator decision | Per-format `is_active=0` review (keep 4PB — has active sibling SKUs) |
| F3 (count drift 240→244) | RESOLVED (informational) | PM memory bumped to 244 |
| F4 (bom_template_id NULL × 154) | OPEN — pending operator decision | Single-shot UPDATE backfill OR formally retire column |
| F5 (3 dbc speculative rows) | **RESOLVED via mig 207** (2026-05-28) | is_active=0 on BOT_GLASS_50 / BOT_PET_100 / KEG_INOX_30 |
