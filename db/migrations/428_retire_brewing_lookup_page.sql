-- 428_retire_brewing_lookup_page.sql
-- Retire the standalone brewing-lookup page (mig 425). The "Consulter un brassin"
-- lookup is folding into wort.php as a 3rd tab, so the dedicated page_key, its nav
-- row, and its preset grants are no longer needed. The API endpoint
-- public/api/brewing-lookup.php is KEPT (the wort-embedded panel calls it) and its
-- gate is repointed to require_page_access('wort') in the same commit.
--
-- Audit: action ENUM is ('insert','update') only — no 'delete'. We tombstone via
--   action='update' + an after_json {_tombstone:...} marker (house convention).
-- FK: ref_access_preset_pages.page_id_fk -> ref_pages.id ON DELETE CASCADE, so the
--   grant rows are removed automatically by the ref_pages delete; the explicit
--   DELETE below is belt-and-braces and makes the intent auditable.
-- PDO-safe: no standalone result-returning SELECT (the SELECTs are inside
--   INSERT...SELECT / DELETE subqueries).

INSERT INTO audit_row_revisions
  (user_id, username, target_table, target_pk, action, before_json, after_json, comment)
SELECT
  0, 'migration_428', 'ref_pages', id, 'update',
  JSON_OBJECT('id', id, 'page_key', page_key, 'label', label,
              'min_role', min_role, 'domain', domain, 'is_active', is_active),
  JSON_OBJECT('_tombstone', JSON_OBJECT('reason', 'folded into wort.php Consulter tab', 'mig', '428')),
  'retire brewing-lookup standalone page (mig 428)'
FROM ref_pages
WHERE page_key = 'brewing-lookup';

DELETE FROM ref_access_preset_pages
 WHERE page_id_fk = (SELECT id FROM (SELECT id FROM ref_pages WHERE page_key = 'brewing-lookup') t);

DELETE FROM ref_pages WHERE page_key = 'brewing-lookup';
