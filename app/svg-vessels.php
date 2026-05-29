<?php
/**
 * svg-vessels.php — Mother Shell SVG vessel component library (Atom 2).
 *
 * Returns inline SVG strings (no <?xml header) for embedding in HTML.
 * All fill/stroke values use var(--token) with warm-steel fallbacks.
 * Lighting convention: light on kraft ground, upper-left key light.
 * No inline <style> blocks — all animation classes are hooks for atom 3 CSS.
 *
 * Public API (prefixed `svg_vessel_*` to avoid collision with svg-tanks.php
 * which defines svg_cct/svg_bbt for the packaging+tanks pages):
 *   svg_vessel_cct(int $number, float $fill_pct, string $state, array $opts): string
 *   svg_vessel_bbt(int $number, float $fill_pct, string $state, array $opts): string
 *   svg_vessel_kettle(int $number, string $state, array $opts): string
 *   svg_vessel_packaging_line(string $state, array $opts): string
 */

declare(strict_types=1);

/* ──────────────────────────────────────────────────────────────────────
   INTERNAL HELPERS
────────────────────────────────────────────────────────────────────── */

/**
 * Generate a short collision-resistant UID for <defs> element IDs.
 * Uses vessel type + number + a 6-char hash so multiple vessels on the
 * same page never clash on clipPath/gradient id references.
 */
function _svg_uid(string $prefix, int $number = 0): string {
    static $counter = 0;
    $counter++;
    return $prefix . $number . '_' . substr(md5($prefix . $number . $counter), 0, 6);
}

/**
 * Resolve liquid fill color and opacity for CCT based on state.
 * Returns ['color' => string, 'opacity' => float].
 */
function _cct_fill_style(string $state): array {
    return match ($state) {
        'active'       => ['color' => 'var(--cold,#2f5575)',    'opacity' => 0.72],
        'cold-crashed' => ['color' => 'var(--bbt,#2f6d99)',     'opacity' => 0.60],
        'cleaning'     => ['color' => 'var(--steel-mid,#9a8868)', 'opacity' => 0.35],
        default        => ['color' => 'none',                   'opacity' => 0.0],
    };
}

/**
 * Resolve liquid fill color and opacity for BBT based on state.
 */
function _bbt_fill_style(string $state): array {
    return match ($state) {
        // F2 fix: align 'filling' opacity to mockup baseline (occupied-BBT racking ref = 0.72)
        'filling'  => ['color' => 'var(--bbt,#2f6d99)', 'opacity' => 0.72],
        'ready'    => ['color' => 'var(--bbt,#2f6d99)', 'opacity' => 0.75],
        'serving'  => ['color' => 'var(--bbt,#2f6d99)', 'opacity' => 0.82],
        'cleaning' => ['color' => 'var(--steel-mid,#9a8868)', 'opacity' => 0.35],
        default    => ['color' => 'none',                'opacity' => 0.0],
    };
}

/**
 * Resolve outline stroke tokens. Aligned to mockup contract (board-populated.html):
 * uniform --steel-mid + width 1.2 across all states. The active/idle visual delta
 * comes from the pulsing CSS animation + fill color, NOT from stroke variation.
 * Returns ['stroke' => string, 'width' => string].
 */
function _vessel_stroke(bool $is_active): array {
    // BLOCK 2 fix: mockup uses uniform var(--steel-mid) stroke-width 1.2 for every vessel.
    // Two-tier scheme (steel-deep/2 vs steel-light/1) diverged from the operator's
    // aesthetic-locked contract. $is_active param kept for signature stability;
    // future variations should ride class+CSS, not bare stroke attrs.
    return ['stroke' => 'var(--steel-mid,#9a8868)', 'width' => '1.2'];
}

/**
 * States considered "active" (pulsing class, stronger stroke) per vessel type.
 */
function _cct_is_active(string $state): bool {
    return in_array($state, ['active', 'cold-crashed', 'cleaning'], true);
}

function _bbt_is_active(string $state): bool {
    return in_array($state, ['filling', 'ready', 'serving', 'cleaning'], true);
}

/**
 * Build a text label string for the <title> element.
 */
function _vessel_title(string $type, int $number, string $state, array $opts): string {
    $label = strtoupper($type) . ' ' . $number;
    if ($state !== 'empty' && $state !== 'idle') {
        $label .= ' — ' . $state;
    }
    if (!empty($opts['recipe']) || !empty($opts['batch'])) {
        $parts = [];
        if (!empty($opts['recipe'])) $parts[] = $opts['recipe'];
        if (!empty($opts['batch']))  $parts[] = '#' . $opts['batch'];
        $label .= ' (' . implode(' ', $parts) . ')';
    }
    return htmlspecialchars($label, ENT_XML1, 'UTF-8');
}

/* ──────────────────────────────────────────────────────────────────────
   svg_cct — Conical Cylindrical Tank (fermentation vessel)
────────────────────────────────────────────────────────────────────── */

/**
 * CCT (Conical Cylindrical Tank) — fermentation vessel.
 *
 * @param int    $number   CCT number for label (1-based)
 * @param float  $fill_pct 0.0–1.0; controls liquid fill height
 * @param string $state    'empty'|'active'|'cold-crashed'|'cleaning'
 * @param array  $opts     ['recipe'=>'EMB', 'batch'=>'244', 'compact'=>false]
 * @return string          Inline SVG, no <?xml header
 */
function svg_vessel_cct(int $number, float $fill_pct = 0.0, string $state = 'empty', array $opts = []): string {
    $fill_pct = max(0.0, min(1.0, $fill_pct));
    $compact  = !empty($opts['compact']);
    $is_active = _cct_is_active($state);

    $uid     = _svg_uid('cct', $number);
    $clip_id = 'clip_' . $uid;
    $spec_id = 'spec_' . $uid;
    $leg_id  = 'leg_'  . $uid;

    $fill    = _cct_fill_style($state);
    $outline = _vessel_stroke($is_active);

    // CCT body geometry (viewBox 0 0 80 155, matches mockup byte-for-byte)
    // Cylinder: x=12–68, top y=10, bottom y=100; Cone: apex at (40,130)
    $cyl_top  = 10;
    $cyl_bot  = 100;
    $cone_bot = 130;
    $cyl_l    = 12;
    $cyl_r    = 68;
    $cyl_h    = $cyl_bot - $cyl_top;

    // Fill band geometry (clipped to vessel polygon)
    $has_fill  = $fill_pct > 0 && $state !== 'empty';
    $empty_h   = (int) round($cyl_h * (1.0 - $fill_pct));
    $fill_y    = $cyl_top + $empty_h;
    $fill_h    = $cyl_bot - $fill_y + ($cone_bot - $cyl_bot); // extends into cone

    // Number label text contrast
    $num_fill   = $has_fill ? 'rgba(255,255,255,0.8)' : 'rgba(0,0,0,0.5)';
    $num_stroke = $has_fill ? 'rgba(0,0,0,0.3)' : 'rgba(255,255,255,0.4)';

    // Compact mode: show only number label (no recipe/batch overlay)
    $show_label = !$compact && (!empty($opts['recipe']) || !empty($opts['batch']));

    // Animation class for active states
    $svg_class = 'sb-vessel__svg sb-vessel__svg--cct';
    if ($is_active && in_array($state, ['active', 'cold-crashed'], true)) {
        $svg_class .= ' sb-vessel--pulsing';
    }

    $title = _vessel_title('CCT', $number, $state, $opts);

    ob_start(); ?>
<svg class="<?= htmlspecialchars($svg_class, ENT_QUOTES, 'UTF-8') ?>"
     viewBox="0 0 80 155"
     xmlns="http://www.w3.org/2000/svg"
     role="img"
     aria-label="<?= $title ?>">
  <title><?= $title ?></title>
  <defs>
    <clipPath id="<?= $clip_id ?>">
      <polygon points="<?= $cyl_l ?>,<?= $cyl_top ?> <?= $cyl_r ?>,<?= $cyl_top ?> <?= $cyl_r ?>,<?= $cyl_bot ?> 46,<?= $cone_bot ?> 34,<?= $cone_bot ?> <?= $cyl_l ?>,<?= $cyl_bot ?>"/>
    </clipPath>
    <!-- Left-edge specular highlight: key light from upper-left on kraft ground -->
    <linearGradient id="<?= $spec_id ?>" x1="0" y1="0" x2="1" y2="0">
      <stop offset="0%"   stop-color="rgba(255,255,255,0.16)"/>
      <stop offset="15%"  stop-color="rgba(255,255,255,0.05)"/>
      <stop offset="100%" stop-color="rgba(0,0,0,0.08)"/>
    </linearGradient>
    <!-- Leg gradient: brushed steel cylindrical legs -->
    <linearGradient id="<?= $leg_id ?>" x1="0" y1="0" x2="1" y2="0">
      <stop offset="0%"   stop-color="var(--steel-shadow,#5c4e32)"/>
      <stop offset="40%"  stop-color="var(--steel-light,#d4c4a4)" stop-opacity="0.3"/>
      <stop offset="60%"  stop-color="var(--steel-mid,#9a8868)"/>
      <stop offset="100%" stop-color="var(--steel-shadow,#5c4e32)"/>
    </linearGradient>
  </defs>

  <!-- ── Body: conical cylinder (polygon = cylinder + cone merged) ── -->
  <polygon
    points="<?= $cyl_l ?>,<?= $cyl_top ?> <?= $cyl_r ?>,<?= $cyl_top ?> <?= $cyl_r ?>,<?= $cyl_bot ?> 46,<?= $cone_bot ?> 34,<?= $cone_bot ?> <?= $cyl_l ?>,<?= $cyl_bot ?>"
    fill="var(--steel-deep,#7a6848)"
    stroke="<?= $outline['stroke'] ?>"
    stroke-width="<?= $outline['width'] ?>"/>

  <!-- Top cap (manway / top of tank) -->
  <rect x="<?= $cyl_l ?>" y="2" width="56" height="8"
    fill="var(--steel-deep,#7a6848)"
    stroke="<?= $outline['stroke'] ?>"
    stroke-width="<?= $outline['width'] ?>"/>
  <line x1="<?= $cyl_l ?>" y1="2" x2="<?= $cyl_r ?>" y2="2"
    stroke="rgba(255,255,255,0.12)" stroke-width="0.7"/>

  <!-- ── Liquid fill (clipped to body polygon) ── -->
  <?php if ($has_fill): ?>
  <rect
    x="<?= $cyl_l ?>" y="<?= $fill_y ?>"
    width="56" height="<?= $fill_h ?>"
    fill="<?= $fill['color'] ?>" opacity="<?= $fill['opacity'] ?>"
    clip-path="url(#<?= $clip_id ?>)"/>
  <?php endif ?>

  <!-- ── Specular highlight — left face, simulates rounded brushed steel ── -->
  <rect x="<?= $cyl_l ?>" y="2" width="7" height="98"
    fill="url(#<?= $spec_id ?>)"
    clip-path="url(#<?= $clip_id ?>)"/>

  <!-- ── Horizontal weld seam lines ── -->
  <line x1="13" y1="44" x2="67" y2="44"
    stroke="var(--steel-mid,#9a8868)" stroke-width="0.5" opacity="0.4"/>
  <line x1="13" y1="72" x2="67" y2="72"
    stroke="var(--steel-mid,#9a8868)" stroke-width="0.5" opacity="0.4"/>

  <!-- ── Contact shadow (soft ellipse at base) ── -->
  <ellipse cx="40" cy="150" rx="30" ry="1.6"
    fill="var(--steel-shadow,#5c4e32)" opacity="0.22"/>

  <!-- ── Three legs with brushed-steel gradient ── -->
  <path d="M 20,108 L 22,108 L 17,147 L 15,147 Z" fill="url(#<?= $leg_id ?>)"/>
  <path d="M 39,108 L 41,108 L 41.2,147 L 38.8,147 Z" fill="url(#<?= $leg_id ?>)"/>
  <path d="M 58,108 L 60,108 L 65,147 L 63,147 Z" fill="url(#<?= $leg_id ?>)"/>
  <!-- Leg feet pads -->
  <rect x="13"  y="146.5" width="6" height="2" fill="var(--steel-shadow,#5c4e32)" opacity="0.6"/>
  <rect x="36.5" y="146.5" width="6" height="2" fill="var(--steel-shadow,#5c4e32)" opacity="0.6"/>
  <rect x="61"  y="146.5" width="6" height="2" fill="var(--steel-shadow,#5c4e32)" opacity="0.6"/>

  <!-- ── Number badge ── -->
  <text x="40" y="30" text-anchor="middle"
    font-family="'JetBrains Mono',ui-monospace,monospace"
    font-size="13" font-weight="500"
    fill="<?= $num_fill ?>"
    stroke="<?= $num_stroke ?>"
    stroke-width="0.4" paint-order="stroke"><?= $number ?></text>

  <?php if ($show_label): ?>
  <!-- ── Recipe/batch overlay label (non-compact only) ── -->
  <?php
    $label_parts = [];
    if (!empty($opts['recipe'])) $label_parts[] = htmlspecialchars($opts['recipe'], ENT_XML1, 'UTF-8');
    if (!empty($opts['batch']))  $label_parts[] = '#' . htmlspecialchars((string)$opts['batch'], ENT_XML1, 'UTF-8');
    $label_text = implode(' ', $label_parts);
  ?>
  <text x="40" y="82" text-anchor="middle"
    font-family="'DM Sans',ui-sans-serif,sans-serif"
    font-size="7.5" font-weight="500"
    fill="rgba(255,255,255,0.75)"
    stroke="rgba(0,0,0,0.25)"
    stroke-width="0.3" paint-order="stroke"><?= $label_text ?></text>
  <?php endif ?>

</svg>
<?php
    return trim(ob_get_clean());
}

/* ──────────────────────────────────────────────────────────────────────
   svg_bbt — Bright Beer Tank (post-racking serving tank)
────────────────────────────────────────────────────────────────────── */

/**
 * BBT (Bright Beer Tank) — post-racking serving tank.
 *
 * @param int    $number   BBT number for label (1-based)
 * @param float  $fill_pct 0.0–1.0; controls liquid fill height
 * @param string $state    'empty'|'filling'|'ready'|'serving'|'cleaning'
 * @param array  $opts     ['recipe'=>'MOO', 'batch'=>'54', 'compact'=>false]
 * @return string          Inline SVG, no <?xml header
 */
function svg_vessel_bbt(int $number, float $fill_pct = 0.0, string $state = 'empty', array $opts = []): string {
    $fill_pct  = max(0.0, min(1.0, $fill_pct));
    $compact   = !empty($opts['compact']);
    $is_active = _bbt_is_active($state);

    $uid     = _svg_uid('bbt', $number);
    $clip_id = 'clip_' . $uid;
    $spec_id = 'spec_' . $uid;
    $leg_id  = 'leg_'  . $uid;

    $fill    = _bbt_fill_style($state);
    $outline = _vessel_stroke($is_active);

    // BBT body geometry (viewBox 0 0 80 155)
    // Cylinder: x=12–68, top y=15, bottom y=125; no cone — flat elliptic caps
    $cyl_top = 15;
    $cyl_bot = 125;
    $cyl_l   = 12;
    $cyl_r   = 68;
    $cyl_h   = $cyl_bot - $cyl_top;

    $has_fill  = $fill_pct > 0 && $state !== 'empty';
    $empty_h   = (int) round($cyl_h * (1.0 - $fill_pct));
    $fill_y    = $cyl_top + $empty_h;
    $fill_h    = $cyl_bot - $fill_y;

    $num_fill   = $has_fill ? 'rgba(255,255,255,0.8)' : 'rgba(0,0,0,0.5)';
    $num_stroke = $has_fill ? 'rgba(0,0,0,0.3)' : 'rgba(255,255,255,0.4)';

    $show_label = !$compact && (!empty($opts['recipe']) || !empty($opts['batch']));

    $svg_class = 'sb-vessel__svg sb-vessel__svg--bbt';
    if ($state === 'filling') {
        $svg_class .= ' sb-vessel--pulsing';
    }

    $title = _vessel_title('BBT', $number, $state, $opts);

    ob_start(); ?>
<svg class="<?= htmlspecialchars($svg_class, ENT_QUOTES, 'UTF-8') ?>"
     viewBox="0 0 80 155"
     xmlns="http://www.w3.org/2000/svg"
     role="img"
     aria-label="<?= $title ?>">
  <title><?= $title ?></title>
  <defs>
    <clipPath id="<?= $clip_id ?>">
      <rect x="<?= $cyl_l ?>" y="<?= $cyl_top ?>" width="56" height="<?= $cyl_h ?>"/>
    </clipPath>
    <!-- Left-edge specular highlight: key light from upper-left -->
    <linearGradient id="<?= $spec_id ?>" x1="0" y1="0" x2="1" y2="0">
      <stop offset="0%"   stop-color="rgba(255,255,255,0.14)"/>
      <stop offset="18%"  stop-color="rgba(255,255,255,0.04)"/>
      <stop offset="100%" stop-color="rgba(0,0,0,0.07)"/>
    </linearGradient>
    <!-- Leg gradient: brushed steel cylindrical legs -->
    <linearGradient id="<?= $leg_id ?>" x1="0" y1="0" x2="1" y2="0">
      <stop offset="0%"   stop-color="var(--steel-shadow,#5c4e32)"/>
      <stop offset="40%"  stop-color="var(--steel-light,#d4c4a4)" stop-opacity="0.3"/>
      <stop offset="60%"  stop-color="var(--steel-mid,#9a8868)"/>
      <stop offset="100%" stop-color="var(--steel-shadow,#5c4e32)"/>
    </linearGradient>
  </defs>

  <!-- ── Body: cylinder with elliptic caps (distinguishes BBT from CCT) ── -->
  <rect x="<?= $cyl_l ?>" y="<?= $cyl_top ?>" width="56" height="<?= $cyl_h ?>"
    fill="var(--steel-deep,#7a6848)"
    stroke="<?= $outline['stroke'] ?>"
    stroke-width="<?= $outline['width'] ?>"/>

  <!-- Top elliptic cap -->
  <ellipse cx="40" cy="<?= $cyl_top ?>" rx="28" ry="5"
    fill="var(--steel-deep,#7a6848)"
    stroke="<?= $outline['stroke'] ?>"
    stroke-width="<?= $outline['width'] ?>"/>
  <!-- Top cap inner specular ring -->
  <ellipse cx="40" cy="<?= $cyl_top ?>" rx="24" ry="3.5"
    fill="none" stroke="rgba(255,255,255,0.12)" stroke-width="0.5"/>

  <!-- Bottom elliptic cap -->
  <ellipse cx="40" cy="<?= $cyl_bot ?>" rx="28" ry="5"
    fill="var(--steel-deep,#7a6848)"
    stroke="<?= $outline['stroke'] ?>"
    stroke-width="<?= $outline['width'] ?>"/>

  <!-- ── Liquid fill (clipped to cylinder rect) ── -->
  <?php if ($has_fill): ?>
  <rect
    x="<?= $cyl_l ?>" y="<?= $fill_y ?>"
    width="56" height="<?= $fill_h ?>"
    fill="<?= $fill['color'] ?>" opacity="<?= $fill['opacity'] ?>"
    clip-path="url(#<?= $clip_id ?>)"/>
  <?php endif ?>

  <!-- ── Specular highlight — left face ── -->
  <rect x="<?= $cyl_l ?>" y="<?= $cyl_top ?>" width="7" height="<?= $cyl_h ?>"
    fill="url(#<?= $spec_id ?>)"/>

  <!-- ── Structural strap lines (2x — visually distinguishes BBT from CCT) ── -->
  <line x1="<?= $cyl_l ?>" y1="52" x2="<?= $cyl_r ?>" y2="52"
    stroke="var(--steel-mid,#9a8868)" stroke-width="0.8" opacity="0.5"/>
  <line x1="<?= $cyl_l ?>" y1="90" x2="<?= $cyl_r ?>" y2="90"
    stroke="var(--steel-mid,#9a8868)" stroke-width="0.8" opacity="0.5"/>

  <!-- ── Contact shadow ── -->
  <ellipse cx="40" cy="150" rx="30" ry="1.6"
    fill="var(--steel-shadow,#5c4e32)" opacity="0.22"/>

  <!-- ── Three legs ── -->
  <path d="M 20,128 L 22,128 L 17,147 L 15,147 Z" fill="url(#<?= $leg_id ?>)"/>
  <path d="M 39,128 L 41,128 L 41.2,147 L 38.8,147 Z" fill="url(#<?= $leg_id ?>)"/>
  <path d="M 58,128 L 60,128 L 65,147 L 63,147 Z" fill="url(#<?= $leg_id ?>)"/>
  <!-- Leg feet pads -->
  <rect x="13"   y="146.5" width="6" height="2" fill="var(--steel-shadow,#5c4e32)" opacity="0.6"/>
  <rect x="36.5" y="146.5" width="6" height="2" fill="var(--steel-shadow,#5c4e32)" opacity="0.6"/>
  <rect x="61"   y="146.5" width="6" height="2" fill="var(--steel-shadow,#5c4e32)" opacity="0.6"/>

  <!-- ── Number badge ── -->
  <text x="40" y="38" text-anchor="middle"
    font-family="'JetBrains Mono',ui-monospace,monospace"
    font-size="13" font-weight="500"
    fill="<?= $num_fill ?>"
    stroke="<?= $num_stroke ?>"
    stroke-width="0.4" paint-order="stroke"><?= $number ?></text>

  <?php if ($show_label): ?>
  <!-- ── Recipe/batch overlay label (non-compact only) ── -->
  <?php
    $label_parts = [];
    if (!empty($opts['recipe'])) $label_parts[] = htmlspecialchars($opts['recipe'], ENT_XML1, 'UTF-8');
    if (!empty($opts['batch']))  $label_parts[] = '#' . htmlspecialchars((string)$opts['batch'], ENT_XML1, 'UTF-8');
    $label_text = implode(' ', $label_parts);
  ?>
  <text x="40" y="80" text-anchor="middle"
    font-family="'DM Sans',ui-sans-serif,sans-serif"
    font-size="7.5" font-weight="500"
    fill="rgba(255,255,255,0.75)"
    stroke="rgba(0,0,0,0.25)"
    stroke-width="0.3" paint-order="stroke"><?= $label_text ?></text>
  <?php endif ?>

</svg>
<?php
    return trim(ob_get_clean());
}

/* ──────────────────────────────────────────────────────────────────────
   svg_kettle — Brewhouse kettle (brassage zone)
────────────────────────────────────────────────────────────────────── */

/**
 * Brewhouse kettle — Brasserie zone vessel silhouette.
 *
 * Squat kettle with heating jacket hatch + optional wort fill.
 * Steam animation is a CSS class hook (sb-vessel--pulsing) pointing at
 * the sb-kettle-glow sibling div — no inline animation here.
 *
 * @param int    $number  Kettle number (usually 1, supports multi-kettle)
 * @param string $state   'idle'|'mashing'|'boiling'|'whirlpool'|'transferring'
 * @param array  $opts    []
 * @return string         Inline SVG, no <?xml header
 */
function svg_vessel_kettle(int $number = 1, string $state = 'idle', array $opts = []): string {
    $uid      = _svg_uid('kettle', $number);
    $hatch_id = 'hatch_' . $uid;
    $body_id  = 'kbody_' . $uid;
    $wort_id  = 'wort_'  . $uid;

    $is_active = in_array($state, ['mashing', 'boiling', 'whirlpool', 'transferring'], true);
    $has_wort  = in_array($state, ['mashing', 'boiling', 'whirlpool', 'transferring'], true);

    // Wort fill level: mashing / boiling = full; transferring = ~40% (draining)
    $wort_top = match ($state) {
        'transferring' => 43,  // partial fill, draining
        default        => 22,  // full wort (mash/boil/whirlpool)
    };

    $svg_class = 'sb-vessel__svg sb-vessel__svg--kettle';
    if ($is_active) $svg_class .= ' sb-vessel--pulsing';

    $outline = _vessel_stroke($is_active);

    $state_label = match ($state) {
        'mashing'      => 'empâtage',
        'boiling'      => 'ébullition',
        'whirlpool'    => 'whirlpool',
        'transferring' => 'transfert',
        default        => 'vide',
    };
    $title = 'Chaudière ' . $number . ' — ' . $state_label;

    ob_start(); ?>
<svg class="<?= htmlspecialchars($svg_class, ENT_QUOTES, 'UTF-8') ?>"
     viewBox="0 0 72 80"
     xmlns="http://www.w3.org/2000/svg"
     role="img"
     aria-label="<?= htmlspecialchars($title, ENT_XML1, 'UTF-8') ?>">
  <title><?= htmlspecialchars($title, ENT_XML1, 'UTF-8') ?></title>
  <defs>
    <!-- Heating jacket hatch pattern (45° diagonal lines, ember-tinted) -->
    <pattern id="<?= $hatch_id ?>" width="5" height="5"
             patternUnits="userSpaceOnUse" patternTransform="rotate(45)">
      <line x1="0" y1="0" x2="0" y2="5"
        stroke="var(--ember,#b34428)" stroke-width="0.5" opacity="0.3"/>
    </pattern>
    <!-- Body left-edge specular gradient -->
    <linearGradient id="<?= $body_id ?>" x1="0" y1="0" x2="1" y2="0">
      <stop offset="0%"   stop-color="rgba(255,255,255,0.18)"/>
      <stop offset="18%"  stop-color="rgba(255,255,255,0.06)"/>
      <stop offset="60%"  stop-color="rgba(0,0,0,0.04)"/>
      <stop offset="100%" stop-color="rgba(0,0,0,0.12)"/>
    </linearGradient>
    <!-- Wort fill gradient (warm amber, top-to-bottom opacity) -->
    <?php if ($has_wort): ?>
    <linearGradient id="<?= $wort_id ?>" x1="0" y1="0" x2="0" y2="1">
      <stop offset="0%"   stop-color="var(--ember,#b34428)" stop-opacity="0.7"/>
      <stop offset="100%" stop-color="var(--ember,#b34428)" stop-opacity="0.45"/>
    </linearGradient>
    <?php endif ?>
  </defs>

  <!-- ── Kettle body (wide cylinder, squat proportions) ── -->
  <rect x="8" y="15" width="56" height="50" rx="1"
    fill="var(--steel-deep,#7a6848)"
    stroke="<?= $outline['stroke'] ?>"
    stroke-width="<?= $outline['width'] ?>"/>
  <!-- Body specular highlight: left 8px band -->
  <rect x="8" y="15" width="8" height="50" fill="url(#<?= $body_id ?>)" rx="1"/>

  <!-- ── Wort fill (active states only) ── -->
  <?php if ($has_wort): ?>
  <!-- BLOCK 3 fix: dropped invalid clip-path="inset(...)" (CSS notation not valid as
       SVG attr — silently ignored by every renderer). Current wort_top values keep
       fill inside body geometry; no clip needed. If geometry changes, add <clipPath>. -->
  <rect x="9" y="<?= $wort_top ?>" width="54" height="<?= 65 - $wort_top ?>"
    fill="url(#<?= $wort_id ?>)"/>
  <?php endif ?>

  <!-- ── Top rim / lid ── -->
  <rect x="4" y="10" width="64" height="7" rx="1"
    fill="var(--steel-deep,#7a6848)"
    stroke="<?= $outline['stroke'] ?>"
    stroke-width="<?= $outline['width'] ?>"/>
  <!-- Lid top highlight -->
  <line x1="5" y1="11" x2="67" y2="11"
    stroke="rgba(255,255,255,0.14)" stroke-width="0.6"/>
  <!-- Center manhole / CIP port ellipse -->
  <ellipse cx="36" cy="13" rx="8" ry="3"
    fill="var(--steel-mid,#9a8868)"
    stroke="var(--steel-shadow,#5c4e32)" stroke-width="0.5"/>
  <ellipse cx="36" cy="13" rx="5" ry="1.8"
    fill="none" stroke="rgba(255,255,255,0.15)" stroke-width="0.4"/>

  <!-- ── Heating jacket hatch (lower third of body) ── -->
  <rect x="8" y="53" width="56" height="12"
    fill="url(#<?= $hatch_id ?>)" stroke="none"/>
  <rect x="8" y="53" width="56" height="12"
    fill="none"
    stroke="var(--steel-mid,#9a8868)" stroke-width="0.5"/>

  <!-- ── Pressure gauge (left side) ── -->
  <rect x="1" y="30" width="6" height="3.5"
    fill="var(--steel-mid,#9a8868)"
    stroke="var(--steel-shadow,#5c4e32)" stroke-width="0.3"/>
  <circle cx="-1.5" cy="31.75" r="3"
    fill="var(--steel,#b8a684)"
    stroke="var(--steel-shadow,#5c4e32)" stroke-width="0.4"/>
  <circle cx="-1.5" cy="31.75" r="2.2"
    fill="rgba(245,235,220,0.92)"
    stroke="var(--steel-mid,#9a8868)" stroke-width="0.2"/>
  <!-- Gauge needle angled by state -->
  <line x1="-1.5" y1="31.75" x2="-1.5" y2="30.0"
    stroke="var(--ember,#b34428)" stroke-width="0.5"
    transform="rotate(<?= $is_active ? '40' : '-30' ?>, -1.5, 31.75)"/>
  <circle cx="-1.5" cy="31.75" r="0.45" fill="var(--steel-shadow,#5c4e32)"/>

  <!-- ── Outlet pipe (right side, goes to whirlpool/transfer) ── -->
  <rect x="64" y="57" width="8" height="2.5"
    fill="var(--steel-mid,#9a8868)"
    stroke="var(--steel-shadow,#5c4e32)" stroke-width="0.3"/>
  <rect x="70" y="55" width="2" height="18"
    fill="var(--steel-mid,#9a8868)"
    stroke="var(--steel-shadow,#5c4e32)" stroke-width="0.3"/>

  <!-- ── Four legs ── -->
  <rect x="14" y="65" width="4" height="12" rx="0.5"
    fill="var(--steel-mid,#9a8868)"
    stroke="var(--steel-shadow,#5c4e32)" stroke-width="0.3"/>
  <rect x="27" y="65" width="4" height="12" rx="0.5"
    fill="var(--steel-mid,#9a8868)"
    stroke="var(--steel-shadow,#5c4e32)" stroke-width="0.3"/>
  <rect x="45" y="65" width="4" height="12" rx="0.5"
    fill="var(--steel-mid,#9a8868)"
    stroke="var(--steel-shadow,#5c4e32)" stroke-width="0.3"/>
  <rect x="58" y="65" width="4" height="12" rx="0.5"
    fill="var(--steel-mid,#9a8868)"
    stroke="var(--steel-shadow,#5c4e32)" stroke-width="0.3"/>

  <!-- ── Contact shadow ── -->
  <ellipse cx="36" cy="78" rx="28" ry="1.5"
    fill="var(--steel-shadow,#5c4e32)" opacity="0.25"/>

  <?php if ($number > 1): ?>
  <!-- Kettle number label (shown when > 1 for multi-kettle layout) -->
  <text x="36" y="50" text-anchor="middle"
    font-family="'JetBrains Mono',ui-monospace,monospace"
    font-size="11" font-weight="500"
    fill="<?= $has_wort ? 'rgba(255,255,255,0.75)' : 'rgba(0,0,0,0.45)' ?>"
    stroke="<?= $has_wort ? 'rgba(0,0,0,0.25)' : 'rgba(255,255,255,0.35)' ?>"
    stroke-width="0.4" paint-order="stroke"><?= $number ?></text>
  <?php endif ?>

</svg>
<?php
    return trim(ob_get_clean());
}

/* ──────────────────────────────────────────────────────────────────────
   svg_packaging_line — Conditionnement zone conveyor illustration
────────────────────────────────────────────────────────────────────── */

/**
 * Packaging line — Conditionnement zone horizontal conveyor illustration.
 *
 * Horizontal strip: two end rollers + conveyor belt + filler heads.
 * When running: belt gets class sb-vessel--pulsing; atom 3 CSS animates
 * the conveyor motion lines via CSS @keyframes sb-conveyor translateX.
 *
 * @param string $state  'idle'|'running'|'changeover'|'maintenance'
 * @param array  $opts   []
 * @return string        Inline SVG, no <?xml header
 */
function svg_vessel_packaging_line(string $state = 'idle', array $opts = []): string {
    $uid     = _svg_uid('pkg', 0);
    $grad_id = 'pkggrad_' . $uid;

    $is_running  = $state === 'running';
    $is_maint    = $state === 'maintenance';
    $is_change   = $state === 'changeover';

    // Belt color signal by state
    $belt_fill   = 'var(--bg-side,#dcc9a4)';
    $belt_stroke = 'var(--hairline-2,#a08060)';
    $line_color  = 'var(--hop,#567020)';
    $line_opacity = '0.4';

    if ($is_maint) {
        $line_color   = 'var(--ember,#b34428)';
        $line_opacity = '0.3';
    } elseif ($is_change) {
        $line_color   = 'var(--oak,#8b5e2a)';
        $line_opacity = '0.35';
    }

    $svg_class = 'sb-vessel__svg sb-vessel__svg--packaging';
    if ($is_running) $svg_class .= ' sb-vessel--pulsing';

    $state_label = match ($state) {
        'running'     => 'en cours',
        'changeover'  => 'changement de format',
        'maintenance' => 'maintenance',
        default       => 'arrêt',
    };
    $title = 'Ligne de conditionnement — ' . $state_label;

    // Filler head bottles: active state shows filled bottles
    $bottle_fill_1 = $is_running ? 'var(--bbt,#2f6d99)' : 'var(--steel-deep,#7a6848)';
    $bottle_op_1   = $is_running ? '0.7' : '0.3';
    $bottle_fill_2 = $is_running ? 'var(--bbt,#2f6d99)' : 'var(--steel-deep,#7a6848)';
    $bottle_op_2   = $is_running ? '0.7' : '0.3';
    $bottle_fill_3 = $is_running ? 'var(--bbt,#2f6d99)' : 'var(--steel-deep,#7a6848)';
    $bottle_op_3   = '0.4';

    ob_start(); ?>
<svg class="<?= htmlspecialchars($svg_class, ENT_QUOTES, 'UTF-8') ?>"
     viewBox="0 0 200 60"
     xmlns="http://www.w3.org/2000/svg"
     role="img"
     aria-label="<?= htmlspecialchars($title, ENT_XML1, 'UTF-8') ?>">
  <title><?= htmlspecialchars($title, ENT_XML1, 'UTF-8') ?></title>
  <defs>
    <linearGradient id="<?= $grad_id ?>" x1="0" y1="0" x2="0" y2="1">
      <stop offset="0%"   stop-color="var(--steel-mid,#9a8868)"/>
      <stop offset="100%" stop-color="var(--steel-shadow,#5c4e32)"/>
    </linearGradient>
  </defs>

  <!-- ── Filler heads (3 vertical forms above belt) ── -->
  <rect x="70"  y="0" width="12" height="22" rx="1"
    fill="var(--steel-deep,#7a6848)"
    stroke="var(--steel-mid,#9a8868)" stroke-width="0.8"/>
  <rect x="90"  y="0" width="12" height="22" rx="1"
    fill="var(--steel-deep,#7a6848)"
    stroke="var(--steel-mid,#9a8868)" stroke-width="0.8"/>
  <rect x="110" y="0" width="12" height="22" rx="1"
    fill="var(--steel-deep,#7a6848)"
    stroke="var(--steel-mid,#9a8868)" stroke-width="0.8"/>
  <!-- Filler nozzle stems -->
  <line x1="76"  y1="22" x2="76"  y2="28" stroke="var(--steel-mid,#9a8868)" stroke-width="0.8"/>
  <line x1="96"  y1="22" x2="96"  y2="28" stroke="var(--steel-mid,#9a8868)" stroke-width="0.8"/>
  <line x1="116" y1="22" x2="116" y2="28" stroke="var(--steel-mid,#9a8868)" stroke-width="0.8"/>

  <!-- ── Conveyor belt body ── -->
  <rect x="5" y="32" width="190" height="14" rx="1"
    fill="<?= $belt_fill ?>"
    stroke="<?= $belt_stroke ?>" stroke-width="0.8"/>

  <!-- ── Belt motion lines (animated by atom 3 CSS when running) ── -->
  <g class="<?= $is_running ? 'sb-conveyor-lines' : 'sb-conveyor-lines sb-conveyor-lines--idle' ?>">
    <?php for ($x = 10; $x <= 190; $x += 15): ?>
    <line x1="<?= $x ?>" y1="33" x2="<?= $x ?>" y2="45"
      stroke="<?= $line_color ?>" stroke-width="0.8" opacity="<?= $line_opacity ?>"/>
    <?php endfor ?>
  </g>

  <!-- ── End rollers ── -->
  <circle cx="8"   cy="39" r="6"
    fill="var(--steel-mid,#9a8868)"
    stroke="var(--steel-shadow,#5c4e32)" stroke-width="0.6"/>
  <circle cx="192" cy="39" r="6"
    fill="var(--steel-mid,#9a8868)"
    stroke="var(--steel-shadow,#5c4e32)" stroke-width="0.6"/>

  <!-- ── Bottles/cans on belt (state-colored) ── -->
  <rect x="30" y="28" width="8" height="16" rx="1"
    fill="<?= $bottle_fill_1 ?>" opacity="<?= $bottle_op_1 ?>"
    stroke="var(--steel-mid,#9a8868)" stroke-width="0.4"/>
  <rect x="46" y="28" width="8" height="16" rx="1"
    fill="<?= $bottle_fill_2 ?>" opacity="<?= $bottle_op_2 ?>"
    stroke="var(--steel-mid,#9a8868)" stroke-width="0.4"/>
  <rect x="62" y="28" width="8" height="16" rx="1"
    fill="<?= $bottle_fill_3 ?>" opacity="<?= $bottle_op_3 ?>"
    stroke="var(--steel-mid,#9a8868)" stroke-width="0.4"/>

  <!-- ── Contact shadow ── -->
  <ellipse cx="100" cy="48" rx="90" ry="2"
    fill="var(--steel-shadow,#5c4e32)" opacity="0.12"/>

</svg>
<?php
    return trim(ob_get_clean());
}
