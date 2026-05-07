-- 022 — add 'manager' to users.role enum.
--
-- 'manager' role gates the Settings page (CRUD on reference data).
-- 'admin' retains exclusive access to the DB Browser + LLM-correction tool.
-- Additive change — existing rows untouched, default unchanged.

ALTER TABLE users
  MODIFY COLUMN role ENUM('admin','operator','viewer','manager') NOT NULL DEFAULT 'operator';
