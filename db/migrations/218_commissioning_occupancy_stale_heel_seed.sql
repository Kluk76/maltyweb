-- db/migrations/218_commissioning_occupancy_stale_heel_seed.sql
--
-- What: Seed commissioning_settings row for the occupancy stale-heel age gate.
--
-- Why : sb_observed_occupancy() (app/sb-board.php, Atom 12) must distinguish
--       live occupancies from abandoned heels. CCT-5 Speakeasy#57 (racked
--       2025-10-20, 223 d) is a canonical example: the batch was never fully
--       packaged out but the heel is clearly abandoned. A configurable threshold
--       (default 90 days) gates whether an occupancy row is "ACTIVE" or a
--       "SUPPRESSED HEEL" that renders as a muted badge rather than a full fill.
--
--       The threshold is read via _sb_occupancy_threshold(PDO) in the same way
--       _sb_heartbeat_thresholds() reads 'heartbeat' rows. Falls back to 90 when
--       the row is missing.
--
-- Column names verified against live schema (DESCRIBE commissioning_settings):
--   id, section, key_name, label_fr, description_fr,
--   value_num, value_text, unit_fr, default_num, is_active, updated_at, updated_by
--
-- Idempotent: INSERT ... ON DUPLICATE KEY UPDATE — the UNIQUE KEY
--   uk_commissioning_section_key (section, key_name) ensures re-applying is safe.
--   migrate.php no-SELECT rule: no bare SELECT statements.

INSERT INTO commissioning_settings
  (section, key_name, label_fr, description_fr, value_num, value_text, unit_fr, default_num, is_active, updated_by)
VALUES
  ('occupancy', 'stale_heel_days',
   'Cuve — seuil heel résiduel (jours)',
   'Nombre de jours depuis le dernier soutirage en-deçà desquels une cuve est considérée en occupation active. Au-delà de ce seuil, le fond résiduel est signalé comme heel résiduel (affiché en mode atténué sur le tableau Lots en cours) et exclu des occupations actives.',
   90, NULL, 'j', 90, 1, 'mig-218')
ON DUPLICATE KEY UPDATE
  label_fr       = VALUES(label_fr),
  description_fr = VALUES(description_fr),
  unit_fr        = VALUES(unit_fr),
  default_num    = VALUES(default_num),
  is_active      = VALUES(is_active),
  updated_by     = VALUES(updated_by);

SET @noop = 1;
