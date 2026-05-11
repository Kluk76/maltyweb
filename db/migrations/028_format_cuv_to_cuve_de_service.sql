-- 028: Rename packaging format 'Cuv' → 'Cuve de service'
--      Touches bd_packaging (~235 rows) and ref_skus (~3 rows).
--      Format column is VARCHAR(16); 'Cuve de service' is 15 chars.

UPDATE bd_packaging SET format = 'Cuve de service' WHERE format = 'Cuv';
UPDATE ref_skus     SET format = 'Cuve de service' WHERE format = 'Cuv';
