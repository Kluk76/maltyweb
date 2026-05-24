-- db/migrations/103_bd_fermenting_v2_backfill.sql
-- What: Backfill + flag residuals for bd_fermenting_v2.
--   Step 1: recipe_id_fk name-match fallback from beer_raw → ref_recipes.name.
--           beer_raw often carries a batch suffix ("BLO 11") or a bare prefix ("EMB"),
--           so we match on the leading token before any digits. Defensive — most rows
--           already resolved from the source beer_recipe_id; only fills genuine NULLs.
--   Step 2: Flag the 4 blank-identity rows (empty beer_raw) with audit_flags so they
--           surface in the operator UI rather than sitting as silent NULLs.
--   Step 3: Flag the DryHop rows where MI stayed unresolved (lot number mis-parsed into
--           dh_raw_name) so the operator can correct the hop identity later.
-- Why:  Phase 1.C. The Python upload left recipe_id_fk / dh_mi_id_fk NULL for rows the
--       source itself could not resolve. Per the no-speculation rule we do NOT guess the
--       hop or beer — we make the gap visible.
-- Risk: Low. UPDATEs only touch NULL-FK rows. No deletes, no value overwrites.
-- Rollback: UPDATE bd_fermenting_v2 SET audit_flags=NULL WHERE audit_flags IN
--           ('no_beer_identity','mi_unresolved');
--           (recipe_id_fk name-match is not auto-reverted; re-NULL manually if needed)

-- ─── Step 1: recipe_id_fk name-match fallback ────────────────────────────────
-- Match the leading alpha token of beer_raw (strip trailing " NNN" batch suffix)
-- against ref_recipes.name. Exact, case-insensitive — no fuzzy matching.
-- NOTE: most fermentation beer_raw values are short codes ("BLO", "EMB") that won't
-- match ref_recipes.name directly; this step is a safety net and may be a no-op.

UPDATE bd_fermenting_v2 bf
JOIN ref_recipes rr
  ON LOWER(TRIM(rr.name)) = LOWER(TRIM(REGEXP_REPLACE(bf.beer_raw, '\\s*[0-9]+\\s*$', '')))
SET
  bf.recipe_id_fk = rr.id,
  bf.updated_at = NOW()
WHERE bf.recipe_id_fk IS NULL
  AND bf.beer_raw <> '';

-- ─── Step 2: flag blank-identity rows ────────────────────────────────────────

UPDATE bd_fermenting_v2
SET audit_flags = 'no_beer_identity',
    updated_at = NOW()
WHERE recipe_id_fk IS NULL
  AND (beer_raw = '' OR beer_raw IS NULL)
  AND audit_flags IS NULL;

-- ─── Step 3: flag unresolved DryHop MI rows ──────────────────────────────────

UPDATE bd_fermenting_v2
SET audit_flags = CONCAT_WS(',', audit_flags, 'mi_unresolved'),
    updated_at = NOW()
WHERE event_type = 'DryHop'
  AND dh_mi_id_fk IS NULL
  AND (audit_flags IS NULL OR audit_flags NOT LIKE '%mi_unresolved%');
