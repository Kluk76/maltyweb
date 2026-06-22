-- 436_comm_thread_suppliers.sql
-- Junction table: secondary supplier links on a comm thread.
-- The PRIMARY supplier stays on comm_threads.supplier_id_fk (never duplicated here).
-- role='linked' = secondary link confirmed by operator.

CREATE TABLE `comm_thread_suppliers` (
  `id`                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `thread_id_fk`         BIGINT UNSIGNED NOT NULL COMMENT 'FK → comm_threads.id',
  `supplier_id_fk`       INT UNSIGNED    NOT NULL COMMENT 'FK → ref_suppliers.id (secondary only)',
  `role`                 ENUM('linked')  NOT NULL DEFAULT 'linked',
  `linked_by_user_id`    INT UNSIGNED    NULL     COMMENT 'FK → users.id',
  `created_at`           DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_thread_supplier` (`thread_id_fk`, `supplier_id_fk`),
  KEY `idx_cts_supplier` (`supplier_id_fk`),
  CONSTRAINT `fk_cts_thread`    FOREIGN KEY (`thread_id_fk`)      REFERENCES `comm_threads`  (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_cts_supplier`  FOREIGN KEY (`supplier_id_fk`)    REFERENCES `ref_suppliers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_cts_user`      FOREIGN KEY (`linked_by_user_id`) REFERENCES `users`         (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO schema_meta (table_name, table_class, corrections_policy, writer_script, upstream_hint, notes)
VALUES (
  'comm_thread_suppliers',
  'source',
  'allowed',
  'web (manager)',
  NULL,
  'Junction — secondary supplier links on a comm thread (forwarder/principal). NON-FISCAL CRM — never feeds COGS/COP/WAC/BOM/beer-tax/stock. Primary supplier stays on comm_threads.supplier_id_fk.'
)
ON DUPLICATE KEY UPDATE
  table_class        = VALUES(table_class),
  corrections_policy = VALUES(corrections_policy),
  writer_script      = VALUES(writer_script),
  notes              = VALUES(notes);
