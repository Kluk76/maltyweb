-- 386_smoketest_per_user_page_grants.sql
--
-- WHY per-user grants and NOT the smoke_viewer preset (id=11):
--
--   app/auth.php::user_can_access() evaluates in order:
--     1. admin bypass
--     2. page in registry?
--     3. per-user override (user_page_access) → RETURNS HERE if found (bypasses floor)
--     4. role floor: _role_rank(user.role) >= _role_rank(page.min_role)  ← BLOCKS viewer
--     5. preset membership check
--     6. fallback
--
--   Step 4 runs BEFORE step 5.  A viewer (rank 1) against an operator-floor page
--   (rank 2) returns false at step 4 — the preset grant at step 5 is never reached.
--   Per-user grants (step 3) are evaluated BEFORE the floor and short-circuit with
--   `return $overrides[$page_key]`, so they are the ONLY mechanism that can surface
--   above-floor pages for a viewer without touching auth.php.
--
-- Pages granted (all operator or manager floor; viewer-floor pages already reachable
-- via the preset's step-5 path):
--   zeppelin, qa, approvisionnement, expeditions, warehouse, planning,
--   rm-comparison, financier
--
-- User: id=16 (username='smoketest', role='viewer'), smoke-test account only.
-- Idempotent: UNIQUE KEY uniq_user_page (user_id_fk, page_id_fk) → INSERT IGNORE
--             is a no-op on re-run.

INSERT IGNORE INTO user_page_access (user_id_fk, page_id_fk, granted)
SELECT 16, rp.id, 1
FROM ref_pages rp
WHERE rp.page_key IN (
    'zeppelin',
    'qa',
    'approvisionnement',
    'expeditions',
    'warehouse',
    'planning',
    'rm-comparison',
    'financier'
);
