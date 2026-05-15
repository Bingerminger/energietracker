<?php
/**
 * Energietracker v1.1.0 — SPA shell.
 *
 * Layout: thin top bar + 220px left sidebar (v0.9.0-style),
 * main content area on the right. All rendering happens client-side
 * in /public/js/.
 */
declare(strict_types=1);

$version = trim((string)@file_get_contents(__DIR__ . '/VERSION')) ?: '1.1.0';
$cb = filemtime(__DIR__ . '/public/js/app.js') ?: time();
?>
<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="dark">
<title>Energietracker <?= htmlspecialchars($version, ENT_QUOTES) ?></title>
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
      <span class="topbar__title">ENERGIE TRACKING</span>
      <span class="topbar__version">v<?= htmlspecialchars($version, ENT_QUOTES) ?></span>
    </div>
    <div class="topbar__status" id="topbar-status"></div>
  </header>

  <!-- Left sidebar -->
  <aside class="sidebar" id="sidebar">
    <nav class="sidebar__nav" id="primary-nav">

      <div class="sidebar__group">
        <div class="sidebar__group-label">Übersicht</div>
        <a class="sidebar__item" href="#/dashboard" data-route="dashboard">
          <span class="sidebar__icon">🏠</span><span>Dashboard</span>
        </a>
      </div>

      <div class="sidebar__group">
        <div class="sidebar__group-label">Verbrauch</div>
        <a class="sidebar__item" href="#/utility/gas"    data-route="utility:gas"    data-utility="gas">
          <span class="sidebar__icon">🔥</span><span>Gas</span>
        </a>
        <a class="sidebar__item" href="#/utility/strom"  data-route="utility:strom"  data-utility="strom">
          <span class="sidebar__icon">⚡</span><span>Strom</span>
        </a>
        <a class="sidebar__item" href="#/utility/wasser" data-route="utility:wasser" data-utility="wasser">
          <span class="sidebar__icon">💧</span><span>Wasser</span>
        </a>
        <a class="sidebar__item" href="#/temperatures"   data-route="temperatures">
          <span class="sidebar__icon">🌡️</span><span>Temperaturen</span>
        </a>
      </div>

      <div class="sidebar__group">
        <div class="sidebar__group-label">Analyse</div>
        <a class="sidebar__item" href="#/analysis" data-route="analysis">
          <span class="sidebar__icon">📊</span><span>Korrelation</span>
        </a>
        <a class="sidebar__item" href="#/forecast" data-route="forecast">
          <span class="sidebar__icon">🎯</span><span>Prognose</span>
        </a>
      </div>

      <div class="sidebar__group">
        <div class="sidebar__group-label">System</div>
        <a class="sidebar__item" href="#/settings" data-route="settings">
          <span class="sidebar__icon">⚙️</span><span>Einstellungen</span>
        </a>
      </div>

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
