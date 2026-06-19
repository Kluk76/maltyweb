-- ============================================================
-- Migration 422: Seed system_settings for recipe change-request email notifications
--
-- What:    Two settings in section 'recipes' controlling whether
--          emails are sent when a CR is filed, approved, or rejected.
--
-- Why:     sdc_cr_email_send() in salle-de-controle.php reads these
--          via system_setting(). Default is 'off' so no emails are
--          sent until the operator enables the feature.
--
-- Risk:    Pure INSERT — no DDL, no schema change.
--          ON DUPLICATE KEY UPDATE id=id makes re-apply a safe no-op.
--
-- Rollback: DELETE FROM system_settings
--             WHERE section='recipes'
--               AND key_name IN ('recipe_cr_email_mode','recipe_cr_email_test_to');
-- ============================================================

INSERT INTO system_settings
    (section, key_name, label_fr, description_fr, value_text, is_active, updated_by)
VALUES
    ('recipes', 'recipe_cr_email_mode',
     'Mode e-mail demandes de modification recette',
     'Contrôle l''envoi des e-mails lors des demandes de modification de recette. off = aucun envoi (défaut) ; test = envoi réel redirigé vers l''adresse de test ; live = envoi aux destinataires réels.',
     'off', 1, 'migration_422'),

    ('recipes', 'recipe_cr_email_test_to',
     'Adresse de test (e-mail demandes recette)',
     'Destinataire(s) utilisé(s) quand le mode est « test ». Séparer plusieurs adresses par un point-virgule.',
     'kouros@lanebuleuse.ch', 1, 'migration_422')

ON DUPLICATE KEY UPDATE id = id;
