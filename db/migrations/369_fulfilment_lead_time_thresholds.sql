-- 369_fulfilment_lead_time_thresholds.sql
-- Seed system_settings thresholds for the hors-process lead-time badge on the
-- Commandes board. No new table, no schema_meta row. Idempotent. No bare SELECT.

INSERT INTO system_settings (section, key_name, label_fr, description_fr, value_num, unit_fr, is_active)
VALUES (
  'fulfilment',
  'order_lead_time_critical_days',
  'Seuil commande critique (jours)',
  'Lead de traitement en dessous duquel une commande est critique (jour même / sous 24h).',
  1.0000,
  'jours',
  1
)
ON DUPLICATE KEY UPDATE updated_at = updated_at;

INSERT INTO system_settings (section, key_name, label_fr, description_fr, value_num, unit_fr, is_active)
VALUES (
  'fulfilment',
  'order_lead_time_warn_days',
  'Seuil commande tardive (jours)',
  'Lead en dessous duquel une commande est tardive (sous 48h).',
  2.0000,
  'jours',
  1
)
ON DUPLICATE KEY UPDATE updated_at = updated_at;
