-- 060_ref_units.sql
--
-- Unit registry: canonical dimension + conversion factor table.
-- to_base_factor converts to the smallest base unit of each dimension
-- (g for mass, mL for volume, 1 for count/time/etc).
--
-- Apply:
--   ssh maltyweb 'cd /var/www/maltytask && sudo -u www-data php scripts/migrate.php'

CREATE TABLE IF NOT EXISTS ref_units (
  code            VARCHAR(16) NOT NULL PRIMARY KEY,
  dimension       ENUM('mass','volume','count','time','length','other') NOT NULL,
  to_base_factor  DECIMAL(18,9) NOT NULL,
  display_label   VARCHAR(64) NOT NULL,
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO ref_units (code, dimension, to_base_factor, display_label) VALUES
  ('g',        'mass',   1,       'gramme'),
  ('kg',       'mass',   1000,    'kilogramme'),
  ('ton',      'mass',   1000000, 'tonne'),
  ('mL',       'volume', 1,       'millilitre'),
  ('L',        'volume', 1000,    'litre'),
  ('hL',       'volume', 100000,  'hectolitre'),
  ('unit',     'count',  1,       'unité'),
  ('UN',       'count',  1,       'unité'),
  ('PCE',      'count',  1,       'pièce'),
  ('piece',    'count',  1,       'pièce'),
  ('pair',     'count',  2,       'paire'),
  ('lot',      'count',  1,       'lot'),
  ('shipment', 'count',  1,       'envoi'),
  ('voyage',   'count',  1,       'voyage'),
  ('exchange', 'count',  1,       'échange'),
  ('test',     'count',  1,       'analyse'),
  ('month',    'time',   1,       'mois'),
  ('day',      'time',   1,       'jour'),
  ('hour',     'time',   1,       'heure');
