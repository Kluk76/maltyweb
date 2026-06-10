-- Migration 310: Add 'recap' viz_type + two daily-recap KPI trackers
-- tracker_no 270 = wort daily recap (source_domain='wort')
-- tracker_no 271 = packaging daily recap (source_domain='packaging')
-- ENUM extension is INSTANT in MySQL 8 (additive, no rebuild).
-- No new table → no schema_meta row. No ref_pages INSERT (tiles on existing page).

-- Step 1: Extend viz_type ENUM with 'recap'
ALTER TABLE `ref_kpi_trackers`
  MODIFY COLUMN `viz_type` ENUM(
    'kpi_number',
    'sparkline',
    'bar',
    'stacked_bar',
    'line',
    'donut',
    'flag',
    'table',
    'waterfall',
    'recap'
  ) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'kpi_number';

-- Step 2: Insert wort daily recap tracker (tracker_no 270)
INSERT INTO `ref_kpi_trackers`
  (`tracker_no`, `slug`, `label`, `description`, `category`, `domain`,
   `source_domain`, `compute_handler`, `params_json`,
   `viz_type`, `readiness`, `default_cadence`, `is_hero`,
   `data_ready`, `min_role`, `is_active`, `sort`)
VALUES
  (270,
   'wort_daily_recap',
   'Brassage — résumé du jour',
   'HL brassés, brassins et transferts saisis aujourd\'hui',
   'production',
   'production',
   'wort',
   'daily_recap',
   NULL,
   'recap',
   'live',
   'daily',
   0,
   1,
   'operator',
   1,
   2620);

-- Step 3: Insert packaging daily recap tracker (tracker_no 271)
INSERT INTO `ref_kpi_trackers`
  (`tracker_no`, `slug`, `label`, `description`, `category`, `domain`,
   `source_domain`, `compute_handler`, `params_json`,
   `viz_type`, `readiness`, `default_cadence`, `is_hero`,
   `data_ready`, `min_role`, `is_active`, `sort`)
VALUES
  (271,
   'packaging_daily_recap',
   'Mise en bouteille — résumé du jour',
   'Runs, HL vendables et pertes matières saisis aujourd\'hui',
   'packaging',
   'production',
   'packaging',
   'daily_recap',
   NULL,
   'recap',
   'live',
   'daily',
   0,
   1,
   'operator',
   1,
   2580);
