(function() {
  'use strict';

  /* ============================================================
     CATEGORY METADATA — var at IIFE top level
     ============================================================ */
  var CAT_META = {
    malt:        { label: 'Malt',          chip: 'cat-chip--malt',    color: '#8a5210' },
    hops_kettle: { label: 'Houblon cuve',  chip: 'cat-chip--hops-k',  color: '#567020' },
    hops_dry:    { label: 'Houblon cru',   chip: 'cat-chip--hops-d',  color: '#7a8f3a' },
    mineral:     { label: 'Minéral',       chip: 'cat-chip--mineral',  color: '#2d7a88' },
    adjunct:     { label: 'Adjuvant',      chip: 'cat-chip--process',  color: '#5a4a8c' },
    process:     { label: 'Process',       chip: 'cat-chip--process',  color: '#5a4a8c' }
  };

  var CAT_ORDER = ['malt', 'hops_kettle', 'hops_dry', 'mineral', 'adjunct', 'process'];

  /* ============================================================
     UTILITIES
     ============================================================ */
  function parseDT(iso) {
    return new Date(iso.replace('T', ' '));
  }

  function computeDuration(start, end) {
    var s = parseDT(start), e = parseDT(end);
    var diffMs = e - s;
    var totalMin = Math.round(diffMs / 60000);
    var h = Math.floor(totalMin / 60);
    var m = totalMin % 60;
    return h + 'h' + String(m).padStart(2, '0');
  }

  function formatDateTime(iso) {
    if (!iso) return '—';
    return iso.replace('T', ' · ');
  }

  function formatFrenchDate(isoDate) {
    if (!isoDate) return '—';
    var MONTHS = ['janvier','février','mars','avril','mai','juin',
                  'juillet','août','septembre','octobre','novembre','décembre'];
    var parts = isoDate.split('-');
    return parseInt(parts[2]) + ' ' + MONTHS[parseInt(parts[1])-1] + ' ' + parts[0];
  }

  function esc(s) {
    return String(s == null ? '' : s)
      .replace(/&/g,'&amp;').replace(/</g,'&lt;')
      .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  }

  function fmtGravity(v) {
    var n = parseFloat(v);
    if (isNaN(n)) return '—';
    return n.toFixed(1) + '°P';
  }

  function fmtPh(v) {
    var n = parseFloat(v);
    if (isNaN(n)) return '—';
    return n.toFixed(2);
  }

  function fmtVol(v) {
    var n = parseFloat(v);
    if (isNaN(n)) return '—';
    return n.toFixed(1) + ' hL';
  }

  function fmtDil(v) {
    var n = parseFloat(v);
    if (isNaN(n)) return '—';
    return n.toFixed(1) + ' hL';
  }

  /* ============================================================
     SVG SCHEMATIC — renderBrewSchematic(brewData, idx)
     brewData: merged gravity readings + brew session data
     ============================================================ */
  function renderBrewSchematic(brewData, idx) {
    var VB_W = 960, VB_H = 400;

    var stations = [
      { x: 100, label: 'Première trempe',     type: 'mash'   },
      { x: 320, label: 'Chaudière pleine',    type: 'kettle' },
      { x: 545, label: 'Wort bouilli',        type: 'boil'   },
      { x: 790, label: 'Refroidissement / OG', type: 'cct'  }
    ];

    var readings = [
      [
        { glyph: 'ρ',  value: fmtGravity(brewData.firstwort_gravity), label: 'Densité' },
        { glyph: 'pH', value: fmtPh(brewData.firstwort_ph),           label: 'pH' }
      ],
      [
        { glyph: 'ρ',  value: fmtGravity(brewData.pfannevoll_gravity), label: 'Densité' }
      ],
      [
        { glyph: 'ρ',  value: fmtGravity(brewData.kochwurze_gravity), label: 'Densité' }
      ],
      [
        { glyph: 'OG', value: fmtGravity(brewData.final_gravity),  label: 'Densité finale', emphasis: true },
        { glyph: 'pH', value: fmtPh(brewData.final_ph),            label: 'pH',             emphasis: true },
        { glyph: 'V',  value: fmtVol(brewData.final_volume),       label: 'Volume' }
      ]
    ];

    function gravToFill(g) {
      var n = parseFloat(g);
      if (isNaN(n)) return 0.3;
      return Math.min(0.9, Math.max(0.15, (n - 10) / (16 - 10)));
    }

    var FILL_COLORS = [
      'rgba(190,160,90,0.18)',
      'rgba(160,120,55,0.22)',
      'rgba(130,85,30,0.28)',
      'rgba(47,101,117,0.20)'
    ];

    var defs = ''
      + '<defs>'
      + '<pattern id="hatch-' + idx + '" patternUnits="userSpaceOnUse" width="6" height="6" patternTransform="rotate(45)">'
      + '<line x1="0" y1="0" x2="0" y2="6" stroke="#8a7250" stroke-width="0.4" opacity="0.25"/>'
      + '</pattern>'
      + '<pattern id="cone-hatch-' + idx + '" patternUnits="userSpaceOnUse" width="4" height="4" patternTransform="rotate(45)">'
      + '<line x1="0" y1="0" x2="4" y2="4" stroke="#5a3a12" stroke-width="0.5" opacity="0.45"/>'
      + '<line x1="4" y1="0" x2="0" y2="4" stroke="#5a3a12" stroke-width="0.5" opacity="0.45"/>'
      + '</pattern>'
      + '<pattern id="grid-' + idx + '" patternUnits="userSpaceOnUse" width="40" height="40">'
      + '<path d="M 40 0 L 0 0 0 40" fill="none" stroke="#a08060" stroke-width="0.4" opacity="0.10"/>'
      + '</pattern>'
      + '<pattern id="grid-sm-' + idx + '" patternUnits="userSpaceOnUse" width="8" height="8">'
      + '<path d="M 8 0 L 0 0 0 8" fill="none" stroke="#a08060" stroke-width="0.15" opacity="0.07"/>'
      + '</pattern>'
      + '</defs>';

    function vesselMash(cx, fillLevel, fillColor, si) {
      const w = 130, hTall = 140;
      const x = cx - w/2, y = 115;
      const trapTop = w * 0.85, trapBot = w;
      const txOffset = (w - trapTop) / 2;
      const liqH = Math.round(hTall * fillLevel);
      const liqY = y + hTall - liqH;
      const cid = 'clip-mash-' + idx + '-' + cx;
      const bottomY = y + hTall;
      return ''
        + '<defs><clipPath id="' + cid + '">'
        + '<polygon points="' + (x+txOffset) + ',' + y + ' ' + (x+w-txOffset) + ',' + y + ' ' + (x+w) + ',' + bottomY + ' ' + x + ',' + bottomY + '"/>'
        + '</clipPath></defs>'
        + '<ellipse cx="' + cx + '" cy="' + (bottomY+4) + '" rx="' + (w*0.6*0.5) + '" ry="2" fill="rgba(90,58,18,0.10)"/>'
        + '<polygon points="' + (x+txOffset) + ',' + y + ' ' + (x+w-txOffset) + ',' + y + ' ' + (x+w) + ',' + bottomY + ' ' + x + ',' + bottomY + '"'
        + ' fill="var(--bg,#f1e8d4)" stroke="none"/>'
        + '<rect class="liq-fill-' + si + '" x="' + x + '" y="' + liqY + '" width="' + w + '" height="' + liqH + '"'
        + ' fill="' + fillColor + '" clip-path="url(#' + cid + ')"/>'
        + '<polygon points="' + (x+txOffset) + ',' + y + ' ' + (x+w-txOffset) + ',' + y + ' ' + (x+w) + ',' + bottomY + ' ' + x + ',' + bottomY + '"'
        + ' fill="url(#hatch-' + idx + ')" opacity="1"/>'
        + '<line x1="' + x + '" y1="' + liqY + '" x2="' + (x+w) + '" y2="' + liqY + '"'
        + ' stroke="' + fillColor + '" stroke-width="1.2" stroke-dasharray="3,2" opacity="0.8"'
        + ' clip-path="url(#' + cid + ')"/>'
        + '<line x1="' + (x+txOffset+4) + '" y1="' + (y + Math.round(hTall/3)) + '" x2="' + (x+w-txOffset-4) + '" y2="' + (y + Math.round(hTall/3)) + '"'
        + ' stroke="#5a3a12" stroke-width="0.9" opacity="0.45"/>'
        + '<line x1="' + (x+txOffset+8) + '" y1="' + (y + Math.round(2*hTall/3)) + '" x2="' + (x+w-txOffset-8) + '" y2="' + (y + Math.round(2*hTall/3)) + '"'
        + ' stroke="#5a3a12" stroke-width="0.9" opacity="0.45"/>'
        + '<line x1="' + (x+txOffset+1.5) + '" y1="' + (y+2) + '" x2="' + (x+1.5) + '" y2="' + (bottomY-2) + '" stroke="#5a3a12" stroke-width="0.5" opacity="0.45"/>'
        + '<line x1="' + (x+txOffset+3.0) + '" y1="' + (y+2) + '" x2="' + (x+3.0) + '" y2="' + (bottomY-2) + '" stroke="#5a3a12" stroke-width="0.45" opacity="0.38"/>'
        + '<line x1="' + (x+txOffset+4.5) + '" y1="' + (y+2) + '" x2="' + (x+4.5) + '" y2="' + (bottomY-2) + '" stroke="#5a3a12" stroke-width="0.4" opacity="0.30"/>'
        + '<line x1="' + (x+w-txOffset-1.5) + '" y1="' + (y+2) + '" x2="' + (x+w-1.5) + '" y2="' + (bottomY-2) + '" stroke="#5a3a12" stroke-width="0.5" opacity="0.45"/>'
        + '<line x1="' + (x+w-txOffset-3.0) + '" y1="' + (y+2) + '" x2="' + (x+w-3.0) + '" y2="' + (bottomY-2) + '" stroke="#5a3a12" stroke-width="0.45" opacity="0.38"/>'
        + '<line x1="' + (x+w-txOffset-4.5) + '" y1="' + (y+2) + '" x2="' + (x+w-4.5) + '" y2="' + (bottomY-2) + '" stroke="#5a3a12" stroke-width="0.4" opacity="0.30"/>'
        + '<line x1="' + cx + '" y1="' + y + '" x2="' + cx + '" y2="' + bottomY + '" stroke="#8a7250" stroke-width="0.5" stroke-dasharray="4,3" opacity="0.3"/>'
        + '<line x1="' + cx + '" y1="' + (y + Math.round(hTall/2)) + '" x2="' + (cx + w*0.3) + '" y2="' + (y + Math.round(hTall/2)) + '"'
        + ' stroke="#5a3a12" stroke-width="0.8" opacity="0.5"/>'
        + '<polygon points="' + (x+txOffset) + ',' + y + ' ' + (x+w-txOffset) + ',' + y + ' ' + (x+w) + ',' + bottomY + ' ' + x + ',' + bottomY + '"'
        + ' fill="none" stroke="#5a3a12" stroke-width="1.5" stroke-linejoin="round"/>'
        + '<rect x="' + (x+w-txOffset/2-3) + '" y="' + (y + Math.round(hTall*0.65)) + '" width="6" height="4"'
        + ' fill="var(--bg,#f1e8d4)" stroke="#5a3a12" stroke-width="0.8"/>'
        + '<rect x="' + (x-4) + '" y="' + (y-5) + '" width="' + (w+8) + '" height="6" rx="2"'
        + ' fill="#ece0c6" stroke="#5a3a12" stroke-width="1.2"/>'
        + '<rect x="' + (cx-5) + '" y="' + (y-11) + '" width="10" height="10" rx="1"'
        + ' fill="#dcc9a4" stroke="#5a3a12" stroke-width="1.0"/>';
    }

    function vesselKettle(cx, fillLevel, type, fillColor, si) {
      const w = 110, hTall = 145;
      const x = cx - w/2, y = 105;
      const liqH = Math.round(hTall * fillLevel);
      const liqY = y + hTall - liqH;
      const bottomY = y + hTall;
      const cid = 'clip-kettle-' + idx + '-' + cx;
      const isBoil = type === 'boil';

      let bubblesSVG = '';
      if (isBoil) {
        const bxs = [cx-14, cx-5, cx+6];
        bubblesSVG = bxs.map(function(bx, bi) {
          const by = liqY + 12 + bi * 6;
          return '<circle cx="' + bx + '" cy="' + by + '" r="2.5" fill="rgba(86,112,32,0.25)" opacity="0.25"/>';
        }).join('');
      }

      return ''
        + '<defs><clipPath id="' + cid + '">'
        + '<rect x="' + x + '" y="' + y + '" width="' + w + '" height="' + hTall + '" rx="2"/>'
        + '</clipPath></defs>'
        + '<ellipse cx="' + cx + '" cy="' + (bottomY+4) + '" rx="' + (w*0.6*0.5) + '" ry="2" fill="rgba(90,58,18,0.10)"/>'
        + '<rect x="' + x + '" y="' + y + '" width="' + w + '" height="' + hTall + '" rx="2"'
        + ' fill="var(--bg,#f1e8d4)" stroke="none"/>'
        + '<rect class="liq-fill-' + si + '" x="' + x + '" y="' + liqY + '" width="' + w + '" height="' + liqH + '"'
        + ' fill="' + fillColor + '" clip-path="url(#' + cid + ')"/>'
        + '<rect x="' + x + '" y="' + y + '" width="' + w + '" height="' + hTall + '" rx="2"'
        + ' fill="url(#hatch-' + idx + ')"/>'
        + '<line x1="' + x + '" y1="' + liqY + '" x2="' + (x+w) + '" y2="' + liqY + '"'
        + ' stroke="' + fillColor + '" stroke-width="1.2" stroke-dasharray="3,2" opacity="0.8"/>'
        + '<line x1="' + (x+2) + '" y1="' + (y + Math.round(hTall/3)) + '" x2="' + (x+w-2) + '" y2="' + (y + Math.round(hTall/3)) + '"'
        + ' stroke="#5a3a12" stroke-width="0.9" opacity="0.45"/>'
        + '<line x1="' + (x+2) + '" y1="' + (y + Math.round(2*hTall/3)) + '" x2="' + (x+w-2) + '" y2="' + (y + Math.round(2*hTall/3)) + '"'
        + ' stroke="#5a3a12" stroke-width="0.9" opacity="0.45"/>'
        + '<line x1="' + (x+4) + '" y1="' + (y+4) + '" x2="' + (x+4) + '" y2="' + (bottomY-4) + '" stroke="#8a7250" stroke-width="0.5" opacity="0.35"/>'
        + '<line x1="' + (x+w-4) + '" y1="' + (y+4) + '" x2="' + (x+w-4) + '" y2="' + (bottomY-4) + '" stroke="#8a7250" stroke-width="0.5" opacity="0.35"/>'
        + '<line x1="' + (x+1.5) + '" y1="' + (y+2) + '" x2="' + (x+1.5) + '" y2="' + (bottomY-2) + '" stroke="#5a3a12" stroke-width="0.5" opacity="0.45"/>'
        + '<line x1="' + (x+3.0) + '" y1="' + (y+2) + '" x2="' + (x+3.0) + '" y2="' + (bottomY-2) + '" stroke="#5a3a12" stroke-width="0.45" opacity="0.38"/>'
        + '<line x1="' + (x+4.5) + '" y1="' + (y+2) + '" x2="' + (x+4.5) + '" y2="' + (bottomY-2) + '" stroke="#5a3a12" stroke-width="0.4" opacity="0.30"/>'
        + '<line x1="' + (x+w-1.5) + '" y1="' + (y+2) + '" x2="' + (x+w-1.5) + '" y2="' + (bottomY-2) + '" stroke="#5a3a12" stroke-width="0.5" opacity="0.45"/>'
        + '<line x1="' + (x+w-3.0) + '" y1="' + (y+2) + '" x2="' + (x+w-3.0) + '" y2="' + (bottomY-2) + '" stroke="#5a3a12" stroke-width="0.45" opacity="0.38"/>'
        + '<line x1="' + (x+w-4.5) + '" y1="' + (y+2) + '" x2="' + (x+w-4.5) + '" y2="' + (bottomY-2) + '" stroke="#5a3a12" stroke-width="0.4" opacity="0.30"/>'
        + '<rect x="' + x + '" y="' + y + '" width="' + w + '" height="' + hTall + '" rx="2"'
        + ' fill="none" stroke="#5a3a12" stroke-width="1.5"/>'
        + bubblesSVG
        + '<line x1="' + (cx-5) + '" y1="' + (bottomY-2) + '" x2="' + (cx+5) + '" y2="' + (bottomY-2) + '"'
        + ' stroke="#5a3a12" stroke-width="1.0" opacity="0.5"/>'
        + '<rect x="' + (x-3) + '" y="' + (y-4) + '" width="' + (w+6) + '" height="4" rx="2"'
        + ' fill="#dcc9a4" stroke="#5a3a12" stroke-width="1.2"/>';
    }

    function vesselCCT(cx, fillLevel, fillColor, si) {
      const w = 110, cylH = 105, coneH = 40;
      const x = cx - w/2;
      const y = 88;
      const coneY = y + cylH;
      const coneBottom = coneY + coneH;
      const totalH = cylH + coneH;
      const liqFill = Math.round(totalH * fillLevel * 0.75);
      const liqY = coneY - liqFill + coneH * 0.3;
      const cid = 'clip-cct-' + idx + '-' + cx;

      const legY = coneBottom + 2;
      const legBot = coneBottom + 30;
      const legssvg = ''
        + '<rect x="' + (cx - w*0.35) + '" y="' + legY + '" width="4" height="' + (legBot - legY) + '" rx="1"'
        + ' fill="var(--bg,#f1e8d4)" stroke="#5a3a12" stroke-width="1.1"/>'
        + '<rect x="' + (cx - 2) + '" y="' + legY + '" width="4" height="' + (legBot - legY) + '" rx="1"'
        + ' fill="var(--bg,#f1e8d4)" stroke="#5a3a12" stroke-width="1.1"/>'
        + '<rect x="' + (cx + w*0.32) + '" y="' + legY + '" width="4" height="' + (legBot - legY) + '" rx="1"'
        + ' fill="var(--bg,#f1e8d4)" stroke="#5a3a12" stroke-width="1.1"/>';

      return ''
        + '<defs>'
        + '<clipPath id="' + cid + '">'
        + '<polygon points="' + x + ',' + y + ' ' + (x+w) + ',' + y + ' ' + (x+w) + ',' + coneY + ' ' + cx + ',' + coneBottom + ' ' + x + ',' + coneY + '"/>'
        + '</clipPath>'
        + '<clipPath id="cone-' + cid + '">'
        + '<polygon points="' + x + ',' + coneY + ' ' + (x+w) + ',' + coneY + ' ' + cx + ',' + coneBottom + '"/>'
        + '</clipPath>'
        + '</defs>'
        + '<ellipse cx="' + cx + '" cy="' + (coneBottom+34) + '" rx="' + (w*0.35) + '" ry="2" fill="rgba(90,58,18,0.10)"/>'
        + legssvg
        + '<polygon points="' + x + ',' + y + ' ' + (x+w) + ',' + y + ' ' + (x+w) + ',' + coneY + ' ' + cx + ',' + coneBottom + ' ' + x + ',' + coneY + '"'
        + ' fill="var(--bg,#f1e8d4)" stroke="none"/>'
        + '<rect class="liq-fill-' + si + '" x="' + x + '" y="' + liqY + '" width="' + w + '" height="' + (liqFill+coneH) + '"'
        + ' fill="' + fillColor + '" clip-path="url(#' + cid + ')"/>'
        + '<polygon points="' + x + ',' + y + ' ' + (x+w) + ',' + y + ' ' + (x+w) + ',' + coneY + ' ' + cx + ',' + coneBottom + ' ' + x + ',' + coneY + '"'
        + ' fill="url(#hatch-' + idx + ')" opacity="1"/>'
        + '<rect x="' + x + '" y="' + coneY + '" width="' + w + '" height="' + coneH + '"'
        + ' fill="url(#cone-hatch-' + idx + ')" clip-path="url(#cone-' + cid + ')"/>'
        + '<line x1="' + x + '" y1="' + liqY + '" x2="' + (x+w) + '" y2="' + liqY + '"'
        + ' stroke="' + fillColor + '" stroke-width="1.2" stroke-dasharray="3,2" opacity="0.8"'
        + ' clip-path="url(#' + cid + ')"/>'
        + '<line x1="' + (x+2) + '" y1="' + (y + Math.round(cylH/3)) + '" x2="' + (x+w-2) + '" y2="' + (y + Math.round(cylH/3)) + '"'
        + ' stroke="#5a3a12" stroke-width="0.9" opacity="0.45"/>'
        + '<line x1="' + (x+2) + '" y1="' + (y + Math.round(2*cylH/3)) + '" x2="' + (x+w-2) + '" y2="' + (y + Math.round(2*cylH/3)) + '"'
        + ' stroke="#5a3a12" stroke-width="0.9" opacity="0.45"/>'
        + '<line x1="' + x + '" y1="' + coneY + '" x2="' + (x+w) + '" y2="' + coneY + '"'
        + ' stroke="#5a3a12" stroke-width="1.1" opacity="0.55"/>'
        + '<line x1="' + (x+1.5) + '" y1="' + (y+2) + '" x2="' + (x+1.5) + '" y2="' + coneY + '" stroke="#5a3a12" stroke-width="0.55" opacity="0.50"/>'
        + '<line x1="' + (x+3.0) + '" y1="' + (y+2) + '" x2="' + (x+3.0) + '" y2="' + coneY + '" stroke="#5a3a12" stroke-width="0.50" opacity="0.45"/>'
        + '<line x1="' + (x+4.5) + '" y1="' + (y+2) + '" x2="' + (x+4.5) + '" y2="' + coneY + '" stroke="#5a3a12" stroke-width="0.45" opacity="0.38"/>'
        + '<line x1="' + (x+w-1.5) + '" y1="' + (y+2) + '" x2="' + (x+w-1.5) + '" y2="' + coneY + '" stroke="#5a3a12" stroke-width="0.55" opacity="0.50"/>'
        + '<line x1="' + (x+w-3.0) + '" y1="' + (y+2) + '" x2="' + (x+w-3.0) + '" y2="' + coneY + '" stroke="#5a3a12" stroke-width="0.50" opacity="0.45"/>'
        + '<line x1="' + (x+w-4.5) + '" y1="' + (y+2) + '" x2="' + (x+w-4.5) + '" y2="' + coneY + '" stroke="#5a3a12" stroke-width="0.45" opacity="0.38"/>'
        + '<line x1="' + (x+1.5) + '" y1="' + coneY + '" x2="' + (cx-1.5) + '" y2="' + coneBottom + '" stroke="#5a3a12" stroke-width="0.55" opacity="0.50"/>'
        + '<line x1="' + (x+3.0) + '" y1="' + coneY + '" x2="' + (cx-3.0) + '" y2="' + coneBottom + '" stroke="#5a3a12" stroke-width="0.50" opacity="0.45"/>'
        + '<line x1="' + (x+4.5) + '" y1="' + coneY + '" x2="' + (cx-4.5) + '" y2="' + coneBottom + '" stroke="#5a3a12" stroke-width="0.45" opacity="0.38"/>'
        + '<line x1="' + (x+w-1.5) + '" y1="' + coneY + '" x2="' + (cx+1.5) + '" y2="' + coneBottom + '" stroke="#5a3a12" stroke-width="0.55" opacity="0.50"/>'
        + '<line x1="' + (x+w-3.0) + '" y1="' + coneY + '" x2="' + (cx+3.0) + '" y2="' + coneBottom + '" stroke="#5a3a12" stroke-width="0.50" opacity="0.45"/>'
        + '<line x1="' + (x+w-4.5) + '" y1="' + coneY + '" x2="' + (cx+4.5) + '" y2="' + coneBottom + '" stroke="#5a3a12" stroke-width="0.45" opacity="0.38"/>'
        + '<polygon points="' + x + ',' + y + ' ' + (x+w) + ',' + y + ' ' + (x+w) + ',' + coneY + ' ' + cx + ',' + coneBottom + ' ' + x + ',' + coneY + '"'
        + ' fill="none" stroke="#5a3a12" stroke-width="1.5" stroke-linejoin="round"/>'
        + '<rect x="' + (cx-2.5) + '" y="' + coneBottom + '" width="5" height="3"'
        + ' fill="var(--bg,#f1e8d4)" stroke="#5a3a12" stroke-width="0.8"/>'
        + '<rect x="' + (x-3) + '" y="' + (y-4) + '" width="' + (w+6) + '" height="5" rx="2"'
        + ' fill="#dcc9a4" stroke="#5a3a12" stroke-width="1.2"/>';
    }

    function drawInstrumentTag(cx, anchorY, glyph, value, label, emphasis) {
      const tagW = emphasis ? 64 : 56;
      const tagH = 32;
      const tagX = cx - tagW/2;
      const tagY = 18;
      const dividerY = tagY + 14;
      const valSize = emphasis ? 13 : 11;
      const valWeight = emphasis ? '600' : '500';
      const leaderY1 = tagY + tagH;

      const accentRing = emphasis
        ? '<rect x="' + (tagX-2) + '" y="' + (tagY-2) + '" width="' + (tagW+4) + '" height="' + (tagH+4) + '" rx="3"'
          + ' fill="none" stroke="#8b5e2a" stroke-width="0.6" opacity="0.5"/>'
        : '';

      return '<g aria-label="' + esc(label) + ': ' + esc(value) + '">'
        + '<circle cx="' + cx + '" cy="' + anchorY + '" r="2" fill="#8a7250"/>'
        + '<line x1="' + cx + '" y1="' + anchorY + '" x2="' + cx + '" y2="' + leaderY1 + '"'
        + ' stroke="#a08060" stroke-width="0.8"/>'
        + accentRing
        + '<rect x="' + tagX + '" y="' + tagY + '" width="' + tagW + '" height="' + tagH + '" rx="2"'
        + ' fill="rgba(241,232,212,0.97)" stroke="#8a7250" stroke-width="0.8"/>'
        + '<line x1="' + (tagX+1) + '" y1="' + dividerY + '" x2="' + (tagX+tagW-1) + '" y2="' + dividerY + '"'
        + ' stroke="#a08060" stroke-width="0.7" opacity="0.8"/>'
        + '<text x="' + cx + '" y="' + (tagY + 10) + '" text-anchor="middle" dominant-baseline="middle"'
        + ' font-family="\'JetBrains Mono\',monospace" font-size="9" font-weight="400"'
        + ' fill="#5a3a12" letter-spacing="0.08em">' + esc(glyph) + '</text>'
        + '<text x="' + cx + '" y="' + (dividerY + (tagH - 14)/2 + 4) + '" text-anchor="middle" dominant-baseline="middle"'
        + ' font-family="\'JetBrains Mono\',monospace" font-size="' + valSize + '" font-weight="' + valWeight + '"'
        + ' fill="#241b10">' + esc(value) + '</text>'
        + '</g>';
    }

    function drawPipe(x1, x2, y, isLast) {
      const midX = (x1 + x2) / 2;
      if (isLast) {
        return ''
          + '<path d="M' + x1 + ' ' + y + ' Q' + midX + ' ' + (y+10) + ' ' + x2 + ' ' + (y+14) + '"'
          + ' stroke="#a08060" stroke-width="5" fill="none"/>'
          + '<path class="pipe-flow" d="M' + x1 + ' ' + y + ' Q' + midX + ' ' + (y+10) + ' ' + x2 + ' ' + (y+14) + '"'
          + ' stroke="#c8b48a" stroke-width="1.5" fill="none"'
          + ' stroke-dasharray="6 4" opacity="0.6"/>'
          + '<polygon points="' + (x2-7) + ',' + (y+9) + ' ' + (x2+2) + ',' + (y+14) + ' ' + (x2-7) + ',' + (y+19) + '"'
          + ' fill="#a08060"/>';
      }
      return ''
        + '<line x1="' + x1 + '" y1="' + (y-2.5) + '" x2="' + x2 + '" y2="' + (y-2.5) + '"'
        + ' stroke="#a08060" stroke-width="1.8"/>'
        + '<line x1="' + x1 + '" y1="' + (y+2.5) + '" x2="' + x2 + '" y2="' + (y+2.5) + '"'
        + ' stroke="#a08060" stroke-width="1.8"/>'
        + '<line class="pipe-flow" x1="' + x1 + '" y1="' + y + '" x2="' + x2 + '" y2="' + y + '"'
        + ' stroke="#c8b48a" stroke-width="3" stroke-dasharray="6 4" opacity="0.6"/>'
        + '<polygon points="' + (midX-1) + ',' + (y-8) + ' ' + (midX+9) + ',' + y + ' ' + (midX-1) + ',' + (y+8) + '"'
        + ' fill="#a08060"/>';
    }

    var svgParts = [
      '<svg viewBox="0 0 ' + VB_W + ' ' + VB_H + '" xmlns="http://www.w3.org/2000/svg"'
      + ' role="img" aria-label="Schéma de brassage">'
    ];
    svgParts.push(defs);
    svgParts.push('<rect width="' + VB_W + '" height="' + VB_H + '" fill="url(#grid-sm-' + idx + ')"/>');
    svgParts.push('<rect width="' + VB_W + '" height="' + VB_H + '" fill="url(#grid-' + idx + ')"/>');

    var pipeY = 185;
    for (var pi = 0; pi < stations.length - 1; pi++) {
      const x1 = stations[pi].x + 65;
      const x2 = stations[pi+1].x - 65;
      const isLast = (pi === stations.length - 2);
      svgParts.push('<g class="station-' + (pi+1) + '">');
      svgParts.push(drawPipe(x1, x2, pipeY, isLast));
      svgParts.push('</g>');
    }

    for (var si = 0; si < stations.length; si++) {
      const st = stations[si];
      var gravVal;
      if (si === 0) gravVal = brewData.firstwort_gravity;
      else if (si === 1) gravVal = brewData.pfannevoll_gravity;
      else if (si === 2) gravVal = brewData.kochwurze_gravity;
      else gravVal = brewData.final_gravity;

      const fg = gravToFill(gravVal);
      const fillColor = FILL_COLORS[si];

      svgParts.push('<g class="station-' + si + '">');

      if (st.type === 'mash') {
        svgParts.push(vesselMash(st.x, fg, fillColor, si));
      } else if (st.type === 'cct') {
        svgParts.push(vesselCCT(st.x, fg, fillColor, si));
      } else {
        svgParts.push(vesselKettle(st.x, fg, st.type, fillColor, si));
      }

      svgParts.push(
        '<text x="' + st.x + '" y="380" text-anchor="middle"'
        + ' font-family="\'DM Sans\',sans-serif" font-size="11" font-weight="700"'
        + ' fill="#5a3a12" letter-spacing="0.06em">' + esc(st.label) + '</text>'
      );

      const stReadings = readings[si];
      const spacing = (si === 3) ? 58 : 52;
      const totalW = (stReadings.length - 1) * spacing;
      const startBx = st.x - totalW / 2;
      const vesselTopY = (st.type === 'mash') ? 115 : (st.type === 'cct') ? 88 : 105;

      for (var ri = 0; ri < stReadings.length; ri++) {
        const rd = stReadings[ri];
        const bx = startBx + ri * spacing;
        svgParts.push(drawInstrumentTag(bx, vesselTopY, rd.glyph, rd.value, rd.label, rd.emphasis || false));
      }

      svgParts.push('</g>');
    }

    svgParts.push('</svg>');
    return svgParts.join('\n');
  }

  /* ============================================================
     TIMINGS STRIP
     ============================================================ */
  function renderTimingsStrip(timingRow, brewData) {
    var duration = '—';
    var startFmt = '—';
    var endFmt = '—';
    if (timingRow && timingRow.brew_start && timingRow.brew_end) {
      duration = computeDuration(timingRow.brew_start, timingRow.brew_end);
      startFmt = formatDateTime(timingRow.brew_start);
      endFmt   = formatDateTime(timingRow.brew_end);
    } else if (timingRow && timingRow.brew_start) {
      startFmt = formatDateTime(timingRow.brew_start);
    }
    var dilution = fmtDil(brewData ? brewData.batch_dilution : null);
    return '<div class="timings-strip">'
      + '<div class="timings-cell"><div class="timings-cell__label">Début</div><div class="timings-cell__val">' + esc(startFmt) + '</div></div>'
      + '<div class="timings-cell"><div class="timings-cell__label">Fin</div><div class="timings-cell__val">' + esc(endFmt) + '</div></div>'
      + '<div class="timings-cell"><div class="timings-cell__label">Durée</div><div class="timings-cell__val timings-cell__val--accent">' + esc(duration) + '</div></div>'
      + '<div class="timings-cell"><div class="timings-cell__label">Dilution</div><div class="timings-cell__val">' + esc(dilution) + '</div></div>'
      + '</div>';
  }

  /* ============================================================
     YEAST PANEL
     ============================================================ */
  // start_ferm is 100% NULL in v2 — it was never captured. When absent, estimate
  // the start of fermentation from the latest brew_end across the batch's brews
  // (≈ when wort was cooled & pitched). Same day-0 anchor logic as tanks.php.
  function estimatePitchDate(brews) {
    if (!brews || !brews.length) return null;
    var best = null; // 'YYYY-MM-DD'
    for (var i = 0; i < brews.length; i++) {
      var cands = [];
      var ts = brews[i].timings || [];
      for (var j = 0; j < ts.length; j++) {
        if (ts[j].brew_end)        cands.push(ts[j].brew_end);
        else if (ts[j].event_date) cands.push(ts[j].event_date);
      }
      if (brews[i].event_date) cands.push(brews[i].event_date);
      for (var k = 0; k < cands.length; k++) {
        var d = String(cands[k]).slice(0, 10);
        if (d && (!best || d > best)) best = d;
      }
    }
    return best ? formatFrenchDate(best) : null;
  }

  function renderYeastPanel(b0, brews) {
    var strain = b0.yeast || '—';
    var gen = b0.yeast_gen || '—';
    var ytNum = b0.yt_number ? 'YT' + b0.yt_number : (b0.pitched_from || '—');
    var startFermHtml;
    if (b0.start_ferm) {
      startFermHtml = esc(formatDateTime(b0.start_ferm));
    } else {
      var est = estimatePitchDate(brews);
      startFermHtml = est
        ? esc(est) + ' <span class="yeast-field__est" title="Estimé d\'après la fin du dernier brassin (mise en cuve / pitch)">estimé</span>'
        : '—';
    }
    var rawNew = b0.new_yeast;
    var isNew = rawNew === true || rawNew === 1 || rawNew === '1'
      || (typeof rawNew === 'string' && /^(oui|yes|true)$/i.test(rawNew));
    var badge = isNew
      ? '<span class="yeast-badge yeast-badge--new">Levure neuve</span>'
      : '<span class="yeast-badge yeast-badge--repic">Repiquée</span>';

    return '<div class="yeast-panel">'
      + '<div class="yeast-panel__heading">Fermentation — Levure</div>'
      + '<div class="yeast-panel__fields">'
      + '<div class="yeast-field"><div class="yeast-field__label">Souche</div><div class="yeast-field__val">' + esc(strain) + '</div></div>'
      + '<div class="yeast-field"><div class="yeast-field__label">Génération</div><div class="yeast-field__val"><span class="gen-pill">' + esc(gen) + '</span></div></div>'
      + '<div class="yeast-field"><div class="yeast-field__label">Statut</div><div class="yeast-field__val">' + badge + '</div></div>'
      + '<div class="yeast-field"><div class="yeast-field__label">Repiquée de</div><div class="yeast-field__val">' + esc(ytNum) + '</div></div>'
      + '<div class="yeast-field"><div class="yeast-field__label">Début fermentation</div><div class="yeast-field__val">' + startFermHtml + '</div></div>'
      + '</div>'
      + '</div>';
  }

  /* ============================================================
     INGREDIENTS DOCKET
     ============================================================ */
  function renderDocket(ingredients) {
    var groups = {};
    for (var ci = 0; ci < CAT_ORDER.length; ci++) {
      groups[CAT_ORDER[ci]] = [];
    }
    for (var ii = 0; ii < ingredients.length; ii++) {
      var ing = ingredients[ii];
      if (groups[ing.category]) {
        groups[ing.category].push(ing);
      } else {
        groups[ing.category] = [ing];
      }
    }

    var totalKg = 0;
    for (var ti = 0; ti < ingredients.length; ti++) {
      if (ingredients[ti].unit === 'kg') {
        totalKg += parseFloat(ingredients[ti].qty) || 0;
      }
    }

    var catHtml = '';
    for (var coi = 0; coi < CAT_ORDER.length; coi++) {
      var cat = CAT_ORDER[coi];
      var rows = groups[cat];
      if (!rows || rows.length === 0) continue;
      var meta = CAT_META[cat] || { label: cat, chip: '' };
      var rowsHtml = '';
      for (var ri = 0; ri < rows.length; ri++) {
        var i = rows[ri];
        rowsHtml += '<div class="docket-row">'
          + '<span class="docket-row__name">' + esc(i.mi_name || i.raw_name || '') + '</span>'
          + '<span class="docket-row__qty">' + esc(i.qty) + ' ' + esc(i.unit) + '</span>'
          + '<span class="docket-row__lot">' + esc(i.lot || '') + '</span>'
          + '<span class="conf-dot conf-dot--' + esc(i.confidence || 'low') + '"'
          + ' title="Confiance : ' + esc(i.confidence || '') + '"></span>'
          + '</div>';
      }
      catHtml += '<div class="docket-category">'
        + '<div class="docket-cat-header">'
        + '<span class="cat-chip ' + esc(meta.chip) + '"></span>'
        + '<span class="cat-name">' + esc(meta.label) + '</span>'
        + '</div>'
        + rowsHtml
        + '</div>';
    }

    return '<div class="docket">'
      + '<div class="docket__header">'
      + '<span class="docket__title">Dossier d\'ingrédients</span>'
      + '<span class="docket__total-label">' + ingredients.length + ' ingrédients</span>'
      + '</div>'
      + catHtml
      + '<div class="docket__footer">'
      + '<span class="docket__total-label">Total malt + houblon (kg) :</span>'
      + '<span class="docket__total-kg">' + totalKg.toFixed(1) + ' kg</span>'
      + '</div>'
      + '</div>';
  }

  /* ============================================================
     BATCH VIEW
     ============================================================ */
  function renderBatch(container, apiData) {
    var b0 = apiData.brews[0];

    // Build gravity map keyed by brew number string, merging all rows
    var gravMap = {};
    for (var gi = 0; gi < apiData.gravity.length; gi++) {
      var gr = apiData.gravity[gi];
      var gk = String(gr.brew);
      if (!gravMap[gk]) gravMap[gk] = {};
      var keys = Object.keys(gr);
      for (var ki = 0; ki < keys.length; ki++) {
        if (gr[keys[ki]] !== null && gr[keys[ki]] !== undefined) {
          gravMap[gk][keys[ki]] = gr[keys[ki]];
        }
      }
    }

    var recipe = b0.recipe_name || b0.beer || '—';
    var classification = b0.classification || 'Neb';
    var classLabel = classification === 'Neb' ? 'Nébuleuse' : 'Contract';
    var pillClass = classification === 'Neb' ? 'pill--neb' : 'pill--contract';
    var frDate = formatFrenchDate(b0.event_date);
    var cctLabel = b0.cct ? 'CCT ' + b0.cct : '—';

    var html = '<div class="fiche-header">'
      + '<div class="fiche-header__inner">'
      + '<div class="fiche-header__left">'
      + '<div class="fiche-header__overline">Schéma de brassage</div>'
      + '<h1 class="fiche-recipe-name">' + esc(recipe) + '</h1>'
      + '<div class="fiche-pills"><span class="pill ' + pillClass + '">' + esc(classLabel) + '</span></div>'
      + '</div>'
      + '<div class="fiche-header__right">'
      + '<div class="fiche-meta-cell"><span class="fiche-meta-cell__label">Lot</span><span class="fiche-meta-cell__val">' + esc(b0.batch) + '</span></div>'
      + '<div class="fiche-meta-cell"><span class="fiche-meta-cell__label">Date</span><span class="fiche-meta-cell__val">' + esc(frDate) + '</span></div>'
      + '<div class="fiche-meta-cell"><span class="fiche-meta-cell__label">CCT</span><span class="fiche-meta-cell__val">' + esc(cctLabel) + '</span></div>'
      + '<div class="fiche-meta-cell"><span class="fiche-meta-cell__label">Brasseur</span><span class="fiche-meta-cell__val">—</span></div>'
      + '<div class="fiche-meta-cell"><span class="fiche-meta-cell__label">&nbsp;</span><span class="fiche-meta-cell__val"></span></div>'
      + '<div class="fiche-meta-cell"><span class="fiche-meta-cell__label">&nbsp;</span><span class="fiche-meta-cell__val"></span></div>'
      + '</div>'
      + '</div>'
      + '</div>';

    // Per-brew schematics
    for (var bi = 0; bi < apiData.brews.length; bi++) {
      var brew = apiData.brews[bi];
      var gData = gravMap[String(bi + 1)] || {};
      var brewData = {
        brew: brew,
        firstwort_gravity:  gData.firstwort_gravity  || null,
        firstwort_ph:       gData.firstwort_ph       || null,
        pfannevoll_gravity: gData.pfannevoll_gravity || null,
        kochwurze_gravity:  gData.kochwurze_gravity  || null,
        final_gravity:      gData.final_gravity      || null,
        final_ph:           gData.final_ph           || null,
        final_volume:       gData.final_volume       || null,
        batch_dilution:     gData.batch_dilution     || null
      };
      var timingRow = (brew.timings && brew.timings[0]) ? brew.timings[0] : null;

      html += '<div class="brew-section">'
        + '<div class="brew-section-label">Brassin ' + esc(bi + 1) + '</div>'
        + '<div class="brew-schematic-wrap">'
        + renderBrewSchematic(brewData, bi)
        + '</div>'
        + renderTimingsStrip(timingRow, brewData)
        + '</div>';
    }

    html += '<hr class="section-divider">';

    // Yeast panel — show when we have yeast data OR can show a (real/estimated)
    // fermentation start. start_ferm is 100% NULL in v2 so we estimate from brews.
    if (b0.yeast || b0.yeast_gen || b0.start_ferm || (apiData.brews && apiData.brews.length)) {
      html += renderYeastPanel(b0, apiData.brews);
    } else {
      html += '<div class="yeast-panel"><div class="yeast-panel__heading">Fermentation — Levure</div>'
        + '<div style="color:var(--ink-label);font-size:13px">Données non saisies.</div></div>';
    }

    // Docket
    html += renderDocket(apiData.ingredients);

    // Comments
    if (b0.comments) {
      html += '<div class="comments-block">'
        + '<div class="comments-block__label">Notes de brassage</div>'
        + '<p class="comments-block__text">' + esc(b0.comments) + '</p>'
        + '</div>';
    }

    container.innerHTML = html;
  }

  /* ============================================================
     DAY VIEW — miniature CCT card
     ============================================================ */
  function miniCCTSvg(og, classification) {
    var w = 68, cylH = 52, coneH = 22;
    var x = 6, y = 10;
    var fillLevel;
    var n = parseFloat(og);
    if (isNaN(n)) {
      fillLevel = 0.3;
    } else {
      fillLevel = Math.min(0.85, Math.max(0.15, (n - 10) / (16 - 10)));
    }
    var totalH = cylH + coneH;
    var liqH = Math.round(totalH * fillLevel * 0.72);
    var coneY = y + cylH;
    var coneBottom = coneY + coneH;
    var cx = x + w / 2;
    var liqFillColor = (classification === 'Neb')
      ? 'rgba(86,112,32,0.18)' : 'rgba(47,85,117,0.18)';
    var liqY = coneY - liqH + coneH * 0.25;
    var ogLabel = isNaN(n) ? '—' : n.toFixed(1) + '°P';

    return '<svg width="80" height="95" viewBox="0 0 80 95" xmlns="http://www.w3.org/2000/svg"'
      + ' aria-label="Cuve CCT — OG ' + esc(ogLabel) + '">'
      + '<defs>'
      + '<pattern id="mhatch" patternUnits="userSpaceOnUse" width="5" height="5" patternTransform="rotate(45)">'
      + '<line x1="0" y1="0" x2="0" y2="5" stroke="#c8b48a" stroke-width="0.5" opacity="0.35"/>'
      + '</pattern>'
      + '<clipPath id="mclip">'
      + '<polygon points="' + x + ',' + y + ' ' + (x+w) + ',' + y + ' ' + (x+w) + ',' + coneY + ' ' + cx + ',' + coneBottom + ' ' + x + ',' + coneY + '"/>'
      + '</clipPath>'
      + '</defs>'
      + '<polygon points="' + x + ',' + y + ' ' + (x+w) + ',' + y + ' ' + (x+w) + ',' + coneY + ' ' + cx + ',' + coneBottom + ' ' + x + ',' + coneY + '"'
      + ' fill="url(#mhatch)"/>'
      + '<rect x="' + x + '" y="' + liqY + '" width="' + w + '" height="' + (liqH + coneH) + '"'
      + ' fill="' + liqFillColor + '" clip-path="url(#mclip)"/>'
      + '<polygon points="' + x + ',' + y + ' ' + (x+w) + ',' + y + ' ' + (x+w) + ',' + coneY + ' ' + cx + ',' + coneBottom + ' ' + x + ',' + coneY + '"'
      + ' fill="none" stroke="#5a3a12" stroke-width="1.8"/>'
      + '<rect x="' + (x-2) + '" y="' + (y-3) + '" width="' + (w+4) + '" height="5" rx="1.5"'
      + ' fill="#dcc9a4" stroke="#5a3a12" stroke-width="1.2"/>'
      + '</svg>';
  }

  function renderDay(container, apiData) {
    if (!apiData.brews || apiData.brews.length === 0) {
      container.innerHTML = '<div style="color:var(--ink-label);padding:20px 0">Aucun brassin pour cette date.</div>';
      return;
    }

    var dateStr = (apiData.brews[0] && apiData.brews[0].event_date)
      ? formatFrenchDate(apiData.brews[0].event_date) : '—';

    var html = '<div class="day-header">Vue journalière <span>' + esc(dateStr) + '</span></div>'
      + '<div class="day-cards">';

    for (var i = 0; i < apiData.brews.length; i++) {
      var b = apiData.brews[i];
      var cls = b.classification || 'Neb';
      var classLabel = cls === 'Neb' ? 'Nébuleuse' : 'Contract';
      var pillCls = cls === 'Neb' ? 'pill--neb' : 'pill--contract';
      var t1 = (b.timings && b.timings[0]) ? b.timings[0] : null;
      var dur = '—';
      if (t1 && t1.brew_start && t1.brew_end) {
        dur = computeDuration(t1.brew_start, t1.brew_end);
      }

      var og = null;
      if (apiData.gravity) {
        for (var gi = 0; gi < apiData.gravity.length; gi++) {
          var gr = apiData.gravity[gi];
          if (String(gr.brew) === String(i+1) && gr.final_gravity != null) {
            og = gr.final_gravity;
            break;
          }
        }
      }

      html += '<div class="day-card" tabindex="0" role="button"'
        + ' data-recipe-id="' + esc(b.recipe_id_fk) + '"'
        + ' data-batch="' + esc(b.batch) + '"'
        + ' aria-label="Voir le brassin ' + esc(b.recipe_name || b.beer) + ' Lot ' + esc(b.batch) + '">'
        + '<div class="day-card__vessel">' + miniCCTSvg(og, cls) + '</div>'
        + '<div class="day-card__recipe">' + esc(b.recipe_name || b.beer) + '</div>'
        + '<div class="day-card__lot">Lot ' + esc(b.batch) + '</div>'
        + '<div class="day-card__pill"><span class="pill ' + pillCls + '">' + esc(classLabel) + '</span></div>'
        + '<div class="day-card__readings">'
        + '<div class="day-reading"><div class="day-reading__label">OG</div><div class="day-reading__val day-reading__val--hero">' + fmtGravity(og) + '</div></div>'
        + '<div class="day-reading"><div class="day-reading__label">pH</div><div class="day-reading__val day-reading__val--hero">—</div></div>'
        + '<div class="day-reading"><div class="day-reading__label">Volume</div><div class="day-reading__val">—</div></div>'
        + '<div class="day-reading"><div class="day-reading__label">Durée</div><div class="day-reading__val">' + esc(dur) + '</div></div>'
        + '</div>'
        + '</div>';
    }

    html += '</div>';
    container.innerHTML = html;
  }

  /* ============================================================
     PUBLIC API
     ============================================================ */
  window.BrewingConsulter = {
    renderBatch: renderBatch,
    renderDay:   renderDay,
  };

}());
