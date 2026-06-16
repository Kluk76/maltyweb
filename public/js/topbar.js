/**
 * topbar.js — vanilla JS for the horizontal topbar (category consolidation, 2026-06)
 * Handles: mobile drawer, N category dropdowns (one-open-at-a-time), user chip,
 *          Esc/click-outside, drawer category sections (collapsible).
 * ≤ 150 LOC  |  no external deps  |  ES2017
 */
(function () {
  "use strict";

  /* ── helpers ── */
  function $(sel, ctx) { return (ctx || document).querySelector(sel); }
  function $$(sel, ctx) { return Array.from((ctx || document).querySelectorAll(sel)); }
  function on(el, ev, fn) { if (el) el.addEventListener(ev, fn); }

  /* ── element refs ── */
  var burger      = $("#tb-burger");
  var drawer      = $("#tb-drawer");
  var backdrop    = $("#tb-backdrop");
  var drawerClose = $("#tb-drawer-close");

  var userBtn   = $("#tb-user-btn");
  var userPanel = $("#tb-user-panel");
  var userWrap  = $("#tb-user-wrap");

  /* ── mobile drawer ── */
  function openDrawer() {
    drawer.setAttribute("aria-hidden", "false");
    drawer.classList.add("tb-drawer--open");
    burger.setAttribute("aria-expanded", "true");
    document.body.style.overflow = "hidden";
  }
  function closeDrawer() {
    drawer.setAttribute("aria-hidden", "true");
    drawer.classList.remove("tb-drawer--open");
    burger.setAttribute("aria-expanded", "false");
    document.body.style.overflow = "";
  }

  on(burger,      "click", openDrawer);
  on(backdrop,    "click", closeDrawer);
  on(drawerClose, "click", closeDrawer);

  /* ── generic dropdown factory (used for user chip) ── */
  function setupDropdown(btn, panel, wrap) {
    if (!btn || !panel) return;
    function open() {
      panel.hidden = false;
      btn.setAttribute("aria-expanded", "true");
      panel.classList.add("tb-panel--open");
    }
    function close() {
      panel.hidden = true;
      btn.setAttribute("aria-expanded", "false");
      panel.classList.remove("tb-panel--open");
    }
    function toggle() { panel.hidden ? open() : close(); }

    on(btn, "click", function (e) { e.stopPropagation(); toggle(); });

    on(document, "click", function (e) {
      if (wrap && !wrap.contains(e.target)) close();
    });

    on(document, "keydown", function (e) {
      if (e.key === "Escape") close();
    });

    return { open: open, close: close };
  }

  setupDropdown(userBtn, userPanel, userWrap);

  /* ── category dropdowns (one-open-at-a-time) ── */
  // Collect all category wraps from the topbar nav
  var catDropdowns = $$(".tb__cat-wrap", $("#tb-nav")).map(function (wrap) {
    var btn   = wrap.querySelector(".tb__cat-btn");
    var panel = wrap.querySelector(".tb__cat-panel");
    return { wrap: wrap, btn: btn, panel: panel };
  });

  function closeCatPanel(d) {
    if (!d.panel || !d.btn) return;
    d.panel.hidden = true;
    d.btn.setAttribute("aria-expanded", "false");
    d.panel.classList.remove("tb-panel--open");
  }

  function openCatPanel(d) {
    if (!d.panel || !d.btn) return;
    // Close all others first (one-open-at-a-time)
    catDropdowns.forEach(function (other) {
      if (other !== d) closeCatPanel(other);
    });
    // Also close user chip
    if (userPanel) { userPanel.hidden = true; userBtn.setAttribute("aria-expanded", "false"); userPanel.classList.remove("tb-panel--open"); }
    d.panel.hidden = false;
    d.btn.setAttribute("aria-expanded", "true");
    d.panel.classList.add("tb-panel--open");
  }

  catDropdowns.forEach(function (d) {
    if (!d.btn) return;
    on(d.btn, "click", function (e) {
      e.stopPropagation();
      d.panel.hidden ? openCatPanel(d) : closeCatPanel(d);
    });
  });

  /* Esc closes any open category panel */
  on(document, "keydown", function (e) {
    if (e.key === "Escape") {
      catDropdowns.forEach(closeCatPanel);
      closeDrawer();
    }
  });

  /* Click-outside closes category panels */
  on(document, "click", function (e) {
    catDropdowns.forEach(function (d) {
      if (d.wrap && !d.wrap.contains(e.target)) closeCatPanel(d);
    });
  });

  /* ── drawer category sections (collapsible) ── */
  $$(".tb-drawer__section-label--cat").forEach(function (hdr) {
    var listId = hdr.getAttribute("aria-controls");
    var list   = listId ? document.getElementById(listId) : null;
    if (!list) return;

    on(hdr, "click", function () {
      var isOpen = !list.hidden;
      list.hidden = isOpen;
      hdr.setAttribute("aria-expanded", isOpen ? "false" : "true");
    });
    on(hdr, "keydown", function (e) {
      if (e.key === "Enter" || e.key === " ") {
        e.preventDefault();
        hdr.click();
      }
    });
  });

  /* ── scroll active module into view (narrow nav) ── */
  document.addEventListener("DOMContentLoaded", function () {
    var active = $(".tb__cat-btn--active");
    if (active) {
      active.scrollIntoView({ behavior: "auto", block: "nearest", inline: "center" });
    }
    // Also handle standalone active
    var saActive = $(".tb__standalone--active");
    if (saActive) {
      saActive.scrollIntoView({ behavior: "auto", block: "nearest", inline: "center" });
    }
  });

}());
