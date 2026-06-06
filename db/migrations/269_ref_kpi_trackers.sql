-- 269_ref_kpi_trackers.sql
-- KPI tracker catalog: one row per tracker (#1–269).
-- Seeds all trackers with readiness, handler routing, and hero flags.
-- data_ready=1 for ✅ live + cheap 🔶 computable. data_ready=0 for ⛔ gap +
-- blocked 🔶 trackers whose upstream inputs aren't priced/captured yet.
-- When a gap's data lands, flip data_ready=1 in the same migration that
-- delivers the capture — tracker auto-appears in picker with no re-seed.
-- ============================================================

CREATE TABLE `ref_kpi_trackers` (
  `id`             INT UNSIGNED     NOT NULL AUTO_INCREMENT,
  `tracker_no`     SMALLINT UNSIGNED NOT NULL COMMENT 'Catalog # (1–269)',
  `slug`           VARCHAR(48)      NOT NULL COMMENT 'Handler/recap key (machine-safe)',
  `label`          VARCHAR(96)      NOT NULL,
  `description`    VARCHAR(255)     NULL DEFAULT NULL,
  `category`       ENUM(
                     'production',
                     'fermentation',
                     'racking',
                     'packaging',
                     'fg_stock',
                     'sales',
                     'rm_procurement',
                     'logistics',
                     'qa_qc',
                     'cogs_finance',
                     'utilities',
                     'ops_health',
                     'equipment',
                     'control_loop'
                   )                NOT NULL,
  `domain`         ENUM('production','logistics','admin','general')
                                    NOT NULL DEFAULT 'production'
                                    COMMENT 'Composes with page access — which users may see it',
  `source_domain`  VARCHAR(32)      NOT NULL COMMENT 'Handler registry key (~12 surfaces)',
  `compute_handler` VARCHAR(64)     NOT NULL COMMENT 'Handler function/key within source_domain',
  `params_json`    JSON             NULL     COMMENT 'Whitelisted: period/groupby/filter/metric',
  `viz_type`       ENUM(
                     'kpi_number',
                     'sparkline',
                     'bar',
                     'stacked_bar',
                     'line',
                     'donut',
                     'flag',
                     'table',
                     'waterfall'
                   )                NOT NULL DEFAULT 'kpi_number',
  `readiness`      ENUM('live','compute','gap')
                                    NOT NULL DEFAULT 'gap',
  `default_cadence` ENUM('daily','weekly','monthly')
                                    NOT NULL DEFAULT 'monthly',
  `is_hero`        TINYINT(1)       NOT NULL DEFAULT 0
                                    COMMENT '1 = H1–H6 hero card on the dashboard',
  `hero_slot`      TINYINT UNSIGNED NULL DEFAULT NULL
                                    COMMENT '1–6 for the six hero positions',
  `data_ready`     TINYINT(1)       NOT NULL DEFAULT 0
                                    COMMENT '1 = selectable/renderable; 0 = blocked',
  `min_role`       ENUM('viewer','operator','manager','admin')
                                    NOT NULL DEFAULT 'operator',
  `is_active`      TINYINT(1)       NOT NULL DEFAULT 1,
  `sort`           INT              NOT NULL DEFAULT 0,
  `created_at`     TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP
                                    ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_tracker_no` (`tracker_no`),
  UNIQUE KEY `uniq_slug` (`slug`),
  KEY `idx_category` (`category`),
  KEY `idx_source_domain` (`source_domain`),
  KEY `idx_data_ready` (`data_ready`),
  KEY `idx_is_hero` (`is_hero`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- SEED: all ~268 trackers
-- Columns: tracker_no, slug, label, category, domain, source_domain,
--          compute_handler, params_json, viz_type, readiness, default_cadence,
--          is_hero, hero_slot, data_ready, min_role, sort
-- ============================================================

INSERT INTO `ref_kpi_trackers`
  (`tracker_no`,`slug`,`label`,`category`,`domain`,`source_domain`,`compute_handler`,`params_json`,`viz_type`,`readiness`,`default_cadence`,`is_hero`,`hero_slot`,`data_ready`,`min_role`,`sort`)
VALUES

-- ─── Cat 1: Production (Brewing) ────────────────────────────
(1,'hl_brewed_month','HL brassés ce mois','production','production','wort','hl_brewed_period','{"period":"current_month","groupby":"classification"}','kpi_number','live','monthly',0,NULL,1,'operator',10),
(2,'hl_brewed_ytd','HL brassés YTD vs N-1','production','production','wort','hl_brewed_ytd',NULL,'sparkline','live','monthly',0,NULL,1,'operator',20),
(3,'brew_count_month','Nombre de brassins ce mois','production','production','wort','brew_count_period','{"period":"current_month"}','kpi_number','live','monthly',0,NULL,1,'operator',30),
(4,'avg_hl_per_brew','HL moyen par brassin','production','production','wort','avg_hl_per_brew','{"period":"rolling_3m"}','kpi_number','live','monthly',0,NULL,1,'operator',40),
(5,'production_by_beer_yoy','Production par bière — sparklines YoY','production','production','wort','production_by_beer_yoy',NULL,'sparkline','live','monthly',0,NULL,1,'operator',50),
(6,'brewhouse_yield','Rendement / efficacité brasserie %','production','production','wort','brewhouse_yield','{"period":"current_month"}','kpi_number','live','monthly',0,NULL,1,'operator',60),
(7,'og_attainment','Atteinte OG vs cible recette','production','production','wort','og_attainment',NULL,'bar','compute','monthly',0,NULL,0,'operator',70),
(8,'days_since_last_brew','Jours depuis dernier brassin','production','production','wort','days_since_last_brew',NULL,'kpi_number','live','daily',0,NULL,1,'operator',80),
(9,'brews_this_week','Brassins cette semaine','production','production','wort','brew_count_period','{"period":"current_week"}','kpi_number','live','weekly',0,NULL,1,'operator',90),
(10,'avg_brew_duration','Durée moyenne par brassin','production','production','wort','avg_brew_duration',NULL,'kpi_number','compute','monthly',0,NULL,0,'operator',100),
(11,'ingredient_consumption_month','Consommation MP / mois par catégorie','production','production','wort','ingredient_consumption_period','{"period":"current_month","groupby":"category"}','bar','compute','monthly',0,NULL,0,'operator',110),
(12,'brewing_deviations','Déviations brasserie (OG/durée/pH)','production','production','wort','brewing_deviations',NULL,'flag','compute','monthly',0,NULL,0,'operator',120),

-- ─── Cat 2: Fermentation / Tanks ────────────────────────────
(13,'cct_bbt_occupancy','Occupation CCT/BBT (occupés vs libres)','fermentation','production','tanks','tank_occupancy',NULL,'kpi_number','compute','daily',0,NULL,0,'operator',130),
(14,'cct_capacity_utilization','Taux d utilisation CCT (HL)','fermentation','production','tanks','cct_utilization_pct',NULL,'kpi_number','compute','daily',0,NULL,0,'operator',140),
(15,'cct_idle_days','Jours CCT vides (moy.)','fermentation','production','tanks','cct_idle_days',NULL,'kpi_number','compute','monthly',0,NULL,0,'operator',150),
(16,'cct_days_per_beer','Répartition jours CCT par bière','fermentation','production','tanks','cct_days_per_beer',NULL,'donut','compute','monthly',0,NULL,0,'operator',160),
(17,'hl_in_tank','HL actuellement en cuve (WIP)','fermentation','production','tanks','hl_in_tank_now',NULL,'kpi_number','compute','daily',0,NULL,0,'operator',170),
(18,'beers_fermenting_now','Bières en fermentation + jours en cuve','fermentation','production','tanks','beers_fermenting_now',NULL,'table','compute','daily',0,NULL,0,'operator',180),
(19,'garde_vs_target','Garde / délai mise en fût vs cible','fermentation','production','tanks','garde_vs_target',NULL,'bar','compute','monthly',0,NULL,0,'operator',190),
(20,'tank_turns_month','Rotations cuves par mois','fermentation','production','tanks','tank_turns_period','{"period":"current_month"}','kpi_number','compute','monthly',0,NULL,0,'operator',200),
(21,'cold_crash_dryhop','Cold crash / dryhop en cours','fermentation','production','tanks','cold_crash_in_progress',NULL,'kpi_number','compute','daily',0,NULL,0,'operator',210),
(22,'fermentation_deviations','Déviations fermentation (DFE/atténuation/durée)','fermentation','production','tanks','fermentation_deviations',NULL,'flag','compute','monthly',0,NULL,0,'operator',220),
(23,'suggested_next_brew','Prochaines bières à brasser (suggestion)','fermentation','production','tanks','suggested_next_brew',NULL,'table','compute','monthly',0,NULL,0,'operator',230),
(24,'temp_pressure_excursions','Excursions température/pression','fermentation','production','tanks','temp_pressure_excursions',NULL,'flag','compute','daily',0,NULL,0,'operator',240),
(25,'diacetyl_rest_tracking','Suivi repos diacétyle','fermentation','production','tanks','diacetyl_rest',NULL,'flag','gap','daily',0,NULL,0,'operator',250),
(26,'yeast_generation','Génération levure','fermentation','production','tanks','yeast_generation',NULL,'kpi_number','gap','monthly',0,NULL,0,'operator',260),
(27,'repitch_count','Nombre de repiquages','fermentation','production','tanks','repitch_count',NULL,'kpi_number','gap','monthly',0,NULL,0,'operator',270),
(28,'harvest_yield','Rendement récolte levure','fermentation','production','tanks','harvest_yield',NULL,'kpi_number','gap','monthly',0,NULL,0,'operator',280),
(29,'co2_recovery','Récupération CO₂','fermentation','production','tanks','co2_recovery',NULL,'kpi_number','gap','monthly',0,NULL,0,'operator',290),
(30,'cip_cleaning_due','CIP / nettoyage dû','fermentation','production','tanks','cip_due',NULL,'flag','gap','daily',0,NULL,0,'operator',300),
(31,'avg_fermentation_time','Temps moyen de fermentation par bière','fermentation','production','tanks','avg_fermentation_time',NULL,'bar','compute','monthly',0,NULL,0,'operator',310),
(32,'avg_yeast_generation','Génération levure moyenne','fermentation','production','tanks','avg_yeast_generation',NULL,'kpi_number','gap','monthly',0,NULL,0,'operator',320),
(33,'yeast_gen_vs_ferment_time','Génération levure vs temps fermentation','fermentation','production','tanks','yeast_gen_vs_ferment_time',NULL,'bar','gap','monthly',0,NULL,0,'operator',330),
(34,'o2_in_bbt','O₂ dissous en BBT','fermentation','production','tanks','o2_in_bbt','{"metric":"o2_ppm"}','kpi_number','compute','monthly',0,NULL,0,'operator',340),
(35,'o2_deviations','Déviations O₂','fermentation','production','tanks','o2_deviations',NULL,'flag','compute','monthly',0,NULL,0,'operator',350),
(36,'turbidity_deviations','Turbidité et déviations','fermentation','production','tanks','turbidity_deviations',NULL,'flag','gap','monthly',0,NULL,0,'operator',360),

-- ─── Cat 3: Racking ─────────────────────────────────────────
(37,'avg_racking_time_beer','Temps moyen mise en fût par bière','racking','production','racking','avg_racking_time_per_beer',NULL,'bar','compute','monthly',0,NULL,0,'operator',370),
(38,'avg_racking_time_hl','Temps moyen mise en fût / HL','racking','production','racking','avg_racking_time_per_hl',NULL,'kpi_number','compute','monthly',0,NULL,0,'operator',380),
(39,'rackings_month','Mises en fût ce mois (nbre + HL)','racking','production','racking','rackings_period','{"period":"current_month"}','kpi_number','compute','monthly',0,NULL,0,'operator',390),
(40,'racking_loss_pct','Pertes mise en fût % (brassé→soutiré)','racking','production','racking','racking_loss_pct',NULL,'kpi_number','compute','monthly',0,NULL,0,'operator',400),
(41,'brew_to_rack_cycle','Délai brassin→mise en fût','racking','production','racking','brew_to_rack_cycle',NULL,'kpi_number','compute','monthly',0,NULL,0,'operator',410),
(42,'blend_rackings_count','Mélanges / mises en fût multi-cuves','racking','production','racking','blend_rackings_count',NULL,'kpi_number','compute','monthly',0,NULL,0,'operator',420),
(43,'racking_yield_vs_target','Rendement mise en fût vs cible','racking','production','racking','racking_yield_vs_target',NULL,'bar','compute','monthly',0,NULL,0,'operator',430),
(44,'do_pickup_racking','O₂ dissous pickup à la mise en fût','racking','production','racking','do_pickup_racking',NULL,'kpi_number','gap','monthly',0,NULL,0,'operator',440),
(45,'carbonation_achieved','Carbonatation atteinte','racking','production','racking','carbonation_achieved',NULL,'kpi_number','gap','monthly',0,NULL,0,'operator',450),
(46,'rack_to_packaging_lag','Délai mise en fût → packaging','racking','production','racking','rack_to_packaging_lag',NULL,'kpi_number','compute','monthly',0,NULL,0,'operator',460),
(47,'tank_emptying_efficiency','Efficacité vidage cuve','racking','production','racking','tank_emptying_efficiency',NULL,'kpi_number','compute','monthly',0,NULL,0,'operator',470),

-- ─── Cat 4: Packaging ────────────────────────────────────────
(48,'units_packaged_month','Unités packagées ce mois par format','packaging','production','packaging','units_packaged_period','{"period":"current_month","groupby":"format"}','bar','compute','monthly',0,NULL,0,'operator',480),
(49,'hl_packaged_month','HL packagés ce mois','packaging','production','packaging','hl_packaged_period','{"period":"current_month"}','kpi_number','compute','monthly',0,NULL,0,'operator',490),
(50,'packaging_runs_count','Runs packaging ce mois + jours depuis dernier','packaging','production','packaging','packaging_runs_period','{"period":"current_month"}','kpi_number','compute','monthly',0,NULL,0,'operator',500),
(51,'top_skus_packaged','Top SKUs packagés','packaging','production','packaging','top_skus_packaged','{"period":"current_month","limit":5}','bar','compute','monthly',0,NULL,0,'operator',510),
(52,'format_mix_pct','Répartition formats % (keg/bouteille/canette)','packaging','production','packaging','format_mix_pct','{"period":"current_month"}','donut','compute','monthly',0,NULL,0,'operator',520),
(53,'parallel_white_label_volume','Volume parallel / marque blanche','packaging','production','packaging','parallel_white_label_volume',NULL,'kpi_number','compute','monthly',0,NULL,0,'operator',530),
(54,'packaging_yield_pct','Rendement packaging / pertes de remplissage %','packaging','production','packaging','packaging_yield_pct',NULL,'kpi_number','compute','monthly',0,NULL,0,'operator',540),
(55,'fill_efficiency','Efficacité remplissage (réel vs théorique)','packaging','production','packaging','fill_efficiency',NULL,'kpi_number','compute','monthly',0,NULL,0,'operator',550),
(56,'contract_packaging_volume','Volume packaging contractuel par client','packaging','production','packaging','contract_packaging_volume',NULL,'bar','compute','monthly',0,NULL,0,'operator',560),
(58,'packaging_material_consumption','Consommation matériaux packaging / mois','packaging','production','packaging','packaging_material_consumption',NULL,'bar','gap','monthly',0,NULL,0,'operator',580),
(59,'avg_throughput_packaging','Débit moyen par run packaging','packaging','production','packaging','avg_throughput_packaging',NULL,'kpi_number','compute','monthly',0,NULL,0,'operator',590),
(60,'avg_losses_per_category','Pertes moyennes par catégorie','packaging','production','packaging','avg_losses_per_category',NULL,'bar','compute','monthly',0,NULL,0,'operator',600),
(61,'avg_losses_per_sku','Pertes moyennes par SKU','packaging','production','packaging','avg_losses_per_sku',NULL,'bar','compute','monthly',0,NULL,0,'operator',610),
(62,'o2_co2_pickup_per_sku','O₂/CO₂ pickup par SKU·format·mois','packaging','production','packaging','o2_co2_pickup_per_sku',NULL,'bar','gap','monthly',0,NULL,0,'operator',620),
(63,'volume_per_sku_month','Volume par SKU / mois','packaging','production','packaging','volume_per_sku_period','{"period":"current_month"}','bar','compute','monthly',0,NULL,0,'operator',630),
(64,'suggested_packaging_events','Events packaging suggérés','packaging','production','packaging','suggested_packaging_events',NULL,'table','compute','monthly',0,NULL,0,'operator',640),
(65,'packaging_deviations','Déviations packaging (planifié vs réel)','packaging','production','packaging','packaging_deviations',NULL,'flag','compute','monthly',0,NULL,0,'operator',650),
(66,'packaging_cost_per_unit','Coût packaging / unité (→COGS)','packaging','production','packaging','packaging_cost_per_unit',NULL,'kpi_number','compute','monthly',0,NULL,0,'manager',660),
(67,'underfill_overfill_loss','Pertes sous/sur-remplissage','packaging','production','packaging','underfill_overfill_loss',NULL,'kpi_number','gap','monthly',0,NULL,0,'operator',670),
(68,'label_cap_waste_pct','Gaspillage étiquettes / capsules %','packaging','production','packaging','label_cap_waste_pct',NULL,'kpi_number','gap','monthly',0,NULL,0,'operator',680),
(69,'fg_added_inventory_month','PF ajoutés à l inventaire / mois','packaging','production','packaging','fg_added_inventory_period','{"period":"current_month"}','kpi_number','compute','monthly',0,NULL,0,'operator',690),
(70,'o2_pickup_fill','O₂ pickup au remplissage','packaging','production','packaging','o2_pickup_fill',NULL,'kpi_number','gap','monthly',0,NULL,0,'operator',700),
(71,'seam_torque_qc_pass','Taux réussite QC sertissage / couple','packaging','production','packaging','seam_torque_qc_pass',NULL,'kpi_number','gap','monthly',0,NULL,0,'operator',710),

-- ─── Cat 5: Finished Goods / Stock ──────────────────────────
(72,'fg_units_in_stock','Unités PF en stock par SKU/format','fg_stock','logistics','fg_stock','fg_units_in_stock',NULL,'table','compute','weekly',0,NULL,0,'operator',720),
(73,'fg_inventory_value','Valeur inventaire PF (CHF coût)','fg_stock','logistics','fg_stock','fg_inventory_value',NULL,'kpi_number','compute','monthly',0,NULL,0,'manager',730),
(74,'fg_days_cover','Jours/semaines de couverture par SKU','fg_stock','logistics','fg_stock','fg_days_cover',NULL,'table','compute','weekly',0,NULL,0,'operator',740),
(75,'fg_stockouts','Ruptures / sous seuil de réapprovisionnement','fg_stock','logistics','fg_stock','fg_stockouts',NULL,'flag','gap','daily',0,NULL,0,'operator',750),
(76,'fg_produced_vs_sold','PF produits vs vendus (Δ stock net)','fg_stock','logistics','fg_stock','fg_produced_vs_sold',NULL,'bar','compute','monthly',0,NULL,0,'operator',760),
(77,'fg_aging_best_before','PF vieillissants / risque DDM','fg_stock','logistics','fg_stock','fg_aging_best_before',NULL,'flag','compute','monthly',0,NULL,0,'operator',770),
(78,'fg_stock_turnover','Rotation stock PF','fg_stock','logistics','fg_stock','fg_stock_turnover',NULL,'kpi_number','compute','monthly',0,NULL,0,'operator',780),
(79,'warehouse_cage_fill','Remplissage dépôt / cage','fg_stock','logistics','fg_stock','warehouse_cage_fill',NULL,'kpi_number','compute','weekly',0,NULL,0,'operator',790),
(80,'slow_mover_flag','Flag stock mort / rotation lente','fg_stock','logistics','fg_stock','slow_mover_flag',NULL,'flag','compute','monthly',0,NULL,0,'operator',800),
(81,'fg_by_location','PF par emplacement (froid vs entrepôt)','fg_stock','logistics','fg_stock','fg_by_location',NULL,'bar','compute','weekly',0,NULL,0,'operator',810),
(82,'consignment_keg_fleet','Consignation / flotte fûts en marché','fg_stock','logistics','fg_stock','consignment_keg_fleet',NULL,'kpi_number','gap','weekly',0,NULL,0,'operator',820),
(83,'return_breakage_rate','Taux retours / casse','fg_stock','logistics','fg_stock','return_breakage_rate',NULL,'kpi_number','gap','monthly',0,NULL,0,'operator',830),
(84,'value_tied_per_beer','Valeur immobilisée par bière','fg_stock','logistics','fg_stock','value_tied_per_beer',NULL,'bar','compute','monthly',0,NULL,0,'manager',840),
(85,'fg_stock_variation_flag','🚩 Écart stock PF (physique vs théorique)','fg_stock','logistics','fg_stock','fg_stock_variation',NULL,'flag','compute','monthly',0,NULL,0,'operator',850),

-- ─── Cat 6: Sales / Commercial ───────────────────────────────
(86,'revenue_month','Chiffre d affaires ce mois (HT)','sales','general','sales','revenue_period','{"period":"current_month"}','kpi_number','compute','monthly',0,NULL,0,'manager',860),
(87,'units_sold_sku','Unités vendues par SKU/format','sales','general','sales','units_sold_period','{"period":"current_month","groupby":"sku"}','bar','compute','monthly',0,NULL,0,'manager',870),
(88,'hl_sold_month','HL vendus ce mois','sales','general','sales','hl_sold_period','{"period":"current_month"}','kpi_number','compute','monthly',0,NULL,0,'manager',880),
(89,'sales_velocity_sku','Vélocité ventes par SKU (unités/semaine)','sales','general','sales','sales_velocity_sku',NULL,'table','compute','weekly',0,NULL,0,'manager',890),
(90,'sales_yoy_pace','Rythme ventes YoY','sales','general','sales','sales_yoy_pace',NULL,'sparkline','compute','monthly',0,NULL,0,'manager',900),
(91,'revenue_by_family','CA par famille bière (core/spécial/contract)','sales','general','sales','revenue_by_family','{"period":"current_month"}','donut','compute','monthly',0,NULL,0,'manager',910),
(92,'top_customers_revenue','Top clients par CA','sales','general','sales','top_customers_revenue','{"period":"current_month","limit":10}','bar','compute','monthly',0,NULL,0,'manager',920),
(93,'top_skus_volume_revenue','Top SKUs par volume/CA','sales','general','sales','top_skus_volume_revenue','{"period":"current_month"}','bar','compute','monthly',0,NULL,0,'manager',930),
(94,'sales_by_channel','Ventes par canal (B2B/eshop/taproom)','sales','general','sales','sales_by_channel',NULL,'donut','compute','monthly',0,NULL,0,'manager',940),
(95,'swiss_vs_export','Suisse vs export (base taxe bière)','sales','general','sales','swiss_vs_export',NULL,'donut','compute','monthly',0,NULL,0,'manager',950),
(96,'contract_vs_own_brand','Contract vs propre marque CA','sales','general','sales','contract_vs_own_brand',NULL,'donut','compute','monthly',0,NULL,0,'manager',960),
(97,'avg_order_value','Panier moyen · nouveaux vs récurrents','sales','general','sales','avg_order_value',NULL,'kpi_number','compute','monthly',0,NULL,0,'manager',970),
(98,'gross_margin_sku','Marge brute % global + par bière','sales','general','sales','gross_margin_sku',NULL,'bar','compute','monthly',0,NULL,0,'manager',980),
(99,'revenue_per_hl_trend','CA / HL tendance','sales','general','sales','revenue_per_hl_trend',NULL,'sparkline','compute','monthly',0,NULL,0,'manager',990),
(100,'discount_rebate_rate','Taux remises / rabais','sales','general','sales','discount_rebate_rate',NULL,'kpi_number','compute','monthly',0,NULL,0,'manager',1000),
(101,'days_sales_outstanding','Délai moyen paiement (DSO)','sales','general','sales','days_sales_outstanding',NULL,'kpi_number','gap','monthly',0,NULL,0,'manager',1010),
(102,'seasonal_demand_curve','Courbe de demande saisonnière par bière','sales','general','sales','seasonal_demand_curve',NULL,'line','compute','monthly',0,NULL,0,'manager',1020),
(103,'lost_sales_stockout','Ventes perdues (rupture × demande)','sales','general','sales','lost_sales_stockout',NULL,'kpi_number','compute','monthly',0,NULL,0,'manager',1030),
(104,'forecast_vs_actual_sales','Prévisionnel vs ventes réelles','sales','general','sales','forecast_vs_actual_sales',NULL,'bar','compute','monthly',0,NULL,0,'manager',1040),
(105,'customer_churn','Churn clients','sales','general','sales','customer_churn',NULL,'kpi_number','compute','monthly',0,NULL,0,'manager',1050),

-- ─── Cat 7: Raw Materials / Procurement ─────────────────────
(106,'rm_stock_value','Valeur stock MP (CHF) maintenant','rm_procurement','logistics','rm_procurement','rm_stock_value',NULL,'kpi_number','compute','weekly',0,NULL,0,'manager',1060),
(107,'rm_stock_by_category','Stock MP par catégorie','rm_procurement','logistics','rm_procurement','rm_stock_by_category',NULL,'bar','compute','weekly',0,NULL,0,'operator',1070),
(108,'rm_negative_stock_alerts','Alertes stock MP négatif','rm_procurement','logistics','rm_procurement','rm_negative_stock_alerts',NULL,'flag','live','daily',0,NULL,1,'operator',1080),
(109,'rm_stale_items','MP périmées >180j','rm_procurement','logistics','rm_procurement','rm_stale_items',NULL,'flag','live','weekly',0,NULL,1,'operator',1090),
(110,'rm_days_cover','Jours de couverture par MP','rm_procurement','logistics','rm_procurement','rm_days_cover',NULL,'table','compute','weekly',0,NULL,0,'operator',1100),
(111,'rm_reorder_alerts','Alertes réapprovisionnement (sous seuil)','rm_procurement','logistics','rm_procurement','rm_reorder_alerts',NULL,'flag','gap','daily',0,NULL,0,'operator',1110),
(112,'deliveries_month','Livraisons ce mois (nbre + CHF)','rm_procurement','logistics','rm_procurement','deliveries_period','{"period":"current_month"}','kpi_number','live','monthly',0,NULL,1,'operator',1120),
(113,'pending_deliveries','Livraisons en attente (camion arrivé, facture non)','rm_procurement','logistics','rm_procurement','pending_deliveries',NULL,'kpi_number','live','daily',0,NULL,1,'operator',1130),
(114,'spend_by_gl_month','Dépenses par GL / catégorie ce mois','rm_procurement','logistics','rm_procurement','spend_by_gl_period','{"period":"current_month","groupby":"gl"}','bar','live','monthly',0,NULL,1,'manager',1140),
(115,'top_suppliers_spend','Top fournisseurs par dépense','rm_procurement','logistics','rm_procurement','top_suppliers_spend','{"period":"current_month","limit":10}','bar','compute','monthly',0,NULL,0,'manager',1150),
(116,'wac_trend_per_mi','Tendance coût moyen pondéré par MP','rm_procurement','logistics','rm_procurement','wac_trend_per_mi',NULL,'sparkline','compute','monthly',0,NULL,0,'manager',1160),
(117,'price_anomalies','Anomalies / hausses prix par ingrédient','rm_procurement','logistics','rm_procurement','price_anomalies',NULL,'flag','compute','monthly',0,NULL,0,'manager',1170),
(118,'consumption_per_mi_month','Consommation par MP / mois','rm_procurement','logistics','rm_procurement','consumption_per_mi_period','{"period":"current_month"}','bar','compute','monthly',0,NULL,0,'operator',1180),
(119,'rm_drift_alert','Écart stock MP (dynamique vs inventaire)','rm_procurement','logistics','rm_procurement','rm_drift_alert',NULL,'flag','live','monthly',0,NULL,1,'operator',1190),
(120,'caution_deposit_balance','Solde cautions / dépôts par fournisseur','rm_procurement','logistics','rm_procurement','caution_deposit_balance',NULL,'table','compute','monthly',0,NULL,0,'manager',1200),
(121,'import_vat_freight_trend','Tendance TVA import / frais transport','rm_procurement','logistics','rm_procurement','import_vat_freight_trend',NULL,'sparkline','compute','monthly',0,NULL,0,'manager',1210),
(122,'ingredient_cost_pct_cogs','Coût ingrédient en % du COGS','rm_procurement','logistics','rm_procurement','ingredient_cost_pct_cogs',NULL,'donut','compute','monthly',0,NULL,0,'manager',1220),
(123,'supplier_lead_time','Délai livraison fournisseur','rm_procurement','logistics','rm_procurement','supplier_lead_time',NULL,'table','compute','monthly',0,NULL,0,'operator',1230),
(124,'on_time_delivery_rate','Taux livraison à temps','rm_procurement','logistics','rm_procurement','on_time_delivery_rate',NULL,'kpi_number','compute','monthly',0,NULL,0,'operator',1240),
(125,'open_pos_expected','Bons de commande ouverts / arrivées prévues','rm_procurement','logistics','rm_procurement','open_pos_expected',NULL,'table','gap','weekly',0,NULL,0,'operator',1250),
(126,'price_vs_budget_variance','Écart prix vs budget','rm_procurement','logistics','rm_procurement','price_vs_budget_variance',NULL,'bar','gap','monthly',0,NULL,0,'manager',1260),
(127,'single_source_risk','Flag risque fournisseur unique','rm_procurement','logistics','rm_procurement','single_source_risk',NULL,'flag','compute','monthly',0,NULL,0,'manager',1270),
(128,'spend_yoy','Dépenses YoY','rm_procurement','logistics','rm_procurement','spend_yoy',NULL,'sparkline','compute','monthly',0,NULL,0,'manager',1280),
(129,'malt_hops_cost_split','Répartition coût malt vs houblon','rm_procurement','logistics','rm_procurement','malt_hops_cost_split',NULL,'donut','compute','monthly',0,NULL,0,'manager',1290),
(130,'fx_eur_chf_exposure','Exposition FX (EUR/CHF)','rm_procurement','logistics','rm_procurement','fx_eur_chf_exposure',NULL,'kpi_number','compute','monthly',0,NULL,0,'manager',1300),
(131,'overpriced_purchase_flag','🚩 Flag achat surpayé (mauvais achat)','rm_procurement','logistics','rm_procurement','overpriced_purchase_flag',NULL,'flag','compute','monthly',0,NULL,0,'manager',1310),

-- ─── Cat 8: Logistics / Fulfilment ──────────────────────────
(132,'inbound_deliveries_month','Livraisons entrantes ce mois','logistics','logistics','rm_procurement','deliveries_period','{"period":"current_month","metric":"inbound_count"}','kpi_number','live','monthly',0,NULL,1,'operator',1320),
(133,'warehouse_cage_capacity','Capacité entrepôt / cage et remplissage','logistics','logistics','fg_stock','warehouse_cage_fill',NULL,'kpi_number','compute','weekly',0,NULL,0,'operator',1330),
(134,'orders_to_fulfil','Commandes à expédier / expédiées','logistics','logistics','logistics','orders_to_fulfil',NULL,'kpi_number','gap','daily',0,NULL,0,'operator',1340),
(135,'outbound_delivery_notes','Bons livraison sortants émis','logistics','logistics','logistics','outbound_delivery_notes',NULL,'kpi_number','gap','daily',0,NULL,0,'operator',1350),
(136,'on_time_shipment_rate','Taux expédition à temps','logistics','logistics','logistics','on_time_shipment_rate',NULL,'kpi_number','gap','monthly',0,NULL,0,'operator',1360),
(137,'shipping_cost_per_order','Coût expédition / commande / HL','logistics','logistics','logistics','shipping_cost_per_order',NULL,'kpi_number','gap','monthly',0,NULL,0,'manager',1370),
(138,'order_backlog','Carnet commandes / expéditions en attente','logistics','logistics','logistics','order_backlog',NULL,'kpi_number','gap','daily',0,NULL,0,'operator',1380),
(139,'returns_breakage_transit','Retours / casse en transit','logistics','logistics','logistics','returns_breakage_transit',NULL,'kpi_number','gap','monthly',0,NULL,0,'operator',1390),
(140,'keg_fleet_out_returned','Flotte fûts sortis vs retournés','logistics','logistics','logistics','keg_fleet_out_returned',NULL,'kpi_number','gap','weekly',0,NULL,0,'operator',1400),
(141,'pick_pack_throughput','Débit préparation / emballage','logistics','logistics','logistics','pick_pack_throughput',NULL,'kpi_number','gap','daily',0,NULL,0,'operator',1410),
(142,'avg_delivery_lead_time','Délai moyen livraison client','logistics','logistics','logistics','avg_delivery_lead_time',NULL,'kpi_number','gap','monthly',0,NULL,0,'operator',1420),
(143,'carbon_transport_footprint','Empreinte carbone / transport','logistics','logistics','logistics','carbon_transport_footprint',NULL,'kpi_number','gap','monthly',0,NULL,0,'manager',1430),
(144,'delivery_density_region','Densité livraisons par région','logistics','logistics','logistics','delivery_density_region',NULL,'bar','gap','monthly',0,NULL,0,'manager',1440),
(145,'cold_chain_compliance','Conformité chaîne du froid','logistics','logistics','logistics','cold_chain_compliance',NULL,'flag','gap','daily',0,NULL,0,'operator',1450),
(146,'packaging_for_shipping_cost','Coût emballage pour expédition','logistics','logistics','logistics','packaging_for_shipping_cost',NULL,'kpi_number','gap','monthly',0,NULL,0,'manager',1460),

-- ─── Cat 9: QA / QC ─────────────────────────────────────────
(147,'batches_pending_qa','Lots en attente validation QA','qa_qc','production','qa_qc','batches_pending_qa',NULL,'kpi_number','compute','daily',0,NULL,0,'operator',1470),
(148,'qa_pass_fail_rate','Taux réussite / échec QA par lot','qa_qc','production','qa_qc','qa_pass_fail_rate',NULL,'kpi_number','compute','monthly',0,NULL,0,'operator',1480),
(149,'qa_outliers_flagged','Anomalies QA signalées ce mois','qa_qc','production','qa_qc','qa_outliers_flagged','{"period":"current_month"}','kpi_number','compute','monthly',0,NULL,0,'operator',1490),
(150,'out_of_spec_batches','Lots hors spec (OG/DFE/pH)','qa_qc','production','qa_qc','out_of_spec_batches',NULL,'flag','compute','monthly',0,NULL,0,'operator',1500),
(151,'abv_accuracy','Précision TAV vs cible','qa_qc','production','qa_qc','abv_accuracy',NULL,'bar','compute','monthly',0,NULL,0,'operator',1510),
(152,'final_ph_deviations','Déviations pH final','qa_qc','production','qa_qc','final_ph_deviations',NULL,'bar','compute','monthly',0,NULL,0,'operator',1520),
(153,'do_co2_spec_compliance','Conformité O₂ dissous / CO₂','qa_qc','production','qa_qc','do_co2_spec_compliance',NULL,'kpi_number','compute','monthly',0,NULL,0,'operator',1530),
(154,'batch_release_cycle_time','Délai de libération lot','qa_qc','production','qa_qc','batch_release_cycle_time',NULL,'kpi_number','compute','monthly',0,NULL,0,'operator',1540),
(155,'recurring_quality_issues','Problèmes qualité récurrents par bière','qa_qc','production','qa_qc','recurring_quality_issues',NULL,'table','compute','monthly',0,NULL,0,'operator',1550),
(156,'first_pass_quality_rate','Taux qualité premier passage','qa_qc','production','qa_qc','first_pass_quality_rate',NULL,'kpi_number','compute','monthly',0,NULL,0,'operator',1560),
(157,'micro_test_pass_rate','Taux réussite tests microbiologiques','qa_qc','production','qa_qc','micro_test_pass_rate',NULL,'kpi_number','gap','monthly',0,NULL,0,'operator',1570),
(158,'sensory_tasting_scores','Scores panel dégustation','qa_qc','production','qa_qc','sensory_tasting_scores',NULL,'bar','gap','monthly',0,NULL,0,'operator',1580),
(159,'shelf_life_stability','Statut tests stabilité / DDM','qa_qc','production','qa_qc','shelf_life_stability',NULL,'flag','gap','monthly',0,NULL,0,'operator',1590),
(160,'lab_tests_outstanding','Tests labo en attente / délai','qa_qc','production','qa_qc','lab_tests_outstanding',NULL,'table','gap','weekly',0,NULL,0,'operator',1600),
(161,'contamination_incidents','Incidents contamination / altération','qa_qc','production','qa_qc','contamination_incidents',NULL,'flag','gap','monthly',0,NULL,0,'operator',1610),
(162,'complaint_rate_batch','Taux réclamations par lot','qa_qc','production','qa_qc','complaint_rate_batch',NULL,'kpi_number','gap','monthly',0,NULL,0,'manager',1620),
(163,'color_ibu_adherence','Conformité couleur / IBU','qa_qc','production','qa_qc','color_ibu_adherence',NULL,'bar','compute','monthly',0,NULL,0,'operator',1630),
(164,'carbonation_spec_compliance','Conformité spec carbonatation','qa_qc','production','qa_qc','carbonation_spec_compliance',NULL,'kpi_number','compute','monthly',0,NULL,0,'operator',1640),
(165,'calibration_due','Calibration due (instruments)','qa_qc','production','qa_qc','calibration_due',NULL,'flag','gap','weekly',0,NULL,0,'operator',1650),
(166,'cip_verification_pass','Validation vérification CIP','qa_qc','production','qa_qc','cip_verification_pass',NULL,'flag','gap','monthly',0,NULL,0,'operator',1660),
(167,'allergen_label_compliance','Conformité allergènes / étiquetage','qa_qc','production','qa_qc','allergen_label_compliance',NULL,'flag','gap','monthly',0,NULL,0,'operator',1670),
(168,'traceability_completeness','Complétude traçabilité par lot','qa_qc','production','qa_qc','traceability_completeness',NULL,'kpi_number','compute','monthly',0,NULL,0,'operator',1680),

-- ─── Cat 10: COGS / Cost / Finance ──────────────────────────
-- ⚠️ ALL cat-10 handlers CONSUME pipeline output — never recompute!
(169,'cogs_total_month','COGS ce mois (total)','cogs_finance','admin','cogs','cogs_total_month','{"period":"current_month"}','kpi_number','live','monthly',0,NULL,1,'manager',1690),
(170,'cogs_per_hl','COGS / HL','cogs_finance','admin','cogs','cogs_per_hl','{"period":"latest_closed_month"}','kpi_number','live','monthly',1,2,1,'manager',1700),
(171,'cogs_per_unit_sku','COGS / unité par SKU','cogs_finance','admin','cogs','cogs_per_unit_sku',NULL,'table','live','monthly',0,NULL,1,'manager',1710),
(172,'brewing_cost_chf_hl','Coût brassage CHF/HL','cogs_finance','admin','cogs','brewing_cost_chf_hl','{"period":"latest_closed_month"}','kpi_number','live','monthly',0,NULL,1,'manager',1720),
(173,'cop_total_breakdown','COP total + décomposition (5 sections)','cogs_finance','admin','cogs','cop_total_breakdown',NULL,'stacked_bar','live','monthly',0,NULL,1,'manager',1730),
(174,'gross_margin_pct','Marge brute % global + par bière','cogs_finance','admin','cogs','gross_margin_pct',NULL,'bar','compute','monthly',0,NULL,0,'manager',1740),
(175,'full_cost_breakdown_beer','Décomposition coût complet par bière/SKU','cogs_finance','admin','cogs','full_cost_breakdown_beer',NULL,'waterfall','live','monthly',0,NULL,1,'manager',1750),
(176,'beer_tax_hl_liability','HL taxe bière / engagement ce mois','cogs_finance','admin','cogs','beer_tax_hl_liability','{"period":"current_month"}','kpi_number','live','monthly',0,NULL,1,'manager',1760),
(177,'beer_tax_by_category','Taxe bière par catégorie','cogs_finance','admin','cogs','beer_tax_by_category',NULL,'bar','live','monthly',0,NULL,1,'manager',1770),
(178,'indirect_cost_categorization','Catégorisation coûts indirects','cogs_finance','admin','cogs','indirect_cost_categorization',NULL,'donut','live','monthly',0,NULL,1,'manager',1780),
(179,'maintenance_opex','OPEX maintenance (excl. COP)','cogs_finance','admin','cogs','maintenance_opex',NULL,'sparkline','live','monthly',0,NULL,1,'manager',1790),
(180,'rd_qa_spend','Dépenses R&D / QA','cogs_finance','admin','cogs','rd_qa_spend',NULL,'sparkline','live','monthly',0,NULL,1,'manager',1800),
(181,'wip_value','Valeur WIP (balances cuves)','cogs_finance','admin','cogs','wip_value',NULL,'kpi_number','compute','monthly',0,NULL,0,'manager',1810),
(182,'total_inventory_valuation','Valorisation totale inventaire (MP+PF+WIP)','cogs_finance','admin','cogs','total_inventory_valuation',NULL,'kpi_number','live','monthly',0,NULL,1,'manager',1820),
(183,'cost_variance_prior_month','Écart coût vs mois précédent','cogs_finance','admin','cogs','cost_variance_prior_month',NULL,'bar','compute','monthly',0,NULL,0,'manager',1830),
(184,'cost_per_hl_trend','Tendance coût / HL','cogs_finance','admin','cogs','cost_per_hl_trend',NULL,'sparkline','compute','monthly',0,NULL,0,'manager',1840),
(185,'break_even_volume','Volume seuil de rentabilité','cogs_finance','admin','cogs','break_even_volume',NULL,'kpi_number','compute','monthly',0,NULL,0,'manager',1850),
(186,'contribution_margin_sku','Marge sur coûts variables par SKU','cogs_finance','admin','cogs','contribution_margin_sku',NULL,'bar','compute','monthly',0,NULL,0,'manager',1860),
(187,'cogs_pct_revenue','COGS en % du CA','cogs_finance','admin','cogs','cogs_pct_revenue',NULL,'kpi_number','compute','monthly',0,NULL,0,'manager',1870),
(188,'price_realisation_vs_inflation','Réalisation prix vs inflation coûts','cogs_finance','admin','cogs','price_realisation_vs_inflation',NULL,'sparkline','compute','monthly',0,NULL,0,'manager',1880),
(189,'cash_tied_inventory','Trésorerie immobilisée en stock','cogs_finance','admin','cogs','cash_tied_inventory',NULL,'kpi_number','compute','monthly',0,NULL,0,'manager',1890),
(190,'cost_of_quality','Coût de la qualité (gaspillage + retouches)','cogs_finance','admin','cogs','cost_of_quality',NULL,'kpi_number','compute','monthly',0,NULL,0,'manager',1900),
(191,'budget_vs_actual_pl','Budget vs réel P&L','cogs_finance','admin','cogs','budget_vs_actual_pl',NULL,'bar','gap','monthly',0,NULL,0,'manager',1910),

-- ─── Cat 11: Utilities / Energy / Sustainability ─────────────
(192,'electricity_kwh_month','Consommation électricité (kWh) ce mois','utilities','admin','utilities','electricity_kwh_month','{"period":"current_month"}','kpi_number','compute','monthly',0,NULL,0,'manager',1920),
(193,'peak_demand_kw','Pic de puissance (kW)','utilities','admin','utilities','peak_demand_kw','{"period":"latest_closed_month"}','kpi_number','live','monthly',0,NULL,1,'manager',1930),
(194,'reactive_power_kvarch','Puissance réactive (kVArh) / facteur de puissance','utilities','admin','utilities','reactive_power_kvarch',NULL,'kpi_number','live','monthly',0,NULL,1,'manager',1940),
(195,'electricity_cost_month','Coût électricité ce mois','utilities','admin','utilities','electricity_cost_month','{"period":"current_month"}','kpi_number','live','monthly',0,NULL,1,'manager',1950),
(196,'water_consumption_cost','Consommation eau + coût','utilities','admin','utilities','water_consumption_cost',NULL,'kpi_number','compute','monthly',0,NULL,0,'manager',1960),
(197,'gas_consumption_cost','Consommation gaz + coût','utilities','admin','utilities','gas_consumption_cost',NULL,'kpi_number','compute','monthly',0,NULL,0,'manager',1970),
(198,'energy_cost_per_hl','Coût énergie / HL produit','utilities','admin','utilities','energy_cost_per_hl',NULL,'kpi_number','compute','monthly',0,NULL,0,'manager',1980),
(199,'water_to_beer_ratio','Ratio eau/bière','utilities','admin','utilities','water_to_beer_ratio',NULL,'kpi_number','compute','monthly',1,4,0,'manager',1990),
(200,'kwh_per_hl_trend','kWh / HL tendance (intensité énergétique)','utilities','admin','utilities','kwh_per_hl_trend',NULL,'sparkline','compute','monthly',1,4,0,'manager',2000),
(201,'predictive_vs_actual_utilities','Prédictif vs réel utilités (précision modèle)','utilities','admin','utilities','predictive_vs_actual_utilities',NULL,'bar','compute','monthly',0,NULL,0,'manager',2010),
(202,'reactive_penalty_risk','Flag risque pénalité réactive','utilities','admin','utilities','reactive_penalty_risk',NULL,'flag','compute','monthly',0,NULL,0,'manager',2020),
(203,'co2_purchased_cost','CO₂ acheté usage + coût','utilities','admin','utilities','co2_purchased_cost',NULL,'kpi_number','live','monthly',0,NULL,1,'manager',2030),
(204,'voc_tax_exposure','Exposition taxe COV','utilities','admin','utilities','voc_tax_exposure',NULL,'kpi_number','compute','monthly',0,NULL,0,'manager',2040),
(205,'co2_footprint_per_hl','Empreinte CO₂ / HL','utilities','admin','utilities','co2_footprint_per_hl',NULL,'kpi_number','gap','monthly',0,NULL,0,'manager',2050),
(206,'spent_grain_volume','Volume drêches / trub (+ CA)','utilities','admin','utilities','spent_grain_volume',NULL,'kpi_number','gap','monthly',0,NULL,0,'operator',2060),
(207,'wastewater_load','Charge eaux usées','utilities','admin','utilities','wastewater_load',NULL,'kpi_number','gap','monthly',0,NULL,0,'manager',2070),
(208,'renewable_energy_pct','% énergie renouvelable','utilities','admin','utilities','renewable_energy_pct',NULL,'kpi_number','gap','monthly',0,NULL,0,'manager',2080),
(209,'heat_recovery','Récupération chaleur','utilities','admin','utilities','heat_recovery',NULL,'kpi_number','gap','monthly',0,NULL,0,'manager',2090),
(210,'peak_shaving_opportunity','Opportunité écrêtage pic','utilities','admin','utilities','peak_shaving_opportunity',NULL,'flag','compute','monthly',0,NULL,0,'manager',2100),
(211,'utility_cost_pct_cogs','Coût utilités en % COGS','utilities','admin','utilities','utility_cost_pct_cogs',NULL,'kpi_number','compute','monthly',0,NULL,0,'manager',2110),
(212,'seasonal_energy_curve','Courbe saisonnière consommation énergie','utilities','admin','utilities','seasonal_energy_curve',NULL,'sparkline','compute','monthly',0,NULL,0,'manager',2120),

-- ─── Cat 12: Ops / System Health & Data Quality ─────────────
(213,'open_rq_by_type','File d attente relecture ouverte par type','ops_health','admin','ops_health','open_rq_by_type',NULL,'bar','live','daily',0,NULL,1,'admin',2130),
(214,'rq_aging_oldest','Ancienneté file (plus ancien ouvert)','ops_health','admin','ops_health','rq_aging_oldest',NULL,'kpi_number','live','daily',0,NULL,1,'admin',2140),
(215,'last_ingest_status','Dernier statut ingest / ancienneté','ops_health','admin','ops_health','last_ingest_status',NULL,'kpi_number','live','daily',0,NULL,1,'admin',2150),
(216,'docs_awaiting_triage','Documents en attente de triage','ops_health','admin','ops_health','docs_awaiting_triage',NULL,'kpi_number','live','daily',0,NULL,1,'admin',2160),
(217,'invoices_needing_line_items','Factures nécessitant saisie lignes / non-matchées','ops_health','admin','ops_health','invoices_needing_line_items',NULL,'kpi_number','live','daily',0,NULL,1,'admin',2170),
(218,'ingest_success_rate','Taux succès ingest / erreurs cette semaine','ops_health','admin','ops_health','ingest_success_rate','{"period":"current_week"}','kpi_number','live','weekly',0,NULL,1,'admin',2180),
(219,'orphan_deliveries','Livraisons orphelines (DN/facture)','ops_health','admin','ops_health','orphan_deliveries',NULL,'kpi_number','live','daily',0,NULL,1,'admin',2190),
(220,'parser_coverage_rate','Couverture parseurs / taux sans parseur','ops_health','admin','ops_health','parser_coverage_rate',NULL,'kpi_number','compute','monthly',0,NULL,0,'admin',2200),
(221,'quarantined_values_count','Valeurs en quarantaine','ops_health','admin','ops_health','quarantined_values_count',NULL,'kpi_number','compute','daily',0,NULL,0,'admin',2210),
(222,'validation_rule_failures','Échecs règles de validation','ops_health','admin','ops_health','validation_rule_failures',NULL,'kpi_number','compute','daily',0,NULL,0,'admin',2220),
(223,'pending_deliveries_aging','Ancienneté livraisons en attente','ops_health','admin','ops_health','pending_deliveries_aging',NULL,'kpi_number','live','daily',0,NULL,1,'operator',2230),
(224,'data_freshness','Fraîcheur données (dernière saisie par source)','ops_health','admin','ops_health','data_freshness',NULL,'table','compute','daily',0,NULL,0,'admin',2240),
(225,'docs_processed_month','Documents traités ce mois','ops_health','admin','ops_health','docs_processed_month','{"period":"current_month"}','kpi_number','live','monthly',0,NULL,1,'admin',2250),
(226,'supplier_mi_resolution_failures','Échecs résolution fournisseur/MP (FK null)','ops_health','admin','ops_health','supplier_mi_resolution_failures',NULL,'kpi_number','compute','daily',0,NULL,0,'admin',2260),
(227,'llm_fallback_usage_rate','Taux utilisation LLM fallback','ops_health','admin','ops_health','llm_fallback_usage_rate',NULL,'kpi_number','compute','monthly',0,NULL,0,'admin',2270),
(228,'auto_write_vs_manual_ratio','Ratio écriture auto vs revue manuelle','ops_health','admin','ops_health','auto_write_vs_manual_ratio',NULL,'kpi_number','compute','monthly',0,NULL,0,'admin',2280),
(229,'avg_triage_time','Temps moyen de triage par opérateur','ops_health','admin','ops_health','avg_triage_time',NULL,'bar','compute','monthly',0,NULL,0,'admin',2290),
(230,'classifier_accuracy','Précision classifieur','ops_health','admin','ops_health','classifier_accuracy',NULL,'kpi_number','compute','monthly',0,NULL,0,'admin',2300),
(231,'duplicate_detection_hits','Détections de doublons','ops_health','admin','ops_health','duplicate_detection_hits',NULL,'kpi_number','compute','monthly',0,NULL,0,'admin',2310),
(232,'system_uptime','Disponibilité système','ops_health','admin','ops_health','system_uptime',NULL,'kpi_number','gap','daily',0,NULL,0,'admin',2320),
(233,'backup_status','Statut sauvegardes','ops_health','admin','ops_health','backup_status',NULL,'flag','compute','daily',0,NULL,0,'admin',2330),
(234,'form_submission_compliance','Conformité saisies par site','ops_health','admin','ops_health','form_submission_compliance',NULL,'bar','compute','monthly',0,NULL,0,'admin',2340),

-- ─── Cat 13: Equipment / Maintenance & People / Safety ───────
(235,'maintenance_opex_trend','Tendance OPEX maintenance','equipment','admin','cogs','maintenance_opex_trend',NULL,'sparkline','live','monthly',0,NULL,1,'manager',2350),
(236,'equipment_vessel_utilization','Utilisation équipements / cuves','equipment','production','equipment','equipment_vessel_utilization',NULL,'bar','compute','monthly',0,NULL,0,'operator',2360),
(237,'equipment_uptime_downtime','Disponibilité / arrêts équipements','equipment','production','equipment','equipment_uptime_downtime',NULL,'kpi_number','gap','monthly',0,NULL,0,'operator',2370),
(238,'preventive_maintenance_due','Maintenance préventive due / en retard','equipment','production','equipment','preventive_maintenance_due',NULL,'flag','gap','weekly',0,NULL,0,'operator',2380),
(239,'unplanned_stops_mtbf','Arrêts non planifiés / MTBF','equipment','production','equipment','unplanned_stops_mtbf',NULL,'kpi_number','gap','monthly',0,NULL,0,'operator',2390),
(240,'spare_parts_inventory','Stock pièces de rechange','equipment','logistics','equipment','spare_parts_inventory',NULL,'kpi_number','gap','monthly',0,NULL,0,'operator',2400),
(241,'labor_hours_cost_per_hl','Heures travail / coût par HL','equipment','admin','equipment','labor_hours_cost_per_hl',NULL,'kpi_number','gap','monthly',0,NULL,0,'manager',2410),
(242,'productivity_hl_per_fte','Productivité (HL par ETP)','equipment','admin','equipment','productivity_hl_per_fte',NULL,'kpi_number','gap','monthly',0,NULL,0,'manager',2420),
(243,'active_users_logins','Utilisateurs actifs / connexions','equipment','admin','ops_health','active_users_logins',NULL,'kpi_number','live','daily',0,NULL,1,'admin',2430),
(244,'training_certification_status','Statut formations / certifications','equipment','admin','equipment','training_certification_status',NULL,'flag','gap','monthly',0,NULL,0,'manager',2440),
(245,'safety_incidents','Incidents sécurité / jours depuis dernier','equipment','admin','equipment','safety_incidents',NULL,'flag','gap','monthly',0,NULL,0,'manager',2450),
(246,'overtime_rate','Taux heures supplémentaires','equipment','admin','equipment','overtime_rate',NULL,'kpi_number','gap','monthly',0,NULL,0,'manager',2460),
(247,'shift_coverage','Couverture postes','equipment','admin','equipment','shift_coverage',NULL,'kpi_number','gap','daily',0,NULL,0,'manager',2470),
(248,'cip_cleaning_cycles','Cycles CIP / nettoyage','equipment','production','equipment','cip_cleaning_cycles',NULL,'kpi_number','gap','monthly',0,NULL,0,'operator',2480),
(249,'instrument_calibration_log','Journal calibration instruments','equipment','production','equipment','instrument_calibration_log',NULL,'flag','gap','monthly',0,NULL,0,'operator',2490),
(250,'energy_per_equipment','Énergie par équipement','equipment','admin','utilities','energy_per_equipment',NULL,'bar','gap','monthly',0,NULL,0,'manager',2500),
(251,'line_changeover_time','Temps changement ligne','equipment','production','equipment','line_changeover_time',NULL,'kpi_number','gap','monthly',0,NULL,0,'operator',2510),

-- ─── Cat 14: Control-Loop / Cross-Cutting (expert-added) ────
(252,'beer_loss_cascade','🌟 Cascade pertes bière + % total','control_loop','production','wort','beer_loss_cascade',NULL,'waterfall','compute','monthly',1,1,0,'manager',2520),
(253,'extract_efficiency_lab','Efficacité extractive vs extrait labo','control_loop','production','wort','extract_efficiency_lab',NULL,'kpi_number','gap','monthly',0,NULL,0,'manager',2530),
(254,'dryhop_absorption_loss','Pertes absorption dry-hop','control_loop','production','wort','dryhop_absorption_loss',NULL,'kpi_number','gap','monthly',0,NULL,0,'operator',2540),
(255,'fv_trub_loss','Pertes FV / trub (knock-out → FV)','control_loop','production','wort','fv_trub_loss',NULL,'kpi_number','gap','monthly',0,NULL,0,'operator',2550),
(256,'giveaway_overfill','Surgavage / sur-remplissage (% et CHF)','control_loop','production','packaging','giveaway_overfill',NULL,'kpi_number','gap','monthly',0,NULL,0,'manager',2560),
(257,'packaging_line_oee','🌟 OEE ligne packaging','control_loop','production','packaging','packaging_line_oee',NULL,'kpi_number','gap','monthly',1,3,0,'manager',2570),
(258,'brewhouse_oee','OEE / utilisation brasserie','control_loop','production','wort','brewhouse_oee',NULL,'kpi_number','gap','monthly',0,NULL,0,'manager',2580),
(259,'changeover_cip_pct_available','Changement / CIP en % du temps disponible','control_loop','production','wort','changeover_cip_pct_available',NULL,'kpi_number','gap','monthly',0,NULL,0,'operator',2590),
(260,'mtbf_mttr_packaging','MTBF / MTTR (ligne packaging)','control_loop','production','equipment','mtbf_mttr_packaging',NULL,'kpi_number','gap','monthly',0,NULL,0,'manager',2600),
(261,'plan_attainment_pct','🌟 Atteinte plan / conformité planification %','control_loop','production','wort','plan_attainment_pct',NULL,'kpi_number','gap','monthly',1,5,0,'manager',2610),
(262,'forecast_accuracy','Précision prévision (ventes)','control_loop','general','sales','forecast_accuracy',NULL,'kpi_number','gap','monthly',0,NULL,0,'manager',2620),
(263,'otif_to_customer','OTIF client (livraison à temps en totalité)','control_loop','logistics','logistics','otif_to_customer',NULL,'kpi_number','gap','monthly',0,NULL,0,'manager',2630),
(264,'right_first_time_pct','🌟 Right-First-Time % (tous stades)','control_loop','production','qa_qc','right_first_time_pct',NULL,'kpi_number','compute','monthly',1,6,0,'manager',2640),
(265,'complaint_ppm','PPM réclamations (qualité marché)','control_loop','general','qa_qc','complaint_ppm',NULL,'kpi_number','gap','monthly',0,NULL,0,'manager',2650),
(266,'safety_ltifr','Sécurité — LTIFR / jours sans incident','control_loop','admin','equipment','safety_ltifr',NULL,'kpi_number','gap','monthly',0,NULL,0,'manager',2660),
(267,'inventory_days_of_supply','Jours de couverture stock (MP + PF)','control_loop','logistics','rm_procurement','inventory_days_of_supply',NULL,'kpi_number','compute','monthly',0,NULL,1,'operator',2670),
(268,'cash_conversion_cycle','Cycle de conversion de trésorerie','control_loop','admin','cogs','cash_conversion_cycle',NULL,'kpi_number','gap','monthly',0,NULL,0,'manager',2680),
(269,'mass_energy_water_balance','Bilan masse / énergie / eau (usine)','control_loop','production','utilities','mass_energy_water_balance',NULL,'waterfall','compute','monthly',0,NULL,0,'manager',2690);

-- ─────────────────────────────────────────────────────────────
-- Set data_ready=1 for ✅ live + clearly cheap 🔶 computable
-- (All data_ready already defaulted to 0; update the ready set.)
-- ─────────────────────────────────────────────────────────────
UPDATE `ref_kpi_trackers` SET `data_ready` = 1 WHERE `tracker_no` IN (
  -- Cat 1: Production — ✅ live
  1, 2, 3, 4, 5, 6, 8, 9,
  -- Cat 7: RM Procurement — ✅ live
  108, 109, 112, 113, 114, 119, 132,
  -- Cat 10: COGS / Finance — ✅ live (reads pipeline JSON/DB output)
  169, 170, 171, 172, 173, 175, 176, 177, 178, 179, 180, 182,
  -- Cat 11: Utilities — ✅ live
  193, 194, 195, 203,
  -- Cat 12: Ops Health — ✅ live (reads doc_review_queue/ingest_runs/doc_uploads)
  213, 214, 215, 216, 217, 218, 219, 223, 225,
  -- Cat 13: Equipment — ✅ live (reads users table)
  235, 243
);

-- schema_meta classification.
INSERT INTO schema_meta
    (table_name, table_class, corrections_policy, writer_script, upstream_hint)
VALUES
    ('ref_kpi_trackers', 'reference', 'allowed',
     'db/migrations/269_ref_kpi_trackers.sql (seed); flip data_ready=1 in the same migration that delivers each gap''s capture',
     'KPI tracker catalog. Edit label/viz_type/params_json/data_ready/is_active via admin UI or direct MySQL edit. data_ready: 1=selectable/renderable, 0=blocked on data gap. Never fabricate metrics — each handler must wrap an existing canonical source.');
