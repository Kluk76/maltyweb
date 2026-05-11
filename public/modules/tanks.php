<?php
declare(strict_types=1);

require __DIR__ . "/../../app/auth.php";

require_login();
$me = current_user();

header("Content-Type: text/html; charset=utf-8");

$active_module = "fermentation";
$crumbs        = ["Accueil", "Fermentation", "Tank Board"];

// --- Date helpers (FR locale, consistent with wort.php) ---
$monthsFR = [
    1 => "jan", 2 => "fév", 3 => "mar", 4 => "avr",
    5 => "mai", 6 => "jun", 7 => "jul", 8 => "aoû",
    9 => "sep", 10 => "oct", 11 => "nov", 12 => "déc",
];

$monthsFRFull = [
    1  => "janvier",   2  => "février",   3  => "mars",
    4  => "avril",     5  => "mai",        6  => "juin",
    7  => "juillet",   8  => "août",       9  => "septembre",
    10 => "octobre",   11 => "novembre",   12 => "décembre",
];

// --- As-of filter -----------------------------------------------------------
$todayDT      = new DateTimeImmutable('today');
$minDate      = new DateTimeImmutable('2023-10-01');
$currentYear  = (int)$todayDT->format('Y');

// Parse GET params (strict: ctype_digit + range)
$asOfDT       = $todayDT; // default = today
$filterActive = false;

$_gy = $_GET['year']  ?? '';
$_gm = $_GET['month'] ?? '';
$_gd = $_GET['day']   ?? '';

if (ctype_digit($_gy) && ctype_digit($_gm) && ctype_digit($_gd)) {
    $py = (int)$_gy;
    $pm = (int)$_gm;
    $pd = (int)$_gd;
    if ($py >= 2023 && $py <= $currentYear && $pm >= 1 && $pm <= 12 && $pd >= 1 && $pd <= 31) {
        $parsed = DateTimeImmutable::createFromFormat('Y-n-j', "{$py}-{$pm}-{$pd}");
        if ($parsed !== false) {
            // Clamp to [minDate, today]
            if ($parsed > $todayDT) $parsed = $todayDT;
            if ($parsed < $minDate) $parsed = $minDate;
            $asOfDT       = $parsed;
            $filterActive = ($asOfDT->format('Y-m-d') !== $todayDT->format('Y-m-d'));
        }
    }
}

$selYear  = (int)$asOfDT->format('Y');
$selMonth = (int)$asOfDT->format('n');
$selDay   = (int)$asOfDT->format('j');

// FR-formatted as-of date for display (e.g. "12 mars 2026")
function fmt_date_fr_tanks_full(DateTimeImmutable $dt, array $monthsFull): string {
    return sprintf('%d %s %s', (int)$dt->format('j'), $monthsFull[(int)$dt->format('n')], $dt->format('Y'));
}

function fmt_date_fr_tanks(string $dateStr, array $months): string {
    $ts = strtotime($dateStr);
    if ($ts === false) return htmlspecialchars($dateStr);
    $d = (int) date("j", $ts);
    $m = (int) date("n", $ts);
    $y = date("Y", $ts);
    return sprintf("%d %s %s", $d, $months[$m], $y);
}

// --- SVG helper: cylindro-conical CCT ---
function svg_cct(float $fillRatio, string $stateClass = '', int $number = 0, string $beer = ''): string {
    $fillRatio = max(0.0, min(1.0, $fillRatio));

    // Geometry
    $cylTop    = 10;   // top of cylinder body
    $cylBot    = 100;  // bottom of cylinder body (start of cone)
    $coneBot   = 130;  // cone tip
    $cylLeft   = 12;
    $cylRight  = 68;
    $conePoint = 40;   // x-centre for cone tip (width 12: 34..46)
    $cylH      = $cylBot - $cylTop;  // 90

    // State-driven colour palette:
    //   green   — active fermentation, near-full (normal state)
    //   red     — fermentation but tank is unexpectedly under-filled (anomaly)
    //   blue    — cold crash (cool, dormant)
    //   grey    — maintenance / retired
    //   none    — empty
    $isMaint = str_contains($stateClass, 'maint');
    $isCold  = str_contains($stateClass, 'cold');
    $isFerm  = str_contains($stateClass, 'ferment');
    // Threshold for "full enough" — below this, fermenting tank flags red.
    $fullThreshold = 0.85;
    $isUnderfilled = $isFerm && $fillRatio > 0 && $fillRatio < $fullThreshold;
    if ($isMaint) {
        $fillColour  = '#3a3a3c';
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

    // Fill level: empty at top, beer from bottom
    // fillRatio 1.0 = full cylinder; we clip fill rect to cylinder+cone polygon
    $emptyH    = (int) round($cylH * (1.0 - $fillRatio));
    $fillY     = $cylTop + $emptyH;
    $fillH     = $cylBot - $fillY; // height of fill within cylinder rect

    $uid = 'cct' . $number . '_' . substr(md5((string)microtime(true) . $number), 0, 6);
    $clipId    = 'clip_' . $uid;
    $gradId    = 'grad_' . $uid;
    $legGradId = 'leggrad_' . $uid;
    $pipeGradId = 'pipegrad_' . $uid;

    // Leg & accessory opacity — dimmed for maintenance state
    $accOp = $isMaint ? '0.55' : '1';

    ob_start(); ?>
<svg class="tank-svg" viewBox="0 0 80 155" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="CCT <?= $number ?>">
  <defs>
    <!-- Shape clip: cylinder + cone -->
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
    <!-- Body highlight gradient -->
    <linearGradient id="<?= $gradId ?>" x1="0" y1="0" x2="1" y2="0">
      <stop offset="0%" stop-color="rgba(255,255,255,0.13)"/>
      <stop offset="12%" stop-color="rgba(255,255,255,0.04)"/>
      <stop offset="100%" stop-color="rgba(255,255,255,0)"/>
    </linearGradient>
    <!-- Cylindrical-pipe shading for legs (highlight band on left) -->
    <linearGradient id="<?= $legGradId ?>" x1="0" y1="0" x2="1" y2="0">
      <stop offset="0%"  stop-color="var(--steel-shadow)"/>
      <stop offset="35%" stop-color="var(--steel-light)" stop-opacity="0.35"/>
      <stop offset="55%" stop-color="var(--steel-mid)"/>
      <stop offset="100%" stop-color="var(--steel-shadow)"/>
    </linearGradient>
    <!-- CIP pipe shading (thinner, softer) -->
    <linearGradient id="<?= $pipeGradId ?>" x1="0" y1="0" x2="1" y2="0">
      <stop offset="0%"  stop-color="var(--steel-shadow)"/>
      <stop offset="40%" stop-color="var(--steel)"/>
      <stop offset="65%" stop-color="var(--steel-mid)"/>
      <stop offset="100%" stop-color="var(--steel-shadow)"/>
    </linearGradient>
  </defs>

  <!-- ═══════════════════════════════════════════════════════════════════ -->
  <!-- CIP arm (drawn first, behind tank, so the tank body covers entry)   -->
  <!-- ═══════════════════════════════════════════════════════════════════ -->
  <g opacity="<?= $accOp ?>">
    <!-- 90° elbow at top: thin curved path entering the cap -->
    <path d="M 73,8 Q 73,3 68,3 L 60,3"
      fill="none"
      stroke="url(#<?= $pipeGradId ?>)"
      stroke-width="2.2"
      stroke-linecap="round"
    />
    <!-- Elbow shadow stroke for definition -->
    <path d="M 73,8 Q 73,3 68,3 L 60,3"
      fill="none"
      stroke="var(--steel-shadow)"
      stroke-width="0.5"
      stroke-linecap="round"
      opacity="0.6"
    />
    <!-- Vertical pipe -->
    <rect x="72" y="6" width="2" height="86"
      fill="url(#<?= $pipeGradId ?>)"
      stroke="var(--steel-shadow)"
      stroke-width="0.3"
    />
    <!-- Mounting bracket (connects pipe to cylinder around 1/3 down) -->
    <line x1="68" y1="42" x2="72" y2="42"
      stroke="var(--steel-mid)" stroke-width="0.7" opacity="0.85"/>
    <line x1="68" y1="42.8" x2="72" y2="42.8"
      stroke="var(--steel-shadow)" stroke-width="0.4" opacity="0.6"/>
    <!-- Bottom valve/diverter flange -->
    <rect x="70.5" y="88" width="5" height="4"
      fill="var(--steel-mid)"
      stroke="var(--steel-shadow)" stroke-width="0.3"
      rx="0.5"
    />
    <!-- Spraybar tip below flange (small protrusion) -->
    <rect x="72.4" y="92" width="1.2" height="3"
      fill="var(--steel-shadow)"
    />
  </g>

  <!-- ═══════════════════════════════════════════════════════════════════ -->
  <!-- Tank body                                                            -->
  <!-- ═══════════════════════════════════════════════════════════════════ -->
  <!-- Cylinder + cone outline -->
  <polygon
    points="<?= $cylLeft ?>,<?= $cylTop ?> <?= $cylRight ?>,<?= $cylTop ?> <?= $cylRight ?>,<?= $cylBot ?> <?= $conePoint + 6 ?>,<?= $coneBot ?> <?= $conePoint - 6 ?>,<?= $coneBot ?> <?= $cylLeft ?>,<?= $cylBot ?>"
    fill="var(--steel-deep)"
    stroke="var(--steel-mid)"
    stroke-width="1.2"
  />

  <!-- Top cap rectangle (manhole) -->
  <rect x="<?= $cylLeft ?>" y="<?= $cylTop - 8 ?>" width="<?= $cylRight - $cylLeft ?>" height="8"
    fill="var(--steel-deep)"
    stroke="var(--steel-mid)"
    stroke-width="1.2"
  />
  <!-- Cap top line (bright edge) -->
  <line x1="<?= $cylLeft ?>" y1="<?= $cylTop - 8 ?>" x2="<?= $cylRight ?>" y2="<?= $cylTop - 8 ?>"
    stroke="var(--steel-light)" stroke-width="0.8" opacity="0.5"/>

  <!-- Beer fill (clipped to shape) -->
  <?php if ($fillRatio > 0 && !$isMaint): ?>
  <rect
    x="<?= $cylLeft ?>" y="<?= $fillY ?>"
    width="<?= $cylRight - $cylLeft ?>" height="<?= $fillH + ($coneBot - $cylBot) ?>"
    fill="<?= $fillColour ?>"
    opacity="<?= $fillOpacity ?>"
    clip-path="url(#<?= $clipId ?>)"
  />
  <?php endif ?>

  <!-- ─── Glycol cooling jacket on cone — the defining feature of a CCT ─── -->
  <!-- Darker band wrapping the upper cone with subtle vertical coil seams -->
  <g opacity="<?= $accOp ?>">
    <path d="M <?= $cylLeft ?>,<?= $cylBot ?> L <?= $cylRight ?>,<?= $cylBot ?> L <?= $conePoint + 4 ?>,<?= $coneBot - 8 ?> L <?= $conePoint - 4 ?>,<?= $coneBot - 8 ?> Z"
      fill="var(--steel-shadow)" opacity="0.6"
      clip-path="url(#<?= $clipId ?>)"
    />
    <!-- Cooling coil seams (vertical lines hinting at wrap) -->
    <line x1="22" y1="100" x2="34" y2="121" stroke="var(--steel-mid)" stroke-width="0.4" opacity="0.45"/>
    <line x1="32" y1="100" x2="36.5" y2="121" stroke="var(--steel-mid)" stroke-width="0.4" opacity="0.45"/>
    <line x1="48" y1="100" x2="43.5" y2="121" stroke="var(--steel-mid)" stroke-width="0.4" opacity="0.45"/>
    <line x1="58" y1="100" x2="46" y2="121" stroke="var(--steel-mid)" stroke-width="0.4" opacity="0.45"/>
    <!-- Top jacket band (thin metal strip) -->
    <line x1="<?= $cylLeft ?>" y1="100" x2="<?= $cylRight ?>" y2="100"
      stroke="var(--steel-mid)" stroke-width="0.7" opacity="0.75"/>
    <line x1="<?= $cylLeft ?>" y1="100.8" x2="<?= $cylRight ?>" y2="100.8"
      stroke="var(--steel-light)" stroke-width="0.3" opacity="0.4"/>
    <!-- Bottom jacket band -->
    <line x1="<?= $conePoint - 4 ?>" y1="121.5" x2="<?= $conePoint + 4 ?>" y2="121.5"
      stroke="var(--steel-mid)" stroke-width="0.5" opacity="0.65"/>
    <!-- Glycol IN port: small pipe stub on right side of jacket -->
    <rect x="<?= $cylRight ?>" y="105" width="3" height="1.4"
      fill="var(--steel-mid)" stroke="var(--steel-shadow)" stroke-width="0.2"/>
    <!-- Glycol OUT port: lower on right -->
    <rect x="<?= $cylRight - 1 ?>" y="116" width="3" height="1.4"
      fill="var(--steel-mid)" stroke="var(--steel-shadow)" stroke-width="0.2"/>
  </g>

  <!-- Highlight stripe (stainless reflection) -->
  <rect
    x="<?= $cylLeft ?>" y="<?= $cylTop ?>"
    width="5" height="<?= $cylH ?>"
    fill="url(#<?= $gradId ?>)"
    clip-path="url(#<?= $clipId ?>)"
  />

  <!-- Seam lines (horizontal bands suggest tank segments) -->
  <line x1="<?= $cylLeft + 1 ?>" y1="44" x2="<?= $cylRight - 1 ?>" y2="44"
    stroke="var(--steel-mid)" stroke-width="0.5" opacity="0.4"/>
  <line x1="<?= $cylLeft + 1 ?>" y1="72" x2="<?= $cylRight - 1 ?>" y2="72"
    stroke="var(--steel-mid)" stroke-width="0.5" opacity="0.4"/>

  <!-- ─── PRV (pressure relief valve) on top cap, left of CIP entry ─── -->
  <g opacity="<?= $accOp ?>">
    <!-- Mushroom dome on a short stem -->
    <rect x="22" y="-1" width="1.6" height="3.5"
      fill="var(--steel-mid)" stroke="var(--steel-shadow)" stroke-width="0.2"/>
    <ellipse cx="22.8" cy="-1.5" rx="2.6" ry="1.4"
      fill="var(--steel)" stroke="var(--steel-shadow)" stroke-width="0.3"/>
    <ellipse cx="22.8" cy="-1.8" rx="1.6" ry="0.7"
      fill="none" stroke="var(--steel-light)" stroke-width="0.3" opacity="0.55"/>
  </g>

  <!-- ─── Temperature probe + cable (left side, mid-cylinder) ─── -->
  <g opacity="<?= $accOp ?>">
    <!-- Probe body protruding from cylinder wall -->
    <rect x="6" y="55" width="6" height="3"
      fill="var(--steel-mid)" stroke="var(--steel-shadow)" stroke-width="0.3"/>
    <circle cx="7.4" cy="56.5" r="0.7" fill="var(--steel-shadow)"/>
    <!-- Cable: smooth curve trailing down -->
    <path d="M 6,57.5 Q 3,68 4.5,90 Q 5.5,108 9,128"
      fill="none"
      stroke="var(--steel-shadow)"
      stroke-width="0.7"
      opacity="0.75"
    />
    <!-- Cable highlight -->
    <path d="M 6,57.5 Q 3,68 4.5,90 Q 5.5,108 9,128"
      fill="none"
      stroke="var(--steel-light)"
      stroke-width="0.25"
      opacity="0.25"
    />
  </g>

  <!-- ─── Side hublot (sight glass) on cylinder face ─── -->
  <g opacity="<?= $accOp ?>">
    <!-- Outer ring (mounting flange) -->
    <circle cx="40" cy="64" r="4.6"
      fill="var(--steel-mid)"
      stroke="var(--steel-shadow)" stroke-width="0.4"/>
    <!-- Inner glass disc — slightly darker (shows beer through) -->
    <?php if ($fillRatio > 0 && !$isMaint): ?>
    <circle cx="40" cy="64" r="3.2"
      fill="<?= $fillColour ?>" opacity="<?= (float)$fillOpacity * 0.9 ?>"/>
    <?php else: ?>
    <circle cx="40" cy="64" r="3.2"
      fill="var(--steel-shadow)" opacity="0.85"/>
    <?php endif ?>
    <!-- Glass highlight (curved reflection) -->
    <path d="M 38,62 Q 39,61.3 40.5,61.5"
      fill="none" stroke="rgba(255,255,255,0.6)" stroke-width="0.4"/>
    <!-- Tiny bolts around flange -->
    <circle cx="40"   cy="59.6" r="0.4" fill="var(--steel-shadow)"/>
    <circle cx="44.2" cy="64"   r="0.4" fill="var(--steel-shadow)"/>
    <circle cx="40"   cy="68.4" r="0.4" fill="var(--steel-shadow)"/>
    <circle cx="35.8" cy="64"   r="0.4" fill="var(--steel-shadow)"/>
  </g>

  <!-- ─── Sample port (lower right cylinder, just above cone) ─── -->
  <g opacity="<?= $accOp ?>">
    <rect x="<?= $cylRight ?>" y="88" width="4" height="1.6"
      fill="var(--steel-mid)" stroke="var(--steel-shadow)" stroke-width="0.25"/>
    <!-- Small handle wheel -->
    <circle cx="<?= $cylRight + 4.5 ?>" cy="88.8" r="1.1"
      fill="var(--steel-mid)" stroke="var(--steel-shadow)" stroke-width="0.3"/>
    <circle cx="<?= $cylRight + 4.5 ?>" cy="88.8" r="0.4"
      fill="var(--steel-shadow)"/>
  </g>

  <!-- Racking valve at cone tip -->
  <rect x="38" y="130" width="4" height="3.5"
    fill="var(--steel-mid)"
    stroke="var(--steel-shadow)" stroke-width="0.3"
  />
  <rect x="39" y="133.5" width="2" height="2"
    fill="var(--steel-shadow)"
  />
  <!-- Tiny handle wheel on racking valve -->
  <circle cx="44.5" cy="131.6" r="1.1"
    fill="var(--steel-mid)" stroke="var(--steel-shadow)" stroke-width="0.3"
    opacity="<?= $accOp ?>"/>
  <circle cx="44.5" cy="131.6" r="0.4"
    fill="var(--steel-shadow)" opacity="<?= $accOp ?>"/>

  <!-- ═══════════════════════════════════════════════════════════════════ -->
  <!-- Support legs (3 visible, splayed)                                    -->
  <!-- ═══════════════════════════════════════════════════════════════════ -->
  <g opacity="<?= $accOp ?>">
    <!-- Structural ring around cone where legs attach -->
    <rect x="<?= $cylLeft - 0.5 ?>" y="108" width="<?= $cylRight - $cylLeft + 1 ?>" height="3"
      fill="var(--steel-mid)"
      stroke="var(--steel-shadow)" stroke-width="0.4"
    />
    <line x1="<?= $cylLeft ?>" y1="108.6" x2="<?= $cylRight ?>" y2="108.6"
      stroke="var(--steel-light)" stroke-width="0.4" opacity="0.45"/>

    <!-- LEFT leg: splayed outward -->
    <path d="M 19,111 L 21,111 L 16.5,147 L 13.5,147 Z"
      fill="url(#<?= $legGradId ?>)"
      stroke="var(--steel-shadow)" stroke-width="0.35"
    />
    <!-- CENTER leg: straight down behind cone -->
    <path d="M 39,111 L 41,111 L 41.2,147 L 38.8,147 Z"
      fill="url(#<?= $legGradId ?>)"
      stroke="var(--steel-shadow)" stroke-width="0.35"
    />
    <!-- RIGHT leg: splayed outward -->
    <path d="M 59,111 L 61,111 L 66.5,147 L 63.5,147 Z"
      fill="url(#<?= $legGradId ?>)"
      stroke="var(--steel-shadow)" stroke-width="0.35"
    />

    <!-- Foot pads -->
    <rect x="11" y="146.5" width="7"  height="2.2" fill="var(--steel-shadow)"/>
    <rect x="36.5" y="146.5" width="7" height="2.2" fill="var(--steel-shadow)"/>
    <rect x="62" y="146.5" width="7"  height="2.2" fill="var(--steel-shadow)"/>

    <!-- Subtle ground shadow -->
    <ellipse cx="40" cy="151" rx="32" ry="1.8"
      fill="var(--steel-shadow)" opacity="0.35"/>
  </g>

  <!-- Tank number overlay (kept last to stay on top) -->
  <text
    x="40" y="30"
    text-anchor="middle"
    font-family="'JetBrains Mono', ui-monospace, monospace"
    font-size="14"
    font-weight="500"
    fill="rgba(255,255,255,0.9)"
    stroke="rgba(0,0,0,0.5)"
    stroke-width="0.4"
    paint-order="stroke"
  ><?= $number ?></text>
</svg>
<?php
    return ob_get_clean();
}

// --- SVG helper: cylindrical BBT ---
function svg_bbt(float $fillRatio, string $stateClass = '', int $number = 0, string $beer = ''): string {
    $fillRatio = max(0.0, min(1.0, $fillRatio));

    $cylTop   = 15;
    $cylBot   = 125;
    $cylLeft  = 12;
    $cylRight = 68;
    $cylH     = $cylBot - $cylTop; // 110

    $isMaint = str_contains($stateClass, 'maint');
    if ($isMaint) {
        $fillColour  = '#3a3a3c';
        $fillOpacity = '0.5';
    } else {
        // BBT = clarified, conditioned beer in a pressurised vessel.
        // Brighter blue tone, distinct from the deeper cold-crash blue used in CCT.
        $fillColour  = 'var(--bbt)';
        $fillOpacity = '0.82';
    }

    $emptyH = (int) round($cylH * (1.0 - $fillRatio));
    $fillY  = $cylTop + $emptyH;
    $fillH  = $cylBot - $fillY;

    $uid       = 'bbt' . $number . '_' . substr(md5((string)microtime(true) . 'b' . $number), 0, 6);
    $clipId    = 'clip_' . $uid;
    $gradId    = 'grad_' . $uid;
    $legGradId = 'leggrad_' . $uid;
    $pipeGradId = 'pipegrad_' . $uid;
    $rx        = 28; // half-width of ellipse for top/bottom caps
    $ry        = 5;  // ellipse height (shallow dome)

    $accOp = $isMaint ? '0.55' : '1';

    ob_start(); ?>
<svg class="tank-svg" viewBox="0 0 80 155" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="BBT <?= $number ?>">
  <defs>
    <clipPath id="<?= $clipId ?>">
      <rect x="<?= $cylLeft ?>" y="<?= $cylTop ?>" width="<?= $cylRight - $cylLeft ?>" height="<?= $cylH ?>"/>
    </clipPath>
    <linearGradient id="<?= $gradId ?>" x1="0" y1="0" x2="1" y2="0">
      <stop offset="0%" stop-color="rgba(255,255,255,0.13)"/>
      <stop offset="12%" stop-color="rgba(255,255,255,0.04)"/>
      <stop offset="100%" stop-color="rgba(255,255,255,0)"/>
    </linearGradient>
    <!-- Cylindrical-pipe shading for legs -->
    <linearGradient id="<?= $legGradId ?>" x1="0" y1="0" x2="1" y2="0">
      <stop offset="0%"  stop-color="var(--steel-shadow)"/>
      <stop offset="35%" stop-color="var(--steel-light)" stop-opacity="0.35"/>
      <stop offset="55%" stop-color="var(--steel-mid)"/>
      <stop offset="100%" stop-color="var(--steel-shadow)"/>
    </linearGradient>
    <!-- CIP pipe shading (softer than legs) -->
    <linearGradient id="<?= $pipeGradId ?>" x1="0" y1="0" x2="1" y2="0">
      <stop offset="0%"  stop-color="var(--steel-shadow)"/>
      <stop offset="40%" stop-color="var(--steel)"/>
      <stop offset="65%" stop-color="var(--steel-mid)"/>
      <stop offset="100%" stop-color="var(--steel-shadow)"/>
    </linearGradient>
  </defs>

  <!-- ═══════════════════════════════════════════════════════════════════ -->
  <!-- CIP arm (drawn first, behind tank)                                   -->
  <!-- ═══════════════════════════════════════════════════════════════════ -->
  <g opacity="<?= $accOp ?>">
    <!-- 90° elbow at top: curves down and enters the dome from above -->
    <path d="M 73,12 Q 73,7 68,7 L 60,7"
      fill="none"
      stroke="url(#<?= $pipeGradId ?>)"
      stroke-width="2.2"
      stroke-linecap="round"
    />
    <path d="M 73,12 Q 73,7 68,7 L 60,7"
      fill="none"
      stroke="var(--steel-shadow)"
      stroke-width="0.5"
      stroke-linecap="round"
      opacity="0.6"
    />
    <!-- Vertical pipe -->
    <rect x="72" y="10" width="2" height="92"
      fill="url(#<?= $pipeGradId ?>)"
      stroke="var(--steel-shadow)"
      stroke-width="0.3"
    />
    <!-- Mid-tank mounting bracket -->
    <line x1="68" y1="48" x2="72" y2="48"
      stroke="var(--steel-mid)" stroke-width="0.7" opacity="0.85"/>
    <line x1="68" y1="48.8" x2="72" y2="48.8"
      stroke="var(--steel-shadow)" stroke-width="0.4" opacity="0.6"/>
    <!-- Lower mounting bracket -->
    <line x1="68" y1="85" x2="72" y2="85"
      stroke="var(--steel-mid)" stroke-width="0.7" opacity="0.85"/>
    <line x1="68" y1="85.8" x2="72" y2="85.8"
      stroke="var(--steel-shadow)" stroke-width="0.4" opacity="0.6"/>
    <!-- Bottom valve/diverter flange -->
    <rect x="70.5" y="98" width="5" height="4"
      fill="var(--steel-mid)"
      stroke="var(--steel-shadow)" stroke-width="0.3"
      rx="0.5"
    />
    <!-- Spraybar tip -->
    <rect x="72.4" y="102" width="1.2" height="3"
      fill="var(--steel-shadow)"
    />
  </g>

  <!-- ═══════════════════════════════════════════════════════════════════ -->
  <!-- Tank body                                                            -->
  <!-- ═══════════════════════════════════════════════════════════════════ -->
  <!-- Cylinder body -->
  <rect
    x="<?= $cylLeft ?>" y="<?= $cylTop ?>"
    width="<?= $cylRight - $cylLeft ?>" height="<?= $cylH ?>"
    fill="var(--steel-deep)"
    stroke="var(--steel-mid)"
    stroke-width="1.2"
  />

  <!-- Domed top cap -->
  <ellipse cx="40" cy="<?= $cylTop ?>" rx="<?= $rx ?>" ry="<?= $ry ?>"
    fill="var(--steel-deep)"
    stroke="var(--steel-mid)"
    stroke-width="1.2"
  />
  <!-- Highlight on top dome -->
  <ellipse cx="40" cy="<?= $cylTop ?>" rx="<?= $rx - 4 ?>" ry="<?= $ry - 1 ?>"
    fill="none"
    stroke="var(--steel-light)"
    stroke-width="0.6"
    opacity="0.35"
  />

  <!-- Domed bottom cap -->
  <ellipse cx="40" cy="<?= $cylBot ?>" rx="<?= $rx ?>" ry="<?= $ry ?>"
    fill="var(--steel-deep)"
    stroke="var(--steel-mid)"
    stroke-width="1.2"
  />

  <!-- Beer fill (clipped to cylinder rect) -->
  <?php if ($fillRatio > 0 && !$isMaint): ?>
  <rect
    x="<?= $cylLeft ?>" y="<?= $fillY ?>"
    width="<?= $cylRight - $cylLeft ?>" height="<?= $fillH ?>"
    fill="<?= $fillColour ?>"
    opacity="<?= $fillOpacity ?>"
    clip-path="url(#<?= $clipId ?>)"
  />
  <?php endif ?>

  <!-- ─── Insulation jacket — vertical panel seams on the cylinder face ─── -->
  <!-- Subtle hatching that hints at insulated panels (BBT stores cold beer) -->
  <g opacity="<?= $accOp ?>">
    <line x1="24" y1="<?= $cylTop + 2 ?>" x2="24" y2="<?= $cylBot - 2 ?>"
      stroke="var(--steel-mid)" stroke-width="0.35" opacity="0.32"/>
    <line x1="40" y1="<?= $cylTop + 2 ?>" x2="40" y2="<?= $cylBot - 2 ?>"
      stroke="var(--steel-mid)" stroke-width="0.35" opacity="0.32"/>
    <line x1="56" y1="<?= $cylTop + 2 ?>" x2="56" y2="<?= $cylBot - 2 ?>"
      stroke="var(--steel-mid)" stroke-width="0.35" opacity="0.32"/>
    <!-- Top and bottom jacket trim bands -->
    <line x1="<?= $cylLeft ?>" y1="<?= $cylTop + 2 ?>" x2="<?= $cylRight ?>" y2="<?= $cylTop + 2 ?>"
      stroke="var(--steel-mid)" stroke-width="0.5" opacity="0.55"/>
    <line x1="<?= $cylLeft ?>" y1="<?= $cylBot - 2 ?>" x2="<?= $cylRight ?>" y2="<?= $cylBot - 2 ?>"
      stroke="var(--steel-mid)" stroke-width="0.5" opacity="0.55"/>
  </g>

  <!-- Highlight stripe -->
  <rect
    x="<?= $cylLeft ?>" y="<?= $cylTop ?>"
    width="5" height="<?= $cylH ?>"
    fill="url(#<?= $gradId ?>)"
    clip-path="url(#<?= $clipId ?>)"
  />

  <!-- Seam lines -->
  <line x1="<?= $cylLeft + 1 ?>" y1="55" x2="<?= $cylRight - 1 ?>" y2="55"
    stroke="var(--steel-mid)" stroke-width="0.5" opacity="0.4"/>
  <line x1="<?= $cylLeft + 1 ?>" y1="90" x2="<?= $cylRight - 1 ?>" y2="90"
    stroke="var(--steel-mid)" stroke-width="0.5" opacity="0.4"/>

  <!-- ─── PRV (pressure relief valve) on top dome ─── -->
  <g opacity="<?= $accOp ?>">
    <rect x="22" y="3" width="1.6" height="4"
      fill="var(--steel-mid)" stroke="var(--steel-shadow)" stroke-width="0.2"/>
    <ellipse cx="22.8" cy="2.5" rx="2.8" ry="1.6"
      fill="var(--steel)" stroke="var(--steel-shadow)" stroke-width="0.3"/>
    <ellipse cx="22.8" cy="2.2" rx="1.7" ry="0.8"
      fill="none" stroke="var(--steel-light)" stroke-width="0.3" opacity="0.55"/>
  </g>

  <!-- ─── CO₂ inlet stub on top dome ─── -->
  <g opacity="<?= $accOp ?>">
    <rect x="32" y="2" width="1.5" height="6"
      fill="var(--steel-mid)" stroke="var(--steel-shadow)" stroke-width="0.2"/>
    <!-- Small flange at base where it joins the dome -->
    <rect x="30.5" y="6.5" width="4.5" height="1.4"
      fill="var(--steel-mid)" stroke="var(--steel-shadow)" stroke-width="0.25"/>
    <!-- Tiny cap on top of stub -->
    <rect x="31.5" y="0.8" width="3" height="1.4"
      fill="var(--steel-shadow)"/>
  </g>

  <!-- ─── Pressure gauge dial (lower-left cylinder) ─── -->
  <g opacity="<?= $accOp ?>">
    <!-- Mounting stub -->
    <rect x="<?= $cylLeft - 1 ?>" y="100" width="3" height="1.4"
      fill="var(--steel-mid)" stroke="var(--steel-shadow)" stroke-width="0.2"/>
    <!-- Gauge body -->
    <circle cx="<?= $cylLeft - 3.5 ?>" cy="100.7" r="3"
      fill="var(--steel)" stroke="var(--steel-shadow)" stroke-width="0.4"/>
    <!-- Gauge face -->
    <circle cx="<?= $cylLeft - 3.5 ?>" cy="100.7" r="2.2"
      fill="rgba(245,235,220,0.9)" stroke="var(--steel-mid)" stroke-width="0.2"/>
    <!-- Tick marks at cardinal positions -->
    <line x1="<?= $cylLeft - 3.5 ?>" y1="99.0" x2="<?= $cylLeft - 3.5 ?>" y2="99.5"
      stroke="var(--steel-shadow)" stroke-width="0.3"/>
    <line x1="<?= $cylLeft - 1.8 ?>" y1="100.7" x2="<?= $cylLeft - 2.3 ?>" y2="100.7"
      stroke="var(--steel-shadow)" stroke-width="0.3"/>
    <line x1="<?= $cylLeft - 5.2 ?>" y1="100.7" x2="<?= $cylLeft - 4.7 ?>" y2="100.7"
      stroke="var(--steel-shadow)" stroke-width="0.3"/>
    <!-- Needle pointing to ~2 o'clock when occupied; flat at empty -->
    <?php $needleAngle = $fillRatio > 0 ? -40 : -90; ?>
    <line
      x1="<?= $cylLeft - 3.5 ?>" y1="100.7"
      x2="<?= $cylLeft - 3.5 + 1.6 * cos(deg2rad($needleAngle)) ?>"
      y2="<?= 100.7 + 1.6 * sin(deg2rad($needleAngle)) ?>"
      stroke="var(--ember)" stroke-width="0.45"
    />
    <circle cx="<?= $cylLeft - 3.5 ?>" cy="100.7" r="0.4"
      fill="var(--steel-shadow)"/>
  </g>

  <!-- ─── Side hublot (sight glass) on cylinder face ─── -->
  <g opacity="<?= $accOp ?>">
    <circle cx="40" cy="72" r="4.6"
      fill="var(--steel-mid)" stroke="var(--steel-shadow)" stroke-width="0.4"/>
    <?php if ($fillRatio > 0 && !$isMaint): ?>
    <circle cx="40" cy="72" r="3.2"
      fill="<?= $fillColour ?>" opacity="<?= (float)$fillOpacity * 0.9 ?>"/>
    <?php else: ?>
    <circle cx="40" cy="72" r="3.2"
      fill="var(--steel-shadow)" opacity="0.85"/>
    <?php endif ?>
    <path d="M 38,70 Q 39,69.3 40.5,69.5"
      fill="none" stroke="rgba(255,255,255,0.6)" stroke-width="0.4"/>
    <circle cx="40"   cy="67.6" r="0.4" fill="var(--steel-shadow)"/>
    <circle cx="44.2" cy="72"   r="0.4" fill="var(--steel-shadow)"/>
    <circle cx="40"   cy="76.4" r="0.4" fill="var(--steel-shadow)"/>
    <circle cx="35.8" cy="72"   r="0.4" fill="var(--steel-shadow)"/>
  </g>

  <!-- Bottom outlet (centred under the dome) -->
  <rect x="38" y="129" width="4" height="3"
    fill="var(--steel-mid)"
    stroke="var(--steel-shadow)" stroke-width="0.3"
  />
  <rect x="39" y="132" width="2" height="2"
    fill="var(--steel-shadow)"
  />
  <!-- Sample valve handle wheel beside outlet -->
  <circle cx="46" cy="130.5" r="1.2"
    fill="var(--steel-mid)" stroke="var(--steel-shadow)" stroke-width="0.3"
    opacity="<?= $accOp ?>"/>
  <line x1="44.8" y1="130.5" x2="47.2" y2="130.5"
    stroke="var(--steel-shadow)" stroke-width="0.3" opacity="<?= $accOp ?>"/>
  <line x1="46" y1="129.3" x2="46" y2="131.7"
    stroke="var(--steel-shadow)" stroke-width="0.3" opacity="<?= $accOp ?>"/>

  <!-- ═══════════════════════════════════════════════════════════════════ -->
  <!-- Support legs                                                         -->
  <!-- ═══════════════════════════════════════════════════════════════════ -->
  <g opacity="<?= $accOp ?>">
    <!-- Structural ring just below cylinder bottom -->
    <rect x="<?= $cylLeft - 0.5 ?>" y="128" width="<?= $cylRight - $cylLeft + 1 ?>" height="3"
      fill="var(--steel-mid)"
      stroke="var(--steel-shadow)" stroke-width="0.4"
    />
    <line x1="<?= $cylLeft ?>" y1="128.6" x2="<?= $cylRight ?>" y2="128.6"
      stroke="var(--steel-light)" stroke-width="0.4" opacity="0.45"/>

    <!-- LEFT leg: splayed outward -->
    <path d="M 19,131 L 21,131 L 16.5,147 L 13.5,147 Z"
      fill="url(#<?= $legGradId ?>)"
      stroke="var(--steel-shadow)" stroke-width="0.35"
    />
    <!-- CENTER leg: straight down (behind outlet) -->
    <path d="M 39,131 L 41,131 L 41.2,147 L 38.8,147 Z"
      fill="url(#<?= $legGradId ?>)"
      stroke="var(--steel-shadow)" stroke-width="0.35"
    />
    <!-- RIGHT leg: splayed outward -->
    <path d="M 59,131 L 61,131 L 66.5,147 L 63.5,147 Z"
      fill="url(#<?= $legGradId ?>)"
      stroke="var(--steel-shadow)" stroke-width="0.35"
    />

    <!-- Foot pads -->
    <rect x="11" y="146.5" width="7"  height="2.2" fill="var(--steel-shadow)"/>
    <rect x="36.5" y="146.5" width="7" height="2.2" fill="var(--steel-shadow)"/>
    <rect x="62" y="146.5" width="7"  height="2.2" fill="var(--steel-shadow)"/>

    <!-- Subtle ground shadow -->
    <ellipse cx="40" cy="151" rx="32" ry="1.8"
      fill="var(--steel-shadow)" opacity="0.35"/>
  </g>

  <!-- Tank number overlay -->
  <text
    x="40" y="37"
    text-anchor="middle"
    font-family="'JetBrains Mono', ui-monospace, monospace"
    font-size="14"
    font-weight="500"
    fill="rgba(255,255,255,0.9)"
    stroke="rgba(0,0,0,0.5)"
    stroke-width="0.4"
    paint-order="stroke"
  ><?= $number ?></text>
</svg>
<?php
    return ob_get_clean();
}

// -------------------------------------------------------------------
// Database queries + event-sourced simulation
// -------------------------------------------------------------------
require __DIR__ . "/../../app/tank-simulator.php";

try {
    $pdo = maltytask_pdo();

    // ---- Reference tables (capacity + status) ----
    $cctRef = $pdo->query("
        SELECT number, capacity_hl, status
        FROM ref_cct
        ORDER BY number ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

    $bbtRef = $pdo->query("
        SELECT number, capacity_hl, status
        FROM ref_bbt
        ORDER BY number ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

    // ---- Run event-sourced simulation ----
    $simState  = (new TankSimulator($pdo))->run($asOfDT);
    $cctSimMap = $simState['cct']; // int → state|null
    $bbtSimMap = $simState['bbt'];

    // ---- Per-CCT supplemental queries (gravity + cold crash) ----
    // Only for occupied CCTs — at most 18 small queries per page load.
    $cctOccMap = [];
    foreach ($cctSimMap as $num => $simRow) {
        if ($simRow === null) continue;

        $beer  = $simRow['beer'];
        $batch = $simRow['batch'];

        // Brewday date for this (beer, batch) → for "J+N days" display
        $brewdayDate = $pdo->prepare(
            'SELECT event_date FROM bd_brewing_brewday
             WHERE bd_beer = :beer AND bd_batch = :batch
             ORDER BY event_date DESC LIMIT 1'
        );
        $brewdayDate->execute([':beer' => $beer, ':batch' => $batch]);
        $brewRow = $brewdayDate->fetch();

        // Recipe short name
        $recipeStmt = $pdo->prepare(
            'SELECT COALESCE(rr.recipe_short_name, rr2.recipe_short_name) AS recipe_short_name,
                    COALESCE(rr.classification, rr2.classification)        AS classification,
                    COALESCE(rc.name, \'\')                                AS client_name
             FROM (SELECT 1) dummy
             LEFT JOIN ref_recipes rr
                 ON  rr.name    = :beer
                 AND rr.vintage = CONCAT(\'20\', LPAD(REGEXP_REPLACE(:batch_v, \'[^0-9].*$\', \'\'), 2, \'0\'))
                 AND rr.vintage <> \'20\'
             LEFT JOIN ref_recipes rr2
                 ON  rr2.name    = :beer2
                 AND rr2.vintage = \'\'
             LEFT JOIN ref_clients rc
                 ON  rc.id = COALESCE(rr.client_id, rr2.client_id)
             LIMIT 1'
        );
        $recipeStmt->execute([':beer' => $beer, ':batch_v' => $batch, ':beer2' => $beer]);
        $recipeRow = $recipeStmt->fetch() ?: [];

        // Beer-specific prefix used in operator-typed fermenting cells.
        // Matches "<PREFIX> <BATCH>" exactly (or "<PREFIX> <BATCH> <trail>")
        // — avoids cross-matching batch numbers across different beers
        // (e.g. DIB 6 vs ALT 6 vs EST 6 all sharing batch number "6").
        $beerPrefix = TankSimulator::beerPrefix($beer);
        $exactMatch = $beerPrefix . ' ' . $batch;
        $withTrail  = $beerPrefix . ' ' . $batch . ' %';

        // Last gravity
        $gravStmt = $pdo->prepare(
            'SELECT gravity AS last_gravity, event_date AS last_gravity_date
             FROM bd_fermenting
             WHERE (beers_to_read = :exact OR beers_to_read LIKE :withTrail)
               AND gravity IS NOT NULL
             ORDER BY event_date DESC LIMIT 1'
        );
        $gravStmt->execute([
            ':exact'     => $exactMatch,
            ':withTrail' => $withTrail,
        ]);
        $gravRow = $gravStmt->fetch() ?: [];

        // Last cold-crash date
        $ccStmt = $pdo->prepare(
            'SELECT MAX(event_date) AS last_cc_date
             FROM bd_fermenting
             WHERE (beers_to_cold_crash = :exact OR beers_to_cold_crash LIKE :withTrail)
               AND beers_to_cold_crash IS NOT NULL AND beers_to_cold_crash != \'\''
        );
        $ccStmt->execute([
            ':exact'     => $exactMatch,
            ':withTrail' => $withTrail,
        ]);
        $ccRow = $ccStmt->fetch() ?: [];

        $cctOccMap[$num] = [
            'cct_number'       => $num,
            'bd_beer'          => $beer,
            'bd_batch'         => $batch,
            'volume_hl'        => $simRow['volume_hl'],
            'brewday_date'     => $brewRow['event_date'] ?? null,
            'recipe_short_name'=> $recipeRow['recipe_short_name'] ?? null,
            'classification'   => $recipeRow['classification'] ?? null,
            'client_name'      => $recipeRow['client_name'] ?? null,
            'last_gravity'     => $gravRow['last_gravity'] ?? null,
            'last_gravity_date'=> $gravRow['last_gravity_date'] ?? null,
            'last_cc_date'     => $ccRow['last_cc_date'] ?? null,
        ];
    }

    // ---- Per-BBT supplemental: recipe short name ----
    $bbtOccMap = [];
    foreach ($bbtSimMap as $num => $simRow) {
        if ($simRow === null) continue;

        $beer  = $simRow['beer'];
        $batch = $simRow['batch'];

        $recipeStmt = $pdo->prepare(
            'SELECT COALESCE(rr.recipe_short_name, rr2.recipe_short_name) AS recipe_short_name,
                    COALESCE(rc.name, \'\')                                AS client_name
             FROM (SELECT 1) dummy
             LEFT JOIN ref_recipes rr
                 ON  rr.name    = :beer
                 AND rr.vintage = CONCAT(\'20\', LPAD(REGEXP_REPLACE(:batch_v, \'[^0-9].*$\', \'\'), 2, \'0\'))
                 AND rr.vintage <> \'20\'
             LEFT JOIN ref_recipes rr2
                 ON  rr2.name    = :beer2
                 AND rr2.vintage = \'\'
             LEFT JOIN ref_clients rc
                 ON  rc.id = COALESCE(rr.client_id, rr2.client_id)
             LIMIT 1'
        );
        $recipeStmt->execute([':beer' => $beer, ':batch_v' => $batch, ':beer2' => $beer]);
        $recipeRow = $recipeStmt->fetch() ?: [];

        // Blend detail string (e.g. "#169: 50hl + #170: 44hl")
        $blendStr = '';
        if (!empty($simRow['blend_info']) && count($simRow['blend_info']) > 1) {
            $parts = array_map(
                fn($bi) => '#' . $bi['batch'] . ': ' . round((float)$bi['vol']) . 'hl',
                $simRow['blend_info']
            );
            $blendStr = implode(' + ', $parts);
        }

        $bbtOccMap[$num] = [
            'bbt_number'       => $num,
            'beer'             => $beer,
            'batch'            => $batch,
            'remaining_hl'     => $simRow['volume_hl'],
            'rack_date'        => $simRow['filled_date']->format('Y-m-d'),
            'recipe_short_name'=> $recipeRow['recipe_short_name'] ?? null,
            'client_name'      => $recipeRow['client_name'] ?? null,
            'blend_str'        => $blendStr,
        ];
    }

    // ---- KPI aggregates ----
    $activeCcts  = array_filter($cctRef, fn($r) => $r['status'] === 'active');
    $activeBbts  = array_filter($bbtRef, fn($r) => $r['status'] === 'active');
    $totalCct    = count($activeCcts);
    $totalBbt    = count($activeBbts);
    $occupiedCct = count($cctOccMap);
    $occupiedBbt = count($bbtOccMap);

    $hlInCcts = 0.0;
    foreach ($cctOccMap as $row) {
        $hlInCcts += (float)($row['volume_hl'] ?? 0);
    }
    $hlInBbts = 0.0;
    foreach ($bbtOccMap as $row) {
        $hlInBbts += (float)($row['remaining_hl'] ?? 0);
    }

    $dbError = null;

} catch (Throwable $e) {
    $cctRef      = [];
    $bbtRef      = [];
    $cctOccMap   = [];
    $bbtOccMap   = [];
    $totalCct    = 0;
    $totalBbt    = 0;
    $occupiedCct = 0;
    $occupiedBbt = 0;
    $hlInCcts    = 0.0;
    $hlInBbts    = 0.0;
    $dbError     = $e->getMessage();
}

// $asOfDT already set above; use it as reference for "days in BBT" calculations.
$today = $asOfDT;
?><!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Tank Board — MaltyTask</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@0,9..144,300;0,9..144,400;0,9..144,500;1,9..144,300;1,9..144,400&family=DM+Sans:opsz,wght@9..40,400;9..40,500;9..40,600&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/css/app.css?v=<?= @filemtime(__DIR__ . '/../css/app.css') ?: time() ?>">
</head>
<body class="home">

<?php require __DIR__ . "/../../app/partials/sidebar.php" ?>

<?php require __DIR__ . "/../../app/partials/topbar.php" ?>

<main class="main tanks-main">

  <?php if ($dbError): ?>
    <div class="wort-error">
      Erreur base de données&nbsp;: <?= htmlspecialchars($dbError) ?>
    </div>
  <?php endif ?>

  <!-- As-of date filter -->
  <form class="tanks-filters" method="get" action="<?= htmlspecialchars($_SERVER['PHP_SELF']) ?>">
    <div class="wort-filters__row">

      <div class="wort-filters__field">
        <label class="wort-filters__label" for="tf-year">Année</label>
        <select id="tf-year" name="year" onchange="this.form.submit()">
          <?php for ($y = 2023; $y <= $currentYear; $y++): ?>
            <option value="<?= $y ?>"<?= $y === $selYear ? ' selected' : '' ?>><?= $y ?></option>
          <?php endfor ?>
        </select>
      </div>

      <div class="wort-filters__field">
        <label class="wort-filters__label" for="tf-month">Mois</label>
        <select id="tf-month" name="month" onchange="this.form.submit()">
          <?php foreach ($monthsFRFull as $mn => $ml): ?>
            <option value="<?= $mn ?>"<?= $mn === $selMonth ? ' selected' : '' ?>><?= htmlspecialchars($ml) ?></option>
          <?php endforeach ?>
        </select>
      </div>

      <div class="wort-filters__field">
        <label class="wort-filters__label" for="tf-day">Jour</label>
        <select id="tf-day" name="day" onchange="this.form.submit()">
          <?php for ($d = 1; $d <= 31; $d++): ?>
            <option value="<?= $d ?>"<?= $d === $selDay ? ' selected' : '' ?>><?= $d ?></option>
          <?php endfor ?>
        </select>
      </div>

      <?php if ($filterActive): ?>
        <a href="<?= htmlspecialchars($_SERVER['PHP_SELF']) ?>" class="wort-filters__reset tanks-filters__reset">
          Réinitialiser
        </a>
      <?php endif ?>

    </div>
  </form>

  <!-- As-of banner -->
  <?php if ($filterActive): ?>
    <div class="tanks-asof-banner">
      État au <?= htmlspecialchars(fmt_date_fr_tanks_full($asOfDT, $monthsFRFull)) ?>
    </div>
  <?php else: ?>
    <div class="tanks-asof-banner tanks-asof-banner--current">
      État actuel · <?= htmlspecialchars(fmt_date_fr_tanks_full($todayDT, $monthsFRFull)) ?>
    </div>
  <?php endif ?>

  <!-- KPI bar -->
  <section class="wort-kpis" aria-label="État des cuves">
    <div class="wort-kpi">
      <span class="wort-kpi__num"><?= $occupiedCct ?><span class="wort-kpi__denom"> / <?= $totalCct ?></span></span>
      <span class="wort-kpi__label">Fermenteurs occupés</span>
    </div>
    <div class="wort-kpi">
      <span class="wort-kpi__num"><?= $occupiedBbt ?><span class="wort-kpi__denom"> / <?= $totalBbt ?></span></span>
      <span class="wort-kpi__label">Tanks de garde occupés</span>
    </div>
    <div class="wort-kpi">
      <span class="wort-kpi__num"><?= $hlInCcts > 0 ? number_format($hlInCcts, 1) : '—' ?></span>
      <span class="wort-kpi__label">HL en fermentation</span>
    </div>
    <div class="wort-kpi">
      <span class="wort-kpi__num"><?= $hlInBbts > 0 ? number_format($hlInBbts, 1) : '—' ?></span>
      <span class="wort-kpi__label">HL en garde</span>
    </div>
  </section>

  <!-- CCT Section -->
  <section class="tanks-section" aria-label="Fermenteurs CCT">
    <div class="wort-section__head">
      <h2 class="tanks-section__title">Fermenteurs <span class="tanks-section__tag">CCT</span></h2>
      <span class="wort-section__label">— <?= $occupiedCct ?> occupé<?= $occupiedCct !== 1 ? 's' : '' ?> sur <?= $totalCct ?> actifs</span>
    </div>

    <div class="tanks-grid">
      <?php foreach ($cctRef as $cct):
        $num      = (int)$cct['number'];
        $capHl    = (float)$cct['capacity_hl'];
        $status   = $cct['status'];
        $occ      = $cctOccMap[$num] ?? null;
        $isMaint  = ($status === 'maintenance' || $status === 'retired');

        // Determine state
        if ($isMaint) {
            $stateClass = 'tank-card--maint';
            $svgState   = 'maint';
            $fillRatio  = 0.0;
        } elseif ($occ === null) {
            $stateClass = 'tank-card--empty';
            $svgState   = '';
            $fillRatio  = 0.0;
        } else {
            $hasColdCrash = !empty($occ['last_cc_date']);
            $stateClass   = $hasColdCrash ? 'tank-card--cold' : 'tank-card--ferment';
            $svgState     = $hasColdCrash ? 'cold' : 'ferment';
            $volHl        = (float)($occ['volume_hl'] ?? 0);
            $fillRatio    = $capHl > 0 ? min(1.0, $volHl / $capHl) : 0.0;
        }
      ?>
      <div class="tank-card <?= $stateClass ?>">
        <div class="tank-card__svg">
          <?= svg_cct($fillRatio, $svgState, $num, (string)($occ['bd_beer'] ?? '')) ?>
        </div>

        <?php if ($isMaint): ?>
          <div class="tank-card__info">
            <span class="tank-card__cap tanks-mute"><?= htmlspecialchars(number_format($capHl, 0)) ?> HL</span>
            <span class="tank-badge tank-badge--maint"><?= htmlspecialchars($status) ?></span>
          </div>

        <?php elseif ($occ === null): ?>
          <div class="tank-card__info">
            <span class="tank-card__empty-label">—</span>
            <span class="tank-card__cap tanks-mute"><?= htmlspecialchars(number_format($capHl, 0)) ?> HL</span>
          </div>

        <?php else:
          $beerLabel  = htmlspecialchars($occ['recipe_short_name'] ?? $occ['bd_beer'] ?? '');
          $batch      = htmlspecialchars($occ['bd_batch'] ?? '');
          $volHlFmt   = $occ['volume_hl'] !== null ? number_format((float)$occ['volume_hl'], 1) . ' HL' : '—';
          $brewDate   = !empty($occ['brewday_date'])
              ? fmt_date_fr_tanks($occ['brewday_date'], $monthsFR)
              : '—';

          // Last gravity
          $lastGrav     = $occ['last_gravity']      ?? null;
          $lastGravDate = $occ['last_gravity_date'] ?? null;
          $gravFmt      = $lastGrav !== null
              ? number_format((float)$lastGrav, 1) . '°P'
              : null;
          $gravDateFmt  = $lastGravDate
              ? fmt_date_fr_tanks($lastGravDate, $monthsFR)
              : null;

          // Cold crash badge: J+N days since brewday
          $ccDate    = $occ['last_cc_date'] ?? null;
          $ccDays    = null;
          $ccDateFmt = null;
          if ($ccDate && !empty($occ['brewday_date'])) {
              $brewDT   = new DateTimeImmutable($occ['brewday_date']);
              $ccDT     = new DateTimeImmutable($ccDate);
              $ccDays   = (int)$brewDT->diff($ccDT)->days;
              $ccDateFmt = fmt_date_fr_tanks($ccDate, $monthsFR);
          }
        ?>
          <div class="tank-card__info">
            <span class="tank-card__beer"><?= $beerLabel ?></span>
            <span class="tank-card__batch tanks-mono"><?= $batch ?></span>
            <span class="tank-card__vol tanks-mute"><?= htmlspecialchars($volHlFmt) ?></span>
            <span class="tank-card__brewdate tanks-mute"><?= htmlspecialchars($brewDate) ?></span>
            <?php if ($gravFmt): ?>
              <span class="tank-card__grav tanks-mono">
                <?= htmlspecialchars($gravFmt) ?>
                <?php if ($gravDateFmt): ?>
                  <span class="tanks-mute"><?= htmlspecialchars($gravDateFmt) ?></span>
                <?php endif ?>
              </span>
            <?php endif ?>
            <?php if ($ccDays !== null): ?>
              <span class="tank-badge tank-badge--cold">❄ J+<?= $ccDays ?> · <?= htmlspecialchars($ccDateFmt ?? '') ?></span>
            <?php endif ?>
          </div>
        <?php endif ?>
      </div>
      <?php endforeach ?>
    </div>
  </section>

  <!-- BBT Section -->
  <section class="tanks-section" aria-label="Tanks de garde BBT">
    <div class="wort-section__head">
      <h2 class="tanks-section__title">Tanks de garde <span class="tanks-section__tag">BBT</span></h2>
      <span class="wort-section__label">— <?= $occupiedBbt ?> occupé<?= $occupiedBbt !== 1 ? 's' : '' ?> sur <?= $totalBbt ?> actifs</span>
    </div>

    <div class="tanks-grid">
      <?php foreach ($bbtRef as $bbt):
        $num      = (int)$bbt['number'];
        $capHl    = (float)$bbt['capacity_hl'];
        $status   = $bbt['status'];
        $occ      = $bbtOccMap[$num] ?? null;
        $isMaint  = ($status === 'maintenance' || $status === 'retired');

        if ($isMaint) {
            $stateClass = 'tank-card--maint';
            $svgState   = 'maint';
            $fillRatio  = 0.0;
        } elseif ($occ === null) {
            $stateClass = 'tank-card--empty';
            $svgState   = '';
            $fillRatio  = 0.0;
        } else {
            $stateClass = 'tank-card--bbt-occ';
            $svgState   = 'bbt';
            $remainHl   = (float)($occ['remaining_hl'] ?? 0);
            $fillRatio  = $capHl > 0 ? min(1.0, $remainHl / $capHl) : 0.0;
        }
      ?>
      <div class="tank-card <?= $stateClass ?>">
        <div class="tank-card__svg">
          <?= svg_bbt($fillRatio, $svgState, $num, (string)($occ['beer'] ?? '')) ?>
        </div>

        <?php if ($isMaint): ?>
          <div class="tank-card__info">
            <span class="tank-card__cap tanks-mute"><?= htmlspecialchars(number_format($capHl, 0)) ?> HL</span>
            <span class="tank-badge tank-badge--maint"><?= htmlspecialchars($status) ?></span>
          </div>

        <?php elseif ($occ === null): ?>
          <div class="tank-card__info">
            <span class="tank-card__empty-label">—</span>
            <span class="tank-card__cap tanks-mute"><?= htmlspecialchars(number_format($capHl, 0)) ?> HL</span>
          </div>

        <?php else:
          $beerLabel = htmlspecialchars($occ['recipe_short_name'] ?? $occ['beer'] ?? '');
          $batch     = htmlspecialchars($occ['batch'] ?? '');
          $remainHl  = (float)($occ['remaining_hl'] ?? 0);
          $blendStr  = $occ['blend_str'] ?? '';
          $rackDate  = !empty($occ['rack_date'])
              ? fmt_date_fr_tanks($occ['rack_date'], $monthsFR)
              : '—';

          // Days in BBT
          $daysInBbt = 0;
          if (!empty($occ['rack_date'])) {
              $rackDT    = new DateTimeImmutable($occ['rack_date']);
              $daysInBbt = (int)$rackDT->diff($today)->days;
          }
        ?>
          <div class="tank-card__info">
            <span class="tank-card__beer"><?= $beerLabel ?></span>
            <span class="tank-card__batch tanks-mono"><?= $batch ?></span>
            <span class="tank-card__vol"><?= htmlspecialchars(number_format($remainHl, 1)) ?> HL</span>
            <?php if ($blendStr !== ''): ?>
              <span class="tank-card__sub tanks-mute"><?= htmlspecialchars($blendStr) ?></span>
            <?php endif ?>
            <span class="tank-card__brewdate tanks-mute"><?= htmlspecialchars($rackDate) ?></span>
            <span class="tank-badge tank-badge--days">J+<?= $daysInBbt ?></span>
          </div>
        <?php endif ?>
      </div>
      <?php endforeach ?>
    </div>
  </section>

</main>

</body>
</html>
