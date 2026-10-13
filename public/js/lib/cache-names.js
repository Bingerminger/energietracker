// =====================================================================
// Namen der Caches dieser Installation (v3.4.0, N1020).
//
// sw.js legt seine Caches seit v3.1.0 als `et:<scope>:static-<version>` und
// `et:<scope>:runtime-<version>` an (Scope = Verzeichnis der Installation).
// Das Abmelden suchte bis v3.3 noch nach `et-runtime-…` und ließ die
// API-Antworten (Zähler, Verträge, Stände) auf geteilten Geräten liegen.
// sw.js ist ein klassisches Worker-Skript und kann nichts importieren;
// tests/cache-names.test.mjs gleicht beide Seiten ab.
// =====================================================================

/** Präfix der Caches dieser Installation (wie `PREFIX` in sw.js und index.php). */
export function cachePrefix(base = location.href) {
  return 'et:' + new URL('.', base).pathname + ':';
}

/** Cache mit API-Antworten dieser Installation — auch der alte Name ohne Scope (bis v3.0). */
export function isRuntimeCache(name, base = location.href) {
  return name.startsWith(cachePrefix(base) + 'runtime-') || /^et-runtime-v/.test(name);
}
