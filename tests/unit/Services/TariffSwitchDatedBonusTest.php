<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\ForecastService;
use Energietracker\Services\TariffSwitchService;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * v3.13.0 — Wechselentscheidung: Ein Bonus mit Datum an einem Angebot (`bonuses`, wie ihn der Vertragsdialog für jeden
 * Vertrag anbietet) zählt im ersten Jahr, wenn sein Tag darin liegt, genau wie derselbe Betrag als Neukundenbonus. Bis
 * dahin zählte nur `signup_bonus_eur`. Jahr 2 bleibt ohne Bonus (der dauerhafte Preis).
 *
 * Erfundener Haushalt: Strom 250 kWh in jedem Monat (Stände an jedem Monatsersten ab 01.01.2024), laufender Vertrag bis
 * 31.12.2026 mit einem Monat Frist → Wechsel 01.01.2027, Jahr 1 = 2027, Jahr 2 = 2028. Angebote: 28 ct und 9 €/Monat.
 * Von Hand: 12 × 250 kWh × 0,28 € + 12 × 9 € = 840 € + 108 € = 948 € je Jahr; mit 100 € Bonus im ersten Jahr 848 €.
 */
#[CoversClass(TariffSwitchService::class)]
final class TariffSwitchDatedBonusTest extends ServiceTestCase
{
    private TariffSwitchService $switch;
    private string $meterId = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->switch = new TariffSwitchService(new ForecastService($this->consumption, $this->regression, $this->settings, $this->contracts, $this->i18n),
            $this->contracts, $this->meters, $this->i18n);
        $this->meterId = $this->setMeterDevices('strom', [['id' => 'd_s', 'serial' => null, 'installed_on' => '2023-12-01',
            'initial_counter' => 0.0, 'removed_on' => null, 'final_counter' => null, 'reason' => null]]);
        $rows = []; $counter = 500.0;
        for ($d = new \DateTimeImmutable('2024-01-01'); $d <= new \DateTimeImmutable('2026-10-01'); $d = $d->modify('+1 month'), $counter += 250.0) {
            $rows[] = ['date' => $d->format('Y-m-d'), 'counter' => $counter, 'device_id' => 'd_s'];
        }
        $this->setReadings('strom', $this->meterId, $rows);
        $this->contracts->create('strom', ['meter_id' => $this->meterId, 'provider' => 'Stadtwerke Musterstadt', 'tariff_name' => 'Basis',
            'start' => '2026-01-01', 'end' => '2026-12-31', 'notice_period_months' => 1,
            'working_prices' => [['from' => '2026-01-01', 'ct_per_kwh' => 31.0]], 'base_prices' => [['from' => '2026-01-01', 'eur_per_month' => 11.0]]]);
    }

    private function offer(string $label, array $extra = []): void
    {
        $this->contracts->create('strom', ['meter_id' => $this->meterId, 'provider' => 'Anbieter', 'tariff_name' => $label,
            'start' => '2027-01-01', 'is_shadow' => true, 'shadow_label' => $label,
            'working_prices' => [['from' => '2027-01-01', 'ct_per_kwh' => 28.0]], 'base_prices' => [['from' => '2027-01-01', 'eur_per_month' => 9.0]]] + $extra);
    }

    private function cand(string $label): array
    {
        $r = $this->switch->analyze('strom', $this->meterId, ['today' => '2026-10-15']);
        self::assertSame('2027-01-01', $r['switch_date']);
        return array_values(array_filter($r['candidates'], fn($c) => $c['label'] === $label))[0];
    }

    public function testADatedBonusCountsLikeASignupBonus(): void
    {
        $this->offer('Neukunde', ['signup_bonus_eur' => 100.0]);
        $this->offer('Datiert', ['bonuses' => [['credit_date' => '2027-04-15', 'amount_eur' => 100.0, 'kind' => 'switch', 'note' => '']]]);
        $a = $this->cand('Neukunde'); $b = $this->cand('Datiert');
        self::assertEqualsWithDelta(848.0, $a['year1_eur'], 0.5, 'von Hand: 948 € − 100 €');
        self::assertSame($a['year1_eur'], $b['year1_eur'], 'derselbe Betrag, gleich welches Feld ihn trägt');
        self::assertSame($a['year2_eur'], $b['year2_eur']);
        self::assertSame(100.0, $b['bonus_year1_eur']);
        self::assertNull($b['signup_bonus_eur'], 'der Neukundenbonus bleibt das eingetragene Feld');
    }

    /**
     * Tag des Bonus gegen das erste Jahr (01.01.2027 bis 31.12.2027): am ersten und am letzten Tag zählt er, am Tag davor und
     * im 13. Monat nicht (Jahr 2 bleibt der dauerhafte Preis).
     *
     * @return array<string, array{string, float}>
     */
    public static function days(): array
    {
        return [
            'erster Tag des ersten Jahres' => ['2027-01-01', 848.0],
            'letzter Tag des ersten Jahres' => ['2027-12-31', 848.0],
            'im 14. Monat'                  => ['2028-02-15', 948.0],
        ];
    }

    /** Ein Angebot mit Laufzeit bis 31.12.2027 und Bonus im 14. Monat: Der Tag zählt, nicht das Ende des Angebots (Jahr 1 948 €). */
    public function testAnOfferWithAnEndKeepsTheBonusDay(): void
    {
        $this->offer('Befristet', ['end' => '2027-12-31', 'bonuses' => [['credit_date' => '2028-02-15', 'amount_eur' => 100.0, 'kind' => 'switch', 'note' => '']]]);
        self::assertEqualsWithDelta(948.0, $this->cand('Befristet')['year1_eur'], 0.5);
    }

    #[DataProvider('days')]
    public function testTheDayDecides(string $day, float $year1): void
    {
        $this->offer('Datiert', ['bonuses' => [['credit_date' => $day, 'amount_eur' => 100.0, 'kind' => 'switch', 'note' => '']]]);
        $c = $this->cand('Datiert');
        self::assertEqualsWithDelta($year1, $c['year1_eur'], 0.5);
        self::assertEqualsWithDelta(948.0, $c['year2_eur'], 0.5, 'Jahr 2 ohne Bonus');
    }
}
