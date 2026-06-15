-- 358_qa_net_content_readings.sql
--
-- HACCP QA observation layer — net content control at packaging.
--
-- Records per-sample net weight/volume measurements taken during a
-- conditionnement run (child of bd_packaging_v2).  Pure QA observation:
-- this table NEVER alters vendable_hl, beer_tax, or inv_deliveries.
--
-- is_conforming is derived at write by the handler:
--   |measured_value − target_value| ≤ tolerance_abs  →  1
--   otherwise                                         →  0
--   NULL when no target_value is set.
--
-- Unit convention (canonical, never re-derived):
--   measure_type = 'weight'  →  measured_value in g
--   measure_type = 'volume'  →  measured_value in mL

CREATE TABLE `qa_net_content_readings` (
  `id`                        bigint unsigned  NOT NULL AUTO_INCREMENT COMMENT 'PK',
  `packaging_id_fk`           bigint unsigned  NOT NULL COMMENT 'FK→bd_packaging_v2(id) — the conditionnement run this sample belongs to',
  `reading_seq`               int unsigned     NOT NULL COMMENT 'Sample index 1..N within the packaging run',
  `measure_type`              enum('weight','volume') COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Measurement type: weight (g) or volume (mL)',
  `measured_value`            decimal(10,3)    NOT NULL COMMENT 'Measured net content: g when measure_type=weight, mL when measure_type=volume — canonical convention, never re-derived',
  `target_value`              decimal(10,3)    DEFAULT NULL COMMENT 'Nominal net content (same unit as measured_value); NULL if not set',
  `tolerance_abs`             decimal(10,3)    DEFAULT NULL COMMENT '± allowed deviation (absolute, same unit as measured_value); NULL if not set',
  `is_conforming`             tinyint(1)       DEFAULT NULL COMMENT 'Derived at write by handler: 1 when |measured_value−target_value|≤tolerance_abs, 0 when non-conforming, NULL when no target set',
  `tare_value`                decimal(10,3)    DEFAULT NULL COMMENT 'Gross-weight tare used when measuring gross weight (g); NULL for direct net or volume measurements',
  `measured_at`               datetime(6)      NOT NULL COMMENT 'Exact timestamp the sample was measured (microsecond precision)',
  `submitted_by_user_id_fk`  int unsigned     DEFAULT NULL COMMENT 'FK→users(id) — operator who entered the reading; NULL if not captured',
  `comments`                  text             COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Free-text notes on this reading',
  `row_hash`                  char(64)         COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'UNIQUE dedup key; handler computes SHA2(CONCAT(packaging_id_fk,\"|\",reading_seq,\"|\",measured_at), 256)',
  `created_at`                timestamp        NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Row insertion timestamp',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_qa_ncr_row_hash` (`row_hash`),
  KEY `idx_qa_ncr_packaging` (`packaging_id_fk`),
  CONSTRAINT `fk_qa_ncr_packaging` FOREIGN KEY (`packaging_id_fk`)
    REFERENCES `bd_packaging_v2` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_qa_ncr_user` FOREIGN KEY (`submitted_by_user_id_fk`)
    REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
  COMMENT='HACCP net-content control readings per packaging run. Pure QA observation — never alters vendable_hl, beer_tax, or inv_deliveries.';

-- schema_meta
INSERT INTO `schema_meta` (table_name, table_class, corrections_policy, writer_script, notes)
VALUES (
  'qa_net_content_readings',
  'source',
  'allowed',
  '/modules/qa.php',
  'HACCP net-content control readings per packaging run. measured_value unit: g when measure_type=weight, mL when measure_type=volume — canonical convention, never re-derived. is_conforming derived at write (|measured−target|≤tolerance_abs); NULL when no target set. Pure QA observation — NEVER alters vendable_hl, beer_tax, or inv_deliveries.'
) ON DUPLICATE KEY UPDATE notes = VALUES(notes), writer_script = VALUES(writer_script);
