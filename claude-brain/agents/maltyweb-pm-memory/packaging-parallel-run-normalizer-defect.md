# Packaging parallel -4/-B run normalizer defect (`process_packaging_data`)

> Read when touching `scripts/normalize-rawdb.py` `process_packaging_data` (special-pack block ~L1406-1553), `bd_packaging_v2` packaging-volume correctness, the RawDB→MySQL staging re-upload, the TankSimulator BBT under-fill, or any consumer that drains a tank from packaging volume. Diagnosed 2026-06-04/05 during TankSimulator task #15 (BBT2 over-fill trace). **SCOPE CORRECTED + PM-VERIFIED LIVE 2026-06-05 (operator re-verified, PM confirmed against v1 ground truth): the real defect is exactly 4 mode_a rows, NOT the earlier "13 sessions / 141 HL" overcount** (see §CORRECTED SCOPE below — that overcount is RETRACTED).
>
> ## ✅ SHIPPED + VERIFIED 2026-06-05 (data + normalizer code) — see §SHIPPED below
> The surgical 4-row data mig + the normalizer code-fix LANDED and are LIVE-VERIFIED. STILL-OPEN: per-recipe historical re-derivation (batches 5/63/169), Node `parse-tank-simulation.ts` name-keying, and the session is uncommitted (operator hasn't asked).

## Domain rule (NEW, operator-confirmed, durable)
**"Parallel runs": a single bottling session sometimes fills BOTH the -4 (6×4 carton) AND the -B (24-pack box) SKU of the SAME beer.** Operator: *"we do -4 and -B runs in bot sometimes."* The two legs are the **SAME `recipe_id` / same beer** — only the format suffix flips (4↔B). The parallel leg is NOT a different beer.

## Ground truth = v1 `bd_packaging` (legacy mirror, 2203 rows, VERIFIED live 2026-06-05)
The old inputting system stores a parallel session as ONE row:
- `special_pack` / `hl_first_packaging` = the main leg (e.g. DIV4, 31.891 HL)
- `second_packaging` / `hl_second_packaging` = the parallel leg (e.g. DIVB, 14.969 HL)
- both legs carry the SAME `recipe_code` (e.g. "DIV 45").
**44 v1 rows have `second_packaging` set** (the 44 parallel sessions). VERIFIED count live.
Canonical example (VERIFIED live): batch 45 → `neb=DIV4 special=24BotBlanc hl_first=31.891 second=DIVB hl_second=14.969 recipe_code="DIV 45"` — both legs Diversion.

## The defect (in `bd_packaging_v2`, the canonical normalized table)
`process_packaging_data` special-pack block has two emission modes:
- **Mode B** (correct, handles the MAJORITY): strips the main suffix and flips it (24Bot→B, 6x4Bot→4) → parallel leg inherits the main's identity. This is v1-correct.
- **Mode A** (DEFECT): resolves the parallel beer from a separate `Selection Recette` source field (SRC_SEL_RECETTE=66) via `resolve_sku_code`. When the operator filled the old form's `Selection Recette` with the WRONG beer, Mode A trusts it → the leg's VOLUME is captured but stamped with the WRONG recipe.

## ✅ CORRECTED SCOPE — exactly 4 mode_a rows, PM-VERIFIED LIVE 2026-06-05 (supersedes the RETRACTED "13 sessions / 141 HL")
`audit_flags LIKE '%mode_a%'` = **exactly 4 rows live.** The earlier "13 sessions / 141 HL missing-or-misattributed" was an OVERCOUNT — the other "missing" legs turned out fine: correct `mode_b_extraction` parallel rows OR correctly web-entered backlog (`web_entry`, the web form's parallel-leg logic is CORRECT; only the RawDB normalizer Mode A is buggy). **RETRACT the "Defect 2 — 9 missing sessions" framing AND the 141 HL number AND the "affects Stirling/170, Embuscade/234, Moonshine/122" list.**

**ROOT CAUSE CONFIRMED (verified against v1 `bd_packaging` ground truth + the normalized output, NOT guessed):** in those 4 raw rows the operator filled the old form's `Selection Recette` field with the WRONG beer (e.g. DIV4/45's parallel leg has `Selection Recette='SPYB'`, `Selection Pack='24BotBlanc'`). Mode A trusted `Selection Recette` → filed the leg under the wrong beer. Mode B (suffix-flip from main + `Selection Pack`) is correct and would have produced the right beer. The earlier "+4 column-index misalignment" hypothesis stays RETRACTED.

**The 4 rows (v2 id → filed-as → CORRECT, each reconciled EXACTLY to v1 `second_packaging`/`hl_second_packaging`; all `row_origin='parallel'`, prod=special, not tombstoned, `mode_a_extraction`):**
| v2 id | filed as | batch | vend HL | CORRECT (v1-confirmed) | v1 ground truth |
|---|---|---|---|---|---|
| 2227 | SPYB rid=51 (Speakeasy) | 45 | 14.969 | **DIVB rid=25 (Diversion)** — THE BBT2 GAP, only one on a LIVE tank | `[DIV 45] second=DIVB hl2=14.969` ✓ |
| 2158 | SPY4 rid=51 | 169 | 4.039 | **STIB rid=52 (Stirling)** | `[STI 169] second=STIB hl2=4.039` ✓ |
| 2119 | DIVB rid=25 | 63 | 3.389 | **SPYB rid=51 (Speakeasy)** | `[SPY 63] second=SPYB hl2=3.389` ✓ |
| 2086 | SPYB rid=51 | 5 | 14.969 | **DIBB rid=26 (Diversion Blanche)** | `[DIB 5] second=DIBB hl2=14.969` ✓ |
**⚠️ id=2086 CORRECTION OVER THE OLD FILE:** the old note guessed "DIBB (Double Oat→ per v1 DIB4/5)" — WRONG beer name. v1 ground truth `[DIB 5] second=DIBB` → **rid=26 = Diversion Blanche** (DIB prefix = Diversion Blanche, NOT Double Oat). Operator's "DIBB rid=26 Diversion Blanche" is correct. recipe identities re-verified live: 25=Diversion(DIV)/26=Diversion Blanche(DIB)/51=Speakeasy(SPY)/52=Stirling(STI), all Neb.

**id=2227 is THE BBT2 under-fill** (the ONLY one on a current/live tank): DIVB 14.97 HL filed under Speakeasy, so Diversion/45 never drained → BBT2 sim ~15 HL too full. The other 3 (batches 5/63/169) are HISTORICAL — correcting them shifts no current tank, only per-recipe historical packaging HL (re-derive COGS/beer-tax for those recipe-years, see §DOWNSTREAM below).

## SPY4/65 "absent ~45 HL session" — NOT A GAP (PM-VERIFIED LIVE 2026-06-05, overturns the operator's worry)
The operator flagged SPY4/65 (06-02, ~45 HL) as a possibly-absent session needing web-entry. **It is NOT absent.** v1 has TWO SPY/65 parallel sessions: `[SPY 65] main 25.014 + second SPYB 17.846` AND `[SPY 65] main 33.469 + second SPYB 11.900`. Both already in v2: ids **2224(SPY4 main 23.282)/2225(SPYB parallel 17.846, `mode_b_extraction`)** = first session, normalized CORRECTLY via Mode B; ids **6735(Speakeasy main 33.208)/6736(Speakeasy parallel 11.900, `web_entry`)** = second session, web-entered by the operator CORRECTLY (parallel leg = SPYB rid=51, suffix-flipped). **No data-entry needed — nothing to enter, nothing to flag.**

## RECONCILIATION (PM-VERIFIED LIVE 2026-06-05)
v1 parallel sessions (`second_packaging` set, hl2>0) = **44**. v2 non-tombstoned `row_origin='parallel'` rows = **67** (= 44 normalized + 23 web-entry backlog). Of the 44 normalized parallel legs, exactly **4 are mode_a mis-recipe'd**; the rest are mode_b-correct. The per-leg-recipe reconciliation gap is EXACTLY these 4 — full stop.

## ✅ SHIPPED 2026-06-05 (data + normalizer code, LIVE-VERIFIED)
**(A) DATA — mig 265** (`db/migrations/265_parallel_run_recipe_fix.sql` + `scripts/python/mig265_parallel_run_recipe_fix.py`): surgically corrected the 4 mislabelled `bd_packaging_v2` rows — **id 2227→DIVB/rid25, 2158→STIB/rid52, 2119→SPYB/rid51, 2086→DIBB/rid26** (`neb_beer` + `recipe_id_fk` + `nebuleuse_format_suffix` + **`sku_id_fk`** all corrected). `row_hash` recomputed via the Python json scheme; **self-check PASSed on all 4** (old values reproduce stored hash). `audit_row_revisions` 10658-10661 (action='update', reconciled to v1 `second_packaging`). `schema_migrations` 265. **No re-normalize** → `web_entry` + ZEP/EPH hand-corrections untouched.
**(B) CODE — `scripts/normalize-rawdb.py` `process_packaging_data` Mode A rewritten:** parallel leg now inherits the MAIN `recipe_id` unconditionally; suffix from `Selection Pack`; `Selection Recette` demoted to mismatch-detection only (`sel_recette_overridden_to_main` flag). `py_compile` passes. **NOT re-run** (RawDB frozen; operator would run only if ever re-seeding) — purely future-seed-correctness.
**(C) VERIFIED LIVE:** deployed TankSimulator BBT2 Diversion/45 dropped **63.1 → 48.0 HL** (operator board weight-sensor 43.8; ~4 HL residual within the operator's accepted half-filled/sensor tolerance). All other BBTs hold correct beer identities; BBT4 Speakeasy/65 (racking #394, 05-15) is real live data, unaffected.
**(D) CODIFY:** parallel-run WRITE-side anti-pattern being added to `sql` skill now (companion to read-side #27).
**Uncommitted:** session has many uncommitted files incl. mig265 + normalizer edit; no git commit yet (operator hasn't asked).

### STILL-OPEN (carry forward)
- **Per-recipe historical re-derivation** for batches 5/63/169 (~22 HL re-attributed across Speakeasy/Diversion/DivBlanche/Stirling, Feb–May 2026; **net Neb total unchanged**) — beer-tax base / FG-COGS / packaging-dashboard per-recipe views, regenerate when next needed (see §DOWNSTREAM).
- **Node `parse-tank-simulation.ts` still name-keyed** (same bug family, COGS/WIP path) — read-side companion still unfixed.

## BLESSED FIX (PM-endorsed 2026-06-05 — surgical, NO re-normalize) — HISTORICAL, now SHIPPED above
**Re-normalize is OFF THE TABLE** (RawDB.xlsx is FROZEN at migration date → re-running `normalize-rawdb.py` canNOT catch up recent data AND would clobber the 23 `web_entry` rows + prior hand-corrections; see derivation-tree-and-schema.md §CANONICAL v1/v2/RawDB ARCHITECTURE). The data correction is surgical SQL; the normalizer code-fix is for future-seed-correctness only.
- **(A) DATA — one migration, surgical UPDATE of the 4 rows:** set `recipe_id_fk` + `neb_beer` to the CORRECT beer (table above, reconciled to v1 `second_packaging`). **Recompute `row_hash` using the normalize-origin scheme (Python `json.dumps(canonical, sort_keys=True, default=str)`), NOT the PHP `bd_row_hash` implode scheme** — these are normalizer-origin rows; match what a future re-seed would emit (same convergence ruling as mig 236; NK is the real idempotency guard but match the origin recipe to avoid a transient row_hash divergence). Append `audit_flags ',correction'`. `log_revision` action='update' with before/after. **Self-check: reconstruct stored hash with OLD values → assert == stored → recompute with new** (proves we're touching the right rows AND the hash recipe is right). No re-normalize → `web_entry` rows + ZEP id=6737 + EPH mig263 untouched. Mig number via `migrate.php --status`.
- **(B) CODE — fix `process_packaging_data` Mode A:** parallel leg inherits the MAIN's `recipe_id`; derive suffix from `Selection Pack` (24Bot→B, 6x4Bot→4) like Mode B; treat `Selection Recette` as at most an ADVISORY cross-check, NEVER the identity source. When `Selection Recette` resolves to a recipe ≠ main, override with main + flag. So a future re-seed is correct. (Does NOT catch up live data — RawDB frozen — purely seed-correctness.)
- **(C) VERIFY:** re-run the deployed TankSimulator; confirm BBT2 Diversion/45 drops by ~15 HL to match the operator's weight-sensor board (~43.8). Re-check the other BBTs unchanged. Acceptance gate = reconcile v2 normalized parallel legs vs v1 `second_packaging`: 0 mis-recipe'd (per-leg HL + recipe_id match).
- **(D) CODIFY:** encode the parallel-run WRITE-side rule in the `sql` skill at fix-commit time (the earlier "encode at commit" gate is met once A+B land + C passes).

## DOWNSTREAM re-derivation after the 3 historical corrections (record so it isn't forgotten)
Correcting batches 5/63/169 moves ~22 HL of historical bottle volume between recipes (DIVB→SPYB net, SPYB→DIBB, SPY4→STIB). Anything that aggregates packaging HL by `recipe_id_fk` for a CLOSED period — beer-tax base per beer, FG/COGS per-recipe historical packaging output, the packaging dashboard's per-SKU/per-recipe historical views — should be re-derived for the affected recipe-years (Speakeasy/Diversion/Diversion-Blanche/Stirling, 2026 Feb–May spread). Net Neb total HL is UNCHANGED (it's a recipe re-attribution, not a volume add) → grand-total beer-tax + grand COGS unaffected; only PER-RECIPE splits move. id=2227 (batch 45, live tank) additionally flows through the live TankSimulator (the §C check).

## Durable coding rule (codify in `sql` skill on fix)
**Packaging parallel -4/-B runs: the parallel leg is the SAME `recipe_id` as the main (only the format suffix flips). Any normalize / derive / drain code must (1) emit BOTH legs, (2) keep `recipe_id` IDENTICAL to the main, (3) NEVER resolve the parallel beer from a separate selection field that can disagree with the main, and (4) reconcile against v1 `first`/`second_packaging` as the acceptance gate.** This is a WRITE-side companion to the read-side `(recipe_id_fk,batch)` keying rule (sql skill #25/#27).

## Relation to TankSimulator (task #15) — both real, distinct
The 2026-06-04 sim fix (`app/tank-simulator.php`, recipe_id-keyed drain, sql #27) made beer IDENTITIES right at replay time. THIS normalizer bug is why VOLUMES stay too high where a parallel leg was dropped/mislabelled at NORMALIZE time. The sim fix stays; this is upstream of it. **This REVISES tank-simulator follow-up #3** ("operator enter missing ~17 HL DIV/45 packaging") — it is NOT operator data-entry lag, the volume EXISTS in v1; it was mis-attributed by the normalizer (Defect 1, id=2227).

---

## ✅ v2 ⇄ old-form (Google Form `PackagingForm`) RECONCILIATION AUDIT — read-only, NO writes (recorded 2026-06-10)
Full v2 vs old-form audit. **The migration itself is CLEAN (only zero-prod skips). Exactly ONE live discrepancy, and it is a volume/HL/tax-NEUTRAL per-client mis-attribution — NOT a lost event, NOT the dedup-collapse hazard.** Holds for operator confirmation; nothing written.

**CUTOVER LINE = `submitted_at` 2026-05-22 16:38:43.** Migrated v2 rows carry `source_sheet_row_index NOT NULL`; web-form/parallel-leg rows carry NULL. After the cutover v2 is fed by the new web form. **⚠️ PROCESS FLAG #1: the old Google Form (sheet `1gsaht5…`, tab `PackagingForm`) is STILL being filled in parallel POST-cutover and is NOT ingested into v2** — a live divergent input stream alongside the web app.

**PART A — pre-cutover migration completeness (≤ cutoff):** 1342 old-form rows, **1336 matched a v2 main row by `submitted_at` (±3s)**. 6 unmatched, **ALL prod_total=0** → immaterial (0 volume): 1 junk "Baies-Tises"/Contract test row (sheetRow 2, 2023) + 5 zero-production cuv rows (ZEPV/147 People in the City ×2; ZEPV/148 Fêtes de Genève ×3). Hypothesis (worth a one-line operator confirm): the normalizer intentionally SKIPS zero-production rows. **Migration is clean — only zero-prod skips.**

**PART B — post-cutover (> cutoff):** 26 old-form rows, NONE match v2 by timestamp (the parallel stream). Event-identity reconciliation (recipe token + batch + run_type + client + volume ±2%): **25/26 reconcile to a v2 web-form event. The 1 discrepancy is a CLIENT MIS-ATTRIBUTION, volume/HL/tax-neutral:**

Batch 211 cuv — both sides total **5 fills / 3350 L (33.5 HL)** (liquid totals INTACT):
- Old form: Jardins 850, Jetée 500, Blues Rules 500, Blues Rules 500, Arches 1000 → **Blues×2 / Jetée×1**
- v2: 6737 Jardins 850, 6738 Jetée 500, **6739 Jetée 500(main) + 6740 Blues 500(parallel)**, 6743 Arches 1000 → **Blues×1 / Jetée×2**
- Net: v2 has **+1 Jetée / −1 Blues Rules**. The old form's 2nd Blues fill (sheetRow 1368, 6/3 11:19) appears in v2 as **`bd_packaging_v2.id=6739` tagged `client_fk=2` (Jetée) instead of `client_fk=19` (Blues Rules)**. Cause = hand-transcription slip when the web form was bulk-entered (web entries batched one late-night sitting → transcribed FROM the old form).

So Kouros's "1 event missed" is **REAL at the per-CLIENT level** (Blues Rules shows 1 cuv event, should be 2) but **liquid / beer-tax totals are intact**.

**The dedup-collapse hazard (§Durable coding rule / earlier `row_hash` two-identical-same-day worry) is REAL and latent but is NOT the cause here** — this is post-cutover web-form transcription, not a same-day `row_hash` collision in the migration. **⚠️ PROCESS FLAG #2 (key-design ruling to log): the `bd_packaging_v2` dedup key still cannot represent two byte-identical same-day events** (the standing hazard) — decide the key design.

**MATERIALITY:** per-client serving-tank attribution ONLY (Blues Rules under-counted 5 HL / Jetée over-counted 5 HL). Matters for **per-client packaging COGS / recharge** (the contract-packaging-per-client rule), NOT beer tax or total COGS.

**PROPOSED FIX (HOLDING — operator must confirm first, NOT done):** flip `bd_packaging_v2.id=6739.client_fk` 2→19 (Jetée→Blues Rules) after Kouros confirms WITH THE OPERATOR which of the two 16:37/16:40 "Jetée" web entries is the real Blues fill. Volume-neutral; `audit_row_revisions` insert (action='update', before/after); re-derive per-client packaging rollups ONLY. Refuse-don't-guess: do NOT flip until the operator names the row.

**TWO ROADMAP PROCESS FLAGS (carry forward):**
1. **Old Google Form still live + filled post-cutover, in parallel with the web app** → decide: retire/disable it OR build a parity check. Hand-transcription is injecting per-client attribution errors (this case is the proof).
2. **`bd_packaging_v2` dedup key cannot represent two byte-identical same-day events** (the §Durable coding rule hazard) → log the key-design ruling.
