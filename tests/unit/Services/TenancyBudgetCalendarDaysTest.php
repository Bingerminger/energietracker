<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\PeriodService;
use Energietracker\Services\TenancyBudgetService;
use Energietracker\Services\TenancyService;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * v3.12.0 — Das Budget eines Mietverhältnisses zählt die Tage eines Monats als Kalendertage. Bis v3.11 zählte es
 * Sekunden ÷ 86.400 und schnitt ab: In einer Zeitzone mit Sommerzeit hat der März 30 Tage und 23 Stunden, das
 * Budget nahm ihn mit 30 von 31 Tagen (Vorauszahlung, Verbrauch und feste Kosten des März zu niedrig).
 *
 * Erfundener Haushalt, Zeitzone Europe/Berlin (Umstellung am 29.03.2026): Heizwärme 400 kWh je Monat zu 0,20 €/kWh,
 * Vorauszahlung 90 € Heizung + 30 € Betriebskosten je Monat, Periode 2026 (Stichtag 01.01.). Von Hand:
 *   jeder Monat ganz (Anteil 1): März 400 kWh × 0,20 € = 80,00 €, Vorauszahlung 90 + 30 = 120,00 €
 *   Jahr: erwartet 12 × 80 = 960,00 €, vorausgezahlt 12 × 120 = 1.440,00 €, Ergebnis 960 − 1.440 = −480,00 €
 *   der alte Weg im März: Anteil 30/31 → 116,13 € Vorauszahlung, 77,42 € Heizung, Jahr 1.436,13 € / 957,42 €
 */
#[CoversClass(TenancyBudgetService::class)]
final class TenancyBudgetCalendarDaysTest extends ServiceTestCase
{
    private string $tz = 'UTC';

    protected function setUp(): void
    {
        parent::setUp();
        $this->tz = date_default_timezone_get();
        date_default_timezone_set('Europe/Berlin');
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->tz);
        parent::tearDown();
    }

    /** @return array<string,mixed> */
    private function budget(): array
    {
        $ten = new TenancyService($this->store, $this->meters, $this->i18n);
        $periods = new PeriodService($this->store, $this->meters, $this->i18n);
        $m = (string)$this->meters->create('waerme', ['name' => 'Heizkostenverteiler', 'capture' => 'period', 'installed_on' => '2026-01-01'])['id'];
        for ($mo = 1; $mo <= 12; $mo++) {
            $periods->create('waerme', ['meter_id' => $m, 'month' => sprintf('2026-%02d', $mo), 'value' => 400]);
        }
        $t = $ten->create([
            'start' => '2025-01-01', 'billing_anchor' => '01-01',
            'prepayments' => [['from' => '2025-01-01', 'heating_eur_month' => 90, 'operating_eur_month' => 30]],
            'prices' => [['from' => '2025-01-01', 'heat_eur_per_kwh' => 0.20]],
            'meter_ids' => ['heat' => [$m]],
        ]);
        return (new TenancyBudgetService($ten, $this->meters, $this->consumption, $this->i18n))->budget((string)$t['id'], '2026-12-31');
    }

    public function testMarchCountsAllItsDaysInTheSummerTimeZone(): void
    {
        $b = $this->budget();
        $march = array_values(array_filter($b['months'], fn($m) => $m['ym'] === '2026-03'))[0];
        self::assertSame(1.0, (float)$march['share'], 'März ganz: 31 von 31 Tagen');
        self::assertEqualsWithDelta(120.0, (float)$march['prepaid_eur'], 0.005, 'von Hand: 90 + 30');
        self::assertEqualsWithDelta(80.0, (float)$march['expected_eur'], 0.005, 'von Hand: 400 kWh × 0,20 €');
    }

    public function testTheYearAddsUpToTwelveWholeMonths(): void
    {
        $b = $this->budget();
        foreach ($b['months'] as $m) self::assertSame(1.0, (float)$m['share'], $m['ym'] . ' liegt mitten in der Periode');
        self::assertEqualsWithDelta(1440.0, (float)$b['prepaid_eur'], 0.005, 'von Hand: 12 × 120 €');
        self::assertEqualsWithDelta(960.0, (float)$b['expected_eur'], 0.005, 'von Hand: 12 × 80 €');
        self::assertEqualsWithDelta(-480.0, (float)$b['projected_result_eur'], 0.005, 'von Hand: 960 − 1.440');
    }
}
