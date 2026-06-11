-- Migration 331: add grouped_bar to ref_kpi_trackers.viz_type ENUM
-- and reassign production_by_beer_yoy to use it.
-- MySQL 8 (NOT MariaDB) — no ADD COLUMN IF NOT EXISTS.
-- No SELECT statements (migrate.php uses PDO exec()).

ALTER TABLE ref_kpi_trackers
  MODIFY COLUMN viz_type
    ENUM('kpi_number','sparkline','bar','stacked_bar','line','donut','flag','table','waterfall','recap','grouped_bar')
    NOT NULL DEFAULT 'kpi_number';

UPDATE ref_kpi_trackers
   SET viz_type = 'grouped_bar',
       label    = 'Production par bière — YTD vs N-1'
 WHERE slug = 'production_by_beer_yoy';
