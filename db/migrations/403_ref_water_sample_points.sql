-- 403_ref_water_sample_points.sql
-- Reference lookup: water sampling points for HACCP/QA "Analyse de l'eau" panel.
-- NON-FISCAL — never feeds COGS/stock/WAC/BOM/beer-tax.
-- PS-2/4/6 seeded with is_active=0 (installation unconfirmed).

CREATE TABLE ref_water_sample_points (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  code          VARCHAR(16)  NOT NULL,
  label         VARCHAR(190) NOT NULL,
  description   VARCHAR(500) NULL,
  is_ccp        TINYINT(1)   NOT NULL DEFAULT 0,
  risk_basis    VARCHAR(500) NULL,
  sort_order    INT          NOT NULL DEFAULT 0,
  is_active     TINYINT(1)   NOT NULL DEFAULT 1,
  notes         VARCHAR(500) NULL,
  created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_wsp_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO ref_water_sample_points
  (code, label, is_ccp, risk_basis, sort_order, is_active)
VALUES
  ('PS-1', 'Point d''entrée / compteur (interface réseau communal)',
   0, 'Risque faible — amont maîtrisé par le distributeur communal', 1, 1),

  ('PS-2', 'Sortie de traitement — adoucisseur / filtre à charbon actif',
   0, 'Biofilm sur média, stagnation, perte de chlore résiduel — le cas échéant', 2, 0),

  ('PS-3', 'Piquage eau de brassage (point d''usage — CCP-1)',
   1, 'Intrant direct produit ; biofilm tuyauterie interne ; métaux', 3, 1),

  ('PS-4', 'Eau de dilution — gamme sans-alcool',
   0, 'Ajout direct au produit — le cas échéant', 4, 0),

  ('PS-5', 'Eau du dernier rinçage CIP (surfaces contact produit)',
   0, 'Recontamination post-nettoyage ; résidus détergent', 5, 1),

  ('PS-6', 'Stagnation / bras morts / réservoir tampon',
   0, 'Stagnation → Legionella si eau tiède/stockage — le cas échéant', 6, 0);

INSERT INTO schema_meta
  (table_name, table_class, corrections_policy, writer_script, notes)
VALUES
  ('ref_water_sample_points', 'reference', 'allowed',
   '/modules/qa.php (seed) + admin',
   'Points sensibles d''analyse d''eau (HACCP CCP-1, AE-01). PS-2/4/6 inactifs jusqu''à validation du schéma hydraulique.');
