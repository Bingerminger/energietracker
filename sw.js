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
//   • fremde Origin         → stale-while-revalidate (seit v2.2.0 gibt es
//                             keine mehr; Schriften und Chart.js liegen unter
//                             public/vendor/. Der Zweig bleibt als Auffangnetz)
//
// Cache-Version: bei jedem Release bumpen, damit alte Caches verworfen
// werden (siehe `activate`). Ein CI-Schritt vergleicht sie mit der Datei
// VERSION, damit das Bumpen nicht vergessen werden kann.
// =====================================================================

const VERSION = 'v2.14.0';
const STATIC_CACHE  = `et-static-${VERSION}`;
const RUNTIME_CACHE = `et-runtime-${VERSION}`;

// v2.2.0 — Precache der App-Shell samt Schriften und Chart.js. Vorher standen
// hier nur die SPA-Wurzel und das Manifest: Wer die Anwendung installierte und
// erst danach offline ging, hatte zwar die Shell, aber weder Stile noch
// Diagramme. Jetzt ist der erste Start ohne Netz vollständig.
const SHELL_URLS = [
  '.',
  './manifest.webmanifest',
  './public/css/tokens.css',
  './public/css/app.css',
  './public/css/components.css',
  './public/css/readings-entry.css',
  './public/vendor/fonts.css',
  './public/vendor/fonts/dm-sans.woff2',
  './public/vendor/fonts/dm-mono-400.woff2',
  './public/vendor/fonts/dm-mono-500.woff2',
  './public/vendor/chart.umd.min.js',
  './public/js/app.js',
  './public/locales/languages.json',
];

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

// v2.2.0 — `ignoreSearch`, damit der Cache-Buster (?v=<version>-<mtime>) einen
// Treffer nicht verhindert. Die Cache-Namen tragen ohnehin die Release-Version:
// Bei einem Update wird der gesamte alte Cache verworfen, es kann also nichts
// Veraltetes überleben.
const MATCH_OPTS = { ignoreSearch: true };

async function staleWhileRevalidate(request, cacheName) {
  const cache = await caches.open(cacheName);
  const cached = await cache.match(request, MATCH_OPTS);
  const network = fetch(request).then(resp => {
    // Nur erfolgreiche oder opaque Antworten cachen.
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
    const cached = await cache.match(request, MATCH_OPTS);
    if (cached) return cached;
    if (fallback) {
      const fb = await caches.match(fallback, MATCH_OPTS);
      if (fb) return fb;
    }
    throw e;
  }
}

// v2.6.0 — API-Lesezugriffe (Review FE-15, API-25).
//
// Erkennung relativ zum Scope des Workers: Im Unterverzeichnis
// (…/energietracker/api.php/api/…) griff die alte, absolute Prüfung nie.
// Gecacht wird nur eine Freigabeliste von Datenansichten — nie Backup,
// Tokens, Anmeldung, Diagnose oder Exporte (bisher lag das Vollbackup nach
// einem Export in der Cache Storage). Treffer genau, samt Query: Mit
// `ignoreSearch` bekam `?meter_id=garten` offline die Antwort zum Hauptzähler.
// Eine Antwort aus dem Cache trägt `X-ET-Offline` (Stand laut Date-Header),
// damit die Oberfläche „Offline – Stand vom …" zeigen kann.
const SCOPE_PATH = new URL(self.registration.scope).pathname;
const CACHEABLE_API = /^(utilities|settings|countries|readings-overview|reminders|recommendations|temperatures|pv-summary|strom-saldo|benchmarks\/efficiency|utility\/[^/]+\/(meters|readings|contracts|consumption|deliveries|meter-groups))(\/|$)/;

function apiPath(url) {
  if (!url.pathname.startsWith(SCOPE_PATH)) return null;
  const rest = url.pathname.slice(SCOPE_PATH.length).replace(/^api\.php\//, '');
  return rest.startsWith('api/') ? rest.slice(4) : null;
}

async function apiNetworkFirst(request, cacheable) {
  try {
    const resp = await fetch(request);
    if (cacheable && resp && resp.ok) {
      const cache = await caches.open(RUNTIME_CACHE);
      cache.put(request, resp.clone());
    }
    return resp;
  } catch (e) {
    if (!cacheable) throw e;
    const cached = await (await caches.open(RUNTIME_CACHE)).match(request);
    if (!cached) throw e;
    const headers = new Headers(cached.headers);
    headers.set('X-ET-Offline', cached.headers.get('date') || '');
    return new Response(await cached.blob(), { status: cached.status, statusText: cached.statusText, headers });
  }
}

self.addEventListener('fetch', (event) => {
  const { request } = event;
  if (request.method !== 'GET') return; // Schreibzugriffe nie abfangen

  const url = new URL(request.url);
  const sameOrigin = url.origin === self.location.origin;

  // API-Lesezugriffe: network-first; offline der letzte Stand der Freigabeliste.
  // Vor dem Navigationszweig: Ein Download-Link (Snapshot, CSV, PDF) ist eine
  // Navigation — bis v2.5.3 landete er darüber im Shell-Cache.
  const api = sameOrigin ? apiPath(url) : null;
  if (api !== null) {
    event.respondWith(apiNetworkFirst(request, CACHEABLE_API.test(api)));
    return;
  }

  // Navigationen: frische Shell bevorzugen, offline auf gecachte Shell zurück.
  if (request.mode === 'navigate') {
    event.respondWith(networkFirst(request, STATIC_CACHE, '.'));
    return;
  }

  // v2.2.3 — Anwendungscode und Sprachkataloge: network-first statt
  // stale-while-revalidate.
  //
  // Die ES-Module importieren einander OHNE Cache-Buster (`./lib/sidebar.js`).
  // Unter stale-while-revalidate lieferte der Worker sie nach einem Update aus
  // dem alten Cache aus, während die Shell schon neu war — eine frische
  // `app.js` traf auf ein altes `sidebar.js` ohne den erwarteten Export, der
  // Modulgraph brach mit einem SyntaxError ab, und die Oberfläche blieb bei
  // „Lädt…" stehen. Ein einziger veralteter Baustein legt die ganze Anwendung
  // lahm; das ist den Geschwindigkeitsvorteil nicht wert. Offline greift
  // weiterhin der Cache.
  if (sameOrigin && (url.pathname.includes('/public/js/')
                     || url.pathname.includes('/public/locales/'))) {
    event.respondWith(networkFirst(request, STATIC_CACHE));
    return;
  }

  // Übrige statische Assets (Stile, Schriften, Bilder, Chart.js):
  // stale-while-revalidate — sie stehen für sich und reißen nichts mit.
  if ((sameOrigin && isStaticAsset(url)) || !sameOrigin) {
    event.respondWith(staleWhileRevalidate(request, sameOrigin ? STATIC_CACHE : RUNTIME_CACHE));
    return;
  }
  // Alles andere: normaler Netzwerkpfad.
});
