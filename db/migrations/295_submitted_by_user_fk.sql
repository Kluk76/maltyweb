-- db/migrations/295_submitted_by_user_fk.sql
-- What: Add submitted_by_user_id_fk (INT UNSIGNED NULL) + FK to users(id) on all bd_*_v2 event tables
-- Why: Operator attribution was carried only by a free-text email column, re-stamped on every edit.
--      Introduce a durable set-once FK so the original submitter is preserved even after corrections.
--      The email column is KEPT as a frozen legacy fallback and is NOT dropped.
-- Risk: LOW. ADD COLUMN NULL with ALGORITHM=INSTANT + FK RESTRICT. No data moved.
-- Rollback:
--   ALTER TABLE bd_brewing_brewday_v2    DROP FOREIGN KEY fk_bd_brewing_brewday_v2_submitted_by,    DROP COLUMN submitted_by_user_id_fk;
--   ALTER TABLE bd_brewing_gravity_v2    DROP FOREIGN KEY fk_bd_brewing_gravity_v2_submitted_by,    DROP COLUMN submitted_by_user_id_fk;
--   ALTER TABLE bd_brewing_ingredients_v2 DROP FOREIGN KEY fk_bd_brewing_ingredients_v2_submitted_by, DROP COLUMN submitted_by_user_id_fk;
--   ALTER TABLE bd_brewing_timings_v2    DROP FOREIGN KEY fk_bd_brewing_timings_v2_submitted_by,    DROP COLUMN submitted_by_user_id_fk;
--   ALTER TABLE bd_fermenting_v2         DROP FOREIGN KEY fk_bd_fermenting_v2_submitted_by,         DROP COLUMN submitted_by_user_id_fk;
--   ALTER TABLE bd_packaging_v2          DROP FOREIGN KEY fk_bd_packaging_v2_submitted_by,          DROP COLUMN submitted_by_user_id_fk;
--   ALTER TABLE bd_racking_v2            DROP FOREIGN KEY fk_bd_racking_v2_submitted_by,            DROP COLUMN submitted_by_user_id_fk;

ALTER TABLE bd_brewing_brewday_v2
  ADD COLUMN submitted_by_user_id_fk INT UNSIGNED NULL
    COMMENT 'FK to users.id (INT UNSIGNED). NULL = legacy row pre-backfill. Set once at creation; never re-stamped on edits.',
  ALGORITHM=INSTANT;

ALTER TABLE bd_brewing_brewday_v2
  ADD CONSTRAINT fk_bd_brewing_brewday_v2_submitted_by
    FOREIGN KEY (submitted_by_user_id_fk) REFERENCES users(id) ON DELETE RESTRICT;

ALTER TABLE bd_brewing_gravity_v2
  ADD COLUMN submitted_by_user_id_fk INT UNSIGNED NULL
    COMMENT 'FK to users.id (INT UNSIGNED). NULL = legacy row pre-backfill. Set once at creation; never re-stamped on edits.',
  ALGORITHM=INSTANT;

ALTER TABLE bd_brewing_gravity_v2
  ADD CONSTRAINT fk_bd_brewing_gravity_v2_submitted_by
    FOREIGN KEY (submitted_by_user_id_fk) REFERENCES users(id) ON DELETE RESTRICT;

ALTER TABLE bd_brewing_ingredients_v2
  ADD COLUMN submitted_by_user_id_fk INT UNSIGNED NULL
    COMMENT 'FK to users.id (INT UNSIGNED). NULL = legacy row pre-backfill. Set once at creation; never re-stamped on edits.',
  ALGORITHM=INSTANT;

ALTER TABLE bd_brewing_ingredients_v2
  ADD CONSTRAINT fk_bd_brewing_ingredients_v2_submitted_by
    FOREIGN KEY (submitted_by_user_id_fk) REFERENCES users(id) ON DELETE RESTRICT;

ALTER TABLE bd_brewing_timings_v2
  ADD COLUMN submitted_by_user_id_fk INT UNSIGNED NULL
    COMMENT 'FK to users.id (INT UNSIGNED). NULL = legacy row pre-backfill. Set once at creation; never re-stamped on edits.',
  ALGORITHM=INSTANT;

ALTER TABLE bd_brewing_timings_v2
  ADD CONSTRAINT fk_bd_brewing_timings_v2_submitted_by
    FOREIGN KEY (submitted_by_user_id_fk) REFERENCES users(id) ON DELETE RESTRICT;

ALTER TABLE bd_fermenting_v2
  ADD COLUMN submitted_by_user_id_fk INT UNSIGNED NULL
    COMMENT 'FK to users.id (INT UNSIGNED). NULL = legacy row pre-backfill. Set once at creation; never re-stamped on edits.',
  ALGORITHM=INSTANT;

ALTER TABLE bd_fermenting_v2
  ADD CONSTRAINT fk_bd_fermenting_v2_submitted_by
    FOREIGN KEY (submitted_by_user_id_fk) REFERENCES users(id) ON DELETE RESTRICT;

ALTER TABLE bd_packaging_v2
  ADD COLUMN submitted_by_user_id_fk INT UNSIGNED NULL
    COMMENT 'FK to users.id (INT UNSIGNED). NULL = legacy row pre-backfill. Set once at creation; never re-stamped on edits.',
  ALGORITHM=INSTANT;

ALTER TABLE bd_packaging_v2
  ADD CONSTRAINT fk_bd_packaging_v2_submitted_by
    FOREIGN KEY (submitted_by_user_id_fk) REFERENCES users(id) ON DELETE RESTRICT;

ALTER TABLE bd_racking_v2
  ADD COLUMN submitted_by_user_id_fk INT UNSIGNED NULL
    COMMENT 'FK to users.id (INT UNSIGNED). NULL = legacy row pre-backfill. Set once at creation; never re-stamped on edits.',
  ALGORITHM=INSTANT;

ALTER TABLE bd_racking_v2
  ADD CONSTRAINT fk_bd_racking_v2_submitted_by
    FOREIGN KEY (submitted_by_user_id_fk) REFERENCES users(id) ON DELETE RESTRICT;
