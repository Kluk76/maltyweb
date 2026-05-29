-- Migration 216: seed commissioning_settings section='heartbeat' rows.
--
-- Atom 1 (app/sb-board.php data layer) reads green_max_hours + amber_max_hours
-- via _sb_heartbeat_thresholds(), which falls back to constants 24/72 when these
-- rows are absent. This migration makes the thresholds operator-editable via the
-- standard commissioning UI without hardcoding in PHP.
--
-- Verified column names against live schema (DESCRIBE commissioning_settings):
--   id, section, key_name, label_fr, description_fr,
--   value_num, value_text, unit_fr, default_num,
--   is_active, updated_at, updated_by
--
-- Idempotent: INSERT IGNORE — safe to re-run.
-- migrate.php no-SELECT rule: no SELECT statements.

INSERT IGNORE INTO commissioning_settings
  (section, key_name, value_num, label_fr, description_fr, unit_fr, default_num, is_active, updated_by)
VALUES
  ('heartbeat', 'green_max_hours', 24,
   'Heartbeat vert (max)',
   'Heures depuis la dernière activité en-deçà desquelles le lot s''affiche en vert sur le tableau Lots en cours.',
   'h', 24, 1, 'mig-216'),

  ('heartbeat', 'amber_max_hours', 72,
   'Heartbeat ambre (max)',
   'Heures depuis la dernière activité en-deçà desquelles le lot s''affiche en ambre. Au-delà : rouge.',
   'h', 72, 1, 'mig-216');

SET @noop = 1;
