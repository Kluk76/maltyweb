-- =============================================================================
-- Migration 409: Supplier Evaluation Foundation (Wave 1)
-- Tables: supplier_evaluation_grids, supplier_evaluation_grid_criteria,
--         supplier_evaluations, supplier_evaluation_criteria,
--         supplier_cert_documents, supplier_nc
-- ALTER: ref_suppliers ADD criticality
-- Seed: EF-01 v1.0 grid + 11 criteria (all is_ko_flag=0)
-- schema_meta: 6 rows
-- NON-FISCAL — this evaluation layer is strictly additive and never feeds
-- COGS/COP/WAC/BOM/beer-tax/stock.
-- =============================================================================

-- ─────────────────────────────────────────────────────────────────────────────
-- 1. supplier_evaluation_grids (reference/allowed)
--    Grid version header — weights are DATA; past evals pin their grid_id_fk
--    so they never rescore when the grid evolves.
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE supplier_evaluation_grids (
    id                  SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
    code                VARCHAR(20) NOT NULL              COMMENT 'Short grid identifier e.g. EF-01',
    version             VARCHAR(20) NOT NULL              COMMENT 'Version string e.g. 1.0',
    label               VARCHAR(200) NOT NULL             COMMENT 'Full human label',
    threshold_agree     DECIMAL(5,2) NOT NULL DEFAULT 75.00  COMMENT 'Min % to be Agréé',
    threshold_surveillance DECIMAL(5,2) NOT NULL DEFAULT 50.00 COMMENT 'Min % for Agréé sous surveillance',
    pillar_a_max        SMALLINT UNSIGNED NOT NULL DEFAULT 22,
    pillar_b_max        SMALLINT UNSIGNED NOT NULL DEFAULT 13,
    is_active           TINYINT(1) NOT NULL DEFAULT 0     COMMENT 'Exactly one active grid; enforced in handler',
    effective_from      DATE NOT NULL,
    notes               VARCHAR(500) NULL,
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by          INT UNSIGNED NULL                 COMMENT 'Soft ref to users.id',
    PRIMARY KEY (id),
    UNIQUE KEY uniq_grid_version_label (code, version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- 2. supplier_evaluation_grid_criteria (reference/allowed)
--    One row per criterion per grid version.
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE supplier_evaluation_grid_criteria (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    grid_id_fk      SMALLINT UNSIGNED NOT NULL            COMMENT 'FK → supplier_evaluation_grids.id',
    pillar          ENUM('A','B') NOT NULL,
    code            VARCHAR(8) NOT NULL                   COMMENT 'A1…A6, B1…B5',
    label           VARCHAR(200) NOT NULL,
    max_score       SMALLINT UNSIGNED NOT NULL,
    evidence_source ENUM('auto_delivery','auto_criticality','manual_cert','manual_coa',
                         'manual_nc','manual_esg','manual') NOT NULL DEFAULT 'manual'
                                                          COMMENT 'Drives which criteria the UI auto-fills vs prompts',
    is_ko_flag      TINYINT(1) NOT NULL DEFAULT 0         COMMENT 'KO criteria force non_agree; operator ratifies — do NOT guess',
    display_order   SMALLINT UNSIGNED NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_grid_criterion_code (grid_id_fk, code),
    CONSTRAINT fk_segc_grid FOREIGN KEY (grid_id_fk)
        REFERENCES supplier_evaluation_grids (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- 3. supplier_evaluations (source/allowed)
--    One row per evaluation EVENT (assessment header).
--    NON-FISCAL — never feeds COGS/stock/WAC/BOM/beer-tax.
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE supplier_evaluations (
    id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    supplier_id_fk      INT UNSIGNED NOT NULL             COMMENT 'FK → ref_suppliers.id (INT UNSIGNED)',
    grid_id_fk          SMALLINT UNSIGNED NOT NULL        COMMENT 'FK → supplier_evaluation_grids.id; RESTRICT so grid def survives',
    evaluation_type     ENUM('initial','annuel','biennal','evenementiel') NOT NULL,
    pillar_a_score      SMALLINT UNSIGNED NULL            COMMENT 'Computed from criteria; NULL while draft',
    pillar_b_score      SMALLINT UNSIGNED NULL,
    total_pct           DECIMAL(5,2) NULL                 COMMENT 'Recomputed server-side on every save; never trust client',
    food_safety_ko      TINYINT(1) NOT NULL DEFAULT 0     COMMENT 'KO flag forces result=non_agree regardless of score',
    result              ENUM('agree','agree_sous_surveillance','non_agree','draft') NOT NULL DEFAULT 'draft',
    evaluated_at        DATE NULL                         COMMENT 'Date of assessment; NULL while draft',
    valid_until         DATE NULL                         COMMENT 'Cadence-driven next-review date',
    evaluator_user_id   INT UNSIGNED NULL                 COMMENT 'Soft ref to users.id',
    comment             TEXT NULL,
    status              ENUM('draft','final') NOT NULL DEFAULT 'draft'
                                                          COMMENT 'draft=editable; final=sealed, supersede via NEW row',
    superseded_by_id    INT UNSIGNED NULL                 COMMENT 'Self-ref restate chain; old row retained, never in-place rewrite',
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_se_supplier (supplier_id_fk, evaluated_at),
    KEY ix_se_valid (valid_until, status),
    CONSTRAINT fk_se_supplier FOREIGN KEY (supplier_id_fk)
        REFERENCES ref_suppliers (id) ON DELETE CASCADE,
    CONSTRAINT fk_se_grid FOREIGN KEY (grid_id_fk)
        REFERENCES supplier_evaluation_grids (id) ON DELETE RESTRICT,
    CONSTRAINT fk_se_superseded_by FOREIGN KEY (superseded_by_id)
        REFERENCES supplier_evaluations (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- 4. supplier_evaluation_criteria (source/allowed)
--    Child scores — one row per criterion per evaluation.
--    NON-FISCAL — never feeds COGS/stock/WAC/BOM/beer-tax.
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE supplier_evaluation_criteria (
    id                      INT UNSIGNED NOT NULL AUTO_INCREMENT,
    evaluation_id_fk        INT UNSIGNED NOT NULL         COMMENT 'FK → supplier_evaluations.id',
    grid_criterion_id_fk    INT UNSIGNED NOT NULL         COMMENT 'FK → supplier_evaluation_grid_criteria.id; RESTRICT — snapshot of which def was used',
    score                   SMALLINT UNSIGNED NULL        COMMENT '0..max_score; NULL = not yet scored',
    score_source            ENUM('auto','manual') NOT NULL DEFAULT 'manual',
    evidence_note           VARCHAR(500) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_eval_criterion (evaluation_id_fk, grid_criterion_id_fk),
    CONSTRAINT fk_sec_evaluation FOREIGN KEY (evaluation_id_fk)
        REFERENCES supplier_evaluations (id) ON DELETE CASCADE,
    CONSTRAINT fk_sec_criterion FOREIGN KEY (grid_criterion_id_fk)
        REFERENCES supplier_evaluation_grid_criteria (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- 5. supplier_cert_documents (reference/allowed)
--    COA/cert links — reuses doc_files; NO parallel doc store.
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE supplier_cert_documents (
    id                      INT UNSIGNED NOT NULL AUTO_INCREMENT,
    supplier_id_fk          INT UNSIGNED NOT NULL         COMMENT 'FK → ref_suppliers.id (INT UNSIGNED)',
    doc_file_id_fk          BIGINT UNSIGNED NULL          COMMENT 'FK → doc_files.id (BIGINT UNSIGNED — dual-key rule: .id NOT UUID); NULL = held-on-paper before upload',
    doc_type                ENUM('cert_iso22000','cert_fssc','cert_ifs','cert_bio','cert_brc',
                                 'coa','spec_sheet','code_conduite','esg_report','other') NOT NULL,
    reference_label         VARCHAR(200) NULL             COMMENT 'Cert number / lab ref',
    issued_on               DATE NULL,
    expires_on              DATE NULL                     COMMENT 'Feeds re-review + future expiry watchdog',
    linked_evaluation_id_fk INT UNSIGNED NULL             COMMENT 'Which eval cited this evidence; soft',
    is_active               TINYINT(1) NOT NULL DEFAULT 1,
    created_at              TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by              INT UNSIGNED NULL             COMMENT 'Soft ref to users.id',
    PRIMARY KEY (id),
    KEY ix_scd_supplier (supplier_id_fk, doc_type),
    CONSTRAINT fk_scd_supplier FOREIGN KEY (supplier_id_fk)
        REFERENCES ref_suppliers (id) ON DELETE CASCADE,
    CONSTRAINT fk_scd_doc_file FOREIGN KEY (doc_file_id_fk)
        REFERENCES doc_files (id) ON DELETE SET NULL,
    CONSTRAINT fk_scd_evaluation FOREIGN KEY (linked_evaluation_id_fk)
        REFERENCES supplier_evaluations (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- 6. supplier_nc (source/allowed)
--    Supplier non-conformities — feeds A6 food-safety pillar.
--    Soft CAPA text ref (MA-01 doc-only); NO FK — no CAPA register table yet.
--    NON-FISCAL — never feeds COGS/stock/WAC/BOM/beer-tax.
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE supplier_nc (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    supplier_id_fk  INT UNSIGNED NOT NULL             COMMENT 'FK → ref_suppliers.id (INT UNSIGNED)',
    detected_on     DATE NOT NULL,
    nc_type         ENUM('food_safety','quality','delivery','documentation','esg','other') NOT NULL,
    severity        ENUM('mineure','majeure','critique') NOT NULL,
    description     TEXT NOT NULL,
    delivery_id_fk  BIGINT UNSIGNED NULL              COMMENT 'FK → inv_deliveries.id (BIGINT UNSIGNED); soft link to offending delivery; SET NULL on delete',
    capa_register   VARCHAR(20) NULL DEFAULT 'MA-01'  COMMENT 'Document register identifier; text only',
    capa_ref        VARCHAR(64) NULL                  COMMENT 'Soft text ref e.g. MA-01-2026-001; FUTURE: replace with capa_id_fk when a CAPA register table lands — NO FK now',
    status          ENUM('open','in_progress','closed') NOT NULL DEFAULT 'open',
    closed_on       DATE NULL,
    resolution      TEXT NULL,
    triggered_evaluation TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'A majeure/critique NC arms an evenementiel re-eval',
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by      INT UNSIGNED NULL                 COMMENT 'Soft ref to users.id',
    PRIMARY KEY (id),
    KEY ix_snc_supplier (supplier_id_fk, detected_on),
    KEY ix_snc_open (status, severity),
    CONSTRAINT fk_snc_supplier FOREIGN KEY (supplier_id_fk)
        REFERENCES ref_suppliers (id) ON DELETE CASCADE,
    CONSTRAINT fk_snc_delivery FOREIGN KEY (delivery_id_fk)
        REFERENCES inv_deliveries (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- 7. ALTER ref_suppliers — additive ONLY (one nullable col)
--    criticality NULL = not yet ratified; seed via one-shot UPDATE AFTER
--    operator ratifies the food-contact category set (Wave 1 lands it NULL).
--    DO NOT touch vat_regime/gl_account/hors_perimetre_cogs/parser_key.
-- ─────────────────────────────────────────────────────────────────────────────
ALTER TABLE ref_suppliers
    ADD COLUMN criticality ENUM('critique','non_critique') NULL
        COMMENT 'Derived from food-contact MI categories (NULL=unratified); manual override allowed via governance write';

-- ─────────────────────────────────────────────────────────────────────────────
-- 8. Grid seed — EF-01 v1.0
--    threshold_agree=75 (Agréé), threshold_surveillance=50 (sous surveillance)
--    pillar_a_max=22, pillar_b_max=13
--    is_ko_flag=0 on ALL criteria — operator ratifies KO flags in follow-up
-- ─────────────────────────────────────────────────────────────────────────────
INSERT INTO supplier_evaluation_grids
    (code, version, label, threshold_agree, threshold_surveillance, pillar_a_max, pillar_b_max, is_active, effective_from)
VALUES
    ('EF-01', '1.0', 'Grille d''évaluation fournisseurs EF-01', 75.00, 50.00, 22, 13, 1, '2026-06-19');

-- Insert 11 criteria linked to the grid just inserted (id=1, first and only row)
-- Pilier A (max total 22): A1=4, A2=4, A3=4, A4=3, A5=4, A6=3
-- Pilier B (max total 13): B1=3, B2=3, B3=2, B4=2, B5=3
-- ALL is_ko_flag=0 — operator will ratify KO criteria separately
INSERT INTO supplier_evaluation_grid_criteria
    (grid_id_fk, pillar, code, label, max_score, evidence_source, is_ko_flag, display_order)
VALUES
    (1, 'A', 'A1', 'Certification de système / cahier des charges',           4, 'manual_cert',     0, 10),
    (1, 'A', 'A2', 'Spécifications et COA conformes',                         4, 'manual_coa',      0, 20),
    (1, 'A', 'A3', 'Conformité qualité des livraisons',                        4, 'auto_delivery',   0, 30),
    (1, 'A', 'A4', 'Respect des délais et disponibilité',                      3, 'auto_delivery',   0, 40),
    (1, 'A', 'A5', 'Documentation et traçabilité (lot, allergènes)',           4, 'manual',          0, 50),
    (1, 'A', 'A6', 'Traitement des non-conformités',                           3, 'manual_nc',       0, 60),
    (1, 'B', 'B1', 'Engagement environnemental',                               3, 'manual_esg',      0, 70),
    (1, 'B', 'B2', 'Social et code de conduite',                               3, 'manual_esg',      0, 80),
    (1, 'B', 'B3', 'Éthique et gouvernance',                                   2, 'manual_esg',      0, 90),
    (1, 'B', 'B4', 'Ancrage local / circuit court',                            2, 'manual_esg',      0, 100),
    (1, 'B', 'B5', 'Certifications RSE',                                       3, 'manual_cert',     0, 110);

-- ─────────────────────────────────────────────────────────────────────────────
-- 9. schema_meta — one row per new table
--    NON-FISCAL note on source tables is mandatory per arc spec.
-- ─────────────────────────────────────────────────────────────────────────────
INSERT INTO schema_meta (table_name, table_class, corrections_policy, writer_script, upstream_hint, notes)
VALUES
    ('supplier_evaluation_grids',
     'reference', 'allowed',
     NULL,
     NULL,
     'Evaluation grid version header; weights are data (data-driven per-grid); past evals pin grid_id_fk so they never rescore on grid evolution. ADDITIVE — does not feed COGS/COP/WAC/BOM/beer-tax/stock.'),

    ('supplier_evaluation_grid_criteria',
     'reference', 'allowed',
     NULL,
     'Never mutate max_score in place after evals reference this grid — add a new grid version row instead',
     'Criterion definitions (A1-A6/B1-B5) per grid version. is_ko_flag operator-ratified; do NOT guess. ADDITIVE — NON-FISCAL.'),

    ('supplier_evaluations',
     'source', 'allowed',
     'public/api/sf-evaluation-save.php',
     'Score recompute is server-side (handler re-derives pillar scores, total_pct, KO, result from criteria rows). Supersede via NEW row — never in-place rewrite a final eval.',
     'Supplier evaluation event headers. NON-FISCAL — jamais COGS/stock/WAC/BOM/beer-tax. One row per assessment event; status=draft editable / status=final sealed.'),

    ('supplier_evaluation_criteria',
     'source', 'allowed',
     'public/api/sf-evaluation-save.php',
     'Child of supplier_evaluations; score recomputed by save handler. Do not trust client totals.',
     'Per-criterion scores for an evaluation. NON-FISCAL — jamais COGS/stock/WAC/BOM/beer-tax. UNIQUE (evaluation_id_fk, grid_criterion_id_fk).'),

    ('supplier_cert_documents',
     'reference', 'allowed',
     'public/api/sf-cert-link.php',
     'doc_file_id_fk → doc_files.id (BIGINT UNSIGNED, the .id PK not the UUID). Upload route: doc_uploads→pipeline→doc_files; then link here.',
     'COA/cert links per supplier. Reuses doc_files (canonical doc store) — NO parallel cert store. ADDITIVE — NON-FISCAL.'),

    ('supplier_nc',
     'source', 'allowed',
     'public/api/sf-nc-save.php',
     'capa_ref is a soft text ref (MA-01 doc-only); no CAPA table exists yet — NO FK. delivery_id_fk → inv_deliveries.id (BIGINT UNSIGNED).',
     'Supplier non-conformities. Feeds A6 food-safety pillar. NON-FISCAL — jamais COGS/stock/WAC/BOM/beer-tax. CAPA FK to land when a CAPA register table is built.');
