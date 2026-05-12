/**
 * topbar.js — vanilla JS for the horizontal topbar (Option-F nav, 2026-05)
 * ≤ 100 LOC  |  no external deps  |  ES2017
 */
(function () {
  "use strict";

  /* ── helpers ── */
  function $(sel, ctx) { return (ctx || document).querySelector(sel); }
  function on(el, ev, fn) { if (el) el.addEventListener(ev, fn); }

  /* ── element refs ── */
  var burger      = $("#tb-burger");
  var drawer      = $("#tb-drawer");
  var backdrop    = $("#tb-backdrop");
  var drawerClose = $("#tb-drawer-close");

  var adminBtn    = $("#tb-admin-btn");
  var adminPanel  = $("#tb-admin-panel");
  var adminWrap   = $("#tb-admin-wrap");

  var userBtn     = $("#tb-user-btn");
  var userPanel   = $("#tb-user-panel");
  var userWrap    = $("#tb-user-wrap");

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

  /* ── dropdown factory ── */
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

    /* close when clicking outside */
    on(document, "click", function (e) {
      if (wrap && !wrap.contains(e.target)) close();
    });

    /* Escape key */
    on(document, "keydown", function (e) {
      if (e.key === "Escape") close();
    });
  }

  setupDropdown(adminBtn, adminPanel, adminWrap);
  setupDropdown(userBtn,  userPanel,  userWrap);

  /* ── scroll active module into view (narrow nav) ── */
  document.addEventListener("DOMContentLoaded", function () {
    var active = $(".tb__module--active");
    if (active) {
      active.scrollIntoView({ behavior: "auto", block: "nearest", inline: "center" });
    }
  });

  /* ── Escape closes drawer too ── */
  on(document, "keydown", function (e) {
    if (e.key === "Escape") closeDrawer();
  });

}());
