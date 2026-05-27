-- db/migrations/180_bd_racking_v2_kze_pu.sql
-- What: Add kze_target_pu and kze_avg_pu (pasteurisation unit fields) to bd_racking_v2.
-- Why:  When the KZE flash pasteuriser is used during a transfer the operator needs to
--       record the PU setpoint (target) and the achieved average PU over the run.
--       The KZE section in the form is shown only when KZE is in the CIP set
--       (cip_machine_kze checked OR cip_inline_combine checked).
-- Risk: Low — ADD COLUMN NULL with no default; ALGORITHM=INSTANT on MySQL 8.
-- Rollback: ALTER TABLE bd_racking_v2 DROP COLUMN kze_target_pu, DROP COLUMN kze_avg_pu;

ALTER TABLE bd_racking_v2
  ADD COLUMN kze_target_pu DECIMAL(6,2) NULL COMMENT 'KZE PU setpoint (pasteurisation units target)' AFTER centri_rinsed,
  ADD COLUMN kze_avg_pu    DECIMAL(6,2) NULL COMMENT 'KZE average achieved PU over the run'          AFTER kze_target_pu,
  ALGORITHM = INSTANT;
