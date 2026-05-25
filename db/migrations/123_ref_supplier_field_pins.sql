-- db/migrations/123_ref_supplier_field_pins.sql
-- What: Create ref_supplier_field_pins — field-level provenance table for ref_suppliers.
--       Records which specific fields on a supplier row have been pinned by an admin,
--       preventing auto-overwrite by the ingest/reconciler pipeline.
-- Why:  ref_suppliers.last_modified_by = 'ingest' on all 135 rows (DBA report §1b).
--       This makes it impossible to distinguish an admin-curated name from one last
--       touched by ingest. When the reconciler runs, it cannot know which fields are
--       human-pinned and must not overwrite. This table is the DB-native replacement
--       for entity-overrides.json: one row per (supplier, field_name) pair. Ingest
--       reads this table BEFORE writing any field to ref_suppliers — if a pin exists
--       for that field, the auto-update is skipped and logged. Admin explicitly removes
--       a pin via the fiche UI to hand the field back to auto-management. Never
--       auto-populated by ingest; written only by the web layer.
-- Decision: UNIQUE on (supplier_fk, field_name) — at most one active pin per field
--           per supplier. No SCD2 here: when admin changes a pinned value the row
--           is UPSERT'd (ON DUPLICATE KEY UPDATE). Historical pin changes are captured
--           by audit_row_revisions (migration 120), not by this table.
-- Rollback: DROP TABLE ref_supplier_field_pins;
--           DELETE FROM schema_meta WHERE table_name = 'ref_supplier_field_pins';

CREATE TABLE IF NOT EXISTS ref_supplier_field_pins (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  supplier_fk  INT UNSIGNED NOT NULL,
  field_name   VARCHAR(64)  NOT NULL COLLATE utf8mb4_unicode_ci,
  pinned_value TEXT         COLLATE utf8mb4_unicode_ci,
  pinned_by    VARCHAR(64)  NOT NULL DEFAULT 'web',
  pinned_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  pin_reason   TEXT         COLLATE utf8mb4_unicode_ci,
  PRIMARY KEY (id),
  UNIQUE KEY uk_supplier_field (supplier_fk, field_name),
  CONSTRAINT fk_spfp_supplier FOREIGN KEY (supplier_fk)
    REFERENCES ref_suppliers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO schema_meta (table_name, table_class, corrections_policy, writer_script, notes)
VALUES (
  'ref_supplier_field_pins',
  'reference',
  'allowed',
  'web',
  'Admin-only surface. Ingest reads before writing ref_suppliers; if a pin exists for a field, the auto-update is skipped. Never auto-populated. Replaces entity-overrides.json supplierGLForces (migration in progress). One row per (supplier, field_name); UPSERT on change; history in audit_row_revisions.'
);
