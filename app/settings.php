<?php
declare(strict_types=1);

/**
 * app/settings.php — System-wide configuration read helper.
 *
 * CONVENTION: All ingestion, normalization, and display code that needs
 * date formats, locale preferences, or org identity values MUST read them
 * via this helper. Never hardcode date format strings, language codes, or
 * brewery name strings in PHP, JS, or parser scripts.
 *
 * This prevents the "racking date-swap" class of bug where format assumptions
 * are scattered across parsers and silently diverge from the operator's locale.
 *
 * Migration 129 populates the `system_settings` table that backs these helpers.
 * Retrofit of existing parsers is incremental — establish this helper first,
 * then migrate call-sites one by one as they are touched.
 *
 * Usage:
 *   $fmt     = date_display_format();           // e.g. 'd/m/Y'
 *   $dayFirst = date_parse_dayfirst();          // true = jj/mm/aaaa
 *   $name    = system_setting('brewery_name');  // 'La Nébuleuse'
 *   $lang    = system_setting('language');      // 'fr'
 */

require_once __DIR__ . '/db.php';

/**
 * Per-request cache so we hit the DB at most once per section per request.
 * Keys: "$section" → associative [key_name => value].
 */
$_system_settings_cache = [];

/**
 * Read a single system setting by key (within a section).
 *
 * Returns the effective value: value_text ?? value_num ?? default_text ??
 * default_num ?? $fallback. Returns $fallback when the table does not exist
 * (migration 129 not yet applied) or when the key is absent.
 *
 * @param string $key     key_name in the system_settings table
 * @param string $section section name (default 'general')
 * @param mixed  $fallback value returned when the setting cannot be resolved
 * @return mixed string|int|float|null
 */
function system_setting(string $key, string $section = 'general', mixed $fallback = null): mixed
{
    global $_system_settings_cache;

    // Load the section into cache on first access
    if (!array_key_exists($section, $_system_settings_cache)) {
        $_system_settings_cache[$section] = _system_settings_load_section($section);
    }

    $row = $_system_settings_cache[$section][$key] ?? null;
    if ($row === null) {
        return $fallback;
    }

    // Precedence: value_text > value_num > default_text > default_num > fallback
    if ($row['value_text'] !== null && $row['value_text'] !== '') {
        return $row['value_text'];
    }
    if ($row['value_num'] !== null) {
        return (float) $row['value_num'];
    }
    if ($row['default_text'] !== null && $row['default_text'] !== '') {
        return $row['default_text'];
    }
    if ($row['default_num'] !== null) {
        return (float) $row['default_num'];
    }
    return $fallback;
}

/**
 * Returns the PHP date format string for display (e.g. 'd/m/Y').
 * Falls back to 'd/m/Y' (Swiss/European default) when migration 129 is not applied.
 */
function date_display_format(): string
{
    return (string) system_setting('date_format', 'general', 'd/m/Y');
}

/**
 * Returns true when operator-entered dates should be parsed day-first (jj/mm/aaaa).
 * Falls back to true (European default) when migration 129 is not applied.
 */
function date_parse_dayfirst(): bool
{
    $v = system_setting('date_parse_dayfirst', 'general', 1.0);
    return (bool) round((float) $v);
}

/**
 * Feature flag: whether repack-event depletion is live on FG stock.
 *
 * Returns FALSE (safe default) until the operator explicitly sets
 *   system_settings.section='features', key_name='repack_depletion_live', value_num=1
 * (e.g. after the 2026-06-15 cage count). No redeploy needed to flip.
 *
 * Used in app/fg-stock.php to gate Step 5.5 in fg_stock_compute() and
 * the repack leg in fg_stock_location_snapshot().
 */
function repack_depletion_live(): bool
{
    $v = system_setting('repack_depletion_live', 'features', 0.0);
    return ((float) $v) >= 1.0;
}

/**
 * Internal: load all active rows for a section into a [key_name => row] map.
 * Returns an empty array (not an exception) when the table does not yet exist.
 */
function _system_settings_load_section(string $section): array
{
    try {
        $pdo  = maltytask_pdo();
        $stmt = $pdo->prepare(
            "SELECT key_name, value_text, value_num, default_text, default_num
               FROM system_settings
              WHERE section = ? AND is_active = 1"
        );
        $stmt->execute([$section]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $map  = [];
        foreach ($rows as $r) {
            $map[$r['key_name']] = $r;
        }
        return $map;
    } catch (Throwable) {
        // Table not yet created (migration 129 pending) — return empty so
        // callers receive their $fallback values transparently.
        return [];
    }
}
