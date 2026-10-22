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
 * Der Fall ist praxisrelevant und nicht konstruiert: Ein Vertrag, der
 * vollständig innerhalb der Laufzeit eines anderen liegt, entsteht leicht
 * beim Nachtragen älterer Verträge.
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
            ['id' => 'aeltererLangerVertrag', 'start' => '2020-01-01', 'end' => '2024-12-31'],
            ['id' => 'neuererKurzerVertrag',  'start' => '2021-06-01', 'end' => '2022-05-31'],
        ];
    }

    public function testLaterStartWinsRegardlessOfArrayOrder(): void
    {
        $a = $this->pair();
        $b = array_reverse($a);

        // Ein Datum, an dem beide laufen.
        $datum = '2021-12-15';

        self::assertSame('neuererKurzerVertrag',
            $this->contracts->findActiveForDate($a, $datum)['id'],
            'der später begonnene Vertrag gilt');
        self::assertSame('neuererKurzerVertrag',
            $this->contracts->findActiveForDate($b, $datum)['id'],
            'und zwar unabhängig von der Reihenfolge im Array');
    }

    /**
     * v3.7.0 (wirklichkeit-2, Schritt 3) — Vor dem kurzen Vertrag läuft nur der lange.
     * Beginnt der kurze während der Laufzeit des langen, löst er ihn ab: Der lange
     * endet am Vortag (ContractService::supersededOn), auch wenn der kurze ganz in
     * ihm liegt. Nach dem Ende des kurzen läuft deshalb nicht wieder der lange,
     * sondern der kurze verlängert sich (§ 309 Nr. 9 BGB, resolveForDate). Bis v3.6
     * galt danach wieder der lange — bei echten Daten liefen so die Abschläge des
     * alten Vertrags weiter.
     */
    public function testOutsideTheOverlapTheRemainingContractStillApplies(): void
    {
        $cs = $this->pair();

        self::assertSame('aeltererLangerVertrag',
            $this->contracts->findActiveForDate($cs, '2020-07-10')['id']);
        self::assertSame('2021-05-31', ContractService::supersededOn($cs[0], $cs));
        self::assertNull($this->contracts->findActiveForDate($cs, '2023-06-01'), 'der lange ist abgelöst');
        self::assertSame(['neuererKurzerVertrag', true], [
            $this->contracts->resolveForDate($cs, '2023-06-01')['contract']['id'] ?? null,
            $this->contracts->resolveForDate($cs, '2023-06-01')['assumed'] ?? null,
        ], 'der kurze läuft weiter');
    }

    /** Andere Zähler oder Gruppen (Doppelbelieferung) lösen sich nicht ab. */
    public function testContractsOfDifferentMetersDoNotSupersedeEachOther(): void
    {
        $cs = [
            ['id' => 'a', 'meter_id' => 'm1', 'start' => '2024-01-01', 'end' => null],
            ['id' => 'b', 'meter_id' => 'm2', 'start' => '2024-06-01', 'end' => null],
            ['id' => 'g', 'meter_group_id' => 'grp', 'meter_id' => 'm1', 'start' => '2024-09-01', 'end' => null],
            ['id' => 's', 'meter_id' => 'm1', 'start' => '2024-03-01', 'end' => null, 'is_shadow' => true],
        ];
        self::assertNull(ContractService::supersededOn($cs[0], $cs), 'Zähler m2, Gruppe und Schattenvertrag lösen nichts ab');
        $cs[] = ['id' => 'c', 'meter_id' => 'm1', 'start' => '2025-01-01', 'end' => '2025-06-30'];
        self::assertSame('2024-12-31', ContractService::supersededOn($cs[0], $cs), 'derselbe Zähler: abgelöst, auch durch einen kurzen Vertrag');
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
