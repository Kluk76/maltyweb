-- 404_ref_water_parameters.sql
-- Reference lookup: water quality parameters with regulatory limits for HACCP/QA panel.
-- NON-FISCAL — never feeds COGS/stock/WAC/BOM/beer-tax.
-- limit_min/limit_max left NULL where OPBD value is unconfirmed; limit_basis carries citation.
-- Micro presence/absence params use limit_operator='presence_absence' (conformity derived without a numeric threshold).

CREATE TABLE ref_water_parameters (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  code           VARCHAR(32)  NOT NULL,
  label          VARCHAR(190) NOT NULL,
  unit           VARCHAR(32)  NULL,
  limit_operator ENUM('lte','gte','range','presence_absence') NOT NULL DEFAULT 'lte',
  limit_min      DECIMAL(12,4) NULL,
  limit_max      DECIMAL(12,4) NULL,
  limit_basis    VARCHAR(255) NULL,
  sort_order     INT          NOT NULL DEFAULT 0,
  is_active      TINYINT(1)   NOT NULL DEFAULT 1,
  created_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_wp_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO ref_water_parameters
  (code, label, unit, limit_operator, limit_min, limit_max, limit_basis, sort_order)
VALUES
  ('ecoli',         'E. coli',
   'UFC/100 mL', 'presence_absence', NULL, NULL,
   'OPBD — absence dans 100 mL', 1),

  ('enterocoques',  'Entérocoques intestinaux',
   'UFC/100 mL', 'presence_absence', NULL, NULL,
   'OPBD — absence dans 100 mL', 2),

  ('aerobies',      'Germes aérobies mésophiles (20–37 °C)',
   'UFC/mL', 'lte', NULL, NULL,
   'OPBD valeur de tolérance — à confirmer (annexe OPBD)', 3),

  ('pseudomonas',   'Pseudomonas aeruginosa',
   'UFC/100 mL', 'presence_absence', NULL, NULL,
   'Bonne pratique — indicateur de biofilm (absence visée)', 4),

  ('legionella',    'Legionella spp.',
   'UFC/L', 'lte', NULL, NULL,
   'Recommandations OSAV/OPBD — à confirmer ; si stockage/eau tiède', 5),

  ('nitrate',       'Nitrate',
   'mg/L', 'lte', NULL, NULL,
   'OPBD — valeur à confirmer', 6),

  ('durete',        'Dureté totale',
   '°fH', 'lte', NULL, NULL,
   'Paramètre de procédé (non sanitaire) — suivi', 7),

  ('chlore',        'Chlore résiduel libre',
   'mg/L', 'lte', NULL, NULL,
   'OPBD — pertinent en sortie de filtre charbon — à confirmer', 8),

  ('conductivite',  'Conductivité',
   'µS/cm', 'range', NULL, NULL,
   'Valeur de référence — détection de dérive', 9),

  ('ph',            'pH',
   '', 'range', NULL, NULL,
   'Plage de référence eau potable — à confirmer (OPBD)', 10);

INSERT INTO schema_meta
  (table_name, table_class, corrections_policy, writer_script, notes)
VALUES
  ('ref_water_parameters', 'reference', 'allowed',
   '/modules/qa.php (seed) + admin',
   'Paramètres d''analyse d''eau + limites OPBD (certaines à confirmer).');
