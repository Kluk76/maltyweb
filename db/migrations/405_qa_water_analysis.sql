-- 405_qa_water_analysis.sql
-- Observation table: water analysis results keyed by sample point + parameter.
-- NON-FISCAL — NEVER feeds COGS/stock/WAC/BOM/beer-tax.
-- action_limit is a human-readable snapshot of the limit at write-time (audit truth; survives later ref edits).
-- is_conforming derived at write per limit_operator; NULL when no limit defined (semantic NULL, intentional).
-- created_by_fk → users.id (INT UNSIGNED, matches users PK).
-- row_hash provides idempotent re-ingest.

CREATE TABLE qa_water_analysis (
  id                 INT UNSIGNED  NOT NULL AUTO_INCREMENT PRIMARY KEY,
  sample_point_id_fk INT UNSIGNED  NOT NULL,
  parameter_id_fk    INT UNSIGNED  NOT NULL,
  measured_value     DECIMAL(12,4) NULL,
  measured_text      VARCHAR(64)   NULL,
  unit               VARCHAR(32)   NULL,
  action_limit       VARCHAR(120)  NULL,
  is_conforming      TINYINT(1)    NULL,
  lab_name           VARCHAR(190)  NULL,
  method             VARCHAR(190)  NULL,
  sampled_at         DATETIME      NOT NULL,
  report_ref         VARCHAR(120)  NULL,
  comments           VARCHAR(1000) NULL,
  row_hash           CHAR(64)      NOT NULL,
  created_by_fk      INT UNSIGNED  NULL,
  created_at         TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_qwa_rowhash (row_hash),
  KEY idx_qwa_point  (sample_point_id_fk),
  KEY idx_qwa_param  (parameter_id_fk),
  KEY idx_qwa_sampled (sampled_at),
  CONSTRAINT fk_qwa_point FOREIGN KEY (sample_point_id_fk) REFERENCES ref_water_sample_points(id),
  CONSTRAINT fk_qwa_param FOREIGN KEY (parameter_id_fk)    REFERENCES ref_water_parameters(id),
  CONSTRAINT fk_qwa_user  FOREIGN KEY (created_by_fk)      REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO schema_meta
  (table_name, table_class, corrections_policy, writer_script, notes)
VALUES
  ('qa_water_analysis', 'source', 'allowed',
   '/modules/qa.php',
   'NON-FISCAL observation. Analyses internes d''eau aux points sensibles. NEVER feeds COGS/stock/WAC/BOM/beer-tax. action_limit snapshot at write.');
