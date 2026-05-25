-- db/migrations/122_ref_supplier_gls.sql
-- What: Create ref_supplier_gls — the multi-GL footprint table for ref_suppliers,
--       with SCD2 temporal columns (effective_from / effective_until).
-- Why:  ref_suppliers.gl_account is a single VARCHAR, structurally incompatible with
--       the multi-GL first-class principle already encoded in the reconciler. Suppliers
--       such as Brau- und Rauchshop (7 GLs), Westfalen (5), Univerre Pro UVA SA (3),
--       Carbagas (2) have GL footprints that cannot be represented in one field. This
--       table holds one row per (supplier, gl_account, effective_from) triple; the
--       reconciler populates it from inv_deliveries category_raw distribution and marks
--       is_primary=1 on the modal GL. Admin can add manual rows or flag GL rows as
--       excluded from COGS footprint. SCD2 follows the ref_brewhouse_size pattern
--       already in prod: open row = effective_until IS NULL; to close, set effective_until
--       = CURDATE() and INSERT new row. ref_suppliers.gl_account (single-value shortcut)
--       remains as a denormalized "primary GL" for backward compatibility with all
--       existing code consumers; the reconciler keeps it in sync with is_primary=1 here.
-- Decision: Table STARTS EMPTY. Reconciler populates on next run. UNIQUE key is on
--           (supplier_fk, gl_account, effective_from) so that SCD2 row reopening
--           (same supplier + GL, new effective_from) is allowed without collision.
-- Rollback: DROP TABLE ref_supplier_gls;
--           DELETE FROM schema_meta WHERE table_name = 'ref_supplier_gls';

CREATE TABLE IF NOT EXISTS ref_supplier_gls (
  id                           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  supplier_fk                  INT UNSIGNED NOT NULL,
  gl_account                   VARCHAR(16)  NOT NULL COLLATE utf8mb4_unicode_ci,
  gl_label                     VARCHAR(64)  NULL     COLLATE utf8mb4_unicode_ci
    COMMENT 'e.g. Houblon',
  is_primary                   TINYINT(1)   NOT NULL DEFAULT 0
    COMMENT 'modal/default GL',
  is_excluded_from_cogs_footprint TINYINT(1) NOT NULL DEFAULT 0,
  derived_from                 ENUM('observed','manual') NOT NULL DEFAULT 'observed',
  observed_delivery_count      INT UNSIGNED NULL
    COMMENT 'refreshed by reconciler',
  effective_from               DATE         NOT NULL DEFAULT (CURDATE())
    COMMENT 'SCD2 open date',
  effective_until              DATE         NULL
    COMMENT 'NULL = current row',
  PRIMARY KEY (id),
  UNIQUE KEY uk_supplier_gl (supplier_fk, gl_account, effective_from),
  KEY idx_supplier_fk (supplier_fk),
  CONSTRAINT fk_srg_supplier FOREIGN KEY (supplier_fk)
    REFERENCES ref_suppliers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO schema_meta (table_name, table_class, corrections_policy, writer_script, notes)
VALUES (
  'ref_supplier_gls',
  'reference',
  'allowed',
  'reconciler/web',
  'Multi-GL footprint per supplier. Derived from inv_deliveries per-line GL distribution; admin can pin/add rows; is_excluded_from_cogs_footprint toggled on the fiche UI. SCD2 on currency+GL (effective_from/effective_until). STARTS EMPTY — reconciler populates on next run.'
);
