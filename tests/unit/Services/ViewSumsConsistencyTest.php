<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Config\Utilities;
use Energietracker\Services\BackupService;
use Energietracker\Services\BillingPeriodService;
use Energietracker\Services\BillService;
use Energietracker\Services\CsvExportService;
use Energietracker\Services\DeliveryService;
use Energietracker\Services\DemoService;
use Energietracker\Services\ForecastService;
use Energietracker\Services\MeterService;
use Energietracker\Services\ScenarioService;
use Energietracker\Services\TariffSwitchService;
use Energietracker\Services\TemperatureService;
use Energietracker\Services\WeatherService;
use Energietracker\Tests\Support\ReportFixture;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * v3.6.0 (Paket abrechnung) — Zahlen stimmig über die Sichten.
 *
 * Je Beispielhaushalt und „heute“ müssen Menge, Kosten, Abschläge und Saldo
 * überall dieselben sein — im Kalenderjahr wie im Abrechnungsjahr:
 *
 *   Kalenderjahr   Verbrauchsart (Zähler einzeln) = Art (Übersicht, Analyse:
 *                  dieselben Monatszeilen) = CSV-Monatsexport = Jahresbericht
 *                  (Seite je Art, Kosten gesamt mit Heizöl und Pellets); Ausgangsfall „Vorjahr“ der
 *                  Prognose-Werkstatt = die gemessenen Monate des Vorjahrs
 *   Abrechnungsjahr  Perioden der Art = Zähler einzeln = Monatsreihe der
 *                  Periode = CSV je Periode = Anhang des Jahresberichts =
 *                  „Rechnung prüfen“ (jede erfasste Rechnung) = Saldo bisher
 *                  in der Prognose-Werkstatt
 *   beide Modi     mit Stichtag 01.01. und ohne Rechnungen ist jedes ganze,
 *                  gemessene Abrechnungsjahr das Kalenderjahr — dieselben Summen
 *
 * Toleranzen nur für Rundung: Die Monatszeilen runden Menge (0,1) und Beträge
 * (0,01) je Monat, die Periode rechnet ungerundet tagesgenau.
 *
 * „Heute“ liegt hier in der Vergangenheit: Die Dienste lesen Ablesungen bis
 * zum echten heutigen Tag (ConsumptionService::plausibleReadings); bei einem
 * künftigen „heute“ zählten die Abrechnungsperioden Stände, die die Rechnung
 * noch nicht kennt.
 */
final class ViewSumsConsistencyTest extends ServiceTestCase
{
    use ReportFixture;

    private const HOUSEHOLDS = ['mieterin', 'etw-fernwaerme', 'eigenheim-klassisch', 'eigenheim-modern', 'showcase'];

    /**
     * Mehrere Stichtage wie PersonaUseCasesTest::testAlignedBillsStillMatchAndFollowTheAnchor —
     * die relativen Stichtage wandern mit. Alle nach dem Ende der ausgelieferten
     * Daten (Mai 2026: davor schreibt DemoDataAligner nichts fort, spätere Stände
     * blieben stehen) und nicht nach dem Tag, an dem dieser Test entstand.
     */
    private const TODAYS = ['2026-06-15', '2026-08-31', '2026-10-16'];

    protected function tearDown(): void
    {
        parent::tearDown();
        gc_collect_cycles();
        gc_mem_caches();
    }

    /** @return array<string,array{0:string,1:string}> */
    public static function householdDays(): array
    {
        $out = [];
        foreach (self::HOUSEHOLDS as $id) foreach (self::TODAYS as $d) $out["$id $d"] = [$id, $d];
        return $out;
    }

    private function bills(): BillService
    {
        return new BillService($this->store, $this->meters, $this->contracts, $this->consumption, $this->i18n);
    }

    private function periods(): BillingPeriodService
    {
        return new BillingPeriodService($this->settings, $this->meters, $this->readings, $this->consumption, $this->bills());
    }

    private function csv(): CsvExportService
    {
        $csv = new CsvExportService($this->consumption, $this->readings, $this->meters,
            new TemperatureService($this->store, $this->settings, new WeatherService()),
            new DeliveryService($this->store, $this->meters, $this->i18n), $this->i18n);
        $csv->attachBillingPeriods($this->periods());
        return $csv;
    }

    private function scenarios(): ScenarioService
    {
        $forecast = new ForecastService($this->consumption, $this->regression, $this->settings, $this->contracts, $this->i18n);
        return new ScenarioService($forecast, $this->consumption, $this->contracts, $this->meters, $this->settings,
            new TariffSwitchService($forecast, $this->contracts, $this->meters, $this->i18n), $this->periods());
    }

    /** Wie die App die Beispieldaten einspielt: ausgerichtet auf „heute“, gewanderte Rechnungen nachgerechnet. */
    private function importHousehold(string $id, string $today): void
    {
        $demo = new DemoService($this->store, new BackupService($this->store, $this->i18n), $this->i18n);
        $demo->attachBills($this->bills());
        $demo->import(true, $today, $id === 'showcase' ? null : $id);
    }

    private static function qk(string $u): string
    {
        return (Utilities::get($u)['consumption_unit'] ?? '') === 'kWh' ? 'kwh' : 'm3';
    }

    /** Arten mit Zählern, die Monatszeilen haben. */
    private function utilitiesWithData(): array
    {
        return array_values(array_filter(Utilities::keys(), fn($u) => $this->meters->list($u) !== []));
    }

    /** CSV Format 1 (Semikolon, Dezimalkomma) → Zeilen ohne Kopf. */
    private static function csvRows(string $csv): array
    {
        $lines = array_values(array_filter(explode("\n", str_replace("\r", '', ltrim($csv, "\u{FEFF}"))), fn($l) => $l !== ''));
        array_shift($lines);
        return array_map(fn($l) => str_getcsv($l, ';', '"', ''), $lines);
    }

    private static function num(string $s): float
    {
        return $s === '' ? 0.0 : (float)str_replace(',', '.', $s);
    }

    /** Jahressummen der Art aus den Monatszeilen (Übersicht, Verbrauchsart, CSV, Bericht lesen sie). */
    private static function yearSums(array $monthly, string $qk): array
    {
        $y = [];
        foreach ($monthly as $m) {
            $k = (int)$m['year'];
            $y[$k] ??= ['q' => 0.0, 'cost' => 0.0, 'bonus' => 0.0, 'adv' => 0.0, 'co2' => 0.0, 'n' => 0];
            $y[$k]['q'] += (float)($m[$qk] ?? 0);
            $y[$k]['cost'] += (float)($m['cost'] ?? 0);
            $y[$k]['bonus'] += (float)($m['bonus_eur'] ?? 0);
            $y[$k]['adv'] += (float)($m['advance_eur'] ?? 0);
            $y[$k]['co2'] += (float)($m['co2_kg'] ?? 0);
            $y[$k]['n']++;
        }
        return $y;
    }

    private static function assertClose(float $want, float $got, float $tol, string $msg): void
    {
        self::assertEqualsWithDelta($want, $got, $tol, $msg);
    }

    /** Toleranz für Beträge aus gerundeten Monatszeilen: 1 ct je Monat und 0,2 % (Wasser: Menge je Monat auf 0,1 m³). */
    private static function costTol(float $amount, int $months): float
    {
        return 0.01 * max(1, $months) + 0.002 * abs($amount) + 0.01;
    }

    // ── Kalenderjahr ────────────────────────────────────────────────────

    #[DataProvider('householdDays')]
    public function testCalendarYearSumsAgreeAcrossViews(string $id, string $today): void
    {
        $this->importHousehold($id, $today);
        $csv = $this->csv();
        $checked = 0;
        $byYear = [];
        foreach ($this->utilitiesWithData() as $u) {
            $qk = self::qk($u);
            $total = $this->consumption->forUtility($u);
            $years = self::yearSums($total['monthly_total'], $qk);
            // (1) Zähler einzeln (Verbrauchsart) = Art (Übersicht)
            $roots = array_values(array_filter($this->meters->list($u), fn($m) => MeterService::countsInTotals($m)));
            $sum = [];
            foreach ($roots as $m) {
                foreach (self::yearSums($this->consumption->forMeter($u, $m), $qk) as $y => $s) {
                    $sum[$y] ??= ['q' => 0.0, 'cost' => 0.0, 'adv' => 0.0];
                    foreach (['q', 'cost', 'adv'] as $k) $sum[$y][$k] += $s[$k];
                }
            }
            // (2) CSV je Monat
            $csvYears = [];
            foreach (self::csvRows($csv->monthly($u, '1')) as $r) {
                $y = (int)substr($r[0], 0, 4);
                $csvYears[$y] ??= ['q' => 0.0, 'cost' => 0.0, 'adv' => 0.0];
                $csvYears[$y]['q'] += self::num($r[2]); $csvYears[$y]['cost'] += self::num($r[3]); $csvYears[$y]['adv'] += self::num($r[4]);
            }
            foreach ($years as $y => $s) {
                $ctx = "$id $today $u $y";
                self::assertClose($s['q'], $sum[$y]['q'] ?? 0.0, 0.05 * $s['n'] + 0.01, "$ctx: Menge Zähler = Art");
                self::assertClose($s['cost'], $sum[$y]['cost'] ?? 0.0, self::costTol($s['cost'], $s['n']), "$ctx: Kosten Zähler = Art");
                self::assertClose($s['adv'], $sum[$y]['adv'] ?? 0.0, 0.01 * $s['n'] + 0.01, "$ctx: Abschläge Zähler = Art");
                self::assertClose($s['q'], $csvYears[$y]['q'] ?? 0.0, 0.001 * $s['n'] + 0.01, "$ctx: Menge CSV");
                self::assertClose($s['cost'], $csvYears[$y]['cost'] ?? 0.0, 0.001 * $s['n'] + 0.01, "$ctx: Kosten CSV");
                self::assertClose($s['adv'], $csvYears[$y]['adv'] ?? 0.0, 0.001 * $s['n'] + 0.01, "$ctx: Abschläge CSV");
                $checked++;
            }
            $byYear[$u] = $years;
        }
        self::assertGreaterThan(3, $checked, 'Jahre geprüft');

        // (3) Jahresbericht: Seite je Art (Menge) und Kosten gesamt — für jedes ganze Jahr davor
        $model = $this->reportServices()['model'];
        $y = (int)substr($today, 0, 4) - 1;
        $m = $model->build($y, ['today' => $today]);
        $cost = 0.0; $any = false;
        foreach ($m['pages'] as $page) {
            $s = $byYear[$page['utility']][$y] ?? null;
            self::assertNotNull($s, "$id $y: Bericht ohne Monatszeilen für {$page['utility']}");
            self::assertClose($s['q'], (float)$page['value'], 0.05 * $s['n'] + 0.01, "$id $today Bericht $y {$page['utility']}: Menge");
        }
        // Kosten gesamt wie die Übersicht: Arten mit Vertrag in dem Jahr und —
        // seit v3.6.0 — Heizöl und Pellets mit den Kosten ihrer Lieferungen
        // (ReportModelService `priced`; bis v3.5 fehlten sie in der Kachel)
        foreach ($byYear as $u => $years) {
            if (Utilities::accountingKind($u) !== 'consumption' || !isset($years[$y]) || $years[$y]['cost'] == 0.0) continue;
            $priced = Utilities::isDelivery($u);
            foreach ($this->meters->list($u) as $mt) {
                if (!MeterService::countsInTotals($mt)) continue;
                foreach ($this->consumption->forMeter($u, $mt) as $r) if ((int)$r['year'] === $y && !empty($r['contract_id'])) $priced = true;
            }
            if (!$priced) continue;
            // v3.7.0 (#33) — die Kachel zählt ohne Bonus, wie die Kachel der Verbrauchsart
            $cost += $years[$y]['cost'] + $years[$y]['bonus']; $any = true;
        }
        $tile = array_column($m['headline']['tiles'] ?? [], null, 'key')['cost'] ?? null;
        if ($any) {
            self::assertNotNull($tile, "$id $today Bericht $y: Kachel Kosten");
            self::assertClose($cost, (float)$tile['value'], 0.02 * count($byYear) + 0.01, "$id $today Bericht $y: Kosten gesamt");
        }

        // (4) Prognose-Werkstatt, Ausgangsfall „Vorjahr“: die gemessenen Monate ein Jahr früher
        $sc = $this->scenarios();
        foreach (array_filter($this->utilitiesWithData(), [ScenarioService::class, 'supports']) as $u) {
            $qk = self::qk($u);
            foreach (array_filter($this->meters->list($u), fn($m) => MeterService::countsInTotals($m) && ($m['capture'] ?? null) !== 'period') as $mt) {
                $r = $sc->compute(['utility' => $u, 'meter_id' => $mt['id'], 'model' => 'last_year'], $today);
                if (empty($r['valid']) || $r['model'] !== 'last_year') continue;
                $rows = array_column($this->consumption->forMeter($u, $mt), null, 'ym');
                foreach ($r['months'] as $fm) {
                    $prev = sprintf('%04d-%s', (int)substr($fm['ym'], 0, 4) - 1, substr($fm['ym'], 5, 2));
                    $row = $rows[$prev] ?? null;
                    $dim = (int)date('t', (int)strtotime($fm['ym'] . '-01'));
                    // v3.7.0 (#37) — ein verteilter Vorjahresmonat ist nicht gemessen: Die Werkstatt ergänzt ihn aus der Prognose (`filled`)
                    if ($row === null || !empty($row['pre_baseline']) || !empty($row['interpolated']) || (int)$row['days'] < (int)date('t', (int)strtotime($prev . '-01')) || $dim !== (int)$row['days']) continue;
                    self::assertClose((float)$row[$qk], (float)$fm['base_qty'], 0.06, "$id $today Werkstatt $u {$mt['id']} {$fm['ym']}: Vorjahr = gemessen");
                }
            }
        }
    }

    // ── Abrechnungsjahr ─────────────────────────────────────────────────

    #[DataProvider('householdDays')]
    public function testBillingPeriodSumsAgreeAcrossViews(string $id, string $today): void
    {
        $this->importHousehold($id, $today);
        $bp = $this->periods();
        $bills = $this->bills();
        $realToday = date('Y-m-d');
        $csv = $this->csv();
        $sc = $this->scenarios();
        $reported = [];
        $checked = 0;
        foreach (array_filter($this->utilitiesWithData(), [Utilities::class, 'supportsBillCheck']) as $u) {
            $qk = self::qk($u);
            $art = $bp->forUtility($u, true, $today);
            $roots = array_values(array_filter($this->meters->list($u), fn($m) => MeterService::countsInTotals($m) && ($m['capture'] ?? null) !== 'period'));
            $perMeter = [];
            foreach ($roots as $m) $perMeter[$m['id']] = $bp->forTarget($u, $m, false, $today);
            foreach ($art['periods'] as $p) {
                $ctx = "$id $today $u {$p['from']}…{$p['until']}";
                // (1) Monatsreihe der Periode (Tabelle, Diagramm) = Periode (Kennzahlen)
                $n = count($p['months']);
                $ms = ['q' => 0.0, 'cost' => 0.0, 'adv' => 0.0];
                foreach ($p['months'] as $r) { $ms['q'] += (float)$r[$qk]; $ms['cost'] += (float)$r['cost']; $ms['adv'] += (float)$r['advance_eur']; }
                self::assertClose((float)$p[$qk], $ms['q'], 0.05 * $n + 0.01, "$ctx: Menge Monate = Periode");
                self::assertClose((float)$p['cost'], $ms['cost'], 0.015 * $n + 0.02, "$ctx: Kosten Monate = Periode");
                self::assertClose((float)$p['advances'], $ms['adv'], 0.015 * $n + 0.02, "$ctx: Abschläge Monate = Periode");
                self::assertClose(round((float)$p['cost'] - (float)$p['advances'] - (float)$p['special']['extra_advances'], 2), (float)$p['balance'], 0.001, "$ctx: Saldo = Kosten − Abschläge");
                // (2) Zähler einzeln (Verbrauchsart, Analyse) = Art (Übersicht), wo alle Zähler diese Periode haben
                $acc = ['q' => 0.0, 'cost' => 0.0, 'adv' => 0.0, 'bal' => 0.0, 'n' => 0];
                foreach ($perMeter as $t) {
                    foreach ($t['periods'] as $x) {
                        if ($x['from'] !== $p['from'] || $x['until'] !== $p['until']) continue;
                        $acc['q'] += (float)$x[$qk]; $acc['cost'] += (float)$x['cost']; $acc['adv'] += (float)$x['advances']; $acc['bal'] += (float)$x['balance']; $acc['n']++;
                    }
                }
                if ($acc['n'] === count($perMeter)) {
                    self::assertClose((float)$p[$qk], $acc['q'], 0.05 * count($perMeter) + 0.01, "$ctx: Menge Zähler = Art");
                    self::assertClose((float)$p['cost'], $acc['cost'], 0.01 * count($perMeter) + 0.01, "$ctx: Kosten Zähler = Art");
                    self::assertClose((float)$p['advances'], $acc['adv'], 0.01 * count($perMeter) + 0.01, "$ctx: Abschläge Zähler = Art");
                    self::assertClose((float)$p['balance'], $acc['bal'], 0.01 * count($perMeter) + 0.01, "$ctx: Saldo Zähler = Art");
                }
                if (substr((string)$p['until'], 0, 4) === substr($today, 0, 4) || (int)substr((string)$p['until'], 0, 4) === (int)substr($today, 0, 4) - 1) {
                    $reported[(int)substr((string)$p['until'], 0, 4)][$u][] = $p;
                }
                $checked++;
            }
            // (3) CSV je Abrechnungsperiode = Perioden der Art (die CSV rechnet zum echten heutigen Tag)
            $now = $bp->forUtility($u, false, $realToday);
            $rows = self::csvRows($csv->billingPeriods($u, '1'));
            self::assertCount(count($now['periods']), $rows, "$id $today $u: eine CSV-Zeile je Periode");
            foreach ($now['periods'] as $i => $p) {
                $r = $rows[$i];
                self::assertSame([$p['from'], $p['until']], [$r[0], $r[1]], "$id $today $u CSV: Zeitraum");
                foreach ([[4, $qk], [5, 'cost'], [6, 'advances'], [8, 'balance'], [10, 'bonus']] as [$col, $k]) {
                    self::assertClose((float)$p[$k], self::num($r[$col]), 0.0001, "$id $today $u CSV {$p['from']}: $k");
                }
                // v3.7.0 (#33) — Kosten ohne Bonus, die Zahl der Kachel
                self::assertClose((float)$p['cost'] + (float)$p['bonus'], self::num($r[11]), 0.0051, "$id $today $u CSV {$p['from']}: Kosten ohne Bonus");
            }
            // (4) „Rechnung prüfen“ = Periode aus der Rechnung, am Ziel der Rechnung: der
            // Zähler, mit Gruppenvertrag die Gruppe (wie BillService::billTarget)
            foreach ($bills->list($u) as $b) {
                $meter = $this->meters->get($u, (string)$b['meter_id']);
                $gid = (string)($meter['meter_group_id'] ?? '');
                $groupContract = $gid !== '' && array_filter($this->contracts->list($u, $gid), fn($c) => empty($c['is_shadow'])
                    && ($c['meter_group_id'] ?? null) === $gid && (string)$c['start'] <= (string)$b['period_to']
                    && (string)$b['period_from'] <= ((string)($c['end'] ?? '') ?: '9999-12-31')) !== [];
                $t = $groupContract ? $bp->forTarget($u, $this->meters->groupTarget($u, $gid), false, $today)
                    : ($perMeter[$b['meter_id']] ?? $bp->forTarget($u, $meter, false, $today));
                $p = array_values(array_filter($t['periods'], fn($x) => $x['bill_id'] === $b['id']))[0] ?? null;
                if ($p === null) continue;   // überlappt eine frühere Rechnung (die frühere gilt)
                $ctx = "$id $today $u Rechnung {$b['period_from']}…{$b['period_to']}";
                $cmp = $bills->compare($u, (string)$b['id']);
                $o = $cmp['ours'];
                self::assertClose((float)$o[$qk], (float)$p[$qk], 0.06, "$ctx: Menge");
                self::assertClose((float)$o['total'] - (float)$o['items_total'], (float)$p['cost'], 0.011, "$ctx: Kosten");
                if ($p['as_of'] === $p['until']) {
                    self::assertClose((float)$o['advances'], (float)$p['advances'], 0.011, "$ctx: Abschläge");
                    self::assertClose((float)$o['total'] - (float)$o['items_total'] - (float)$o['advances'] - (float)$p['special']['extra_advances'], (float)$p['balance'], 0.021, "$ctx: Saldo");
                }
            }
            // (5) Prognose-Werkstatt: in der laufenden Periode des Zählers, und (v3.9.0, V1) aus
            // derselben Projektion wie die Saldo-Karte — Saldo bisher und erwarteter Endsaldo
            // sind die Summe der Verträge in dieser Periode (bis v3.8: eigene Rechnung ab der
            // letzten Ablesung, ein anderer Endsaldo als die Karte)
            foreach ($roots as $m) {
                if (!ScenarioService::supports($u)) continue;
                $r = $sc->compute(['utility' => $u, 'meter_id' => $m['id']], $today);
                if (empty($r['valid']) || $r['billing'] === null) continue;
                $running = array_values(array_filter($perMeter[$m['id']]['periods'], fn($x) => !empty($x['running'])));
                $last = end($running);
                self::assertNotFalse($last, "$id $today $u {$m['id']}: laufende Periode");
                self::assertSame([$last['from'], $last['until']], [$r['billing']['from'], $r['billing']['until']], "$id $today $u {$m['id']}: Werkstatt in der laufenden Periode");
                $cards = array_values(array_filter($this->consumption->contractStatus($u, $m, $today)['contracts'], fn($c) => is_array($c['billing_period'] ?? null)));
                if ($cards === []) continue;
                self::assertSame('contract_status', $r['billing']['source'], "$id $today $u {$m['id']}: Werkstatt aus dem Vertragsstatus");
                self::assertClose(array_sum(array_column($cards, 'current_balance')), (float)$r['billing']['balance_so_far'], 0.011, "$id $today $u {$m['id']}: Werkstatt Saldo bisher = Karte");
                self::assertClose(array_sum(array_column($cards, 'projected_end_balance')), (float)$r['billing']['base_end_balance'], 0.011, "$id $today $u {$m['id']}: Werkstatt Endsaldo = Karte");
                self::assertSame(max(array_column($cards, 'settle_on')), $r['billing']['settle_on'], "$id $today $u {$m['id']}: derselbe Abrechnungstag");
            }
        }
        self::assertGreaterThan(0, $checked, 'Perioden geprüft');

        // (6) Anhang „Abrechnungsjahre“ des Jahresberichts = Perioden, die im Berichtsjahr enden (aktive Arten)
        $model = $this->reportServices()['model'];
        $active = (array)$this->settings->get('active_utilities', []);
        foreach ($reported as $year => $byUtility) {
            $m = $model->build($year, ['today' => $today, 'billing' => true]);
            $tables = array_column($m['billing'] ?? [], null, 'utility');
            foreach ($byUtility as $u => $list) {
                if ($active !== [] && !in_array($u, $active, true)) continue;
                self::assertArrayHasKey($u, $tables, "$id $today Bericht $year: Anhang für $u");
                $dec = self::qk($u) === 'kwh' ? 0 : 1;
                foreach ($list as $i => $p) {
                    $row = $tables[$u]['rows'][$i] ?? self::fail("$id $today Bericht $year $u: Zeile $i fehlt");
                    // v3.7.0 (#33) — Kosten ohne Bonus, der Bonus als eigene Spalte
                    self::assertSame([$this->i18n->number((float)$p[self::qk($u)], $dec), $this->i18n->number((float)$p['cost'] + (float)$p['bonus'], 2),
                        $this->i18n->number((float)$p['bonus'], 2),
                        $this->i18n->number((float)$p['advances'], 2), $this->i18n->number((float)$p['balance'], 2)],
                        array_slice($row, 2, 5), "$id $today Bericht $year $u {$p['from']}");
                }
            }
        }
    }

    // ── Beide Modi ──────────────────────────────────────────────────────

    /**
     * Stichtag 01.01., keine Rechnungen: Die Abrechnungsjahre sind die
     * Kalenderjahre — jedes ganze, gemessene Jahr hat dieselben Summen. Bis
     * v3.5 fehlte dabei beim Wasser mit Garten- bzw. eigenem
     * Schmutzwasserzähler das Schmutzwasser (Rechnungsprüfung).
     */
    #[DataProvider('householdDays')]
    public function testBillingYearFromJanuaryEqualsTheCalendarYear(string $id, string $today): void
    {
        $this->importHousehold($id, $today);
        $settings = $this->store->read('settings.json', []);
        $compared = 0;
        foreach (array_filter($this->utilitiesWithData(), [Utilities::class, 'supportsBillCheck']) as $u) {
            $this->store->write("$u/bills.json", []);
            $settings["billing_cycle_anchor_$u"] = '01-01';
        }
        $this->store->write('settings.json', $settings);
        $bp = new BillingPeriodService(new \Energietracker\Services\SettingsService($this->store), $this->meters, $this->readings, $this->consumption, $this->bills());
        foreach (array_filter($this->utilitiesWithData(), [Utilities::class, 'supportsBillCheck']) as $u) {
            $qk = self::qk($u);
            $cal = self::yearSums($this->consumption->forUtility($u)['monthly_total'], $qk);
            foreach ($bp->forUtility($u, false, $today)['periods'] as $p) {
                $y = (int)substr($p['from'], 0, 4);
                if ($p['from'] !== "$y-01-01" || $p['until'] !== "$y-12-31" || $p['as_of'] !== "$y-12-31") continue;
                $c = $cal[$y] ?? self::fail("$id $today $u $y: Kalenderjahr fehlt");
                $ctx = "$id $today $u $y";
                self::assertSame('anchor', $p['source']);
                self::assertClose($c['q'], (float)$p[$qk], 0.05 * $c['n'] + 0.01, "$ctx: Menge");
                self::assertClose($c['cost'], (float)$p['cost'], self::costTol($c['cost'], $c['n']), "$ctx: Kosten");
                self::assertClose($c['adv'], (float)$p['advances'], 0.01 * $c['n'] + 0.01, "$ctx: Abschläge");
                self::assertClose($c['cost'] - $c['adv'], (float)$p['cost'] - (float)$p['advances'], self::costTol($c['cost'], $c['n']) + 0.01 * $c['n'], "$ctx: Saldo");
                self::assertClose($c['co2'], (float)$p['co2_kg'], 0.05 * $c['n'] + 0.1, "$ctx: CO₂");
                $compared++;
            }
        }
        self::assertGreaterThan(0, $compared, "$id $today: mindestens ein ganzes Jahr verglichen");
    }

    // ── Saldo des laufenden Vertrags ────────────────────────────────────

    /**
     * Beginnt der Saldo eines laufenden Vertrags nach Vertragsbeginn, dann am
     * Tag nach einer gebuchten Rechnung dieses Zählers — und das gebuchte
     * Ergebnis zählt nicht mehr im Saldo.
     */
    #[DataProvider('householdDays')]
    public function testRunningContractBalanceStartsAfterTheLastBookedBill(string $id, string $today): void
    {
        $this->importHousehold($id, $today);
        $bills = $this->bills();
        $windowed = 0;
        foreach (array_filter($this->utilitiesWithData(), [Utilities::class, 'supportsBillCheck']) as $u) {
            $byMeter = [];
            foreach ($bills->list($u) as $b) if (!empty($b['special_payment_id'])) $byMeter[$b['meter_id']][] = $b;
            foreach ($this->meters->list($u) as $m) {
                // v3.9.0 (V1) — derselbe Tag wie der importierte Haushalt
                foreach ($this->consumption->contractStatus($u, $m, $today)['contracts'] as $c) {
                    if (empty($c['is_current']) || ($c['balance_from'] ?? null) === null || $c['balance_from'] <= $c['start']) continue;
                    $ends = array_map(fn($b) => date('Y-m-d', (int)strtotime($b['period_to'] . ' +1 day')), $byMeter[$m['id']] ?? []);
                    // v3.9.0 (V1) — oder der Beginn der laufenden Abrechnungsperiode (Stichtag, Wechsel)
                    if (is_array($c['billing_period'] ?? null)) $ends[] = (string)$c['billing_period']['open_from'];
                    self::assertContains($c['balance_from'], $ends, "$id $today $u {$c['contract_id']}: Saldo ab dem Tag nach einer gebuchten Rechnung bzw. ab der laufenden Periode");
                    // gezählt werden nur Zahlungen ab dem Saldobeginn, keine gebuchten Rechnungsergebnisse
                    $bookedOn = array_map(fn($b) => (string)($b['issued_on'] ?? ''), $byMeter[$m['id']] ?? []);
                    foreach ($c['special_payments'] ?? [] as $sp) {
                        self::assertGreaterThanOrEqual($c['balance_from'], $sp['date'], "$id $today $u: nur Zahlungen ab dem Saldobeginn");
                        self::assertFalse(in_array($sp['date'], $bookedOn, true) && $sp['kind'] !== 'abschlagszahlung', "$id $today $u: gebuchtes Rechnungsergebnis zählt nicht");
                        // v3.9.0 (V1) — in der Periode gleichen Rück- und Nachzahlungen frühere Abrechnungen aus
                        if (is_array($c['billing_period'] ?? null)) self::assertSame('abschlagszahlung', $sp['kind'], "$id $today $u: in der laufenden Periode nur Abschlagszahlungen");
                    }
                    $windowed++;
                }
            }
        }
        // ob es einen solchen Vertrag gibt, sichert die Abdeckungsmatrix (DemoCoverageTest)
        self::assertGreaterThanOrEqual(0, $windowed);
    }
}
