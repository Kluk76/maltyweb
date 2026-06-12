-- Migration 338: Add 5 production-beer sales KPI trackers (inv_sales_ledger-based)
-- data_ready=0 — Opus verifies fiscal numbers before flipping.

INSERT INTO ref_kpi_trackers
    (tracker_no, slug, label, description, category, domain, source_domain,
     compute_handler, viz_type, readiness, default_cadence,
     is_hero, data_ready, min_role, is_active, sort)
VALUES
(272, 'hl_sold_monthly_series',
 'Ventes HL par mois',
 'HL de bière production vendus par mois (ledger canonique) — série 18-24 mois',
 'sales', 'general', 'sales',
 'hl_sold_monthly_series', 'line', 'compute', 'monthly',
 0, 0, 'manager', 1, 2720),

(273, 'hl_by_sku_prod',
 'Ventes HL par SKU (production)',
 'HL vendus par SKU de bière production pour le dernier mois — top 12',
 'sales', 'general', 'sales',
 'hl_by_sku_prod', 'bar', 'compute', 'monthly',
 0, 0, 'manager', 1, 2730),

(274, 'units_by_sku_month',
 'Ventes unités par SKU (MoM)',
 'Unités vendues par SKU de bière production — comparaison mois en cours vs mois précédent',
 'sales', 'general', 'sales',
 'units_by_sku_month', 'grouped_bar', 'compute', 'monthly',
 0, 0, 'manager', 1, 2740),

(275, 'hl_by_trade_channel',
 'Ventes HL on-trade vs off-trade',
 'HL vendus par canal de distribution (on-trade / off-trade / non classé) — dernier mois',
 'sales', 'general', 'sales',
 'hl_by_trade_channel', 'stacked_bar', 'compute', 'monthly',
 0, 0, 'manager', 1, 2750),

(276, 'hl_by_recipe',
 'Ventes HL par recette',
 'HL vendus par recette de bière production (via rs.recipe_id FK) — dernier mois, top 12',
 'sales', 'general', 'sales',
 'hl_by_recipe', 'bar', 'compute', 'monthly',
 0, 0, 'manager', 1, 2760);
