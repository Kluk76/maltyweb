-- 365_planning_suggest_settings.sql
-- Phase 3 predictive suggestions: add suggest_reason column + system setting.
-- MySQL 8. Idempotent via schema_migrations. No bare SELECT.

ALTER TABLE pl_plan_items
  ADD COLUMN suggest_reason VARCHAR(255) NULL DEFAULT NULL
    COMMENT 'Rationale for predictive proposals; null for manual items'
    AFTER hors_process_reason;

INSERT INTO system_settings (section, key_name, label_fr, description_fr, value_num, unit_fr, is_active)
VALUES (
  'stock',
  'plan_suggest_target_weeks',
  'Couverture cible planning prédictif (semaines)',
  'Seuil de couverture en semaines de stock en dessous duquel une bière est proposée pour réapprovisionnement dans le planning.',
  3.0000,
  'semaines',
  1
)
ON DUPLICATE KEY UPDATE updated_at = updated_at;
