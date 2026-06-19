-- ============================================================
-- Migration 397: Seed system_settings for order-confirmation email
--
-- What:    Inserts three fulfilment-section settings that control
--          the order-confirmation email sent on the "Confirmer" click
--          in the Expéditions module.
--
-- Why:     The send_mail call in expeditions-status.php reads these
--          keys via system_setting(). Without them the feature is
--          permanently in 'off' mode (the fallback), so no email is
--          ever sent accidentally. The operator flips mode to 'test'
--          or 'live' via the Données générales settings page.
--
-- Risk:    Pure INSERT — no DDL, no schema change, no data mutation.
--          ON DUPLICATE KEY UPDATE id=id makes re-apply a safe no-op
--          given the UNIQUE key uk_system_settings_section_key(section,key_name).
--
-- Rollback: DELETE FROM system_settings
--             WHERE section='fulfilment'
--               AND key_name IN ('confirmation_email_mode',
--                                'confirmation_email_test_to',
--                                'confirmation_email_from');
-- ============================================================

INSERT INTO system_settings
    (section, key_name, label_fr, description_fr, value_text, is_active, updated_by)
VALUES
    ('fulfilment', 'confirmation_email_mode',
     'Mode e-mail de confirmation de commande',
     'Contrôle l''envoi de l''e-mail de confirmation au clic « Confirmer » d''une commande. off = aucun envoi (défaut) ; test = envoi réel redirigé vers l''adresse de test ; live = envoi réel au client.',
     'off', 1, 'migration_397'),

    ('fulfilment', 'confirmation_email_test_to',
     'Adresse de test (e-mail confirmation)',
     'Destinataire unique utilisé quand le mode est « test ». Les adresses clients sont ignorées en mode test.',
     'kouros@lanebuleuse.ch', 1, 'migration_397'),

    ('fulfilment', 'confirmation_email_from',
     'Expéditeur e-mail de confirmation',
     'Adresse d''expédition (From / Reply-To) des e-mails de confirmation de commande.',
     'commandes@lanebuleuse.ch', 1, 'migration_397')

ON DUPLICATE KEY UPDATE id = id;
