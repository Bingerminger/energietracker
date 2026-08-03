<?php
/**
 * Energietracker v1.3.0 — SPA shell.
 *
 * Layout: thin top bar + 220px left sidebar (v0.9.0-style),
 * main content area on the right. All rendering happens client-side
 * in /public/js/.
 */
declare(strict_types=1);

$version = trim((string)@file_get_contents(__DIR__ . '/VERSION')) ?: '1.3.0';
// Cache-Buster. v2.2.0: an die VERSION gekoppelt statt an die mtime von
// app.js. Die mtime änderte sich nicht, wenn ein Release nur Views oder CSS
// anfasste — Browser behielten dann die alten Module. Der mtime-Anteil bleibt
// als Zusatz erhalten, damit lokale Entwicklung ohne Versionssprung weiterhin
// frische Dateien bekommt.
$cb = preg_replace('/[^A-Za-z0-9._-]/', '', $version)
    . '-' . (filemtime(__DIR__ . '/public/js/app.js') ?: time());

// N1009 — Sprache der Shell aus dem `language`-Setting ableiten, damit
// <html lang> und die serverseitig gerenderten Shell-Strings schon beim
// ersten Paint korrekt sind (vor dem JS-i18n-Init). Fallback: 'de'.
$lang = 'de';
// Unterstützte Sprachen datengetrieben aus der Registry (gleiche Quelle wie
// Frontend i18n.js und Backend I18nService).
$supportedLangs = ['de', 'en'];
$langsFile = __DIR__ . '/public/locales/languages.json';
if (is_file($langsFile)) {
    $reg = json_decode((string)@file_get_contents($langsFile), true);
    if (is_array($reg) && $reg !== []) {
        $supportedLangs = array_keys($reg);
    }
}
$dataDir = getenv('ET_DATA_DIR') ?: (__DIR__ . '/data');
$settingsFile = rtrim($dataDir, '/') . '/settings.json';
if (is_file($settingsFile)) {
    $s = json_decode((string)@file_get_contents($settingsFile), true);
    if (is_array($s) && in_array($s['language'] ?? '', $supportedLangs, true)) {
        $lang = $s['language'];
    }
}
$catalog = json_decode((string)@file_get_contents(__DIR__ . "/public/locales/$lang.json"), true) ?: [];
// Minimaler Katalog-Lookup für die wenigen Shell-Strings (Skip-Link, Nav-Label,
// Theme-Toggle, Lade-Text). Das volle t() läuft im Frontend.
$tShell = function (string $path, string $fallback) use ($catalog): string {
    $ref = $catalog;
    foreach (explode('.', $path) as $k) {
        if (!is_array($ref) || !array_key_exists($k, $ref)) return $fallback;
        $ref = $ref[$k];
    }
    return is_string($ref) ? $ref : $fallback;
};
$h = fn(string $v): string => htmlspecialchars($v, ENT_QUOTES);
?>
<!doctype html>
<html lang="<?= $h($lang) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="light dark">
<meta name="theme-color" media="(prefers-color-scheme: dark)" content="#111827">
<meta name="theme-color" media="(prefers-color-scheme: light)" content="#ffffff">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="Energietracker">
<link rel="manifest" href="manifest.webmanifest">
<title>Energietracker <?= htmlspecialchars($version, ENT_QUOTES) ?></title>
<link rel="icon" type="image/png" sizes="32x32" href="public/img/icon-light-32.png">
<link rel="icon" type="image/png" sizes="16x16" href="public/img/icon-light-16.png">
<link rel="shortcut icon" href="public/favicon.ico">
<link rel="apple-touch-icon" sizes="180x180" href="public/img/icon-light-180.png">
<!--
  Theme anti-flash. Läuft synchron vor dem CSS-Laden und setzt
  data-theme="light|dark" auf <html>, damit das Layout nicht erst dunkel
  rendert und dann auf hell umspringt (umgekehrt analog).
  Quelle der Wahrheit: localStorage["et-theme"] (vom Toggle gesetzt) →
  Fallback prefers-color-scheme → Fallback "dark".
-->
<script>
(function() {
  try {
    var saved = localStorage.getItem('et-theme');
    var theme = (saved === 'light' || saved === 'dark')
      ? saved
      : (window.matchMedia && window.matchMedia('(prefers-color-scheme: light)').matches ? 'light' : 'dark');
    document.documentElement.setAttribute('data-theme', theme);
  } catch (e) {
    document.documentElement.setAttribute('data-theme', 'dark');
  }
})();
</script>
<!--
  v2.2.0 — Schriften und Chart.js liegen im Repo (public/vendor/). Vorher kamen
  sie von fonts.googleapis.com und cdn.jsdelivr.net: Bei einer selbst
  gehosteten Anwendung wanderte damit die IP jedes Aufrufs zu Dritten, und der
  erste Start ohne Internet hatte weder Schrift noch Diagramme. Lizenzen liegen
  daneben (OFL 1.1 bzw. MIT).
-->
<link rel="preload" href="public/vendor/fonts/dm-sans.woff2" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="public/vendor/fonts.css?v=<?= $cb ?>">
<link rel="stylesheet" href="public/css/tokens.css?v=<?= $cb ?>">
<link rel="stylesheet" href="public/css/app.css?v=<?= $cb ?>">
<link rel="stylesheet" href="public/css/components.css?v=<?= $cb ?>">
<link rel="stylesheet" href="public/css/readings-entry.css?v=<?= $cb ?>">
</head>
<body data-app-version="<?= $h($version) ?>">
<a class="skip-link" href="#view"><?= $h($tShell('app.skipToContent', 'Zum Hauptinhalt springen')) ?></a>
<div id="app" class="layout">

  <!-- Thin top bar -->
  <header class="topbar">
    <div class="topbar__brand">
      <span class="topbar__logo" aria-hidden="true"></span>
      <span class="topbar__title">ENERGIETRACKER</span>
      <span class="topbar__version">v<?= htmlspecialchars($version, ENT_QUOTES) ?></span>
    </div>
    <div class="topbar__actions">
      <button type="button" id="theme-toggle" class="topbar__btn" aria-label="<?= $h($tShell('app.themeToggle', 'Theme wechseln')) ?>" title="<?= $h($tShell('app.themeToggle', 'Theme wechseln')) ?>">
        <span class="topbar__btn-icon" aria-hidden="true"></span>
      </button>
      <div class="topbar__status" id="topbar-status" role="status" aria-live="polite"></div>
    </div>
  </header>

  <!-- Left sidebar -->
  <aside class="sidebar" id="sidebar">
    <nav class="sidebar__nav" id="primary-nav" aria-label="<?= $h($tShell('app.primaryNav', 'Hauptnavigation')) ?>">
      <div class="loading" role="status"><?= $h($tShell('common.loading', 'Lädt…')) ?></div>
    </nav>
    <div class="sidebar__footer">
      <a href="https://github.com/Bingerminger/energietracker" target="_blank" rel="noopener">GitHub</a>
      <span>·</span>
      <span>flat-file JSON</span>
    </div>
  </aside>

  <!-- Main content -->
  <main id="view" class="view" tabindex="-1"><div class="loading" role="status"><?= $h($tShell('common.loading', 'Lädt…')) ?></div></main>
</div>

<!--
  Overlays liegen bewusst AUSSERHALB von #app: Ein offener Dialog setzt #app
  auf `inert` (v2.2.0), damit Screenreader nicht hinter den Dialog wandern
  können. Lägen Toasts und Modal-Wurzel darin, wären sie mit inert gelegt.
  Die Toast-Rollen setzt components/toast.js pro Meldung.
-->
<div id="toast-stack" class="toast-stack"></div>
<div id="modal-root"></div>

<!--
  v2.2.3 — Selbstheilung nach einem Update.

  Die ES-Module importieren einander ohne Cache-Buster (`./lib/sidebar.js`,
  nicht `…?v=…`). Der Service Worker cachte sie, sodass nach einem Update die
  frische Shell auf alte Module traf: `app.js` v2.2.2 importierte
  `refreshSidebarBadges`, das die gecachte `sidebar.js` v2.1.5 nicht kennt —
  ein SyntaxError, der den gesamten Start abbricht. Sichtbar blieb nur „Lädt…".

  Dieses Skript läuft VOR den Modulen. Trägt ein Cache eine andere Version als
  die ausgelieferte Shell, räumt es Caches und Worker ab und lädt genau einmal
  neu (die Sperre in sessionStorage verhindert eine Schleife).
-->
<script>
(function () {
  var VERSION = <?= json_encode($version) ?>;
  if (!('caches' in window)) return;
  var GUARD = 'et-cache-healed';
  caches.keys().then(function (keys) {
    var stale = keys.filter(function (k) {
      return k.indexOf('et-') === 0 && k.indexOf(VERSION) === -1;
    });
    if (!stale.length) { try { sessionStorage.removeItem(GUARD); } catch (e) {} return; }
    if (sessionStorage.getItem(GUARD) === VERSION) return;   // schon versucht
    try { sessionStorage.setItem(GUARD, VERSION); } catch (e) {}
    Promise.all([
      Promise.all(keys.map(function (k) { return caches.delete(k); })),
      navigator.serviceWorker && navigator.serviceWorker.getRegistrations
        ? navigator.serviceWorker.getRegistrations().then(function (rs) {
            return Promise.all(rs.map(function (r) { return r.unregister(); }));
          })
        : Promise.resolve()
    ]).then(function () { location.reload(); });
  }).catch(function () {});
})();
</script>
<script src="public/vendor/chart.umd.min.js?v=<?= $cb ?>"></script>
<script type="module" src="public/js/app.js?v=<?= $cb ?>"></script>
<!-- N1008 (PWA) — Service Worker registrieren. Inline (kein Modul), damit der
     relative Pfad 'sw.js' gegen die Dokument-URL (Web-Wurzel) auflöst und der
     Worker Root-Scope erhält. -->
<script>
  if ('serviceWorker' in navigator) {
    window.addEventListener('load', function () {
      navigator.serviceWorker.register('sw.js').catch(function () {});
    });
  }
</script>
</body>
</html>
