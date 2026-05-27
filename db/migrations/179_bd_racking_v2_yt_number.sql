-- db/migrations/179_bd_racking_v2_yt_number.sql
-- What: Add yt_number column to bd_racking_v2 to persist YT destination tank number.
-- Why:  The form now exposes a YT dropdown (Point #4). racking_destination_type already
--       supports 'YT' but there was no column to store which YT number was chosen.
--       Keeps parity with bbt_number / cct_number.
-- Risk: LOW — ADD COLUMN nullable, no data transform, INSTANT DDL.
-- Rollback: ALTER TABLE bd_racking_v2 DROP COLUMN yt_number;

ALTER TABLE bd_racking_v2
  ADD COLUMN yt_number INT UNSIGNED NULL
    COMMENT 'YT destination tank number (ref_yt.number); set when racking_destination_type=YT'
  AFTER cct_number;
