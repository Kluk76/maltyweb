-- Migration 205: CREATE OR REPLACE VIEW v_rm_stock_dynamic
--
-- Provides a read-only view of the current RM dynamic stock replay for the
-- maltyweb operator UI. Data is sourced from build-rm-stock-dynamic.ts which
-- writes data/rm-stock-dynamic.json; this view is a MySQL surface over the
-- canonical tables directly so the operator UI doesn't need to read JSON.
--
-- The view computes the latest inv_rm_stocktake anchor per MI, then adds
-- inv_deliveries inflows and subtracts inv_consumption outflows since the anchor.
-- Only inventoried MIs (ref_mi.is_inventoried = 1) are included.
--
-- Rollback: DROP VIEW IF EXISTS v_rm_stock_dynamic;

CREATE OR REPLACE VIEW v_rm_stock_dynamic AS
WITH anchor_period AS (
  -- Latest stocktake period per MI (single-period for now; multiple periods mean
  -- the MAX(period) ordering picks the most recent)
  SELECT
    mi_id_fk,
    MAX(period) AS anchor_month
  FROM inv_rm_stocktake
  WHERE is_active = 1
    AND mi_id_fk IS NOT NULL
  GROUP BY mi_id_fk
),
anchor_qty AS (
  SELECT
    r.mi_id_fk,
    ap.anchor_month,
    LAST_DAY(CONCAT(ap.anchor_month, '-01')) AS anchor_date,
    COALESCE(SUM(r.counted_qty), 0) AS qty
  FROM inv_rm_stocktake r
  JOIN anchor_period ap ON ap.mi_id_fk = r.mi_id_fk AND ap.anchor_month = r.period
  WHERE r.is_active = 1
    AND r.counted_qty IS NOT NULL
  GROUP BY r.mi_id_fk, ap.anchor_month
),
deliv_flows AS (
  SELECT
    d.ingredient_fk AS mi_id_fk,
    SUM(d.qty_delivered) AS deliveries_in,
    MAX(d.date_received) AS last_deliv_date
  FROM inv_deliveries d
  JOIN anchor_qty aq ON aq.mi_id_fk = d.ingredient_fk
  WHERE d.ingredient_fk IS NOT NULL
    AND d.status IN ('Active', 'Pending')
    AND d.date_received > aq.anchor_date
  GROUP BY d.ingredient_fk
),
cons_flows AS (
  SELECT
    c.mi_id_fk,
    SUM(c.qty) AS consumption_out,
    MAX(c.consumed_at) AS last_cons_date
  FROM inv_consumption c
  JOIN anchor_qty aq ON aq.mi_id_fk = c.mi_id_fk
  WHERE c.mi_id_fk IS NOT NULL
    AND c.consumed_at > aq.anchor_date
  GROUP BY c.mi_id_fk
)
SELECT
  m.id                                              AS mi_id_fk,
  m.mi_id,
  m.name                                            AS item,
  cat.name                                          AS category,
  sub.name                                          AS subcategory,
  m.pricing_unit                                    AS unit,
  aq.anchor_month,
  aq.anchor_date,
  COALESCE(aq.qty, 0)                               AS anchor_qty,
  COALESCE(df.deliveries_in, 0)                     AS deliveries_in,
  COALESCE(cf.consumption_out, 0)                   AS consumption_out,
  ROUND(
    COALESCE(aq.qty, 0)
    + COALESCE(df.deliveries_in, 0)
    - COALESCE(cf.consumption_out, 0),
    3
  )                                                 AS current_qty,
  CASE
    WHEN m.currency = 'EUR' THEN ROUND(m.price * 0.945, 4)
    ELSE m.price
  END                                               AS price_chf,
  ROUND(
    (
      COALESCE(aq.qty, 0)
      + COALESCE(df.deliveries_in, 0)
      - COALESCE(cf.consumption_out, 0)
    ) * CASE
      WHEN m.currency = 'EUR' THEN m.price * 0.945
      ELSE m.price
    END,
    2
  )                                                 AS current_value_chf,
  GREATEST(
    COALESCE(df.last_deliv_date, '1970-01-01'),
    COALESCE(cf.last_cons_date,  '1970-01-01')
  )                                                 AS last_movement_date,
  DATEDIFF(
    CURRENT_DATE,
    GREATEST(
      COALESCE(df.last_deliv_date, aq.anchor_date),
      COALESCE(cf.last_cons_date,  aq.anchor_date)
    )
  )                                                 AS days_since_last_movement
FROM ref_mi m
JOIN anchor_qty aq          ON aq.mi_id_fk = m.id
LEFT JOIN ref_mi_categories cat ON cat.id = m.category_id
LEFT JOIN ref_mi_subcategories sub ON sub.id = m.subcategory_id
LEFT JOIN deliv_flows df    ON df.mi_id_fk = m.id
LEFT JOIN cons_flows cf     ON cf.mi_id_fk = m.id
WHERE m.is_inventoried = 1
ORDER BY cat.name, m.name;

-- schema_meta row
INSERT INTO schema_meta (table_name, table_class, corrections_policy, writer_script, upstream_hint)
VALUES (
  'v_rm_stock_dynamic',
  'derived',
  'blocked_with_redirect',
  'build-rm-stock-dynamic.ts',
  'View derived from inv_rm_stocktake + inv_deliveries + inv_consumption. Re-run build-rm-stock-dynamic.ts --apply to refresh.'
)
ON DUPLICATE KEY UPDATE
  table_class         = VALUES(table_class),
  corrections_policy  = VALUES(corrections_policy),
  writer_script       = VALUES(writer_script),
  upstream_hint       = VALUES(upstream_hint);
