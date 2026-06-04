-- Migration 260: add purge_pressure_bar to bd_fermenting_v2
--
-- MODEL
-- ------
-- Adds a nullable DECIMAL(5,2) column purge_pressure_bar to bd_fermenting_v2.
-- Stores the tank headspace pressure (bar) recorded during a Purge event.
--
-- DESIGN DECISIONS
-- ----------------
-- Storage: a dedicated nullable column on bd_fermenting_v2 (NOT bd_tank_readings,
-- which has no pressure field and a different semantic axis).
-- Purge pressure feeds no COP/COGS/QA derivation — it is a leaf event attribute.
-- The column is NULL on all non-Purge rows by construction (handler gates it on
-- event_type='Purge'). NULL on Purge rows is also valid: pressure is optional.
--
-- CHECK CONSTRAINT: the existing chk_fermenting_event_payload Purge arm is
-- unconditional TRUE — it does NOT require any specific column to be set.
-- Adding pressure as optional therefore requires no CHECK change.
--
-- schema_meta: no new row required — this is an ALTER on an already-classified table.

ALTER TABLE bd_fermenting_v2
    ADD COLUMN purge_pressure_bar DECIMAL(5,2) NULL
        COMMENT 'Purge only: tank headspace pressure (bar), optional'
        AFTER comment_purge;
