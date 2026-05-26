-- db/migrations/154_brewday_add_comments.sql
-- What: add a dedicated free-text comments column to bd_brewing_brewday_v2.
-- Why:  the brewing operator form needs a brew-day notes field. The only existing
--       text-note column is start_ferm ("Fermentation start note", varchar(255),
--       NULL in all 803 rows) — semantically a fermentation-start note, not
--       general brew-day comments. Overloading it conflates two concepts. Add a
--       proper comments column (durable modelling); leave start_ferm for its
--       documented purpose.
-- Risk: LOW — additive nullable column, no backfill, no FK.
-- Rollback: ALTER TABLE bd_brewing_brewday_v2 DROP COLUMN comments;

ALTER TABLE bd_brewing_brewday_v2
  ADD COLUMN comments VARCHAR(1000) NULL COMMENT 'Operator brew-day free-text notes (web form)';
