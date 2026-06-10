-- Migration 319: rename packaging_daily_recap tracker label + description
-- Tracker #271 (slug='packaging_daily_recap') was labelled "Mise en bouteille — résumé du jour"
-- which misled operators: the recap covers ALL packaging types (bot/can/keg/cuv), not bottling only.
-- Rename to "Conditionnement — résumé du jour" to reflect full-packaging scope.
-- Pure ref-data update; no schema change; idempotent.
UPDATE ref_kpi_trackers
   SET label       = 'Conditionnement — résumé du jour',
       description = 'Runs, HL vendables et pertes matières saisis aujourd''hui (bouteille, canette, fût, cuve)'
 WHERE slug = 'packaging_daily_recap';
