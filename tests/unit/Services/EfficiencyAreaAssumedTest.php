<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\BenchmarkService;
use Energietracker\Services\DeliveryService;
use Energietracker\Services\RecommendationService;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * v3.9.0 (Skeptiker N6) — die Empfehlung „Effizienzklasse …“ sagt, wenn sie mit der
 * angenommenen Wohnfläche rechnet (niemand hat sie eingetragen, BenchmarkService::efficiency
 * meldet `area_assumed`). Die Effizienzkarte der Übersicht sagte es seit v3.6.0, die Empfehlung
 * nicht.
 *
 * Erfundener Haushalt: Gas, abgelesen am 01.01.2025 und 01.01.2026, dazwischen 16.500 kWh.
 * Mit der angenommenen Fläche von 100 m²: 16.500 / 100 = 165 kWh/m²·a, Klasse F (160 bis 200).
 */
#[CoversClass(RecommendationService::class)]
final class EfficiencyAreaAssumedTest extends ServiceTestCase
{
    private function household(array $settings = []): array
    {
        $this->store->write('settings.json', ['gas_conversion_factors' => [['from' => null, 'kwh_per_m3' => 1.0]]] + $settings);
        $id = $this->setMeterDevices('gas', [['id' => 'd1', 'serial' => null, 'installed_on' => '2024-12-01', 'initial_counter' => 0.0,
            'removed_on' => null, 'final_counter' => null, 'reason' => null]]);
        $this->setReadings('gas', $id, [
            ['date' => '2025-01-01', 'counter' => 1000.0, 'device_id' => 'd1'],
            ['date' => '2026-01-01', 'counter' => 1000.0 + 16500.0, 'device_id' => 'd1'],
        ]);
        $this->store->write('gas/contracts.json', []);
        $recs = new RecommendationService($this->store, $this->meters, $this->consumption, $this->settings,
            new BenchmarkService($this->consumption, $this->meters, $this->settings, $this->i18n),
            new DeliveryService($this->store, $this->meters, $this->i18n), $this->i18n);
        return array_values(array_filter($recs->all(), fn($r) => str_starts_with((string)$r['id'], 'r7_')));
    }

    public function testTheEfficiencyClassSaysWhenTheLivingAreaIsAssumed(): void
    {
        $r7 = $this->household();
        self::assertCount(1, $r7);
        self::assertStringContainsString('Effizienzklasse F (165 kWh/m²·a)', $r7[0]['title']);
        self::assertStringContainsString('Die Klasse rechnet mit einer angenommenen Wohnfläche von 100 m².', $r7[0]['detail']);
        self::assertTrue($r7[0]['evidence']['area_assumed']);
        self::assertSame(100.0, $r7[0]['evidence']['area_m2']);
    }

    public function testWithAnEnteredAreaTheSentenceIsGone(): void
    {
        // eingetragen 100 m²: dieselbe Klasse, aber ohne den Hinweis
        $r7 = $this->household(['wohnflaeche_m2' => 100]);
        self::assertCount(1, $r7);
        self::assertStringNotContainsString('angenommenen Wohnfläche', $r7[0]['detail']);
        self::assertFalse($r7[0]['evidence']['area_assumed']);
    }
}
