-- db/migrations/140_seed_activated_packaging_equipment.sql
-- What: Seeds La Nébuleuse's activated packaging/cellar equipment configuration
-- Why:  Operator-stated canonical config 2026-05-25 — bottling, canning, kegging lines
--       + cellar process machines (centrifuge, KZE).  Provides the data that
--       salle-des-machines.php reads to wire the Conditionnement panel.
-- Risk: INSERT IGNORE / ON DUPLICATE KEY — fully idempotent; safe to re-run.
-- Rollback: DELETE FROM ref_process_machines WHERE machine_type IN
--           ('filler_bottle','filler_can','filler_keg','centrifuge','kze')
--           AND notes LIKE '%seed-140%';
--           DELETE FROM ref_filler_containers WHERE created_at >= '2026-05-25';
--
-- FK types verified: ref_process_machines.id INT UNSIGNED,
--                    dbc_equipment_types.id   INT UNSIGNED (catalog_id FK),
--                    dbc_container_types.id   INT UNSIGNED (container_id FK)
--
-- Note: dbc_equipment_types codes from verified catalog data:
--   id=1 CENTRIFUGE, id=2 KZE, id=3 FILLER_BOTTLE, id=4 FILLER_CAN,
--   id=5 FILLER_KEG, id=6 CARTONER
-- dbc_container_types codes:
--   id=1 BOT_GLASS_33, id=4 CAN_ALU_33, id=5 CAN_ALU_50, id=6 KEG_INOX_20

-- ── 1. Process machines ────────────────────────────────────────────────────
-- Using INSERT IGNORE so re-runs are no-ops (unique key on machine_type alone
-- is not present, but we guard with a SELECT pre-check comment; in practice the
-- table is seeded once and managed via UI after that).

INSERT INTO ref_process_machines
    (machine_type, name, throughput_hl_h, speed_units_h, temp_c, is_active, catalog_id, notes,
     effective_from, effective_until)
SELECT 'filler_bottle', 'Soutireuse bouteilles', NULL, 2000, NULL, 1, 3,
       'seed-140 — operator config 2026-05-25',
       '1970-01-01 00:00:00', '9999-12-31 23:59:59'
WHERE NOT EXISTS (
    SELECT 1 FROM ref_process_machines WHERE machine_type = 'filler_bottle'
);

INSERT INTO ref_process_machines
    (machine_type, name, throughput_hl_h, speed_units_h, temp_c, is_active, catalog_id, notes,
     effective_from, effective_until)
SELECT 'filler_can', 'Ligne de canettes', NULL, 3000, NULL, 1, 4,
       'seed-140 — operator config 2026-05-25',
       '1970-01-01 00:00:00', '9999-12-31 23:59:59'
WHERE NOT EXISTS (
    SELECT 1 FROM ref_process_machines WHERE machine_type = 'filler_can'
);

INSERT INTO ref_process_machines
    (machine_type, name, throughput_hl_h, speed_units_h, temp_c, is_active, catalog_id, notes,
     effective_from, effective_until)
SELECT 'filler_keg', 'Soutireuse fûts', NULL, 40, NULL, 1, 5,
       'seed-140 — operator config 2026-05-25',
       '1970-01-01 00:00:00', '9999-12-31 23:59:59'
WHERE NOT EXISTS (
    SELECT 1 FROM ref_process_machines WHERE machine_type = 'filler_keg'
);

INSERT INTO ref_process_machines
    (machine_type, name, throughput_hl_h, speed_units_h, temp_c, is_active, catalog_id, notes,
     effective_from, effective_until)
SELECT 'cartoner', 'Encartonneuse', NULL, NULL, NULL, 1, 6,
       'seed-140 — operator config 2026-05-25',
       '1970-01-01 00:00:00', '9999-12-31 23:59:59'
WHERE NOT EXISTS (
    SELECT 1 FROM ref_process_machines WHERE machine_type = 'cartoner'
);

INSERT INTO ref_process_machines
    (machine_type, name, throughput_hl_h, speed_units_h, temp_c, is_active, catalog_id, notes,
     effective_from, effective_until)
SELECT 'centrifuge', 'Centrifugeuse', 30.00, NULL, NULL, 1, 1,
       'seed-140 — operator config 2026-05-25',
       '1970-01-01 00:00:00', '9999-12-31 23:59:59'
WHERE NOT EXISTS (
    SELECT 1 FROM ref_process_machines WHERE machine_type = 'centrifuge'
);

INSERT INTO ref_process_machines
    (machine_type, name, throughput_hl_h, speed_units_h, temp_c, is_active, catalog_id, notes,
     effective_from, effective_until)
SELECT 'kze', 'Flash-pasteurisateur KZE', 20.00, NULL, 72.00, 1, 2,
       'seed-140 — operator config 2026-05-25',
       '1970-01-01 00:00:00', '9999-12-31 23:59:59'
WHERE NOT EXISTS (
    SELECT 1 FROM ref_process_machines WHERE machine_type = 'kze'
);

-- ── 2. Filler ↔ container links ────────────────────────────────────────────
-- Bottling line: BOT_GLASS_33 (container_id=1)
INSERT INTO ref_filler_containers
    (machine_id, container_id, is_active, effective_from, effective_until)
SELECT pm.id, ct.id, 1, '1970-01-01 00:00:00', '9999-12-31 23:59:59'
FROM ref_process_machines pm
JOIN dbc_container_types  ct ON ct.container_code = 'BOT_GLASS_33'
WHERE pm.machine_type = 'filler_bottle'
  AND NOT EXISTS (
    SELECT 1 FROM ref_filler_containers fc
     WHERE fc.machine_id   = pm.id
       AND fc.container_id = ct.id
  );

-- Canning line: CAN_ALU_33 (container_id=4)
INSERT INTO ref_filler_containers
    (machine_id, container_id, is_active, effective_from, effective_until)
SELECT pm.id, ct.id, 1, '1970-01-01 00:00:00', '9999-12-31 23:59:59'
FROM ref_process_machines pm
JOIN dbc_container_types  ct ON ct.container_code = 'CAN_ALU_33'
WHERE pm.machine_type = 'filler_can'
  AND NOT EXISTS (
    SELECT 1 FROM ref_filler_containers fc
     WHERE fc.machine_id   = pm.id
       AND fc.container_id = ct.id
  );

-- Canning line: CAN_ALU_50 (container_id=5)
INSERT INTO ref_filler_containers
    (machine_id, container_id, is_active, effective_from, effective_until)
SELECT pm.id, ct.id, 1, '1970-01-01 00:00:00', '9999-12-31 23:59:59'
FROM ref_process_machines pm
JOIN dbc_container_types  ct ON ct.container_code = 'CAN_ALU_50'
WHERE pm.machine_type = 'filler_can'
  AND NOT EXISTS (
    SELECT 1 FROM ref_filler_containers fc
     WHERE fc.machine_id   = pm.id
       AND fc.container_id = ct.id
  );

-- Kegging line: KEG_INOX_20 (container_id=6)
INSERT INTO ref_filler_containers
    (machine_id, container_id, is_active, effective_from, effective_until)
SELECT pm.id, ct.id, 1, '1970-01-01 00:00:00', '9999-12-31 23:59:59'
FROM ref_process_machines pm
JOIN dbc_container_types  ct ON ct.container_code = 'KEG_INOX_20'
WHERE pm.machine_type = 'filler_keg'
  AND NOT EXISTS (
    SELECT 1 FROM ref_filler_containers fc
     WHERE fc.machine_id   = pm.id
       AND fc.container_id = ct.id
  );

-- ── 3. schema_meta row ────────────────────────────────────────────────────
-- ref_process_machines and ref_filler_containers were created by earlier
-- migrations; their schema_meta rows should already exist.  This is a
-- no-op guard in case they were missed.
INSERT IGNORE INTO schema_meta
    (table_name, table_class, corrections_policy, writer_script, upstream_hint)
VALUES
    ('ref_process_machines',  'reference', 'allowed', 'salle-des-machines.php', 'Managed via Salle des Machines UI'),
    ('ref_filler_containers', 'reference', 'allowed', 'salle-des-machines.php', 'Managed via Salle des Machines UI');
