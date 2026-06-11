# Operator attribution stability + global event-recap page — SHIPPED 2026-06-09

> Load when touching: operator/submitter attribution on ANY saisie form (racking/fermenting/packaging/brewing), the `bd_*_v2.email` column or the new `submitted_by_user_id_fk`, the "saisies récentes" recent-submissions block, the `?edit=<id>` Corriger path, the new **Journal des saisies** page / `v_saisie_events` view / journal-feed/journal-detail APIs, OR any future generic recette/qty editor that writes a bd_*_v2 row. Two asks from Kouros, one root cause + one new surface — BOTH SHIPPED + LIVE 2026-06-09. Schema facts verified live.

## STATUS — ✅ BOTH ASKS SHIPPED + LIVE 2026-06-09
Plan (below, §ARCHITECTURE VERDICT) was executed by the build session. Landed per the durable model (A), not just the stopgap (B). Two maltyweb commits on `main`, **deployed live to VPS via deploy.sh but NOT pushed to origin** — left for Kouros to review/push 2026-06-10:
- **`c8e895c`** fix(saisie): preserve original operator on edit + durable submitted_by FK (ASK 1 — mig 295 + write-path wiring + backfill + bug-row correction)
- **`5b918f3`** feat(journal): live "Journal des saisies" event-recap page with audit drill-down (ASK 2 — mig 298 + page + 2 APIs + JS/CSS)

## ASK 1 — operator attribution (SHIPPED, durable model A)
- **Mig `295_submitted_by_user_fk.sql` applied.** Pre-flight found live head was **294** (NOT the 292 the plan guessed — parallel sessions had moved it; 293=loss-model, 294=ord_orders_source_ref). `users.id = INT UNSIGNED`.
- **The `email` col is on 7 bd_*_v2 tables, NOT 4** — brewing is split into 4 sub-tables. The full set carrying attribution: `bd_racking_v2`, `bd_fermenting_v2`, `bd_packaging_v2`, `bd_brewing_brewday_v2`, `bd_brewing_gravity_v2`, `bd_brewing_ingredients_v2`, `bd_brewing_timings_v2`. (The plan's "4 forms / ~13 sites" undercounted the tables — the FORMS are 4 but the TABLES are 7.) **CORRECTS plan §VERIFIED GROUND TRUTH which named only 4 tables.**
- Added nullable **`submitted_by_user_id_fk INT UNSIGNED → users(id) ON DELETE RESTRICT`** to all 7 (`ALGORITHM=INSTANT`). All 7 already have schema_meta rows (table_class=source, corrections_policy=allowed) — untouched (additive column, no new table → no schema_meta change needed).
- **Write-path wiring (set-once, never on edit):**
  - INSERT path stamps `submitted_by_user_id_fk = (int)$me['id']`.
  - EDIT path restores `$row['email'] = $beforeSnapshot['email']` (the original submitter, from the pre-image) AND **omits the FK from `$row`** so `bd_upsert`'s ON DUPLICATE KEY UPDATE leaves it untouched. Confirmed `bd_upsert` only updates keys present in `$row` — so omitting the FK = structural set-once.
  - Done across `form-racking.php`, `form-brewing.php` (5 row builders), `form-packaging.php`, `api/fermenting-phase-submit.php`.
  - Recent/historical display blocks now show **`COALESCE(NULLIF(u.display_name,''), email)`** (short floor name, with `email` legacy fallback) via LEFT JOIN `users`. Matches the plan's "display display_name, fall back to email" ruling and respects the username=full-name / display_name=short-name inversion.
- **Bug row corrected:** `bd_racking_v2 id=434` (Speakeasy/66) → `email='Gonzalo'`, `submitted_by_user_id_fk=2` (users.display_name='Gonzalo', username='Gonzalo Saporito'), logged as `audit_row_revisions` rev#13003 with a correction comment. The audit tail for 434 now reads: insert by Gonzalo (12:01) → update by Kouros (14:33, the clobbering edit) → update by Kouros (14:51, the correction). **Corrected from Kouros's explicit statement (Gonzalo submitted it), NOT inferred — refuse-don't-guess honored.**
- **Backfill — unambiguous-only, no guesses, idempotent (re-run = 0 changes).** Per-table FK-set / FK-NULL tallies (NULL = email-NULL legacy imports + 5 ex-employee strings with no `users` row, kept as `email` fallback):
  - racking 379 / 27
  - packaging 962 / 1306
  - fermenting 2723 / 4006
  - brewing_brewday 357 / 473
  - brewing_gravity 3707 / 4996
  - brewing_ingredients 350 / 333
  - brewing_timings 1017 / 1250
  - **Unmatched ex-employee email strings** (no `users` row → FK stays NULL, `email` kept as frozen legacy fallback): `fernando@`, `nicolas@`, `sandro@`, `ruben@`, `josh@lanebuleuse.ch`. These are the legitimate semantic-NULLs (departed staff) — not a backfill gap to chase.

## ASK 2 — Journal des saisies (SHIPPED)
- **Mig `298_journal_saisies.sql`** created VIEW **`v_saisie_events`** — UNION ALL over the 7 bd_*_v2 tables, common projection: `source_table, row_pk, form_type` (human label), `event_date, submitted_at, submitted_by_user_id_fk, operator_email, label`. **21,887 rows** at ship. Added `submitted_at` indexes on the 4 brewing tables (the other 3 already had them).
- **ref_pages row:** `('journal-saisies','Journal des saisies','◎','/modules/journal-saisies.php','viewer','general',1, sort=15)` — sits directly below sb-board "Lots en cours" (sort=10), above sb-guerre (sort=20). Exactly the §DERIVATION-TREE-PLACEMENT slot the plan specified (general / sort=15 / min_role=viewer). **4 access-preset mappings added** (manager, production_operator, logistics_operator, marketing).
- **Files:**
  - `public/modules/journal-saisies.php` — shell + `window.JOURNAL_DATA` hydration.
  - `public/api/journal-feed.php` — `since`/`before` pagination; operator resolved via `users` JOIN.
  - `public/api/journal-detail.php` — current row + `audit_row_revisions` timeline with per-field before/after diffs; **`table` param whitelisted to the 7 table names** (injection guard).
  - `public/js/journal-saisies.js` — **20s live auto-refresh**, paused when tab hidden, prepend-with-pulse, load-more, `<dialog>` drill-down.
  - `public/css/journal-saisies.css` — dark aged-oak theme.
- **CADENCE DECISION CHANGED from the plan.** Plan §ASK-2 said "poll-on-load (no auto-refresh) … promote to poll only if Kouros says". Kouros explicitly wanted **LIVE/dynamic visibility as the default** → the feed **auto-refreshes every 20s** (paused on hidden tab), NOT poll-on-load. Durable: this page is the operator's live event-recap; live refresh is the intended posture. **CORRECTS plan §ASK-2 READ-CADENCE.**
- Compute-on-read VIEW over the event layer — NO parallel store, NO cache table (plan §divergence-flag honored). Drill-down reads the live event row + its `audit_row_revisions` tail filtered `target_table/target_pk` — exactly the "input + its modifications" split, with the original submitter as operator and the audit log as the modification trail.

## MIGRATION STATE (live-verified 2026-06-09)
- Files present on disk: 290–299. **APPLIED on VPS: through 298.** 295 (submitted_by FK) + 298 (journal) = this arc's two migs.
- **299 `299_fulfilment_site_attribution.sql` was created later and is NOT applied** (separate fulfilment work).
- **SIDE-EFFECT (flagged): the deploy.sh --apply + migrate.php from this session's working tree applied parallel-session migs 296 + 297 (BBT-vide arc: `296_bd_racking_v2_bbt_vide_scrapped_hl`, `297_pertes_bbt_empty_threshold`) to the live DB** (both additive). Kouros's BBT-vide PHP/CSS/JS working-tree edits (uncommitted) were ALSO deployed live as a side effect. This is the canonical "deploy pushes the working tree, not HEAD" hazard (index BUILD-LAUNCH-CHECKLIST §line 14) materializing again across parallel sessions — record it, Kouros is aware.
- **`form-racking.php` was committed SURGICALLY** — only the 3 operator hunks from this session; Kouros's in-progress BBT-vide hunks in the same file were left UNSTAGED. Good discipline (mirrors the earlier surgical-commit pattern when two sessions share a file).

## CORRECTIONS TO THE PLAN (for future-me)
- **7 tables, not 4** — brewing is 4 sub-tables (brewday/gravity/ingredients/timings). Any future "touch all attribution tables" work = 7, not 4.
- **Mig numbers** — head was 294 at build (not 292); this arc consumed 295 + 298; 296/297 = parallel BBT-vide; 299 = parallel fulfilment.
- **Cadence** — Journal is 20s live auto-refresh, NOT poll-on-load (operator override).
- Durable model (A) shipped directly — the stopgap (B) was folded in (edit path preserves `email`) AND the FK added in the same commit; no two-step.

## OPEN / RESIDUAL FOR KOUROS'S 2026-06-10 REVIEW
1. **Brewing `ingHeaderRow` always inserts** (its `submitted_at` is part of the NK) — verify the edit-mode FK behavior there is acceptable (does an edit create a new header row with a fresh FK rather than preserving the original?). Likely benign but eyeball it.
2. **Feed granularity includes brewing gravity (~9k rows, noisy)** — BY DESIGN (granular per-input feed), paginated. If Kouros finds it too noisy, a form_type filter / gravity-collapse is the lever — not a structural change.
3. **Authenticated VISUAL smoke NOT done** — the build session lacked login creds, so endpoints were verified at the data layer + 200/302 only. Feed render + drill-down screenshots were NOT captured. **Kouros should eyeball the live page + click a row 2026-06-10.** (A permanent smoketest account exists — `secrets/maltyweb-smoketest.env`, user id=16 — so a future session CAN do the authenticated webapp-testing smoke; the build session simply didn't have it wired. Flag for next pass.)

## DERIVATION-TREE PLACEMENT (unchanged — confirmed correct at ship)
- Operator attribution is an **event-row fact** owned by each `bd_*_v2` table. COGS-NEUTRAL + SOT-safe — nothing in commissioning→format→SKU→BOM→COGS reads `email`/the FK. The FK adds a clean derivation (operator identity → `users` by FK) where there was a string-copy; it does not touch any cost fact.
- The Journal page is a pure compute-on-read VIEW over the event layer (same posture as the Stock PF board + sessions Journal de bord): no parallel store, no derived-state column.

## GOTCHAS / CONVENTIONS (still load-bearing)
- **`users.username` = FULL name, `users.display_name` = SHORT floor name** — the inversion. Recent block + journal feed display `display_name`, fall back to `email`. Resolve carefully in any new consumer.
- **`submitted_by_user_id_fk` is set-once at INSERT, never on edit.** Pattern = INSERT stamps `(int)$me['id']`; EDIT omits the FK from the `$row` passed to `bd_upsert` (which only updates keys present) AND restores `$row['email']` from the pre-image. Any FUTURE generic recette/qty editor that writes a bd_*_v2 row MUST follow this — do not re-stamp the editor's identity.
- `email` is KEPT as a frozen legacy display-fallback (NOT NULL on racking + fermenting; the new FK is NULLABLE precisely so legacy/ex-employee rows are non-breaking). One fact, one owner: FK is canonical, `email` is fallback — not a competing store.
- `audit_row_revisions.action` ENUM = ('insert','update') only; the modification feed reads both; tombstone convention for deletes.
- The 4-form edit path already preserves `submitted_at` (NK) — the FK/email preserve mirrors that exact edit-mode discipline (see saisie-forms-5-correction-arc.md §SAISIE EDIT-MODE CORRECTION PATTERN).
