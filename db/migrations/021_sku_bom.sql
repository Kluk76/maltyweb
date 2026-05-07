-- 021 — SKU catalog and Bill of Materials.
--
-- Two tables:
--   ref_skus     : one row per sellable SKU code (ZEPF, ZEP4, ZEPC, …)
--   ref_sku_bom  : dependency graph — many rows per SKU, one row per
--                  (sku, ingredient_raw, source) tuple.
--
-- ref_skus links each SKU to its recipe (ref_recipes) where a match exists.
-- ref_sku_bom links each BOM line to its MI ingredient (ref_mi) where resolvable.
--
-- Upsert policy (SCD Type 1):
--   Both tables are upserted on each ingest run via ON DUPLICATE KEY UPDATE.
--   last_seen_at is refreshed every run; imported_at is set once (INSERT only).
--   SKUs absent from the current snapshot are soft-flagged via is_active=0 (not
--   deleted — future FK references must survive).
--   BOM lines absent from the current snapshot are hard-deleted — the BOM is
--   entirely derived/computed from the source tab, so stale lines must go.
--
-- No seeding here — scripts/python/ingest_sku_bom.py owns all data population.

CREATE TABLE IF NOT EXISTS ref_skus (
  id            INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  sku_code      VARCHAR(16)   NOT NULL,
  recipe_id     INT UNSIGNED  NULL,
  beer_raw      VARCHAR(128)  NULL,
  format        VARCHAR(16)   NULL,
  unit_label    VARCHAR(128)  NULL,
  hl_per_unit   DECIMAL(10,5) NULL,
  is_active     TINYINT       NOT NULL DEFAULT 1,
  row_hash      CHAR(64)      NOT NULL,
  last_seen_at  TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  imported_at   TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_sku_code     (sku_code),
  KEY idx_recipe                (recipe_id),
  KEY idx_format                (format),
  KEY idx_active                (is_active),
  CONSTRAINT fk_sku_recipe      FOREIGN KEY (recipe_id) REFERENCES ref_recipes(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ref_sku_bom (
  id             INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  sku_id         INT UNSIGNED  NOT NULL,
  mi_id          INT UNSIGNED  NULL,                        -- FK ref_mi.id (NULL if unresolved)
  ingredient_raw VARCHAR(255)  NOT NULL,                    -- always preserved verbatim
  source         VARCHAR(32)   NULL,                        -- 'Brewing' | 'Packaging'
  category_raw   VARCHAR(64)   NULL,
  qty_per_unit   DECIMAL(14,6) NULL,
  ing_unit       VARCHAR(16)   NULL,
  pricing_unit   VARCHAR(16)   NULL,
  price          DECIMAL(14,6) NULL,
  currency       VARCHAR(8)    NULL,
  cost           DECIMAL(14,6) NULL,
  resolution     VARCHAR(32)   NOT NULL DEFAULT 'unresolved',  -- 'mi_match' | 'alias' | 'unresolved'
  row_hash       CHAR(64)      NOT NULL,
  last_seen_at   TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  imported_at    TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_sku_ing_src  (sku_id, ingredient_raw(191), source),
  KEY idx_mi_id                (mi_id),
  KEY idx_source               (source),
  KEY idx_resolution           (resolution),
  CONSTRAINT fk_bom_sku        FOREIGN KEY (sku_id) REFERENCES ref_skus(id) ON DELETE CASCADE,
  CONSTRAINT fk_bom_mi         FOREIGN KEY (mi_id)  REFERENCES ref_mi(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
