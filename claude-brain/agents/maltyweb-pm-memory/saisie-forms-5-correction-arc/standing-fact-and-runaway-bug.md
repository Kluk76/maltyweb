# Saisie-forms 5-correction batch — ✅ SHIPPED + LIVE (2026-06-02)

Operator pre-cleared ALL design Qs (batch-clear-then-full-send mode); Kouros built fully
independently, no operator supervision, delegating code to Sonnet agents. PM consulted at
arc-start; this file is the locked decision record + as-found code map + final landing.

---

## ✅ BREWING-FORM INGREDIENT-LINE RUNAWAY DUPLICATION BUG — FIXED + DATA-CORRECTED 2026-06-19 (Speakeasy batch 67) — AS-BUILT
> Triggers: "trop de lignes" / "ingredient lines duplicate" / "deleted rows reinput" / "form-brewing" / "bd_brewing_ingredients_parsed_v2" / "line_idx" / "corriger ingredient" / Charles + brewing form. READ before any form-brewing.php ingredient-line touch.

**STATUS: both briefs LANDED + verified live 2026-06-19.** Code fix deployed+committed (`5ad8929` maltyweb); 65→6 data correction done with audit rows; clean window confirmed (no derived COP rows existed yet). Original diagnosis retained below the AS-BUILT block for the root-cause record.

### AS-BUILT — Brief A (CODE FIX, `form-brewing.php`, commit `5ad8929`)
- **§5-§7 now wrapped in an explicit transaction** via the `$ownTx = !$pdo->inTransaction()` guard; `rollBack()` in the catch (nested-txn-safe — only commits/rolls back the txn it opened).
- **DELETE-then-reinsert is now the line-write contract** (PM verdict implemented): **§7a** captures a pre-image of all parsed lines under ALL non-tombstoned headers for `(beer,batch)`; **§7b** hard-DELETEs them; **§7c** reinserts the submitted set with a **plain INSERT (`ON DUPLICATE KEY UPDATE` removed)**. Result: stored lines == EXACTLY the submitted lines — never a union across corriger submits. The positional-`line_idx`-append runaway is structurally impossible now.
- **FIRST audit coverage the parsed-lines write ever had:** §7c emits ONE `log_revision` on `bd_brewing_ingredients_parsed_v2` row=`ingHeaderId`, before=pre-image, after=new set, action derived `'update'` (non-null before).
- **Verified:** invariant test 6→6→6→5 passed (clean submit, two corriger re-saves, then a shrink — all yield exactly the submitted set); `php8.1 -l` clean; md5 `34e66df3` local↔VPS match.
- **LIVE webapp regression confirmed:** reopened Speakeasy 67 via `?edit=1513` (that is the **brewday header id**; the **ingredient header is 1137**) — form renders exactly 6 rows; re-saved unchanged → DB stays 6 under header 1137, NO new header spawned. Non-accumulation proven on real operator data.
- **🔑 DIAGNOSIS NUANCE (corrects the commit message + the original diagnosis below):** the header upsert keeps the **SAME header id (1137) across all corriger cycles** (NK is `(beer,batch)`). The accumulation was **purely line-append under that ONE header**, NOT new-header-per-cycle as the commit message speculated. The fix is robust either way — §7b deletes under ALL non-tombstoned headers for the beer/batch, so even a stray duplicate header would be cleaned.

### AS-BUILT — Brief B (DATA CORRECTION, header_id=1137, 65→6)
- Collapsed **65→6 lines in one audited transaction.** Final 6: malt **PILSENER 260kg / OAT_FLAKES 60kg / WHEAT 140kg** (single WHEAT — stray 30kg dropped); hops_kettle **HOPS_C_INCOGNITO 2000g**; process **YEASTVIT 240g / PHOSPHORIQUE 700g** (final corrections kept, 850g copies dropped). `line_idx` contiguous 0-based per category. All 6 `mi_id_fk` resolved from `ref_mi` (ids **3, 20, 6, 51, 82, 80**).
- `raw_blob_text` on the header rewritten to a clean 6-element array (`JSON_LENGTH=6`).
- **2 `audit_row_revisions`** written (one for the lines, one for the blob), action=`'update'`, `qc_flag='elevated'`, actor `user_id=1`.
- 65-line pre-image snapshots kept on VPS `/tmp/b67_ingredients_pre_1137_*.json`. **No helper scripts left in either repo.**

### DOWNSTREAM / COP — clean window, no recompute now
`inv_consumption` had **0 derived rows** for these lines (the June derive has not run yet), so the correction landed in the clean window. The next June derive / `run-month-close` picks up the clean 6 lines automatically — no recompute needed now; that's Kouros's later month-close step.

### 🔴 LATENT GAP TO TRACK (deriver orphan-prune) — BACKLOG
`derive-bd-consumption.ts` does **NOT prune derived rows whose `source_row_id` no longer exists** in the parsed table — it only deletes legacy `source_row_id IS NULL` rows. So a **parsed-line DELETE that happens AFTER a derive would orphan derived COP rows** (the deleted line's old COP cost lingers). Moot for batch 67 (corrected pre-derive), **but the new form-brewing DELETE+reinsert path means ANY future `corriger` on an already-derived batch hits this** — every re-save deletes+reinserts the whole line set, orphaning the prior derive's rows by `source_row_id`. RESOLUTION OPTIONS (for Kouros / next build): (a) give the deriver an orphan-prune (delete derived rows whose `source_row_id` is absent from the live parsed table for that source), OR (b) have the form block ingredient edits post-derive. EQUIP coder+sql when built.

---

### ORIGINAL DIAGNOSIS (2026-06-19, retained for the root-cause record)

**Symptom (Kouros via Charles):** yesterday's Speakeasy brewing form — Charles deleted ingredient rows to re-input, the line count exploded. **CONFIRMED LIVE: header id=1137 (Speakeasy, batch 67, 2026-06-18) has 65 child lines in `bd_brewing_ingredients_parsed_v2` for what should be ~8** (4 malt + 1 hop + 2 process ≈ 7-8). Breakdown: malt=4 (correct, line_idx 0-3 contiguous), **hops_kettle=21 (all identical HOPS_C_INCOGNITO 2000g, line_idx 3-37 with GAPS), process=40 (PROC_YEASTVIT/PROC_PHOSPHORIQUE duplicated, line_idx 4-45 with gaps).** Gaps in line_idx (12,14,18,21,…) + multiple `imported_at` waves (09:59 / 12:03 / 12:09 / 13:08 / 14:24 / 06:12 / 06:14 next-day) = the corriger-loop signature.

**DATA MODEL (verified live):** brewing form = parent/child.
- **Header** `bd_brewing_ingredients_v2` — ONE row per `(beer, batch)` (NK `uq_natural_key (beer(64), batch)`). Holds `raw_blob_text` (JSON of the line array as submitted), `recipe_id_fk`, `event_date`. `bd_upsert` on (beer,batch) → header stays single (Speakeasy 67 = exactly 1 header, id 1137, correct).
- **Lines** `bd_brewing_ingredients_parsed_v2` — N rows per header. **NK `uq_natural_key (header_id, category, line_idx)`** (note: includes `category` — corrected from the form's own code comment which says "(header_id, line_idx)"). `header_id` FK→header (ON DELETE CASCADE), `line_idx` INT 0-based, `category` ENUM(malt,hops_kettle,hops_dry,adjunct,mineral,process), `mi_id_fk`→ref_mi (ON DELETE RESTRICT), qty/unit/lot, confidence='web_entry'.
- Writer = `form-brewing.php` (self-POST handler). schema_meta lists writer_script='parse_bd_ingredients.py' for the parsed table — STALE (that's the dead xlsx-ingest path); the LIVE writer is form-brewing.php. corrections_policy='allowed_with_side_effect' on parsed, 'allowed' on header.

**ROOT CAUSE (verified in form-brewing.php §7, ~L325-380):** the line write loop does `foreach ($ingLines as $lineIdx => $line)` → `INSERT … ON DUPLICATE KEY UPDATE` keyed on `(header_id, line_idx)` (well, the unique key is (header_id,category,line_idx)). **`$lineIdx` is the PHP ARRAY INDEX of the lines IN THE CURRENT SUBMISSION** — NOT a stable per-ingredient identity. **THERE IS NO DELETE/TOMBSTONE OF ORPHANED LINES** before/after the loop (contrast: the fermenting DryHop edit path has an explicit shrink-tombstone block `line_idx >= newLineCount`; brewing has NO equivalent). Two compounding failure modes:
  1. **No shrink on re-input with fewer lines** — if a re-submit carries fewer lines than a prior one, the higher-index orphans survive (stale lines persist).
  2. **THE RUNAWAY (the actual explosion):** the edit-prefill read-back (§ ~L745-770, `$prefillIngLines` `ORDER BY line_idx ASC` → `$prefillIngArrays`) loads ALL existing lines back into the form as a FLAT list (mi_ids/cats/qtys/units/lots arrays, position-indexed). On the next corriger submit the JS re-serializes that flat list PLUS whatever Charles re-added → the submitted `$ingLines` array is now LONGER, and because `line_idx` is positional-within-category and the prior rows are NOT cleared, each cycle lands at HIGHER `(category, line_idx)` slots that don't collide with the old ones → **net APPEND, not replace.** The gaps in line_idx (skipped 12,14,18,…) confirm the per-category positional index drifting across submits with different category orderings. So "delete a row and re-input" doesn't delete on the write side — it re-appends the whole list at a new offset. Hence 65 lines for an 8-line recipe.

**WHY MALT DIDN'T EXPLODE but hops/process did:** malt stayed 4 contiguous (line_idx 0-3) — those were entered once and the positional index for category=malt kept landing 0-3 (idempotent UPDATE). The hops + process lines are the ones Charles repeatedly deleted/re-added, so their positional indices drifted upward each cycle and never overwrote the originals.

**THE FIX (PM verdict — ✅ BUILT, see AS-BUILT block above):** mirror the fermenting DryHop shrink-tombstone discipline, but stronger — for brewing the right pattern is **DELETE-then-reinsert the full line set per (header_id) inside the submit txn** (or tombstone-all-then-upsert), so the stored lines == EXACTLY the submitted lines, never a union across submits. This was implemented as §7a/§7b/§7c in `5ad8929` (delete under ALL non-tombstoned headers for the beer/batch, plain INSERT, one log_revision). The 65-line data correction was done too (header 1137 → 6). One **latent COP gap remains** (deriver doesn't orphan-prune on `source_row_id` absence) — see the §LATENT GAP backlog note above.

---

## 🔴 STANDING OPERATIONAL FACT — operator incremental-entry workflow (applies to ALL saisie/brewing form work, not bug-specific)
> Triggers: "corriger" / "modifier" / "saisie form" / "incremental entry" / "Gonzalo" / "form POST" / "re-submit" / "partial data". Read this BEFORE building OR reviewing ANY saisie/brewing form. Elevated 2026-06-16 from the 1055-hotfix severity note (it is reusable craft, not specific to that bug).

**The fact:** Gonzalo (brewing/saisie operator) does NOT fill a form in one pass. His pattern: create the brewday/saisie **header**, then repeatedly re-open the SAME record via **"Corriger/modifier"**, add a few datapoints, and re-submit — **many times over the life of one brewday.** Consequence at the server: the **entire POST handler — every resolver, every validation, every row-builder/insert/upsert — re-runs IN FULL on every single `corriger` submit, against partial/incomplete data.** There is no "submitted once when complete" moment.

**Two durable consequences for every future form build / review:**
1. **DESIGN — idempotent + partial-tolerant POST path.** Any resolver / validation / insert / upsert in a form's POST path MUST be idempotent and tolerant of partial/incremental data across repeated submits. It must NOT assume the record is complete, must not duplicate rows on re-submit (NK-preserving upsert, not blind INSERT), and must not hard-block on a not-yet-entered field. A bug in ANY POST-path query blocks the operator on EVERY re-save, mid-entry — not just once at the end. (This is exactly why the 1055 `ONLY_FULL_GROUP_BY` regression was a HARD-BLOCK, not an edge case: the recipe resolver runs unconditionally on every `corriger`.)
2. **TESTING — smoke with the corriger LOOP, not a single happy-path submit.** Smoke-test saisie/brewing forms by submitting MULTIPLE times (header → corriger → corriger …), not one complete submit. The dangerous bug class here is "fires on every re-submit / on partial data" — a single-submit smoke can pass while every real re-save fails. Repeated-submit (open → add → re-submit ×N) is the CORRECT smoke for these forms. (Pairs with the webapp-testing default in the build-launch checklist.)

**EQUIP when this surfaces:** ui + coder + sql (+ webapp-testing for the corriger-loop smoke).

