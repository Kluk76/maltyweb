-- db/migrations/226_contract_rename_aliases.sql
--
-- What: Seed ref_recipe_aliases with 9 aliases covering 5 contract recipes
--       that were renamed (Toccalmatto → Brasserie 28; Nylo → canonical "Nylo").
--
-- Why:  Operator renamed recipes (ids 46, 53, 54, 55, 56) to their canonical
--       form. Historical bd_* rows (bd_brewing_v2, bd_fermenting_v2, bd_racking_v2,
--       bd_packaging_v2) still hold the OLD names (TM-BLO, TM-IPA, TM-TR, TM-ST,
--       NYL) and the intermediate full forms ("Toccalmatto - Blonde", etc.).
--       Without these aliases, resolve_recipe_id() / load_recipe_ingredients_for_batch()
--       silently drop those historical brews from gap-fill and cost derivation.
--
-- Aliases added (recipe_id → alias):
--   53 → TM-BLO              (short code for old "Toccalmatto - Blonde")
--   53 → Toccalmatto - Blonde (full intermediate name)
--   54 → TM-IPA              (short code for old "Toccalmatto - IPA")
--   54 → Toccalmatto - IPA   (full intermediate name)
--   56 → TM-TR               (short code for old "Toccalmatto - Triple")
--   56 → Toccalmatto - Triple (full intermediate name)
--   55 → TM-ST               (short code for old "Toccalmatto - Stria"; name unchanged)
--   46 → NYL                 (short code for old "Nylo" form; canonical is now plain "Nylo")
--   46 → NYL (Hard Seltzer)  — ALREADY EXISTS (id=22, migration 065); IGNORED by INSERT IGNORE
--
-- Column set: matches actual ref_recipe_aliases schema
--   (id, alias, recipe_id, notes, created_at).
--   INSERT IGNORE makes this idempotent via the UNIQUE KEY uniq_alias on alias.
--
-- Canonical names verified 2026-05-31 (ssh read-only query):
--   id 46  → "Nylo"
--   id 53  → "Brasserie 28 - Blonde"
--   id 54  → "Brasserie 28 - IPA"
--   id 55  → "Toccalmatto - Stria"
--   id 56  → "Brasserie 28 - Triple"
--
-- Pre-existing alias check 2026-05-31: none of the 9 aliases exist yet.
--
-- Risk: LOW. Pure INSERTs into ref_recipe_aliases; no FK redirects; no deletes.
--       INSERT IGNORE means re-run is a no-op.
--
-- Date   : 2026-05-31
-- Author : migration_226

INSERT IGNORE INTO ref_recipe_aliases (alias, recipe_id, notes)
VALUES
  -- id 53 → Brasserie 28 - Blonde
  ('TM-BLO',               53, 'rename 2026-05: was short code TM-BLO for Toccalmatto - Blonde, now Brasserie 28 - Blonde (id=53); migration_226'),
  ('Toccalmatto - Blonde',  53, 'rename 2026-05: full pre-rename name for Brasserie 28 - Blonde (id=53); migration_226'),

  -- id 54 → Brasserie 28 - IPA
  ('TM-IPA',               54, 'rename 2026-05: was short code TM-IPA for Toccalmatto - IPA, now Brasserie 28 - IPA (id=54); migration_226'),
  ('Toccalmatto - IPA',    54, 'rename 2026-05: full pre-rename name for Brasserie 28 - IPA (id=54); migration_226'),

  -- id 56 → Brasserie 28 - Triple
  ('TM-TR',                56, 'rename 2026-05: was short code TM-TR for Toccalmatto - Triple, now Brasserie 28 - Triple (id=56); migration_226'),
  ('Toccalmatto - Triple', 56, 'rename 2026-05: full pre-rename name for Brasserie 28 - Triple (id=56); migration_226'),

  -- id 55 → Toccalmatto - Stria (name unchanged; short code is the new alias)
  ('TM-ST',                55, 'rename 2026-05: short code alias for Toccalmatto - Stria (id=55); migration_226'),

  -- id 46 → Nylo (short code alias for the plain canonical name)
  ('NYL',                  46, 'rename 2026-05: short code alias for Nylo (id=46); migration_226');

  -- Note: 'NYL (Hard Seltzer)' (id=22) already exists from migration 065 for the same
  -- recipe_id=46 and is intentionally excluded from this INSERT (INSERT IGNORE would
  -- skip it anyway via the UNIQUE KEY on alias). Operator confirmed both aliases needed;
  -- the parenthetical form is already in place.
