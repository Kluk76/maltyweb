-- Migration 288: Make inv_fg_stocktake.row_hash a STORED GENERATED column
--
-- Purpose: prevent location_id_fk / row_hash divergence after the 2026-06-08
-- incident where an ad-hoc UPDATE SET location_id_fk=<new> was applied without
-- recomputing the hash, leaving the stored hash pointing at the old location.
-- A STORED GENERATED column makes this class of corruption impossible by
-- construction: MySQL recomputes the value on every INSERT/UPDATE automatically.
--
-- Formula: SHA2(CONCAT_WS('|','fgct',sku_id_fk,location_id_fk,counted_at),256)
-- This matches exactly what the PHP application computed via
-- hash('sha256','fgct|'.$sid.'|'.$stLocId.'|'.$stCountedAt).
--
-- NULL handling: 41 April 2026 bsf-stocktake anchor rows have counted_at IS NULL.
-- CONCAT_WS skips NULL segments, so their generated hash becomes
-- SHA2('fgct|<sku_id>|<loc_id>',256) — a hash without the date segment.
-- This is intentional and inert: these rows are never re-derived or matched
-- by the application's new-saisie path (which always supplies a counted_at).
-- The Step 0 distinct-count check (122/122) already accounts for them.
--
-- MySQL 8 FK rule on generated-column inputs: ON DELETE SET NULL / CASCADE are
-- forbidden on a column referenced by a stored generated column, but RESTRICT /
-- NO ACTION are allowed. The original fk_fg_sku was ON DELETE SET NULL, so it is
-- dropped before the generated column is added, then re-added as ON DELETE
-- RESTRICT (stronger, and moot-equivalent: sku_id_fk has 0 NULLs — a NULL sku on
-- a count row is a corrupt fact, not a tolerable orphan). fk_fg_stocktake_location
-- is dropped/re-added the same way (RESTRICT preserved). Both sku_id_fk and
-- location_id_fk remain FK-enforced AND row_hash generated-expression inputs.
--
-- Schema_migrations provides idempotency (prevents re-application).
-- DDL auto-commits; each statement runs independently.

ALTER TABLE inv_fg_stocktake DROP FOREIGN KEY fk_fg_sku;

ALTER TABLE inv_fg_stocktake DROP FOREIGN KEY fk_fg_stocktake_location;

ALTER TABLE inv_fg_stocktake DROP INDEX uniq_row_hash;

ALTER TABLE inv_fg_stocktake DROP COLUMN row_hash;

ALTER TABLE inv_fg_stocktake ADD COLUMN row_hash CHAR(64) COLLATE utf8mb4_unicode_ci
    GENERATED ALWAYS AS (SHA2(CONCAT_WS('|','fgct',sku_id_fk,location_id_fk,counted_at),256)) STORED NOT NULL;

ALTER TABLE inv_fg_stocktake ADD UNIQUE KEY uniq_row_hash (row_hash);

-- Re-add fk_fg_sku as ON DELETE RESTRICT (was SET NULL; RESTRICT is permitted on
-- a generated-column input, SET NULL is not). Preserves referential integrity.
ALTER TABLE inv_fg_stocktake ADD CONSTRAINT fk_fg_sku
    FOREIGN KEY (sku_id_fk) REFERENCES ref_skus (id) ON DELETE RESTRICT;

ALTER TABLE inv_fg_stocktake ADD CONSTRAINT fk_fg_stocktake_location
    FOREIGN KEY (location_id_fk) REFERENCES ref_sites (id) ON DELETE RESTRICT;
