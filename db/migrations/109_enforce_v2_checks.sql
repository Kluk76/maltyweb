-- db/migrations/109_enforce_v2_checks.sql
-- What: Promote the three v2 CHECK constraints from NOT ENFORCED -> ENFORCED.
--       Only bd_packaging_v2.chk_sku_or_flagged was enforced; the rest shipped
--       as documentation-only (NOT ENFORCED) in migrations 098/104/108.
-- Why:  DBA Phase-1-boundary finding N1. A NOT ENFORCED CHECK is parsed and
--       ignored on write — it guarantees nothing. The data is clean, so this
--       converts intent into a real write-time guarantee for the future
--       MaltyWeb forms (Phase 6) that will write these tables.
-- Pre-verified 2026-05-24: violation count = 0 for all three constraints:
--   chk_timings_has_time          (brew_start | brew_end | event_date NOT NULL) -> 0
--   chk_fermenting_event_payload  (DryHop/Reads/Purge/ColdCrash payload rules)  -> 0
--   chk_bdrkv2_identity           (neb_beer | contract_beer | no_beer_identity) -> 0
-- Risk: Low. Idempotent (re-enforcing an enforced CHECK is a no-op). Each ALTER
--       auto-commits; if a later one failed, earlier ones stay applied — but all
--       three are verified-clean so all succeed.
-- Rollback:
--   ALTER TABLE bd_brewing_timings_v2 ALTER CHECK chk_timings_has_time NOT ENFORCED;
--   ALTER TABLE bd_fermenting_v2      ALTER CHECK chk_fermenting_event_payload NOT ENFORCED;
--   ALTER TABLE bd_racking_v2         ALTER CHECK chk_bdrkv2_identity NOT ENFORCED;

ALTER TABLE bd_brewing_timings_v2 ALTER CHECK chk_timings_has_time ENFORCED;
ALTER TABLE bd_fermenting_v2      ALTER CHECK chk_fermenting_event_payload ENFORCED;
ALTER TABLE bd_racking_v2         ALTER CHECK chk_bdrkv2_identity ENFORCED;

SET @migration_109_done = 1;
