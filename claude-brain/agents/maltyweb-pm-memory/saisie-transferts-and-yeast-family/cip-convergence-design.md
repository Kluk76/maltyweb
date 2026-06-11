> Extracted from [saisie-transferts-and-yeast-family.md](../saisie-transferts-and-yeast-family.md) during the 2026-06-05 compaction. This is the ORIGINAL CIP-convergence DESIGN record (D1 = converge all 3 forms). The AS-BUILT CIP module lives in [cip-module.md](../cip-module.md); read this only for the original design rationale.

## §CIP CONVERGENCE DESIGN — D1=CONVERGE ALL 3 FORMS NOW (canonical, 2026-05-27; verified read-only)
> ⚠️ **CANONICAL HOME MOVED: the durable, reusable cross-form CIP spec now lives in `cip-module.md`** (created 2026-05-27h per operator directive — PM owns the CIP module as a reusable pattern). That file holds the AMENDED DDL (with A3 TIMEs), the 3 operator amendments (A1/A2/A3), per-form processes, and all rulings. This section is kept as the racking-build arc context; on any divergence, `cip-module.md` wins.
> Operator ruled D1 = build `bd_cip_events` + converge racking + brewing + packaging this round. This is a durable-modeling build with high blast radius — one child table serving 3 heterogeneous forms. The consumer picture is the BEST CASE for a convergence (see below).

**VERIFIED FLAT-COLUMN INVENTORY (live, 2026-05-27):**
- `bd_racking_v2`: `last_cip_date` varchar(32), `cip_type` varchar(64), `cip_bbt_done` varchar(8), `cip_bbt_type` varchar(64), `cip_bbt_date` varchar(32). Populated: 399/399 last_cip+cip_type; cip_bbt_done 377, cip_bbt_date 361, cip_bbt_type 356.
- `bd_brewing_brewday_v2`: `cct_cip` varchar(32), `cct_cip_date` varchar(32), `yt_cip_date` varchar(32). **NO `yt_cip` column** (only the date) — the 27d index "yt_cip" claim was WRONG; correct = `cct_cip`/`cct_cip_date`/`yt_cip_date`. Populated: cct_cip 366, cct_cip_date 366, yt_cip_date 46 (of 803). Hardcoded `CIP_TYPES=['Full CIP','Acid']` in form-brewing.php L42.
- `bd_packaging_v2`: `cip_tank_done/type/date` + `cip_machines_done/type/date` (all varchar; mig 127). **Populated: 0/2236 on ALL SIX — packaging CIP was never actually captured.** (Free win: no packaging backfill needed.)
- **Distinct CIP type values across ALL tables = exactly `Full CIP` (dominant) + `Acid`.** Maps cleanly onto `ref_cip_types` seed (Soude/Acide/Full CIP/Full CIP+rinser) — `Acid`→Acide, `Full CIP`→Full CIP. Backfill match is trivial.
- All 3 PKs = `bigint unsigned auto_increment`; all 3 have `is_tombstoned`. **`bd_packaging_readings`(id BIGINT PK, packaging_id BIGINT MUL→`bd_packaging.id`, reading_idx, …) is the EXISTING 1-to-many child precedent** — `bd_cip_events` mirrors its shape.

**CONSUMER PICTURE — THE PART MOST LIKELY TO BITE, and it's CLEAN:** word-boundary grep + information_schema.VIEWS scan: the ONLY readers of flat CIP columns are (a) the 3 write forms themselves, (b) the legacy Sheets ingest (`tab_racking.py`/`tab_brewing.py`/`tab_packaging.py` live path via `ingest.py`; `ingest_bd_*_v2.py` older direct path). **ZERO COGS/COP, ZERO reports, ZERO dashboards, ZERO QA/QC surface, ZERO SQL view reads CIP.** → Converging writes breaks NOTHING downstream. No dual-read transition needed for analytics. The only thing that must stay write-compatible is the **legacy Sheets ingest** (it writes flat cols on historical re-ingest) — see migration ruling.

**CANONICAL `bd_cip_events` DDL (migration 176; re-check `--status` — as of 2026-05-27h next free = 175, so 175=ref_cip_types / 176=bd_cip_events / 177=backfill):**
> REVISED 2026-05-27h to fold in the 3 operator amendments: (A3) added `cip_started_at`/`cip_ended_at` TIME fields (mandatory all forms, NULL on backfill); `target_code` enum now CARRIES `'unspecified'` (the backfill ruling below always required it — earlier DDL omitted it, now corrected). A1 (resulting volume) + A2 (conditional dest-CIP) do NOT touch this DDL — see the rulings below.
```sql
CREATE TABLE bd_cip_events (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  source_form   ENUM('racking','brewing','packaging') NOT NULL,
  racking_id    BIGINT UNSIGNED NULL,
  brewing_id    BIGINT UNSIGNED NULL,
  packaging_id  BIGINT UNSIGNED NULL,
  target_kind   ENUM('machine','vessel') NOT NULL,
  target_code   ENUM('centri','kze','pump','cct','yt','bbt','tank','unspecified') NOT NULL,
  target_number INT UNSIGNED NULL,            -- vessel no. (cct/yt/bbt); NULL for machines + 'tank' + 'unspecified'
  cip_type_id_fk INT UNSIGNED NULL,           -- FK → ref_cip_types.id, ON DELETE RESTRICT
  cip_date      VARCHAR(32) NULL,             -- match flat-col type (operator dates are free-text varchar today)
  cip_started_at TIME NULL,                   -- A3: mandatory on NEW writes (all forms); NULL on historical backfill
  cip_ended_at   TIME NULL,                   -- A3: mandatory on NEW writes (all forms); NULL on historical backfill
  inline_group  TINYINT UNSIGNED NULL,        -- per-parent group id; same value = one combined inline event (centri+KZE)
  notes         VARCHAR(255) NULL,
  row_hash      CHAR(64) NOT NULL,            -- bd_row_hash over the canonical event fields (incl. the two TIMEs)
  submitted_at  VARCHAR(32) NULL,
  email         VARCHAR(255) NULL,
  is_tombstoned TINYINT(1) NOT NULL DEFAULT 0,
  imported_at   TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_racking  (racking_id),
  KEY idx_brewing  (brewing_id),
  KEY idx_packaging(packaging_id),
  CONSTRAINT fk_cip_racking   FOREIGN KEY (racking_id)   REFERENCES bd_racking_v2(id)         ON DELETE RESTRICT,
  CONSTRAINT fk_cip_brewing   FOREIGN KEY (brewing_id)   REFERENCES bd_brewing_brewday_v2(id) ON DELETE RESTRICT,
  CONSTRAINT fk_cip_packaging FOREIGN KEY (packaging_id) REFERENCES bd_packaging_v2(id)       ON DELETE RESTRICT,
  CONSTRAINT fk_cip_type      FOREIGN KEY (cip_type_id_fk) REFERENCES ref_cip_types(id)       ON DELETE RESTRICT,
  CONSTRAINT chk_cip_parent_xor CHECK (
    (source_form='racking'   AND racking_id   IS NOT NULL AND brewing_id IS NULL AND packaging_id IS NULL) OR
    (source_form='brewing'   AND brewing_id   IS NOT NULL AND racking_id IS NULL AND packaging_id IS NULL) OR
    (source_form='packaging' AND packaging_id IS NOT NULL AND racking_id IS NULL AND brewing_id IS NULL)
  ),
  CONSTRAINT chk_cip_target CHECK (
    (target_kind='machine' AND target_code IN ('centri','kze','pump','unspecified')) OR
    (target_kind='vessel'  AND target_code IN ('cct','yt','bbt','tank'))
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```
- **A3 — start/end TIME on EVERY CIP event (mandatory, ALL forms; standing cross-form rule).** Operators actively look up + enter the start and end of each CIP so no machine is ever started without a prior CIP and the audit window is explicit. **TYPE DECISION: two `TIME` columns (`cip_started_at`/`cip_ended_at`), NOT two DATETIMEs.** Rationale: the DATE is ALREADY captured as the free-text varchar `cip_date` (operator date strings are free-text today — house convention; do NOT silently re-type to DATE mid-convergence). A DATETIME would force a real datetime parse and FIGHT the free-text-varchar date convention; keeping date=varchar + time=TIME is internally consistent and keeps the two facts (which day / what window) cleanly separated. The pair models the CIP window; an overnight CIP (end < start) is a validation note for the writer, not a schema concern. Both NULL-able at the column level (so backfill can leave them NULL) but **the writer/form enforces them as REQUIRED on every NEW event** — refuse-don't-NULL on live capture, NULL only on historical backfill (never invent a window we don't have). Both TIMEs join the `row_hash` field set.
- **Polymorphic parent = `source_form` ENUM + 3 nullable typed FKs + XOR CHECK** (the house "ENUM type + N mutually-exclusive _id + CHECK" durable-modeling rule, EXACTLY as the inv_deliveries precedent). NOT a single `(parent_table,parent_id)` pair — that loses the real FK (broken-derivation smell) and can't be enforced by the engine. Typed FKs give referential integrity per parent.
- **FK = `ON DELETE RESTRICT`, NOT CASCADE** — parent rows are audited + tombstoned, never hard-deleted. CASCADE would silently destroy CIP history when a parent is (wrongly) deleted. Child gets its own `is_tombstoned`; tombstoning a parent does NOT auto-tombstone children (writer handles re-sync on re-submit).
- **Target model = `target_kind` ENUM + `target_code` ENUM + `target_number`.** Machines (centri/kze/pump) → number NULL. Vessels (cct/yt/bbt) → the vessel no. `'tank'` = packaging's generic serving tank (number NULL or the YT/BBT no. if known). The CHECK couples kind↔code so a 'machine' can't carry a vessel code.
- **inline-group = `inline_group TINYINT` (per-parent group id), NOT a boolean.** The "centri+KZE inline" combined event = TWO rows (one centri, one kze) sharing the same `inline_group` value under the same parent. A boolean can't express WHICH machines were inline if there were ever 3+; a group id generalizes and lets the form render "these were CIP'd together inline". For a standalone (non-inline) machine event, `inline_group=NULL`. The form's "centri+KZE inline" checkbox → writer assigns both rows the next group int.
- **MACHINE LIST = FIXED ENUM in `target_code`, NOT an editable ref table.** Operator named exactly 3 machines (centri/kze/pump) and did NOT ask for editability. Machines are commissioned equipment (Salle des Machines territory) and change on the order of years, not weeks — an editable list is premature. CONFIRMED: fixed enum. (If a 4th machine is ever commissioned, it's a one-line ALTER ENUM — cheaper than a CRUD surface no one asked for.) Contrast `ref_cip_types` which IS editable (the operator explicitly asked).
- `cip_date`/`submitted_at` kept VARCHAR(32) to MATCH the flat columns (operator date strings are free-text today; don't silently re-type to DATE mid-convergence — that's a separate hardening pass). `row_hash` via `bd_row_hash()` over (source_form, parent_id, target_code, target_number, cip_type_id_fk, cip_date, **cip_started_at, cip_ended_at**, inline_group) for idempotent re-submit/re-ingest.
- **schema_meta row REQUIRED** (table_class=transactional, corrections_policy per the bd_* convention, writer=web+ingest).

**PER-FORM FLAT-COLUMN → bd_cip_events MAPPING:**
- **RACKING** (2 logical CIP facts today → events):
  - equipment-CIP `last_cip_date`+`cip_type` → 1 event: source_form='racking', target_kind='machine'?? — ⚠️ NB racking's `last_cip` is NOT machine-scoped today (it's "date of last CIP" generic). Operator's per-machine restructure (#8) REPLACES this with per-machine rows (centri/kze/pump). So NEW racking writes = N machine events (centri/kze/pump checkboxes) + the destination-vessel CIP. BACKFILL of the 399 historical rows = 1 event target_kind='machine' target_code=NULL?? — can't: target_code is NOT NULL. **RULING: backfill historical racking equipment-CIP as a single event with `target_kind='machine', target_code='pump'`?? NO — don't invent a machine.** Backfill it as `target_kind='vessel', target_code='cct', target_number=NULL` is also wrong. → **Backfill historical generic `last_cip` as target_kind='machine' with a dedicated `target_code` is impossible without inventing data.** See BACKFILL RULING below — historical generic-CIP stays representable via a `target_code` that means "unspecified", OR we DON'T backfill the generic last_cip and only backfill the structured cip_bbt_*. **DECISION: extend target_code with `'unspecified'` (machine-kind) for the historical generic racking last_cip; new structured writes use centri/kze/pump.** (This keeps backfill faithful — "a CIP happened, machine unspecified" — without guessing which machine.)
  - destination-CIP `cip_bbt_done`+`cip_bbt_type`+`cip_bbt_date` → 1 event when done='Yes': target_kind='vessel', target_code = the destination type (bbt/cct/yt per `racking_destination_type`), target_number = the dest tank no., cip_type_id_fk = resolve cip_bbt_type. done='No' → NO event (absence = not done).
- **BREWING** (`bd_brewing_brewday_v2`):
  - `cct_cip`+`cct_cip_date` → 1 event: source_form='brewing', target_kind='vessel', target_code='cct', target_number = brewday cct, cip_type_id_fk = resolve cct_cip, cip_date=cct_cip_date.
  - `yt_cip_date` (no type col) → 1 event when date present: target_kind='vessel', target_code='yt', cip_type_id_fk=NULL (no type was captured), cip_date=yt_cip_date.
- **PACKAGING** (`bd_packaging_v2`) — **0 rows populated, so NO BACKFILL.** New writes only: `cip_tank_*` → 1 vessel event (target_code='tank'); `cip_machines_*` → machine event(s) (centri/kze/pump per the new per-machine UI). The packaging form's CIP UI gets the SAME shared component as racking.

**BACKFILL RULING:** Backfill historical CIP into `bd_cip_events` (don't leave it stranded in flat columns) BECAUSE the convergence's whole point is one canonical store; a half-migrated fact (new rows in child, old rows in flat) is itself a parallel-store smell. Volume is small + clean (399 racking + 366 brewing = ~765 events + the cip_bbt subset; packaging 0). Map `Full CIP`→ref_cip_types 'Full CIP', `Acid`→'Acide'. Generic racking `last_cip` (no machine identity) → target_kind='machine', target_code='unspecified' (added to the enum) — faithful, refuse-don't-guess (we do NOT assign it to centri/kze/pump we can't prove). **The flat columns are KEPT (not dropped) this round** — they are the legacy Sheets-ingest write target and dropping them mid-convergence would break `tab_*.py` re-ingest + cause hash drift on the parent `bd_*_v2` rows. Flat columns become READ-DEAD (no consumer reads them) but WRITE-LIVE for ingest only; a later hardening migration retires them once the Sheets ingest is itself retired or rewired to write `bd_cip_events`. → RULE-1 scrapping backlog item: "retire flat CIP columns + rewire Sheets ingest to bd_cip_events."

**WRITER HELPER:** new `app/cip-events.php` — `cip_upsert($pdo, $sourceForm, $parentId, array $events)` that (a) resolves cip_type name→id via ref_cip_types, (b) computes row_hash per event, (c) tombstone-resyncs (on parent re-submit, tombstone prior events for that parent + insert current set — mirrors how bd_upsert handles re-submit), (d) assigns inline_group ints. Called from all 3 forms AFTER `bd_upsert` returns the parent `id`. Shared CIP UI partial (the per-machine checkbox grid + type dropdowns) so the 3 forms render identical CIP capture — this is the ONE place a shared partial IS justified (vs the section-order reorder, which is not).

**MIGRATIONS:** 175 = `ref_cip_types` (+seed Soude/Acide/Full CIP/Full CIP+rinser +schema_meta). 176 = `bd_cip_events` (+schema_meta). 177 (or fold into 176's apply) = backfill INSERT…SELECT from the 3 flat sources. (re-check `--status`; VPS can lead local git — the 166/167/168 collision precedent.)

**SEQUENCING (CIP convergence inside Round 2):**
1. **Mig 175 `ref_cip_types`** (gates every CIP-type dropdown + the FK). FIRST, blocks all else.
2. **Mig 176 `bd_cip_events`** + `app/cip-events.php` writer + shared CIP UI partial. Depends on 175 (FK). Then backfill (177 or 176-tail).
3. **Form rewrites consume the writer** — racking + brewing + packaging each: replace flat-col POST capture with the shared CIP component → `cip_upsert`. Can parallelize ACROSS the 3 forms once 176+writer land, but RULE-2 each before its commit. Racking also carries the rest of its Round-2 edits (R2-C); brewing/packaging get CIP-only + the CIP-first reorder.
4. **R2-B salle-de-controle `ref_cip_types` editor** (list-CRUD) needs only 175 → parallelizes with the form work.
5. **CIP-first reorder** = per-form markup move (confirmed below) — bundle into each form's CIP rewrite (same files, same RULE-2).

**RELATIONSHIP TO THE REST OF R2:** R2-A now = migs 175 + 176 (+ backfill) + the writer + shared partial. R2-B (CIP editor) unchanged, needs 175. R2-C (form-racking other edits: client drop, YT dropdown, label relabels incl D3, vitesse drop, BBT-into-hors-process) is INDEPENDENT of the CIP child table and can land in the same racking commit or separately. Brewing + packaging now ALSO get a Round-2 touch (previously they were untouched) — scope them as their own commits (CIP convergence + CIP-first reorder only; do NOT widen into other brewing/packaging changes this round).

**MIGRATIONS THIS ROUND (provisional, re-check `--status`):** #10 `ref_cip_types` (+seed+schema_meta) = 175. #8 `bd_cip_events` child table (+schema_meta) = 176 IF child-table chosen. #4 `yt_number` on bd_racking_v2 = only IF D2=persist-number. Points 2/5/6/7/9 + most of 4 = NO migration (pure UI/JS/behavior). #1/#3 = behavior/SQL, no migration.

**SEQUENCING (Round 2):**
- Phase R2-A (master-data, gates the dropdowns): mig 175 `ref_cip_types` + seed + schema_meta; IF child-table chosen, mig 176 `bd_cip_events` + `app/cip-events.php` writer; IF D2, the `yt_number` column.
- Phase R2-B (salle-de-controle CIP editor): the ref_cip_types list-CRUD section/subtab — needs R2-A mig 175. Parallelizable with R2-C.
- Phase R2-C (form-racking edits): pure-UI relabels (#4 labels, #5, parts of #6/#9) + JS dynamic labels (#6/#9) + drop inputs (#3 client, #7 vitesse) + YT dropdown (#4) + BBT-into-hors-process (#1) + CIP-section-first reorder + Type-CIP dropdown (#10, reads ref_cip_types so needs 175) + CIP restructure (#8, needs 176 if child-table). #2 = verify-only.
- Pure-UI/JS (no DB): 2, 5, 6, 7, 9, label-parts-of-4. Schema-backed: 8, 10, (4 if yt_number). Behavior/SQL: 1, 3.
- **DISPATCH: Sonnet, equip ui+sql+coder. RULE-2 before commit (XSS on the new dropdowns/labels, dynamic-label can't desync from persisted destType, dropped-input fields fully removed from POST coerce+row+hash, no helper scripts). RULE-1: log the CIP-first global rollout (brewing+packaging) + the CIP-model convergence as backlog if not done this round.**

**§OPERATOR AMENDMENTS (3, recorded 2026-05-27h) — A1 output volume / A2 conditional dest-CIP / A3 start+end TIME:**

**A1 — Destination resulting volume = `racked_vol_hl + blend_hl`. RULING: PURE DISPLAY computation, NO stored field. (Operator's lean confirmed.)** Both inputs are ALREADY stored canonical facts on `bd_racking_v2` (verified live: `racked_vol_hl` decimal(8,3) + `blend_hl` decimal(8,3), both captured by the form). `blend_hl` is relabelled "Volume résiduel en cuve" (the D3 pure-relabel already ruled; residual can be 0). The resulting volume is the SUM of two stored facts → storing it would be a **derived-duplicate = one-fact-many-stores smell + drift risk**. DECISIVE: `app/tank-simulator.php` (the occupancy authority) ALREADY computes this exact sum itself — its comment (L581-586) explicitly says "BSF col W = racked_vol + blend_leftover" and it DELIBERATELY does NOT read that precomputed total, instead deriving `racked_vol + blend_vol` from the two raw inputs (L609/L612/L628-629) to avoid the historical "every rack treated as a blend, BBT volumes inflated 2-3×" bug. A stored "resulting volume" column would re-introduce exactly the duplicate the simulator was hardened against. **So: display-only in the form (JS sum of the two inputs, server can echo it but stores nothing); the canonical fact stays the two inputs; downstream (tank-simulator) keeps deriving its own sum. No migration, no column.**

**A2 — Destination-tank CIP conditionally optional (blend rule). Standing CIP-module rule (generalizes): a destination tank whose residual volume > 0 SKIPS its CIP (you don't CIP a tank already holding the same beer = a blend); residual 0/blank ⇒ destination CIP REQUIRED.** Machine/equipment CIP obligations are UNAFFECTED by this rule (always governed by their own per-machine checkboxes). Canonical validation predicate (server is authoritative; client mirrors for UX — server NEVER trusts the client):
```
# residual = parsed blend_hl ("Volume résiduel en cuve"), numeric, default 0 if blank/non-numeric
# destCipPresent = a destination-vessel CIP event exists for this submission
#                  (target_kind='vessel' with target_code = the destination type bbt/cct/yt,
#                   carrying its cip_type + the A3 start/end TIMEs)
residual = to_float(blend_hl, default=0.0)
destCipRequired = (residual <= 0.0)          # 0 or blank ⇒ required; >0 (blend) ⇒ optional
VALID  iff  (destCipPresent OR not destCipRequired)
# i.e. REJECT only when residual<=0 AND no destination-vessel CIP event was provided.
# Machine/equipment CIP events are validated independently and are NOT relaxed by residual>0.
```
- Server: enforce in `form-racking.php` POST handler BEFORE `cip_upsert`, after parsing `blend_hl` — reject (re-render with error, PRG) when `residual<=0 && !destCipPresent`. Client: `racking-form.js` mirrors — toggle the destination-CIP block's required-ness reactively on the residual input's value (residual>0 ⇒ destination-CIP optional/dimmed; residual<=0/blank ⇒ required). Use the SAME numeric coercion both sides (blank/non-numeric → 0 → required) so client and server can't disagree.
- **Generalization (cross-form):** the predicate is "tank-with-residual skips its tank-CIP." It applies wherever a form captures a destination/serving tank that may carry a residual (racking destination now; packaging serving-tank if it ever gains a residual concept). Record as a CIP-module rule, not a racking one-off.

**A3 — every CIP event carries start+end TIME (mandatory, ALL forms).** Folded into the DDL above (`cip_started_at`/`cip_ended_at` TIME, both in `row_hash`, writer-enforced-required on new events, NULL on backfill). See the A3 note under the DDL for the type rationale (two TIMEs not DATETIMEs — date stays the free-text varchar `cip_date`). This is a standing cross-form rule: NO machine started without a prior CIP whose window is auditable.

**DIVERGENCE / DERIVATION-TREE RISKS (Round 2):**
- The `client` free-text column = string-copy of a ref_clients fact (broken-FK smell) — #3 is the fix, don't reintroduce free-text.
- **A1 resulting-volume MUST NOT be stored** — it is `racked_vol_hl + blend_hl`, a sum of two stored facts the tank-simulator already derives independently; a stored column = duplicate-fact + drift (re-introduces the very bug tank-simulator L581-586 was hardened against). Display-only.
- 3-way CIP divergence (brewing/packaging/racking flat cols) — #8/#10 are the convergence opportunity; flat-columns-again perpetuates the parallel store. **RESOLVED-DIRECTION 2026-05-27: D1=converge all 3 onto `bd_cip_events` now. New divergence traps introduced by the convergence:** (a) DUAL-WRITE — flat cols are KEPT (legacy Sheets-ingest target) but READ-DEAD; the forms write bd_cip_events ONLY, NOT both (don't dual-write from the web forms or you re-create the parallel store you're killing). (b) HASH-DRIFT — flat CIP cols stay in the parent `bd_*_v2` row's hash IF still written by ingest, but the WEB form must REMOVE the dropped flat-CIP fields from its parent hashCols (form-racking L147-161) OR keep writing them; a half-removal silently drifts the parent hash. RULING: web form stops populating flat CIP cols → remove them from the web parent hash; ingest keeps them (separate write path, separate hash basis). (c) FK/CHECK MySQL-8: the two CHECKs are named (chk_cip_parent_xor / chk_cip_target) — names are schema-unique, prefix-OK; a CASCADE-FK column CANNOT appear in a CHECK, so FKs are RESTRICT (which is correct anyway). (d) inline-group = TINYINT group-id not boolean (a boolean can't say WHICH machines were inline). (e) backfill faithfulness — generic racking `last_cip` has no machine identity → target_code='unspecified', never guessed to centri/kze/pump.
- Dynamic CO2/O2/CIP labels (#6/#9) must read the PERSISTED destType — a label that says "CCT" while the column-name implies BBT is fine (column is legacy-named) BUT the operator-facing label must match what was actually selected, never desync.
- Dropping inputs (#3/#7): remove from POST coerce + row array + hash cols (form-racking L96/L113 + L150-160) — a half-removal leaves them in the hash (silent hash drift) or writes stale values.
- #8 child-table: parent_id must be the bd_racking_v2.id returned by bd_upsert — FK integrity, ON DELETE handling (tombstone-aware, not CASCADE-delete an audited row).

