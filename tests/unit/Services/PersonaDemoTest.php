<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Config\Utilities;
use Energietracker\Services\BackupService;
use Energietracker\Services\BillService;
use Energietracker\Services\Co2CostService;
use Energietracker\Services\Co2SplitService;
use Energietracker\Services\DemoDataAligner;
use Energietracker\Services\EvChargingReportService;
use Energietracker\Services\HeatPumpService;
use Energietracker\Services\MeterService;
use Energietracker\Services\PeriodService;
use Energietracker\Services\PvSummaryService;
use Energietracker\Services\TenancyBudgetService;
use Energietracker\Services\TenancyService;
use Energietracker\Storage\Migrator;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * F1018 (v3.2.0) — Beispielhaushalte je Persona (demo-data/personas/, erzeugt
 * von tools/build-personas.mjs).
 *
 * Jede Persona muss sich wie ein echtes Backup einspielen lassen und danach
 * rechnen: ohne Ausnahme, ohne Warnung zu den Ständen, mit plausiblen
 * Kennzahlen. Dazu gehen die Datensätze durch die Services, die sie sonst
 * schreiben — was die App beim Speichern anders ablegen würde oder gar nicht
 * kennt, fällt hier auf, nicht erst in der Demo.
 */
final class PersonaDemoTest extends ServiceTestCase
{
    private const PERSONAS = ['mieterin', 'etw-fernwaerme', 'eigenheim-klassisch', 'eigenheim-modern'];

    /** Exportstand der Haupt-Demo = letzter Tag der Persona-Daten. */
    private const END = '2026-05-31';

    private static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    /** @return array<string,array{0:string}> */
    public static function personas(): array
    {
        return array_combine(self::PERSONAS, array_map(fn($p) => [$p], self::PERSONAS));
    }

    private static function load(string $id): array
    {
        return json_decode((string)file_get_contents(self::root() . "/demo-data/personas/$id.json"), true, 512, JSON_THROW_ON_ERROR);
    }

    private function import(array $payload): array
    {
        return (new BackupService($this->store, $this->i18n))->import($payload);
    }

    /** Summe eines Felds über die Monatszeilen eines Jahres. */
    private static function annual(array $rows, int $year, string $field = 'kwh'): float
    {
        return array_sum(array_map(fn($r) => (int)$r['year'] === $year ? (float)($r[$field] ?? 0) : 0.0, $rows));
    }

    private function meterRows(string $u, string $id): array
    {
        return $this->consumption->forMeter($u, $this->meters->get($u, $id) ?? self::fail("Zähler $id fehlt"));
    }

    /** Schlüssel unserer Daten, die der Datensatz der App nicht hat (rekursiv). */
    private static function unknownKeys(mixed $ours, mixed $app, string $path = ''): array
    {
        if (!is_array($ours) || !is_array($app)) return [];
        $out = [];
        foreach ($ours as $k => $v) {
            if (!array_key_exists($k, $app)) { $out[] = $path . $k; continue; }
            $out = array_merge($out, self::unknownKeys($v, $app[$k], "$path$k."));
        }
        return $out;
    }

    public function testTheFourPersonasAreThere(): void
    {
        $files = array_map(fn($f) => basename($f, '.json'), glob(self::root() . '/demo-data/personas/*.json') ?: []);
        sort($files);
        $expected = self::PERSONAS;
        sort($expected);
        self::assertSame($expected, $files);
    }

    #[DataProvider('personas')]
    public function testTheFileHasTheFormatOfAnExport(string $id): void
    {
        $p = self::load($id);
        $demo = json_decode((string)file_get_contents(self::root() . '/demo-data/energietracker-demo-backup.json'), true);
        self::assertSame(BackupService::BACKUP_VERSION, $p['backup_version']);
        self::assertSame($demo['exported_at'], $p['exported_at'], 'Exportstand wie die Haupt-Demo');
        self::assertTrue(version_compare((string)$p['meta']['schema_version'], Migrator::SCHEMA_VERSION, '<='), 'Schema nicht neuer als die App');
        foreach (array_keys(BackupService::TOP_POTS) as $pot) self::assertArrayHasKey($pot, $p, "Topf $pot");
        // alle Töpfe aller Arten: ein Import ersetzt den ganzen Haushalt
        self::assertSame(Utilities::keys(), array_keys($p['utilities']));
        foreach ($p['utilities'] as $u => $bucket) self::assertSame(BackupService::UTILITY_POTS, array_keys($bucket), "Töpfe von $u");
        // gleicher Ort und dieselben Temperaturen wie die Haupt-Demo (Heizgradtage)
        self::assertSame($demo['temperatures'], $p['temperatures']);
        foreach (['location_name', 'latitude', 'longitude'] as $k) self::assertSame($demo['settings'][$k], $p['settings'][$k], $k);

        $s = $p['settings'];
        self::assertSame($id, $s['setup_persona']);
        self::assertContains($s['wohnverhaeltnis'], ['eigentum', 'miete']);
        self::assertContains($s['gebaeudetyp'], ['efh', 'rh', 'mfh', 'whg']);
        self::assertGreaterThan(0, $s['wohnflaeche_m2']);
        $withMeters = array_keys(array_filter($p['utilities'], fn($b) => $b['meters'] !== []));
        self::assertEqualsCanonicalizing($withMeters, $s['active_utilities'], 'aktiv sind genau die Arten mit Zählern');
    }

    #[DataProvider('personas')]
    public function testImportsAndEveryMeterCalculates(string $id): void
    {
        $p = self::load($id);
        $dry = (new BackupService($this->store, $this->i18n))->import($p, true);
        self::assertSame([], $dry['problems']);
        $this->import($p);

        foreach ($p['settings']['active_utilities'] as $u) {
            $util = $this->consumption->forUtility($u);
            foreach ($util['meters'] as $pm) {
                $m = $pm['meter'];
                self::assertGreaterThanOrEqual(35, count($pm['monthly']), "$u/{$m['id']}: drei Jahre Monate");
                self::assertSame([], $this->consumption->readingWarnings($u, $m), "$u/{$m['id']}: Stände ohne Warnung");
                foreach ($pm['monthly'] as $row) {
                    self::assertGreaterThanOrEqual(0.0, (float)($row['kwh'] ?? 0) + (float)($row['m3'] ?? 0), "$u/{$m['id']} {$row['ym']}");
                }
                if (Utilities::hasContracts($u) && $this->contracts->list($u, (string)$m['id']) !== []) {
                    self::assertGreaterThan(0.0, abs(self::annual($pm['monthly'], 2025, 'cost')), "$u/{$m['id']}: Kosten 2025");
                }
            }
        }
    }

    #[DataProvider('personas')]
    public function testRecordsAreStoredAsTheAppStoresThem(string $id): void
    {
        $p = self::load($id);
        $this->import($p);
        $tenancies = new TenancyService($this->store, $this->meters, $this->i18n);
        $periods = new PeriodService($this->store, $this->meters, $this->i18n);
        $bills = new BillService($this->store, $this->meters, $this->contracts, $this->consumption, $this->i18n);

        foreach ($p['utilities'] as $u => $b) {
            foreach ($b['meters'] as $m) {
                $after = $this->meters->update($u, $m['id'], array_diff_key($m, array_flip(['id', 'devices', 'created_at'])));
                self::assertEquals($m, $after, "Zähler $u/{$m['id']}");
            }
            foreach ($b['contracts'] as $c) {
                $this->contracts->update($u, $c['id'], $c);
                self::assertEquals($c, $this->contracts->get($u, $c['id']), "Vertrag $u/{$c['id']}");
            }
            foreach ($b['periods'] as $x) {
                self::assertEquals($x, $periods->update($u, $x['id'], $x), "Zeitraum $u/{$x['id']}");
            }
            foreach ($b['bills'] as $x) {
                self::assertEquals($x, $bills->update($u, $x['id'], $x), "Rechnung $u/{$x['id']}");
            }
        }
        foreach ($p['tenancies'] as $t) {
            self::assertEquals($t, $tenancies->update($t['id'], $t), "Mietverhältnis {$t['id']}");
        }
        foreach ($p['tenancy_statements'] as $s) {
            self::assertEquals($s, $tenancies->updateStatement($s['tenancy_id'], $s['id'], $s), "Abrechnung {$s['id']}");
        }
    }

    #[DataProvider('personas')]
    public function testRecordsUseOnlyFieldsTheAppKnows(string $id): void
    {
        $p = self::load($id);
        $this->import($p);
        $tenancies = new TenancyService($this->store, $this->meters, $this->i18n);
        $periods = new PeriodService($this->store, $this->meters, $this->i18n);
        $bills = new BillService($this->store, $this->meters, $this->contracts, $this->consumption, $this->i18n);
        $unknown = [];
        foreach ($p['utilities'] as $u => $b) {
            foreach ($b['meters'] as $m) {
                $unknown[] = self::unknownKeys($m, $this->meters->create($u, $m), "$u/{$m['id']}.");
            }
            foreach ($b['contracts'] as $c) {
                $unknown[] = self::unknownKeys($c, $this->contracts->create($u, $c), "$u/{$c['id']}.");
            }
            foreach ($b['periods'] as $x) {
                // ein frischer Zähler, sonst überlappt der Zeitraum mit sich selbst
                $fresh = $this->meters->create($u, ['name' => 'Probe', 'capture' => 'period']);
                $unknown[] = self::unknownKeys($x, $periods->create($u, ['meter_id' => $fresh['id']] + $x), "$u/{$x['id']}.");
            }
            foreach ($b['bills'] as $x) {
                $unknown[] = self::unknownKeys($x, $bills->create($u, $x), "$u/{$x['id']}.");
            }
        }
        foreach ($p['tenancies'] as $t) $unknown[] = self::unknownKeys($t, $tenancies->create($t), "{$t['id']}.");
        foreach ($p['tenancy_statements'] as $s) {
            $unknown[] = self::unknownKeys($s, $tenancies->createStatement($s['tenancy_id'], $s), "{$s['id']}.");
        }
        self::assertSame([], array_merge(...$unknown), 'Felder, die die App nicht anlegt');
    }

    #[DataProvider('personas')]
    public function testTheAlignerCarriesThePersonaToToday(string $id): void
    {
        $p = self::load($id);
        $today = (new \DateTimeImmutable(self::END))->modify('+400 days')->format('Y-m-d');
        $out = DemoDataAligner::align($p, $today);
        self::assertArrayHasKey($today, $out['temperatures'], 'Temperaturen bis heute');
        $due = array_filter($out['reminders'], fn($r) => $r['next_due'] <= $today);
        self::assertCount(1, $due, 'genau ein fälliger Termin');
        foreach ($out['utilities'] as $u => $b) {
            $byMeter = [];
            foreach ($b['readings'] as $r) $byMeter[$r['meter_id']][] = $r['date'];
            foreach ($byMeter as $mid => $dates) {
                self::assertGreaterThan(self::END, max($dates), "$u/$mid: fortgeschrieben");
                self::assertLessThanOrEqual($today, max($dates));
            }
        }
        $this->import($out);
        foreach ($p['settings']['active_utilities'] as $u) {
            foreach ($this->consumption->forUtility($u)['meters'] as $pm) {
                self::assertSame([], $this->consumption->readingWarnings($u, $pm['meter']), "$u/{$pm['meter']['id']}");
            }
        }
    }

    // ── je Persona ───────────────────────────────────────────────────────

    public function testMieterin(): void
    {
        $p = self::load('mieterin');
        $this->import($p);
        $ten = new TenancyService($this->store, $this->meters, $this->i18n);
        $t = $ten->list()[0];

        // Heizwärme aus der Verbrauchsinfo, mit Vergleichswerten
        $heat = $this->meterRows('waerme', $t['meter_ids']['heat'][0]);
        self::assertEqualsWithDelta(4800, self::annual($heat, 2025), 1500, 'Heizwärme 2025 (kWh)');
        foreach ($p['utilities']['waerme']['periods'] as $x) {
            self::assertSame(['prev_month', 'prev_year_month', 'average_user'], array_keys($x['reference']));
        }
        $water = $this->consumption->forUtility('wasser')['monthly_total'];
        self::assertEqualsWithDelta(43, self::annual($water, 2025, 'm3'), 15, 'Wasser 2025 (m³)');

        // Budget im laufenden Abrechnungsjahr
        $b = (new TenancyBudgetService($ten, $this->meters, $this->consumption, $this->i18n))->budget($t['id'], self::END);
        self::assertSame(['2026-01-01', '2026-12-31'], [$b['period_from'], $b['period_to']]);
        self::assertCount(12, $b['months']);
        self::assertTrue($b['months'][0]['measured'], 'Januar gemessen');
        self::assertContains($b['months_estimated'], [7, 8], 'geschätzt ab Juni (Mai, wenn die letzte Wasserablesung davor lag)');
        self::assertGreaterThan(0.0, $b['components']['heat_eur']);
        self::assertGreaterThan(0.0, $b['components']['warm_water_eur']);
        self::assertGreaterThan(0.0, $b['components']['cold_water_eur']);
        self::assertEqualsWithDelta($b['prepaid_eur'], $b['expected_eur'], $b['prepaid_eur'] * 0.25, 'Vorauszahlung deckt grob die Kosten');
        self::assertSame(['months_estimated'], $b['assumptions']);

        // Preise im Mietverhältnis = was die Abrechnungen nahelegen
        foreach ($ten->statements($t['id']) as $s) {
            $entry = array_values(array_filter($t['prices'], fn($e) => ($e['statement_id'] ?? null) === $s['id']))[0] ?? null;
            self::assertNotNull($entry, "Preise aus {$s['id']}");
            foreach (TenancyService::derivePrices($s) as $f => $v) self::assertEqualsWithDelta($v, $entry[$f], 0.0001, $f);
            self::assertEqualsWithDelta($s['total_cost_eur'], array_sum(array_column($s['positions'], 'amount_eur')), 0.01);
        }

        // CO₂-Kosten bei Zentralheizung: Angaben der Abrechnung stimmen mit der Nachrechnung
        $costs = new Co2CostService($this->store, $this->settings, $this->meters, $this->consumption, $this->contracts, $this->i18n, $ten);
        $split = (new Co2SplitService($costs, $ten, $this->settings, $this->i18n))->forYear(2025);
        self::assertSame('central', $split['case']);
        self::assertSame([], $split['checks']);
    }

    public function testEtwFernwaerme(): void
    {
        $this->import(self::load('etw-fernwaerme'));
        $m = $this->meters->list('fernwaerme')[0];
        $c = array_values(array_filter($this->contracts->list('fernwaerme', $m['id']), fn($x) => empty($x['is_shadow'])))[0];

        // feste Kosten = Grundpreis + Anschlussleistung × Leistungspreis / 12 + Messpreis / 12
        $base = $this->contracts->valueOnDate($c['base_prices'], 'eur_per_month', '2025-06-15');
        $capacity = $c['capacity_kw'] * $this->contracts->valueOnDate($c['capacity_prices'], 'eur_per_kw_year', '2025-06-15') / 12;
        $metering = $this->contracts->valueOnDate($c['metering_prices'], 'eur_per_year', '2025-06-15') / 12;
        self::assertGreaterThan(0.0, $capacity);
        self::assertEqualsWithDelta($base + $capacity + $metering, $this->contracts->fixedPerMonthOn($c, '2025-06-15'), 0.001);
        $june = array_column($this->meterRows('fernwaerme', $m['id']), null, 'ym')['2025-06'];
        self::assertEqualsWithDelta($base + $capacity + $metering, $june['base_price_eur'], 0.01, 'Leistungspreis in den festen Kosten');
        self::assertEqualsWithDelta($june['kwh'] * $c['co2_g_per_kwh'] / 1000, $june['co2_kg'], 0.2, 'Netzfaktor aus dem Vertrag');

        $rows = $this->meterRows('fernwaerme', $m['id']);
        self::assertEqualsWithDelta(8500, self::annual($rows, 2025), 2500, 'Fernwärme 2025 (kWh)');

        // Versorgerrechnung 2025 passt zur eigenen Rechnung
        $bills = new BillService($this->store, $this->meters, $this->contracts, $this->consumption, $this->i18n);
        $bill = $bills->list('fernwaerme')[0];
        $cmp = $bills->compare('fernwaerme', $bill['id']);
        self::assertSame('ok', $cmp['verdict']);
        self::assertEqualsWithDelta(0.0, $cmp['delta']['eur'], 2.0);
        self::assertEqualsWithDelta($bill['invoice']['advances_paid_eur'], $cmp['ours']['advances'], 0.01);
    }

    public function testEigenheimKlassisch(): void
    {
        $p = self::load('eigenheim-klassisch');
        $this->import($p);
        $gas = $this->meters->list('gas')[0];
        self::assertNotEmpty($gas['baseline_events'], 'Zäsur vorgeführt');
        $rows = $this->meterRows('gas', $gas['id']);
        self::assertEqualsWithDelta(20000, self::annual($rows, 2025), 5000, 'Gas 2025 (kWh)');
        self::assertEqualsWithDelta(11.1, self::annual($rows, 2025) / self::annual($rows, 2025, 'm3'), 0.3, 'Umrechnung m³ → kWh mit den Faktoren');

        // Vertragswechsel: der neue Vertrag beginnt am Tag nach dem alten
        $real = array_values(array_filter($this->contracts->list('gas', $gas['id']), fn($c) => empty($c['is_shadow'])));
        self::assertCount(2, $real);
        self::assertSame((new \DateTimeImmutable($real[0]['end']))->modify('+1 day')->format('Y-m-d'), $real[1]['start']);
        self::assertNotSame($real[0]['provider'], $real[1]['provider']);

        // Gartenwasser: Subzähler, vom Schmutzwasser abgezogen
        [$haupt, $garten] = $this->meters->list('wasser');
        self::assertSame($haupt['id'], $garten['parent_meter_id']);
        self::assertFalse(MeterService::countsInTotals($garten));
        $july = array_column($this->meterRows('wasser', $haupt['id']), null, 'ym')['2025-07'];
        self::assertSame('trinkwasser_minus_abzug', $july['schmutzwasser']['basis']);
        self::assertGreaterThan(0.0, $july['schmutzwasser']['abzug_m3']);
        self::assertLessThan($july['trinkwasser']['m3'], $july['schmutzwasser']['m3']);

        self::assertContains('schornsteinfeger', array_column($p['reminders'], 'category'));
        self::assertContains('heizung_wartung', array_column($p['reminders'], 'category'));
    }

    public function testEigenheimModern(): void
    {
        $this->import(self::load('eigenheim-modern'));

        // Jahresarbeitszahl aus Wärmemengen- und Stromzähler der Wärmepumpe
        $hp = (new HeatPumpService($this->meters, $this->consumption))->forYear(2025);
        self::assertCount(1, $hp['pumps']);
        self::assertSame(12, $hp['pumps'][0]['months_covered']);
        self::assertGreaterThanOrEqual(2.5, $hp['pumps'][0]['jaz']);
        self::assertLessThanOrEqual(5.0, $hp['pumps'][0]['jaz']);

        // PV mit Speicher: Autarkie, Vollzyklen
        $pv = array_column((new PvSummaryService($this->consumption, $this->settings))->compute()['yearly'], null, 'year')[2025];
        self::assertSame(12, $pv['months_covered']);
        self::assertGreaterThanOrEqual(0.15, $pv['autarkiequote']);
        self::assertLessThanOrEqual(0.85, $pv['autarkiequote']);
        self::assertGreaterThan(100, $pv['battery']['full_cycles']);
        self::assertLessThanOrEqual(300, $pv['battery']['full_cycles']);
        self::assertEqualsWithDelta(92.0, $pv['battery']['efficiency_pct'], 1.0);

        // Wallbox: Subzähler des Haushalts, Ladestrom-Nachweis über dessen Vertrag
        $ev = array_values(array_filter($this->meters->list('strom'), fn($m) => Utilities::roleOf('strom', $m) === 'ev_charger'))[0];
        self::assertNotNull($ev['parent_meter_id']);
        $report = (new EvChargingReportService($this->meters, $this->readings, $this->consumption, $this->settings, $this->i18n))->report($ev['id'], 2025);
        self::assertSame($ev['parent_meter_id'], $report['payer_meter_id']);
        self::assertGreaterThanOrEqual(2000.0, $report['total']['kwh']);
        self::assertLessThanOrEqual(3000.0, $report['total']['kwh']);

        // Wärmepumpentarif mit § 14a Modul 1, dynamischer Schattenvertrag
        $wp = array_values(array_filter($this->meters->list('strom'), fn($m) => Utilities::roleOf('strom', $m) === 'heat_pump'))[0];
        $june = array_column($this->meterRows('strom', $wp['id']), null, 'ym')['2025-06'];
        self::assertGreaterThan(0.0, $june['grid_reduction_eur'] ?? 0.0);
        $dynamic = array_values(array_filter($this->contracts->list('strom'), fn($c) => ($c['price_model'] ?? null) === 'dynamic'));
        self::assertCount(1, $dynamic);
        self::assertTrue($dynamic[0]['is_shadow']);
    }

    public function testTheDockerImageShipsThePersonas(): void
    {
        $lines = array_map('trim', file(self::root() . '/.dockerignore') ?: []);
        $i = array_search('!demo-data/translations.json', $lines, true);
        self::assertNotFalse($i);
        self::assertSame('!demo-data/personas/', $lines[$i + 1] ?? null);
    }
}
