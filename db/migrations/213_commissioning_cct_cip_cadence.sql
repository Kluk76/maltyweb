-- 213_commissioning_cct_cip_cadence.sql
-- Adds CCT CIP cadence threshold keys and a fermenting purge-cadence key to
-- commissioning_settings. INSERT-only (no ALTER). Idempotent via INSERT IGNORE.
--
-- CCT keys (section='cip_cadence') are parallel to the existing BBT rack-count
-- keys but use a days-since-last-CIP metric (simpler for fermentation context).
-- Fermenting key (section='fermenting_cadence') is exposed here for P-C wiring
-- of the in-progress purge-cadence check.
--
-- Verified column names against live schema:
--   commissioning_settings(id, section, key_name, label_fr, description_fr,
--                          value_num, value_text, unit_fr, default_num,
--                          is_active, updated_at, updated_by)
--
-- migrate.php no-SELECT rule: no SELECT statements; PDO::exec safe.
SET @noop = 1;

INSERT IGNORE INTO commissioning_settings
  (section, key_name, label_fr, description_fr, value_num, unit_fr, default_num, is_active, updated_by)
VALUES
  ('cip_cadence', 'cct_max_days_since_cip',
   'CCT — jours max entre CIP (blocage)',
   'Nombre de jours depuis le dernier CIP CCT au-delà duquel le démarrage de fermentation est bloqué (rouge).',
   14, 'jours', 14, 1, 'migration'),

  ('cip_cadence', 'cct_warn_days_since_cip',
   'CCT — jours entre CIP (avertissement)',
   'Nombre de jours depuis le dernier CIP CCT à partir duquel le pare-feu affiche un avertissement jaune.',
   10, 'jours', 10, 1, 'migration'),

  ('fermenting_cadence', 'purge_after_days',
   'Fermentation — purge CO₂ recommandée après N jours',
   'Nombre de jours sans purge CO₂ après lesquels une alerte est levée en cours de fermentation (P-C wiring).',
   3, 'jours', 3, 1, 'migration');
