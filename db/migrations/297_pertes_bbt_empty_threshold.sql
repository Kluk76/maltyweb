-- Migration 297: Add commissioning setting for BBT-vide empty threshold
--
-- Promotes the TankSimulator's hardcoded 2.5 HL dead-volume floor to a
-- commissioning setting, editable on the QA/QC losses page.
--
-- Mirrors the column set of existing pertes rows (section, key_name,
-- label_fr, description_fr, value_num, unit_fr, default_num, is_active).
--
-- The setting governs:
--   1. TankSimulator: below this threshold the BBT is treated as empty
--      (both at event-replay time and for candidate filtering).
--   2. tank_bbt_composition(): blend candidates below threshold are hidden.
--
-- Plain INSERT is safe — migrate.php has already confirmed this row does not
-- exist (schema_migrations tracks whether 297 was applied).

INSERT INTO commissioning_settings
    (section, key_name, label_fr, description_fr, value_num, unit_fr, default_num, is_active)
VALUES
    ('pertes', 'pertes_bbt_empty_threshold_hl',
     'Seuil BBT vide (talon)',
     'Volume résiduel en BBT en-dessous duquel la cuve est considérée comme vide (talon de fond). Utilisé par le simulateur de tank et le filtre des candidats de blend.',
     2.5, 'HL', 2.5, 1);
