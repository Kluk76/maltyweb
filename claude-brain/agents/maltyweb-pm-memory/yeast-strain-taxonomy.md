# Yeast-strain taxonomy + Biochimie (SDC) — fully verified architecture

> Canonical store: `ref_yeast_strains` (strains) + `ref_yeast_strain_aliases` (resolution, FK→strains.id CASCADE) + `ref_yeast_family_defaults` (per-family garde/ferm-temp defaults, PK = family ENUM). Surfaced/edited via the SDC "Biochimie" card. Read when touching yeast strains, the Biochimie yeast section, recipe yeast linkage, the racking/fermenting garde-min gating, or yeast family taxonomy. **As-built state re-verified live VPS 2026-05-31 (post mig 228 + 229).**

## ⚠️⚠️ CRITICAL CORRECTION — family is an ENUM, NOT a lookup table. `ref_yeast_families` DOES NOT EXIST.
A previous PM consult (the "lookup-table session") signed off a DATA-DRIVEN LOOKUP-TABLE refactor of yeast families (`ref_yeast_families` id/code/label, FK `family_id_fk`, fold `ref_yeast_family_defaults` in). **THAT WAS NEVER BUILT.** Process slip: PM was consulted on a lookup-table design based on an ASSUMED operator answer that never came back. **The operator actually chose ENUM-EXTEND (the simpler path).** Verified live 2026-05-31: `ref_yeast_families` table count = 0; family remains an ENUM column. Do NOT advise as if a families lookup table exists; there is NO `family_id_fk` column; the garde-min JOINs still match on the family STRING (`fd.family = s.family`), they were NOT flipped to FK. Family taxonomy lives in lock-step in: the `family` ENUM on `ref_yeast_strains` AND `ref_yeast_family_defaults`, plus PHP consts `YEAST_FAMILIES` / `YEAST_FAMILY_LABELS` in `app/yeast-eligibility.php`.

## AS-BUILT — shipped + applied live + committed 2026-05-31

### Atom A — migration 228 (commit `051bea6`): Pinnacle merge
- `ref_yeast_strains` id 11 "Pinnacle NA" TOMBSTONED (`is_active=0`), merged into id 15 "Pinnacle Low Alcohol". (verified live)
- 3 aliases (apinnacle, Pinnacle, Pinnacle N/A) repointed strain_id 11→15; 'Pinnacle NA' added as 4th alias on 15. **id 15 now owns ALL 4 aliases; id 11 owns none.** (verified live: 15 ← Pinnacle N/A, apinnacle, Pinnacle, Pinnacle NA)
- 0 `ref_recipes` + 0 `bd_brewing_brewday`(v1) rows referenced id 11 → defensive repoints were no-ops. (`bd_brewing_brewday_v2` free-text 'Pinnacle'×14 + 'Pinnacle Cream'×3 are NOT FK'd, resolve via aliases — repointing all 4 to id 15 keeps them resolving.)
- Tombstone audited: `audit_row_revisions` action='update', target_pk=11, comment 'tombstone_mig228_pinnacle_na_id11'. (NOT hard DELETE — CASCADE would have wiped aliases.)

### Atom B+C — migration 229 (commit `8a765f4`): family ENUM-extend + Type-field removal
- family ENUM EXTENDED on BOTH `ref_yeast_strains` AND `ref_yeast_family_defaults` to: **`enum('ale','lager','non_alcool','spontane','mixte','priming','hybrid')`** (7 values). Append-only → INSTANT in MySQL 8. (verified live, both tables identical)
- 2 new `ref_yeast_family_defaults` rows seeded: `priming` (label 'Priming (refermentation)') + `hybrid` (label 'Hybride'), both `garde_days_min=NULL` (→ per-recipe fallback / no auto racking gate — never guessed a numeric default), `is_produced=0`, `is_active=1`. (verified live)
- No audit row for the 2 reference-bootstrap INSERTs — consistent with mig 167/219 convention for pure reference seeds.
- PHP (`app/yeast-eligibility.php`): `YEAST_FAMILIES` + `YEAST_FAMILY_LABELS` extended with priming+hybrid. Biochimie "Famille" dropdown now renders all 7.
- **SDC Biochimie "Type" dropdown REMOVED end-to-end:** table header, row cell, edit-dialog `<select>`, JS `setOpt`, and the `update_yeast_strain` handler's type read/validate/write/audit all stripped.

### Family ENUM defaults — live snapshot (verified 2026-05-31)
ale(garde 7, prod 1) · lager(garde 14, prod 1) · non_alcool(garde 7, prod 1) · spontane(NULL, prod 0) · mixte(NULL, prod 0) · priming(NULL, prod 0) · hybrid(NULL, prod 0). All is_active=1.

### Deprecated-but-present
- `ref_yeast_strains.type` column REMAINS in DB, deprecated, ALL rows='unknown', DEFAULT='unknown' (omitting it from UPDATEs preserves values). Biology axis, no logic consumer ever read it. Could be physically dropped in a future migration once operator confirms biology-type is never wanted — NOT done.

## How to add a family value (the lock-step, since it's an ENUM not a table)
`ALTER TABLE ref_yeast_strains MODIFY family ENUM(...,'newval')` (append = INSTANT) **+** same ALTER on `ref_yeast_family_defaults` **+** edit `YEAST_FAMILIES` + `YEAST_FAMILY_LABELS` (`app/yeast-eligibility.php`) **+** INSERT the `ref_yeast_family_defaults` row (garde_days_min — SURFACE to operator, never guess). Mig 229 is the worked example.

## Garde-min resolution chain (`app/yeast-eligibility.php` — do NOT break; STRING join, not FK)
- Precedence: recipe override → strain's family default → unresolved(NULL). NULL = NOT time-gate-eligible (refuse-don't-invent — never fabricates a numeric fallback).
- Chain: `ref_recipes.yeast_strain_id_fk → ref_yeast_strains.family → ref_yeast_family_defaults.garde_days_min`, COALESCE'd with `ref_recipes.garde_days_min_override`. Two JOIN sites (per-recipe resolver + set-based fragment), BOTH `LEFT JOIN ref_yeast_family_defaults ON .family = .family` (string). The racking gate (`form-racking.php` state-gated lot dropdown) + fermenting in-progress widget (`partials/fermenting-phase-in-progress.php`) both read these family defaults.

## FK fan-out into ref_yeast_strains = 3 FKs
`ref_recipes.yeast_strain_id_fk` (recipe→strain) · `bd_brewing_brewday.bd_yeast` (v1, FK'd) · `ref_yeast_strain_aliases.strain_id` (CASCADE). `bd_brewing_brewday_v2` yeast cols are free-text, NOT FK'd. **MIGRATION LESSON below was caught on the bd_brewing_brewday.bd_yeast guard.**

## ⚠️ Open scrapping candidate (flagged, NOT touched this session)
- `public/admin/settings/yeasts.php` — LEGACY admin CRUD, parallel writer to Biochimie; does a HARD `DELETE` of strains with NO tombstone / NO log_revision / NO audit. Violates refuse-don't-NULL + one-fact-one-writer. RETIREMENT CANDIDATE — retire once Biochimie covers create/delete AND nothing routes here. Until then it can silently destroy strain rows — keep it on the kill-list.

## Migration lesson — VARCHAR column vs INT literal (durable, generalizes)
**NEVER compare a VARCHAR column to an INTEGER literal in a maltyweb migration WHERE clause.** Mig 228 first FAILED with MySQL error 1292 "Truncated incorrect DOUBLE value: 'W34/70'" because the merge guard did `WHERE bd_yeast = 11` — `bd_brewing_brewday.bd_yeast` is VARCHAR, so MySQL cast EVERY row to DOUBLE to compare, and stored yeast code 'W34/70' is non-numeric → hard fail on the whole statement. Fix: quote the literal → `WHERE bd_yeast = '11'`. Steps 1-3 were idempotent and had already applied, so re-running after the fix completed cleanly. RULE: match the literal's type to the column's type; VARCHAR identity/code columns (bd_yeast and any free-text code col) get QUOTED literals. (Also worth folding into the `sql` skill craft.)
