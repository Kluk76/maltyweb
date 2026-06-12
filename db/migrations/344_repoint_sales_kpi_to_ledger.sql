-- 344 — Repoint legacy bc KPI tiles to inv_sales_ledger.
-- Tranche 2 (86,92,187): data_ready=0 pending Opus verification.
-- Retire #94 (superseded by #275), #100 (discount col missing from ledger).

-- Tranche 2: drop to pending (Opus verifies numbers independently)
UPDATE ref_kpi_trackers SET data_ready = 0 WHERE tracker_no IN (86, 92, 187);

-- Retire #94: superseded by #275 hl_by_trade_channel
UPDATE ref_kpi_trackers SET is_active = 0 WHERE tracker_no = 94;

-- Retire #100: discount_amount_chf absent from inv_sales_ledger
UPDATE ref_kpi_trackers SET data_ready = 0, is_active = 0 WHERE tracker_no = 100;
