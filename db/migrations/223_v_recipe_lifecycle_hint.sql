-- db/migrations/223_v_recipe_lifecycle_hint.sql
--
-- What: Rebuild v_recipe_lifecycle to honour the new lifecycle_hint column
--       added by migration 222.
--
-- Two changes from migration 220's body (everything else is byte-identical):
--   1. The `operative` CTE now carries op_row.lifecycle_hint through from
--      the operative ref_recipes row.
--   2. The `with_state` CASE gains a first branch: when lifecycle_hint =
--      'historical' → state is forced to 'passee', regardless of brew
--      history or stock. All other branches are unchanged in meaning and
--      order. Contracts remain able to show 'upcoming'.
--
-- Effect on TM-ST (id=55, Contract, lifecycle_hint='historical'):
--   Before: lifecycle_state=upcoming, flag=production_a_venir, list_bucket=contrats
--   After : lifecycle_state=passee,   flag=NULL,               list_bucket=contrats
--   (list_bucket unchanged: Contract gate in the CASE fires before the
--   state-based branches, so TM-ST stays in contrats whether passee or not.)
--
-- Bucket totals: actives=15, contrats=42, passees=4 (unchanged — TM-ST
--   moves from upcoming→passee WITHIN contrats, not out of it).
--
-- Idempotency: CREATE OR REPLACE VIEW is always safe to re-run.
--
-- Date  : 2026-05-31
-- Author: migration_223

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
-- CHANGE 1 (vs mig 220): lifecycle_hint is carried through from op_row
operative AS (
    SELECT
        ia.*,
        -- sku_prefix from the newest-vintage row (NULL for EPH — known gap)
        op_row.sku_prefix,
        op_row.subtype,
        op_row.client_id,
        op_row.lifecycle_hint
    FROM identity_agg ia
    JOIN ref_recipes op_row
           ON op_row.id = ia.recipe_id
),

-- Derive lifecycle_state using the W window setting
-- CHANGE 2 (vs mig 220): lifecycle_hint='historical' forces 'passee' first,
-- before any other branch. Contracts can still show 'upcoming' when hint='auto'.
with_state AS (
    SELECT
        o.*,
        CASE
            WHEN o.lifecycle_hint = 'historical'
                THEN 'passee'
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
