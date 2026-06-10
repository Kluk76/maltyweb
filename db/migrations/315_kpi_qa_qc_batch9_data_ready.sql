-- Migration 315: Phase 2b Batch 9 — qa_qc KPI handlers data_ready flip
-- Sets data_ready=1 for the 12 built qa_qc trackers (readiness='compute').
-- Stub trackers (readiness='gap', tracker_nos 151,157-163,165-167,265) remain data_ready=0.
-- tracker_no is globally unique — no domain filter needed.
SET @noop = 1;

UPDATE ref_kpi_trackers
   SET data_ready = 1
 WHERE tracker_no IN (
     147, -- batches_pending_qa
     148, -- qa_pass_fail_rate
     149, -- qa_outliers_flagged
     150, -- out_of_spec_batches
     152, -- final_ph_deviations
     153, -- do_co2_spec_compliance
     154, -- batch_release_cycle_time
     155, -- recurring_quality_issues
     156, -- first_pass_quality_rate
     164, -- carbonation_spec_compliance
     168, -- traceability_completeness
     264  -- right_first_time_pct
 );

SET @noop = 1;
