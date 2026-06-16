-- 379_users_manager_scope_finance.sql
-- Add 'finance' to the manager_scope ENUM so a CFO manager can seal/restate the fiche COGS.
-- The cogs-fiche-seal.php handler gates on: is_admin() OR manager_can('finance').
-- To grant Thierry the finance scope:
--   UPDATE users SET manager_scope = 'finance' WHERE id = <thierry_id>;
-- Or via the admin UI: Données générales → Utilisateurs → Edit → Scope = Finance.

ALTER TABLE users
  MODIFY COLUMN manager_scope ENUM('production','logistics','all','finance') DEFAULT NULL;
