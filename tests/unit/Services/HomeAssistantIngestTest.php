<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Tests\Support\ServiceTestCase;
use Energietracker\Services\AuthService;
use Energietracker\Services\IngestService;
use Energietracker\Services\MeterService;
use Energietracker\Storage\Migrator;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * F1009 — Home-Assistant-Anbindung: Token-Auth, Zähler-Alias (external_id)
 * und idempotenter Push-Ingest (upsert-by-date).
 */
#[CoversClass(AuthService::class)]
#[CoversClass(IngestService::class)]
#[CoversClass(MeterService::class)]
#[CoversClass(Migrator::class)]
final class HomeAssistantIngestTest extends ServiceTestCase
{
    private AuthService $auth;
    private IngestService $ingest;

    protected function setUp(): void
    {
        parent::setUp();
        $this->auth   = new AuthService($this->store);
        $this->ingest = new IngestService($this->meters, $this->readings);
    }

    /**
     * Setzt das Einbaudatum des Default-Strom-Geräts auf 2024-01-01 zurück
     * (initFresh legt es auf „heute", was Ingest-Tests mit festen
     * Vergangenheitsdaten sonst ablehnt). external_id/Topologie bleiben erhalten.
     */
    private function backdateStromDevice(): void
    {
        $meters = $this->store->read('strom/meters.json', []);
        foreach ($meters as &$m) {
            foreach ($m['devices'] as &$d) {
                $d['installed_on'] = '2024-01-01';
            }
            unset($d);
        }
        unset($m);
        $this->store->write('strom/meters.json', $meters);
    }

    // ── Migration ───────────────────────────────────────────────────────

    public function testFreshInstallHasExternalIdField(): void
    {
        self::assertSame('1.3.0', Migrator::SCHEMA_VERSION);
        $migrator = new Migrator($this->store);
        self::assertFalse($migrator->needsV130Upgrade());

        $meterId = $this->meters->defaultId('strom');
        $meter = $this->meters->get('strom', $meterId);
        self::assertArrayHasKey('external_id', $meter);
        self::assertNull($meter['external_id']);
    }

    public function testUpgradeToV130IsIdempotent(): void
    {
        // Zähler ohne external_id simulieren.
        $this->store->write('strom/meters.json', [[
            'id' => 'm_strom_legacy', 'name' => 'Alt', 'icon' => '⚡',
            'created_at' => '2024-01-01', 'active' => true, 'notes' => '',
            'parent_meter_id' => null, 'meter_group_id' => null,
            'devices' => [[
                'id' => 'd_x', 'serial' => null, 'installed_on' => '2024-01-01',
                'initial_counter' => 0.0, 'removed_on' => null,
                'final_counter' => null, 'reason' => null,
            ]],
        ]]);
        $migrator = new Migrator($this->store);
        self::assertTrue($migrator->needsV130Upgrade());
        $migrator->upgradeToV130();

        $m = $this->meters->get('strom', 'm_strom_legacy');
        self::assertArrayHasKey('external_id', $m);
        self::assertNull($m['external_id']);

        self::assertFalse($migrator->needsV130Upgrade());
        $before = $this->store->read('strom/meters.json', []);
        $migrator->upgradeToV130();
        self::assertSame($before, $this->store->read('strom/meters.json', []));
    }

    // ── Token-Auth ──────────────────────────────────────────────────────

    public function testAuthOptInDefaultsToOpen(): void
    {
        self::assertFalse($this->auth->requiresAuth(), 'ohne Token ist die API offen');
        self::assertTrue($this->auth->verify(null), 'ohne Token gilt jede Anfrage als autorisiert');
        self::assertFalse($this->auth->status()['enabled']);
    }

    public function testGenerateStoresHashNotPlaintextAndVerifies(): void
    {
        $token = $this->auth->generate();
        self::assertStringStartsWith('et_', $token);
        self::assertTrue($this->auth->requiresAuth());
        self::assertTrue($this->auth->verify($token));
        self::assertFalse($this->auth->verify('falscher-token'));
        self::assertFalse($this->auth->verify(null));

        // Klartext darf NICHT auf der Platte liegen — nur der Hash.
        $raw = file_get_contents($this->store->path('auth.json'));
        self::assertStringNotContainsString($token, $raw);
        self::assertStringContainsString('token_hash', $raw);
    }

    public function testRevokeReopensApi(): void
    {
        $this->auth->generate();
        self::assertTrue($this->auth->requiresAuth());
        $this->auth->revoke();
        self::assertFalse($this->auth->requiresAuth());
        self::assertTrue($this->auth->verify(null));
    }

    // ── Zähler-Alias (external_id) ──────────────────────────────────────

    public function testExternalIdRoundtripAndLookup(): void
    {
        $id = $this->meters->create('strom', [
            'name' => 'Hauptzähler', 'external_id' => 'stromzaehler_haus',
        ])['id'];
        $found = $this->meters->getByExternalId('strom', 'stromzaehler_haus');
        self::assertNotNull($found);
        self::assertSame($id, $found['id']);
        self::assertNull($this->meters->getByExternalId('strom', 'gibtsnicht'));
    }

    public function testExternalIdMustBeUniquePerUtility(): void
    {
        $this->meters->create('strom', ['name' => 'A', 'external_id' => 'dup']);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/bereits vergeben/');
        $this->meters->create('strom', ['name' => 'B', 'external_id' => 'dup']);
    }

    public function testExternalIdRejectsInvalidChars(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->meters->create('strom', ['name' => 'A', 'external_id' => 'hat leerzeichen']);
    }

    // ── Ingest ──────────────────────────────────────────────────────────

    public function testIngestCreatesReadingViaAlias(): void
    {
        $this->meters->update('strom', $this->meters->defaultId('strom'), [
            'external_id' => 'stromzaehler_haus',
        ]);
        $this->backdateStromDevice();
        $res = $this->ingest->ingest([
            'utility' => 'strom', 'meter' => 'stromzaehler_haus',
            'value' => 12345.6, 'date' => '2026-01-15',
        ]);
        self::assertSame('created', $res['status']);
        self::assertSame(12345.6, $res['counter']);

        $readings = $this->readings->list('strom', $this->meters->defaultId('strom'));
        $jan = array_filter($readings, fn($r) => $r['date'] === '2026-01-15');
        self::assertCount(1, $jan);
    }

    public function testIngestIsIdempotentPerDate(): void
    {
        $meterId = $this->meters->defaultId('strom');
        $this->backdateStromDevice();
        $this->ingest->ingest(['utility' => 'strom', 'meter' => $meterId, 'value' => 100.0, 'date' => '2026-02-01']);
        $second = $this->ingest->ingest(['utility' => 'strom', 'meter' => $meterId, 'value' => 150.0, 'date' => '2026-02-01']);

        self::assertSame('updated', $second['status'], 'zweiter Push am selben Tag aktualisiert');
        self::assertSame(150.0, $second['counter']);

        $sameDay = array_filter(
            $this->readings->list('strom', $meterId),
            fn($r) => $r['date'] === '2026-02-01'
        );
        self::assertCount(1, $sameDay, 'kein Duplikat am selben Datum');
        self::assertSame(150.0, (float)array_values($sameDay)[0]['counter']);
    }

    public function testIngestAcceptsInternalIdAndIsoTimestamp(): void
    {
        $meterId = $this->meters->defaultId('strom');
        $this->backdateStromDevice();
        $res = $this->ingest->ingest([
            'utility' => 'strom', 'meter' => $meterId,
            'value' => 42.0, 'date' => '2026-03-01T23:55:00+02:00',
        ]);
        self::assertSame('created', $res['status']);
        self::assertSame('2026-03-01', $res['date'], 'ISO-Zeitstempel wird auf Datum gekürzt');
    }

    public function testIngestDefaultsToToday(): void
    {
        $meterId = $this->meters->defaultId('strom');
        $res = $this->ingest->ingest(['utility' => 'strom', 'meter' => $meterId, 'value' => 1.0]);
        self::assertSame(date('Y-m-d'), $res['date']);
    }

    public function testIngestRejectsUnknownMeter(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->ingest->ingest(['utility' => 'strom', 'meter' => 'nope', 'value' => 1.0]);
    }

    public function testIngestRejectsDeliveryUtility(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Lieferungen/');
        $this->ingest->ingest(['utility' => 'heizoel', 'meter' => 'x', 'value' => 1.0]);
    }

    public function testIngestRejectsNonNumericValue(): void
    {
        $meterId = $this->meters->defaultId('strom');
        $this->expectException(\InvalidArgumentException::class);
        $this->ingest->ingest(['utility' => 'strom', 'meter' => $meterId, 'value' => 'abc']);
    }
}
