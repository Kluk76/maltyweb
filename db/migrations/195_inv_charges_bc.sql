-- db/migrations/195_inv_charges_bc.sql
--
-- What: New source table inv_charges_bc — canonicalises the bookkeeper's
--       monthly Business Central GL journal export (BSF ChargesBC tab).
--
-- Why:  ChargesBC is the sole raw source for the monthly GL accrual pipeline
--       (lib/accrual.js, sources/charges-bc.js). It lives only in BSF today.
--       Phase 1 BSF-exit: this table makes the data MySQL-canonical so the
--       accrual pipeline can eventually stop hitting BSF.
--
-- Source: BSF ChargesBC A2:AU (47 cols). Live-inspected 2026-05-28: 441 rows.
--
-- Column selection rationale:
--   The 47-col BC export is a REPORT LAYOUT, not a clean relational export.
--   Many columns are repeated caption/metadata values that belong to the report
--   template (CompanyName, period text, caption labels, balance running totals).
--   We store only the ACCOUNTING-RELEVANT columns as typed fields, plus the
--   full raw row as `raw_json` for auditability and future re-extraction.
--
--   Typed accounting columns (from BSF header inspection):
--     H  No_GLAccount               → gl_account_no    VARCHAR(20)  (e.g. 4101)
--     F  Name_GLAccount             → gl_account_name  VARCHAR(128)
--     B  PeriodGlJourDateFilter     → period_text      VARCHAR(64)  (e.g. "Période : 01.03.26..31.03.26")
--     Z  DebitAmount_GLEntry        → debit_amount     DECIMAL(14,4) NULL
--     AA CreditAmount_GLEntry       → credit_amount    DECIMAL(14,4) NULL
--     AH Description_GLEntry        → description      TEXT NULL
--     AI DocumentNo_GLEntry         → document_no      VARCHAR(64)  NULL
--     AJ PostingDateFormatted_GLEntry→ posting_date_txt VARCHAR(32) NULL (BC formatted)
--     AO PostingDate_GLEntry        → posting_date     DATE NULL (parsed from AO)
--     AL EntryNo_GLEntry            → entry_no         VARCHAR(32)  NULL
--     AF BalAccountNo_GLEntry       → bal_account_no   VARCHAR(32)  NULL
--     AT Exrate                     → exrate           VARCHAR(32)  NULL (often NULL string)
--
--   Non-accounting / summary rows (e.g. "Solde final" rows) will have NULL
--   entry_no + NULL document_no — they are stored but flagged via is_summary.
--
-- Dedup key: (period_text, gl_account_no, entry_no) — entry_no is unique per
--   posting within a GL account + period. Summary rows use row_hash.
--
-- Rollback:
--   DROP TABLE IF EXISTS inv_charges_bc;
--   DELETE FROM schema_meta WHERE table_name = 'inv_charges_bc';
--
-- NOTE: No bare SELECT statements.

-- ============================================================================
-- TABLE: inv_charges_bc
-- ============================================================================

CREATE TABLE IF NOT EXISTS inv_charges_bc (
  id                  BIGINT UNSIGNED     NOT NULL AUTO_INCREMENT,

  -- ── GL ACCOUNT ─────────────────────────────────────────────────────────
  gl_account_no       VARCHAR(20)         NULL
                        COLLATE utf8mb4_unicode_ci,
  gl_account_name     VARCHAR(128)        NULL
                        COLLATE utf8mb4_unicode_ci,

  -- ── PERIOD ─────────────────────────────────────────────────────────────
  period_text         VARCHAR(64)         NULL
                        COLLATE utf8mb4_unicode_ci
                        COMMENT 'Raw BC period filter string e.g. Période : 01.03.26..31.03.26',

  -- ── AMOUNTS ────────────────────────────────────────────────────────────
  debit_amount        DECIMAL(14,4)       NULL
                        COMMENT 'DebitAmount_GLEntry (col Z)',
  credit_amount       DECIMAL(14,4)       NULL
                        COMMENT 'CreditAmount_GLEntry (col AA)',

  -- ── ENTRY FIELDS ───────────────────────────────────────────────────────
  description         TEXT                NULL
                        COLLATE utf8mb4_unicode_ci
                        COMMENT 'Description_GLEntry (col AH)',
  document_no         VARCHAR(64)         NULL
                        COLLATE utf8mb4_unicode_ci
                        COMMENT 'DocumentNo_GLEntry (col AI)',
  posting_date_txt    VARCHAR(32)         NULL
                        COLLATE utf8mb4_unicode_ci
                        COMMENT 'PostingDateFormatted_GLEntry (col AJ) — raw BC string',
  posting_date        DATE                NULL
                        COMMENT 'PostingDate_GLEntry (col AO) — parsed date',
  entry_no            VARCHAR(32)         NULL
                        COLLATE utf8mb4_unicode_ci
                        COMMENT 'EntryNo_GLEntry (col AL)',
  bal_account_no      VARCHAR(32)         NULL
                        COLLATE utf8mb4_unicode_ci
                        COMMENT 'BalAccountNo_GLEntry (col AF)',
  exrate              DECIMAL(10,6)       NULL
                        COMMENT 'Exrate (col AT) — FX rate when applicable',

  -- ── SUMMARY FLAG ───────────────────────────────────────────────────────
  is_summary          TINYINT(1)          NOT NULL DEFAULT 0
                        COMMENT '1 = opening/closing balance or total row (no entry_no)',

  -- ── RAW ARCHIVE ────────────────────────────────────────────────────────
  raw_json            JSON                NULL
                        COMMENT 'Full 47-col raw BC row for auditability',

  -- ── AUDIT ──────────────────────────────────────────────────────────────
  row_hash            CHAR(64)            NOT NULL
                        COLLATE utf8mb4_unicode_ci,
  last_modified_by    ENUM('ingest','web')
                        NOT NULL DEFAULT 'ingest',
  created_at          TIMESTAMP           NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          TIMESTAMP           NOT NULL DEFAULT CURRENT_TIMESTAMP
                        ON UPDATE CURRENT_TIMESTAMP,

  -- ── CONSTRAINTS ────────────────────────────────────────────────────────
  PRIMARY KEY (id),
  UNIQUE KEY uq_inv_charges_bc_row_hash (row_hash),

  -- Partial unique index: transactional rows have a unique entry per GL acct + period
  -- Summary rows (is_summary=1) are deduped by row_hash only
  KEY idx_inv_charges_bc_period_gl   (period_text(32), gl_account_no),
  KEY idx_inv_charges_bc_posting_date (posting_date),
  KEY idx_inv_charges_bc_document_no  (document_no),
  KEY idx_inv_charges_bc_entry_no     (entry_no)

) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
  COMMENT='Canonical store for Business Central GL journal exports (BSF ChargesBC tab). Phase 1 BSF-exit foundation migration 195.';

-- ============================================================================
-- schema_meta row for inv_charges_bc
-- ============================================================================

INSERT INTO schema_meta
  (table_name, table_class, writer_script, corrections_policy, notes)
VALUES (
  'inv_charges_bc',
  'source',
  'scripts/ingest-charges-bc.ts (Phase 7) / python ingest_charges_bc.py',
  'allowed_with_side_effect',
  'Phase 1 BSF-exit foundation. Monthly GL journal from Business Central, pasted by bookkeeper into BSF ChargesBC tab. Raw 47-col BC report layout — only accounting-relevant typed columns extracted; full raw row archived in raw_json. Seeded from BSF via scripts/_phase-bsf-exit/seed-inv-charges-bc.ts. Side-effect: changes here flow into the accrual pipeline (lib/accrual.js) and COGS GL routing — rerun accrual after any correction.'
)
ON DUPLICATE KEY UPDATE
  table_class        = VALUES(table_class),
  writer_script      = VALUES(writer_script),
  corrections_policy = VALUES(corrections_policy),
  notes              = VALUES(notes);

-- end migration 195
