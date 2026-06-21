-- =============================================================================
-- Migration 432: Move "Fournisseurs" (salle-fournisseurs) out of the Logistique
-- topbar dropdown into the Le Zeppelin hub. It is now reached via a manager-only
-- sub-card under the Salle des Machines family on le-zeppelin.php.
--
-- NULL category drops it from the topbar dropdown — same mechanism as the
-- 'zeppelin' hub button itself (which is a standalone button, category NULL).
-- The row is KEPT (is_active=1, min_role='manager') so the access grant and the
-- hub-card render guard still resolve. Supersedes the category_key/sort that
-- mig 431 set. Idempotent — re-asserting NULL is a no-op.
-- =============================================================================

UPDATE ref_pages
   SET category_key = NULL,
       category_sort = 0
 WHERE page_key = 'salle-fournisseurs';
