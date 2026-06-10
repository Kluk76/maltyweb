-- 322_inv_sales_fulfilment.sql
-- Shopify integration Phase 2A: eshop fulfilment workflow side tables.
-- Adds inv_sales_fulfilment (1:1 cache) + inv_sales_fulfilment_events (append-only)
-- + schema_meta rows mirroring ord_orders / ord_order_status_events.
--
-- MySQL 8 — NO ADD COLUMN IF NOT EXISTS; idempotency via schema_migrations.
-- NO bare SELECT statements (migrate.php uses exec() which leaves result sets open).

-- ── 1. inv_sales_fulfilment (1:1 per eshop order — status cache) ──────────────
CREATE TABLE IF NOT EXISTS inv_sales_fulfilment (
    id                      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    order_id_fk             BIGINT UNSIGNED NOT NULL,
    status                  ENUM(
                              'new',
                              'picking',
                              'picked',
                              'ready_for_pickup',
                              'fulfilled',
                              'picked_up',
                              'cancelled'
                            ) NOT NULL DEFAULT 'new',
    prepared_by_user_id     INT UNSIGNED NULL,
    fulfilment_site_id_fk   INT UNSIGNED NULL,
    shopify_sync_state      ENUM('idle','pending','pushed','failed') NOT NULL DEFAULT 'idle',
    shopify_fulfillment_id  VARCHAR(64)  NULL,
    push_error              TEXT         NULL,
    push_attempts           INT UNSIGNED NOT NULL DEFAULT 0,
    created_at              TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at              TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_isf_order (order_id_fk),
    KEY idx_isf_status          (status),
    KEY idx_isf_shopify_sync    (shopify_sync_state),

    CONSTRAINT fk_isf_order   FOREIGN KEY (order_id_fk)
        REFERENCES inv_sales_orders (id) ON DELETE CASCADE,
    CONSTRAINT fk_isf_user    FOREIGN KEY (prepared_by_user_id)
        REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_isf_site    FOREIGN KEY (fulfilment_site_id_fk)
        REFERENCES ref_sites (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 2. inv_sales_fulfilment_events (append-only event log) ───────────────────
CREATE TABLE IF NOT EXISTS inv_sales_fulfilment_events (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    order_id_fk     BIGINT UNSIGNED NOT NULL,
    status          ENUM(
                      'new',
                      'picking',
                      'picked',
                      'ready_for_pickup',
                      'fulfilled',
                      'picked_up',
                      'cancelled'
                    ) NOT NULL,
    occurred_at     DATETIME        NOT NULL,
    user_id_fk      INT UNSIGNED    NULL,
    source          ENUM('operator','shopify_reconcile') NOT NULL DEFAULT 'operator',
    comment         VARCHAR(255)    NULL,

    PRIMARY KEY (id),
    KEY idx_isfe_order (order_id_fk),

    CONSTRAINT fk_isfe_order  FOREIGN KEY (order_id_fk)
        REFERENCES inv_sales_orders (id) ON DELETE CASCADE,
    CONSTRAINT fk_isfe_user   FOREIGN KEY (user_id_fk)
        REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 3. schema_meta rows — mirror ord_orders / ord_order_status_events ─────────
INSERT INTO schema_meta
    (table_name, table_class, writer_script, corrections_policy, upstream_hint, notes)
VALUES
    (
        'inv_sales_fulfilment',
        'source',
        'public/api/eshop-fulfilment-status.php',
        'allowed',
        'Eshop fulfilment status cache (1:1 inv_sales_orders). status is materialised from inv_sales_fulfilment_events — update both in the same transaction. Fix channel/mode upstream via inv_sales_orders.',
        NULL
    ),
    (
        'inv_sales_fulfilment_events',
        'source',
        'public/api/eshop-fulfilment-status.php status-advance API',
        'allowed',
        'Append-only eshop fulfilment event log. Truth for eshop workflow lifecycle; inv_sales_fulfilment.status is the materialised cache. Do not delete or UPDATE rows — append corrections only.',
        NULL
    )
ON DUPLICATE KEY UPDATE
    writer_script      = VALUES(writer_script),
    corrections_policy = VALUES(corrections_policy),
    upstream_hint      = VALUES(upstream_hint);
