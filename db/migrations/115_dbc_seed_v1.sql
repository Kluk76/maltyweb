-- db/migrations/115_dbc_seed_v1.sql
-- What: Shippable v1 seed for all four catalog tables + dbc_catalog_version row.
--       INSERT IGNORE keyed on stable *_code so re-runs are no-ops.
-- Why:  Populates the DBCommissioning catalog with the La Neb-relevant universe
--       (plus PET/30L keg for transmissibility). Unused entries stay catalog-only
--       until another brewery commissions them.
-- Risk: INSERT IGNORE only — no UPDATE, no DELETE. Safe to re-run.
-- Rollback: DELETE FROM dbc_* WHERE catalog_version_basis=1 (if needed; no cascade risk).

-- -----------------------------------------------------------------------
-- dbc_container_types  (physical fillable container — the new dimension)
-- -----------------------------------------------------------------------
INSERT IGNORE INTO dbc_container_types
  (container_code, display_name, material, vessel_class, volume_l, hl_per_unit, run_type, is_active, sort_order)
VALUES
  ('BOT_GLASS_33', 'Bouteille verre 33cl',   'glass',     'bottle', 0.3300, 0.003300, 'bot',   1, 10),
  ('BOT_GLASS_50', 'Bouteille verre 50cl',   'glass',     'bottle', 0.5000, 0.005000, 'bot',   1, 20),
  ('BOT_PET_100',  'Bouteille PET 1L',        'pet',       'bottle', 1.0000, 0.010000, 'bot',   1, 30),
  ('CAN_ALU_33',   'Canette alu 33cl',        'aluminium', 'can',    0.3300, 0.003300, 'can33', 1, 40),
  ('CAN_ALU_50',   'Canette alu 50cl',        'aluminium', 'can',    0.5000, 0.005000, 'can',   1, 50),
  ('KEG_INOX_20',  'Fût inox 20L',            'steel',     'keg',    20.000, 0.200000, 'keg',   1, 60),
  ('KEG_INOX_30',  'Fût inox 30L',            'steel',     'keg',    30.000, 0.300000, 'keg',   1, 70),
  ('CUV_LINER',    'Cuve de service (liner)',  'other',     'liner',  NULL,   NULL,     'cuv',   1, 80),
  ('KEG_PET_20',   'Fût PET 20L',             'pet',       'keg',    20.000, 0.200000, 'keg',   0, 90);

-- -----------------------------------------------------------------------
-- dbc_packaging_format_templates  (outer-packaging templates)
-- -----------------------------------------------------------------------
INSERT IGNORE INTO dbc_packaging_format_templates
  (template_code, display_name, container_code, units_per_format, is_composite, default_run_type, is_active, sort_order)
VALUES
  -- Bottle formats
  ('SINGLE_BOT_33',   'Bouteille 33cl unitaire',               'BOT_GLASS_33', 1,    0, 'bot',   1, 10),
  ('BOX24_BOT_33',    'Caisse 24 bouteilles 33cl',             'BOT_GLASS_33', 24,   0, 'bot',   1, 20),
  ('BOX12_BOT_33',    'Caisse 12 bouteilles 33cl',             'BOT_GLASS_33', 12,   0, 'bot',   1, 30),
  ('6X4_BOT_33',      '6×4-pack carton 24×33cl',               'BOT_GLASS_33', 24,   0, 'bot',   1, 40),
  ('4PACK_BOT_33',    '4-pack lâche bouteilles 33cl',          'BOT_GLASS_33', 4,    0, 'bot',   1, 50),
  ('PAL_1027_BOT_33', 'Palette 1027×33cl',                     'BOT_GLASS_33', 1027, 0, 'bot',   1, 60),
  -- Can formats
  ('SINGLE_CAN_50',   'Canette 50cl unitaire',                 'CAN_ALU_50',   1,    0, 'can',   1, 70),
  ('BOX24_CAN_50',    'Caisse 24 canettes 50cl',               'CAN_ALU_50',   24,   0, 'can',   1, 80),
  ('BOX12_CAN_50',    'Caisse 12 canettes 50cl',               'CAN_ALU_50',   12,   0, 'can',   1, 90),
  ('6X4_CAN_50',      '6×4-pack carton 24×50cl',               'CAN_ALU_50',   24,   0, 'can',   1, 100),
  ('4PACK_CAN_50',    '4-pack lâche canettes 50cl',            'CAN_ALU_50',   4,    0, 'can',   1, 110),
  ('SINGLE_CAN_33',   'Canette 33cl unitaire',                 'CAN_ALU_33',   1,    0, 'can33', 1, 120),
  -- Keg formats
  ('KEG_20L',         'Fût 20L',                               'KEG_INOX_20',  1,    0, 'keg',   1, 130),
  ('KEG_30L',         'Fût 30L',                               'KEG_INOX_30',  1,    0, 'keg',   1, 140),
  -- Cuve / liner
  ('CUV_SERVICE',     'Cuve de service (liner)',                'CUV_LINER',    1,    0, 'cuv',   1, 150),
  -- Composite formats (container_code=NULL)
  ('COMPOSITE_MIXED', 'Assortiment mixte (composite)',          NULL,           NULL, 1, NULL,    1, 200);

-- -----------------------------------------------------------------------
-- dbc_equipment_types  (machine type templates)
-- -----------------------------------------------------------------------
INSERT IGNORE INTO dbc_equipment_types
  (equipment_code, display_name, machine_type, process_stage,
   has_throughput_hl_h, has_speed_units_h, has_temp_c, takes_containers,
   is_active, sort_order)
VALUES
  ('CENTRIFUGE',      'Centrifugeuse',             'centrifuge',   'cellar',     1, 0, 0, 0, 1, 10),
  ('KZE',             'Flash-pasteurisateur KZE',  'kze',          'cellar',     1, 0, 1, 0, 1, 20),
  ('FILLER_BOTTLE',   'Soutireuse bouteilles',      'filler_bottle','packaging',  0, 1, 0, 1, 1, 30),
  ('FILLER_CAN',      'Ligne de canettes',          'filler_can',   'packaging',  0, 1, 0, 1, 1, 40),
  ('FILLER_KEG',      'Soutireuse fûts',            'filler_keg',   'packaging',  0, 1, 0, 1, 1, 50),
  ('CARTONER',        'Encartonneuse',              'cartoner',     'packaging',  0, 0, 0, 0, 1, 60);

-- -----------------------------------------------------------------------
-- dbc_vessel_types  (process-vessel type templates)
-- -----------------------------------------------------------------------
INSERT IGNORE INTO dbc_vessel_types
  (vessel_code, display_name, process_stage, target_ref_table,
   is_numbered, default_count, is_active, sort_order)
VALUES
  ('HLT',       'Cuve eau chaude (HLT)',            'water',        'ref_brewhouse_vessels', 0, 1,    1, 10),
  ('CLT',       'Cuve eau froide (CLT)',             'water',        'ref_brewhouse_vessels', 1, 2,    1, 20),
  ('MASH',      'Cuve de maische',                  'brewhouse',    'ref_brewhouse_vessels', 0, 1,    1, 30),
  ('LAUTER',    'Cuve-filtre / lauter',             'brewhouse',    'ref_brewhouse_vessels', 0, 1,    1, 40),
  ('BUFFER',    'Cuve tampon',                      'brewhouse',    'ref_brewhouse_vessels', 0, 1,    1, 50),
  ('KETTLE',    'Cuve d\'ébullition (kettle)',       'brewhouse',    'ref_brewhouse_vessels', 0, 1,    1, 60),
  ('WHIRLPOOL', 'Whirlpool',                        'brewhouse',    'ref_brewhouse_vessels', 0, 1,    1, 70),
  ('YT',        'Cuve de levure (YT)',              'fermentation', 'ref_yt',                1, 3,    1, 80),
  ('CCT',       'Cuve de fermentation (CCT)',       'fermentation', 'ref_cct',               1, NULL, 1, 90),
  ('BBT',       'Cuve de garde (BBT)',              'maturation',   'ref_bbt',               1, NULL, 1, 100);

-- -----------------------------------------------------------------------
-- Version record
-- -----------------------------------------------------------------------
INSERT IGNORE INTO dbc_catalog_version (catalog_version, shipped_at, notes)
VALUES (1, '2026-05-24', 'Initial catalog: La Neb container universe + equipment + vessel types');
