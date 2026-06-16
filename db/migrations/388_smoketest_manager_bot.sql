-- =============================================================================
-- Migration 388: Manager-tier smoke-test bot account
-- =============================================================================
-- Creates a second smoke-test account (role='manager', manager_scope=NULL) so
-- webapp-testing agents can render manager-tier KPI widgets (sales, COGS,
-- financier page) without touching any real user's credentials.
--
-- Design:
--   manager_scope=NULL  — role=manager rank passes min_role <= manager checks,
--                         so all manager-tier widgets render.  But manager_can()
--                         always returns false when scope=NULL, so scope-gated
--                         write controls (COGS "Sceller", finance actions) stay
--                         hidden.  Minimum surface for read-only smoke.
--   access_preset_id_fk=NULL — role floor passes for all viewer/operator/manager
--                         pages; admin-only hard gates (is_admin()) remain blocked.
--   email=NULL          — bot account, no email needed.
--   No per-user page grants inserted — role floor is sufficient.
-- =============================================================================

-- Part 1: Insert manager bot user (idempotent: INSERT IGNORE on UNIQUE username)
INSERT IGNORE INTO users
    (username, email, password_hash, display_name, role, manager_scope,
     access_preset_id_fk, is_active)
VALUES
    (
        'smoketest_mgr',
        NULL,
        -- Argon2id hash of the plaintext in secrets/maltyweb-smoketest-manager.env
        -- Generated: password_hash($pw, PASSWORD_ARGON2ID) on VPS 2026-06-16
        '$argon2id$v=19$m=65536,t=4,p=1$N2VDaEc4UE1PeWZxNEl4ZA$sa37ZS1EQGct/D4kFp7FNOw8GfRgQHEZmTnbLuJ4sug',
        'Smoke Test Manager (bot)',
        'manager',
        NULL,        -- manager_scope=NULL: renders all manager widgets, writes nothing
        NULL,        -- access_preset_id_fk=NULL: role floor grants all non-admin pages
        1
    );

-- Part 2: Seed 8 KPI trackers for the manager bot (idempotent via INSERT IGNORE)
-- Resolve user id via subquery so this works before we know the assigned PK.
-- Selected trackers span tiers + viz types, include the target manager widgets:
--   pos 1: id=1   viewer/wort      hl_brewed_month          (kpi_number)
--   pos 2: id=13  operator/tanks   cct_bbt_occupancy        (kpi_number)
--   pos 3: id=85  manager/sales    revenue_month            (kpi_number)
--   pos 4: id=86  manager/sales    units_sold_sku  **TARGET** (table/bar)
--   pos 5: id=92  manager/sales    top_skus_volume_revenue  **TARGET** (table)
--   pos 6: id=72  manager/fg_stock fg_inventory_value       (kpi_number)
--   pos 7: id=168 manager/cogs     cogs_total_month **TARGET** (kpi_number)
--   pos 8: id=172 manager/cogs     cop_total_breakdown **TARGET** (waterfall)
INSERT IGNORE INTO user_kpi_selections (user_id_fk, tracker_id_fk, position)
SELECT u.id, t.tracker_id, t.pos
FROM
    (SELECT id FROM users WHERE username = 'smoketest_mgr' LIMIT 1) u,
    (
        SELECT 1  AS tracker_id, 1  AS pos UNION ALL
        SELECT 13 AS tracker_id, 2  AS pos UNION ALL
        SELECT 85 AS tracker_id, 3  AS pos UNION ALL
        SELECT 86 AS tracker_id, 4  AS pos UNION ALL
        SELECT 92 AS tracker_id, 5  AS pos UNION ALL
        SELECT 72 AS tracker_id, 6  AS pos UNION ALL
        SELECT 168 AS tracker_id, 7 AS pos UNION ALL
        SELECT 172 AS tracker_id, 8 AS pos
    ) t;

-- Part 3: Re-seed viewer bot (id=16) with ONLY viewer-tier data_ready trackers.
-- The bot currently holds manager/operator trackers that never render at role=viewer.
-- Idempotent: DELETE the non-viewer ones, then INSERT IGNORE the 8 viewer-tier ones.
-- The 8 viewer data_ready trackers are: 1, 2, 3, 8, 39, 49, 50, 52.

-- Remove non-viewer-tier selections from the viewer bot (keeps any viewer ones already there)
DELETE FROM user_kpi_selections
WHERE user_id_fk = 16
  AND tracker_id_fk NOT IN (1, 2, 3, 8, 39, 49, 50, 52);

-- Insert the correct 8 viewer trackers (INSERT IGNORE is safe: existing rows unchanged)
INSERT IGNORE INTO user_kpi_selections (user_id_fk, tracker_id_fk, position)
VALUES
    (16, 1,  1),   -- hl_brewed_month
    (16, 2,  2),   -- hl_brewed_ytd
    (16, 3,  3),   -- brew_count_month
    (16, 8,  4),   -- days_since_last_brew
    (16, 39, 5),   -- rackings_month
    (16, 49, 6),   -- hl_packaged_month
    (16, 50, 7),   -- packaging_runs_count
    (16, 52, 8);   -- format_mix_pct

-- Part 4: schema_meta classification for audit completeness
-- (no new table added; users + user_kpi_selections already have rows; no new row needed)
-- No SELECT in this file per migrate.php PDO exec() discipline.
