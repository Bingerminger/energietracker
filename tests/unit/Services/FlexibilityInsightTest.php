<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\EvccService;
use Energietracker\Services\FlexibilityInsightService;
use Energietracker\Services\FlexibilityService;
use Energietracker\Services\IntervalService;
use Energietracker\Services\ReadingImportService;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * v3.6.0 — Flexibilität lenkt: Ampel, Hebel, Zeitfenster, Einladung. Von Hand
 * nachgerechnet an kleinen Tagen und an einer Woche mit festen Preisen.
 */
#[CoversClass(FlexibilityInsightService::class)]
final class FlexibilityInsightTest extends ServiceTestCase
{
    private IntervalService $iv;

    protected function setUp(): void
    {
        parent::setUp();
        $this->iv = new IntervalService($this->store, $this->settings);
    }

    /** Umsatzsteuer auf den Börsenpreis ohne dynamisches Angebot (Regelsatz). */
    private const GROSS = 1.19;

    private function service(): FlexibilityInsightService
    {
        $flex = new FlexibilityService($this->iv, $this->meters, $this->contracts);
        $s = new FlexibilityInsightService($this->iv, $this->meters, $this->contracts, $flex, $this->settings);
        $s->attachSessions(new EvccService($this->store, $this->meters, $this->readings,
            new ReadingImportService($this->readings, $this->meters, $this->i18n), $this->settings));
        return $s;
    }

    private function compute(int $year = 2025, string $today = '2026-01-15'): array
    {
        $flex = new FlexibilityService($this->iv, $this->meters, $this->contracts);
        return $this->service()->insights($year, $flex->analyze($year), $today);
    }

    private function at(string $local): int
    {
        return (new \DateTimeImmutable($local, new \DateTimeZone('Europe/Berlin')))->getTimestamp();
    }

    // ── Bausteine, von Hand ───────────────────────────────────────────

    /** Preise 10/2/8/4 ct, je 1 kWh in der ersten und letzten Viertelstunde: beides wandert nach 2 und 4 ct. */
    public function testShiftMovesIntoTheCheapestQuarterHours(): void
    {
        $r = FlexibilityInsightService::shiftDays([1.0, 0.0, 0.0, 1.0], [10.0, 2.0, 8.0, 4.0], [[0, 1, 2, 3]], 1.0, 1.0, 'min');
        self::assertSame(2.0, $r['kwh']);
        self::assertEqualsWithDelta(14 - 6, $r['delta'], 1e-9, '(10 + 4) − (2 + 4) ct');
        self::assertEqualsWithDelta(1.0, $r['moved'], 1e-9, 'nur die erste Viertelstunde wechselt den Platz');
    }

    /** Leistungsgrenze: mit 1 kWh je Viertelstunde passen zwei kWh nicht in die günstigste. */
    public function testThePowerLimitSpreadsTheLoad(): void
    {
        $e = [1.0, 1.0, 0.0, 0.0]; $p = [9.0, 8.0, 1.0, 2.0];
        self::assertEqualsWithDelta(17 - 3, FlexibilityInsightService::shiftDays($e, $p, [[0, 1, 2, 3]], 1.0, 1.0, 'min')['delta'], 1e-9);
        self::assertEqualsWithDelta(17 - 2, FlexibilityInsightService::shiftDays($e, $p, [[0, 1, 2, 3]], 1.0, 2.0, 'min')['delta'], 1e-9);
    }

    /** Anteil: Von 2 kWh bei 10 ct ist die Hälfte frei und wandert nach 2 ct. */
    public function testOnlyTheShareMoves(): void
    {
        $r = FlexibilityInsightService::shiftDays([2.0, 0.0], [10.0, 2.0], [[0, 1]], 0.5, 2.0, 'min');
        self::assertEqualsWithDelta(20 - 12, $r['delta'], 1e-9);
        self::assertEqualsWithDelta(1.0, $r['moved'], 1e-9);
    }

    /** Verschoben wird nur innerhalb des Tages; Viertelstunden ohne Preis bleiben. */
    public function testShiftingStaysWithinTheDay(): void
    {
        $r = FlexibilityInsightService::shiftDays([1.0, 0.0, 0.0, 0.0], [10.0, 9.0, 1.0, null], [[0, 1], [2, 3]], 1.0, 1.0, 'min');
        self::assertEqualsWithDelta(1.0, $r['delta'], 1e-9, 'nicht in den nächsten Tag (1 ct)');
    }

    /** Speicher: Entladen in die teuersten Viertelstunden, höchstens bis zum Bedarf des Hauses. */
    public function testTheBatteryDischargesIntoTheMostExpensiveQuarterHours(): void
    {
        $r = FlexibilityInsightService::shiftDays([1.0, 0.0, 0.0], [5.0, 20.0, 10.0], [[0, 1, 2]], 1.0, 1.0, 'max', [1.0, 0.5, 1.0]);
        self::assertEqualsWithDelta((0.5 * 20 + 0.5 * 10) - 5, $r['delta'], 1e-9);
    }

    /** Ein Hebel ist nie negativ: Der heutige Ablauf ist eine der möglichen Verteilungen. */
    public function testALeverIsNeverNegative(): void
    {
        mt_srand(7);
        for ($k = 0; $k < 50; $k++) {
            $e = []; $p = [];
            for ($i = 0; $i < 96; $i++) { $e[] = mt_rand(0, 100) / 100; $p[] = mt_rand(-500, 3000) / 100; }
            $cap = max($e);
            self::assertGreaterThanOrEqual(0.0, FlexibilityInsightService::shiftDays($e, $p, [range(0, 95)], mt_rand(1, 10) / 10, $cap, 'min')['delta']);
            self::assertGreaterThanOrEqual(0.0, FlexibilityInsightService::shiftDays($e, $p, [range(0, 95)], 1.0, $cap, 'max', array_fill(0, 96, $cap))['delta']);
        }
    }

    /**
     * Sonnenstrom: nur Netzbezug wandert, nur in Viertelstunden mit Einspeisung,
     * und die Einspeisung ist danach verbraucht.
     */
    public function testSolarShiftUsesTheFeedInOnce(): void
    {
        $room = [0.0, 0.6, 0.6];
        $m = FlexibilityInsightService::solarShift([1.0, 0.0, 0.0], $room, [0.5, 0.0, 0.0], [[0, 1, 2]], [1, 1, 1], 1.0, 1.0);
        self::assertEqualsWithDelta(0.5, $m[1], 1e-9, 'höchstens der Netzbezug (0,5 kWh)');
        self::assertEqualsWithDelta(0.7, $room[1] + $room[2], 1e-9);
        $m2 = FlexibilityInsightService::solarShift([1.0, 0.0, 0.0], $room, null, [[0, 1, 2]], [1, 1, 1], 1.0, 1.0);
        self::assertEqualsWithDelta(0.7, $m2[1], 1e-9, 'das zweite Gerät bekommt den Rest');
    }

    /** Waschmaschine (Beispielwert): günstigstes Fenster gegen den Mittelwert aller Startzeiten. */
    public function testAnApplianceAgainstAnyStartTime(): void
    {
        $r = FlexibilityInsightService::applianceSpot([4.0, 0.0, 8.0, 4.0], [[0, 1, 2, 3]], 1.0, 2);
        self::assertEqualsWithDelta(4 - 2, $r['delta'], 1e-9, 'Fenster 2/4/6 ct: Mittel 4, bestes 2');
        $room = [0.0, 1.0, 1.0, 0.0];
        $m = FlexibilityInsightService::applianceSolar($room, [[0, 1, 2, 3]], [6, 6, 6, 6], 1.0, 2);
        self::assertEqualsWithDelta(1.0 - 2 / 3, $m[6], 1e-9, 'Deckung 0,5/1/0,5 kWh: bestes 1, Mittel 2/3');
    }

    public function testTheClockKnowsWeekdayAndDay(): void
    {
        $c = $this->iv->localClock(2025);
        self::assertSame([3, 0], [$c['weekday'][0], $c['day'][0]], '1. Januar 2025: Mittwoch, Tag 0');
        $i = intdiv($this->at('2025-01-05 12:00') - $this->iv->yearStart(2025), 900);
        self::assertSame([7, 4, 720], [$c['weekday'][$i], $c['day'][$i], $c['minute'][$i]], 'Sonntag');
        self::assertSame(92, count(array_keys($c['day'], 88, true)), '30. März: Sommerzeit, 92 Viertelstunden');
        self::assertSame(364, $c['day'][35039]);
    }

    // ── Eine Woche mit festen Preisen ────────────────────────────────

    /** Preise: nachts 5, tags 10, abends (17–20) 20 ct, sonntags 12–15 Uhr −1 ct; Januar 2025. */
    private function weekPrices(): void
    {
        $p = [];
        for ($t = $this->at('2025-01-01 00:00'); $t < $this->at('2025-02-01 00:00'); $t += 900) {
            $d = (new \DateTimeImmutable('@' . $t))->setTimezone(new \DateTimeZone('Europe/Berlin'));
            $h = (int)$d->format('G');
            $p[$t] = (int)$d->format('N') === 7 && $h >= 12 && $h < 15 ? -1.0 : ($h < 6 ? 5.0 : ($h >= 17 && $h < 20 ? 20.0 : 10.0));
        }
        $this->iv->mergePrices($p, 'test');
    }

    /** @return array{0:string,1:string,2:string} Haushalt, Wallbox (Subzähler), Wärmepumpe (eigener Zähler, Modul 3) */
    private function household(): array
    {
        $hh = (string)$this->meters->defaultId('strom');
        $wb = (string)$this->meters->create('strom', ['name' => 'Wallbox', 'installed_on' => '2024-01-01', 'role' => 'ev_charger', 'parent_meter_id' => $hh])['id'];
        $hp = (string)$this->meters->create('strom', ['name' => 'Wärmepumpe', 'installed_on' => '2024-01-01', 'role' => 'heat_pump'])['id'];
        $this->contracts->create('strom', ['meter_id' => $hp, 'provider' => 'X', 'start' => '2024-01-01',
            'working_prices' => [['from' => '2024-01-01', 'ct_per_kwh' => 25]],
            'grid_tou' => ['standard_ct' => 8, 'windows' => [['quarters' => [1, 2, 3, 4], 'from' => '00:00', 'to' => '06:00', 'ct' => 2]]]]);
        // Wallbox lädt abends 18–20 Uhr je 1 kWh, die Wärmepumpe tags (6–24 Uhr) 0,3 kWh, der Haushalt 0,1 kWh
        $ev = []; $hpE = []; $hhE = [];
        for ($t = $this->at('2025-01-01 00:00'); $t < $this->at('2025-02-01 00:00'); $t += 900) {
            $h = (int)(new \DateTimeImmutable('@' . $t))->setTimezone(new \DateTimeZone('Europe/Berlin'))->format('G');
            $ev[$t] = $h >= 18 && $h < 20 ? 1.0 : 0.0;
            $hpE[$t] = $h >= 6 ? 0.3 : 0.0;
            $hhE[$t] = 0.1 + $ev[$t];
        }
        $this->iv->mergeEnergy('strom', $wb, $ev, 'test');
        $this->iv->mergeEnergy('strom', $hp, $hpE, 'test');
        $this->iv->mergeEnergy('strom', $hh, $hhE, 'test');
        return [$hh, $wb, $hp];
    }

    public function testTheTrafficLightFollowsThePrices(): void
    {
        $this->weekPrices();
        $x = $this->compute();
        self::assertSame('spot', $x['signal']);
        $w = $x['pattern']['winter'];
        self::assertSame('expensive', $w['hours'][18]['level']);
        self::assertSame('cheap', $w['hours'][3]['level']);
        self::assertSame('cheap', $w['week'][6][13]['level'], 'Sonntag 13 Uhr');
        self::assertSame('mid', $w['week'][0][10]['level'], 'Montag 10 Uhr: 10 ct, im mittleren Drittel');
        self::assertSame(['weekday' => 7, 'from' => 12, 'to' => 15], array_intersect_key($w['windows'][0], ['weekday' => 1, 'from' => 1, 'to' => 1]), 'bestes Fenster: Sonntag 12–15 Uhr');
        self::assertEqualsWithDelta(-1.0, $w['windows'][0]['ct'], 0.001);
        // keine zwei Fenster überschneiden sich an einem Tag
        foreach ($w['windows'] as $a) foreach ($w['windows'] as $b) {
            if ($a === $b || $a['weekday'] !== $b['weekday']) continue;
            self::assertTrue($a['to'] <= $b['from'] || $b['to'] <= $a['from']);
        }
        self::assertSame(['from' => 17, 'to' => 20], array_intersect_key($w['day_peak'], ['from' => 1, 'to' => 1]));
        self::assertNull($x['pattern']['summer']['hours'][3]['ct'], 'ohne Preise im Sommer');
        self::assertSame('winter', $x['season_now']);
        self::assertSame('summer', $this->compute(2025, '2026-07-01')['season_now']);
    }

    public function testLeversPerApplianceAndSignal(): void
    {
        $this->weekPrices();
        [, $wb, $hp] = $this->household();
        $x = $this->compute();
        $by = [];
        foreach ($x['levers'] as $l) $by[$l['kind'] . '|' . $l['signal']] = $l;
        self::assertArrayHasKey('ev|spot', $by);
        self::assertArrayHasKey('heat_pump|spot', $by);
        self::assertArrayHasKey('heat_pump|tou', $by);
        self::assertArrayHasKey('dishwasher|spot', $by);
        self::assertArrayNotHasKey('ev|solar', $by, 'ohne PV kein Sonnenstrom');
        // Wallbox: 31 Tage × 8 kWh von 20 ct in die günstigsten Viertelstunden (Sonntage −1 ct, sonst 5 ct nachts)
        self::assertSame($wb, $by['ev|spot']['meter_id']);
        self::assertFalse($by['ev|spot']['real']);
        self::assertTrue($by['heat_pump|tou']['real']);
        self::assertSame('assumed', $by['dishwasher|spot']['basis']);
        self::assertSame('day', $by['ev|spot']['availability'], 'ohne Ladevorgänge: ganzer Ladetag');
        self::assertNull($by['heat_pump|spot']['availability']);
        $sundays = 4; $weekdays = 31 - $sundays;
        // Sonntag: 8 kWh, höchstens 1 kWh je Viertelstunde → alle 12 Viertelstunden 12–15 Uhr zu −1 ct;
        // v3.6.0 (Paket flexplus) — Börsenpreis mit Umsatzsteuer, wie ein dynamischer Tarif abrechnet
        self::assertEqualsWithDelta(($weekdays * 8 * (20 - 5) + $sundays * 8 * (20 + 1)) / 100 * self::GROSS, $by['ev|spot']['eur'], 0.01);
        // Wärmepumpe Modul 3: 30 % von 72 × 0,3 kWh je Tag (6,48 kWh) wandern in 00–06 Uhr
        // (24 × 0,3 kWh Platz), 2 statt 8 ct — die Beträge am Vertrag, ohne Aufschlag
        self::assertEqualsWithDelta(31 * 6.48 * (8 - 2) / 100, $by['heat_pump|tou']['eur'], 0.01);
        self::assertEqualsWithDelta(31 * 6.48, $by['heat_pump|tou']['shift_kwh'], 0.1);
        self::assertSame(['from' => 0, 'to' => 6], array_intersect_key($by['heat_pump|tou']['window']['winter'], ['from' => 1, 'to' => 1]), 'Niedrigtarif-Fenster');
        // sortiert, größter zuerst; die Summe zählt je Gerät den größten
        $eur = array_column($x['levers'], 'eur');
        $sorted = $eur; rsort($sorted);
        self::assertSame($sorted, $eur);
        $best = [];
        foreach ($x['levers'] as $l) $best[$l['kind'] . ($l['meter_id'] ?? '')] = max($best[$l['kind'] . ($l['meter_id'] ?? '')] ?? 0.0, $l['eur']);
        self::assertEqualsWithDelta(array_sum($best), $x['total_eur'], 0.01);
        // Handlungssätze: ein Satz je Gerät, Zeitfenster der laufenden Jahreszeit
        self::assertCount(count($best), $x['actions']);
        $ev = array_values(array_filter($x['actions'], fn($a) => $a['kind'] === 'ev'))[0];
        self::assertSame(['weekday' => 7, 'from' => 12, 'to' => 15], array_intersect_key($ev['window'], ['weekday' => 1, 'from' => 1, 'to' => 1]));
        self::assertEqualsWithDelta($by['heat_pump|tou']['eur'], $x['total_real_eur'], 0.01, 'mit dem Vertrag, wie er ist: nur Modul 3');
        $hpAction = array_values(array_filter($x['actions'], fn($a) => $a['kind'] === 'heat_pump'))[0];
        self::assertSame('tou', $hpAction['signal'], 'Handlungssatz: der Hebel mit dem eigenen Vertrag vor dem dynamischen');
        self::assertNotContains('devices', $x['missing']);
        self::assertNotContains('intervals', $x['missing']);
        self::assertNotContains('tariff', $x['missing'], 'Modul 3 am Vertrag');
    }

    /** Modul 3 ohne Börsenpreise: Ampel aus den Zeitfenstern, Hebel mit dem Vertrag, wie er ist. */
    public function testWithoutPricesTheContractWindowsLeadTheTrafficLight(): void
    {
        [, , $hp] = $this->household();
        $x = $this->compute();
        self::assertSame('tou', $x['signal']);
        $w = $x['pattern']['winter'];
        self::assertSame('cheap', $w['hours'][2]['level']);
        self::assertSame('mid', $w['hours'][12]['level'], 'Standardtarif');
        self::assertNull($w['cheap_ct'], 'Stufen statt Dritteln');
        self::assertContains('prices', $x['missing']);
        self::assertSame([], array_values(array_filter($x['levers'], fn($l) => $l['signal'] === 'spot')));
    }

    /** Leere Installation: keine Ampel, kein Hebel, eine Einladung mit allem, was fehlt. */
    public function testAnEmptyInstallationIsInvited(): void
    {
        $x = $this->compute();
        self::assertNull($x['signal']);
        self::assertNull($x['pattern']);
        self::assertSame([], $x['levers']);
        self::assertSame(0.0, $x['total_eur']);
        self::assertSame(['prices', 'intervals', 'devices', 'tariff'], $x['missing']);
    }

    /** Gewinn je kWh und Tag eines 2-Stunden-Geräts im Januar der Testwoche (ct): bestes Fenster gegen das Mittel aller Startzeiten. */
    private function applianceGainPerKwh(): float
    {
        $gain = 0.0;
        for ($t = $this->at('2025-01-01 00:00'); $t < $this->at('2025-02-01 00:00'); $t += 86400) {
            $sunday = (int)(new \DateTimeImmutable('@' . $t))->setTimezone(new \DateTimeZone('Europe/Berlin'))->format('N') === 7;
            // 89 Startzeiten je Tag (96 − 8 + 1); Mittel der Fenster aus den Stundenpreisen
            $hour = fn(int $h) => $sunday && $h >= 12 && $h < 15 ? -1.0 : ($h < 6 ? 5.0 : ($h >= 17 && $h < 20 ? 20.0 : 10.0));
            $means = [];
            for ($s = 0; $s + 8 <= 96; $s++) { $sum = 0.0; for ($j = 0; $j < 8; $j++) $sum += $hour(intdiv($s + $j, 4)); $means[] = $sum / 8; }
            $gain += array_sum($means) / count($means) - min($means);
        }
        return $gain;
    }

    /** Nur Börsenpreise: Ampel und Beispielwerte für Wasch- und Spülmaschine. */
    public function testPricesAloneShowTheTrafficLightAndTheAppliances(): void
    {
        $this->weekPrices();
        $x = $this->compute();
        // Spülmaschine: 5 × 1 kWh je Woche, Waschmaschine 4 × 0,9 kWh, je Tag das günstigste
        // 2-Stunden-Fenster gegen den Mittelwert aller Startzeiten, mit Umsatzsteuer
        self::assertSame(['dishwasher', 'washer'], array_column($x['levers'], 'kind'));
        $gain = $this->applianceGainPerKwh();
        self::assertEqualsWithDelta(5.0 / 7 * $gain / 100 * self::GROSS, $x['levers'][0]['eur'], 0.01);
        self::assertEqualsWithDelta(0.9 * 4 / 7 * $gain / 100 * self::GROSS, $x['levers'][1]['eur'], 0.01);
        foreach ($x['levers'] as $l) {
            self::assertSame('assumed', $l['basis']);
            self::assertFalse($l['real']);
            self::assertSame('spot', $l['signal']);
        }
        self::assertSame(['intervals', 'devices', 'tariff'], $x['missing']);
        self::assertSame(19.0, $x['vat_pct'], 'ohne dynamisches Angebot der Regelsatz');
        // ohne gemessene Viertelstunden: Karte ja (mit dynamischem Tarif), Kachel der Übersicht nein
        self::assertFalse($x['summary']['measured']);
        self::assertFalse($x['summary']['real']);
        self::assertSame($x['total_eur'], $x['summary']['eur']);
        self::assertNull($x['summary']['dynamic_eur']);
    }

    /**
     * v3.6.0 (Paket flexplus) — Die Umsatzsteuer kommt aus dem dynamischen
     * Angebot; unter 1 € im Jahr erscheint kein Hebel (Waschmaschine mit 7 %).
     */
    public function testSpotLeversCarryTheVatOfTheDynamicOffer(): void
    {
        $this->weekPrices();
        $hh = (string)$this->meters->defaultId('strom');
        $this->contracts->create('strom', ['meter_id' => $hh, 'provider' => 'Börse', 'start' => '2027-01-01', 'is_shadow' => true,
            'shadow_label' => 'Dynamisch', 'price_model' => 'dynamic', 'dynamic' => ['markup_ct_per_kwh' => 18, 'base_eur_month' => 10, 'vat_pct' => 7]]);
        $x = $this->compute();
        self::assertSame(7.0, $x['vat_pct']);
        $gain = $this->applianceGainPerKwh();
        $by = array_column($x['levers'], null, 'kind');
        self::assertEqualsWithDelta(5.0 / 7 * $gain / 100 * 1.07, $by['dishwasher']['eur'], 0.01);
        self::assertLessThan(1.0, 0.9 * 4 / 7 * $gain / 100 * 1.07, 'Voraussetzung: Waschmaschine unter 1 €');
        self::assertArrayNotHasKey('washer', $by, 'unter 1 € im Jahr erscheint kein Hebel');
        self::assertNotContains('tariff', $x['missing'], 'dynamisches Angebot vorhanden');
    }

    /**
     * v3.6.0 (Paket flexplus) — Ladefenster aus den Steckzeiten: abends um
     * 18 Uhr angesteckt, morgens um 7 Uhr abgesteckt. Das Laden (18–20 Uhr,
     * 20 ct) wandert in die Nacht (5 ct), auch über Mitternacht — sonntags
     * mittags (−1 ct) ist das Auto nicht da. Am 31. Januar endet die Preisreihe
     * um Mitternacht: dort bleiben 20–24 Uhr (10 ct).
     */
    public function testTheCarShiftsOnlyWhilePluggedIn(): void
    {
        $this->weekPrices();
        [, $wb] = $this->household();
        $sessions = [];
        for ($d = 1; $d <= 31; $d++) $sessions[] = $this->session($wb, sprintf('2025-01-%02d', $d));
        // ein doppelt geführter Vorgang (zweiter Ladepunkt-Eintrag) überlappt: ein Fenster, nichts doppelt
        $sessions[] = ['id' => 'evs_2025-01-10b', 'created' => '2025-01-10T19:00:00+01:00', 'finished' => '2025-01-11T06:00:00+01:00']
            + $this->session($wb, '2025-01-10');
        $this->store->write(EvccService::FILE, $sessions);
        $x = $this->compute();
        $ev = array_values(array_filter($x['levers'], fn($l) => $l['kind'] === 'ev' && $l['signal'] === 'spot'))[0];
        self::assertSame('sessions', $ev['availability']);
        self::assertSame(32, $ev['sessions']);
        self::assertSame(1.0, $ev['session_share']);
        self::assertEqualsWithDelta((30 * 8 * (20 - 5) + 8 * (20 - 10)) / 100 * self::GROSS, $ev['eur'], 0.01);
        self::assertEqualsWithDelta(31 * 8, $ev['kwh'], 0.01);
        // Satz: die günstigsten drei Stunden, in denen das Auto meist angesteckt ist — nicht sonntags mittags
        self::assertSame(['weekday' => null, 'from' => 0, 'to' => 3], array_intersect_key($ev['window']['winter'], ['weekday' => 1, 'from' => 1, 'to' => 1]));
        self::assertTrue($ev['window']['winter']['plugged']);
        self::assertSame(['basis' => 'sessions', 'sessions' => 32, 'share' => 1.0, 'window' => null], $x['ev_availability']);
    }

    /**
     * v3.7.0 — Eigene Steckzeit ohne evcc (Einstellungen): 18 bis 7 Uhr wirkt wie
     * ein Ladevorgang je Tag — dasselbe Ergebnis wie mit den Steckzeiten aus
     * evcc oben, Grundlage `window` statt `sessions`.
     */
    public function testAnOwnPlugInTimeWithoutEvccActsLikeSessions(): void
    {
        $this->weekPrices();
        $this->household();
        $this->settings->set(['flex_ev_plugged_from' => 18, 'flex_ev_plugged_until' => 7]);
        $x = $this->compute();
        $ev = array_values(array_filter($x['levers'], fn($l) => $l['kind'] === 'ev' && $l['signal'] === 'spot'))[0];
        self::assertSame('window', $ev['availability']);
        self::assertSame(0, $ev['sessions'], 'keine echten Ladevorgänge');
        self::assertEqualsWithDelta((30 * 8 * (20 - 5) + 8 * (20 - 10)) / 100 * self::GROSS, $ev['eur'], 0.01);
        self::assertSame(['basis' => 'window', 'sessions' => 0, 'share' => 1.0, 'window' => ['from' => 18, 'until' => 7]], $x['ev_availability']);
        self::assertTrue($x['assumptions']['own']['ev_window']);
        // nur eine der beiden Stunden: kein Fenster, wieder der ganze Ladetag (Beispielwert)
        $this->settings->set(['flex_ev_plugged_until' => '']);
        self::assertSame('day', $this->compute()['ev_availability']['basis']);
    }

    /**
     * v3.7.0 — Eigene Werte statt Beispielwerte: Spülmaschine 7 Gänge je Woche
     * (Grundlage `own`), Wärmepumpe 20 % statt 30 %; die Waschmaschine bleibt
     * beim Beispielwert. 0 Gänge: kein solches Gerät, kein Hebel.
     */
    public function testOwnValuesReplaceTheSampleValues(): void
    {
        $this->weekPrices();
        $this->household();
        $this->settings->set(['flex_dishwasher_cycles_per_week' => 7, 'flex_heat_pump_share_pct' => 20]);
        $x = $this->compute();
        $by = [];
        foreach ($x['levers'] as $l) $by[$l['kind'] . '|' . $l['signal']] = $l;
        self::assertSame('own', $by['dishwasher|spot']['basis']);
        self::assertEqualsWithDelta(7.0 / 7 * $this->applianceGainPerKwh() / 100 * self::GROSS, $by['dishwasher|spot']['eur'], 0.01);
        self::assertSame('assumed', $by['washer|spot']['basis']);
        self::assertEqualsWithDelta(31 * 72 * 0.3 * 0.2 * (8 - 2) / 100, $by['heat_pump|tou']['eur'], 0.01, '20 % statt 30 % des Tagesverbrauchs');
        self::assertSame(['heat_pump_share' => true, 'washer' => false, 'dishwasher' => true, 'ev_window' => false], $x['assumptions']['own']);
        self::assertSame(0.2, $x['assumptions']['heat_pump_share']);
        // derselbe Wert wie das Beispiel, aber eingetragen: eigener Wert
        $this->settings->set(['flex_washer_kwh_per_cycle' => 0.9]);
        self::assertTrue($this->compute()['assumptions']['own']['washer']);
        $this->settings->set(['flex_dishwasher_cycles_per_week' => 0]);
        self::assertArrayNotHasKey('dishwasher', array_column($this->compute()['levers'], null, 'kind'), '0 Gänge: kein Hebel');
    }

    /** v3.7.0 — Die Defaults der Einstellungen sind die Beispielwerte; Grenzen beim Speichern, leer nimmt zurück. */
    public function testTheSettingsDefaultsAreTheSampleValues(): void
    {
        self::assertSame(FlexibilityInsightService::ASSUMPTIONS['heat_pump_share'] * 100, \Energietracker\Services\SettingsService::defaultFor('flex_heat_pump_share_pct'));
        foreach (FlexibilityInsightService::APPLIANCES as $k => $a) {
            self::assertSame($a['cycles_per_week'], \Energietracker\Services\SettingsService::defaultFor("flex_{$k}_cycles_per_week"));
            self::assertSame($a['kwh_per_cycle'], \Energietracker\Services\SettingsService::defaultFor("flex_{$k}_kwh_per_cycle"));
        }
        self::assertSame(array_keys(\Energietracker\Services\SettingsService::EXAMPLE_VALUES), $this->settings->exampleKeys(), 'Neuinstallation: alles Beispielwerte');
        $this->settings->set(['flex_washer_kwh_per_cycle' => '1,2']);
        self::assertSame(1.2, $this->settings->get('flex_washer_kwh_per_cycle'));
        self::assertNotContains('flex_washer_kwh_per_cycle', $this->settings->exampleKeys());
        $this->settings->set(['flex_washer_kwh_per_cycle' => null]);
        self::assertContains('flex_washer_kwh_per_cycle', $this->settings->exampleKeys(), 'geleert: wieder Beispielwert');
        foreach ([['flex_heat_pump_share_pct', 150], ['flex_ev_plugged_from', 7.5], ['flex_ev_plugged_until', 24], ['flex_washer_cycles_per_week', 'oft']] as [$k, $v]) {
            try { $this->settings->set([$k => $v]); self::fail("$k = $v angenommen"); } catch (\InvalidArgumentException) { self::assertTrue(true); }
        }
    }

    /**
     * Ladevorgänge nur für die erste Monatshälfte: Tage ohne Vorgang mit der
     * Annahme „ganzer Ladetag“; der 16. war bis 7 Uhr angesteckt (Vorgang vom
     * 15.), sein Laden am Abend ohne Vorgang bleibt, wo es ist.
     */
    public function testDaysWithoutSessionsKeepTheAssumption(): void
    {
        $this->weekPrices();
        [, $wb] = $this->household();
        $sessions = [];
        for ($d = 1; $d <= 15; $d++) $sessions[] = $this->session($wb, sprintf('2025-01-%02d', $d));
        $this->store->write(EvccService::FILE, $sessions);
        $ev = array_values(array_filter($this->compute()['levers'], fn($l) => $l['kind'] === 'ev' && $l['signal'] === 'spot'))[0];
        // 1.–15.: nachts (5 ct, auch die Sonntage 5. und 12.); 16.: bleibt; 17.–31.: ganzer Tag (Sonntage 19. und 26. mittags −1 ct)
        self::assertEqualsWithDelta((15 * 120 + 0 + 13 * 120 + 2 * 168) / 100 * self::GROSS, $ev['eur'], 0.01);
        self::assertEqualsWithDelta(15 / 31, $ev['session_share'], 0.0001);
        self::assertSame('sessions', $ev['availability']);
    }

    /** Sonnenstrom fürs Auto nur, solange es angesteckt ist: abends angesteckt, mittags weg — kein Hebel. */
    public function testNoSolarLeverForACarAwayAtNoon(): void
    {
        [$hh, $wb] = $this->household();
        $this->solarHousehold($hh);
        $sessions = [];
        for ($d = 1; $d <= 31; $d++) $sessions[] = $this->session($wb, sprintf('2025-01-%02d', $d));
        $this->store->write(EvccService::FILE, $sessions);
        $x = $this->compute();
        self::assertSame([], array_values(array_filter($x['levers'], fn($l) => $l['kind'] === 'ev' && $l['signal'] === 'solar')));
        self::assertNotSame([], array_values(array_filter($x['levers'], fn($l) => $l['signal'] === 'solar')), 'Wasch- und Spülmaschine schon');
    }

    /**
     * Die Zahl der Ergebniskarte (`summary`): mit dem Vertrag, wie er ist,
     * daneben „bis zu“ mit dynamischem Tarif, der Satz mit dem Vertrag zuerst;
     * summary() rechnet ohne Nachrechnung dieselbe Zahl.
     */
    public function testTheSummaryIsTheNumberOfTheCard(): void
    {
        self::assertNull($this->compute()['summary'], 'ohne Hebel keine Karte');
        $this->weekPrices();
        $this->household();
        $x = $this->compute();
        $s = $x['summary'];
        self::assertTrue($s['real']);
        self::assertTrue($s['measured']);
        self::assertSame($x['total_real_eur'], $s['eur']);
        self::assertSame($x['total_eur'], $s['dynamic_eur']);
        self::assertSame(['heat_pump', 'tou'], [$s['action']['kind'], $s['action']['signal']], 'mit dem Vertrag vor dem größeren dynamischen Hebel');
        self::assertSame(19.0, $s['vat_pct']);
        self::assertSame($s, $this->service()->summary(2025, '2026-01-15'));
    }

    /** Ein Ladevorgang, wie evcc ihn führt: um 18 Uhr angesteckt, am nächsten Morgen um 7 Uhr abgesteckt (Januar, MEZ). */
    private function session(string $meterId, string $day): array
    {
        $next = (new \DateTimeImmutable($day . ' 12:00'))->modify('+1 day')->format('Y-m-d');
        return ['id' => 'evs_' . $day, 'meter_id' => $meterId, 'created' => "{$day}T18:00:00+01:00", 'finished' => "{$next}T07:00:00+01:00",
            'date' => $next, 'loadpoint' => 'Wallbox', 'charged_kwh' => 8.0, 'source' => 'evcc'];
    }

    /** Hausanschluss mit Vertrag (30 ct), PV: mittags 11–14 Uhr je Viertelstunde 1,5 kWh erzeugt, davon 1,3 eingespeist (8 ct). */
    private function solarHousehold(string $hh): void
    {
        $this->contracts->create('strom', ['meter_id' => $hh, 'provider' => 'Y', 'start' => '2024-01-01',
            'working_prices' => [['from' => '2024-01-01', 'ct_per_kwh' => 30]]]);
        $gen = (string)$this->meters->create('pv_erzeugung', ['name' => 'WR', 'installed_on' => '2024-01-01'])['id'];
        $feed = (string)$this->meters->create('pv_einspeisung', ['name' => 'Einspeisung', 'installed_on' => '2024-01-01'])['id'];
        $this->contracts->create('pv_einspeisung', ['meter_id' => $feed, 'provider' => 'Netz', 'start' => '2024-01-01',
            'working_prices' => [['from' => '2024-01-01', 'ct_per_kwh' => 8]]]);
        $g = []; $f = [];
        for ($t = $this->at('2025-01-01 00:00'); $t < $this->at('2025-02-01 00:00'); $t += 900) {
            $h = (int)(new \DateTimeImmutable('@' . $t))->setTimezone(new \DateTimeZone('Europe/Berlin'))->format('G');
            $g[$t] = $h >= 11 && $h < 14 ? 1.5 : 0.0;
            $f[$t] = $h >= 11 && $h < 14 ? 1.3 : 0.0;
        }
        $this->iv->mergeEnergy('pv_erzeugung', $gen, $g, 'test');
        $this->iv->mergeEnergy('pv_einspeisung', $feed, $f, 'test');
    }

    /** Sonnenstrom erreicht den Hausanschluss und seine Subzähler, nicht eine Wärmepumpe mit eigenem Zähler. */
    public function testSolarReachesOnlyMetersBehindTheHouseConnection(): void
    {
        [$hh, $wb, $hp] = $this->household();
        // Die Wärmepumpe pausiert mittags: Platz für Sonnenstrom hätte sie — bekommen darf sie ihn nicht (eigener Zähler)
        $pause = [];
        for ($t = $this->at('2025-01-01 00:00'); $t < $this->at('2025-02-01 00:00'); $t += 900) {
            $h = (int)(new \DateTimeImmutable('@' . $t))->setTimezone(new \DateTimeZone('Europe/Berlin'))->format('G');
            if ($h >= 11 && $h < 14) $pause[$t] = 0.0;
        }
        $this->iv->mergeEnergy('strom', $hp, $pause, 'test');
        $this->solarHousehold($hh);
        $x = $this->compute();
        $solar = array_values(array_filter($x['levers'], fn($l) => $l['signal'] === 'solar'));
        self::assertNotSame([], $solar);
        self::assertNotContains('heat_pump', array_column($solar, 'kind'), 'eigener Zähler der Wärmepumpe');
        $ev = array_values(array_filter($solar, fn($l) => $l['kind'] === 'ev'))[0];
        // Wallbox: 8 kWh abends je Tag, Platz 12 × 1,3 kWh mittags, höchstens 1 kWh je Viertelstunde → 8 kWh je Tag
        self::assertEqualsWithDelta(31 * 8, $ev['shift_kwh'], 0.01);
        self::assertEqualsWithDelta(31 * 8 * (30 - 8) / 100, $ev['eur'], 0.01);
        self::assertTrue($ev['real']);
        self::assertSame(['from' => 11, 'to' => 14], array_intersect_key($ev['window']['winter'], ['from' => 1, 'to' => 1]));
        $s = $x['solar'];
        self::assertEqualsWithDelta(31 * 12 * 1.5, $s['generation_kwh'], 0.01);
        self::assertEqualsWithDelta(31 * 12 * 0.2, $s['self_kwh'], 0.01);
        self::assertLessThanOrEqual($s['feed_kwh'], $s['more_kwh'], 'nie mehr als eingespeist');
        self::assertContains('prices', $x['missing']);
        self::assertSame('tou', $x['signal'], 'ohne Börsenpreise die Zeitfenster der Wärmepumpe');
    }
}
