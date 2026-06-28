// =====================================================================
// Energietracker — Service Worker (N1008, PWA)
//
// Ziele:
//   • Installierbarkeit (zusammen mit manifest.webmanifest)
//   • App lädt offline (App-Shell + statische Assets gecacht)
//   • zuletzt geladene Daten offline sichtbar (API-GETs network-first
//     mit Cache-Fallback)
//
// Strategie je Anfrage:
//   • nur GET wird behandelt; POST/PUT/DELETE laufen immer ans Netz
//   • Navigationen          → network-first, Fallback auf gecachte Shell
//   • /api/ (GET)           → network-first, Fallback auf Cache
//   • gleiche Origin statisch→ stale-while-revalidate
//   • fremde Origin (CDN/Fonts) → stale-while-revalidate (opaque ok)
//
// Cache-Version: bei jedem Release bumpen, damit alte Caches verworfen
// werden (siehe `activate`).
// =====================================================================

const VERSION = 'v2.1.3';
const STATIC_CACHE  = `et-static-${VERSION}`;
const RUNTIME_CACHE = `et-runtime-${VERSION}`;

// Minimal-Shell: die SPA-Wurzel (liefert index.php) als Navigations-Fallback.
const SHELL_URLS = ['.', './manifest.webmanifest'];

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(STATIC_CACHE)
      .then(cache => cache.addAll(SHELL_URLS).catch(() => {}))
      .then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys()
      .then(keys => Promise.all(
        keys.filter(k => k !== STATIC_CACHE && k !== RUNTIME_CACHE)
            .map(k => caches.delete(k))
      ))
      .then(() => self.clients.claim())
  );
});

// Erlaubt der Seite, ein wartendes Update sofort zu aktivieren.
self.addEventListener('message', (event) => {
  if (event.data === 'skipWaiting') self.skipWaiting();
});

function isStaticAsset(url) {
  return /\.(?:css|js|mjs|json|webmanifest|png|jpg|jpeg|svg|gif|ico|woff2?|ttf)$/i.test(url.pathname);
}

async function staleWhileRevalidate(request, cacheName) {
  const cache = await caches.open(cacheName);
  const cached = await cache.match(request);
  const network = fetch(request).then(resp => {
    // Nur erfolgreiche oder opaque (CDN) Antworten cachen.
    if (resp && (resp.ok || resp.type === 'opaque')) cache.put(request, resp.clone());
    return resp;
  }).catch(() => null);
  return cached || network || fetch(request);
}

async function networkFirst(request, cacheName, fallback) {
  const cache = await caches.open(cacheName);
  try {
    const resp = await fetch(request);
    if (resp && resp.ok) cache.put(request, resp.clone());
    return resp;
  } catch (e) {
    const cached = await cache.match(request);
    if (cached) return cached;
    if (fallback) {
      const fb = await caches.match(fallback);
      if (fb) return fb;
    }
    throw e;
  }
}

self.addEventListener('fetch', (event) => {
  const { request } = event;
  if (request.method !== 'GET') return; // Schreibzugriffe nie abfangen

  const url = new URL(request.url);
  const sameOrigin = url.origin === self.location.origin;

  // Navigationen: frische Shell bevorzugen, offline auf gecachte Shell zurück.
  if (request.mode === 'navigate') {
    event.respondWith(networkFirst(request, STATIC_CACHE, '.'));
    return;
  }

  // API-Lesezugriffe: network-first, damit offline der letzte Stand erscheint.
  if (sameOrigin && (url.pathname.startsWith('/api/') || url.pathname.startsWith('/api.php'))) {
    event.respondWith(networkFirst(request, RUNTIME_CACHE));
    return;
  }

  // Statische Assets (eigene Origin) und CDN/Fonts: stale-while-revalidate.
  if ((sameOrigin && isStaticAsset(url)) || !sameOrigin) {
    event.respondWith(staleWhileRevalidate(request, sameOrigin ? STATIC_CACHE : RUNTIME_CACHE));
    return;
  }
  // Alles andere: normaler Netzwerkpfad.
});
