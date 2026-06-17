-- db/migrations/396_energy_cop_webpath_foundations.sql
--
-- What: DB foundations for the energy/utilities web-path.
--       Moves the canonical tariff store and the per-month peak-kW/reactive
--       closure snapshots from off-VPS JSON files into MySQL, enabling
--       a PHP-native anticipated-cost estimator and a monthly meter-reading
--       input surface.
--
-- Contains:
--   1. CREATE TABLE ref_utility_tariffs  — versioned tariff store (was data/utility-tariffs.json)
--      + seed: 1 version (effectiveFrom 2026-01-01)
--   2. CREATE TABLE ops_utility_closures — frozen peak-kW / reactive snapshots (was data/utility-closures.json)
--      + seed: 31 periods (2023-10 … 2026-04)
--   3. ref_pages row  — page_key='saisie-energie', finance category
--   4. ref_access_preset_pages grants — manager preset (id=1) + finance_viewer preset
--   5. schema_meta rows — ref_utility_tariffs (reference), ops_utility_closures (source)
--      NOTE: inv_energydata already has a schema_meta row (mig 196) — NOT duplicated here.
--
-- Rollback:
--   DELETE FROM ref_access_preset_pages
--     WHERE page_id_fk = (SELECT id FROM ref_pages WHERE page_key='saisie-energie');
--   DELETE FROM ref_pages WHERE page_key='saisie-energie';
--   DELETE FROM schema_meta WHERE table_name IN ('ref_utility_tariffs','ops_utility_closures');
--   DROP TABLE IF EXISTS ops_utility_closures;
--   DROP TABLE IF EXISTS ref_utility_tariffs;
--
-- MySQL 8 compatible: no ADD COLUMN IF NOT EXISTS, no bare SELECT, no MariaDB-only
--   extensions. Idempotency via CREATE TABLE IF NOT EXISTS + ON DUPLICATE KEY UPDATE
--   + INSERT IGNORE. No raw SELECT statements (PDO::exec() leaves result sets open).
-- ============================================================================


-- ============================================================================
-- 1. TABLE: ref_utility_tariffs
--    Canonical versioned tariff store. One row per effective-from date.
--    Replaces data/utility-tariffs.json for the web path.
-- ============================================================================

CREATE TABLE IF NOT EXISTS ref_utility_tariffs (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  effective_from DATE         NOT NULL,
  tariff_json    JSON         NOT NULL COMMENT 'Full tariff version object: {effectiveFrom, gas, water, sewage, electricity}',
  source_note    VARCHAR(255) NULL,
  created_by     VARCHAR(64)  NOT NULL DEFAULT 'seed',
  created_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_ref_utility_tariffs_effective_from (effective_from)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Versioned utility tariffs (gas, water, sewage, electricity). Active tariff = row with MAX(effective_from) <= billing period. Seeded from data/utility-tariffs.json mig 396.';

-- Seed: 1 version, effectiveFrom 2026-01-01
-- tariff_json = full version object from data/utility-tariffs.json versions[0]
-- Numeric values are semantically identical to source (trailing zeros are cosmetic in IEEE 754 floats)
INSERT INTO ref_utility_tariffs
  (effective_from, tariff_json, source_note, created_by)
VALUES
  (
    '2026-01-01',
    '{"effectiveFrom":"2026-01-01","gas":{"_source":"SIL invoice #8701, Feb 2026 consumption","meterCoefficient_kWhPerM3":10.607855958,"subscriptionCHFPerMonth":60.0,"powerClauseCHFPerKWPerMonth":1.5,"subscribedCapacityKW":400,"consumptionCHFPerKWh":0.1084,"co2TaxCHFPerKWh":0.02158,"tvaRate":0.081},"water":{"_source":"SIL invoice #8701","taxeBaseCHFPerMonth":7.0,"meterRentalCHFPerMonth":29.0,"antiReturnValveCHFPerMonth":3.0,"flowFeeCHFPerM3hPerYear":40.0,"meterCapacityM3h":63,"consumptionCHFPerM3":1.62,"solidarityFundCHFPerM3":0.01,"tvaRate":0.026},"sewage":{"_source":"SIL invoice #8701","baseRateCHFPerM3":1.5,"nonDrinkingRebate":0.07,"tvaRate":0.081},"electricity":{"_source":"SIE invoice #8666, Feb 2026 consumption","meterCoefficient":15,"energy":{"hpCHFPerKWh":0.1778,"hcCHFPerKWh":0.1177},"achemRegional":{"subscriptionCHFPerMonth":60.0,"hpCHFPerKWh":0.099,"hcCHFPerKWh":0.0522,"peakPowerCHFPerKWPerMonth":8.71,"reactiveFranchiseKVArh":779.84,"reactiveChargeCHFPerKVArh":0.04,"measuringSubscriptionCHFPerMonth":14.0},"achemNational":{"hpCHFPerKWh":0.0151,"hcCHFPerKWh":0.0091,"peakPowerCHFPerKWPerMonth":1.18,"winterReserveCHFPerKWh":0.0041,"systemServicesCHFPerKWh":0.0027,"solidarityCostsCHFPerKWh":0.0005},"taxes":{"federalesLEneCHFPerKWh":0.023,"emolumentCantonalCHFPerKWh":0.0002,"emolumentCommunalCHFPerKWh":0.007,"_tvaApplicableAbove":"federal + emolument cantonal + emolument communal are TVA-taxed","cantonalLVLEneCHFPerKWh":0.006,"taxeCommunaleSpecifiqueCHFPerKWh":0.016,"_tvaExemptAbove":"cantonal + taxe communale spécifique are NOT TVA-taxed"},"tvaRate":0.081,"defaultPeakPowerKW":70.5,"_defaults_note":"Peak power and reactive-within-franchise are seeded from Feb 2026 measurements. Override monthly if actual differs (peak demand varies with production intensity)."}}',
    'seed from maltytask data/utility-tariffs.json',
    'migration 396 seed'
  )
ON DUPLICATE KEY UPDATE
  tariff_json  = VALUES(tariff_json),
  source_note  = VALUES(source_note);


-- ============================================================================
-- 2. TABLE: ops_utility_closures
--    Frozen peak-kW / reactive snapshots for closed months.
--    Replaces data/utility-closures.json for the web path.
--    One row per YYYY-MM period. source ENUM matches the two values present
--    in data/utility-closures.json (verified: only 'actual-invoice' and
--    'rolling-at-closure' appear — no unknown strings).
-- ============================================================================

CREATE TABLE IF NOT EXISTS ops_utility_closures (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  period          CHAR(7)      NOT NULL COMMENT 'YYYY-MM',
  peak_kw         DECIMAL(10,3) NOT NULL,
  reactive_kvarh  DECIMAL(12,3) NOT NULL DEFAULT 0,
  snapshot_source ENUM('actual-invoice','rolling-at-closure') NOT NULL,
  frozen_at       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_ops_utility_closures_period (period)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Frozen peak-kW + reactive kVArh per closed month. Immutable once sealed. Written by app/utilities-estimate.php resolvePeakKW close path. Replaces data/utility-closures.json mig 396.';

-- Seed: 31 periods from data/utility-closures.json (sorted by period ascending)
-- frozen_at = ISO frozenAt converted to MySQL DATETIME (YYYY-MM-DD HH:MM:SS, UTC)
INSERT INTO ops_utility_closures
  (period, peak_kw, reactive_kvarh, snapshot_source, frozen_at)
VALUES
  ('2023-10', 70.5,    0,      'rolling-at-closure', '2026-04-20 21:14:27'),
  ('2023-11', 70.5,    0,      'rolling-at-closure', '2026-04-20 21:14:27'),
  ('2023-12', 70.5,    0,      'rolling-at-closure', '2026-04-20 21:14:27'),
  ('2024-01', 70.5,    0,      'rolling-at-closure', '2026-04-20 21:14:27'),
  ('2024-02', 70.5,    0,      'rolling-at-closure', '2026-04-20 21:14:27'),
  ('2024-03', 70.5,    0,      'rolling-at-closure', '2026-04-20 21:14:27'),
  ('2024-04', 70.5,    0,      'rolling-at-closure', '2026-04-20 21:14:27'),
  ('2024-05', 70.5,    0,      'rolling-at-closure', '2026-04-20 21:14:27'),
  ('2024-06', 70.5,    0,      'rolling-at-closure', '2026-04-20 21:14:27'),
  ('2024-07', 70.5,    0,      'rolling-at-closure', '2026-04-20 21:14:27'),
  ('2024-08', 70.5,    0,      'rolling-at-closure', '2026-04-20 21:14:27'),
  ('2024-09', 70.5,    0,      'rolling-at-closure', '2026-04-20 21:14:27'),
  ('2024-10', 70.5,    0,      'rolling-at-closure', '2026-04-20 21:14:27'),
  ('2024-11', 70.5,    0,      'rolling-at-closure', '2026-04-20 21:14:27'),
  ('2024-12', 70.5,    0,      'rolling-at-closure', '2026-04-20 21:14:27'),
  ('2025-01', 70.5,    0,      'rolling-at-closure', '2026-04-20 21:14:27'),
  ('2025-02', 70.5,    0,      'rolling-at-closure', '2026-04-20 21:14:27'),
  ('2025-03', 70.5,    0,      'rolling-at-closure', '2026-04-20 21:14:27'),
  ('2025-04', 70.5,    0,      'rolling-at-closure', '2026-04-20 21:14:27'),
  ('2025-05', 70.5,    0,      'rolling-at-closure', '2026-04-20 21:14:27'),
  ('2025-06', 70.5,    0,      'rolling-at-closure', '2026-04-20 21:14:27'),
  ('2025-07', 70.5,    0,      'rolling-at-closure', '2026-04-20 21:14:27'),
  ('2025-08', 70.5,    0,      'rolling-at-closure', '2026-04-20 21:14:27'),
  ('2025-09', 70.5,    0,      'rolling-at-closure', '2026-04-20 21:14:27'),
  ('2025-10', 70.5,    0,      'rolling-at-closure', '2026-04-20 21:14:27'),
  ('2025-11', 70.5,    0,      'rolling-at-closure', '2026-04-20 21:14:27'),
  ('2025-12', 57,   3658,      'actual-invoice',      '2026-05-05 11:34:05'),
  ('2026-01', 63,    732,      'actual-invoice',      '2026-05-05 11:34:05'),
  ('2026-02', 70.5,  858,      'actual-invoice',      '2026-04-19 16:35:04'),
  ('2026-03', 72,    789,      'actual-invoice',      '2026-05-05 11:34:05'),
  ('2026-04', 66,    829.5,    'actual-invoice',      '2026-06-10 06:35:04')
ON DUPLICATE KEY UPDATE
  peak_kw         = VALUES(peak_kw),
  reactive_kvarh  = VALUES(reactive_kvarh),
  snapshot_source = VALUES(snapshot_source),
  frozen_at       = VALUES(frozen_at);


-- ============================================================================
-- 3. ref_pages row — saisie-energie
--    Finance category, sort=40 (after financier=10, charges-bc=20, bom-review=30).
--    category_key assigned inline via a separate UPDATE (mig 383 pattern).
--    domain='general' matches financier; min_role='manager' (financial input).
--    href='/modules/saisie-energie.php' — page to be built.
-- ============================================================================

INSERT INTO `ref_pages`
  (`page_key`, `label`, `icon`, `href`, `min_role`, `domain`, `is_active`, `sort`)
VALUES
  ('saisie-energie', 'Index énergie', '⚡', '/modules/saisie-energie.php', 'manager', 'general', 1, 128)
ON DUPLICATE KEY UPDATE
  `label`     = VALUES(`label`),
  `icon`      = VALUES(`icon`),
  `href`      = VALUES(`href`),
  `min_role`  = VALUES(`min_role`),
  `domain`    = VALUES(`domain`),
  `is_active` = VALUES(`is_active`),
  `sort`      = VALUES(`sort`);

-- Assign finance category (category_key + category_sort added by mig 383)
UPDATE `ref_pages`
SET    category_key  = 'finance',
       category_sort = 40
WHERE  page_key = 'saisie-energie';


-- ============================================================================
-- 4. Access preset grants
--    manager preset (id=1): finance input is manager-only, same as financier.
--    finance_viewer preset: read-only CFO access — should see energy index too.
--    Pattern: INSERT IGNORE with subquery resolution (mig 304 + 378 style).
--    Keys on (preset_id_fk, page_id_fk) — per ref_access_preset_pages schema.
-- ============================================================================

-- manager preset (preset_id=1)
INSERT IGNORE INTO `ref_access_preset_pages` (`preset_id_fk`, `page_id_fk`)
SELECT p.id, rp.id
FROM   `ref_pages` rp
CROSS JOIN `ref_access_presets` p
WHERE  rp.page_key = 'saisie-energie'
  AND  p.id = 1;

-- finance_viewer preset (resolved by preset_key — avoids hardcoded id)
INSERT IGNORE INTO `ref_access_preset_pages` (`preset_id_fk`, `page_id_fk`)
SELECT (SELECT id FROM ref_access_presets WHERE preset_key = 'finance_viewer'), rp.id
FROM   `ref_pages` rp
WHERE  rp.page_key = 'saisie-energie';


-- ============================================================================
-- 5. schema_meta rows
--    ref_utility_tariffs: class='reference', corrections_policy='allowed'
--      (admin edits tariff versions when SIE/SIL issues a new tariff schedule)
--    ops_utility_closures: class='source', corrections_policy='allowed'
--      (written by app/utilities-estimate.php resolvePeakKW close path;
--       corrections permitted to fix a bad invoice read before month-close)
--    NOTE: inv_energydata already has a schema_meta row (mig 196) — not duplicated.
-- ============================================================================

INSERT INTO schema_meta
    (table_name, table_class, writer_script, corrections_policy, upstream_hint, notes)
VALUES
    ('ref_utility_tariffs',
     'reference',
     'migration 396 seed + admin SQL',
     'allowed',
     'Update via direct MySQL INSERT with a new effective_from date when SIE/SIL issues a revised tariff. Active tariff for a given period = row with MAX(effective_from) <= period. Never UPDATE an existing row — append a new version.',
     'Created mig 396. Replaces data/utility-tariffs.json for the web path. One row per tariff version; tariff_json = full gas/water/sewage/electricity object.')
ON DUPLICATE KEY UPDATE
    notes      = VALUES(notes),
    updated_at = CURRENT_TIMESTAMP;

INSERT INTO schema_meta
    (table_name, table_class, writer_script, corrections_policy, upstream_hint, notes)
VALUES
    ('ops_utility_closures',
     'source',
     'app/utilities-estimate.php (resolvePeakKW close)',
     'allowed',
     'Written when a month is closed: peak_kw and reactive_kvarh frozen from the SIE invoice or rolling estimate. Corrections allowed before month-close; post-close edits must be coordinated with COGS utilities recompute (build-cogs-report.js).',
     'Created mig 396. Replaces data/utility-closures.json for the web path. 31 periods seeded (2023-10 to 2026-04). One row per YYYY-MM period; immutable once closed month is sealed.')
ON DUPLICATE KEY UPDATE
    notes      = VALUES(notes),
    updated_at = CURRENT_TIMESTAMP;

-- end migration 396
