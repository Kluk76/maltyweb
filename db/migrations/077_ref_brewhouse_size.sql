-- Migration 077: ref_brewhouse_size
-- Stores the brewhouse nominal capacity in HL with SCD2 versioning.
-- A NULL effective_until means "currently active".

CREATE TABLE ref_brewhouse_size (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  size_hl         DECIMAL(8,3) NOT NULL,
  effective_from  DATE NULL,
  effective_until DATE NULL,
  notes           VARCHAR(255) NULL,
  updated_by      VARCHAR(64) NULL,
  created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_effective (effective_from, effective_until)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO ref_brewhouse_size (size_hl, effective_from, notes, updated_by)
VALUES (30.000, CURDATE(), 'Initial value set 2026-05-21 by operator', 'web');
