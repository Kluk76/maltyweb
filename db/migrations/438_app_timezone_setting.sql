-- 438_app_timezone_setting.sql
-- Central display-timezone setting. The app stores timestamps in UTC (MySQL
-- canonical) and renders them in this zone via app/settings.php::display_local().
-- Idempotent: re-applying is a no-op (does not clobber an operator-edited value).
INSERT INTO system_settings
  (section, key_name, label_fr, description_fr, value_text, default_text, is_active)
VALUES
  ('general', 'app_timezone', 'Fuseau horaire d''affichage',
   'Fuseau horaire (nom IANA, ex. Europe/Zurich) utilisé pour afficher les dates/heures stockées en UTC. La base de données reste en UTC.',
   'Europe/Zurich', 'Europe/Zurich', 1)
ON DUPLICATE KEY UPDATE key_name = key_name;
