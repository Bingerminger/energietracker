<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\DeviceArrival;
use Energietracker\Services\ForecastService;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * v3.13.0 (Paket speicher-auto) — Speicher und Wallbox, die mitten im Verlauf dazukommen, an von Hand gerechneten
 * Kleinfällen ohne Rauschen.
 *
 * Speicher ab 01.01.2024: Er lädt je Tag c(m) = 2 + 0,2 × Monat kWh und gibt d(m) = 0,9 × c(m) ab. Vorher speist der
 * Haushalt je Tag 6 kWh ein und bezieht 10 kWh, danach 6 − c(m) bzw. 10 − d(m). Am Stichtag 01.07.2024 hat der
 * Speicher Januar bis Juni gemessen: Diese Kalendermonate bereinigt er mit ihrem eigenen Wert, Juli bis Dezember mit
 * der Tagesrate aller gemessenen Monate. Die Prognose muss also überall den Haushalt mit Speicher zeigen.
 *
 * Wallbox ab 01.05.2024 am Hauszähler, je Tag 5 kWh; Temperatur je Kalendermonat fest (TEMP), Annahme
 * DeviceArrival::EV_PER_KELVIN je Grad unter EV_TEMP_REF. Alle Tage fest, kein „heute“.
 */
#[CoversClass(DeviceArrival::class)]
#[CoversClass(ForecastService::class)]
final class DeviceArrivalTest extends ServiceTestCase
{
    private const BATTERY = '2024-01-01';
    private const WALLBOX = '2024-05-01';
    /** Tagesmittel je Kalendermonat (°C), in jedem Jahr gleich */
    private const TEMP = [1 => 0.0, 2 => 2.0, 3 => 6.0, 4 => 10.0, 5 => 14.0, 6 => 18.0, 7 => 20.0, 8 => 20.0, 9 => 16.0, 10 => 12.0, 11 => 6.0, 12 => 2.0];

    /** @var array<string,list<array<string,mixed>>> Zähler je Art */
    private array $metersOf = [];
    /** @var array<string,list<array<string,mixed>>> Ablesungen je Art */
    private array $readingsOf = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->settings->set(['active_utilities' => ['strom', 'pv_einspeisung', 'pv_erzeugung']]);
        foreach (['strom', 'pv_einspeisung', 'pv_erzeugung'] as $u) {
            $this->store->write("$u/meters.json", []);
            $this->store->write("$u/readings.json", []);
            $this->store->write("$u/contracts.json", []);
        }
    }

    private function forecast(): ForecastService
    {
        return new ForecastService($this->consumption, $this->regression, $this->settings, $this->contracts, $this->i18n);
    }

    private static function charge(int $t): float { return 2.0 + 0.2 * (int)date('n', $t); }
    private static function discharge(int $t): float { return 0.9 * self::charge($t); }

    /**
     * Ein Zähler mit Ablesungen an $dates, je Tag $perDay(Zeitstempel) kWh.
     *
     * @param list<string> $dates
     */
    private function meter(string $utility, string $id, array $dates, callable $perDay, array $extra = [], ?string $installed = null): array
    {
        $installed ??= $dates[0];
        $m = $extra + ['id' => $id, 'name' => $id, 'icon' => '', 'created_at' => $installed, 'active' => true, 'notes' => '',
            'parent_meter_id' => null, 'meter_group_id' => null,
            'devices' => [['id' => "d_$id", 'serial' => null, 'installed_on' => $installed, 'initial_counter' => 0.0,
                           'removed_on' => null, 'final_counter' => null, 'reason' => null]]];
        $this->metersOf[$utility][] = $m;
        $c = 0.0; $prev = null;
        foreach ($dates as $i => $d) {
            if ($prev !== null) for ($t = strtotime("$prev 12:00"); date('Y-m-d', $t) < $d; $t += 86400) $c += $perDay($t);
            $this->readingsOf[$utility][] = ['id' => sprintf('r_%s_%03d', $id, $i), 'meter_id' => $id, 'date' => $d, 'counter' => round($c, 4),
                'device_id' => "d_$id", 'price_cents' => null, 'note' => '', 'is_estimated' => false, 'is_future' => false];
            $prev = $d;
        }
        $this->store->write("$utility/meters.json", $this->metersOf[$utility]);
        $this->store->write("$utility/readings.json", $this->readingsOf[$utility]);
        return $m;
    }

    /** @return list<string> Monatserste von $from bis $to, je $step Monate */
    private static function firsts(string $from, string $to, int $step = 1): array
    {
        $out = [];
        for ($t = strtotime($from); date('Y-m-d', $t) <= $to; $t = strtotime("+$step month", $t)) $out[] = date('Y-m-d', $t);
        return $out;
    }

    private static function monthSum(string $ym, callable $perDay): float
    {
        $s = 0.0;
        for ($t = strtotime("$ym-01 12:00"); date('Y-m', $t) === $ym; $t += 86400) $s += $perDay($t);
        return $s;
    }

    private static function row(array $fc, string $ym): array
    {
        foreach ($fc['forecast'] as $r) if ($r['ym'] === $ym) return $r;
        self::fail("Monat $ym fehlt");
    }

    /** @return list<array<string,mixed>> Hinweise mit diesem Code */
    private static function warnings(array $fc, string $code): array
    {
        return array_values(array_filter((array)$fc['warnings'], fn($w) => ($w['code'] ?? '') === $code));
    }

    /** Speicherzähler ab BATTERY, monatlich bis $to abgelesen. */
    private function battery(string $to = '2025-01-01', bool $discharge = true): void
    {
        $dates = self::firsts(self::BATTERY, $to);
        $this->meter('pv_erzeugung', 'm_bat_in', $dates, fn(int $t) => self::charge($t), ['role' => 'battery_charge']);
        if ($discharge) $this->meter('pv_erzeugung', 'm_bat_out', $dates, fn(int $t) => self::discharge($t), ['role' => 'battery_discharge']);
    }

    /** Tagesrate aller gemessenen Monate Januar bis Juni 2024 (die Annahme für Kalendermonate ohne Messung). */
    private static function flat(callable $perDay): float
    {
        $s = 0.0; $d = 0;
        foreach (['2024-01', '2024-02', '2024-03', '2024-04', '2024-05', '2024-06'] as $ym) { $s += self::monthSum($ym, $perDay); $d += (int)date('t', strtotime("$ym-01")); }
        return $s / $d;
    }

    private function feedMeter(array $dates): array
    {
        return $this->meter('pv_einspeisung', 'm_feed', $dates, fn(int $t) => date('Y-m-d', $t) < self::BATTERY ? 6.0 : 6.0 - self::charge($t));
    }

    private function importMeter(array $dates, array $extra = []): array
    {
        return $this->meter('strom', 'm_bezug', $dates, fn(int $t) => date('Y-m-d', $t) < self::BATTERY ? 10.0 : 10.0 - self::discharge($t), $extra);
    }

    // ── Auslieferung ────────────────────────────────────────────────────

    public function testShippedSwitches(): void
    {
        // Torregel und Messung: DeviceArrival-Docblock und umsetzung-j-speicher-auto.md
        self::assertFalse(DeviceArrival::BATTERY_IMPORT_ENABLED, 'Bezug: Schwelle am Prüfhaushalt nicht geschafft');
        self::assertTrue(DeviceArrival::BATTERY_FEED_ENABLED, 'Einspeisung: Schwelle an A/V1, A/V2, A/V3 geschafft');
        self::assertFalse(DeviceArrival::EV_PARENT_ENABLED, 'Elternzähler: Schwelle nicht geschafft');
        self::assertTrue(DeviceArrival::EV_MONTHS_ENABLED, 'Wallbox-Monate: Winter-WAPE geschafft, nicht schlechter als alt');
        self::assertSame(3, DeviceArrival::MIN_MONTHS);
        self::assertSame(0.01, DeviceArrival::EV_PER_KELVIN);
        self::assertSame(20.0, DeviceArrival::EV_TEMP_REF);
    }

    // ── Speicher ────────────────────────────────────────────────────────

    public function testFeedInBeforeTheBatteryLosesTheChargeOfTheSameCalendarMonth(): void
    {
        $this->battery();
        $feed = $this->feedMeter(self::firsts('2023-01-01', '2025-01-01'));
        $fc = $this->forecast()->forMeter('pv_einspeisung', $feed, ['today' => '2024-07-01']);
        self::assertTrue($fc['valid']);
        $flat = self::flat(fn(int $t) => self::charge($t));
        // Januar bis Juni: gemessen im selben Kalendermonat → 6 − c(m), wie mit Speicher gemessen
        foreach (['2025-01' => 1, '2025-03' => 3, '2025-06' => 6] as $ym => $mn) {
            self::assertEqualsWithDelta((6.0 - (2.0 + 0.2 * $mn)) * (int)date('t', strtotime("$ym-01")), self::row($fc, $ym)['kwh'], 0.06, $ym);
        }
        // Juli bis Dezember: nur 2023 gemessen, bereinigt mit der Tagesrate von Januar bis Juni (Annahme)
        foreach (['2024-07', '2024-10', '2024-12'] as $ym) {
            self::assertEqualsWithDelta((6.0 - $flat) * (int)date('t', strtotime("$ym-01")), self::row($fc, $ym)['kwh'], 0.06, $ym);
        }
        $w = self::warnings($fc, 'battery_history');
        self::assertCount(1, $w);
        self::assertSame(['reason' => 'adjusted', 'since' => self::BATTERY, 'months' => 6, 'kind' => 'feed_in'],
            array_intersect_key($w[0], array_flip(['reason', 'since', 'months', 'kind'])));
        // gemessen bleibt gemessen: `historical` trägt die Ablesungen, nicht die Bereinigung
        $h = array_column($fc['historical'], 'kwh', 'ym');
        self::assertEqualsWithDelta(6.0 * 31, $h['2023-07'], 0.01);
    }

    public function testImportIsOnlyAHintAsShipped(): void
    {
        $this->battery();
        $m = $this->importMeter(self::firsts('2023-01-01', '2025-01-01'));
        $fc = $this->forecast()->forMeter('strom', $m, ['today' => '2024-07-01']);
        // alter Weg: Juli nur aus 2023 (10 kWh je Tag), Januar Mittel aus 2023 und 2024
        self::assertEqualsWithDelta(10.0 * 31, self::row($fc, '2024-07')['kwh'], 0.06);
        self::assertEqualsWithDelta((10.0 + 10.0 - 0.9 * 2.2) / 2 * 31, self::row($fc, '2025-01')['kwh'], 0.06);
        $w = self::warnings($fc, 'battery_history');
        self::assertSame('not_validated', $w[0]['reason'] ?? null);
        self::assertSame('import', $w[0]['kind'] ?? null);
    }

    public function testImportSwitchedOnLosesTheDischarge(): void
    {
        $this->consumption->setDeviceArrival(true);
        $this->battery();
        $m = $this->importMeter(self::firsts('2023-01-01', '2025-01-01'));
        $fc = $this->forecast()->forMeter('strom', $m, ['today' => '2024-07-01']);
        $flat = self::flat(fn(int $t) => self::discharge($t));
        self::assertEqualsWithDelta((10.0 - $flat) * 31, self::row($fc, '2024-07')['kwh'], 0.06);
        self::assertEqualsWithDelta((10.0 - 0.9 * 2.2) * 31, self::row($fc, '2025-01')['kwh'], 0.06);
        self::assertSame('adjusted', self::warnings($fc, 'battery_history')[0]['reason']);
    }

    public function testHtNtRegistersShareTheDischargeByTheirImport(): void
    {
        $this->consumption->setDeviceArrival(true);
        $this->battery();
        $dates = self::firsts('2023-01-01', '2025-01-01');
        // ein Zähler mit zwei Zählwerken: HT 7, NT 3 kWh je Tag vor dem Speicher; danach jedes um seinen Anteil der Entladung weniger
        $ht = $this->meter('strom', 'm_ht', $dates, fn(int $t) => date('Y-m-d', $t) < self::BATTERY ? 7.0 : 7.0 - 0.7 * self::discharge($t), ['meter_group_id' => 'g_z']);
        $nt = $this->meter('strom', 'm_nt', $dates, fn(int $t) => date('Y-m-d', $t) < self::BATTERY ? 3.0 : 3.0 - 0.3 * self::discharge($t), ['meter_group_id' => 'g_z']);
        $this->store->write('strom/meter_groups.json', [['id' => 'g_z', 'name' => 'Zähler', 'created_at' => '2023-01-01']]);
        $flat = self::flat(fn(int $t) => self::discharge($t));
        $fHt = $this->forecast()->forMeter('strom', $ht, ['today' => '2024-07-01']);
        $fNt = $this->forecast()->forMeter('strom', $nt, ['today' => '2024-07-01']);
        self::assertEqualsWithDelta((7.0 - 0.7 * $flat) * 31, self::row($fHt, '2024-07')['kwh'], 0.06);
        self::assertEqualsWithDelta((3.0 - 0.3 * $flat) * 31, self::row($fNt, '2024-07')['kwh'], 0.06);
        self::assertEqualsWithDelta((7.0 - 0.7 * 0.9 * 2.2) * 31, self::row($fHt, '2025-01')['kwh'], 0.06);
    }

    public function testFewerThanThreeMonthsOrOneSideLeaveTheMonthsAsTheyAre(): void
    {
        $this->consumption->setDeviceArrival(true, true);
        $this->battery();
        $feed = $this->feedMeter(self::firsts('2023-01-01', '2025-01-01'));
        // Stichtag 01.03.2024: der Speicher hat zwei Monate gemessen; die Ablesungen danach zählen an diesem Tag nicht
        $fc = $this->forecast()->forMeter('pv_einspeisung', $feed, ['today' => '2024-03-01']);
        self::assertEqualsWithDelta(6.0 * 31, self::row($fc, '2024-07')['kwh'], 0.06);
        self::assertSame('few_months', self::warnings($fc, 'battery_history')[0]['reason']);
        self::assertSame(2, self::warnings($fc, 'battery_history')[0]['months']);
    }

    public function testOnlyAChargeMeterAdjustsTheFeedInButNotTheImport(): void
    {
        $this->consumption->setDeviceArrival(true, true);
        $this->battery('2025-01-01', false);
        $feed = $this->feedMeter(self::firsts('2023-01-01', '2025-01-01'));
        $imp = $this->importMeter(self::firsts('2023-01-01', '2025-01-01'));
        self::assertSame('adjusted', self::warnings($this->forecast()->forMeter('pv_einspeisung', $feed, ['today' => '2024-07-01']), 'battery_history')[0]['reason']);
        $fc = $this->forecast()->forMeter('strom', $imp, ['today' => '2024-07-01']);
        self::assertSame('one_side', self::warnings($fc, 'battery_history')[0]['reason']);
        self::assertEqualsWithDelta(10.0 * 31, self::row($fc, '2024-07')['kwh'], 0.06);
    }

    /** Speicher und Zäsur am 15.01.2024: Auch der halbe Januar davor zählt nicht mehr (Übergangsmonat der Zäsur). */
    public function testABaselineEventFromTheBatteryOnMakesTheCorrectionAndTheHintUnnecessary(): void
    {
        $dates = array_merge(['2024-01-15'], self::firsts('2024-02-01', '2025-01-01'));
        $this->meter('pv_erzeugung', 'm_bat_in', $dates, fn(int $t) => self::charge($t), ['role' => 'battery_charge']);
        $this->meter('pv_erzeugung', 'm_bat_out', $dates, fn(int $t) => self::discharge($t), ['role' => 'battery_discharge']);
        $feed = $this->meter('pv_einspeisung', 'm_feed', self::firsts('2023-01-01', '2025-01-01'),
            fn(int $t) => date('Y-m-d', $t) < '2024-01-15' ? 6.0 : 6.0 - self::charge($t));
        $plain = $this->forecast()->forMeter('pv_einspeisung', $feed, ['today' => '2024-07-01']);
        self::assertSame('adjusted', self::warnings($plain, 'battery_history')[0]['reason'] ?? null, 'ohne Zäsur bereinigt');
        $feed['baseline_events'] = [['date' => '2024-01-15', 'label' => 'Speicher']];
        $this->store->write('pv_einspeisung/meters.json', [$feed]);
        $fc = $this->forecast()->forMeter('pv_einspeisung', $this->meters->get('pv_einspeisung', 'm_feed'), ['today' => '2024-07-01']);
        self::assertSame([], self::warnings($fc, 'battery_history'));
    }

    /** Zwei Jahre nach dem Einbau kein Hinweis mehr (Lektion 93); die Bereinigung wirkt weiter. */
    public function testTheHintsHaveAWindowOfTwoYearsTheAdjustmentStays(): void
    {
        $this->consumption->setDeviceArrival(true, true);
        $this->battery('2026-03-01');
        $feed = $this->feedMeter(self::firsts('2023-01-01', '2026-03-01'));
        $imp = $this->importMeter(self::firsts('2023-01-01', '2026-03-01'));
        self::assertNotSame([], self::warnings($this->forecast()->forMeter('pv_einspeisung', $feed, ['today' => '2025-12-31']), 'battery_history'));
        $late = $this->forecast()->forMeter('pv_einspeisung', $feed, ['today' => '2026-01-01']);
        self::assertSame([], self::warnings($late, 'battery_history'));
        // Juli: 2023 bereinigt (6 − c(7)) neben 2024 und 2025 gemessen (6 − c(7)) → 6 − c(7) je Tag
        self::assertEqualsWithDelta((6.0 - (2.0 + 0.2 * 7)) * 31, self::row($late, '2026-07')['kwh'], 0.06);
        self::assertSame([], self::warnings($this->forecast()->forMeter('strom', $imp, ['today' => '2026-01-01']), 'battery_history'));
    }

    /** Am Speicher sind der Netzanschluss und der Zähler, hinter dem die PV sitzt; ein Wärmepumpenzähler daneben nicht. */
    public function testOnlyMetersAtTheBatteryAreAdjusted(): void
    {
        $this->consumption->setDeviceArrival(true);
        $this->battery();
        $dates = self::firsts('2023-01-01', '2025-01-01');
        $wp = $this->meter('strom', 'm_wp', $dates, fn() => 8.0, ['role' => 'heat_pump']);
        $main = $this->importMeter($dates);
        self::assertSame([], self::warnings($this->forecast()->forMeter('strom', $wp, ['today' => '2024-07-01']), 'battery_history'), 'eigener Anschluss der Wärmepumpe');
        self::assertEqualsWithDelta(8.0 * 31, self::row($this->forecast()->forMeter('strom', $wp, ['today' => '2024-07-01']), '2024-07')['kwh'], 0.06);
        self::assertSame('adjusted', self::warnings($this->forecast()->forMeter('strom', $main, ['today' => '2024-07-01']), 'battery_history')[0]['reason']);
        // ein Subzähler ohne PV dahinter bleibt, wie er ist; mit der PV dahinter (cascade_meter_id) ist er am Speicher
        $sub = $this->meter('strom', 'm_sub', $dates, fn() => 4.0, ['parent_meter_id' => 'm_bezug']);
        self::assertSame([], self::warnings($this->forecast()->forMeter('strom', $sub, ['today' => '2024-07-01']), 'battery_history'));
        $this->meter('pv_einspeisung', 'm_feed_sub', $dates, fn() => 1.0, ['cascade_meter_id' => 'm_sub']);
        self::assertNotSame([], self::warnings($this->forecast()->forMeter('strom', $this->meters->get('strom', 'm_sub'), ['today' => '2024-07-01']), 'battery_history'));
    }

    /** Der Einbau ist der früheste in der Gerätekette: Ein Zählertausch am Speicherzähler ist kein neuer Speicher. */
    public function testTheInstallationIsTheEarliestDevice(): void
    {
        $this->battery();
        $all = $this->metersOf['pv_erzeugung'];
        foreach ($all as &$m) {
            $m['devices'] = [
                ['id' => 'd_new_' . $m['id'], 'serial' => null, 'installed_on' => '2024-05-01', 'initial_counter' => 0.0, 'removed_on' => null, 'final_counter' => null, 'reason' => null],
                $m['devices'][0] + ['removed_on' => '2024-05-01'],
            ];
        }
        unset($m);
        $this->store->write('pv_erzeugung/meters.json', $all);
        $feed = $this->feedMeter(self::firsts('2023-01-01', '2025-01-01'));
        $w = self::warnings($this->forecast()->forMeter('pv_einspeisung', $feed, ['today' => '2024-07-01']), 'battery_history');
        self::assertSame(self::BATTERY, $w[0]['since'] ?? null);
    }

    /** Was der Speicher lädt, kann die Einspeisung eines Monats davor nicht unter null drücken. */
    public function testTheAdjustedMonthNeverGoesBelowZero(): void
    {
        $this->battery();
        // wenig Einspeisung vor dem Speicher (1 kWh je Tag), der Speicher lädt 2,2 bis 3,2 kWh
        $feed = $this->meter('pv_einspeisung', 'm_feed', self::firsts('2023-01-01', '2025-01-01'),
            fn(int $t) => date('Y-m-d', $t) < self::BATTERY ? 1.0 : 0.0);
        $fc = $this->forecast()->forMeter('pv_einspeisung', $feed, ['today' => '2024-07-01']);
        self::assertSame(0.0, (float)self::row($fc, '2024-08')['kwh']);
        foreach ($fc['forecast'] as $r) self::assertGreaterThanOrEqual(0.0, (float)$r['kwh'], $r['ym']);
    }

    public function testAMeterWithoutMonthsBeforeTheBatteryGetsNothing(): void
    {
        $this->battery();
        $feed = $this->meter('pv_einspeisung', 'm_feed', self::firsts(self::BATTERY, '2025-01-01'), fn(int $t) => 6.0 - self::charge($t));
        $fc = $this->forecast()->forMeter('pv_einspeisung', $feed, ['today' => '2024-07-01']);
        self::assertSame([], self::warnings($fc, 'battery_history'));
    }

    /**
     * Seltene Ablesungen (vierteljährlich): Unter zwölf gemessenen Kalendermonaten folgt die Jahresmenge den letzten
     * 365 Tagen (`level_last_year`). Die kommen aus den bereinigten Monaten; aus den gemessenen läge das Jahr mit
     * einem halben Jahr vor dem Speicher bei (10 + 7) / 2 = 8,5 kWh je Tag statt 7.
     */
    public function testTheLastYearLevelComesFromTheAdjustedMonths(): void
    {
        $this->consumption->setDeviceArrival(true);
        $dates = self::firsts(self::BATTERY, '2025-01-01');
        $this->meter('pv_erzeugung', 'm_bat_in', $dates, fn() => 3.3, ['role' => 'battery_charge']);
        $this->meter('pv_erzeugung', 'm_bat_out', $dates, fn() => 3.0, ['role' => 'battery_discharge']);
        $m = $this->meter('strom', 'm_bezug', self::firsts('2023-07-01', '2025-01-01', 3), fn(int $t) => date('Y-m-d', $t) < self::BATTERY ? 10.0 : 7.0);
        $fc = $this->forecast()->forMeter('strom', $m, ['today' => '2024-07-01']);
        self::assertNotSame([], self::warnings($fc, 'level_last_year'), 'Weg über die letzten 365 Tage');
        self::assertEqualsWithDelta(7.0 * 365, $fc['annual']['value'], 1.0);
    }

    // ── Wallbox ─────────────────────────────────────────────────────────

    private function temperatures(): void
    {
        $temps = [];
        for ($t = strtotime('2023-01-01 12:00'); $t <= strtotime('2024-12-31 12:00'); $t += 86400) {
            $v = self::TEMP[(int)date('n', $t)];
            $temps[date('Y-m-d', $t)] = ['avg' => $v, 'min' => $v - 3, 'max' => $v + 3, 'source' => 'archive'];
        }
        $this->store->write('temperatures.json', $temps);
    }

    /** Faktor der Annahme für einen Kalendermonat (Gradtage unter 20 °C je Tag × 1 %). */
    private static function evFactor(int $mn): float
    {
        return 1.0 + 0.01 * max(0.0, 20.0 - self::TEMP[$mn]);
    }

    private function wallbox(array $dates, callable $perDay, ?string $parent = 'm_bezug'): array
    {
        return $this->meter('strom', 'm_wallbox', $dates, $perDay, ['role' => 'ev_charger', 'parent_meter_id' => $parent], self::WALLBOX);
    }

    public function testWallboxMonthsWithoutHistoryFollowTheNamedAssumption(): void
    {
        $this->temperatures();
        $this->meter('strom', 'm_bezug', self::firsts('2023-01-01', '2024-11-01'), fn(int $t) => date('Y-m-d', $t) < self::WALLBOX ? 10.0 : 15.0);
        $w = $this->wallbox(self::firsts(self::WALLBOX, '2024-11-01'), fn() => 5.0);
        $fc = $this->forecast()->forMeter('strom', $w, ['today' => '2024-11-01']);
        self::assertTrue($fc['valid']);
        // r0 = gemessen ÷ Σ Tage × Faktor (Mai bis Oktober)
        $num = 0.0; $den = 0.0;
        foreach ([5, 6, 7, 8, 9, 10] as $mn) { $d = (int)date('t', mktime(0, 0, 0, $mn, 1, 2024)); $num += 5.0 * $d; $den += $d * self::evFactor($mn); }
        $r0 = $num / $den;
        self::assertSame('ev_assumed', self::row($fc, '2025-01')['method']);
        self::assertEqualsWithDelta($r0 * self::evFactor(1) * 31, self::row($fc, '2025-01')['kwh'], 0.06);
        self::assertEqualsWithDelta($r0 * self::evFactor(12) * 31, self::row($fc, '2024-12')['kwh'], 0.06);
        self::assertSame('seasonal_only', self::row($fc, '2025-07')['method']);
        self::assertEqualsWithDelta(5.0 * 31, self::row($fc, '2025-07')['kwh'], 0.06);
        $ev = self::warnings($fc, 'ev_months');
        self::assertSame([11, 12, 1, 2, 3, 4], $ev[0]['assumed']);
        self::assertSame([], $ev[0]['sessions']);
    }

    /** Ohne Temperaturen gäbe es keinen Faktor: Dann bleibt es beim Mittel aller Monate (`filled`), nicht „Annahme“. */
    public function testWithoutTemperaturesTheMonthsStayFilled(): void
    {
        $this->meter('strom', 'm_bezug', self::firsts('2023-01-01', '2024-11-01'), fn() => 10.0);
        $w = $this->wallbox(self::firsts(self::WALLBOX, '2024-11-01'), fn() => 5.0);
        $fc = $this->forecast()->forMeter('strom', $w, ['today' => '2024-11-01']);
        self::assertSame('filled', self::row($fc, '2025-01')['method']);
        self::assertSame([], self::warnings($fc, 'ev_months'));
    }

    public function testWallboxMonthsOnlyDistributedBetweenReadingsComeFromTheSessions(): void
    {
        $this->temperatures();
        $this->meter('strom', 'm_bezug', self::firsts('2023-01-01', '2024-11-01'), fn() => 10.0);
        // Wallbox nur vierteljährlich abgelesen (Monate verteilt), evcc kennt jeden Ladevorgang
        $perMonth = [5 => 120.0, 6 => 150.0, 7 => 180.0, 8 => 160.0, 9 => 140.0, 10 => 130.0];
        $w = $this->wallbox(['2024-05-01', '2024-08-01', '2024-11-01'], fn(int $t) => $perMonth[(int)date('n', $t)] / (int)date('t', $t));
        $sessions = [];
        foreach ($perMonth as $mn => $kwh) {
            $sessions[] = ['id' => "evs_$mn", 'meter_id' => 'm_wallbox', 'date' => sprintf('2024-%02d-15', $mn), 'charged_kwh' => $kwh, 'solar_pct' => 50.0, 'source' => 'evcc'];
        }
        // ein Ladevorgang nach dem Stichtag zählt nicht
        $sessions[] = ['id' => 'evs_late', 'meter_id' => 'm_wallbox', 'date' => '2025-06-15', 'charged_kwh' => 999.0, 'solar_pct' => 0.0, 'source' => 'evcc'];
        $this->store->write('ev_sessions.json', $sessions);
        $fc = $this->forecast()->forMeter('strom', $w, ['today' => '2024-11-01']);
        self::assertSame('ev_sessions', self::row($fc, '2025-06')['method']);
        self::assertEqualsWithDelta(150.0, self::row($fc, '2025-06')['kwh'], 0.06);
        self::assertEqualsWithDelta(180.0, self::row($fc, '2025-07')['kwh'], 0.06);
        self::assertSame([5, 6, 7, 8, 9, 10], self::warnings($fc, 'ev_months')[0]['sessions']);
    }

    public function testWallboxParentIsOnlyAHintAsShipped(): void
    {
        $this->temperatures();
        $p = $this->meter('strom', 'm_bezug', self::firsts('2023-01-01', '2024-11-01'), fn(int $t) => date('Y-m-d', $t) < self::WALLBOX ? 10.0 : 15.0);
        $this->wallbox(self::firsts(self::WALLBOX, '2024-11-01'), fn() => 5.0);
        $fc = $this->forecast()->forMeter('strom', $p, ['today' => '2024-11-01']);
        self::assertEqualsWithDelta(10.0 * 31, self::row($fc, '2025-01')['kwh'], 0.06);
        $w = self::warnings($fc, 'ev_history');
        self::assertSame('not_validated', $w[0]['reason'] ?? null);
        self::assertSame('m_wallbox', $w[0]['meter_id'] ?? null);
    }

    public function testWallboxParentSwitchedOnGetsTheWallboxInTheMonthsBefore(): void
    {
        $this->consumption->setDeviceArrival(null, null, true);
        $this->temperatures();
        $p = $this->meter('strom', 'm_bezug', self::firsts('2023-01-01', '2024-11-01'), fn(int $t) => date('Y-m-d', $t) < self::WALLBOX ? 10.0 : 15.0);
        $this->wallbox(self::firsts(self::WALLBOX, '2024-11-01'), fn() => 5.0);
        $fc = $this->forecast()->forMeter('strom', $p, ['today' => '2024-11-01']);
        // ohne PV ist alles Netzbezug: Juli gemessen im selben Kalendermonat, Januar nach der Annahme
        self::assertEqualsWithDelta(15.0 * 31, self::row($fc, '2025-07')['kwh'], 0.06);
        $num = 0.0; $den = 0.0;
        foreach ([5, 6, 7, 8, 9, 10] as $mn) { $d = (int)date('t', mktime(0, 0, 0, $mn, 1, 2024)); $num += 5.0 * $d; $den += $d * self::evFactor($mn); }
        self::assertEqualsWithDelta((10.0 + $num / $den * self::evFactor(1)) * 31, self::row($fc, '2025-01')['kwh'], 0.06);
        self::assertSame('adjusted', self::warnings($fc, 'ev_history')[0]['reason']);
    }

    public function testWithSunAndSessionsOnlyTheGridShareOfTheWallboxCounts(): void
    {
        $this->consumption->setDeviceArrival(null, null, true);
        $this->temperatures();
        // PV vorhanden; die Ladevorgänge sagen: 40 % aus der Sonne
        $this->meter('pv_erzeugung', 'm_pv', self::firsts('2023-01-01', '2024-11-01'), fn() => 20.0);
        $p = $this->meter('strom', 'm_bezug', self::firsts('2023-01-01', '2024-11-01'), fn(int $t) => date('Y-m-d', $t) < self::WALLBOX ? 10.0 : 13.0);
        $this->wallbox(self::firsts(self::WALLBOX, '2024-11-01'), fn() => 5.0);
        $sessions = [];
        foreach ([5, 6, 7, 8, 9, 10] as $mn) {
            $d = (int)date('t', mktime(0, 0, 0, $mn, 1, 2024));
            $sessions[] = ['id' => "evs_$mn", 'meter_id' => 'm_wallbox', 'date' => sprintf('2024-%02d-10', $mn), 'charged_kwh' => 5.0 * $d, 'solar_pct' => 40.0, 'source' => 'evcc'];
        }
        $this->store->write('ev_sessions.json', $sessions);
        $fc = $this->forecast()->forMeter('strom', $p, ['today' => '2024-11-01']);
        // Juli: vorher 10 + 60 % von 5 = 13 kWh je Tag, wie gemessen
        self::assertEqualsWithDelta(13.0 * 31, self::row($fc, '2025-07')['kwh'], 0.06);
        // Januar: Annahme r0 × Faktor, davon die Sonne nach der Erzeugung des Monats (2 kWh je 20 kWh Erzeugung)
        $num = 0.0; $den = 0.0;
        foreach ([5, 6, 7, 8, 9, 10] as $mn) { $d = (int)date('t', mktime(0, 0, 0, $mn, 1, 2024)); $num += 5.0 * $d; $den += $d * self::evFactor($mn); }
        $total = $num / $den * self::evFactor(1);
        self::assertEqualsWithDelta((10.0 + $total - 2.0) * 31, self::row($fc, '2025-01')['kwh'], 0.1);
    }

    public function testAWallboxWithFewerThanThreeMonthsOnlyGivesAHint(): void
    {
        $this->consumption->setDeviceArrival(null, null, true);
        $this->temperatures();
        $p = $this->meter('strom', 'm_bezug', self::firsts('2023-01-01', '2024-07-01'), fn(int $t) => date('Y-m-d', $t) < self::WALLBOX ? 10.0 : 15.0);
        $this->wallbox(self::firsts(self::WALLBOX, '2024-07-01'), fn() => 5.0);
        $fc = $this->forecast()->forMeter('strom', $p, ['today' => '2024-07-01']);
        self::assertSame('few_months', self::warnings($fc, 'ev_history')[0]['reason']);
        self::assertSame(2, self::warnings($fc, 'ev_history')[0]['months']);
        self::assertEqualsWithDelta(10.0 * 31, self::row($fc, '2025-01')['kwh'], 0.06);
    }

    /**
     * Stichtag mitten im Monat: Ein Ladevorgang nach dem Stichtag (hier aus dem Netz) zählt für den Anteil der Sonne
     * im laufenden Monat nicht mit; an diesem Tag kennt die App nur den mittags aus der Sonne.
     */
    public function testSessionsAfterTheCutDoNotCount(): void
    {
        $this->consumption->setDeviceArrival(null, null, true);
        $this->temperatures();
        $this->meter('pv_erzeugung', 'm_pv', self::firsts('2023-01-01', '2024-11-01'), fn() => 20.0);
        $dates = array_merge(self::firsts('2023-01-01', '2024-10-01'), ['2024-10-25']);
        $p = $this->meter('strom', 'm_bezug', $dates, fn() => 10.0);
        $this->wallbox(array_merge(self::firsts(self::WALLBOX, '2024-10-01'), ['2024-10-25']), fn() => 5.0);
        $sessions = [];
        foreach ([5, 6, 7, 8, 9] as $mn) $sessions[] = ['id' => "evs_$mn", 'meter_id' => 'm_wallbox', 'date' => sprintf('2024-%02d-10', $mn), 'charged_kwh' => 100.0, 'solar_pct' => 100.0, 'source' => 'evcc'];
        $sessions[] = ['id' => 'evs_10a', 'meter_id' => 'm_wallbox', 'date' => '2024-10-10', 'charged_kwh' => 100.0, 'solar_pct' => 100.0, 'source' => 'evcc'];
        $sessions[] = ['id' => 'evs_10b', 'meter_id' => 'm_wallbox', 'date' => '2024-10-28', 'charged_kwh' => 100.0, 'solar_pct' => 0.0, 'source' => 'evcc'];
        $this->store->write('ev_sessions.json', $sessions);
        $fc = $this->forecast()->forMeter('strom', $p, ['today' => '2024-10-25']);
        // Oktober: am Stichtag alles aus der Sonne, also bekommt der Oktober davor nichts dazu
        self::assertEqualsWithDelta(10.0 * 31, self::row($fc, '2025-10')['kwh'], 0.06);
    }

    public function testWithSunButWithoutSessionsTheGridShareIsUnknown(): void
    {
        $this->consumption->setDeviceArrival(null, null, true);
        $this->meter('pv_erzeugung', 'm_pv', self::firsts('2023-01-01', '2024-11-01'), fn() => 20.0);
        $p = $this->meter('strom', 'm_bezug', self::firsts('2023-01-01', '2024-11-01'), fn() => 10.0);
        $this->wallbox(self::firsts(self::WALLBOX, '2024-11-01'), fn() => 5.0);
        $fc = $this->forecast()->forMeter('strom', $p, ['today' => '2024-11-01']);
        self::assertSame('no_solar_share', self::warnings($fc, 'ev_history')[0]['reason']);
        self::assertEqualsWithDelta(10.0 * 31, self::row($fc, '2025-01')['kwh'], 0.06);
    }

    /**
     * Kaskade wie #40: vorn Z1 mit der Wärmepumpe, dahinter Z2 Haushalt mit eigenem Vertrag. Die Wallbox hängt am
     * vorderen Zähler (ein Subzähler darf keine haben), steckt aber im Haushalt; beide bekommen den Hinweis.
     */
    public function testInACascadeTheHouseholdBehindIsAParentOfTheWallboxToo(): void
    {
        $this->temperatures();
        $dates = self::firsts('2023-01-01', '2024-11-01');
        $this->meter('strom', 'm_z1', $dates, fn() => 20.0, ['role' => 'heat_pump']);
        $z2 = $this->meter('strom', 'm_z2', $dates, fn() => 10.0, ['parent_meter_id' => 'm_z1']);
        $this->wallbox(self::firsts(self::WALLBOX, '2024-11-01'), fn() => 5.0, 'm_z1');
        $this->contracts->create('strom', ['meter_id' => 'm_z2', 'provider' => 'Testversorger', 'start' => '2023-01-01',
            'working_prices' => [['from' => '2023-01-01', 'ct_per_kwh' => 30.0]]]);
        $fc = $this->forecast()->forMeter('strom', $this->meters->get('strom', 'm_z2'), ['today' => '2024-11-01']);
        self::assertSame('not_validated', self::warnings($fc, 'ev_history')[0]['reason'] ?? null);
        $f1 = $this->forecast()->forMeter('strom', $this->meters->get('strom', 'm_z1'), ['today' => '2024-11-01']);
        self::assertNotSame([], self::warnings($f1, 'ev_history'));
        self::assertNotNull($z2);
    }

    // ── Doku ────────────────────────────────────────────────────────────

    /** Die Doku sagt, was ausgeliefert ist (Schalter), und nennt die Annahme mit ihren Zahlen. */
    public function testTheDocumentationStatesWhatIsSwitchedOn(): void
    {
        $root = dirname(__DIR__, 3);
        $want = [
            'docs/verstehen/12-pv.md' => ['<!-- doc-claim: battery-arrival -->',
                DeviceArrival::BATTERY_FEED_ENABLED ? 'Einspeisung der Monate davor um die Ladung' : 'Einspeisung bleibt',
                DeviceArrival::BATTERY_IMPORT_ENABLED ? 'Bezug um die Entladung' : 'Bezug rechnet weiter mit den Monaten davor',
                'drei Monate'],
            'docs/en/verstehen/12-pv.md' => ['<!-- doc-claim: battery-arrival -->',
                DeviceArrival::BATTERY_FEED_ENABLED ? 'feed-in of the months before it by the charging' : 'feed-in stays',
                DeviceArrival::BATTERY_IMPORT_ENABLED ? 'import by the discharging' : 'import still uses the months before it',
                'three months'],
            'docs/verstehen/02-strom.md' => ['<!-- doc-claim: wallbox-arrival -->',
                DeviceArrival::EV_MONTHS_ENABLED ? 'nach einer Annahme' : 'Mittel aller Monate',
                (int)round(DeviceArrival::EV_PER_KELVIN * 100) . ' % mehr Strom', 'unter ' . (int)DeviceArrival::EV_TEMP_REF . ' °C',
                DeviceArrival::EV_PARENT_ENABLED ? 'bekommen den Netzbezug der Wallbox' : 'rechnet weiter mit den Monaten davor'],
            'docs/en/verstehen/02-strom.md' => ['<!-- doc-claim: wallbox-arrival -->',
                DeviceArrival::EV_MONTHS_ENABLED ? 'by an assumption' : 'average of all months',
                (int)round(DeviceArrival::EV_PER_KELVIN * 100) . '% more electricity', 'below ' . (int)DeviceArrival::EV_TEMP_REF . ' °C',
                DeviceArrival::EV_PARENT_ENABLED ? 'get the grid consumption of the wallbox' : 'still uses the months before it'],
        ];
        foreach ($want as $file => $phrases) {
            $text = (string)file_get_contents("$root/$file");
            $pos = strpos($text, $phrases[0]);
            self::assertNotFalse($pos, "$file: Abschnitt fehlt");
            $part = (string)preg_replace('/\s+/u', ' ', substr($text, $pos, 2200));
            foreach (array_slice($phrases, 1) as $p) self::assertStringContainsString($p, $part, "$file: $p");
        }
    }
}
