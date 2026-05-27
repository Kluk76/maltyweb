-- Migration 183: Add raw event-input columns for Pertes section + interrupted-transfer CIP
-- to bd_racking_v2. Schema foundation only — form writes + $hashCols plumbing land in later commits.
--
-- Loss semantics (additive, not replacing racked_vol_hl):
--   After the racking event:
--     source tank balance = V_source − racked_vol_hl − loss_source_hl
--     dest   tank balance = residual + racked_vol_hl − loss_dest_hl
--   Both loss_* columns are NULL when no loss was entered (= zero-loss assumption intact).
--
-- ALGORITHM=INSTANT: all columns are nullable or have a constant default — no row rebuild required.
--
-- Rollback:
--   ALTER TABLE `bd_racking_v2`
--     DROP COLUMN `loss_source_hl`,
--     DROP COLUMN `loss_dest_hl`,
--     DROP COLUMN `loss_cause`,
--     DROP COLUMN `loss_note`,
--     DROP COLUMN `interrupted_flag`,
--     DROP COLUMN `interrupted_reason`,
--     DROP COLUMN `dest_bbt_still_clean`;

ALTER TABLE `bd_racking_v2`
  ADD COLUMN `loss_source_hl` DECIMAL(8,3) NULL
    COMMENT 'HL destroyed / never transferred at the SOURCE tank. Additive: source_balance = V_source − racked_vol_hl − loss_source_hl.',
  ADD COLUMN `loss_dest_hl` DECIMAL(8,3) NULL
    COMMENT 'HL lost at or inside the DESTINATION tank (spillage, dead-leg, etc.). Additive: dest_balance = residual + racked_vol_hl − loss_dest_hl.',
  ADD COLUMN `loss_cause` ENUM('produit','machine','humain') NULL
    COMMENT 'Coarse analytical category for the loss event. Closed domain → ENUM. NULL when no loss was recorded.',
  ADD COLUMN `loss_note` VARCHAR(500) NULL
    COMMENT 'Free-text operator explanation of the loss. NULL when no loss was recorded.',
  ADD COLUMN `interrupted_flag` TINYINT(1) NOT NULL DEFAULT 0
    COMMENT '1 = transfer was interrupted before completion. App enforces interrupted_reason is set when this is 1.',
  ADD COLUMN `interrupted_reason` VARCHAR(500) NULL
    COMMENT 'Free-text reason for the interruption. App-enforced required when interrupted_flag = 1.',
  ADD COLUMN `dest_bbt_still_clean` TINYINT(1) NULL
    COMMENT 'Captured ONLY when interrupted_flag=1 AND racked_vol_hl=0 (\"BBT encore propre ?\"). NULL in all other cases.',
  ALGORITHM=INSTANT;

SET @noop = 1;
