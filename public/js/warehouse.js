(function () {
  'use strict';

  var el = document.getElementById('wh-stocktake-chart');
  if (!el) return;

  var raw = el.getAttribute('data-points');
  if (!raw) return;

  var pts;
  try { pts = JSON.parse(raw); } catch (_) { return; }
  if (!Array.isArray(pts) || pts.length < 2) return;

  // Resolve --hop from the computed style (falls back to a safe default)
  var hopColor = getComputedStyle(document.body).getPropertyValue('--hop').trim() || '#9eb060';

  var W = el.offsetWidth || 640;
  var H = 140;

  var canvas = document.createElement('canvas');
  canvas.width  = W;
  canvas.height = H;
  canvas.setAttribute('aria-hidden', 'true');
  el.appendChild(canvas);

  var ctx = canvas.getContext('2d');
  if (!ctx) return;

  var pad = { t: 14, r: 18, b: 24, l: 18 };
  var innerW = W - pad.l - pad.r;
  var innerH = H - pad.t - pad.b;

  var qtys = pts.map(function (p) { return parseFloat(p.qty) || 0; });
  var minQ = Math.min.apply(null, qtys);
  var maxQ = Math.max.apply(null, qtys);
  var rangeQ = maxQ - minQ || 1;

  // Map point index → canvas coords
  var n = pts.length;
  function cx(i) { return pad.l + (i / (n - 1)) * innerW; }
  function cy(q) { return pad.t + (1 - (q - minQ) / rangeQ) * innerH; }

  // Gradient fill beneath the line
  var grad = ctx.createLinearGradient(0, pad.t, 0, pad.t + innerH);
  grad.addColorStop(0, 'rgba(158, 176, 96, 0.18)');
  grad.addColorStop(1, 'rgba(158, 176, 96, 0.00)');

  ctx.beginPath();
  ctx.moveTo(cx(0), cy(qtys[0]));
  for (var i = 1; i < n; i++) { ctx.lineTo(cx(i), cy(qtys[i])); }
  ctx.lineTo(cx(n - 1), pad.t + innerH);
  ctx.lineTo(cx(0), pad.t + innerH);
  ctx.closePath();
  ctx.fillStyle = grad;
  ctx.fill();

  // Line
  ctx.beginPath();
  ctx.moveTo(cx(0), cy(qtys[0]));
  for (var j = 1; j < n; j++) { ctx.lineTo(cx(j), cy(qtys[j])); }
  ctx.strokeStyle = hopColor;
  ctx.lineWidth   = 1.5;
  ctx.lineJoin    = 'round';
  ctx.stroke();

  // Final point dot
  ctx.beginPath();
  ctx.arc(cx(n - 1), cy(qtys[n - 1]), 3, 0, Math.PI * 2);
  ctx.fillStyle = hopColor;
  ctx.fill();
})();
