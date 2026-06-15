-- Migration 356: ops_health pipeline-health tiles → live
-- Sets data_ready=1/readiness='live' for the 4 MySQL-native handler tiles (224,226,228,229).
-- Re-tiers 7 trackers with no reachable MySQL source as readiness='gap':
--   #77  fg_aging_best_before   — no DDM/best-before column in inv_fg_stocktake
--   #220 parser_coverage_rate   — data lives in Node-side ingest, no MySQL table
--   #221 quarantined_values_count — data in data/quarantine.json, not MySQL
--   #222 validation_rule_failures — data in Node/JS validator, not MySQL
--   #227 llm_fallback_usage_rate  — data in data/llm-extract-cache/ filesystem, not MySQL
--   #230 classifier_accuracy      — data in Node-side classifier, not MySQL
--   #231 duplicate_detection_hits — file_hash col unpopulated; zero dup RQ rows in practice
-- data_ready stays 0 on gap rows; is_active unchanged (already 0 on unreachable ones).

UPDATE ref_kpi_trackers
   SET data_ready = 1,
       readiness  = 'live'
 WHERE tracker_no IN (224, 226, 228, 229);

UPDATE ref_kpi_trackers
   SET readiness  = 'gap',
       data_ready = 0
 WHERE tracker_no IN (77, 220, 221, 222, 227, 230, 231);
