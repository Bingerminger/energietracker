<?php
declare(strict_types=1);

namespace Energietracker\Tests\Support;

use Energietracker\Services\AnomalyService;
use Energietracker\Services\ConsumptionService;
use Energietracker\Services\ContractService;
use Energietracker\Services\DeliveryConsumptionService;
use Energietracker\Services\MeterService;
use Energietracker\Services\ReadingService;
use Energietracker\Services\RegressionService;
use Energietracker\Services\SettingsService;
use Energietracker\Storage\JsonStore;
use Energietracker\Storage\Migrator;
use PHPUnit\Framework\TestCase;

/**
 * Base class for service-level unit tests.
 *
 * Each test gets a fresh JSON data directory under sys_get_temp_dir() with the
 * schema already initialised via Migrator::initFresh(). Services are wired up
 * exactly like the App container does in src/bootstrap.php, but without the
 * HTTP layer — so tests exercise real I/O against real JSON files instead of
 * mocks. That matches the project philosophy ("Code-First", no mocking of the
 * storage layer).
 */
abstract class ServiceTestCase extends TestCase
{
    protected string $dataDir;
    protected JsonStore $store;
    protected SettingsService $settings;
    protected MeterService $meters;
    protected ReadingService $readings;
    protected ContractService $contracts;
    protected RegressionService $regression;
    protected DeliveryConsumptionService $deliveryConsumption;
    protected ConsumptionService $consumption;
    protected AnomalyService $anomalies;

    protected function setUp(): void
    {
        parent::setUp();
        $tmp = sys_get_temp_dir() . '/et-test-' . bin2hex(random_bytes(6));
        mkdir($tmp, 0755, true);
        // macOS: /var/folders/... ist ein Symlink auf /private/var/folders/...;
        // JsonStore::path() prüft realpath()-Prefix, also dataDir auflösen.
        $this->dataDir = realpath($tmp) ?: $tmp;

        $this->store    = new JsonStore($this->dataDir);
        (new Migrator($this->store))->initFresh();

        $this->settings    = new SettingsService($this->store);
        $this->meters      = new MeterService($this->store);
        $this->readings    = new ReadingService($this->store, $this->meters);
        $this->contracts   = new ContractService($this->store, $this->meters);
        $this->regression  = new RegressionService();
        $this->deliveryConsumption = new DeliveryConsumptionService($this->store, $this->settings);
        $this->consumption = new ConsumptionService(
            $this->store, $this->meters, $this->readings, $this->contracts, $this->settings,
            $this->regression, $this->deliveryConsumption
        );
        $this->anomalies   = new AnomalyService($this->regression, $this->settings);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->dataDir);
        parent::tearDown();
    }

    /**
     * Replace the default single-device meter for a utility with a custom
     * device chain. Convenient for bridging tests that need a specific
     * old/new device pair with explicit initial_counter / final_counter.
     *
     * @param array<int,array<string,mixed>> $devices
     */
    protected function setMeterDevices(string $utility, array $devices, ?string $meterId = null): string
    {
        $all = $this->store->read("$utility/meters.json", []);
        if (!is_array($all) || empty($all)) {
            $meterId ??= 'm_' . $utility . '_default';
            $all = [[
                'id'         => $meterId,
                'name'       => 'Test',
                'icon'       => '',
                'created_at' => '2024-01-01',
                'active'     => true,
                'notes'      => '',
                'devices'    => $devices,
            ]];
        } else {
            $meterId ??= $all[0]['id'];
            $all[0]['devices'] = $devices;
        }
        $this->store->write("$utility/meters.json", $all);
        return $meterId;
    }

    /**
     * Bulk-write readings for a meter. Each entry must carry at minimum
     * 'date', 'counter', 'device_id'. ID and meter_id are filled in.
     *
     * @param array<int,array<string,mixed>> $readings
     */
    protected function setReadings(string $utility, string $meterId, array $readings): void
    {
        $out = [];
        foreach ($readings as $i => $r) {
            $out[] = array_merge([
                'id'           => sprintf('r_%03d', $i),
                'meter_id'     => $meterId,
                'price_cents'  => null,
                'note'         => '',
                'is_estimated' => false,
                'is_future'    => false,
            ], $r);
        }
        usort($out, fn($a, $b) => strcmp($a['date'], $b['date']));
        $this->store->write("$utility/readings.json", $out);
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) return;
        foreach (scandir($dir) ?: [] as $f) {
            if ($f === '.' || $f === '..') continue;
            $p = "$dir/$f";
            is_dir($p) ? $this->rrmdir($p) : @unlink($p);
        }
        @rmdir($dir);
    }
}
