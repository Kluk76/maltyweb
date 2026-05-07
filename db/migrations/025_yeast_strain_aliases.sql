-- 025 — Yeast strain aliases table + at-ingest canonicalization.
--
-- Replaces the one-shot UPDATE approach of migration 017 with a durable
-- lookup table that the ingest script consults at write time (lib_yeast.py).
-- Canonical names live in ref_yeast_strains; variant spellings live here.
-- Unknown raw values pass through to bd_yeast unchanged (no silent inserts).
--
-- This migration:
--   1. Creates ref_yeast_strain_aliases.
--   2. Seeds the 9 known variant-to-canonical mappings (mirrors migration 017).
--   3. Re-runs the normalization UPDATEs to clean rows that crept back since
--      017 applied (8 "New Yeast" rows with bd_yeast_new IS NOT NULL, plus
--      17 "POMONA" rows, etc.).
--   4. Removes variant rows from ref_yeast_strains (idempotent, safe to re-run).

CREATE TABLE IF NOT EXISTS ref_yeast_strain_aliases (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  strain_id  INT UNSIGNED NOT NULL,
  alias      VARCHAR(128) NOT NULL,
  source     ENUM('manual','auto') NOT NULL DEFAULT 'manual',
  added_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_alias (alias),
  FOREIGN KEY (strain_id) REFERENCES ref_yeast_strains(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed the 9 known aliases.
-- SELECT subqueries resolve canonical IDs at migration time, so this works
-- regardless of auto_increment state in ref_yeast_strains.
-- COLLATE utf8mb4_bin ensures exact-case lookups for canonical names.
INSERT IGNORE INTO ref_yeast_strain_aliases (strain_id, alias) VALUES
  ((SELECT id FROM ref_yeast_strains WHERE name COLLATE utf8mb4_bin = 'Pomona'),      'POMONA'),
  ((SELECT id FROM ref_yeast_strains WHERE name COLLATE utf8mb4_bin = 'Pomona'),      'Pomona Lallemand'),
  ((SELECT id FROM ref_yeast_strains WHERE name COLLATE utf8mb4_bin = 'Pomona'),      'Pomona - Second harvest from 1st GEN from SPY58'),
  ((SELECT id FROM ref_yeast_strains WHERE name COLLATE utf8mb4_bin = 'Farmhouse'),   'farmhouse'),
  ((SELECT id FROM ref_yeast_strains WHERE name COLLATE utf8mb4_bin = 'Pinnacle NA'), 'Pinnacle N/A'),
  ((SELECT id FROM ref_yeast_strains WHERE name COLLATE utf8mb4_bin = 'WLP001'),      'WLP-001'),
  ((SELECT id FROM ref_yeast_strains WHERE name COLLATE utf8mb4_bin = 'Diamond'),     'Lallamand Diamond Lager'),
  ((SELECT id FROM ref_yeast_strains WHERE name COLLATE utf8mb4_bin = 'Diamond'),     'Diamond - Lallemand'),
  ((SELECT id FROM ref_yeast_strains WHERE name COLLATE utf8mb4_bin = 'WLP080-O'),    'WLP080-O (Organic Cream Ale Yeast Blend)');

-- Re-run the col-K fold for "New Yeast" rows that appeared after migration 016.
-- Rows with bd_yeast_new IS NULL are left as-is (no col-K value to fold into).
UPDATE bd_brewing_brewday
   SET bd_yeast = bd_yeast_new
 WHERE bd_yeast = 'New Yeast'
   AND bd_yeast_new IS NOT NULL
   AND bd_yeast_new <> '';

-- Resolve all remaining known variants via the alias table.
UPDATE bd_brewing_brewday bb
  JOIN ref_yeast_strain_aliases a ON a.alias = bb.bd_yeast
  JOIN ref_yeast_strains s        ON s.id    = a.strain_id
   SET bb.bd_yeast = s.name;

-- Remove variant names from ref_yeast_strains (already done in 017 for most;
-- re-applying is a no-op thanks to DELETE … WHERE name IN (…)).
-- COLLATE utf8mb4_bin ensures exact byte-match so canonical names like
-- 'Pomona' and 'Farmhouse' are never accidentally caught by ci-collation
-- matching 'POMONA' or 'farmhouse'.
DELETE FROM ref_yeast_strains WHERE name COLLATE utf8mb4_bin IN (
    'POMONA',
    'farmhouse',
    'Pinnacle N/A',
    'WLP-001',
    'Lallamand Diamond Lager',
    'Diamond - Lallemand',
    'Pomona Lallemand',
    'Pomona - Second harvest from 1st GEN from SPY58',
    'WLP080-O (Organic Cream Ale Yeast Blend)'
);
