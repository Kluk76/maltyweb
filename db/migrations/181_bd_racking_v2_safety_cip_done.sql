-- Migration 181: bd_racking_v2 — add safety_cip_done
-- Mirrors centri_rinsed (VARCHAR(8) NULL, stores 'Oui'/'Non').
-- Tracks whether the Safety CIP was performed before the transfer.
-- INSTANT algorithm (MySQL 8, no table rebuild).

ALTER TABLE bd_racking_v2
  ADD COLUMN safety_cip_done VARCHAR(8) NULL AFTER centri_rinsed;
