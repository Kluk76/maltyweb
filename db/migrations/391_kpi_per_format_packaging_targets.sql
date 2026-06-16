-- 391_kpi_per_format_packaging_targets.sql
-- Add three per-format packaging KPI trackers (Fûts / Bouteilles / Canettes)
-- data_ready=0; flip to 1 after reconciliation gate passes.

INSERT INTO ref_kpi_trackers
    (tracker_no, slug, label, description, category, domain, source_domain,
     compute_handler, params_json, viz_type, readiness, default_cadence,
     is_hero, data_ready, min_role, is_active, sort)
VALUES
    (284, 'production_targets_packaging_keg', 'Objectifs Fûts',
     'Produit vs Objectif HL Fûts · hebdo / mensuel / annuel',
     'packaging', 'production', 'production_targets',
     'objectifs_packaging_keg', '{"scope": "packaging_keg"}',
     'grouped_bar', 'live', 'weekly',
     0, 0, 'operator', 1, 2830),

    (285, 'production_targets_packaging_bot', 'Objectifs Bouteilles',
     'Produit vs Objectif HL Bouteilles · hebdo / mensuel / annuel',
     'packaging', 'production', 'production_targets',
     'objectifs_packaging_bot', '{"scope": "packaging_bot"}',
     'grouped_bar', 'live', 'weekly',
     0, 0, 'operator', 1, 2840),

    (286, 'production_targets_packaging_can', 'Objectifs Canettes',
     'Produit vs Objectif HL Canettes · hebdo / mensuel / annuel',
     'packaging', 'production', 'production_targets',
     'objectifs_packaging_can', '{"scope": "packaging_can"}',
     'grouped_bar', 'live', 'weekly',
     0, 0, 'operator', 1, 2850);
