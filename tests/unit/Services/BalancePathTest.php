<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\ConsumptionService;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * v2.16.0 (Review FE-31) — Saldo-Verlauf: `balance_path` am laufenden Vertrag
 * ist dieselbe Rechnung wie `projected_end_balance`, nur als Monatsreihe.
 * Der letzte Punkt muss der erwartete Endsaldo sein — sonst zeigte das
 * Diagramm eine andere Abrechnung als die Karte darüber.
 *
 * Datumsrechnung vom Monatsersten aus (Lesson 26); „heute“ ist das echte Datum.
 */
#[CoversClass(ConsumptionService::class)]
final class BalancePathTest extends ServiceTestCase
{
    private string $meterId;
    private string $start;

    protected function setUp(): void
    {
        parent::setUp();
        $this->meterId = $this->setMeterDevices('strom', [[
            'id' => 'd1', 'serial' => null, 'installed_on' => '2020-01-01', 'initial_counter' => 0.0,
            'removed_on' => null, 'final_counter' => null, 'reason' => null,
        ]]);
        // Vertrag über zwölf Monate, begonnen vor acht — heute liegt mittendrin
        $this->start = date('Y-m-01', strtotime(date('Y-m-01') . ' -8 months'));
    }

    private function ym(string $date, int $months): string
    {
        return date('Y-m', strtotime(substr($date, 0, 7) . "-01 $months months"));
    }

    /** 100 kWh je Monat, Ablesung am Ersten, vom Vertragsbeginn bis vor zwei Monaten. */
    private function readingsUntilTwoMonthsAgo(): void
    {
        $rows = [];
        $counter = 0.0;
        $until = date('Y-m-01', strtotime(date('Y-m-01') . ' -2 months'));
        for ($t = strtotime($this->start); date('Y-m-d', $t) <= $until; $t = strtotime('+1 month', $t)) {
            $rows[] = ['date' => date('Y-m-d', $t), 'counter' => $counter, 'device_id' => 'd1'];
            $counter += 100.0;
        }
        $this->setReadings('strom', $this->meterId, $rows);
    }

    private function contract(array $extra = []): array
    {
        return $this->contracts->create('strom', $extra + [
            'meter_id' => $this->meterId, 'provider' => 'A', 'tariff_name' => 'T',
            'start' => $this->start,
            'end' => date('Y-m-d', strtotime($this->start . ' +12 months -1 day')),
            'working_prices'   => [['from' => $this->start, 'ct_per_kwh' => 30.0]],
            'base_prices'      => [['from' => $this->start, 'eur_per_month' => 10.0]],
            'advance_payments' => [['from' => $this->start, 'amount_eur' => 50.0]],
        ]);
    }

    private function state(string $id): array
    {
        $all = $this->consumption->contractStatus('strom', $this->meters->get('strom', $this->meterId))['contracts'];
        return array_column($all, null, 'contract_id')[$id];
    }

    public function testPathEndsAtTheProjectedBalance(): void
    {
        $c = $this->contract();
        $this->readingsUntilTwoMonthsAgo();
        $s = $this->state($c['id']);
        $path = $s['balance_path'];

        self::assertIsArray($path);
        self::assertCount(12, $path, 'ein Punkt je Vertragsmonat');
        self::assertSame(substr($this->start, 0, 7), $path[0]['ym']);
        $last = $path[count($path) - 1];
        self::assertEqualsWithDelta($s['projected_end_balance'], $last['balance'], 0.02,
            'Das Diagramm muss dieselbe Abrechnung zeigen wie die Karte');
        self::assertEqualsWithDelta($last['cost'] - $last['paid'], $last['balance'], 0.011);
        // Abschläge nach Plan: 50 € je Monat
        self::assertEqualsWithDelta(50.0, $path[1]['paid'] - $path[0]['paid'], 0.01);
        self::assertEqualsWithDelta(600.0, $last['paid'], 0.01);
        // Kosten eines gemessenen Monats: 100 kWh × 30 ct + 10 € Grundpreis
        self::assertEqualsWithDelta(40.0, $path[1]['cost'] - $path[0]['cost'], 0.01);
        // gemessen vorn, geschätzt ab der letzten Ablesung, künftig nach heute
        self::assertFalse($path[0]['estimated']);
        self::assertTrue($last['estimated']);
        self::assertFalse($path[0]['future']);
        self::assertTrue($last['future']);
        $current = array_values(array_filter($path, fn($p) => $p['ym'] === date('Y-m')));
        self::assertCount(1, $current);
        self::assertFalse($current[0]['future'], 'der laufende Monat gehört zu „bis heute“');
    }

    public function testARefundLowersWhatWasPaidInItsMonth(): void
    {
        $refundMonth = $this->ym($this->start, 2);
        $c = $this->contract(['special_payments' => [
            ['date' => $refundMonth . '-10', 'kind' => 'rueckzahlung_ohne', 'amount_eur' => 30.0],
        ]]);
        $this->readingsUntilTwoMonthsAgo();
        $s = $this->state($c['id']);
        $path = array_column($s['balance_path'], null, 'ym');
        $prev = $this->ym($this->start, 1);
        self::assertEqualsWithDelta(50.0 - 30.0, $path[$refundMonth]['paid'] - $path[$prev]['paid'], 0.01,
            'eine Rückzahlung vermindert das Bezahlte in ihrem Monat');
        $list = $s['balance_path'];
        self::assertEqualsWithDelta($s['projected_end_balance'], $list[count($list) - 1]['balance'], 0.02);
    }

    public function testOnlyTheCurrentContractCarriesAPath(): void
    {
        $pastStart = date('Y-m-01', strtotime($this->start . ' -12 months'));
        $past = $this->contracts->create('strom', [
            'meter_id' => $this->meterId, 'provider' => 'Alt', 'tariff_name' => 'T',
            'start' => $pastStart, 'end' => date('Y-m-d', strtotime($this->start . ' -1 day')),
            'working_prices'   => [['from' => $pastStart, 'ct_per_kwh' => 28.0]],
            'base_prices'      => [['from' => $pastStart, 'eur_per_month' => 9.0]],
            'advance_payments' => [['from' => $pastStart, 'amount_eur' => 45.0]],
        ]);
        $c = $this->contract();
        $this->readingsUntilTwoMonthsAgo();
        self::assertNull($this->state($past['id'])['balance_path'] ?? null);
        self::assertIsArray($this->state($c['id'])['balance_path']);
    }
}
