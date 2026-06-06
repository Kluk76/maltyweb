# MaltyWeb KPI Tracker Catalog — v1 working spec

Source of truth for `ref_kpi_trackers` (Phase 2 of the user-account deepening arc).
Co-authored with the operator (Kouros) 2026-06-06, category by category.

Each tracker becomes one `ref_kpi_trackers` row whose `compute_handler` **wraps an
existing canonical metric** — never recomputes a fact a second way. The personal
dashboard (`mon-tableau.php`) and the recap email share the same `compute_handler`.

## Readiness legend
- ✅ **LIVE** — the number is already computed somewhere; the tracker just exposes it.
- 🔶 **COMPUTE** — the source data exists; needs a new aggregation/query (no new capture).
- ⛔ **GAP** — needs new data capture first (a form field, a pipeline fix, or an external integration). Cataloged now so it activates the moment its data lands; **never fabricated**.

## Readiness tally (provisional — verified against schema at seed time)
- ✅ LIVE: ~38
- 🔶 COMPUTE: ~140
- ⛔ GAP: ~90 (incl. the expert-added control-loop canon, Category 14)
- **Total: ~268 active trackers** (#57 dropped; #252–269 added from the expert audit).

> An external **VP-Brewing-Operations audit** (`docs/kpi-catalog-expert-audit.md`, 2026-06-06) found the
> original 250 measured *levels and counts, not control loops*. Its missing canon is folded in as
> **Category 14**; its hero shortlist is the **Hero metrics** section below. Headline finding: there was no
> single reconciled **beer-loss / extract cascade** — now #252, designated hero H1.

## Cross-cutting "smart" engines
Two flagship predictive trackers share one engine (sales-velocity × inventory-cover × free-capacity):
- **#23 — Suggested next beers to brew** (sales rate × FG cover × free tank capacity)
- **#64 — Suggested packaging events** (FG cover × sales rate × what's ready in tank)
Build the engine once; both trackers call it. Heavier than the rest (Phase 2b).

Flag-type trackers (deviation/alert, not a level): #85 FG stock-variation, #131 overpriced-purchase, plus the existing RM RQ alerts (#108/#109/#119).

## Hero metrics (expert-designated — the dashboard's prominent big-number cards)
The audit's shortlist of the 6 a production director is held accountable for. Four don't fully exist yet — build toward them. The **volume triad (#1 HL brewed, #3 brew count, #9 brews-this-week, #48 units packaged) is explicitly DEMOTED from the hero row** (vanity volume — kept in the catalog/dashboards, never a hero card).

| Hero | Metric | Maps to | Status |
|------|--------|---------|--------|
| **H1** | Total Beer Loss % + stage waterfall | #252 | partial buildable now; full needs FV/dry-hop capture |
| **H2** | COGS / HL & / SKU | #170 / #171 / #184 | ✅ live |
| **H3** | Packaging-line OEE | #257 | needs availability/downtime capture |
| **H4** | Water/beer ratio + kWh/HL | #199 / #200 | ✅ promote |
| **H5** | Plan attainment % | #261 | needs production-schedule artifact |
| **H6** | Right-First-Time % (all stages) | #264 (#156 partial) | needs stage RFT rollup |

---

## 1 — 🍺 Production (Brewing)  · source: `bd_brewing_gravity_v2`, `ref_recipes`, efficiency pipeline
| # | Tracker | Readiness |
|---|---------|-----------|
| 1 | HL brewed this month (core·special·contract) | ✅ |
| 2 | HL brewed YTD vs last YTD (pace) | ✅ |
| 3 | Brew count this month | ✅ |
| 4 | Avg HL per brew | ✅ |
| 5 | Production by beer — YoY sparklines | ✅ |
| 6 | Brewhouse yield / efficiency % | ✅ |
| 7 | OG attainment vs recipe target | 🔶 (needs target-OG on recipe) |
| 8 | Days since last brew (idle signal) | ✅ |
| 9 | Brews this week | ✅ |
| 10 | Avg time per brew (brewday duration) | 🔶 (`bd_brewing_timings_v2`) |
| 11 | Ingredient consumption / month by category | 🔶 (`inv_consumption`) |
| 12 | Brewing deviations (OG, brew-time, mash-pH vs profile) | 🔶 |

## 2 — 🫧 Fermentation / Tanks  · source: tank simulation, `Tank_Balances`, `bd_fermenting`, capacités
| # | Tracker | Readiness |
|---|---------|-----------|
| 13 | CCT/BBT occupancy now (occupied vs free + %) | 🔶 |
| 14 | CCT capacity utilization % (HL filled ÷ total) | 🔶 |
| 15 | CCT idle days (avg empty days/tank — least-empty goal) | 🔶 |
| 16 | Share of CCT-days per beer | 🔶 |
| 17 | HL currently in tank (WIP) | 🔶 |
| 18 | Beers fermenting now + days in tank | 🔶 |
| 19 | Garde / time-to-rack vs target | 🔶 (target `garde_days` may be NULL) |
| 20 | Tank turns per month | 🔶 |
| 21 | Cold-crash / dry-hop in progress | 🔶 |
| 22 | Fermentation deviations (FG, attenuation, duration) | 🔶 |
| 23 | 🌟 Suggested next beers to brew | 🔶🔶 flagship engine |
| 24 | Temp/pressure excursions | 🔶 pressure / ⛔ temp (TBC) |
| 25 | Diacetyl-rest tracking | ⛔ |
| 26 | Yeast generation # | ⛔ |
| 27 | Repitch count | ⛔ |
| 28 | Harvest yield | ⛔ |
| 29 | CO₂ recovery | ⛔ |
| 30 | CIP / cleaning due | ⛔ |
| 31 | Avg fermentation time per beer | 🔶 |
| 32 | Avg yeast generation | ⛔ |
| 33 | Yeast-gen vs ferment-time comparative | ⛔ (needs 26+31) |
| 34 | Avg O₂ in BBT | 🔶 (co2o2 in-tank reads) |
| 35 | O₂ deviations | 🔶 |
| 36 | Turbidity + turbidity deviations | ⛔ |

## 3 — 🪣 Racking  · source: `bd_racking_v2`
| # | Tracker | Readiness |
|---|---------|-----------|
| 37 | Avg racking time per beer | 🔶 |
| 38 | Avg racking time per HL (total) | 🔶 |
| 39 | Rackings this month (count + HL) | 🔶 |
| 40 | Racking loss % (brewed HL → racked HL) | 🔶 |
| 41 | Avg time brew→rack (cycle time per beer) | 🔶 |
| 42 | Blends / multi-tank rackings count | 🔶 |
| 43 | Racking yield vs target | 🔶 (target may be NULL) |
| 44 | Dissolved-O₂ pickup at racking | 🔶/⛔ (capture at racking step TBC) |
| 45 | Carbonation level achieved | 🔶/⛔ (TBC) |
| 46 | Racking → packaging lag | 🔶 |
| 47 | Tank-emptying efficiency | 🔶 |

## 4 — 📦 Packaging  · source: `bd_packaging_v2`, `SKU_BOM`, `ref_skus`
| # | Tracker | Readiness |
|---|---------|-----------|
| 48 | Units packaged this month by format (keg/bottle/can/cuv) | 🔶 |
| 49 | HL packaged this month | 🔶 |
| 50 | Packaging runs count · days since last | 🔶 |
| 51 | Top SKUs packaged | 🔶 |
| 52 | Format mix % (keg/bottle/can) | 🔶 |
| 53 | Parallel-run / white-label volume | 🔶 |
| 54 | Packaging yield / fill-loss % | 🔶 |
| 55 | Fill efficiency (actual units vs theoretical) | 🔶 |
| 56 | Contract packaging volume per client | 🔶 |
| 58 | Packaging material consumption / month | ⛔ (packaging-consumption pipeline gap) |
| 59 | Avg throughput per packaging run | 🔶 |
| 60 | Avg losses per loss-category | 🔶 |
| 61 | Avg losses per SKU | 🔶 |
| 62 | O₂/CO₂ pickup per SKU·format·month | 🔶/⛔ (capture TBC) |
| 63 | Volume per SKU / month | 🔶 |
| 64 | 🌟 Suggested packaging events | 🔶🔶 flagship engine |
| 65 | Packaging deviations (planned vs actual) | 🔶 |
| 66 | Packaging cost per unit (→COGS) | 🔶 |
| 67 | Underfill / overfill loss | 🔶/⛔ |
| 68 | Label / cap waste % | ⛔ (depends on #58) |
| 69 | Finished-goods added to inventory / month | 🔶 |
| 70 | O₂ pickup at fill | ⛔/🔶 (capture TBC) |
| 71 | Seam / torque QC pass rate | ⛔ |
*(#57 DK-label/sticker routing — DROPPED.)*

## 5 — 🏷️ Finished Goods / Stock  · source: `StockTake`, `SKU_BOM`, sales
| # | Tracker | Readiness |
|---|---------|-----------|
| 72 | FG units in stock by SKU/format | 🔶 |
| 73 | FG inventory value (CHF at cost) | 🔶 |
| 74 | Days/weeks of cover per SKU | 🔶 |
| 75 | Stockouts / below reorder threshold | ⛔ (no thresholds) |
| 76 | FG produced vs sold (net stock Δ) | 🔶 |
| 77 | Aging FG / best-before risk | 🔶 (needs pack-date on stock) |
| 78 | Stock turnover rate | 🔶 |
| 79 | Warehouse / cage fill | 🔶 |
| 80 | Slow-mover / dead-stock flag | 🔶 |
| 81 | FG by location (cold store vs warehouse) | 🔶 |
| 82 | Consignment / keg-fleet out in market | ⛔ (no keg tracking) |
| 83 | Return / breakage rate | ⛔ |
| 84 | Value tied up per beer | 🔶 |
| 85 | 🚩 FG stock-variation (physical vs theoretical/book) | 🔶 (FG analogue of RM drift) |

## 6 — 💷 Sales / Commercial  · source: `sales-cogs-data`, `ref_beer_types`, `ExportCustomers`
| # | Tracker | Readiness |
|---|---------|-----------|
| 86 | Revenue this month (HT) | 🔶 |
| 87 | Units sold by SKU/format | 🔶 |
| 88 | HL sold this month | 🔶 |
| 89 | Sales velocity per SKU (units/week) — feeds #23/#64 | 🔶 |
| 90 | Sales YoY pace | 🔶 |
| 91 | Revenue by beer family (core/special/contract) | 🔶 |
| 92 | Top customers by revenue | 🔶 |
| 93 | Top SKUs by volume/revenue | 🔶 |
| 94 | Sales by channel (B2B/eshop/taproom) | 🔶 (if channel tagged) |
| 95 | Swiss vs export split (beer-tax base) | 🔶 |
| 96 | Contract vs own-brand revenue | 🔶 |
| 97 | Avg order value · new vs returning | 🔶 |
| 98 | Gross margin per SKU | 🔶 |
| 99 | Revenue / HL trend | 🔶 |
| 100 | Discount / rebate rate | 🔶 |
| 101 | Days-sales-outstanding (unpaid invoices) | ⛔ (needs AR data) |
| 102 | Seasonal demand curve per beer | 🔶 |
| 103 | Lost-sales (stockout × demand) | 🔶 |
| 104 | Forecast vs actual sales | 🔶 (needs forecast basis) |
| 105 | Customer churn | 🔶 |

## 7 — 🌾 Raw Materials / Procurement  · source: `inv_deliveries`, `RM_Stock_Dynamic`, `ref_mi`
| # | Tracker | Readiness |
|---|---------|-----------|
| 106 | RM stock value (CHF) now | 🔶 |
| 107 | RM stock by category | 🔶 |
| 108 | RM negative-stock alerts | ✅ (RQ) |
| 109 | RM stale items >180d | ✅ (RQ) |
| 110 | Days of cover per RM | 🔶 |
| 111 | Reorder alerts (below threshold) | ⛔ (no thresholds) |
| 112 | Deliveries this month (count + CHF) | ✅ |
| 113 | Pending deliveries (truck in, invoice not) | ✅ |
| 114 | Spend by GL / category this month | ✅ |
| 115 | Top suppliers by spend | 🔶 |
| 116 | Weighted-avg cost trend per MI | 🔶 |
| 117 | Price anomalies / increases per ingredient | 🔶 |
| 118 | Consumption per MI / month | 🔶 |
| 119 | RM drift (dynamic vs physical take) | ✅ (RQ) |
| 120 | Caution / deposit balance per supplier | 🔶 |
| 121 | Import VAT / freight cost trend | 🔶 |
| 122 | Ingredient cost as % of COGS | 🔶 |
| 123 | Supplier lead-time | 🔶 |
| 124 | On-time delivery rate | 🔶 |
| 125 | Open POs / expected arrivals | ⛔ (no PO system) |
| 126 | Price-vs-budget variance | ⛔ (needs budget) |
| 127 | Single-source-risk flag | 🔶 |
| 128 | Spend YoY | 🔶 |
| 129 | Malt vs hops cost split | 🔶 |
| 130 | FX (EUR/CHF) exposure | 🔶 (current) / ⛔ (open orders) |
| 131 | 🚩 Overpriced-purchase flag (bad-purchasing signal) | 🔶 (price-anomaly gate exists) |

## 8 — 🚚 Logistics / Fulfilment  · mostly future (Shopify/Swiss Post not yet ingested)
| # | Tracker | Readiness |
|---|---------|-----------|
| 132 | Inbound deliveries received this month | ✅ |
| 133 | Warehouse / cage capacity & fill | 🔶 |
| 134 | Orders to fulfil / shipped | ⛔ future |
| 135 | Outbound delivery notes issued | ⛔ future |
| 136 | On-time shipment rate | ⛔ future |
| 137 | Shipping cost per order / HL | ⛔ future |
| 138 | Order backlog / pending shipments | ⛔ future |
| 139 | Returns / breakage in transit | ⛔ future |
| 140 | Keg fleet out vs returned | ⛔ future |
| 141 | Pick / pack throughput | ⛔ future |
| 142 | Avg delivery lead-time to customer | ⛔ future |
| 143 | Carbon / transport footprint | ⛔ future |
| 144 | Delivery density by region | ⛔ future |
| 145 | Cold-chain compliance | ⛔ future |
| 146 | Packaging-for-shipping cost | ⛔ future |

## 9 — 🔬 QA / QC  · source: salle-de-contrôle gates, `bd_*` (°Plato windows), QA auto-flag, in-tank reads
| # | Tracker | Readiness |
|---|---------|-----------|
| 147 | Batches pending QA validation (gate) | 🔶 |
| 148 | QA pass / fail rate per batch | 🔶 |
| 149 | QA outliers flagged this month | 🔶 |
| 150 | Out-of-spec batches (OG/FG/pH window) | 🔶 |
| 151 | ABV accuracy vs target | 🔶 |
| 152 | Final pH deviations | 🔶 |
| 153 | Dissolved O₂ / CO₂ spec compliance | 🔶 |
| 154 | Batch release cycle time | 🔶 |
| 155 | Recurring quality issues per beer | 🔶 |
| 156 | First-pass quality rate | 🔶 |
| 157 | Microbiological test pass rate | ⛔ |
| 158 | Sensory / tasting-panel scores | ⛔ |
| 159 | Shelf-life / stability test status | ⛔ |
| 160 | Lab tests outstanding / turnaround | ⛔/🔶 |
| 161 | Contamination / spoilage incidents | ⛔ |
| 162 | Complaint rate per batch | ⛔ |
| 163 | Color / IBU adherence | 🔶 (if measured) |
| 164 | Carbonation spec compliance | 🔶 |
| 165 | Calibration-due (instruments) | ⛔ |
| 166 | CIP verification pass | ⛔ |
| 167 | Allergen / label compliance | ⛔ |
| 168 | Traceability completeness per batch | 🔶 |

## 10 — 💰 COGS / Cost / Finance (COP)  · source: cogs/cop pipeline, month-closure, beer-tax
| # | Tracker | Readiness |
|---|---------|-----------|
| 169 | COGS this month (total) | ✅ |
| 170 | COGS per HL | ✅ |
| 171 | COGS per unit by SKU | ✅ |
| 172 | Brewing cost CHF/HL | ✅ |
| 173 | COP total + breakdown (5 sections) | ✅ |
| 174 | Gross margin % overall + per beer | 🔶 |
| 175 | Full cost breakdown per beer/SKU | ✅ |
| 176 | Beer-tax HL / liability this month | ✅ |
| 177 | Beer-tax by category | ✅ |
| 178 | Indirect cost categorization | ✅ |
| 179 | Maintenance OPEX (excl. COP) | ✅ |
| 180 | R&D / QA spend | ✅ |
| 181 | WIP value (tank balances) | 🔶 |
| 182 | Total inventory valuation (RM+FG+WIP) | ✅ |
| 183 | Cost variance vs prior month | 🔶 |
| 184 | Cost-per-HL trend | 🔶 |
| 185 | Break-even volume | 🔶 |
| 186 | Contribution margin per SKU | 🔶 |
| 187 | COGS as % of revenue | 🔶 |
| 188 | Price-realisation vs cost-inflation | 🔶 |
| 189 | Cash tied in inventory | 🔶 |
| 190 | Cost-of-quality (waste + rework) | 🔶 |
| 191 | Budget vs actual P&L lines | ⛔ (needs budget) |

## 11 — ⚡ Utilities / Energy / Sustainability  · source: `energydata`, SIE/SIL bills, predictive model
| # | Tracker | Readiness |
|---|---------|-----------|
| 192 | Electricity consumption (kWh) this month | 🔶 |
| 193 | Peak demand (kW) | ✅ |
| 194 | Reactive power (kVArh) / power factor | ✅ |
| 195 | Electricity cost this month | ✅ |
| 196 | Water consumption + cost | 🔶 |
| 197 | Gas consumption + cost | 🔶 |
| 198 | Energy cost per HL produced | 🔶 |
| 199 | Water-to-beer ratio | 🔶 |
| 200 | kWh per HL trend (energy intensity) | 🔶 |
| 201 | Predictive vs actual utilities (model accuracy) | 🔶 |
| 202 | Reactive-penalty risk flag | 🔶 |
| 203 | Purchased CO₂ usage + cost | ✅ |
| 204 | VOC tax exposure | 🔶 |
| 205 | CO₂ footprint per HL | 🔶/⛔ |
| 206 | Spent-grain / trub byproduct volume (+ revenue) | ⛔ |
| 207 | Wastewater load | ⛔ |
| 208 | Renewable-energy % | ⛔ |
| 209 | Heat recovery | ⛔ |
| 210 | Peak-shaving opportunity | 🔶 |
| 211 | Utility cost as % of COGS | 🔶 |
| 212 | Seasonal energy curve | 🔶 |

## 12 — 🗂️ Operations / System Health & Data Quality (admin)  · source: `doc_review_queue`, `ingest_runs`, `doc_uploads`, quarantine
| # | Tracker | Readiness |
|---|---------|-----------|
| 213 | Open review-queue items by type | ✅ |
| 214 | Review-queue aging (oldest open) | ✅ |
| 215 | Last ingest status / age | ✅ |
| 216 | Documents awaiting triage | ✅ |
| 217 | Invoices needing line-items / unmatched | ✅ |
| 218 | Ingest success rate / failures this week | ✅ |
| 219 | Orphan deliveries (DN/invoice) | ✅ |
| 220 | Parser coverage / no-parser rate | 🔶 |
| 221 | Quarantined values count | 🔶 |
| 222 | Validation-rule failures | 🔶 |
| 223 | Pending deliveries aging | ✅ |
| 224 | Data freshness (last submission per source) | 🔶 |
| 225 | Documents processed this month | ✅ |
| 226 | Supplier / MI resolution failures (null FK) | 🔶 |
| 227 | LLM-fallback usage rate | 🔶 |
| 228 | Auto-write vs manual-review ratio | 🔶 |
| 229 | Avg triage time per operator | 🔶 |
| 230 | Classifier accuracy | 🔶 |
| 231 | Duplicate-detection hits | 🔶 |
| 232 | System uptime | ⛔/🔶 |
| 233 | Backup status | 🔶 |
| 234 | Form-submission compliance per site | 🔶 |

## 13 — 🔧 Equipment / Maintenance & People / Safety  · mostly GAP (no uptime/labor/safety logging)
| # | Tracker | Readiness |
|---|---------|-----------|
| 235 | Maintenance OPEX trend | ✅ |
| 236 | Equipment / vessel utilization | 🔶 |
| 237 | Equipment uptime / downtime | ⛔ |
| 238 | Preventive maintenance due / overdue | ⛔ |
| 239 | Unplanned stops / MTBF | ⛔ |
| 240 | Spare-parts inventory | ⛔ |
| 241 | Labor hours / cost per HL | ⛔ |
| 242 | Productivity (HL per FTE) | ⛔ |
| 243 | Active users on system / logins | ✅ |
| 244 | Training / certification status | ⛔ |
| 245 | Safety incidents / days-since-last | ⛔ |
| 246 | Overtime rate | ⛔ |
| 247 | Shift coverage | ⛔ |
| 248 | CIP / cleaning cycles | ⛔ |
| 249 | Instrument calibration log | ⛔ |
| 250 | Energy per equipment | ⛔ |
| 251 | Line changeover time | ⛔ |

---

## 14 — 🎯 Control-Loop / Cross-Cutting KPIs (expert-added 2026-06-06)
The brewing-ops canon the original 250 under-developed. These are *control loops*, not levels. Several are hero metrics. Where a metric was scattered across earlier trackers, the cascade/rollup is the point — it must roll up to ONE reconciled number.

| # | Tracker | Source / what it needs | Readiness |
|---|---------|------------------------|-----------|
| 252 | 🌟 **Total beer-loss / extract cascade** (brewhouse→ferment→rack→package, reconciled waterfall + total %) | HL deltas across `bd_brewing_gravity_v2`→`bd_racking_v2`→`bd_packaging_v2`; full needs 254/255 | 🔶 partial now / ⛔ full |
| 253 | **Extract efficiency vs *lab* extract** (not self-set recipe yield) | needs lab/theoretical extract per recipe | ⛔ |
| 254 | **Dry-hop absorption loss** | new fermentation form field | ⛔ |
| 255 | **FV / trub loss** (knockout→FV) | new form field | ⛔ |
| 256 | **Giveaway / overfill** (% and CHF) | fill-volume actuals at packaging | 🔶/⛔ |
| 257 | 🌟 **Packaging-line OEE** (availability × performance × quality) | needs downtime/changeover capture; perf+quality partial | ⛔ |
| 258 | **Brewhouse OEE / utilization** | brewhouse run-time vs available | 🔶/⛔ |
| 259 | **Changeover / CIP time as % of available** | time capture | ⛔ |
| 260 | **MTBF / MTTR** (packaging line) | breakdown log | ⛔ |
| 261 | 🌟 **Plan attainment / schedule adherence %** | needs a production-schedule artifact | ⛔ |
| 262 | **Forecast accuracy** (sales) | needs a forecast artifact (also unblocks #23/#64) | ⛔ |
| 263 | **OTIF to customer** (on-time-in-full) | order/ship data | ⛔ future |
| 264 | 🌟 **Right-First-Time %** (rolled up all stages, not just final QA) | stage RFT rollup; #156 partial | 🔶 partial / ⛔ full |
| 265 | **Complaint PPM** (market quality, not per-batch) | complaint log | ⛔ |
| 266 | **Safety — LTIFR / days-since-last-incident** | incident log (cheap field) | ⛔ |
| 267 | **Inventory days-of-supply** (RM + FG) | stock ÷ consumption/sales rate | 🔶 |
| 268 | **Cash-conversion cycle** (DIO + DSO − DPO) | needs AR/AP days | 🔶/⛔ |
| 269 | **Mass / energy / water balance** (plant-level) | water-ratio #199 + kWh/HL #200 + mass = #252 | 🔶 |

## Data-gap backlog (the ⛔ items, grouped by what capture they need)
- **New fermentation/QA form fields:** yeast generation # + repitch + harvest yield (26/27/28/32/33), diacetyl rest (25), turbidity (36), tank temp log (24), micro/sensory/shelf-life/contamination/complaint/calibration/CIP-verify/allergen (157–162, 165–167).
- **Packaging-consumption pipeline fix:** #58, #68 (and unblocks the paused RM retro).
- **O₂/CO₂ capture at racking & fill steps:** 44, 45, 62, 70.
- **Thresholds / reference values:** reorder points (75, 111), recipe targets garde_days/yield (19, 43), budget (126, 191).
- **External integrations:** eshop/Swiss Post fulfilment (134–146), AR/payments (101), keg-fleet tracking (82, 140), byproduct/wastewater/renewable/heat (206–209), labor/HR/safety/maintenance-logging (237–251 most).

## Proposed Phase-2 seed plan (post-audit)
- **v1 seed = the ~65 backbone (✅ + cheap 🔶)** confirmed with the operator, **+ #252 (partial loss-cascade tile, buildable today from HL deltas) + #267 (days-of-supply)**. Volume triad (#1/#3/#9/#48) stays in the dashboard but is **demoted from the hero row**. Hero cards = H1–H6 (build partial where full capture isn't ready).
- **v1b = flagship engines** #23 / #64 (shared sales×inventory×capacity engine; after the tank-sim free-capacity port).
- **Roadmap = the ⛔ backlog** (incl. Category 14's control-loop canon), each activated as its data-capture lands via the `data_ready` flag.

### Instrument-FIRST (expert priority, ahead of/alongside v1 — new capture justified)
1. **FIX the packaging-consumption pipeline gap (#58/#68)** — a known paused defect (RM-retro arc) silently corrupting RM stock + packaging COGS already on display. A fix, not a tracker. **Front-loaded.**
2. **Capture FV loss (#255) + dry-hop absorption (#254)** — a few form fields that turn the loss cascade from "we're losing beer" into "here's where."
3. **Stand up a production schedule + crude sales forecast** — unlocks #261 plan-attainment, #262 forecast-accuracy, #263 OTIF, and credible #23/#64.
4. Cheap governance add: **days-since-last-safety-incident** field (#266).
