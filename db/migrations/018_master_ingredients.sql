-- 018 — MasterIngredients reference catalog.
--
-- Four tables:
--   ref_mi_categories    : distinct categories (Malt, Hops, Packaging, …) with default GL
--   ref_mi_subcategories : distinct (category, subcategory) pairs with optional GL override
--   ref_mi               : one row per ingredient (natural key = mi_id, e.g. HOPS_CITRA_PEL)
--   ref_mi_aliases       : supplier-side name variants, one row per alias
--
-- Upsert policy (SCD Type 1):
--   ref_mi rows are upserted on each ingest run via ON DUPLICATE KEY UPDATE.
--   last_seen_at is touched on every successful pass; imported_at is set once (INSERT only).
--   Ingredients that vanish from BSF are flagged is_active=0 (not deleted) to preserve
--   any FK references from inv_deliveries and similar tables built in later chunks.
--
-- No seeding here — ingest_mi.py owns all data population.

CREATE TABLE IF NOT EXISTS ref_mi_categories (
  id                INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  name              VARCHAR(64)   NOT NULL,
  default_gl_account VARCHAR(8)   NULL,
  notes             TEXT          NULL,
  created_at        TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ref_mi_subcategories (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  category_id INT UNSIGNED NOT NULL,
  name        VARCHAR(64)  NOT NULL,
  gl_account  VARCHAR(8)   NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_cat_name (category_id, name),
  CONSTRAINT fk_subcat_category FOREIGN KEY (category_id)
    REFERENCES ref_mi_categories(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ref_mi (
  id                INT UNSIGNED   NOT NULL AUTO_INCREMENT,
  mi_id             VARCHAR(64)    NOT NULL,
  name              VARCHAR(255)   NOT NULL,
  category_id       INT UNSIGNED   NULL,
  subcategory_id    INT UNSIGNED   NULL,
  input_unit        VARCHAR(16)    NULL,
  pricing_unit      VARCHAR(16)    NULL,
  conversion_factor DECIMAL(14,6)  NULL,
  currency          VARCHAR(8)     NULL,
  price             DECIMAL(14,6)  NULL,
  pack_size         DECIMAL(14,4)  NULL,
  preferred_supplier VARCHAR(255)  NULL,
  gl_account        VARCHAR(8)     NULL,
  notes             TEXT           NULL,
  is_active         TINYINT        NOT NULL DEFAULT 1,
  row_hash          CHAR(64)       NOT NULL,
  last_seen_at      TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  imported_at       TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_mi_id         (mi_id),
  KEY idx_category              (category_id),
  KEY idx_subcategory           (subcategory_id),
  KEY idx_active                (is_active),
  KEY idx_pricing_unit          (pricing_unit),
  KEY idx_supplier              (preferred_supplier(64)),
  CONSTRAINT fk_mi_category     FOREIGN KEY (category_id)
    REFERENCES ref_mi_categories(id),
  CONSTRAINT fk_mi_subcategory  FOREIGN KEY (subcategory_id)
    REFERENCES ref_mi_subcategories(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ref_mi_aliases (
  id        INT UNSIGNED NOT NULL AUTO_INCREMENT,
  mi_id_fk  INT UNSIGNED NOT NULL,
  alias     VARCHAR(255) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_alias  (alias),
  KEY idx_mi_id_fk       (mi_id_fk),
  CONSTRAINT fk_alias_mi FOREIGN KEY (mi_id_fk)
    REFERENCES ref_mi(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
