<?php
/**
 * Energietracker — API entry point.
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

// ET_DATA_DIR allows overriding the storage path via environment variable.
// Useful for CI pipelines and custom deployment layouts.
$dataDir = getenv('ET_DATA_DIR') ?: __DIR__ . '/data';
$app = new \Energietracker\App($dataDir);
$app->handle(new \Energietracker\Http\Request());
