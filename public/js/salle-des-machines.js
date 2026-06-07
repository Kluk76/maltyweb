/**
 * salle-des-machines.js
 * Client logic for /modules/salle-des-machines.php
 * Data injected server-side via window.SDM_*
 * All mutations go through form POSTs (PRG pattern); JS handles:
 *   - slide navigation (overview → detail rooms)
 *   - SVG scene/croqui generation (ported from _design mock)
 *   - Format toggle POST submissions (encartonneuse)
 *   - Stepper POST submissions (machine throughput/speed)
 *   - Size picker POST submissions (vessel capacity)
 *   - Add machine modal (admin only)
 *   - Toast notifications
 */

/* ── Escape helper ─────────────────────────────────────────────── */
function escHtml(s) {
  return String(s)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');
}

/* ── Toast ─────────────────────────────────────────────────────── */
const _toast = document.getElementById('sdmToast');
function showToast(msg) {
  if (!_toast) return;
  _toast.textContent = msg;
  _toast.classList.add('show');
  setTimeout(() => _toast.classList.remove('show'), 2800);
}

/* ── Role (server-authoritative, JS reads only for CSS triggers) ── */
function getRole() {
  return document.body.dataset.role || 'operateur';
}

/* ── Slide navigation ──────────────────────────────────────────── */
const stage  = document.getElementById('sdmStage');
const crumbs = document.getElementById('sdmCrumbs');
const ROOM_NAMES = ['Plan', 'Salle de Brassage', 'Cave de Fermentation', 'Conditionnement'];
let croquiPainted = [false, false, false];

function go(room) {
  if (!stage) return;
  stage.style.transform = `translateX(-${room * 25}%)`;
  if (!crumbs) return;
  crumbs.innerHTML = room === 0
    ? `<span class="here">Plan</span>`
    : `<span>Plan</span><i>↦</i><span class="here">${escHtml(ROOM_NAMES[room])}</span>`;
  if (room === 1 && !croquiPainted[0]) { paintCroqui('croquiBrew', brewScene); paintBrew();   croquiPainted[0] = true; }
  if (room === 2 && !croquiPainted[1]) { paintCroqui('croquiCellar', cellarScene); paintCellar(); croquiPainted[1] = true; }
  if (room === 3 && !croquiPainted[2]) { paintCroqui('croquiPkg', pkgScene); paintPkg();    croquiPainted[2] = true; }
}

document.querySelectorAll('[data-sdm-go]').forEach(el => {
  el.addEventListener('click', () => go(+el.dataset.sdmGo));
});
document.querySelectorAll('[data-sdm-back]').forEach(el => {
  el.addEventListener('click', () => go(+el.dataset.sdmBack));
});

/* ── SVG shared glyph library (ported from _design mock) ──────── */
function triClamp(x, y, s) {
  s = s || 1;
  return `<g transform="translate(${x} ${y}) scale(${s})">
    <line class="ink" x1="-4.5" y1="0" x2="4.5" y2="0"/>
    <line class="ink" x1="-3.3" y1="-2.4" x2="-3.3" y2="2.4"/>
    <line class="ink" x1="3.3" y1="-2.4" x2="3.3" y2="2.4"/>
    <ellipse class="ink-2" cx="0" cy="0" rx="2" ry="1"/>
  </g>`;
}
function valve(x, y, dir, s) {
  s = s || 1;
  const rot = dir === 'side' ? -90 : 0;
  return `<g transform="translate(${x} ${y}) rotate(${rot}) scale(${s})">
    <circle class="ink" cx="0" cy="0" r="4.4"/>
    <path class="ink" d="M-3.1 -3.1 L3.1 3.1 M-3.1 3.1 L3.1 -3.1"/>
    <line class="ink" x1="0" y1="-4.4" x2="0" y2="-8"/>
    <line class="ink" x1="-2.4" y1="-8" x2="2.4" y2="-8"/>
  </g>`;
}
/*
 * cylShade — replaced by cylGravure for the scene SVGs.
 * Same call signature (x1, x2, y1, y2, n) kept for mini-icon call sites.
 * Mini icons (icoHlt, icoMash, …) still use the lightweight shade lines
 * because they are small-format and do not carry hatch pattern refs.
 * Scene SVGs (brewScene / cellarScene / pkgScene) use cylGravure instead.
 */
function cylShade(x1, x2, y1, y2, n) {
  n = n || 5;
  let d = '';
  const step = (x2 - x1) / (n + 1);
  for (let i = 1; i <= n; i++) {
    const x = x1 + step * i;
    const op = (i === 1 || i === n) ? .18 : (i === 2 || i === n - 1) ? .28 : .38;
    d += `<line class="shade" x1="${x}" y1="${y1}" x2="${x}" y2="${y2}" opacity="${op}"/>`;
  }
  return d;
}
/*
 * cylGravure — gravure-dense shading for scene SVGs.
 * Emits: ① paper fill ② centre sparse-vertical hatch (bh_px)
 *        ③ right shadow cross-hatch (bh_pd) ④ 4 left contour strokes
 *        ⑤ 5 right contour strokes (shadow side)
 * Parameters:
 *   x1,x2  — left/right x of the cylinder body rect
 *   y1,y2  — top/bottom y of the cylinder body rect
 *   cx     — width ratio of the centre hatch band (default 0.3 = 30% from left)
 *   sx     — width ratio of the right shadow band (default 0.22 = 22% from right)
 */
function cylGravure(x1, x2, y1, y2, cx, sx) {
  cx = cx || 0.30;
  sx = sx || 0.22;
  const w = x2 - x1, h = y2 - y1;
  const cw = Math.round(w * cx);
  const sw = Math.round(w * sx);
  const sxStart = x2 - sw;
  // Paper fill
  let d = `<rect x="${x1}" y="${y1}" width="${w}" height="${h}" fill="var(--bg,#f1e8d4)" stroke="none"/>`;
  // Centre sparse hatch
  d += `<rect x="${x1}" y="${y1}" width="${cw}" height="${h}" fill="url(#bh_px)" opacity="0.9"/>`;
  // Right shadow cross-hatch
  d += `<rect x="${sxStart}" y="${y1}" width="${sw}" height="${h}" fill="url(#bh_pd)" opacity="0.82"/>`;
  // 4 left contour strokes (fading inward)
  const lOps = [0.58, 0.52, 0.45, 0.36];
  const lWts = [0.62, 0.57, 0.52, 0.46];
  for (let i = 0; i < 4; i++) {
    const lx = x1 + 1.5 + i * 1.6;
    d += `<line x1="${lx}" y1="${y1}" x2="${lx}" y2="${y2}" stroke="var(--oak-deep,#5a3a12)" stroke-width="${lWts[i]}" opacity="${lOps[i]}"/>`;
  }
  // 5 right contour strokes (shadow dominant)
  const rOps = [0.62, 0.57, 0.50, 0.42, 0.34];
  const rWts = [0.65, 0.60, 0.55, 0.50, 0.44];
  for (let i = 0; i < 5; i++) {
    const rx = x2 + 0.5 - i * 1.6;
    d += `<line x1="${rx}" y1="${y1}" x2="${rx}" y2="${y2}" stroke="var(--oak-deep,#5a3a12)" stroke-width="${rWts[i]}" opacity="${rOps[i]}"/>`;
  }
  return d;
}
/*
 * strapShadow — 10px dense-hatch band below a weld seam (strap shadow).
 * Emitted after each weldSeam() in scene SVGs.
 */
function strapShadow(x1, x2, y, op) {
  op = op || 0.68;
  return `<rect x="${x1}" y="${y}" width="${x2 - x1}" height="9" fill="url(#bh_pd)" opacity="${op}"/>`;
}
/*
 * sceneDefs — shared SVG defs block (hatch patterns + heating-jacket pattern).
 * Emitted once per scene SVG inside its <defs>.
 * Pattern IDs are globally unique per page so no prefix needed for single-page use.
 */
function sceneDefs() {
  return `<defs>
    <pattern id="bh_px" x="0" y="0" width="3.8" height="3.8" patternUnits="userSpaceOnUse">
      <line x1="1.9" y1="0" x2="1.9" y2="3.8" stroke="var(--oak-deep,#5a3a12)" stroke-width="0.55" opacity="0.27"/>
    </pattern>
    <pattern id="bh_pd" x="0" y="0" width="3.5" height="3.5" patternUnits="userSpaceOnUse">
      <line x1="0" y1="0" x2="3.5" y2="3.5" stroke="var(--oak-deep,#5a3a12)" stroke-width="0.58" opacity="0.52"/>
      <line x1="3.5" y1="0" x2="0" y2="3.5" stroke="var(--oak-deep,#5a3a12)" stroke-width="0.58" opacity="0.52"/>
    </pattern>
    <pattern id="bh_pj" x="0" y="0" width="5" height="5" patternUnits="userSpaceOnUse">
      <line x1="0" y1="5" x2="5" y2="0" stroke="var(--oak-deep,#5a3a12)" stroke-width="0.5" opacity="0.38"/>
    </pattern>
  </defs>`;
}
/*
 * weldSeam — unchanged signature; emits the seam line + strap shadow below it.
 * The strap-shadow is appended inside the same group so clip paths keep it correct.
 */
function weldSeam(x1, x2, y, n) {
  n = n || 7;
  let t = '', step = (x2 - x1) / (n + 1);
  for (let i = 1; i <= n; i++) { const x = x1 + step * i; t += `<line class="ink-2" x1="${x}" y1="${y - 1.1}" x2="${x}" y2="${y + 1.1}"/>`; }
  return `<line class="ink-2" x1="${x1}" y1="${y}" x2="${x2}" y2="${y}"/>${t}${strapShadow(x1, x2, y + 0.5, 0.68)}`;
}
function dim(x, y, t, cls) { return `<text class="${cls || 'dim'}" x="${x}" y="${y}">${escHtml(t)}</text>`; }

/* ── Mini icon SVGs ─────────────────────────────────────────────── */
function icoHlt() {
  return `<svg viewBox="0 0 150 240" width="36" height="44" xmlns="http://www.w3.org/2000/svg" preserveAspectRatio="xMidYMid meet">
    <line class="ink" x1="75" y1="18" x2="75" y2="34"/><path class="ink" d="M52 18 q8 -10 23 -10 q15 0 23 10"/>
    <path class="ink" d="M38 56 Q75 28 112 56"/><ellipse class="ink-2" cx="75" cy="56" rx="37" ry="6"/>
    <path class="ground" d="M38 56 V196"/><path class="ground" d="M112 56 V196"/>
    ${cylShade(42, 108, 62, 192, 4)}
    <path class="ink" d="M38 196 Q75 210 112 196"/>${valve(75, 220, 'down', .9)}
    <path class="ground" d="M52 200 L42 234"/><path class="ground" d="M98 200 L108 234"/>
    <line class="ground" x1="20" y1="234" x2="130" y2="234"/>
  </svg>`;
}
function icoMash() {
  return `<svg viewBox="0 0 180 240" width="36" height="44" xmlns="http://www.w3.org/2000/svg" preserveAspectRatio="xMidYMid meet">
    <rect class="ink" x="78" y="14" width="24" height="16" rx="2"/>
    <line class="ink" x1="90" y1="30" x2="90" y2="64"/>
    <path class="ink" d="M40 78 Q90 56 140 78"/><ellipse class="ink-2" cx="90" cy="78" rx="50" ry="7"/>
    <path class="ground" d="M40 78 V172"/><path class="ground" d="M140 78 V172"/>
    ${cylShade(45, 135, 84, 168, 5)}
    <path class="ink" d="M40 172 Q90 188 140 172"/><ellipse class="ink" cx="90" cy="118" rx="13" ry="16"/>
    ${valve(90, 196, 'down', .9)}
    <path class="ground" d="M56 176 L48 214"/><path class="ground" d="M124 176 L132 214"/>
    <line class="ground" x1="22" y1="214" x2="158" y2="214"/>
  </svg>`;
}
function icoLauter() {
  return `<svg viewBox="0 0 200 240" width="36" height="44" xmlns="http://www.w3.org/2000/svg" preserveAspectRatio="xMidYMid meet">
    <rect class="ink" x="88" y="22" width="24" height="14" rx="2"/>
    <line class="ink" x1="100" y1="36" x2="100" y2="60"/>
    <path class="ink" d="M30 84 Q100 68 170 84"/><ellipse class="ink-2" cx="100" cy="84" rx="70" ry="7"/>
    <path class="ground" d="M30 84 V156"/><path class="ground" d="M170 84 V156"/>
    ${cylShade(35, 165, 90, 152, 6)}
    <line class="ink" x1="36" y1="146" x2="164" y2="146"/>
    <path class="ink" d="M30 156 Q100 170 170 156"/>
    ${valve(100, 202, 'down', .9)}
    <path class="ground" d="M40 160 L30 214"/><path class="ground" d="M160 160 L170 214"/>
    <line class="ground" x1="18" y1="214" x2="182" y2="214"/>
  </svg>`;
}
function icoBuffer() {
  return `<svg viewBox="0 0 130 220" width="32" height="44" xmlns="http://www.w3.org/2000/svg" preserveAspectRatio="xMidYMid meet">
    <path class="ink" d="M28 54 Q65 38 102 54"/><ellipse class="ink-2" cx="65" cy="54" rx="37" ry="6"/>
    <path class="ground" d="M28 54 V172"/><path class="ground" d="M102 54 V172"/>
    ${cylShade(32, 98, 58, 168, 4)}
    <path class="ink" d="M28 172 Q65 186 102 172"/><ellipse class="ink-2" cx="65" cy="172" rx="37" ry="6"/>
    ${valve(65, 190, 'down', .9)}
    <path class="ground" d="M36 176 L26 210"/><path class="ground" d="M94 176 L104 210"/>
    <line class="ground" x1="14" y1="210" x2="116" y2="210"/>
  </svg>`;
}
function icoKettle() {
  return `<svg viewBox="0 0 160 240" width="36" height="44" xmlns="http://www.w3.org/2000/svg" preserveAspectRatio="xMidYMid meet">
    <path class="steam" d="M76 6 q-5 -7 1 -13 M84 8 q6 -7 -1 -14"/>
    <path class="ink" d="M58 46 L74 22 H86 L102 46"/>
    <path class="ink" d="M34 62 Q80 38 126 62"/><ellipse class="ink-2" cx="80" cy="62" rx="46" ry="6"/>
    <path class="ground" d="M34 62 V184"/><path class="ground" d="M126 62 V184"/>
    ${cylShade(39, 121, 68, 180, 5)}
    <path class="ink" d="M34 184 Q80 200 126 184"/>
    ${valve(80, 208, 'down', .9)}
    <path class="ground" d="M50 188 L42 224"/><path class="ground" d="M110 188 L118 224"/>
    <line class="ground" x1="20" y1="224" x2="140" y2="224"/>
  </svg>`;
}
function icoWhirlpool() {
  return `<svg viewBox="0 0 170 240" width="36" height="44" xmlns="http://www.w3.org/2000/svg" preserveAspectRatio="xMidYMid meet">
    <line class="ink" x1="36" y1="70" x2="124" y2="70"/><ellipse class="ink-2" cx="80" cy="70" rx="44" ry="6"/>
    <path class="ground" d="M36 70 V178"/><path class="ground" d="M124 70 V178"/>
    ${cylShade(41, 119, 76, 174, 4)}
    <path class="ink-2" d="M62 120 q18 -10 36 0 q-18 10 -36 0" opacity=".7"/>
    <path class="ink" d="M36 178 H124"/><path class="ink" d="M60 178 L80 196 L100 178"/>
    ${valve(80, 208, 'down', .9)}
    <path class="ground" d="M52 178 L44 224"/><path class="ground" d="M108 178 L116 224"/>
    <line class="ground" x1="22" y1="224" x2="148" y2="224"/>
  </svg>`;
}
function icoYt() {
  return `<svg viewBox="0 0 130 270" width="26" height="44" xmlns="http://www.w3.org/2000/svg" preserveAspectRatio="xMidYMid meet">
    <path class="ink" d="M34 64 Q65 44 96 64"/><ellipse class="ink-2" cx="65" cy="64" rx="31" ry="5"/>
    <path class="ground" d="M34 64 V176"/><path class="ground" d="M96 64 V176"/>
    ${cylShade(38, 92, 70, 172, 3)}
    <line class="band-hop" x1="38" y1="92" x2="92" y2="92"/><line class="band-hop" x1="38" y1="99" x2="92" y2="99"/>
    <path class="ground" d="M34 176 L65 234"/><path class="ground" d="M96 176 L65 234"/>
    ${valve(65, 244, 'down', .85)}
    <path class="ground" d="M42 172 L32 258"/><path class="ground" d="M88 172 L98 258"/>
    <line class="ground" x1="16" y1="258" x2="114" y2="258"/>
  </svg>`;
}
function icoCct() {
  return `<svg viewBox="0 0 150 330" width="26" height="44" xmlns="http://www.w3.org/2000/svg" preserveAspectRatio="xMidYMid meet">
    <path class="ink" d="M40 64 Q75 38 110 64"/><ellipse class="ink-2" cx="75" cy="64" rx="35" ry="6"/>
    <path class="ground" d="M40 64 V214"/><path class="ground" d="M110 64 V214"/>
    ${cylShade(45, 105, 70, 210, 4)}
    <line class="band-oak" x1="45" y1="98" x2="105" y2="98"/><line class="band-oak" x1="45" y1="106" x2="105" y2="106"/>
    <path class="ground" d="M40 214 L75 300"/><path class="ground" d="M110 214 L75 300"/>
    ${valve(75, 310, 'down', .9)}
    <path class="ground" d="M44 210 L30 322"/><path class="ground" d="M106 210 L120 322"/>
    <line class="ground" x1="14" y1="322" x2="136" y2="322"/>
  </svg>`;
}
function icoBbt() {
  return `<svg viewBox="0 0 150 300" width="26" height="44" xmlns="http://www.w3.org/2000/svg" preserveAspectRatio="xMidYMid meet">
    <path class="ink" d="M40 74 Q75 36 110 74"/><ellipse class="ink-2" cx="75" cy="74" rx="35" ry="6"/>
    <path class="ground" d="M40 74 V218"/><path class="ground" d="M110 74 V218"/>
    ${cylShade(45, 105, 80, 214, 4)}
    <line class="band-bbt" x1="45" y1="106" x2="105" y2="106"/><line class="band-bbt" x1="45" y1="114" x2="105" y2="114"/>
    <path class="ground" d="M40 218 Q75 250 110 218"/>
    ${valve(75, 252, 'down', .9)}
    <path class="ground" d="M48 220 L36 288"/><path class="ground" d="M102 220 L114 288"/>
    <line class="ground" x1="16" y1="288" x2="134" y2="288"/>
  </svg>`;
}
function icoCentrifuge() {
  return `<svg viewBox="0 0 100 100" width="36" height="36" xmlns="http://www.w3.org/2000/svg" preserveAspectRatio="xMidYMid meet">
    <circle class="ink" cx="50" cy="50" r="34"/><circle class="ink-2" cx="50" cy="50" r="22"/><circle class="ink-2" cx="50" cy="50" r="5"/>
    <line class="ink-2" x1="50" y1="28" x2="50" y2="16"/><line class="ink-2" x1="50" y1="72" x2="50" y2="84"/>
    <line class="ink-2" x1="28" y1="50" x2="16" y2="50"/><line class="ink-2" x1="72" y1="50" x2="84" y2="50"/>
  </svg>`;
}
function icoKze() {
  return `<svg viewBox="0 0 100 80" width="40" height="32" xmlns="http://www.w3.org/2000/svg" preserveAspectRatio="xMidYMid meet">
    <rect class="ink" x="8" y="20" width="84" height="40" rx="4"/>
    <line class="ink-2" x1="8" y1="40" x2="92" y2="40"/>
    ${[20,32,44,56,68,80].map(x => `<line class="ink-2" x1="${x}" y1="22" x2="${x}" y2="58" opacity=".4"/>`).join('')}
    <path class="steam" d="M30 18 q-3 -5 1 -9 M50 16 q4 -5 -1 -10 M70 18 q-3 -5 1 -9"/>
    ${valve(50, 72, 'down', .75)}
  </svg>`;
}
function icoBottleFiller() {
  return `<svg viewBox="0 0 100 100" width="36" height="36" xmlns="http://www.w3.org/2000/svg" preserveAspectRatio="xMidYMid meet">
    <rect class="ink" x="22" y="10" width="56" height="14" rx="3"/>
    <line class="ink" x1="50" y1="24" x2="50" y2="32"/>
    ${[35,50,65].map(x => `<line class="ink-2" x1="${x}" y1="32" x2="${x}" y2="52" opacity=".8"/>`).join('')}
    ${[35,50,65].map(x => `<path class="ink" d="M${x-5} 52 q0 -2 5 -2 q5 0 5 2 v24 q0 3 -5 3 q-5 0 -5 -3 z"/>`).join('')}
    <line class="ground" x1="14" y1="90" x2="86" y2="90"/>
  </svg>`;
}
function icoCanLine() {
  return `<svg viewBox="0 0 110 100" width="40" height="36" xmlns="http://www.w3.org/2000/svg" preserveAspectRatio="xMidYMid meet">
    <rect class="ink" x="8" y="12" width="94" height="22" rx="3"/>
    ${[22,42,62,82].map(x => `<ellipse class="ink-2" cx="${x}" cy="56" rx="7" ry="18"/>`).join('')}
    ${[22,42,62,82].map(x => `<ellipse class="ink-2" cx="${x}" cy="40" rx="7" ry="3"/>`).join('')}
    <line class="ground" x1="8" y1="84" x2="102" y2="84"/>
  </svg>`;
}
function icoKegFiller() {
  return `<svg viewBox="0 0 100 100" width="36" height="36" xmlns="http://www.w3.org/2000/svg" preserveAspectRatio="xMidYMid meet">
    <path class="ink" d="M28 32 Q50 18 72 32 V68 Q50 82 28 68 Z"/>
    <ellipse class="ink-2" cx="50" cy="32" rx="22" ry="5"/><ellipse class="ink-2" cx="50" cy="68" rx="22" ry="5"/>
    <line class="ink" x1="50" y1="18" x2="50" y2="10"/><path class="ink" d="M44 10 h12"/>
    <line class="ink-2" x1="36" y1="44" x2="64" y2="44"/><line class="ink-2" x1="36" y1="56" x2="64" y2="56"/>
    <line class="ground" x1="16" y1="90" x2="84" y2="90"/>
    <path class="ground" d="M34 68 L28 90"/><path class="ground" d="M66 68 L72 90"/>
  </svg>`;
}
function icoCartoner() {
  return `<svg viewBox="0 0 110 100" width="40" height="36" xmlns="http://www.w3.org/2000/svg" preserveAspectRatio="xMidYMid meet">
    <rect class="ink" x="10" y="14" width="90" height="58" rx="4"/>
    <path class="ink-2" d="M10 40 h90"/><path class="ink-2" d="M10 56 h90"/>
    <path class="ink" d="M30 14 L22 8 H88 L80 14"/>
    <rect class="ink-2" x="46" y="44" width="18" height="10" rx="2"/>
  </svg>`;
}
function icoCuvFiller() {
  /* Serving-tank filler icon: cylindrical serving tank with tap */
  return `<svg viewBox="0 0 100 100" width="36" height="36" xmlns="http://www.w3.org/2000/svg" preserveAspectRatio="xMidYMid meet">
    <path class="ink" d="M22 28 Q50 14 78 28"/><ellipse class="ink-2" cx="50" cy="28" rx="28" ry="5"/>
    <path class="ground" d="M22 28 V72"/><path class="ground" d="M78 28 V72"/>
    ${cylShade(26,74,33,68,4)}
    <path class="ink" d="M22 72 Q50 86 78 72"/>
    <line class="ink" x1="50" y1="86" x2="50" y2="94"/>
    <path class="ink" d="M42 94 h16"/><path class="ink" d="M58 94 v-5 l4 -3 h6"/>
    ${triClamp(72,91,.75)}
  </svg>`;
}

/* ── Scene generators (gravure-dense, returns SVG string) ─────── */
function brewScene() {
  /* Element budget: ~480 (well under 700 limit) */
  return `<svg viewBox="0 0 980 400" width="100%" height="100%" class="sdm-draw" preserveAspectRatio="xMidYMid meet" xmlns="http://www.w3.org/2000/svg">
    ${sceneDefs()}
    <!-- floor line -->
    <line class="ground" x1="18" y1="368" x2="962" y2="368"/>
    <!-- secondary floor shadow -->
    <line x1="18" y1="374" x2="962" y2="374" stroke="var(--oak-deep,#5a3a12)" stroke-width="0.5" opacity="0.35"/>

    <!-- ── HLT translate(44,18) ── -->
    <g transform="translate(44,18)">
      ${cylGravure(28,92,50,232)}
      <!-- dome top -->
      <path class="ink" d="M28 50 Q60 24 92 50"/>
      <ellipse class="ink-2" cx="60" cy="50" rx="32" ry="5.5"/>
      <!-- dome bottom -->
      <path class="ink" d="M28 232 Q60 246 92 232"/>
      <ellipse class="ink-2" cx="60" cy="232" rx="32" ry="5.5"/>
      <!-- body rect outline -->
      <rect x="28" y="50" width="64" height="182" fill="none" stroke="var(--oak-deep,#5a3a12)" stroke-width="1.85" stroke-linejoin="round"/>
      <!-- weld seams -->
      ${weldSeam(28,92,130)}
      ${weldSeam(28,92,180)}
      <!-- dome top shadow -->
      <rect x="28" y="44" width="64" height="14" fill="url(#bh_pd)" opacity="0.8"/>
      <!-- dome bottom shadow -->
      <rect x="28" y="222" width="64" height="14" fill="url(#bh_pd)" opacity="0.7"/>
      ${valve(60,256,'down',.9)}
      <path class="ground" d="M44 232 L36 340"/><path class="ground" d="M76 232 L84 340"/>
      <line class="ground" x1="20" y1="340" x2="108" y2="340"/>
      ${dim(30,42,'HLT')}
    </g>

    <!-- ── MAISCHE translate(200,68) ── -->
    <g transform="translate(200,68)">
      ${cylGravure(28,130,68,166,0.32,0.24)}
      <!-- dome top -->
      <path class="ink" d="M28 68 Q79 48 130 68"/>
      <ellipse class="ink-2" cx="79" cy="68" rx="51" ry="6.5"/>
      <!-- dome bottom -->
      <path class="ink" d="M28 166 Q79 182 130 166"/>
      <ellipse class="ink-2" cx="79" cy="166" rx="51" ry="6.5"/>
      <!-- body rect outline -->
      <rect x="28" y="68" width="102" height="98" fill="none" stroke="var(--oak-deep,#5a3a12)" stroke-width="1.85" stroke-linejoin="round"/>
      <!-- dome shadows -->
      <rect x="28" y="62" width="102" height="13" fill="url(#bh_pd)" opacity="0.8"/>
      <rect x="28" y="156" width="102" height="13" fill="url(#bh_pd)" opacity="0.7"/>
      <!-- weld seam -->
      ${weldSeam(28,130,118)}
      <!-- rake drive indicator -->
      <ellipse cx="79" cy="118" rx="10" ry="13" fill="none" stroke="var(--oak-deep,#5a3a12)" stroke-width="0.9" opacity="0.45"/>
      ${valve(79,190,'down',.9)}
      <path class="ground" d="M44 170 L36 298"/><path class="ground" d="M114 170 L122 298"/>
      ${dim(50,60,'MAISCHE')}
    </g>

    <!-- ── FILTRE translate(390,100) ── -->
    <g transform="translate(390,100)">
      ${cylGravure(18,156,76,150,0.28,0.20)}
      <!-- dome top -->
      <path class="ink" d="M18 76 Q87 60 156 76"/>
      <ellipse class="ink-2" cx="87" cy="76" rx="69" ry="6"/>
      <!-- dome bottom -->
      <path class="ink" d="M18 150 Q87 165 156 150"/>
      <ellipse class="ink-2" cx="87" cy="150" rx="69" ry="5.5"/>
      <!-- body rect outline -->
      <rect x="18" y="76" width="138" height="74" fill="none" stroke="var(--oak-deep,#5a3a12)" stroke-width="1.85" stroke-linejoin="round"/>
      <!-- dome shadows -->
      <rect x="18" y="70" width="138" height="13" fill="url(#bh_pd)" opacity="0.8"/>
      <rect x="18" y="140" width="138" height="13" fill="url(#bh_pd)" opacity="0.7"/>
      <!-- filter screen line -->
      <line x1="24" y1="138" x2="150" y2="138" stroke="var(--oak-deep,#5a3a12)" stroke-width="0.9" opacity="0.5"/>
      <!-- sparger arms -->
      <line x1="52" y1="122" x2="122" y2="122" stroke="var(--oak-deep,#5a3a12)" stroke-width="0.85" opacity="0.40"/>
      ${valve(87,192,'down',.9)}
      <path class="ground" d="M28 154 L18 266"/><path class="ground" d="M146 154 L156 266"/>
      ${dim(58,68,'FILTRE')}
    </g>

    <!-- ── CUITE translate(620,48) ── -->
    <g transform="translate(620,48)">
      <!-- steam wisps (CSS gated by prefers-reduced-motion) -->
      <path class="steam-wisp" d="M68 6 q-5 -7 1 -13" fill="none" stroke="var(--oak-deep,#5a3a12)" stroke-width="0.9" stroke-linecap="round"/>
      <path class="steam-wisp" d="M76 8 q6 -7 -1 -14" fill="none" stroke="var(--oak-deep,#5a3a12)" stroke-width="0.9" stroke-linecap="round"/>
      <path class="steam-wisp" d="M72 2 q-2 -8 3 -13" fill="none" stroke="var(--oak-deep,#5a3a12)" stroke-width="0.9" stroke-linecap="round"/>
      ${cylGravure(28,116,58,178)}
      <!-- dome top -->
      <path class="ink" d="M28 58 Q72 36 116 58"/>
      <ellipse class="ink-2" cx="72" cy="58" rx="44" ry="5.5"/>
      <!-- dome bottom -->
      <path class="ink" d="M28 178 Q72 194 116 178"/>
      <ellipse class="ink-2" cx="72" cy="178" rx="44" ry="5.5"/>
      <!-- body rect outline -->
      <rect x="28" y="58" width="88" height="120" fill="none" stroke="var(--oak-deep,#5a3a12)" stroke-width="1.85" stroke-linejoin="round"/>
      <!-- dome shadows -->
      <rect x="28" y="52" width="88" height="13" fill="url(#bh_pd)" opacity="0.8"/>
      <rect x="28" y="168" width="88" height="13" fill="url(#bh_pd)" opacity="0.7"/>
      <!-- weld seams -->
      ${weldSeam(28,116,110)}
      ${weldSeam(28,116,148)}
      <!-- CUITE calandre interne (dashed ellipse + diagonal hatch) -->
      <ellipse cx="72" cy="118" rx="13" ry="30" fill="none" stroke="var(--oak-deep,#5a3a12)" stroke-width="1.0" stroke-dasharray="3,2" opacity="0.55"/>
      <ellipse cx="72" cy="118" rx="13" ry="30" fill="url(#bh_pj)" opacity="0.85" stroke="none"/>
      <!-- calandre nozzles -->
      <line x1="59" y1="96" x2="70" y2="96" stroke="var(--oak-deep,#5a3a12)" stroke-width="0.7" opacity="0.55"/>
      <line x1="59" y1="138" x2="70" y2="138" stroke="var(--oak-deep,#5a3a12)" stroke-width="0.7" opacity="0.55"/>
      <!-- sight glass / vent stack top -->
      <path d="M58 46 L66 20 H78 L86 46" fill="none" stroke="var(--oak-deep,#5a3a12)" stroke-width="1.1"/>
      ${valve(72,202,'down',.9)}
      <path class="ground" d="M42 182 L34 318"/><path class="ground" d="M102 182 L110 318"/>
      ${dim(50,50,'CUITE')}
    </g>

    <!-- ── WHIRLPOOL translate(826,80) ── -->
    <g transform="translate(826,80)">
      ${cylGravure(24,112,62,170)}
      <!-- top flat ring -->
      <line x1="24" y1="62" x2="112" y2="62" stroke="var(--oak-deep,#5a3a12)" stroke-width="1.85"/>
      <ellipse class="ink-2" cx="68" cy="62" rx="44" ry="5.5"/>
      <!-- vent stub -->
      <line x1="68" y1="62" x2="68" y2="48" stroke="var(--oak-deep,#5a3a12)" stroke-width="1.1"/>
      <line x1="62" y1="48" x2="74" y2="48" stroke="var(--oak-deep,#5a3a12)" stroke-width="0.9"/>
      <!-- body walls -->
      <line x1="24" y1="62" x2="24" y2="170" stroke="var(--oak-deep,#5a3a12)" stroke-width="1.85"/>
      <line x1="112" y1="62" x2="112" y2="170" stroke="var(--oak-deep,#5a3a12)" stroke-width="1.85"/>
      <!-- dome top shadow -->
      <rect x="24" y="56" width="88" height="13" fill="url(#bh_pd)" opacity="0.8"/>
      <!-- weld seam -->
      ${weldSeam(24,112,116)}
      <!-- flat bottom + cone exit -->
      <line x1="24" y1="170" x2="112" y2="170" stroke="var(--oak-deep,#5a3a12)" stroke-width="1.85"/>
      <path d="M50 170 L68 188 L86 170" fill="none" stroke="var(--oak-deep,#5a3a12)" stroke-width="1.85" stroke-linejoin="round"/>
      <!-- tangential inlet (right side) -->
      <path d="M112 88 l28 8" fill="none" stroke="var(--oak-deep,#5a3a12)" stroke-width="1.1"/>
      <!-- whirlpool vortex glyph (twin curves) -->
      <path d="M50 116 q18 -9 36 0" fill="none" stroke="var(--oak-deep,#5a3a12)" stroke-width="0.8" opacity="0.50"/>
      <path d="M54 128 q14 -7 28 0" fill="none" stroke="var(--oak-deep,#5a3a12)" stroke-width="0.65" opacity="0.35"/>
      <!-- wort outlet right lower -->
      <line x1="112" y1="160" x2="128" y2="160" stroke="var(--oak-deep,#5a3a12)" stroke-width="1.1"/>
      ${valve(68,200,'down',.9)}
      <path class="ground" d="M38 170 L30 288"/><path class="ground" d="M98 170 L106 288"/>
      ${dim(44,54,'WHIRLPOOL')}
    </g>

    <!-- ── inter-vessel wort piping ── -->
    <!-- HLT → Maische -->
    <path d="M164 276 h28 V118 h40" fill="none" stroke="var(--oak-deep,#5a3a12)" stroke-width="1.0" opacity="0.70"/>
    ${triClamp(192,276,0.85)}
    <!-- Maische → Filtre -->
    <path d="M360 264 h24 V212 h28" fill="none" stroke="var(--oak-deep,#5a3a12)" stroke-width="1.0" opacity="0.70"/>
    ${triClamp(388,212,0.85)}
    <!-- Filtre → Cuite wort run -->
    <path d="M580 302 h18 V256 h46" fill="none" stroke="var(--oak-deep,#5a3a12)" stroke-width="1.0" opacity="0.70"/>
    ${triClamp(620,256,0.85)}
    <!-- Cuite → Whirlpool transfer -->
    <path d="M790 254 h28 V172 h32" fill="none" stroke="var(--oak-deep,#5a3a12)" stroke-width="1.0" opacity="0.70"/>
    ${triClamp(810,172,0.85)}
    <!-- CIP return dashed header -->
    <path d="M694 346 H188" fill="none" stroke="var(--oak-deep,#5a3a12)" stroke-width="0.65" stroke-dasharray="4,3" opacity="0.30"/>
    <text class="dim" x="426" y="342">CIP RETOUR</text>
    <!-- hot water supply header (dashed, near top) -->
    <path d="M104 28 h94" fill="none" stroke="var(--oak-deep,#5a3a12)" stroke-width="0.65" stroke-dasharray="3,4" opacity="0.26"/>
    <text class="dim" x="110" y="24">eau chaude</text>
  </svg>`;
}

function cellarScene() {
  /* CCT/BBT: already gravure via svg_cct()/svg_bbt() PHP on the detail panel.
   * Overview croqui re-draws them using cylGravure for visual consistency.
   * YT (yeast tank): gravure-dense treatment added here. */
  return `<svg viewBox="0 0 860 440" width="100%" height="100%" class="sdm-draw" preserveAspectRatio="xMidYMid meet" xmlns="http://www.w3.org/2000/svg">
    ${sceneDefs()}
    <line class="ground" x1="18" y1="418" x2="842" y2="418"/>
    <!-- CCT translate(70,14) — cylindrical with cone bottom -->
    <g transform="translate(70,14)">
      ${cylGravure(72,148,68,224,0.30,0.22)}
      <path class="ink" d="M72 68 Q110 42 148 68"/>
      <ellipse class="ink-2" cx="110" cy="68" rx="38" ry="6"/>
      <rect x="72" y="68" width="76" height="156" fill="none" stroke="var(--oak-deep,#5a3a12)" stroke-width="1.85" stroke-linejoin="round"/>
      <rect x="72" y="62" width="76" height="13" fill="url(#bh_pd)" opacity="0.8"/>
      ${weldSeam(72,148,104)}
      <line class="band-oak" x1="77" y1="104" x2="143" y2="104"/>
      <line class="band-oak" x1="77" y1="112" x2="143" y2="112"/>
      <path class="ground" d="M72 224 L110 318"/><path class="ground" d="M148 224 L110 318"/>
      ${valve(110,328,'down',.95)}
      <path class="ground" d="M78 220 L58 402"/><path class="ground" d="M142 220 L162 402"/>
      <line class="ground" x1="42" y1="402" x2="178" y2="402"/>
      ${dim(64,60,'CCT','dim-o')}
    </g>
    <!-- BBT translate(336,46) — cylindrical with domed bottom -->
    <g transform="translate(336,46)">
      ${cylGravure(38,182,72,240,0.28,0.20)}
      <path class="ink" d="M38 72 Q110 20 182 72"/>
      <ellipse class="ink-2" cx="110" cy="72" rx="72" ry="10"/>
      <rect x="38" y="72" width="144" height="168" fill="none" stroke="var(--oak-deep,#5a3a12)" stroke-width="1.85" stroke-linejoin="round"/>
      <rect x="38" y="66" width="144" height="13" fill="url(#bh_pd)" opacity="0.8"/>
      ${weldSeam(38,182,108)}
      <line class="band-bbt" x1="43" y1="108" x2="177" y2="108"/>
      <line class="band-bbt" x1="43" y1="117" x2="177" y2="117"/>
      <path class="ground" d="M38 240 Q110 268 182 240"/>
      ${valve(110,270,'down',.95)}
      <path class="ground" d="M52 244 L36 402"/><path class="ground" d="M168 244 L184 402"/>
      <line class="ground" x1="20" y1="402" x2="200" y2="402"/>
      ${dim(64,64,'BBT','dim-o')}
    </g>
    <!-- YT translate(674,96) — yeast tank with cone bottom -->
    <g transform="translate(674,96)">
      ${cylGravure(38,106,58,172,0.32,0.24)}
      <path class="ink" d="M38 58 Q72 36 106 58"/>
      <ellipse class="ink-2" cx="72" cy="58" rx="34" ry="5.5"/>
      <rect x="38" y="58" width="68" height="114" fill="none" stroke="var(--oak-deep,#5a3a12)" stroke-width="1.85" stroke-linejoin="round"/>
      <rect x="38" y="52" width="68" height="13" fill="url(#bh_pd)" opacity="0.8"/>
      ${weldSeam(38,106,86)}
      <line class="band-hop" x1="42" y1="86" x2="102" y2="86"/>
      <line class="band-hop" x1="42" y1="93" x2="102" y2="93"/>
      <path class="ground" d="M38 172 L72 232"/><path class="ground" d="M106 172 L72 232"/>
      ${valve(72,242,'down',.85)}
      <path class="ground" d="M46 168 L36 322"/><path class="ground" d="M98 168 L108 322"/>
      <line class="ground" x1="20" y1="322" x2="124" y2="322"/>
      ${dim(44,50,'YT','dim-o')}
    </g>
  </svg>`;
}

function pkgScene() {
  return `<svg viewBox="0 0 760 280" width="100%" height="100%" class="sdm-draw" preserveAspectRatio="xMidYMid meet" xmlns="http://www.w3.org/2000/svg">
    <line class="ground" x1="18" y1="258" x2="742" y2="258"/>
    <g transform="translate(40,40)">
      <rect class="ink" x="20" y="8" width="100" height="22" rx="3"/>
      ${[40,70,100].map(x => `<path class="ink" d="M${x-7} 44 q0 -3 7 -3 q7 0 7 3 v36 q0 4 -7 4 q-7 0 -7 -4 z"/>`).join('')}
      <line class="ground" x1="10" y1="200" x2="130" y2="200"/>
      ${dim(34,0,'BOUTEILLE','dim-o')}
    </g>
    <g transform="translate(218,50)">
      <rect class="ink" x="8" y="8" width="140" height="28" rx="3"/>
      ${[28,56,84,112,132].map(x => `<ellipse class="ink-2" cx="${x}" cy="72" rx="10" ry="26"/>`).join('')}
      <line class="ground" x1="6" y1="200" x2="150" y2="200"/>
      ${dim(28,0,'CANETTES','dim-o')}
    </g>
    <g transform="translate(438,56)">
      <path class="ink" d="M20 36 Q70 18 120 36 V134 Q70 152 20 134 Z"/>
      <ellipse class="ink-2" cx="70" cy="36" rx="50" ry="9"/>
      <ellipse class="ink-2" cx="70" cy="134" rx="50" ry="9"/>
      <line class="ground" x1="8" y1="200" x2="132" y2="200"/>
      ${dim(32,4,'FÛTS','dim-o')}
    </g>
    <g transform="translate(602,46)">
      <rect class="ink" x="10" y="12" width="110" height="76" rx="4"/>
      <path class="ink-2" d="M10 52 h110"/><path class="ink-2" d="M10 74 h110"/>
      <path class="ink" d="M32 12 L22 4 H108 L98 12"/>
      <rect class="ink-2" x="56" y="58" width="24" height="12" rx="2"/>
      <line class="ground" x1="6" y1="200" x2="124" y2="200"/>
      ${dim(26,4,'ENCART.','dim-o')}
    </g>
  </svg>`;
}

/* ── Paint overview scenes ─────────────────────────────────────── */
function paintScenes() {
  const el1 = document.getElementById('sceneBrew');
  const el2 = document.getElementById('sceneCellar');
  const el3 = document.getElementById('scenePkg');
  if (el1) el1.innerHTML = brewScene();
  if (el2) el2.innerHTML = cellarScene();
  if (el3) el3.innerHTML = pkgScene();
}

function paintCroqui(id, sceneFn) {
  const el = document.getElementById(id);
  if (!el) return;
  el.insertAdjacentHTML('afterbegin', `<div style="width:90%;max-height:90%">${sceneFn()}</div>`);
}

/* ── Icon map for brewhouse vessel types ─────────────────────────── */
const VESSEL_ICONS = {
  hlt: icoHlt, clt: icoHlt, mash: icoMash, lauter: icoLauter,
  buffer: icoBuffer, kettle: icoKettle, whirlpool: icoWhirlpool,
};
const VESSEL_TYPE_LABELS = {
  hlt: 'Cuve eau chaude (HLT)', clt: 'Cuve eau froide (CLT)',
  mash: 'Cuve de maische', lauter: 'Cuve-filtre (Lauter)',
  buffer: 'Cuve tampon', kettle: 'Cuve d\'ébullition', whirlpool: 'Whirlpool',
};

/* ── Brassage panel ────────────────────────────────────────────── */
function paintBrew() {
  const cfg = document.getElementById('cfgBrew');
  if (!cfg) return;
  const vessels = window.SDM_BREW_VESSELS || [];
  const brewsize = window.SDM_BREW_SIZE || 30;
  const ytRows = (window.SDM_YT || []);

  let waterHtml = '', hotHtml = '', ytHtml = '';
  const water = vessels.filter(v => v.vessel_type === 'hlt' || v.vessel_type === 'clt');
  const hot   = vessels.filter(v => v.vessel_type !== 'hlt' && v.vessel_type !== 'clt');

  water.forEach((v, i) => {
    const icoFn = VESSEL_ICONS[v.vessel_type] || icoHlt;
    waterHtml += vesselRow(v, i, icoFn, [80,100,120], v.volume_hl, 'HL', 'brewhouse');
  });
  hot.forEach((v, i) => {
    const icoFn = VESSEL_ICONS[v.vessel_type] || icoBuffer;
    hotHtml += vesselRow(v, water.length + i, icoFn, [20,25,30,35,40], v.volume_hl, 'HL', 'brewhouse');
  });
  ytRows.forEach((v, i) => {
    ytHtml += vesselRow(v, i, icoYt, [8,10,12,15,20], v.capacity_hl, 'HL', 'yt');
  });

  cfg.innerHTML = `
    <div class="sdm-cfg-head"><h3>Eau — HLT &amp; CLT</h3><span class="tot"><b>${water.length}</b> cuve(s)</span></div>
    ${waterHtml || '<p class="sdm-cfg-note">Aucune cuve eau configurée.</p>'}
    <button class="sdm-addrow" data-sdm-addzone="water">+ Ajouter</button>
    <div class="sdm-cfg-head" style="margin-top:16px"><h3>Brassage — ${brewsize} HL nominal</h3><span class="tot"><b>${hot.length}</b> cuves</span></div>
    ${hotHtml || '<p class="sdm-cfg-note">Aucune cuve brassage configurée.</p>'}
    <button class="sdm-addrow" data-sdm-addzone="hot">+ Ajouter</button>
    <div class="sdm-cfg-head" style="margin-top:16px"><h3>Cuves à levure — YT</h3><span class="tot"><b>${ytRows.length}</b> cuves</span></div>
    ${ytHtml || '<p class="sdm-cfg-note">Aucune cuve levure configurée.</p>'}
    <button class="sdm-addrow" data-sdm-addzone="yt">+ Ajouter</button>
    <div class="sdm-cfg-note" style="margin-top:12px">Le volume de brassin nominal pilote la normalisation des recettes.</div>`;
}

function vesselRow(v, i, icoFn, sizes, currentSize, unit, tableKey) {
  const delay = 0.06 + i * 0.04;
  const sizeBtns = sizes.map(s => {
    const on = parseFloat(currentSize) === s ? ' on' : '';
    return `<button class="sdm-szpick-btn${on}" data-action="vessel-size"
      data-table="${escHtml(tableKey)}" data-id="${escHtml(v.id)}" data-val="${s}">${s}${unit}</button>`;
  }).join('');
  const nmLabel = v.name || (v.vessel_type ? (VESSEL_TYPE_LABELS[v.vessel_type] || v.vessel_type) : `#${v.number}`);
  return `<div class="sdm-vrow sdm-reveal" style="animation-delay:${delay}s">
    <div class="ico">${icoFn()}</div>
    <div class="meta">
      <div class="nm">${escHtml(nmLabel)}</div>
      <div class="kv">${v.vessel_type || 'cuve'} · #${v.number || v.id}</div>
    </div>
    <div class="sdm-szpick">${sizeBtns}</div>
    <button class="sdm-del" data-action="vessel-del"
      data-table="${escHtml(tableKey)}" data-id="${escHtml(v.id)}"
      title="Supprimer">✕</button>
  </div>`;
}

/* ── Cave panel ────────────────────────────────────────────────── */
function paintCellar() {
  const cfg = document.getElementById('cfgCellar');
  if (!cfg) return;
  const ccts = window.SDM_CCT || [];
  const bbts = window.SDM_BBT || [];
  const machines = window.SDM_CELLAR_MACHINES || [];

  let cctHtml = '', bbtHtml = '', machHtml = '';
  const CCT_SIZES = [30, 60, 90, 120, 150, 240];
  const BBT_SIZES = [30, 60, 90, 120, 150, 240];

  ccts.forEach((v, i) => { cctHtml += vesselRow(v, i, icoCct, CCT_SIZES, v.capacity_hl, '', 'cct'); });
  bbts.forEach((v, i) => { bbtHtml += vesselRow(v, i, icoBbt, BBT_SIZES, v.capacity_hl, '', 'bbt'); });

  machines.forEach((m, i) => {
    const delay = 0.06 + i * 0.06;
    const icoFn = m.machine_type === 'kze' ? icoKze : icoCentrifuge;
    const val   = m.throughput_hl_h || 0;
    machHtml += `<div class="sdm-vrow sdm-reveal" style="animation-delay:${delay}s">
      <div class="ico">${icoFn()}</div>
      <div class="meta">
        <div class="nm">${escHtml(m.name || m.machine_type)}</div>
        <div class="kv">${escHtml(m.machine_type)} · <b>${val} HL/h</b></div>
      </div>
      <div class="sdm-stepper">
        <button data-action="machine-step" data-id="${m.id}" data-field="throughput_hl_h" data-delta="-5">−</button>
        <span class="val">${val}</span>
        <button data-action="machine-step" data-id="${m.id}" data-field="throughput_hl_h" data-delta="5">+</button>
      </div>
      <button class="sdm-del" data-action="machine-del" data-id="${m.id}" title="Supprimer">✕</button>
    </div>`;
  });

  const cctTotal = ccts.reduce((a, v) => a + parseFloat(v.capacity_hl || 0), 0);
  const bbtTotal = bbts.reduce((a, v) => a + parseFloat(v.capacity_hl || 0), 0);

  cfg.innerHTML = `
    <div class="sdm-cfg-head"><h3>Fermenteurs — CCT</h3><span class="tot"><b>${cctTotal} HL</b> · ${ccts.length} cuves</span></div>
    ${cctHtml || '<p class="sdm-cfg-note">Aucun CCT configuré.</p>'}
    <button class="sdm-addrow" data-sdm-addzone="cct">+ Ajouter CCT</button>
    <div class="sdm-cfg-head" style="margin-top:16px"><h3>Cuves de garde — BBT</h3><span class="tot"><b>${bbtTotal} HL</b> · ${bbts.length} cuves</span></div>
    ${bbtHtml || '<p class="sdm-cfg-note">Aucun BBT configuré.</p>'}
    <button class="sdm-addrow" data-sdm-addzone="bbt">+ Ajouter BBT</button>
    <div class="sdm-cfg-head" style="margin-top:16px"><h3>Machines de process</h3></div>
    ${machHtml || '<p class="sdm-cfg-note">Aucune machine configurée.</p>'}
    <button class="sdm-addrow" data-sdm-addzone="cellar-machine">+ Ajouter</button>
    <div class="sdm-cfg-note" style="margin-top:12px">Centrifugeuse + KZE = clarification + pasteurisation flash avant conditionnement.</div>`;
}

/* ── Conditionnement panel ─────────────────────────────────────── */
function paintPkg() {
  const cfg = document.getElementById('cfgPkg');
  if (!cfg) return;
  const machines  = window.SDM_PKG_MACHINES  || [];
  const formats   = window.SDM_PKG_FORMATS   || [];
  const fillerContainers = window.SDM_FILLER_CONTAINERS || {}; // { machine_id: [container_codes] }
  const containerMi      = window.SDM_CONTAINER_MI      || {}; // { container_code: mi_id }

  // Build set of active container codes across all active fillers
  const activeContainerCodes = new Set();
  machines.forEach(m => {
    if (m.is_active && m.takes_containers) {
      const conts = fillerContainers[m.id] || [];
      conts.forEach(c => activeContainerCodes.add(c.container_code));
    }
  });

  let machHtml = '';
  machines.forEach((m, i) => {
    machHtml += pkgMachineRow(m, i, fillerContainers, activeContainerCodes, formats, containerMi);
  });

  // Cartoner format section (derived from active filler containers)
  const cartoner = machines.find(m => m.machine_type === 'cartoner');
  let cartonerHtml = '';
  if (cartoner) {
    cartonerHtml = pkgCartonerRow(cartoner, formats, activeContainerCodes);
  }

  cfg.innerHTML = `
    <div class="sdm-cfg-head"><h3>Machines de conditionnement</h3><span class="tot"><b>${machines.length}</b> machines</span></div>
    ${machHtml || '<p class="sdm-cfg-note">Aucune machine configurée. Migration 140 non appliquée.</p>'}
    <button class="sdm-addrow" data-sdm-addzone="pkg-machine">+ Ajouter machine</button>
    ${cartonerHtml}
    ${servingTanksCard()}
    <div class="sdm-cfg-note" style="margin-top:12px">Les formats encartonneuse sont gérés par contenants actifs. Un format ne s'active que si son contenant est supporté par une soutireuse active.</div>`;
}

/* ── Serving tanks capacity card ─────────────────────────────── */
function servingTanksCard() {
  const tanks = window.SDM_SERVING_TANKS || [];
  const totalHl = window.SDM_SERVING_TANK_TOTAL || 0;

  if (tanks.length === 0) return '';

  // Group by capacity for compact display
  const grouped = {};
  tanks.forEach(t => {
    const key = parseFloat(t.capacity_hl).toFixed(0);
    if (!grouped[key]) grouped[key] = [];
    grouped[key].push(t);
  });

  let groupHtml = '';
  Object.entries(grouped)
    .sort((a, b) => Number(a[0]) - Number(b[0]))
    .forEach(([cap, group]) => {
      const statusBadges = group.map(t => {
        const cls = t.status === 'active' ? 'sdm-stank-dot active' : (t.status === 'maintenance' ? 'sdm-stank-dot maint' : 'sdm-stank-dot ret');
        return `<span class="${cls}" title="Cuve #${escHtml(String(t.number))} — ${escHtml(t.status)}"></span>`;
      }).join('');
      groupHtml += `<div class="sdm-stank-group">
        <span class="sdm-stank-cap">${escHtml(cap)} HL</span>
        <span class="sdm-stank-count">×${group.length}</span>
        <span class="sdm-stank-dots">${statusBadges}</span>
      </div>`;
    });

  return `<div class="sdm-cfg-head" style="margin-top:18px">
    <h3>Cuves de service</h3>
    <span class="tot"><b>${totalHl} HL</b> · ${tanks.length} cuves</span>
  </div>
  <div class="sdm-vrow sdm-reveal sdm-stank-card" style="animation-delay:.1s;flex-wrap:wrap;gap:8px;">
    <div class="ico">${icoCuvFiller()}</div>
    <div class="meta">
      <div class="nm">Cuves de service — in-house</div>
      <div class="kv">Format V (cuve de service) · capacité totale : ${totalHl} HL</div>
    </div>
    <div class="sdm-stank-groups">${groupHtml}</div>
    <div class="sdm-stank-note">Registre des cuves en propre. Les cuves client sont gérées séparément. La disponibilité du format V dépend de la tireuse cuves de service — pas du registre des cuves.</div>
  </div>`;
}

function pkgMachineRow(m, i, fillerContainers, activeContainerCodes, formats, containerMi) {
  const delay = 0.06 + i * 0.07;
  const icoMap = { filler_bottle: icoBottleFiller, filler_can: icoCanLine, filler_keg: icoKegFiller, filler_cuv: icoCuvFiller, cartoner: icoCartoner, centrifuge: icoCentrifuge, kze: icoKze };
  const icoFn  = icoMap[m.machine_type] || icoCartoner;
  const typeLabel = { filler_bottle:'Soutireuse bouteilles', filler_can:'Ligne canettes', filler_keg:'Soutireuse fûts', filler_cuv:'Tireuse cuves de service', cartoner:'Encartonneuse', centrifuge:'Centrifugeuse', kze:'KZE' }[m.machine_type] || m.machine_type;

  if (m.machine_type === 'cartoner') return ''; // rendered separately

  let rightSide = '';
  if (m.takes_containers) {
    // Container toggle badges
    const myConts = fillerContainers[m.id] || [];
    const contBadges = myConts.map(c => {
      const miId = containerMi[c.container_code] || null;
      const miNote = miId ? ` · MI ${miId}` : ' · réutilisable';
      return `<span class="sdm-cont-badge">${escHtml(c.display_name)}${miNote}</span>`;
    }).join(' ');
    const speedVal = m.speed_units_h || 0;
    rightSide = `
      <div class="sdm-stepper">
        <button data-action="machine-step" data-id="${m.id}" data-field="speed_units_h" data-delta="-100">−</button>
        <span class="val">${speedVal}</span>
        <button data-action="machine-step" data-id="${m.id}" data-field="speed_units_h" data-delta="100">+</button>
      </div>`;
    return `<div class="sdm-vrow sdm-reveal" style="animation-delay:${delay}s;flex-wrap:wrap;gap:8px;">
      <div class="ico">${icoFn()}</div>
      <div class="meta">
        <div class="nm">${escHtml(m.name || typeLabel)}</div>
        <div class="kv">${escHtml(typeLabel)} · ${speedVal} u/h · ${myConts.length > 0 ? contBadges : '<span style="opacity:.5">aucun contenant</span>'}</div>
      </div>
      ${rightSide}
      <button class="sdm-del" data-action="machine-del" data-id="${m.id}" title="Supprimer">✕</button>
    </div>`;
  }

  // throughput machines (centrifuge/kze) — not shown in pkg panel (cellar only)
  return '';
}

function pkgCartonerRow(cartoner, formats, activeContainerCodes) {
  // Map format run_type → container_codes that support it
  const runTypeToContainer = { bot:'BOT_GLASS_33', can:'CAN_ALU_50', can33:'CAN_ALU_33', keg:'KEG_INOX_20', cuv:'CUV_LINER' };

  let fmtHtml = '';
  // Group formats by run_type
  const byType = {};
  formats.forEach(f => {
    const rt = f.run_type || 'other';
    if (!byType[rt]) byType[rt] = [];
    byType[rt].push(f);
  });

  const rtLabels = { bot:'Bouteille', can:'Canette 50cl', can33:'Canette 33cl', keg:'Fût', cuv:'Cuve de service', '':'Composite/Autre' };
  Object.entries(byType).forEach(([rt, fmts]) => {
    const contCode = runTypeToContainer[rt];
    const isGated = contCode ? !activeContainerCodes.has(contCode) : false;
    const groupLabel = rtLabels[rt] || rt;
    fmtHtml += `<div style="margin-top:10px;margin-bottom:4px;font-family:'JetBrains Mono',monospace;font-size:9px;letter-spacing:.15em;color:var(--ink-mute);text-transform:uppercase;">${escHtml(groupLabel)}${isGated ? ' — <span style="color:var(--ember)">soutireuse inactive ⊘</span>' : ''}</div>`;
    fmts.forEach(f => {
      const gated = isGated ? ' sdm-fmt-btn gated' : '';
      const on    = (!isGated && f.is_active) ? ' on' : '';
      fmtHtml += `<button class="sdm-fmt-btn${on}${gated}"
        data-action="format-toggle" data-id="${f.id}" data-code="${escHtml(f.format_code)}"
        ${isGated ? 'disabled' : ''}
        title="${isGated ? 'Soutireuse ou contenant inactif — non disponible' : escHtml(f.display_name)}"
        >${escHtml(f.format_code)} — ${escHtml(f.display_name)}</button>`;
    });
  });

  return `<div class="sdm-cfg-head" style="margin-top:18px">
    <h3>Formats encartonneuse</h3>
    <span class="tot"><b>${formats.filter(f=>f.is_active).length}/${formats.length}</b> actifs</span>
  </div>
  <div class="sdm-vrow" style="flex-wrap:wrap;gap:6px;padding:12px;">
    <div class="ico">${icoCartoner()}</div>
    <div class="meta"><div class="nm">Encartonneuse</div><div class="kv">formats multi-sélection</div></div>
    <div class="sdm-fmt-wrap">${fmtHtml}</div>
  </div>`;
}

/* ── POST helper (submits hidden form) ─────────────────────────── */
function postAction(fields) {
  const form = document.createElement('form');
  form.method = 'POST';
  form.action = '';
  const csrf = document.getElementById('sdmCsrf');
  Object.assign(fields, { csrf: csrf ? csrf.value : '' });
  Object.entries(fields).forEach(([k, v]) => {
    const inp = document.createElement('input');
    inp.type = 'hidden'; inp.name = k; inp.value = v;
    form.appendChild(inp);
  });
  document.body.appendChild(form);
  form.submit();
}

/* ── Event delegation ──────────────────────────────────────────── */
document.addEventListener('click', function (e) {
  const role = getRole();
  const t = e.target;

  /* size picker (vessels) */
  const szBtn = t.closest('[data-action="vessel-size"]');
  if (szBtn) {
    e.preventDefault();
    if (role === 'operateur') return;
    if (role === 'manager') { showToast('Demande de modification envoyée à l\'administrateur'); return; }
    if (!confirm(`Confirmer → ${szBtn.dataset.val} HL ?`)) return;
    postAction({ action: 'vessel-update', table: szBtn.dataset.table, id: szBtn.dataset.id, capacity_hl: szBtn.dataset.val });
    return;
  }

  /* vessel delete */
  const delVessel = t.closest('[data-action="vessel-del"]');
  if (delVessel) {
    e.preventDefault();
    if (role === 'operateur') return;
    if (role === 'manager') { showToast('Demande de suppression envoyée à l\'administrateur'); return; }
    if (!confirm('Supprimer cette cuve ?')) return;
    postAction({ action: 'vessel-del', table: delVessel.dataset.table, id: delVessel.dataset.id });
    return;
  }

  /* machine stepper */
  const stepBtn = t.closest('[data-action="machine-step"]');
  if (stepBtn) {
    e.preventDefault();
    if (role === 'operateur') return;
    if (role === 'manager') { showToast('Demande de modification envoyée à l\'administrateur'); return; }
    const valEl = stepBtn.parentElement.querySelector('.val');
    if (!valEl) return;
    const oldVal = parseFloat(valEl.textContent) || 0;
    const delta  = parseFloat(stepBtn.dataset.delta) || 0;
    const newVal = Math.max(0, oldVal + delta);
    if (!confirm(`Confirmer → ${newVal} ?`)) return;
    postAction({ action: 'machine-update', id: stepBtn.dataset.id, field: stepBtn.dataset.field, value: newVal });
    return;
  }

  /* machine delete */
  const delMach = t.closest('[data-action="machine-del"]');
  if (delMach) {
    e.preventDefault();
    if (role === 'operateur') return;
    if (role === 'manager') { showToast('Demande de suppression envoyée à l\'administrateur'); return; }
    if (!confirm('Supprimer cette machine ?')) return;
    postAction({ action: 'machine-del', id: delMach.dataset.id });
    return;
  }

  /* format toggle (encartonneuse) */
  const fmtBtn = t.closest('[data-action="format-toggle"]');
  if (fmtBtn) {
    e.preventDefault();
    if (role === 'operateur') return;
    if (fmtBtn.classList.contains('gated')) { showToast('Format non disponible — soutireuse ou contenant inactif'); return; }
    if (role === 'manager') { showToast('Demande de modification envoyée à l\'administrateur'); return; }
    const turningOn = !fmtBtn.classList.contains('on');
    if (!confirm(`${turningOn ? 'Activer' : 'Désactiver'} le format « ${fmtBtn.dataset.code} » ?`)) return;
    postAction({ action: 'format-toggle', id: fmtBtn.dataset.id, is_active: turningOn ? 1 : 0 });
    return;
  }

  /* add zone buttons */
  const addBtn = t.closest('[data-sdm-addzone]');
  if (addBtn) {
    e.preventDefault();
    if (role === 'operateur') return;
    if (role === 'manager') { showToast('Demande de modification envoyée à l\'administrateur'); return; }
    openAddModal(addBtn.dataset.sdmAddzone);
    return;
  }
});

/* ── Add modal ─────────────────────────────────────────────────── */
const ADD_TYPES = {
  water:          { label: 'Cuve eau (HLT/CLT)', fields: [{ name:'vessel_type', label:'Type', type:'select', opts:{hlt:'HLT — eau chaude', clt:'CLT — eau froide'} }, { name:'volume_hl', label:'Volume (HL)', type:'number', min:1, val:100 }] },
  hot:            { label: 'Cuve brassage', fields: [{ name:'vessel_type', label:'Type', type:'select', opts:{mash:'Maische',lauter:'Lauter',buffer:'Tampon',kettle:'Ébullition',whirlpool:'Whirlpool'} }, { name:'volume_hl', label:'Volume (HL)', type:'number', min:1, val:30 }] },
  yt:             { label: 'Cuve à levure (YT)', fields: [{ name:'capacity_hl', label:'Capacité (HL)', type:'number', min:1, val:12 }] },
  cct:            { label: 'CCT (fermenteur)', fields: [{ name:'capacity_hl', label:'Capacité (HL)', type:'number', min:1, val:90 }] },
  bbt:            { label: 'BBT (cuve de garde)', fields: [{ name:'capacity_hl', label:'Capacité (HL)', type:'number', min:1, val:120 }] },
  'cellar-machine':{ label: 'Machine de process (cave)', fields: [{ name:'machine_type', label:'Type', type:'select', opts:{centrifuge:'Centrifugeuse',kze:'KZE — pasteurisateur flash'} }, { name:'throughput_hl_h', label:'Débit (HL/h)', type:'number', min:1, val:30 }] },
  'pkg-machine':  { label: 'Machine conditionnement', fields: [{ name:'machine_type', label:'Type', type:'select', opts:{filler_bottle:'Soutireuse bouteilles',filler_can:'Ligne canettes',filler_keg:'Soutireuse fûts',filler_cuv:'Tireuse cuves de service'} }, { name:'speed_units_h', label:'Vitesse (u/h)', type:'number', min:0, val:0 }] },
};

function openAddModal(zone) {
  document.querySelector('.sdm-modal-overlay')?.remove();
  const def = ADD_TYPES[zone];
  if (!def) { showToast('Zone inconnue'); return; }
  const fieldsHtml = (def.fields || []).map(f => {
    if (f.type === 'select') {
      const opts = Object.entries(f.opts).map(([k,v]) => `<option value="${escHtml(k)}">${escHtml(v)}</option>`).join('');
      return `<label>${escHtml(f.label)}</label><select name="${escHtml(f.name)}">${opts}</select>`;
    }
    return `<label>${escHtml(f.label)}</label><input type="number" name="${escHtml(f.name)}" min="${f.min||0}" value="${f.val||0}">`;
  }).join('');
  const overlay = document.createElement('div');
  overlay.className = 'sdm-modal-overlay';
  overlay.innerHTML = `<div class="sdm-modal">
    <h4>Ajouter — ${escHtml(def.label)}</h4>
    ${fieldsHtml}
    <p class="modal-note">L'entrée sera enregistrée en base (audit log inclus).</p>
    <div class="modal-actions">
      <button class="btn-cancel" id="sdmModalCancel">Annuler</button>
      <button class="btn-confirm" id="sdmModalConfirm">Ajouter ↦</button>
    </div>
  </div>`;
  document.body.appendChild(overlay);
  document.getElementById('sdmModalCancel').onclick = () => overlay.remove();
  overlay.addEventListener('click', e => { if (e.target === overlay) overlay.remove(); });
  document.getElementById('sdmModalConfirm').onclick = () => {
    const form = overlay.querySelector('.sdm-modal');
    const data = { action: 'add', zone };
    form.querySelectorAll('input[name], select[name]').forEach(el => { data[el.name] = el.value; });
    overlay.remove();
    postAction(data);
  };
}

/* ── Init ──────────────────────────────────────────────────────── */
paintScenes();
// Detail croquis are painted lazily on first navigation (go() calls)
