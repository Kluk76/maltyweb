-- 399_schema_meta_bd_v2_live_writer.sql
--
-- Scrap of the v1->v2 historical MIGRATION bridge (2026-06-19).
-- The bd_*_v2 source tables were originally POPULATED by the one-shot
-- ingest_bd_*_v2.py scripts reading data/RawDB-normalized.xlsx. That bridge
-- is dead: live saisie rows (audit_flags='web_entry') have been written
-- exclusively by the form-*.php handlers since the cutover (~2026-05-22).
-- The xlsx + scripts/normalize-rawdb.py have been removed; the ingest_bd_*_v2.py
-- scripts are KEPT-AS-HISTORICAL (hash-basis import target for mig236/263/265).
--
-- This migration corrects schema_meta.writer_script provenance to name the
-- LIVE writer (the form handler), so no future session mistakes the inert
-- ingest script for the current writer. UPDATE-only (no SELECT per migrate.php
-- rule); idempotent (re-run sets identical values).

UPDATE schema_meta
   SET writer_script = 'form-brewing.php'
 WHERE table_name IN (
   'bd_brewing_brewday_v2',
   'bd_brewing_gravity_v2',
   'bd_brewing_timings_v2',
   'bd_brewing_ingredients_v2'
 );

UPDATE schema_meta
   SET writer_script = 'form-fermenting.php'
 WHERE table_name = 'bd_fermenting_v2';

UPDATE schema_meta
   SET writer_script = 'form-packaging.php'
 WHERE table_name = 'bd_packaging_v2';

UPDATE schema_meta
   SET writer_script = 'form-racking.php'
 WHERE table_name = 'bd_racking_v2';
