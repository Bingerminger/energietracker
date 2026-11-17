<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\ForecastService;
use Energietracker\Tests\Support\ReportFixture;
use Energietracker\Tests\Support\ServiceTestCase;

/**
 * v3.13.0 (Paket K1) — Jahresbericht „Erwartete Kosten“ mit einem Subzähler, der nach einem eigenen Vertrag rechnet,
 * ohne Gruppe. Der Hauptzähler rechnet dann nur den Rest (Prognose: Abzug des Subzählers an den Tagen seines Vertrags,
 * ForecastService::deductionForecast); bis v3.12 fehlte das Geld des Subzählers in den erwarteten Kosten ganz.
 *
 * Erfundener Haushalt, Monatserste 2023-01-01 bis 2026-01-01, Stichtag 15.01.2026 (Prognose 2026, 365 Tage):
 * Hauptzähler 10 kWh am Tag (mit dem Subzähler), Subzähler 3 kWh am Tag. Hauptvertrag 30 ct, 10 € im Monat.
 *
 * Orakel außerhalb des Modells: Rechnet der Subzähler zum selben Preis ohne Grundpreis, zahlt der Haushalt dasselbe
 * wie ohne seinen Vertrag, egal wann der Vertrag endet. Dazu ein Fall von Hand mit anderem Preis, Grundpreis und
 * einem Ende mitten im Monat.
 */
final class ReportSubmeterOwnContractTest extends ServiceTestCase
{
    use ReportFixture;

    private const TODAY = '2026-01-15';

    /** @param array<string,mixed>|null $sub Vertrag des Subzählers (Preise, Ende) oder null ohne Vertrag */
    private function household(?array $sub): void
    {
        $dev = fn(string $id) => [['id' => "d_$id", 'serial' => null, 'installed_on' => '2023-01-01', 'initial_counter' => 0.0,
            'removed_on' => null, 'final_counter' => null, 'reason' => null]];
        $meter = fn(string $id, string $name, array $extra = []) => ['id' => $id, 'name' => $name, 'icon' => '', 'created_at' => '2023-01-01',
            'active' => true, 'notes' => '', 'devices' => $dev($id)] + $extra;
        $this->store->write('strom/meters.json', [$meter('m_strom_haus', 'Haus'), $meter('m_strom_werkstatt', 'Werkstatt', ['parent_meter_id' => 'm_strom_haus'])]);
        $rows = [];
        foreach (['m_strom_haus' => 10.0, 'm_strom_werkstatt' => 3.0] as $id => $rate) {
            $c = 0.0; $i = 0;
            $d = new \DateTimeImmutable('2023-01-01');
            while (true) {
                $rows[] = ['id' => sprintf('r_%s_%03d', $id, $i++), 'meter_id' => $id, 'date' => $d->format('Y-m-d'), 'counter' => round($c, 3),
                    'device_id' => "d_$id", 'price_cents' => null, 'note' => '', 'is_estimated' => false, 'is_future' => false];
                if ($d->format('Y-m-d') === '2026-01-01') break;
                $c += $rate * (int)$d->format('t');
                $d = $d->modify('first day of next month');
            }
        }
        $this->store->write('strom/readings.json', $rows);
        $base = ['provider' => 'Musterwerk', 'start' => '2023-01-01', 'end' => null, 'auto_renews' => false, 'notes' => '',
                 'base_prices' => [], 'advance_payments' => [], 'bonuses' => [], 'special_payments' => [], 'is_shadow' => false];
        $contracts = [['id' => 'c_strom_haus', 'meter_id' => 'm_strom_haus', 'tariff_name' => 'Haus',
            'working_prices' => [['from' => '2023-01-01', 'ct_per_kwh' => 30.0]], 'base_prices' => [['from' => '2023-01-01', 'eur_per_month' => 10.0]],
            'advance_payments' => [['from' => '2023-01-01', 'amount_eur' => 100.0]]] + $base];
        if ($sub !== null) $contracts[] = $sub + ['id' => 'c_strom_werkstatt', 'meter_id' => 'm_strom_werkstatt', 'tariff_name' => 'Werkstatt'] + $base;
        $this->store->write('strom/contracts.json', $contracts);
    }

    private function expectedCost(): float
    {
        // Bericht über 2025 (das Jahr mit Kosten), erwartet werden die zwölf Monate ab dem Stichtag
        $model = $this->reportServices()['model']->build(2025, ['today' => self::TODAY, 'utilities' => ['strom']]);
        return (float)($model['money']['forecast']['cost'] ?? 0.0);
    }

    /** @return list<float> ein Wert je Fall: ohne Vertrag des Subzählers und mit, zum selben Preis ohne Grundpreis */
    private function samePrice(?string $end, bool $renews = false): array
    {
        $this->household(null);
        $without = $this->expectedCost();
        $this->household(['working_prices' => [['from' => '2023-01-01', 'ct_per_kwh' => 30.0]], 'end' => $end, 'auto_renews' => $renews]);
        return [$without, $this->expectedCost()];
    }

    /** Ohne Vertrag des Subzählers rechnet der Hauptvertrag alles: 3.650 kWh × 30 ct + 12 × 10 € = 1.215,00 €. */
    public function testWithoutOwnContractTheMainContractBillsEverything(): void
    {
        $this->household(null);
        self::assertEqualsWithDelta(1215.00, $this->expectedCost(), 0.05);
    }

    /** Vertrag des Subzählers über das ganze Jahr (läuft weiter): dieselben Kosten wie ohne ihn. Bis v3.12 fehlten 328,50 €. */
    public function testSamePriceRunningContractCostsTheSameAsWithoutIt(): void
    {
        [$without, $with] = $this->samePrice(null);
        self::assertEqualsWithDelta(1215.00, $without, 0.05);
        self::assertEqualsWithDelta($without, $with, 0.05, 'Hauptzähler (Rest) + Subzähler = alles zum selben Preis');
    }

    /**
     * Vertrag des Subzählers endet am 31.03.2026 ohne Verlängerung: Ab April rechnet wieder der Hauptvertrag die ganze
     * Menge. Die Prognose des Subzählers rechnet die Monate danach zum letzten Preis weiter; zählten sie mit, stünde
     * die Werkstatt ab April doppelt im Bericht (275 Tage × 3 kWh × 30 ct = 247,50 € zu viel).
     */
    public function testSamePriceEndingContractCostsTheSameAsWithoutIt(): void
    {
        [$without, $with] = $this->samePrice('2026-03-31');
        self::assertEqualsWithDelta($without, $with, 0.05);
    }

    /** Ende mitten im Monat (15.03.2026): nur die Vertragstage des Subzählers zählen bei ihm, der Rest beim Hauptzähler. */
    public function testSamePriceContractEndingMidMonthCostsTheSameAsWithoutIt(): void
    {
        [$without, $with] = $this->samePrice('2026-03-15');
        self::assertEqualsWithDelta($without, $with, 0.05);
    }

    /**
     * Von Hand: Werkstatt 25 ct und 5 € im Monat, Vertrag bis 15.03.2026 (ohne Verlängerung).
     *   Werkstatt: Januar 93 kWh → 23,25 € + 5,00 €, Februar 84 kWh → 21,00 € + 5,00 €,
     *              März 15 Tage 45 kWh → 11,25 € + 5 € × 15/31 = 2,42 €; zusammen 67,92 €
     *   Haus:      3.650 kWh − (93 + 84 + 45) kWh = 3.428 kWh × 30 ct = 1.028,40 € + 120,00 € = 1.148,40 €
     *   erwartet:  1.216,32 €
     */
    public function testHandCalculatedOwnContractEndingMidMonth(): void
    {
        $this->household(['working_prices' => [['from' => '2023-01-01', 'ct_per_kwh' => 25.0]],
            'base_prices' => [['from' => '2023-01-01', 'eur_per_month' => 5.0]], 'end' => '2026-03-15']);
        $fc = new ForecastService($this->consumption, $this->regression, $this->settings, $this->contracts, $this->i18n);
        $main = $this->consumption->atDate(self::TODAY, fn() => array_sum(array_map(fn($r) => (float)$r['cost_estimated'],
            array_slice($fc->forMeter('strom', $this->meters->get('strom', 'm_strom_haus'))['forecast'], 0, 12))));
        self::assertEqualsWithDelta(1148.40, $main, 0.05, 'Hauptzähler rechnet den Rest');
        self::assertEqualsWithDelta(1216.32, $this->expectedCost(), 0.05, 'Haus 1.148,40 € + Werkstatt 67,92 €');
    }
}
