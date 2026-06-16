<?php
declare(strict_types=1);
/**
 * page-categories.php — canonical topbar category metadata accessor.
 *
 * Returns an ordered map of category_key → metadata for all six nav categories.
 * CALL this function from every consumer (topbar.php, reglages-generaux.php);
 * NEVER copy the literal map into a second file — it drifts silently.
 *
 * Icon convention: uses the same inline-text glyph style as the existing
 * tb__midx spans (emoji rendered as text). If the design system ever migrates
 * to SVG glyphs, update here and both consumers pick it up automatically.
 *
 * @return array<string, array{label: string, icon: string, order: int}>
 */
function page_categories(): array
{
    return [
        'production' => ['label' => 'Production', 'icon' => '🏭', 'order' => 10],
        'logistique' => ['label' => 'Logistique', 'icon' => '🚚', 'order' => 20],
        'qualite'    => ['label' => 'Qualité',    'icon' => '🧪', 'order' => 30],
        'finance'    => ['label' => 'Finance',    'icon' => '💰', 'order' => 40],
        'pilotage'   => ['label' => 'Pilotage',   'icon' => '📊', 'order' => 50],
        'systeme'    => ['label' => 'Système',    'icon' => '⚙️',  'order' => 60],
    ];
}
