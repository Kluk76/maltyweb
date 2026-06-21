-- ============================================================
-- Migration 426: Seed system_settings for BC push mode (email orders)
--
-- What:    One setting in section 'logistics' controlling whether
--          validated email orders are pushed to Business Central
--          immediately after local creation.
--
-- Why:     email-orders.php reads this via system_setting() after
--          calling email_order_promote(). Default is 'off' so the
--          BC push is disarmed until the operator enables it.
--          'armed' = POST réel vers BC.
--
-- Risk:    Pure INSERT — no DDL, no schema change.
--          ON DUPLICATE KEY UPDATE id=id makes re-apply a safe no-op.
--
-- Rollback: DELETE FROM system_settings
--             WHERE section='logistics'
--               AND key_name = 'email_order_bc_push_mode';
-- ============================================================

INSERT INTO system_settings
    (section, key_name, label_fr, description_fr, value_text, is_active, updated_by)
VALUES
    ('logistics', 'email_order_bc_push_mode',
     'Mode envoi BC (commandes e-mail)',
     'off = commande créée localement uniquement, payload BC journalisé (défaut) ; armed = POST réel vers BC.',
     'off', 1, 'migration_426')

ON DUPLICATE KEY UPDATE id = id;
