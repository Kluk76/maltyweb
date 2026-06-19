-- Migration 414: Seed system_settings row for serving-tank turnaround estimate.
-- Used by predict_load_free_serving_tank_count() to compute occupied count via
-- N-day decay from last fill (bd_packaging_v2 run_type='cuv').  No return/pickup
-- event exists; this is an approximation, not exact occupancy.
INSERT INTO system_settings
    (section, key_name, label_fr, description_fr, value_num, default_num, unit_fr, is_active)
VALUES
    (
        'production_targets',
        'serving_tank_turnaround_days',
        'Rotation cuve de service (jours)',
        'Estimation : nombre de jours pendant lesquels une cuve de service est considérée occupée après un remplissage (pas d''événement de retour ; sert au comptage des cuves libres du planificateur).',
        14,
        14,
        'jours',
        1
    )
ON DUPLICATE KEY UPDATE
    label_fr       = VALUES(label_fr),
    description_fr = VALUES(description_fr),
    default_num    = VALUES(default_num),
    unit_fr        = VALUES(unit_fr),
    is_active      = VALUES(is_active);
-- NOTE: value_num is intentionally NOT updated on duplicate so an operator-changed
-- value is preserved across re-runs.
