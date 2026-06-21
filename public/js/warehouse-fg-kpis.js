'use strict';
(function () {

  var BUCKETS = window.WH_FG_BUCKETS;
  if (!BUCKETS) return;

  function esc(str) {
    return String(str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function applyBuckets(value) {
    var b    = BUCKETS[value] || BUCKETS['all'];
    var allB = BUCKETS['all'];
    var isAll = (value === 'all');

    // Update KPI tiles
    var tiles = document.querySelectorAll('[data-fg-tile]');
    for (var i = 0; i < tiles.length; i++) {
      var tile   = tiles[i];
      var metric = tile.getAttribute('data-fg-tile');
      var numEl  = tile.querySelector('[data-fg-num]');
      var totEl  = tile.querySelector('[data-fg-total]');
      if (numEl) numEl.textContent = b[metric] || '';
      if (totEl) {
        totEl.textContent = 'Total : ' + (allB[metric] || '');
        totEl.hidden = isAll;
      }
    }

    // Rebuild GL tbody
    var tbody = document.querySelector('[data-fg-gl-body]');
    if (tbody) {
      var rows = b.gl || [];
      var html = '';
      for (var j = 0; j < rows.length; j++) {
        var r = rows[j];
        html += '<tr>'
          + '<td class="wort-td"><span class="wort-mono">' + esc(r.gl) + '</span></td>'
          + '<td class="wort-td">' + esc(r.label) + '</td>'
          + '<td class="wort-td wh-td--num">' + esc(r.chf) + '</td>'
          + '</tr>';
      }
      tbody.innerHTML = html;
    }

    // Update GL total
    var glTotalEl = document.querySelector('[data-fg-gl-total]');
    if (glTotalEl) glTotalEl.textContent = b.gl_total || '';

    // Update GL portfolio ref
    var glRefEl = document.querySelector('[data-fg-gl-ref]');
    if (glRefEl) {
      glRefEl.textContent = '(portefeuille : ' + (allB.gl_total || '') + ')';
      glRefEl.hidden = isAll;
      glRefEl.style.display = isAll ? 'none' : '';
    }
  }

  document.addEventListener('DOMContentLoaded', function () {
    var skuf = document.querySelector('.skuf');
    var initVal = (skuf && skuf.getAttribute('data-skuf-value')) || 'all';
    applyBuckets(initVal);
  });

  document.addEventListener('skufilter:change', function (e) {
    if (e && e.detail && e.detail.value !== undefined) {
      applyBuckets(e.detail.value);
    }
  });

})();
