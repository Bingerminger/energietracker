<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\ForecastService;
use Energietracker\Services\TariffSwitchService;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * v3.12.0 — Wechselentscheidung tagesgenau: Eine Preisstufe am 15. gilt ab diesem Tag, im ersten wie im zweiten Jahr,
 * und ein Grundpreis ab dem 15. tagesanteilig, wie in der Prognose und auf der Rechnung. Bis dahin galt je Fenstermonat
 * der Preis des Monatsersten; eine Erhöhung zum 15. erschien erst im Folgemonat, Jahr 1 und Jahr 2 lagen zu niedrig.
 *
 * Erfundener Haushalt: Strom nach Saisonprofil, laufender Vertrag 30 ct + 12 €/Monat bis 31.12.2026, ein Monat Frist
 * → Wechsel 01.01.2027. Angebot: 20 ct ab 2026, 26 ct ab 15.06.2027, 32 ct ab 15.01.2028; Grundpreis 10 €/Monat,
 * ab 15.06.2027 13 €/Monat. Von Hand mit q = erwartete Menge des Monats (gleich je Tag):
 *   Juni 2027 (30 Tage):    Preis (14 × 20 + 16 × 26) / 30 = 696 / 30 = 23,2 ct; Grundpreis (14 × 10 + 16 × 13) / 30
 *                           = 348 / 30 = 11,60 € → Kosten q × 0,232 + 11,60 € (bisher q × 0,20 + 10 €)
 *   Januar 2028 (31 Tage):  Preis (14 × 26 + 17 × 32) / 31 = 908 / 31 = 29,290323 ct; Grundpreis 13 €
 *                           → Kosten q × 0,29290323 + 13 € (bisher q × 0,26 + 13 €)
 */
#[CoversClass(TariffSwitchService::class)]
final class TariffSwitchByDayTest extends ServiceTestCase
{
    private const PROFILE = [
        1 => 640, 2 => 580, 3 => 520, 4 => 410, 5 => 300, 6 => 240,
        7 => 210, 8 => 230, 9 => 290, 10 => 400, 11 => 520, 12 => 610,
    ];

    private TariffSwitchService $switch;
    private string $meterId = '';

    protected function setUp(): void
    {
        parent::setUp();
        $forecast = new ForecastService($this->consumption, $this->regression, $this->settings, $this->contracts, $this->i18n);
        $this->switch = new TariffSwitchService($forecast, $this->contracts, $this->meters, $this->i18n);
        $this->meterId = $this->setMeterDevices('strom', [['id' => 'd_t', 'serial' => null, 'installed_on' => '2023-01-01',
            'initial_counter' => 0.0, 'removed_on' => null, 'final_counter' => null, 'reason' => null]]);
        $rows = []; $counter = 500.0;
        $cur = new \DateTimeImmutable('2024-01-01');
        $rows[] = ['date' => $cur->format('Y-m-d'), 'counter' => $counter, 'device_id' => 'd_t'];
        while ($cur < new \DateTimeImmutable('2026-07-01')) {
            $counter += self::PROFILE[(int)$cur->format('n')];
            $cur = $cur->modify('+1 month');
            $rows[] = ['date' => $cur->format('Y-m-d'), 'counter' => $counter, 'device_id' => 'd_t'];
        }
        $this->setReadings('strom', $this->meterId, $rows);
        $this->contracts->create('strom', ['meter_id' => $this->meterId, 'provider' => 'Stadtwerke', 'tariff_name' => 'Basis',
            'start' => '2026-01-01', 'end' => '2026-12-31', 'notice_period_months' => 1,
            'working_prices' => [['from' => '2026-01-01', 'ct_per_kwh' => 30.0]], 'base_prices' => [['from' => '2026-01-01', 'eur_per_month' => 12.0]]]);
        $this->contracts->create('strom', ['meter_id' => $this->meterId, 'provider' => 'Anbieter', 'tariff_name' => 'Stufen',
            'start' => '2026-01-01', 'is_shadow' => true, 'shadow_label' => 'Stufen',
            'working_prices' => [['from' => '2026-01-01', 'ct_per_kwh' => 20.0], ['from' => '2027-06-15', 'ct_per_kwh' => 26.0],
                                 ['from' => '2028-01-15', 'ct_per_kwh' => 32.0]],
            'base_prices' => [['from' => '2026-01-01', 'eur_per_month' => 10.0], ['from' => '2027-06-15', 'eur_per_month' => 13.0]]]);
    }

    /** @return array<string,mixed> */
    private function offer(): array
    {
        $r = $this->switch->analyze('strom', $this->meterId, ['today' => '2026-08-03']);
        self::assertSame('2027-01-01', $r['switch_date']);
        return array_values(array_filter($r['candidates'], fn($c) => $c['label'] === 'Stufen'))[0];
    }

    public function testAStepOnTheFifteenthCountsFromThatDayInYearOne(): void
    {
        $june = array_column($this->offer()['monthly'], null, 'ym')['2027-06'];
        $q = (float)$june['consumption'];
        self::assertGreaterThan(0.0, $q);
        self::assertEqualsWithDelta(23.2, $june['working_price_ct'], 1e-4, '(14 × 20 + 16 × 26) / 30');
        self::assertEqualsWithDelta(round($q * 0.232 + 11.60, 2), $june['cost'], 0.011, 'Grundpreis tagesanteilig: 348 / 30');
    }

    public function testAStepOnTheFifteenthCountsFromThatDayInYearTwo(): void
    {
        $o = $this->offer();
        $jan = array_column($o['monthly_year2'], null, 'ym')['2028-01'];
        $q = (float)$jan['consumption'];
        self::assertEqualsWithDelta(29.290323, $jan['working_price_ct'], 1e-4, '(14 × 26 + 17 × 32) / 31');
        self::assertEqualsWithDelta(round($q * 0.29290323 + 13.0, 2), $jan['cost'], 0.011);
        $sum = array_sum(array_map(fn($m) => (float)$m['cost'], $o['monthly_year2']));
        self::assertEqualsWithDelta($sum, $o['year2_eur'], 0.06, 'Jahr 2 ist die Summe seiner Monate');
    }

    /** Die Prognose-Werkstatt bewertet „Wechsel zum Angebot“ mit demselben Monatspreis. */
    public function testTheWorkshopOfferPriceIsTheSameMonthPrice(): void
    {
        $offer = array_values(array_filter($this->contracts->list('strom', $this->meterId), fn($c) => !empty($c['is_shadow'])))[0];
        self::assertEqualsWithDelta(23.2, $this->switch->offerPrice($offer, 'working_prices', 'ct_per_kwh', 2027, 6), 1e-4);
        self::assertEqualsWithDelta(11.6, $this->switch->offerPrice($offer, 'base_prices', 'eur_per_month', 2027, 6), 1e-4);
        self::assertEqualsWithDelta(20.0, $this->switch->offerPrice($offer, 'working_prices', 'ct_per_kwh', 2027, 5), 1e-4);
    }
}
