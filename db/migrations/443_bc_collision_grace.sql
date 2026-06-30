-- 443_bc_collision_grace.sql
-- Layer B: seed grace window for BC collision liveness gate.
-- Active-candidate legacy ord_orders rows are only valid collision targets
-- when their requested_date >= (run_date - bc_collision_legacy_grace_days).
-- NULL requested_date continues to guard (fail-safe). Default: 14 days.

INSERT INTO system_settings
    (section, key_name, label_fr, description_fr, value_num, unit_fr, default_num, is_active, updated_by)
VALUES
    ('fulfilment', 'bc_collision_legacy_grace_days',
     'Fenêtre de validité BC collision (jours)',
     'Nombre de jours en arrière depuis aujourd''hui pour qu''un ord_orders actif (non-BC) soit encore un obstacle à l''import BC. Au-delà, la ligne est considérée périmée et ne bloque plus. NULL = bloque toujours (fail-safe).',
     14, 'jours', 14, 1, 'migration_443')
ON DUPLICATE KEY UPDATE value_num = 14;
