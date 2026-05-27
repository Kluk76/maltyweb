-- db/migrations/177_backfill_cip_events.sql
-- What: Backfill historical flat CIP columns from bd_racking_v2 and
--       bd_brewing_brewday_v2 into the normalised bd_cip_events table.
-- Why:  CIP module convergence Wave 1. Provides a complete normalised history so
--       the CIP dashboard can query a single table across all form types.
-- Risk: MEDIUM — multi-row INSERT…SELECT on live tables. All INSERTs use
--       INSERT IGNORE so re-running is a no-op (row_hash UNIQUE key guards
--       against duplicates). Flat columns are NOT dropped (they remain the
--       legacy Sheets-ingest write target for Wave 1).
-- Pre-check counts (expected at time of authoring, 2026-05-27):
--   Racking equipment CIP (last_cip_date or cip_type present): 399 rows
--   Racking destination CIP (cip_bbt_done = 'Yes'):            284 rows
--   Brewing CCT CIP (cct_cip IS NOT NULL OR cct_cip_date IS NOT NULL): 366 rows
--   Brewing YT CIP (yt_cip_date IS NOT NULL):                   46 rows
--   Packaging CIP: 0 rows (no populated cip_* rows in bd_packaging_v2 yet)
-- CIP type value mapping (only two distinct values in all flat columns):
--   'Full CIP' → ref_cip_types WHERE name = 'Full CIP'
--   'Acid'     → ref_cip_types WHERE name = 'Acide'
--   Any other value → cip_type_id_fk = NULL (reported at apply time)
-- Columns verified on bd_racking_v2:
--   last_cip_date, cip_type, cip_bbt_done, cip_bbt_type, cip_bbt_date,
--   racking_destination_type, bbt_number, cct_number (dest), id
-- Columns verified on bd_brewing_brewday_v2:
--   cct_cip (done-flag, VARCHAR 'Full CIP'/'Acid' or NULL), cct_cip_date,
--   yt_cip_date, cct (CCT number), yt_number (YT number), id
--   NOTE: No yt_cip done-flag column exists — backfill uses yt_cip_date IS NOT NULL
--         as the trigger. No YT CIP type column exists — cip_type_id_fk = NULL for all YT rows.
-- Rollback:
--   DELETE FROM bd_cip_events WHERE row_hash LIKE 'backfill-%';
--   (All backfill rows carry row_hash = SHA2(CONCAT('backfill|', ...), 256)
--    which is deterministic — re-running after rollback re-inserts them.)

-- ──────────────────────────────────────────────────────────────────────────────
-- 1. RACKING EQUIPMENT CIP
--    Source:  bd_racking_v2.last_cip_date and/or cip_type
--    Trigger: last_cip_date IS NOT NULL OR cip_type IS NOT NULL
--    Target:  source_form='racking', target_kind='machine', target_code='unspecified'
--             (historical data has no machine identity — centri/kze/pump not recorded)
-- ──────────────────────────────────────────────────────────────────────────────
INSERT IGNORE INTO `bd_cip_events`
  (`source_form`, `racking_id`, `target_kind`, `target_code`, `target_number`,
   `cip_type_id_fk`, `cip_date`,
   `cip_started_at`, `cip_ended_at`,
   `submitted_at`, `email`, `is_tombstoned`, `row_hash`)
SELECT
  'racking',
  r.`id`,
  'machine',
  'unspecified',
  NULL,
  (SELECT ct.`id` FROM `ref_cip_types` ct
    WHERE ct.`name` = CASE r.`cip_type`
      WHEN 'Full CIP' THEN 'Full CIP'
      WHEN 'Acid'     THEN 'Acide'
      ELSE NULL
    END
    LIMIT 1),
  r.`last_cip_date`,
  NULL,
  NULL,
  NULL,
  r.`email`,
  r.`is_tombstoned`,
  SHA2(CONCAT_WS('|',
    'backfill', 'racking', 'machine', 'unspecified', r.`id`,
    COALESCE(r.`last_cip_date`, ''),
    COALESCE(r.`cip_type`, ''),
    'equip'
  ), 256)
FROM `bd_racking_v2` r
WHERE r.`last_cip_date` IS NOT NULL OR r.`cip_type` IS NOT NULL;

-- ──────────────────────────────────────────────────────────────────────────────
-- 2. RACKING DESTINATION CIP (BBT/CCT/YT vessel CIP)
--    Source:  bd_racking_v2.cip_bbt_done = 'Yes'
--    Target:  source_form='racking', target_kind='vessel'
--             target_code derived from racking_destination_type:
--               BBT → 'bbt', CCT → 'cct', YT → 'yt'
--             target_number:
--               BBT → bbt_number, CCT → cct_number, YT → NULL (no YT dest number col)
-- ──────────────────────────────────────────────────────────────────────────────
INSERT IGNORE INTO `bd_cip_events`
  (`source_form`, `racking_id`, `target_kind`, `target_code`, `target_number`,
   `cip_type_id_fk`, `cip_date`,
   `cip_started_at`, `cip_ended_at`,
   `submitted_at`, `email`, `is_tombstoned`, `row_hash`)
SELECT
  'racking',
  r.`id`,
  'vessel',
  CASE r.`racking_destination_type`
    WHEN 'BBT' THEN 'bbt'
    WHEN 'CCT' THEN 'cct'
    WHEN 'YT'  THEN 'yt'
    ELSE 'unspecified'
  END,
  CASE r.`racking_destination_type`
    WHEN 'BBT' THEN r.`bbt_number`
    WHEN 'CCT' THEN r.`cct_number`
    ELSE NULL
  END,
  (SELECT ct.`id` FROM `ref_cip_types` ct
    WHERE ct.`name` = CASE r.`cip_bbt_type`
      WHEN 'Full CIP' THEN 'Full CIP'
      WHEN 'Acid'     THEN 'Acide'
      ELSE NULL
    END
    LIMIT 1),
  r.`cip_bbt_date`,
  NULL,
  NULL,
  NULL,
  r.`email`,
  r.`is_tombstoned`,
  SHA2(CONCAT_WS('|',
    'backfill', 'racking', 'vessel',
    COALESCE(r.`racking_destination_type`, 'unknown'),
    r.`id`,
    COALESCE(r.`cip_bbt_date`, ''),
    COALESCE(r.`cip_bbt_type`, ''),
    'dest'
  ), 256)
FROM `bd_racking_v2` r
WHERE r.`cip_bbt_done` = 'Yes';

-- ──────────────────────────────────────────────────────────────────────────────
-- 3. BREWING CCT CIP
--    Source:  bd_brewing_brewday_v2 WHERE cct_cip IS NOT NULL OR cct_cip_date IS NOT NULL
--             cct_cip is the done-flag (VARCHAR 'Full CIP'/'Acid' when set, else NULL)
--    Target:  source_form='brewing', target_kind='vessel', target_code='cct'
--             target_number = cct (the brew CCT number, INT UNSIGNED, may be NULL)
--             cip_type_id_fk mapped from cct_cip value (doubles as type column here)
-- ──────────────────────────────────────────────────────────────────────────────
INSERT IGNORE INTO `bd_cip_events`
  (`source_form`, `brewing_id`, `target_kind`, `target_code`, `target_number`,
   `cip_type_id_fk`, `cip_date`,
   `cip_started_at`, `cip_ended_at`,
   `submitted_at`, `email`, `is_tombstoned`, `row_hash`)
SELECT
  'brewing',
  b.`id`,
  'vessel',
  'cct',
  b.`cct`,
  (SELECT ct.`id` FROM `ref_cip_types` ct
    WHERE ct.`name` = CASE b.`cct_cip`
      WHEN 'Full CIP' THEN 'Full CIP'
      WHEN 'Acid'     THEN 'Acide'
      ELSE NULL
    END
    LIMIT 1),
  b.`cct_cip_date`,
  NULL,
  NULL,
  NULL,
  b.`email`,
  b.`is_tombstoned`,
  SHA2(CONCAT_WS('|',
    'backfill', 'brewing', 'vessel', 'cct',
    b.`id`,
    COALESCE(b.`cct_cip_date`, ''),
    COALESCE(b.`cct_cip`, '')
  ), 256)
FROM `bd_brewing_brewday_v2` b
WHERE b.`cct_cip` IS NOT NULL OR b.`cct_cip_date` IS NOT NULL;

-- ──────────────────────────────────────────────────────────────────────────────
-- 4. BREWING YT CIP
--    Source:  bd_brewing_brewday_v2 WHERE yt_cip_date IS NOT NULL
--             NOTE: No yt_cip done-flag column exists (ERRATA confirmed).
--                   Date presence is used as the trigger.
--             NOTE: No YT CIP type column exists — cip_type_id_fk = NULL for all YT rows.
--    Target:  source_form='brewing', target_kind='vessel', target_code='yt'
--             target_number = yt_number (INT UNSIGNED, may be NULL)
-- ──────────────────────────────────────────────────────────────────────────────
INSERT IGNORE INTO `bd_cip_events`
  (`source_form`, `brewing_id`, `target_kind`, `target_code`, `target_number`,
   `cip_type_id_fk`, `cip_date`,
   `cip_started_at`, `cip_ended_at`,
   `submitted_at`, `email`, `is_tombstoned`, `row_hash`)
SELECT
  'brewing',
  b.`id`,
  'vessel',
  'yt',
  b.`yt_number`,
  NULL,
  b.`yt_cip_date`,
  NULL,
  NULL,
  NULL,
  b.`email`,
  b.`is_tombstoned`,
  SHA2(CONCAT_WS('|',
    'backfill', 'brewing', 'vessel', 'yt',
    b.`id`,
    COALESCE(b.`yt_cip_date`, '')
  ), 256)
FROM `bd_brewing_brewday_v2` b
WHERE b.`yt_cip_date` IS NOT NULL;

-- NOTE: Packaging CIP (bd_packaging_v2.cip_tank_* and cip_machines_*) has 0 populated
-- rows as of 2026-05-27 — no backfill needed. Future Wave 2 ingest will write directly
-- to bd_cip_events.
