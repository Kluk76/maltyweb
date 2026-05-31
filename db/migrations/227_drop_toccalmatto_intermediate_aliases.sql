-- db/migrations/227_drop_toccalmatto_intermediate_aliases.sql
--
-- What: Remove the 3 intermediate-name aliases that migration 226 added but the
--       operator did NOT want. Operator decision (2026-05-31): alias ONLY the
--       original short codes (TM-BLO, TM-IPA, TM-TR, TM-ST, NYL), NOT the
--       intermediate full names from the two-hop rename.
--
-- Removes (alias → recipe_id):
--   'Toccalmatto - Blonde' → 53
--   'Toccalmatto - IPA'    → 54
--   'Toccalmatto - Triple' → 56
--
-- Kept (the short codes the operator chose):
--   TM-BLO→53, TM-IPA→54, TM-TR→56, TM-ST→55, NYL→46, plus pre-existing
--   'NYL (Hard Seltzer)'→46 (migration 065).
--
-- Risk: LOW. If any bd_* rows were written during the brief window the recipes
--       were named "Toccalmatto - X" they will no longer resolve via alias —
--       operator accepted this trade-off (chose "only original codes").
--       Scoped DELETE by exact alias + recipe_id; idempotent (0 rows on re-run).
--
-- Date   : 2026-05-31
-- Author : migration_227

DELETE FROM ref_recipe_aliases
 WHERE recipe_id = 53 AND alias = 'Toccalmatto - Blonde';

DELETE FROM ref_recipe_aliases
 WHERE recipe_id = 54 AND alias = 'Toccalmatto - IPA';

DELETE FROM ref_recipe_aliases
 WHERE recipe_id = 56 AND alias = 'Toccalmatto - Triple';
