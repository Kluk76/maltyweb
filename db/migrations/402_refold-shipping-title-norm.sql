-- ============================================================
-- Migration 402 — Re-fold ref_shipping_methods.title_norm
--                 to strip diacritical marks
-- ============================================================
-- The normaliseShippingTitle() function in ingest-shopify-orders.ts
-- was updated to call .normalize('NFD') + strip combining marks
-- (U+0300–U+036F) before trim/collapse.  Two existing rows were
-- seeded before this fix and still carry accented characters in
-- title_norm.  This migration brings them in line so that future
-- lookups from accented Shopify shipping titles (e.g. "Expédition
-- gratuite") resolve correctly instead of landing in fulfilment_mode
-- = 'review'.
--
-- Only the two rows whose old value ≠ new value are touched;
-- every WHERE clause is idempotent (old_value guard).
-- ============================================================

-- id=3 : "la nébuleuse sa"  →  "la nebuleuse sa"
UPDATE ref_shipping_methods
SET    title_norm = 'la nebuleuse sa'
WHERE  id = 3
  AND  title_norm = 'la nébuleuse sa';

-- id=15 : "...après le paiement"  →  "...apres le paiement"
UPDATE ref_shipping_methods
SET    title_norm = 'chronoshop 2shop en point relais (selection du point relais apres le paiement)'
WHERE  id = 15
  AND  title_norm = 'chronoshop 2shop en point relais (selection du point relais après le paiement)';

-- migrate.php records schema_migrations(filename) itself on success; no self-insert here.
