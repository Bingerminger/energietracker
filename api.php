<?php
/**
 * Energietracker v1.0.2 — API entry point.
 *
 * This file is intentionally tiny. All wiring lives in src/bootstrap.php.
 * Every HTTP request hits this file via the web server.
 *
 * Backend layout:
 *   /api.php           — this entry point
 *   /index.php         — SPA shell (HTML)
 *   /src/              — namespaced PHP code (Energietracker\…)
 *   /data/             — JSON storage (created on first run)
 *   /public/           — static assets (css, js)
 */
declare(strict_types=1);

require __DIR__ . '/src/bootstrap.php';

$app = new \Energietracker\App(__DIR__ . '/data');
$app->handle(new \Energietracker\Http\Request());
