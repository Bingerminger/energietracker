<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\BackupService;
use Energietracker\Services\ForecastService;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * v3.13.0 (Paket pruefhaushalt) — der Prüfhaushalt Wärmepumpe.
 *
 * Die Beispielhaushalte rechnen den Wärmebedarf mit dem Modell der App (Konstante × Heizgradtage),
 * daran lässt sich ein Wettermodell nicht beurteilen. tests/fixtures/heatpump-household/ entsteht aus
 * eigener Physik (generate.mjs, ohne Code und Konstanten der App): zwei Haushalte, drei Ablese-Varianten
 * derselben Wahrheit, die Wahrheit getrennt. tools/rueckrechnung.php misst daran die Prognose.
 *
 * Geprüft wird hier nur, dass der Prüfhaushalt stimmt (aktuell, in sich stimmig, einspielbar) und die
 * Rückrechnung für einen Schnitt alle Maße liefert. Die Schwellen prüfen die Fachpakete. Alle Tage
 * sind fest (Schnitte 2024-04 und 2025-01), nie heute.
 */
#[CoversNothing]
final class HeatPumpHouseholdTest extends ServiceTestCase
{
    private const DIR = __DIR__ . '/../../fixtures/heatpump-household/';

    /** @var array<string,array<string,mixed>> */
    private static array $cache = [];

    private static function json(string $name): array
    {
        return self::$cache[$name] ??= json_decode((string)file_get_contents(self::DIR . $name), true, 512, JSON_THROW_ON_ERROR);
    }

    /** Tag → Wert einer Reihe der Wahrheit. */
    private static function daily(array $truth, string $series): array
    {
        $out = [];
        $t = strtotime($truth['daily']['from'] . ' 12:00:00');
        foreach ($truth['daily']['series'][$series] as $i => $v) $out[date('Y-m-d', $t + $i * 86400)] = (float)$v;
        return $out;
    }

    private static function sumBetween(array $daily, string $from, string $toExcl): float
    {
        $s = 0.0;
        foreach ($daily as $d => $v) if ($d >= $from && $d < $toExcl) $s += $v;
        return $s;
    }

    public static function variants(): array
    {
        $out = [];
        foreach (['a', 'b'] as $h) foreach (['v1', 'v2', 'v3'] as $v) $out["$h-$v"] = [$h, $v];
        return $out;
    }

    /** Die Dateien sind, was der Generator schreibt (fester Seed; `--check` vergleicht nur). */
    public function testTheFixtureIsWhatTheGeneratorWrites(): void
    {
        exec('command -v node 2>/dev/null', $which, $found);
        if ($found !== 0) self::markTestSkipped('node fehlt');
        exec('node ' . escapeshellarg(self::DIR . 'generate.mjs') . ' --check 2>&1', $out, $rc);
        self::assertSame(0, $rc, implode("\n", $out));
    }

    /** Eigene Physik: Heizgrenze und Arbeitszahl ergeben sich, keine Grenze ist gesetzt. */
    public function testThePhysicsIsItsOwn(): void
    {
        // Der Generator lädt nur Node-Module, keinen Code der App (src/, tools/, public/)
        $src = (string)file_get_contents(self::DIR . 'generate.mjs');
        preg_match_all('/^import .* from \'([^\']+)\'/m', $src, $m);
        self::assertNotSame([], $m[1]);
        foreach ($m[1] as $mod) self::assertStringStartsWith('node:', $mod);
        self::assertDoesNotMatchRegularExpression('/readFileSync\([^)]*(src|public|tools)\//', $src);
        $a = self::json('a-truth.json'); $b = self::json('b-truth.json');
        // Die Heizgrenze ergibt sich aus Verlust und Gewinnen (rund 13 und 11 °C), der Neubau liegt tiefer
        self::assertEqualsWithDelta(13.0, $a['heat_limit']['limit_c'], 1.5);
        self::assertEqualsWithDelta(11.0, $b['heat_limit']['limit_c'], 1.5);
        self::assertLessThan($a['heat_limit']['limit_c'], $b['heat_limit']['limit_c']);
        foreach ($a['years'] as $y) self::assertGreaterThanOrEqual(3.0, $y['jaz'], 'JAZ Luft/Wasser rund 3,2 bis 3,6');
        foreach ($a['years'] as $y) self::assertLessThanOrEqual(3.7, $y['jaz']);
        // Der kalte Winter braucht mehr Wärme als der milde (Juli 2024 bis Juni 2025 gegen das Jahr davor)
        self::assertGreaterThan($a['years'][1]['heat_space_kwh'] * 1.15, $a['years'][2]['heat_space_kwh']);
        // Die Einstellungen setzen keine Heizgrenze; die Heizungsart ist Strom (Entscheidung E4)
        foreach (['a-v1.json', 'b-v1.json'] as $f) {
            $s = self::json($f)['settings'];
            self::assertArrayNotHasKey('hdd_base_temp', $s);
            self::assertSame('strom', $s['waerme_energietraeger']);
        }
        foreach (self::json('a-v1.json')['utilities']['strom']['meters'] as $m) self::assertArrayNotHasKey('hdd_base_temp', $m);
        // Das Klimanormal hat das Format der App und dazu die Einstrahlung je Monat
        $n = self::json('climate_normal.json');
        self::assertCount(366, $n['doy_avg']);
        self::assertArrayHasKey('6.0', $n['hdd']);
        self::assertArrayHasKey('22.0', $n['hdd']);
        self::assertSame(30, $n['hdd']['15.0']['years']);
        self::assertCount(12, $n['rad_month']);
        self::assertGreaterThan(5 * $n['rad_month'][11], $n['rad_month'][5], 'Juni scheint mehr als Dezember');
    }

    /** Die Wahrheit ist in sich stimmig: Bilanz an Z1 und Z2, Rest, HT + NT, Rechnungen. */
    public function testTheTruthAddsUp(): void
    {
        $t = self::json('a-truth.json');
        $s = $t['daily']['series'];
        $n = count($s['wp_kwh']);
        for ($i = 0; $i < $n; $i++) {
            $z1 = $s['z1_imp_ht'][$i] + $s['z1_imp_nt'][$i] - $s['z1_exp'][$i];
            $z2 = $s['z2_imp_ht'][$i] + $s['z2_imp_nt'][$i] - $s['z2_exp'][$i];
            // Bezug − Einspeisung = Last − Erzeugung (Eigenverbrauch hebt sich heraus)
            $load = $s['wp_kwh'][$i] + $s['house_kwh'][$i] + $s['ev_kwh'][$i] + $s['bat_charge_kwh'][$i] - $s['bat_discharge_kwh'][$i];
            self::assertEqualsWithDelta($load - $s['pv_kwh'][$i], $z1, 0.002, "Bilanz Z1 am Tag $i");
            // Kaskade: Z1 = Wärmepumpe + Z2 (netto)
            self::assertEqualsWithDelta($s['wp_kwh'][$i] + $z2, $z1, 0.002, "Kaskade am Tag $i");
            // Rest je Zählwerk = Z1 − Z2, nie negativ
            self::assertEqualsWithDelta($s['z1_imp_ht'][$i] - $s['z2_imp_ht'][$i], $s['rest_ht'][$i], 0.0002);
            self::assertEqualsWithDelta($s['z1_imp_nt'][$i] - $s['z2_imp_nt'][$i], $s['rest_nt'][$i], 0.0002);
            self::assertGreaterThanOrEqual(-0.0001, $s['rest_ht'][$i]);
            self::assertGreaterThanOrEqual(-0.0001, $s['rest_nt'][$i]);
            // der Netzbezug der Wärmepumpe ist höchstens ihr Verbrauch (den Rest deckt die Sonne über Z2)
            self::assertLessThanOrEqual($s['wp_kwh'][$i] + 0.001, $s['rest_ht'][$i] + $s['rest_nt'][$i]);
        }
        // Speicher erst ab Juli 2023, Wallbox erst ab 15.06.2024
        $bat = self::daily($t, 'bat_charge_kwh'); $ev = self::daily($t, 'ev_kwh');
        self::assertSame(0.0, self::sumBetween($bat, '2022-07-01', '2023-07-01'));
        self::assertGreaterThan(1000, self::sumBetween($bat, '2023-07-01', '2024-07-01'));
        self::assertSame(0.0, self::sumBetween($ev, '2022-07-01', '2024-06-15'));
        // Rechnungen: HT + NT, Arbeits- und Grundpreis, Ergebnis = Kosten − Abschläge
        $bp = self::json('a-v1.json');
        $prices = [];
        foreach ($bp['utilities']['strom']['contracts'] as $c) {
            $prices[$c['id']] = ['ht' => $c['working_prices'][0]['ct_per_kwh'], 'nt' => array_values($c['working_prices_by_meter'])[0][0]['ct_per_kwh'], 'base' => $c['base_prices'][0]['eur_per_month']];
        }
        foreach (['wp', 'house'] as $k) foreach ($t['bills'][$k] as $bill) {
            $p = $prices[$bill['contract_id']];
            self::assertEqualsWithDelta($bill['kwh'], $bill['kwh_ht'] + $bill['kwh_nt'], 0.11);
            self::assertEqualsWithDelta(($bill['kwh_ht'] * $p['ht'] + $bill['kwh_nt'] * $p['nt']) / 100 + $p['base'] * $bill['months'], $bill['cost_eur'], 0.1);
            self::assertEqualsWithDelta($bill['cost_eur'] - $bill['advances_paid_eur'], $bill['result_eur'], 0.011);
        }
        // Haushalt B: ein Zähler für Haushalt und Wärmepumpe
        $b = self::json('b-truth.json')['daily']['series'];
        foreach ($b['imp_kwh'] as $i => $v) self::assertEqualsWithDelta($b['house_kwh'][$i] + $b['wp_kwh'][$i], $v, 0.0002);
    }

    /** Die Ablesungen folgen der Wahrheit (außer der geschätzten), HT und NT am selben Tag. */
    #[DataProvider('variants')]
    public function testReadingsFollowTheTruth(string $h, string $v): void
    {
        $bk = self::json("$h-$v.json");
        $truth = self::json("$h-truth.json");
        $series = $truth['layout']['meter_series'];
        $tol = $v === 'v3' ? 0.011 : 0.11;
        $dates = [];
        foreach ($bk['utilities'] as $u => $pots) foreach ($pots['readings'] as $r) $byMeter[$r['meter_id']][] = $r;
        foreach ($byMeter as $mid => $list) {
            $daily = self::daily($truth, $series[$mid]);
            for ($i = 1; $i < count($list); $i++) {
                $a = $list[$i - 1]; $b = $list[$i];
                self::assertLessThan($b['date'], $a['date']);
                if ($a['is_estimated'] || $b['is_estimated']) continue;
                self::assertEqualsWithDelta(self::sumBetween($daily, $a['date'], $b['date']), $b['counter'] - $a['counter'], $tol, "$mid {$a['date']} → {$b['date']}");
            }
            $dates[$mid] = array_column($list, 'date');
        }
        if ($h === 'a') {
            self::assertSame($dates['m_strom_z1_ht'], $dates['m_strom_z1_nt'], 'HT und NT am selben Tag (Z1)');
            self::assertSame($dates['m_strom_z2_ht'], $dates['m_strom_z2_nt'], 'HT und NT am selben Tag (Z2)');
        }
        $main = $dates[$h === 'a' ? 'm_strom_z1_ht' : 'm_strom_b'];
        $gaps = [];
        for ($i = 1; $i < count($main); $i++) $gaps[] = (strtotime($main[$i]) - strtotime($main[$i - 1])) / 86400;
        $est = array_filter($bk['utilities']['strom']['readings'], fn($r) => $r['is_estimated']);
        if ($v === 'v1') {
            self::assertGreaterThan(45, max($gaps), 'eine Lücke');
            self::assertCount(1, array_unique(array_column($est, 'date')), 'eine geschätzte Ablesung');
        } elseif ($v === 'v2') {
            self::assertLessThanOrEqual(12, count($main), 'erst vierteljährlich, dann jährlich');
            self::assertGreaterThan(250, max($gaps));
            self::assertSame([], $est);
        } else {
            self::assertEqualsWithDelta(1.0, max($gaps), 0.05, 'jeden Tag ein Stand');
            self::assertSame([], $est);
        }
    }

    /** Viertelstunden (A/V3, Z2 im Jahr 2024): jede Viertelstunde des Jahres, Summe wie die Wahrheit. */
    public function testQuarterHoursOfTheLastYear(): void
    {
        $bk = self::json('a-v3.json');
        $truth = self::json('a-truth.json');
        self::assertCount(2, $bk['intervals']['series']);
        foreach ($bk['intervals']['series'] as $s) {
            $file = $bk['interval_files'][$s['id']];
            self::assertSame(35136, $s['slots'], '2024 hat 366 Tage');
            self::assertCount($s['slots'], $file['values']);
            self::assertSame(900, $file['step']);
            self::assertSame('2024-01-01T00:00:00+01:00', $file['start']);
            $reg = str_ends_with($s['meter_id'], '_ht') ? 'z2_imp_ht' : 'z2_imp_nt';
            self::assertEqualsWithDelta(self::sumBetween(self::daily($truth, $reg), '2024-01-01', '2025-01-01'), array_sum($file['values']), 1.0);
        }
        foreach (['a-v1.json', 'a-v2.json', 'b-v3.json'] as $f) self::assertSame([], self::json($f)['intervals']['series'], 'Viertelstunden nur ein Jahr, ein Zähler');
        // Ladevorgänge wie evcc: Tag des Absteckens, Summe = Wallbox-Zähler
        $ev = $bk['ev_sessions'];
        self::assertGreaterThan(200, count($ev));
        $wallbox = array_values(array_filter($bk['utilities']['strom']['readings'], fn($r) => $r['meter_id'] === 'm_strom_wallbox'));
        self::assertEqualsWithDelta(end($wallbox)['counter'], array_sum(array_column($ev, 'charged_kwh')), 60.0);
        foreach (array_slice($ev, 0, 20) as $e) self::assertSame(substr($e['finished'], 0, 10), $e['date']);
    }

    /** Jede Variante lässt sich einspielen; A/V1 und B/V1 rechnen danach Prognose und Saldo. */
    #[DataProvider('variants')]
    public function testEveryVariantImports(string $h, string $v): void
    {
        $payload = self::json("$h-$v.json");
        $report = (new BackupService($this->store, $this->i18n))->import($payload, true);
        self::assertTrue($report['dry_run']);
        self::assertSame([], $report['problems']);
        if ($v !== 'v1') return;
        (new BackupService($this->store, $this->i18n))->import($payload, false, true);
        $forecasts = new ForecastService($this->consumption, $this->regression, $this->settings, $this->contracts, $this->i18n);
        $target = $h === 'a' ? $this->meters->target('strom', 'g_strom_z1') : $this->meters->get('strom', 'm_strom_b');
        self::assertNotNull($target);
        $f = $forecasts->forMeter('strom', $target, ['today' => '2024-04-01']);
        self::assertTrue($f['valid']);
        self::assertSame('2024-04', $f['forecast'][0]['ym']);
        $st = $this->consumption->contractStatus('strom', $target, '2024-04-01')['contracts'];
        $cur = array_values(array_filter($st, fn($c) => $c['is_current']))[0];
        self::assertNotNull($cur['projected_end_balance']);
        self::assertSame($h === 'a' ? '2023-10-01' : '2024-04-01', $cur['billing_period']['from'], 'Abrechnungsjahr nicht im Januar');
    }

    /** Die Rückrechnung läuft für einen Schnitt durch und liefert alle Maße, vorab und nachgerechnet. */
    public function testRueckrechnungDeliversAllMeasures(): void
    {
        $json = $this->dataDir . '/rueck.json';
        exec(implode(' ', array_map('escapeshellarg', [PHP_BINARY, dirname(__DIR__, 3) . '/tools/rueckrechnung.php', '--runs', 'A/V1,B/V1', '--cut', '2025-01',
            '--json', $json, '--md', $this->dataDir . '/rueck.md', '--quiet'])) . ' 2>&1', $out, $rc);
        self::assertSame(0, $rc, implode("\n", $out));
        $r = json_decode((string)file_get_contents($json), true, 512, JSON_THROW_ON_ERROR);
        self::assertCount(2, $r['runs']);
        [$a, $b] = $r['runs'];
        self::assertSame('2025-01-01', $a['cuts'][0]['cut']);
        foreach (['vorab', 'nachgerechnet'] as $mode) {
            $blocks = $a['cuts'][0]['modes'][$mode]['blocks'];
            foreach (['heat_pump', 'pv', 'feed_in', 'battery_import', 'wallbox_parent', 'wallbox_meter'] as $k) {
                self::assertTrue($blocks[$k]['valid'], "$mode $k");
                foreach (['annual_err_pct', 'wape_pct', 'max_month_err_pct', 'band_months', 'band_hits', 'truth_kwh', 'forecast_kwh'] as $m) {
                    self::assertArrayHasKey($m, $blocks[$k]['metrics'], "$mode $k $m");
                }
                self::assertCount(12, $blocks[$k]['months']);
            }
            self::assertArrayHasKey('winter_wape_pct', $blocks['wallbox_meter']['metrics']);
            self::assertSame('billed_kwh', $blocks['heat_pump']['source'], 'Kaskade: Rest aus der Gruppe Z1');
            // nichts nach dem Schnitt: Ablesungen bis zum Schnitt, Wetter und Ladevorgänge bis zum Vortag
            self::assertSame('2025-01', $blocks['heat_pump']['first_ym']);
            $seen = $a['cuts'][0]['modes'][$mode]['data'];
            self::assertLessThanOrEqual('2025-01-01', $seen['last_reading']);
            self::assertGreaterThanOrEqual('2024-12-01', $seen['last_reading'], 'V1: letzte Ablesung Anfang Dezember');
            self::assertSame('2024-12-31', $seen['last_temperature']);
            self::assertLessThan('2025-01-01', $seen['last_session']);
            // der Rest ist Z1 ohne den Haushalt, also deutlich weniger als der ganze Bezug an Z1
            self::assertLessThan($blocks['battery_import']['metrics']['forecast_kwh'] - 300, $blocks['heat_pump']['metrics']['forecast_kwh']);
            self::assertTrue($b['cuts'][0]['modes'][$mode]['blocks']['heat_pump']['valid']);
        }
        // Wahrheit des Fensters (Januar bis Dezember 2025): Rest der Wärmepumpe aus truth.json
        $truth = self::json('a-truth.json');
        $want = self::sumBetween(self::daily($truth, 'rest_ht'), '2025-01-01', '2026-01-01') + self::sumBetween(self::daily($truth, 'rest_nt'), '2025-01-01', '2026-01-01');
        self::assertEqualsWithDelta($want, $a['cuts'][0]['modes']['vorab']['blocks']['heat_pump']['metrics']['truth_kwh'], 0.5);
        // nachgerechnet rechnet mit dem Ist-Wetter des Fensters: Heizgradtage (15 °C) wie aus den Tagesmitteln
        $hdd = 0.0;
        foreach (self::daily($truth, 't_avg') as $d => $t) if ($d >= '2025-01-01' && $d < '2026-01-01') $hdd += max(0.0, 15.0 - $t);
        self::assertEqualsWithDelta($hdd, $a['cuts'][0]['modes']['nachgerechnet']['normal_hdd15_window'], 1.0);
        self::assertNotEqualsWithDelta($hdd, $a['cuts'][0]['modes']['vorab']['normal_hdd15_window'], 5.0);
        // Saldo nach 3 Monaten (Abrechnungsjahr ab 01.10.2024) und HT/NT
        $saldo = array_values(array_filter($a['saldo'], fn($s) => $s['mode'] === 'vorab' && $s['key'] === 'wp'));
        self::assertCount(1, $saldo);
        self::assertSame(3, $saldo[0]['after_months']);
        self::assertSame('2024-10-01', $saldo[0]['period']['from']);
        self::assertArrayHasKey('err_eur', $saldo[0]);
        self::assertArrayHasKey('sign_ok', $saldo[0]);
        self::assertArrayHasKey('energy_gap_month_max_kwh', $a['cuts'][0]['modes']['vorab']['htnt']['z1']);
        self::assertArrayHasKey('report_gap_eur', $a['cuts'][0]['modes']['vorab']['htnt']['report']);
        // je Zeile der Schwellen ein Wert (die Schwellen selbst prüft dieser Test nicht)
        $ids = array_column(array_filter($r['thresholds'], fn($t) => $t['household'] === 'A'), 'id');
        // (die Einspeisung hat im Januar keinen Saldo-Fall: ihr Abrechnungsjahr beginnt am 01.01.)
        foreach (['hp_post_annual', 'hp_pre_mean', 'hp_max_month', 'saldo_mean', 'saldo_sign', 'band_months', 'pv_post_annual', 'feed_pre_mean', 'htnt_energy', 'htnt_base', 'htnt_report'] as $id) {
            self::assertContains($id, $ids);
        }
        // Entscheidungen 2026-11-12: Urteilsfenster ab 2024-07 für Wärmepumpe, Saldo und Einspeisung; B nur feste Schwellen
        self::assertArrayHasKey('2024-07', $a['summary_from']);
        self::assertArrayHasKey('2024-07', $b['summary_from']);
        $byId = array_column(array_filter($r['thresholds'], fn($t) => $t['household'] === 'A'), null, 'id');
        foreach (['hp_pre_mean', 'saldo_mean', 'feed_pre_mean'] as $id) self::assertSame('2024-07', $byId[$id]['judge_from']);
        self::assertNull($byId['pv_pre_mean']['judge_from']);
        $bRel = array_values(array_filter($r['thresholds'], fn($t) => $t['household'] === 'B' && $t['id'] === 'hp_pre_mean'))[0];
        self::assertStringContainsString('nicht schlechter als alt', $bRel['text']);
        self::assertStringContainsString('1 × alt', $bRel['limit']);
        $sw = self::json('schwellen.json');
        foreach ($sw['rows'] as $row) {
            if (in_array($row['block'], ['heat_pump', 'saldo', 'feed_in'], true)) self::assertSame('2024-07', $row['judge_from'] ?? null, $row['id']);
        }
        self::assertSame('not_worse', $sw['households']['B']['relative']);
        $feedRow = array_values(array_filter($sw['rows'], fn($row) => $row['id'] === 'feed_saldo_mean'))[0];
        self::assertSame(['saldo', 'feed_abs_err_eur', 0.8, '2024-07'], [$feedRow['block'], $feedRow['measure'], $feedRow['factor'], $feedRow['judge_from']]);
        self::assertSame(['annual_err_pct', 'max_month_err_pct', 'energy_gap_kwh'], $sw['measures_confirmed']['ids']);
        self::assertStringContainsString('## Schwellen', (string)file_get_contents($this->dataDir . '/rueck.md'));
    }
}
