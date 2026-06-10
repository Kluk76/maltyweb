-- Migration 312: drop the redundant "À valider" entry from the topbar.
--
-- The invoice-validation queue already lives at triage.php?tab=valider (ref_pages
-- id=4, key 'triage', on the topbar) since the 2026-06-08 triage merge.
-- /modules/invoice-validate.php (ref_pages id=99, key 'invoice-validate') is now a
-- thin wrapper and a duplicate topbar entry. Deactivate its ref_pages row so it no
-- longer renders in the topbar, while keeping the page reachable by href and
-- access-gated (user_can_access() / _page_registry() query ref_pages WITHOUT the
-- is_active filter, so deactivation hides the nav entry without orphaning the page).
--
-- Reversible: UPDATE ref_pages SET is_active = 1 WHERE page_key = 'invoice-validate';

UPDATE ref_pages SET is_active = 0 WHERE page_key = 'invoice-validate';
