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

    /* ── Fill tint color for the liquid rect (under hatching, op 0.14-0.17) ── */
    if ($isMaint) {
        $tintColor = 'var(--tank-empty,#cfc6b2)';
        $tintOp    = 0.22;
    } elseif ($isCold) {
        $tintColor = 'var(--cold,#2f5575)';
        $tintOp    = 0.15;
    } elseif ($isFerm) {
        $tintColor = 'var(--hop,#567020)';
        $tintOp    = 0.17;
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

  <?php if ($fillRatio > 0 && !$isMaint): ?>
  <!-- Beer fill tint — UNDER all hatching (op 0.14-0.17) -->
  <rect x="<?= $cylLeft ?>" y="<?= $fillY ?>"
    width="<?= $cylRight - $cylLeft ?>" height="<?= $fillH + ($coneBot - $cylBot) ?>"
    fill="<?= $tintColor ?>" opacity="<?= $tintOp ?>"
    clip-path="url(#<?= $clipId ?>)"/>
  <?php endif ?>

  <!-- Paper body fill (no stroke — outlines rendered on top) -->
  <polygon points="<?= $bodyPts ?>" fill="var(--bg,#f1e8d4)" stroke="none"/>
  <rect x="<?= $cylLeft ?>" y="<?= $capTop ?>" width="<?= $cylRight - $cylLeft ?>" height="<?= $capH ?>"
    fill="var(--bg,#f1e8d4)" stroke="none"/>

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
  <!-- Fill surface dashed line -->
  <line x1="<?= $cylLeft ?>" y1="<?= $fillY ?>" x2="<?= $cylRight ?>" y2="<?= $fillY ?>"
    stroke="<?= $tintColor ?>" stroke-width="0.9" opacity="0.60" stroke-dasharray="3,2"/>
  <?php endif ?>

  <?php if ($isFerm && $fillRatio > 0): ?>
  <!-- Fermentation bubbles (CSS animation hook: class="ferment-bubble") -->
  <circle cx="30" cy="<?= max($fillY + 6, 68) ?>" r="1.2" class="ferment-bubble" fill="var(--hop,#567020)" opacity="0.28"/>
  <circle cx="46" cy="<?= max($fillY + 16, 78) ?>" r="0.9" class="ferment-bubble" fill="var(--hop,#567020)" opacity="0.22" style="animation-delay:1.1s"/>
  <circle cx="54" cy="<?= max($fillY + 4, 60) ?>" r="1.0" class="ferment-bubble" fill="var(--hop,#567020)" opacity="0.18" style="animation-delay:2.0s"/>
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
    if ($isMaint) {
        $tintColor = 'var(--tank-empty,#cfc6b2)';
        $tintOp    = 0.22;
    } else {
        $tintColor = 'var(--bbt,#2f6d99)';
        $tintOp    = 0.17;
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

  <?php if ($fillRatio > 0 && !$isMaint): ?>
  <!-- Beer fill tint — UNDER all hatching -->
  <rect x="<?= $cylLeft ?>" y="<?= $fillY ?>" width="<?= $cylRight - $cylLeft ?>" height="<?= $fillH ?>"
    fill="<?= $tintColor ?>" opacity="<?= $tintOp ?>"
    clip-path="url(#<?= $clipId ?>)"/>
  <?php endif ?>

  <!-- Paper fills (no stroke yet) -->
  <rect x="<?= $cylLeft ?>" y="<?= $cylTop ?>" width="<?= $cylRight - $cylLeft ?>" height="<?= $cylH ?>"
    fill="var(--bg,#f1e8d4)" stroke="none"/>
  <ellipse cx="40" cy="<?= $cylTop ?>" rx="<?= $rx ?>" ry="<?= $ry ?>" fill="var(--bg,#f1e8d4)" stroke="none"/>
  <ellipse cx="40" cy="<?= $cylBot ?>" rx="<?= $rx ?>" ry="<?= $ry ?>" fill="var(--bg,#f1e8d4)" stroke="none"/>

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
  <!-- Fill surface dashed line -->
  <line x1="<?= $cylLeft ?>" y1="<?= $fillY ?>" x2="<?= $cylRight ?>" y2="<?= $fillY ?>"
    stroke="var(--bbt,#2f6d99)" stroke-width="0.9" opacity="0.60" stroke-dasharray="3,2"/>
  <?php endif ?>
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
