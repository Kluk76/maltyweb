-- ============================================================================
-- Migration 237: Packaging clients normalisation (cuve-de-service clients)
--
-- WHAT:
--   1. CREATE TABLE ref_packaging_clients — reference table for the real
--      venues/events that receive cuve-de-service (serving-tank) deliveries.
--      These are distinct from ref_clients (contract-brewer companies).
--   2. Insert schema_meta classification row.
--   3. Seed 17 clients from live bd_packaging_v2.keg_client_delivered distinct
--      values, ranked by frequency (sort_order = rank × 10).
--   4. Drop the mis-wired FK  fk_bdpv2_client → ref_clients.id  from
--      bd_packaging_v2.client_fk.
--   5. Re-add FK  fk_bdpv2_pkg_client → ref_packaging_clients.id  on the
--      SAME column client_fk (column name unchanged — minimise blast radius).
--   6. Backfill client_fk on cuv rows only: join on TRIM(keg_client_delivered).
--      Non-cuv rows are left NULL (deliberate — P1 session-bleed, not real cuv
--      clients; do not promote).  keg_client_delivered is kept as provenance.
--
-- WHY:
--   bd_packaging_v2.client_fk was wired to ref_clients (contract-brewer list,
--   16 rows: BLZ Company, BadFish, Chien Bleu …) in error.  The REAL cuve
--   clients are local venues / festivals, hard-typed as free-text in
--   keg_client_delivered.  This migration creates the correct reference table,
--   rewires the FK, and backfills historical cuv rows.
--
-- ROLLBACK:
--   1. ALTER TABLE bd_packaging_v2 DROP FOREIGN KEY fk_bdpv2_pkg_client;
--   2. ALTER TABLE bd_packaging_v2 ADD CONSTRAINT fk_bdpv2_client
--        FOREIGN KEY (client_fk) REFERENCES ref_clients(id) ON DELETE SET NULL;
--   3. DELETE FROM schema_meta WHERE table_name = 'ref_packaging_clients';
--   4. DROP TABLE ref_packaging_clients;
--   (client_fk values set by step 6 above become orphaned FKs after step 2,
--    so NULL them first:
--      UPDATE bd_packaging_v2 SET client_fk = NULL WHERE client_fk IS NOT NULL;
--    before re-adding the old FK.)
-- ============================================================================

-- ── 1. Create reference table ─────────────────────────────────────────────────
CREATE TABLE ref_packaging_clients (
  `id`         INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  `name`       VARCHAR(128)    NOT NULL COMMENT 'Venue / event name as the operator types it (accented French)',
  `is_active`  TINYINT(1)      NOT NULL DEFAULT 1,
  `sort_order` INT             NOT NULL DEFAULT 0 COMMENT 'Display order in form dropdown; lower = first',
  `notes`      VARCHAR(255)    NULL,
  `updated_by` VARCHAR(64)     NULL     COMMENT 'Username of last editor (informational only)',
  `created_at` TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ref_packaging_clients_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Reference list of cuve-de-service delivery clients (venues, festivals). Writer: reglages-generaux.php';

-- ── 2. schema_meta classification ────────────────────────────────────────────
INSERT INTO schema_meta
  (table_name, table_class, corrections_policy, writer_script, upstream_hint)
VALUES
  ('ref_packaging_clients', 'reference', 'allowed',
   'public/modules/reglages-generaux.php',
   'Edit via Réglages généraux → Clients packaging. This is the canonical list of cuve-de-service venues/events.');

-- ── 3. Seed 17 clients (byte-exact names from live keg_client_delivered) ─────
-- sort_order = rank by descending frequency × 10
-- Les Arches 97 → 10, Jetée de la Compagnie 70 → 20, Festival de la Cité 23 → 30,
-- Les Docks 22 → 40, Festi'Cheyr 10 → 50, Fêtes de Genève 7 → 60,
-- Rincette 6 → 70, Wine Night 5 → 80, People in the City 3 → 90,
-- Fête de la Musique 3 → 100, 20km Lausanne 3 → 110,
-- ParaBôle 2 → 120, Digital Dreams 1 → 130,
-- Union Instrumentale de Forel 1 → 140, Pop Rock Festival 1 → 150,
-- Forget Yesterday 1 → 160, Festival de Sévelin 1 → 170
INSERT INTO ref_packaging_clients (name, is_active, sort_order) VALUES
  ('Les Arches',                  1,  10),
  ('Jetée de la Compagnie',       1,  20),
  ('Festival de la Cité',         1,  30),
  ('Les Docks',                   1,  40),
  ('Festi''Cheyr',                1,  50),
  ('Fêtes de Genève',             1,  60),
  ('Rincette',                    1,  70),
  ('Wine Night',                  1,  80),
  ('People in the City',          1,  90),
  ('Fête de la Musique',          1, 100),
  ('20km Lausanne',               1, 110),
  ('ParaBôle',                    1, 120),
  ('Digital Dreams',              1, 130),
  ('Union Instrumentale de Forel',1, 140),
  ('Pop Rock Festival',           1, 150),
  ('Forget Yesterday',            1, 160),
  ('Festival de Sévelin',         1, 170),
  -- New client added by operator request 2026-05-31 (no historical rows; seeded active)
  ('Les Jardins de Louis',        1, 180);

-- ── 4. Drop the old (mis-wired) FK → ref_clients ─────────────────────────────
-- Constraint name verified via SHOW CREATE TABLE bd_packaging_v2:
--   CONSTRAINT `fk_bdpv2_client` FOREIGN KEY (`client_fk`) REFERENCES `ref_clients` (`id`) ON DELETE SET NULL
ALTER TABLE bd_packaging_v2
  DROP FOREIGN KEY fk_bdpv2_client;

-- ── 5. Re-add FK → ref_packaging_clients (same column, correct target) ───────
ALTER TABLE bd_packaging_v2
  ADD CONSTRAINT fk_bdpv2_pkg_client
    FOREIGN KEY (client_fk)
    REFERENCES ref_packaging_clients(id)
    ON DELETE RESTRICT;

-- ── 6. Backfill cuv rows only ─────────────────────────────────────────────────
-- Joins on TRIM(keg_client_delivered) = name (all 14 distinct cuv names are
-- in the seed above → 96 rows expected to resolve).
-- Non-cuv rows intentionally excluded (P1 session-bleed; 3 extra distinct names
-- appear on non-cuv rows: Digital Dreams, Union Instrumentale de Forel,
-- Festival de Sévelin — these are NOT real cuv clients, do not promote).
-- keg_client_delivered is deliberately NOT nulled — kept as provenance.
UPDATE bd_packaging_v2 p
  JOIN ref_packaging_clients c ON TRIM(p.keg_client_delivered) = c.name
   SET p.client_fk = c.id
 WHERE p.run_type = 'cuv'
   AND p.keg_client_delivered IS NOT NULL
   AND LENGTH(TRIM(p.keg_client_delivered)) > 0;
