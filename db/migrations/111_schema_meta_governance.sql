-- db/migrations/111_schema_meta_governance.sql
-- What: Fix stale / missing schema_meta.writer_script values so "who writes this
--       table?" has a correct answer for every v2 table and the legacy fermenting row.
-- Why:  DBA Phase-1-boundary N2 + governance hygiene. Verified against the actual
--       files in scripts/python/ on 2026-05-24:
--         - bd_packaging_v2          writer_script was NULL                       -> ingest_bd_packaging_v2.py
--         - bd_fermenting            writer_script was 'tab_fermenting.py (or similar)' (placeholder text!) -> tab_fermenting.py
--         - 4 brewing v2 source tables said 'upload_bd_brewing_v2.py' (file does not exist; the real uploader is ingest_bd_brewing_v2.py) -> ingest_bd_brewing_v2.py
--       (bd_brewing_ingredients_parsed_v2 already correctly names parse_bd_ingredients.py — left as-is.)
-- Risk: Zero — metadata only, no production table touched.
-- Rollback: re-UPDATE the prior values (NULL / placeholder / 'upload_bd_brewing_v2.py').

UPDATE schema_meta SET writer_script='ingest_bd_packaging_v2.py' WHERE table_name='bd_packaging_v2';
UPDATE schema_meta SET writer_script='tab_fermenting.py'         WHERE table_name='bd_fermenting';
UPDATE schema_meta SET writer_script='ingest_bd_brewing_v2.py'
 WHERE table_name IN ('bd_brewing_brewday_v2','bd_brewing_gravity_v2','bd_brewing_timings_v2','bd_brewing_ingredients_v2');

SET @migration_111_done = 1;
