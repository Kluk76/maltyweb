-- =============================================================================
-- Migration 385: smoke_viewer access preset for dedicated smoke-test account
-- =============================================================================
-- Purpose: Make users.id=16 (username='smoketest', role='viewer') usable for
--   read-only automated smoke tests by:
--     1. Creating a 'smoke_viewer' access preset (INSERT IGNORE — idempotent)
--     2. Granting it READ access to the 12 verified ref_pages entries
--     3. Wiring users.id=16 to the preset via subquery (no hardcoded literal)
--     4. Seeding 8 diverse KPI trackers into user_kpi_selections for user 16
--
-- pages requested but NOT found in ref_pages (dropped):
--   salle-de-controle — does not exist as a page_key in ref_pages
--
-- KPIs seeded (ref_kpi_trackers.id, data_ready=1):
--   1  hl_brewed_month       kpi_number   wort
--   86 units_sold_sku        bar          sales
--   92 top_skus_volume_revenue bar         sales
--   16 cct_days_per_beer     donut        tanks
--   72 fg_inventory_value    kpi_number   fg_stock
--   73 fg_days_cover         table        fg_stock
--  168 cogs_total_month      kpi_number   cogs
--  172 cop_total_breakdown   stacked_bar  cogs
--
-- Idempotency: INSERT IGNORE on preset + grant rows; UPDATE on user FK is safe
--   to re-run (same value). KPI seeds use INSERT IGNORE on (user_id_fk,
--   tracker_id_fk) via a UNIQUE-safe guard (no duplicate pair possible given
--   the explicit values).
--
-- MySQL 8 compatible: no IF NOT EXISTS on ALTER, no bare SELECT, no MariaDB-only
--   extensions. No raw SELECT statements (would leave open result sets in
--   PDO::exec() mode).
-- =============================================================================

-- 1. Create the smoke_viewer preset (idempotent)
INSERT IGNORE INTO ref_access_presets (preset_key, label, description)
VALUES (
    'smoke_viewer',
    'Smoke-test (lecture)',
    'Read-only preset for automated smoke-test account (users.id=16). NEVER use for real users.'
);

-- 2. Grant page access — one row per page, keyed on the preset's natural key.
--    All 12 pages verified to exist in ref_pages as of 2026-06-16.
--    INSERT IGNORE makes this idempotent (re-run = no-op on existing rows).

INSERT IGNORE INTO ref_access_preset_pages (preset_id_fk, page_id_fk)
SELECT p.id, rp.id
FROM ref_access_presets p
JOIN ref_pages rp ON rp.page_key IN (
    'mon-tableau',
    'sb-board',
    'sb-guerre',
    'journal-saisies',
    'zeppelin',
    'qa',
    'approvisionnement',
    'expeditions',
    'warehouse',
    'planning',
    'rm-comparison',
    'financier'
)
WHERE p.preset_key = 'smoke_viewer';

-- 3. Wire users.id=16 to the new preset (resolved via subquery — no hardcoded id)
UPDATE users
SET access_preset_id_fk = (
    SELECT id FROM ref_access_presets WHERE preset_key = 'smoke_viewer'
)
WHERE id = 16;

-- 4. Seed KPI tracker selections for user 16
--    Sequential positions 1..8 spanning kpi_number/bar/donut/table/stacked_bar viz types.
--    INSERT IGNORE prevents duplicate (user_id_fk, tracker_id_fk) pairs on re-run.
INSERT IGNORE INTO user_kpi_selections (user_id_fk, tracker_id_fk, position)
VALUES
    (16,   1, 1),   -- hl_brewed_month        (kpi_number, wort)
    (16,  86, 2),   -- units_sold_sku          (bar, sales)
    (16,  92, 3),   -- top_skus_volume_revenue  (bar, sales)
    (16,  16, 4),   -- cct_days_per_beer       (donut, tanks)
    (16,  72, 5),   -- fg_inventory_value      (kpi_number, fg_stock)
    (16,  73, 6),   -- fg_days_cover           (table, fg_stock)
    (16, 168, 7),   -- cogs_total_month        (kpi_number, cogs)
    (16, 172, 8);   -- cop_total_breakdown     (stacked_bar, cogs)
