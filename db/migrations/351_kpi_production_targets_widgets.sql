-- Migration 351: Seed 2 ref_kpi_trackers rows for Objectifs de production widgets.
-- source_domain='production_targets' handler added in kpi-handlers.php (same commit).
-- data_ready=0 initially — orchestrator flips to 1 after verifying live payloads.
-- No schema_meta row: this is an existing-table seed only.

INSERT INTO ref_kpi_trackers
    (tracker_no, slug, label, description, category, domain,
     source_domain, compute_handler, params_json, viz_type,
     readiness, default_cadence, is_hero, hero_slot, data_ready,
     min_role, is_active, sort)
VALUES
    (281,
     'production_targets_wort',
     'Objectifs — Moût',
     'Produit vs Objectif HL brassés + brassins · hebdo / mensuel / annuel',
     'production', 'production',
     'production_targets', 'objectifs_wort',
     '{"scope":"wort"}',
     'grouped_bar',
     'live', 'weekly', 0, NULL, 0,
     'operator', 1, 2810),
    (282,
     'production_targets_packaging',
     'Objectifs — Packaging',
     'Produit vs Objectif HL Fûts / Bouteilles / Canettes · hebdo / mensuel / annuel',
     'production', 'production',
     'production_targets', 'objectifs_packaging',
     '{"scope":"packaging"}',
     'grouped_bar',
     'live', 'weekly', 0, NULL, 0,
     'operator', 1, 2820);
