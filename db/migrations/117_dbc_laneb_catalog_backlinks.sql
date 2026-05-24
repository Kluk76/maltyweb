-- db/migrations/117_dbc_laneb_catalog_backlinks.sql
-- What: La Neb-specific UPDATE of catalog_id back-links on existing ref_* rows.
--       Vessel and format provenance only.
-- Why:  Retrofit step: bind La Neb's existing vessels/formats to catalog entries as provenance.
--       Does NOT create any brewery rows — only back-links existing rows.
-- Risk: UPDATE of nullable column only. The SET catalog_id=NULL fallback on sub-SELECT miss
--       is safe — no row corruption.
-- Rollback: UPDATE ref_cct/bbt/yt/ref_packaging_formats SET catalog_id=NULL;
--
-- NOTE: All MI bindings (container→MI in ref_container_mi, format→MI in ref_packaging_format_mis)
--       are deferred to a post-operator-signoff seed migration (118+),
--       pending capacites-mi-binding-preview-2026-05-24.md.

-- -----------------------------------------------------------------------
-- ref_cct: back-link to dbc_vessel_types.vessel_code='CCT'
-- -----------------------------------------------------------------------
UPDATE ref_cct
SET catalog_id = (
  SELECT id FROM dbc_vessel_types WHERE vessel_code = 'CCT' LIMIT 1
);

-- -----------------------------------------------------------------------
-- ref_bbt: back-link to dbc_vessel_types.vessel_code='BBT'
-- -----------------------------------------------------------------------
UPDATE ref_bbt
SET catalog_id = (
  SELECT id FROM dbc_vessel_types WHERE vessel_code = 'BBT' LIMIT 1
);

-- -----------------------------------------------------------------------
-- ref_yt: back-link to dbc_vessel_types.vessel_code='YT'
-- -----------------------------------------------------------------------
UPDATE ref_yt
SET catalog_id = (
  SELECT id FROM dbc_vessel_types WHERE vessel_code = 'YT' LIMIT 1
);

-- -----------------------------------------------------------------------
-- ref_packaging_formats: back-link to dbc_packaging_format_templates by run_type+unit match
-- Only formats with a clean 1:1 template match. Composites and PAD [VERIFY] left NULL.
-- -----------------------------------------------------------------------
UPDATE ref_packaging_formats SET catalog_id = (SELECT id FROM dbc_packaging_format_templates WHERE template_code='SINGLE_BOT_33' LIMIT 1) WHERE format_code IN ('BU');
UPDATE ref_packaging_formats SET catalog_id = (SELECT id FROM dbc_packaging_format_templates WHERE template_code='BOX24_BOT_33'  LIMIT 1) WHERE format_code IN ('B');
UPDATE ref_packaging_formats SET catalog_id = (SELECT id FROM dbc_packaging_format_templates WHERE template_code='BOX12_BOT_33'  LIMIT 1) WHERE format_code IN ('B12');
UPDATE ref_packaging_formats SET catalog_id = (SELECT id FROM dbc_packaging_format_templates WHERE template_code='6X4_BOT_33'    LIMIT 1) WHERE format_code IN ('4');
UPDATE ref_packaging_formats SET catalog_id = (SELECT id FROM dbc_packaging_format_templates WHERE template_code='4PACK_BOT_33'  LIMIT 1) WHERE format_code IN ('4PB');
UPDATE ref_packaging_formats SET catalog_id = (SELECT id FROM dbc_packaging_format_templates WHERE template_code='PAL_1027_BOT_33' LIMIT 1) WHERE format_code IN ('X');
UPDATE ref_packaging_formats SET catalog_id = (SELECT id FROM dbc_packaging_format_templates WHERE template_code='SINGLE_CAN_50' LIMIT 1) WHERE format_code IN ('CU');
UPDATE ref_packaging_formats SET catalog_id = (SELECT id FROM dbc_packaging_format_templates WHERE template_code='BOX24_CAN_50'  LIMIT 1) WHERE format_code IN ('C','BC');
UPDATE ref_packaging_formats SET catalog_id = (SELECT id FROM dbc_packaging_format_templates WHERE template_code='BOX12_CAN_50'  LIMIT 1) WHERE format_code IN ('12C');
UPDATE ref_packaging_formats SET catalog_id = (SELECT id FROM dbc_packaging_format_templates WHERE template_code='6X4_CAN_50'    LIMIT 1) WHERE format_code IN ('4C');
UPDATE ref_packaging_formats SET catalog_id = (SELECT id FROM dbc_packaging_format_templates WHERE template_code='4PACK_CAN_50'  LIMIT 1) WHERE format_code IN ('4PC');
UPDATE ref_packaging_formats SET catalog_id = (SELECT id FROM dbc_packaging_format_templates WHERE template_code='SINGLE_CAN_33' LIMIT 1) WHERE format_code IN ('33C');
UPDATE ref_packaging_formats SET catalog_id = (SELECT id FROM dbc_packaging_format_templates WHERE template_code='KEG_20L'       LIMIT 1) WHERE format_code IN ('F');
UPDATE ref_packaging_formats SET catalog_id = (SELECT id FROM dbc_packaging_format_templates WHERE template_code='CUV_SERVICE'   LIMIT 1) WHERE format_code IN ('V');
-- Composite formats → COMPOSITE_MIXED (PD8, PAL, XMASPACK confirmed; PAD [VERIFY] excluded)
UPDATE ref_packaging_formats SET catalog_id = (SELECT id FROM dbc_packaging_format_templates WHERE template_code='COMPOSITE_MIXED' LIMIT 1) WHERE format_code IN ('PD8','PAL','XMASPACK');
-- PAD catalog_id stays NULL pending volume VERIFY (8×33 vs 80×33)
-- P25, P50, 6C left NULL — no matching template (draft pours / 6-pack tray not in v1 catalog)

-- Container→MI bindings (ref_container_mi) and format→MI bindings (ref_packaging_format_mis)
-- are intentionally absent here. They are deferred to migration 118+ pending operator sign-off
-- on capacites-mi-binding-preview-2026-05-24.md. The dbc_container_types catalog table has no
-- mi_id_fk column and must not be modified for per-brewery MI data.
