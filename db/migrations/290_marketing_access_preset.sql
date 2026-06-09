-- 289_marketing_access_preset.sql
-- Marketing access preset (for Olivier, olivier@lanebuleuse.ch) — read-only FG-stock
-- visibility + Taproom stocktake input. Operator-ruled 2026-06-09.
--
-- Grants exactly two pages:
--   * expeditions  (id 10) — the Le Cockpit logistics module; its ?view=stocktake
--                            surface is the per-location FG count input, ?view=stock
--                            the FG-stock board. Marketing sees the whole module
--                            (incl. commandes/clients) — accepted, no per-tab ACL today.
--   * mon-tableau  (id 20) — the universal landing page. REQUIRED: a preset-bearing
--                            user is hard-denied on any non-member page (no fallback),
--                            so omitting the home page locks the user out of their own
--                            landing. (self-test defect #3 lesson.)
--
-- ALSO flips ref_pages.expeditions is_active 0 -> 1. This is the Fulfilment v1 GO-LIVE:
-- the flag is page-global, so Expeditions now appears in the topbar for managers
-- (Gonzalo/Yves/Nathan) and logistics ops (Stephane/Joelson) too — operator-accepted
-- 2026-06-09 as the deliberate launch of the logistics surface.
-- Flip runs FIRST so a partial-failure interruption never leaves a granted-but-inactive
-- page (a marketing user would be hard-denied on an inactive page).
--
-- MySQL 8 (no "IF NOT EXISTS" on inserts). Idempotency:
--   * the UPDATE is naturally idempotent (1 -> 1).
--   * ref_access_presets.preset_key is UNIQUE -> ON DUPLICATE KEY UPDATE (row-alias
--     form; VALUES() is deprecated since 8.0.20).
--   * ref_access_preset_pages has UNIQUE uniq_preset_page (preset_id_fk,page_id_fk)
--     -> ON DUPLICATE KEY UPDATE no-ops a re-run (col = its own stored value).

UPDATE ref_pages SET is_active = 1 WHERE page_key = 'expeditions';

INSERT INTO ref_access_presets (preset_key, label, description)
VALUES ('marketing', 'Marketing', 'Marketing — visibilité des stocks PF (lecture) + saisie stocktake Taproom') AS new_preset
ON DUPLICATE KEY UPDATE label = new_preset.label, description = new_preset.description;

INSERT INTO ref_access_preset_pages (preset_id_fk, page_id_fk)
SELECT (SELECT id FROM ref_access_presets WHERE preset_key = 'marketing'), p.id
  FROM ref_pages p
 WHERE p.page_key IN ('expeditions', 'mon-tableau')
ON DUPLICATE KEY UPDATE page_id_fk = ref_access_preset_pages.page_id_fk;
