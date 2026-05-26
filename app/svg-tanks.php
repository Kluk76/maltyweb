<?php
declare(strict_types=1);

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

    $cylTop    = 10;
    $cylBot    = 100;
    $coneBot   = 130;
    $cylLeft   = 12;
    $cylRight  = 68;
    $conePoint = 40;
    $cylH      = $cylBot - $cylTop;

    $isMaint = str_contains($stateClass, 'maint');
    $isCold  = str_contains($stateClass, 'cold');
    $isFerm  = str_contains($stateClass, 'ferment');
    $fullThreshold = 0.85;
    $isUnderfilled = $variant === 'fill' && $isFerm && $fillRatio > 0 && $fillRatio < $fullThreshold;
    if ($isMaint) {
        $fillColour  = '#cfc6b2';
        $fillOpacity = '0.5';
    } elseif ($isCold) {
        $fillColour  = 'var(--cold)';
        $fillOpacity = '0.82';
    } elseif ($isFerm) {
        $fillColour  = $isUnderfilled ? 'var(--ember)' : 'var(--hop)';
        $fillOpacity = '0.82';
    } else {
        $fillColour  = 'none';
        $fillOpacity = '0';
    }

    $emptyH = (int) round($cylH * (1.0 - $fillRatio));
    $fillY  = $cylTop + $emptyH;
    $fillH  = $cylBot - $fillY;

    $uid        = 'cct' . $number . '_' . substr(md5((string)microtime(true) . $number), 0, 6);
    $clipId     = 'clip_' . $uid;
    $gradId     = 'grad_' . $uid;
    $legGradId  = 'leggrad_' . $uid;
    $pipeGradId = 'pipegrad_' . $uid;

    $accOp = $isMaint ? '0.55' : '1';

    ob_start(); ?>
<svg class="tank-svg" viewBox="0 0 80 155" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="CCT <?= $number ?>">
  <defs>
    <clipPath id="<?= $clipId ?>">
      <polygon points="
        <?= $cylLeft ?>,<?= $cylTop ?>
        <?= $cylRight ?>,<?= $cylTop ?>
        <?= $cylRight ?>,<?= $cylBot ?>
        <?= $conePoint + 6 ?>,<?= $coneBot ?>
        <?= $conePoint - 6 ?>,<?= $coneBot ?>
        <?= $cylLeft ?>,<?= $cylBot ?>
      "/>
    </clipPath>
    <linearGradient id="<?= $gradId ?>" x1="0" y1="0" x2="1" y2="0">
      <stop offset="0%" stop-color="rgba(0,0,0,0.10)"/>
      <stop offset="12%" stop-color="rgba(0,0,0,0.03)"/>
      <stop offset="100%" stop-color="rgba(0,0,0,0)"/>
    </linearGradient>
    <linearGradient id="<?= $legGradId ?>" x1="0" y1="0" x2="1" y2="0">
      <stop offset="0%"  stop-color="var(--steel-shadow)"/>
      <stop offset="35%" stop-color="var(--steel-light)" stop-opacity="0.35"/>
      <stop offset="55%" stop-color="var(--steel-mid)"/>
      <stop offset="100%" stop-color="var(--steel-shadow)"/>
    </linearGradient>
    <linearGradient id="<?= $pipeGradId ?>" x1="0" y1="0" x2="1" y2="0">
      <stop offset="0%"  stop-color="var(--steel-shadow)"/>
      <stop offset="40%" stop-color="var(--steel)"/>
      <stop offset="65%" stop-color="var(--steel-mid)"/>
      <stop offset="100%" stop-color="var(--steel-shadow)"/>
    </linearGradient>
  </defs>

  <g opacity="<?= $accOp ?>">
    <path d="M 73,8 Q 73,3 68,3 L 60,3"
      fill="none" stroke="url(#<?= $pipeGradId ?>)" stroke-width="2.2" stroke-linecap="round"/>
    <path d="M 73,8 Q 73,3 68,3 L 60,3"
      fill="none" stroke="var(--steel-shadow)" stroke-width="0.5" stroke-linecap="round" opacity="0.6"/>
    <rect x="72" y="6" width="2" height="86"
      fill="url(#<?= $pipeGradId ?>)" stroke="var(--steel-shadow)" stroke-width="0.3"/>
    <line x1="68" y1="42" x2="72" y2="42"
      stroke="var(--steel-mid)" stroke-width="0.7" opacity="0.85"/>
    <line x1="68" y1="42.8" x2="72" y2="42.8"
      stroke="var(--steel-shadow)" stroke-width="0.4" opacity="0.6"/>
    <rect x="70.5" y="88" width="5" height="4"
      fill="var(--steel-mid)" stroke="var(--steel-shadow)" stroke-width="0.3" rx="0.5"/>
    <rect x="72.4" y="92" width="1.2" height="3" fill="var(--steel-shadow)"/>
  </g>

  <polygon
    points="<?= $cylLeft ?>,<?= $cylTop ?> <?= $cylRight ?>,<?= $cylTop ?> <?= $cylRight ?>,<?= $cylBot ?> <?= $conePoint + 6 ?>,<?= $coneBot ?> <?= $conePoint - 6 ?>,<?= $coneBot ?> <?= $cylLeft ?>,<?= $cylBot ?>"
    fill="var(--steel-deep)" stroke="var(--steel-mid)" stroke-width="1.2"/>

  <rect x="<?= $cylLeft ?>" y="<?= $cylTop - 8 ?>" width="<?= $cylRight - $cylLeft ?>" height="8"
    fill="var(--steel-deep)" stroke="var(--steel-mid)" stroke-width="1.2"/>
  <line x1="<?= $cylLeft ?>" y1="<?= $cylTop - 8 ?>" x2="<?= $cylRight ?>" y2="<?= $cylTop - 8 ?>"
    stroke="var(--steel-light)" stroke-width="0.8" opacity="0.5"/>

  <?php
    $rectFillOpacity = ($variant === 'fill') ? (float)$fillOpacity : (float)$fillOpacity * 0.35;
  ?>
  <?php if ($fillRatio > 0 && !$isMaint): ?>
  <rect
    x="<?= $cylLeft ?>" y="<?= $fillY ?>"
    width="<?= $cylRight - $cylLeft ?>" height="<?= $fillH + ($coneBot - $cylBot) ?>"
    fill="<?= $fillColour ?>" opacity="<?= $rectFillOpacity ?>"
    clip-path="url(#<?= $clipId ?>)"/>
  <?php endif ?>

  <g opacity="<?= $accOp ?>">
    <path d="M <?= $cylLeft ?>,<?= $cylBot ?> L <?= $cylRight ?>,<?= $cylBot ?> L <?= $conePoint + 4 ?>,<?= $coneBot - 8 ?> L <?= $conePoint - 4 ?>,<?= $coneBot - 8 ?> Z"
      fill="var(--steel-shadow)" opacity="0.6" clip-path="url(#<?= $clipId ?>)"/>
    <line x1="22" y1="100" x2="34" y2="121" stroke="var(--steel-mid)" stroke-width="0.4" opacity="0.45"/>
    <line x1="32" y1="100" x2="36.5" y2="121" stroke="var(--steel-mid)" stroke-width="0.4" opacity="0.45"/>
    <line x1="48" y1="100" x2="43.5" y2="121" stroke="var(--steel-mid)" stroke-width="0.4" opacity="0.45"/>
    <line x1="58" y1="100" x2="46" y2="121" stroke="var(--steel-mid)" stroke-width="0.4" opacity="0.45"/>
    <line x1="<?= $cylLeft ?>" y1="100" x2="<?= $cylRight ?>" y2="100"
      stroke="var(--steel-mid)" stroke-width="0.7" opacity="0.75"/>
    <line x1="<?= $cylLeft ?>" y1="100.8" x2="<?= $cylRight ?>" y2="100.8"
      stroke="var(--steel-light)" stroke-width="0.3" opacity="0.4"/>
    <line x1="<?= $conePoint - 4 ?>" y1="121.5" x2="<?= $conePoint + 4 ?>" y2="121.5"
      stroke="var(--steel-mid)" stroke-width="0.5" opacity="0.65"/>
    <rect x="<?= $cylRight ?>" y="105" width="3" height="1.4"
      fill="var(--steel-mid)" stroke="var(--steel-shadow)" stroke-width="0.2"/>
    <rect x="<?= $cylRight - 1 ?>" y="116" width="3" height="1.4"
      fill="var(--steel-mid)" stroke="var(--steel-shadow)" stroke-width="0.2"/>
  </g>

  <rect
    x="<?= $cylLeft ?>" y="<?= $cylTop ?>"
    width="5" height="<?= $cylH ?>"
    fill="url(#<?= $gradId ?>)" clip-path="url(#<?= $clipId ?>)"/>

  <line x1="<?= $cylLeft + 1 ?>" y1="44" x2="<?= $cylRight - 1 ?>" y2="44"
    stroke="var(--steel-mid)" stroke-width="0.5" opacity="0.4"/>
  <line x1="<?= $cylLeft + 1 ?>" y1="72" x2="<?= $cylRight - 1 ?>" y2="72"
    stroke="var(--steel-mid)" stroke-width="0.5" opacity="0.4"/>

  <g opacity="<?= $accOp ?>">
    <rect x="22" y="-1" width="1.6" height="3.5"
      fill="var(--steel-mid)" stroke="var(--steel-shadow)" stroke-width="0.2"/>
    <ellipse cx="22.8" cy="-1.5" rx="2.6" ry="1.4"
      fill="var(--steel)" stroke="var(--steel-shadow)" stroke-width="0.3"/>
    <ellipse cx="22.8" cy="-1.8" rx="1.6" ry="0.7"
      fill="none" stroke="var(--steel-light)" stroke-width="0.3" opacity="0.55"/>
  </g>

  <g opacity="<?= $accOp ?>">
    <rect x="6" y="55" width="6" height="3"
      fill="var(--steel-mid)" stroke="var(--steel-shadow)" stroke-width="0.3"/>
    <circle cx="7.4" cy="56.5" r="0.7" fill="var(--steel-shadow)"/>
    <path d="M 6,57.5 Q 3,68 4.5,90 Q 5.5,108 9,128"
      fill="none" stroke="var(--steel-shadow)" stroke-width="0.7" opacity="0.75"/>
    <path d="M 6,57.5 Q 3,68 4.5,90 Q 5.5,108 9,128"
      fill="none" stroke="var(--steel-light)" stroke-width="0.25" opacity="0.25"/>
  </g>

  <g opacity="<?= $accOp ?>">
    <circle cx="40" cy="64" r="4.6"
      fill="var(--steel-mid)" stroke="var(--steel-shadow)" stroke-width="0.4"/>
    <?php if ($fillRatio > 0 && !$isMaint): ?>
    <circle cx="40" cy="64" r="3.2"
      fill="<?= $fillColour ?>" opacity="<?= $rectFillOpacity * 0.9 ?>"/>
    <?php else: ?>
    <circle cx="40" cy="64" r="3.2" fill="var(--steel-shadow)" opacity="0.85"/>
    <?php endif ?>
    <path d="M 38,62 Q 39,61.3 40.5,61.5"
      fill="none" stroke="rgba(0,0,0,0.18)" stroke-width="0.4"/>
    <circle cx="40"   cy="59.6" r="0.4" fill="var(--steel-shadow)"/>
    <circle cx="44.2" cy="64"   r="0.4" fill="var(--steel-shadow)"/>
    <circle cx="40"   cy="68.4" r="0.4" fill="var(--steel-shadow)"/>
    <circle cx="35.8" cy="64"   r="0.4" fill="var(--steel-shadow)"/>
  </g>

  <g opacity="<?= $accOp ?>">
    <rect x="<?= $cylRight ?>" y="88" width="4" height="1.6"
      fill="var(--steel-mid)" stroke="var(--steel-shadow)" stroke-width="0.25"/>
    <circle cx="<?= $cylRight + 4.5 ?>" cy="88.8" r="1.1"
      fill="var(--steel-mid)" stroke="var(--steel-shadow)" stroke-width="0.3"/>
    <circle cx="<?= $cylRight + 4.5 ?>" cy="88.8" r="0.4" fill="var(--steel-shadow)"/>
  </g>

  <rect x="38" y="130" width="4" height="3.5"
    fill="var(--steel-mid)" stroke="var(--steel-shadow)" stroke-width="0.3"/>
  <rect x="39" y="133.5" width="2" height="2" fill="var(--steel-shadow)"/>
  <circle cx="44.5" cy="131.6" r="1.1"
    fill="var(--steel-mid)" stroke="var(--steel-shadow)" stroke-width="0.3" opacity="<?= $accOp ?>"/>
  <circle cx="44.5" cy="131.6" r="0.4" fill="var(--steel-shadow)" opacity="<?= $accOp ?>"/>

  <g opacity="<?= $accOp ?>">
    <rect x="<?= $cylLeft - 0.5 ?>" y="108" width="<?= $cylRight - $cylLeft + 1 ?>" height="3"
      fill="var(--steel-mid)" stroke="var(--steel-shadow)" stroke-width="0.4"/>
    <line x1="<?= $cylLeft ?>" y1="108.6" x2="<?= $cylRight ?>" y2="108.6"
      stroke="var(--steel-light)" stroke-width="0.4" opacity="0.45"/>
    <path d="M 19,111 L 21,111 L 16.5,147 L 13.5,147 Z"
      fill="url(#<?= $legGradId ?>)" stroke="var(--steel-shadow)" stroke-width="0.35"/>
    <path d="M 39,111 L 41,111 L 41.2,147 L 38.8,147 Z"
      fill="url(#<?= $legGradId ?>)" stroke="var(--steel-shadow)" stroke-width="0.35"/>
    <path d="M 59,111 L 61,111 L 66.5,147 L 63.5,147 Z"
      fill="url(#<?= $legGradId ?>)" stroke="var(--steel-shadow)" stroke-width="0.35"/>
    <rect x="11" y="146.5" width="7"  height="2.2" fill="var(--steel-shadow)"/>
    <rect x="36.5" y="146.5" width="7" height="2.2" fill="var(--steel-shadow)"/>
    <rect x="62" y="146.5" width="7"  height="2.2" fill="var(--steel-shadow)"/>
    <ellipse cx="40" cy="151" rx="32" ry="1.8" fill="var(--steel-shadow)" opacity="0.35"/>
  </g>

  <text
    x="40" y="30" text-anchor="middle"
    font-family="'JetBrains Mono', ui-monospace, monospace"
    font-size="14" font-weight="500"
    fill="rgba(0,0,0,0.75)" stroke="rgba(255,255,255,0.5)" stroke-width="0.4" paint-order="stroke"
  ><?= $number ?></text>

</svg>
<?php
    return ob_get_clean();
}
endif;

if (!function_exists('svg_bbt')):
function svg_bbt(float $fillRatio, string $stateClass = '', int $number = 0, string $beer = ''): string {
    $fillRatio = max(0.0, min(1.0, $fillRatio));

    $cylTop   = 15;
    $cylBot   = 125;
    $cylLeft  = 12;
    $cylRight = 68;
    $cylH     = $cylBot - $cylTop;

    $isMaint = str_contains($stateClass, 'maint');
    if ($isMaint) {
        $fillColour  = '#cfc6b2';
        $fillOpacity = '0.5';
    } else {
        $fillColour  = 'var(--bbt)';
        $fillOpacity = '0.82';
    }

    $emptyH = (int) round($cylH * (1.0 - $fillRatio));
    $fillY  = $cylTop + $emptyH;
    $fillH  = $cylBot - $fillY;

    $uid        = 'bbt' . $number . '_' . substr(md5((string)microtime(true) . 'b' . $number), 0, 6);
    $clipId     = 'clip_' . $uid;
    $gradId     = 'grad_' . $uid;
    $legGradId  = 'leggrad_' . $uid;
    $pipeGradId = 'pipegrad_' . $uid;
    $rx         = 28;
    $ry         = 5;

    $accOp = $isMaint ? '0.55' : '1';

    ob_start(); ?>
<svg class="tank-svg" viewBox="0 0 80 155" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="BBT <?= $number ?>">
  <defs>
    <clipPath id="<?= $clipId ?>">
      <rect x="<?= $cylLeft ?>" y="<?= $cylTop ?>" width="<?= $cylRight - $cylLeft ?>" height="<?= $cylH ?>"/>
    </clipPath>
    <linearGradient id="<?= $gradId ?>" x1="0" y1="0" x2="1" y2="0">
      <stop offset="0%" stop-color="rgba(0,0,0,0.10)"/>
      <stop offset="12%" stop-color="rgba(0,0,0,0.03)"/>
      <stop offset="100%" stop-color="rgba(0,0,0,0)"/>
    </linearGradient>
    <linearGradient id="<?= $legGradId ?>" x1="0" y1="0" x2="1" y2="0">
      <stop offset="0%"  stop-color="var(--steel-shadow)"/>
      <stop offset="35%" stop-color="var(--steel-light)" stop-opacity="0.35"/>
      <stop offset="55%" stop-color="var(--steel-mid)"/>
      <stop offset="100%" stop-color="var(--steel-shadow)"/>
    </linearGradient>
    <linearGradient id="<?= $pipeGradId ?>" x1="0" y1="0" x2="1" y2="0">
      <stop offset="0%"  stop-color="var(--steel-shadow)"/>
      <stop offset="40%" stop-color="var(--steel)"/>
      <stop offset="65%" stop-color="var(--steel-mid)"/>
      <stop offset="100%" stop-color="var(--steel-shadow)"/>
    </linearGradient>
  </defs>

  <g opacity="<?= $accOp ?>">
    <path d="M 73,12 Q 73,7 68,7 L 60,7"
      fill="none" stroke="url(#<?= $pipeGradId ?>)" stroke-width="2.2" stroke-linecap="round"/>
    <path d="M 73,12 Q 73,7 68,7 L 60,7"
      fill="none" stroke="var(--steel-shadow)" stroke-width="0.5" stroke-linecap="round" opacity="0.6"/>
    <rect x="72" y="10" width="2" height="92"
      fill="url(#<?= $pipeGradId ?>)" stroke="var(--steel-shadow)" stroke-width="0.3"/>
    <line x1="68" y1="48" x2="72" y2="48"
      stroke="var(--steel-mid)" stroke-width="0.7" opacity="0.85"/>
    <line x1="68" y1="48.8" x2="72" y2="48.8"
      stroke="var(--steel-shadow)" stroke-width="0.4" opacity="0.6"/>
    <line x1="68" y1="85" x2="72" y2="85"
      stroke="var(--steel-mid)" stroke-width="0.7" opacity="0.85"/>
    <line x1="68" y1="85.8" x2="72" y2="85.8"
      stroke="var(--steel-shadow)" stroke-width="0.4" opacity="0.6"/>
    <rect x="70.5" y="98" width="5" height="4"
      fill="var(--steel-mid)" stroke="var(--steel-shadow)" stroke-width="0.3" rx="0.5"/>
    <rect x="72.4" y="102" width="1.2" height="3" fill="var(--steel-shadow)"/>
  </g>

  <rect
    x="<?= $cylLeft ?>" y="<?= $cylTop ?>"
    width="<?= $cylRight - $cylLeft ?>" height="<?= $cylH ?>"
    fill="var(--steel-deep)" stroke="var(--steel-mid)" stroke-width="1.2"/>

  <ellipse cx="40" cy="<?= $cylTop ?>" rx="<?= $rx ?>" ry="<?= $ry ?>"
    fill="var(--steel-deep)" stroke="var(--steel-mid)" stroke-width="1.2"/>
  <ellipse cx="40" cy="<?= $cylTop ?>" rx="<?= $rx - 4 ?>" ry="<?= $ry - 1 ?>"
    fill="none" stroke="var(--steel-light)" stroke-width="0.6" opacity="0.35"/>

  <ellipse cx="40" cy="<?= $cylBot ?>" rx="<?= $rx ?>" ry="<?= $ry ?>"
    fill="var(--steel-deep)" stroke="var(--steel-mid)" stroke-width="1.2"/>

  <?php if ($fillRatio > 0 && !$isMaint): ?>
  <rect
    x="<?= $cylLeft ?>" y="<?= $fillY ?>"
    width="<?= $cylRight - $cylLeft ?>" height="<?= $fillH ?>"
    fill="<?= $fillColour ?>" opacity="<?= $fillOpacity ?>"
    clip-path="url(#<?= $clipId ?>)"/>
  <?php endif ?>

  <g opacity="<?= $accOp ?>">
    <line x1="24" y1="<?= $cylTop + 2 ?>" x2="24" y2="<?= $cylBot - 2 ?>"
      stroke="var(--steel-mid)" stroke-width="0.35" opacity="0.32"/>
    <line x1="40" y1="<?= $cylTop + 2 ?>" x2="40" y2="<?= $cylBot - 2 ?>"
      stroke="var(--steel-mid)" stroke-width="0.35" opacity="0.32"/>
    <line x1="56" y1="<?= $cylTop + 2 ?>" x2="56" y2="<?= $cylBot - 2 ?>"
      stroke="var(--steel-mid)" stroke-width="0.35" opacity="0.32"/>
    <line x1="<?= $cylLeft ?>" y1="<?= $cylTop + 2 ?>" x2="<?= $cylRight ?>" y2="<?= $cylTop + 2 ?>"
      stroke="var(--steel-mid)" stroke-width="0.5" opacity="0.55"/>
    <line x1="<?= $cylLeft ?>" y1="<?= $cylBot - 2 ?>" x2="<?= $cylRight ?>" y2="<?= $cylBot - 2 ?>"
      stroke="var(--steel-mid)" stroke-width="0.5" opacity="0.55"/>
  </g>

  <rect
    x="<?= $cylLeft ?>" y="<?= $cylTop ?>"
    width="5" height="<?= $cylH ?>"
    fill="url(#<?= $gradId ?>)" clip-path="url(#<?= $clipId ?>)"/>

  <line x1="<?= $cylLeft + 1 ?>" y1="55" x2="<?= $cylRight - 1 ?>" y2="55"
    stroke="var(--steel-mid)" stroke-width="0.5" opacity="0.4"/>
  <line x1="<?= $cylLeft + 1 ?>" y1="90" x2="<?= $cylRight - 1 ?>" y2="90"
    stroke="var(--steel-mid)" stroke-width="0.5" opacity="0.4"/>

  <g opacity="<?= $accOp ?>">
    <rect x="22" y="3" width="1.6" height="4"
      fill="var(--steel-mid)" stroke="var(--steel-shadow)" stroke-width="0.2"/>
    <ellipse cx="22.8" cy="2.5" rx="2.8" ry="1.6"
      fill="var(--steel)" stroke="var(--steel-shadow)" stroke-width="0.3"/>
    <ellipse cx="22.8" cy="2.2" rx="1.7" ry="0.8"
      fill="none" stroke="var(--steel-light)" stroke-width="0.3" opacity="0.55"/>
  </g>

  <g opacity="<?= $accOp ?>">
    <rect x="32" y="2" width="1.5" height="6"
      fill="var(--steel-mid)" stroke="var(--steel-shadow)" stroke-width="0.2"/>
    <rect x="30.5" y="6.5" width="4.5" height="1.4"
      fill="var(--steel-mid)" stroke="var(--steel-shadow)" stroke-width="0.25"/>
    <rect x="31.5" y="0.8" width="3" height="1.4" fill="var(--steel-shadow)"/>
  </g>

  <g opacity="<?= $accOp ?>">
    <rect x="<?= $cylLeft - 1 ?>" y="100" width="3" height="1.4"
      fill="var(--steel-mid)" stroke="var(--steel-shadow)" stroke-width="0.2"/>
    <circle cx="<?= $cylLeft - 3.5 ?>" cy="100.7" r="3"
      fill="var(--steel)" stroke="var(--steel-shadow)" stroke-width="0.4"/>
    <circle cx="<?= $cylLeft - 3.5 ?>" cy="100.7" r="2.2"
      fill="rgba(245,235,220,0.9)" stroke="var(--steel-mid)" stroke-width="0.2"/>
    <line x1="<?= $cylLeft - 3.5 ?>" y1="99.0" x2="<?= $cylLeft - 3.5 ?>" y2="99.5"
      stroke="var(--steel-shadow)" stroke-width="0.3"/>
    <line x1="<?= $cylLeft - 1.8 ?>" y1="100.7" x2="<?= $cylLeft - 2.3 ?>" y2="100.7"
      stroke="var(--steel-shadow)" stroke-width="0.3"/>
    <line x1="<?= $cylLeft - 5.2 ?>" y1="100.7" x2="<?= $cylLeft - 4.7 ?>" y2="100.7"
      stroke="var(--steel-shadow)" stroke-width="0.3"/>
    <?php $needleAngle = $fillRatio > 0 ? -40 : -90; ?>
    <line
      x1="<?= $cylLeft - 3.5 ?>" y1="100.7"
      x2="<?= $cylLeft - 3.5 + 1.6 * cos(deg2rad($needleAngle)) ?>"
      y2="<?= 100.7 + 1.6 * sin(deg2rad($needleAngle)) ?>"
      stroke="var(--ember)" stroke-width="0.45"/>
    <circle cx="<?= $cylLeft - 3.5 ?>" cy="100.7" r="0.4" fill="var(--steel-shadow)"/>
  </g>

  <g opacity="<?= $accOp ?>">
    <circle cx="40" cy="72" r="4.6"
      fill="var(--steel-mid)" stroke="var(--steel-shadow)" stroke-width="0.4"/>
    <?php if ($fillRatio > 0 && !$isMaint): ?>
    <circle cx="40" cy="72" r="3.2"
      fill="<?= $fillColour ?>" opacity="<?= (float)$fillOpacity * 0.9 ?>"/>
    <?php else: ?>
    <circle cx="40" cy="72" r="3.2" fill="var(--steel-shadow)" opacity="0.85"/>
    <?php endif ?>
    <path d="M 38,70 Q 39,69.3 40.5,69.5"
      fill="none" stroke="rgba(0,0,0,0.18)" stroke-width="0.4"/>
    <circle cx="40"   cy="67.6" r="0.4" fill="var(--steel-shadow)"/>
    <circle cx="44.2" cy="72"   r="0.4" fill="var(--steel-shadow)"/>
    <circle cx="40"   cy="76.4" r="0.4" fill="var(--steel-shadow)"/>
    <circle cx="35.8" cy="72"   r="0.4" fill="var(--steel-shadow)"/>
  </g>

  <rect x="38" y="129" width="4" height="3"
    fill="var(--steel-mid)" stroke="var(--steel-shadow)" stroke-width="0.3"/>
  <rect x="39" y="132" width="2" height="2" fill="var(--steel-shadow)"/>
  <circle cx="46" cy="130.5" r="1.2"
    fill="var(--steel-mid)" stroke="var(--steel-shadow)" stroke-width="0.3" opacity="<?= $accOp ?>"/>
  <line x1="44.8" y1="130.5" x2="47.2" y2="130.5"
    stroke="var(--steel-shadow)" stroke-width="0.3" opacity="<?= $accOp ?>"/>
  <line x1="46" y1="129.3" x2="46" y2="131.7"
    stroke="var(--steel-shadow)" stroke-width="0.3" opacity="<?= $accOp ?>"/>

  <g opacity="<?= $accOp ?>">
    <rect x="<?= $cylLeft - 0.5 ?>" y="128" width="<?= $cylRight - $cylLeft + 1 ?>" height="3"
      fill="var(--steel-mid)" stroke="var(--steel-shadow)" stroke-width="0.4"/>
    <line x1="<?= $cylLeft ?>" y1="128.6" x2="<?= $cylRight ?>" y2="128.6"
      stroke="var(--steel-light)" stroke-width="0.4" opacity="0.45"/>
    <path d="M 19,131 L 21,131 L 16.5,147 L 13.5,147 Z"
      fill="url(#<?= $legGradId ?>)" stroke="var(--steel-shadow)" stroke-width="0.35"/>
    <path d="M 39,131 L 41,131 L 41.2,147 L 38.8,147 Z"
      fill="url(#<?= $legGradId ?>)" stroke="var(--steel-shadow)" stroke-width="0.35"/>
    <path d="M 59,131 L 61,131 L 66.5,147 L 63.5,147 Z"
      fill="url(#<?= $legGradId ?>)" stroke="var(--steel-shadow)" stroke-width="0.35"/>
    <rect x="11" y="146.5" width="7"  height="2.2" fill="var(--steel-shadow)"/>
    <rect x="36.5" y="146.5" width="7" height="2.2" fill="var(--steel-shadow)"/>
    <rect x="62" y="146.5" width="7"  height="2.2" fill="var(--steel-shadow)"/>
    <ellipse cx="40" cy="151" rx="32" ry="1.8" fill="var(--steel-shadow)" opacity="0.35"/>
  </g>

  <text
    x="40" y="37" text-anchor="middle"
    font-family="'JetBrains Mono', ui-monospace, monospace"
    font-size="14" font-weight="500"
    fill="rgba(0,0,0,0.75)" stroke="rgba(255,255,255,0.5)" stroke-width="0.4" paint-order="stroke"
  ><?= $number ?></text>
</svg>
<?php
    return ob_get_clean();
}
endif;
