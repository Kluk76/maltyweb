-- Trim bd_brewing_brewday: drop columns that belong to other event_types.
-- After dispatch refactor, event_type='Brewday' rows fill ONLY cols 3-13
-- (CCT/yeast info). Gravities/Timings/etc. each have their own table.
-- These DROPs are safe because no rows have been ingested yet — the table
-- has been created but never populated.

ALTER TABLE bd_brewing_brewday
  DROP COLUMN first_wort_beer,
  DROP COLUMN first_wort_batch,
  DROP COLUMN first_wort_brew,
  DROP COLUMN first_wort_gravity,
  DROP COLUMN first_wort_ph,
  DROP COLUMN pfann_beer,
  DROP COLUMN pfann_batch,
  DROP COLUMN pfann_brew,
  DROP COLUMN pfann_gravity,
  DROP COLUMN koch_beer,
  DROP COLUMN koch_batch,
  DROP COLUMN koch_brew,
  DROP COLUMN koch_gravity,
  DROP COLUMN brew_beer,
  DROP COLUMN brew_batch,
  DROP COLUMN brew_label,
  DROP COLUMN brew_start,
  DROP COLUMN brew_end;
