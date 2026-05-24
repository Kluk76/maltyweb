-- db/migrations/104_bd_fermenting_v2_harden.sql
-- What: Light hardening for bd_fermenting_v2.
--   Add a NOT ENFORCED CHECK documenting the event-type column contract:
--     - DryHop rows should carry a dh_* payload (category set)
--     - Reads rows should carry at least one of gravity/ph/temperature
--   NOT ENFORCED because historic data has legitimate gaps (e.g. a Reads row where the
--   operator logged only temperature, or a DryHop row pending MI resolution).
-- Why:  recipe_id_fk and dh_mi_id_fk CANNOT be hardened NOT NULL — 4 blank-identity rows
--       and 2 lot-in-name DryHop rows are legitimate NULLs (flagged in migration 103).
--       This migration records the intended column contract for the future MaltyWeb form
--       (Phase 6.C) without breaking the historic backfill.
-- Risk: Low. NOT ENFORCED CHECK — recorded in information_schema, not validated on existing rows.
-- Rollback: ALTER TABLE bd_fermenting_v2 DROP CHECK chk_fermenting_event_payload;

ALTER TABLE bd_fermenting_v2
  ADD CONSTRAINT chk_fermenting_event_payload
    CHECK (
      (event_type = 'DryHop'    AND dh_category = 'hops_dry')
      OR (event_type = 'Reads'     AND (gravity IS NOT NULL OR ph IS NOT NULL OR temperature IS NOT NULL))
      OR (event_type = 'Purge')
      OR (event_type = 'ColdCrash')
    )
    NOT ENFORCED;

-- Promote to ENFORCED once a clean quarter of MaltyWeb-form data confirms coverage:
--   ALTER TABLE bd_fermenting_v2 ALTER CHECK chk_fermenting_event_payload ENFORCED;

SET @migration_104_harden_done = 1;
