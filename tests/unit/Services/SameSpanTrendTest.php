<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\BillingPeriodService;
use Energietracker\Services\BillService;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * v3.10.0 (GitHub #36) — Trendpfeile im Abrechnungsjahr: Die Übersicht vergleicht
 * die jüngste Periode mit Daten mit derselben Spanne der Periode davor (gleich
 * viele Tage ab deren Beginn), nicht mit dem ganzen Vorjahr. Bis v3.9 gab es im
 * Abrechnungsjahr gar keinen Pfeil.
 *
 * Erwartungen von Hand: Strom, Ablesung am Monatsersten, 200 kWh je Monat 2024,
 * 250 kWh 2025, 300 kWh 2026; 30 ct/kWh, 10 €/Monat Grundpreis.
 */
#[CoversClass(BillingPeriodService::class)]
final class SameSpanTrendTest extends ServiceTestCase
{
    private string $meterId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->meterId = $this->setMeterDevices('strom', [[
            'id' => 'd1', 'serial' => null, 'installed_on' => '2024-01-01', 'initial_counter' => 0.0,
            'removed_on' => null, 'final_counter' => null, 'reason' => null,
        ]]);
    }

    private function service(): BillingPeriodService
    {
        $bills = new BillService($this->store, $this->meters, $this->contracts, $this->consumption, $this->i18n);
        return new BillingPeriodService($this->settings, $this->meters, $this->readings, $this->consumption, $bills);
    }

    /**
     * Ablesungen am Monatsersten ab $from bis $until (einschließlich), dazu $extra (Datum => kWh nach dem Monatsersten davor).
     *
     * @param array<string,float> $extra
     */
    private function seed(string $until, string $from = '2024-01-01', array $extra = [], bool $contract = true): void
    {
        $perMonth = ['2024' => 200.0, '2025' => 250.0, '2026' => 300.0];
        $rows = [];
        $counter = 0.0;
        for ($m = 0; ; $m++) {
            $d = date('Y-m-d', (int)strtotime("2024-01-01 +$m months"));
            if ($d > $until) break;
            if ($d >= $from) $rows[] = ['date' => $d, 'counter' => $counter, 'device_id' => 'd1'];
            foreach ($extra as $day => $kwh) {
                if (substr($day, 0, 7) === substr($d, 0, 7) && $day > $d) $rows[] = ['date' => $day, 'counter' => $counter + $kwh, 'device_id' => 'd1'];
            }
            $counter += $perMonth[substr($d, 0, 4)] ?? 300.0;
        }
        usort($rows, fn($a, $b) => strcmp($a['date'], $b['date']));
        $this->setReadings('strom', $this->meterId, $rows);
        if ($contract) {
            $this->contracts->create('strom', [
                'meter_id' => $this->meterId, 'provider' => 'A', 'tariff_name' => 'T', 'start' => '2024-01-01',
                'working_prices'   => [['from' => '2024-01-01', 'ct_per_kwh' => 30.0]],
                'base_prices'      => [['from' => '2024-01-01', 'eur_per_month' => 10.0]],
                'advance_payments' => [['from' => '2024-01-01', 'amount_eur' => 90.0]],
            ]);
        }
    }

    /** @return array<string,mixed> die jüngste Periode mit Daten (die der Übersicht) */
    private function shown(string $today): array
    {
        $r = $this->service()->forUtility('strom', false, $today);
        $with = array_values(array_filter($r['periods'], fn($p) => $p['days'] > 0));
        return $with[count($with) - 1];
    }

    public function testTheRunningPeriodIsComparedWithTheSameDaysOfThePeriodBefore(): void
    {
        $this->seed('2026-03-01');
        $p = $this->shown('2026-03-15');
        self::assertSame(['2026-01-01', '2026-02-28', 59], [$p['from'], $p['as_of'], $p['days']]);
        self::assertEqualsWithDelta(600.0, $p['kwh'], 0.5, 'Januar und Februar 2026');
        $s = $p['prev_same_span'];
        self::assertSame(['2025-01-01', '2025-02-28', 59, false, 'period'], [$s['from'], $s['until'], $s['days'], $s['whole'], $s['basis'] ?? null],
            'dieselben 59 Tage ein Abrechnungsjahr früher, nicht das ganze Jahr 2025');
        self::assertEqualsWithDelta(500.0, $s['kwh'], 0.5, 'Januar und Februar 2025: 2 × 250 kWh');
        self::assertEqualsWithDelta(500 * 0.30 + 2 * 10.0, $s['cost'], 0.05, 'Arbeitspreis × Menge + zwei Monate Grundpreis');
        self::assertEqualsWithDelta(20.0, ($p['kwh'] / $s['kwh'] - 1) * 100, 0.1, '+20 %, gegen das ganze Vorjahr wären es −80 %');
    }

    public function testTheSpanEndsAtTheLastReadingToTheDay(): void
    {
        // letzte Ablesung am 15.02.2026, 150 kWh nach dem 01.02. → 45 Tage (01.01.–14.02.)
        $this->seed('2026-02-01', '2024-01-01', ['2026-02-15' => 150.0]);
        $p = $this->shown('2026-02-20');
        self::assertSame(['2026-02-14', 45], [$p['as_of'], $p['days']]);
        self::assertEqualsWithDelta(450.0, $p['kwh'], 0.5);
        $s = $p['prev_same_span'];
        self::assertSame(['2025-01-01', '2025-02-14', 45], [$s['from'], $s['until'], $s['days']]);
        // Januar 250 + 14 von 28 Februartagen aus 250 kWh = 125
        self::assertEqualsWithDelta(375.0, $s['kwh'], 0.5, 'tagesgenau, linear zwischen den Ablesungen');
    }

    public function testAStichtagInTheMiddleOfTheYearComparesAcrossTheTurnOfTheYear(): void
    {
        $this->seed('2026-03-01');
        $this->settings->set(['billing_cycle_anchor_strom' => '07-01']);
        $p = $this->shown('2026-03-15');
        self::assertSame(['2025-07-01', 243], [$p['from'], $p['days']]);
        self::assertEqualsWithDelta(6 * 250 + 2 * 300, $p['kwh'], 0.5);
        $s = $p['prev_same_span'];
        self::assertSame(['2024-07-01', '2025-02-28', 243], [$s['from'], $s['until'], $s['days']]);
        self::assertEqualsWithDelta(6 * 200 + 2 * 250, $s['kwh'], 0.5, 'Juli bis Dezember 2024 und Januar, Februar 2025');
    }

    public function testAPeriodCalculatedToItsEndFacesTheWholePeriodBefore(): void
    {
        // Ablesung am 01.01.2026 ist der Endstand von 2025; 2026 hat noch keine Daten
        $this->seed('2026-01-01');
        $p = $this->shown('2026-01-10');
        self::assertSame(['2025-01-01', '2025-12-31'], [$p['from'], $p['as_of']]);
        $s = $p['prev_same_span'];
        self::assertSame(['2024-01-01', '2024-12-31', 366, true], [$s['from'], $s['until'], $s['days'], $s['whole']],
            'ganz gegen ganz, auch wenn das Schaltjahr einen Tag länger ist');
        self::assertEqualsWithDelta(12 * 200.0, $s['kwh'], 0.5);
    }

    public function testThereIsNoComparisonWithAPartialFirstPeriod(): void
    {
        $this->seed('2026-03-01', '2025-03-01');
        $p = $this->shown('2026-03-15');
        self::assertSame('2026-01-01', $p['from']);
        self::assertNull($p['prev_same_span'], 'die Daten beginnen im März 2025: Januar und Februar fehlen im Vergleich, '
            . 'auch dieselben Kalendertage ein Jahr früher (ohne Daten im Vorjahr kein Pfeil)');
    }

    public function testThereIsNoComparisonWhenTheDataStartAFewDaysIntoThePeriodBefore(): void
    {
        // erste Ablesung am 20.01.2025: die Periode davor beginnt keine drei Wochen neben „ein Jahr
        // früher“, ist aber nur ein Teil — 20.01. bis 19.03. wären nicht dieselben Tage wie 01.01. bis 28.02.
        $this->seed('2026-03-01', '2025-01-20', ['2025-01-20' => 100.0]);
        $r = $this->service()->forUtility('strom', false, '2026-03-15');
        self::assertSame(['2025-01-20', false], [$r['periods'][0]['from'] ?? null, $r['periods'][0]['complete'] ?? null], 'Vorbedingung: Teilperiode ab der ersten Ablesung');
        self::assertNull($this->shown('2026-03-15')['prev_same_span']);
    }

    /** Lieferantenwechsel zum 01.07.2025 bei zwei Verträgen A (bis 30.06.2025) und B (ab 01.07.2025), je 30 ct und 10 € Grundpreis. */
    private function switchSupplierMidYear(string $on = '2025-07-01'): void
    {
        $before = date('Y-m-d', (int)strtotime("$on -1 day"));
        $this->contracts->create('strom', ['meter_id' => $this->meterId, 'provider' => 'A', 'tariff_name' => 'T', 'start' => '2024-01-01',
            'end' => $before, 'auto_renews' => false,
            'working_prices' => [['from' => '2024-01-01', 'ct_per_kwh' => 30.0]], 'base_prices' => [['from' => '2024-01-01', 'eur_per_month' => 10.0]]]);
        $this->contracts->create('strom', ['meter_id' => $this->meterId, 'provider' => 'B', 'tariff_name' => 'U', 'start' => $on,
            'working_prices' => [['from' => $on, 'ct_per_kwh' => 30.0]], 'base_prices' => [['from' => $on, 'eur_per_month' => 10.0]]]);
    }

    public function testASupplierSwitchMidYearFallsBackToTheSameCalendarDays(): void
    {
        // Die Periode davor beginnt am 01.07.2025, ein halbes Jahr neben „ein Jahr früher“: Januar/Februar
        // gegen Juli/August wäre kein Vergleich. Nachtrag #36: dieselben Kalendertage ein Jahr früher.
        $this->seed('2026-03-01', '2024-01-01', [], false);
        $this->switchSupplierMidYear();
        $r = $this->service()->forUtility('strom', false, '2026-03-15');
        $spans = array_map(fn($p) => $p['from'] . '..' . $p['until'], $r['periods']);
        self::assertContains('2025-07-01..2025-12-31', $spans, 'Vorbedingung: der Wechsel teilt das Jahr');
        $p = $this->shown('2026-03-15');
        self::assertSame(['2026-01-01', '2026-02-28', 59], [$p['from'], $p['as_of'], $p['days']]);
        $s = $p['prev_same_span'];
        self::assertSame(['2025-01-01', '2025-02-28', 59, 'calendar'], [$s['from'] ?? null, $s['until'] ?? null, $s['days'] ?? null, $s['basis'] ?? null],
            '01.01.–28.02.2025, über den Wechsel und die Periode davor hinweg');
        self::assertEqualsWithDelta(500.0, $s['kwh'], 0.5, 'Januar und Februar 2025: 2 × 250 kWh (beim alten Lieferanten)');
        self::assertEqualsWithDelta(500 * 0.30 + 2 * 10.0, $s['cost'], 0.05);
    }

    public function testAPeriodBeforeShorterThanTheSpanFallsBackToTheSameCalendarDays(): void
    {
        // Rechnung 01.01.–10.12.2025 (344 Tage), danach Stichtag 12-11: laufend ab 11.12.2025
        $this->seed('2026-12-01');
        $bills = new BillService($this->store, $this->meters, $this->contracts, $this->consumption, $this->i18n);
        $bills->create('strom', ['meter_id' => $this->meterId, 'period_from' => '2024-01-01', 'period_to' => '2024-12-31',
            'invoice' => ['amount_eur' => 1000.0, 'advances_paid_eur' => 1080.0]]);
        $bills->create('strom', ['meter_id' => $this->meterId, 'period_from' => '2025-01-01', 'period_to' => '2025-12-10',
            'invoice' => ['amount_eur' => 1000.0, 'advances_paid_eur' => 1080.0]]);
        $this->settings->set(['billing_cycle_anchor_strom' => '12-11']);
        $p = $this->shown('2026-12-05');
        self::assertSame(['2025-12-11', '2026-11-30', 355], [$p['from'], $p['as_of'], $p['days']]);
        // 355 Tage passen nicht in die Periode von 344 Tagen: 11.12.2024–30.11.2025
        $s = $p['prev_same_span'];
        self::assertSame(['2024-12-11', '2025-11-30', 355, 'calendar'], [$s['from'] ?? null, $s['until'] ?? null, $s['days'] ?? null, $s['basis'] ?? null]);
        // Dezember 2024: 21 von 31 Tagen aus 200 kWh = 135,48; Januar bis November 2025: 11 × 250 = 2.750
        self::assertEqualsWithDelta(135.48 + 2750.0, $s['kwh'], 0.1);
        // Arbeitspreis 30 ct + Grundpreis 21/31 × 10 € + 11 × 10 €
        self::assertEqualsWithDelta((135.48 + 2750.0) * 0.30 + 21 / 31 * 10.0 + 110.0, $s['cost'], 0.05);
        // im Frühjahr passt die Spanne noch hinein: die Periode davor gilt
        $this->setReadings('strom', $this->meterId, array_values(array_filter($this->readings->list('strom', $this->meterId), fn($r) => $r['date'] <= '2026-04-01')));
        $q = $this->shown('2026-04-05');
        self::assertSame(['2025-01-01', 111, 'period'], [$q['prev_same_span']['from'] ?? null, $q['prev_same_span']['days'] ?? null, $q['prev_same_span']['basis'] ?? null]);
    }

    public function testTheLeapDayBecomesTheTwentyEighth(): void
    {
        self::assertSame('2023-02-28', BillingPeriodService::yearEarlier('2024-02-29'), 'nicht der 01.03. (PHP modify -1 year)');
        self::assertSame('2027-02-28', BillingPeriodService::yearEarlier('2028-02-29'));
        self::assertSame('2024-02-28', BillingPeriodService::yearEarlier('2025-02-28'));
        self::assertSame('2024-03-01', BillingPeriodService::yearEarlier('2025-03-01'));
        self::assertSame('2025-12-31', BillingPeriodService::yearEarlier('2026-12-31'));
        self::assertSame('2025-01-01', BillingPeriodService::yearEarlier('2026-01-01'));
    }

    public function testTheCalendarDaysAYearEarlierIncludeTheLeapDay(): void
    {
        // Wechsel zum 01.07.2024 → die Periode davor (01.07.–31.12.2024) passt nicht; Ablesung am 15.03.2025:
        // 01.01.–14.03.2025 (73 Tage) gegen 01.01.–14.03.2024 (74 Tage, mit dem 29.02.)
        $this->seed('2025-03-01', '2024-01-01', ['2025-03-15' => 120.0], false);
        $this->switchSupplierMidYear('2024-07-01');
        $p = $this->shown('2025-03-20');
        self::assertSame(['2025-01-01', '2025-03-14', 73], [$p['from'], $p['as_of'], $p['days']]);
        $s = $p['prev_same_span'];
        self::assertSame(['2024-01-01', '2024-03-14', 74, 'calendar'], [$s['from'] ?? null, $s['until'] ?? null, $s['days'] ?? null, $s['basis'] ?? null],
            'dieselben Kalendertage; der 29.02.2024 gehört dazu');
        // Januar und Februar 2024 je 200 kWh, dazu 14 von 31 Märztagen aus 200 kWh = 90,32
        self::assertEqualsWithDelta(400.0 + 90.32, $s['kwh'], 0.1);
    }

    public function testTheSpanNeedsTwoWeeks(): void
    {
        $min = BillingPeriodService::SAME_SPAN_MIN_DAYS;
        $this->seed('2026-01-01', '2024-01-01', ['2026-01-' . sprintf('%02d', $min) => 100.0]);
        self::assertNull($this->shown('2026-01-20')['prev_same_span'], ($min - 1) . ' Tage: kein Pfeil');
        $this->setReadings('strom', $this->meterId, array_merge($this->readings->list('strom', $this->meterId),
            [['date' => '2026-01-' . sprintf('%02d', $min + 1), 'counter' => 7000.0, 'device_id' => 'd1']]));
        self::assertSame($min, $this->shown('2026-01-20')['prev_same_span']['days'] ?? null, "$min Tage: Pfeil");
    }

    /** Die Schwellen in der Doku hängen an den Konstanten (Lektion 31). */
    public function testTheDocsNameTheThresholds(): void
    {
        $flat = fn(string $rel) => (string)preg_replace('/\s+/u', ' ', (string)file_get_contents(dirname(__DIR__, 3) . '/' . $rel));
        $min = BillingPeriodService::SAME_SPAN_MIN_DAYS;
        $align = BillingPeriodService::SAME_SPAN_ALIGN_DAYS;
        foreach (['docs/referenz/ansichten.md' => ["weniger als $min Tage", "mehr als $align Tage neben"],
                  'docs/en/referenz/ansichten.md' => ["fewer than $min days", "more than $align days away"],
                  'docs/referenz/api.md' => ["unter $min Tagen", "mehr als $align Tage neben"],
                  'docs/en/referenz/api.md' => ["under $min days", "more than $align days away"]] as $file => $phrases) {
            foreach ($phrases as $p) self::assertStringContainsString($p, $flat($file), $file);
        }
        // Nachtrag #36 — Rückfall auf dieselben Kalendertage, Grundlage in der API, 29. Februar
        foreach (['docs/referenz/ansichten.md' => ['dieselben Kalendertage ein Jahr früher', 'Ein 29. Februar wird dabei zum 28. Februar'],
                  'docs/en/referenz/ansichten.md' => ['the same calendar days a year earlier', 'A 29 February becomes 28 February'],
                  'docs/referenz/api.md' => ['`basis: "period"`', '`basis: "calendar"`: dieselben Kalendertage ein Jahr früher', 'ein 29. Februar wird dort zum 28. Februar'],
                  'docs/en/referenz/api.md' => ['`basis: "period"`', '`basis: "calendar"` applies: the same calendar days a year earlier', 'a 29 February becomes 28 February']] as $file => $phrases) {
            foreach ($phrases as $p) self::assertStringContainsString($p, $flat($file), $file);
        }
        // der Tooltip, wie die Doku ihn zitiert, ist der Katalogtext
        foreach (['de' => ['docs/referenz/ansichten.md', '„%s“'], 'en' => ['docs/en/referenz/ansichten.md', '“%s”']] as $lang => [$file, $quote]) {
            $cat = json_decode((string)file_get_contents(dirname(__DIR__, 3) . "/public/locales/$lang.json"), true);
            $tip = (string)$cat['dashboard']['trend']['vsCalendarDays'];
            self::assertSame(1, preg_match('/^\{span\} (.+) \(\{prev\}\)$/u', $tip, $m), $tip);
            self::assertStringContainsString(sprintf($quote, $m[1]), $flat($file), $file);
        }
    }

    /** Der unordentliche Haushalt: Lücken, Schätzung, Zählertausch — die Spanne ist dieselbe, die Menge stimmt mit den Monaten. */
    /** @return array<string,mixed> der unordentliche Haushalt, schon im Speicher */
    private function loadMessyHousehold(): array
    {
        $dir = __DIR__ . '/../../fixtures/messy-household/';
        $h = json_decode((string)file_get_contents($dir . 'household.json'), true, 512, JSON_THROW_ON_ERROR);
        $this->store->write('settings.json', $h['settings']);
        $this->store->write('temperatures.json', $h['temperatures']);
        foreach (['gas', 'strom', 'wasser'] as $u) foreach (['meters', 'readings', 'contracts'] as $pot) $this->store->write("$u/$pot.json", []);
        foreach ($h['utilities'] as $u => $pots) foreach ($pots as $pot => $rows) $this->store->write("$u/$pot.json", $rows);
        return $h;
    }

    /**
     * Nachtrag #36 am unordentlichen Haushalt: Lieferantenwechsel zum 01.05.2025, Stichtag 10-01, letzte
     * Ablesung 05.01.2026. Die Periode davor (01.05.–30.09.2025) liegt sieben Monate neben „ein Jahr
     * früher“; verglichen werden 01.10.2024–04.01.2025, mitten durch die Ablese-Lücke im Herbst 2024.
     */
    public function testTheMessyHouseholdFallsBackToTheSameCalendarDays(): void
    {
        $h = $this->loadMessyHousehold();
        $old = $h['utilities']['gas']['contracts'][0];
        $this->store->write('gas/contracts.json', [
            ['end' => '2025-04-30', 'auto_renews' => false] + $old,
            ['id' => 'c_gas_messy_b', 'provider' => 'Anderer Versorger', 'start' => '2025-05-01',
             'working_prices' => [['from' => '2025-05-01', 'ct_per_kwh' => 12.0]], 'base_prices' => [['from' => '2025-05-01', 'eur_per_month' => 13.5]],
             'advance_payments' => [['from' => '2025-05-01', 'amount_eur' => 150]]] + $old,
        ]);
        $this->settings->set(['billing_cycle_anchor_gas' => '10-01']);
        $r = $this->service()->forUtility('gas', false, '2026-01-20');
        $with = array_values(array_filter($r['periods'], fn($p) => $p['days'] > 0));
        $p = $with[count($with) - 1];
        self::assertSame(['2025-10-01', '2026-01-04', 96], [$p['from'], $p['as_of'], $p['days']]);
        self::assertSame('2025-05-01', $with[count($with) - 2]['from'] ?? null, 'Vorbedingung: die Periode davor beginnt mit dem Wechsel');
        $s = $p['prev_same_span'];
        self::assertSame(['2024-10-01', '2025-01-04', 96, 'calendar'], [$s['from'] ?? null, $s['until'] ?? null, $s['days'] ?? null, $s['basis'] ?? null]);
        // unabhängig gerechnet: Oktober bis Dezember 2024 aus der Monatsreihe, dazu 4 von 31 Januartagen
        $monthly = $this->consumption->forMeter('gas', $this->meters->list('gas')[0]);
        $kwh = fn(string $ym) => (float)(array_values(array_filter($monthly, fn($m) => $m['ym'] === $ym))[0]['kwh'] ?? 0.0);
        $want = $kwh('2024-10') + $kwh('2024-11') + $kwh('2024-12') + $kwh('2025-01') * 4 / 31;
        self::assertGreaterThan(1000.0, $want, 'Vorbedingung: Herbst und Winteranfang mit Heizung');
        self::assertEqualsWithDelta($want, $s['kwh'], 0.02 * $want, 'im Januar nach Gradtagen verteilt, daher auf 2 %');
    }

    public function testTheMessyHouseholdComparesTheSameNumberOfDays(): void
    {
        $this->loadMessyHousehold();
        $this->settings->set(['billing_cycle_anchor_gas' => '07-01']);
        $today = '2026-01-20';
        $r = $this->service()->forUtility('gas', false, $today);
        $with = array_values(array_filter($r['periods'], fn($p) => $p['days'] > 0));
        $p = $with[count($with) - 1];
        $s = $p['prev_same_span'];
        self::assertNotNull($s, 'Vorperiode 01.07.2024–30.06.2025 ist ganz');
        self::assertSame([$p['days'], '2024-07-01'], [$s['days'], $s['from']]);
        // dieselbe Rechnung wie die Periode selbst: eine Periode über genau diese Tage hätte dieselbe Menge
        $monthly = $this->consumption->forMeter('gas', $this->meters->list('gas')[0]);
        $full = array_sum(array_map(fn($m) => (float)$m['kwh'], array_filter($monthly, fn($m) => $m['ym'] >= '2024-07' && $m['ym'] < substr($s['until'], 0, 7))));
        $lastYm = substr($s['until'], 0, 7);
        $last = array_values(array_filter($monthly, fn($m) => $m['ym'] === $lastYm))[0] ?? null;
        $share = $last ? (int)substr($s['until'], 8, 2) / (int)date('t', (int)strtotime($lastYm . '-01')) : 0.0;
        self::assertGreaterThan(0.0, $s['kwh']);
        self::assertEqualsWithDelta($full + (float)($last['kwh'] ?? 0) * $share, $s['kwh'], 0.02 * $s['kwh'],
            'Vollmonate aus der Monatsreihe, der letzte anteilig (im Monat nach Gradtagen verteilt, daher auf 2 %)');
    }
}
