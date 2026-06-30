-- 449_retire_v1_bd_tables.sql
-- Quarantine-rename 9 frozen v1 bd_* tables (zero writes since 2026-06-08) + drop vestigial FK.
-- Reversible: RENAME back to restore. Permanent DROP = Phase 4 after ~1 week soak.
-- Backups: data/snapshots/v1-decom-20260630T052911/
SET lock_wait_timeout = 10;

ALTER TABLE bd_packaging_readings DROP FOREIGN KEY fk_readings_packaging;

RENAME TABLE
  bd_brewing_brewday            TO z_retired_bd_brewing_brewday_20260630,
  bd_brewing_cooling            TO z_retired_bd_brewing_cooling_20260630,
  bd_brewing_gravity            TO z_retired_bd_brewing_gravity_20260630,
  bd_brewing_ingredients        TO z_retired_bd_brewing_ingredients_20260630,
  bd_brewing_ingredients_parsed TO z_retired_bd_brewing_ingredients_parsed_20260630,
  bd_brewing_timings            TO z_retired_bd_brewing_timings_20260630,
  bd_fermenting                 TO z_retired_bd_fermenting_20260630,
  bd_packaging                  TO z_retired_bd_packaging_20260630,
  bd_racking                    TO z_retired_bd_racking_20260630;

UPDATE schema_meta
   SET table_name = CONCAT('z_retired_', table_name, '_20260630'),
       corrections_policy = 'blocked'
 WHERE table_name IN ('bd_brewing_brewday','bd_brewing_cooling','bd_brewing_gravity',
   'bd_brewing_ingredients','bd_brewing_ingredients_parsed','bd_brewing_timings',
   'bd_fermenting','bd_packaging','bd_racking');
