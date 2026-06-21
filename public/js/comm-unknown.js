'use strict';
/**
 * comm-unknown.js — Client-side logic for the comm_unknown_domain_seen triage screen.
 *
 * Reads window.CUD_DATA (initial rows) and window.CUD_CSRF (CSRF token).
 * Polls /api/comm-unknown-domain.php?action=list every 30 s and re-renders the table.
 * Handles supplier/customer search modals and promote/dismiss actions.
 */

(function () {

  /** @type {Array} Current list of undismissed domain rows. */
  let rows = window.CUD_DATA || [];

  // ── State for the active modal ────────────────────────────────────────────
  let modalMode     = null;   // 'supplier' | 'customer'
  let modalRow      = null;   // current domain row being triaged
  let selectedId    = null;   // selected supplier/customer id
  let selectedName  = null;   // selected supplier/customer name
  let searchDebounce = null;

  // ── DOM refs (resolved on DOMContentLoaded) ───────────────────────────────
  let tableBody, countBadge, refreshNote, backdrop, modalTitle,
      searchInput, searchResults, confirmBtn;

  // ── Helpers ───────────────────────────────────────────────────────────────

  function fmtDate(s) {
    return s || '—';
  }

  function showToast(msg, type) {
    const el = document.createElement('div');
    el.className = 'cud-toast cud-toast--' + (type === 'err' ? 'err' : 'ok');
    el.textContent = msg;
    document.body.appendChild(el);
    setTimeout(function () { el.remove(); }, 2500);
  }

  // ── Table rendering ───────────────────────────────────────────────────────

  function renderTable(data) {
    if (!tableBody) return;

    if (!data.length) {
      tableBody.closest('table').style.display = 'none';
      let empty = document.getElementById('cud-empty-state');
      if (!empty) {
        empty = document.createElement('div');
        empty.id = 'cud-empty-state';
        empty.className = 'cud-empty';
        empty.textContent = 'Aucun domaine inconnu en attente. Tout est propre.';
        tableBody.closest('table').insertAdjacentElement('afterend', empty);
      }
      return;
    }

    // Ensure table visible and empty state gone
    tableBody.closest('table').style.display = '';
    const empty = document.getElementById('cud-empty-state');
    if (empty) empty.remove();

    tableBody.innerHTML = data.map(function (r) {
      const hot = r.hit_count > 5 ? ' cud-hits--hot' : '';
      const addr = r.sample_address ? escHtml(r.sample_address) : '—';
      return '<tr data-id="' + r.id + '">'
        + '<td><span class="cud-domain">' + escHtml(r.domain) + '</span></td>'
        + '<td class="cud-hits-cell"><span class="cud-hits' + hot + '">' + r.hit_count + '</span></td>'
        + '<td><span class="cud-addr">' + addr + '</span></td>'
        + '<td><span class="cud-date">' + fmtDate(r.first_seen) + '</span></td>'
        + '<td><span class="cud-date">' + fmtDate(r.last_seen) + '</span></td>'
        + '<td><div class="cud-actions">'
        + '<button class="cud-btn cud-btn--supplier" type="button" data-action="open-supplier" data-id="' + r.id + '" data-domain="' + escAttr(r.domain) + '">→ Fournisseur</button>'
        + '<button class="cud-btn cud-btn--customer" type="button" data-action="open-customer" data-id="' + r.id + '" data-domain="' + escAttr(r.domain) + '">→ Client</button>'
        + '<button class="cud-btn cud-btn--dismiss" type="button" data-action="dismiss" data-id="' + r.id + '" data-domain="' + escAttr(r.domain) + '">Ignorer</button>'
        + '</div></td>'
        + '</tr>';
    }).join('');
  }

  function escHtml(s) {
    return String(s)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function escAttr(s) {
    return String(s).replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }

  function updateCountBadge(n) {
    if (countBadge) {
      countBadge.textContent = n + ' domaine' + (n !== 1 ? 's' : '') + ' en attente';
    }
  }

  function updateRefreshNote() {
    if (!refreshNote) return;
    const now = new Date();
    const hh  = String(now.getHours()).padStart(2, '0');
    const mm  = String(now.getMinutes()).padStart(2, '0');
    const ss  = String(now.getSeconds()).padStart(2, '0');
    refreshNote.textContent = 'Dernière actualisation : ' + hh + ':' + mm + ':' + ss;
  }

  // ── Fetch list (polling) ──────────────────────────────────────────────────

  function fetchList() {
    fetch('/api/comm-unknown-domain.php?action=list', { credentials: 'same-origin' })
      .then(function (res) { return res.json(); })
      .then(function (data) {
        if (!data.ok) return;
        if (data.csrf) window.CUD_CSRF = data.csrf;
        rows = data.rows;
        renderTable(rows);
        updateCountBadge(rows.length);
        updateRefreshNote();
      })
      .catch(function () { /* silent — next poll will retry */ });
  }

  // ── Search ────────────────────────────────────────────────────────────────

  function searchSuppliers(q) {
    if (!q) { searchResults.innerHTML = ''; return; }
    fetch('/api/comm-unknown-domain.php?action=search_supplier&q=' + encodeURIComponent(q), { credentials: 'same-origin' })
      .then(function (res) { return res.json(); })
      .then(function (data) {
        if (!data.ok) return;
        renderSearchResults(data.suppliers || [], 'id', 'name');
      });
  }

  function searchCustomers(q) {
    if (!q) { searchResults.innerHTML = ''; return; }
    fetch('/api/comm-unknown-domain.php?action=search_customer&q=' + encodeURIComponent(q), { credentials: 'same-origin' })
      .then(function (res) { return res.json(); })
      .then(function (data) {
        if (!data.ok) return;
        renderSearchResults(data.customers || [], 'id', 'name');
      });
  }

  function renderSearchResults(items, idKey, nameKey) {
    if (!items.length) {
      searchResults.innerHTML = '<li style="padding:0.5rem 0.75rem;color:var(--ink-mute)">Aucun résultat</li>';
      return;
    }
    searchResults.innerHTML = items.map(function (item) {
      return '<li class="cud-search-result-item" role="option" data-id="' + item[idKey] + '" data-name="' + escAttr(item[nameKey]) + '">'
        + escHtml(item[nameKey])
        + '</li>';
    }).join('');

    // Wire click on each result item
    searchResults.querySelectorAll('.cud-search-result-item').forEach(function (li) {
      li.addEventListener('click', function () {
        // Deselect previous
        searchResults.querySelectorAll('.is-selected').forEach(function (el) { el.classList.remove('is-selected'); });
        li.classList.add('is-selected');
        selectedId   = parseInt(li.dataset.id, 10);
        selectedName = li.dataset.name;
        confirmBtn.disabled = false;
      });
    });
  }

  // ── Modal ─────────────────────────────────────────────────────────────────

  function openSupplierModal(row) {
    modalMode    = 'supplier';
    modalRow     = row;
    selectedId   = null;
    selectedName = null;
    modalTitle.textContent = 'Associer ' + row.domain + ' à un fournisseur';
    searchInput.value   = '';
    searchResults.innerHTML = '';
    confirmBtn.disabled = true;
    backdrop.hidden = false;
    searchInput.focus();
  }

  function openCustomerModal(row) {
    modalMode    = 'customer';
    modalRow     = row;
    selectedId   = null;
    selectedName = null;
    modalTitle.textContent = 'Associer ' + row.domain + ' à un client';
    searchInput.value   = '';
    searchResults.innerHTML = '';
    confirmBtn.disabled = true;
    backdrop.hidden = false;
    searchInput.focus();
  }

  function closeModal() {
    backdrop.hidden = true;
    modalMode    = null;
    modalRow     = null;
    selectedId   = null;
    selectedName = null;
    searchResults.innerHTML = '';
    searchInput.value = '';
    confirmBtn.disabled = true;
  }

  // ── POST actions ──────────────────────────────────────────────────────────

  function doAction(action, data, retried) {
    const body = new URLSearchParams();
    body.append('action', action);
    body.append('csrf', window.CUD_CSRF);
    Object.keys(data).forEach(function (k) { body.append(k, data[k]); });

    fetch('/api/comm-unknown-domain.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: body.toString(),
    })
      .then(function (res) { return res.json(); })
      .then(function (resp) {
        if (resp.csrf) window.CUD_CSRF = resp.csrf;
        if (!resp.ok) {
          if (resp.reason === 'expired' && !retried) {
            // CSRF rotated — retry once
            doAction(action, data, true);
            return;
          }
          showToast(resp.error || 'Erreur inattendue.', 'err');
          return;
        }
        closeModal();
        fetchList();
        showToast('Action enregistrée.', 'ok');
      })
      .catch(function () {
        showToast('Erreur réseau.', 'err');
      });
  }

  // ── Event delegation ──────────────────────────────────────────────────────

  function handleTableClick(e) {
    const btn = e.target.closest('[data-action]');
    if (!btn) return;

    const action   = btn.dataset.action;
    const id       = parseInt(btn.dataset.id, 10);
    const domain   = btn.dataset.domain;
    const row      = rows.find(function (r) { return r.id === id; });

    if (action === 'open-supplier') {
      if (row) openSupplierModal(row);
    } else if (action === 'open-customer') {
      if (row) openCustomerModal(row);
    } else if (action === 'dismiss') {
      if (!confirm('Ignorer le domaine « ' + domain + ' » ?')) return;
      doAction('dismiss', { domain_id: id });
    }
  }

  // ── Bootstrap ─────────────────────────────────────────────────────────────

  document.addEventListener('DOMContentLoaded', function () {
    tableBody    = document.getElementById('cud-table-body');
    countBadge   = document.getElementById('cud-count');
    refreshNote  = document.getElementById('cud-refresh-note');
    backdrop     = document.getElementById('cud-modal-backdrop');
    modalTitle   = document.getElementById('cud-modal-title');
    searchInput  = document.getElementById('cud-search-input');
    searchResults = document.getElementById('cud-search-results');
    confirmBtn   = document.getElementById('cud-modal-confirm');

    // Initial render from server-provided data (avoids blank flash)
    renderTable(rows);
    updateCountBadge(rows.length);
    updateRefreshNote();

    // Table action delegation
    if (tableBody) {
      tableBody.addEventListener('click', handleTableClick);
    }

    // Modal: search input with debounce
    if (searchInput) {
      searchInput.addEventListener('input', function () {
        clearTimeout(searchDebounce);
        const q = searchInput.value.trim();
        selectedId   = null;
        selectedName = null;
        confirmBtn.disabled = true;
        searchDebounce = setTimeout(function () {
          if (modalMode === 'supplier') {
            searchSuppliers(q);
          } else if (modalMode === 'customer') {
            searchCustomers(q);
          }
        }, 300);
      });
    }

    // Modal: confirm button
    if (confirmBtn) {
      confirmBtn.addEventListener('click', function () {
        if (!selectedId || !modalRow) return;
        if (modalMode === 'supplier') {
          doAction('promote_supplier', { domain_id: modalRow.id, supplier_id: selectedId });
        } else if (modalMode === 'customer') {
          doAction('promote_customer', { domain_id: modalRow.id, customer_id: selectedId });
        }
      });
    }

    // Modal: cancel + backdrop click
    const cancelBtn = document.getElementById('cud-modal-cancel');
    if (cancelBtn) cancelBtn.addEventListener('click', closeModal);
    if (backdrop) {
      backdrop.addEventListener('click', function (e) {
        if (e.target === backdrop) closeModal();
      });
    }

    // Escape key closes modal
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && backdrop && !backdrop.hidden) closeModal();
    });

    // Auto-refresh every 30 s
    setInterval(fetchList, 30000);
  });

}());
