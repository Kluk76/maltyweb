-- db/migrations/074_ref_recipes_branded_scotch.sql
-- What: Add uses_branded_scotch flag to ref_recipes; set to 1 for the 4 beers
--       whose -B SKUs use PKG_SCOTCH_<BEER> (branded tape) instead of the generic
--       PKG_SCOTCH_TRANSP + PKG_STICKER_<BEER> pair used by generic -B SKUs.
-- Why:  SKU builder UI needs to know which recipes default to branded scotch at the
--       -B format level, so it can auto-populate the slot choice without operator
--       manual override every time a new batch is set up.
-- Source: reference_v_liner_and_yeastvit_in_recipes.md — -B SKU sticker convention.
--         Branded: Embuscade (EMBB), Diversion (DIVB), Zepp (ZEPB), Moonshine (MOOB).
--         Generic: all others (STI, SPY, DIB, ALT, EPH*, DGD, DOC, etc.)
-- Risk: ALTER + UPDATE on ref_recipes (~60 rows). ADD COLUMN IF NOT EXISTS safe.
-- Rollback:
--   ALTER TABLE ref_recipes DROP COLUMN uses_branded_scotch;

START TRANSACTION;

ALTER TABLE ref_recipes
  ADD COLUMN uses_branded_scotch BOOL NOT NULL DEFAULT 0
    COMMENT 'When 1, -B SKUs default to PKG_SCOTCH_{BEER} branded; when 0, use PKG_SCOTCH_TRANSP + PKG_STICKER_{BEER}';

-- Operator-confirmed branded scotch beers (reference_v_liner_and_yeastvit_in_recipes.md)
UPDATE ref_recipes
  SET uses_branded_scotch = 1
WHERE name IN ('Embuscade', 'Diversion', 'Zepp', 'Moonshine')
  AND vintage = '';

COMMIT;
