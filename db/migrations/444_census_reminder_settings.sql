-- ============================================================
-- Migration 444: Seed system_settings for FG census reminder + month-close reminder
-- ============================================================
--
-- Seeds 7 keys in system_settings (section = 'fulfilment').
-- No new tables, no DDL — pure INSERT only.
-- Idempotent: ON DUPLICATE KEY UPDATE key_name = key_name is a strict
-- no-op — it never overwrites an operator-set value.
--
-- Keys seeded:
--   fg_census_stale_days          (numeric) — staleness threshold, default 7 days
--   census_reminder_email_mode    (text)    — 'off'/'test'/'on'; gating switch
--   census_reminder_hour          (numeric) — local hour to send daily check, default 7
--   census_reminder_dow           (numeric) — ISO day-of-week to send (1=Mon), default 1
--   census_reminder_test_to       (text)    — recipient email in test mode
--   census_month_close_reminder_mode (text) — 'off'/'test'/'on'
--   census_month_close_lead_days  (numeric) — days before month-end to alert, default 3
--
-- Phase B (email cron) reads all 7 via system_setting($key, 'fulfilment').
-- Phase C (tile) reads fg_census_stale_days via census_stale_days_threshold().
--
-- Rollback:
--   DELETE FROM system_settings
--    WHERE section = 'fulfilment'
--      AND key_name IN (
--        'fg_census_stale_days', 'census_reminder_email_mode',
--        'census_reminder_hour', 'census_reminder_dow',
--        'census_reminder_test_to', 'census_month_close_reminder_mode',
--        'census_month_close_lead_days'
--      );
-- ============================================================

-- ── Numeric settings ──────────────────────────────────────────────────────────

INSERT INTO system_settings
    (section, key_name, label_fr, description_fr, value_num, unit_fr, is_active, updated_by)
VALUES
    ('fulfilment', 'fg_census_stale_days',
     'Seuil fraîcheur inventaire FG (jours)',
     'Nombre de jours sans inventaire FG au-delà duquel un site est considéré « en retard ». Pilote l''alerte pastille (Phase C) et le rappel e-mail (Phase B). Minimum effectif : 1.',
     7.0000, 'jours', 1, 'migration_444')
ON DUPLICATE KEY UPDATE key_name = key_name;

INSERT INTO system_settings
    (section, key_name, label_fr, description_fr, value_num, unit_fr, is_active, updated_by)
VALUES
    ('fulfilment', 'census_reminder_hour',
     'Heure d''envoi du rappel inventaire (heure locale)',
     'Heure locale (0–23) à laquelle le cron envoie le rappel d''inventaire FG si la condition de retard est remplie. Valeur par défaut : 7 (07h00).',
     7.0000, 'heure', 1, 'migration_444')
ON DUPLICATE KEY UPDATE key_name = key_name;

INSERT INTO system_settings
    (section, key_name, label_fr, description_fr, value_num, unit_fr, is_active, updated_by)
VALUES
    ('fulfilment', 'census_reminder_dow',
     'Jour d''envoi du rappel inventaire (ISO 1=lundi)',
     'Jour de la semaine ISO (1=lundi … 7=dimanche) pendant lequel le rappel hebdomadaire d''inventaire est envoyé. Valeur par défaut : 1 (lundi).',
     1.0000, 'jour ISO', 1, 'migration_444')
ON DUPLICATE KEY UPDATE key_name = key_name;

INSERT INTO system_settings
    (section, key_name, label_fr, description_fr, value_num, unit_fr, is_active, updated_by)
VALUES
    ('fulfilment', 'census_month_close_lead_days',
     'Préavis rappel clôture mensuelle (jours)',
     'Nombre de jours avant la fin du mois à partir desquels le rappel de clôture mensuelle est envoyé aux managers et admins. Valeur par défaut : 3.',
     3.0000, 'jours', 1, 'migration_444')
ON DUPLICATE KEY UPDATE key_name = key_name;

-- ── Text settings ─────────────────────────────────────────────────────────────

INSERT INTO system_settings
    (section, key_name, label_fr, description_fr, value_text, is_active, updated_by)
VALUES
    ('fulfilment', 'census_reminder_email_mode',
     'Mode envoi rappel inventaire FG',
     'off = pas d''envoi (défaut) ; test = envoi uniquement à census_reminder_test_to ; on = envoi aux responsables de site réels. Activer progressivement : test → on.',
     'off', 1, 'migration_444')
ON DUPLICATE KEY UPDATE key_name = key_name;

INSERT INTO system_settings
    (section, key_name, label_fr, description_fr, value_text, is_active, updated_by)
VALUES
    ('fulfilment', 'census_reminder_test_to',
     'Destinataire test rappel inventaire FG',
     'Adresse e-mail destinataire lorsque census_reminder_email_mode = ''test''. Ignoré en mode ''off'' et ''on''.',
     'kouros@lanebuleuse.ch', 1, 'migration_444')
ON DUPLICATE KEY UPDATE key_name = key_name;

INSERT INTO system_settings
    (section, key_name, label_fr, description_fr, value_text, is_active, updated_by)
VALUES
    ('fulfilment', 'census_month_close_reminder_mode',
     'Mode envoi rappel clôture mensuelle',
     'off = pas d''envoi (défaut) ; test = envoi uniquement à census_reminder_test_to ; on = envoi aux managers + admins. Activer une fois le cron de clôture validé.',
     'off', 1, 'migration_444')
ON DUPLICATE KEY UPDATE key_name = key_name;
