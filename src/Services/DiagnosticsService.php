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
    /**
     * v3.13.0 (Paket K1) — die Mindestversion von PHP, die die CI prüft (wie `require.php` in composer.json,
     * PhpMinimumVersionTest). Darunter zeigt die Diagnose-Seite einen Hinweis; `/api/health` bleibt davon
     * unberührt (eine ältere Version läuft weiter, sie ist nur nicht mehr getestet).
     */
    public const PHP_MINIMUM = '8.4';
    public const PHP_MINIMUM_ID = 80400;

    /**
     * @param (\Closure(): array<string,mixed>)|null $security v3.4.0 (N1020) —
     *        Sicherheitshinweise der Anfrage (App::securityStatus)
     * @param ?string $phpVersion v3.13.0 (Paket K1) — Version, die die Diagnose nennt und prüft (Tests; sonst die Laufzeit)
     */
    public function __construct(
        private JsonStore $store,
        private SettingsService $settings,
        private ?\Closure $security = null,
        private ?string $phpVersion = null,
    ) {}

    public function run(): array
    {
        $rootDir = $this->store->rootDir();
        $php = $this->phpVersion ?? PHP_VERSION;
        $info = [
            'app_version'    => trim(@file_get_contents(__DIR__ . '/../../VERSION') ?: 'unknown'),
            'schema_version' => $this->store->read('meta.json', [])['schema_version'] ?? '?',
            'php_version'    => $php,
            // v3.13.0 (Paket K1) — additiv: Mindestversion und ob diese Version sie erreicht (Hinweis auf der Diagnose-Seite)
            'php_minimum'    => self::PHP_MINIMUM,
            'php_supported'  => self::versionId($php) >= self::PHP_MINIMUM_ID,
            'data_dir'       => $rootDir,
            'data_dir_writable' => is_dir($rootDir) && is_writable($rootDir),
            'curl_available' => function_exists('curl_init'),
            'time_zone'      => date_default_timezone_get(),
            'now'            => date('c'),
            // v3.13.0 (K2d) — additiv: gespeicherte Werte, die die Schreibwege heute abweisen (Bestand einer älteren
            // Version), je Fund Topf, Kennung und Art; die Rechnung lässt sie aus bzw. nimmt die Vorgabe (DataIssues)
            'data_issues'    => (new DataIssues($this->store))->scan(),
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
        // v3.4.0 (N1020) — additiv: Hinweise zu Proxy-Kopfzeile und Hostliste
        $info['security'] = $this->security !== null ? ($this->security)() : null;
        // v3.10.0 (#36) — additiv: eigene Stammzertifikate (ET_CA_FILE, Einstellung) und „nicht prüfen“
        $info['home_tls'] = \Energietracker\Support\HomeTls::status();

        return $info;
    }

    /** v3.13.0 (Paket K1) — `8.3.27` → 80327 wie PHP_VERSION_ID (Haupt × 10.000 + Neben × 100 + Patch). */
    public static function versionId(string $version): int
    {
        if (!preg_match('/^(\d+)\.(\d+)(?:\.(\d+))?/', $version, $m)) return 0;
        return (int)$m[1] * 10000 + (int)$m[2] * 100 + (int)($m[3] ?? 0);
    }

    private function lastReadingDate(array $readings): ?string
    {
        // v3.13.0 (K2d) — nur Tage als Text: ein Tag als Liste aus einer älteren Version war für max() größer als jeder
        // Text, und die Diagnose endete in 500
        $dates = array_filter(array_map(fn($r) => is_array($r) && is_string($r['date'] ?? null) ? $r['date'] : null, $readings));
        return $dates ? max($dates) : null;
    }

    private function lastDeliveryDate(array $deliveries): ?string
    {
        $dates = array_filter(array_map(
            fn($d) => is_array($d) && empty($d['is_planned']) && is_string($d['date'] ?? null) ? $d['date'] : null,   // v3.13.0 (K2d) — wie oben
            $deliveries
        ));
        return $dates ? max($dates) : null;
    }
}
