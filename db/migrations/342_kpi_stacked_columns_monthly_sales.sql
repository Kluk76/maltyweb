-- 342: Add stacked_columns viz_type + 4 monthly sales trackers (channel/recipe/SKU-HL/SKU-units)
-- data_ready=0 — Opus verifies fiscal numbers before going live.

-- 1. Add stacked_columns to ENUM (idempotent — re-running with same set is safe)
ALTER TABLE ref_kpi_trackers
  MODIFY COLUMN viz_type ENUM(
    'kpi_number','sparkline','bar','stacked_bar','line','donut','flag','table',
    'waterfall','recap','grouped_bar','stacked_columns'
  ) NOT NULL DEFAULT 'kpi_number';

-- 2. Seed 4 trackers (compute_handler = slug, same convention as trackers 272-276)
INSERT IGNORE INTO ref_kpi_trackers
  (tracker_no, slug, label, category, source_domain, domain, viz_type,
   compute_handler, min_role, is_active, data_ready, readiness, default_cadence, is_hero)
VALUES
  (277, 'hl_by_channel_monthly',        'Ventes HL par canal / mois',     'sales','sales','general','stacked_columns','hl_by_channel_monthly',       'manager',1,0,'compute','monthly',0),
  (278, 'hl_by_recipe_monthly',         'Ventes HL par recette / mois',   'sales','sales','general','stacked_columns','hl_by_recipe_monthly',        'manager',1,0,'compute','monthly',0),
  (279, 'hl_by_sku_monthly',            'Ventes HL par SKU / mois',       'sales','sales','general','stacked_columns','hl_by_sku_monthly',           'manager',1,0,'compute','monthly',0),
  (280, 'units_by_sku_monthly_matrix',  'Ventes unités par SKU / mois',   'sales','sales','general','stacked_columns','units_by_sku_monthly_matrix', 'manager',1,0,'compute','monthly',0);
