<?php
declare(strict_types=1);

/**
 * fulfilment-site.php — THE single fulfilment-site authority.
 *
 * Two public functions:
 *   fulfilment_default_sites(PDO $pdo): array
 *     Returns role→site_id derived from ref_sites. Never hardcoded IDs.
 *     Roles: 'warehouse', 'pos', 'production'.
 *     Fallback chain if a role has no active site:
 *       warehouse → production → any holds_fg_stock=1 site
 *
 *   resolve_fulfilment_site(PDO $pdo, array $ctx): int
 *     Single entry point for site resolution. $ctx keys (all optional):
 *       fulfilment_site_id_fk      — per-order operator override (wins over everything)
 *       customer_id_fk             — look up ref_customers.default_delivery_site_id_fk
 *       _customer_default_site_id  — prefetched default site (skips the DB lookup in step 2;
 *                                    use when the caller already JOINed ref_customers, e.g.
 *                                    the expedie leg in fg_stock_location_snapshot())
 *       channel                    — 'taproom'→pos, 'eshop'→warehouse, else→warehouse
 *
 * Three call patterns:
 *   B2B/expedié:  resolve_fulfilment_site($pdo, [
 *                   'fulfilment_site_id_fk'     => $order['fulfilment_site_id_fk'],
 *                   'customer_id_fk'             => $order['customer_id_fk'],
 *                   'channel'                    => $order['internal_channel'],
 *                   '_customer_default_site_id'  => $order['customer_default_site_id'], // prefetched via JOIN
 *                 ])
 *   eshop leg:    resolve_fulfilment_site($pdo, ['channel' => 'eshop'])
 *   taproom leg:  resolve_fulfilment_site($pdo, ['channel' => 'taproom'])
 *
 * Never inline a parallel channel→site map elsewhere — divergence anti-pattern.
 * This file is consumed by:
 *   - order-create endpoint (seeds the site selector default)
 *   - fg_stock_location_snapshot() (attributes each sale to a site)
 */

/**
 * Returns role→site_id map derived live from ref_sites.
 * Cached in a static; safe to call multiple times per request.
 *
 * @return array{warehouse: int, pos: int, production: int}
 */
function fulfilment_default_sites(PDO $pdo): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $resolve = static function (string $type) use ($pdo): ?int {
        $stmt = $pdo->prepare(
            'SELECT id FROM ref_sites
              WHERE site_type = ? AND is_active = 1
              ORDER BY sort_order ASC, id ASC
              LIMIT 1'
        );
        $stmt->execute([$type]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? (int)$row['id'] : null;
    };

    $warehouse  = $resolve('warehouse');
    $pos        = $resolve('pos');
    $production = $resolve('production');

    // Fallback: warehouse missing → try production → any holds_fg_stock=1 site
    if ($warehouse === null) {
        $warehouse = $production;
        if ($warehouse === null) {
            $stmt = $pdo->prepare(
                'SELECT id FROM ref_sites
                  WHERE holds_fg_stock = 1 AND is_active = 1
                  ORDER BY sort_order ASC, id ASC
                  LIMIT 1'
            );
            $stmt->execute();
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $warehouse = $row ? (int)$row['id'] : null;
        }
    }

    $cache = [
        'warehouse'  => $warehouse,
        'pos'        => $pos,
        'production' => $production,
    ];

    return $cache;
}

/**
 * Resolve the fulfilment site for an order/sale context.
 *
 * Precedence (confirmed with operator):
 *   1. $ctx['fulfilment_site_id_fk'] > 0  → per-order operator override wins
 *   2. $ctx['customer_id_fk']              → ref_customers.default_delivery_site_id_fk
 *   3. Channel default:
 *        taproom → pos site
 *        eshop   → warehouse site
 *        *       → warehouse site
 *
 * Pure reads only — no writes.
 *
 * @param array{
 *   fulfilment_site_id_fk?: int|null,
 *   customer_id_fk?: int|null,
 *   _customer_default_site_id?: int|null,
 *   channel?: string|null
 * } $ctx
 */
function resolve_fulfilment_site(PDO $pdo, array $ctx): int
{
    // ── 1. Per-order override ──────────────────────────────────────────────
    $overrideId = isset($ctx['fulfilment_site_id_fk']) ? (int)$ctx['fulfilment_site_id_fk'] : 0;
    if ($overrideId > 0) {
        return $overrideId;
    }

    // ── 2. Customer default delivery site ─────────────────────────────────
    // Short-circuit: if the caller already JOINed ref_customers and prefetched
    // the default site, use it directly — avoids the per-customer DB roundtrip.
    // _customer_default_site_id takes precedence over customer_id_fk lookup.
    $prefetchedSiteId = isset($ctx['_customer_default_site_id'])
        ? (int)$ctx['_customer_default_site_id']
        : 0;
    if ($prefetchedSiteId > 0) {
        return $prefetchedSiteId;
    }

    $customerId = isset($ctx['customer_id_fk']) ? (int)$ctx['customer_id_fk'] : 0;
    if ($customerId > 0) {
        $siteId = _fulfilment_customer_default_site($pdo, $customerId);
        if ($siteId > 0) {
            return $siteId;
        }
    }

    // ── 3. Channel default ────────────────────────────────────────────────
    $defaults = fulfilment_default_sites($pdo);
    $channel  = isset($ctx['channel']) ? (string)$ctx['channel'] : '';

    if ($channel === 'taproom') {
        return (int)($defaults['pos'] ?? $defaults['warehouse'] ?? $defaults['production']);
    }

    // eshop / wholesale / b2b / customer / null → warehouse
    return (int)($defaults['warehouse'] ?? $defaults['production']);
}

/**
 * Internal: look up ref_customers.default_delivery_site_id_fk.
 * Results cached per customer_id for the request lifetime (avoids N+1).
 *
 * @internal
 */
function _fulfilment_customer_default_site(PDO $pdo, int $customerId): int
{
    static $cache = [];
    if (array_key_exists($customerId, $cache)) {
        return $cache[$customerId];
    }

    $stmt = $pdo->prepare(
        'SELECT default_delivery_site_id_fk FROM ref_customers WHERE id = ? LIMIT 1'
    );
    $stmt->execute([$customerId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    $siteId = ($row && $row['default_delivery_site_id_fk'] !== null)
        ? (int)$row['default_delivery_site_id_fk']
        : 0;

    $cache[$customerId] = $siteId;
    return $siteId;
}

/**
 * Returns the ref_sites.site_type for the logged-in user's home site, or null
 * when the user spans all sites (admin/manager-all) and has no single home.
 *
 * THE single home-site resolver. Callers: public/modules/expeditions.php
 * (render-layer home-site highlight) and app/census-responsibility.php
 * (per-site count responsibility). Never copy this logic — call this function.
 *
 * Precedence 1 — manager_scope (set for managers, null for operators):
 *   'production' → 'production'
 *   'logistics'  → 'warehouse'
 *   'all'        → null (all-scope manager: no single home site)
 *
 * Precedence 2 — access_preset_id_fk resolved to preset_key:
 *   'production_operator' → 'production'
 *   'logistics_operator'  → 'warehouse'
 *   'marketing'           → 'pos'
 *   anything else         → null
 *
 * $user must carry 'manager_scope' and 'preset_key' keys.
 * Guarded by function_exists ONLY to permit a zero-downtime two-step deploy of the
 * move out of public/modules/expeditions.php: while the server still serves the old
 * expeditions.php (which declares this unconditionally, hoisted at compile time),
 * this conditional/runtime def yields to it (no redeclare). Once the new
 * expeditions.php (inline def removed) is deployed, this becomes the sole def.
 * Loaded via require_once (fg-stock.php → fulfilment-site.php) before any caller,
 * so the runtime definition is always in time. Once both files are deployed the
 * guard is a harmless no-op and may be removed.
 */
if (!function_exists('exp_user_home_site_type')) {
function exp_user_home_site_type(array $user): ?string
{
    // Precedence 1: manager_scope
    $scope = $user['manager_scope'] ?? null;
    if ($scope !== null) {
        if ($scope === 'production') return 'production';
        if ($scope === 'logistics')  return 'warehouse';
        // 'all' or any future value → no single home
        return null;
    }

    // Precedence 2: preset_key
    $preset = $user['preset_key'] ?? null;
    if ($preset === 'production_operator') return 'production';
    if ($preset === 'logistics_operator')  return 'warehouse';
    if ($preset === 'marketing')           return 'pos';

    // admin / viewer / manager (no manager_scope) / unmapped → no home site
    return null;
}
} // end function_exists('exp_user_home_site_type') guard
