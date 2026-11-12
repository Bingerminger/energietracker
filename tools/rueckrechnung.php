<?php
/**
 * Rückrechnung am Prüfhaushalt Wärmepumpe (v3.13.0, Paket pruefhaushalt).
 *
 * Misst die 12-Monats-Prognose und „Erwartet bei Abrechnung“ einer Arbeitskopie der App an
 * einem Haushalt, dessen Wahrheit bekannt ist (tests/fixtures/heatpump-household/, eigene
 * Physik, siehe generate.mjs). Für jeden Schnitt (Monatsanfang) entsteht ein temporäres
 * Datenverzeichnis mit allem, was an diesem Tag bekannt war: Ablesungen bis zum Schnitt,
 * Ladevorgänge, Viertelstunden und Wetter bis zum Vortag, Zähler und Verträge, die es schon gab,
 * Preise und Abschläge, die schon galten. Dann rechnet die App mit `today` = Schnitt, zweimal:
 *
 *   vorab          mit dem Klimanormal des Haushalts (30 erfundene Jahre)
 *   nachgerechnet  Normal ersetzt durch das Ist-Wetter des Prognosefensters
 *                  (trennt den Modellfehler vom Wetterglück)
 *
 * Verglichen wird mit der Wahrheit je Baustein (nicht mit Ablesungen), dazu eine naive Prognose
 * (Vorjahresmonate aus den Monatswerten der App). Fehlermaße: Jahresfehler in %, WAPE der Monate,
 * größter Monatsfehler, Treffer des Bands 80 %, beim Saldo Fehler in € und Vorzeichen.
 * Mit `--baseline` (JSON eines früheren Laufs, etwa der Grundlinie) prüft es die Schwellen aus
 * schwellen.json, die gegen „alt“ gestellt sind.
 *
 *   php tools/rueckrechnung.php [--root <app>] [--household A|B] [--variant V1|V2|V3] [--runs A/V1,B/V1]
 *       [--block heat_pump,pv,feed_in,battery,wallbox,htnt,saldo] [--mode vorab|nachgerechnet|both]
 *       [--cut 2024-04] [--json <datei>] [--md <datei>] [--baseline <datei>] [--quiet]
 *
 * Standard: beide Haushalte, alle Varianten, alle Bausteine, beide Modi; Markdown auf stdout.
 * `--root` ist die Arbeitskopie, deren Code rechnet (Standard: das Repo dieses Werkzeugs). Die
 * Daten liegen nur im temporären Verzeichnis (nie in data/) und werden danach gelöscht.
 */
declare(strict_types=1);

const BLOCKS = ['heat_pump', 'pv', 'feed_in', 'battery', 'wallbox', 'htnt', 'saldo'];

// ── Aufruf ───────────────────────────────────────────────────────────────
$args = ['root' => dirname(__DIR__), 'fixture' => null, 'household' => null, 'variant' => null, 'runs' => null, 'block' => null,
         'mode' => 'both', 'cut' => null, 'json' => null, 'md' => null, 'baseline' => null, 'quiet' => false];
for ($i = 1; $i < count($argv); $i++) {
    $a = $argv[$i];
    if ($a === '--quiet') { $args['quiet'] = true; continue; }
    if ($a === '--help' || $a === '-h') { fwrite(STDOUT, preg_replace('/^ \* ?/m', '', explode('*/', explode('/**', file_get_contents(__FILE__), 2)[1], 2)[0])); exit(0); }
    if (!str_starts_with($a, '--') || !array_key_exists(substr($a, 2), $args) || !isset($argv[$i + 1])) { fwrite(STDERR, "Unbekannt: $a (--help)\n"); exit(2); }
    $args[substr($a, 2)] = $argv[++$i];
}
$root = realpath((string)$args['root']) ?: (string)$args['root'];
$fixture = realpath((string)($args['fixture'] ?? (__DIR__ . '/../tests/fixtures/heatpump-household'))) ?: '';
if (!is_file("$root/src/bootstrap.php")) { fwrite(STDERR, "Keine App unter $root\n"); exit(2); }
if (!is_file("$fixture/schwellen.json")) { fwrite(STDERR, "Kein Prüfhaushalt unter $fixture\n"); exit(2); }
$households = $args['household'] !== null ? [strtoupper((string)$args['household'])] : ['A', 'B'];
$variants = $args['variant'] !== null ? [strtoupper((string)$args['variant'])] : ['V1', 'V2', 'V3'];
// --runs A/V1,A/V2,B/V1: genau diese Paare (etwa für die Grundlinie)
$pairs = null;
if ($args['runs'] !== null) {
    $pairs = [];
    foreach (explode(',', strtoupper((string)$args['runs'])) as $p) {
        if (!preg_match('#^([AB])/(V[123])$#', trim($p), $mm)) { fwrite(STDERR, "--runs etwa A/V1,B/V1\n"); exit(2); }
        $pairs[$mm[1]][] = $mm[2];
    }
    $households = array_keys($pairs);
}
$blocks = $args['block'] !== null ? array_values(array_intersect(BLOCKS, explode(',', (string)$args['block']))) : BLOCKS;
$modes = $args['mode'] === 'both' ? ['vorab', 'nachgerechnet'] : [(string)$args['mode']];
foreach ($households as $h) if (!in_array($h, ['A', 'B'], true)) { fwrite(STDERR, "Haushalt A oder B\n"); exit(2); }
foreach ($variants as $v) if (!in_array($v, ['V1', 'V2', 'V3'], true)) { fwrite(STDERR, "Variante V1, V2 oder V3\n"); exit(2); }
foreach ($modes as $m) if (!in_array($m, ['vorab', 'nachgerechnet'], true)) { fwrite(STDERR, "Modus vorab, nachgerechnet oder both\n"); exit(2); }
if ($blocks === []) { fwrite(STDERR, 'Baustein: ' . implode(', ', BLOCKS) . "\n"); exit(2); }

require_once "$root/src/bootstrap.php";
date_default_timezone_set('Europe/Berlin');
$log = fn(string $s) => $args['quiet'] ? null : fwrite(STDERR, $s . "\n");

$readJson = function (string $file): array {
    $d = json_decode((string)file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
    return is_array($d) ? $d : [];
};
$schwellen = $readJson("$fixture/schwellen.json");
$normal = $readJson("$fixture/climate_normal.json");
$actual = $readJson("$fixture/actual_normals.json")['years'] ?? [];
$baseline = $args['baseline'] !== null ? $readJson((string)$args['baseline']) : null;

// ── Kalender ─────────────────────────────────────────────────────────────
function ymAdd(string $ym, int $n): string { return date('Y-m', strtotime("$ym-01 " . ($n >= 0 ? "+$n" : "$n") . ' months')); }
function dayAdd(string $d, int $n): string { return date('Y-m-d', strtotime("$d " . ($n >= 0 ? "+$n" : "$n") . ' days')); }
function ymRange(string $from, int $n): array { $o = []; for ($i = 0; $i < $n; $i++) $o[] = ymAdd($from, $i); return $o; }

// ── Wahrheit je Monat ────────────────────────────────────────────────────
function truthMonthly(array $truth): array
{
    $from = $truth['daily']['from'];
    $out = [];
    foreach ($truth['daily']['series'] as $name => $vals) {
        $t = strtotime("$from 12:00:00");
        foreach ($vals as $i => $v) {
            $ym = date('Y-m', $t + $i * 86400);
            $out[$name][$ym] = ($out[$name][$ym] ?? 0.0) + (float)$v;
        }
    }
    return $out;
}
function seriesSum(array $tm, array $names, string $ym): ?float
{
    $s = 0.0;
    foreach ($names as $n) { if (!isset($tm[$n][$ym])) return null; $s += $tm[$n][$ym]; }
    return $s;
}

// ── Datenverzeichnis am Schnitt ──────────────────────────────────────────
/** Ein Normal, dessen Monate das Ist-Wetter des Fensters tragen (Streuung aus dem echten Normal). */
function windowNormal(array $normal, array $actual, string $fromYm): array
{
    $n = $normal;
    $n['period'] = ['from' => "$fromYm-01", 'to' => date('Y-m-t', strtotime("$fromYm-01 +11 months"))];
    $n['fetched_at'] = "$fromYm-01T00:00:00+01:00";
    foreach (ymRange($fromYm, 12) as $ym) {
        $y = substr($ym, 0, 4); $m = (int)substr($ym, 5, 2);
        $src = $actual[$y] ?? null;
        if ($src === null) continue;
        foreach ($n['hdd'] as $base => $row) {
            $v = $src['hdd'][$base]['mean'][$m - 1] ?? null;
            if ($v !== null) $n['hdd'][$base]['mean'][$m - 1] = $v;
        }
        if (isset($src['rad_month'][$m - 1], $n['rad_month'])) $n['rad_month'][$m - 1] = $src['rad_month'][$m - 1];
        // Tagesmittel des Fensters für jeden Kalendertag dieses Monats
        $first = (int)date('z', strtotime("$ym-01")) + 1;
        $leap = (bool)date('L', strtotime("$ym-01"));
        $dim = (int)date('t', strtotime("$ym-01"));
        for ($d = 0; $d < $dim; $d++) {
            $doy = $first + $d;
            if ($doy >= 60 && !$leap) $doy++;
            $v = $src['doy_avg'][$doy - 1] ?? null;
            if ($v !== null) $n['doy_avg'][$doy - 1] = $v;
        }
    }
    return $n;
}

/**
 * Was am Schnitt (Tag, morgens) bekannt war, in ein neues Datenverzeichnis.
 *
 * @return array{last_reading:?string,last_temperature:?string,last_session:?string} zur Kontrolle
 */
function writeDataDir(string $dir, array $backup, string $cut, array $normal): array
{
    $seen = ['last_reading' => null, 'last_temperature' => null, 'last_session' => null];
    $store = new \Energietracker\Storage\JsonStore($dir);
    $store->write('settings.json', $backup['settings']);
    $temps = array_filter($backup['temperatures'], fn($k) => $k < $cut, ARRAY_FILTER_USE_KEY);
    $store->write('temperatures.json', $temps);
    $store->write('climate_normal.json', $normal);
    $sessions = array_values(array_filter($backup['ev_sessions'] ?? [], fn($s) => (string)$s['date'] < $cut));
    $store->write('ev_sessions.json', $sessions);
    $seen['last_temperature'] = $temps !== [] ? max(array_keys($temps)) : null;
    $seen['last_session'] = $sessions !== [] ? max(array_column($sessions, 'date')) : null;
    // Viertelstunden bis zum Vortag (Plätze ab dem Schnitt leer)
    $cutTs = (new DateTimeImmutable("$cut 00:00:00", new DateTimeZone('Europe/Berlin')))->getTimestamp();
    $series = [];
    foreach ((array)($backup['intervals']['series'] ?? []) as $s) {
        $file = $backup['interval_files'][$s['id']] ?? null;
        if ($file === null) continue;
        $start = (new DateTimeImmutable((string)$file['start']))->getTimestamp();
        if ($start >= $cutTs) continue;
        $vals = $file['values'];
        $keep = max(0, min(count($vals), intdiv($cutTs - $start, 900)));
        for ($i = $keep; $i < count($vals); $i++) $vals[$i] = null;
        $filled = count(array_filter($vals, fn($v) => $v !== null));
        if ($filled === 0) continue;
        $store->write("intervals/{$s['id']}.json", ['values' => $vals] + $file);
        $s['filled'] = $filled;
        if ($keep < count($vals)) $s['to'] = date('c', $start + $keep * 900);
        $series[] = $s;
    }
    $store->write('intervals.json', ['series' => $series, 'samples' => (object)[]]);
    $known = fn(array $list) => array_values(array_filter($list, fn($e) => (string)($e['from'] ?? '') <= $cut));
    foreach ($backup['utilities'] as $u => $pots) {
        $meters = array_values(array_filter($pots['meters'], fn($m) => (string)($m['devices'][0]['installed_on'] ?? '') <= $cut));
        $ids = array_flip(array_column($meters, 'id'));
        $groups = array_values(array_filter($pots['meter_groups'] ?? [], function ($g) use ($meters) {
            foreach ($meters as $m) if (($m['meter_group_id'] ?? null) === $g['id']) return true;
            return false;
        }));
        $contracts = [];
        foreach ($pots['contracts'] as $c) {
            if ((string)$c['start'] > $cut) continue;
            foreach (['working_prices', 'base_prices', 'advance_payments'] as $f) $c[$f] = $known($c[$f] ?? []);
            if (isset($c['working_prices_by_meter'])) foreach ($c['working_prices_by_meter'] as $mid => $l) $c['working_prices_by_meter'][$mid] = $known($l);
            $contracts[] = $c;
        }
        $store->write("$u/meters.json", $meters);
        $readings = array_values(array_filter($pots['readings'], fn($r) => isset($ids[$r['meter_id']]) && (string)$r['date'] <= $cut));
        $store->write("$u/readings.json", $readings);
        foreach ($readings as $r) if ($seen['last_reading'] === null || (string)$r['date'] > $seen['last_reading']) $seen['last_reading'] = (string)$r['date'];
        $store->write("$u/contracts.json", $contracts);
        $store->write("$u/meter_groups.json", $groups);
        foreach (['deliveries', 'periods', 'bills'] as $p) $store->write("$u/$p.json", $pots[$p] ?? []);
    }
    (new \Energietracker\Storage\Migrator($store))->initFresh();
    return $seen;
}

function rrmdir(string $dir): void
{
    if (!is_dir($dir)) return;
    foreach (scandir($dir) ?: [] as $f) {
        if ($f === '.' || $f === '..') continue;
        is_dir("$dir/$f") ? rrmdir("$dir/$f") : @unlink("$dir/$f");
    }
    @rmdir($dir);
}

/** Die App auf einem Datenverzeichnis, ohne ihre HTTP-Fehlerbehandlung. */
function appOn(string $dir): \Energietracker\App
{
    $level = ob_get_level();
    $app = new \Energietracker\App($dir);
    while (ob_get_level() > $level) ob_end_flush();
    restore_error_handler();
    restore_exception_handler();
    return $app;
}

$tmpBase = sys_get_temp_dir() . '/et-rueckrechnung-' . bin2hex(random_bytes(4));
mkdir($tmpBase, 0755, true);
$tmpBase = realpath($tmpBase) ?: $tmpBase;
register_shutdown_function(fn() => rrmdir($tmpBase));
set_error_handler(function (int $sev, string $msg, string $file, int $line): bool {
    if (in_array($sev, [E_DEPRECATED, E_USER_DEPRECATED, E_NOTICE, E_USER_NOTICE], true)) return true;
    throw new ErrorException($msg, 0, $sev, $file, $line);
});

/** Eine Prognose als Monatszeilen ab dem Monat des Schnitts. */
function forecastAt(\Energietracker\App $app, string $u, string $id, string $cut): array
{
    $target = $app->meters->target($u, $id);
    if ($target === null) return ['valid' => false, 'reason' => 'no_target'];
    $cutYm = substr($cut, 0, 7);
    $f = $app->forecasts->forMeter($u, $target, ['today' => $cut]);
    if (empty($f['valid'])) return ['valid' => false, 'reason' => (string)($f['reason'] ?? 'invalid')];
    $first = (string)($f['forecast'][0]['ym'] ?? $cutYm);
    $lag = 0;
    if ($first < $cutYm) {   // Prognose beginnt vor dem Schnitt (letzte Ablesung lange her): mehr Monate holen
        $lag = (int)round((strtotime("$cutYm-01") - strtotime("$first-01")) / (30.44 * 86400));
        $f = $app->forecasts->forMeter($u, $target, ['today' => $cut, 'forecast_months' => min(60, 12 + $lag)]);
    }
    $vf = isset($f['forecast'][0]['kwh']) ? 'kwh' : 'm3';
    $rows = [];
    foreach ($f['forecast'] as $r) {
        $rows[(string)$r['ym']] = ['v' => (float)$r[$vf], 'billed' => isset($r['billed_kwh']) ? (float)$r['billed_kwh'] : null,
            'low' => $r['band_low'] ?? null, 'high' => $r['band_high'] ?? null, 'cost' => (float)($r['cost_estimated'] ?? 0), 'method' => (string)($r['method'] ?? '')];
    }
    $hist = [];
    foreach ($f['historical'] as $h) $hist[(string)$h['ym']] = (float)($h[$vf] ?? 0);
    // Monate des Fensters, die die App schon zur Geschichte zählt (Teilmonat des Schnitts)
    foreach (ymRange($cutYm, 12) as $ym) if (!isset($rows[$ym]) && isset($hist[$ym]) && $ym < $first) $rows[$ym] = ['v' => $hist[$ym], 'billed' => null, 'low' => null, 'high' => null, 'cost' => 0.0, 'method' => 'history'];
    $annualWindow = $first === $cutYm && is_array($f['annual'] ?? null) ? $f['annual'] : null;
    return ['valid' => true, 'first_ym' => $first, 'lag' => $lag, 'rows' => $rows, 'hist' => $hist, 'annual' => $annualWindow,
            'warnings' => array_values(array_map(fn($w) => is_array($w) ? (string)($w['code'] ?? json_encode($w)) : (string)$w, (array)($f['warnings'] ?? []))),
            'heat_model' => $f['heat_model'] ?? null, 'blend_weight' => $f['blend_weight'] ?? null];
}

// ── Fehlermaße ───────────────────────────────────────────────────────────
/**
 * @param array<string,?float> $fc   Prognose je Monat
 * @param array<string,?float> $tr   Wahrheit je Monat
 */
function metrics(array $fc, array $tr, ?array $lo = null, ?array $hi = null, ?array $annual = null): ?array
{
    $sf = 0.0; $st = 0.0; $abs = 0.0; $maxSig = null; $maxAll = null; $hits = 0; $bands = 0;
    foreach ($tr as $ym => $t) { if ($t === null || ($fc[$ym] ?? null) === null) return null; }
    foreach ($tr as $ym => $t) $st += $t;
    foreach ($tr as $ym => $t) {
        $f = (float)$fc[$ym];
        $sf += $f; $abs += abs($f - $t);
        if ($t > 0) {
            $e = abs($f - $t) / $t * 100;
            $maxAll = max($maxAll ?? 0, $e);
            if ($t >= 0.03 * $st) $maxSig = max($maxSig ?? 0, $e);
        }
        if ($lo !== null && ($lo[$ym] ?? null) !== null && ($hi[$ym] ?? null) !== null) {
            $bands++;
            if ($t >= (float)$lo[$ym] - 1e-9 && $t <= (float)$hi[$ym] + 1e-9) $hits++;
        }
    }
    if ($st <= 0) return null;
    $yearHit = ($annual !== null && ($annual['low'] ?? null) !== null && ($annual['high'] ?? null) !== null)
        ? ($st >= (float)$annual['low'] && $st <= (float)$annual['high']) : null;
    return ['forecast_kwh' => round($sf, 1), 'truth_kwh' => round($st, 1), 'annual_err_pct' => round(($sf - $st) / $st * 100, 2),
            'wape_pct' => round($abs / $st * 100, 2), 'max_month_err_pct' => $maxSig !== null ? round($maxSig, 1) : null,
            'max_month_err_all_pct' => $maxAll !== null ? round($maxAll, 1) : null, 'band_months' => $bands, 'band_hits' => $hits, 'band_year_hit' => $yearHit];
}

// ── Bausteine je Haushalt ────────────────────────────────────────────────
/**
 * Was je Baustein verglichen wird: Ziele der Prognose (Zähler oder Gruppe), wie daraus der Wert je
 * Monat entsteht, und welche Reihen der Wahrheit dazugehören.
 */
function blockDefs(string $hh, array $layout): array
{
    if ($hh === 'B') {
        return ['heat_pump' => ['targets' => [['strom', $layout['strom']['main']]], 'truth' => ['imp_kwh'], 'value' => 'single',
                'note' => 'Wärmepumpe am Haushaltszähler: verglichen wird der Zähler (Wärmepumpe und Haushalt)']];
    }
    $s = $layout['strom']; $pe = $layout['pv_einspeisung']; $pg = $layout['pv_erzeugung'];
    return [
        'heat_pump' => ['targets' => [['strom', $s['z1_group']], ['strom', $s['z2_group']]], 'truth' => ['rest_ht', 'rest_nt'], 'value' => 'rest',
                        'note' => 'Kaskade: Wärmepumpe = Rest Z1 − Z2 (Netzbezug der Wärmepumpe), Wert aus `billed_kwh` der Gruppe Z1, sonst Z1 − Z2'],
        'pv' => ['targets' => [['pv_erzeugung', $pg['gen']]], 'truth' => ['pv_kwh'], 'value' => 'single'],
        'feed_in' => ['targets' => [['pv_einspeisung', $pe['feed_z1']]], 'truth' => ['z1_exp'], 'value' => 'single'],
        'battery_import' => ['targets' => [['strom', $s['z1_group']]], 'truth' => ['z1_imp_ht', 'z1_imp_nt'], 'value' => 'single'],
        'wallbox_parent' => ['targets' => [['strom', $s['z2_group']]], 'truth' => ['z2_imp_ht', 'z2_imp_nt'], 'value' => 'single'],
        'wallbox_meter' => ['targets' => [['strom', $s['wallbox']]], 'truth' => ['ev_kwh'], 'value' => 'single'],
        'battery_charge' => ['targets' => [['pv_erzeugung', $pg['bat_in']]], 'truth' => ['bat_charge_kwh'], 'value' => 'single'],
        'battery_discharge' => ['targets' => [['pv_erzeugung', $pg['bat_out']]], 'truth' => ['bat_discharge_kwh'], 'value' => 'single'],
    ];
}
/** Welche Prognosen ein Baustein braucht. */
function wanted(array $blocks, string $hh): array
{
    $w = [];
    if (in_array('heat_pump', $blocks, true)) $w[] = 'heat_pump';
    if ($hh === 'A') {
        if (in_array('pv', $blocks, true)) $w[] = 'pv';
        if (in_array('feed_in', $blocks, true) || in_array('battery', $blocks, true)) $w[] = 'feed_in';
        if (in_array('battery', $blocks, true)) array_push($w, 'battery_import', 'battery_charge', 'battery_discharge');
        if (in_array('wallbox', $blocks, true)) array_push($w, 'wallbox_parent', 'wallbox_meter');
    }
    return array_values(array_unique($w));
}

/** Monatswerte eines Bausteins aus den Prognosen der Ziele. */
function blockSeries(array $def, array $fcs, array $months): ?array
{
    $f0 = $fcs[$def['targets'][0][1]] ?? null;
    if ($f0 === null || !$f0['valid']) return null;
    $fc = []; $lo = []; $hi = []; $naive = []; $methods = []; $dedSum = 0.0;
    $f1 = isset($def['targets'][1]) ? ($fcs[$def['targets'][1][1]] ?? null) : null;
    $billed = $def['value'] === 'rest' && array_filter($f0['rows'], fn($r) => $r['billed'] !== null) !== [];
    foreach ($months as $ym) {
        $r = $f0['rows'][$ym] ?? null;
        if ($r === null) return null;
        $methods[$r['method']] = ($methods[$r['method']] ?? 0) + 1;
        $prev = ymAdd($ym, -12);
        if ($def['value'] === 'rest') {
            if ($billed) {
                $ded = $r['billed'] !== null ? $r['v'] - $r['billed'] : 0.0;
                $fc[$ym] = $r['billed'] ?? $r['v'];
            } else {
                $r1 = $f1['valid'] ?? false ? ($f1['rows'][$ym] ?? null) : null;
                if ($r1 === null) return null;
                $ded = $r1['v'];
                $fc[$ym] = $r['v'] - $r1['v'];
            }
            $dedSum += $ded;
            $lo[$ym] = $r['low'] !== null ? (float)$r['low'] - $ded : null;
            $hi[$ym] = $r['high'] !== null ? (float)$r['high'] - $ded : null;
            $h0 = $f0['hist'][$prev] ?? null; $h1 = ($f1['valid'] ?? false) ? ($f1['hist'][$prev] ?? null) : null;
            $naive[$ym] = $h0 !== null && $h1 !== null ? $h0 - $h1 : null;
        } else {
            $fc[$ym] = $r['v'];
            $lo[$ym] = $r['low']; $hi[$ym] = $r['high'];
            $naive[$ym] = $f0['hist'][$prev] ?? null;
        }
    }
    // Jahresband: beim Rest das Band der Gruppe Z1 um den Abzug verschoben (wie die Monatsbänder)
    $annual = $f0['annual'];
    if ($def['value'] === 'rest' && $annual !== null) {
        $annual = ['value' => isset($annual['value']) ? (float)$annual['value'] - $dedSum : null,
                   'low' => ($annual['low'] ?? null) !== null ? (float)$annual['low'] - $dedSum : null,
                   'high' => ($annual['high'] ?? null) !== null ? (float)$annual['high'] - $dedSum : null] + $annual;
    }
    return ['fc' => $fc, 'lo' => $lo, 'hi' => $hi, 'naive' => $naive, 'annual' => $annual, 'methods' => $methods, 'source' => $billed ? 'billed_kwh' : ($def['value'] === 'rest' ? 'z1_minus_z2' : 'kwh'),
            'first_ym' => $f0['first_ym'], 'warnings' => $f0['warnings'], 'heat_model' => $f0['heat_model']];
}

// ── Schnitte und Teilmengen ──────────────────────────────────────────────
function rollingCuts(string $hh): array
{
    $from = $hh === 'A' ? '2023-07' : '2024-01';
    $out = [];
    for ($ym = $from; $ym <= '2025-06'; $ym = ymAdd($ym, 1)) $out[] = "$ym-01";
    return $out;
}
function cutSets(array $layout): array
{
    $range = function (string $a, string $b) { $o = []; for ($ym = $a; $ym <= $b; $ym = ymAdd($ym, 1)) $o[] = "$ym-01"; return $o; };
    $covers = function (string $from, string $to) { $o = []; for ($ym = '2023-07'; $ym <= '2025-06'; $ym = ymAdd($ym, 1)) if ($ym <= $from && ymAdd($ym, 11) >= $to) $o[] = "$ym-01"; return $o; };
    $sets = ['after_mild' => $range('2024-04', '2024-09'), 'after_cold' => $range('2025-04', '2025-06'),
             'mild_year' => $covers('2023-11', '2024-03'), 'cold_year' => $covers('2024-11', '2025-03')];
    if (isset($layout['battery_from'])) { $b = substr($layout['battery_from'], 0, 7); $sets['battery_year'] = $range(ymAdd($b, 3), ymAdd($b, 12)); }
    if (isset($layout['wallbox_from'])) { $w = substr($layout['wallbox_from'], 0, 7); $sets['wallbox_year'] = $range(ymAdd($w, 4), min('2025-06', ymAdd($w, 12))); }
    return $sets;
}

// ── Lauf ─────────────────────────────────────────────────────────────────
$t0 = microtime(true);
$result = ['meta' => [
    'tool' => 'tools/rueckrechnung.php', 'root' => $root, 'fixture' => 'tests/fixtures/heatpump-household',
    'commit' => trim((string)@shell_exec('git -C ' . escapeshellarg($root) . ' rev-parse --short HEAD 2>/dev/null')),
    'modes' => $modes, 'blocks' => $blocks,
    'notes' => [
        'Schnitt = Monatsanfang, today = Schnitt; Ablesungen mit Datum ≤ Schnitt, Wetter, Ladevorgänge und Viertelstunden bis zum Vortag',
        'Fenster = die zwölf Kalendermonate ab dem Monat des Schnitts; Wahrheit in MEZ-Tagen',
        'größter Monatsfehler über Monate mit mindestens 3 % der Jahreswahrheit (max_month_err_all_pct: alle Monate)',
        'naiv = Monatswert der App zwölf Monate vorher',
    ]], 'runs' => []];

$runSeconds = [];
foreach ($households as $hh) {
    $truth = $readJson("$fixture/" . strtolower($hh) . '-truth.json');
    $tm = truthMonthly($truth);
    $layout = $truth['layout'];
    $defs = blockDefs($hh, $layout);
    $want = wanted($blocks, $hh);
    $sets = cutSets($layout);
    foreach ($pairs[$hh] ?? $variants as $variant) {
        $tv = microtime(true);
        $backup = $readJson("$fixture/" . strtolower($hh) . '-' . strtolower($variant) . '.json');
        $run = ['household' => $hh, 'variant' => $variant, 'name' => $truth['name'], 'cut_sets' => $sets, 'cuts' => [], 'saldo' => [], 'htnt' => []];
        $cuts = rollingCuts($hh);
        if ($args['cut'] !== null) $cuts = array_values(array_filter($cuts, fn($c) => substr($c, 0, 7) === substr((string)$args['cut'], 0, 7)));
        $needForecast = $want !== [] || in_array('htnt', $blocks, true);
        foreach ($needForecast ? $cuts : [] as $cut) {
            $cutYm = substr($cut, 0, 7);
            $months = ymRange($cutYm, 12);
            $entry = ['cut' => $cut, 'modes' => []];
            foreach ($modes as $mode) {
                $dir = "$tmpBase/" . strtolower($hh . $variant) . '-' . $cutYm . '-' . $mode;
                mkdir($dir, 0755, true);
                $useNormal = $mode === 'vorab' ? $normal : windowNormal($normal, $actual, $cutYm);
                $seen = writeDataDir($dir, $backup, $cut, $useNormal);
                $app = appOn($dir);
                // alle Ziele einmal rechnen
                $targets = [];
                foreach ($want as $b) foreach ($defs[$b]['targets'] as [$u, $id]) $targets["$u|$id"] = [$u, $id];
                if ($hh === 'A' && in_array('htnt', $blocks, true)) {
                    foreach (['z1_group', 'z1_ht', 'z1_nt', 'z2_group', 'z2_ht', 'z2_nt'] as $k) $targets['strom|' . $layout['strom'][$k]] = ['strom', $layout['strom'][$k]];
                }
                $fcs = [];
                foreach ($targets as [$u, $id]) {
                    try { $fcs[$id] = forecastAt($app, $u, $id, $cut); }
                    catch (\Throwable $e) { $fcs[$id] = ['valid' => false, 'reason' => 'error: ' . $e->getMessage()]; }
                }
                // zur Kontrolle: Heizgradtage (Grenze 15 °C) des Normals, mit dem die App rechnet, über das Fenster
                $res = ['normal_hdd15_window' => round(array_sum(array_map(fn($ym) => (float)($useNormal['hdd']['15.0']['mean'][(int)substr($ym, 5, 2) - 1] ?? 0), $months)), 1),
                        'data' => $seen, 'blocks' => []];
                foreach ($want as $b) {
                    $def = $defs[$b];
                    $tr = []; foreach ($months as $ym) $tr[$ym] = seriesSum($tm, $def['truth'], $ym);
                    $s = blockSeries($def, $fcs, $months);
                    if ($s === null) { $res['blocks'][$b] = ['valid' => false, 'reason' => $fcs[$def['targets'][0][1]]['reason'] ?? 'missing_months']; continue; }
                    $m = metrics($s['fc'], $tr, $s['lo'], $s['hi'], $s['annual']);
                    $nm = metrics($s['naive'], $tr);
                    $row = ['valid' => $m !== null, 'source' => $s['source'], 'first_ym' => $s['first_ym'], 'methods' => $s['methods'],
                            'metrics' => $m, 'naive' => $nm,
                            'months' => array_map(fn($ym) => ['ym' => $ym, 'fc' => round((float)$s['fc'][$ym], 1), 'truth' => $tr[$ym] !== null ? round($tr[$ym], 1) : null,
                                'low' => $s['lo'][$ym] !== null ? round((float)$s['lo'][$ym], 1) : null, 'high' => $s['hi'][$ym] !== null ? round((float)$s['hi'][$ym], 1) : null,
                                'naive' => $s['naive'][$ym] !== null ? round((float)$s['naive'][$ym], 1) : null], $months),
                            'warnings' => $s['warnings'], 'heat_model' => $s['heat_model']];
                    // Wallbox: Wintermonate der Wallbox (November bis März)
                    if ($b === 'wallbox_meter' && $m !== null) {
                        $winter = array_filter($months, fn($ym) => in_array((int)substr($ym, 5, 2), [11, 12, 1, 2, 3], true));
                        $abs = 0.0; $sum = 0.0;
                        foreach ($winter as $ym) { $abs += abs((float)$s['fc'][$ym] - (float)$tr[$ym]); $sum += (float)$tr[$ym]; }
                        $row['metrics']['winter_wape_pct'] = $sum > 0 ? round($abs / $sum * 100, 2) : null;
                    }
                    $res['blocks'][$b] = $row;
                }
                // HT/NT: Gruppe gegen Zählwerke (Energie, Kosten, Grundpreis)
                if ($hh === 'A' && in_array('htnt', $blocks, true)) {
                    foreach (['z1' => 'c_strom_wp', 'z2' => 'c_strom_haus'] as $g => $cid) {
                        $fg = $fcs[$layout['strom']["{$g}_group"]] ?? null; $fh = $fcs[$layout['strom']["{$g}_ht"]] ?? null; $fn = $fcs[$layout['strom']["{$g}_nt"]] ?? null;
                        if (!($fg['valid'] ?? false) || !($fh['valid'] ?? false) || !($fn['valid'] ?? false)) { $res['htnt'][$g] = ['valid' => false]; continue; }
                        $sum = fn(array $f, string $k) => array_sum(array_map(fn($ym) => (float)($f['rows'][$ym][$k] ?? 0), $months));
                        // je Monat (die App rundet jede Zeile auf 0,1 kWh; die Jahressumme trägt 36 Rundungen)
                        $gapMonth = max(array_map(fn($ym) => abs((float)($fg['rows'][$ym]['v'] ?? 0) - (float)($fh['rows'][$ym]['v'] ?? 0) - (float)($fn['rows'][$ym]['v'] ?? 0)), $months));
                        $base = 0.0;
                        foreach ($backup['utilities']['strom']['contracts'] as $c) if ($c['id'] === $cid) $base = 12 * (float)$c['base_prices'][0]['eur_per_month'];
                        $res['htnt'][$g] = ['valid' => true, 'group_kwh' => round($sum($fg, 'v'), 1), 'members_kwh' => round($sum($fh, 'v') + $sum($fn, 'v'), 1),
                            'energy_gap_kwh' => round($sum($fg, 'v') - $sum($fh, 'v') - $sum($fn, 'v'), 2), 'energy_gap_month_max_kwh' => round($gapMonth, 2),
                            'group_cost_eur' => round($sum($fg, 'cost'), 2), 'members_cost_eur' => round($sum($fh, 'cost') + $sum($fn, 'cost'), 2),
                            'base_year_eur' => $base, 'base_gap_eur' => round($sum($fg, 'cost') - $sum($fh, 'cost') - $sum($fn, 'cost') - $base, 2)];
                    }
                    if ($mode === 'vorab') {
                        try {
                            $rep = $app->reportModel->build((int)substr(dayAdd($cut, -1), 0, 4), ['utilities' => ['strom'], 'today' => $cut]);
                            $cost = null;
                            $find = function ($n) use (&$find, &$cost) { if (!is_array($n)) return; if (isset($n['as_of_today'], $n['cost'], $n['text']) && $n['as_of_today'] === true) { $cost = (float)$n['cost']; return; } foreach ($n as $v) $find($v); };
                            $find($rep);
                            $groups = ($res['htnt']['z1']['group_cost_eur'] ?? 0) + ($res['htnt']['z2']['group_cost_eur'] ?? 0);
                            $res['htnt']['report'] = ['report_cost_eur' => $cost, 'groups_cost_eur' => round($groups, 2), 'report_gap_eur' => $cost !== null ? round($cost - $groups, 2) : null];
                        } catch (\Throwable $e) { $res['htnt']['report'] = ['error' => $e->getMessage()]; }
                    }
                }
                $entry['modes'][$mode] = $res;
                unset($app);
                rrmdir($dir);
            }
            $run['cuts'][] = $entry;
            $log(sprintf('%s/%s %s  %.0f s', $hh, $variant, $cut, microtime(true) - $tv));
        }

        // ── Saldo nach 3, 6, 9 Monaten des Abrechnungsjahrs ──
        if (in_array('saldo', $blocks, true)) {
            $contractTarget = $hh === 'A'
                ? ['wp' => ['strom', $layout['strom']['z1_group']], 'house' => ['strom', $layout['strom']['z2_group']], 'feed' => ['pv_einspeisung', $layout['pv_einspeisung']['feed_z1']]]
                : ['main' => ['strom', $layout['strom']['main']]];
            $cases = [];
            foreach ($truth['bills'] as $key => $list) foreach ($list as $bill) {
                if ($bill['months'] < 6) continue;
                foreach ([3, 6, 9] as $k) {
                    $cut = ymAdd(substr($bill['from'], 0, 7), $k) . '-01';
                    if ($cut > $bill['until']) continue;
                    if ($args['cut'] !== null && substr($cut, 0, 7) !== substr((string)$args['cut'], 0, 7)) continue;
                    $cases[$cut][] = ['key' => $key, 'bill' => $bill, 'k' => $k];
                }
            }
            ksort($cases);
            foreach ($cases as $cut => $list) {
                foreach ($modes as $mode) {
                    $dir = "$tmpBase/" . strtolower($hh . $variant) . '-saldo-' . $cut . '-' . $mode;
                    mkdir($dir, 0755, true);
                    writeDataDir($dir, $backup, $cut, $mode === 'vorab' ? $normal : windowNormal($normal, $actual, substr($cut, 0, 7)));
                    $app = appOn($dir);
                    foreach ($list as $case) {
                        [$u, $tid] = $contractTarget[$case['key']];
                        $bill = $case['bill'];
                        $row = ['contract' => $bill['contract_id'], 'key' => $case['key'], 'mode' => $mode, 'cut' => $cut, 'after_months' => $case['k'],
                                'by_from' => $bill['from'], 'by_until' => $bill['until'], 'truth_eur' => $bill['result_eur'], 'cost_eur' => $bill['cost_eur'],
                                'kind' => $case['key'] === 'feed' ? 'feed_in' : 'consumption'];
                        try {
                            $target = $app->meters->target($u, $tid);
                            $st = $target !== null ? (array)($app->consumption->contractStatus($u, $target, $cut)['contracts'] ?? []) : [];
                            $c = null;
                            foreach ($st as $r) if (($r['contract_id'] ?? null) === $bill['contract_id']) $c = $r;
                            $p = $c['projected_end_balance'] ?? null;
                            $row += ['projected_eur' => $p !== null ? round((float)$p, 2) : null, 'method' => $c['projection_method'] ?? null,
                                     'period' => $c['billing_period'] ?? null, 'settle_on' => $c['settle_on'] ?? null];
                            if ($p !== null) {
                                $row['err_eur'] = round((float)$p - $bill['result_eur'], 2);
                                $row['abs_err_eur'] = abs($row['err_eur']);
                                $row['significant'] = abs($bill['result_eur']) > 0.05 * $bill['cost_eur'];
                                $row['sign_ok'] = ($p > 0) === ($bill['result_eur'] > 0);
                            }
                        } catch (\Throwable $e) { $row['error'] = $e->getMessage(); }
                        // naiv: Vorjahresmonate für den Rest des Abrechnungsjahrs (nur Verbrauch, Preise des Vertrags)
                        if ($row['kind'] === 'consumption') $row['naive_eur'] = naiveSaldo($app, $backup, $layout, $case['key'], $bill, $cut);
                        if (isset($row['naive_eur'])) $row['naive_abs_err_eur'] = round(abs($row['naive_eur'] - $bill['result_eur']), 2);
                        $run['saldo'][] = $row;
                    }
                    unset($app);
                    rrmdir($dir);
                }
            }
            $log(sprintf('%s/%s Saldo  %.0f s', $hh, $variant, microtime(true) - $tv));
        }
        $run['seconds'] = round(microtime(true) - $tv, 1);
        $run['summary'] = summarize($run, $modes);
        // Urteilsfenster je `judge_from` der Schwellen (Entscheidung 2026-11-12): dieselben Kennzahlen nur ab dort
        $run['summary_from'] = [];
        foreach (array_unique(array_filter(array_column($schwellen['rows'], 'judge_from'))) as $jf) $run['summary_from'][$jf] = summarize($run, $modes, "$jf-01");
        $result['runs'][] = $run;
    }
}

/**
 * Naiver Saldo: bisherige Monate des Abrechnungsjahrs aus den Monatswerten der App, die restlichen
 * aus denselben Monaten ein Jahr vorher; Arbeitspreis je Zählwerk, Grundpreis, Abschläge wie im
 * Vertrag zum Schnitt.
 */
function naiveSaldo(\Energietracker\App $app, array $backup, array $layout, string $key, array $bill, string $cut): ?float
{
    $members = $key === 'wp' ? ['z1_ht', 'z1_nt'] : ($key === 'house' ? ['z2_ht', 'z2_nt'] : ['main']);
    $contract = null;
    foreach ($backup['utilities']['strom']['contracts'] as $c) if ($c['id'] === $bill['contract_id']) $contract = $c;
    if ($contract === null) return null;
    $priceOf = function (string $mid) use ($contract): float {
        $l = $contract['working_prices_by_meter'][$mid] ?? $contract['working_prices'];
        return (float)$l[0]['ct_per_kwh'] / 100;
    };
    $adv = 0.0;
    foreach ($contract['advance_payments'] as $a) if ($a['from'] <= $cut) $adv = (float)$a['amount_eur'];
    $energy = 0.0;
    foreach ($members as $k) {
        $mid = $layout['strom'][$k];
        $m = $app->meters->get('strom', $mid);
        if ($m === null) return null;
        $monthly = [];
        foreach ($app->consumption->atDate($cut, fn() => $app->consumption->forMeter('strom', $m)) as $r) $monthly[(string)$r['ym']] = (float)($r['kwh'] ?? 0);
        $kwh = 0.0;
        for ($ym = substr($bill['from'], 0, 7); $ym <= substr($bill['until'], 0, 7); $ym = ymAdd($ym, 1)) {
            $v = $ym < substr($cut, 0, 7) ? ($monthly[$ym] ?? null) : ($monthly[ymAdd($ym, -12)] ?? null);
            if ($v === null) return null;
            // Wärmepumpe in der Kaskade: der Rest (Z1 − Z2 je Zählwerk)
            $kwh += $v;
        }
        if ($key === 'wp') {
            $sub = $app->meters->get('strom', $layout['strom'][$k === 'z1_ht' ? 'z2_ht' : 'z2_nt']);
            if ($sub === null) return null;
            $sm = [];
            foreach ($app->consumption->atDate($cut, fn() => $app->consumption->forMeter('strom', $sub)) as $r) $sm[(string)$r['ym']] = (float)($r['kwh'] ?? 0);
            for ($ym = substr($bill['from'], 0, 7); $ym <= substr($bill['until'], 0, 7); $ym = ymAdd($ym, 1)) {
                $v = $ym < substr($cut, 0, 7) ? ($sm[$ym] ?? null) : ($sm[ymAdd($ym, -12)] ?? null);
                if ($v === null) return null;
                $kwh -= $v;
            }
        }
        $energy += $kwh * $priceOf($mid);
    }
    $base = (float)$contract['base_prices'][0]['eur_per_month'] * $bill['months'];
    return round($energy + $base - $adv * $bill['months'], 2);
}

// ── Zusammenfassung je Lauf ──────────────────────────────────────────────
function agg(array $vals, string $how): ?float
{
    $vals = array_values(array_filter($vals, fn($v) => $v !== null));
    if ($vals === []) return null;
    return match ($how) {
        'mean' => round(array_sum($vals) / count($vals), 2),
        'mean_abs' => round(array_sum(array_map('abs', $vals)) / count($vals), 2),
        'max_abs' => round(max(array_map('abs', $vals)), 2),
        'max' => round(max($vals), 2),
        default => null,
    };
}
/**
 * Kennzahlen eines Laufs. Mit `$from` (Tag) nur Schnitte und Saldo-Fälle ab diesem Tag: das
 * Urteilsfenster (Entscheidung 2026-11-12, `judge_from` in schwellen.json).
 */
function summarize(array $run, array $modes, ?string $from = null): array
{
    if ($from !== null) {
        $run['cuts'] = array_values(array_filter($run['cuts'], fn($c) => $c['cut'] >= $from));
        $run['saldo'] = array_values(array_filter($run['saldo'], fn($r) => $r['cut'] >= $from));
    }
    $out = [];
    foreach ($modes as $mode) {
        $blocks = [];
        foreach ($run['cuts'] as $c) foreach ($c['modes'][$mode]['blocks'] ?? [] as $b => $row) $blocks[$b][] = ['cut' => $c['cut'], 'row' => $row];
        foreach ($blocks as $b => $list) {
            $ok = array_values(array_filter($list, fn($x) => $x['row']['valid'] && $x['row']['metrics'] !== null));
            $col = fn(string $k, ?array $only = null) => array_map(fn($x) => $x['row']['metrics'][$k] ?? null, array_values(array_filter($ok, fn($x) => $only === null || in_array($x['cut'], $only, true))));
            $ncol = fn(string $k) => array_map(fn($x) => $x['row']['naive'][$k] ?? null, $ok);
            $bandM = array_sum(array_map(fn($x) => $x['row']['metrics']['band_months'], $ok));
            $bandH = array_sum(array_map(fn($x) => $x['row']['metrics']['band_hits'], $ok));
            $s = ['cuts' => count($list), 'valid' => count($ok),
                  'mean_annual_err_pct' => agg($col('annual_err_pct'), 'mean'), 'mean_abs_annual_err_pct' => agg($col('annual_err_pct'), 'mean_abs'),
                  'max_abs_annual_err_pct' => agg($col('annual_err_pct'), 'max_abs'), 'mean_wape_pct' => agg($col('wape_pct'), 'mean'),
                  'max_month_err_pct' => agg($col('max_month_err_pct'), 'max'), 'max_month_err_all_pct' => agg($col('max_month_err_all_pct'), 'max'),
                  'band_month_hit_pct' => $bandM > 0 ? round($bandH / $bandM * 100, 1) : null, 'band_months' => $bandM,
                  'naive_mean_abs_annual_err_pct' => agg($ncol('annual_err_pct'), 'mean_abs'), 'naive_mean_wape_pct' => agg($ncol('wape_pct'), 'mean'),
                  'per_cut' => array_map(fn($x) => ['cut' => $x['cut'], 'annual_err_pct' => $x['row']['metrics']['annual_err_pct'] ?? null, 'wape_pct' => $x['row']['metrics']['wape_pct'] ?? null,
                      'band_year_hit' => $x['row']['metrics']['band_year_hit'] ?? null, 'winter_wape_pct' => $x['row']['metrics']['winter_wape_pct'] ?? null], $list),
                  'invalid' => array_values(array_map(fn($x) => ['cut' => $x['cut'], 'reason' => $x['row']['reason'] ?? 'no_metrics'], array_filter($list, fn($x) => !$x['row']['valid'] || $x['row']['metrics'] === null)))];
            foreach ($run['cut_sets'] as $set => $cuts) {
                $s['sets'][$set] = ['mean_abs_annual_err_pct' => agg($col('annual_err_pct', $cuts), 'mean_abs'), 'mean_annual_err_pct' => agg($col('annual_err_pct', $cuts), 'mean'),
                    'mean_wape_pct' => agg($col('wape_pct', $cuts), 'mean'), 'n' => count(array_filter($col('annual_err_pct', $cuts), fn($v) => $v !== null)),
                    'band_year_hits' => array_values(array_map(fn($x) => $x['row']['metrics']['band_year_hit'], array_filter($ok, fn($x) => in_array($x['cut'], $cuts, true)))),
                    'winter_wape_pct' => agg($col('winter_wape_pct', $cuts), 'mean')];
            }
            $out[$mode]['blocks'][$b] = $s;
        }
        // HT/NT
        $h = [];
        foreach ($run['cuts'] as $c) {
            $x = $c['modes'][$mode]['htnt'] ?? [];
            foreach (['z1', 'z2'] as $g) if ($x[$g]['valid'] ?? false) { $h['energy'][] = $x[$g]['energy_gap_month_max_kwh']; $h['energy_year'][] = $x[$g]['energy_gap_kwh']; $h['base'][] = $x[$g]['base_gap_eur']; }
            if (isset($x['report']['report_gap_eur'])) $h['report'][] = $x['report']['report_gap_eur'];
        }
        if ($h !== []) $out[$mode]['htnt'] = ['max_abs_energy_gap_kwh' => agg($h['energy'] ?? [], 'max_abs'), 'max_abs_energy_gap_year_kwh' => agg($h['energy_year'] ?? [], 'max_abs'), 'max_abs_base_gap_eur' => agg($h['base'] ?? [], 'max_abs'),
            'mean_base_gap_eur' => agg($h['base'] ?? [], 'mean'), 'max_abs_report_gap_eur' => agg($h['report'] ?? [], 'max_abs'), 'mean_report_gap_eur' => agg($h['report'] ?? [], 'mean')];
        // Saldo
        $rows = array_values(array_filter($run['saldo'], fn($r) => $r['mode'] === $mode && isset($r['err_eur'])));
        if ($rows !== []) {
            $cons = array_values(array_filter($rows, fn($r) => $r['kind'] === 'consumption'));
            $sig = array_values(array_filter($cons, fn($r) => $r['significant']));
            $out[$mode]['saldo'] = ['cases' => count($rows), 'consumption_cases' => count($cons),
                'mean_abs_err_eur' => agg(array_column($cons, 'abs_err_eur'), 'mean'), 'mean_err_eur' => agg(array_column($cons, 'err_eur'), 'mean'),
                'naive_mean_abs_err_eur' => agg(array_column($cons, 'naive_abs_err_eur'), 'mean'),
                'sign_cases' => count($sig), 'sign_ok_pct' => $sig !== [] ? round(count(array_filter($sig, fn($r) => $r['sign_ok'])) / count($sig) * 100, 1) : null,
                'feed_in_mean_abs_err_eur' => agg(array_column(array_values(array_filter($rows, fn($r) => $r['kind'] === 'feed_in')), 'abs_err_eur'), 'mean'),
                'by_contract' => array_map(fn($k) => ['mean_abs_err_eur' => agg(array_column(array_filter($rows, fn($r) => $r['key'] === $k), 'abs_err_eur'), 'mean'),
                    'mean_err_eur' => agg(array_column(array_filter($rows, fn($r) => $r['key'] === $k), 'err_eur'), 'mean')], array_combine($u = array_values(array_unique(array_column($rows, 'key'))), $u))];
        }
    }
    return $out;
}

// ── Schwellen ────────────────────────────────────────────────────────────
/** Der Wert einer Schwellen-Zeile in einem Lauf (oder null, wenn er dort nicht vorkommt). */
function rowValue(array $row, array $sum, ?array $run = null): mixed
{
    $mode = $row['mode'] ?? 'vorab';
    $s = $sum[$mode] ?? null;
    if ($s === null) return null;
    $blockKey = match ($row['block']) {
        'battery' => str_starts_with($row['measure'], 'import') ? 'battery_import' : 'feed_in',
        'wallbox' => str_starts_with($row['measure'], 'parent') ? 'wallbox_parent' : 'wallbox_meter',
        default => $row['block'],
    };
    if ($row['block'] === 'saldo') {
        return match ($row['measure']) { 'abs_err_eur' => $s['saldo']['mean_abs_err_eur'] ?? null, 'sign_ok' => $s['saldo']['sign_ok_pct'] ?? null,
            'feed_abs_err_eur' => $s['saldo']['feed_in_mean_abs_err_eur'] ?? null, default => null };
    }
    if ($row['block'] === 'htnt') {
        return match ($row['measure']) { 'energy_gap_kwh' => $s['htnt']['max_abs_energy_gap_kwh'] ?? null, 'base_gap_eur' => $s['htnt']['max_abs_base_gap_eur'] ?? null,
            'report_gap_eur' => $s['htnt']['max_abs_report_gap_eur'] ?? null, default => null };
    }
    $b = $s['blocks'][$blockKey] ?? null;
    if ($b === null) return null;
    $set = $row['cut_set'] ?? null;
    if (is_array($set)) {   // Band im kalten und im milden Jahr: alle Fenster beider Mengen
        $hits = [];
        foreach ($set as $x) foreach ($b['sets'][$x]['band_year_hits'] ?? [] as $v) $hits[] = $v;
        return $hits === [] ? null : (in_array(null, $hits, true) ? false : !in_array(false, $hits, true));
    }
    $m = $row['measure'];
    if ($m === 'winter_wape_pct') return $set !== null ? ($b['sets'][$set]['winter_wape_pct'] ?? null) : null;
    if ($m === 'band_month_hit_pct') return $b['band_month_hit_pct'];
    if ($m === 'max_month_err_pct') return $b['max_month_err_pct'];
    if ($m === 'wape_pct') return $set !== null ? ($b['sets'][$set]['mean_wape_pct'] ?? null) : $b['mean_wape_pct'];
    // Jahresfehler (auch import_/feed_/parent_annual_err_pct)
    if ($set !== null) return $b['sets'][$set]['mean_abs_annual_err_pct'] ?? null;
    return match ($row['stat']) { 'max_abs' => $b['max_abs_annual_err_pct'], 'max_cut_worse_pp' => $b['per_cut'], default => $b['mean_abs_annual_err_pct'] };
}
function checkRow(array $row, mixed $value, mixed $old): array
{
    $fmt = fn($v) => $v === null ? '–' : (is_bool($v) ? ($v ? 'ja' : 'nein') : (is_array($v) ? 'je Schnitt' : (string)$v));
    if ($row['stat'] === 'max_cut_worse_pp' && is_array($value)) {
        if (!is_array($old)) return ['value' => 'je Schnitt', 'limit' => '≤ alt + ' . $row['max'] . ' Punkte', 'met' => null];
        $byCut = array_column($old, 'annual_err_pct', 'cut');
        $worst = null;
        foreach ($value as $c) if (($c['annual_err_pct'] ?? null) !== null && ($byCut[$c['cut']] ?? null) !== null) $worst = max($worst ?? -INF, abs($c['annual_err_pct']) - abs($byCut[$c['cut']]));
        return ['value' => $worst !== null ? round($worst, 2) . ' Punkte' : '–', 'limit' => '≤ ' . $row['max'] . ' Punkte', 'met' => $worst !== null ? $worst <= $row['max'] : null];
    }
    switch ($row['kind']) {
        case 'abs':
            $met = $value === null ? null : (isset($row['max']) ? abs((float)$value) <= $row['max'] : (float)$value >= $row['min']);
            return ['value' => $fmt($value), 'limit' => isset($row['max']) ? '≤ ' . $row['max'] : '≥ ' . $row['min'], 'met' => $met];
        case 'range':
            return ['value' => $fmt($value), 'limit' => $row['min'] . '–' . $row['max'], 'met' => $value === null ? null : ((float)$value >= $row['min'] && (float)$value <= $row['max'])];
        case 'bool':
            return ['value' => $fmt($value), 'limit' => 'ja', 'met' => $value === null ? null : $value === true];
        case 'rel':
            if ($old === null || is_array($old)) return ['value' => $fmt($value), 'limit' => $value !== null ? '≤ ' . $row['factor'] . ' × alt (für neu: ≤ ' . round($row['factor'] * (float)$value, 2) . ')' : '–', 'met' => null];
            return ['value' => $fmt($value), 'limit' => '≤ ' . $row['factor'] . ' × ' . $old . ' = ' . round($row['factor'] * (float)$old, 2), 'met' => $value === null ? null : (float)$value <= $row['factor'] * (float)$old + 1e-9];
        default:
            return ['value' => '–', 'limit' => $row['text'], 'met' => null];
    }
}
$result['thresholds'] = [];
$plain = fn($v) => $v === null ? null : (is_bool($v) ? ($v ? 'ja' : 'nein') : (is_array($v) ? null : $v));
foreach ($result['runs'] as $run) {
    $old = null;
    if ($baseline !== null) foreach ($baseline['runs'] ?? [] as $br) if ($br['household'] === $run['household'] && $br['variant'] === $run['variant']) $old = $br;
    // Entscheidung 2026-11-12 (2): für B gelten nur die festen Schwellen, relative Zeilen als „nicht schlechter als alt“
    $hhRule = $schwellen['households'][$run['household']] ?? null;
    foreach ($schwellen['rows'] as $row) {
        if (($row['kind'] ?? '') === 'external') continue;
        if (isset($row['variant']) && $row['variant'] !== $run['variant']) continue;
        if (!in_array($row['block'], $blocks, true)) continue;
        $jf = $row['judge_from'] ?? null;
        // Urteil im Fenster ab `judge_from` (Entscheidung 2026-11-12 (1)), daneben der Wert über alle Schnitte
        $sum = $jf !== null ? ($run['summary_from'][$jf] ?? null) : $run['summary'];
        $v = $sum !== null ? rowValue($row, $sum, $run) : null;
        $all = rowValue($row, $run['summary'], $run);
        if ($v === null && $all === null && $row['block'] !== 'heat_pump') continue;
        $oldSum = $old === null ? null : ($jf !== null ? ($old['summary_from'][$jf] ?? null) : ($old['summary'] ?? null));
        $o = $oldSum !== null ? rowValue($row, $oldSum) : null;
        $text = $row['text'];
        if ($hhRule !== null && $row['kind'] === 'rel') {
            $row['factor'] = (float)$hhRule['factor'];
            $text .= ' (für ' . $run['household'] . ': nicht schlechter als alt)';
        }
        $result['thresholds'][] = ['id' => $row['id'], 'household' => $run['household'], 'variant' => $run['variant'], 'text' => $text, 'judge_from' => $jf]
            + checkRow($row, $v, $o) + ['value_all_cuts' => $plain($all), 'old' => is_array($o) ? null : $o];
    }
}
$result['meta']['seconds'] = round(microtime(true) - $t0, 1);

// ── Ausgabe ──────────────────────────────────────────────────────────────
function pct(?float $v): string { return $v === null ? '–' : str_replace('.', ',', sprintf('%+.1f', $v)) . ' %'; }
function num(?float $v, int $d = 1): string { return $v === null ? '–' : str_replace('.', ',', number_format($v, $d, '.', '')); }
$md = ["# Rückrechnung am Prüfhaushalt", '', 'Code: `' . $result['meta']['commit'] . '` (' . $root . '), Laufzeit ' . num($result['meta']['seconds'], 0) . ' s. '
    . 'Fenster je Schnitt: zwölf Monate ab dem Schnitt; vorab = Klimanormal, nachgerechnet = Ist-Wetter des Fensters.', ''];
$labels = ['heat_pump' => 'Wärmepumpe', 'pv' => 'PV-Erzeugung', 'feed_in' => 'Einspeisung Z1', 'battery_import' => 'Bezug Z1 (Speicher)', 'battery_charge' => 'Speicher Ladung',
           'battery_discharge' => 'Speicher Entladung', 'wallbox_parent' => 'Elternzähler Z2 (Wallbox)', 'wallbox_meter' => 'Wallbox-Zähler'];
foreach ($result['runs'] as $run) {
    $md[] = "## {$run['household']}/{$run['variant']} — {$run['name']}";
    $md[] = '';
    $md[] = '| Baustein | Modus | Schnitte | Jahresfehler Ø | |Jahresfehler| Ø | max | WAPE Ø | größter Monatsfehler | Band Monate | naiv |Jahr| Ø | naiv WAPE |';
    $md[] = '|---|---|---|---|---|---|---|---|---|---|---|';
    foreach ($run['summary'] as $mode => $s) foreach ($s['blocks'] ?? [] as $b => $x) {
        $md[] = sprintf('| %s | %s | %d/%d | %s | %s | %s | %s | %s | %s | %s | %s |', $labels[$b] ?? $b, $mode, $x['valid'], $x['cuts'], pct($x['mean_annual_err_pct']),
            num($x['mean_abs_annual_err_pct']) . ' %', num($x['max_abs_annual_err_pct']) . ' %', num($x['mean_wape_pct']) . ' %', num($x['max_month_err_pct'], 0) . ' %',
            $x['band_month_hit_pct'] !== null ? num($x['band_month_hit_pct'], 0) . ' %' : 'kein Band', num($x['naive_mean_abs_annual_err_pct']) . ' %', num($x['naive_mean_wape_pct']) . ' %');
    }
    $md[] = '';
    $setRows = [];
    foreach ($run['summary'] as $mode => $s) foreach ($s['blocks'] ?? [] as $b => $x) foreach ($x['sets'] ?? [] as $set => $v) {
        if ($v['n'] === 0) continue;
        if (!in_array($b, ['heat_pump', 'battery_import', 'feed_in', 'wallbox_parent', 'wallbox_meter'], true)) continue;
        if (in_array($set, ['battery_year'], true) && !in_array($b, ['battery_import', 'feed_in'], true)) continue;
        if ($set === 'wallbox_year' && !in_array($b, ['wallbox_parent', 'wallbox_meter'], true)) continue;
        if (in_array($set, ['after_mild', 'after_cold', 'mild_year', 'cold_year'], true) && $b !== 'heat_pump') continue;
        $setRows[] = sprintf('| %s | %s | %s | %d | %s | %s | %s |', $labels[$b] ?? $b, $mode, $set, $v['n'], pct($v['mean_annual_err_pct']), num($v['mean_abs_annual_err_pct']) . ' %',
            $set === 'wallbox_year' && $b === 'wallbox_meter' ? 'Winter-WAPE ' . num($v['winter_wape_pct']) . ' %' : (in_array($set, ['mild_year', 'cold_year'], true) ? 'Band Jahr: ' . implode(' ', array_map(fn($h) => $h === null ? '–' : ($h ? 'ja' : 'nein'), $v['band_year_hits'])) : 'WAPE ' . num($v['mean_wape_pct']) . ' %'));
    }
    if ($setRows !== []) { $md[] = '| Baustein | Modus | Teilmenge | n | Jahresfehler Ø | |Jahresfehler| Ø | dazu |'; $md[] = '|---|---|---|---|---|---|---|'; array_push($md, ...$setRows); $md[] = ''; }
    foreach ($run['summary'] as $mode => $s) {
        if (isset($s['htnt'])) $md[] = "- HT/NT ($mode): Gruppe − (HT + NT) höchstens " . num($s['htnt']['max_abs_energy_gap_kwh'], 2) . ' kWh; Gruppe − Zählwerke − Grundpreis im Mittel '
            . num($s['htnt']['mean_base_gap_eur'], 2) . ' € (höchstens ' . num($s['htnt']['max_abs_base_gap_eur'], 2) . ' €); Jahresbericht − Gruppen im Mittel ' . num($s['htnt']['mean_report_gap_eur'], 2) . ' €';
        if (isset($s['saldo'])) $md[] = "- Saldo ($mode): " . $s['saldo']['consumption_cases'] . ' Fälle, mittlerer Fehler ' . num($s['saldo']['mean_abs_err_eur'], 2) . ' € (Ø mit Vorzeichen '
            . num($s['saldo']['mean_err_eur'], 2) . ' €), naiv ' . num($s['saldo']['naive_mean_abs_err_eur'], 2) . ' €; Vorzeichen richtig ' . num($s['saldo']['sign_ok_pct'], 0) . ' % von '
            . $s['saldo']['sign_cases'] . ($s['saldo']['feed_in_mean_abs_err_eur'] !== null ? '; Einspeisung mittlerer Fehler ' . num($s['saldo']['feed_in_mean_abs_err_eur'], 2) . ' €' : '');
    }
    $md[] = '';
    // Urteilsfenster (Entscheidung 2026-11-12): dieselben Kennzahlen nur ab `judge_from`
    foreach ($run['summary_from'] ?? [] as $jf => $sf) {
        $md[] = "**Urteilsfenster ab $jf** (Schnitte und Saldo-Fälle ab diesem Monat; Wärmepumpe, Saldo und Einspeisung werden hier geurteilt):";
        $md[] = '';
        $md[] = '| Baustein | Modus | Schnitte | Jahresfehler Ø | |Jahresfehler| Ø | max | WAPE Ø | größter Monatsfehler | Band Monate |';
        $md[] = '|---|---|---|---|---|---|---|---|---|';
        foreach ($sf as $mode => $s) foreach (['heat_pump', 'feed_in'] as $b) {
            $x = $s['blocks'][$b] ?? null;
            if ($x === null) continue;
            $md[] = sprintf('| %s | %s | %d/%d | %s | %s | %s | %s | %s | %s |', $labels[$b] ?? $b, $mode, $x['valid'], $x['cuts'], pct($x['mean_annual_err_pct']),
                num($x['mean_abs_annual_err_pct']) . ' %', num($x['max_abs_annual_err_pct']) . ' %', num($x['mean_wape_pct']) . ' %', num($x['max_month_err_pct'], 0) . ' %',
                $x['band_month_hit_pct'] !== null ? num($x['band_month_hit_pct'], 0) . ' %' : 'kein Band');
        }
        $md[] = '';
        foreach ($sf as $mode => $s) if (isset($s['saldo'])) $md[] = "- Saldo ab $jf ($mode): " . $s['saldo']['consumption_cases'] . ' Fälle, mittlerer Fehler ' . num($s['saldo']['mean_abs_err_eur'], 2)
            . ' €; Vorzeichen richtig ' . num($s['saldo']['sign_ok_pct'], 0) . ' % von ' . $s['saldo']['sign_cases']
            . ($s['saldo']['feed_in_mean_abs_err_eur'] !== null ? '; Einspeisung mittlerer Fehler ' . num($s['saldo']['feed_in_mean_abs_err_eur'], 2) . ' €' : '');
        $md[] = '';
    }
}
if ($result['thresholds'] !== []) {
    $md[] = '## Schwellen';
    $md[] = '';
    $md[] = 'Geurteilt wird im Urteilsfenster der Zeile („ab“); „alle Schnitte“ zeigt denselben Wert über alle Schnitte. '
        . 'Für Haushalt B gelten nur die festen Schwellen, relative Zeilen dort als „nicht schlechter als alt“.';
    $md[] = '';
    $md[] = '| Haushalt | Schwelle | ab | Wert | alle Schnitte | Grenze | erfüllt |';
    $md[] = '|---|---|---|---|---|---|---|';
    foreach ($result['thresholds'] as $t) $md[] = sprintf('| %s/%s | %s | %s | %s | %s | %s | %s |', $t['household'], $t['variant'], $t['text'], $t['judge_from'] ?? 'alle',
        $t['value'], $t['value_all_cuts'] ?? '–', $t['limit'], $t['met'] === null ? 'offen' : ($t['met'] ? 'ja' : 'nein'));
    $md[] = '';
}
$text = implode("\n", $md) . "\n";
if ($args['json'] !== null) file_put_contents((string)$args['json'], json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION) . "\n");
if ($args['md'] !== null) file_put_contents((string)$args['md'], $text); else echo $text;
