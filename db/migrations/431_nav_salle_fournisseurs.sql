-- =============================================================================
-- Migration 431: Nav entry for Salle des Fournisseurs (supplier governance +
--                Entity Discussion Tracker).
-- The page existed URL-only (no topbar entry); this registers it under
-- Logistique, manager+. Idempotent upsert — the row may already exist from the
-- live INSERT that shipped the link ahead of this migration; on a fresh rebuild
-- it inserts, on prod it re-asserts the same values (no-op / self-heal).
-- Page enforces manager+ server-side (operators are redirected).
-- =============================================================================

INSERT INTO ref_pages
  (page_key, label, icon, href, min_role, domain, category_key, category_sort, is_active, sort)
VALUES
  ('salle-fournisseurs', 'Fournisseurs', '🏭', '/modules/salle-fournisseurs.php',
   'manager', 'logistics', 'logistique', 80, 1, 145)
ON DUPLICATE KEY UPDATE
  label         = VALUES(label),
  icon          = VALUES(icon),
  href          = VALUES(href),
  min_role      = VALUES(min_role),
  domain        = VALUES(domain),
  category_key  = VALUES(category_key),
  category_sort = VALUES(category_sort),
  is_active     = VALUES(is_active),
  sort          = VALUES(sort);
