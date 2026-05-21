-- 063_ref_mi_invoicing_units.sql
--
-- Per-MI (optionally per-supplier) invoicing unit registry.
-- Captures the unit a supplier bills in + the multiplier from billed qty
-- to stock qty (pack_size). Kept empty for now; Phase 2 populates it.
-- supplier_fk references ref_suppliers(id).
--
-- Apply:
--   ssh maltyweb 'cd /var/www/maltytask && sudo -u www-data php scripts/migrate.php'

CREATE TABLE IF NOT EXISTS ref_mi_invoicing_units (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  mi_id_fk        INT UNSIGNED NOT NULL,
  supplier_fk     INT UNSIGNED NULL
    COMMENT 'Optional: per-supplier override. NULL = default for any supplier.',
  invoicing_unit  VARCHAR(16) NOT NULL,
  pack_size       DECIMAL(10,4) NOT NULL DEFAULT 1
    COMMENT 'Multiplier from billed qty to stock qty.',
  is_default      TINYINT(1) NOT NULL DEFAULT 0,
  active          TINYINT(1) NOT NULL DEFAULT 1,
  notes           TEXT NULL,
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_invu_mi       FOREIGN KEY (mi_id_fk)        REFERENCES ref_mi(id),
  CONSTRAINT fk_invu_unit     FOREIGN KEY (invoicing_unit)  REFERENCES ref_units(code),
  CONSTRAINT fk_invu_supplier FOREIGN KEY (supplier_fk)     REFERENCES ref_suppliers(id),
  UNIQUE KEY uk_mi_supplier_unit_pack (mi_id_fk, supplier_fk, invoicing_unit, pack_size),
  KEY idx_mi (mi_id_fk),
  KEY idx_supplier (supplier_fk)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
