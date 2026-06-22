-- ============================================================
-- Migration 433: Flag pour couper le fast-forward auto-BC des statuts d'expédition
--
-- What:    Un setting section 'fulfilment' gatant l'auto-avance de statut
--          déclenchée par BC Completely_Shipped=True dans ingest_bc_sales_orders.py.
--
-- Why:     En inputting hybride, l'opérateur pilote chaque étape logistique
--          manuellement. '0' (défaut) = pas de fast-forward auto ; '1' = réactive
--          l'avancement auto jusqu'à bl_printed (à rallumer au retour de l'opérateur
--          quand l'auto-write BC sera armé). Fail-safe : absence/illisible = OFF.
--
-- Risk:    Pure INSERT — no DDL. ON DUPLICATE KEY UPDATE id=id => re-apply no-op.
--
-- Rollback: DELETE FROM system_settings
--             WHERE section='fulfilment' AND key_name='bc_auto_advance_shipping';
-- ============================================================

INSERT INTO system_settings
    (section, key_name, label_fr, description_fr, value_text, is_active, updated_by)
VALUES
    ('fulfilment', 'bc_auto_advance_shipping',
     'Avancement auto des statuts depuis BC',
     '0 = l''opérateur pilote chaque étape d''expédition manuellement (défaut) ; 1 = quand Business Central indique la commande complètement expédiée, le statut avance automatiquement jusqu''à « BL imprimé ». Ne déclenche jamais « Livrée ».',
     '0', 1, 'migration_433')
ON DUPLICATE KEY UPDATE id = id;
