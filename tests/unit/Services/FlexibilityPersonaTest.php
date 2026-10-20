<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\BackupService;
use Energietracker\Services\EvccService;
use Energietracker\Services\FlexibilityInsightService;
use Energietracker\Services\FlexibilityService;
use Energietracker\Services\IntervalService;
use Energietracker\Services\PvSummaryService;
use Energietracker\Services\ReadingImportService;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * v3.6.0 — Die Kennzahlen der Seite „Flexibilität“ für den Beispielhaushalt
 * „Eigenheim mit Wärmepumpe und PV“ (eigenheim-modern, 2025), unabhängig
 * nachgerechnet: direkt aus den Reihen der Datei, mit eigener Uhr (DateTime je
 * Viertelstunde statt IntervalService::localClock) und eigener Auswertung der
 * Zeitfenster. Dazu die Zahlen der Anleitung (DE/EN) gegen den Server.
 */
#[CoversClass(FlexibilityService::class)]
#[CoversClass(FlexibilityInsightService::class)]
final class FlexibilityPersonaTest extends ServiceTestCase
{
    private const YEAR = 2025;
    private static ?array $persona = null;
    private static ?array $clock = null;

    private static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    private static function persona(): array
    {
        return self::$persona ??= json_decode((string)file_get_contents(self::root() . '/demo-data/personas/eigenheim-modern.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return list<?float> */
    private static function series(string $id): array
    {
        $f = self::persona()['interval_files'][$id] ?? null;
        self::assertNotNull($f, "Reihe $id fehlt");
        return array_map(fn($v) => $v === null ? null : (float)$v, $f['values']);
    }

    /**
     * Eigene Uhr: Monat, Minute, Wochentag und Datum je Viertelstunde, mit
     * DateTime in Europe/Berlin gerechnet.
     *
     * @return list<array{0:int,1:int,2:int,3:string}>
     */
    private static function clock(): array
    {
        if (self::$clock !== null) return self::$clock;
        $tz = new \DateTimeZone('Europe/Berlin');
        $start = (new \DateTimeImmutable(self::YEAR . '-01-01 00:00:00', $tz))->getTimestamp();
        $end = (new \DateTimeImmutable((self::YEAR + 1) . '-01-01 00:00:00', $tz))->getTimestamp();
        $out = [];
        for ($ts = $start; $ts < $end; $ts += 900) {
            $d = (new \DateTimeImmutable('@' . $ts))->setTimezone($tz);
            $out[] = [(int)$d->format('n'), (int)$d->format('G') * 60 + (int)$d->format('i'), (int)$d->format('N'), $d->format('Y-m-d')];
        }
        return self::$clock = $out;
    }

    protected function setUp(): void
    {
        parent::setUp();
        (new BackupService($this->store, $this->i18n))->import(self::persona());
        $m = new \Energietracker\Storage\Migrator($this->store);
        if ($m->needsMigration()) $m->migrate();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        gc_collect_cycles();
    }

    /** @return array{0:FlexibilityService,1:FlexibilityInsightService} */
    private function services(): array
    {
        $iv = new IntervalService($this->store, $this->settings);
        $flex = new FlexibilityService($iv, $this->meters, $this->contracts);
        $ins = new FlexibilityInsightService($iv, $this->meters, $this->contracts, $flex);
        // v3.6.0 (Paket flexplus) — wie bootstrap.php: das Auto mit seinen Steckzeiten aus evcc
        $ins->attachSessions(new EvccService($this->store, $this->meters, $this->readings,
            new ReadingImportService($this->readings, $this->meters, $this->i18n), $this->settings));
        return [$flex, $ins];
    }

    /** Umsatzsteuer des dynamischen Angebots der Datei (Prozent). */
    private static function vatOfTheOffer(): float
    {
        foreach (self::persona()['utilities']['strom']['contracts'] as $c) {
            if (($c['price_model'] ?? null) === 'dynamic') return (float)$c['dynamic']['vat_pct'];
        }
        self::fail('kein dynamisches Angebot in der Datei');
    }

    /**
     * Ladefenster der Wallbox aus den Ladevorgängen der Datei, eigene Rechnung:
     * erste und letzte Viertelstunde 2025, die Anstecken bis Abstecken berührt.
     *
     * @return list<array{0:int,1:int}>
     */
    private static function plugWindows(): array
    {
        $tz = new \DateTimeZone('Europe/Berlin');
        $start = (new \DateTimeImmutable(self::YEAR . '-01-01 00:00:00', $tz))->getTimestamp();
        $out = [];
        foreach (self::persona()['ev_sessions'] as $s) {
            if ($s['meter_id'] !== 'm_strom_wallbox') continue;
            $a = (new \DateTimeImmutable($s['created']))->getTimestamp();
            $b = (new \DateTimeImmutable($s['finished']))->getTimestamp();
            $i0 = (int)floor(($a - $start) / 900);
            $i1 = (int)ceil(($b - $start) / 900) - 1;
            if ($i1 < 0 || $i0 >= 35040) continue;
            $out[] = [max(0, $i0), min(35039, $i1)];
        }
        sort($out);
        return $out;
    }

    /** Börsenmittel je Monat aus der Preisreihe. @return array<int,float> */
    private static function monthMeans(array $p): array
    {
        $s = []; $n = [];
        foreach (self::clock() as $i => [$mo]) {
            if ($p[$i] === null) continue;
            $s[$mo] = ($s[$mo] ?? 0.0) + $p[$i];
            $n[$mo] = ($n[$mo] ?? 0) + 1;
        }
        return array_map(fn($mo) => $s[$mo] / $n[$mo], array_combine(array_keys($s), array_keys($s)));
    }

    public function testTheClockMatchesTheSeries(): void
    {
        self::assertCount(35040, self::clock());
        self::assertCount(35040, self::series('p-de-lu-2025'));
        self::assertSame([1, 0, 3, '2025-01-01'], self::clock()[0], '1. Januar 2025: Mittwoch');
        self::assertSame([12, 23 * 60 + 45, 3, '2025-12-31'], self::clock()[35039]);
    }

    /** Je Stromzähler: kWh mit Preis, günstig, negativ, eigener Ø, Börse Ø, Profilfaktor, Vorteil. */
    public function testMeterFiguresFollowFromTheQuarterHours(): void
    {
        [$flex] = $this->services();
        $r = $flex->analyze(self::YEAR);
        $p = self::series('p-de-lu-2025');
        $mean = self::monthMeans($p);
        $ids = ['m_strom_haushalt', 'm_strom_waermepumpe', 'm_strom_wallbox'];
        self::assertEqualsCanonicalizing($ids, array_column($r['meters'], 'meter_id'));
        foreach ($ids as $id) {
            $e = self::series("e-strom-$id-2025");
            $kwh = 0.0; $ep = 0.0; $cheap = 0.0; $neg = 0.0; $market = 0.0;
            foreach (self::clock() as $i => [$mo]) {
                if ($e[$i] === null || $p[$i] === null) continue;
                $kwh += $e[$i];
                $ep += $e[$i] * $p[$i];
                $market += $e[$i] * $mean[$mo];
                if ($p[$i] < $mean[$mo]) $cheap += $e[$i];
                if ($p[$i] < 0) $neg += $e[$i];
            }
            $t = array_values(array_filter($r['meters'], fn($m) => $m['meter_id'] === $id))[0]['total'];
            self::assertEqualsWithDelta($kwh, $t['priced_kwh'], 0.01, "$id kWh");
            self::assertEqualsWithDelta($cheap, $t['cheap_kwh'], 0.01, "$id günstig");
            self::assertEqualsWithDelta($neg, $t['negative_kwh'], 0.01, "$id negativ");
            self::assertEqualsWithDelta($ep / $kwh, $t['own_ct'], 0.001, "$id eigener Ø");
            self::assertEqualsWithDelta($market / $kwh, $t['market_ct'], 0.001, "$id Börse Ø");
            self::assertEqualsWithDelta($ep / $market, $t['profile_factor'], 0.001, "$id Profilfaktor");
            self::assertEqualsWithDelta(($market - $ep) / 100, $t['advantage_eur'], 0.01, "$id Vorteil");
        }
    }

    /** Speicher: geladen und entladen zum Börsenpreis ihrer Viertelstunde. */
    public function testBatteryValueFollowsFromTheQuarterHours(): void
    {
        [$flex] = $this->services();
        $b = $flex->analyze(self::YEAR)['battery']['total'];
        $p = self::series('p-de-lu-2025');
        $in = self::series('e-pv_erzeugung-m_pv_speicher_laden-2025');
        $out = self::series('e-pv_erzeugung-m_pv_speicher_entladen-2025');
        $ci = 0.0; $co = 0.0; $si = 0.0; $so = 0.0;
        foreach ($p as $i => $v) {
            if ($v === null) continue;
            $si += (float)$in[$i]; $ci += (float)$in[$i] * $v;
            $so += (float)$out[$i]; $co += (float)$out[$i] * $v;
        }
        self::assertEqualsWithDelta($si, $b['charged_kwh'], 0.01);
        self::assertEqualsWithDelta($so, $b['discharged_kwh'], 0.01);
        self::assertEqualsWithDelta($ci / $si, $b['charge_ct'], 0.001);
        self::assertEqualsWithDelta($co / $so, $b['discharge_ct'], 0.001);
        self::assertEqualsWithDelta(($co - $ci) / 100, $b['value_eur'], 0.01);
        self::assertGreaterThan($b['charge_ct'], $b['discharge_ct'], 'lädt billiger, als er entlädt');
    }

    /** § 14a Modul 3: Zeitfenster aus dem Vertrag der Datei, Quartal und Uhrzeit je Viertelstunde. */
    public function testModule3SavingFollowsFromTheContractWindows(): void
    {
        [$flex] = $this->services();
        $m3 = $flex->analyze(self::YEAR)['module3'];
        self::assertCount(1, $m3);
        $c = array_values(array_filter(self::persona()['utilities']['strom']['contracts'], fn($c) => $c['id'] === 'c_strom_waermepumpe'))[0];
        $tou = $c['grid_tou'];
        $e = self::series('e-strom-m_strom_waermepumpe-2025');
        $low = 0.0; $high = 0.0; $std = 0.0; $save = 0.0;
        foreach (self::clock() as $i => [$mo, $min]) {
            $q = (int)ceil($mo / 3);
            $ct = (float)$tou['standard_ct'];
            foreach ($tou['windows'] as $w) {
                [$fh, $fm] = array_map('intval', explode(':', $w['from']));
                [$th, $tm] = array_map('intval', explode(':', $w['to']));
                if (in_array($q, $w['quarters'], true) && $min >= $fh * 60 + $fm && $min < $th * 60 + $tm) { $ct = (float)$w['ct']; break; }
            }
            $v = (float)$e[$i];
            if ($ct < $tou['standard_ct']) $low += $v; elseif ($ct > $tou['standard_ct']) $high += $v; else $std += $v;
            $save += $v * ($tou['standard_ct'] - $ct);
        }
        $t = $m3[0]['total'];
        self::assertEqualsWithDelta($low, $t['low_kwh'], 0.01);
        self::assertEqualsWithDelta($high, $t['high_kwh'], 0.01);
        self::assertEqualsWithDelta($std, $t['standard_kwh'], 0.01);
        self::assertEqualsWithDelta($save / 100, $t['saving_eur'], 0.01);
    }

    /**
     * Eigenverbrauch = Erzeugung − Einspeisung, aus den Viertelstunden des
     * Kalenderjahres. Gegen die Ablesungen (PV-Seite) weicht er um weniger als
     * 1 % ab: Die Ablesetage liegen um den Monatsersten (±3 Tage), dazwischen
     * verteilt die App linear — das Kalenderjahr der Ablesungen beginnt und
     * endet deshalb nicht genau am 1. Januar.
     */
    public function testSelfConsumptionFollowsFromTheQuarterHours(): void
    {
        [$flex, $ins] = $this->services();
        $s = $ins->insights(self::YEAR, $flex->analyze(self::YEAR), '2026-01-15')['solar'];
        $gen = array_sum(self::series('e-pv_erzeugung-m_pv_erzeugung_wr-2025'));
        $feed = array_sum(self::series('e-pv_einspeisung-m_pv_einspeisung-2025'));
        $charge = array_sum(self::series('e-pv_erzeugung-m_pv_speicher_laden-2025'));
        self::assertEqualsWithDelta($gen, $s['generation_kwh'], 0.1);
        self::assertEqualsWithDelta($feed, $s['feed_kwh'], 0.1);
        self::assertEqualsWithDelta($gen - $feed, $s['self_kwh'], 0.1);
        self::assertEqualsWithDelta($gen - $feed - $charge, $s['direct_kwh'], 0.1);
        self::assertEqualsWithDelta(($gen - $feed) / $gen, $s['self_share'], 0.0001);
        self::assertSame(29.8, $s['working_ct'], 'Arbeitspreis des Haushaltsvertrags 2025');
        self::assertSame(8.2, $s['feed_ct'], 'EEG-Vergütung');

        $year = array_values(array_filter((new PvSummaryService($this->consumption, $this->settings))->compute()['yearly'], fn($y) => $y['year'] === self::YEAR))[0];
        self::assertSame(12, $year['months_covered']);
        self::assertEqualsWithDelta($gen, $year['erzeugung_kwh'], 0.01 * $gen, 'Erzeugung: Ablesungen gegen Viertelstunden');
        self::assertEqualsWithDelta($gen - $feed, $year['eigenverbrauch_kwh'], 0.01 * ($gen - $feed), 'Eigenverbrauch: Ablesungen gegen Viertelstunden');
        self::assertEqualsWithDelta(($gen - $feed) / $gen, $year['eigenverbrauchsquote'], 0.002);
    }

    /** Ersparnis (ct) einer Gruppe von Viertelstunden: Ladung in die günstigsten, höchstens `cap` je Viertelstunde. */
    private static function cheapest(array $slots, array $e, array $p, float $cap): float
    {
        $need = 0.0; $now = 0.0; $prices = [];
        foreach ($slots as $i) { $need += (float)$e[$i]; $now += (float)$e[$i] * $p[$i]; $prices[] = $p[$i]; }
        if ($need <= 0) return 0.0;
        sort($prices);
        $cost = 0.0;
        foreach ($prices as $pr) { $q = min($cap, $need); $cost += $q * $pr; $need -= $q; if ($need <= 1e-9) break; }
        return max(0.0, $now - $cost);
    }

    /**
     * Lastverschiebung gegen Viertelstundenpreise, eigene Herleitung für die
     * Wallbox: v3.6.0 (Paket flexplus) — die Ladung wandert nur innerhalb der
     * Steckzeiten der Ladevorgänge (Anstecken bis Abstecken, abends bis zum
     * nächsten Morgen) in die günstigsten Viertelstunden, höchstens mit der
     * größten gemessenen Ladeleistung; Börsenpreis mit der Umsatzsteuer des
     * dynamischen Angebots. Ohne Steckzeiten (ganzer Ladetag) wäre der Hebel größer.
     */
    public function testTheWallboxLeverFollowsFromTheCheapestQuarterHours(): void
    {
        [$flex, $ins] = $this->services();
        $x = $ins->insights(self::YEAR, $flex->analyze(self::YEAR), '2026-01-15');
        $l = array_values(array_filter($x['levers'], fn($y) => $y['kind'] === 'ev' && $y['signal'] === 'spot'))[0];
        $e = self::series('e-strom-m_strom_wallbox-2025');
        $p = self::series('p-de-lu-2025');
        $cap = max(array_map(fn($v) => (float)$v, $e));
        $gross = 1 + self::vatOfTheOffer() / 100;
        $windows = self::plugWindows();
        $in = [];
        foreach ($windows as [$a, $b]) for ($i = $a; $i <= $b; $i++) $in[$i] = true;
        // Die Datei lädt nur, solange das Auto angesteckt ist
        foreach ($e as $i => $v) if ((float)$v > 0) self::assertArrayHasKey($i, $in, "Ladung außerhalb eines Ladevorgangs (Viertelstunde $i)");
        $save = 0.0;
        foreach ($windows as [$a, $b]) $save += self::cheapest(range($a, $b), $e, $p, $cap);
        self::assertEqualsWithDelta($save / 100 * $gross, $l['eur'], 0.02);
        self::assertSame('sessions', $l['availability']);
        self::assertSame(count($windows), $l['sessions']);
        self::assertSame(1.0, $l['session_share']);
        self::assertFalse($l['real'], 'Börsenpreis: nur mit dynamischem Tarif');
        // Zeitfenster der Sätze nur, wenn das Auto meist angesteckt ist: im Winter nachts, mit Sonne nur im Sommer
        self::assertTrue($l['window']['winter']['plugged']);
        self::assertTrue($l['window']['winter']['to'] <= 7 || $l['window']['winter']['from'] >= 17, 'Winter: abends bis morgens angesteckt');
        $sun = array_values(array_filter($x['levers'], fn($y) => $y['kind'] === 'ev' && $y['signal'] === 'solar'))[0];
        self::assertNull($sun['window']['winter'], 'im Winter mittags nicht angesteckt');
        self::assertNotNull($sun['window']['summer']);
        // Gegenrechnung: ohne Steckzeiten (je Tag die günstigsten Viertelstunden des Tages) mehr
        $byDay = [];
        foreach (self::clock() as $i => [, , , $d]) $byDay[$d][] = $i;
        $day = 0.0;
        foreach ($byDay as $slots) $day += self::cheapest($slots, $e, $p, $cap);
        self::assertGreaterThan($save * 1.05, $day, 'die Annahme „ganzer Ladetag“ überschätzt den Hebel');
    }

    /**
     * Steckzeiten der Datei (tools/build-personas.mjs, evPlugTimes): Abends
     * angesteckt bleibt das Auto über Nacht bis 06:30–07:45 Uhr, mittags
     * angesteckt bis 16:30–18:30 Uhr; `date` ist der Tag des Absteckens wie
     * beim Import aus evcc.
     */
    public function testThePlugInTimesOfTheSampleHousehold(): void
    {
        $sessions = array_values(array_filter(self::persona()['ev_sessions'], fn($s) => str_starts_with($s['created'], '2025-')));
        self::assertGreaterThan(100, count($sessions));
        $evening = 0;
        foreach ($sessions as $s) {
            $a = new \DateTimeImmutable($s['created']);
            $b = new \DateTimeImmutable($s['finished']);
            self::assertSame($b->format('Y-m-d'), $s['date'], 'Tag des Absteckens');
            $out = (int)$b->format('G') * 60 + (int)$b->format('i');
            if ((int)$a->format('G') >= 15) {
                $evening++;
                self::assertSame($a->modify('+1 day')->format('Y-m-d'), $b->format('Y-m-d'), "{$s['id']}: über Nacht");
                self::assertTrue($out >= 6 * 60 + 30 && $out <= 7 * 60 + 45, "{$s['id']}: morgens abgesteckt");
            } else {
                self::assertSame($a->format('Y-m-d'), $b->format('Y-m-d'));
                self::assertGreaterThanOrEqual(16 * 60 + 30, $out, "{$s['id']}: am Nachmittag abgesteckt");
            }
        }
        self::assertGreaterThan(0, $evening);
        self::assertLessThan(count($sessions), $evening, 'auch mittags angesteckt (Sommerhalbjahr)');
    }

    /** Doku-Behauptung: die Tabelle der Anleitung (DE/EN, § 9) und die Sätze zum Hebel. */
    public function testTheGuideShowsTheseNumbers(): void
    {
        [$flex, $ins] = $this->services();
        $r = $flex->analyze(self::YEAR);
        $x = $ins->insights(self::YEAR, $r, '2026-01-15');
        $byId = array_column($r['meters'], 'total', 'meter_id');
        $rows = [
            'de' => ['docs/anleitungen/flexibilitaet.md', ['Wärmepumpe (steuerbar)' => 'm_strom_waermepumpe', 'Wallbox (steuerbar)' => 'm_strom_wallbox', 'Haushalt (Bezug)' => 'm_strom_haushalt'], ',', '.',
                     ['Börsenpreis,', 'Sonnenstrom,', 'Netzentgelt (Modul 3),']],
            'en' => ['docs/en/anleitungen/flexibilitaet.md', ['Heat pump (controllable)' => 'm_strom_waermepumpe', 'Wallbox (controllable)' => 'm_strom_wallbox', 'Household (grid import)' => 'm_strom_haushalt'], '.', ',',
                     ['Wholesale price,', 'Solar power,', 'Grid fee (module 3),']],
        ];
        foreach ($rows as $lang => [$file, $labels, $dec, $thou, $signals]) {
            $all = (string)file_get_contents(self::root() . "/$file");
            $at = strpos($all, "\n## 9.");
            self::assertNotFalse($at, "$lang: Abschnitt 9 (Beispielhaushalt)");
            $text = substr($all, (int)$at);
            $num = fn(string $s) => (float)str_replace(['−', $thou, $dec], ['-', '', '.'], $s);
            foreach ($labels as $label => $id) {
                self::assertSame(1, preg_match('/^' . preg_quote($label, '/') . '[^\n]*$/m', $text, $line), "$lang: Zeile $label");
                preg_match_all('/−?€?(\d[\d.,]*)/u', preg_replace('/^' . preg_quote($label, '/') . '(\s*\([^)]*\))?/', '', $line[0]), $n);
                $vals = array_map($num, $n[1]);
                $signs = preg_match_all('/−/u', $line[0]);
                $t = $byId[$id];
                self::assertEqualsWithDelta(round($t['priced_kwh']), $vals[0], 0.5, "$lang $label kWh");
                self::assertEqualsWithDelta(round($t['cheap_share'] * 100), $vals[1], 0.5, "$lang $label günstig");
                self::assertEqualsWithDelta(round($t['negative_kwh']), $vals[2], 0.5, "$lang $label negativ");
                self::assertEqualsWithDelta($t['own_ct'], $vals[3], 0.006, "$lang $label eigener Ø");
                self::assertEqualsWithDelta($t['market_ct'], $vals[4], 0.006, "$lang $label Börse Ø");
                self::assertEqualsWithDelta($t['profile_factor'], $vals[5], 0.006, "$lang $label Profilfaktor");
                self::assertEqualsWithDelta(abs($t['advantage_eur']), $vals[6], 0.006, "$lang $label Vorteil");
                self::assertSame($t['advantage_eur'] < 0 ? 1 : 0, $signs, "$lang $label Vorzeichen");
            }
            // „Dein Hebel“: jede Zeile der Tabelle in der Reihenfolge des Servers, verschobene kWh und € (ganze Zahlen)
            $levers = array_values(array_filter(explode("\n", $text), function ($l) use ($signals) {
                foreach ($signals as $s) if (str_contains($l, $s) && str_contains($l, 'kWh')) return true;
                return false;
            }));
            self::assertCount(count($x['levers']), $levers, "$lang: Zeilen „Dein Hebel“");
            foreach ($x['levers'] as $i => $l) {
                preg_match_all('/(\d[\d.,]*)/u', preg_replace('/^.*?(' . implode('|', array_map(fn($s) => preg_quote($s, '/'), $signals)) . ')[^\d]*/u', '', $levers[$i]), $n);
                self::assertGreaterThanOrEqual(2, count($n[1]), "$lang: Zahlen in „{$levers[$i]}“");
                self::assertEqualsWithDelta(round($l['shift_kwh']), $num($n[1][0]), 0.5, "$lang: verschoben „{$levers[$i]}“");
                self::assertEqualsWithDelta(round($l['eur']), $num($n[1][count($n[1]) - 1]), 0.5, "$lang: € „{$levers[$i]}“");
            }
            // Hebel: „bis zu … €“ / „up to €…“ in der Anleitung = Summe des Servers (ganze Euro)
            $real = (int)round($x['total_real_eur']); $dyn = (int)round($x['total_eur']);
            $fmt = fn(int $v) => $lang === 'de' ? number_format($v, 0, ',', '.') . ' €' : '€' . number_format($v, 0, '.', ',');
            self::assertStringContainsString($fmt($real), $text, "$lang: Hebel mit dem Tarif");
            self::assertStringContainsString($fmt($dyn), $text, "$lang: Hebel mit dynamischem Tarif");
        }
    }
}
