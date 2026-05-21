-- db/migrations/081_schema_meta_doc_lines_policy.sql
-- What: Demote doc_invoice_lines and doc_dn_lines from 'allowed_with_side_effect'
--       to 'allowed' to match current implementation reality.
-- Why:  Migration 080 classified these 2 tables aspirationally — the CTO report
--       intent is that mi_id_fk corrections on doc lines upsert into ref_mi_aliases
--       (like bd_brewing_ingredients_parsed does today). But `dbcorrect_is_alias_trigger`
--       in app/db-correct.php only fires for bd_brewing_ingredients_parsed. Until
--       the trigger logic is extended (Phase 1.4), the side-effect banner would
--       promise behavior that doesn't fire — misleading the operator.
--
--       Flip back to 'allowed_with_side_effect' once Phase 1.4 wires the upsert
--       for doc_invoice_lines.mi_id_fk and doc_dn_lines.mi_id_fk.
-- Risk: Trivial UPDATE on 2 rows. No data loss; same semantics for the UI
--       (no banner, no preview, no upsert — already the actual behavior).
-- Rollback: UPDATE schema_meta SET corrections_policy = 'allowed_with_side_effect'
--           WHERE table_name IN ('doc_invoice_lines','doc_dn_lines');

START TRANSACTION;

UPDATE schema_meta
   SET corrections_policy = 'allowed',
       upstream_hint = NULL,
       notes = CONCAT(IFNULL(notes, ''),
                      ' [2026-05-22: policy demoted from allowed_with_side_effect to allowed — '
                      'alias-upsert side-effect not yet wired for this table; see Phase 1.4]')
 WHERE table_name IN ('doc_invoice_lines', 'doc_dn_lines');

COMMIT;
