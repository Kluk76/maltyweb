-- Migration 234: Create ref_packaging_loss_types catalog + seed 9 rows + schema_meta
--
-- What: Adds a new reference catalog table `ref_packaging_loss_types` that enumerates
--       every managed packaging-run disposition (invendable, perte liquide, QA holds,
--       taproom, capuchon fût, etc.). Each row describes one disposition type: its
--       storage column name in bd_packaging_v2, measure unit, liquid fraction, tax/loss
--       flags, segregation routing, BOM treatment, and applicable run types.
--
-- Why:  The loss-type taxonomy was previously implicit in the view SQL and PHP form.
--       This catalog makes it first-class: the form renderer and the view codegen
--       (A-LT2+) will read it to auto-generate UI inputs and view arms rather than
--       hardcoding them. is_system=1 seeds are the 9 incumbent types matched to their
--       existing bd_packaging_v2 storage columns.
--
-- Scope: TABLE + SEED + schema_meta ONLY. Zero changes to bd_packaging_v2,
--        v_bd_packaging_v2_vendable, any PHP form, or any existing object.
--        The catalog is INERT — nothing reads it yet. Zero behaviour change.
--
-- Rollback:
--   DROP TABLE ref_packaging_loss_types;
--   DELETE FROM schema_meta WHERE table_name = 'ref_packaging_loss_types';

CREATE TABLE `ref_packaging_loss_types` (
  `id`                       INT UNSIGNED                                                        NOT NULL AUTO_INCREMENT,
  `code`                     VARCHAR(48)   CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci     NOT NULL,
  `label_fr`                 VARCHAR(120)  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci     NOT NULL,
  `column_name`              VARCHAR(64)   CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci     NOT NULL  COMMENT 'Bridge to the bd_packaging_v2 storage column; view-codegen (A-LT2) reads this',
  `measure_unit`             ENUM('units','litres')                                              NOT NULL,
  `liquid_fraction`          DECIMAL(3,2)                                                        NOT NULL DEFAULT 1.00  COMMENT '1.0 full / 0.5 half / 0.0 material; modulates unit-measured types',
  `affects_vendable`         TINYINT(1)                                                          NOT NULL DEFAULT 1,
  `is_taxed`                 TINYINT(1)                                                          NOT NULL DEFAULT 0,
  `counts_as_loss`           TINYINT(1)                                                          NOT NULL DEFAULT 1,
  `goes_to_segregated_stock` TINYINT(1)                                                          NOT NULL DEFAULT 0  COMMENT 'Taproom / segregated destination',
  `bom_treatment`            ENUM('full','minus_crown_cork','material_only','none')               NOT NULL DEFAULT 'full'  COMMENT 'LOAD-BEARING: a boolean cannot express minus_crown_cork',
  `run_type_applicability`   SET('bot','can','can33','keg','cuv')                                NOT NULL,
  `is_system`                TINYINT(1)                                                          NOT NULL DEFAULT 0  COMMENT '1 = seeded incumbent: relabel/reorder/deactivate only, never delete/drop-column',
  `active`                   TINYINT(1)                                                          NOT NULL DEFAULT 1,
  `sort_order`               INT                                                                 NOT NULL DEFAULT 0,
  `created_at`               TIMESTAMP                                                           NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`               TIMESTAMP                                                           NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_plt_code`   (`code`),
  UNIQUE KEY `uq_plt_column` (`column_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Catalog of managed packaging-run disposition types. column_name bridges each row to its bd_packaging_v2 storage column. is_system=1 rows are the 9 seeded incumbents.';

-- ─── Seed: 9 incumbent disposition types (is_system=1, active=1) ─────────────
-- Columns: code, label_fr, column_name, measure_unit, liquid_fraction,
--          affects_vendable, is_taxed, counts_as_loss, goes_to_segregated_stock,
--          bom_treatment, run_type_applicability, is_system, active, sort_order

INSERT INTO `ref_packaging_loss_types`
  (`code`, `label_fr`, `column_name`, `measure_unit`, `liquid_fraction`,
   `affects_vendable`, `is_taxed`, `counts_as_loss`, `goes_to_segregated_stock`,
   `bom_treatment`, `run_type_applicability`, `is_system`, `active`, `sort_order`)
VALUES
  -- Row 1: invendable — full unit, taxed (beer-tax base includes it)
  ('invendable',          'Invendable',                     'unsaleable_units',        'units',  1.00, 1, 1, 1, 0, 'full',             'bot,can,can33', 1, 1, 10),

  -- Row 2: perte liquide autre — full unit, untaxed (subtracted from beer-tax base)
  ('perte_liquide_autre', 'Perte liquide autre',            'loss_untaxed_full_units', 'units',  1.00, 1, 0, 1, 0, 'full',             'bot,can,can33', 1, 1, 20),

  -- Row 3: sans capsule — full liquid but minus crown-cork BOM treatment
  ('sans_capsule',        'Perte liquide sans capsule',     'loss_uncapped_units',     'units',  1.00, 1, 0, 1, 0, 'minus_crown_cork', 'bot,can,can33', 1, 1, 30),

  -- Row 4: half-filled — 0.50 liquid fraction
  ('half_filled',         'Perte liquide à moitié remplie', 'loss_half_filled_units',  'units',  0.50, 1, 0, 1, 0, 'full',             'bot,can,can33', 1, 1, 40),

  -- Row 5: QA library — counts_as_loss=0, affects_vendable=1, is_taxed=0
  ('qa_library',          'Bibliothèque QA',                'qa_library_units',        'units',  1.00, 1, 0, 0, 0, 'full',             'bot,can,can33', 1, 1, 50),

  -- Row 6: QA analyses — same flags as qa_library
  ('qa_analyses',         'Mesures QA',                     'qa_analyses_units',       'units',  1.00, 1, 0, 0, 0, 'full',             'bot,can,can33', 1, 1, 60),

  -- Row 7: keg liquid loss — litres, bom=none, keg+cuv only
  ('keg_liquid',          'Perte liquide fût',              'loss_keg_liquid_l',       'litres', 1.00, 1, 0, 1, 0, 'none',             'keg,cuv',       1, 1, 70),

  -- Row 8: taproom — taxed, not a loss, goes to segregated stock
  ('taproom',             'Fût taproom',                    'taproom_keg_l',           'litres', 1.00, 1, 1, 0, 1, 'none',             'keg,cuv',       1, 1, 80),

  -- Row 9: capuchon fût — liquid_fraction=0, affects_vendable=0, bom=material_only
  ('capuchon_fut',        'Perte capuchon fût',             'loss_keg_save_units',     'units',  0.00, 0, 0, 0, 0, 'material_only',    'keg,cuv',       1, 1, 90);

-- ─── schema_meta registration ────────────────────────────────────────────────
INSERT INTO `schema_meta`
  (`table_name`, `table_class`, `writer_script`, `corrections_policy`, `upstream_hint`, `notes`)
VALUES
  (
    'ref_packaging_loss_types',
    'reference',
    'web (admin)',
    'allowed',
    'is_system=1 rows are the 9 seeded incumbents — relabel/reorder/deactivate only; never delete or drop their bd_packaging_v2 storage columns (G2 guard)',
    'Operator-editable catalog driving packaging-form loss rendering + the codegen of v_bd_packaging_v2_vendable (A-LT2+). column_name bridges each row to its bd_packaging_v2 storage column.'
  );
