<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\ContractService;
use Energietracker\Services\FlexibilityService;
use Energietracker\Services\IntervalService;
use Energietracker\Services\MarketPriceService;
use Energietracker\Support\LocalizedException;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * v3.3.0 (F1021) — Flexibilität bewerten, von Hand nachgerechnet.
 */
#[CoversClass(FlexibilityService::class)]
final class FlexibilityTest extends ServiceTestCase
{
    private IntervalService $iv;

    protected function setUp(): void
    {
        parent::setUp();
        $this->iv = new IntervalService($this->store, $this->settings);
    }

    private function flex(): FlexibilityService
    {
        return new FlexibilityService($this->iv, $this->meters, $this->contracts);
    }

    private function at(string $local): int
    {
        return (new \DateTimeImmutable($local, new \DateTimeZone('Europe/Berlin')))->getTimestamp();
    }

    /** Januar 2025: Preise abwechselnd 5 und 15 ct (Mittel 10), dazu zwei Viertelstunden mit −2 ct. */
    private function januaryPrices(): void
    {
        $p = [];
        for ($t = $this->at('2025-01-01 00:00'); $t < $this->at('2025-02-01 00:00'); $t += 900) {
            $p[$t] = intdiv($t, 900) % 2 === 0 ? 5.0 : 15.0;
        }
        $neg = [$this->at('2025-01-12 13:00'), $this->at('2025-01-12 13:15')];
        foreach ($neg as $t) $p[$t] = -2.0;
        $this->iv->mergePrices($p, 'test');
    }

    /** Die Wallbox lädt 10 × 1 kWh in günstigen Viertelstunden (5 ct) und 2 × 1 kWh bei −2 ct. */
    public function testShiftingIntoCheapQuarterHoursShows(): void
    {
        $this->januaryPrices();
        $wb = (string)$this->meters->create('strom', ['name' => 'Wallbox', 'installed_on' => '2024-01-01', 'role' => 'ev_charger'])['id'];
        $e = [];
        $t = $this->at('2025-01-05 00:00');
        while (count($e) < 10) { if (intdiv($t, 900) % 2 === 0) $e[$t] = 1.0; $t += 900; }
        $e[$this->at('2025-01-12 13:00')] = 1.0;
        $e[$this->at('2025-01-12 13:15')] = 1.0;
        $this->iv->mergeEnergy('strom', $wb, $e, 'test');

        $r = $this->flex()->analyze(2025);
        $m = array_values(array_filter($r['meters'], fn($x) => $x['meter_id'] === $wb))[0];
        self::assertTrue($m['controllable']);
        $jan = $m['months'][0];
        self::assertSame(1, $jan['month']);
        self::assertEqualsWithDelta(12.0, $jan['priced_kwh'], 0.001);
        self::assertEqualsWithDelta(12.0, $jan['cheap_kwh'], 0.001, 'alle unter dem Monatsmittel');
        self::assertEqualsWithDelta(2.0, $jan['negative_kwh'], 0.001);
        self::assertEqualsWithDelta((10 * 5 + 2 * -2) / 12, $jan['own_ct'], 0.001, 'eigener Durchschnitt');
        self::assertLessThan(1.0, $jan['profile_factor']);
        $market = $jan['market_ct'];
        self::assertEqualsWithDelta(($market - $jan['own_ct']) * 12 / 100, $jan['advantage_eur'], 0.01);
        self::assertSame(1.0, $r['prices']['coverage'] > 0.08 ? 1.0 : 0.0, 'Januar abgedeckt (1/12 des Jahres)');
    }

    /** Speicher: 5 kWh zu 2 ct geladen, 4 kWh zu 20 ct entladen → (4 × 20 − 5 × 2) / 100 = 0,70 €. */
    public function testTheBatteryValueFromChargeAndDischarge(): void
    {
        $this->iv->mergePrices([$this->at('2025-03-03 12:00') => 2.0, $this->at('2025-03-03 19:00') => 20.0], 'test');
        $ch = (string)$this->meters->create('pv_erzeugung', ['name' => 'Laden', 'installed_on' => '2024-01-01', 'role' => 'battery_charge'])['id'];
        $dis = (string)$this->meters->create('pv_erzeugung', ['name' => 'Entladen', 'installed_on' => '2024-01-01', 'role' => 'battery_discharge'])['id'];
        $this->iv->mergeEnergy('pv_erzeugung', $ch, [$this->at('2025-03-03 12:00') => 5.0], 'test');
        $this->iv->mergeEnergy('pv_erzeugung', $dis, [$this->at('2025-03-03 19:00') => 4.0], 'test');
        $b = $this->flex()->analyze(2025)['battery'];
        self::assertNotNull($b);
        self::assertSame([5.0, 4.0, 2.0, 20.0, 0.7], [$b['total']['charged_kwh'], $b['total']['discharged_kwh'],
            $b['total']['charge_ct'], $b['total']['discharge_ct'], $b['total']['value_eur']]);
    }

    /**
     * § 14a Modul 3: Standard 8 ct, im ersten Quartal 00–06 Uhr 2 ct und 17–20 Uhr 12 ct.
     * Je 1 kWh um 03:00, 12:00 und 18:00 → (8 − 2) + 0 + (8 − 12) = 2 ct.
     */
    public function testModule3SavingFromTheContractWindows(): void
    {
        $hp = (string)$this->meters->create('strom', ['name' => 'Wärmepumpe', 'installed_on' => '2024-01-01', 'role' => 'heat_pump'])['id'];
        $this->contracts->create('strom', ['meter_id' => $hp, 'provider' => 'X', 'start' => '2024-01-01',
            'working_prices' => [['from' => '2024-01-01', 'ct_per_kwh' => 25]],
            'grid_tou' => ['standard_ct' => '8', 'windows' => [
                ['quarters' => [1], 'from' => '00:00', 'to' => '06:00', 'ct' => 2],
                ['quarters' => [1], 'from' => '17:00', 'to' => '20:00', 'ct' => '12'],
            ]]]);
        $this->iv->mergeEnergy('strom', $hp, [$this->at('2025-02-10 03:00') => 1.0, $this->at('2025-02-10 12:00') => 1.0,
            $this->at('2025-02-10 18:00') => 1.0, $this->at('2025-07-10 03:00') => 1.0], 'test');
        $m3 = $this->flex()->analyze(2025)['module3'][0];
        self::assertSame($hp, $m3['meter_id']);
        self::assertSame(['low_kwh' => 1.0, 'high_kwh' => 1.0, 'standard_kwh' => 2.0, 'saving_eur' => 0.02], $m3['total'],
            'Juli: kein Fenster im dritten Quartal → Standard');
    }

    public function testTheContractChecksTheWindows(): void
    {
        self::assertNull(ContractService::normalizeGridTou([]));
        self::assertSame(['standard_ct' => 8.5, 'windows' => [['quarters' => [1, 4], 'from' => '22:00', 'to' => '24:00', 'ct' => 1.5]]],
            ContractService::normalizeGridTou(['standard_ct' => '8,5', 'windows' => [['quarters' => [4, 1, '1'], 'from' => '22:00', 'to' => '24:00', 'ct' => '1,5']]]));
        foreach ([
            ['standard_ct' => 8, 'windows' => [['quarters' => [], 'from' => '00:00', 'to' => '06:00', 'ct' => 2]]],
            ['standard_ct' => 8, 'windows' => [['quarters' => [5], 'from' => '00:00', 'to' => '06:00', 'ct' => 2]]],
            ['standard_ct' => 8, 'windows' => [['quarters' => [1], 'from' => '22:00', 'to' => '06:00', 'ct' => 2]]],
            ['windows' => [['quarters' => [1], 'from' => '00:00', 'to' => '06:00', 'ct' => 2]]],
        ] as $bad) {
            try { ContractService::normalizeGridTou($bad); self::fail(json_encode($bad)); }
            catch (LocalizedException $e) { self::assertSame('errors.contract.gridTouInvalid', $e->key); }
        }
    }

    /** Dynamik-Check: Monate mit eigenem Lastgang rechnen mit dem gewichteten Börsenpreis. */
    public function testTheDynamicCheckUsesTheOwnLoadProfile(): void
    {
        $meter = (string)$this->meters->defaultId('strom');
        $this->januaryPrices();
        // Verbrauch nur in den 5-ct-Viertelstunden, den ganzen Januar
        $e = [];
        for ($t = $this->at('2025-01-01 00:00'); $t < $this->at('2025-02-01 00:00'); $t += 900) $e[$t] = intdiv($t, 900) % 2 === 0 ? 0.2 : 0.0;
        $this->iv->mergeEnergy('strom', $meter, $e, 'test');
        // Februar: Preise ganz, Verbrauch nur zehn Tage — unter 90 % Abdeckung
        $pf = []; $ef = [];
        for ($t = $this->at('2025-02-01 00:00'); $t < $this->at('2025-03-01 00:00'); $t += 900) {
            $pf[$t] = 30.0;
            if ($t < $this->at('2025-02-11 00:00')) $ef[$t] = 0.1;
        }
        $this->iv->mergePrices($pf, 'test');
        $this->iv->mergeEnergy('strom', $meter, $ef, 'test');
        $w = $this->flex()->weightedSpot('strom', $meter, '2025-01', '2025-02');
        self::assertSame(['2025-01'], array_keys($w), 'Februar unter 90 % Abdeckung: Monatsmittel');
        self::assertEqualsWithDelta(5.0, $w['2025-01'], 0.05, 'fast nur 5 ct (zwei Viertelstunden −2 ct)');

        $c = (new MarketPriceService($this->store, $this->i18n))->expand(
            ['price_model' => 'dynamic', 'is_shadow' => true, 'start' => '2025-01-01', 'dynamic' => ['markup_ct_per_kwh' => 15, 'vat_pct' => 19]],
            '2025-01', '2025-01', $w);
        self::assertSame(['2025-01'], $c['dynamic_profile']);
        self::assertEqualsWithDelta($w['2025-01'] * 1.19 + 15, $c['working_prices'][0]['ct_per_kwh'], 0.001);
    }
}
