-- Migration 276: Add 'whirlpool' to ref_recipe_ingredients.hop_addition_stage ENUM
--
-- Whirlpool = hops added at flameout WITHOUT cooling (infuses near 100°C).
-- Distinct from hop_stand = cooled to 80°C first.
--
-- 'whirlpool' is APPENDED LAST to preserve existing index positions of all
-- current ENUM values (MySQL stores ENUMs by index; reordering breaks existing data).
--
-- INSTANT DDL on MySQL 8.0 — no table rebuild for ENUM append-at-end.
--
-- Applied via: ssh maltyweb 'sudo php /var/www/maltytask/scripts/migrate.php'

ALTER TABLE ref_recipe_ingredients
  MODIFY hop_addition_stage
    ENUM('mash','first_wort','boil','hop_stand','dry_hop','whirlpool')
    NULL DEFAULT NULL
    COMMENT 'hop_stand=cooled to 80°C; whirlpool=flameout no-chill ~100°C; boil=requires hop_boil_time_min';
