-- Migration 333: mark tank occupancy KPI trackers as data_ready=1 + flip #13 to bar viz
-- Source: kpi_tanks_occupancy / kpi_tanks_cct_utilization / kpi_tanks_cct_idle_days / kpi_tanks_hl_in_tank
-- Handlers in app/kpi-handlers.php, consuming TankSimulator::run().
UPDATE ref_kpi_trackers SET data_ready = 1, readiness = 'live' WHERE tracker_no IN (13, 14, 15, 17);
UPDATE ref_kpi_trackers SET viz_type = 'bar' WHERE tracker_no = 13;
