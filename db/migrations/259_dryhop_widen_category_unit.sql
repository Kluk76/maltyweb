-- Migration 259: widen bd_fermenting_v2 dry-hop ENUM columns + update CHECK constraint
--
-- MODEL
-- ------
-- Extends dh_category ENUM to support non-hops dry-hop additions
-- (adjuncts, minerals, process aids) alongside the existing hops_dry value.
--
-- Extends dh_unit ENUM to include 'ml' for liquid process additions.
--
-- Updates chk_fermenting_event_payload CHECK constraint to allow any of the
-- four dh_category values on DryHop rows (previously hardcoded 'hops_dry').
--
-- Existing rows remain valid: all have dh_category='hops_dry' or NULL,
-- and dh_unit='g' or 'kg' or NULL — no data migration required.
--
-- MySQL-8 MODIFY COLUMN (no IF NOT EXISTS — not supported in MySQL 8).
-- Nullability and default preserved from current schema (both columns are
-- nullable, no DEFAULT set):
--   dh_category: ENUM(...) NULL
--   dh_unit:     ENUM(...) NULL

ALTER TABLE bd_fermenting_v2
    MODIFY COLUMN dh_category ENUM('hops_dry','adjunct','mineral','process') NULL;

ALTER TABLE bd_fermenting_v2
    MODIFY COLUMN dh_unit ENUM('kg','g','ml') NULL;

-- Drop and recreate the event-payload CHECK constraint to allow all
-- four dh_category values (not just 'hops_dry') on DryHop rows.
ALTER TABLE bd_fermenting_v2
    DROP CHECK chk_fermenting_event_payload;

ALTER TABLE bd_fermenting_v2
    ADD CONSTRAINT chk_fermenting_event_payload CHECK (
        (event_type = 'DryHop' AND dh_category IN ('hops_dry','adjunct','mineral','process'))
        OR (event_type = 'Reads'     AND (gravity IS NOT NULL OR ph IS NOT NULL OR temperature IS NOT NULL))
        OR  event_type = 'Purge'
        OR  event_type = 'ColdCrash'
    ) ENFORCED;
