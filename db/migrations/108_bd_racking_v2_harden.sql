-- db/migrations/108_bd_racking_v2_harden.sql
-- What: Light hardening for bd_racking_v2 — a NOT ENFORCED CHECK documenting the
--       identity contract for the future MaltyWeb /racking form (Phase 6.D):
--       every row carries a Nébuleuse beer, a contract beer, or is explicitly
--       flagged no_beer_identity.
-- Why:  Phase 1.D. recipe_id_fk / bbt_number CANNOT be hardened NOT NULL — 1 blank-identity
--       row and 3 no-tank rows are legitimate NULLs. CHECK is NOT ENFORCED so existing
--       historical rows are untouched; documents intent, promote to ENFORCED later.
-- NOTE: a destination CHECK (BBT→bbt_number, CCT→cct_number) was intended but MySQL 8
--       forbids using a column in a CHECK when it also has a FK referential action
--       (error 3823 on bbt_number / fk_bdrkv2_bbt ON UPDATE CASCADE). The FK constraints
--       already enforce that bbt_number/cct_number reference valid tanks, so the
--       destination invariant is covered structurally; the type↔tank pairing is left to
--       the ingest/form layer.
-- Risk: Low. NOT ENFORCED CHECK — recorded in information_schema, not validated on existing rows.
-- Rollback: ALTER TABLE bd_racking_v2 DROP CHECK chk_bdrkv2_identity;

ALTER TABLE bd_racking_v2
  ADD CONSTRAINT chk_bdrkv2_identity
    CHECK (
      neb_beer <> ''
      OR contract_beer <> ''
      OR audit_flags LIKE '%no_beer_identity%'
    )
    NOT ENFORCED;

-- Promote to ENFORCED after a clean quarter:
--   ALTER TABLE bd_racking_v2 ALTER CHECK chk_bdrkv2_identity ENFORCED;

SET @migration_108_harden_done = 1;
