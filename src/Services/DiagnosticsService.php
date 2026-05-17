<?php
declare(strict_types=1);

namespace Energietracker\Services;

use Energietracker\Storage\JsonStore;
use Energietracker\Storage\Migrator;
use Energietracker\Config\Utilities;

/**
 * System-Diagnose: PHP-Version, Datenverzeichnis, Schreibrechte,
 * Schema-Version, Aggregate pro Utility (Anzahl Zähler, Readings, Verträge).
 * Wird von der Settings-View und potenziellen Monitoring-Konsumenten genutzt.
 */
final class DiagnosticsService
{
    public function __construct(
        private JsonStore $store,
        private SettingsService $settings,
    ) {}

    public function run(): array
    {
        $rootDir = $this->store->rootDir();
        $info = [
            'app_version'    => trim(@file_get_contents(__DIR__ . '/../../VERSION') ?: 'unknown'),
            'schema_version' => $this->store->read('meta.json', [])['schema_version'] ?? '?',
            'php_version'    => PHP_VERSION,
            'data_dir'       => $rootDir,
            'data_dir_writable' => is_dir($rootDir) && is_writable($rootDir),
            'curl_available' => function_exists('curl_init'),
            'time_zone'      => date_default_timezone_get(),
            'now'            => date('c'),
        ];

        $info['utilities'] = [];
        foreach (Utilities::keys() as $key) {
            $isDelivery = Utilities::isDelivery($key);
            $meters    = $this->store->read("$key/meters.json", []);
            $contracts = $this->store->read("$key/contracts.json", []);

            // v1.3.0 — bei Delivery-Utilities Lieferungen statt Ablesungen zählen
            if ($isDelivery) {
                $deliveries = $this->store->read("$key/deliveries.json", []);
                $info['utilities'][$key] = [
                    'kind'              => 'delivery',
                    'meters'            => count($meters),
                    'deliveries'        => count($deliveries),
                    'contracts'         => count($contracts),
                    'last_delivery_date' => $this->lastDeliveryDate($deliveries),
                ];
            } else {
                $readings  = $this->store->read("$key/readings.json", []);
                $info['utilities'][$key] = [
                    'kind'              => 'cumulative',
                    'meters'            => count($meters),
                    'readings'          => count($readings),
                    'contracts'         => count($contracts),
                    'last_reading_date' => $this->lastReadingDate($readings),
                ];
            }
        }
        $info['temperatures'] = [
            'rows' => count($this->store->read('temperatures.json', [])),
        ];
        $info['settings_known_keys'] = $this->settings->knownKeys();
        $info['migration_needed'] = (new Migrator($this->store))->needsMigration();

        return $info;
    }

    private function lastReadingDate(array $readings): ?string
    {
        $dates = array_filter(array_map(fn($r) => is_array($r) ? ($r['date'] ?? null) : null, $readings));
        return $dates ? max($dates) : null;
    }

    private function lastDeliveryDate(array $deliveries): ?string
    {
        $dates = array_filter(array_map(
            fn($d) => is_array($d) && empty($d['is_planned']) ? ($d['date'] ?? null) : null,
            $deliveries
        ));
        return $dates ? max($dates) : null;
    }
}
