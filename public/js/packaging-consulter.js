/* ============================================================
   packaging-consulter.js
   Read-only packaging consultation renderer.
   Exposes window.PackagingConsulter = { renderBatch, renderDay }
   Called by lookup-panel.js renderPackagingBatch / renderPackagingDay.
   ============================================================ */
(function () {
  'use strict';

  /* ── Utilities ─────────────────────────────────────────── */
  function esc(s) {
    return String(s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;')
      .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }

  function formatFrenchDate(isoDate) {
    var MONTHS = ['janvier','février','mars','avril','mai','juin',
                  'juillet','août','septembre','octobre','novembre','décembre'];
    var parts = isoDate.split('-');
    return parseInt(parts[2]) + ' ' + MONTHS[parseInt(parts[1]) - 1] + ' ' + parts[0];
  }

  function fmtNum(n, decimals) {
    var d = decimals !== undefined ? decimals : 0;
    return Number(n).toFixed(d);
  }

  /* ── Format resolution from API event fields ──────────── */
  function resolveFormat(ev) {
    var rt = ev.run_type || '';
    var scope = ev.stocktake_scope || '';
    var is4pack = false;
    var isCage = false;
    var label = '—';

    if (rt === 'bot') {
      if (scope === 'cage') {
        label = 'Cage bouteilles';
        isCage = true;
      } else if (scope === '4pack') {
        label = 'Bouteille 33cl · 4-pack';
        is4pack = true;
      } else {
        label = 'Bouteille 33cl';
      }
    } else if (rt === 'can' || rt === 'can33') {
      if (scope === '4pack') {
        label = 'Canette 33cl · 4-pack';
        is4pack = true;
      } else {
        label = 'Canette 33cl';
      }
    } else if (rt === 'keg') {
      label = 'Fût / Keg';
    } else if (rt === 'cuv') {
      label = 'Cuve de service';
    } else {
      label = rt ? (rt.toUpperCase ? rt.toUpperCase() : rt) : '—';
    }

    return { label: label, is_4pack: is4pack, is_cage: isCage };
  }

  /* ── SVG helpers ───────────────────────────────────────── */

  function drawInstrumentTag(cx, anchorY, glyph, value, label, emphasis, overrideTagY) {
    var tagW = emphasis ? 78 : 68;
    var tagH = 38;
    var tagX = cx - tagW / 2;
    var tagY = (overrideTagY !== undefined) ? overrideTagY : (anchorY - tagH - 34);
    var dividerY = tagY + 16;

    var accentRing = emphasis ? `
      <rect x="${tagX-2}" y="${tagY-2}" width="${tagW+4}" height="${tagH+4}" rx="3"
            fill="none" stroke="#8b5e2a" stroke-width="0.7" opacity="0.55"/>
    ` : '';

    return `
      <g aria-label="${esc(label)}: ${esc(String(value))}">
        <circle cx="${cx}" cy="${anchorY}" r="2.5" fill="#8a7250"/>
        <line x1="${cx}" y1="${anchorY}" x2="${cx}" y2="${tagY+tagH}"
              stroke="#a08060" stroke-width="0.9"/>
        ${accentRing}
        <rect x="${tagX}" y="${tagY}" width="${tagW}" height="${tagH}" rx="2.5"
              fill="rgba(241,232,212,0.97)" stroke="#8a7250" stroke-width="0.9"/>
        <line x1="${tagX+1}" y1="${dividerY}" x2="${tagX+tagW-1}" y2="${dividerY}"
              stroke="#a08060" stroke-width="0.75" opacity="0.8"/>
        <text x="${cx}" y="${tagY+11}" text-anchor="middle" dominant-baseline="middle"
              font-family="'JetBrains Mono',monospace" font-size="10" font-weight="400"
              fill="#5a3a12" letter-spacing="0.08em">${esc(glyph)}</text>
        <text x="${cx}" y="${dividerY + (tagH-16)/2 + 5}" text-anchor="middle" dominant-baseline="middle"
              font-family="'JetBrains Mono',monospace" font-size="${emphasis ? 14 : 12}" font-weight="${emphasis ? '600' : '500'}"
              fill="#241b10">${esc(String(value))}</text>
      </g>
    `;
  }

  function buildFillTags(run) {
    var vendHl = run.vendable_hl != null ? fmtNum(run.vendable_hl, 2) + ' hL' : '—';
    var taxHl  = run.beer_tax_base_hl != null ? fmtNum(run.beer_tax_base_hl, 2) + ' hL' : '—';
    var co2    = (run.qa && run.qa.avg_co2 != null) ? fmtNum(parseFloat(run.qa.avg_co2), 1) + ' g/L' : '—';
    var o2     = (run.qa && run.qa.avg_o2  != null) ? parseFloat(run.qa.avg_o2).toFixed(1) + ' ppb' : '—';
    var units  = run.prod_total_units > 0
      ? run.prod_total_units.toLocaleString('fr-CH') + ' u' : '—';

    return [
      { glyph: 'U',    value: units,   label: 'Unités produites', emphasis: true },
      { glyph: 'VND',  value: vendHl,  label: 'Vol. vendable',    emphasis: true },
      { glyph: 'TX',   value: taxHl,   label: 'Base taxe-bière',  emphasis: false },
      { glyph: 'CO₂',  value: co2,     label: 'CO₂ moyen',        emphasis: false },
      { glyph: 'O₂',   value: o2,      label: 'O₂ moyen',         emphasis: false },
    ];
  }

  function drawBottle(cx, baseY) {
    var bodyW=11, bodyH=22, neckW=4.5, neckH=15, shoulderH=6;
    var bx = cx - bodyW/2;
    var topY = baseY - neckH - shoulderH - bodyH;
    var capY = topY - 4;
    return `
      <g>
        <rect x="${cx-3}" y="${capY}" width="6" height="4" rx="1"
              fill="#dcc9a4" stroke="#5a3a12" stroke-width="0.8"/>
        <rect x="${cx - neckW/2}" y="${topY}" width="${neckW}" height="${neckH}" rx="1"
              fill="rgba(190,150,60,0.18)" stroke="#5a3a12" stroke-width="0.8"/>
        <polygon points="${cx-neckW/2},${topY+neckH} ${cx+neckW/2},${topY+neckH} ${cx+bodyW/2},${topY+neckH+shoulderH} ${cx-bodyW/2},${topY+neckH+shoulderH}"
                 fill="rgba(190,150,60,0.18)" stroke="#5a3a12" stroke-width="0.8"/>
        <rect x="${bx}" y="${baseY - bodyH}" width="${bodyW}" height="${bodyH}" rx="2"
              fill="rgba(190,150,60,0.18)" stroke="#5a3a12" stroke-width="0.8"/>
        <rect x="${bx}" y="${baseY - bodyH + Math.round(bodyH*0.3)}" width="${bodyW}" height="${Math.round(bodyH*0.4)}" rx="0"
              fill="rgba(200,180,138,0.4)" stroke="#5a3a12" stroke-width="0.3" opacity="0.3"/>
        <line x1="${bx+1}" y1="${baseY - bodyH + Math.round(bodyH*0.5)}" x2="${bx+bodyW-1}" y2="${baseY - bodyH + Math.round(bodyH*0.5)}"
              stroke="rgba(86,112,32,0.3)" stroke-width="0.8"/>
      </g>
    `;
  }

  function drawCan(cx, baseY) {
    var cW=10, cH=24;
    var x = cx - cW/2;
    var y = baseY - cH;
    return `
      <g>
        <rect x="${x}" y="${y}" width="${cW}" height="${cH}" rx="1"
              fill="rgba(150,160,170,0.22)" stroke="#5a3a12" stroke-width="0.7"/>
        <ellipse cx="${cx}" cy="${y}" rx="5" ry="2.5"
                 fill="#dcc9a4" stroke="#5a3a12" stroke-width="0.7"/>
        <rect x="${cx-4}" y="${baseY-3}" width="8" height="3" rx="1"
              fill="#dcc9a4" stroke="#5a3a12" stroke-width="0.7"/>
        <path d="M ${cx-1},${y-1} A 2,1.5 0 0,1 ${cx+1},${y-1}" stroke="#5a3a12" stroke-width="0.8" fill="none"/>
        <line x1="${x+cW-0.5}" y1="${y+3}" x2="${x+cW-0.5}" y2="${baseY-3}"
              stroke="#5a3a12" stroke-width="0.4" opacity="0.25"/>
        <line x1="${x+0.5}" y1="${y + Math.round(cH/3)}" x2="${x+cW-0.5}" y2="${y + Math.round(cH/3)}"
              stroke="#5a3a12" stroke-width="0.5" opacity="0.35"/>
        <line x1="${x+0.5}" y1="${y + Math.round(2*cH/3)}" x2="${x+cW-0.5}" y2="${y + Math.round(2*cH/3)}"
              stroke="#5a3a12" stroke-width="0.5" opacity="0.35"/>
      </g>
    `;
  }

  function drawKeg(cx, baseY) {
    var kW=26, kH=18;
    var x = cx - kW/2;
    var y = baseY - kH;
    return `
      <g>
        <rect x="${cx-7}" y="${y-4}" width="14" height="4" rx="1"
              fill="#dcc9a4" stroke="#5a3a12" stroke-width="0.8"/>
        <rect x="${x}" y="${y}" width="${kW}" height="${kH}" rx="4"
              fill="rgba(140,143,148,0.22)" stroke="#5a3a12" stroke-width="0.8"/>
        <line x1="${x+2}" y1="${y + Math.round(kH*0.25)}" x2="${x+kW-2}" y2="${y + Math.round(kH*0.25)}"
              stroke="#5a3a12" stroke-width="0.6" opacity="0.4"/>
        <line x1="${x+2}" y1="${y + Math.round(kH*0.5)}" x2="${x+kW-2}" y2="${y + Math.round(kH*0.5)}"
              stroke="#5a3a12" stroke-width="0.6" opacity="0.4"/>
        <line x1="${x+2}" y1="${y + Math.round(kH*0.75)}" x2="${x+kW-2}" y2="${y + Math.round(kH*0.75)}"
              stroke="#5a3a12" stroke-width="0.6" opacity="0.4"/>
        <rect x="${cx-2}" y="${y-6}" width="4" height="6" rx="0.5"
              fill="#8a7250" stroke="#5a3a12" stroke-width="0.6"/>
        <circle cx="${cx}" cy="${y-7}" r="2.5" fill="#8a7250" stroke="#5a3a12" stroke-width="0.6"/>
        <path d="M ${x-2},${y+4} A 3,3 0 0,0 ${x-2},${y+kH-4}"
              stroke="#8a7250" stroke-width="0.8" fill="none" opacity="0.5"/>
        <path d="M ${x+kW+2},${y+4} A 3,3 0 0,1 ${x+kW+2},${y+kH-4}"
              stroke="#8a7250" stroke-width="0.8" fill="none" opacity="0.5"/>
      </g>
    `;
  }

  function drawFillerCarousel(cx, convY) {
    var discCX = cx;
    var discCY = convY - 76;
    var discR = 52;
    var hubR = 14;
    var p = [];

    p.push(`<ellipse cx="${discCX}" cy="${convY+6}" rx="54" ry="5"
      fill="rgba(60,40,20,0.12)"/>`);
    p.push(`<rect x="${cx-3}" y="${discCY - 58}" width="6" height="30" rx="1"
      fill="#dcc9a4" stroke="#5a3a12" stroke-width="1"/>`);
    p.push(`<rect x="${cx-10}" y="${discCY - 60}" width="20" height="8" rx="2"
      fill="#ece0c6" stroke="#5a3a12" stroke-width="1"/>`);
    p.push(`<circle cx="${cx-68}" cy="${convY-20}" r="16"
      stroke="#8a7250" stroke-width="1.2" fill="rgba(220,201,164,0.4)"/>`);
    for (var a = 0; a < 6; a++) {
      var ang = a * 60 * Math.PI / 180;
      p.push(`<line x1="${cx-68}" y1="${convY-20}"
        x2="${Math.round(cx-68 + Math.cos(ang)*16)}" y2="${Math.round(convY-20 + Math.sin(ang)*16)}"
        stroke="#8a7250" stroke-width="1" opacity="0.6"/>`);
    }
    p.push(`<circle cx="${cx+68}" cy="${convY-20}" r="16"
      stroke="#8a7250" stroke-width="1.2" fill="rgba(220,201,164,0.4)"/>`);
    for (var a2 = 0; a2 < 6; a2++) {
      var ang2 = a2 * 60 * Math.PI / 180;
      p.push(`<line x1="${cx+68}" y1="${convY-20}"
        x2="${Math.round(cx+68 + Math.cos(ang2)*16)}" y2="${Math.round(convY-20 + Math.sin(ang2)*16)}"
        stroke="#8a7250" stroke-width="1" opacity="0.6"/>`);
    }
    p.push(`<g class="filler-carousel" style="transform-origin:${discCX}px ${discCY}px">`);
    p.push(`<circle cx="${discCX}" cy="${discCY}" r="${discR}"
      stroke="#5a3a12" stroke-width="2.2" fill="rgba(241,232,212,0.9)"/>`);
    for (var s = 0; s < 8; s++) {
      var angS = s * 45 * Math.PI / 180;
      var sx1 = discCX + Math.cos(angS) * hubR;
      var sy1 = discCY + Math.sin(angS) * hubR;
      var sx2 = discCX + Math.cos(angS) * discR;
      var sy2 = discCY + Math.sin(angS) * discR;
      p.push(`<line x1="${sx1}" y1="${sy1}" x2="${sx2}" y2="${sy2}"
        stroke="#5a3a12" stroke-width="0.8" opacity="0.5"/>`);
    }
    p.push(`<circle cx="${discCX}" cy="${discCY}" r="${hubR}"
      stroke="#5a3a12" stroke-width="1.5" fill="#ece0c6"/>`);
    for (var n = 0; n < 6; n++) {
      var angN = n * 60 * Math.PI / 180;
      var nx = discCX + Math.cos(angN) * 38;
      var ny = discCY + Math.sin(angN) * 38;
      var deg = n * 60;
      p.push(`<rect x="${nx-2.5}" y="${ny-6}" width="5" height="12" rx="1"
        transform="rotate(${deg}, ${nx}, ${ny})"
        fill="#dcc9a4" stroke="#5a3a12" stroke-width="1.2"/>`);
      var tipX = nx + Math.cos(angN) * 6;
      var tipY = ny + Math.sin(angN) * 6;
      p.push(`<circle cx="${Math.round(tipX)}" cy="${Math.round(tipY)}" r="2.5"
        fill="#8a7250"/>`);
    }
    p.push(`</g>`);
    return p.join('\n');
  }

  function drawCapper(cx, convY) {
    var mW = 56, mH = 70;
    var mX = cx - mW/2;
    var mY = convY - mH;
    var p = [];

    p.push(`<ellipse cx="${cx}" cy="${convY+4}" rx="32" ry="4"
      fill="rgba(60,40,20,0.12)"/>`);
    p.push(`<rect x="${mX}" y="${mY}" width="${mW}" height="${mH}" rx="3"
      fill="rgba(241,232,212,0.92)" stroke="#5a3a12" stroke-width="1.8"/>`);
    p.push(`<rect x="${mX+1}" y="${mY+1}" width="${mW-2}" height="${mH-2}" rx="2"
      fill="url(#pkg-hatch)"/>`);
    p.push(`<line x1="${mX+4}" y1="${mY + Math.round(mH*0.25)}" x2="${mX+mW-4}" y2="${mY + Math.round(mH*0.25)}"
      stroke="#5a3a12" stroke-width="0.8" opacity="0.4"/>`);
    p.push(`<line x1="${mX+4}" y1="${mY + Math.round(mH*0.5)}" x2="${mX+mW-4}" y2="${mY + Math.round(mH*0.5)}"
      stroke="#5a3a12" stroke-width="0.8" opacity="0.4"/>`);
    p.push(`<line x1="${mX+4}" y1="${mY + Math.round(mH*0.75)}" x2="${mX+mW-4}" y2="${mY + Math.round(mH*0.75)}"
      stroke="#5a3a12" stroke-width="0.8" opacity="0.4"/>`);
    p.push(`<rect x="${cx-10}" y="${convY}" width="20" height="14" rx="2"
      fill="#dcc9a4" stroke="#5a3a12" stroke-width="1"/>`);
    var hopperW1 = 50, hopperW2 = 20, hopperH = 36;
    var hTopY = mY - hopperH;
    var hTopX = cx - hopperW1/2;
    var hBotX = cx - hopperW2/2;
    p.push(`<polygon points="${hTopX},${hTopY} ${hTopX+hopperW1},${hTopY} ${hBotX+hopperW2},${mY} ${hBotX},${mY}"
      fill="rgba(220,201,164,0.7)" stroke="#5a3a12" stroke-width="1.5"/>`);
    p.push(`<rect x="${cx-4}" y="${mY}" width="8" height="14" rx="1"
      fill="#dcc9a4" stroke="#5a3a12" stroke-width="0.8"/>`);
    p.push(`<line x1="${cx+60}" y1="${convY-110}" x2="${cx+28}" y2="${convY-88}"
      stroke="#8a7250" stroke-width="1.5" opacity="0.7"/>`);
    p.push(`<rect x="${cx+20}" y="${convY-96}" width="8" height="3" rx="0.5" fill="#dcc9a4" stroke="#5a3a12" stroke-width="0.5"/>`);
    p.push(`<rect x="${cx+20}" y="${convY-92}" width="8" height="3" rx="0.5" fill="#dcc9a4" stroke="#5a3a12" stroke-width="0.5"/>`);
    p.push(`<rect x="${cx+20}" y="${convY-88}" width="8" height="3" rx="0.5" fill="#dcc9a4" stroke="#5a3a12" stroke-width="0.5"/>`);
    return p.join('\n');
  }

  function drawLabeller(cx, convY) {
    var mW = 52, mH = 65;
    var mX = cx - mW/2;
    var mY = convY - mH;
    var reelCX = cx + 66;
    var reelCY = convY - 68;
    var p = [];

    p.push(`<ellipse cx="${cx}" cy="${convY+4}" rx="30" ry="4"
      fill="rgba(60,40,20,0.12)"/>`);
    p.push(`<rect x="${mX}" y="${mY}" width="${mW}" height="${mH}" rx="3"
      fill="rgba(241,232,212,0.92)" stroke="#5a3a12" stroke-width="1.8"/>`);
    p.push(`<rect x="${mX+2}" y="${mY+2}" width="${mW-4}" height="${mH-4}" rx="2"
      fill="url(#pkg-hatch)"/>`);
    p.push(`<rect x="${cx-9}" y="${convY}" width="18" height="10" rx="1"
      fill="#dcc9a4" stroke="#5a3a12" stroke-width="1"/>`);
    p.push(`<ellipse cx="${reelCX}" cy="${reelCY+34}" rx="28" ry="4" fill="rgba(60,40,20,0.08)"/>`);
    p.push(`<line x1="${cx+26}" y1="${convY-50}" x2="${reelCX-34}" y2="${reelCY}"
      stroke="#8a7250" stroke-width="2" stroke-linecap="round"/>`);
    p.push(`<circle cx="${reelCX-34}" cy="${reelCY}" r="3" fill="#8a7250"/>`);
    p.push(`<path d="M ${reelCX-34},${reelCY+10} C ${cx+20},${reelCY+20} ${cx+8},${convY-50} ${cx+6},${convY-30}"
      stroke="#c8b48a" stroke-width="5" fill="none" opacity="0.65"/>`);
    p.push(`<g class="reel-spin" style="transform-origin:${reelCX}px ${reelCY}px">`);
    p.push(`<circle cx="${reelCX}" cy="${reelCY}" r="34"
      stroke="#5a3a12" stroke-width="2" fill="rgba(235,225,200,0.85)"/>`);
    [10,14,18,22,27].forEach(function(r) {
      p.push(`<circle cx="${reelCX}" cy="${reelCY}" r="${r}"
        stroke="#8a7250" stroke-width="1.0" fill="none"
        stroke-dasharray="4 3" opacity="0.6"/>`);
    });
    p.push(`<circle cx="${reelCX}" cy="${reelCY}" r="11"
      fill="#dcc9a4" stroke="#5a3a12" stroke-width="1.2"/>`);
    p.push(`<rect x="${reelCX-4}" y="${reelCY-14}" width="8" height="28" rx="2"
      fill="#ece0c6" stroke="#5a3a12" stroke-width="1.2"/>`);
    p.push(`</g>`);
    return p.join('\n');
  }

  function drawCasePacker(cx, convY) {
    var mW = 60, mH = 68;
    var mX = cx - mW/2;
    var mY = convY - mH;
    var p = [];

    p.push(`<ellipse cx="${cx}" cy="${convY+4}" rx="34" ry="4"
      fill="rgba(60,40,20,0.12)"/>`);
    p.push(`<rect x="${mX}" y="${mY}" width="${mW}" height="${mH}" rx="2"
      fill="rgba(241,232,212,0.92)" stroke="#5a3a12" stroke-width="1.8"/>`);
    p.push(`<rect x="${mX+2}" y="${mY+2}" width="${mW-4}" height="${mH-4}" rx="1"
      fill="url(#pkg-hatch)"/>`);
    p.push(`<line x1="${mX+4}" y1="${mY + Math.round(mH/3)}" x2="${mX+mW-4}" y2="${mY + Math.round(mH/3)}"
      stroke="#5a3a12" stroke-width="0.8" opacity="0.4"/>`);
    p.push(`<line x1="${mX+4}" y1="${mY + Math.round(2*mH/3)}" x2="${mX+mW-4}" y2="${mY + Math.round(2*mH/3)}"
      stroke="#5a3a12" stroke-width="0.8" opacity="0.4"/>`);
    p.push(`<rect x="${cx-19}" y="${convY-26}" width="38" height="24" rx="1"
      fill="rgba(210,185,140,0.5)" stroke="#5a3a12" stroke-width="1.2"/>`);
    p.push(`<line x1="${cx-10}" y1="${convY-26}" x2="${cx+5}" y2="${convY-2}"
      stroke="#5a3a12" stroke-width="0.6" opacity="0.25"/>`);
    p.push(`<line x1="${cx+10}" y1="${convY-26}" x2="${cx-5}" y2="${convY-2}"
      stroke="#5a3a12" stroke-width="0.6" opacity="0.25"/>`);
    p.push(`<polygon points="${cx-19},${convY-26} ${cx-19},${convY-40} ${cx-30},${convY-46} ${cx-30},${convY-32}"
      fill="rgba(200,175,125,0.4)" stroke="#5a3a12" stroke-width="1.0"/>`);
    p.push(`<polygon points="${cx+19},${convY-26} ${cx+19},${convY-40} ${cx+30},${convY-46} ${cx+30},${convY-32}"
      fill="rgba(200,175,125,0.4)" stroke="#5a3a12" stroke-width="1.0"/>`);
    [0,1,2,3].forEach(function(i) {
      p.push(`<rect x="${cx+32}" y="${convY-55+i*4}" width="28" height="2" rx="0.5"
        stroke="#8a7250" stroke-width="0.8" fill="none" opacity="0.5"/>`);
    });
    return p.join('\n');
  }

  function drawRoboticPalletiser(cx, convY) {
    var p = [];
    var palX = cx + 18;
    var shoulderX = cx, shoulderY = convY - 28;
    var wristX = cx + 45, wristY = convY - 78;

    p.push(`<ellipse cx="${cx+30}" cy="${convY+6}" rx="60" ry="6"
      fill="rgba(60,40,20,0.10)"/>`);
    p.push(`<rect x="${cx-22}" y="${convY-16}" width="44" height="16" rx="2"
      fill="#dcc9a4" stroke="#5a3a12" stroke-width="2.5"/>`);
    p.push(`<rect x="${cx-14}" y="${convY-26}" width="28" height="10" rx="0"
      fill="#ece0c6" stroke="#5a3a12" stroke-width="2"/>`);
    p.push(`<circle cx="${cx}" cy="${convY-20}" r="10"
      fill="none" stroke="#8a7250" stroke-width="1.2"
      stroke-dasharray="4 3"/>`);
    p.push(`<rect x="${palX}" y="${convY-8}" width="72" height="8" rx="1"
      fill="#dcc9a4" stroke="#5a3a12" stroke-width="1.2"/>`);
    [palX+2, palX+28, palX+54].forEach(function(lx) {
      p.push(`<rect x="${lx}" y="${convY}" width="16" height="8" rx="1"
        fill="#c8b48a" stroke="#5a3a12" stroke-width="1.0"/>`);
    });
    [palX+4, palX+36].forEach(function(bx) {
      p.push(`<rect x="${bx}" y="${convY-24}" width="30" height="16" rx="1"
        fill="rgba(210,185,140,0.55)" stroke="#5a3a12" stroke-width="1.0"/>`);
      p.push(`<line x1="${bx+5}" y1="${convY-24}" x2="${bx+25}" y2="${convY-8}"
        stroke="#5a3a12" stroke-width="0.5" opacity="0.2"/>`);
    });
    [palX+4, palX+36].forEach(function(bx) {
      p.push(`<rect x="${bx}" y="${convY-40}" width="30" height="16" rx="1"
        fill="rgba(210,185,140,0.55)" stroke="#5a3a12" stroke-width="1.0"/>`);
      p.push(`<line x1="${bx+5}" y1="${convY-40}" x2="${bx+25}" y2="${convY-24}"
        stroke="#5a3a12" stroke-width="0.5" opacity="0.2"/>`);
    });
    p.push(`<rect x="${palX+20}" y="${convY-56}" width="30" height="16" rx="1"
      fill="rgba(225,205,160,0.45)" stroke="#5a3a12" stroke-width="0.9"/>`);
    p.push(`<line x1="${palX+25}" y1="${convY-56}" x2="${palX+45}" y2="${convY-40}"
      stroke="#5a3a12" stroke-width="0.5" opacity="0.2"/>`);
    p.push(`<g class="robot-arm" style="transform-origin:${shoulderX}px ${shoulderY}px">`);
    p.push(`<circle cx="${shoulderX}" cy="${shoulderY}" r="10"
      fill="#ece0c6" stroke="#5a3a12" stroke-width="1.5"/>`);
    [{dx:7,dy:0},{dx:-7,dy:0},{dx:0,dy:7},{dx:0,dy:-7}].forEach(function(pt) {
      p.push(`<circle cx="${shoulderX+pt.dx}" cy="${shoulderY+pt.dy}" r="2" fill="#8a7250"/>`);
    });
    p.push(`<rect x="${cx-9}" y="${convY-80}" width="18" height="52" rx="3"
      fill="rgba(236,224,198,0.95)" stroke="#5a3a12" stroke-width="1.8"/>`);
    p.push(`<line x1="${cx}" y1="${convY-80}" x2="${cx}" y2="${convY-28}"
      stroke="#5a3a12" stroke-width="0.8" opacity="0.5"/>`);
    p.push(`<line x1="${cx-5}" y1="${convY-80}" x2="${cx-5}" y2="${convY-28}"
      stroke="#a08060" stroke-width="0.5" opacity="0.3"/>`);
    p.push(`<line x1="${cx+5}" y1="${convY-80}" x2="${cx+5}" y2="${convY-28}"
      stroke="#a08060" stroke-width="0.5" opacity="0.3"/>`);
    p.push(`<circle cx="${cx}" cy="${convY-82}" r="10"
      fill="#ece0c6" stroke="#5a3a12" stroke-width="1.5"/>`);
    [{dx:7,dy:0},{dx:-7,dy:0},{dx:0,dy:7},{dx:0,dy:-7}].forEach(function(pt) {
      p.push(`<circle cx="${cx+pt.dx}" cy="${convY-82+pt.dy}" r="2" fill="#8a7250"/>`);
    });
    p.push(`<rect x="${cx-8}" y="${convY-122}" width="16" height="40" rx="2"
      transform="rotate(30, ${cx}, ${convY-82})"
      fill="rgba(236,224,198,0.95)" stroke="#5a3a12" stroke-width="1.6"/>`);
    p.push(`<line x1="${cx}" y1="${convY-122}" x2="${cx}" y2="${convY-82}"
      transform="rotate(30, ${cx}, ${convY-82})"
      stroke="#5a3a12" stroke-width="0.8" opacity="0.5"/>`);
    p.push(`<circle cx="${wristX}" cy="${wristY}" r="8"
      fill="#ece0c6" stroke="#5a3a12" stroke-width="1.2"/>`);
    p.push(`<rect x="${wristX-12}" y="${wristY-5}" width="24" height="10" rx="1"
      fill="#dcc9a4" stroke="#5a3a12" stroke-width="1.4"/>`);
    p.push(`<rect x="${wristX-12}" y="${wristY+5}" width="5" height="14" rx="1"
      fill="#ece0c6" stroke="#5a3a12" stroke-width="1.2"/>`);
    p.push(`<rect x="${wristX+7}" y="${wristY+5}" width="5" height="14" rx="1"
      fill="#ece0c6" stroke="#5a3a12" stroke-width="1.2"/>`);
    p.push(`<rect x="${wristX-9}" y="${wristY+5}" width="18" height="12" rx="1"
      fill="rgba(210,185,140,0.6)" stroke="#5a3a12" stroke-width="0.8"/>`);
    p.push(`</g>`);
    return p.join('\n');
  }

  function drawPackMultiFormer(cx, convY) {
    var mW = 54, mH = 62;
    var mX = cx - mW/2;
    var mY = convY - mH;
    var reelCX = cx + 44;
    var reelCY = convY - 52;
    var p = [];

    p.push(`<ellipse cx="${cx}" cy="${convY+4}" rx="30" ry="4"
      fill="rgba(60,40,20,0.12)"/>`);
    p.push(`<rect x="${mX}" y="${mY}" width="${mW}" height="${mH}" rx="3"
      fill="rgba(241,232,212,0.92)" stroke="#5a3a12" stroke-width="1.8"/>`);
    p.push(`<rect x="${mX+2}" y="${mY+2}" width="${mW-4}" height="${mH-4}" rx="2"
      fill="url(#pkg-hatch)"/>`);
    var bStarts = [[cx-14, convY-30],[cx+2, convY-30],[cx-14, convY-12],[cx+2, convY-12]];
    bStarts.forEach(function(b) {
      p.push(`<rect x="${b[0]}" y="${b[1]}" width="10" height="18" rx="1"
        fill="rgba(190,150,60,0.15)" stroke="#5a3a12" stroke-width="0.8"/>`);
    });
    p.push(`<g class="reel-spin" style="transform-origin:${reelCX}px ${reelCY}px">`);
    p.push(`<circle cx="${reelCX}" cy="${reelCY}" r="14"
      stroke="#5a3a12" stroke-width="1.2" fill="rgba(235,225,200,0.8)"/>`);
    [7,11].forEach(function(r) {
      p.push(`<circle cx="${reelCX}" cy="${reelCY}" r="${r}"
        stroke="#8a7250" stroke-width="0.8" fill="none"
        stroke-dasharray="3 2" opacity="0.5"/>`);
    });
    p.push(`<circle cx="${reelCX}" cy="${reelCY}" r="4"
      fill="#dcc9a4" stroke="#5a3a12" stroke-width="1"/>`);
    p.push(`</g>`);
    return p.join('\n');
  }

  function drawKegStation(cx, convY) {
    var mW = 54, mH = 65;
    var mX = cx - mW/2;
    var mY = convY - mH;
    var cradleY = convY - 18;
    var p = [];

    p.push(`<ellipse cx="${cx}" cy="${convY+4}" rx="30" ry="4"
      fill="rgba(60,40,20,0.12)"/>`);
    p.push(`<rect x="${mX}" y="${mY}" width="${mW}" height="${mH}" rx="3"
      fill="rgba(241,232,212,0.92)" stroke="#5a3a12" stroke-width="1.8"/>`);
    p.push(`<rect x="${mX+2}" y="${mY+2}" width="${mW-4}" height="${mH-4}" rx="2"
      fill="url(#pkg-hatch)"/>`);
    p.push(`<line x1="${cx-20}" y1="${cradleY-16}" x2="${cx}" y2="${cradleY}"
      stroke="#8a7250" stroke-width="1.2"/>`);
    p.push(`<line x1="${cx+20}" y1="${cradleY-16}" x2="${cx}" y2="${cradleY}"
      stroke="#8a7250" stroke-width="1.2"/>`);
    p.push(`<rect x="${cx-18}" y="${cradleY - 24}" width="36" height="22" rx="4"
      fill="rgba(140,143,148,0.25)" stroke="#5a3a12" stroke-width="1.2"/>`);
    p.push(`<line x1="${cx-15}" y1="${cradleY-18}" x2="${cx+15}" y2="${cradleY-18}"
      stroke="#5a3a12" stroke-width="0.7" opacity="0.4"/>`);
    p.push(`<line x1="${cx-15}" y1="${cradleY-10}" x2="${cx+15}" y2="${cradleY-10}"
      stroke="#5a3a12" stroke-width="0.7" opacity="0.4"/>`);
    p.push(`<rect x="${cx-3}" y="${cradleY-28}" width="6" height="6" rx="1"
      fill="#8a7250" stroke="#5a3a12" stroke-width="0.8"/>`);
    p.push(`<circle cx="${cx}" cy="${cradleY-30}" r="3" fill="#8a7250" stroke="#5a3a12" stroke-width="0.7"/>`);
    p.push(`<line x1="${mX+mW}" y1="${mY+10}" x2="${mX+mW+16}" y2="${mY+10}"
      stroke="#5a3a12" stroke-width="1.2" opacity="0.6"/>`);
    p.push(`<line x1="${mX+mW+16}" y1="${mY+10}" x2="${mX+mW+16}" y2="${mY+24}"
      stroke="#5a3a12" stroke-width="1.2" opacity="0.6"/>`);
    return p.join('\n');
  }

  function drawMachine(st, idx, run, convY, viewH, viewW) {
    var cx = st.x;
    var labelY = viewH - 18;

    var stLabel = `
      <text x="${cx}" y="${labelY}" text-anchor="middle"
            font-family="'DM Sans',sans-serif" font-size="11" font-weight="700"
            fill="#5a3a12" letter-spacing="0.05em">${esc(st.label)}</text>
    `;

    var tagParts = '';
    var tagAnchorY = convY - 8;

    if (st.tagType === 'fill') {
      var tags = buildFillTags(run);
      /* Fill-spine tags span the full SVG width (P&ID style), on a dedicated
         upper row so they never collide with the downstream single-station tags
         (CAPS/ÉTQ/CTN/PAL) that share the standard tag-row Y band.
         All 5 tags share the same tagY; leader lines reach from each tag's
         anchor dot at the conveyor (tagAnchorY) up to that shared row. */
      var fillTagH = 38;
      /* Pin the fill-tag row to a fixed Y that clears the tallest machine head
         (filler carousel + supply pipe reaches to ~convY-130).
         Use convY - 180 to guarantee a clean gap above all machine bodies. */
      var fillTagY = convY - 180;
      var totalTagPx = 0;
      tags.forEach(function(t) { totalTagPx += (t.emphasis ? 78 : 68); });
      var margin = 8;
      var available = viewW - margin * 2;
      var tagGap = (available - totalTagPx) / (tags.length + 1);
      var curX = margin + tagGap;
      tags.forEach(function(t, ti) {
        var tagW2 = t.emphasis ? 78 : 68;
        var tagCx = curX + tagW2 / 2;
        tagParts += drawInstrumentTag(tagCx, tagAnchorY, t.glyph, t.value, t.label, t.emphasis, fillTagY);
        curX += tagW2 + tagGap;
      });
    } else if (st.tagType === 'cap') {
      var caps = run.losses ? run.losses.loss_crown_cork_units : 0;
      tagParts = drawInstrumentTag(cx + 14, tagAnchorY, 'CAPS', caps + ' u pertes', 'Capsules', false);
    } else if (st.tagType === 'label') {
      var lbls = run.losses ? run.losses.loss_label_btl_units : 0;
      tagParts = drawInstrumentTag(cx, tagAnchorY, 'ÉTQ', lbls + ' u pertes', 'Étiquettes', false);
    } else if (st.tagType === 'carton') {
      var ctnBom = run.bom || [];
      var ctnRow = null;
      for (var ci = 0; ci < ctnBom.length; ci++) {
        if (ctnBom[ci].mi_name && ctnBom[ci].mi_name.toLowerCase().indexOf('carton') !== -1) {
          ctnRow = ctnBom[ci]; break;
        }
      }
      var ctnCount = ctnRow ? ctnRow.qty : '—';
      tagParts = drawInstrumentTag(cx, tagAnchorY, 'CTN', ctnCount + ' u', 'Cartons', false);
    } else if (st.tagType === 'pal') {
      tagParts = drawInstrumentTag(cx, tagAnchorY, 'PAL', '—', 'Palettes', false);
    } else if (st.tagType === 'seam') {
      tagParts = drawInstrumentTag(cx, tagAnchorY, 'SERT', '—', 'Sertissage', false);
    } else if (st.tagType === 'pack') {
      tagParts = drawInstrumentTag(cx, tagAnchorY, '4PK', '—', '4-pack', false);
    } else if (st.tagType === 'keg') {
      var kegUnits = run.prod_total_units || '—';
      tagParts = drawInstrumentTag(cx, tagAnchorY, 'FÛT', kegUnits + ' u', 'Fûts', false);
    } else if (st.tagType === 'cage') {
      tagParts = drawInstrumentTag(cx, tagAnchorY, 'CAGE', '—', 'Mise en cage', false);
    } else {
      tagParts = drawInstrumentTag(cx, tagAnchorY, esc(st.tag_label), '—', esc(st.label), false);
    }

    var machineSvg = '';
    if (st.tagType === 'fill') {
      machineSvg = drawFillerCarousel(cx, convY);
    } else if (st.tagType === 'cap' || st.tagType === 'seam') {
      machineSvg = drawCapper(cx, convY);
    } else if (st.tagType === 'label') {
      machineSvg = drawLabeller(cx, convY);
    } else if (st.tagType === 'carton') {
      machineSvg = drawCasePacker(cx, convY);
    } else if (st.tagType === 'pal') {
      machineSvg = drawRoboticPalletiser(cx, convY);
    } else if (st.tagType === 'pack') {
      machineSvg = drawPackMultiFormer(cx, convY);
    } else if (st.tagType === 'keg' || st.tagType === 'cage') {
      machineSvg = drawKegStation(cx, convY);
    } else {
      var mW = st.w, mH = st.h;
      var mX = cx - mW/2, mY2 = convY - mH;
      machineSvg = `
        <rect x="${mX}" y="${mY2}" width="${mW}" height="${mH}" rx="4"
              fill="#f1e8d4" stroke="#5a3a12" stroke-width="1.5"/>
        <rect x="${mX+1}" y="${mY2+1}" width="${mW-2}" height="${mH-2}" rx="3"
              fill="url(#pkg-hatch)"/>
      `;
    }

    return machineSvg + tagParts + stLabel;
  }

  function renderLineSchematic(run) {
    var run_type = run.run_type;
    // cage detection: bot + is_cage flag, or explicit 'cage' run_type
    if (run.is_cage) run_type = 'cage';
    var is_4pack = run.is_4pack;

    var stationDefs = [];
    var viewW = 1100;
    var viewH = 320;

    if (run_type === 'bot') {
      stationDefs = [
        { x: 110, label: "Soutirage",    tag_label: "REMPL.", tagType: "fill",    w: 80, h: 85 },
        { x: 310, label: "Bouchage",     tag_label: "CAPS.",  tagType: "cap",     w: 60, h: 75 },
        { x: 490, label: "Étiquetage",   tag_label: "ÉTQ.",   tagType: "label",   w: 60, h: 75 },
      ];
      if (is_4pack) {
        stationDefs.push({ x: 650, label: "Mise en pack", tag_label: "4-PACK", tagType: "pack", w: 60, h: 75 });
        stationDefs.push({ x: 820, label: "Mise en carton", tag_label: "CTN.", tagType: "carton", w: 60, h: 75 });
        stationDefs.push({ x: 1000, label: "Palettisation", tag_label: "PAL.", tagType: "pal", w: 60, h: 75 });
        viewW = 1100;
      } else {
        stationDefs.push({ x: 670, label: "Mise en carton", tag_label: "CTN.", tagType: "carton", w: 60, h: 75 });
        stationDefs.push({ x: 860, label: "Palettisation",  tag_label: "PAL.", tagType: "pal",    w: 60, h: 75 });
        viewW = 980;
      }
    } else if (run_type === 'can') {
      stationDefs = [
        { x: 110, label: "Soutirage",     tag_label: "REMPL.", tagType: "fill",   w: 80, h: 85 },
        { x: 310, label: "Sertissage",    tag_label: "SERT.",  tagType: "seam",   w: 60, h: 75 },
        { x: 500, label: "Mise en carton",tag_label: "CTN.",   tagType: "carton", w: 60, h: 75 },
        { x: 690, label: "Palettisation", tag_label: "PAL.",   tagType: "pal",    w: 60, h: 75 },
      ];
      viewW = 820;
    } else if (run_type === 'keg') {
      stationDefs = [
        { x: 120, label: "Soutirage",         tag_label: "REMPL.", tagType: "fill", w: 80, h: 85 },
        { x: 360, label: "Fût / Collerette",  tag_label: "FÛT.",   tagType: "keg",  w: 70, h: 75 },
      ];
      viewW = 520;
    } else if (run_type === 'cuv') {
      stationDefs = [
        { x: 160, label: "Soutirage cuve", tag_label: "REMPL.", tagType: "fill", w: 80, h: 85 },
      ];
      viewW = 380;
    } else if (run_type === 'cage') {
      stationDefs = [
        { x: 110, label: "Soutirage",      tag_label: "REMPL.", tagType: "fill",   w: 80, h: 85 },
        { x: 310, label: "Bouchage",       tag_label: "CAPS.",  tagType: "cap",    w: 60, h: 75 },
        { x: 490, label: "Étiquetage",     tag_label: "ÉTQ.",   tagType: "label",  w: 60, h: 75 },
        { x: 680, label: "Mise en carton", tag_label: "CTN.",   tagType: "carton", w: 60, h: 75 },
        { x: 860, label: "Mise en cage",   tag_label: "CAGE",   tagType: "cage",   w: 60, h: 75 },
        { x: 1000,label: "Palettisation",  tag_label: "PAL.",   tagType: "pal",    w: 60, h: 75 },
      ];
      viewW = 1100;
    }

    if (run_type === 'bot' || run_type === 'cage') {
      viewH = 500;
    } else if (run_type === 'can') {
      viewH = 480;
    } else if (run_type === 'keg') {
      viewH = 400;
    } else if (run_type === 'cuv') {
      viewH = 340;
    }

    var CONV_Y = (run_type === 'bot' || run_type === 'can' || run_type === 'cage') ? 340
               : (run_type === 'keg') ? 270 : 220;
    var CONV_Y1 = CONV_Y - 7;
    var CONV_Y2 = CONV_Y + 7;

    var defs = `
      <defs>
        <pattern id="pkg-hatch" patternUnits="userSpaceOnUse" width="6" height="6" patternTransform="rotate(45)">
          <line x1="0" y1="0" x2="0" y2="6" stroke="#8a7250" stroke-width="0.4" opacity="0.22"/>
        </pattern>
        <pattern id="pkg-grid-sm" patternUnits="userSpaceOnUse" width="8" height="8">
          <path d="M 8 0 L 0 0 0 8" fill="none" stroke="#a08060" stroke-width="0.15" opacity="0.07"/>
        </pattern>
        <pattern id="pkg-grid" patternUnits="userSpaceOnUse" width="40" height="40">
          <path d="M 40 0 L 0 0 0 40" fill="none" stroke="#a08060" stroke-width="0.4" opacity="0.10"/>
        </pattern>
      </defs>
    `;

    var parts = [`<svg viewBox="0 0 ${viewW} ${viewH}" xmlns="http://www.w3.org/2000/svg"
      role="img" aria-label="Schéma de ligne d'emballage — ${esc(run.format_human || run.run_type)}">`];
    parts.push(defs);
    parts.push(`<rect width="${viewW}" height="${viewH}" fill="url(#pkg-grid-sm)"/>`);
    parts.push(`<rect width="${viewW}" height="${viewH}" fill="url(#pkg-grid)"/>`);

    // Roller conveyor
    parts.push(`<g aria-label="Convoyeur">`);
    parts.push(`<line x1="20" y1="${CONV_Y1}" x2="${viewW-20}" y2="${CONV_Y1}"
      stroke="#5a3a12" stroke-width="2.5"/>`);
    parts.push(`<line x1="20" y1="${CONV_Y2}" x2="${viewW-20}" y2="${CONV_Y2}"
      stroke="#5a3a12" stroke-width="2.5"/>`);
    var rollerMidY = Math.round((CONV_Y1 + CONV_Y2) / 2);
    for (var sx = 30; sx < viewW - 20; sx += 22) {
      parts.push(`<circle cx="${sx}" cy="${rollerMidY}" r="5"
        fill="#ece0c6" stroke="#5a3a12" stroke-width="0.8"/>`);
      parts.push(`<circle cx="${sx}" cy="${rollerMidY}" r="1"
        fill="#8a7250"/>`);
    }
    for (var lx = 60; lx < viewW - 20; lx += 120) {
      parts.push(`<rect x="${lx-4}" y="${CONV_Y2}" width="8" height="22" rx="1"
        fill="#dcc9a4" stroke="#8a7250" stroke-width="1.0"/>`);
      parts.push(`<rect x="${lx-7}" y="${CONV_Y2+20}" width="14" height="3" rx="1"
        fill="#c8b48a" stroke="#8a7250" stroke-width="0.6"/>`);
    }
    parts.push(`<line x1="10" y1="${CONV_Y2 + 18}" x2="${viewW-10}" y2="${CONV_Y2 + 18}"
      stroke="#8a7250" stroke-width="1.2" opacity="0.5" stroke-dasharray="6 4"/>`);
    parts.push(`<line class="conveyor-flow"
      x1="20" y1="${CONV_Y}" x2="${viewW-20}" y2="${CONV_Y}"
      stroke="#c8b48a" stroke-width="2" stroke-dasharray="8 5" opacity="0.5"/>`);
    parts.push(`</g>`);

    // Travelling silhouettes between stations
    for (var si = 0; si < stationDefs.length - 1; si++) {
      var s1 = stationDefs[si];
      var s2 = stationDefs[si + 1];
      var gapStart = s1.x + s1.w / 2 + 14;
      var gapEnd   = s2.x - s2.w / 2 - 14;
      var midX = (gapStart + gapEnd) / 2;
      if (gapEnd - gapStart < 40) continue;

      parts.push(`<g class="conveyor-bottles station-${si}" aria-hidden="true">`);
      for (var bi = -1; bi <= 1; bi++) {
        var bx2 = midX + bi * 16;
        if (run_type === 'can') {
          parts.push(drawCan(bx2, CONV_Y - 2));
        } else if (run_type === 'keg') {
          parts.push(drawKeg(bx2, CONV_Y - 2));
        } else {
          parts.push(drawBottle(bx2, CONV_Y - 2));
        }
      }
      parts.push(`</g>`);
    }

    // Station machines
    stationDefs.forEach(function(st, i) {
      parts.push(`<g class="station-${i}">`);
      parts.push(drawMachine(st, i, run, CONV_Y, viewH, viewW));
      parts.push(`</g>`);
    });

    parts.push('</svg>');
    return parts.join('\n');
  }

  /* ── Metrics strip ─────────────────────────────────────── */
  function renderMetricsStrip(run) {
    var vendStr = run.run_type === 'cuv'
      ? '— (service)'
      : (run.vendable_hl != null ? fmtNum(run.vendable_hl, 2) + ' hL' : '—');

    var items = [
      { label: 'Unités produites', val: run.prod_total_units > 0 ? run.prod_total_units.toLocaleString('fr-CH') + ' u' : '—', hero: true },
      { label: 'Vol. vendable',    val: vendStr, hero: false },
      { label: 'Base taxe-bière',  val: run.run_type === 'cuv' ? '—' : (run.beer_tax_base_hl != null ? fmtNum(run.beer_tax_base_hl, 2) + ' hL' : '—'), hero: false },
      { label: 'Pertes (hL)',      val: run.loss_kpi_hl != null ? fmtNum(run.loss_kpi_hl, 2) + ' hL' : '—', hero: false },
      { label: 'O₂ moy.',         val: (run.qa && run.qa.avg_o2 != null) ? parseFloat(run.qa.avg_o2).toFixed(1) + ' ppb' : '—', hero: false },
      { label: 'CO₂ moy.',        val: (run.qa && run.qa.avg_co2 != null) ? fmtNum(parseFloat(run.qa.avg_co2), 1) + ' g/L' : '—', hero: false },
    ];

    return `<div class="metrics-strip">` +
      items.map(function(item) {
        return `<div class="metrics-cell">
          <div class="metrics-cell__label">${esc(item.label)}</div>
          <div class="metrics-cell__val${item.hero ? ' metrics-cell__val--hero' : ''}">${esc(item.val)}</div>
        </div>`;
      }).join('') +
    `</div>`;
  }

  /* ── BOM docket ────────────────────────────────────────── */
  function renderBomDocket(bom) {
    if (!bom || bom.length === 0) {
      return `<div class="bom-empty">Nomenclature en cours de calcul…</div>`;
    }
    return bom.map(function(row) {
      var isLiquid = row.mi_name && row.mi_name.toLowerCase().indexOf('liquide') !== -1;
      var qtyStr = (row.qty !== null && row.qty !== undefined)
        ? (typeof row.qty === 'number' ? row.qty.toLocaleString('fr-CH') : parseFloat(row.qty).toLocaleString('fr-CH'))
        : '—';
      return `<div class="bom-row${isLiquid ? ' bom-row--liquid' : ''}">
        <span class="bom-row__name">${esc(row.mi_name || '—')}</span>
        <span class="bom-row__qty">${qtyStr} ${esc(row.unit || '')}</span>
      </div>`;
    }).join('');
  }

  /* ── Losses panel ──────────────────────────────────────── */
  function renderLosses(run) {
    var loss = run.losses || {};
    var kpiHl = run.loss_kpi_hl || 0;
    var rt = run.run_type;

    var rows = [];
    if (rt === 'bot' || rt === 'cage') {
      rows = [
        { label: 'Capsules perdues',   val: loss.loss_crown_cork_units || 0, unit: 'u' },
        { label: 'Étiquettes perdues', val: loss.loss_label_btl_units  || 0, unit: 'u' },
        { label: 'Non-capsulées',      val: loss.loss_uncapped_units    || 0, unit: 'u' },
        { label: 'Demi-remplies',      val: loss.loss_half_filled_units || 0, unit: 'u' },
        { label: 'Invendables',        val: loss.unsaleable_units       || 0, unit: 'u' },
      ];
    } else if (rt === 'can' || rt === 'can33') {
      rows = [
        { label: 'Fonds perdus',  val: loss.loss_can_lid_units    || 0, unit: 'u' },
        { label: 'Demi-remplies', val: loss.loss_half_filled_units || 0, unit: 'u' },
        { label: 'Invendables',   val: loss.unsaleable_units       || 0, unit: 'u' },
      ];
    } else if (rt === 'keg') {
      rows = [
        { label: 'Liquide perdu', val: loss.loss_keg_liquid_l   || 0, unit: 'L' },
        { label: 'Fûts sauvés',   val: loss.loss_keg_save_units || 0, unit: 'u' },
      ];
    }

    var headHtml = `<div class="loss-headline">${esc(fmtNum(kpiHl, 2))}<span class="loss-headline__unit"> hL pertes</span></div>`;

    var rowsHtml = rows.map(function(r) {
      var nonzero = r.val > 0;
      return `<div class="loss-row">
        <span class="loss-row__label">${esc(r.label)}</span>
        <span class="loss-row__val${nonzero ? ' loss-val--nonzero' : ''}">${typeof r.val === 'number' ? r.val.toLocaleString('fr-CH') : r.val} ${esc(r.unit)}</span>
      </div>`;
    }).join('');

    return headHtml + (rowsHtml || '<span style="color:var(--ink-label);font-size:13px">—</span>');
  }

  /* ── QA panel ──────────────────────────────────────────── */
  function renderQA(run) {
    var qa = run.qa || {};
    var cipMachines = qa.cip_machines || '';
    var cipTank     = qa.cip_tank     || '';
    var cipMClass   = cipMachines === 'Fait' ? 'cip-pill--ok' : 'cip-pill--pending';
    var cipTClass   = cipTank     === 'Fait' ? 'cip-pill--ok' : 'cip-pill--pending';

    var co2Str = qa.avg_co2 != null ? fmtNum(parseFloat(qa.avg_co2), 1) : '—';
    var o2Str  = qa.avg_o2  != null ? parseFloat(qa.avg_o2).toFixed(1) : '—';
    var nRead  = qa.n_readings != null ? qa.n_readings : '?';
    var qaAn   = qa.qa_analyses_units != null ? qa.qa_analyses_units : '—';
    var qaLib  = qa.qa_library_units  != null ? qa.qa_library_units  : '—';

    return `<div class="qa-reading-row">
        <span class="qa-glyph">CO₂</span>
        <span class="qa-val">${esc(co2Str)}</span>
        <span class="qa-unit">g/L moy. (${esc(String(nRead))} mesures)</span>
      </div>
      <div class="qa-reading-row">
        <span class="qa-glyph">O₂</span>
        <span class="qa-val">${esc(o2Str)}</span>
        <span class="qa-unit">ppb moy.</span>
      </div>
      <div class="qa-field">
        <span class="qa-field__label">Prélèvements (analyses)</span>
        <span class="qa-val">${esc(String(qaAn))} u</span>
      </div>
      <div class="qa-field">
        <span class="qa-field__label">Prélèvements (bibliothèque)</span>
        <span class="qa-val">${esc(String(qaLib))} u</span>
      </div>
      <div class="qa-field">
        <span class="qa-field__label">CIP machines</span>
        <span class="cip-pill ${cipMClass}">${esc(cipMachines || 'Non renseigné')}</span>
      </div>
      <div class="qa-field">
        <span class="qa-field__label">CIP cuve source</span>
        <span class="cip-pill ${cipTClass}">${esc(cipTank || 'Non renseigné')}</span>
      </div>`;
  }

  /* ── Mini format icon for day view ────────────────────── */
  function miniFormatIcon(run_type, classification) {
    var fillColor = classification === 'Neb'
      ? 'rgba(86,112,32,0.18)' : 'rgba(47,85,117,0.18)';
    var stroke = '#5a3a12';

    if (run_type === 'bot' || run_type === 'cage') {
      return `<svg width="48" height="72" viewBox="0 0 48 72" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
        <defs>
          <pattern id="mhatch-bot" patternUnits="userSpaceOnUse" width="5" height="5" patternTransform="rotate(45)">
            <line x1="0" y1="0" x2="0" y2="5" stroke="#c8b48a" stroke-width="0.5" opacity="0.35"/>
          </pattern>
        </defs>
        <rect x="20" y="8" width="8" height="18" rx="2"
              fill="url(#mhatch-bot)" stroke="${stroke}" stroke-width="1.5"/>
        <polygon points="14,26 34,26 38,34 10,34"
                 fill="${fillColor}" stroke="${stroke}" stroke-width="1.2"/>
        <rect x="10" y="34" width="28" height="32" rx="3"
              fill="${fillColor}" stroke="${stroke}" stroke-width="1.5"/>
        <rect x="10" y="42" width="28" height="10" rx="0"
              fill="rgba(200,180,138,0.35)" stroke="${stroke}" stroke-width="0.5" opacity="0.6"/>
        <rect x="19" y="4" width="10" height="6" rx="2"
              fill="#dcc9a4" stroke="${stroke}" stroke-width="1.2"/>
      </svg>`;
    } else if (run_type === 'can' || run_type === 'can33') {
      return `<svg width="44" height="68" viewBox="0 0 44 68" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
        <defs>
          <pattern id="mhatch-can" patternUnits="userSpaceOnUse" width="5" height="5" patternTransform="rotate(45)">
            <line x1="0" y1="0" x2="0" y2="5" stroke="#c8b48a" stroke-width="0.5" opacity="0.35"/>
          </pattern>
        </defs>
        <rect x="8" y="14" width="28" height="46" rx="2"
              fill="${fillColor}" stroke="${stroke}" stroke-width="1.5"/>
        <rect x="8" y="14" width="28" height="46" rx="2"
              fill="url(#mhatch-can)" opacity="0.8"/>
        <rect x="9" y="10" width="26" height="6" rx="2"
              fill="#dcc9a4" stroke="${stroke}" stroke-width="1.2"/>
        <line x1="22" y1="7" x2="22" y2="12" stroke="${stroke}" stroke-width="1.2"/>
        <ellipse cx="22" cy="7" rx="3" ry="2" fill="#dcc9a4" stroke="${stroke}" stroke-width="1.0"/>
        <line x1="8" y1="26" x2="36" y2="26" stroke="${stroke}" stroke-width="0.7" opacity="0.4"/>
        <line x1="8" y1="46" x2="36" y2="46" stroke="${stroke}" stroke-width="0.7" opacity="0.4"/>
        <rect x="9" y="58" width="26" height="4" rx="2"
              fill="#dcc9a4" stroke="${stroke}" stroke-width="1.0"/>
      </svg>`;
    } else if (run_type === 'keg') {
      return `<svg width="68" height="54" viewBox="0 0 68 54" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
        <defs>
          <pattern id="mhatch-keg" patternUnits="userSpaceOnUse" width="5" height="5" patternTransform="rotate(45)">
            <line x1="0" y1="0" x2="0" y2="5" stroke="#c8b48a" stroke-width="0.5" opacity="0.35"/>
          </pattern>
        </defs>
        <rect x="6" y="10" width="56" height="36" rx="6"
              fill="${fillColor}" stroke="${stroke}" stroke-width="1.8"/>
        <rect x="6" y="10" width="56" height="36" rx="6"
              fill="url(#mhatch-keg)" opacity="0.8"/>
        <line x1="6" y1="18" x2="62" y2="18" stroke="${stroke}" stroke-width="1.2" opacity="0.5"/>
        <line x1="6" y1="38" x2="62" y2="38" stroke="${stroke}" stroke-width="1.2" opacity="0.5"/>
        <line x1="6" y1="28" x2="62" y2="28" stroke="${stroke}" stroke-width="0.8" opacity="0.3"/>
        <rect x="28" y="4" width="12" height="8" rx="2"
              fill="#dcc9a4" stroke="${stroke}" stroke-width="1.2"/>
        <rect x="31" y="1" width="6" height="4" rx="1.5"
              fill="#8a7250" stroke="${stroke}" stroke-width="0.8"/>
      </svg>`;
    } else if (run_type === 'cuv') {
      return `<svg width="60" height="70" viewBox="0 0 60 70" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
        <defs>
          <pattern id="mhatch-cuv" patternUnits="userSpaceOnUse" width="5" height="5" patternTransform="rotate(45)">
            <line x1="0" y1="0" x2="0" y2="5" stroke="#c8b48a" stroke-width="0.5" opacity="0.35"/>
          </pattern>
          <clipPath id="cuv-clip">
            <polygon points="10,12 50,12 50,52 30,62 10,52"/>
          </clipPath>
        </defs>
        <polygon points="10,12 50,12 50,52 30,62 10,52"
                 fill="${fillColor}" stroke="${stroke}" stroke-width="1.8"/>
        <polygon points="10,12 50,12 50,52 30,62 10,52"
                 fill="url(#mhatch-cuv)" opacity="0.8"/>
        <line x1="10" y1="32" x2="50" y2="32" stroke="${stroke}" stroke-width="0.9" opacity="0.4"/>
        <rect x="8" y="8" width="44" height="6" rx="2"
              fill="#dcc9a4" stroke="${stroke}" stroke-width="1.2"/>
        <rect x="14" y="60" width="4" height="8" rx="1" fill="#dcc9a4" stroke="${stroke}" stroke-width="1.0"/>
        <rect x="28" y="60" width="4" height="8" rx="1" fill="#dcc9a4" stroke="${stroke}" stroke-width="1.0"/>
        <rect x="42" y="60" width="4" height="8" rx="1" fill="#dcc9a4" stroke="${stroke}" stroke-width="1.0"/>
      </svg>`;
    }

    return `<svg width="40" height="40" viewBox="0 0 40 40" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
      <rect x="8" y="8" width="24" height="24" rx="4"
            fill="${fillColor}" stroke="#5a3a12" stroke-width="1.5"/>
    </svg>`;
  }

  /* ── Full batch HTML builder ───────────────────────────── */
  function renderBatchViewHtml(run) {
    var classLabel = run.classification === 'Neb' ? 'Nébuleuse' : 'Contract';
    var pillClass  = run.classification === 'Neb' ? 'pill--neb'  : 'pill--contract';
    var frDate = run.event_date ? formatFrenchDate(run.event_date) : '—';

    var html = `<div class="fiche-header">
      <div class="fiche-header__inner">
        <div class="fiche-header__left">
          <div class="fiche-header__overline">Schéma de ligne d'emballage</div>
          <h1 class="fiche-recipe-name">${esc(run.beer)}</h1>
          <div class="fiche-pills">
            <span class="pill ${pillClass}">${esc(classLabel)}</span>
            <span class="pill pill--format">${esc(run.format_human)}</span>
          </div>
        </div>
        <div class="fiche-header__right">
          <div class="fiche-meta-cell">
            <span class="fiche-meta-cell__label">Lot</span>
            <span class="fiche-meta-cell__val">${esc(run.lot || '—')}</span>
          </div>
          <div class="fiche-meta-cell">
            <span class="fiche-meta-cell__label">Date</span>
            <span class="fiche-meta-cell__val">${esc(frDate)}</span>
          </div>
          <div class="fiche-meta-cell">
            <span class="fiche-meta-cell__label">Cuve source</span>
            <span class="fiche-meta-cell__val">${esc(run.source_tank)}</span>
          </div>
          <div class="fiche-meta-cell">
            <span class="fiche-meta-cell__label">Opérateur</span>
            <span class="fiche-meta-cell__val">${esc(run.operator)}</span>
          </div>
          <div class="fiche-meta-cell">
            <span class="fiche-meta-cell__label">Format</span>
            <span class="fiche-meta-cell__val">${esc(run.format_human)}</span>
          </div>
          <div class="fiche-meta-cell">
            <span class="fiche-meta-cell__label">Recette</span>
            <span class="fiche-meta-cell__val">${esc(run.recipe_name)}</span>
          </div>
        </div>
      </div>
    </div>`;

    if (run.hors_process) {
      html += `<div class="flag-block">
        <span class="flag-block__icon">⚠</span>
        <div class="flag-block__text">
          <strong>Run hors process</strong>
          ${run.white_label ? ` — Marque blanche&nbsp;: <em>${esc(run.white_label)}</em>` : ''}
        </div>
      </div>`;
    }

    html += `<div style="margin-top:20px;">
      <div class="pkg-section-label">Ligne de conditionnement</div>
      <div class="pkg-schematic-wrap">
        ${renderLineSchematic(run)}
      </div>
    </div>`;

    html += renderMetricsStrip(run);

    html += `<div class="panels-grid">
      <div class="panel-card">
        <div class="panel-card__header">
          <div class="panel-card__title">Nomenclature consommée</div>
        </div>
        <div class="panel-card__body">
          ${renderBomDocket(run.bom)}
        </div>
      </div>
      <div class="panel-card">
        <div class="panel-card__header">
          <div class="panel-card__title">Pertes &amp; écarts</div>
        </div>
        <div class="panel-card__body">
          ${renderLosses(run)}
        </div>
      </div>
      <div class="panel-card">
        <div class="panel-card__header">
          <div class="panel-card__title">QA / Contrôles</div>
        </div>
        <div class="panel-card__body">
          ${renderQA(run)}
        </div>
      </div>
    </div>`;

    if (run.comments) {
      html += `<div class="comments-block">
        <div class="comments-block__label">Notes d'emballage</div>
        <p class="comments-block__text">${esc(run.comments)}</p>
      </div>`;
    }

    return html;
  }

  /* ── Public: renderBatch ───────────────────────────────── */
  function renderBatch(container, data) {
    if (!data.events || data.events.length === 0) {
      container.innerHTML = '<p style="color:var(--ink-label);padding:20px 0">Aucun résultat.</p>';
      return;
    }

    var ev = data.events[0];
    var gas = data.co2o2 && data.co2o2[String(ev.id)] ? data.co2o2[String(ev.id)] : null;
    var formatInfo = resolveFormat(ev);

    var run = {
      beer: ev.neb_beer || ev.contract_beer || ev.recipe_name || '—',
      lot: ev.batch || '—',
      sku_code: ev.sku_code,
      recipe_name: ev.recipe_name || '—',
      classification: ev.classification || 'Neb',
      format_human: formatInfo.label,
      run_type: ev.run_type || '',
      is_4pack: formatInfo.is_4pack,
      is_cage: formatInfo.is_cage,
      event_date: ev.event_date || null,
      operator: ev.email || '—',
      source_tank: ev.source_tank_type || '—',
      comments: ev.comments || null,
      hors_process: ev.hors_process_flag == 1,
      white_label: ev.white_label_name || null,
      prod_total_units: parseInt(ev.prod_total_units) || 0,
      vendable_units: ev.vendable_units !== null ? parseFloat(ev.vendable_units) : null,
      vendable_hl: ev.vendable_hl !== null ? parseFloat(ev.vendable_hl) : null,
      beer_tax_base_hl: ev.beer_tax_base_hl !== null ? parseFloat(ev.beer_tax_base_hl) : null,
      loss_kpi_hl: ev.loss_kpi_hl !== null ? parseFloat(ev.loss_kpi_hl) : null,
      bom: ev.bom || [],
      losses: {
        loss_crown_cork_units:   parseInt(ev.loss_crown_cork_units)   || 0,
        loss_label_btl_units:    parseInt(ev.loss_label_btl_units)    || 0,
        loss_uncapped_units:     parseInt(ev.loss_uncapped_units)      || 0,
        loss_half_filled_units:  parseInt(ev.loss_half_filled_units)  || 0,
        unsaleable_units:        parseInt(ev.unsaleable_units)         || 0,
        loss_can_lid_units:      parseInt(ev.loss_can_lid_units)       || 0,
        loss_keg_liquid_l:       parseFloat(ev.loss_keg_liquid_l)      || 0,
        loss_keg_save_units:     parseInt(ev.loss_keg_save_units)      || 0,
        loss_4pack_btl_units:    parseInt(ev.loss_4pack_btl_units)     || 0,
        loss_4pack_can_units:    parseInt(ev.loss_4pack_can_units)     || 0,
        loss_container_btl_units: parseInt(ev.loss_container_btl_units) || 0,
        loss_container_can_units: parseInt(ev.loss_container_can_units) || 0,
        loss_liquid_other_units: parseInt(ev.loss_liquid_other_units)  || 0,
      },
      qa: {
        qa_analyses_units: ev.qa_analyses_units,
        qa_library_units:  ev.qa_library_units,
        n_readings: gas ? gas.n_readings : null,
        avg_co2:    gas ? gas.avg_co2    : null,
        avg_o2:     gas ? gas.avg_o2     : null,
        cip_machines: ev.cip_machines_done == 1 ? 'Fait' : (ev.cip_machines_done === null ? null : 'Non fait'),
        cip_tank:     ev.cip_tank_done    == 1 ? 'Fait' : (ev.cip_tank_done    === null ? null : 'Non fait'),
      },
    };

    container.innerHTML = renderBatchViewHtml(run);
  }

  /* ── Public: renderDay ─────────────────────────────────── */
  function renderDay(container, data) {
    if (!data.events || data.events.length === 0) {
      container.innerHTML = '<p style="color:var(--ink-label);padding:20px 0">Aucun packaging pour cette date.</p>';
      return;
    }

    var dateStr = data.events[0].event_date
      ? formatFrenchDate(data.events[0].event_date) : '—';

    var html = `<div class="day-header">Vue journalière <span>${esc(dateStr)}</span></div>`
      + `<div class="day-cards">`;

    for (var i = 0; i < data.events.length; i++) {
      var ev = data.events[i];
      var formatInfo = resolveFormat(ev);
      var cls = ev.classification || 'Neb';
      var classLabel = cls === 'Contract' ? 'Contract' : 'Nébuleuse';
      var pillCls = cls === 'Contract' ? 'pill--contract' : 'pill--neb';
      var beerName = ev.neb_beer || ev.contract_beer || ev.recipe_name || '—';
      var units = parseInt(ev.prod_total_units) || 0;
      var vendHl = ev.vendable_hl !== null ? parseFloat(ev.vendable_hl) : null;
      var lossHl = ev.loss_kpi_hl !== null ? parseFloat(ev.loss_kpi_hl) : null;
      var unitsStr = units > 0 ? units.toLocaleString('fr-CH') + ' u' : '—';
      var vendStr = formatInfo.label === 'Cuve de service' ? '— (service)'
                 : (vendHl !== null ? vendHl.toFixed(2) + ' hL' : '—');

      html += `<div class="day-card" tabindex="0" role="button"`
        + ` data-sku-id="${esc(String(ev.sku_id_fk == null ? '' : ev.sku_id_fk))}"`
        + ` data-batch="${esc(ev.batch == null ? '' : String(ev.batch))}"`
        + ` aria-label="Voir le run ${esc(beerName)} — ${esc(formatInfo.label)}">`
        + `<div class="day-card__icon">${miniFormatIcon(ev.run_type, cls)}</div>`
        + `<div class="day-card__recipe">${esc(beerName)}</div>`
        + `<div class="day-card__format">${esc(formatInfo.label)}</div>`
        + `<div class="day-card__pill"><span class="pill ${pillCls}">${esc(classLabel)}</span></div>`
        + `<div class="day-card__readings">`
        + `<div class="day-reading"><div class="day-reading__label">Unités</div>`
        + `<div class="day-reading__val day-reading__val--hero">${esc(unitsStr)}</div></div>`
        + `<div class="day-reading"><div class="day-reading__label">Vol. vendable</div>`
        + `<div class="day-reading__val day-reading__val--hero">${esc(vendStr)}</div></div>`
        + `<div class="day-reading"><div class="day-reading__label">Pertes hL</div>`
        + `<div class="day-reading__val">${lossHl !== null ? lossHl.toFixed(2) + ' hL' : '—'}</div></div>`
        + `<div class="day-reading"><div class="day-reading__label">Format</div>`
        + `<div class="day-reading__val">${esc(formatInfo.label.split(' ')[0] || '—')}</div></div>`
        + `</div>`
        + `</div>`;
    }

    html += `</div>`;
    container.innerHTML = html;
  }

  /* ── Export ────────────────────────────────────────────── */
  window.PackagingConsulter = {
    renderBatch: renderBatch,
    renderDay:   renderDay,
  };

}());
