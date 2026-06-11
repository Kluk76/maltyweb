-- 325_kpi_sku_seasonal_index.sql
-- Materialized weekly seasonal-index cache for the "semaines restantes de stock"
-- forward-seasonal burn engine on the Expéditions page.
--
-- Four operations in order:
--   1. CREATE kpi_sku_seasonal_index (cache table — rebuilt wholesale by seasonal-index-cli)
--   2. ADD composite index on inv_sales_ledger (sku_id_fk, posting_date) for burn queries
--   3. INSERT 11 tunable burn-engine parameters into system_settings (section='stock')
--   4. INSERT schema_meta classification row for kpi_sku_seasonal_index
--
-- MySQL 8 — NO ADD COLUMN/INDEX IF NOT EXISTS; idempotency via schema_migrations.
-- NO bare SELECT statements (migrate.php uses PDO::exec() which cannot leave open
-- result sets).  All INSERTs use ON DUPLICATE KEY UPDATE for safe re-run in dev.

-- ── 1. Cache table ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS kpi_sku_seasonal_index (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  recipe_id INT UNSIGNED NOT NULL DEFAULT 0,        -- family = ref_skus.recipe_id; 0 = global brewery fallback curve (sentinel, not a real recipe)
  iso_week TINYINT UNSIGNED NOT NULL,               -- ISO week-of-year 1..53 (MySQL WEEK(d,3))
  seasonal_index DECIMAL(6,4) NOT NULL,             -- multiplicative; mean ~= 1.0 across weeks 1..52; clamped [0.20,4.00]
  n_obs INT UNSIGNED NOT NULL DEFAULT 0,            -- # of yearly observations behind this week's median ratio
  sample_years DECIMAL(4,1) NOT NULL DEFAULT 0,    -- span of usable history feeding this family (years)
  is_global_fallback TINYINT(1) NOT NULL DEFAULT 0, -- 1 iff this row belongs to the global sentinel curve (recipe_id=0)
  computed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_seasonal_family_week (recipe_id, iso_week),
  KEY idx_seasonal_recipe (recipe_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Mig325: materialized family(recipe_id) weekly seasonal index for semaines-restantes forward burn. CACHE - weekly wholesale rebuild by seasonal-index-cli from inv_sales_ledger. recipe_id=0=global fallback curve.';

-- ── 2. Composite index on inv_sales_ledger ────────────────────────────────────
-- Burn queries filter on sku_id_fk then range-scan posting_date.
-- inv_sales_ledger already has idx_sl_sku (sku_id_fk) and idx_sl_posting
-- (posting_date) as separate single-column indexes; the composite supersedes
-- both for the burn-query access pattern (sku equality + date range scan).
-- Algorithm: INSTANT (InnoDB metadata-only, no table rebuild).
-- Idempotency: schema_migrations prevents re-run in production; bare ADD INDEX
-- matches the house style established in mig 320.
ALTER TABLE inv_sales_ledger ADD INDEX idx_sl_sku_posting (sku_id_fk, posting_date);

-- ── 3. Burn-engine tunable parameters → system_settings (section='stock') ────
-- value_num is set, value_text=NULL — satisfies the CHECK
-- (value_num IS NULL) OR (value_text IS NULL): one must be NULL.
-- UNIQUE key: uk_system_settings_section_key (section, key_name).
-- ON DUPLICATE KEY UPDATE only refreshes labels/descriptions, not the live value,
-- so an operator who has tuned a param doesn't get it reset on re-run.
INSERT INTO system_settings
    (section, key_name, label_fr, description_fr,
     value_text, value_num, unit_fr,
     default_text, default_num,
     is_active, updated_by)
VALUES
    ('stock', 'burn_ewma_lambda',
     'Lissage exponentiel (lambda)',
     'Poids de récence du rythme de vente; demi-vie ~13 semaines à 0.95.',
     NULL, 0.9500, NULL, NULL, 0.9500, 1, 'mig325'),

    ('stock', 'burn_season_weeks',
     'Longueur de saison',
     'Nombre de semaines par cycle saisonnier (ordinairement 52).',
     NULL, 52.0000, 'semaines', NULL, 52.0000, 1, 'mig325'),

    ('stock', 'burn_horizon_weeks',
     'Horizon max de projection',
     'Nombre maximum de semaines projetées dans le calcul de couverture.',
     NULL, 104.0000, 'semaines', NULL, 104.0000, 1, 'mig325'),

    ('stock', 'burn_index_floor',
     'Plancher indice saisonnier',
     'Valeur minimale autorisée pour un indice saisonnier (écrêtage bas).',
     NULL, 0.2000, NULL, NULL, 0.2000, 1, 'mig325'),

    ('stock', 'burn_index_cap',
     'Plafond indice saisonnier',
     'Valeur maximale autorisée pour un indice saisonnier (écrêtage haut).',
     NULL, 4.0000, NULL, NULL, 4.0000, 1, 'mig325'),

    ('stock', 'burn_index_smooth_weeks',
     'Lissage circulaire de l''indice',
     'Fenêtre (en semaines) du lissage circulaire appliqué à la courbe saisonnière.',
     NULL, 3.0000, 'semaines', NULL, 3.0000, 1, 'mig325'),

    ('stock', 'burn_min_family_years',
     'Historique minimum par famille avant repli sur la courbe globale',
     'Années d''historique nécessaires pour utiliser la courbe propre à une famille; en-dessous → repli sur la courbe globale (recipe_id=0).',
     NULL, 2.0000, 'ans', NULL, 2.0000, 1, 'mig325'),

    ('stock', 'burn_level_window_weeks',
     'Fenêtre de calcul du rythme de base',
     'Nombre de semaines glissantes utilisées pour estimer le rythme de vente de base (avant application de l''indice saisonnier).',
     NULL, 52.0000, 'semaines', NULL, 52.0000, 1, 'mig325'),

    ('stock', 'burn_provisional_min_weeks',
     'Seuil d''historique sous lequel l''estimation est provisoire',
     'Si le SKU a moins de N semaines d''historique, l''estimation de couverture est marquée provisoire.',
     NULL, 13.0000, 'semaines', NULL, 13.0000, 1, 'mig325'),

    ('stock', 'burn_provisional_min_nonzero_weeks',
     'Nombre minimum de semaines avec ventes pour fiabiliser le rythme',
     'Parmi les semaines observées, le nombre minimum de semaines avec au moins une vente nette pour que le rythme soit considéré fiable.',
     NULL, 6.0000, 'semaines', NULL, 6.0000, 1, 'mig325'),

    ('stock', 'burn_eol_dormant_weeks',
     'Sans vente nette au-delà → SKU classé sans rotation',
     'Si un SKU n''a aucune vente nette sur les N dernières semaines, il est classé en statut « sans rotation » (end-of-life ou dormant).',
     NULL, 26.0000, 'semaines', NULL, 26.0000, 1, 'mig325')

AS new_param
ON DUPLICATE KEY UPDATE
    label_fr       = new_param.label_fr,
    description_fr = new_param.description_fr,
    unit_fr        = new_param.unit_fr,
    default_num    = new_param.default_num,
    updated_by     = new_param.updated_by;
-- Note: value_num is intentionally NOT updated on duplicate — preserves any
-- operator tuning done after initial seed.

-- ── 4. schema_meta row for kpi_sku_seasonal_index ────────────────────────────
-- Classification: derived + blocked_with_redirect — this is a materialized cache
-- rebuilt wholesale by the seasonal-index-cli from inv_sales_ledger.
-- Modeled on ref_recipe_profile (derived / blocked_with_redirect / rebuild-script).
INSERT INTO schema_meta
    (table_name, table_class, writer_script, corrections_policy, upstream_hint, notes)
VALUES
    (
        'kpi_sku_seasonal_index',
        'derived',
        'scripts/seasonal-index-cli.php',
        'blocked_with_redirect',
        'Recompute via scripts/seasonal-index-cli.php (weekly cron or manual run). Fix source data upstream in inv_sales_ledger. recipe_id=0 rows are the global fallback curve; family rows require burn_min_family_years of history.',
        'Mig325: weekly seasonal-index cache for semaines-restantes forward burn on Expéditions page. Rebuilt wholesale each run — no partial updates. recipe_id=0 is a sentinel (global fallback), not a real ref_recipes row.'
    )
AS new_meta
ON DUPLICATE KEY UPDATE
    writer_script      = new_meta.writer_script,
    corrections_policy = new_meta.corrections_policy,
    upstream_hint      = new_meta.upstream_hint,
    notes              = new_meta.notes;

SET @noop = 1;
