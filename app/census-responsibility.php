<?php
declare(strict_types=1);

/**
 * app/census-responsibility.php — Canonical per-site FG census staleness + responsibility.
 *
 * THIS IS THE SINGLE SOURCE OF TRUTH for:
 *   - The staleness threshold (census_stale_days_threshold)
 *   - Per-site census status and negative-stock counts (census_site_status)
 *   - Per-site responsible users (census_responsible_users_for_site)
 *   - Month-close reminder recipients (census_month_close_recipients)
 *
 * Phase B (email reminder cron) and Phase C (dashboard tile) MUST call these
 * functions — never duplicate the staleness or responsibility logic elsewhere.
 *
 * Pure SELECTs only. No writes, no side-effects.
 */

require_once __DIR__ . '/settings.php';  // system_setting() — app/settings.php:46
require_once __DIR__ . '/fg-stock.php';  // fg_stock_location_snapshot() — app/fg-stock.php:1462

/**
 * Returns the operator-configured staleness threshold in whole days.
 *
 * Reads `fg_census_stale_days` from system_settings (section 'fulfilment').
 * Seeded to 7 by migration 444. Minimum enforced: 1 day.
 *
 * Accessor: system_setting(string $key, string $section, mixed $fallback)
 *   defined in app/settings.php:46 — returns value_text ?? value_num ??
 *   default_text ?? default_num ?? $fallback.
 */
function census_stale_days_threshold(PDO $pdo): int
{
    // system_setting() uses a per-request cache; safe to call multiple times.
    $val = system_setting('fg_census_stale_days', 'fulfilment', 7);
    return max(1, (int) $val);
}

/**
 * Returns per-site census staleness status for all active FG-stock-holding sites.
 *
 * Return shape (keyed by site_id):
 *   array<int, array{
 *     site_id:      int,
 *     name:         string,
 *     site_type:    string,           // 'production'|'warehouse'|'pos'|'consignment'
 *     last_counted: string|null,      // 'YYYY-MM-DD' date string; null if never counted
 *     days_since:   int|null,         // whole days from last_counted to today; null if never
 *     is_overdue:   bool,             // true when never counted OR days_since > threshold
 *     negatives:    int,              // count of SKUs at negative physical stock at this site
 *   }>
 *
 * Bulk-fetches last_counted in ONE query to avoid N+1.
 * Calls fg_stock_location_snapshot() once for negative-stock counts — do NOT
 * call it again in the same request (expensive multi-table scan). If the caller
 * also needs the full snapshot, call fg_stock_location_snapshot() independently
 * and cache the result at the call site.
 */
function census_site_status(PDO $pdo): array
{
    $threshold = census_stale_days_threshold($pdo);
    $today     = new DateTimeImmutable('today');

    // ── 1. Active FG-stock-holding sites ────────────────────────────────────
    $sitesStmt = $pdo->query(
        'SELECT id, name, site_type
           FROM ref_sites
          WHERE is_active = 1
            AND holds_fg_stock = 1
          ORDER BY sort_order ASC, id ASC'
    );
    $sites = $sitesStmt->fetchAll(PDO::FETCH_ASSOC);

    if ($sites === []) {
        return [];
    }

    // ── 2. Bulk last_counted per site (ONE query — no N+1) ──────────────────
    // MAX(counted_at) directly from inv_fg_stocktake, constrained to the same
    // active-row set that fg_stock_location_snapshot() uses for its anchor.
    $siteIds      = array_column($sites, 'id');
    $placeholders = implode(',', array_fill(0, count($siteIds), '?'));

    $lastStmt = $pdo->prepare(
        "SELECT location_id_fk,
                DATE(MAX(counted_at)) AS last_counted
           FROM inv_fg_stocktake
          WHERE is_active = 1
            AND counted_at IS NOT NULL
            AND location_id_fk IN ($placeholders)
          GROUP BY location_id_fk"
    );
    $lastStmt->execute($siteIds);

    $lastCounted = [];
    foreach ($lastStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $lastCounted[(int) $row['location_id_fk']] = $row['last_counted'];
    }

    // ── 3. Snapshot for negative-stock counts (called once — expensive) ──────
    // fg_stock_location_snapshot() applies the full census-date anchor model
    // plus sales, transfers, and repack flows. We only need the per-SKU qty
    // per site; we do NOT reimplement the stock math ourselves.
    $snap = fg_stock_location_snapshot($pdo);

    // Index snapshot locations by site_id for O(1) lookup.
    // $snap['locations'] is a numerically-indexed list (not keyed by site_id).
    $snapBySite = [];
    foreach ($snap['locations'] as $loc) {
        $snapBySite[(int) $loc['id']] = $loc;
    }

    // ── 4. Assemble result ───────────────────────────────────────────────────
    $result = [];
    foreach ($sites as $site) {
        $siteId = (int) $site['id'];
        $lc     = $lastCounted[$siteId] ?? null;   // 'YYYY-MM-DD' or null

        $daysSince = null;
        if ($lc !== null) {
            $dt        = new DateTimeImmutable($lc);
            $daysSince = (int) $today->diff($dt)->days;
        }

        $isOverdue = ($daysSince === null || $daysSince > $threshold);

        // Count SKUs at negative physical stock for this site.
        // qty may be negative after flows (honest signal — see fg-stock.php:2034).
        $negatives = 0;
        if (isset($snapBySite[$siteId])) {
            foreach ($snapBySite[$siteId]['rows'] as $r) {
                if (($r['qty'] ?? 0) < 0) {
                    $negatives++;
                }
            }
        }

        $result[$siteId] = [
            'site_id'      => $siteId,
            'name'         => $site['name'],
            'site_type'    => $site['site_type'],
            'last_counted' => $lc,
            'days_since'   => $daysSince,
            'is_overdue'   => $isOverdue,
            'negatives'    => $negatives,
        ];
    }

    return $result;
}

/**
 * MIRROR of exp_user_home_site_type() at public/modules/expeditions.php:3227.
 *
 * Cannot be called directly: that function lives in a render-page that executes
 * top-level code on include (not an includable lib), so it cannot be require'd.
 * This helper replicates its precedence byte-faithfully (verified against
 * expeditions.php:3227-3246 on 2026-06-29).
 *
 * Precedence (manager_scope ALWAYS wins over preset_key):
 *   1. manager_scope (ENUM('production','logistics','all','finance'), NULL for operators)
 *        'production' → 'production'
 *        'logistics'  → 'warehouse'
 *        any other non-null ('all'/'finance'/…) → null (no single home)
 *   2. only when manager_scope IS NULL — preset_key (from ref_access_presets)
 *        'production_operator' → 'production'
 *        'logistics_operator'  → 'warehouse'
 *        'marketing'           → 'pos'
 *        anything else / NULL  → null (no home site)
 *
 * TODO(arch): extract exp_user_home_site_type() into a shared app/ lib so this
 * helper and expeditions.php both call ONE home (avoids drift). Surfaced to PM.
 */
function _census_home_site_type(?string $managerScope, ?string $presetKey): ?string
{
    // Precedence 1: manager_scope
    if ($managerScope !== null) {
        if ($managerScope === 'production') return 'production';
        if ($managerScope === 'logistics')  return 'warehouse';
        return null; // 'all'/'finance'/other → no single home
    }

    // Precedence 2: preset_key (only reached when manager_scope IS NULL)
    if ($presetKey === 'production_operator') return 'production';
    if ($presetKey === 'logistics_operator')  return 'warehouse';
    if ($presetKey === 'marketing')           return 'pos';

    return null;
}

/**
 * Returns users responsible for counting stock at the given site.
 *
 * Return shape (keyed by user id):
 *   array<int, array{id: int, email: string, display_name: string}>
 *
 * Only active users with a non-empty email are returned. A user is responsible
 * for a site when their resolved home site_type (via _census_home_site_type(),
 * the mirror of expeditions.php:exp_user_home_site_type()) equals the site's
 * site_type. Admins, viewers, and 'all'/'finance'-scope managers resolve to no
 * home and are therefore never returned here.
 *
 * Consignment sites have no natural owner → empty array (explicit, per spec).
 *
 * No N+1: ONE query joins users → ref_access_presets; precedence is applied in
 * PHP via _census_home_site_type() so manager_scope reliably beats preset_key.
 * (A naive OR-SQL predicate would wrongly admit an 'all'-scope manager who
 * happens to carry an operator preset.)
 */
function census_responsible_users_for_site(PDO $pdo, int $siteId): array
{
    // Fetch site_type for this site
    $st = $pdo->prepare('SELECT site_type FROM ref_sites WHERE id = ? AND is_active = 1 LIMIT 1');
    $st->execute([$siteId]);
    $siteRow = $st->fetch(PDO::FETCH_ASSOC);
    if (!$siteRow) {
        return [];
    }

    $targetSiteType = $siteRow['site_type'];

    // Consignment sites have no natural owner — return empty explicitly.
    if ($targetSiteType === 'consignment') {
        return [];
    }

    // One query: every active emailed user + their preset_key. Filter in PHP so
    // the manager_scope-beats-preset_key precedence is preserved exactly.
    $stmt = $pdo->query(
        "SELECT u.id,
                u.email,
                COALESCE(NULLIF(u.display_name, ''), u.username) AS display_name,
                u.manager_scope,
                p.preset_key
           FROM users u
           LEFT JOIN ref_access_presets p ON p.id = u.access_preset_id_fk
          WHERE u.is_active = 1
            AND u.email IS NOT NULL
            AND u.email <> ''
          ORDER BY display_name ASC"
    );

    $result = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $homeType = _census_home_site_type(
            $row['manager_scope'] !== null ? (string) $row['manager_scope'] : null,
            $row['preset_key']    !== null ? (string) $row['preset_key']    : null
        );
        if ($homeType !== $targetSiteType) {
            continue;
        }
        $result[(int) $row['id']] = [
            'id'           => (int) $row['id'],
            'email'        => $row['email'],
            'display_name' => $row['display_name'],
        ];
    }
    return $result;
}

/**
 * Returns all managers and admins with a non-empty email address.
 *
 * Used by the month-close reminder (Phase B) to notify decision-makers
 * regardless of site. Returns users in display_name order.
 *
 * Return shape (keyed by user id):
 *   array<int, array{id: int, email: string, display_name: string}>
 */
function census_month_close_recipients(PDO $pdo): array
{
    $stmt = $pdo->query(
        "SELECT id,
                email,
                COALESCE(display_name, username) AS display_name
           FROM users
          WHERE is_active = 1
            AND role IN ('admin','manager')
            AND email IS NOT NULL
            AND email <> ''
          ORDER BY display_name ASC"
    );

    $result = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $result[(int) $row['id']] = [
            'id'           => (int) $row['id'],
            'email'        => $row['email'],
            'display_name' => $row['display_name'],
        ];
    }
    return $result;
}
