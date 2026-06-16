-- 389_ref_customer_identity.sql
-- Links geographic ship-to (member) ref_customers rows to Cobra distributor
-- bill-to (canonical) rows for BC-connector collision-key normalisation.
-- Applied: 2026-06-16  Author: mig389-cobra-identity

CREATE TABLE IF NOT EXISTS ref_customer_identity (
  id                       INT UNSIGNED NOT NULL AUTO_INCREMENT,
  member_customer_id_fk    INT UNSIGNED NOT NULL,
  canonical_customer_id_fk INT UNSIGNED NOT NULL,
  relation                 ENUM('billing_alias') NOT NULL DEFAULT 'billing_alias',
  notes                    VARCHAR(255) NULL,
  created_at               TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_by               VARCHAR(64) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_member (member_customer_id_fk),
  KEY idx_canonical (canonical_customer_id_fk),
  CONSTRAINT fk_rci_member    FOREIGN KEY (member_customer_id_fk)    REFERENCES ref_customers(id),
  CONSTRAINT fk_rci_canonical FOREIGN KEY (canonical_customer_id_fk) REFERENCES ref_customers(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Canonical 756: Cobra Traders - ALIGRO (4 rows)
INSERT IGNORE INTO ref_customer_identity (member_customer_id_fk, canonical_customer_id_fk, notes, created_by) VALUES
  (316,  756, 'Cobra Traders - ALIGRO billing alias', 'mig389-cobra-identity'),
  (2414, 756, 'Cobra Traders - ALIGRO billing alias', 'mig389-cobra-identity'),
  (2415, 756, 'Cobra Traders - ALIGRO billing alias', 'mig389-cobra-identity'),
  (2416, 756, 'Cobra Traders - ALIGRO billing alias', 'mig389-cobra-identity');

-- Canonical 755: Cobra Traders - COOP (26 rows)
INSERT IGNORE INTO ref_customer_identity (member_customer_id_fk, canonical_customer_id_fk, notes, created_by) VALUES
  (343,  755, 'Cobra Traders - COOP billing alias', 'mig389-cobra-identity'),
  (369,  755, 'Cobra Traders - COOP billing alias', 'mig389-cobra-identity'),
  (578,  755, 'Cobra Traders - COOP billing alias', 'mig389-cobra-identity'),
  (579,  755, 'Cobra Traders - COOP billing alias', 'mig389-cobra-identity'),
  (2417, 755, 'Cobra Traders - COOP billing alias', 'mig389-cobra-identity'),
  (2418, 755, 'Cobra Traders - COOP billing alias', 'mig389-cobra-identity'),
  (2419, 755, 'Cobra Traders - COOP billing alias', 'mig389-cobra-identity'),
  (2420, 755, 'Cobra Traders - COOP billing alias', 'mig389-cobra-identity'),
  (2421, 755, 'Cobra Traders - COOP billing alias', 'mig389-cobra-identity'),
  (2422, 755, 'Cobra Traders - COOP billing alias', 'mig389-cobra-identity'),
  (2423, 755, 'Cobra Traders - COOP billing alias', 'mig389-cobra-identity'),
  (2424, 755, 'Cobra Traders - COOP billing alias', 'mig389-cobra-identity'),
  (2425, 755, 'Cobra Traders - COOP billing alias', 'mig389-cobra-identity'),
  (2426, 755, 'Cobra Traders - COOP billing alias', 'mig389-cobra-identity'),
  (2427, 755, 'Cobra Traders - COOP billing alias', 'mig389-cobra-identity'),
  (2428, 755, 'Cobra Traders - COOP billing alias', 'mig389-cobra-identity'),
  (2429, 755, 'Cobra Traders - COOP billing alias', 'mig389-cobra-identity'),
  (2430, 755, 'Cobra Traders - COOP billing alias', 'mig389-cobra-identity'),
  (2431, 755, 'Cobra Traders - COOP billing alias', 'mig389-cobra-identity'),
  (2432, 755, 'Cobra Traders - COOP billing alias', 'mig389-cobra-identity'),
  (2433, 755, 'Cobra Traders - COOP billing alias', 'mig389-cobra-identity'),
  (2434, 755, 'Cobra Traders - COOP billing alias', 'mig389-cobra-identity'),
  (2435, 755, 'Cobra Traders - COOP billing alias', 'mig389-cobra-identity'),
  (2436, 755, 'Cobra Traders - COOP billing alias', 'mig389-cobra-identity'),
  (2437, 755, 'Cobra Traders - COOP billing alias', 'mig389-cobra-identity'),
  (2438, 755, 'Cobra Traders - COOP billing alias', 'mig389-cobra-identity');

-- Canonical 993: Cobra Traders - MIGROS (2 rows)
INSERT IGNORE INTO ref_customer_identity (member_customer_id_fk, canonical_customer_id_fk, notes, created_by) VALUES
  (2439, 993, 'Cobra Traders - MIGROS billing alias', 'mig389-cobra-identity'),
  (2440, 993, 'Cobra Traders - MIGROS billing alias', 'mig389-cobra-identity');

-- Canonical 760: Cobra Traders - MANOR (7 rows)
INSERT IGNORE INTO ref_customer_identity (member_customer_id_fk, canonical_customer_id_fk, notes, created_by) VALUES
  (97,  760, 'Cobra Traders - MANOR billing alias', 'mig389-cobra-identity'),
  (262, 760, 'Cobra Traders - MANOR billing alias', 'mig389-cobra-identity'),
  (263, 760, 'Cobra Traders - MANOR billing alias', 'mig389-cobra-identity'),
  (277, 760, 'Cobra Traders - MANOR billing alias', 'mig389-cobra-identity'),
  (289, 760, 'Cobra Traders - MANOR billing alias', 'mig389-cobra-identity'),
  (331, 760, 'Cobra Traders - MANOR billing alias', 'mig389-cobra-identity'),
  (646, 760, 'Cobra Traders - MANOR billing alias', 'mig389-cobra-identity');

-- Canonical 934: Cobra Traders - WITTICH (1 row)
INSERT IGNORE INTO ref_customer_identity (member_customer_id_fk, canonical_customer_id_fk, notes, created_by) VALUES
  (94, 934, 'Cobra Traders - WITTICH billing alias', 'mig389-cobra-identity');

INSERT IGNORE INTO schema_meta (table_name, table_class, writer_script, corrections_policy, upstream_hint, notes)
VALUES (
  'ref_customer_identity',
  'reference',
  'manual/web + mig389',
  'allowed',
  '',
  'Links geographic ship-to (member) accounts to Cobra distributor bill-to (canonical) accounts. Non-destructive — both rows remain is_active=1. Used by BC connector canon() to collapse collision keys.'
);
