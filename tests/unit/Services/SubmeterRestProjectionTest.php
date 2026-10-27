<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\BillingPeriodService;
use Energietracker\Services\BillService;
use Energietracker\Services\ConsumptionService;
use Energietracker\Services\ForecastService;
use Energietracker\Services\ScenarioService;
use Energietracker\Services\TariffSwitchService;
use Energietracker\Tests\Support\ReportFixture;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * v3.9.0 (F1032, #40, nach dem Merge mit Paket auswertung) — Die Rest-Abrechnung
 * greift in der EINEN Projektion über die laufende Abrechnungsperiode: Saldo-Karte,
 * Werkstatt und Jahresbericht („Wohin geht mein Geld?“) zeigen denselben Saldo des
 * Elternvertrags, gerechnet auf den Rest.
 *
 * Erfundene Werte: Elternzähler 500 kWh, Subzähler (Wärmepumpe) 200 kWh im Monat,
 * Stände am Ersten bis 01.10.2026, heute 26.10.2026, Stichtag 01.01. Elternvertrag
 * 20 ct und 70 €/Monat Abschlag, Subzähler 40 ct und 80 €/Monat, beide seit
 * 01.01.2025. Laufende Periode 01.01. bis 31.12.2026:
 *   Elternvertrag  Rest 300 kWh × 12 × 0,20 € = 720 €, Abschläge 12 × 70 € = 840 €
 *                  → 120 € Guthaben (brutto wären es 1.200 − 840 = 360 € Nachzahlung)
 */
#[CoversClass(ConsumptionService::class)]
#[CoversClass(ScenarioService::class)]
final class SubmeterRestProjectionTest extends ServiceTestCase
{
    use ReportFixture;

    private const TODAY = '2026-10-26';

    private function household(bool $subContract): array
    {
        $this->store->write('strom/meters.json', []);
        $this->store->write('strom/contracts.json', []);
        $this->store->write('gas/meters.json', []);
        $this->store->write('wasser/meters.json', []);
        $parent = $this->meters->create('strom', ['name' => 'Hausanschluss', 'installed_on' => '2025-01-01']);
        $sub = $this->meters->create('strom', ['name' => 'Wärmepumpe', 'installed_on' => '2025-01-01', 'parent_meter_id' => (string)$parent['id']]);
        $all = [];
        foreach ([[$parent, 500.0], [$sub, 200.0]] as [$m, $per]) {
            for ($i = 0; $i <= 21; $i++) {   // 01.01.2025 bis 01.10.2026
                $all[] = ['id' => "r_{$m['id']}_$i", 'meter_id' => $m['id'], 'device_id' => $m['devices'][0]['id'],
                          'date' => date('Y-m-d', (int)strtotime("2025-01-01 +$i months")), 'counter' => 1000.0 + $per * $i,
                          'price_cents' => null, 'note' => '', 'is_estimated' => false, 'is_future' => false];
            }
        }
        $this->store->write('strom/readings.json', $all);
        $mk = fn(array $m, float $ct, float $adv) => $this->contracts->create('strom', ['meter_id' => (string)$m['id'], 'provider' => 'Versorger ' . $m['name'],
            'start' => '2025-01-01', 'working_prices' => [['from' => '2025-01-01', 'ct_per_kwh' => $ct]],
            'advance_payments' => [['from' => '2025-01-01', 'amount_eur' => $adv]]]);
        $pc = $mk($parent, 20.0, 70.0);
        if ($subContract) $mk($sub, 40.0, 80.0);
        return [$this->meters->get('strom', (string)$parent['id']), $pc];
    }

    private function scenario(): ScenarioService
    {
        $f = new ForecastService($this->consumption, $this->regression, $this->settings, $this->contracts, $this->i18n);
        $bills = new BillService($this->store, $this->meters, $this->contracts, $this->consumption, $this->i18n);
        return new ScenarioService($f, $this->consumption, $this->contracts, $this->meters, $this->settings,
            new TariffSwitchService($f, $this->contracts, $this->meters, $this->i18n),
            new BillingPeriodService($this->settings, $this->meters, $this->readings, $this->consumption, $bills));
    }

    private function card(array $parent, array $pc): array
    {
        foreach ($this->consumption->contractStatus('strom', $parent, self::TODAY)['contracts'] as $c) if ($c['contract_id'] === $pc['id']) return $c;
        self::fail('Elternvertrag fehlt');
    }

    public function testCardWorkshopAndReportShowTheParentBalanceOnTheRest(): void
    {
        [$parent, $pc] = $this->household(false);
        $gross = $this->card($parent, $pc);
        [$parent, $pc] = $this->household(true);
        $card = $this->card($parent, $pc);

        // laufende Periode 2026, Abschläge Januar bis Oktober
        self::assertSame('2026-01-01', $card['balance_from']);
        self::assertSame('2026-12-31', $card['settle_on']);
        self::assertEqualsWithDelta(700.0, $card['advance_paid'], 0.01);
        // gemessen bis 01.10.: 9 Monate Rest × 300 kWh = 2.700 kWh, 540 €
        self::assertEqualsWithDelta(2700.0, $card['rest_of']['rest'] ?? 0, 0.5, 'Hinweis rest_of in der Periode');
        self::assertEqualsWithDelta(4500.0, $card['rest_of']['measured'] ?? 0, 0.5);
        $measured = (float)$card['energy_cost_to_date'] - (float)$card['estimated_cost_to_date'];
        self::assertEqualsWithDelta(540.0, $measured, 0.5, 'gemessene Arbeitskosten auf den Rest');
        // Ende der Periode: 720 € Kosten gegen 840 € Abschläge
        self::assertEqualsWithDelta(-120.0, $card['projected_end_balance'], 0.5, '120 € Guthaben, nicht 360 € Nachzahlung');
        self::assertEqualsWithDelta(360.0, $gross['projected_end_balance'], 0.5, 'ohne Subzählervertrag brutto');

        // die Werkstatt übernimmt die Karte
        $r = $this->scenario()->compute(['utility' => 'strom', 'meter_id' => $parent['id']], self::TODAY);
        self::assertSame('contract_status', $r['billing']['source']);
        self::assertSame($card['projected_end_balance'], $r['billing']['base_end_balance']);
        self::assertSame($card['current_balance'], $r['billing']['balance_so_far']);

        // der Jahresbericht liest dieselbe Projektion
        $m = $this->reportServices()['model']->build(2025, ['today' => self::TODAY]);
        $row = array_values(array_filter($m['money']['balances'] ?? [], fn($b) => $b['meter_name'] === 'Hausanschluss'))[0] ?? null;
        self::assertNotNull($row, 'Saldo des Elternvertrags im Bericht');
        self::assertEqualsWithDelta($card['projected_end_balance'], $row['explainer']['projected_end_balance'], 0.001);
        self::assertSame('2026-12-31', $row['settle_on']);
    }
}
