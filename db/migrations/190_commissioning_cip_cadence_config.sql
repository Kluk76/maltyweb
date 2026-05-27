-- db/migrations/190_commissioning_cip_cadence_config.sql
-- What: Seed section='cip_cadence' in commissioning_settings (4 rows).
--       Two integer threshold constants (racks until acid/full CIP recommended)
--       and two CSV columns mapping ref_cip_types ids onto reset classes
--       (acid-reset vs full-reset). These configure the event-sourced
--       per-BBT CIP cadence resolver (C6b, deferred) and are edited
--       via the new cadence panel inside the existing SDC #sec-cip section.
--
-- Why:  The BBT-CIP cadence policy (6 racks -> acid CIP; 6 more -> full CIP)
--       must be operator-configurable and must know which CIP types count as
--       an acid or full reset. Hardcoding these values would duplicate the
--       ref_cip_types master-data into code (PM ruling WS4-R(b)). The reset-
--       class mapping references ref_cip_types by id (verified live: 1=Soude,
--       2=Acide, 3=Full CIP, 4=Full CIP + rinser). Soude(1) is left out of
--       both defaults by design -- operator decides its reset class.
--
-- Risk: VERY LOW.
--       Pure INSERT ... SELECT ... WHERE NOT EXISTS -- idempotent (no UNIQUE on
--       (section, key_name); NOT EXISTS is the idempotency guard, cloned from
--       migration 184 section='pertes' pattern). No ALTER. No DDL. No
--       schema_meta rows needed (no new table). commissioning_settings is
--       classified config/allowed. Re-running is a safe no-op.
--
-- Defaults (current intended behavior):
--   cip_cadence_acid_after:        6  racks since last acid-or-fuller reset
--   cip_cadence_full_after:        6  additional racks since last acid reset
--   cip_cadence_acid_reset_types:  '2'   (Acide only; Soude excluded by default)
--   cip_cadence_full_reset_types:  '3,4' (Full CIP + Full CIP + rinser)
--
-- Rollback:
--   DELETE FROM commissioning_settings WHERE section = 'cip_cadence';

INSERT INTO commissioning_settings
  (section, key_name, label_fr, description_fr, value_num, value_text, unit_fr, default_num, is_active, updated_by)
SELECT
  v.section, v.key_name, v.label_fr, v.description_fr,
  v.value_num, v.value_text, v.unit_fr, v.default_num, 1, 'migration_190'
FROM (

  -- Threshold: number of racks without acid-or-fuller BBT CIP before soft-flag fires
  SELECT 'cip_cadence'     section,
         'cip_cadence_acid_after' key_name,
         'Racks avant CIP acide recommande' label_fr,
         'Nombre de soutirages dans un BBT sans CIP acide (ou superieur) avant que le cadenceur recommande un CIP acide. Informatif -- ne bloque pas la saisie.' description_fr,
         6.0000            value_num,
         NULL              value_text,
         'racks'           unit_fr,
         6.0000            default_num

  UNION ALL
  -- Threshold: additional racks after acid before full CIP is recommended
  SELECT 'cip_cadence',
         'cip_cadence_full_after',
         'Racks apres acide avant CIP complet recommande',
         'Nombre de soutirages supplementaires apres le dernier CIP acide avant que le cadenceur recommande un CIP complet. Informatif -- ne bloque pas la saisie.',
         6.0000, NULL, 'racks', 6.0000

  UNION ALL
  -- CSV of ref_cip_types.id that count as an acid reset (resets acid counter)
  -- Default: '2' = Acide only. Soude(1) excluded -- operator classifies it.
  -- Full-reset types also reset the acid counter by implication, but they are
  -- stored separately in cip_cadence_full_reset_types (the resolver checks
  -- full first, then acid, so Soude membership in acid_reset is advisory).
  SELECT 'cip_cadence',
         'cip_cadence_acid_reset_types',
         'Types CIP comptant comme remise a zero acide',
         'Liste des identifiants ref_cip_types (CSV, ex: 2) dont la presence dans bd_cip_events remet a zero le compteur de racks depuis le dernier CIP acide. Soude(1) exclu par defaut -- a classer selon politique brasserie.',
         NULL, '2', NULL, NULL

  UNION ALL
  -- CSV of ref_cip_types.id that count as a full reset (resets both counters)
  -- Default: '3,4' = Full CIP + Full CIP + rinser.
  SELECT 'cip_cadence',
         'cip_cadence_full_reset_types',
         'Types CIP comptant comme remise a zero complete',
         'Liste des identifiants ref_cip_types (CSV, ex: 3,4) dont la presence dans bd_cip_events remet a zero les deux compteurs (acide ET complet). Un CIP complet implique aussi une remise a zero acide -- le resolveur verifie full en premier.',
         NULL, '3,4', NULL, NULL

) v
WHERE NOT EXISTS (
  SELECT 1 FROM commissioning_settings cs
   WHERE cs.section  = v.section
     AND cs.key_name = v.key_name
);
