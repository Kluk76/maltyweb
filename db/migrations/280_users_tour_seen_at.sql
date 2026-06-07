-- 278_users_tour_seen_at.sql
-- Add tour_seen_at column to users for onboarding tour first-view tracking.
-- NULL = never seen; NOT NULL = timestamp of first visit (marked on page load).
-- MySQL 8: NO "IF NOT EXISTS" on ADD COLUMN (MariaDB-only syntax).

ALTER TABLE users
  ADD COLUMN tour_seen_at TIMESTAMP NULL DEFAULT NULL
    COMMENT 'First time the user loaded the visite-guidee page; NULL = never seen';
