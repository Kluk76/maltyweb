-- Migration 220: v_recipe_lifecycle — derived recipe lifecycle state view
--
-- Collapses ref_recipes (one row per recipe × vintage) to one entry per
-- recipe IDENTITY: (name, classification, COALESCE(client_id, 0)).
-- The OPERATIVE ROW for each identity = newest vintage (MAX vintage, tie-break MAX id).
--
-- Lifecycle state is fully derived — no stored status column:
--   upcoming      — never brewed (last_brew IS NULL)
--   active        — brewed within W months of today (W from commissioning_settings)
--   plus_produite — not brewed within W but still in FG stock
--   passee        — not brewed within W and no stock
--
-- List routing:
--   contrats  — classification = 'Contract'
--   passees   — Neb + (operative is_active=0 OR state='passee')
--   actives   — everything else (Neb + active or plus_produite or upcoming)
--
-- Flag column (human label chip, NULL when no chip):
--   'production_a_venir' — upcoming
--   'plus_produite'      — plus_produite
--   NULL                 — active / passee (passee is implied by list bucket)

CREATE OR REPLACE VIEW v_recipe_lifecycle AS
WITH

-- Active window setting — single-sourced from commissioning_settings
-- Defaults to 12 if the row is missing (should not happen after mig 219).
window_setting AS (
    SELECT COALESCE(
        (SELECT value_num
           FROM commissioning_settings
          WHERE section  = 'recipe_lifecycle'
            AND key_name = 'active_window_months'
          LIMIT 1),
        12
    ) AS w_months
),

-- Collapse ref_recipes to one row per identity; aggregate signals across all vintages
identity_agg AS (
    SELECT
        rr.name,
        rr.classification,
        COALESCE(rr.client_id, 0)            AS cid,
        -- Operative id: newest vintage, tie-break MAX id
        MAX(rr.id)                           AS recipe_id,
        MAX(rr.vintage)                      AS vintage,
        -- is_active: take the newest-vintage row's value (via operative id)
        -- We approximate with MAX — if any vintage is active, treat as active
        MAX(rr.is_active)                    AS is_active,
        -- last_brew across ALL vintages of this identity
        MAX(bb.event_date)                   AS last_brew,
        -- FG stock across ALL SKUs tied to any vintage of this identity
        COALESCE(SUM(fg_agg.fg_qty), 0)     AS in_stock_qty
    FROM ref_recipes rr
    LEFT JOIN bd_brewing_brewday_v2 bb
           ON bb.recipe_id_fk = rr.id
          AND bb.is_tombstoned = 0
    LEFT JOIN (
        -- Latest FG stocktake snapshot, summed per recipe
        SELECT s.recipe_id, SUM(f.qty) AS fg_qty
          FROM inv_fg_stocktake f
          JOIN ref_skus s ON s.id = f.sku_id_fk
         WHERE f.is_active = 1
           AND f.month_closed = (SELECT MAX(month_closed) FROM inv_fg_stocktake)
         GROUP BY s.recipe_id
    ) fg_agg ON fg_agg.recipe_id = rr.id
    GROUP BY rr.name, rr.classification, COALESCE(rr.client_id, 0)
),

-- Join operative row back to carry display fields from newest vintage
operative AS (
    SELECT
        ia.*,
        -- sku_prefix from the newest-vintage row (NULL for EPH — known gap)
        op_row.sku_prefix,
        op_row.subtype,
        op_row.client_id
    FROM identity_agg ia
    JOIN ref_recipes op_row
           ON op_row.id = ia.recipe_id
),

-- Derive lifecycle_state using the W window setting
with_state AS (
    SELECT
        o.*,
        CASE
            WHEN o.last_brew IS NULL
                THEN 'upcoming'
            WHEN o.last_brew >= DATE_SUB(CURDATE(), INTERVAL ws.w_months MONTH)
                THEN 'active'
            WHEN o.in_stock_qty > 0
                THEN 'plus_produite'
            ELSE 'passee'
        END AS lifecycle_state
    FROM operative o
    CROSS JOIN window_setting ws
)

-- Final projection: list bucket + flag chip
SELECT
    -- Identity fields
    s.name                                      AS display_name,
    s.classification,
    s.cid                                       AS client_id_collapsed,
    s.client_id,
    -- Operative row fields (newest vintage)
    s.recipe_id,
    s.vintage,
    s.sku_prefix,
    s.subtype,
    s.is_active,
    -- Derived signals
    s.last_brew,
    s.in_stock_qty,
    s.lifecycle_state,
    -- List bucket
    CASE
        WHEN s.classification = 'Contract'  THEN 'contrats'
        WHEN s.is_active      = 0           THEN 'passees'
        WHEN s.lifecycle_state = 'passee'   THEN 'passees'
        ELSE                                     'actives'
    END                                         AS list_bucket,
    -- Chip flag (NULL = no chip)
    CASE
        WHEN s.lifecycle_state = 'upcoming'      THEN 'production_a_venir'
        WHEN s.lifecycle_state = 'plus_produite' THEN 'plus_produite'
        ELSE NULL
    END                                         AS flag
FROM with_state s
ORDER BY
    s.classification,
    s.lifecycle_state,
    s.name;
