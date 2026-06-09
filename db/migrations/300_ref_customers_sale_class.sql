-- Migration 300: ref_customers.sale_class
--
-- Adds the `sale_class` channel/price-type discriminator to ref_customers.
-- This is the ASP taxonomy for the BC sales-ledger arc.
--
-- The derived boolean "counts_as_unit_sale" is NOT stored as a column
-- (drift hazard). Compute it at read-time with:
--   CASE sale_class
--     WHEN 'eshop'             THEN FALSE   -- Shopify owns; exclude from units
--     WHEN 'taproom'           THEN FALSE   -- Lightspeed owns; exclude
--     WHEN 'customs_artifact'  THEN FALSE   -- net-0 pro-forma; exclude
--     WHEN 'transfer'          THEN FALSE   -- intra-entity; exclude
--     WHEN 'sample'            THEN FALSE   -- no revenue; exclude
--     ELSE TRUE                             -- b2b/giveaway/staff/event/other: TRACK
--   END
--
-- 6 internal accounts classified by Kouros:
--   1080 → eshop            (La Nébuleuse ESHOP — Shopify channel)
--   3822 → taproom           (TAP/SHOP — Lightspeed channel)
--   3701 → customs_artifact  (STE La Nébuleuse SA — net-0 customs pro-forma)
--   1807 → giveaway          (Département Marketing — CHF≈0 gifts; track for ASP drag)
--   1858 → staff             (Staff/Investisseur — discounted; track)
--   3823 → event             (La Nébuleuse Event — full-price direct sales; track)
--
-- All other customers default to 'b2b' via the column DEFAULT.

ALTER TABLE ref_customers
  ADD COLUMN sale_class ENUM(
    'b2b',
    'eshop',
    'taproom',
    'event',
    'giveaway',
    'staff',
    'customs_artifact',
    'transfer',
    'sample',
    'other'
  ) NOT NULL DEFAULT 'b2b' AFTER trade_channel;

UPDATE ref_customers
  SET sale_class = CASE bc_customer_no
    WHEN '1080' THEN 'eshop'
    WHEN '3822' THEN 'taproom'
    WHEN '3701' THEN 'customs_artifact'
    WHEN '1807' THEN 'giveaway'
    WHEN '1858' THEN 'staff'
    WHEN '3823' THEN 'event'
  END
  WHERE bc_customer_no IN ('1080', '3822', '3701', '1807', '1858', '3823');
