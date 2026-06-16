-- 375_cogs_fiche_sealed.sql
-- Append-only sealed/restatement store for COGS fiche.
-- One seal event = 12 rows (one per COGS_FICHE_CATEGORIES).
-- Active seal for a month = latest sealed_at for that month (restatement chain head).
-- Older rows retained as immutable history; supersedes_seal_id FK chains the restatements.

CREATE TABLE IF NOT EXISTS cogs_fiche_sealed (
    id                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    month_key            CHAR(7)         NOT NULL COMMENT 'YYYY-MM',
    category_key         VARCHAR(64)     NOT NULL COMMENT 'One of COGS_FICHE_CATEGORIES',
    rm_chf               DECIMAL(14,4)   NOT NULL DEFAULT 0,
    wip_chf              DECIMAL(14,4)   NOT NULL DEFAULT 0,
    fg_chf               DECIMAL(14,4)   NOT NULL DEFAULT 0,
    total_chf            DECIMAL(14,4)   NOT NULL DEFAULT 0,
    opening_chf          DECIMAL(14,4)   NOT NULL DEFAULT 0,
    variation_chf        DECIMAL(14,4)   NOT NULL DEFAULT 0,
    basis_adjustment_chf DECIMAL(14,4)   NOT NULL DEFAULT 0,
    sealed_at            DATETIME        NOT NULL,
    sealed_by            VARCHAR(128)    NULL      COMMENT 'User/system label that triggered the seal',
    supersedes_seal_id   BIGINT UNSIGNED NULL      COMMENT 'FK to cogs_fiche_sealed.id; set on restatement to point at prior active seal',
    note                 VARCHAR(255)    NULL,
    created_at           TIMESTAMP       NOT NULL  DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    INDEX idx_sealed_month          (month_key),
    INDEX idx_sealed_month_sealed_at (month_key, sealed_at),
    CONSTRAINT fk_cfs_supersedes
        FOREIGN KEY (supersedes_seal_id) REFERENCES cogs_fiche_sealed (id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Append-only operator-sealed COGS fiche values. Active seal = MAX(sealed_at) per month_key.';

-- schema_meta row: append-only, operator-written via cogs_fiche_seal_month()
INSERT INTO schema_meta
    (table_name, table_class, writer_script, corrections_policy, upstream_hint, notes)
VALUES
    ('cogs_fiche_sealed',
     'reference',
     'app/cogs-fiche-resolve.php::cogs_fiche_seal_month()',
     'allowed',
     'Sealed values are frozen from cogs_fiche_compute_month() at seal time. Restatements append a new seal event and set supersedes_seal_id on the prior active seal row. Never UPDATE existing rows.',
     'Created mig 375. One seal event = 12 rows (one per COGS_FICHE_CATEGORIES). Active seal = latest sealed_at per month_key. Append-only: old seals are historical record.')
ON DUPLICATE KEY UPDATE
    notes       = VALUES(notes),
    updated_at  = CURRENT_TIMESTAMP;
