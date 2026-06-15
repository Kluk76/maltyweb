-- 359_qa_cleaning_efficacy_checks.sql
--
-- HACCP PRP-04 cleaning-efficacy record.
--
-- Standalone table: a check can exist without a linked CIP event.
-- cip_event_id_fk is an optional traceability link only — the check is
-- complete and meaningful without it.
--
-- method values:
--   atp          — ATP bioluminescence (RLU)
--   swab         — contact-plate or swab culture (CFU/cm2)
--   visual       — qualitative visual inspection
--   rinse_water  — rinse-water microbiology
--
-- outcome starts as 'pending' until reviewed; corrective_action is
-- required when outcome IN ('fail', 'marginal').

CREATE TABLE `qa_cleaning_efficacy_checks` (
  `id`                        bigint unsigned  NOT NULL AUTO_INCREMENT COMMENT 'PK',
  `check_date`                date             NOT NULL COMMENT 'Calendar date of the cleaning-efficacy check',
  `method`                    enum('atp','swab','visual','rinse_water') COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Test method used: atp (ATP bioluminescence), swab (contact-plate/swab culture), visual (qualitative), rinse_water (rinse microbiology)',
  `surface_label`             varchar(128)     COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Free-text label identifying the surface or equipment tested (no lookup table this build)',
  `cip_event_id_fk`           bigint unsigned  DEFAULT NULL COMMENT 'FK→bd_cip_events(id) ON DELETE SET NULL — optional traceability link to the preceding CIP event; NULL when no CIP event is linked',
  `result_value`              decimal(10,2)    DEFAULT NULL COMMENT 'Numeric test result: RLU for atp, CFU/cm2 for swab; NULL for qualitative methods (visual, rinse_water)',
  `result_unit`               varchar(16)      COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Unit of result_value (e.g. RLU, CFU/cm2); NULL for qualitative methods',
  `threshold_value`           decimal(10,2)    DEFAULT NULL COMMENT 'Alert threshold in the same unit as result_value; NULL when no numeric threshold defined',
  `outcome`                   enum('pass','fail','marginal','pending') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending' COMMENT 'Review outcome; starts as pending. corrective_action required when fail or marginal',
  `corrective_action`         text             COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Description of corrective action taken; required when outcome IN (fail, marginal)',
  `measured_at`               datetime(6)      DEFAULT NULL COMMENT 'Exact timestamp the test was taken (microsecond precision); NULL when only check_date is known',
  `submitted_by_user_id_fk`  int unsigned     DEFAULT NULL COMMENT 'FK→users(id) — operator who submitted the check; NULL if not captured',
  `comments`                  text             COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Free-text notes on this check',
  `row_hash`                  char(64)         COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'UNIQUE dedup key; handler computes SHA2 over canonical fields',
  `created_at`                timestamp        NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Row insertion timestamp',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_qa_cec_row_hash` (`row_hash`),
  KEY `idx_qa_cec_check_date` (`check_date`),
  KEY `idx_qa_cec_cip` (`cip_event_id_fk`),
  CONSTRAINT `fk_qa_cec_cip` FOREIGN KEY (`cip_event_id_fk`)
    REFERENCES `bd_cip_events` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_qa_cec_user` FOREIGN KEY (`submitted_by_user_id_fk`)
    REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
  COMMENT='HACCP PRP-04 cleaning-efficacy records (ATP/swab/visual/rinse). Standalone — a check can exist without a linked CIP event.';

-- schema_meta
INSERT INTO `schema_meta` (table_name, table_class, corrections_policy, writer_script, notes)
VALUES (
  'qa_cleaning_efficacy_checks',
  'source',
  'allowed',
  '/modules/qa.php',
  'HACCP PRP-04 cleaning-efficacy records (ATP/swab/visual/rinse). Standalone — a check can exist without a linked CIP event. cip_event_id_fk is an optional traceability link only. result_value unit given by result_unit column (RLU, CFU/cm2, etc.). outcome=pending until reviewed. corrective_action required when outcome IN (fail, marginal).'
) ON DUPLICATE KEY UPDATE notes = VALUES(notes), writer_script = VALUES(writer_script);
