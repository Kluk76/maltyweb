-- =============================================================================
-- Migration 412 — recipe_change_requests + recipe_change_request_lines
-- =============================================================================
-- Purpose : Canonical request envelope for manager-initiated recipe modifications.
--           Managers submit requests (ingredient add/update/remove, recipe-field
--           edits, QC targets, yeast assignment, format activate/deactivate,
--           BOM-slot bindings); admins review and approve or reject from the
--           recettes tab.  Approved requests replay through shared sdc_apply_*
--           helpers (P3 work) — no direct write from this table to fiscal data.
-- Tables  : recipe_change_requests (envelope) +
--           recipe_change_request_lines (per-change rows)
-- FK types: all INT UNSIGNED — matches ref_recipes.id, ref_mi.id, users.id.
-- Pre-flight checks performed (2026-06-19):
--   - MySQL 8 syntax (no ADD COLUMN IF NOT EXISTS) — schema_migrations idempotency
--   - No bare SELECT in file (PDO exec() constraint)
--   - CHECK constraint names are table-prefixed (schema-unique)
--   - CASCADE-FK cols NOT referenced in CHECK expressions
--   - ref_recipes.id INT UNSIGNED, ref_mi.id INT UNSIGNED, users.id INT UNSIGNED
--     verified live via SHOW COLUMNS on VPS.
-- =============================================================================

CREATE TABLE `recipe_change_requests` (
  `id`               INT UNSIGNED     NOT NULL AUTO_INCREMENT,
  `recipe_id_fk`     INT UNSIGNED     NOT NULL COMMENT 'FK → ref_recipes.id; the recipe being proposed for change',
  `requested_by_fk`  INT UNSIGNED     NOT NULL COMMENT 'FK → users.id; manager who submitted the request',
  `requested_at`     DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `status`           ENUM('pending','approved','rejected','withdrawn')
                                      NOT NULL DEFAULT 'pending',
  `decided_by_fk`    INT UNSIGNED     NULL     COMMENT 'FK → users.id; admin who approved or rejected; NULL while pending',
  `decided_at`       DATETIME         NULL,
  `decision_note`    VARCHAR(500)     NULL     COMMENT 'Free-text rationale for rejection or approval notes',
  `change_kind`      ENUM(
                       'ingredient_add',
                       'ingredient_update',
                       'ingredient_remove',
                       'recipe_field',
                       'qc_target',
                       'yeast',
                       'format_activate',
                       'format_deactivate',
                       'bom_binding'
                     )                NOT NULL COMMENT 'Primary intent of the request; multi-line requests share one envelope',
  `summary`          VARCHAR(255)     NULL     COMMENT 'Human-readable one-liner for badge / email subject',
  `created_at`       DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_rcr_status_recipe` (`status`, `recipe_id_fk`),
  KEY `idx_rcr_requested_by`  (`requested_by_fk`),
  KEY `idx_rcr_decided_by`    (`decided_by_fk`),
  CONSTRAINT `fk_rcr_recipe`       FOREIGN KEY (`recipe_id_fk`)    REFERENCES `ref_recipes` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_rcr_requested_by` FOREIGN KEY (`requested_by_fk`) REFERENCES `users`       (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_rcr_decided_by`   FOREIGN KEY (`decided_by_fk`)   REFERENCES `users`       (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE `recipe_change_request_lines` (
  `id`               INT UNSIGNED     NOT NULL AUTO_INCREMENT,
  `request_id_fk`    INT UNSIGNED     NOT NULL COMMENT 'FK → recipe_change_requests.id; CASCADE on delete',
  `target_table`     ENUM(
                       'ref_recipes',
                       'ref_recipe_ingredients',
                       'ref_packaging_formats',
                       'ref_sku_bom'
                     )                NOT NULL COMMENT 'Table that the approved replay will write to',
  `target_pk`        INT UNSIGNED     NULL     COMMENT 'PK of the existing row being changed (NULL for new-row adds)',
  `mi_id_fk`         INT UNSIGNED     NULL     COMMENT 'FK → ref_mi.id; set for ingredient or BOM-slot lines; NULL for recipe-field / format / qc / yeast lines',
  `field`            VARCHAR(64)      NULL     COMMENT 'Column name being changed, or BOM-slot name (label/can/sticker/holder/outer_tray/scotch)',
  `old_value`        VARCHAR(255)     NULL     COMMENT 'Serialised previous value captured at submit time; may become stale by approval (see replay gotcha)',
  `new_value`        VARCHAR(255)     NULL     COMMENT 'Serialised intended value',
  `is_cost_affecting` TINYINT(1)     NOT NULL DEFAULT 0 COMMENT 'Pre-computed at submit: 1 if line feeds COP/COGS (minerals/finings/process-aids gap-fill or BOM binding); re-verified live at approval',
  `created_at`       DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_rcrl_request_id`   (`request_id_fk`),
  KEY `idx_rcrl_mi_id_fk`     (`mi_id_fk`),
  CONSTRAINT `fk_rcrl_request` FOREIGN KEY (`request_id_fk`) REFERENCES `recipe_change_requests` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_rcrl_mi`      FOREIGN KEY (`mi_id_fk`)      REFERENCES `ref_mi`                  (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =============================================================================
-- schema_meta — one row per new table (mig 080 convention)
-- Both tables are 'source' class (intent envelopes written by the app UI;
-- the replay that touches fiscal tables is a SEPARATE operation).
-- corrections_policy='allowed' — requests are human-written records.
-- writer_script=NULL — app UI writes via PHP handler (P2 work).
-- =============================================================================
INSERT INTO schema_meta (table_name, table_class, corrections_policy, writer_script, upstream_hint, notes)
VALUES
  ('recipe_change_requests',
   'source', 'allowed',
   NULL,
   NULL,
   'Envelope for manager-submitted recipe modification requests. Approved requests replay through sdc_apply_* shared helpers (P3). NEVER feeds COGS/COP/WAC/BOM directly — the replay does; this table is pure intent. Status lifecycle: pending→approved/rejected/withdrawn.'),

  ('recipe_change_request_lines',
   'source', 'allowed',
   NULL,
   'old_value captured at submit may be stale by approval time — replay must re-resolve current open row by (recipe_id, mi_id, stage) and surface "stale request" if source moved',
   'Per-change rows for a recipe change request. is_cost_affecting precomputed at submit from ref_mi.category; ALWAYS recomputed live at approval before COGS-delta preview. CASCADE delete from recipe_change_requests. NOT a derived table — do not prune; rows are authoritative intent records.');
