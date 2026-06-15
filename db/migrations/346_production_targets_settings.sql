-- Migration 346: seed system_settings rows for section='production_targets'
-- Idempotent via INSERT IGNORE (UNIQUE on section+key_name).
-- NO ADD COLUMN IF NOT EXISTS (MySQL 8, not MariaDB).

INSERT IGNORE INTO system_settings
    (section, key_name, label_fr, description_fr, value_num, unit_fr, default_num, updated_by)
VALUES
    ('production_targets', 'wort_hl_year',      'Objectif moût annuel',                   'Volume de moût total cible pour l''année civile.',                                                                   16700, 'hl',       16700, 'mig346'),
    ('production_targets', 'wort_brews_year',   'Objectif brassins annuel',               'Nombre de brassins cibles pour l''année civile.',                                                                     528,   'brassins',  528,   'mig346'),
    ('production_targets', 'operating_weeks',   'Semaines de production / an',            'Nombre de semaines de production effectives (utilisé pour calculer les objectifs hebdomadaires).',                    48,    'semaines',  48,    'mig346'),
    ('production_targets', 'operating_months',  'Mois de production / an',                'Nombre de mois de production (utilisé pour calculer les objectifs mensuels).',                                        12,    'mois',      12,    'mig346'),
    ('production_targets', 'pkg_keg_hl_year',   'Objectif fûts annuel',                   'Volume de conditionnement fûts cible pour l''année civile.',                                                          7920,  'hl',       7920,  'mig346'),
    ('production_targets', 'pkg_bottle_hl_year','Objectif bouteilles annuel',             'Volume de conditionnement bouteilles cible pour l''année civile.',                                                    4620,  'hl',       4620,  'mig346'),
    ('production_targets', 'pkg_can_hl_year',   'Objectif canettes annuel',               'Volume de conditionnement canettes cible pour l''année civile.',                                                      1008,  'hl',       1008,  'mig346');
