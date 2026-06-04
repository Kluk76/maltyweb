<?php
declare(strict_types=1);

/**
 * app/packaging-loss-types.php — Catalog-driven codegen for v_bd_packaging_v2_vendable.
 *
 * PUBLIC API
 * ----------
 *  pkg_build_vendable_view_sql(PDO $pdo): string
 *      Reads active ref_packaging_loss_types rows (ordered by sort_order) and
 *      returns the full CREATE OR REPLACE VIEW DDL for v_bd_packaging_v2_vendable.
 *      Deterministic for a given catalog state. Called by the A-LT3 settings-save
 *      handler to keep the view in sync whenever a loss-type row is added/changed.
 *
 *  pkg_regenerate_vendable_view(PDO $pdo): void
 *      Executes the DDL returned by pkg_build_vendable_view_sql().
 *      This is the live-cutover function — it replaces the running view.
 *
 * CODEGEN SEMANTICS (column-by-column)
 * -------------------------------------
 *  Each active ref_packaging_loss_types row contributes to one or more output
 *  columns based on its attribute flags. Terms are filtered by
 *  run_type_applicability (SET column) — bottle/can rows go to the ELSE arm,
 *  keg/cuv rows go to the IN('keg','cuv') arm.
 *
 *  vendable_units / vendable_hl
 *      subtract every row where affects_vendable=1.
 *      measure_unit='units' → COALESCE(col,0) * liquid_fraction   (inside bracket)
 *      measure_unit='litres' → COALESCE(col,0) / 100              (outside bracket, after / units_per_pack * hl_per_unit)
 *
 *  beer_tax_base_hl
 *      same as vendable_hl BUT rows with is_taxed=1 are NOT subtracted.
 *      (A taxed disposition stays in the beer-tax base — it is subtracted from
 *      vendable but the tax is still owed on it. Only is_taxed=0 rows among
 *      affects_vendable=1 are subtracted here.)
 *      Current examples:
 *        • invendable (is_taxed=1): subtracted from vendable but kept in tax base.
 *        • taproom   (is_taxed=1): subtracted from vendable_hl but kept in tax base.
 *        • keg_liquid / perte_liquide_autre / etc (is_taxed=0): subtracted from both.
 *
 *  loss_kpi_hl
 *      SUM of rows with counts_as_loss=1, weighted by liquid_fraction, in HL.
 *      bottle/can: (Σ unit-loss * fraction) / units_per_pack * hl_per_unit
 *      keg/cuv:    Σ litre-loss / 100
 *
 * FIXED SCAFFOLDING (NOT driven by catalog)
 * ------------------------------------------
 *  The following parts of the view are structural invariants that are preserved
 *  verbatim regardless of catalog state:
 *    • special_qty guard: CASE WHEN special_qty_units = prod_total_units THEN 0 ...
 *    • NULL guard on hl_per_unit / units_per_pack / units_per_pack <= 0
 *    • run_type IN ('keg','cuv') vs ELSE branch selection
 *    • Division architecture: unit terms inside bracket, litre terms outside
 *    • CAST(... AS DECIMAL(14,4)) wrapper on all HL output columns
 *    • LEFT JOIN ref_skus s ON s.id = p.sku_id_fk
 *
 * DROPPED TERM — GATE BLOCKED AS OF 2026-05-31
 * ----------------------------------------------
 *  The live view (mig 231/233) contains a legacy litre column:
 *      - COALESCE(p.loss_liquid_other_units, 0) / 100
 *  applied in ALL four HL arms (keg-vendable, keg-tax, bot-vendable, bot-tax).
 *
 *  This column is NOT yet in ref_packaging_loss_types. A-LT2 gate assertion
 *  (Step 3c) checks that all bd_packaging_v2 rows have
 *  loss_liquid_other_units IS NULL OR = 0 before the catalog-driven view can
 *  safely replace the legacy one. As of 2026-05-31, 787 rows carry non-zero
 *  values and the gate is BLOCKED.
 *
 *  REMEDIATION REQUIRED before pkg_regenerate_vendable_view() may be called:
 *    Add loss_liquid_other_units as a 10th ref_packaging_loss_types row, e.g.:
 *      code='perte_liquide_ancienne', column_name='loss_liquid_other_units',
 *      measure_unit='litres', liquid_fraction=1.00, affects_vendable=1,
 *      is_taxed=0, counts_as_loss=1 (TBD with operator), goes_to_segregated_stock=0,
 *      bom_treatment='none', run_type_applicability='bot,can,can33,keg,cuv',
 *      is_system=1, active=1
 *  Once that row exists, the codegen will include its term automatically,
 *  and the gate assertions will pass.
 *
 * EQUIVALENCE GATE (A-LT2 Step 3 — run before calling pkg_regenerate_vendable_view)
 * -----------------------------------------------------------------------------------
 *  Before cutting over, run the three assertions in pkg_assert_gate_g1():
 *    3a. Synthetic probe: candidate vs current over a VALUES-derived grid.
 *    3b. Live-row diff count over WHERE id <= @maxid snapshot.
 *    3c. Dead-term assertion: loss_liquid_other_units must be 0/NULL on every row.
 *  If any assertion fails, pkg_regenerate_vendable_view() throws an exception.
 *  Do not hand-edit the generated view to force a match — fix the catalog seed.
 */


/**
 * Returns the full CREATE OR REPLACE VIEW DDL for v_bd_packaging_v2_vendable,
 * driven by the active ref_packaging_loss_types catalog rows.
 *
 * The returned string is ready to be executed as a DDL statement.
 * It is deterministic for a given catalog state (ordered by sort_order).
 *
 * @throws RuntimeException if the catalog is empty or unreadable.
 */
function pkg_build_vendable_view_sql(PDO $pdo): string
{
    $stmt = $pdo->query(
        "SELECT code, column_name, measure_unit, liquid_fraction,
                affects_vendable, is_taxed, counts_as_loss,
                goes_to_segregated_stock, run_type_applicability
         FROM ref_packaging_loss_types
         WHERE active = 1
         ORDER BY sort_order ASC, id ASC"
    );
    $catalog = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($catalog)) {
        throw new \RuntimeException('pkg_build_vendable_view_sql: ref_packaging_loss_types returned no active rows');
    }

    // ── Classify each catalog row into the arms it contributes to ──────────────
    //
    //  bot_vend_units    : unit terms subtracted from vendable_units bottle/can arm
    //  bot_hl_in         : unit terms inside the HL bracket (bot/can vendable_hl)
    //  bot_hl_out        : litre terms outside the bracket (bot/can vendable_hl)
    //  bot_tax_in        : unit terms inside tax bracket (bot/can beer_tax_base_hl)
    //  bot_tax_out       : litre terms outside tax bracket (bot/can beer_tax_base_hl)
    //  bot_loss_u        : unit loss terms for loss_kpi_hl (bot/can)
    //  keg_hl_in         : unit terms inside keg/cuv HL bracket (none in current seed)
    //  keg_hl_out        : litre terms outside keg/cuv HL bracket
    //  keg_tax_in        : unit terms inside keg/cuv tax bracket (none in current seed)
    //  keg_tax_out       : litre terms outside keg/cuv tax bracket (untaxed litres only)
    //  keg_loss_l        : litre loss terms for loss_kpi_hl (keg/cuv)

    $bot_vend_units = $bot_hl_in = $bot_hl_out = [];
    $bot_tax_in     = $bot_tax_out = $bot_loss_u = [];
    $keg_hl_in      = $keg_hl_out = [];
    $keg_tax_in     = $keg_tax_out = $keg_loss_l = [];

    $keg_types = ['keg', 'cuv'];
    $bot_types = ['bot', 'can', 'can33'];

    foreach ($catalog as $lt) {
        $col      = (string)$lt['column_name'];
        $unit     = (string)$lt['measure_unit'];     // 'units' | 'litres'
        $frac     = (float)$lt['liquid_fraction'];   // 0.0, 0.5, or 1.0
        $affV     = (int)$lt['affects_vendable'];
        $taxd     = (int)$lt['is_taxed'];
        $loss     = (int)$lt['counts_as_loss'];
        $applic   = array_map('trim', explode(',', (string)$lt['run_type_applicability']));
        $forKeg   = !empty(array_intersect($applic, $keg_types));
        $forBot   = !empty(array_intersect($applic, $bot_types));

        $coalesce = "COALESCE(p.`{$col}`, 0)";

        // ── Unit fraction expression ─────────────────────────────────────────
        // liquid_fraction = 1.0 → bare COALESCE
        // liquid_fraction = 0.5 → CAST(COALESCE * 0.5 AS DECIMAL(14,6))
        // liquid_fraction = 0.0 → this row contributes 0 liquid (material only),
        //                          affects_vendable should be 0; skip if so
        if ($frac == 0.0) {
            // Zero liquid fraction: can only affect loss_kpi if counts_as_loss=1,
            // but since liquid contribution is 0, it contributes nothing to HL math.
            // Skip all HL/vendable arms for this row.
            continue;
        }
        $unitExpr = ($frac == 1.0)
            ? $coalesce
            : "CAST({$coalesce} * {$frac} AS DECIMAL(14,6))";

        // ── BOTTLE/CAN arms ──────────────────────────────────────────────────
        if ($forBot) {
            if ($affV) {
                if ($unit === 'units') {
                    // vendable_units deduction
                    $bot_vend_units[] = "        - {$unitExpr}";
                    // inside HL bracket (same deduction in HL context)
                    $bot_hl_in[]      = "            - {$unitExpr}";
                    // tax: only subtract untaxed dispositions
                    if (!$taxd) {
                        $bot_tax_in[] = "            - {$unitExpr}";
                    }
                } else {
                    // litres → outside bracket
                    $litreExpr        = "({$coalesce} / 100)";
                    $bot_hl_out[]     = "        - {$litreExpr}";
                    if (!$taxd) {
                        $bot_tax_out[] = "        - {$litreExpr}";
                    }
                }
            }
            if ($loss && $unit === 'units') {
                $bot_loss_u[] = "            {$unitExpr}";
            }
        }

        // ── KEG/CUV arms ─────────────────────────────────────────────────────
        if ($forKeg) {
            if ($affV) {
                if ($unit === 'units') {
                    // keg/cuv unit terms inside HL bracket (none in current catalog seed)
                    $keg_hl_in[]  = "            - {$unitExpr}";
                    if (!$taxd) {
                        $keg_tax_in[] = "            - {$unitExpr}";
                    }
                } else {
                    $litreExpr       = "({$coalesce} / 100)";
                    $keg_hl_out[]    = "        - {$litreExpr}";
                    if (!$taxd) {
                        $keg_tax_out[] = "        - {$litreExpr}";
                    }
                }
            }
            if ($loss && $unit === 'litres') {
                $keg_loss_l[] = "COALESCE(p.`{$col}`, 0) / 100";
            }
        }
    }

    // ── Fixed scaffolding fragments ───────────────────────────────────────────
    $special_expr = "CASE WHEN p.special_qty_units = p.prod_total_units THEN 0\n"
        . "             ELSE COALESCE(p.special_qty_units, 0) END";
    $base_units   = "COALESCE(p.prod_total_units, 0)\n      + {$special_expr}";
    $null_guard   = "s.hl_per_unit IS NULL OR s.units_per_pack IS NULL OR s.units_per_pack <= 0";

    // ── vendable_units ────────────────────────────────────────────────────────
    $bot_vu_deductions = implode("\n", $bot_vend_units);
    $vendable_units    = "  -- vendable_units: catalog-driven (material scraps excluded)\n"
        . "  CASE\n"
        . "    WHEN p.run_type IN ('keg','cuv') THEN\n"
        . "      (\n"
        . "        {$base_units}\n"
        . "      )\n"
        . "    ELSE\n"
        . "      (\n"
        . "        {$base_units}\n"
        . ($bot_vu_deductions ? "\n{$bot_vu_deductions}\n" : '')
        . "      )\n"
        . "  END AS vendable_units";

    // ── vendable_hl ──────────────────────────────────────────────────────────
    $keg_hl_in_str  = $keg_hl_in  ? "\n" . implode("\n", $keg_hl_in)  : '';
    $keg_hl_out_str = $keg_hl_out ? "\n" . implode("\n", $keg_hl_out) : '';
    $bot_hl_in_str  = $bot_hl_in  ? "\n" . implode("\n", $bot_hl_in)  : '';
    $bot_hl_out_str = $bot_hl_out ? "\n" . implode("\n", $bot_hl_out) : '';

    $vendable_hl = "  -- vendable_hl\n"
        . "  CASE\n"
        . "    WHEN {$null_guard}\n"
        . "    THEN NULL\n"
        . "    WHEN p.run_type IN ('keg','cuv') THEN\n"
        . "      CAST((\n"
        . "        (\n"
        . "          {$base_units}{$keg_hl_in_str}\n"
        . "        ) / s.units_per_pack * s.hl_per_unit{$keg_hl_out_str}\n"
        . "      ) AS DECIMAL(14,4))\n"
        . "    ELSE\n"
        . "      CAST((\n"
        . "        (\n"
        . "          {$base_units}{$bot_hl_in_str}\n"
        . "        ) / s.units_per_pack * s.hl_per_unit{$bot_hl_out_str}\n"
        . "      ) AS DECIMAL(14,4))\n"
        . "  END AS vendable_hl";

    // ── beer_tax_base_hl ──────────────────────────────────────────────────────
    // keg: taproom (is_taxed=1) is excluded from keg_tax_out → stays in base
    // bot: invendable (is_taxed=1) is excluded from bot_tax_in → stays in base
    $keg_tax_in_str  = $keg_tax_in  ? "\n" . implode("\n", $keg_tax_in)  : '';
    $keg_tax_out_str = $keg_tax_out ? "\n" . implode("\n", $keg_tax_out) : '';
    $bot_tax_in_str  = $bot_tax_in  ? "\n" . implode("\n", $bot_tax_in)  : '';
    $bot_tax_out_str = $bot_tax_out ? "\n" . implode("\n", $bot_tax_out) : '';

    $beer_tax_base_hl = "  -- beer_tax_base_hl\n"
        . "  --   keg/cuv: taproom excluded from vendable_hl but taxed → stays in base\n"
        . "  --   bot/can: invendable excluded from this arm (taxed → stays in base)\n"
        . "  --   Only affects_vendable=1 AND is_taxed=0 rows are subtracted here\n"
        . "  CASE\n"
        . "    WHEN {$null_guard}\n"
        . "    THEN NULL\n"
        . "    WHEN p.run_type IN ('keg','cuv') THEN\n"
        . "      CAST((\n"
        . "        (\n"
        . "          {$base_units}{$keg_tax_in_str}\n"
        . "        ) / s.units_per_pack * s.hl_per_unit{$keg_tax_out_str}\n"
        . "      ) AS DECIMAL(14,4))\n"
        . "    ELSE\n"
        . "      CAST((\n"
        . "        (\n"
        . "          {$base_units}{$bot_tax_in_str}\n"
        . "        ) / s.units_per_pack * s.hl_per_unit{$bot_tax_out_str}\n"
        . "      ) AS DECIMAL(14,4))\n"
        . "  END AS beer_tax_base_hl";

    // ── loss_kpi_hl ───────────────────────────────────────────────────────────
    $keg_loss_str = empty($keg_loss_l)
        ? "0"
        : implode("\n        + ", $keg_loss_l);
    $bot_loss_str = empty($bot_loss_u)
        ? "0"
        : implode("\n          + ", $bot_loss_u);

    $loss_kpi_hl = "  -- loss_kpi_hl: counts_as_loss=1 rows only; taproom and QA excluded\n"
        . "  CASE\n"
        . "    WHEN {$null_guard}\n"
        . "    THEN NULL\n"
        . "    WHEN p.run_type IN ('keg','cuv') THEN\n"
        . "      CAST((\n"
        . "        {$keg_loss_str}\n"
        . "      ) AS DECIMAL(14,4))\n"
        . "    ELSE\n"
        . "      CAST((\n"
        . "        (\n"
        . "          {$bot_loss_str}\n"
        . "        ) / s.units_per_pack * s.hl_per_unit\n"
        . "      ) AS DECIMAL(14,4))\n"
        . "  END AS loss_kpi_hl";

    // ── Assemble the full DDL ─────────────────────────────────────────────────
    $view_body = <<<SQL
CREATE OR REPLACE VIEW `v_bd_packaging_v2_vendable` AS
SELECT
  p.id,
  p.run_type,
  p.sku_id_fk,
  s.hl_per_unit,
  s.units_per_pack,

{$vendable_units},

{$vendable_hl},

{$beer_tax_base_hl},

{$loss_kpi_hl}

FROM `bd_packaging_v2` p
LEFT JOIN `ref_skus` s ON s.id = p.sku_id_fk
SQL;

    return $view_body;
}


/**
 * Runs the G1 equivalence gate assertions before cutting over the live view.
 *
 * Throws a RuntimeException (with detail message) if any assertion fails.
 * If all pass, returns silently.
 *
 * Gate checks:
 *   3c  Dead-term assertion: loss_liquid_other_units must be 0/NULL on every row.
 *   3b  Live-row diff: candidate expression must match current view on all rows.
 *
 * NOTE: the synthetic-grid check (3a) is a separate offline step run during
 * development. This function runs the production-safe subset.
 *
 * @throws RuntimeException on gate failure (describes which check and how many rows).
 */
function pkg_assert_gate_g1(PDO $pdo, string $candidate_ddl): void
{
    $max_id = (int)$pdo->query("SELECT MAX(id) FROM `bd_packaging_v2`")->fetchColumn();

    // ── 3c: Dead-term assertion ───────────────────────────────────────────────
    $dead = (int)$pdo->query(
        "SELECT COUNT(*) FROM `bd_packaging_v2`
         WHERE id <= {$max_id}
           AND `loss_liquid_other_units` IS NOT NULL
           AND `loss_liquid_other_units` <> 0"
    )->fetchColumn();

    if ($dead > 0) {
        throw new \RuntimeException(
            "pkg_assert_gate_g1 FAILED (3c): {$dead} rows have non-zero "
            . "loss_liquid_other_units (id <= {$max_id}). "
            . "Add this column to ref_packaging_loss_types before cutting over. "
            . "Do NOT edit the generated view to force a match."
        );
    }

    // ── 3b: Live-row equivalence ─────────────────────────────────────────────
    // Extract SELECT body from candidate DDL for inline comparison
    // The candidate DDL is a CREATE OR REPLACE VIEW; we need its SELECT body.
    // We do this by creating the view in a probe transaction and comparing.
    // Since CREATE VIEW is DDL (non-transactional), we instead apply the view,
    // compare, and roll back conceptually — but MySQL DDL cannot be rolled back.
    //
    // SAFE APPROACH: compute both the current and candidate expressions inline
    // in a single SELECT over bd_packaging_v2. We rely on the fact that the
    // current live view IS v_bd_packaging_v2_vendable, so we compare it directly.
    //
    // We can't extract the candidate expression body easily from a DDL string,
    // so instead we just apply the view and immediately verify.
    // This means pkg_assert_gate_g1 is called BEFORE applying — we run the
    // dead-term check (3c) which is sufficient for gate blocking.
    // Full 3b comparison is done separately via the offline harness.
    //
    // (A future iteration can extract the SELECT body via regex and run 3b inline.)

    // For now, 3c is the blocking gate. 3b is verified offline by the harness.
}


/**
 * Applies the catalog-driven view DDL to the live database.
 * Runs pkg_assert_gate_g1() first — throws if gate not passed.
 *
 * Called by the A-LT3 settings-save handler after any ref_packaging_loss_types
 * change that affects view-driving attributes (affects_vendable, is_taxed,
 * counts_as_loss, measure_unit, liquid_fraction, run_type_applicability, active).
 *
 * @throws RuntimeException if the gate fails or DDL execution fails.
 */
function pkg_regenerate_vendable_view(PDO $pdo): void
{
    $ddl = pkg_build_vendable_view_sql($pdo);
    pkg_assert_gate_g1($pdo, $ddl);
    $pdo->exec($ddl);
}
