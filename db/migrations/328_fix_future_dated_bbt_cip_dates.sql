-- 328_fix_future_dated_bbt_cip_dates.sql
-- Corrects bd_cip_events.cip_date rows that are future-dated (data-entry artifact)
-- by resetting them to their parent racking's event_date (the canonical CIP date).
-- Scope: BBT-target, non-tombstoned, parented on a racking, and whose cip_date
-- is in ISO YYYY-MM-DD format AND parses to a future date relative to CURDATE().
-- The REGEXP pre-filter (^[0-9]{4}-) excludes legacy M/D/YYYY rows before STR_TO_DATE
-- runs, avoiding MySQL strict-mode err 1411 on mismatched format strings.
-- Dry-run verified 2026-06-11: exactly 10 rows matched ids {756,757,758,761,764,771,777,780,782,788}.
-- All 10 target rows use YYYY-MM-DD HH:MM:SS format; DATE_FORMAT preserves that on write.
UPDATE bd_cip_events ce
  JOIN bd_racking_v2 r ON r.id = ce.racking_id
   SET ce.cip_date = DATE_FORMAT(r.event_date, '%Y-%m-%d %H:%i:%s')
 WHERE ce.target_code   = 'bbt'
   AND ce.is_tombstoned = 0
   AND ce.racking_id IS NOT NULL
   AND ce.cip_date REGEXP '^[0-9]{4}-'
   AND STR_TO_DATE(ce.cip_date, '%Y-%m-%d %H:%i:%s') > CURDATE();
