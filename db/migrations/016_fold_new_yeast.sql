-- 016 — Fold "New Yeast" placeholder into bd_yeast for legacy rows.
-- When an operator selected "New Yeast" from the yeast dropdown (col I) they
-- typed the real strain name in col K (bd_yeast_new). The DB was storing the
-- literal string "New Yeast" in bd_yeast instead of the real strain.
-- This migration back-fills bd_yeast with the real strain for those 52 rows.
-- The ingest script (tab_brewing.py) is updated to apply the same rule going
-- forward, so this migration is one-shot for legacy rows only.

UPDATE bd_brewing_brewday
   SET bd_yeast = bd_yeast_new
 WHERE bd_yeast = 'New Yeast'
   AND bd_yeast_new IS NOT NULL
   AND bd_yeast_new <> '';

DELETE FROM ref_yeast_strains WHERE name = 'New Yeast';
