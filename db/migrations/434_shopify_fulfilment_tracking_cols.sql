-- ============================================================
-- Migration 434: Colonnes de tracking Shopify sur inv_sales_orders
--
-- What:    Ajoute 4 colonnes nullable sur inv_sales_orders pour stocker
--          les données de fulfillment Shopify capturées à l'ingest :
--            - shopify_fulfilled_at  DATETIME NULL  (fulfillments[].created_at)
--            - tracking_number       VARCHAR(128) NULL
--            - tracking_url          VARCHAR(512) NULL
--            - tracking_company      VARCHAR(128) NULL
--
-- Why:     L'intégration Shopify→maltyweb fulfilment doit suivre l'état
--          d'expédition côté Shopify (Swiss Post App / impression packing slip)
--          sans passer par un JOIN externe. Les 4 champs sont optionnels :
--          NULL = non encore expédié ou non communiqué par Shopify.
--
-- Risk:    DDL pur — 4 ADD COLUMN nullable sur une table existante.
--          Opération INSTANT/INPLACE sur MySQL 8 (no-rebuild).
--          Aucune donnée existante n'est modifiée. Re-apply = erreur 1060
--          (colonne déjà présente), bloquée par schema_migrations.
--
-- Rollback: ALTER TABLE inv_sales_orders
--             DROP COLUMN shopify_fulfilled_at,
--             DROP COLUMN tracking_number,
--             DROP COLUMN tracking_url,
--             DROP COLUMN tracking_company;
-- ============================================================

ALTER TABLE inv_sales_orders
    ADD COLUMN shopify_fulfilled_at DATETIME     NULL DEFAULT NULL AFTER fulfilment_mode_source,
    ADD COLUMN tracking_number      VARCHAR(128) NULL DEFAULT NULL AFTER shopify_fulfilled_at,
    ADD COLUMN tracking_url         VARCHAR(512) NULL DEFAULT NULL AFTER tracking_number,
    ADD COLUMN tracking_company     VARCHAR(128) NULL DEFAULT NULL AFTER tracking_url;
