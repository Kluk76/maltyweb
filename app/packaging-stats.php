<?php
declare(strict_types=1);
/**
 * app/packaging-stats.php — Packaging KPI aggregations over bd_packaging_v2 (V2-ONLY).
 *
 * PKG-A1 data layer. Closes V2-ONLY de-wire item #3 for packaging.php:
 * this module reads ONLY bd_packaging_v2 — never bd_packaging (v1). There is no
 * UNION, no cross-source dedup. Every row in v2 is a distinct packaging event
 * by NK-upsert; the old "one row per beer|batch|date" dedup is intentionally
 * absent here (it would collapse multiple same-day same-batch format runs).
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * CANONICAL RULES (must be preserved in every caller and future revision)
 * ══════════════════════════════════════════════════════════════════════════════
 *
 *   1. STORED COLUMN ONLY — "HL packaged" = SUM(vendable_hl) from bd_packaging_v2.
 *      Do NOT recompute HL from format volumes, do NOT read v_bd_packaging_v2_vendable
 *      (that view still returns NULL for the 244 contract rows; the stored col is 0-NULL).
 *
 *   2. REUSE FILTER MANDATORY — WHERE reuses_packaging_id_fk IS NULL on every
 *      HL/output aggregate. A reused-cuv row produced 0 new liquid (mig 238).
 *
 *   3. FORMAT FAMILY = run_type ENUM('bot','can','can33','keg','cuv').
 *      NOT nebuleuse_format_suffix (v2 drops fmt_suffix).
 *
 *   4. DATE = event_date (DATE column, 0 NULL, = DATE(submitted_at)).
 *      Used for all year/month/quarter filters.
 *
 *   5. Neb vs Contract split — authoritative key (A1.1 corrected 2026-05-31):
 *      - Classification: p.recipe_id_fk → ref_recipes(id) → ref_recipes.classification
 *        ENUM('Neb','Contract'). 100% covered (2244/2244). NEVER use sku_id_fk IS NULL/NOT NULL
 *        for the split — the 244 sku_id_fk-NULL rows are 240 Contract + 4 Neb; using sku-NULL
 *        as a Contract proxy mis-labels those 4 Neb events (PM-verified 2026-05-31).
 *      - Exact SKU code (KPI 2 only): p.sku_id_fk → ref_skus(id) → ref_skus.sku_code.
 *        99.8% covered for Neb. The 4 NULL-sku Neb rows are surfaced under '(SKU manquant)'
 *        — not dropped (refuse-don't-NULL).
 *
 *   6. COLLATION — bd_packaging_v2 = utf8mb4_0900_ai_ci, ref_* = utf8mb4_unicode_ci.
 *      All joins here are integer FK joins (sku_id_fk/recipe_id) — safe without COLLATE.
 *      Any future string join must add explicit COLLATE utf8mb4_unicode_ci.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * PUBLIC API
 * ══════════════════════════════════════════════════════════════════════════════
 *
 *   pkg_neb_hl_by_month(PDO, int $year): array
 *     → list<{mo:int, hl:float, events:int}> — Neb HL per calendar month.
 *
 *   pkg_neb_hl_by_sku_month(PDO, int $year): array
 *     → list<{mo:int, sku_code:string, hl:float, events:int}> — Neb HL per SKU/month.
 *
 *   pkg_hl_by_format_month(PDO, int $year): array
 *     → list<{mo:int, run_type:string, hl:float, events:int}> — Neb HL per run_type/month.
 *       Grand totals per format summed client-side.
 *
 *   pkg_contract_hl_by_format_month(PDO, int $year): array
 *     → list<{mo:int, run_type:string, hl:float, events:int}> — Contract HL per format/month.
 *
 *   pkg_loss_by_month(PDO, int $year): array
 *     → list<{mo:int, loss_hl:float, events_with_loss:int}> — SUM(loss_kpi_hl) per month.
 *
 * All functions:
 *   - Accept a PDO connection (caller supplies from maltytask_pdo()).
 *   - Return plain arrays of associative rows. No HTML, no side effects.
 *   - Are memoized per ($year, function) within a single request.
 *   - Enforce the reuse filter and vendable_hl contract on every query.
 *
 * Dependencies: app/db.php (maltytask_pdo() — caller passes $pdo). Read-only.
 */

require_once __DIR__ . '/db.php';

/* ═══════════════════════════════════════════════════════════════════════════
   Internal helper: base WHERE clause + params shared by all Neb queries.
   ═══════════════════════════════════════════════════════════════════════════ */

/**
 * Build the common WHERE fragment and params array for Neb HL queries.
 *
 * Extracted to ensure every Neb function applies an identical filter.
 * The caller must supply: INNER JOIN ref_recipes r ON p.recipe_id_fk = r.id
 * The caller appends GROUP BY / ORDER BY.
 *
 * Classification is via recipe_id_fk → ref_recipes.classification (100% covered,
 * 2244/2244). The alias `r` refers to ref_recipes joined directly from p.recipe_id_fk.
 * Integer FK join — no COLLATE needed.
 *
 * @internal
 * @param int $year  Calendar year filter.
 * @return array{string, list<int>}  [whereClause, params]
 */
function _pkg_neb_where(int $year): array
{
    // recipe_id_fk → ref_recipes.classification is the sole Neb/Contract discriminator.
    // sku_id_fk is NOT used for the split (244 NULL-sku rows = 240 Contract + 4 Neb;
    // using sku-NULL as a Contract proxy would mis-label 4 real Neb events — A1.1 fix).
    $where = "YEAR(p.event_date) = ?
      AND p.reuses_packaging_id_fk IS NULL
      AND p.is_tombstoned = 0
      AND r.classification = 'Neb'";

    return [$where, [$year]];
}

/* ═══════════════════════════════════════════════════════════════════════════
   1. pkg_neb_hl_by_month — KPI 1
   ═══════════════════════════════════════════════════════════════════════════ */

/**
 * Total Nebuleuse HL packaged per calendar month for the given year.
 *
 * Example return row: ['mo' => 4, 'hl' => 1305.294, 'events' => 50]
 *
 * @param PDO $pdo   Active DB connection.
 * @param int $year  Calendar year (e.g. 2025).
 * @return list<array{mo:int, hl:float, events:int}>
 */
function pkg_neb_hl_by_month(PDO $pdo, int $year): array
{
    static $cache = [];
    if (isset($cache[$year])) {
        return $cache[$year];
    }

    [$where, $params] = _pkg_neb_where($year);

    $stmt = $pdo->prepare(
        "SELECT MONTH(p.event_date)          AS mo,
                ROUND(SUM(p.vendable_hl), 3) AS hl,
                COUNT(*)                     AS events
           FROM bd_packaging_v2 p
     INNER JOIN ref_recipes r ON p.recipe_id_fk = r.id
          WHERE {$where}
          GROUP BY MONTH(p.event_date)
          ORDER BY MONTH(p.event_date)"
    );
    $stmt->execute($params);

    $rows = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $rows[] = [
            'mo'     => (int)$row['mo'],
            'hl'     => (float)$row['hl'],
            'events' => (int)$row['events'],
        ];
    }

    $cache[$year] = $rows;
    return $rows;
}

/* ═══════════════════════════════════════════════════════════════════════════
   2. pkg_neb_hl_by_sku_month — KPI 2
   ═══════════════════════════════════════════════════════════════════════════ */

/**
 * Nebuleuse HL per SKU per calendar month.
 *
 * Classification: recipe_id_fk → ref_recipes.classification = 'Neb' (100% covered).
 * Exact SKU code: sku_id_fk → ref_skus.sku_code (99.8% covered for Neb).
 * The 4 Neb rows with NULL sku_id_fk are bucketed under '(SKU manquant)' — they are
 * surfaced in totals, not dropped (refuse-don't-NULL; 4 events / ~51.90 HL all-years).
 *
 * Returned sorted by month then hl DESC so the heaviest SKUs surface first.
 *
 * Example return row: ['mo' => 1, 'sku_code' => 'ZEPF', 'hl' => 257.553, 'events' => 3]
 *
 * @param PDO $pdo   Active DB connection.
 * @param int $year  Calendar year.
 * @return list<array{mo:int, sku_code:string, hl:float, events:int}>
 */
function pkg_neb_hl_by_sku_month(PDO $pdo, int $year): array
{
    static $cache = [];
    if (isset($cache[$year])) {
        return $cache[$year];
    }

    [$where, $params] = _pkg_neb_where($year);

    // Classification via recipe_id_fk (authoritative). SKU code via sku_id_fk (exact code,
    // Neb 99.8% coverage). LEFT JOIN so the 4 NULL-sku Neb events are not dropped —
    // they appear under the '(SKU manquant)' sentinel bucket.
    $stmt = $pdo->prepare(
        "SELECT MONTH(p.event_date)                          AS mo,
                COALESCE(s.sku_code, '(SKU manquant)')       AS sku_code,
                ROUND(SUM(p.vendable_hl), 3)                 AS hl,
                COUNT(*)                                     AS events
           FROM bd_packaging_v2 p
     INNER JOIN ref_recipes r ON p.recipe_id_fk = r.id
      LEFT JOIN ref_skus s    ON p.sku_id_fk = s.id
          WHERE {$where}
          GROUP BY MONTH(p.event_date), p.sku_id_fk, s.sku_code
          ORDER BY MONTH(p.event_date), hl DESC"
    );
    $stmt->execute($params);

    $rows = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $rows[] = [
            'mo'       => (int)$row['mo'],
            'sku_code' => (string)$row['sku_code'],
            'hl'       => (float)$row['hl'],
            'events'   => (int)$row['events'],
        ];
    }

    $cache[$year] = $rows;
    return $rows;
}

/* ═══════════════════════════════════════════════════════════════════════════
   3. pkg_hl_by_format_month — KPI 3
   ═══════════════════════════════════════════════════════════════════════════ */

/**
 * Nebuleuse HL per format (run_type) per calendar month.
 *
 * run_type values: 'bot' | 'can' | 'can33' | 'keg' | 'cuv'
 * These are the canonical format-family buckets for the operator's
 * bottle / can / keg / cuve KPI view.
 *
 * Example return row: ['mo' => 1, 'run_type' => 'keg', 'hl' => 456.121, 'events' => 10]
 *
 * @param PDO $pdo   Active DB connection.
 * @param int $year  Calendar year.
 * @return list<array{mo:int, run_type:string, hl:float, events:int}>
 */
function pkg_hl_by_format_month(PDO $pdo, int $year): array
{
    static $cache = [];
    if (isset($cache[$year])) {
        return $cache[$year];
    }

    [$where, $params] = _pkg_neb_where($year);

    $stmt = $pdo->prepare(
        "SELECT MONTH(p.event_date)          AS mo,
                p.run_type,
                ROUND(SUM(p.vendable_hl), 3) AS hl,
                COUNT(*)                     AS events
           FROM bd_packaging_v2 p
     INNER JOIN ref_recipes r ON p.recipe_id_fk = r.id
          WHERE {$where}
          GROUP BY MONTH(p.event_date), p.run_type
          ORDER BY MONTH(p.event_date), p.run_type"
    );
    $stmt->execute($params);

    $rows = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $rows[] = [
            'mo'       => (int)$row['mo'],
            'run_type' => (string)$row['run_type'],
            'hl'       => (float)$row['hl'],
            'events'   => (int)$row['events'],
        ];
    }

    $cache[$year] = $rows;
    return $rows;
}

/* ═══════════════════════════════════════════════════════════════════════════
   4. pkg_contract_hl_by_format_month — KPI 4
   ═══════════════════════════════════════════════════════════════════════════ */

/**
 * Contract-beer HL per format (run_type) per calendar month.
 *
 * Classification: recipe_id_fk → ref_recipes.classification = 'Contract' (authoritative,
 * 100% covered — A1.1 fix 2026-05-31). The old sku_id_fk IS NULL proxy was wrong: the 244
 * NULL-sku rows = 240 Contract + 4 Neb; using NULL-sku would mis-classify those 4 Neb events
 * as Contract. Always split via recipe_id_fk.
 *
 * Contract vendable_hl is populated via the run_type fallback (mig 238 / contract fix).
 *
 * Example return row: ['mo' => 3, 'run_type' => 'keg', 'hl' => 12.0, 'events' => 1]
 *
 * @param PDO $pdo   Active DB connection.
 * @param int $year  Calendar year.
 * @return list<array{mo:int, run_type:string, hl:float, events:int}>
 */
function pkg_contract_hl_by_format_month(PDO $pdo, int $year): array
{
    static $cache = [];
    if (isset($cache[$year])) {
        return $cache[$year];
    }

    // recipe_id_fk → ref_recipes.classification is the sole Neb/Contract discriminator.
    // Integer FK join — collation-safe, no COLLATE needed.
    $stmt = $pdo->prepare(
        "SELECT MONTH(p.event_date)          AS mo,
                p.run_type,
                ROUND(SUM(p.vendable_hl), 3) AS hl,
                COUNT(*)                     AS events
           FROM bd_packaging_v2 p
     INNER JOIN ref_recipes r ON p.recipe_id_fk = r.id
          WHERE YEAR(p.event_date) = ?
            AND p.reuses_packaging_id_fk IS NULL
            AND p.is_tombstoned = 0
            AND r.classification = 'Contract'
          GROUP BY MONTH(p.event_date), p.run_type
          ORDER BY MONTH(p.event_date), p.run_type"
    );
    $stmt->execute([$year]);

    $rows = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $rows[] = [
            'mo'       => (int)$row['mo'],
            'run_type' => (string)$row['run_type'],
            'hl'       => (float)$row['hl'],
            'events'   => (int)$row['events'],
        ];
    }

    $cache[$year] = $rows;
    return $rows;
}

/* ═══════════════════════════════════════════════════════════════════════════
   5. pkg_loss_by_month — supplementary loss KPI
   ═══════════════════════════════════════════════════════════════════════════ */

/**
 * Sum of loss_kpi_hl per month for Neb runs.
 *
 * loss_kpi_hl is the stored column covering all loss categories
 * (invendable + sans-capsule + half-filled + perte-liquide-fût + material).
 * Use for a loss-rate card alongside vendable_hl.
 *
 * Rows with NULL loss_kpi_hl are excluded from the sum and the events count.
 * Use events_with_loss to communicate data completeness to the operator.
 *
 * Example return row: ['mo' => 4, 'loss_hl' => 2.45, 'events_with_loss' => 38]
 *
 * @param PDO $pdo   Active DB connection.
 * @param int $year  Calendar year.
 * @return list<array{mo:int, loss_hl:float, events_with_loss:int}>
 */
function pkg_loss_by_month(PDO $pdo, int $year): array
{
    static $cache = [];
    if (isset($cache[$year])) {
        return $cache[$year];
    }

    [$where, $params] = _pkg_neb_where($year);

    $stmt = $pdo->prepare(
        "SELECT MONTH(p.event_date)               AS mo,
                ROUND(SUM(p.loss_kpi_hl), 3)      AS loss_hl,
                COUNT(p.loss_kpi_hl)              AS events_with_loss
           FROM bd_packaging_v2 p
     INNER JOIN ref_recipes r ON p.recipe_id_fk = r.id
          WHERE {$where}
            AND p.loss_kpi_hl IS NOT NULL
          GROUP BY MONTH(p.event_date)
          ORDER BY MONTH(p.event_date)"
    );
    $stmt->execute($params);

    $rows = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $rows[] = [
            'mo'               => (int)$row['mo'],
            'loss_hl'          => (float)$row['loss_hl'],
            'events_with_loss' => (int)$row['events_with_loss'],
        ];
    }

    $cache[$year] = $rows;
    return $rows;
}

/* ═══════════════════════════════════════════════════════════════════════════
   6. pkg_current_week_events — A3a: events in the ISO week of the latest event_date.
   ═══════════════════════════════════════════════════════════════════════════ */

/**
 * Returns packaging events for the ISO week that contains the most recent event_date
 * in bd_packaging_v2 (v2-only, reuse-filtered). Anchors to data max to avoid empty
 * results during off-season testing.
 *
 * Loss formula (operator-locked):
 *   losses_units = SUM of all loss_*_units + unsaleable_units
 *                  (loss_keg_liquid_l excluded — in litres, not units)
 *                  (qa_analyses_units + qa_library_units excluded — not losses)
 *   total_handled = prod_total_units + losses_units
 *   pct = losses_units / total_handled   (NULL-safe via NULLIF)
 *
 * In-filling O2/CO2 joined from bd_packaging_readings (packaging_v2_id link).
 * When no measures exist, avg_co2/avg_o2 are NULL, n_readings = 0.
 *
 * @param PDO $pdo
 * @return array{list: list<array>, week_label: string, week_start: string, week_end: string, total_events: int}
 */
function pkg_current_week_events(PDO $pdo): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    // Loss expression — columns verified against live schema 2026-05-31.
    // loss_keg_liquid_l is in LITRES — excluded.
    // qa_analyses_units + qa_library_units are NOT losses — excluded.
    $lossExpr = "
        COALESCE(p.loss_liquid_other_units,0) +
        COALESCE(p.loss_4pack_btl_units,0) +
        COALESCE(p.loss_4pack_can_units,0) +
        COALESCE(p.loss_wrap_btl_units,0) +
        COALESCE(p.loss_wrap_can_units,0) +
        COALESCE(p.loss_label_btl_units,0) +
        COALESCE(p.loss_crown_cork_units,0) +
        COALESCE(p.loss_can_lid_units,0) +
        COALESCE(p.loss_container_btl_units,0) +
        COALESCE(p.loss_container_can_units,0) +
        COALESCE(p.loss_keg_collar_units,0) +
        COALESCE(p.loss_uncapped_units,0) +
        COALESCE(p.loss_half_filled_units,0) +
        COALESCE(p.loss_untaxed_full_units,0) +
        COALESCE(p.loss_keg_save_units,0) +
        COALESCE(p.unsaleable_units,0)
    ";

    $stmt = $pdo->query("
        SELECT
            p.id,
            p.event_date,
            COALESCE(s.sku_code, '(SKU manquant)')  AS sku_code,
            p.run_type,
            p.prod_total_units,
            ROUND(p.vendable_hl, 3)                  AS vendable_hl,
            co.avg_co2                               AS avg_co2,
            co.avg_o2                                AS avg_o2,
            COALESCE(co.n_readings, 0)               AS n_co2o2_readings,
            ({$lossExpr})                            AS loss_units,
            ROUND(
                ({$lossExpr}) /
                NULLIF(COALESCE(p.prod_total_units,0) + ({$lossExpr}), 0)
            , 4)                                     AS loss_pct
        FROM bd_packaging_v2 p
        INNER JOIN ref_recipes r ON p.recipe_id_fk = r.id
        LEFT JOIN ref_skus s     ON p.sku_id_fk = s.id
        LEFT JOIN (
            SELECT packaging_v2_id,
                   COUNT(*)           AS n_readings,
                   ROUND(AVG(co2),3)  AS avg_co2,
                   ROUND(AVG(o2),2)   AS avg_o2
            FROM bd_packaging_readings
            WHERE packaging_v2_id IS NOT NULL
            GROUP BY packaging_v2_id
        ) co ON co.packaging_v2_id = p.id
        WHERE p.reuses_packaging_id_fk IS NULL
          AND p.is_tombstoned = 0
          AND YEARWEEK(p.event_date, 3) = (
              SELECT YEARWEEK(MAX(event_date), 3)
              FROM bd_packaging_v2
              WHERE reuses_packaging_id_fk IS NULL AND is_tombstoned = 0 AND event_date IS NOT NULL
          )
        ORDER BY p.event_date, p.id
    ");

    $runTypeToFamily = [
        'bot'   => 'Bouteille',
        'can'   => 'Canette',
        'can33' => 'Canette',
        'keg'   => 'Fût',
        'cuv'   => 'Cuve de service',
    ];

    $rows    = [];
    $minDate = null;

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $eventDate = $row['event_date'];
        if ($minDate === null || $eventDate < $minDate) {
            $minDate = $eventDate;
        }

        $rows[] = [
            'id'               => (int)$row['id'],
            'event_date'       => $eventDate,
            'sku_code'         => (string)$row['sku_code'],
            'run_type'         => (string)$row['run_type'],
            'display_family'   => $runTypeToFamily[$row['run_type']] ?? $row['run_type'],
            'prod_total_units' => isset($row['prod_total_units']) ? (int)$row['prod_total_units'] : null,
            'vendable_hl'      => $row['vendable_hl'] !== null ? (float)$row['vendable_hl'] : null,
            'avg_co2'          => $row['avg_co2'] !== null ? (float)$row['avg_co2'] : null,
            'avg_o2'           => $row['avg_o2'] !== null ? (float)$row['avg_o2'] : null,
            'n_co2o2_readings' => (int)$row['n_co2o2_readings'],
            'loss_units'       => (float)$row['loss_units'],
            'loss_pct'         => $row['loss_pct'] !== null ? (float)$row['loss_pct'] : null,
        ];
    }

    // Build ISO week label from the min date in the result
    $weekLabel = '';
    $weekStart = '';
    $weekEnd   = '';
    if ($minDate !== null) {
        $dt = new DateTimeImmutable($minDate);
        // ISO week: Monday = start, Sunday = end
        $wstart = $dt->modify('Monday this week');
        $wend   = $dt->modify('Sunday this week');
        $monthsFRFull = [
            1 => 'janvier',  2 => 'février',   3 => 'mars',
            4 => 'avril',    5 => 'mai',        6 => 'juin',
            7 => 'juillet',  8 => 'août',       9 => 'septembre',
            10 => 'octobre', 11 => 'novembre',  12 => 'décembre',
        ];
        $weekLabel = sprintf(
            'Semaine du %d %s %s',
            (int)$wstart->format('j'),
            $monthsFRFull[(int)$wstart->format('n')],
            $wstart->format('Y')
        );
        $weekStart = $wstart->format('Y-m-d');
        $weekEnd   = $wend->format('Y-m-d');
    }

    $cache = [
        'list'         => $rows,
        'week_label'   => $weekLabel,
        'week_start'   => $weekStart,
        'week_end'     => $weekEnd,
        'total_events' => count($rows),
    ];

    return $cache;
}

/* ═══════════════════════════════════════════════════════════════════════════
   7. pkg_qa_metrics — A3b: year-level QA/losses overview.
   ═══════════════════════════════════════════════════════════════════════════ */

/**
 * QA metrics for the given year: QA draws, losses, O2/CO2 coverage.
 *
 * Same operator-locked loss formula. QA draws excluded from loss numerator.
 *
 * @param PDO $pdo
 * @param int $year
 * @return array
 */
function pkg_qa_metrics(PDO $pdo, int $year): array
{
    static $cache = [];
    if (isset($cache[$year])) {
        return $cache[$year];
    }

    $lossExpr = "
        COALESCE(p.loss_liquid_other_units,0) +
        COALESCE(p.loss_4pack_btl_units,0) +
        COALESCE(p.loss_4pack_can_units,0) +
        COALESCE(p.loss_wrap_btl_units,0) +
        COALESCE(p.loss_wrap_can_units,0) +
        COALESCE(p.loss_label_btl_units,0) +
        COALESCE(p.loss_crown_cork_units,0) +
        COALESCE(p.loss_can_lid_units,0) +
        COALESCE(p.loss_container_btl_units,0) +
        COALESCE(p.loss_container_can_units,0) +
        COALESCE(p.loss_keg_collar_units,0) +
        COALESCE(p.loss_uncapped_units,0) +
        COALESCE(p.loss_half_filled_units,0) +
        COALESCE(p.loss_untaxed_full_units,0) +
        COALESCE(p.loss_keg_save_units,0) +
        COALESCE(p.unsaleable_units,0)
    ";

    $stmt = $pdo->prepare("
        SELECT
            COUNT(*)                             AS total_events,
            SUM(COALESCE(p.qa_analyses_units,0)) AS qa_analyses_total,
            SUM(COALESCE(p.qa_library_units,0))  AS qa_library_total,
            SUM(COALESCE(p.unsaleable_units,0))  AS unsaleable_total,
            SUM({$lossExpr})                     AS loss_units_total,
            SUM(COALESCE(p.prod_total_units,0))  AS prod_units_total
        FROM bd_packaging_v2 p
        WHERE p.reuses_packaging_id_fk IS NULL
          AND p.is_tombstoned = 0
          AND YEAR(p.event_date) = ?
    ");
    $stmt->execute([$year]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $lossUnits = (float)($row['loss_units_total'] ?? 0);
    $prodUnits = (float)($row['prod_units_total'] ?? 0);
    $handled   = $prodUnits + $lossUnits;
    $lossPct   = $handled > 0 ? $lossUnits / $handled : null;

    $coStmt = $pdo->prepare("
        SELECT
            COUNT(DISTINCT m.packaging_v2_id) AS events_with_measures,
            COUNT(*)                           AS n_readings,
            ROUND(AVG(m.co2), 3)              AS avg_co2,
            ROUND(AVG(m.o2), 2)               AS avg_o2
        FROM bd_packaging_readings m
        INNER JOIN bd_packaging_v2 p ON p.id = m.packaging_v2_id
        WHERE YEAR(p.event_date) = ?
          AND p.reuses_packaging_id_fk IS NULL
          AND p.is_tombstoned = 0
    ");
    $coStmt->execute([$year]);
    $coRow = $coStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $result = [
        'year'                       => $year,
        'total_events'               => (int)($row['total_events'] ?? 0),
        'qa_analyses_total'          => (int)($row['qa_analyses_total'] ?? 0),
        'qa_library_total'           => (int)($row['qa_library_total'] ?? 0),
        'unsaleable_total'           => (int)($row['unsaleable_total'] ?? 0),
        'loss_units_total'           => (int)round($lossUnits),
        'prod_units_total'           => (int)$prodUnits,
        'loss_pct'                   => $lossPct !== null ? round($lossPct, 4) : null,
        'co2o2_events_with_measures' => (int)($coRow['events_with_measures'] ?? 0),
        'co2o2_total_events'         => (int)($row['total_events'] ?? 0),
        'avg_co2_where_measured'     => $coRow['avg_co2'] !== null ? (float)$coRow['avg_co2'] : null,
        'avg_o2_where_measured'      => $coRow['avg_o2'] !== null ? (float)$coRow['avg_o2'] : null,
        'n_co2o2_readings'           => (int)($coRow['n_readings'] ?? 0),
    ];

    $cache[$year] = $result;
    return $result;
}

/* ═══════════════════════════════════════════════════════════════════════════
   8. pkg_beer_loss_by_year — canonical beer-loss % per year (used for
      dashboard correction and "données incomplètes" 2021/2022 caveat).
   ═══════════════════════════════════════════════════════════════════════════ */

/**
 * Canonical inline beer-loss % per year.
 *
 * Formula: beer_loss_hl = v.loss_kpi_hl + (b.loss_liquid_other_units / 100)
 * The loss_liquid_other_units/100 term is MANDATORY — the legacy litres bucket
 * holds most historical loss data (pre-2023 typed-col migration was not present).
 *
 * 2021/2022 return ~0 due to data-entry gap (operators did not fill legacy bucket
 * before 2023). Show with the incomplete_data flag; do NOT imply true 0% loss.
 *
 * Returns list keyed by year with: yr, beer_loss_pct, beer_loss_hl, vendable_hl,
 * incomplete_data (bool: true for years where loss_liquid_other_units is all-zero
 * and loss_kpi_hl is all-zero — data-entry gap flag).
 *
 * @param PDO $pdo
 * @return array<int, array{yr:int, beer_loss_pct:float|null, beer_loss_hl:float, vendable_hl:float, incomplete_data:bool}>
 */
function pkg_beer_loss_by_year(PDO $pdo): array
{
    static $cacheByYear = null;
    if ($cacheByYear !== null) {
        return $cacheByYear;
    }

    $stmt = $pdo->query("
        SELECT
            YEAR(b.event_date)  AS yr,
            ROUND(
                100 * SUM(v.loss_kpi_hl + COALESCE(b.loss_liquid_other_units, 0) / 100.0)
                / NULLIF(SUM(v.vendable_hl), 0)
            , 2)                AS beer_loss_pct,
            ROUND(SUM(v.loss_kpi_hl + COALESCE(b.loss_liquid_other_units, 0) / 100.0), 3)
                                AS beer_loss_hl,
            ROUND(SUM(v.vendable_hl), 2) AS vendable_hl,
            SUM(COALESCE(b.loss_liquid_other_units, 0)) AS legacy_bucket_sum,
            SUM(COALESCE(v.loss_kpi_hl, 0))             AS typed_bucket_sum
        FROM bd_packaging_v2 b
        JOIN v_bd_packaging_v2_vendable v ON v.id = b.id
        WHERE b.reuses_packaging_id_fk IS NULL
          AND b.is_tombstoned = 0
          AND b.event_date IS NOT NULL
        GROUP BY YEAR(b.event_date)
        ORDER BY yr
    ");

    $rows = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $legacySum = (float)$row['legacy_bucket_sum'];
        $typedSum  = (float)$row['typed_bucket_sum'];
        $rows[(int)$row['yr']] = [
            'yr'              => (int)$row['yr'],
            'beer_loss_pct'   => $row['beer_loss_pct'] !== null ? (float)$row['beer_loss_pct'] : null,
            'beer_loss_hl'    => (float)$row['beer_loss_hl'],
            'vendable_hl'     => (float)$row['vendable_hl'],
            'incomplete_data' => ($legacySum == 0.0 && $typedSum == 0.0),
        ];
    }

    $cacheByYear = $rows;
    return $rows;
}

/* ═══════════════════════════════════════════════════════════════════════════
   9. pkg_beer_loss_by_format_month — canonical beer-loss % per format per
      month for the selected year (Change 3: losses trend per format).
   ═══════════════════════════════════════════════════════════════════════════ */

/**
 * Canonical inline beer-loss % per run_type per calendar month, for the given year.
 *
 * Uses the identical formula as pkg_beer_loss_by_year but grouped by month + run_type.
 * Allows the dashboard to draw a multi-series line per format family.
 *
 * Returns list<{mo:int, run_type:string, beer_loss_pct:float|null, beer_loss_hl:float, vendable_hl:float}>
 *
 * @param PDO $pdo
 * @param int $year
 * @return list<array{mo:int, run_type:string, beer_loss_pct:float|null, beer_loss_hl:float, vendable_hl:float}>
 */
function pkg_beer_loss_by_format_month(PDO $pdo, int $year): array
{
    static $cache = [];
    if (isset($cache[$year])) {
        return $cache[$year];
    }

    $stmt = $pdo->prepare("
        SELECT
            MONTH(b.event_date)  AS mo,
            v.run_type,
            ROUND(
                100 * SUM(v.loss_kpi_hl + COALESCE(b.loss_liquid_other_units, 0) / 100.0)
                / NULLIF(SUM(v.vendable_hl), 0)
            , 2)                 AS beer_loss_pct,
            ROUND(SUM(v.loss_kpi_hl + COALESCE(b.loss_liquid_other_units, 0) / 100.0), 3)
                                 AS beer_loss_hl,
            ROUND(SUM(v.vendable_hl), 2) AS vendable_hl
        FROM bd_packaging_v2 b
        JOIN v_bd_packaging_v2_vendable v ON v.id = b.id
        WHERE b.reuses_packaging_id_fk IS NULL
          AND b.is_tombstoned = 0
          AND YEAR(b.event_date) = ?
          AND b.event_date IS NOT NULL
        GROUP BY MONTH(b.event_date), v.run_type
        ORDER BY mo, v.run_type
    ");
    $stmt->execute([$year]);

    $rows = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $rows[] = [
            'mo'            => (int)$row['mo'],
            'run_type'      => (string)$row['run_type'],
            'beer_loss_pct' => $row['beer_loss_pct'] !== null ? (float)$row['beer_loss_pct'] : null,
            'beer_loss_hl'  => (float)$row['beer_loss_hl'],
            'vendable_hl'   => (float)$row['vendable_hl'],
        ];
    }

    $cache[$year] = $rows;
    return $rows;
}

/* ═══════════════════════════════════════════════════════════════════════════
   10. pkg_consumable_loss_rates — consumable scrap rates per year.
    Rateable (1:1): label_btl, crown_cork, can_lid, container_btl, container_can.
    Not honestly rateable (show raw): 4pack_btl/can, wrap_btl/can.
    Near-zero (omit): keg_collar, keg_save.
   ═══════════════════════════════════════════════════════════════════════════ */

/**
 * Consumable scrap rates for the given year.
 *
 * Rateable consumables: rate = SUM(waste) / (SUM(matching_prod) + SUM(waste)).
 * Matching prod: bot runs for label/crown_cork/container_btl; can/can33 for can_lid/container_can.
 *
 * Non-rateable (pack-size unknown): 4pack_btl, 4pack_can, wrap_btl, wrap_can — raw waste counts only.
 *
 * @param PDO $pdo
 * @param int $year
 * @return array
 */
function pkg_consumable_loss_rates(PDO $pdo, int $year): array
{
    static $cache = [];
    if (isset($cache[$year])) {
        return $cache[$year];
    }

    $stmt = $pdo->prepare("
        SELECT
            -- Rateable: labels (bot runs 1:1)
            SUM(COALESCE(b.loss_label_btl_units, 0))    AS label_btl_waste,
            SUM(CASE WHEN v.run_type = 'bot'
                THEN COALESCE(b.prod_total_units, 0) ELSE 0 END) AS bot_prod,
            -- Rateable: crown corks (bot 1:1)
            SUM(COALESCE(b.loss_crown_cork_units, 0))   AS crown_cork_waste,
            -- Rateable: can lids (can/can33 1:1)
            SUM(COALESCE(b.loss_can_lid_units, 0))      AS can_lid_waste,
            SUM(CASE WHEN v.run_type IN ('can','can33')
                THEN COALESCE(b.prod_total_units, 0) ELSE 0 END) AS can_prod,
            -- Rateable: bottle containers (bot 1:1)
            SUM(COALESCE(b.loss_container_btl_units, 0)) AS container_btl_waste,
            -- Rateable: can containers (can/can33 1:1)
            SUM(COALESCE(b.loss_container_can_units, 0)) AS container_can_waste,
            -- Not rateable: raw counts only
            SUM(COALESCE(b.loss_4pack_btl_units, 0))    AS pack4_btl_raw,
            SUM(COALESCE(b.loss_4pack_can_units, 0))    AS pack4_can_raw,
            SUM(COALESCE(b.loss_wrap_btl_units, 0))     AS wrap_btl_raw,
            SUM(COALESCE(b.loss_wrap_can_units, 0))     AS wrap_can_raw
        FROM bd_packaging_v2 b
        JOIN v_bd_packaging_v2_vendable v ON v.id = b.id
        WHERE b.reuses_packaging_id_fk IS NULL
          AND b.is_tombstoned = 0
          AND YEAR(b.event_date) = ?
    ");
    $stmt->execute([$year]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $botProd     = (float)($row['bot_prod'] ?? 0);
    $canProd     = (float)($row['can_prod'] ?? 0);

    $labelWaste       = (float)($row['label_btl_waste'] ?? 0);
    $crownWaste       = (float)($row['crown_cork_waste'] ?? 0);
    $canLidWaste      = (float)($row['can_lid_waste'] ?? 0);
    $contBtlWaste     = (float)($row['container_btl_waste'] ?? 0);
    $contCanWaste     = (float)($row['container_can_waste'] ?? 0);

    $calcRate = function(float $waste, float $prod): ?float {
        $denom = $prod + $waste;
        return $denom > 0 ? round(100 * $waste / $denom, 2) : null;
    };

    $result = [
        // Rateable — show rate %
        'rateable' => [
            [
                'code'        => 'label_btl',
                'label_fr'    => 'Étiquettes bouteilles',
                'rate_pct'    => $calcRate($labelWaste, $botProd),
                'waste_units' => (int)$labelWaste,
            ],
            [
                'code'        => 'crown_cork',
                'label_fr'    => 'Capsules couronne',
                'rate_pct'    => $calcRate($crownWaste, $botProd),
                'waste_units' => (int)$crownWaste,
            ],
            [
                'code'        => 'can_lid',
                'label_fr'    => 'Couvercles canettes',
                'rate_pct'    => $calcRate($canLidWaste, $canProd),
                'waste_units' => (int)$canLidWaste,
            ],
            [
                'code'        => 'container_btl',
                'label_fr'    => 'Bouteilles (contenants)',
                'rate_pct'    => $calcRate($contBtlWaste, $botProd),
                'waste_units' => (int)$contBtlWaste,
            ],
            [
                'code'        => 'container_can',
                'label_fr'    => 'Canettes (contenants)',
                'rate_pct'    => $calcRate($contCanWaste, $canProd),
                'waste_units' => (int)$contCanWaste,
            ],
        ],
        // Not rateable — raw count + flag
        'raw_count' => [
            [
                'code'        => 'pack4_btl',
                'label_fr'    => 'Déchets 4-packs bouteilles',
                'waste_units' => (int)($row['pack4_btl_raw'] ?? 0),
                'pending_note'=> 'taux en attente (pack-size)',
            ],
            [
                'code'        => 'pack4_can',
                'label_fr'    => 'Déchets 4-packs canettes',
                'waste_units' => (int)($row['pack4_can_raw'] ?? 0),
                'pending_note'=> 'taux en attente (pack-size)',
            ],
            [
                'code'        => 'wrap_btl',
                'label_fr'    => 'Déchets fardelage bouteilles',
                'waste_units' => (int)($row['wrap_btl_raw'] ?? 0),
                'pending_note'=> 'taux en attente (pack-size)',
            ],
            [
                'code'        => 'wrap_can',
                'label_fr'    => 'Déchets fardelage canettes',
                'waste_units' => (int)($row['wrap_can_raw'] ?? 0),
                'pending_note'=> 'taux en attente (pack-size)',
            ],
        ],
    ];

    $cache[$year] = $result;
    return $result;
}

/* ═══════════════════════════════════════════════════════════════════════════
   11. pkg_qa_trend_by_month — QA reads monthly trend (Change 3).
   ═══════════════════════════════════════════════════════════════════════════ */

/**
 * Monthly QA reads trend: qa_analyses_units + qa_library_units per month.
 *
 * These columns are well-populated 2021→2026 (~800-1200/yr + ~560-900/yr).
 *
 * @param PDO $pdo
 * @param int $year
 * @return list<array{mo:int, qa_analyses:int, qa_library:int, qa_total:int, events:int}>
 */
function pkg_qa_trend_by_month(PDO $pdo, int $year): array
{
    static $cache = [];
    if (isset($cache[$year])) {
        return $cache[$year];
    }

    $stmt = $pdo->prepare("
        SELECT
            MONTH(b.event_date)                   AS mo,
            SUM(COALESCE(b.qa_analyses_units, 0)) AS qa_analyses,
            SUM(COALESCE(b.qa_library_units, 0))  AS qa_library,
            COUNT(*)                              AS events
        FROM bd_packaging_v2 b
        WHERE b.reuses_packaging_id_fk IS NULL
          AND b.is_tombstoned = 0
          AND YEAR(b.event_date) = ?
          AND b.event_date IS NOT NULL
        GROUP BY MONTH(b.event_date)
        ORDER BY mo
    ");
    $stmt->execute([$year]);

    $rows = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $qa  = (int)$row['qa_analyses'];
        $lib = (int)$row['qa_library'];
        $rows[] = [
            'mo'          => (int)$row['mo'],
            'qa_analyses' => $qa,
            'qa_library'  => $lib,
            'qa_total'    => $qa + $lib,
            'events'      => (int)$row['events'],
        ];
    }

    $cache[$year] = $rows;
    return $rows;
}

/* ═══════════════════════════════════════════════════════════════════════════
   12. pkg_co2o2_readings — sparse O2/CO2 individual readings for the year.
   ═══════════════════════════════════════════════════════════════════════════ */

/**
 * All individual in-filling O2/CO2 readings from bd_packaging_readings
 * (packaging_v2_id link) for a year.
 *
 * Extremely sparse: 11 readings / 3 events / all 2026 (PM-verified live).
 * Returned as individual points (not aggregated) so the dashboard can
 * show each as a dot with honest n= context.
 *
 * @param PDO $pdo
 * @param int $year
 * @return array{readings: list<array>, n_readings: int, n_events: int}
 */
function pkg_co2o2_readings(PDO $pdo, int $year): array
{
    static $cache = [];
    if (isset($cache[$year])) {
        return $cache[$year];
    }

    $stmt = $pdo->prepare("
        SELECT
            m.id,
            m.packaging_v2_id,
            m.reading_idx,
            m.co2,
            m.o2,
            p.event_date,
            p.run_type,
            COALESCE(s.sku_code, '(SKU manquant)') AS sku_code
        FROM bd_packaging_readings m
        INNER JOIN bd_packaging_v2 p ON p.id = m.packaging_v2_id
        LEFT  JOIN ref_skus s        ON s.id = p.sku_id_fk
        WHERE YEAR(p.event_date) = ?
          AND p.reuses_packaging_id_fk IS NULL
          AND p.is_tombstoned = 0
        ORDER BY p.event_date, m.packaging_v2_id, m.reading_idx
    ");
    $stmt->execute([$year]);

    $rows = [];
    $eventIds = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $eventIds[$row['packaging_v2_id']] = true;
        $rows[] = [
            'id'              => (int)$row['id'],
            'packaging_id'    => (int)$row['packaging_v2_id'],
            'reading_index'   => (int)$row['reading_idx'],
            'co2_gl'          => $row['co2'] !== null ? (float)$row['co2'] : null,
            'o2_ppb'          => $row['o2'] !== null ? (float)$row['o2'] : null,
            'event_date'      => (string)$row['event_date'],
            'run_type'        => (string)$row['run_type'],
            'sku_code'        => (string)$row['sku_code'],
        ];
    }

    $result = [
        'readings' => $rows,
        'n_readings' => count($rows),
        'n_events'   => count($eventIds),
    ];

    $cache[$year] = $result;
    return $result;
}

/* ═══════════════════════════════════════════════════════════════════════════
   13. pkg_quarterly_hl — A4: quarterly HL for current + prior year.
   ═══════════════════════════════════════════════════════════════════════════ */

/**
 * Quarterly total HL for the given year and the prior year.
 * V2-only, reuse-filtered, stored vendable_hl.
 *
 * Return shape: list<{yr:int, q:int, hl:float, events:int}>
 *
 * @param PDO $pdo
 * @param int $year  Current (selected) year.
 * @return list<array{yr:int, q:int, hl:float, events:int}>
 */
function pkg_quarterly_hl(PDO $pdo, int $year): array
{
    static $cacheQ = [];
    if (isset($cacheQ[$year])) {
        return $cacheQ[$year];
    }

    $priorYear = $year - 1;

    $stmt = $pdo->prepare("
        SELECT YEAR(p.event_date)            AS yr,
               QUARTER(p.event_date)         AS q,
               ROUND(SUM(p.vendable_hl), 2)  AS hl,
               COUNT(*)                      AS events
        FROM bd_packaging_v2 p
        WHERE p.reuses_packaging_id_fk IS NULL
          AND p.is_tombstoned = 0
          AND YEAR(p.event_date) IN (?, ?)
        GROUP BY YEAR(p.event_date), QUARTER(p.event_date)
        ORDER BY yr, q
    ");
    $stmt->execute([$year, $priorYear]);

    $qrows = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $qrows[] = [
            'yr'     => (int)$row['yr'],
            'q'      => (int)$row['q'],
            'hl'     => (float)$row['hl'],
            'events' => (int)$row['events'],
        ];
    }

    $cacheQ[$year] = $qrows;
    return $qrows;
}

/* ═══════════════════════════════════════════════════════════════════════════
   9. pkg_monthly_hl_ytd — A4: monthly HL for cumulative YTD comparison.
   ═══════════════════════════════════════════════════════════════════════════ */

/**
 * Monthly total HL for the given year and the prior year.
 * V2-only, reuse-filtered, stored vendable_hl.
 *
 * Return shape: list<{yr:int, mo:int, hl:float, events:int}>
 *
 * @param PDO $pdo
 * @param int $year  Current (selected) year.
 * @return list<array{yr:int, mo:int, hl:float, events:int}>
 */
function pkg_monthly_hl_ytd(PDO $pdo, int $year): array
{
    static $cacheM = [];
    if (isset($cacheM[$year])) {
        return $cacheM[$year];
    }

    $priorYear = $year - 1;

    $stmt = $pdo->prepare("
        SELECT YEAR(p.event_date)            AS yr,
               MONTH(p.event_date)           AS mo,
               ROUND(SUM(p.vendable_hl), 2)  AS hl,
               COUNT(*)                      AS events
        FROM bd_packaging_v2 p
        WHERE p.reuses_packaging_id_fk IS NULL
          AND p.is_tombstoned = 0
          AND YEAR(p.event_date) IN (?, ?)
        GROUP BY YEAR(p.event_date), MONTH(p.event_date)
        ORDER BY yr, mo
    ");
    $stmt->execute([$year, $priorYear]);

    $mrows = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $mrows[] = [
            'yr'     => (int)$row['yr'],
            'mo'     => (int)$row['mo'],
            'hl'     => (float)$row['hl'],
            'events' => (int)$row['events'],
        ];
    }

    $cacheM[$year] = $mrows;
    return $mrows;
}
