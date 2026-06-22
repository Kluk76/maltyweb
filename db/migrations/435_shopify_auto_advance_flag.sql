-- ============================================================
-- Migration 435: Flag pour l'auto-avance des statuts Shopify (boutique en ligne)
--
-- What:    Insère un setting system_settings section 'fulfilment' gatant
--          l'avancement automatique des statuts de commande eshop déclenché
--          par Shopify (fulfillments[].created_at / impression packing slip /
--          Swiss Post App).
--
-- Why:     En mode hybride, l'opérateur pilote les statuts eshop manuellement.
--          '0' (défaut) = pas d'auto-avance ; '1' = quand Shopify marque une
--          commande LIVRAISON comme expédiée, le statut avance jusqu'à « Expédié ».
--          Les commandes en retrait (pickup) restent toujours manuelles.
--          Fail-safe : absence/illisible = OFF.
--
-- Risk:    Pure INSERT — no DDL. ON DUPLICATE KEY UPDATE id=id => re-apply no-op.
--
-- Rollback: DELETE FROM system_settings
--             WHERE section='fulfilment' AND key_name='shopify_auto_advance_shipping';
-- ============================================================

INSERT INTO system_settings
    (section, key_name, label_fr, description_fr, value_text, is_active, updated_by)
VALUES
    ('fulfilment', 'shopify_auto_advance_shipping',
     'Avancement auto des statuts depuis Shopify (boutique en ligne)',
     '0 = les statuts des commandes boutique en ligne sont pilotés manuellement (défaut) ; 1 = quand Shopify marque une commande LIVRAISON comme expédiée (impression du packing slip / Swiss Post App), le statut du tracker avance automatiquement jusqu''à « Expédié ». Ne s''applique jamais aux commandes en retrait (pickup), qui restent manuelles.',
     '0', 1, 'migration_435')
ON DUPLICATE KEY UPDATE id = id;
