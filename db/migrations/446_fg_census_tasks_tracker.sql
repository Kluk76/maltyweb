-- Migration 446: Seed ref_kpi_trackers row for the FG-census freshness tile.
-- source_domain='fg_stock' handler census_freshness added in app/kpi-handlers.php (same commit).
-- viz_type='table' is reused as-is — JS (renderKpiTable) and email (_kpi_render_table)
-- already dispatch on viz_type, so no JS/email changes are needed.
-- data_ready=0 initially — the orchestrator flips it to 1 after verifying the live payload,
-- so the tile stays out of the picker until then.
-- min_role='manager': the handler has no per-viewer context, so it lists ALL active FG sites
-- read-only; gating at manager honours "managers/admins see all sites" without a per-site filter.
-- No schema_meta row: existing-table seed only.

INSERT INTO ref_kpi_trackers
    (tracker_no, slug, label, description, category, domain,
     source_domain, compute_handler, params_json, viz_type,
     readiness, default_cadence, is_hero, hero_slot, data_ready,
     min_role, is_active, sort)
VALUES
    (287,
     'fg_census_freshness',
     'Fraîcheur des inventaires — à recompter',
     'Par site PF actif : dernier comptage (jours écoulés), retard de recensement et SKU en stock négatif. Seuil de retard depuis les Réglages.',
     'fg_stock', 'logistics',
     'fg_stock', 'census_freshness',
     NULL,
     'table',
     'live', 'weekly', 0, NULL, 0,
     'manager', 1, 855)
ON DUPLICATE KEY UPDATE tracker_no = tracker_no;
