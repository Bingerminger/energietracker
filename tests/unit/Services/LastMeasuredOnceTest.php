<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\ConsumptionService;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * v3.13.0 — Der Tag der letzten echten Ablesung einmal je Zähler und Stichtag.
 *
 * Seit v3.12.0 (Skeptiker M6) fragt der Vertragsstatus für jeden Vertrag, bis wann echt gemessen ist, und las dafür
 * jedes Mal alle Ablesungen neu. Bei zehn Jahren Tageswerten mit einem Vertrag je Jahr war das über die Hälfte der
 * Druckansicht des Jahresberichts (in der CI einmal 4.328 ms gegen die Grenze von 4.170 ms) und fast die Hälfte der
 * Übersicht. Die Werte bleiben dieselben (Vergleich alt gegen neu an allen Beispielhaushalten, byte-gleich).
 *
 * Erfundener Haushalt: Strom ab 01.01.2020, 10 kWh am Tag, Ablesung am Monatsersten bis 01.06.2025, die letzte
 * als geschätzt erfasst; je Kalenderjahr ein Vertrag.
 */
#[CoversClass(ConsumptionService::class)]
final class LastMeasuredOnceTest extends ServiceTestCase
{
    public function testTheContractStatusAsksForTheLastRealReadingOncePerMeterAndStichtag(): void
    {
        $id = $this->setMeterDevices('strom', [['id' => 'd_a', 'serial' => null, 'installed_on' => '2020-01-01',
            'initial_counter' => 0.0, 'removed_on' => null, 'final_counter' => null, 'reason' => null]]);
        $rows = [];
        for ($t = strtotime('2020-01-01 12:00'), $c = 0.0; date('Y-m-d', $t) <= '2025-06-01'; $t = strtotime('+1 month', $t)) {
            $rows[] = ['date' => date('Y-m-d', $t), 'counter' => $c, 'device_id' => 'd_a', 'is_estimated' => date('Y-m-d', $t) === '2025-06-01'];
            $c += 10.0 * (int)date('t', $t);
        }
        $this->setReadings('strom', $id, $rows);
        foreach (range(2020, 2025) as $y) {
            $this->contracts->create('strom', ['meter_id' => $id, 'provider' => 'Strom Musterstadt', 'start' => "$y-01-01", 'end' => "$y-12-31",
                'working_prices' => [['from' => "$y-01-01", 'ct_per_kwh' => 30.0]], 'base_prices' => [['from' => "$y-01-01", 'eur_per_month' => 10.0]],
                'advance_payments' => [['from' => "$y-01-01", 'amount_eur' => 90]]]);
        }
        $meter = $this->meters->get('strom', $id);
        $memo = new \ReflectionProperty($this->consumption, 'lastMeasuredMemo');

        $status = $this->consumption->contractStatus('strom', $meter, '2025-06-20');
        self::assertCount(6, $status['contracts']);
        self::assertCount(1, $memo->getValue($this->consumption), 'sechs Verträge, ein Zähler, ein Stichtag: einmal gefragt');
        // gemessen bis zur letzten echten Ablesung (01.05.2025), nicht bis zur geschätzten vom 01.06.
        $cur = array_values(array_filter($status['contracts'], fn($c) => !empty($c['is_current'])))[0];
        self::assertSame('2025-05-01', $cur['balance_measured']['until'] ?? null);

        // ein anderer Stichtag fragt neu: am 15.03.2025 ist die letzte echte Ablesung die vom 01.03.
        $earlier = $this->consumption->contractStatus('strom', $meter, '2025-03-15');
        self::assertCount(2, $memo->getValue($this->consumption));
        $cur = array_values(array_filter($earlier['contracts'], fn($c) => !empty($c['is_current'])))[0];
        self::assertSame('2025-03-01', $cur['balance_measured']['until'] ?? null);
    }
}
