<?php
declare(strict_types=1);
/**
 * Canonical SKU-catalog accessor for display/UX surfaces.
 *
 * COMPOSITE SKU RULE: 6 composite SKUs (PD8/XMASPACK/etc.) have recipe_id = NULL and
 * are Nébuleuse by construction. The LEFT JOIN + COALESCE(r.classification,'Neb')
 * ensures they are never dropped and always classified as 'Neb'.
 *
 * WARNING: the `classification` filter is for display/UX only. Never use it in a
 * COGS/fiscal/compute query — use the canonical ref_skus + ref_recipes join directly.
 */

if (!function_exists('sku_catalog')) {
    /**
     * Returns all SKUs from ref_skus with recipe metadata joined.
     *
     * @param PDO    $pdo
     * @param array  $opts {
     *   active_only        bool   default true   — AND rs.is_active = 1
     *   classification     string default 'all'  — 'all'|'Neb'|'Contract'
     *   packaging_line_only bool  default false  — AND rs.is_packaging_line = 1
     *   order_by           string default 'sku_code' — 'sku_code'|'beer'
     * }
     * @return array<int, array<string, mixed>>
     */
    function sku_catalog(PDO $pdo, array $opts = []): array
    {
        $activeOnly        = (bool)   ($opts['active_only']        ?? true);
        $classification    = (string) ($opts['classification']     ?? 'all');
        $packagingLineOnly = (bool)   ($opts['packaging_line_only'] ?? false);
        $orderByKey        = (string) ($opts['order_by']           ?? 'sku_code');

        // ── Inner WHERE clauses ───────────────────────────────────────────────
        $where  = [];
        $params = [];

        if ($activeOnly) {
            $where[] = 'rs.is_active = 1';
        }
        if ($packagingLineOnly) {
            $where[] = 'rs.is_packaging_line = 1';
        }

        $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

        // ── ORDER BY whitelist ────────────────────────────────────────────────
        $orderSql = match($orderByKey) {
            'beer'  => 'beer_name, format, sku_code',
            default => 'sku_code',
        };

        // ── Always wrap in a subquery for uniform column references ───────────
        $innerSql = "
            SELECT rs.id,
                   rs.sku_code,
                   rs.unit_label,
                   rs.hl_per_unit,
                   rs.units_per_pack,
                   rs.recipe_id,
                   COALESCE(r.classification, 'Neb') AS classification,
                   r.subtype,
                   r.name AS beer_name,
                   rs.format,
                   rs.is_active,
                   rs.is_packaging_line,
                   rs.is_direct_sales,
                   rs.is_non_stock,
                   rs.stocktake_scope
              FROM ref_skus rs
              LEFT JOIN ref_recipes r ON r.id = rs.recipe_id
            {$whereSql}";

        // ── Outer classification filter ────────────────────────────────────────
        $classificationSql = '';
        if ($classification !== 'all') {
            $classificationSql = 'WHERE sub.classification = ?';
            $params[]          = $classification;
        }

        $sql = "SELECT * FROM ({$innerSql}) sub {$classificationSql} ORDER BY {$orderSql}";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('sku_classification_label')) {
    /**
     * Human-readable label for a classification value.
     */
    function sku_classification_label(string $c): string
    {
        return match($c) {
            'Neb'      => 'Nébuleuse',
            'Contract' => 'Contract',
            default    => $c,
        };
    }
}

if (!function_exists('run_type_container_label')) {
    /**
     * Canonical run_type → French container noun (PLURAL, bare — no volume).
     * Source of truth for customer/UX-facing container words.
     * Supersedes the inline maps in form-packaging.php (RUN_TYPE_LABELS),
     * packaging-stats.php, kpi-email-render.php, mi_propose.php — future work
     * should call this, not re-copy. unit_label already carries volume/pack, so
     * this stays a bare noun to avoid double-stating size.
     */
    function run_type_container_label(?string $rt): string
    {
        switch ($rt) {
            case 'bot':   return 'Bouteilles';
            case 'can':   return 'Canettes';
            case 'can33': return 'Canettes';
            case 'keg':   return 'Fûts';
            case 'cuv':   return 'Cuves de service';
            default:      return ''; // NULL / composite / draft / vrac → no container word
        }
    }
}
