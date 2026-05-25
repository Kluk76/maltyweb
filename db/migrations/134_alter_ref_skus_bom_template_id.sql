-- db/migrations/134_alter_ref_skus_bom_template_id.sql
-- What: Add bom_template_id (Expand-Contract step 1 of 4) to ref_skus.
--       Nullable — recette-activation UI will populate this when an operator assigns
--       a template to each SKU. Until then the recompute service falls back to
--       format_id + run_type heuristic.
-- Why: The SKU must know WHICH template variant it uses (labelled vs pre-printed,
--      we_supply vs client_supply) so the compiler can filter ref_packaging_items slots
--      correctly. Expand-Contract: format_id stays in place; the compiler reads
--      bom_template_id when set, format_id+heuristic when not.
-- Risk: ADD COLUMN nullable — INSTANT DDL. LOW.
-- Rollback: ALTER TABLE ref_skus DROP COLUMN bom_template_id;
--           ALTER TABLE ref_skus DROP FOREIGN KEY fk_rskus_bom_template;

ALTER TABLE ref_skus
  ADD COLUMN bom_template_id INT UNSIGNED NULL
    COMMENT 'FK to ref_packaging_bom_templates.id (INT UNSIGNED); NULL until operator activates via Recettes UI',
  ADD CONSTRAINT fk_rskus_bom_template
    FOREIGN KEY (bom_template_id) REFERENCES ref_packaging_bom_templates(id) ON DELETE SET NULL;

-- No index needed beyond the FK auto-index: reads will be by sku_id (PK), not by template.
