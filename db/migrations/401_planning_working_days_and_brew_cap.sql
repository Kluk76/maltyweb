-- ============================================================
-- Migration 401 — Planning: working-day toggles + brew-day cap
-- ============================================================
-- Adds 9 operator-owned system_settings rows under
-- section='production_targets':
--   • workday_mon … workday_sun (7 binary toggles, 1=ouvré 0=fermé)
--   • max_brews_per_day (integer cap, default 5)
--   • max_packaging_runs_per_day (integer cap, default 4)
--
-- These settings drive the planning suggestion engine only.
-- They have NO effect on COGS, beer tax, or fiscal calculations.
--
-- Idempotent: ON DUPLICATE KEY updates metadata only;
-- value_num (operator's live setting) is intentionally preserved.
-- ============================================================

INSERT INTO system_settings
    (section, key_name, label_fr, description_fr,
     value_text, value_num, unit_fr,
     default_text, default_num, is_active)
VALUES
    ('production_targets', 'workday_mon', 'Lundi ouvré',
     'Jour de production : le moteur de suggestions ne propose des brassins/conditionnements que les jours ouvrés.',
     NULL, 1, NULL, NULL, 1, 1),

    ('production_targets', 'workday_tue', 'Mardi ouvré',
     'Jour de production : le moteur de suggestions ne propose des brassins/conditionnements que les jours ouvrés.',
     NULL, 1, NULL, NULL, 1, 1),

    ('production_targets', 'workday_wed', 'Mercredi ouvré',
     'Jour de production : le moteur de suggestions ne propose des brassins/conditionnements que les jours ouvrés.',
     NULL, 1, NULL, NULL, 1, 1),

    ('production_targets', 'workday_thu', 'Jeudi ouvré',
     'Jour de production : le moteur de suggestions ne propose des brassins/conditionnements que les jours ouvrés.',
     NULL, 1, NULL, NULL, 1, 1),

    ('production_targets', 'workday_fri', 'Vendredi ouvré',
     'Jour de production : le moteur de suggestions ne propose des brassins/conditionnements que les jours ouvrés.',
     NULL, 1, NULL, NULL, 1, 1),

    ('production_targets', 'workday_sat', 'Samedi ouvré',
     'Jour de production : le moteur de suggestions ne propose des brassins/conditionnements que les jours ouvrés.',
     NULL, 0, NULL, NULL, 0, 1),

    ('production_targets', 'workday_sun', 'Dimanche ouvré',
     'Jour de production : le moteur de suggestions ne propose des brassins/conditionnements que les jours ouvrés.',
     NULL, 0, NULL, NULL, 0, 1),

    ('production_targets', 'max_brews_per_day', 'Brassins max / jour',
     'Plafond de brassins proposés par journée par le moteur de suggestions de planning.',
     NULL, 5, 'brassins/jour', NULL, 5, 1),

    ('production_targets', 'max_packaging_runs_per_day', 'Conditionnements max / jour',
     'Plafond de conditionnements proposés par journée par le moteur de suggestions de planning.',
     NULL, 4, 'runs/jour', NULL, 4, 1)

ON DUPLICATE KEY UPDATE
    label_fr        = VALUES(label_fr),
    unit_fr         = VALUES(unit_fr),
    description_fr  = VALUES(description_fr),
    default_num     = VALUES(default_num),
    is_active       = VALUES(is_active);
