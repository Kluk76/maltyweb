-- 393_fulfilment_shopify_heartbeat.sql
-- Seed system_settings rows for Shopify ingest heartbeat and stale-chip threshold.
-- No new table, no schema_meta row. Idempotent. No bare SELECT.

INSERT INTO system_settings (section, key_name, label_fr, description_fr, value_num, unit_fr, is_active)
VALUES ('fulfilment', 'shopify_ingest_stale_minutes',
        'Seuil fraîcheur ingestion Shopify (minutes)',
        'Délai sans heartbeat d''ingestion au-delà duquel la pastille boutique en ligne passe en alerte (ambre).',
        10.0000, 'minutes', 1)
ON DUPLICATE KEY UPDATE updated_at = updated_at;

INSERT INTO system_settings (section, key_name, label_fr, description_fr, value_text, is_active)
VALUES ('fulfilment', 'shopify_ingest_last_run_at',
        'Dernier run ingestion Shopify',
        'Heartbeat auto-écrit à la fin de chaque run d''ingestion Shopify réussi. Pilote l''alarme de fraîcheur (non éditable).',
        NULL, 1)
ON DUPLICATE KEY UPDATE updated_at = updated_at;
