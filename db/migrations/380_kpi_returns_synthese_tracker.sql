-- Migration 380: Seed ref_kpi_trackers row for Retours synthèse widget.
-- source_domain='logistics' handler returns_synthese added in kpi-handlers.php (same commit).
-- data_ready=0 initially — Opus flips to 1 after verifying live payload.
-- No schema_meta row: existing-table seed only.

INSERT INTO ref_kpi_trackers
    (tracker_no, slug, label, description, category, domain,
     source_domain, compute_handler, params_json, viz_type,
     readiness, default_cadence, is_hero, hero_slot, data_ready,
     min_role, is_active, sort)
VALUES
    (283,
     'returns_synthese',
     'Retours — Synthèse',
     'Volume retourné (90j) + mix disposition remise en stock / rebut / quarantaine · hors avoirs purs',
     'logistics', 'logistics',
     'logistics', 'returns_synthese',
     '{"period_days":90}',
     'bar',
     'live', 'monthly', 0, NULL, 0,
     'operator', 1, 2830);
