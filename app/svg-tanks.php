<?php
declare(strict_types=1);

/*
 * svg-tanks.php — CCT / BBT SVG components, Variante B "Gravure dense" engraving style.
 *
 * Visual spec: public/_design/sketch-vessels-v2.html (ids *_vb), operator-approved 2026-06-07.
 * Style: kraft-paper fill, oak-deep ink outlines, bilateral edge-contour strokes,
 *        dense cross-hatch on cone/dome/cap (3.5px pattern), weld/strap shadow strips,
 *        center vertical hatch (3.8px, op 0.28), fill-level as tinted rect (op 0.14-0.17)
 *        UNDER hatching + dashed surface line, outline hierarchy (body 1.8-2.0 / structural 1.1).
 *        State accents: --hop ferment, --cold cold-crash, --ember maintenance.
 *
 * API — identical to the original (API FREEZE — no signature changes):
 *   svg_cct(float $fillRatio, string $stateClass, int $number, string $beer,
 *           string $variant, array $metrics): string
 *   svg_bbt(float $fillRatio, string $stateClass, int $number, string $beer): string
 *
 * Uid mechanism: 'cct'|'bbt' + $number + '_' + 6-char md5(prefix+number+microtime)
 *   → same as before, each call produces a unique 9-14 char prefix for all <defs> IDs.
 *   The per-tank microtime(true) call combined with PHP's single-threaded sequential
 *   rendering ensures no id collision when 10+ vessels render on one page.
 *
 * Colors: all via var(--token, #fallback) — no bare literals.
 * No inline <style>. Animation class "ferment-bubble" is a CSS hook only.
 */

if (!function_exists('svg_cct')):
function svg_cct(
    float $fillRatio,
    string $stateClass = '',
    int $number = 0,
    string $beer = '',
    string $variant = 'fill',
    array $metrics = []
): string {
    $fillRatio = max(0.0, min(1.0, $fillRatio));

    /* ── Geometry (unchanged from original — viewBox 0 0 80 155) ── */
    $cylTop    = 10;   /* top of cylinder body */
    $cylBot    = 100;  /* bottom of cylinder / top of cone */
    $coneBot   = 130;  /* cone tip row */
    $cylLeft   = 12;
    $cylRight  = 68;
    $conePoint = 40;   /* cone tip x */
    $cylH      = $cylBot - $cylTop;   /* 90 */
    $capTop    = 2;    /* top cap starts at y=2 */
    $capH      = 8;    /* cap height */

    /* ── State detection ── */
    $isMaint = str_contains($stateClass, 'maint');
    $isCold  = str_contains($stateClass, 'cold');
    $isFerm  = str_contains($stateClass, 'ferment');

    /* ── Fill level geometry ── */
    $emptyH = (int) round($cylH * (1.0 - $fillRatio));
    $fillY  = $cylTop + $emptyH;
    $fillH  = $cylBot - $fillY;

    /* ── Fill tint color for the liquid rect (under hatching) ── */
    /* Calibration 2026-06-07: raised from 0.15-0.17 to 0.34-0.35 for legibility. */
    /* Hatch strokes render on top — engraving feel preserved. */
    if ($isMaint) {
        $tintColor = 'var(--tank-empty,#cfc6b2)';
        $tintOp    = 0.22;
    } elseif ($isCold) {
        $tintColor = 'var(--cold,#2f5575)';
        $tintOp    = 0.35;
    } elseif ($isFerm) {
        $tintColor = 'var(--hop,#567020)';
        $tintOp    = 0.34;
    } else {
        $tintColor = 'none';
        $tintOp    = 0.0;
    }

    /* ── Number / text color per state ── */
    if ($isMaint) {
        $numColor = 'var(--ember,#b34428)';
        $numOp    = '0.55';
    } elseif ($isCold) {
        $numColor = 'var(--cold,#2f5575)';
        $numOp    = '0.75';
    } elseif ($isFerm) {
        $numColor = 'var(--hop,#567020)';
        $numOp    = '0.85';
    } else {
        /* empty / neutral */
        $numColor = 'var(--oak-deep,#5a3a12)';
        $numOp    = '0.45';
    }

    /* ── Contact shadow color per state ── */
    if ($isCold) {
        $shadowFill = 'rgba(47,85,117,0.15)';
    } elseif ($isFerm) {
        $shadowFill = 'rgba(86,112,32,0.20)';
    } else {
        $shadowFill = 'rgba(90,58,18,0.12)';
    }

    /* ── Collision-resistant uid for <defs> elements ── */
    $uid    = 'cct' . $number . '_' . substr(md5((string)microtime(true) . $number), 0, 6);
    $clipId = 'clip_'  . $uid;
    $capClipId  = 'clipcap_'  . $uid;
    $coneClipId = 'clipcone_' . $uid;
    $pxId   = 'px_'    . $uid;    /* center vertical hatch pattern */
    $pdId   = 'pd_'    . $uid;    /* dense cross-hatch pattern */
    $pmId   = 'pm_'    . $uid;    /* maintenance ember cross-hatch */

    /* ── Body outline coordinates (reused multiple times) ── */
    $bodyPts = "{$cylLeft},{$cylTop} {$cylRight},{$cylTop} {$cylRight},{$cylBot} " .
               ($conePoint+6) . ",{$coneBot} " . ($conePoint-6) . ",{$coneBot} {$cylLeft},{$cylBot}";

    ob_start(); ?>
<svg class="tank-svg" viewBox="0 0 80 155" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="CCT <?= $number ?>">
  <defs>
    <!-- Body clip (cylinder + cone) -->
    <clipPath id="<?= $clipId ?>">
      <polygon points="<?= $bodyPts ?>"/>
    </clipPath>
    <!-- Top cap clip -->
    <clipPath id="<?= $capClipId ?>">
      <rect x="<?= $cylLeft ?>" y="<?= $capTop ?>" width="<?= $cylRight - $cylLeft ?>" height="<?= $capH ?>"/>
    </clipPath>
    <!-- Cone-only clip -->
    <clipPath id="<?= $coneClipId ?>">
      <polygon points="<?= $cylLeft ?>,<?= $cylBot ?> <?= $cylRight ?>,<?= $cylBot ?> <?= $conePoint+6 ?>,<?= $coneBot ?> <?= $conePoint-6 ?>,<?= $coneBot ?>"/>
    </clipPath>
    <!-- Center sparse vertical hatch (3.8px, sw 0.55, op 0.28) -->
    <pattern id="<?= $pxId ?>" x="0" y="0" width="3.8" height="3.8" patternUnits="userSpaceOnUse">
      <line x1="1.9" y1="0" x2="1.9" y2="3.8" stroke="var(--oak-deep,#5a3a12)" stroke-width="0.55" opacity="0.28"/>
    </pattern>
    <?php if ($isMaint): ?>
    <!-- Maintenance: ember diagonal cross-hatch (6px) -->
    <pattern id="<?= $pmId ?>" x="0" y="0" width="6" height="6" patternUnits="userSpaceOnUse">
      <line x1="0" y1="0" x2="6" y2="6" stroke="var(--ember,#b34428)" stroke-width="0.5" opacity="0.25"/>
      <line x1="6" y1="0" x2="0" y2="6" stroke="var(--ember,#b34428)" stroke-width="0.5" opacity="0.25"/>
    </pattern>
    <?php else: ?>
    <!-- Dense cross-hatch for shadow zones (3.5px, sw 0.58, op 0.52) -->
    <pattern id="<?= $pdId ?>" x="0" y="0" width="3.5" height="3.5" patternUnits="userSpaceOnUse">
      <line x1="0" y1="0" x2="3.5" y2="3.5" stroke="var(--oak-deep,#5a3a12)" stroke-width="0.58" opacity="0.52"/>
      <line x1="3.5" y1="0" x2="0" y2="3.5" stroke="var(--oak-deep,#5a3a12)" stroke-width="0.58" opacity="0.52"/>
    </pattern>
    <?php endif ?>
  </defs>

  <!-- Contact shadow -->
  <ellipse cx="40" cy="152" rx="28" ry="2" fill="<?= $shadowFill ?>"/>

  <!-- Paper body fill (no stroke — outlines rendered on top) -->
  <polygon points="<?= $bodyPts ?>" fill="var(--bg,#f1e8d4)" stroke="none"/>
  <rect x="<?= $cylLeft ?>" y="<?= $capTop ?>" width="<?= $cylRight - $cylLeft ?>" height="<?= $capH ?>"
    fill="var(--bg,#f1e8d4)" stroke="none"/>

  <?php if ($fillRatio > 0 && !$isMaint): ?>
  <!-- Beer fill tint — ON TOP of paper fill, UNDER hatching (op 0.32-0.35) -->
  <!-- Calibration 2026-06-07: moved after paper fill so tint is actually visible -->
  <rect x="<?= $cylLeft ?>" y="<?= $fillY ?>"
    width="<?= $cylRight - $cylLeft ?>" height="<?= $fillH + ($coneBot - $cylBot) ?>"
    fill="<?= $tintColor ?>" opacity="<?= $tintOp ?>"
    clip-path="url(#<?= $clipId ?>)"/>
  <?php endif ?>

  <!-- ── HATCHING LAYER ── -->
  <?php if ($isMaint): ?>
  <!-- Maintenance: full ember diagonal cross-hatch over entire body -->
  <rect x="<?= $cylLeft ?>" y="<?= $capTop ?>" width="<?= $cylRight - $cylLeft ?>" height="<?= $coneBot - $capTop ?>"
    fill="url(#<?= $pmId ?>)" clip-path="url(#<?= $clipId ?>)"/>
  <rect x="<?= $cylLeft ?>" y="<?= $capTop ?>" width="<?= $cylRight - $cylLeft ?>" height="<?= $capH ?>"
    fill="url(#<?= $pmId ?>)"/>
  <?php else: ?>
  <!-- Center sparse vertical hatch (light center band, left of center) -->
  <rect x="21" y="<?= $cylTop ?>" width="30" height="<?= $cylH ?>"
    fill="url(#<?= $pxId ?>)" clip-path="url(#<?= $clipId ?>)"/>

  <!-- Top cap cross-hatch -->
  <rect x="<?= $cylLeft ?>" y="<?= $capTop ?>" width="<?= $cylRight - $cylLeft ?>" height="<?= $capH ?>"
    fill="url(#<?= $pdId ?>)" clip-path="url(#<?= $capClipId ?>)"/>

  <!-- Top cap edge contour — left side -->
  <line x1="13.5" y1="<?= $capTop + 0.5 ?>" x2="13.5" y2="<?= $cylTop + 0.5 ?>" stroke="var(--oak-deep,#5a3a12)" stroke-width="0.6" opacity="0.50"/>
  <line x1="15.0" y1="<?= $capTop ?>"       x2="15.1" y2="<?= $cylTop + 0.5 ?>" stroke="var(--oak-deep,#5a3a12)" stroke-width="0.55" opacity="0.45"/>
  <line x1="16.6" y1="<?= $capTop + 0.5 ?>" x2="16.5" y2="<?= $cylTop + 0.5 ?>" stroke="var(--oak-deep,#5a3a12)" stroke-width="0.50" opacity="0.40"/>
  <!-- Top cap edge contour — right side -->
  <line x1="66.2" y1="<?= $capTop ?>"       x2="66.3" y2="<?= $cylTop + 0.5 ?>" stroke="var(--oak-deep,#5a3a12)" stroke-width="0.62" opacity="0.55"/>
  <line x1="64.5" y1="<?= $capTop + 0.5 ?>" x2="64.4" y2="<?= $cylTop + 0.5 ?>" stroke="var(--oak-deep,#5a3a12)" stroke-width="0.58" opacity="0.50"/>
  <line x1="62.8" y1="<?= $capTop ?>"       x2="62.9" y2="<?= $cylTop + 0.5 ?>" stroke="var(--oak-deep,#5a3a12)" stroke-width="0.53" opacity="0.44"/>

  <!-- Cone dense cross-hatch -->
  <rect x="<?= $cylLeft ?>" y="<?= $cylBot ?>" width="<?= $cylRight - $cylLeft ?>" height="<?= $coneBot - $cylBot ?>"
    fill="url(#<?= $pdId ?>)" clip-path="url(#<?= $coneClipId ?>)"/>

  <!-- Strap-band shadow strips below each weld seam (cross-hatch) -->
  <rect x="13" y="44" width="54" height="10" fill="url(#<?= $pdId ?>)" clip-path="url(#<?= $clipId ?>)" opacity="0.75"/>
  <rect x="13" y="72" width="54" height="10" fill="url(#<?= $pdId ?>)" clip-path="url(#<?= $clipId ?>)" opacity="0.65"/>

  <!-- ── LEFT EDGE CONTOUR BAND (4 strokes following left silhouette) ── -->
  <!-- Cylinder left -->
  <line x1="13.5" y1="11"  x2="13.5" y2="<?= $cylBot ?>" stroke="var(--oak-deep,#5a3a12)" stroke-width="0.60" opacity="0.55"/>
  <line x1="15.1" y1="10.5" x2="15.0" y2="<?= $cylBot - 0.5 ?>" stroke="var(--oak-deep,#5a3a12)" stroke-width="0.55" opacity="0.52"/>
  <line x1="16.6" y1="11"  x2="16.7" y2="<?= $cylBot ?>" stroke="var(--oak-deep,#5a3a12)" stroke-width="0.55" opacity="0.48"/>
  <line x1="18.2" y1="10.5" x2="18.1" y2="<?= $cylBot - 0.5 ?>" stroke="var(--oak-deep,#5a3a12)" stroke-width="0.50" opacity="0.42"/>
  <!-- Left cone diagonal -->
  <line x1="13.5" y1="<?= $cylBot ?>" x2="34.5" y2="<?= $coneBot ?>" stroke="var(--oak-deep,#5a3a12)" stroke-width="0.60" opacity="0.55"/>
  <line x1="15.2" y1="<?= $cylBot ?>" x2="36.0" y2="<?= $coneBot ?>" stroke="var(--oak-deep,#5a3a12)" stroke-width="0.55" opacity="0.50"/>
  <line x1="16.8" y1="<?= $cylBot ?>" x2="37.5" y2="<?= $coneBot ?>" stroke="var(--oak-deep,#5a3a12)" stroke-width="0.50" opacity="0.44"/>
  <line x1="18.3" y1="<?= $cylBot ?>" x2="38.8" y2="<?= $coneBot ?>" stroke="var(--oak-deep,#5a3a12)" stroke-width="0.50" opacity="0.38"/>

  <!-- ── RIGHT EDGE CONTOUR BAND (5 strokes following right silhouette) ── -->
  <!-- Cylinder right -->
  <line x1="66.2" y1="11"  x2="66.3" y2="<?= $cylBot ?>" stroke="var(--oak-deep,#5a3a12)" stroke-width="0.65" opacity="0.60"/>
  <line x1="64.5" y1="10.5" x2="64.4" y2="<?= $cylBot - 0.5 ?>" stroke="var(--oak-deep,#5a3a12)" stroke-width="0.62" opacity="0.57"/>
  <line x1="62.9" y1="11"  x2="63.0" y2="<?= $cylBot ?>" stroke="var(--oak-deep,#5a3a12)" stroke-width="0.58" opacity="0.53"/>
  <line x1="61.2" y1="10.5" x2="61.3" y2="<?= $cylBot - 0.5 ?>" stroke="var(--oak-deep,#5a3a12)" stroke-width="0.55" opacity="0.47"/>
  <line x1="59.6" y1="11"  x2="59.5" y2="<?= $cylBot ?>" stroke="var(--oak-deep,#5a3a12)" stroke-width="0.50" opacity="0.40"/>
  <!-- Right cone diagonal -->
  <line x1="66.5" y1="<?= $cylBot ?>" x2="45.5" y2="<?= $coneBot ?>" stroke="var(--oak-deep,#5a3a12)" stroke-width="0.65" opacity="0.60"/>
  <line x1="64.8" y1="<?= $cylBot ?>" x2="44.0" y2="<?= $coneBot ?>" stroke="var(--oak-deep,#5a3a12)" stroke-width="0.60" opacity="0.55"/>
  <line x1="63.2" y1="<?= $cylBot ?>" x2="42.5" y2="<?= $coneBot ?>" stroke="var(--oak-deep,#5a3a12)" stroke-width="0.55" opacity="0.48"/>
  <line x1="61.5" y1="<?= $cylBot ?>" x2="41.2" y2="<?= $coneBot ?>" stroke="var(--oak-deep,#5a3a12)" stroke-width="0.50" opacity="0.40"/>

  <!-- Weld seam lines (structural 1.1) -->
  <line x1="<?= $cylLeft ?>" y1="44" x2="<?= $cylRight ?>" y2="44" stroke="var(--oak-deep,#5a3a12)" stroke-width="1.1" opacity="0.55"/>
  <line x1="<?= $cylLeft ?>" y1="72" x2="<?= $cylRight ?>" y2="72" stroke="var(--oak-deep,#5a3a12)" stroke-width="1.1" opacity="0.55"/>

  <?php if ($fillRatio > 0): ?>
  <!-- Fill surface dashed line — calibrated 2026-06-07: sw 1.4, op 0.90, state color -->
  <line x1="<?= $cylLeft ?>" y1="<?= $fillY ?>" x2="<?= $cylRight ?>" y2="<?= $fillY ?>"
    stroke="<?= $tintColor ?>" stroke-width="1.4" opacity="0.90" stroke-dasharray="3,2"/>
  <!-- Meniscus ticks where surface line meets walls -->
  <line x1="<?= $cylLeft ?>"     y1="<?= $fillY - 2 ?>" x2="<?= $cylLeft ?>"     y2="<?= $fillY + 2 ?>"
    stroke="<?= $tintColor ?>" stroke-width="1.2" opacity="0.85" stroke-linecap="round"/>
  <line x1="<?= $cylRight ?>"    y1="<?= $fillY - 2 ?>" x2="<?= $cylRight ?>"    y2="<?= $fillY + 2 ?>"
    stroke="<?= $tintColor ?>" stroke-width="1.2" opacity="0.85" stroke-linecap="round"/>
  <?php endif ?>

  <?php if ($isFerm && $fillRatio > 0): ?>
  <!-- Fermentation: CO2 bubble column (5 static circles, animation class as enhancement) -->
  <!-- Static at r 0.9-1.4, op 0.55-0.70 → readable in grayscale even with reduced-motion -->
  <circle cx="32" cy="<?= max($fillY + 8,  70) ?>" r="1.4" fill="var(--hop,#567020)" opacity="0.62"/>
  <circle cx="48" cy="<?= max($fillY + 20, 84) ?>" r="1.1" fill="var(--hop,#567020)" opacity="0.58"/>
  <circle cx="38" cy="<?= max($fillY + 14, 78) ?>" r="0.9" fill="var(--hop,#567020)" opacity="0.55"/>
  <circle cx="56" cy="<?= max($fillY + 5,  64) ?>" r="1.2" fill="var(--hop,#567020)" opacity="0.60"/>
  <circle cx="25" cy="<?= max($fillY + 25, 88) ?>" r="1.0" fill="var(--hop,#567020)" opacity="0.55"/>
  <!-- Animated class on top as enhancement (CSS prefers-reduced-motion aware) -->
  <circle cx="32" cy="<?= max($fillY + 8,  70) ?>" r="1.4" class="ferment-bubble" fill="var(--hop,#567020)" opacity="0.65"/>
  <circle cx="48" cy="<?= max($fillY + 20, 84) ?>" r="1.1" class="ferment-bubble" fill="var(--hop,#567020)" opacity="0.58" style="animation-delay:1.1s"/>
  <circle cx="56" cy="<?= max($fillY + 5,  64) ?>" r="1.2" class="ferment-bubble" fill="var(--hop,#567020)" opacity="0.60" style="animation-delay:2.0s"/>
  <?php endif ?>

  <?php if ($isCold && $fillRatio > 0): ?>
  <!-- Cold crash: snowflake glyph (✻ 6-arm asterisk, ~8px, next to tank number zone) -->
  <!-- Grayscale-safe: distinct shape from bubbles, not just color change -->
  <g transform="translate(58,26)" opacity="0.80" stroke="var(--cold,#2f5575)" stroke-width="1.1" stroke-linecap="round">
    <line x1="0" y1="-4.5" x2="0"    y2="4.5"/>
    <line x1="-3.9" y1="-2.25" x2="3.9" y2="2.25"/>
    <line x1="-3.9" y1="2.25"  x2="3.9" y2="-2.25"/>
    <!-- Short cross-arms at each tip (30°) -->
    <line x1="-1.2" y1="-3.5" x2="0"    y2="-4.5"/>
    <line x1="1.2"  y1="-3.5" x2="0"    y2="-4.5"/>
    <line x1="-1.2" y1="3.5"  x2="0"    y2="4.5"/>
    <line x1="1.2"  y1="3.5"  x2="0"    y2="4.5"/>
    <line x1="-4.5" y1="-1.0" x2="-3.9" y2="-2.25"/>
    <line x1="-4.5" y1="0"    x2="-3.9" y2="-2.25"/>
  </g>
  <!-- Frost ticks along liquid zone walls (2 small horizontal ticks, each side) -->
  <line x1="<?= $cylLeft ?>"   y1="<?= min($fillY + 14, $cylBot - 5) ?>" x2="<?= $cylLeft + 4 ?>"  y2="<?= min($fillY + 14, $cylBot - 5) ?>"
    stroke="var(--cold,#2f5575)" stroke-width="0.8" opacity="0.60"/>
  <line x1="<?= $cylRight ?>"  y1="<?= min($fillY + 14, $cylBot - 5) ?>" x2="<?= $cylRight - 4 ?>" y2="<?= min($fillY + 14, $cylBot - 5) ?>"
    stroke="var(--cold,#2f5575)" stroke-width="0.8" opacity="0.60"/>
  <line x1="<?= $cylLeft ?>"   y1="<?= min($fillY + 28, $cylBot - 3) ?>" x2="<?= $cylLeft + 3 ?>"  y2="<?= min($fillY + 28, $cylBot - 3) ?>"
    stroke="var(--cold,#2f5575)" stroke-width="0.7" opacity="0.48"/>
  <line x1="<?= $cylRight ?>"  y1="<?= min($fillY + 28, $cylBot - 3) ?>" x2="<?= $cylRight - 3 ?>" y2="<?= min($fillY + 28, $cylBot - 3) ?>"
    stroke="var(--cold,#2f5575)" stroke-width="0.7" opacity="0.48"/>
  <?php endif ?>
  <?php endif /* end !$isMaint else */ ?>

  <!-- ── OUTLINES (on top of all hatching) ── -->
  <!-- Main body (1.8) -->
  <polygon points="<?= $bodyPts ?>"
    fill="none" stroke="var(--oak-deep,#5a3a12)" stroke-width="1.8" stroke-linejoin="round"
    <?= $isMaint ? 'opacity="0.55"' : '' ?>/>
  <!-- Top cap (1.8) -->
  <rect x="<?= $cylLeft ?>" y="<?= $capTop ?>" width="<?= $cylRight - $cylLeft ?>" height="<?= $capH ?>"
    fill="none" stroke="var(--oak-deep,#5a3a12)" stroke-width="1.8"
    <?= $isMaint ? 'opacity="0.55"' : '' ?>/>

  <!-- Right sampling pipe (structural 1.1) -->
  <rect x="<?= $cylRight ?>" y="52" width="6" height="26" fill="var(--bg,#f1e8d4)"
    stroke="var(--oak-deep,#5a3a12)" stroke-width="1.1" <?= $isMaint ? 'opacity="0.6"' : '' ?>/>
  <line x1="<?= $cylRight + 1 ?>" y1="60" x2="<?= $cylRight + 5 ?>" y2="60"
    stroke="var(--oak-deep,#5a3a12)" stroke-width="0.6" opacity="<?= $isMaint ? '0.35' : '0.55' ?>"/>
  <line x1="<?= $cylRight + 1 ?>" y1="68" x2="<?= $cylRight + 5 ?>" y2="68"
    stroke="var(--oak-deep,#5a3a12)" stroke-width="0.6" opacity="<?= $isMaint ? '0.35' : '0.55' ?>"/>

  <!-- Left CIP port (structural 1.0) -->
  <rect x="6" y="54" width="6" height="4" fill="var(--bg,#f1e8d4)"
    stroke="var(--oak-deep,#5a3a12)" stroke-width="<?= $isMaint ? '0.7' : '0.9' ?>"
    opacity="<?= $isMaint ? '0.6' : '1' ?>"/>

  <!-- Manway circle on top cap -->
  <circle cx="22" cy="6" r="3" fill="var(--bg,#f1e8d4)"
    stroke="var(--oak-deep,#5a3a12)" stroke-width="<?= $isMaint ? '0.7' : '1.0' ?>"
    opacity="<?= $isMaint ? '0.55' : '1' ?>"/>

  <?php if ($isMaint): ?>
  <!-- Maintenance wrench icon (ember) -->
  <path d="M 36,54 L 38,52 L 44,58 L 42,60 Z" fill="none" stroke="var(--ember,#b34428)" stroke-width="0.8" opacity="0.60"/>
  <circle cx="37.5" cy="53.5" r="2.5" fill="none" stroke="var(--ember,#b34428)" stroke-width="0.8" opacity="0.60"/>
  <?php endif ?>

  <!-- Cone drain valve -->
  <rect x="37" y="<?= $coneBot ?>" width="6" height="4" fill="var(--bg,#f1e8d4)"
    stroke="var(--oak-deep,#5a3a12)" stroke-width="<?= $isMaint ? '0.7' : '0.9' ?>"
    opacity="<?= $isMaint ? '0.55' : '1' ?>"/>
  <circle cx="43" cy="<?= $coneBot + 2 ?>" r="1.2" fill="var(--bg,#f1e8d4)"
    stroke="var(--oak-deep,#5a3a12)" stroke-width="<?= $isMaint ? '0.6' : '0.7' ?>"
    opacity="<?= $isMaint ? '0.55' : '1' ?>"/>

  <!-- Legs (structural 1.1) -->
  <path d="M 19,<?= $coneBot + 6 ?> L 21,<?= $coneBot + 6 ?> L 16,152 L 14,152 Z"
    fill="var(--bg,#f1e8d4)" stroke="var(--oak-deep,#5a3a12)" stroke-width="1.1"
    opacity="<?= $isMaint ? '0.55' : '1' ?>"/>
  <path d="M 39,<?= $coneBot + 6 ?> L 41,<?= $coneBot + 6 ?> L 41,152 L 39,152 Z"
    fill="var(--bg,#f1e8d4)" stroke="var(--oak-deep,#5a3a12)" stroke-width="1.1"
    opacity="<?= $isMaint ? '0.55' : '1' ?>"/>
  <path d="M 59,<?= $coneBot + 6 ?> L 61,<?= $coneBot + 6 ?> L 65,152 L 63,152 Z"
    fill="var(--bg,#f1e8d4)" stroke="var(--oak-deep,#5a3a12)" stroke-width="1.1"
    opacity="<?= $isMaint ? '0.55' : '1' ?>"/>
  <!-- Leg feet pads -->
  <rect x="12" y="151" width="6" height="2" fill="var(--oak-deep,#5a3a12)" opacity="<?= $isMaint ? '0.30' : '0.58' ?>"/>
  <rect x="37" y="151" width="6" height="2" fill="var(--oak-deep,#5a3a12)" opacity="<?= $isMaint ? '0.30' : '0.58' ?>"/>
  <rect x="61" y="151" width="6" height="2" fill="var(--oak-deep,#5a3a12)" opacity="<?= $isMaint ? '0.30' : '0.58' ?>"/>

  <!-- Tank number -->
  <text x="40" y="30" text-anchor="middle"
    font-family="'JetBrains Mono',ui-monospace,monospace"
    font-size="13" font-weight="500"
    fill="<?= $numColor ?>" opacity="<?= $numOp ?>"><?= $number ?></text>

</svg>
<?php
    return ob_get_clean();
}
endif;

if (!function_exists('svg_bbt')):
function svg_bbt(float $fillRatio, string $stateClass = '', int $number = 0, string $beer = ''): string {
    $fillRatio = max(0.0, min(1.0, $fillRatio));

    /* ── Geometry (unchanged from original — viewBox 0 0 80 155) ── */
    $cylTop   = 15;
    $cylBot   = 125;
    $cylLeft  = 12;
    $cylRight = 68;
    $cylH     = $cylBot - $cylTop;   /* 110 */
    $rx       = 28;
    $ry       = 6;

    /* ── State detection ── */
    $isMaint = str_contains($stateClass, 'maint');
    /* BBT: 'bbt' stateClass = filled/filling (blue), otherwise empty */
    $isFilled = !$isMaint && $fillRatio > 0;

    /* ── Fill level geometry ── */
    $emptyH = (int) round($cylH * (1.0 - $fillRatio));
    $fillY  = $cylTop + $emptyH;
    $fillH  = $cylBot - $fillY;

    /* ── Fill tint color ── */
    /* Calibration 2026-06-07: raised from 0.17 to 0.35 for fill legibility. */
    if ($isMaint) {
        $tintColor = 'var(--tank-empty,#cfc6b2)';
        $tintOp    = 0.22;
    } else {
        $tintColor = 'var(--bbt,#2f6d99)';
        $tintOp    = 0.35;
    }

    /* ── Number text color ── */
    if ($isMaint) {
        $numColor = 'var(--ember,#b34428)';
        $numOp    = '0.50';
    } elseif ($isFilled) {
        $numColor = 'var(--bbt,#2f6d99)';
        $numOp    = '0.88';
    } else {
        $numColor = 'var(--oak-deep,#5a3a12)';
        $numOp    = '0.45';
    }

    /* ── Contact shadow color ── */
    $shadowFill = $isFilled
        ? 'rgba(47,109,153,0.22)'
        : 'rgba(90,58,18,' . ($isMaint ? '0.10' : '0.15') . ')';

    /* ── Collision-resistant uid ── */
    $uid    = 'bbt' . $number . '_' . substr(md5((string)microtime(true) . 'b' . $number), 0, 6);
    $clipId     = 'clip_'     . $uid;
    $bodyClipId = 'clipbody_' . $uid;
    $pxId   = 'px_'    . $uid;
    $pdId   = 'pd_'    . $uid;
    $pmId   = 'pm_'    . $uid;

    ob_start(); ?>
<svg class="tank-svg" viewBox="0 0 80 155" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="BBT <?= $number ?>">
  <defs>
    <!-- Body rect clip (cylinder only, excludes dome ellipses) -->
    <clipPath id="<?= $clipId ?>">
      <rect x="<?= $cylLeft ?>" y="<?= $cylTop ?>" width="<?= $cylRight - $cylLeft ?>" height="<?= $cylH ?>"/>
    </clipPath>
    <!-- Full body clip including dome protrusion top -->
    <clipPath id="<?= $bodyClipId ?>">
      <rect x="<?= $cylLeft ?>" y="<?= $cylTop - 4 ?>" width="<?= $cylRight - $cylLeft ?>" height="<?= $cylH + 8 ?>"/>
    </clipPath>
    <!-- Center sparse vertical hatch (3.8px) -->
    <pattern id="<?= $pxId ?>" x="0" y="0" width="3.8" height="3.8" patternUnits="userSpaceOnUse">
      <line x1="1.9" y1="0" x2="1.9" y2="3.8" stroke="var(--oak-deep,#5a3a12)" stroke-width="0.55" opacity="0.28"/>
    </pattern>
    <?php if ($isMaint): ?>
    <!-- Maintenance ember cross-hatch (6px) -->
    <pattern id="<?= $pmId ?>" x="0" y="0" width="6" height="6" patternUnits="userSpaceOnUse">
      <line x1="0" y1="0" x2="6" y2="6" stroke="var(--ember,#b34428)" stroke-width="0.5" opacity="0.22"/>
      <line x1="6" y1="0" x2="0" y2="6" stroke="var(--ember,#b34428)" stroke-width="0.5" opacity="0.22"/>
    </pattern>
    <?php else: ?>
    <!-- Dense cross-hatch (3.5px) for dome shading and strap bands -->
    <pattern id="<?= $pdId ?>" x="0" y="0" width="3.5" height="3.5" patternUnits="userSpaceOnUse">
      <line x1="0" y1="0" x2="3.5" y2="3.5" stroke="var(--oak-deep,#5a3a12)" stroke-width="0.58" opacity="0.52"/>
      <line x1="3.5" y1="0" x2="0" y2="3.5" stroke="var(--oak-deep,#5a3a12)" stroke-width="0.58" opacity="0.52"/>
    </pattern>
    <?php endif ?>
  </defs>

  <!-- Contact shadow -->
  <ellipse cx="40" cy="152" rx="28" ry="2" fill="<?= $shadowFill ?>"/>

  <!-- Paper fills (no stroke yet) -->
  <rect x="<?= $cylLeft ?>" y="<?= $cylTop ?>" width="<?= $cylRight - $cylLeft ?>" height="<?= $cylH ?>"
    fill="var(--bg,#f1e8d4)" stroke="none"/>
  <ellipse cx="40" cy="<?= $cylTop ?>" rx="<?= $rx ?>" ry="<?= $ry ?>" fill="var(--bg,#f1e8d4)" stroke="none"/>
  <ellipse cx="40" cy="<?= $cylBot ?>" rx="<?= $rx ?>" ry="<?= $ry ?>" fill="var(--bg,#f1e8d4)" stroke="none"/>

  <?php if ($fillRatio > 0 && !$isMaint): ?>
  <!-- Beer fill tint — ON TOP of paper fill, UNDER hatching (op 0.35) -->
  <!-- Calibration 2026-06-07: moved after paper fill so tint is visible -->
  <rect x="<?= $cylLeft ?>" y="<?= $fillY ?>" width="<?= $cylRight - $cylLeft ?>" height="<?= $fillH ?>"
    fill="<?= $tintColor ?>" opacity="<?= $tintOp ?>"
    clip-path="url(#<?= $clipId ?>)"/>
  <?php endif ?>

  <!-- ── HATCHING LAYER ── -->
  <?php if ($isMaint): ?>
  <!-- Maintenance: full ember diagonal cross-hatch -->
  <rect x="<?= $cylLeft ?>" y="<?= $cylTop ?>" width="<?= $cylRight - $cylLeft ?>" height="<?= $cylH ?>"
    fill="url(#<?= $pmId ?>)" clip-path="url(#<?= $clipId ?>)"/>
  <?php else: ?>
  <!-- Center sparse vertical hatch (light zone, left of center) -->
  <rect x="20" y="<?= $cylTop ?>" width="28" height="<?= $cylH ?>"
    fill="url(#<?= $pxId ?>)" clip-path="url(#<?= $clipId ?>)"/>

  <!-- Top dome dense shadow (cross-hatch, 12px band) -->
  <rect x="<?= $cylLeft ?>" y="<?= $cylTop - 1 ?>" width="<?= $cylRight - $cylLeft ?>" height="12"
    fill="url(#<?= $pdId ?>)" clip-path="url(#<?= $clipId ?>)"/>
  <!-- Top dome arc contour lines — left -->
  <line x1="13.0" y1="<?= $cylTop - 3 ?>" x2="15.5" y2="<?= $cylTop + 0.5 ?>" stroke="var(--oak-deep,#5a3a12)" stroke-width="0.60" opacity="0.55"/>
  <line x1="16.0" y1="<?= $cylTop - 4 ?>" x2="18.5" y2="<?= $cylTop ?>"        stroke="var(--oak-deep,#5a3a12)" stroke-width="0.55" opacity="0.50"/>
  <line x1="19.0" y1="<?= $cylTop - 5 ?>" x2="21.5" y2="<?= $cylTop - 0.5 ?>"  stroke="var(--oak-deep,#5a3a12)" stroke-width="0.50" opacity="0.43"/>
  <!-- Top dome arc contour lines — right -->
  <line x1="67.0" y1="<?= $cylTop - 3 ?>" x2="64.5" y2="<?= $cylTop + 0.5 ?>" stroke="var(--oak-deep,#5a3a12)" stroke-width="0.62" opacity="0.58"/>
  <line x1="64.0" y1="<?= $cylTop - 4 ?>" x2="61.5" y2="<?= $cylTop ?>"        stroke="var(--oak-deep,#5a3a12)" stroke-width="0.57" opacity="0.52"/>
  <line x1="61.0" y1="<?= $cylTop - 5 ?>" x2="58.5" y2="<?= $cylTop - 0.5 ?>"  stroke="var(--oak-deep,#5a3a12)" stroke-width="0.52" opacity="0.45"/>

  <!-- Bottom dome dense shadow (12px band) -->
  <rect x="<?= $cylLeft ?>" y="<?= $cylBot - 7 ?>" width="<?= $cylRight - $cylLeft ?>" height="12"
    fill="url(#<?= $pdId ?>)" clip-path="url(#<?= $clipId ?>)"/>

  <!-- Strap band sub-shadow (cross-hatch strips, 9px each) -->
  <rect x="13" y="52" width="54" height="9" fill="url(#<?= $pdId ?>)" clip-path="url(#<?= $clipId ?>)" opacity="0.72"/>
  <rect x="13" y="90" width="54" height="9" fill="url(#<?= $pdId ?>)" clip-path="url(#<?= $clipId ?>)" opacity="0.65"/>

  <!-- ── LEFT EDGE CONTOUR (4 strokes following left wall) ── -->
  <line x1="13.5" y1="<?= $cylTop + 1 ?>"   x2="13.5" y2="<?= $cylBot - 1 ?>"   stroke="var(--oak-deep,#5a3a12)" stroke-width="0.65" opacity="0.60"/>
  <line x1="15.2" y1="<?= $cylTop + 0.5 ?>" x2="15.1" y2="<?= $cylBot - 1 ?>"   stroke="var(--oak-deep,#5a3a12)" stroke-width="0.60" opacity="0.56"/>
  <line x1="16.8" y1="<?= $cylTop + 1 ?>"   x2="16.9" y2="<?= $cylBot - 0.5 ?>" stroke="var(--oak-deep,#5a3a12)" stroke-width="0.55" opacity="0.50"/>
  <line x1="18.3" y1="<?= $cylTop + 0.5 ?>" x2="18.2" y2="<?= $cylBot - 1 ?>"   stroke="var(--oak-deep,#5a3a12)" stroke-width="0.50" opacity="0.42"/>

  <!-- ── RIGHT EDGE CONTOUR (5 strokes following right wall) ── -->
  <line x1="66.3" y1="<?= $cylTop + 1 ?>"   x2="66.2" y2="<?= $cylBot - 0.5 ?>" stroke="var(--oak-deep,#5a3a12)" stroke-width="0.65" opacity="0.62"/>
  <line x1="64.6" y1="<?= $cylTop + 0.5 ?>" x2="64.7" y2="<?= $cylBot - 1 ?>"   stroke="var(--oak-deep,#5a3a12)" stroke-width="0.62" opacity="0.58"/>
  <line x1="63.0" y1="<?= $cylTop + 1 ?>"   x2="62.9" y2="<?= $cylBot - 0.5 ?>" stroke="var(--oak-deep,#5a3a12)" stroke-width="0.58" opacity="0.52"/>
  <line x1="61.3" y1="<?= $cylTop + 0.5 ?>" x2="61.4" y2="<?= $cylBot - 1 ?>"   stroke="var(--oak-deep,#5a3a12)" stroke-width="0.53" opacity="0.45"/>
  <line x1="59.7" y1="<?= $cylTop + 1 ?>"   x2="59.6" y2="<?= $cylBot - 0.5 ?>" stroke="var(--oak-deep,#5a3a12)" stroke-width="0.48" opacity="0.38"/>

  <!-- Strap band lines (structural 1.2) -->
  <line x1="<?= $cylLeft ?>" y1="52" x2="<?= $cylRight ?>" y2="52" stroke="var(--oak-deep,#5a3a12)" stroke-width="1.2"/>
  <line x1="<?= $cylLeft ?>" y1="90" x2="<?= $cylRight ?>" y2="90" stroke="var(--oak-deep,#5a3a12)" stroke-width="1.2"/>

  <?php if ($fillRatio > 0): ?>
  <!-- Fill surface dashed line — calibrated 2026-06-07: sw 1.4, op 0.90 -->
  <line x1="<?= $cylLeft ?>" y1="<?= $fillY ?>" x2="<?= $cylRight ?>" y2="<?= $fillY ?>"
    stroke="var(--bbt,#2f6d99)" stroke-width="1.4" opacity="0.90" stroke-dasharray="3,2"/>
  <!-- Meniscus ticks where surface line meets walls -->
  <line x1="<?= $cylLeft ?>"  y1="<?= $fillY - 2 ?>" x2="<?= $cylLeft ?>"  y2="<?= $fillY + 2 ?>"
    stroke="var(--bbt,#2f6d99)" stroke-width="1.2" opacity="0.85" stroke-linecap="round"/>
  <line x1="<?= $cylRight ?>" y1="<?= $fillY - 2 ?>" x2="<?= $cylRight ?>" y2="<?= $fillY + 2 ?>"
    stroke="var(--bbt,#2f6d99)" stroke-width="1.2" opacity="0.85" stroke-linecap="round"/>
  <?php endif ?>

  <!-- BBT graduation ticks on right wall (25/50/75/100%) — calibration 2026-06-07 -->
  <?php
    $tickPositions = [
        100 => (int)round($cylTop),
         75 => (int)round($cylTop + $cylH * 0.25),
         50 => (int)round($cylTop + $cylH * 0.50),
         25 => (int)round($cylTop + $cylH * 0.75),
    ];
    foreach ($tickPositions as $pct => $ty):
  ?>
  <line x1="<?= $cylRight ?>" y1="<?= $ty ?>" x2="<?= $cylRight + 4 ?>" y2="<?= $ty ?>"
    stroke="var(--oak-deep,#5a3a12)" stroke-width="0.8" opacity="0.55"/>
  <text x="<?= $cylRight + 6 ?>" y="<?= $ty + 2.5 ?>"
    font-family="'JetBrains Mono',ui-monospace,monospace" font-size="5" fill="var(--ink-mute,#8a7560)" opacity="0.70"><?= $pct ?></text>
  <?php endforeach ?>
  <?php endif /* end !$isMaint else */ ?>

  <!-- ── OUTLINES (on top of all hatching) ── -->
  <!-- Body rect (2.0) -->
  <rect x="<?= $cylLeft ?>" y="<?= $cylTop ?>" width="<?= $cylRight - $cylLeft ?>" height="<?= $cylH ?>"
    fill="none" stroke="var(--oak-deep,#5a3a12)" stroke-width="2.0"
    opacity="<?= $isMaint ? '0.55' : '1' ?>"/>
  <!-- Top dome (1.8) -->
  <ellipse cx="40" cy="<?= $cylTop ?>" rx="<?= $rx ?>" ry="<?= $ry ?>"
    fill="none" stroke="var(--oak-deep,#5a3a12)" stroke-width="1.8"
    opacity="<?= $isMaint ? '0.55' : '1' ?>"/>
  <!-- Bottom dome (1.8) -->
  <ellipse cx="40" cy="<?= $cylBot ?>" rx="<?= $rx ?>" ry="<?= $ry ?>"
    fill="none" stroke="var(--oak-deep,#5a3a12)" stroke-width="1.8"
    opacity="<?= $isMaint ? '0.55' : '1' ?>"/>

  <!-- Side manway (structural 1.1) -->
  <rect x="6" y="60" width="6" height="12" rx="1" fill="var(--bg,#f1e8d4)"
    stroke="var(--oak-deep,#5a3a12)" stroke-width="1.1"
    opacity="<?= $isMaint ? '0.55' : '1' ?>"/>
  <ellipse cx="9" cy="66" rx="2" ry="3.5" fill="none"
    stroke="var(--oak-deep,#5a3a12)" stroke-width="0.6" opacity="<?= $isMaint ? '0.35' : '0.55' ?>"/>

  <!-- Top vent -->
  <rect x="36" y="8" width="8" height="7" fill="var(--bg,#f1e8d4)"
    stroke="var(--oak-deep,#5a3a12)" stroke-width="0.9"
    opacity="<?= $isMaint ? '0.55' : '1' ?>"/>
  <line x1="38" y1="7" x2="38" y2="3" stroke="var(--oak-deep,#5a3a12)" stroke-width="1.0" opacity="<?= $isMaint ? '0.55' : '1' ?>"/>
  <line x1="42" y1="7" x2="42" y2="3" stroke="var(--oak-deep,#5a3a12)" stroke-width="1.0" opacity="<?= $isMaint ? '0.55' : '1' ?>"/>

  <!-- Outlet right -->
  <rect x="<?= $cylRight ?>" y="105" width="8" height="4" fill="var(--bg,#f1e8d4)"
    stroke="var(--oak-deep,#5a3a12)" stroke-width="0.9"
    opacity="<?= $isMaint ? '0.55' : '1' ?>"/>

  <?php if ($isMaint): ?>
  <!-- Maintenance wrench icon (ember) -->
  <path d="M 36,70 L 38,68 L 44,74 L 42,76 Z" fill="none" stroke="var(--ember,#b34428)" stroke-width="0.8" opacity="0.60"/>
  <circle cx="37.5" cy="69.5" r="2.5" fill="none" stroke="var(--ember,#b34428)" stroke-width="0.8" opacity="0.60"/>
  <?php elseif ($fillRatio >= 0.9): ?>
  <!-- Ready/full: green tick -->
  <path d="M 34,70 L 38,75 L 47,63" fill="none" stroke="var(--ok,#3d6826)" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round" opacity="0.75"/>
  <?php endif ?>

  <!-- Legs (structural 1.1) -->
  <path d="M 19,<?= $cylBot + 5 ?> L 21,<?= $cylBot + 5 ?> L 16,152 L 14,152 Z"
    fill="var(--bg,#f1e8d4)" stroke="var(--oak-deep,#5a3a12)" stroke-width="1.1"
    opacity="<?= $isMaint ? '0.55' : '1' ?>"/>
  <path d="M 39,<?= $cylBot + 5 ?> L 41,<?= $cylBot + 5 ?> L 41,152 L 39,152 Z"
    fill="var(--bg,#f1e8d4)" stroke="var(--oak-deep,#5a3a12)" stroke-width="1.1"
    opacity="<?= $isMaint ? '0.55' : '1' ?>"/>
  <path d="M 59,<?= $cylBot + 5 ?> L 61,<?= $cylBot + 5 ?> L 65,152 L 63,152 Z"
    fill="var(--bg,#f1e8d4)" stroke="var(--oak-deep,#5a3a12)" stroke-width="1.1"
    opacity="<?= $isMaint ? '0.55' : '1' ?>"/>
  <!-- Leg feet pads -->
  <rect x="12" y="151" width="6" height="2" fill="var(--oak-deep,#5a3a12)" opacity="<?= $isMaint ? '0.30' : '0.58' ?>"/>
  <rect x="37" y="151" width="6" height="2" fill="var(--oak-deep,#5a3a12)" opacity="<?= $isMaint ? '0.30' : '0.58' ?>"/>
  <rect x="61" y="151" width="6" height="2" fill="var(--oak-deep,#5a3a12)" opacity="<?= $isMaint ? '0.30' : '0.58' ?>"/>

  <!-- Tank number -->
  <text x="40" y="40" text-anchor="middle"
    font-family="'JetBrains Mono',ui-monospace,monospace"
    font-size="13" font-weight="500"
    fill="<?= $numColor ?>" opacity="<?= $numOp ?>"><?= $number ?></text>

</svg>
<?php
    return ob_get_clean();
}
endif;

/* ═══════════════════════════════════════════════════════════════════════════
   svg_vessel_* — Mother Shell vessel components (Atom 2), gravure-dense style.
   Same public API as the retired svg-vessels.php. CSS animation hooks preserved
   verbatim so sb-board.css @keyframes sb-vessel-pulse / sb-conveyor-move
   continue to target .sb-vessel--pulsing / .sb-conveyor-lines without change.

   Implementation notes
   ─────────────────────
   • All fills/strokes use var(--token,#fallback) — no bare hex literals.
   • No inline <style>. Animation is purely CSS-class-driven.
   • UID: '_sv_uid' uses static counter (PHP single-threaded = safe).
     Combined prefix + number ensures page-unique defs IDs across multiple
     vessels. Same guarantee as the svg_cct/svg_bbt microtime uid above.
   • viewBox dimensions are IDENTICAL to svg-vessels.php:
       CCT/BBT = 80×155, kettle = 72×80, packaging_line = 200×60.
   • Element budgets: cct ≤110, bbt ≤115, kettle ≤90, pkg_line ≤55.
     sb-board re-renders every 15s — all well within budget.
   • sb-board re-renders via PHP — ids regenerate each render, which is
     correct; the JS never caches svg defs. No DOM def-accumulation risk.
═══════════════════════════════════════════════════════════════════════════ */

if (!function_exists('_sv_uid')):
/** Collision-resistant uid for svg_vessel_* <defs> ids. Static counter. */
function _sv_uid(string $prefix, int $number = 0): string {
    static $counter = 0;
    $counter++;
    return $prefix . $number . '_' . substr(md5($prefix . $number . $counter), 0, 6);
}
endif;

/* ── svg_vessel_cct ──────────────────────────────────────────────────────── */

/**
 * CCT (Conical Cylindrical Tank) — fermentation vessel, gravure-dense style.
 *
 * @param int    $number   CCT number for label (1-based)
 * @param float  $fill_pct 0.0–1.0
 * @param string $state    'empty'|'active'|'cold-crashed'|'cleaning'
 * @param array  $opts     ['recipe'=>string, 'batch'=>string, 'compact'=>bool]
 * @return string          Inline SVG, no <?xml header
 */
if (!function_exists('svg_vessel_cct')):
function svg_vessel_cct(int $number, float $fill_pct = 0.0, string $state = 'empty', array $opts = []): string {
    $fill_pct  = max(0.0, min(1.0, $fill_pct));
    $is_active = in_array($state, ['active', 'cold-crashed', 'cleaning'], true);
    $has_fill  = $fill_pct > 0 && $state !== 'empty';

    $cylTop = 10; $cylBot = 100; $coneBot = 130;
    $cylLeft = 12; $cylRight = 68; $conePoint = 40;
    $cylH = $cylBot - $cylTop;
    $capTop = 2; $capH = 8;

    $emptyH = (int)round($cylH * (1.0 - $fill_pct));
    $fillY  = $cylTop + $emptyH;
    $fillH  = $cylBot - $fillY + ($coneBot - $cylBot);

    /* Calibration 2026-06-07: tint opacity raised to 0.34-0.35; */
    /* active/fermentation uses --hop (green) not --cold (blue) for correct state color */
    [$tintColor, $tintOp, $numColor, $numOp] = match($state) {
        'active'       => ['var(--hop,#567020)',        0.34, 'var(--hop,#567020)',        '0.85'],
        'cold-crashed' => ['var(--cold,#2f5575)',       0.35, 'var(--cold,#2f5575)',       '0.80'],
        'cleaning'     => ['var(--steel-mid,#9a8868)', 0.18, 'var(--oak-deep,#5a3a12)',  '0.50'],
        default        => ['none',                     0.0,  'var(--oak-deep,#5a3a12)',  '0.45'],
    };

    /* Animation class hooks — preserved from svg-vessels.php */
    $svg_class = 'sb-vessel__svg sb-vessel__svg--cct';
    if ($is_active && in_array($state, ['active', 'cold-crashed'], true)) {
        $svg_class .= ' sb-vessel--pulsing';
    }

    $uid = _sv_uid('vcct', $number);
    $clipId = 'clip_' . $uid; $coneClipId = 'clipc_' . $uid;
    $pxId   = 'px_'  . $uid; $pdId       = 'pd_'   . $uid;

    $bodyPts = "{$cylLeft},{$cylTop} {$cylRight},{$cylTop} {$cylRight},{$cylBot} "
        . ($conePoint+6) . ",{$coneBot} " . ($conePoint-6) . ",{$coneBot} {$cylLeft},{$cylBot}";

    $title = 'CCT ' . $number . ($state !== 'empty' ? ' — ' . $state : '');
    $tp = [];
    if (!empty($opts['recipe'])) $tp[] = htmlspecialchars($opts['recipe'], ENT_XML1, 'UTF-8');
    if (!empty($opts['batch']))  $tp[] = '#' . htmlspecialchars((string)$opts['batch'], ENT_XML1, 'UTF-8');
    if ($tp) $title .= ' (' . implode(' ', $tp) . ')';
    $showLabel = empty($opts['compact']) && (!empty($opts['recipe']) || !empty($opts['batch']));

    ob_start();
    echo '<svg class="' . htmlspecialchars($svg_class, ENT_QUOTES, 'UTF-8') . '"'
        . ' viewBox="0 0 80 155" xmlns="http://www.w3.org/2000/svg"'
        . ' role="img" aria-label="' . htmlspecialchars($title, ENT_XML1, 'UTF-8') . '">';
    echo '<title>' . htmlspecialchars($title, ENT_XML1, 'UTF-8') . '</title>';
    echo '<defs>';
    echo '<clipPath id="' . $clipId . '"><polygon points="' . $bodyPts . '"/></clipPath>';
    echo '<clipPath id="' . $coneClipId . '"><polygon points="' . $cylLeft . ',' . $cylBot
        . ' ' . $cylRight . ',' . $cylBot . ' ' . ($conePoint+6) . ',' . $coneBot
        . ' ' . ($conePoint-6) . ',' . $coneBot . '"/></clipPath>';
    echo '<pattern id="' . $pxId . '" x="0" y="0" width="3.8" height="3.8" patternUnits="userSpaceOnUse">'
        . '<line x1="1.9" y1="0" x2="1.9" y2="3.8" stroke="var(--oak-deep,#5a3a12)" stroke-width="0.55" opacity="0.28"/>'
        . '</pattern>';
    echo '<pattern id="' . $pdId . '" x="0" y="0" width="3.5" height="3.5" patternUnits="userSpaceOnUse">'
        . '<line x1="0" y1="0" x2="3.5" y2="3.5" stroke="var(--oak-deep,#5a3a12)" stroke-width="0.58" opacity="0.52"/>'
        . '<line x1="3.5" y1="0" x2="0" y2="3.5" stroke="var(--oak-deep,#5a3a12)" stroke-width="0.58" opacity="0.52"/>'
        . '</pattern>';
    echo '</defs>';

    echo '<ellipse cx="40" cy="152" rx="28" ry="2" fill="rgba(90,58,18,0.12)"/>';

    /* Paper fill first, THEN tint on top (calibration 2026-06-07 — original had tint under paper = invisible) */
    echo '<polygon points="' . $bodyPts . '" fill="var(--bg,#f1e8d4)" stroke="none"/>';
    echo '<rect x="' . $cylLeft . '" y="' . $capTop . '" width="' . ($cylRight-$cylLeft) . '" height="' . $capH . '" fill="var(--bg,#f1e8d4)" stroke="none"/>';

    if ($has_fill) {
        echo '<rect x="' . $cylLeft . '" y="' . $fillY . '" width="' . ($cylRight-$cylLeft) . '" height="' . $fillH . '"'
            . ' fill="' . $tintColor . '" opacity="' . $tintOp . '" clip-path="url(#' . $clipId . ')"/>';
    }

    echo '<rect x="21" y="' . $cylTop . '" width="30" height="' . $cylH . '" fill="url(#' . $pxId . ')" clip-path="url(#' . $clipId . ')"/>';
    echo '<rect x="' . $cylLeft . '" y="' . $capTop . '" width="' . ($cylRight-$cylLeft) . '" height="' . $capH . '" fill="url(#' . $pdId . ')"/>';
    echo '<rect x="' . $cylLeft . '" y="' . $cylBot . '" width="' . ($cylRight-$cylLeft) . '" height="' . ($coneBot-$cylBot) . '"'
        . ' fill="url(#' . $pdId . ')" clip-path="url(#' . $coneClipId . ')"/>';
    echo '<rect x="13" y="44" width="54" height="10" fill="url(#' . $pdId . ')" clip-path="url(#' . $clipId . ')" opacity="0.75"/>';
    echo '<rect x="13" y="72" width="54" height="10" fill="url(#' . $pdId . ')" clip-path="url(#' . $clipId . ')" opacity="0.65"/>';

    $odk = 'stroke="var(--oak-deep,#5a3a12)"';
    echo '<line x1="13.5" y1="11" x2="13.5" y2="' . $cylBot . '" ' . $odk . ' stroke-width="0.60" opacity="0.55"/>';
    echo '<line x1="15.1" y1="10.5" x2="15.0" y2="' . $cylBot . '" ' . $odk . ' stroke-width="0.55" opacity="0.50"/>';
    echo '<line x1="16.6" y1="11" x2="16.7" y2="' . $cylBot . '" ' . $odk . ' stroke-width="0.50" opacity="0.44"/>';
    echo '<line x1="18.2" y1="10.5" x2="18.1" y2="' . $cylBot . '" ' . $odk . ' stroke-width="0.45" opacity="0.38"/>';
    echo '<line x1="13.5" y1="' . $cylBot . '" x2="34.5" y2="' . $coneBot . '" ' . $odk . ' stroke-width="0.60" opacity="0.55"/>';
    echo '<line x1="15.2" y1="' . $cylBot . '" x2="36.0" y2="' . $coneBot . '" ' . $odk . ' stroke-width="0.55" opacity="0.48"/>';
    echo '<line x1="66.2" y1="11" x2="66.3" y2="' . $cylBot . '" ' . $odk . ' stroke-width="0.65" opacity="0.60"/>';
    echo '<line x1="64.5" y1="10.5" x2="64.4" y2="' . $cylBot . '" ' . $odk . ' stroke-width="0.62" opacity="0.55"/>';
    echo '<line x1="62.9" y1="11" x2="63.0" y2="' . $cylBot . '" ' . $odk . ' stroke-width="0.58" opacity="0.50"/>';
    echo '<line x1="61.2" y1="10.5" x2="61.3" y2="' . $cylBot . '" ' . $odk . ' stroke-width="0.53" opacity="0.44"/>';
    echo '<line x1="59.6" y1="11" x2="59.5" y2="' . $cylBot . '" ' . $odk . ' stroke-width="0.48" opacity="0.38"/>';
    echo '<line x1="66.5" y1="' . $cylBot . '" x2="45.5" y2="' . $coneBot . '" ' . $odk . ' stroke-width="0.65" opacity="0.60"/>';
    echo '<line x1="64.8" y1="' . $cylBot . '" x2="44.0" y2="' . $coneBot . '" ' . $odk . ' stroke-width="0.58" opacity="0.52"/>';

    echo '<line x1="' . $cylLeft . '" y1="44" x2="' . $cylRight . '" y2="44" ' . $odk . ' stroke-width="1.1" opacity="0.55"/>';
    echo '<line x1="' . $cylLeft . '" y1="72" x2="' . $cylRight . '" y2="72" ' . $odk . ' stroke-width="1.1" opacity="0.55"/>';

    if ($has_fill) {
        /* Calibration 2026-06-07: sw 1.4, op 0.90 + meniscus ticks */
        echo '<line x1="' . $cylLeft . '" y1="' . $fillY . '" x2="' . $cylRight . '" y2="' . $fillY . '"'
            . ' stroke="' . $tintColor . '" stroke-width="1.4" opacity="0.90" stroke-dasharray="3,2"/>';
        echo '<line x1="' . $cylLeft  . '" y1="' . ($fillY-2) . '" x2="' . $cylLeft  . '" y2="' . ($fillY+2) . '"'
            . ' stroke="' . $tintColor . '" stroke-width="1.2" opacity="0.85" stroke-linecap="round"/>';
        echo '<line x1="' . $cylRight . '" y1="' . ($fillY-2) . '" x2="' . $cylRight . '" y2="' . ($fillY+2) . '"'
            . ' stroke="' . $tintColor . '" stroke-width="1.2" opacity="0.85" stroke-linecap="round"/>';
    }

    if ($state === 'active' && $has_fill) {
        /* Fermentation: 5 static CO2 bubbles (grayscale-safe) + animated class on top */
        $by1 = max($fillY + 8, 70); $by2 = max($fillY + 20, 84); $by3 = max($fillY + 14, 78);
        $by4 = max($fillY + 5,  64); $by5 = max($fillY + 25, 88);
        echo '<circle cx="32" cy="' . $by1 . '" r="1.4" fill="var(--hop,#567020)" opacity="0.62"/>';
        echo '<circle cx="48" cy="' . $by2 . '" r="1.1" fill="var(--hop,#567020)" opacity="0.58"/>';
        echo '<circle cx="38" cy="' . $by3 . '" r="0.9" fill="var(--hop,#567020)" opacity="0.55"/>';
        echo '<circle cx="56" cy="' . $by4 . '" r="1.2" fill="var(--hop,#567020)" opacity="0.60"/>';
        echo '<circle cx="25" cy="' . $by5 . '" r="1.0" fill="var(--hop,#567020)" opacity="0.55"/>';
        echo '<circle cx="32" cy="' . $by1 . '" r="1.4" class="ferment-bubble" fill="var(--hop,#567020)" opacity="0.65"/>';
        echo '<circle cx="48" cy="' . $by2 . '" r="1.1" class="ferment-bubble" fill="var(--hop,#567020)" opacity="0.58" style="animation-delay:1.1s"/>';
        echo '<circle cx="56" cy="' . $by4 . '" r="1.2" class="ferment-bubble" fill="var(--hop,#567020)" opacity="0.60" style="animation-delay:2.0s"/>';
    }

    if ($state === 'cold-crashed' && $has_fill) {
        /* Cold crash: snowflake glyph (✻ 6-arm) + frost wall ticks — grayscale-safe */
        echo '<g transform="translate(58,26)" opacity="0.80" stroke="var(--cold,#2f5575)" stroke-width="1.1" stroke-linecap="round">'
            . '<line x1="0" y1="-4.5" x2="0" y2="4.5"/>'
            . '<line x1="-3.9" y1="-2.25" x2="3.9" y2="2.25"/>'
            . '<line x1="-3.9" y1="2.25" x2="3.9" y2="-2.25"/>'
            . '<line x1="-1.2" y1="-3.5" x2="0" y2="-4.5"/>'
            . '<line x1="1.2" y1="-3.5" x2="0" y2="-4.5"/>'
            . '<line x1="-1.2" y1="3.5" x2="0" y2="4.5"/>'
            . '<line x1="1.2" y1="3.5" x2="0" y2="4.5"/>'
            . '<line x1="-4.5" y1="-1.0" x2="-3.9" y2="-2.25"/>'
            . '<line x1="-4.5" y1="0" x2="-3.9" y2="-2.25"/>'
            . '</g>';
        $ft1 = min($fillY + 14, $cylBot - 5);
        $ft2 = min($fillY + 28, $cylBot - 3);
        echo '<line x1="' . $cylLeft  . '" y1="' . $ft1 . '" x2="' . ($cylLeft+4)  . '" y2="' . $ft1 . '" stroke="var(--cold,#2f5575)" stroke-width="0.8" opacity="0.60"/>';
        echo '<line x1="' . $cylRight . '" y1="' . $ft1 . '" x2="' . ($cylRight-4) . '" y2="' . $ft1 . '" stroke="var(--cold,#2f5575)" stroke-width="0.8" opacity="0.60"/>';
        echo '<line x1="' . $cylLeft  . '" y1="' . $ft2 . '" x2="' . ($cylLeft+3)  . '" y2="' . $ft2 . '" stroke="var(--cold,#2f5575)" stroke-width="0.7" opacity="0.48"/>';
        echo '<line x1="' . $cylRight . '" y1="' . $ft2 . '" x2="' . ($cylRight-3) . '" y2="' . $ft2 . '" stroke="var(--cold,#2f5575)" stroke-width="0.7" opacity="0.48"/>';
    }

    echo '<polygon points="' . $bodyPts . '" fill="none" ' . $odk . ' stroke-width="1.8" stroke-linejoin="round"/>';
    echo '<rect x="' . $cylLeft . '" y="' . $capTop . '" width="' . ($cylRight-$cylLeft) . '" height="' . $capH . '" fill="none" ' . $odk . ' stroke-width="1.8"/>';
    echo '<rect x="' . $cylRight . '" y="52" width="6" height="26" fill="var(--bg,#f1e8d4)" ' . $odk . ' stroke-width="1.1"/>';
    echo '<rect x="6" y="54" width="6" height="4" fill="var(--bg,#f1e8d4)" ' . $odk . ' stroke-width="0.9"/>';
    echo '<circle cx="22" cy="6" r="3" fill="var(--bg,#f1e8d4)" ' . $odk . ' stroke-width="1.0"/>';
    echo '<rect x="37" y="' . $coneBot . '" width="6" height="4" fill="var(--bg,#f1e8d4)" ' . $odk . ' stroke-width="0.9"/>';
    echo '<path d="M 19,' . ($coneBot+6) . ' L 21,' . ($coneBot+6) . ' L 16,152 L 14,152 Z" fill="var(--bg,#f1e8d4)" ' . $odk . ' stroke-width="1.1"/>';
    echo '<path d="M 39,' . ($coneBot+6) . ' L 41,' . ($coneBot+6) . ' L 41,152 L 39,152 Z" fill="var(--bg,#f1e8d4)" ' . $odk . ' stroke-width="1.1"/>';
    echo '<path d="M 59,' . ($coneBot+6) . ' L 61,' . ($coneBot+6) . ' L 65,152 L 63,152 Z" fill="var(--bg,#f1e8d4)" ' . $odk . ' stroke-width="1.1"/>';
    echo '<rect x="12" y="151" width="6" height="2" fill="var(--oak-deep,#5a3a12)" opacity="0.58"/>';
    echo '<rect x="37" y="151" width="6" height="2" fill="var(--oak-deep,#5a3a12)" opacity="0.58"/>';
    echo '<rect x="61" y="151" width="6" height="2" fill="var(--oak-deep,#5a3a12)" opacity="0.58"/>';

    echo '<text x="40" y="30" text-anchor="middle"'
        . ' font-family="\'JetBrains Mono\',ui-monospace,monospace" font-size="13" font-weight="500"'
        . ' fill="' . $numColor . '" opacity="' . $numOp . '">' . $number . '</text>';

    if ($showLabel) {
        $lp = [];
        if (!empty($opts['recipe'])) $lp[] = htmlspecialchars($opts['recipe'], ENT_XML1, 'UTF-8');
        if (!empty($opts['batch']))  $lp[] = '#' . htmlspecialchars((string)$opts['batch'], ENT_XML1, 'UTF-8');
        echo '<text x="40" y="82" text-anchor="middle"'
            . ' font-family="\'DM Sans\',ui-sans-serif,sans-serif" font-size="7.5" font-weight="500"'
            . ' fill="var(--ink-soft,#5a4028)" opacity="0.70">' . implode(' ', $lp) . '</text>';
    }

    echo '</svg>';
    return trim(ob_get_clean());
}
endif;

/* ── svg_vessel_bbt ──────────────────────────────────────────────────────── */

/**
 * BBT (Bright Beer Tank) — post-racking serving tank, gravure-dense style.
 *
 * @param int    $number   BBT number for label (1-based)
 * @param float  $fill_pct 0.0–1.0
 * @param string $state    'empty'|'filling'|'ready'|'serving'|'cleaning'
 * @param array  $opts     ['recipe'=>string, 'batch'=>string, 'compact'=>bool]
 * @return string          Inline SVG, no <?xml header
 */
if (!function_exists('svg_vessel_bbt')):
function svg_vessel_bbt(int $number, float $fill_pct = 0.0, string $state = 'empty', array $opts = []): string {
    $fill_pct = max(0.0, min(1.0, $fill_pct));
    $has_fill = $fill_pct > 0 && $state !== 'empty';

    $cylTop = 15; $cylBot = 125; $cylLeft = 12; $cylRight = 68;
    $cylH = $cylBot - $cylTop; $rx = 28; $ry = 6;

    $emptyH = (int)round($cylH * (1.0 - $fill_pct));
    $fillY  = $cylTop + $emptyH;
    $fillH  = $cylBot - $fillY;

    /* Calibration 2026-06-07: BBT fill tint opacity raised to 0.35 for legibility */
    [$tintColor, $tintOp, $numColor, $numOp] = match($state) {
        'filling'  => ['var(--bbt,#2f6d99)', 0.35, 'var(--bbt,#2f6d99)',      '0.85'],
        'ready'    => ['var(--bbt,#2f6d99)', 0.35, 'var(--bbt,#2f6d99)',      '0.85'],
        'serving'  => ['var(--bbt,#2f6d99)', 0.35, 'var(--bbt,#2f6d99)',      '0.90'],
        'cleaning' => ['var(--steel-mid,#9a8868)', 0.18, 'var(--oak-deep,#5a3a12)', '0.50'],
        default    => ['none', 0.0, 'var(--oak-deep,#5a3a12)', '0.45'],
    };

    /* Animation class hooks — preserved from svg-vessels.php */
    $svg_class = 'sb-vessel__svg sb-vessel__svg--bbt';
    if ($state === 'filling') $svg_class .= ' sb-vessel--pulsing';

    $uid = _sv_uid('vbbt', $number);
    $clipId = 'clip_' . $uid; $pxId = 'px_' . $uid; $pdId = 'pd_' . $uid;

    $showLabel = empty($opts['compact']) && (!empty($opts['recipe']) || !empty($opts['batch']));
    $title = 'BBT ' . $number . ($state !== 'empty' ? ' — ' . $state : '');
    $tp = [];
    if (!empty($opts['recipe'])) $tp[] = htmlspecialchars($opts['recipe'], ENT_XML1, 'UTF-8');
    if (!empty($opts['batch']))  $tp[] = '#' . htmlspecialchars((string)$opts['batch'], ENT_XML1, 'UTF-8');
    if ($tp) $title .= ' (' . implode(' ', $tp) . ')';

    ob_start();
    echo '<svg class="' . htmlspecialchars($svg_class, ENT_QUOTES, 'UTF-8') . '"'
        . ' viewBox="0 0 80 155" xmlns="http://www.w3.org/2000/svg"'
        . ' role="img" aria-label="' . htmlspecialchars($title, ENT_XML1, 'UTF-8') . '">';
    echo '<title>' . htmlspecialchars($title, ENT_XML1, 'UTF-8') . '</title>';
    echo '<defs>';
    echo '<clipPath id="' . $clipId . '"><rect x="' . $cylLeft . '" y="' . $cylTop . '" width="' . ($cylRight-$cylLeft) . '" height="' . $cylH . '"/></clipPath>';
    echo '<pattern id="' . $pxId . '" x="0" y="0" width="3.8" height="3.8" patternUnits="userSpaceOnUse">'
        . '<line x1="1.9" y1="0" x2="1.9" y2="3.8" stroke="var(--oak-deep,#5a3a12)" stroke-width="0.55" opacity="0.28"/>'
        . '</pattern>';
    echo '<pattern id="' . $pdId . '" x="0" y="0" width="3.5" height="3.5" patternUnits="userSpaceOnUse">'
        . '<line x1="0" y1="0" x2="3.5" y2="3.5" stroke="var(--oak-deep,#5a3a12)" stroke-width="0.58" opacity="0.52"/>'
        . '<line x1="3.5" y1="0" x2="0" y2="3.5" stroke="var(--oak-deep,#5a3a12)" stroke-width="0.58" opacity="0.52"/>'
        . '</pattern>';
    echo '</defs>';

    $shOp = $has_fill ? '0.18' : '0.10';
    echo '<ellipse cx="40" cy="152" rx="28" ry="2" fill="rgba(47,109,153,' . $shOp . ')"/>';

    /* Paper fill first, THEN tint on top (calibration 2026-06-07) */
    echo '<rect x="' . $cylLeft . '" y="' . $cylTop . '" width="' . ($cylRight-$cylLeft) . '" height="' . $cylH . '" fill="var(--bg,#f1e8d4)" stroke="none"/>';
    echo '<ellipse cx="40" cy="' . $cylTop . '" rx="' . $rx . '" ry="' . $ry . '" fill="var(--bg,#f1e8d4)" stroke="none"/>';
    echo '<ellipse cx="40" cy="' . $cylBot . '" rx="' . $rx . '" ry="' . $ry . '" fill="var(--bg,#f1e8d4)" stroke="none"/>';

    if ($has_fill) {
        echo '<rect x="' . $cylLeft . '" y="' . $fillY . '" width="' . ($cylRight-$cylLeft) . '" height="' . $fillH . '"'
            . ' fill="' . $tintColor . '" opacity="' . $tintOp . '" clip-path="url(#' . $clipId . ')"/>';
    }

    echo '<rect x="20" y="' . $cylTop . '" width="28" height="' . $cylH . '" fill="url(#' . $pxId . ')" clip-path="url(#' . $clipId . ')"/>';
    echo '<rect x="' . $cylLeft . '" y="' . ($cylTop-1) . '" width="' . ($cylRight-$cylLeft) . '" height="12" fill="url(#' . $pdId . ')" clip-path="url(#' . $clipId . ')"/>';
    echo '<rect x="' . $cylLeft . '" y="' . ($cylBot-7) . '" width="' . ($cylRight-$cylLeft) . '" height="12" fill="url(#' . $pdId . ')" clip-path="url(#' . $clipId . ')"/>';
    echo '<rect x="13" y="52" width="54" height="9" fill="url(#' . $pdId . ')" clip-path="url(#' . $clipId . ')" opacity="0.72"/>';
    echo '<rect x="13" y="90" width="54" height="9" fill="url(#' . $pdId . ')" clip-path="url(#' . $clipId . ')" opacity="0.65"/>';

    $odk = 'stroke="var(--oak-deep,#5a3a12)"';
    echo '<line x1="13.5" y1="' . ($cylTop+1) . '" x2="13.5" y2="' . ($cylBot-1) . '" ' . $odk . ' stroke-width="0.65" opacity="0.60"/>';
    echo '<line x1="15.2" y1="' . ($cylTop+0.5) . '" x2="15.1" y2="' . ($cylBot-1) . '" ' . $odk . ' stroke-width="0.60" opacity="0.55"/>';
    echo '<line x1="16.8" y1="' . ($cylTop+1) . '" x2="16.9" y2="' . ($cylBot-0.5) . '" ' . $odk . ' stroke-width="0.55" opacity="0.48"/>';
    echo '<line x1="18.3" y1="' . ($cylTop+0.5) . '" x2="18.2" y2="' . ($cylBot-1) . '" ' . $odk . ' stroke-width="0.50" opacity="0.40"/>';
    echo '<line x1="66.3" y1="' . ($cylTop+1) . '" x2="66.2" y2="' . ($cylBot-0.5) . '" ' . $odk . ' stroke-width="0.65" opacity="0.62"/>';
    echo '<line x1="64.6" y1="' . ($cylTop+0.5) . '" x2="64.7" y2="' . ($cylBot-1) . '" ' . $odk . ' stroke-width="0.60" opacity="0.56"/>';
    echo '<line x1="63.0" y1="' . ($cylTop+1) . '" x2="62.9" y2="' . ($cylBot-0.5) . '" ' . $odk . ' stroke-width="0.55" opacity="0.50"/>';
    echo '<line x1="61.3" y1="' . ($cylTop+0.5) . '" x2="61.4" y2="' . ($cylBot-1) . '" ' . $odk . ' stroke-width="0.50" opacity="0.43"/>';
    echo '<line x1="59.7" y1="' . ($cylTop+1) . '" x2="59.6" y2="' . ($cylBot-0.5) . '" ' . $odk . ' stroke-width="0.45" opacity="0.36"/>';
    echo '<line x1="' . $cylLeft . '" y1="52" x2="' . $cylRight . '" y2="52" ' . $odk . ' stroke-width="1.2" opacity="0.58"/>';
    echo '<line x1="' . $cylLeft . '" y1="90" x2="' . $cylRight . '" y2="90" ' . $odk . ' stroke-width="1.2" opacity="0.58"/>';

    if ($has_fill) {
        /* Calibration 2026-06-07: sw 1.4, op 0.90 + meniscus ticks */
        echo '<line x1="' . $cylLeft . '" y1="' . $fillY . '" x2="' . $cylRight . '" y2="' . $fillY . '"'
            . ' stroke="var(--bbt,#2f6d99)" stroke-width="1.4" opacity="0.90" stroke-dasharray="3,2"/>';
        echo '<line x1="' . $cylLeft  . '" y1="' . ($fillY-2) . '" x2="' . $cylLeft  . '" y2="' . ($fillY+2) . '"'
            . ' stroke="var(--bbt,#2f6d99)" stroke-width="1.2" opacity="0.85" stroke-linecap="round"/>';
        echo '<line x1="' . $cylRight . '" y1="' . ($fillY-2) . '" x2="' . $cylRight . '" y2="' . ($fillY+2) . '"'
            . ' stroke="var(--bbt,#2f6d99)" stroke-width="1.2" opacity="0.85" stroke-linecap="round"/>';
    }

    /* BBT graduation ticks on right wall (25/50/75/100%) + hairline labels */
    $tickPcts = [100 => (int)round($cylTop), 75 => (int)round($cylTop + $cylH*0.25), 50 => (int)round($cylTop + $cylH*0.5), 25 => (int)round($cylTop + $cylH*0.75)];
    foreach ($tickPcts as $pct => $ty) {
        echo '<line x1="' . $cylRight . '" y1="' . $ty . '" x2="' . ($cylRight+3) . '" y2="' . $ty . '"'
            . ' stroke="var(--oak-deep,#5a3a12)" stroke-width="0.8" opacity="0.55"/>';
        /* right-anchored at the viewBox edge so "100" never clips (viewBox is 80 wide) */
        echo '<text x="79.5" y="' . ($ty+1.8) . '" text-anchor="end"'
            . ' font-family="\'JetBrains Mono\',ui-monospace,monospace" font-size="4.5"'
            . ' fill="var(--ink-mute,#8a7560)" opacity="0.70">' . $pct . '</text>';
    }

    echo '<rect x="' . $cylLeft . '" y="' . $cylTop . '" width="' . ($cylRight-$cylLeft) . '" height="' . $cylH . '"'
        . ' fill="none" ' . $odk . ' stroke-width="2.0"/>';
    echo '<ellipse cx="40" cy="' . $cylTop . '" rx="' . $rx . '" ry="' . $ry . '" fill="none" ' . $odk . ' stroke-width="1.8"/>';
    echo '<ellipse cx="40" cy="' . $cylBot . '" rx="' . $rx . '" ry="' . $ry . '" fill="none" ' . $odk . ' stroke-width="1.8"/>';
    echo '<rect x="6" y="60" width="6" height="12" rx="1" fill="var(--bg,#f1e8d4)" ' . $odk . ' stroke-width="1.1"/>';
    echo '<rect x="36" y="8" width="8" height="7" fill="var(--bg,#f1e8d4)" ' . $odk . ' stroke-width="0.9"/>';
    echo '<line x1="38" y1="7" x2="38" y2="3" ' . $odk . ' stroke-width="1.0"/>';
    echo '<line x1="42" y1="7" x2="42" y2="3" ' . $odk . ' stroke-width="1.0"/>';
    echo '<rect x="' . $cylRight . '" y="105" width="8" height="4" fill="var(--bg,#f1e8d4)" ' . $odk . ' stroke-width="0.9"/>';

    if ($fill_pct >= 0.9 && $state === 'ready') {
        echo '<path d="M 34,70 L 38,75 L 47,63" fill="none" stroke="var(--ok,#3d6826)"'
            . ' stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round" opacity="0.75"/>';
    }

    echo '<path d="M 19,' . ($cylBot+5) . ' L 21,' . ($cylBot+5) . ' L 16,152 L 14,152 Z" fill="var(--bg,#f1e8d4)" ' . $odk . ' stroke-width="1.1"/>';
    echo '<path d="M 39,' . ($cylBot+5) . ' L 41,' . ($cylBot+5) . ' L 41,152 L 39,152 Z" fill="var(--bg,#f1e8d4)" ' . $odk . ' stroke-width="1.1"/>';
    echo '<path d="M 59,' . ($cylBot+5) . ' L 61,' . ($cylBot+5) . ' L 65,152 L 63,152 Z" fill="var(--bg,#f1e8d4)" ' . $odk . ' stroke-width="1.1"/>';
    echo '<rect x="12" y="151" width="6" height="2" fill="var(--oak-deep,#5a3a12)" opacity="0.58"/>';
    echo '<rect x="37" y="151" width="6" height="2" fill="var(--oak-deep,#5a3a12)" opacity="0.58"/>';
    echo '<rect x="61" y="151" width="6" height="2" fill="var(--oak-deep,#5a3a12)" opacity="0.58"/>';

    echo '<text x="40" y="40" text-anchor="middle"'
        . ' font-family="\'JetBrains Mono\',ui-monospace,monospace" font-size="13" font-weight="500"'
        . ' fill="' . $numColor . '" opacity="' . $numOp . '">' . $number . '</text>';

    if ($showLabel) {
        $lp = [];
        if (!empty($opts['recipe'])) $lp[] = htmlspecialchars($opts['recipe'], ENT_XML1, 'UTF-8');
        if (!empty($opts['batch']))  $lp[] = '#' . htmlspecialchars((string)$opts['batch'], ENT_XML1, 'UTF-8');
        echo '<text x="40" y="80" text-anchor="middle"'
            . ' font-family="\'DM Sans\',ui-sans-serif,sans-serif" font-size="7.5" font-weight="500"'
            . ' fill="var(--ink-soft,#5a4028)" opacity="0.70">' . implode(' ', $lp) . '</text>';
    }

    echo '</svg>';
    return trim(ob_get_clean());
}
endif;

/* ── svg_vessel_kettle ───────────────────────────────────────────────────── */

/**
 * Brewhouse kettle — gravure-dense squat kettle (viewBox 0 0 72 80).
 * Adapts the cylGravure + sceneDefs pattern from salle-des-machines.js
 * into a static PHP SVG. Same geometry + state contract as svg-vessels.php.
 *
 * @param int    $number  Kettle number (usually 1; >1 renders number label)
 * @param string $state   'idle'|'mashing'|'boiling'|'whirlpool'|'transferring'
 * @param array  $opts    []
 * @return string         Inline SVG, no <?xml header
 */
if (!function_exists('svg_vessel_kettle')):
function svg_vessel_kettle(int $number = 1, string $state = 'idle', array $opts = []): string {
    $uid     = _sv_uid('kettle', $number);
    $hatchId = 'hatch_' . $uid;
    $pxId    = 'px_'   . $uid;
    $pdId    = 'pd_'   . $uid;
    $wortId  = 'wort_' . $uid;

    $is_active = in_array($state, ['mashing', 'boiling', 'whirlpool', 'transferring'], true);
    $has_wort  = $is_active;
    $wort_top  = ($state === 'transferring') ? 43 : 22;

    /* Animation class hooks — preserved from svg-vessels.php */
    $svg_class = 'sb-vessel__svg sb-vessel__svg--kettle';
    if ($is_active) $svg_class .= ' sb-vessel--pulsing';

    $state_label = match($state) {
        'mashing'      => 'empâtage',
        'boiling'      => 'ébullition',
        'whirlpool'    => 'whirlpool',
        'transferring' => 'transfert',
        default        => 'vide',
    };
    $title        = htmlspecialchars('Chaudière ' . $number . ' — ' . $state_label, ENT_XML1, 'UTF-8');
    $needleAngle  = $is_active ? '40' : '-30';
    $odk          = 'stroke="var(--oak-deep,#5a3a12)"';

    ob_start();
    echo '<svg class="' . htmlspecialchars($svg_class, ENT_QUOTES, 'UTF-8') . '"'
        . ' viewBox="0 0 72 80" xmlns="http://www.w3.org/2000/svg"'
        . ' role="img" aria-label="' . $title . '">';
    echo '<title>' . $title . '</title>';
    echo '<defs>';
    echo '<pattern id="' . $hatchId . '" width="5" height="5" patternUnits="userSpaceOnUse" patternTransform="rotate(45)">'
        . '<line x1="0" y1="0" x2="0" y2="5" stroke="var(--ember,#b34428)" stroke-width="0.5" opacity="0.30"/>'
        . '</pattern>';
    echo '<pattern id="' . $pxId . '" x="0" y="0" width="3.8" height="3.8" patternUnits="userSpaceOnUse">'
        . '<line x1="1.9" y1="0" x2="1.9" y2="3.8" ' . $odk . ' stroke-width="0.55" opacity="0.27"/>'
        . '</pattern>';
    echo '<pattern id="' . $pdId . '" x="0" y="0" width="3.5" height="3.5" patternUnits="userSpaceOnUse">'
        . '<line x1="0" y1="0" x2="3.5" y2="3.5" ' . $odk . ' stroke-width="0.58" opacity="0.52"/>'
        . '<line x1="3.5" y1="0" x2="0" y2="3.5" ' . $odk . ' stroke-width="0.58" opacity="0.52"/>'
        . '</pattern>';
    if ($has_wort) {
        echo '<linearGradient id="' . $wortId . '" x1="0" y1="0" x2="0" y2="1">'
            . '<stop offset="0%" stop-color="var(--ember,#b34428)" stop-opacity="0.55"/>'
            . '<stop offset="100%" stop-color="var(--ember,#b34428)" stop-opacity="0.35"/>'
            . '</linearGradient>';
    }
    echo '</defs>';

    /* Paper body fill */
    echo '<rect x="8" y="15" width="56" height="50" rx="1" fill="var(--bg,#f1e8d4)" stroke="none"/>';
    /* Centre sparse hatch (left 40%) */
    echo '<rect x="8" y="15" width="22" height="50" fill="url(#' . $pxId . ')"/>';
    /* Right shadow cross-hatch (right ~22%) */
    echo '<rect x="52" y="15" width="12" height="50" fill="url(#' . $pdId . ')"/>';
    /* Top dome shadow band */
    echo '<rect x="8" y="15" width="56" height="8" fill="url(#' . $pdId . ')" opacity="0.78"/>';

    /* Left edge contour (4 strokes) */
    echo '<line x1="9.5" y1="16" x2="9.5" y2="64" ' . $odk . ' stroke-width="0.60" opacity="0.56"/>';
    echo '<line x1="11.1" y1="15" x2="11.1" y2="64" ' . $odk . ' stroke-width="0.55" opacity="0.50"/>';
    echo '<line x1="12.6" y1="16" x2="12.6" y2="64" ' . $odk . ' stroke-width="0.50" opacity="0.43"/>';
    echo '<line x1="14.1" y1="15" x2="14.1" y2="64" ' . $odk . ' stroke-width="0.45" opacity="0.35"/>';
    /* Right edge contour (5 strokes) */
    echo '<line x1="62.5" y1="16" x2="62.5" y2="64" ' . $odk . ' stroke-width="0.65" opacity="0.60"/>';
    echo '<line x1="60.8" y1="15" x2="60.8" y2="64" ' . $odk . ' stroke-width="0.60" opacity="0.55"/>';
    echo '<line x1="59.2" y1="16" x2="59.2" y2="64" ' . $odk . ' stroke-width="0.55" opacity="0.48"/>';
    echo '<line x1="57.6" y1="15" x2="57.6" y2="64" ' . $odk . ' stroke-width="0.50" opacity="0.40"/>';
    echo '<line x1="55.9" y1="16" x2="55.9" y2="64" ' . $odk . ' stroke-width="0.45" opacity="0.32"/>';

    /* Wort fill */
    if ($has_wort) {
        echo '<rect x="9" y="' . $wort_top . '" width="54" height="' . (65 - $wort_top) . '" fill="url(#' . $wortId . ')"/>';
    }

    /* Heating jacket hatch (lower third) + border */
    echo '<rect x="8" y="53" width="56" height="12" fill="url(#' . $hatchId . ')"/>';
    echo '<rect x="8" y="53" width="56" height="12" fill="none" ' . $odk . ' stroke-width="0.6" opacity="0.45"/>';

    /* Weld seam */
    echo '<line x1="8" y1="43" x2="64" y2="43" ' . $odk . ' stroke-width="1.0" opacity="0.48"/>';

    /* Body outline (on top of hatching) */
    echo '<rect x="8" y="15" width="56" height="50" rx="1" fill="none" ' . $odk . ' stroke-width="1.85"/>';

    /* Top rim / lid */
    echo '<rect x="4" y="10" width="64" height="7" rx="1" fill="var(--bg,#f1e8d4)" ' . $odk . ' stroke-width="1.6"/>';
    echo '<rect x="4" y="10" width="64" height="7" fill="url(#' . $pdId . ')" opacity="0.55"/>';
    /* Center manhole */
    echo '<ellipse cx="36" cy="13" rx="8" ry="3" fill="var(--bg-side,#dcc9a4)" ' . $odk . ' stroke-width="0.7"/>';
    echo '<ellipse cx="36" cy="13" rx="5" ry="1.8" fill="none" ' . $odk . ' stroke-width="0.35" opacity="0.50"/>';

    /* Steam wisps (active states; CSS gates animation via .steam-wisp) */
    if ($is_active) {
        echo '<path class="steam-wisp" d="M34 8 q-3 -5 1 -9" fill="none" ' . $odk . ' stroke-width="0.8" stroke-linecap="round" opacity="0.55"/>';
        echo '<path class="steam-wisp" d="M38 6 q4 -5 -1 -9" fill="none" ' . $odk . ' stroke-width="0.8" stroke-linecap="round" opacity="0.45" style="animation-delay:0.6s"/>';
        echo '<path class="steam-wisp" d="M42 8 q-2 -6 2 -10" fill="none" ' . $odk . ' stroke-width="0.8" stroke-linecap="round" opacity="0.40" style="animation-delay:1.2s"/>';
    }

    /* Calandre internal ellipse (boiling / whirlpool) */
    if ($state === 'boiling' || $state === 'whirlpool') {
        echo '<ellipse cx="36" cy="43" rx="10" ry="12" fill="none" ' . $odk . ' stroke-width="0.9" stroke-dasharray="3,2" opacity="0.50"/>';
    }

    /* Pressure gauge (left side) */
    echo '<rect x="1" y="30" width="6" height="3.5" fill="var(--bg-side,#dcc9a4)" ' . $odk . ' stroke-width="0.5"/>';
    echo '<circle cx="-1.5" cy="31.75" r="3" fill="var(--bg,#f1e8d4)" ' . $odk . ' stroke-width="0.6"/>';
    echo '<circle cx="-1.5" cy="31.75" r="2.2" fill="rgba(245,235,220,0.92)" ' . $odk . ' stroke-width="0.25" opacity="0.50"/>';
    echo '<line x1="-1.5" y1="31.75" x2="-1.5" y2="30.0" stroke="var(--ember,#b34428)" stroke-width="0.5"'
        . ' transform="rotate(' . $needleAngle . ', -1.5, 31.75)"/>';
    echo '<circle cx="-1.5" cy="31.75" r="0.45" fill="var(--oak-deep,#5a3a12)"/>';

    /* Outlet pipe (right side) */
    echo '<rect x="64" y="57" width="8" height="2.5" fill="var(--bg-side,#dcc9a4)" ' . $odk . ' stroke-width="0.4"/>';
    echo '<rect x="70" y="55" width="2" height="18" fill="var(--bg-side,#dcc9a4)" ' . $odk . ' stroke-width="0.4"/>';

    /* Four legs */
    foreach ([14, 27, 45, 58] as $lx) {
        echo '<rect x="' . $lx . '" y="65" width="4" height="12" rx="0.5" fill="var(--bg-side,#dcc9a4)" ' . $odk . ' stroke-width="0.6"/>';
    }

    /* Contact shadow */
    echo '<ellipse cx="36" cy="78" rx="28" ry="1.5" fill="rgba(90,58,18,0.18)"/>';

    /* Kettle number (multi-kettle layout only) */
    if ($number > 1) {
        $nFill = $has_wort ? 'var(--ember,#b34428)' : 'var(--ink-mute,#8a7560)';
        echo '<text x="36" y="50" text-anchor="middle"'
            . ' font-family="\'JetBrains Mono\',ui-monospace,monospace" font-size="11" font-weight="500"'
            . ' fill="' . $nFill . '" opacity="0.65">' . $number . '</text>';
    }

    echo '</svg>';
    return trim(ob_get_clean());
}
endif;

/* ── svg_vessel_packaging_line ───────────────────────────────────────────── */

/**
 * Packaging line — Conditionnement zone horizontal conveyor (viewBox 0 0 200 60).
 * CSS hooks: .sb-conveyor-lines / .sb-conveyor-lines--idle / .sb-vessel--pulsing
 *
 * @param string $state  'idle'|'running'|'changeover'|'maintenance'
 * @param array  $opts   []
 * @return string        Inline SVG, no <?xml header
 */
if (!function_exists('svg_vessel_packaging_line')):
function svg_vessel_packaging_line(string $state = 'idle', array $opts = []): string {
    $uid  = _sv_uid('pkg', 0);
    $pxId = 'pkgpx_' . $uid;
    $pdId = 'pkgpd_' . $uid;

    $is_running = $state === 'running';
    $is_maint   = $state === 'maintenance';
    $is_change  = $state === 'changeover';

    $line_color   = 'var(--hop,#567020)';   $line_opacity = '0.40';
    if ($is_maint)      { $line_color = 'var(--ember,#b34428)'; $line_opacity = '0.30'; }
    elseif ($is_change) { $line_color = 'var(--oak,#8b5e2a)';   $line_opacity = '0.35'; }

    $bottle_fill = $is_running ? 'var(--bbt,#2f6d99)' : 'var(--bg-side,#dcc9a4)';
    $bot_op1     = $is_running ? '0.70' : '0.40';
    $bot_op2     = $is_running ? '0.70' : '0.40';

    /* Animation class hooks — preserved from svg-vessels.php */
    $svg_class = 'sb-vessel__svg sb-vessel__svg--packaging';
    if ($is_running) $svg_class .= ' sb-vessel--pulsing';

    $state_label = match($state) {
        'running'     => 'en cours',
        'changeover'  => 'changement de format',
        'maintenance' => 'maintenance',
        default       => 'arrêt',
    };
    $title = htmlspecialchars('Ligne de conditionnement — ' . $state_label, ENT_XML1, 'UTF-8');
    $odk   = 'stroke="var(--oak-deep,#5a3a12)"';

    ob_start();
    echo '<svg class="' . htmlspecialchars($svg_class, ENT_QUOTES, 'UTF-8') . '"'
        . ' viewBox="0 0 200 60" xmlns="http://www.w3.org/2000/svg"'
        . ' role="img" aria-label="' . $title . '">';
    echo '<title>' . $title . '</title>';
    echo '<defs>';
    echo '<pattern id="' . $pxId . '" x="0" y="0" width="3.8" height="3.8" patternUnits="userSpaceOnUse">'
        . '<line x1="1.9" y1="0" x2="1.9" y2="3.8" ' . $odk . ' stroke-width="0.55" opacity="0.26"/>'
        . '</pattern>';
    echo '<pattern id="' . $pdId . '" x="0" y="0" width="3.5" height="3.5" patternUnits="userSpaceOnUse">'
        . '<line x1="0" y1="0" x2="3.5" y2="3.5" ' . $odk . ' stroke-width="0.55" opacity="0.48"/>'
        . '<line x1="3.5" y1="0" x2="0" y2="3.5" ' . $odk . ' stroke-width="0.55" opacity="0.48"/>'
        . '</pattern>';
    echo '</defs>';

    /* Filler heads — gravure paper fill */
    foreach ([70, 90, 110] as $fx) {
        echo '<rect x="' . $fx . '" y="1" width="12" height="22" rx="1" fill="var(--bg,#f1e8d4)" ' . $odk . ' stroke-width="1.1"/>';
        echo '<rect x="' . ($fx+9) . '" y="1" width="3" height="22" fill="url(#' . $pdId . ')" opacity="0.70"/>';
    }
    echo '<line x1="76"  y1="23" x2="76"  y2="29" ' . $odk . ' stroke-width="1.0"/>';
    echo '<line x1="96"  y1="23" x2="96"  y2="29" ' . $odk . ' stroke-width="1.0"/>';
    echo '<line x1="116" y1="23" x2="116" y2="29" ' . $odk . ' stroke-width="1.0"/>';

    /* Conveyor belt — gravure paper fill */
    echo '<rect x="5" y="32" width="190" height="14" rx="1" fill="var(--bg-side,#dcc9a4)" ' . $odk . ' stroke-width="1.0"/>';
    echo '<rect x="5" y="32" width="90" height="14" fill="url(#' . $pxId . ')"/>';
    echo '<rect x="5" y="32" width="190" height="3" fill="url(#' . $pdId . ')" opacity="0.50"/>';

    /* Belt motion lines — CSS-animated when running */
    $convClass = $is_running ? 'sb-conveyor-lines' : 'sb-conveyor-lines sb-conveyor-lines--idle';
    echo '<g class="' . $convClass . '">';
    for ($x = 10; $x <= 190; $x += 15) {
        echo '<line x1="' . $x . '" y1="33" x2="' . $x . '" y2="45"'
            . ' stroke="' . $line_color . '" stroke-width="0.8" opacity="' . $line_opacity . '"/>';
    }
    echo '</g>';

    /* End rollers — gravure paper fill */
    echo '<circle cx="8"   cy="39" r="6" fill="var(--bg,#f1e8d4)" ' . $odk . ' stroke-width="1.1"/>';
    echo '<circle cx="192" cy="39" r="6" fill="var(--bg,#f1e8d4)" ' . $odk . ' stroke-width="1.1"/>';
    echo '<circle cx="8"   cy="39" r="1.5" fill="var(--oak-deep,#5a3a12)" opacity="0.55"/>';
    echo '<circle cx="192" cy="39" r="1.5" fill="var(--oak-deep,#5a3a12)" opacity="0.55"/>';

    /* Bottles/cans on belt */
    echo '<rect x="30" y="28" width="8" height="16" rx="1"'
        . ' fill="' . $bottle_fill . '" opacity="' . $bot_op1 . '" ' . $odk . ' stroke-width="0.6"/>';
    echo '<rect x="46" y="28" width="8" height="16" rx="1"'
        . ' fill="' . $bottle_fill . '" opacity="' . $bot_op2 . '" ' . $odk . ' stroke-width="0.6"/>';
    echo '<rect x="62" y="28" width="8" height="16" rx="1"'
        . ' fill="' . $bottle_fill . '" opacity="0.35" ' . $odk . ' stroke-width="0.6"/>';

    /* Maintenance X overlay */
    if ($is_maint) {
        echo '<line x1="5" y1="32" x2="195" y2="46" stroke="var(--ember,#b34428)" stroke-width="1.0" opacity="0.28"/>';
        echo '<line x1="5" y1="46" x2="195" y2="32" stroke="var(--ember,#b34428)" stroke-width="1.0" opacity="0.20"/>';
    }

    /* Contact shadow */
    echo '<ellipse cx="100" cy="48" rx="90" ry="2" fill="rgba(90,58,18,0.12)"/>';
    echo '</svg>';
    return trim(ob_get_clean());
}
endif;
