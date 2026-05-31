-- Migration 219: Seed recipe_lifecycle / active_window_months setting
-- Controls the rolling window for "Recettes actives" vs "Recettes passées".
-- Default 12 months: a recipe brewed within the last 12 months stays in actives.
-- Adjust via commissioning_settings UPDATE if the brewery changes policy.
-- Applied via migrate.php — idempotent (ON DUPLICATE KEY UPDATE).

INSERT INTO commissioning_settings
    (section, key_name, label_fr, description_fr, value_num, unit_fr, default_num, is_active, updated_by)
VALUES (
    'recipe_lifecycle',
    'active_window_months',
    'Fenêtre recettes actives',
    'Nombre de mois depuis le dernier brassin pour qu''une recette reste dans "Recettes actives". Au-delà, elle passe dans "Recettes passées" sauf si elle est encore en stock.',
    12,
    'mois',
    12,
    1,
    'migration_219'
)
ON DUPLICATE KEY UPDATE
    value_num  = VALUES(value_num),
    default_num = VALUES(default_num),
    updated_by = VALUES(updated_by);
