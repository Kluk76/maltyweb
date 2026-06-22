-- 437_ref_supplier_relationships.sql
-- Declared forwarder ↔ principal relationships between suppliers.
-- Each row = one asymmetric fact (forwarder handles logistics for principal).
-- rel_type ENUM extends by ALTER when new relationship types emerge.

CREATE TABLE `ref_supplier_relationships` (
  `id`                      INT UNSIGNED   NOT NULL AUTO_INCREMENT,
  `forwarder_supplier_fk`   INT UNSIGNED   NOT NULL COMMENT 'FK → ref_suppliers.id (the transitaire / forwarder)',
  `principal_supplier_fk`   INT UNSIGNED   NOT NULL COMMENT 'FK → ref_suppliers.id (the goods supplier)',
  `rel_type`                ENUM('forwarder') NOT NULL DEFAULT 'forwarder',
  `created_by_user_fk`      INT UNSIGNED   NULL     COMMENT 'FK → users.id',
  `created_at`              DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_rsr_rel` (`forwarder_supplier_fk`, `principal_supplier_fk`, `rel_type`),
  KEY `idx_rsr_forwarder`   (`forwarder_supplier_fk`),
  KEY `idx_rsr_principal`   (`principal_supplier_fk`),
  CONSTRAINT `fk_rsr_forwarder`      FOREIGN KEY (`forwarder_supplier_fk`) REFERENCES `ref_suppliers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_rsr_principal`      FOREIGN KEY (`principal_supplier_fk`) REFERENCES `ref_suppliers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_rsr_user`           FOREIGN KEY (`created_by_user_fk`)    REFERENCES `users`         (`id`) ON DELETE SET NULL,
  CONSTRAINT `chk_rsr_no_self_436`   CHECK (`forwarder_supplier_fk` <> `principal_supplier_fk`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO schema_meta (table_name, table_class, corrections_policy, writer_script, upstream_hint, notes)
VALUES (
  'ref_supplier_relationships',
  'reference',
  'allowed',
  'web (manager)',
  NULL,
  'Declared forwarder↔principal relationships. NON-FISCAL master-data leaf under ref_suppliers; drives comm-thread link suggestions only. Never feeds COGS/COP/WAC/BOM/beer-tax/stock.'
)
ON DUPLICATE KEY UPDATE
  table_class        = VALUES(table_class),
  corrections_policy = VALUES(corrections_policy),
  writer_script      = VALUES(writer_script),
  notes              = VALUES(notes);
