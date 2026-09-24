<?php
declare(strict_types=1);

namespace Energietracker\Services;

use Energietracker\Config\Utilities;
use Energietracker\Storage\JsonStore;
use Energietracker\Storage\Migrator;

/**
 * N1003 — Self-Diagnose-Endpoint für Monitoring (Synology-Healthcheck,
 * uptime-Robots, „bei mir geht nichts"-Triage).
 *
 * Bewusst klein gehalten: kein Aggregations-Aufwand, keine Verbrauchs-
 * Berechnung. Nur die Größen, die ein Operator vor dem Aufmachen der
 * Logs sehen will.
 *
 * v2.6.0 — `status` (ok | degraded | error) und `checks{}`. Bis v2.5.3 kam
 * immer HTTP 200, auch bei schreibgeschütztem Datenverzeichnis oder
 * beschädigten Dateien; der Docker-HEALTHCHECK meldete „healthy". Bei
 * `error` antwortet der Controller jetzt mit 503. Die bisherigen Felder
 * bleiben unverändert erhalten (additiv).
 */
final class HealthCheckService
{
    /** Verwaiste Temp-Dateien älter als das gelten als Rest eines Abbruchs. */
    private const TEMP_MAX_AGE = 3600;

    public function __construct(
        private JsonStore $store,
    ) {}

    /** @return array<string,mixed> */
    public function run(): array
    {
        // VERSION-Datei: einzige Quelle für die App-Version (siehe
        // workflow-Skill, Schritt [2]). Liegt im Projekt-Root.
        $versionFile = __DIR__ . '/../../VERSION';
        $version = is_file($versionFile)
            ? trim((string)file_get_contents($versionFile))
            : '?';

        $checks = [];
        $meta = [];
        try {
            $meta = $this->store->read('meta.json', []);
        } catch (\Throwable) {
            // wird unten bei den Dateien als beschädigt gemeldet
        }
        $migrator = new Migrator($this->store);
        $root = $this->store->rootDir();

        $writable = is_writable($root);
        $checks['data_dir_writable'] = ['ok' => $writable, 'level' => $writable ? 'ok' : 'error'];

        $newer = $this->safe(fn() => $migrator->dataSchemaIsNewer());
        $pending = $this->safe(fn() => $migrator->pendingSteps(), 0);
        $checks['schema'] = match (true) {
            $newer !== null => ['ok' => false, 'level' => 'error', 'data_schema' => $newer, 'app_schema' => Migrator::SCHEMA_VERSION],
            $pending > 0    => ['ok' => false, 'level' => 'degraded', 'pending_steps' => $pending],
            default         => ['ok' => true, 'level' => 'ok'],
        };

        $corrupt = $this->corruptFiles($root);
        $checks['files'] = ['ok' => $corrupt === [], 'level' => $corrupt === [] ? 'ok' : 'error', 'corrupt' => $corrupt];

        $free = @disk_free_space($root);
        $checks['disk'] = match (true) {
            $free === false            => ['ok' => true, 'level' => 'ok', 'free_mb' => null],
            $free < 5 * 1024 * 1024    => ['ok' => false, 'level' => 'error', 'free_mb' => round($free / 1048576, 1)],
            $free < 50 * 1024 * 1024   => ['ok' => false, 'level' => 'degraded', 'free_mb' => round($free / 1048576, 1)],
            default                    => ['ok' => true, 'level' => 'ok', 'free_mb' => round($free / 1048576)],
        };

        $removed = $this->removeStaleTempFiles($root);
        $checks['temp_files'] = ['ok' => true, 'level' => 'ok', 'removed' => $removed];

        $levels = array_column($checks, 'level');
        $status = in_array('error', $levels, true) ? 'error'
            : (in_array('degraded', $levels, true) ? 'degraded' : 'ok');

        return [
            'status'             => $status,
            'version'            => $version,
            'schema_version'     => (string)($meta['schema_version'] ?? '?'),
            'data_dir_writable'  => $writable,
            // Seit v2.6.0 die Zahl der ausstehenden Stufen (bisher 0/1).
            'migrations_pending' => (int)$pending,
            // Migrator schreibt `created_at` bei initFresh oder `migrated_at`
            // bei einer Migration; beide markieren denselben Punkt (Datenstand
            // ist auf der aktuellen Schema-Version).
            'data_initialized_at' => isset($meta['created_at'])
                ? (string)$meta['created_at']
                : (isset($meta['migrated_at']) ? (string)$meta['migrated_at'] : null),
            'php_version'        => PHP_VERSION,
            'timezone'           => date_default_timezone_get(),
            'last_ingest'        => $this->lastIngest(),
            'checks'             => $checks,
        ];
    }

    /** Minimalform für nicht angemeldete Aufrufer bei aktiver Anmeldung. */
    public function minimal(): array
    {
        $full = $this->run();
        return ['status' => $full['status'], 'version' => $full['version']];
    }

    private function safe(callable $fn, mixed $fallback = null): mixed
    {
        try { return $fn(); } catch (\Throwable) { return $fallback; }
    }

    /**
     * Alle Datentöpfe auf gültiges JSON prüfen. Liest die Rohdatei, nicht
     * über JsonStore::read(): Das würde bei jedem Healthcheck eine
     * Quarantäne-Kopie anlegen wollen.
     *
     * @return string[] relative Pfade beschädigter Dateien
     */
    private function corruptFiles(string $root): array
    {
        $bad = [];
        $files = array_merge(glob($root . '/*.json') ?: [], glob($root . '/*/*.json') ?: []);
        foreach ($files as $path) {
            $rel = substr($path, strlen($root) + 1);
            if (str_starts_with($rel, 'backups/')) continue;
            $raw = @file_get_contents($path);
            if ($raw === false) { $bad[] = $rel; continue; }
            if (trim($raw) === '') continue;
            json_decode($raw);
            if (json_last_error() !== JSON_ERROR_NONE) $bad[] = $rel;
        }
        sort($bad);
        return $bad;
    }

    /**
     * v2.6.0 — Temp-Dateien eines abgebrochenen Schreibvorgangs
     * (`<datei>.tmp.<hex>`) entfernen, sobald sie älter als eine Stunde sind.
     * Sie enthalten vollständige Datensätze und würden sonst liegen bleiben.
     *
     * @return int Anzahl entfernter Dateien
     */
    private function removeStaleTempFiles(string $root): int
    {
        $n = 0;
        $files = array_merge(glob($root . '/*.tmp.*') ?: [], glob($root . '/*/*.tmp.*') ?: []);
        foreach ($files as $path) {
            $age = time() - (int)@filemtime($path);
            if ($age > self::TEMP_MAX_AGE && @unlink($path)) $n++;
        }
        return $n;
    }

    /**
     * Letzte Home-Assistant-Übermittlung je Zähler (Ablesungen mit
     * `source: "ingest"`, ältere mit der Notiz „Home Assistant").
     *
     * @return array<string,string> meter_id → Datum
     */
    private function lastIngest(): array
    {
        $out = [];
        foreach (Utilities::keys() as $u) {
            if (!Utilities::isCumulative($u)) continue;
            $list = $this->safe(fn() => $this->store->read("$u/readings.json", []), []);
            if (!is_array($list)) continue;
            foreach ($list as $r) {
                if (!is_array($r)) continue;
                $isIngest = ($r['source'] ?? null) === 'ingest' || ($r['note'] ?? null) === 'Home Assistant';
                if (!$isIngest) continue;
                $id = (string)($r['meter_id'] ?? '');
                $d  = (string)($r['date'] ?? '');
                if ($id !== '' && $d > ($out[$id] ?? '')) $out[$id] = $d;
            }
        }
        ksort($out);
        return $out;
    }
}
