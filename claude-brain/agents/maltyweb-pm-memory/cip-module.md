# CIP MODULE — reusable CROSS-FORM spec (PM-owned standing reference) — ✅ AS-BUILT / LIVE ON ALL 3 FORMS (2026-05-27j; packaging CIP corrected to Soutireuse-anchor + optional-KZE 2026-05-31, mig 230, committed `2851ae5`, see §PACKAGING CIP FIX)

> **Load this whenever a build touches CIP on ANY form (live on racking/brewing/packaging; future forms extend the SAME contract).** The CIP module is a REUSABLE PATTERN instantiated per form, not a per-form build. PM is its keeper. This file is the authoritative, cross-form CIP spec — it supersedes the scattered §CIP notes in `saisie-transferts-and-yeast-family.md`. **STATUS: SHIPPED + DEPLOYED + PM-VERIFIED LIVE 2026-05-27.** Migrations **175 `ref_cip_types` / 176 `bd_cip_events` / 177 backfill** all applied (`migrate.php --status` = 175/176/177 ✓, 0 pending). Four commits on `main`: `8c53e98` (schema + shared writer/partial), `36b72c5` (Salle-de-contrôle CIP-types list-CRUD), `b269eee` (racking round-2 + CIP consume + mig **179** `bd_racking_v2.yt_number`), `592641d` (brewing+packaging converge). The design below = AS-DESIGNED + AS-BUILT (deltas flagged inline with **AS-BUILT**).
>
> **🔑 REUSABLE ENTRY POINT for the NEXT CIP-on-a-form build:** instantiate the live contract — `app/partials/cip-section.php` (shared UI partial, takes a `$cipConfig` array) + `app/cip-events.php` `cip_upsert($pdo,$sourceForm,$parentId,$events)` (shared atomic writer, called AFTER `bd_upsert` returns the parent id) + `public/css/cip-section.css`. A new form: (1) add its `source_form` ENUM value + typed FK column to `bd_cip_events` (migration); (2) render `cip-section.php` with a `$cipConfig` selecting which target_kinds/codes that form captures; (3) call `cip_upsert` in the POST handler. Do NOT re-model CIP per form.

## THE SHARED, REUSABLE MODEL (one fact, one table — all forms write here)

**Why a module, not per-form columns:** CIP was modelled DIVERGENTLY across 3 forms (one-fact-three-stores smell) — brewing `cct_cip`/`cct_cip_date`/`yt_cip_date`, packaging `cip_tank_*`/`cip_machines_*`, racking `last_cip_date`/`cip_type`/`cip_bbt_*`. CIP-per-machine is inherently 1-to-many, so the canonical store is a CHILD table `bd_cip_events` all forms write to. Operator ruled (D1, 2026-05-27): converge ALL 3 forms onto it NOW. Precedent for the 1-to-many child shape: `bd_packaging_readings` (id BIGINT PK, packaging_id → bd_packaging.id, …).

**CONSUMER PICTURE = BEST CASE (verified read-only):** the ONLY readers of the flat CIP columns are (a) the 3 write forms, (b) the legacy Sheets ingest (`tab_racking.py`/`tab_brewing.py`/`tab_packaging.py` + older `ingest_bd_*_v2.py`). ZERO COGS/COP, ZERO reports, ZERO dashboards, ZERO QA/QC, ZERO SQL view reads CIP. Converging writes breaks nothing downstream — no dual-read analytics transition needed.

### `bd_cip_events` — canonical child table (migration 176) — REVISED with A3 TIME fields
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
  cip_date      VARCHAR(32) NULL,             -- free-text date (matches the house varchar-date convention)
  cip_started_at TIME NULL,                   -- A3: writer-REQUIRED on new events (all forms); NULL only on backfill
  cip_ended_at   TIME NULL,                   -- A3: writer-REQUIRED on new events (all forms); NULL only on backfill
  inline_group  TINYINT UNSIGNED NULL,        -- per-parent group id; same value = one combined inline event (centri+KZE)
  notes         VARCHAR(255) NULL,
  row_hash      CHAR(64) NOT NULL,            -- bd_row_hash over the canonical event fields (incl. both TIMEs)
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
**Model rules (each load-bearing):**
- **Polymorphic parent** = `source_form` ENUM + 3 mutually-exclusive typed FKs + XOR CHECK = the house "ENUM type + N mutually-exclusive `_id` + CHECK" durable-modeling rule (the `inv_deliveries` precedent). NOT a `(parent_table,parent_id)` pair — that loses the real FK (broken-derivation smell) and the engine can't enforce it. Typed FKs give per-parent referential integrity.
- **FK = ON DELETE RESTRICT, never CASCADE** — parents are audited + tombstoned, never hard-deleted; CASCADE would silently destroy CIP history. Child has its OWN `is_tombstoned`; tombstoning a parent does NOT auto-tombstone children (the writer re-syncs on re-submit).
- **Target model** = `target_kind` ENUM + `target_code` ENUM + `target_number`. Machines (centri/kze/pump) → number NULL. Vessels (cct/yt/bbt) → vessel no. `'tank'` = packaging serving tank. `'unspecified'` (machine-kind) = historical generic racking `last_cip` with no machine identity (backfill only — never assign new writes to it). The CHECK couples kind↔code so a machine can't carry a vessel code.
- **`target_code` MACHINE LIST = FIXED ENUM, NOT an editable ref table.** Operator named exactly 3 machines (centri/kze/pump), didn't ask for editability; machines are commissioned equipment (Salle des Machines territory) changing on the order of years. A 4th machine = a one-line ALTER ENUM, cheaper than a CRUD surface. Contrast `ref_cip_types` which IS editable (operator explicitly asked).
- **`cip_type_id_fk` → `ref_cip_types`** = the editable CIP-type list (see below). ONE shared list serves both machine-CIP and vessel-CIP (D5 confirmed).
- **`inline_group` = TINYINT group-id, NOT a boolean.** "centri+KZE inline" = TWO rows (one centri, one kze) sharing the same `inline_group` value under the same parent. A boolean can't say WHICH machines were inline if ever 3+; a group id generalizes. Standalone machine event → `inline_group=NULL`. **UI (as built): the "centri+KZE simultané" checkbox COLLAPSES the two individual machine rows into ONE combined input block** (one Type CIP / date / start-end pair); on submit the parser emits both rows from that single combined block, sharing the next group int (it does NOT read the hidden individual centri/kze fields). Unchecking restores the two separate rows. Pump is always independent.
- **A3 start/end TIME** (folded in 2026-05-27h): `cip_started_at`/`cip_ended_at` TIME, both mandatory on NEW writes (writer/form-enforced), NULL only on backfill. TWO TIMEs not DATETIMEs because the DATE is already the free-text varchar `cip_date` (house varchar-date convention — don't fight it with a datetime parse). Both join `row_hash`.
- `cip_date`/`submitted_at` = VARCHAR(32) to match the flat columns (house free-text-date convention; re-typing to DATE is a separate hardening pass, not this convergence).
- `row_hash` via `bd_row_hash()` over (source_form, parent_id, target_code, target_number, cip_type_id_fk, cip_date, cip_started_at, cip_ended_at, inline_group) for idempotent re-submit/re-ingest.
- **schema_meta row REQUIRED** (table_class=transactional, corrections_policy per the bd_* convention, writer=web+ingest).

### `ref_cip_types` — editable CIP-type list (migration 175, gates everything)
```sql
ref_cip_types (id INT UNSIGNED PK AUTO_INCREMENT, name VARCHAR(64) NOT NULL UNIQUE,
               sort_order INT DEFAULT 0, is_active TINYINT(1) DEFAULT 1, notes TEXT,
               created_at, updated_at, updated_by)
```
- **Seed: Soude, Acide, Full CIP, Full CIP + rinser.** schema_meta row (class=reference, corrections_policy=allowed).
- **HOME = Salle de Contrôle** (QA/QC family) — a new `#sec-cip` list editor near Biochimie (CIP is process master-data, NOT recipe-scoped → NOT a Recettes subtab). It's a LIST editor (add/remove/modify) → mirror the SKU-format activate/deactivate **list-CRUD** pattern, NOT the yeast-family UPDATE-only handler. Each write = require_login + admin/manager gate + csrf + two-step validate + log_revision + PRG.
- Forms' "Type CIP" inputs → `<select>` from `SELECT id,name FROM ref_cip_types WHERE is_active=1 ORDER BY sort_order,name`. Distinct historical CIP-type values across ALL tables = exactly `Full CIP` + `Acid` → backfill maps `Acid`→Acide, `Full CIP`→Full CIP (trivial).

### Writer + UI partial (shared across all forms)
- **`app/cip-events.php`** — `cip_upsert($pdo, $sourceForm, $parentId, array $events)`: (a) resolves cip_type name→id via ref_cip_types; (b) computes row_hash per event; (c) **tombstone-resyncs** on parent re-submit (tombstone prior events for that parent + insert current set — mirrors `bd_upsert`); (d) assigns inline_group ints; (e) ENFORCES A3 start/end TIME required on every new event. Called from ALL 3 forms AFTER `bd_upsert` returns the parent `id`.
- **ONE shared CIP UI partial** (`app/partials/cip-section.php`, takes `$cipConfig`): per-machine rows (type dropdown + date + start/end TIME) + per-vessel CIP + the **centri+KZE inline-combine** toggle. When `$cipConfig['machines']` has both centri+kze and `show_inline_combine=true`, checking "simultané" COLLAPSES the two individual machine rows into ONE combined input block (one type/date/start-end → both events, shared `inline_group`); pump stays independent. The 3 forms render identical CIP capture from this one partial. This is the ONE place a shared partial IS justified — vs the CIP-first section reorder, which is per-form markup (no shared form template exists; only sidebar/topbar chrome are shared).

### Backfill (migration 177 or 176-tail)
- Backfill historical flat CIP INTO `bd_cip_events` (a half-migrated fact = parallel-store smell). Volume: racking 399 + brewing 366 ≈ 765 events; packaging 0 (never captured — free). Map `Full CIP`→'Full CIP', `Acid`→'Acide'.
- Generic racking `last_cip` (no machine identity) → `target_kind='machine', target_code='unspecified'` — faithful, refuse-don't-guess (NEVER assign to centri/kze/pump we can't prove).
- **Backfilled rows: `cip_started_at`/`cip_ended_at` = NULL** (don't invent a window we don't have).
- **Flat columns KEPT this round** (NOT dropped): they're the legacy Sheets-ingest write target; dropping mid-convergence breaks `tab_*.py` re-ingest + drifts the parent `bd_*_v2` row hash. Flat cols become READ-DEAD (no consumer reads them) but WRITE-LIVE for ingest only. Retire later when ingest is rewired → RULE-1 scrapping #14: "retire flat CIP columns + rewire Sheets ingest to bd_cip_events."

### CIP-first section ordering
Operator wants CIP as the first section in EVERY form. There is NO shared form template (each `form-*.php` is bespoke) → this is a per-form markup reorder, applied form-by-form, bundled into each form's CIP rewrite (same files, same RULE-2). Do NOT build a shared form template speculatively just for section order.

## PER-FORM DISTINCT CIP PROCESSES (each instantiation picks its target_kinds/codes from the shared model)

- **RACKING / TRANSFERTS** = equipment CIP (machines centri/kze/pump — checkbox per machine, "centri+KZE inline" combine option → shared inline_group) + destination-tank CIP (vessel: bbt/cct/yt per `racking_destination_type`, target_number = dest tank no.). NEW writes = N machine events + (conditionally, per A2) 1 destination-vessel event. Historical: equipment-CIP `last_cip`/`cip_type` → 1 event target_kind='machine' target_code='unspecified'; destination `cip_bbt_*` (done='Yes') → 1 vessel event target_code = dest type, done='No' → NO event.
- **BREWING** = vessel CIP only: CCT + YT. `cct_cip`+`cct_cip_date` → 1 event vessel/cct (target_number = brewday cct, cip_type=resolve(cct_cip)). `yt_cip_date` → 1 event vessel/yt (cip_type_id_fk=NULL — brewing has NO yt_cip done-flag/type column today, only the date). NB index errata corrected: brewing cols = `cct_cip`/`cct_cip_date`/`yt_cip_date`, there is NO `yt_cip` column.
- **PACKAGING** = tank CIP (`cip_tank_*` → 1 vessel event target_code='tank') + machine CIP (`cip_machines_*` → machine event(s) centri/kze/pump per the per-machine UI). 0 rows ever populated → NO backfill. Gets the SAME shared CIP component as racking.
- **FERMENTING = NO-CIP (DURABLE RULE, reinforced 2026-06-03).** form-fermenting is NOT part of the CIP module — it captures NO CIP event AND runs NO CCT-cadence firewall. **Why it matters / how it bit us:** the fermenting START-phase firewall had, in the pilot-4 build, PORTED the racking CCT CIP-cadence staleness gate verbatim ("Dernier CIP il y a Nj" + mandatory Raison override on first event). That is a CATEGORY ERROR — the CCT is BY DESIGN full of fermenting beer post-brewday, so a CIP-staleness check on a plain fermentation read is nonsensical and blocks the operator. Gate-2 was REMOVED 2026-06-03 across 5 files (`fermenting-phase-start.php` render+severity+hidden override inputs, `session-body-fermenting.php` the `cct_cip_status()` call, `form-fermenting.js` `initCipOverride()`, `form-fermenting.php` dead `window.FERMENTING_FIREWALL`, `fermenting-phase-submit.php` server-side CIP re-verify + override logging; −191 lines). KEPT = Gate-1 CCT-presence + yeast/garde eligibility (those still need the CCT lookup, just not the cadence). Resolver `app/cct-cip-cadence.php` left on disk (racking still uses it; **racking firewall UNTOUCHED**). **STANDING: do NOT re-add any CIP capture or CIP-cadence firewall to fermenting; dry-hop-EQUIPMENT CIP is a separate PARKED operator-confirmed future build — if it lands it is its own equipment-CIP instantiation of THIS module, not a CCT-cadence gate on the fermentation read.** (Companion fix same day, INDEPENDENT of CIP: `fermenting-phase-submit.php` 500'd on every POST — `undefined flash_set()` — because it never required `app/settings-helpers.php` [loaded only by the module page, not the API endpoint the form POSTs to]; the now-removed firewall had masked it by blocking reach to submit. Fixed with `require_once settings-helpers.php`; endpoint now PRG-helper-complete. Class-bug recorded in `ui` skill: render path ≠ submit path file-load → POST end-to-end before declaring a form done.)

## THE 3 OPERATOR AMENDMENTS (2026-05-27h) — standing cross-form CIP rules

**A1 — destination resulting volume = `racked_vol_hl + blend_hl`. PURE DISPLAY, NO stored field.** Both inputs already stored on `bd_racking_v2` (verified: `racked_vol_hl` + `blend_hl`, both decimal(8,3)). `blend_hl` relabelled "Volume résiduel en cuve" (D3 relabel; residual can be 0). The sum is a derived-duplicate → storing it = one-fact-many-stores + drift. DECISIVE: `app/tank-simulator.php` (occupancy authority) ALREADY derives this exact sum from the two raw inputs (L609/L612/L628-629) and DELIBERATELY refuses to read any precomputed total (its comment L581-586 records the historical "every rack treated as a blend, BBT volumes inflated 2-3×" bug from reading the precomputed col W). A stored column re-introduces that hazard. → form shows a JS/echoed sum; stores nothing; downstream keeps deriving its own. No migration.

**A2 — destination-tank CIP conditionally optional (blend rule); generalizes as a CIP-module rule: a tank with residual volume > 0 SKIPS its CIP** (you don't CIP a tank already holding the same beer = a blend). residual 0/blank ⇒ destination CIP REQUIRED. Machine/equipment CIP obligations UNAFFECTED. Canonical predicate (server authoritative, client mirrors; server never trusts client):
```
residual = to_float(blend_hl, default=0.0)        # blank/non-numeric → 0
destCipRequired = (residual <= 0.0)               # 0/blank ⇒ required; >0 (blend) ⇒ optional
destCipPresent  = a destination-vessel CIP event exists for this submission
                  (target_kind='vessel', target_code = the destination type bbt/cct/yt)
VALID  iff  (destCipPresent OR not destCipRequired)
# REJECT only when residual<=0 AND no destination-vessel CIP event provided.
# Machine/equipment CIP events validated independently — NOT relaxed by residual>0.
```
Server: enforce in the form POST handler BEFORE `cip_upsert`, after parsing `blend_hl` → reject (re-render + PRG) when `residual<=0 && !destCipPresent`. Client: mirror reactively on the residual input (residual>0 ⇒ dest-CIP block optional/dimmed; else required). SAME numeric coercion both sides so they can't disagree. Generalizes anywhere a form captures a destination/serving tank that can carry a residual.

**A3 — every CIP event has start TIME + end TIME (mandatory, ALL forms).** Operators look up + enter start/end of each CIP so no machine is started without a prior CIP and the window is auditable. Implemented as `cip_started_at`/`cip_ended_at` TIME on `bd_cip_events` (see DDL): two TIMEs not DATETIMEs (date stays the free-text varchar `cip_date`); both in `row_hash`; writer-enforced REQUIRED on new events; NULL on backfill (never invent a window).

## ✅ AS-BUILT / LIVE (2026-05-27j) — PM-verified read-only against the live DB

**All four sequencing steps below SHIPPED.** Build-state-of-record:
- **`ref_cip_types` seeded LIVE** (4 rows): `1 Soude` / `2 Acide` / `3 Full CIP` / `4 Full CIP + rinser` (sort 1-4, all active). Editable via the new Salle-de-contrôle CIP section (`36b72c5`, list-CRUD over ref_cip_types — mirrors the SKU-format list-CRUD pattern, NOT the yeast UPDATE-only handler, exactly as specced).
- **`bd_cip_events` is the SINGLE CIP store for ALL THREE forms.** Backfill (mig 177) populated **racking 679 + brewing 412 + packaging 0** (packaging never historically captured — free, as predicted). target_code spread LIVE: machine/unspecified 399 (generic racking `last_cip`, faithfully un-guessed), vessel/cct 367, vessel/bbt 280, vessel/yt 46. **All current rows `cip_started_at`/`cip_ended_at`=NULL** (backfill-only; no NEW live event written yet — consistent with "browser walkthrough still pending").
- **AS-BUILT per-form scope** (matches §PER-FORM DISTINCT CIP PROCESSES):
  - **Racking:** per-machine CIP (centri/kze/pump) + **centri+KZE inline-combine** (shared `inline_group`) + **destination-vessel CIP** with dynamic bbt/cct/yt label per `racking_destination_type` + **A3 start/end TIMEs** + Type CIP from ref_cip_types. **A2 LIVE: dest CIP optional when residual>0 (blend), required when residual≤0** — server-enforced before `cip_upsert`.
  - **Inline-combine COLLAPSE UX (refined 2026-05-27, committed `main`):** checking "centri+KZE simultané" **HIDES the individual centri + kze rows and shows ONE combined input block** (Type CIP / date / start-end times). On submit `cip_parse_post` reads ONLY the combined block and emits the TWO events (one centri, one kze) sharing a single `inline_group` — it no longer reads the now-hidden individual centri/kze fields. Unchecking restores the two separate rows. **Pump is ALWAYS independent** (never folded into the combine). Edit-mode (`cip_events_for` returns an inline group) re-renders as simultané-checked with the combined block populated. Required-on-active discipline preserved. (Files: `app/partials/cip-section.php` + `app/cip-events.php`.)
  - **Brewing:** CCT + YT vessel CIP (live-path writes `bd_cip_events`).
  - **Packaging — REVISED 2026-05-31 (mig 230 + 4 files, LIVE + COMMITTED `2851ae5`):** the packaging line's real CIP scope is **Soutireuse (`filler`) as ANCHOR + optional KZE inline-combined** — NOT centri/kze/pump + tank, and NOT a symmetric pair (KZE-alone is invalid in packaging). `cipConfig` now = `machines=['filler','kze']`, `combine_pair=['filler','kze']`, **`combine_anchor=>'filler'`**, `vessels=[]` (generic tank dropped), CIP OPTIONAL. Soutireuse renders standalone; KZE only as the "+ KZE combiné" addon; KZE-alone has no DOM path + is dropped server-side by `cip_parse_post`. **Live-path writes `bd_cip_events`; draft-path audit-logs ONLY** (unchanged). **Racking + brewing UNTOUCHED** by this change — racking contract byte-identical (no 3rd arg → default `['centri','kze']` pair).
- **All 3 forms STOPPED writing their flat CIP columns AND removed them from the parent `bd_*_v2` row-hash basis — PM-confirmed NO hash drift** (the HASH-DRIFT trap was avoided). Flat columns RETAINED for the legacy Sheets-ingest path → scrapping-backlog #14 stands (retire flat cols + rewire `tab_*.py` ingest).
- **Shared `app/cip-events.php` writer is atomic** (transaction/savepoint, nested-safe), notes round-trip, idempotent re-submit (tombstone-resync). **4 review-found bugs fixed BEFORE the forms consumed it:** hidden-required deadlock ×2, non-atomic tombstone, missing notes. (RULE-2 caught these — recorded as the writer-correctness baseline.)
- **Racking Round-2 also shipped** (commit `b269eee` + mig **179** `bd_racking_v2.yt_number` int NULL — PM-verified live): client input DROPPED → FK-resolved from `ref_recipes.client_id`→`ref_clients` (Nébuleuse when NULL); **YT N° dropdown** (from `ref_yt`) persisted to `yt_number`; "Volume résiduel en cuve" relabel (D3) + derived resultant volume (A1, DISPLAY-ONLY, not stored — the simulator keeps deriving its own); CO₂/O₂ + CIP labels swap by destination type; vitesse moyenne INPUT removed (avg_speed column kept); BBT-occupied lots added to hors-process (sim-filtered, +8); back-dating confirmed working (no min/max on event_date).

**AS-BUILT deltas from the design spec (none material):**
- Seed names: spec said distinct historical types `Full CIP`+`Acid`; seeded list is the full 4-type set (`Soude/Acide/Full CIP/Full CIP + rinser`) — superset, backfill maps `Acid`→Acide / `Full CIP`→Full CIP as planned.
- Mig 179 (`yt_number`) was NOT in the original 175/176/177 plan — it landed under the racking round-2 commit `b269eee` (D2 YT-number-persist, which was an open ops decision at design time, now resolved YES).

### PACKAGING CIP FIX — `filler` machine + config-driven combine-pair + ANCHOR model (2026-05-31, SHIPPED + LIVE + COMMITTED `2851ae5`, WORKING TREE CLEAN)
The packaging form's CIP scope was wrong (centri/kze/pump+tank, inherited from racking by copy). Real packaging-line CIP = **Soutireuse (`filler`, the ANCHOR) + optional KZE inline-combined** (the originally-recorded "filler+KZE pair" was refined to the anchored model below — KZE-alone is invalid in packaging). Fixed via mig 230 + 4 app files, all targeted-rsynced + LIVE on VPS; mig 230 APPLIED. **STATE: COMMITTED as `2851ae5` "feat(saisies-packaging): CIP = Soutireuse (anchor) + optional KZE combine" (4 files: db/migrations/230_cip_target_code_add_filler.sql + app/cip-events.php + app/partials/cip-section.php + public/modules/form-packaging.php, +616/−53); working tree CLEAN; repo↔prod back in SYNC. UNPUSHED (operator commit-don't-push pattern), chain also holds brewing `b1d20d8`.** PM-verified live: ENUM + CHECK both include `filler`; highest applied mig = 230 → **next free = 231** (re-check `--status`).
- **mig `230_cip_target_code_add_filler.sql`** = 3 statements: `DROP CONSTRAINT chk_cip_target` → `MODIFY COLUMN target_code` ENUM with `'filler'` **appended LAST** → `ADD CONSTRAINT chk_cip_target` widened so `target_kind='machine'` allows `filler`. Vessel branch unchanged. **The two-failed-applies migration lesson from this build is durable craft — recorded in the `sql` skill `migrations-and-deploy.md` pre-flight check #9** (no `ALGORITHM=INSTANT`/`LOCK` on ENUM MODIFY or ADD CHECK on this server — let MySQL pick COPY; multi-statement DDL is non-atomic so a mid-failure left the table with NO `chk_cip_target` until hand-restored).
- **`target_code` MACHINE ENUM is now `centri/kze/pump/unspecified/filler`** (supersedes the "exactly 3 machines" notes above + the original `bd_cip_events` DDL block). The "4th machine = one-line ALTER ENUM, no CRUD surface" rule held exactly — `filler` proves it. NB: the original DDL block higher in this file predates mig 230; trust THIS line for the live ENUM membership.
- **`cip_parse_post()` gained an optional 3rd param `?array $combinePair = ['centri','kze']`** — the combine-pair is now CONFIG-DRIVEN, not hardcoded centri+kze. Default preserves racking/brewing byte-for-byte (no 3rd arg). Packaging passes `['filler','kze']`. `app/partials/cip-section.php` inline-combine gate + inlineable-row check + label map are all pair-driven from `$cipConfig['combine_pair']`. **This generalizes the §DISPATCH "centri+kze" combine contract: any form's `combine_pair` now selects which two machines collapse — update the DISPATCH POST-field contract reading accordingly (the `cip_combined_*` block emits the configured pair, not literally centri+kze).**
- **REOPEN-DATA-LOSS BUG FIXED (latent):** `cip_events_for()` built its machine map from a hardcoded list, so saved events on any machine NOT in that list (e.g. `filler`) silently vanished on reopen. Now derived via `array_fill_keys(array_diff(CIP_MACHINE_CODES,['unspecified']), null)` → every commissioned machine re-displays on edit. `CIP_MACHINE_CODES` itself += `'filler'`.
- **French label = "Soutireuse"** (`'filler' => 'Soutireuse'` in cip-section.php label map). RESOLVED: operator renamed it from "Tireuse" mid-build to **Soutireuse** (no schema impact; ENUM code stays `filler`). Any earlier "Tireuse"/"Remplisseuse pending confirm" note is superseded.

#### REFINEMENT — KZE-alone is INVALID in packaging → `combine_anchor` config flag (PM verdict 2026-05-31, OPTION C; ✅ SHIPPED + LIVE in commit `2851ae5`)
Operator constraint: in PACKAGING the filler is **definitionally present** (you're packaging → the Tireuse is in use). The only valid CIP states are **Tireuse alone** OR **Tireuse + KZE** (inline-combined). **KZE-alone is invalid in packaging** (KZE-alone is a RACKING scenario — racking keeps KZE as an independent symmetric toggle, UNCHANGED). Today `machines=['filler','kze']` renders two PEER toggles in non-simultané mode → operator CAN tick KZE without Tireuse → invalid KZE-only packaging CIP. CIP itself stays OPTIONAL (no-CIP is fine); the constraint is specifically "no KZE without filler."
- **VERDICT = OPTION C (anchored pair), done CONFIG-DRIVEN — NOT option A (backend-drop-only: ships a UI that offers an invalid state it then silently discards = a UX refuse-don't-NULL violation) and NOT option B (peer toggles + JS-disable + separate guard = two enforcement points layered over a model that still says the machines are independent → exactly the render-says-X/parser-does-Y trap).** C removes the invalidity at the SOURCE: filler = anchor, KZE = its optional partner, KZE-alone becomes structurally unrepresentable in config + markup + parse. One source of truth.
- **CONFIG SHAPE — add ONE flag `combine_anchor` to the existing pair machinery** (the L136 generalization already made `combine_pair` config-driven; this is one notch on top, additive): packaging cipConfig = `machines=['filler','kze']`, `combine_pair=['filler','kze']`, **`combine_anchor=>'filler'`** (first elem = anchor; partner optional), `show_inline_combine=true`, `vessels=[]`, `cip_optional=true`. Semantics honored GENERICALLY by the shared partial: (a) anchor (`filler`) renders as a normal standalone machine row — independent, CIP-able alone (= "Tireuse alone" valid state); (b) partner (`kze`) is **NOT a peer toggle** — it renders ONLY as the "+ KZE inclus/combiné" addon attached to the anchor block, with NO independent row → no DOM path to "KZE on, filler off"; (c) the existing inline-combine collapse (shared `inline_group`) is reused VERBATIM when "+ KZE" is ticked.
- **RACKING STAYS BYTE-IDENTICAL:** `combine_anchor` ABSENT ⇒ today's symmetric behavior (centri+kze peer rows, either standalone, symmetric "simultané"). Asymmetry is opt-in per form, declared in cipConfig, honored generically — NO fork, NO `if($sourceForm==='packaging')` branch (that's the host fighting back → reject in review). Same pattern that already worked for `combine_pair`.
- **GUARD LIVES IN `cip_parse_post`, CONFIG-FED (the same `combine_anchor`/`combine_pair[0]`)** — NOT in the packaging POST handler (per-form guard = the divergence the module exists to kill, L9/L174). Predicate: if a PARTNER-machine event is emitted with NO anchor-machine event in the same submission → DROP the partner event + soft note (refuse-don't-NULL). The partial structurally prevents KZE-alone (primary); the parser is the enforcement-of-record (hand-crafted POST / stale tab / future reuse). SAME value shapes render AND parse = one source of truth.
- **ZERO MIGRATION** — `filler` already in ENUM + CHECK (mig 230); `combine_anchor` is a pure PHP config key. Pure UI + parser-validation. Next free mig stays **231**.
- **DIVERGENCE FLAGS for the build brief:** (1) must be generic `combine_anchor` honored from `$cipConfig`, no form-name branch; (2) RULE-2 HARD GATE = racking render+parse byte-identical when `combine_anchor` absent (diff a racking POST round-trip); (3) edit-mode reopen (`cip_events_for`, just fixed L137) must re-render a saved filler+KZE inline group as "Tireuse + KZE combiné" with partner-as-addon, NOT two peer rows — exercise the anchored reopen path; (4) one-fact-one-store intact (no new cols, still writes `bd_cip_events`). Skills **ui + coder** (NO sql — zero schema). ✅ SHIPPED in commit `2851ae5` exactly as spec'd: `combine_anchor=>'filler'` on packaging cipConfig; Soutireuse standalone + KZE inline addon; KZE-alone structurally impossible (no DOM path + `cip_parse_post` drops partner-without-anchor); racking/brewing byte-identical (no anchor arg); packaging + racking smoke both 302.

## BACKLOG / OPEN ITEMS (carry in memory)
1. **3 racking rows (ids 60/66/278, Diversion)** have `cip_bbt_done='Yes'` but NULL `racking_destination_type` — upstream data-quality gap, EXCLUDED from backfill (refuse-don't-guess: no destination type to assign the vessel event to). Candidate for a review-queue / data-quality pass — NOT a build bug.
2. **Browser walkthrough still PENDING** on all 3 forms. Everything verified server-side (php -l, write-tests with rollback, render-by-inspection, deploy 302). No logged-in UI click-through yet → all live `cip_started_at`/`cip_ended_at` are NULL (no real new event written). First operator pass is the remaining confidence step.
3. **Strain classification (Round 1 carryover):** only Zepp (recipe 57) is classified → the transfer time-gate is Zepp-only until operators assign strains via Recettes→Levure & garde. Orthogonal to CIP but rides the same Transferts form.
4. **CIP-first global rollout:** done for racking/brewing/packaging. If more operator forms exist or are built, the convention (CIP as the first section + the shared partial/writer) extends to them.
5. **Machine list is a FIXED ENUM** (centri/kze/pump/**filler** as of mig 230); `ref_cip_types` is the editable one. A new machine = one-line ALTER ENUM appending at the end (by design — no CRUD surface; `filler` proved the pattern 2026-05-31).
6. **Scrapping #14 (RULE-1, gated):** retire the flat CIP columns + rewire Sheets ingest (`tab_*.py`) to write `bd_cip_events` directly. Flat cols are now READ-DEAD but WRITE-LIVE for legacy ingest only — retire once ingest is rewired.

## SEQUENCING (cross-form build order) — ✅ ALL STEPS DONE (kept for the next-form template)
1. ✅ **Mig 175 `ref_cip_types`** (+seed +schema_meta) — gates every CIP-type dropdown + the `cip_type_id_fk` FK. FIRST.
2. ✅ **Mig 176 `bd_cip_events`** (+schema_meta) + `app/cip-events.php` writer + shared CIP UI partial. Depends on 175. Then ✅ **mig 177 backfill** (INSERT…SELECT from the 3 flat sources; times NULL).
3. ✅ **Form rewrites consume the writer** — racking + brewing + packaging each replaced flat-col POST capture with the shared CIP component → `cip_upsert`. Racking carried A1 (display) + A2 (validation) + the Round-2 edits + mig 179; brewing/packaging got CIP-convergence + CIP-first reorder.
4. ✅ **Salle-de-Contrôle `ref_cip_types` list-CRUD editor** (`36b72c5`).
5. ✅ **CIP-first reorder** — per-form markup, bundled into each form's CIP rewrite.

## DIVERGENCE FLAGS (enforce on every CIP build)
- **DUAL-WRITE trap:** flat cols are KEPT (legacy ingest target) but READ-DEAD; the WEB forms write `bd_cip_events` ONLY, never both — dual-writing from the web re-creates the parallel store you're killing.
- **HASH-DRIFT trap:** the web form must REMOVE the flat-CIP fields from its parent `bd_*_v2` hashCols (it no longer writes them); ingest keeps writing them on its own path/hash. A half-removal silently drifts the parent hash.
- **A1 stored-volume trap:** resulting volume MUST NOT be stored (duplicate of two stored facts the simulator derives independently).
- **Backfill faithfulness:** generic racking `last_cip` → `target_code='unspecified'`, never guessed to a real machine. Backfilled TIMEs → NULL, never invented.
- **MySQL-8:** CHECKs are NAMED (chk_cip_parent_xor / chk_cip_target) — schema-unique, table-prefixed; a CASCADE-FK column can't appear in a CHECK, so FKs are RESTRICT (correct anyway). No `IF NOT EXISTS` on ALTER; no bare SELECT in the migration.
- **Parent id:** child `*_id` must be the `bd_*_v2.id` RETURNED by `bd_upsert` — FK integrity, tombstone-aware (not CASCADE-delete an audited row).

## DISPATCH (for the NEXT CIP-on-a-form build — the module is LIVE, reuse it)
**The reusable contract is shipped. To add CIP to a new form, instantiate — do NOT re-model:**
- **UI:** render `app/partials/cip-section.php` with a `$cipConfig` array selecting which target_kinds/codes that form captures (machines centri/kze/pump and/or vessels cct/yt/bbt/tank). CSS = `public/css/cip-section.css`. Section goes FIRST in the form (CIP-first convention).
- **POST field-name contract** (what the partial submits + what `cip_parse_post` reads — additive, backward-compatible as of 2026-05-27):
  - **Inline-combine toggle:** `cip_inline_combine` (=1 when "centri+KZE simultané" is checked).
  - **When `cip_inline_combine=1`** (combined block): `cip_combined_type_id`, `cip_combined_date`, `cip_combined_start`, `cip_combined_end` — the parser reads ONLY these and emits the centri + kze events sharing one `inline_group`. The individual `cip_machine_centri_*` / `cip_machine_kze_*` fields are HIDDEN and NOT read.
  - **When inline is OFF** (`cip_inline_combine` unset/0): the individual `cip_machine_{centri,kze,pump}_*` fields are read as before (`*_type_id`, `*_date`, `*_start`, `*_end`).
  - **Pump is ALWAYS read from `cip_machine_pump_*`** regardless of the combine toggle (never folded into the combine).
  - **Reusable rule for future forms:** any form whose `$cipConfig['machines']` contains BOTH `centri` and `kze` AND sets `show_inline_combine=true` gets the collapse behavior AUTOMATICALLY from the partial — no per-form code. Edit-mode re-render (inline group present in `cip_events_for`) shows simultané-checked + combined block populated.
- **Writer:** call `cip_upsert($pdo, $sourceForm, $parentId, $events)` from `app/cip-events.php` in the POST handler AFTER `bd_upsert` returns the parent id. It is atomic (transaction/savepoint, nested-safe), idempotent (tombstone-resync), notes round-trips, and ENFORCES A3 start/end TIMEs on new events.
- **Schema:** add the new form's value to `bd_cip_events.source_form` ENUM + its typed `*_id` FK column (migration) and a branch in the XOR CHECK. Do NOT widen target_code without a real new vessel/machine.
- **Do NOT write flat CIP columns from the web** (DUAL-WRITE trap); REMOVE any flat-CIP field from the parent `bd_*_v2` hash basis (HASH-DRIFT trap).

Sonnet, equip **ui + sql + coder**. RULE-2 before every commit (XSS on new dropdowns/labels; A2 server predicate can't be bypassed by the client; dropped flat-CIP fields fully removed from POST coerce + parent row + parent hash; A3 TIMEs enforced server-side; the writer stays atomic on the new branch; no helper scripts left in repo). RULE-1: scrapping #14 (retire flat CIP columns + rewire Sheets ingest to bd_cip_events) + the CIP-first global rollout already logged.

---

## SIBLING INSTANCE OF THE SAME META-PATTERN — LOOKUP PANEL (config-driven shared partial + per-page read-only GET endpoint) — BUILT 2026-06-21, awaiting operator deploy go-ahead

> The CIP module is the FIRST instance of "one partial driven by per-form `$config`, zero DB queries of its own, caller supplies the data." The **Lookup Panel** is the SECOND, generalized to a read-only embeddable lookup. Same doctrine, now ALSO codified in the `ui` skill Layer-3 section ("the config-driven shared partial — the cross-page form of Layer 3" + "read-only GET JSON endpoints: gate, but no CSRF"). Build agents load it via that skill; this is the PM as-built record.

**Purpose:** read-only interactive lookup panels embedded in existing pages. Operator picks a date OR an identity (SKU+lot / recipe+lot) → live results via fetch to a per-page JSON endpoint. NON-FISCAL — pure read surface, feeds nothing.

**Files created (the reusable spine):**
- `public/modules/partials/lookup-panel.php` — shared partial, driven by `$lookupConfig` set before `require`; renders collapsible panel + two tabs (Par date / Par <identity>); does NO DB queries (caller provides the options).
- `public/js/lookup-panel.js` — IIFE; toggle + tabs + fetch + render for BOTH types; type from `data-type="packaging|brewing"` on the panel element; fetch target from `data-endpoint` on the panel element. One file serves every host.
- `public/css/lookup-panel.css` — dark aged-oak themed panel/tabs/table.
- `public/api/packaging-lookup.php` — GET, gate `require_page_access('packaging')`, params `mode=day&date=` OR `mode=batch&sku_id=&batch=`; reads `bd_packaging_v2` JOIN `v_bd_packaging_v2_vendable` + `ref_skus` + `ref_recipes` + `bd_packaging_readings` (CO2/O2).
- `public/api/brewing-lookup.php` — GET, gate `require_page_access('saisies')`, params `mode=day&date=` OR `mode=batch&recipe_id=&batch=`; reads `bd_brewing_brewday_v2` + `_timings_v2` + `_gravity_v2` + `_ingredients_v2` + `_ingredients_parsed_v2`.

**Files modified (the host wiring — the 4-line graft):** `public/modules/packaging.php` + `public/modules/form-brewing.php` — each: CSS `<link>` in `<head>`; options query + `$lookupConfig`; `require __DIR__.'/partials/lookup-panel.php'` immediately after `<main>` opens; `<script defer>` before `</body>`.

**Recipe to add a Lookup Panel to a NEW page:** (1) set `$lookupConfig` before the require (options from a PDO query the host owns); (2) `require __DIR__ . '/partials/lookup-panel.php'`; (3) link `/css/lookup-panel.css` + `<script defer>` `/js/lookup-panel.js`; (4) create `/api/<page>-lookup.php` with the appropriate `require_page_access('<gate>')`.

**Conventions CONFIRMED by this build (now durable in the `ui` skill):**
- Read-only GET endpoints: `require_page_access()` MANDATORY (data-leak gate), CSRF NOT needed (no state change to forge).
- Date validation: `checkdate()`, NOT `strtotime()` (strtotime accepts overflow dates → wrong-day lookup, no error).
- brewing timings/gravity join brewday on the NATURAL KEY `(beer, batch)` — there is NO `brewday_id` FK to join on (it doesn't exist). (Consistent with the standing "match on recipe_id_fk/(recipe,batch), never reconstruct a missing FK" discipline.)
- CO2/O2 source = `bd_packaging_readings` (cols `packaging_v2_id, reading_idx, o2, co2`).
- NULL `vendable_hl` renders as `—` in JS, NEVER `0` (0 = a real measured zero; — = not-yet-known).

**Status:** built, lint-clean, reviewer-approved; awaiting operator deploy go-ahead. 🔴 When deployed: this adds NO ref_pages row (panels embed in existing pages) → RULE 3 (tour) N/A. Deploy is per-file rsync of the 5 new + 2 modified files; `php8.1 -l` each PHP before fpm reload (standing rule). EQUIP ui+coder+sql(+webapp-testing smoke).
