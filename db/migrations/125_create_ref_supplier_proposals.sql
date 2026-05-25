-- db/migrations/125_create_ref_supplier_proposals.sql
-- What:   New table for the manager→admin field-change proposal workflow on suppliers
--         (Salle des Machines — Fournisseurs admin page).
-- Why:    Governance model: managers can propose field changes (name, GL, country, etc.)
--         to a supplier fiche; admins review + approve/reject before the edit lands.
--         We deliberately do NOT reuse `mi_proposals_audit`:
--           - mi_proposals_audit is MI-specific (corrections_policy='blocked')
--           - it has `validated_mi_id NOT NULL` — would force ugly sentinels for suppliers
--           - it bundles multiple proposed fields per row (category, subcategory, account, name)
--             whereas supplier proposals are one-field-per-row (cleaner audit trail)
-- PK type: BIGINT UNSIGNED — matches the `audit` table convention (mi_proposals_audit.id is
--           BIGINT UNSIGNED, same for audit_row_revisions.id, ingest_runs.id, etc.).
--           ref_*.id are INT UNSIGNED (small reference tables); this is a workflow/event
--           table that can grow unboundedly → BIGINT UNSIGNED is correct.
-- FK types: ref_suppliers.id is INT UNSIGNED (verified in INFORMATION_SCHEMA) → supplier_fk
--           must be INT UNSIGNED. users.id is INT UNSIGNED (verified) → proposed_by / reviewed_by
--           must be INT UNSIGNED.
-- schema_meta classification: table_class='audit' (operator-driven workflow surface;
--           each row records a proposal event and its resolution outcome).
--           corrections_policy='allowed' — the web UI writes directly to this table.
--           writer_script='web' (Salle des Machines fiche proposal form).
-- Rollback: DROP TABLE IF EXISTS ref_supplier_proposals;
--           DELETE FROM schema_meta WHERE table_name='ref_supplier_proposals';

CREATE TABLE IF NOT EXISTS `ref_supplier_proposals` (
  `id`              BIGINT UNSIGNED     NOT NULL AUTO_INCREMENT,
  `supplier_fk`     INT UNSIGNED        NOT NULL  COMMENT 'FK → ref_suppliers.id',
  `field_name`      VARCHAR(64)         NOT NULL  COMMENT 'e.g. name, gl_account, country, vat_number',
  `current_value`   TEXT                NULL      COMMENT 'Snapshot of field value at proposal time',
  `proposed_value`  TEXT                NULL      COMMENT 'Requested new value',
  `proposed_by`     INT UNSIGNED        NOT NULL  COMMENT 'FK → users.id (manager who submitted)',
  `proposed_at`     TIMESTAMP           NOT NULL  DEFAULT CURRENT_TIMESTAMP,
  `status`          ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `reviewed_by`     INT UNSIGNED        NULL      COMMENT 'FK → users.id (admin who decided)',
  `reviewed_at`     TIMESTAMP           NULL,
  `review_note`     TEXT                NULL      COMMENT 'Admin decision rationale / rejection reason',
  PRIMARY KEY (`id`),
  KEY `idx_rsp_supplier_fk` (`supplier_fk`),
  KEY `idx_rsp_status`      (`status`),
  CONSTRAINT `fk_rsp_supplier`    FOREIGN KEY (`supplier_fk`) REFERENCES `ref_suppliers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_rsp_proposed_by` FOREIGN KEY (`proposed_by`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_rsp_reviewed_by` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Manager → admin field-change proposal workflow for supplier fiche';

-- schema_meta row for the new table (required by migration discipline: same file as CREATE).
-- table_class='audit': workflow/event surface; each row is an immutable proposal record.
-- corrections_policy='allowed': web UI writes directly; no upstream redirect needed.
-- writer_script='web': proposals are created via the Salle des Machines fiche UI.
INSERT INTO `schema_meta`
  (`table_name`, `table_class`, `corrections_policy`, `writer_script`, `upstream_hint`, `notes`)
VALUES (
  'ref_supplier_proposals',
  'audit',
  'allowed',
  'web',
  NULL,
  'Manager→admin proposal workflow for supplier fiche fields. One row per proposed field change. status=(pending|approved|rejected). Admin approves via Salle des Machines UI; approval should trigger the actual field update on ref_suppliers. History retained indefinitely — never truncate.'
)
ON DUPLICATE KEY UPDATE
  table_class        = VALUES(table_class),
  corrections_policy = VALUES(corrections_policy),
  writer_script      = VALUES(writer_script),
  notes              = VALUES(notes),
  updated_at         = CURRENT_TIMESTAMP;
