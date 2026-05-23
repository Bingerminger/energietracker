<?php
declare(strict_types=1);

namespace Energietracker\Services;

use Energietracker\Storage\JsonStore;
use Energietracker\Storage\Migrator;

/**
 * N1003 — Self-Diagnose-Endpoint für Monitoring (Synology-Healthcheck,
 * uptime-Robots, „bei mir geht nichts"-Triage).
 *
 * Bewusst klein gehalten: kein Aggregations-Aufwand, keine Verbrauchs-
 * Berechnung. Nur die Größen, die ein Operator vor dem Aufmachen der
 * Logs sehen will.
 */
final class HealthCheckService
{
    public function __construct(
        private JsonStore $store,
    ) {}

    /**
     * @return array{
     *   version:string, schema_version:string,
     *   data_dir_writable:bool, migrations_pending:int,
     *   installed_at:?string, php_version:string, timezone:string
     * }
     */
    public function run(): array
    {
        // VERSION-Datei: einzige Quelle für die App-Version (siehe
        // workflow-Skill, Schritt [2]). Liegt im Projekt-Root.
        $versionFile = __DIR__ . '/../../VERSION';
        $version = is_file($versionFile)
            ? trim((string)file_get_contents($versionFile))
            : '?';

        $meta = $this->store->read('meta.json', []);
        $migrator = new Migrator($this->store);

        return [
            'version'            => $version,
            'schema_version'     => (string)($meta['schema_version'] ?? '?'),
            'data_dir_writable'  => is_writable($this->store->rootDir()),
            'migrations_pending' => $migrator->needsMigration() ? 1 : 0,
            // Migrator schreibt `created_at` bei initFresh oder `migrated_at`
            // bei einer Migration; beide markieren denselben Punkt (Datenstand
            // ist auf der aktuellen Schema-Version).
            'data_initialized_at' => isset($meta['created_at'])
                ? (string)$meta['created_at']
                : (isset($meta['migrated_at']) ? (string)$meta['migrated_at'] : null),
            'php_version'        => PHP_VERSION,
            'timezone'           => date_default_timezone_get(),
        ];
    }
}
