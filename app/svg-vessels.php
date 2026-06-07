<?php
declare(strict_types=1);
/*
 * RETIRED 2026-06-07 — vessel library converged into app/svg-tanks.php (gravure-dense style).
 *
 * Do NOT add new consumers of this file. Do NOT add new functions here.
 * Scrapping backlog: remove once the BSF-exit arc (Phase 6) reaches the sb-board
 * family and no other consumer remains (grep for svg-vessels.php before deleting).
 *
 * Original public API:
 *   svg_vessel_cct(int, float, string, array): string
 *   svg_vessel_bbt(int, float, string, array): string
 *   svg_vessel_kettle(int, string, array): string
 *   svg_vessel_packaging_line(string, array): string
 *
 * All four are now implemented in app/svg-tanks.php.
 * The thin wrappers below delegate to those implementations via function_exists
 * guard — they are a zero-risk fallback for any consumer the grep may have missed.
 * They add no overhead: function_exists returns immediately if svg-tanks.php was
 * already required (which it is, by sb-board.php and sb-mother.php).
 */

require_once __DIR__ . '/svg-tanks.php';

/*
 * Internal helpers from the original svg-vessels.php.
 * _svg_uid() is now _sv_uid() in svg-tanks.php; these are dead stubs
 * kept only to satisfy any require_once that may have been cached.
 */
if (!function_exists('_svg_uid')):
function _svg_uid(string $prefix, int $number = 0): string {
    return _sv_uid($prefix, $number);
}
endif;

if (!function_exists('_cct_fill_style')):
function _cct_fill_style(string $state): array {
    return match ($state) {
        'active'       => ['color' => 'var(--cold,#2f5575)',    'opacity' => 0.72],
        'cold-crashed' => ['color' => 'var(--bbt,#2f6d99)',     'opacity' => 0.60],
        'cleaning'     => ['color' => 'var(--steel-mid,#9a8868)', 'opacity' => 0.35],
        default        => ['color' => 'none',                   'opacity' => 0.0],
    };
}
endif;

if (!function_exists('_bbt_fill_style')):
function _bbt_fill_style(string $state): array {
    return match ($state) {
        'filling'  => ['color' => 'var(--bbt,#2f6d99)', 'opacity' => 0.72],
        'ready'    => ['color' => 'var(--bbt,#2f6d99)', 'opacity' => 0.75],
        'serving'  => ['color' => 'var(--bbt,#2f6d99)', 'opacity' => 0.82],
        'cleaning' => ['color' => 'var(--steel-mid,#9a8868)', 'opacity' => 0.35],
        default    => ['color' => 'none',                'opacity' => 0.0],
    };
}
endif;

if (!function_exists('_vessel_stroke')):
function _vessel_stroke(bool $is_active): array {
    return ['stroke' => 'var(--steel-mid,#9a8868)', 'width' => '1.2'];
}
endif;

if (!function_exists('_cct_is_active')):
function _cct_is_active(string $state): bool {
    return in_array($state, ['active', 'cold-crashed', 'cleaning'], true);
}
endif;

if (!function_exists('_bbt_is_active')):
function _bbt_is_active(string $state): bool {
    return in_array($state, ['filling', 'ready', 'serving', 'cleaning'], true);
}
endif;

if (!function_exists('_vessel_title')):
function _vessel_title(string $type, int $number, string $state, array $opts): string {
    $label = strtoupper($type) . ' ' . $number;
    if ($state !== 'empty' && $state !== 'idle') { $label .= ' — ' . $state; }
    if (!empty($opts['recipe']) || !empty($opts['batch'])) {
        $parts = [];
        if (!empty($opts['recipe'])) $parts[] = $opts['recipe'];
        if (!empty($opts['batch']))  $parts[] = '#' . $opts['batch'];
        $label .= ' (' . implode(' ', $parts) . ')';
    }
    return htmlspecialchars($label, ENT_XML1, 'UTF-8');
}
endif;

/*
 * Public API delegates — all four functions are now in svg-tanks.php.
 * These are no-op stubs: if svg-tanks.php has already been required
 * (which it always is via sb-board.php/sb-mother.php), these blocks
 * are skipped by function_exists. They exist only for grep-missed consumers.
 *
 * svg_vessel_cct, svg_vessel_bbt, svg_vessel_kettle, svg_vessel_packaging_line
 * are all defined in svg-tanks.php with function_exists guards — no redeclaration.
 */
