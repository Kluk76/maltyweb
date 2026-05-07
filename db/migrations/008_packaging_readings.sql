-- bd_packaging_readings
-- Child of bd_packaging — externalized 15 paired O2/CO2 readings (PackagingData
-- cols 14-43, indices N-AR). Each parent row produces up to 15 readings;
-- (packaging_id, reading_idx) is the natural key.

CREATE TABLE IF NOT EXISTS bd_packaging_readings (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  packaging_id    BIGINT UNSIGNED NOT NULL,
  reading_idx     TINYINT UNSIGNED NOT NULL,                       -- 1..15
  o2              DECIMAL(6,3)    NULL,
  co2             DECIMAL(6,3)    NULL,
  imported_at     TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  UNIQUE KEY uniq_pkg_reading (packaging_id, reading_idx),
  KEY idx_packaging_id        (packaging_id),
  CONSTRAINT fk_readings_packaging
    FOREIGN KEY (packaging_id) REFERENCES bd_packaging(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
