-- 361_activate_qa_page.sql
--
-- Activate the QA / QC operator page (ref_pages id 11, page_key='qa').
--
-- The page module (public/modules/qa.php), its CSS/JS, the 3 write handlers
-- (public/api/qa-*.php) and the 3 backing tables (migrations 358/359/360)
-- are all live. This flips the nav entry on and points its href at the module
-- (it was the placeholder '#').
--
-- Touching ref_pages makes this page newly surface in the production topbar for
-- operator+ — the Visite-guidée tour-gap check will flag it; the tour card is
-- authored by the maltyweb-tour-steward agent as a follow-up.
--
-- No new table → no schema_meta row.

UPDATE `ref_pages`
   SET `is_active` = 1,
       `href`      = '/modules/qa.php'
 WHERE `page_key`  = 'qa';
