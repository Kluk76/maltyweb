/**
 * sw.js — MaltyTask service worker (v1: passthrough, no offline caching)
 *
 * Earns PWA "installable" status without introducing stale-cache bugs.
 * Offline support is not needed — operator workflow requires live server state.
 *
 * Scope: / (root) — controls all pages under maltytask domain.
 * Registration: triage.php (for now); move to layout partial to cover all pages.
 */

const SW_VERSION = 'mt-sw-v1';

// ── Install ───────────────────────────────────────────────────────────────────
self.addEventListener('install', (event) => {
  // Skip the waiting phase immediately so new SW activates without a page reload
  self.skipWaiting();
});

// ── Activate ──────────────────────────────────────────────────────────────────
self.addEventListener('activate', (event) => {
  // Take control of all clients immediately (no page reload required)
  event.waitUntil(self.clients.claim());
});

// ── Fetch ─────────────────────────────────────────────────────────────────────
// Pure passthrough — every request goes to the network.
// We intentionally skip caching: triage state must always be fresh.
self.addEventListener('fetch', (event) => {
  // Let the browser handle it normally
  event.respondWith(fetch(event.request));
});
