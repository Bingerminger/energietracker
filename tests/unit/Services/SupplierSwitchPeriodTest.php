<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\BillingPeriodService;
use Energietracker\Services\BillService;
use Energietracker\Services\ConsumptionService;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * v3.9.0 (Skeptiker-Lauf 3.9.0, M7) — jährlicher Lieferantenwechsel, Stichtag einen Monat vor
 * dem Wechseltag. Erfunden: drei Versorger, Verträge 01.03.2024–28.02.2025, 01.03.2025–28.02.2026,
 * ab 01.03.2026; Stichtag 01.02.; heute 15.01.2026.
 *
 * Bis dahin teilte der Stichtag die Lieferzeit: Periode 01.03.2025–31.01.2026 und ein Stummel
 * 01.02.–28.02.2026, „nächste Abrechnung“ am 31.01.2026, einen Monat vor dem Vertragsende. Der
 * Versorger rechnet aber zum Vertragsende ab (Schlussrechnung): eine Periode bis 28.02.2026.
 */
#[CoversClass(BillingPeriodService::class)]
#[CoversClass(ConsumptionService::class)]
final class SupplierSwitchPeriodTest extends ServiceTestCase
{
    private const TODAY = '2026-01-15';
    private string $meterId = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->meterId = $this->setMeterDevices('strom', [['id' => 'd_sw', 'serial' => null, 'installed_on' => '2024-03-01',
            'initial_counter' => 0.0, 'removed_on' => null, 'final_counter' => null, 'reason' => null]]);
        $this->settings->set(['billing_cycle_anchor_strom' => '02-01']);
        $rows = [];
        for ($i = 0; $i <= 22; $i++) $rows[] = ['date' => date('Y-m-d', (int)strtotime("2024-03-01 +$i months")), 'counter' => 250.0 * $i, 'device_id' => 'd_sw'];
        $this->setReadings('strom', $this->meterId, $rows);
        foreach ([['Erster Versorger', '2024-03-01', '2025-02-28'], ['Zweiter Versorger', '2025-03-01', '2026-02-28'], ['Dritter Versorger', '2026-03-01', null]] as [$p, $s, $e]) {
            $this->contracts->create('strom', ['meter_id' => $this->meterId, 'provider' => $p, 'start' => $s, 'end' => $e,
                'working_prices' => [['from' => $s, 'ct_per_kwh' => 30.0]], 'base_prices' => [['from' => $s, 'eur_per_month' => 10.0]],
                'advance_payments' => [['from' => $s, 'amount_eur' => 85.0]]]);
        }
    }

    private function periods(): array
    {
        $bills = new BillService($this->store, $this->meters, $this->contracts, $this->consumption, $this->i18n);
        return (new BillingPeriodService($this->settings, $this->meters, $this->readings, $this->consumption, $bills))
            ->forTarget('strom', $this->meters->get('strom', $this->meterId), false, self::TODAY);
    }

    public function testASupplyBetweenTwoSwitchesIsOnePeriodUntilTheContractEnd(): void
    {
        $spans = array_map(fn($p) => $p['from'] . '..' . $p['until'], $this->periods()['periods']);
        // die erste Lieferzeit beginnt mit den Daten (kein Wechsel davor): dort gilt der Stichtag weiter
        self::assertContains('2025-03-01..2026-02-28', $spans, 'eine Periode bis zum Vertragsende (Schlussrechnung)');
        self::assertNotContains('2026-02-01..2026-02-28', $spans, 'kein Stummel vor dem Wechsel');
        $card = array_values(array_filter($this->consumption->contractStatus('strom', $this->meters->get('strom', $this->meterId), self::TODAY)['contracts'],
            fn($c) => !empty($c['is_current'])))[0];
        self::assertSame('2026-02-28', $card['settle_on'], 'Abrechnung zum Vertragsende, nicht am 31.01.');
        self::assertSame(['2025-03-01', '2026-02-28'], [$card['billing_period']['from'], $card['billing_period']['until']]);
        // Abschläge bis zur Abrechnung: 15.01. bis 28.02. → Februar (der Januar ist heute schon bezahlt)
        self::assertSame(1, $card['months_left']);
        // Stichtag-Hinweis an der Karte: die Verträge beginnen am 01.03., der Stichtag steht auf dem 01.02.
        self::assertSame(['02-01', '03-01', 'contracts'], [$card['billing_period']['anchor'], $card['billing_period']['suggested_anchor'], $card['billing_period']['suggested_anchor_source']]);
    }

    public function testALongSupplyKeepsTheAnchor(): void
    {
        // ein Versorger drei Jahre lang (länger als SWITCH_PERIOD_MAX_DAYS): Jahresrhythmus nach dem Stichtag
        $all = $this->contracts->list('strom', $this->meterId);
        foreach ($all as $c) $this->contracts->delete('strom', $c['id']);
        $this->contracts->create('strom', ['meter_id' => $this->meterId, 'provider' => 'Erster Versorger', 'start' => '2024-03-01', 'end' => '2026-02-28',
            'working_prices' => [['from' => '2024-03-01', 'ct_per_kwh' => 30.0]], 'advance_payments' => [['from' => '2024-03-01', 'amount_eur' => 85.0]]]);
        $this->contracts->create('strom', ['meter_id' => $this->meterId, 'provider' => 'Zweiter Versorger', 'start' => '2026-03-01',
            'working_prices' => [['from' => '2026-03-01', 'ct_per_kwh' => 30.0]], 'advance_payments' => [['from' => '2026-03-01', 'amount_eur' => 85.0]]]);
        $spans = array_map(fn($p) => $p['from'] . '..' . $p['until'], $this->periods()['periods']);
        self::assertContains('2025-02-01..2026-01-31', $spans, 'Jahresperiode nach dem Stichtag');
        self::assertGreaterThan(BillingPeriodService::SWITCH_PERIOD_MAX_DAYS, (int)round((strtotime('2026-03-01') - strtotime('2024-03-01')) / 86400));
    }
}
