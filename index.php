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
$cb = filemtime(__DIR__ . '/public/js/app.js') ?: time();
?>
<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="light dark">
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
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=DM+Mono:wght@400;500&family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="public/css/tokens.css?v=<?= $cb ?>">
<link rel="stylesheet" href="public/css/app.css?v=<?= $cb ?>">
<link rel="stylesheet" href="public/css/components.css?v=<?= $cb ?>">
</head>
<body data-app-version="<?= htmlspecialchars($version, ENT_QUOTES) ?>">
<div id="app" class="layout">

  <!-- Thin top bar -->
  <header class="topbar">
    <div class="topbar__brand">
      <span class="topbar__logo" aria-hidden="true"></span>
      <span class="topbar__title">ENERGIETRACKER</span>
      <span class="topbar__version">v<?= htmlspecialchars($version, ENT_QUOTES) ?></span>
    </div>
    <div class="topbar__actions">
      <button type="button" id="theme-toggle" class="topbar__btn" aria-label="Theme wechseln" title="Theme wechseln">
        <span class="topbar__btn-icon" aria-hidden="true"></span>
      </button>
      <div class="topbar__status" id="topbar-status"></div>
    </div>
  </header>

  <!-- Left sidebar -->
  <aside class="sidebar" id="sidebar">
    <nav class="sidebar__nav" id="primary-nav">
      <div class="loading">…</div>
    </nav>
    <div class="sidebar__footer">
      <a href="https://github.com/Bingerminger/energietracker" target="_blank" rel="noopener">GitHub</a>
      <span>·</span>
      <span>flat-file JSON</span>
    </div>
  </aside>

  <!-- Main content -->
  <main id="view" class="view"><div class="loading">Lade…</div></main>

  <div id="toast-stack" class="toast-stack" aria-live="polite"></div>
  <div id="modal-root"></div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script type="module" src="public/js/app.js?v=<?= $cb ?>"></script>
</body>
</html>
