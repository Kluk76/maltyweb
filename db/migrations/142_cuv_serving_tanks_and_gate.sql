-- db/migrations/142_cuv_serving_tanks_and_gate.sql
-- What: Gate format V (cuve de service) into the packaging activation UI by
--       commissioning a filler_cuv equipment type + machine + container link.
--       Create ref_serving_tanks capacity registry (in-house only, client deferred).
-- Why: EMBV/MOOV/ZEPV SKUs are live but format V was gated out (no filler_cuv
--      row in ref_filler_containers). This migration wires the full gate chain:
--      filler_cuv machine → CUV_LINER container → CUV_SERVICE template → format V.
--      The Salle des Machines "Cuves de service" card reads ref_serving_tanks.
-- Risk: LOW — new rows only, one ENUM extension (MODIFY preserves NOT NULL + existing values).
-- Idempotency: steps 1–4 were applied in a prior partial run (connection drop mid-file).
--   Each step is guarded so re-running is a no-op for already-applied steps.
-- Rollback:
--   ALTER TABLE ref_process_machines MODIFY COLUMN machine_type
--     ENUM('centrifuge','kze','filler_bottle','filler_can','filler_keg','cartoner') NOT NULL;
--   DELETE FROM ref_filler_containers WHERE container_id = 8;
--   DELETE FROM ref_process_machines WHERE machine_type = 'filler_cuv';
--   DELETE FROM dbc_equipment_types WHERE equipment_code = 'FILLER_CUV';
--   DELETE FROM ref_serving_tanks;  -- then DROP TABLE
--   DROP TABLE IF EXISTS ref_serving_tanks;
--   DELETE FROM schema_meta WHERE table_name = 'ref_serving_tanks';

-- ── Step 1: Extend machine_type ENUM (append filler_cuv, preserve order + NOT NULL) ──
-- MODIFY to the same full ENUM list (including filler_cuv) is a safe no-op if already applied.
ALTER TABLE ref_process_machines
  MODIFY COLUMN machine_type
    ENUM('centrifuge','kze','filler_bottle','filler_can','filler_keg','cartoner','filler_cuv')
    NOT NULL;

-- ── Step 2: INSERT dbc_equipment_types row ──────────────────────────────────────────
-- sort_order=55 places it between FILLER_KEG(50) and CARTONER(60)
-- has_throughput_hl_h=0, has_speed_units_h=0: no fill-rate data — refuse-don't-invent
-- takes_containers=1: required for the gate JOIN to work
-- INSERT IGNORE: equipment_code has a UNIQUE key — duplicate is silently skipped.
INSERT IGNORE INTO dbc_equipment_types
  (equipment_code, display_name, machine_type, process_stage,
   takes_containers, has_throughput_hl_h, has_speed_units_h, has_temp_c,
   is_active, sort_order)
VALUES
  ('FILLER_CUV', 'Tireuse cuves de service', 'filler_cuv', 'packaging',
   1, 0, 0, 0,
   1, 55);

-- ── Step 3: Seed ONE filler_cuv machine in ref_process_machines ─────────────────────
-- catalog_id via subselect (not hardcoded) to survive re-apply after manual rollback.
-- INSERT IGNORE: UNIQUE on (machine_type, name, effective_until) — duplicate skipped.
INSERT IGNORE INTO ref_process_machines
  (machine_type, name, is_active, catalog_id,
   throughput_hl_h, speed_units_h, temp_c,
   effective_from, effective_until, notes)
VALUES
  ('filler_cuv', 'Tireuse cuves de service', 1,
   (SELECT id FROM dbc_equipment_types WHERE equipment_code = 'FILLER_CUV'),
   NULL, NULL, NULL,
   '1970-01-01 00:00:00', '9999-12-31 23:59:59',
   'seed-142 — in-house serving-tank filler');

-- ── Step 4: Seed ref_filler_containers link (THE gate-in row) ───────────────────────
-- container_id=8 = CUV_LINER (verified live 2026-05-25)
-- This row is what makes the gate query yield format V (id=18):
--   ref_filler_containers → dbc_container_types(id=8, container_code='CUV_LINER')
--     → dbc_packaging_format_templates(id=15, container_code='CUV_LINER')
--       → ref_packaging_formats(id=18, catalog_id=15, format_code='V')
-- INSERT IGNORE: UNIQUE on (machine_id, container_id, effective_until) — duplicate skipped.
INSERT IGNORE INTO ref_filler_containers
  (machine_id, container_id, is_active, effective_from, effective_until)
SELECT
  (SELECT id FROM ref_process_machines WHERE machine_type = 'filler_cuv' LIMIT 1),
  8,
  1,
  '1970-01-01 00:00:00',
  '9999-12-31 23:59:59'
WHERE NOT EXISTS (
  SELECT 1 FROM ref_filler_containers WHERE container_id = 8
);

-- ── Step 5: CREATE TABLE ref_serving_tanks ──────────────────────────────────────────
-- Mirror ref_cct shape. location discriminator is MANDATORY (client tanks deferred).
-- No FK — this is a capacity registry, not in the gate chain.
-- IF NOT EXISTS makes this a no-op on re-run.
CREATE TABLE IF NOT EXISTS ref_serving_tanks (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  number       INT UNSIGNED NOT NULL,
  capacity_hl  DECIMAL(6,2) NOT NULL,
  location     ENUM('in_house','client') NOT NULL DEFAULT 'in_house',
  status       ENUM('active','maintenance','retired') NOT NULL DEFAULT 'active',
  notes        TEXT NULL,
  created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Step 6: schema_meta row ──────────────────────────────────────────────────────────
-- INSERT IGNORE: schema_meta.table_name has a UNIQUE key — duplicate skipped.
INSERT IGNORE INTO schema_meta
  (table_name, table_class, writer_script, corrections_policy, upstream_hint, notes)
VALUES
  ('ref_serving_tanks', 'reference', 'manual/web (admin)', 'allowed', NULL,
   'In-house & client transport/event serving tanks that fill format V (cuv). Capacity card on Salle des Machines; NOT in the gate chain.');

-- ── Step 7: Seed 8 in-house tanks (5×5hl + 2×10hl + 1×30hl, numbered 1–8) ─────────
-- Client tanks (6×10hl + 3×5hl) are DEFERRED — use location='client' when added.
-- Guard: only seed when the table is empty to prevent duplicate seeds on re-run.
INSERT INTO ref_serving_tanks (number, capacity_hl, location, status)
SELECT v.number, v.capacity_hl, 'in_house', 'active'
FROM (
  SELECT 1 AS number, 5.00  AS capacity_hl UNION ALL
  SELECT 2,           5.00                 UNION ALL
  SELECT 3,           5.00                 UNION ALL
  SELECT 4,           5.00                 UNION ALL
  SELECT 5,           5.00                 UNION ALL
  SELECT 6,           10.00                UNION ALL
  SELECT 7,           10.00                UNION ALL
  SELECT 8,           30.00
) AS v
WHERE NOT EXISTS (SELECT 1 FROM ref_serving_tanks);
