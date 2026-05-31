-- 230_cip_target_code_add_filler.sql
--
-- What:  Extend bd_cip_events.target_code ENUM to include 'filler' (Tireuse),
--        and update chk_cip_target CHECK constraint to allow it for machine rows.
--
-- Why:   Packaging CIP must support the filling machine (Tireuse) as a target,
--        optionally combined inline with KZE. The existing ENUM lacks 'filler'
--        and the CHECK constraint explicitly restricts machine codes to the
--        original set — both must be extended together or filler inserts will
--        fail with a constraint violation.
--
-- Rollback (only safe if no 'filler' rows exist in the table):
--   ALTER TABLE bd_cip_events DROP CONSTRAINT chk_cip_target;
--   ALTER TABLE bd_cip_events
--     MODIFY COLUMN target_code
--       ENUM('centri','kze','pump','cct','yt','bbt','tank','unspecified')
--       CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL;
--   ALTER TABLE bd_cip_events
--     ADD CONSTRAINT chk_cip_target CHECK (
--       (target_kind = 'machine' AND target_code IN ('centri','kze','pump','unspecified'))
--       OR
--       (target_kind = 'vessel'  AND target_code IN ('cct','yt','bbt','tank'))
--     );
--
-- Notes:
--   - 'filler' is appended at the END of the ENUM member list. ENUM ordinals are
--     positional; reordering would silently remap existing stored values.
--   - Historical rows with target_code='centri'/'kze'/'pump'/'unspecified' are
--     left untouched — no backfill. We cannot determine which were actually the
--     filling machine, so we refuse to guess.
--   - No schema_meta INSERT: this is an ALTER on an existing table, not a new table.
--   - ALGORITHM=INSTANT is valid only for the ENUM append (Step 2 — appending a
--     member at the end does not rebuild the table). Re-adding the CHECK (Step 3)
--     does NOT support ALGORITHM=INSTANT (MySQL validates existing rows → INPLACE),
--     so Step 3 carries no ALGORITHM clause and is a separate statement.
--

-- Step 1: Drop the existing CHECK constraint (MySQL 8 requires DROP before re-add
-- because ALTER TABLE cannot modify a CHECK constraint in-place).
ALTER TABLE bd_cip_events
    DROP CONSTRAINT chk_cip_target;

-- Step 2: Append 'filler' to the ENUM (at the END — ordinals preserved).
-- NO ALGORITHM clause: this server rejects ALGORITHM=INSTANT for ENUM MODIFY
-- (MySQL error 1845) and INSTANT can't carry a LOCK clause anyway (error 1221).
-- bd_cip_events is small, so letting MySQL pick (COPY) is fine.
ALTER TABLE bd_cip_events
    MODIFY COLUMN target_code
        ENUM('centri','kze','pump','cct','yt','bbt','tank','unspecified','filler')
        CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL;

-- Step 3: Re-add the CHECK with 'filler' allowed for machine rows.
-- NOTE: ADD CONSTRAINT ... CHECK is NOT supported by ALGORITHM=INSTANT — MySQL 8
-- validates existing rows (INPLACE), so no ALGORITHM clause is given (engine picks
-- INPLACE). The table is small and all existing rows already satisfy the predicate.
ALTER TABLE bd_cip_events
    ADD CONSTRAINT chk_cip_target CHECK (
        (target_kind = 'machine' AND target_code IN ('centri','kze','pump','unspecified','filler'))
        OR
        (target_kind = 'vessel'  AND target_code IN ('cct','yt','bbt','tank'))
    );
