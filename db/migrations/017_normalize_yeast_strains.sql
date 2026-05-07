-- 017_normalize_yeast_strains.sql
-- Consolidates K-column variants (folded by migration 016) into canonical names
-- per operator review on 2026-05-07. Ingest now applies the same map at write
-- time via YEAST_CANONICAL in scripts/python/tab_brewing.py.

UPDATE bd_brewing_brewday SET bd_yeast = 'Pomona'    WHERE bd_yeast = 'POMONA';
UPDATE bd_brewing_brewday SET bd_yeast = 'Farmhouse'  WHERE bd_yeast = 'farmhouse';
UPDATE bd_brewing_brewday SET bd_yeast = 'Pinnacle NA' WHERE bd_yeast = 'Pinnacle N/A';
UPDATE bd_brewing_brewday SET bd_yeast = 'WLP001'     WHERE bd_yeast = 'WLP-001';
UPDATE bd_brewing_brewday SET bd_yeast = 'Diamond'    WHERE bd_yeast = 'Lallamand Diamond Lager';
UPDATE bd_brewing_brewday SET bd_yeast = 'Diamond'    WHERE bd_yeast = 'Diamond - Lallemand';
UPDATE bd_brewing_brewday SET bd_yeast = 'Pomona'     WHERE bd_yeast = 'Pomona Lallemand';
UPDATE bd_brewing_brewday SET bd_yeast = 'Pomona'     WHERE bd_yeast = 'Pomona - Second harvest from 1st GEN from SPY58';
UPDATE bd_brewing_brewday SET bd_yeast = 'WLP080-O'   WHERE bd_yeast = 'WLP080-O (Organic Cream Ale Yeast Blend)';

DELETE FROM ref_yeast_strains WHERE name IN (
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
