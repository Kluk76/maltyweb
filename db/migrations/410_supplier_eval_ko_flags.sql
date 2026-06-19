-- Migration 410: Set KO flags for supplier evaluation grid EF-01 v1.0
-- Operator-ratified critères éliminatoires: A2 (COA) + A5 (traçabilité)
-- Additive update only — no schema change, no fiscal impact.

UPDATE supplier_evaluation_grid_criteria sc
JOIN supplier_evaluation_grids g ON g.id = sc.grid_id_fk
SET sc.is_ko_flag = 1
WHERE g.code = 'EF-01'
  AND g.version = '1.0'
  AND sc.code IN ('A2', 'A5');
