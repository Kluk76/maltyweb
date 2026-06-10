SET @noop = 1;

-- Migration 316: KPI batch 10 — logistics + equipment data_ready flip
-- Logistics BUILT: #134 orders_to_fulfil, #135 outbound_delivery_notes,
--                  #138 order_backlog, #141 pick_pack_throughput
-- Equipment BUILT: #236 equipment_vessel_utilization (BBT live; CCT gap noted)
-- All other logistics and equipment trackers remain readiness='gap' / data_ready=0.

UPDATE ref_kpi_trackers
   SET data_ready = 1
 WHERE tracker_no IN (134, 135, 138, 141, 236);

SET @noop = 1;
