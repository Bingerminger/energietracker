<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Tests\Support\ServiceTestCase;
use Energietracker\Services\ContractService;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * v2.3.2 — Überlappende Verträge müssen deterministisch aufgelöst werden.
 *
 * `findActiveForDate()` nahm den ersten passenden Eintrag des Arrays. Dessen
 * Position ergibt sich aber aus der Anlage-Reihenfolge in der JSON-Datei, nicht
 * aus der Fachlichkeit — bei überlappenden Verträgen entschied also der Zufall
 * der Speicherung, welcher Tarif für einen Monat angesetzt wurde. Dieselbe
 * Datenlage konnte damit unterschiedliche Kosten ergeben.
 *
 * Der Fall ist nicht konstruiert: Auf Produktivdaten lag ein Stromvertrag
 * (2022-12-23 bis 2023-12-22) vollständig innerhalb eines anderen
 * (2021-09-25 bis 2025-11-30).
 *
 * Vier Services hängen an dieser Methode — ConsumptionService,
 * ForecastService, TariffComparisonService und TariffSwitchService —, die
 * Auflösung wirkt also auf Kosten, Prognose und Wechselentscheidung
 * gleichermaßen.
 */
#[CoversClass(ContractService::class)]
final class OverlappingContractsTest extends ServiceTestCase
{
    /** @return array<int,array<string,mixed>> */
    private function pair(): array
    {
        return [
            ['id' => 'aeltererLangerVertrag', 'start' => '2021-09-25', 'end' => '2025-11-30'],
            ['id' => 'neuererKurzerVertrag',  'start' => '2022-12-23', 'end' => '2023-12-22'],
        ];
    }

    public function testLaterStartWinsRegardlessOfArrayOrder(): void
    {
        $a = $this->pair();
        $b = array_reverse($a);

        // Ein Datum, an dem beide laufen.
        $datum = '2023-06-15';

        self::assertSame('neuererKurzerVertrag',
            $this->contracts->findActiveForDate($a, $datum)['id'],
            'der später begonnene Vertrag gilt');
        self::assertSame('neuererKurzerVertrag',
            $this->contracts->findActiveForDate($b, $datum)['id'],
            'und zwar unabhängig von der Reihenfolge im Array');
    }

    public function testOutsideTheOverlapTheRemainingContractStillApplies(): void
    {
        $cs = $this->pair();

        // Vor dem kurzen Vertrag läuft nur der lange.
        self::assertSame('aeltererLangerVertrag',
            $this->contracts->findActiveForDate($cs, '2022-01-10')['id']);
        // Nach seinem Ende ebenfalls.
        self::assertSame('aeltererLangerVertrag',
            $this->contracts->findActiveForDate($cs, '2024-06-01')['id']);
    }

    public function testWithoutOverlapNothingChanges(): void
    {
        $cs = [
            ['id' => 'erster',  'start' => '2024-01-01', 'end' => '2024-12-31'],
            ['id' => 'zweiter', 'start' => '2025-01-01', 'end' => '2025-12-31'],
        ];

        self::assertSame('erster',  $this->contracts->findActiveForDate($cs, '2024-06-01')['id']);
        self::assertSame('zweiter', $this->contracts->findActiveForDate($cs, '2025-06-01')['id']);
        self::assertNull($this->contracts->findActiveForDate($cs, '2023-06-01'));
        self::assertNull($this->contracts->findActiveForDate($cs, '2026-06-01'));
    }

    public function testOpenEndedContractStillMatchesAfterItsStart(): void
    {
        $cs = [
            ['id' => 'befristet',   'start' => '2024-01-01', 'end' => '2024-12-31'],
            ['id' => 'unbefristet', 'start' => '2025-01-01', 'end' => null],
        ];

        self::assertSame('unbefristet',
            $this->contracts->findActiveForDate($cs, '2030-01-01')['id']);
        self::assertSame('befristet',
            $this->contracts->findActiveForDate($cs, '2024-05-05')['id']);
    }

    /**
     * Die Kostenrechnung darf einen überlappenden Monat nicht doppelt
     * bewerten — sie fragt pro Monat genau einen Vertrag ab.
     */
    public function testCostsUseExactlyOneContractPerMonth(): void
    {
        $meterId = $this->setMeterDevices('strom', [[
            'id' => 'd1', 'serial' => null, 'installed_on' => '2023-01-01',
            'initial_counter' => 0.0, 'removed_on' => null,
            'final_counter' => null, 'reason' => null,
        ]]);
        $rows = [];
        for ($i = 0; $i <= 12; $i++) {
            $d = (new \DateTimeImmutable('2023-01-01'))->modify("+$i months");
            $rows[] = ['date' => $d->format('Y-m-d'), 'counter' => 1000 + $i * 300, 'device_id' => 'd1'];
        }
        $this->setReadings('strom', $meterId, $rows);

        $this->contracts->create('strom', [
            'meter_id' => $meterId, 'tariff_name' => 'lang',
            'start' => '2022-01-01', 'end' => '2025-12-31',
            'working_prices' => [['from' => '2022-01-01', 'ct_per_kwh' => 40.0]],
        ]);
        $this->contracts->create('strom', [
            'meter_id' => $meterId, 'tariff_name' => 'kurz',
            'start' => '2023-01-01', 'end' => '2023-12-31',
            'working_prices' => [['from' => '2023-01-01', 'ct_per_kwh' => 20.0]],
        ]);

        $meter   = $this->meters->get('strom', $meterId);
        $monthly = $this->consumption->forMeter('strom', $meter);
        $mit     = array_values(array_filter($monthly, fn($m) => ($m['kwh'] ?? 0) > 0));
        self::assertNotEmpty($mit);

        foreach ($mit as $m) {
            if (!isset($m['cost'], $m['kwh']) || $m['kwh'] <= 0) continue;
            $ct = $m['cost'] / $m['kwh'] * 100;
            // Entweder 20 oder 40 ct — nie die Summe beider Verträge.
            self::assertLessThan(45.0, $ct,
                "Monat {$m['ym']}: {$ct} ct/kWh — beide Verträge zugleich angesetzt?");
            self::assertEqualsWithDelta(20.0, $ct, 0.5,
                "Monat {$m['ym']}: der später begonnene Vertrag muss gelten");
        }
    }
}
