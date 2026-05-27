-- db/migrations/184_commissioning_pertes_config.sql
-- What: Seed section='pertes' in commissioning_settings (7 rows).
--       Two HL fixed-loss constants that feed TankSimulator (COGS/WIP-critical)
--       and five %-threshold advisory rows for process-loss alerting.
--       These de-hardcode TankSimulator::RACKING_LOSS_HL (0.9) and
--       TankSimulator::PACKAGING_LOSS_HL (0.15) — values read in __construct().
--
-- Why:  Constants were hardcoded in app/tank-simulator.php and invisible to
--       operators. Moving them to commissioning_settings makes them operator-
--       tunable via Salle de controle > Pertes without touching PHP code.
--       DEFAULTS EQUAL CURRENT VALUES so sim output is byte-identical after
--       migration. The two HL rows must match TankSimulator class-const
--       fallback values exactly; any drift from 0.9000 / 0.1500 would alter
--       WIP and COGS calculations silently.
--
-- Risk: VERY LOW.
--       Pure INSERT ... SELECT ... WHERE NOT EXISTS -- idempotent (no UNIQUE on
--       (section, key_name); NOT EXISTS is the idempotency guard, cloned from
--       migration 182 section C). No ALTER. No DDL. No schema_meta rows needed
--       (no new table). commissioning_settings is classified config/allowed.
--       Re-running is a safe no-op.
--
-- Rollback:
--   DELETE FROM commissioning_settings WHERE section = 'pertes';

INSERT INTO commissioning_settings
  (section, key_name, label_fr, description_fr, value_num, unit_fr, default_num, is_active, updated_by)
SELECT
  v.section, v.key_name, v.label_fr, v.description_fr,
  v.value_num, v.unit_fr, v.default_num, 1, 'migration_184'
FROM (
  -- HL fixed-loss constants: AFFECTENT LE CALCUL COGS/WIP
  -- Ces deux valeurs sont rejouees par TankSimulator a chaque calcul de stock
  -- WIP. Toute modification change les volumes en cuve et les couts COGS.
  -- Modifier UNIQUEMENT apres validation de la valeur cible avec le brasseur.

  SELECT 'pertes' section,
         'pertes_racking_loss_hl' key_name,
         'Perte fixe au soutirage' label_fr,
         'Volume fixe perdu (HL) lors de chaque soutirage CCT->BBT. AFFECTE LE CALCUL DES COUTS (COGS/WIP) -- tout changement modifie le stock WIP simule et les couts brassage. Valider avec le brasseur avant toute modification.' description_fr,
         0.9000 value_num,
         'HL' unit_fr,
         0.9000 default_num

  UNION ALL
  SELECT 'pertes',
         'pertes_packaging_loss_hl',
         'Perte fixe au packaging',
         'Volume fixe perdu (HL) deduit de chaque run de conditionnement. AFFECTE LE CALCUL DES COUTS (COGS/WIP) -- tout changement modifie le stock BBT simule et les couts de conditionnement. Valider avec le brasseur avant toute modification.',
         0.1500, 'HL', 0.1500

  -- % advisory thresholds: informatifs seulement
  -- Ces seuils declenchent des alertes visuelles dans la Salle de controle.
  -- Ils n'entrent PAS dans les calculs COGS/WIP.

  UNION ALL
  SELECT 'pertes',
         'pertes_rack_warn_pct',
         'Palier alerte perte -- soutirage',
         'Seuil alerte (%) pour la perte au soutirage (volume perdu / volume soutire). Purement informatif -- ne modifie pas les calculs COGS/WIP.',
         2.0000, '%', 2.0000

  UNION ALL
  SELECT 'pertes',
         'pertes_packaging_warn_pct',
         'Palier alerte perte -- packaging',
         'Seuil alerte (%) pour la perte au packaging (volume perdu / volume conditionne). Purement informatif -- ne modifie pas les calculs COGS/WIP.',
         1.0000, '%', 1.0000

  UNION ALL
  SELECT 'pertes',
         'pertes_brewing_warn_pct',
         'Palier alerte perte -- brassage',
         'Seuil alerte (%) pour la perte au brassage (volume pre-fermentation / volume nominal). Purement informatif -- ne modifie pas les calculs COGS/WIP.',
         5.0000, '%', 5.0000

  UNION ALL
  SELECT 'pertes',
         'pertes_total_effectif_warn_pct',
         'Palier alerte perte totale (vs cast-out)',
         'Seuil alerte (%) pour la perte totale effective (volume package / volume mesure apres refroidissement). Purement informatif -- ne modifie pas les calculs COGS/WIP.',
         18.0000, '%', 18.0000

  UNION ALL
  SELECT 'pertes',
         'pertes_total_nominal_warn_pct',
         'Palier alerte perte totale (vs nominal)',
         'Seuil alerte (%) pour la perte totale nominale (volume package / volume nominal brasse). Purement informatif -- ne modifie pas les calculs COGS/WIP.',
         10.0000, '%', 10.0000
) v
WHERE NOT EXISTS (
  SELECT 1 FROM commissioning_settings cs
   WHERE cs.section  = v.section
     AND cs.key_name = v.key_name
);
