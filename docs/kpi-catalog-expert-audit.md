# External Expert Audit — La Nébuleuse KPI Tracker Catalog

**Reviewer:** Group Production Director / VP Brewing Operations (25 yrs, international brewing conglomerate scale — AB InBev / Heineken / Carlsberg lineage), now advising craft-scale operations.
**Subject:** `docs/kpi-catalog.md` — 250-tracker catalog, v1 working spec.
**Date:** 2026-06-06.
**Tone:** Boardroom. Direct. No flattery.

---

## 0. One-paragraph verdict

This is a **serious, unusually well-architected catalog with one structural blind spot that disqualifies it — as written — from being called a production KPI *program*.** The engineering discipline is excellent: every tracker wraps an existing canonical metric instead of re-deriving facts, readiness is honestly tiered, and the gaps are cataloged rather than fabricated. That is better governance than most breweries 10× this size run. **But it is a catalog of *levels and counts*, not a catalog of *control loops*.** The single most important brewing-operations KPI — the **reconciled extract/beer-loss cascade from brewhouse to FG** — does not exist as one number. It is scattered across four disconnected per-stage "loss %" trackers (#40, #54, #67, etc.) that never reconcile to each other or to a theoretical extract baseline. A brewery that cannot state "we lost X% of the extract we paid for, and here is where" is flying blind on the one thing that most directly converts into cash. Fix that, add OEE properly, and this becomes a genuine program. Leave it as-is and it's a high-quality instrument panel with no fuel gauge.

---

## 1. Verdict by category — strong vs naive

### Strong (keep, this is real)
- **§10 COGS/Cost/Finance** — the spine of the whole thing. Mostly ✅, wraps a fiscal-grade pipeline, beer-tax by category is properly modelled. This is the category that earns the catalog its credibility. Most craft breweries cannot produce COGS/HL by SKU at all; you have it live.
- **§12 Ops/System Health** — disproportionately ✅ and genuinely valuable *as a data-trust layer*. Ingest success rate, RQ aging, null-FK resolution failures, classifier accuracy. This is the meta-KPI set that tells you whether the *other* 230 numbers can be believed. Most KPI programs omit this and then quietly rot. Keeping it first-class is mature.
- **§7 Procurement** — price-anomaly flag (#131), WAC trend (#116), single-source-risk (#127). The bones of a real purchasing-control function. Good.
- **§11 Utilities** — peak kW, power factor, reactive penalty risk are ✅ and these are the items that actually move the Swiss utility bill. The predictive-vs-actual accuracy tracker (#201) is a sign someone is thinking about *model trust*, not just levels.

### Naive / under-thought
- **§1 Production** — looks complete (lots of ✅) but it's mostly **vanity volume counting**. "HL brewed," "brew count," "avg HL/brew," "brews this week" — these are activity, not performance. The *only* performance metric in the whole section is #6 (brewhouse yield/efficiency), and it's a single bald "%" with no reconciliation to a theoretical/lab extract baseline. A brewhouse efficiency number with no denominator definition is uncomparable across recipes and unauditable. **This is the section that looks healthiest and is actually weakest.**
- **§2 Fermentation/Tanks** — heavy on occupancy/utilization (good) but the **yeast-management economics are entirely ⛔** (#26–#33 all gap). For a brewery doing core+collab+contract, yeast generation discipline is a direct quality *and* cost lever. Catalogued but parked — acceptable for v1, but understand you're deferring a real cost line, not a nice-to-have.
- **§4 Packaging** — this is where the OEE failure is most acute. You have fragments — fill efficiency (#55), throughput (#59), losses (#60/#61), deviations (#65) — but **no OEE, no changeover/CIP time, no MTBF/MTTR, no availability**. You're measuring the easy 1/3 of OEE (a partial quality + a partial performance proxy) and ignoring availability entirely. On a craft packaging line, availability (changeovers, micro-stops, CIP) is usually the *dominant* OEE loss. You're not measuring the biggest bucket.
- **§5 FG/Stock** — solid on levels, but **reorder thresholds and stockout detection are ⛔ "no thresholds."** A finished-goods system that can't tell you what's about to run out is a ledger, not an inventory control system. #75 and #82 (keg fleet) being gaps means you cannot see lost sales or float capital in the keg park — both real money.
- **§6 Sales** — competent but **forecast accuracy (#104) and lost-sales (#103) are flagged 🔶 "needs forecast basis," i.e. there is no forecast.** You cannot have OTIF, plan attainment, or demand-driven brewing (#23) without a forecast. This isn't a tracker gap, it's a *missing planning artifact* that several flagship trackers silently depend on.
- **§13 Equipment/People/Safety** — almost entirely ⛔, and honestly labelled so. **Safety (LTIFR/days-since-incident) being a gap is the one I'd push back on hardest** — see §2. Labour-hours/HL and HL/FTE being absent means you have **no productivity denominator**, which is the second-most-glaring omission after the loss cascade.

---

## 2. What's MISSING — the industry-standard KPIs absent or under-developed

Ranked by how dangerous the blind spot is, not by ease.

### 2.1 ⭐ THE BEER-LOSS / EXTRACT CASCADE (critical — this is *the* brewing KPI)
**Status in catalog: fragmented and unreconciled. This is the headline failure.**

You have per-stage loss numbers scattered:
- #40 racking loss % (brewed HL → racked HL)
- #54 packaging yield / fill-loss %
- #67 underfill/overfill
- #47 tank-emptying efficiency
- #6 brewhouse yield (but as extract, not volume)

These never roll up. A production director needs **one reconciled cascade**, expressed two ways:

| Stage | Loss metric | Catalog today |
|---|---|---|
| Brewhouse | Extract efficiency vs **lab/theoretical** extract (not just "yield %") | #6 partial, no theoretical baseline |
| Brewhouse→FV | Wort/hot+cold break + trub loss (kettle to FV volume) | **absent** |
| Fermentation | Fermentation/maturation loss (CO₂ blow-off, yeast crop, tank bottoms) | **absent** |
| Dry-hop | **Dry-hop absorption loss** (1–2 L/kg hops — material on a hoppy range) | **absent** |
| Filtration/transfer | Filter/centrifuge/transfer loss | partial via #47 |
| Racking | Racking loss | #40 |
| Packaging | Fill loss + giveaway (overfill) | #54/#67 |
| **TOTAL** | **Total beer loss % (brewed → saleable HL), reconciled** | **ABSENT — does not exist as a single number** |

**Why it matters:** every hL of beer you brewed and *cannot sell* carries full brewing COGS — malt, hops, energy, labour, water, tank time — and zero revenue. At ~thousands of HL/yr, each 1% of total loss is real five-figure CHF. Worse: without the *cascade*, you cannot tell **where** the loss is, so you can't fix it. A scattered set of per-stage % that don't sum to a controlled total is the single most common — and most expensive — measurement failure in craft brewing. **This must be one hero metric with a stage-by-stage waterfall underneath it.**

Two specific sub-misses inside this:
- **Extract efficiency vs lab/theoretical**, not "yield %." Yield % against recipe target tells you if you hit *your own* number; extract efficiency vs lab extract of the malt tells you if your *process* is good. The former can be gamed by sandbagging the recipe; the latter cannot. You need #6 redefined with a theoretical-extract denominator (requires malt lab extract figures — a procurement/QA data field, see §5 prioritization).
- **Giveaway / overfill as % AND CHF.** #67 exists as "underfill/overfill loss" 🔶/⛔ but is framed as a *loss* (quality) not as **giveaway** — the systematic over-target fill that gives beer away for free. On a fill line, 1–2% giveaway is normal, >3% is money on the floor. It deserves to be expressed in **CHF/month**, not just %.

### 2.2 OEE — real, not fragments (critical for packaging, useful for brewhouse)
**Status: fragments only. No composite OEE anywhere.**

OEE = Availability × Performance × Quality. The catalog has scraps of Performance (#55, #59) and Quality (#65, #156) but **zero Availability** — no run-time vs planned-time, no changeover time (#251 is ⛔), no CIP-as-%-of-available-time, no micro-stop logging, no MTBF/MTTR (#239 ⛔).

- **Packaging-line OEE** — slot in §4 as a new composite. Even a *manual* OEE (operator logs planned run window, downtime, good vs total units) beats nothing. **Availability is almost certainly your dominant loss bucket on a craft line and you're not measuring it.**
- **Brewhouse OEE / brews-per-week vs capacity** — §1. You count brews (#3, #9) but never against *theoretical brewhouse capacity*. "Brews this week = 4" is meaningless without "out of a possible 7." Add **brewhouse utilization %**.
- **Changeover / CIP time as % of available time** — §4 and §2. This is where craft lines bleed. Currently ⛔ (#251, #248). Even rough operator-logged changeover minutes per run would be transformative.
- **MTBF / MTTR** — §13 (#239 ⛔). For a small line, full reliability engineering is overkill, but a simple **unplanned-stop count + total downtime minutes/month** is not, and it feeds availability.

### 2.3 Schedule adherence / plan attainment (missing — high value, low cost)
There is **no "did we brew/package what we planned to, when we planned to"** metric anywhere. Plan attainment (actual vs scheduled brews/packaging runs) is one of the cheapest, highest-leverage production KPIs — it exposes firefighting, sequence churn, and capacity over-promising. **Requires a production schedule artifact** (which you also need for #23/#64 to be credible). Slot in §1/§4 as **"Plan attainment % (scheduled vs executed)."**

### 2.4 OTIF to customer + forecast accuracy (missing — gated by absent forecast)
- **OTIF (On-Time-In-Full)** to customer — §8, currently all ⛔ "future." Fair while fulfilment isn't ingested. But flag it as a *named target* so it lands the day Shopify/Swiss Post arrive.
- **Forecast accuracy (MAPE/bias)** — §6 #104 is 🔶 "needs forecast basis." **The forecast basis is the missing artifact, not the tracker.** Without it, #23 (suggested beers to brew) and #64 (suggested packaging) are extrapolating recent sales velocity with no demand signal — fine as a heuristic, dangerous if anyone treats it as a plan. Be honest in the UI that these are velocity-extrapolations, not forecasts.

### 2.5 Right-First-Time / Quality-at-source (partially present, mislabelled)
#156 "first-pass quality rate" exists 🔶 — good, that's RFT for QA release. But **RFT should be measured at *every* stage, not just final QA**: brews that hit OG first time, ferments that hit FG/attenuation without intervention, packaging runs with zero rework. The catalog has the *ingredients* (#7, #22, #65, #150) but no **stage-RFT rollup**. Cheap to compute from data you already flag. Slot a **"Right-First-Time %"** in §9 that aggregates stage pass-flags.

### 2.6 Complaint PPM / market quality (missing — ⛔ #162)
Complaint rate per *batch* (#162 ⛔) is the wrong denominator. Industry standard is **complaints per million units (PPM)** or per hL sold — that's the market-quality lens. Even at craft scale, *zero* complaint capture is a gap; one structured field on a returns/complaint form would seed it. Slot in §9.

### 2.7 Mass / energy / water balance + sustainability benchmarks (under-developed)
- **Water-to-beer ratio (#199)** exists 🔶 — *this is one of your best latent metrics*, see hero list. Make sure the denominator is **hL water in / hL beer packaged**, and benchmark it (§4 below).
- **kWh/hL energy intensity (#200)** exists 🔶 — good, keep, benchmark it.
- **kg CO₂e/hL (#205)** is 🔶/⛔ — increasingly a commercial/regulatory requirement (Swiss + EU buyers ask). Not v1, but real.
- **Spent grain / trub byproduct (+ revenue) (#206 ⛔)** — spent grain is a *revenue* line (farmers) and a diversion-from-waste metric. Worth capturing as a simple monthly tonnage + CHF.
- **Wastewater load (#207 ⛔)** — Swiss cantonal sewage charges scale with COD/load; relevant to utility cost, not just sustainability.

### 2.8 Working-capital / cash metrics (under-developed)
- **Inventory days of supply (RM + FG)** — you have per-item cover (#74, #110) but no **aggregate days-of-supply** for the business. That's the working-capital headline.
- **Cash conversion cycle (DIO + DSO − DPO)** — you have cash-tied-in-inventory (#189), DSO (#101 ⛔, needs AR). **CCC is the CFO/board working-capital KPI.** Gated on AR data (#101), so park it, but name it.
- **Days Payable Outstanding** — entirely absent. You ingest invoices; payment timing is a real cash lever. Slot in §7/§10.

### 2.9 Safety — LTIFR (missing — and I'd argue this is non-negotiable)
§13 #245 is ⛔ "safety incidents / days-since-last." **At a brewery — CO₂, caustic CIP, pressurised vessels, forklifts, wet floors — a board with no safety KPI is a board with an unmanaged liability.** LTIFR (Lost-Time Injury Frequency Rate) or even a dead-simple **days-since-last-incident counter on the wall** costs nothing but a form field and a culture decision. This is the one ⛔ I would not let the operator defer on principle, even though it requires new capture. It's cheap and it's a duty-of-care signal.

### 2.10 Category-specific under-thinking
- **Dry-hop losses** — covered in 2.1; absent and material on a hoppy range.
- **Yeast-management economics** — §2 #26–#33 all ⛔. Yeast generations directly drive both quality drift and the cost of fresh-pitch purchases. Real money, parked.
- **Tank-schedule optimization** — you have occupancy (#13/#14) and idle days (#15) but no **tank-schedule conflict / bottleneck flag** — "you cannot brew X because no CCT frees up until day N." That's the operational decision tank data should drive. #23 gestures at it; make the *constraint* explicit.
- **Tax-class mix risk** — §10 has beer-tax by category (#177) ✅, but no **forward exposure / mix-shift flag**: a shift toward higher-tax-class or toward export (#95) materially changes liability. A *risk* lens on the tax mix, not just a level, would be director-grade.

---

## 3. Hero metrics — what goes on the wall

A production director at this brewery should be held to **6 numbers**. These are the ones I'd put on the board pack and review weekly. Everything else in the 250 is supporting telemetry.

| # | HERO METRIC | Why it's a hero (decision/behaviour it drives) | Maps to | Vanity trap it replaces |
|---|---|---|---|---|
| **H1** | **Total Beer Loss % (brewed → saleable), with stage waterfall** | The master efficiency number. Every % is full-COGS beer with zero revenue. The waterfall tells you *where* to act. Drives loss-reduction projects across all four stages. | **Currently MISSING as a rollup.** Built from #6, #40, #47, #54, #67 + two new captures (FV loss, dry-hop loss). **Build this first.** | Replaces "HL brewed" (#1) as the headline — volume is vanity, *saleable yield* is vital. |
| **H2** | **COGS per HL (and per SKU), trend** | The cash-per-unit truth. Already fiscal-grade. Drives pricing, mix, and the entire cost-reduction agenda. | #170, #171, #184 ✅ | — already vital, keep it. |
| **H3** | **Packaging-line OEE (Availability × Performance × Quality)** | The line is your throughput bottleneck and your second-biggest loss locus after brewing. OEE forces you to confront *availability* (changeovers/CIP/stops) you currently don't measure. Drives line-efficiency and changeover-reduction work. | **Fragments only** (#55/#59/#65). Needs a real composite + availability capture. | Replaces "units packaged" (#48) and "runs count" (#50) — those are activity. |
| **H4** | **Water-to-beer ratio (hL/hL)** + **kWh/hL** | Two numbers, one "resource efficiency" tile. Cheapest credible sustainability + cost KPI you have latent. Benchmarkable against industry. Drives utility-cost and (increasingly) commercial/ESG conversations. | #199, #200 🔶 — *promote these*. | Replaces raw "electricity kWh" (#192) — absolute consumption is vanity, *intensity* is vital. |
| **H5** | **Plan Attainment % (scheduled vs executed brews + packaging)** | The discipline metric. Exposes firefighting, capacity over-promising, sequence churn. A brewery that hits its schedule has its tanks, sales, and procurement in sync. | **MISSING.** Needs a schedule artifact (which #23/#64/forecast also need). | Replaces "brews this week" (#9) — count-without-target is meaningless. |
| **H6** | **Right-First-Time % (stage-aggregated quality-at-source)** | Quality you don't have to rework or dump. Ties QA gates to cost-of-quality. Drives process control upstream instead of inspection downstream. | #156 partial; needs stage rollup from #7/#22/#65/#150. | Replaces "QA pass/fail rate" (#148) — pass-after-rework hides the cost; RFT doesn't. |

**Honourable mention / "earn its way on":** **Inventory days-of-supply (RM+FG aggregate)** as a 7th once the working-capital conversation matures — it's the metric the board will ask about when cash gets tight. And **days-since-last-safety-incident** belongs on the *physical* wall regardless of whether it's a "hero" — it's a culture artifact, not an analytics one.

**Explicit vanity-metric call-outs** (telemetry, *never* hero): HL brewed (#1), brew count (#3), units packaged (#48), brews this week (#9), active users (#243), documents processed (#225). All useful operationally; none should be on the accountability wall. The catalog's instinct to count volume is the craft-brewery default and it must be resisted at director level — **you are accountable for yield, cost, and reliability, not for being busy.**

---

## 4. Benchmarks — what "good" looks like

Cited with honest uncertainty. Craft-scale numbers vary widely; treat these as the *bands a director would expect*, not precision targets. Your scale (low-thousands hL) sits worse than mega-brewery best-in-class on resource ratios — that's physics (less heat recovery, smaller batches, more changeovers), so benchmark against **craft peers**, not AB InBev.

| Metric | World-class (mega) | Realistic craft target | Notes / uncertainty |
|---|---|---|---|
| **Total beer loss (brewed→saleable)** | 4–6% | **8–12%** craft typical; <8% is good craft | Mega achieves ~4% with centrifuges/recovery you won't have. High uncertainty; depends heavily on dry-hop intensity. |
| **Brewhouse extract efficiency (vs lab extract)** | 90–92% | **78–88%** craft; >85% is good | Mash efficiency. Highly recipe/equipment dependent. The *denominator definition* matters more than the number. |
| **Water-to-beer ratio (hL/hL)** | 2.5–3.5 | **4–7** craft typical; <5 is good craft | You cited 3–4 as "industry" — that's *large-brewery*. Craft realistically 4–7; some run 8–10. Don't beat yourself up against 3.5. |
| **Energy intensity (kWh/hL, thermal+electric)** | 25–40 | **50–120** craft; high variance | Wildly scale-dependent. Track the *trend*, benchmark loosely. |
| **Packaging-line OEE** | 75–90% | **35–55%** craft small-line typical; >60% is strong craft | Craft lines live and die on availability (changeovers). Low OEE is normal; the point is to *see it* and trend it up. |
| **Giveaway / overfill** | <0.5% | **1–3%** craft; >3% = act | Direct CHF on the floor. Measure in CHF/month. |
| **Fermentation/maturation loss** | 1–2% | **2–4%** | Plus dry-hop absorption ~1–2 L per kg hops on top. |
| **CO₂ recovery** | High (recovered) | **0% (purchased) is normal craft** | #29 ⛔. You almost certainly buy CO₂; recovery is a capex story, not a v1 KPI. |
| **RFT / first-pass quality** | >98% | **>90%** craft good | — |
| **DSO (days sales outstanding)** | 30–45 | depends on B2B mix | Gated on AR data. |

**Caveat I want on record:** several of these bands are my professional estimate of craft-segment norms, not citations from a published dataset. Treat them as directional. The *discipline of having a target band at all* matters more than hitting my exact number. The brewery should refine these against its own first 6 months of clean data and against any craft-brewing benchmarking it can join (e.g. national craft-brewers' association benchmarking, where available).

---

## 5. Prioritization steer — do I agree with the v1 backbone?

### The proposed v1 (✅ + cheap 🔶 = production/sales/COGS/RM/ops-health backbone, ~40–60 trackers)
**Broadly yes — with one hard reshuffle.** The v1 backbone is a sound *foundation* and the engineering instinct (wrap what's already computed, ship fast, earn trust) is exactly right. The COGS, RM, and ops-health backbones especially should ship as-is. **My objection is not to what's *in* v1 — it's to what v1 *leads with*.**

As drafted, v1 leads with **volume counting** (the §1 ✅ block: HL brewed, brew count, brews this week). That trains the operator and the board to watch *activity*. The first thing on `mon-tableau.php` shapes the culture. **Lead with the loss/cost/reliability triad, not the volume triad.**

### Concrete reshuffle for v1:
1. **Keep** the COGS/Finance (#169–#180, #182), RM (#106–#119), and Ops-Health (#213–#225) ✅ backbones verbatim — they're the trust layer and the cash truth. No change.
2. **Demote** the pure-volume vanity trackers (#1, #3, #9, #48 as headlines) to a secondary "activity" strip, not the hero row.
3. **Promote** into v1, even at extra cost: the **partial loss cascade you *can* build today** from existing data — #6 (redefined with a stated denominator), #40, #47, #54 stitched into a **single "saleable yield / total loss" tile with a stage waterfall.** You don't need the new FV/dry-hop captures to ship a *first* version; ship it with the stages you have and label the missing stages explicitly. This is the most important behaviour-shaping thing you can do at launch.

### The 2–3 things to instrument FIRST even though they need NEW capture (flying blind is dangerous):

**FIRST — Close the packaging-consumption pipeline gap (#58/#68).**
This is already a known, paused defect (it blocks the RM retro per the data-gap backlog). It's not just a tracker — it's a **hole in COGS and RM accuracy**. Packaging material consumption not propagating means your RM stock and your packaging COGS are *both* silently wrong. Fix this before adding any new tracker, because it corrupts numbers you're already showing. **Highest priority, and it's a fix not a new form.**

**SECOND — Capture the two missing loss-cascade stages: FV loss and dry-hop absorption.**
A handful of form fields (kettle-out volume → FV volume; hops kg already known → modelled absorption; FV → racking volume). This is what turns the partial cascade (H1) into a *reconciled* one. Without it you can see *that* you're losing beer but not *where*, and "where" is the whole point. Cheap capture, enormous decision value. This is the one place I'd spend new-data-capture budget first on the *production* side.

**THIRD — Establish a production schedule + simple sales forecast artifact.**
Not a tracker — a *planning input*. It unlocks: Plan Attainment (H5), credible #23/#64 flagship engines, forecast accuracy (#104), and eventually OTIF. Right now #23/#64 are velocity-extrapolations dressed as recommendations; a forecast turns them into planning. Even a crude operator-maintained 8-week demand sheet beats nothing. Medium effort, unlocks the most downstream trackers per unit of work.

**Honourable fourth (cheap, principled):** a **days-since-last-safety-incident** field. One column, one form, zero analytics complexity, and it closes a genuine governance gap. I'd sneak it into v1 on principle.

### What I would NOT prioritize (resist the temptation):
- Full yeast-management telemetry (#26–#33) — real money but high capture cost; phase 3.
- O₂/CO₂ pickup capture everywhere (#44/#62/#70) — instrumentation-heavy; do it when you have the dissolved-gas meters wired, not before, and don't fabricate.
- The entire §8 logistics block — correctly parked behind Shopify/Swiss Post. Leave it ⛔ and named.
- Equipment uptime/MTBF telemetry (#237–#239) — start with manual unplanned-stop *counts* feeding OEE availability; full reliability logging is overkill at this scale.

---

## 6. Closing

You have built the *instrumentation* of a serious operation. What's missing is the **scorecard of a serious operation** — the half-dozen reconciled, accountable numbers that force action rather than describe activity. The gap is not data discipline (yours is excellent) — it's **selection and synthesis**: too many levels, not enough loops; too much volume, not enough yield; per-stage fragments where the business needs one reconciled cascade.

Do three things and this graduates from catalog to program:
1. **Build the total-beer-loss cascade (H1)** — even partial, even ugly, ship it.
2. **Stand up packaging OEE (H3)** with real availability capture.
3. **Lead the dashboard with the loss/cost/reliability hero row**, not the volume row.

Everything else in your 250 is good supporting telemetry. But a production director is paid to defend six numbers. Right now, four of those six don't exist yet. Build them.

— *VP Brewing Operations (advisory)*
