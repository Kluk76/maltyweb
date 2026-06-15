-- 360_qa_bottle_reception_checks.sql
--
-- HACCP incoming-glass QA gate.
--
-- Replaces visual-only bottle-reception check.  Records weight/volume
-- control at each glass-bottle delivery; child of inv_deliveries (optional).
--
-- A failed or marginal reception triggers a quarantine SIGNAL only.
-- It NEVER modifies inv_deliveries.qty_delivered, unit_price, or any
-- stock/COGS surface.
--
-- Unit convention matches qa_net_content_readings (canonical):
--   measure_type = 'weight'  →  measured_value in g  (mean of sample)
--   measure_type = 'volume'  →  measured_value in mL (mean of sample)
--
-- delivery_id_fk and mi_id_fk are both nullable traceability links.

CREATE TABLE `qa_bottle_reception_checks` (
  `id`                        bigint unsigned  NOT NULL AUTO_INCREMENT COMMENT 'PK',
  `delivery_id_fk`            bigint unsigned  DEFAULT NULL COMMENT 'FK→inv_deliveries(id) ON DELETE SET NULL — optional traceability link to the delivery row; NULL when check is not tied to a specific delivery',
  `mi_id_fk`                  int unsigned     DEFAULT NULL COMMENT 'FK→ref_mi(id) ON DELETE SET NULL — the glass article being checked; NULL if not resolved at entry time',
  `reception_date`            date             NOT NULL COMMENT 'Date of the reception inspection',
  `lot_ref`                   varchar(64)      COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Supplier lot or batch reference printed on the packaging',
  `measure_type`              enum('weight','volume') COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Measurement type: weight (g) or volume (mL) — same unit convention as qa_net_content_readings',
  `sample_size`               int unsigned     DEFAULT NULL COMMENT 'Number of bottles measured in the sample; NULL if not recorded',
  `measured_value`            decimal(10,3)    NOT NULL COMMENT 'Mean of sample in g (weight) or mL (volume) — canonical unit, never re-derived',
  `target_value`              decimal(10,3)    DEFAULT NULL COMMENT 'Nominal value per specification (same unit as measured_value); NULL if not set',
  `tolerance_abs`             decimal(10,3)    DEFAULT NULL COMMENT '± allowed deviation (absolute, same unit as measured_value); NULL if not set',
  `outcome`                   enum('pass','fail','marginal') COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Reception outcome. fail or marginal is a quarantine SIGNAL only — never modifies inv_deliveries stock or COGS',
  `submitted_by_user_id_fk`  int unsigned     DEFAULT NULL COMMENT 'FK→users(id) — operator who entered the check; NULL if not captured',
  `comments`                  text             COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Free-text notes on this reception check',
  `row_hash`                  char(64)         COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'UNIQUE dedup key; handler computes SHA2 over canonical fields',
  `created_at`                timestamp        NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Row insertion timestamp',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_qa_brc_row_hash` (`row_hash`),
  KEY `idx_qa_brc_delivery` (`delivery_id_fk`),
  KEY `idx_qa_brc_mi` (`mi_id_fk`),
  CONSTRAINT `fk_qa_brc_delivery` FOREIGN KEY (`delivery_id_fk`)
    REFERENCES `inv_deliveries` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_qa_brc_mi` FOREIGN KEY (`mi_id_fk`)
    REFERENCES `ref_mi` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_qa_brc_user` FOREIGN KEY (`submitted_by_user_id_fk`)
    REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
  COMMENT='HACCP incoming-glass QA gate. Weight/volume control at each glass delivery. outcome=fail/marginal is a quarantine SIGNAL only — never modifies inv_deliveries or COGS.';

-- schema_meta
INSERT INTO `schema_meta` (table_name, table_class, corrections_policy, writer_script, notes)
VALUES (
  'qa_bottle_reception_checks',
  'source',
  'allowed',
  '/modules/qa.php',
  'HACCP incoming-glass QA gate. Weight/volume control at each glass delivery. measured_value = mean of sample (g for weight, mL for volume — same unit convention as qa_net_content_readings). outcome=fail/marginal is a quarantine SIGNAL only — NEVER modifies inv_deliveries.qty_delivered, unit_price, or any stock/COGS surface. delivery_id_fk and mi_id_fk are traceability links; both nullable.'
) ON DUPLICATE KEY UPDATE notes = VALUES(notes), writer_script = VALUES(writer_script);
