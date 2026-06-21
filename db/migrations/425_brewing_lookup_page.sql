-- 425_brewing_lookup_page.sql
-- Register standalone read-only brewing-lookup page (all logged-in users).

INSERT INTO ref_pages
  (page_key, label, icon, href, min_role, domain, category_key, category_sort, is_active, sort)
VALUES
  ('brewing-lookup', 'Consulter un brassin', '🔍', '/modules/brewing-lookup.php',
   'viewer', 'general', 'pilotage', 30, 1, 17);

-- Grant to EVERY non-admin preset (operator said "for everyone"), else preset-assigned users 403.
INSERT IGNORE INTO ref_access_preset_pages (preset_id_fk, page_id_fk)
SELECT p.id, rp.id
  FROM ref_access_presets p
  CROSS JOIN ref_pages rp
 WHERE rp.page_key = 'brewing-lookup'
   AND p.preset_key IN
       ('manager','production_operator','logistics_operator',
        'marketing','sales_manager','finance_viewer','smoke_viewer');
