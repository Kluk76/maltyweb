-- 012 — reference (dimension) tables: recipes, clients, vessels, yeast.
--
-- ref_recipes        : seeded by scripts/python/seed_references.py from BSF!BeerTypes
-- ref_clients        : derived from ref_recipes (auto-split on " - " + manual TM/NYL mappings)
-- ref_cct/yt/bbt     : seeded inline below with the brewery's actual capacities
-- ref_yeast_strains  : seeded by seed_references.py from observed bd_brewing_brewday data
--
-- No FKs from raw bd_* tables yet — those will follow in a later migration once
-- contract batches are backfilled and any unmatched ref values are reconciled.

CREATE TABLE IF NOT EXISTS ref_clients (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name        VARCHAR(128) NOT NULL,
  notes       TEXT         NULL,
  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ref_recipes (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name              VARCHAR(128) NOT NULL,                                  -- exact BeerName from BeerTypes (FK key)
  classification    ENUM('Neb','Contract') NOT NULL,
  subtype           ENUM('Core','EPH','CollabIn','CollabOut','WhiteLabel','Archive') NULL,
  client_id         INT UNSIGNED NULL,
  recipe_short_name VARCHAR(64)  NULL,                                      -- "Blonde" for "TM-BLO", "Zepp" for "Zepp"
  vintage           VARCHAR(8)   NULL,                                      -- "2022", "2023" for EPH
  sku_prefix        VARCHAR(8)   NULL,
  is_active         TINYINT      NOT NULL DEFAULT 1,
  notes             TEXT         NULL,
  created_at        TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_name           (name),
  KEY idx_classification         (classification),
  KEY idx_subtype                (subtype),
  KEY idx_client_id              (client_id),
  CONSTRAINT fk_recipe_client    FOREIGN KEY (client_id) REFERENCES ref_clients(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ref_cct (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  number       INT UNSIGNED NOT NULL,
  capacity_hl  DECIMAL(6,2) NULL,
  status       ENUM('active','maintenance','retired') NOT NULL DEFAULT 'active',
  notes        TEXT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_number (number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ref_yt (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  number       INT UNSIGNED NOT NULL,
  capacity_hl  DECIMAL(6,2) NULL,
  status       ENUM('active','maintenance','retired') NOT NULL DEFAULT 'active',
  notes        TEXT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_number (number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ref_bbt (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  number       INT UNSIGNED NOT NULL,
  capacity_hl  DECIMAL(6,2) NULL,
  status       ENUM('active','maintenance','retired') NOT NULL DEFAULT 'active',
  notes        TEXT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_number (number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ref_yeast_strains (
  id        INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name      VARCHAR(64)  NOT NULL,
  supplier  VARCHAR(64)  NULL,
  type      ENUM('ale','lager','wild','hybrid','unknown') NOT NULL DEFAULT 'unknown',
  notes     TEXT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ── Seed vessels (static — operator-confirmed capacities) ────────────────────

INSERT INTO ref_cct (number, capacity_hl) VALUES
  (1, 90.00),  (2, 90.00),  (3, 90.00),  (4, 90.00),
  (5, 30.00),  (6, 30.00),  (7, 30.00),  (8, 30.00),
  (9, 90.00),  (10, 90.00),
  (11, 120.00), (12, 120.00), (13, 120.00), (14, 120.00),
  (15, 150.00), (16, 150.00),
  (17, 120.00), (18, 120.00)
ON DUPLICATE KEY UPDATE capacity_hl = VALUES(capacity_hl);

INSERT INTO ref_yt (number, capacity_hl) VALUES
  (1, 12.00),
  (2, 10.00),
  (3, 10.00)
ON DUPLICATE KEY UPDATE capacity_hl = VALUES(capacity_hl);

INSERT INTO ref_bbt (number, capacity_hl) VALUES
  (1, 30.00),
  (2, 90.00), (3, 90.00), (4, 90.00), (5, 90.00),
  (6, 120.00), (7, 120.00),
  (8, 240.00)
ON DUPLICATE KEY UPDATE capacity_hl = VALUES(capacity_hl);
