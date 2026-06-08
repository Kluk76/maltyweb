-- Migration 284 — À valider: register invoice-validate page in ref_pages + visite-guidée description
--
-- Adds the "À valider" invoice validation page (B1b) to the nav registry.
-- domain=general (logistics-adjacent but not a pure logistics page), min_role=operator.
-- sort=45 — after Triage (40), before Saisies (50); logically paired with document pipeline.
-- is_active=1 — page ships with this migration.
--
-- Also adds the visite-guidée page description into visite-guidee.php PHP source
-- (no DB table for tour steps — descriptions are keyed PHP arrays in visite-guidee.php).
--
-- Pre-flight checks:
--   ref_pages: table_class='reference', corrections_policy='allowed' — additive INSERT OK.
--   MySQL 8: INSERT IGNORE on UNIQUE(page_key) provides idempotency.
--   No FK added — page_key is self-contained reference data.

INSERT IGNORE INTO `ref_pages`
    (`page_key`, `label`, `icon`, `href`, `min_role`, `domain`, `is_active`, `sort`)
VALUES
    ('invoice-validate', 'À valider', '✓', '/modules/invoice-validate.php', 'operator', 'general', 1, 45);

-- schema_meta: no row needed — ref_pages already has one from migration 266.
-- The INSERT IGNORE on the unique key_name guarantees idempotency on re-run.
