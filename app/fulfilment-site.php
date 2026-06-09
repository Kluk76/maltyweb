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
 *       fulfilment_site_id_fk  — per-order operator override (wins over everything)
 *       customer_id_fk         — look up ref_customers.default_delivery_site_id_fk
 *       channel                — 'taproom'→pos, 'eshop'→warehouse, else→warehouse
 *
 * Three call patterns:
 *   B2B/expedié:  resolve_fulfilment_site($pdo, [
 *                   'fulfilment_site_id_fk' => $order['fulfilment_site_id_fk'],
 *                   'customer_id_fk'        => $order['customer_id_fk'],
 *                   'channel'               => $order['internal_channel'],
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
