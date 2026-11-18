<?php
declare(strict_types=1);

/**
 * v3.14.0 — PHPUnit in mehreren Prozessen gleichzeitig, ohne weitere Abhängigkeit.
 *
 *   php tools/phpunit-parallel.php                 # so viele Prozesse wie Kerne
 *   php tools/phpunit-parallel.php -j 4            # vier Prozesse
 *   php tools/phpunit-parallel.php -- --filter Foo # Optionen für PHPUnit nach "--"
 *   composer test:parallel
 *
 * Ablauf:
 * - Die Testdateien liefert PHPUnit selbst (`--list-test-files`), die Zahl der Tests ebenso (`--list-tests`).
 *   Weicht die Summe der Prozesse davon ab, schlägt der Lauf fehl: Kein Test fällt unbemerkt heraus.
 * - Verteilt wird nach der Dauer je Datei aus dem letzten Lauf (`.phpunit.cache/parallel/zeiten.json`, aus den
 *   JUnit-Protokollen der Prozesse); unbekannte Dateien zählen mit dem Mittel. Gleiche Eingabe, gleiche
 *   Verteilung; welche Datei in welchem Prozess lief, steht in `.phpunit.cache/parallel/verteilung.txt`.
 * - Jeder Prozess bekommt eine eigene Konfiguration (aus phpunit.xml, nur die Dateien ersetzt), einen eigenen
 *   Cache und einen eigenen Bereich für die Ports seiner Testserver (`ET_TEST_PORT_BASE`, siehe
 *   tests/unit/Support/TestPorts.php), damit sich die Prozesse nicht in die Quere kommen.
 * - Immer ohne Coverage. Exit 0 nur, wenn jeder Prozess grün ist und die Zahl stimmt.
 *
 * Der volle Lauf nacheinander (`vendor/bin/phpunit --no-coverage`) bleibt gültig; die CI nutzt ihn, solange
 * der parallele Lauf dort nicht messbar schneller ist (docs/entwicklung/tests.md, „Tempo“).
 */

const PORT_BASE = 24000;   // über der Zeitreise (19200 ff.) und dem nachgebauten Home Assistant, unter 32768
const PORT_SPAN = 400;

$root = dirname(__DIR__);
chdir($root);
$phpunit = $root . '/vendor/bin/phpunit';
if (!is_file($phpunit)) { fwrite(STDERR, "vendor/bin/phpunit fehlt: composer install\n"); exit(2); }

// ── Argumente
$jobs = 0;
$pass = [];
$args = array_slice($argv, 1);
for ($i = 0; $i < count($args); $i++) {
    $a = $args[$i];
    if ($a === '--') { $pass = array_merge($pass, array_slice($args, $i + 1)); break; }
    if ($a === '-j' && isset($args[$i + 1])) { $jobs = (int)$args[++$i]; continue; }
    if (preg_match('/^(?:-j|--jobs=)(\d+)$/', $a, $m)) { $jobs = (int)$m[1]; continue; }
    $pass[] = $a;
}
$pass = array_values(array_filter($pass, fn($a) => $a !== '--no-coverage'));
if ($jobs <= 0) $jobs = (int)(getenv('ET_PARALLEL') ?: trim((string)shell_exec('getconf _NPROCESSORS_ONLN 2>/dev/null')) ?: 4);
$jobs = max(1, min($jobs, 16));

$run = function (array $cmd) use ($root): string {
    $p = proc_open(array_merge([PHP_BINARY, ...$cmd]), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    proc_close($p);
    return (string)$out;
};

// ── Was PHPUnit selbst findet
preg_match_all('/^ - (\S+Test\.php)$/m', $run([$phpunit, '--list-test-files', ...$pass]), $m);
$files = array_values(array_unique($m[1]));
sort($files);
preg_match_all('/^ - \S/m', $run([$phpunit, '--list-tests', ...$pass]), $t);
$expected = count($t[0]);
if ($files === [] || $expected === 0) { fwrite(STDERR, "PHPUnit findet keine Tests\n"); exit(1); }
$jobs = min($jobs, count($files));

// ── Verteilen nach Dauer
$dir = "$root/.phpunit.cache/parallel";
@mkdir($dir, 0777, true);
$times = is_file("$dir/zeiten.json") ? (array)json_decode((string)file_get_contents("$dir/zeiten.json"), true) : [];
$known = array_values(array_filter(array_map(fn($f) => $times[substr($f, strlen($root) + 1)] ?? null, $files), 'is_numeric'));
$fallback = $known ? array_sum($known) / count($known) : 1.0;
$weight = [];
foreach ($files as $f) $weight[$f] = (float)($times[substr($f, strlen($root) + 1)] ?? $fallback);
$order = $files;
usort($order, fn($a, $b) => [$weight[$b], $a] <=> [$weight[$a], $b]);
$groups = array_fill(0, $jobs, []);
$load = array_fill(0, $jobs, 0.0);
foreach ($order as $f) {
    $i = array_keys($load, min($load))[0];
    $groups[$i][] = $f;
    $load[$i] += $weight[$f];
}
$plan = '';
foreach ($groups as $i => $g) {
    $plan .= sprintf("Prozess %d (%.0f s erwartet, %d Dateien)\n", $i, $load[$i], count($g));
    foreach ($g as $f) $plan .= '  ' . substr($f, strlen($root) + 1) . "\n";
}
file_put_contents("$dir/verteilung.txt", $plan);

// ── Konfiguration je Prozess aus phpunit.xml
$base = is_file("$root/phpunit.xml") ? "$root/phpunit.xml" : "$root/phpunit.xml.dist";
$procs = [];
$t0 = microtime(true);
printf("PHPUnit in %d Prozessen, %d Dateien, %d Tests …\n", $jobs, count($files), $expected);
foreach ($groups as $i => $g) {
    @mkdir("$dir/$i", 0777, true);
    $doc = new DOMDocument();
    $doc->load($base);
    $x = $doc->documentElement;
    $x->removeAttribute('xsi:noNamespaceSchemaLocation');
    $x->setAttribute('bootstrap', $root . '/' . $x->getAttribute('bootstrap'));
    $x->setAttribute('cacheDirectory', "$dir/$i/cache");
    foreach (iterator_to_array($doc->getElementsByTagName('source')) as $n) $n->parentNode->removeChild($n);
    foreach (iterator_to_array($doc->getElementsByTagName('testsuites')) as $n) $n->parentNode->removeChild($n);
    $suites = $doc->createElement('testsuites');
    $suite = $doc->createElement('testsuite');
    $suite->setAttribute('name', "teil-$i");
    foreach ($g as $f) $suite->appendChild($doc->createElement('file', $f));
    $suites->appendChild($suite);
    $x->appendChild($suites);
    $doc->save("$dir/$i/phpunit.xml");
    $env = array_merge(getenv(), ['ET_TEST_PORT_BASE' => (string)(PORT_BASE + $i * PORT_SPAN), 'ET_TEST_PORT_SPAN' => (string)PORT_SPAN]);
    $procs[$i] = proc_open(
        [PHP_BINARY, $phpunit, '-c', "$dir/$i/phpunit.xml", '--no-coverage', '--colors=never', '--log-junit', "$dir/$i/junit.xml", ...$pass],
        [0 => ['file', '/dev/null', 'r'], 1 => ['file', "$dir/$i/ausgabe.log", 'w'], 2 => ['file', "$dir/$i/ausgabe.log", 'a']],
        $pipes, $root, $env
    );
}

// ── Warten
$exit = [];
while (count($exit) < $jobs) {
    foreach ($procs as $i => $p) {
        if (isset($exit[$i])) continue;
        $s = proc_get_status($p);
        if (!$s['running']) {
            $exit[$i] = $s['exitcode'];
            proc_close($p);
            printf("  Prozess %d fertig nach %.0f s%s\n", $i, microtime(true) - $t0, $exit[$i] === 0 ? '' : ' — FEHLER');
        }
    }
    usleep(100_000);
}
$wall = microtime(true) - $t0;

// ── Zusammenzählen, Zeiten für den nächsten Lauf
$tests = $assertions = 0;
$failed = [];
$seen = [];
foreach ($groups as $i => $g) {
    $out = (string)@file_get_contents("$dir/$i/ausgabe.log");
    if (preg_match('/OK \((\d+) tests?, (\d+) assertions?\)/', $out, $c) || preg_match('/Tests: (\d+), Assertions: (\d+)/', $out, $c)) {
        $tests += (int)$c[1];
        $assertions += (int)$c[2];
    }
    if ($exit[$i] !== 0) $failed[] = $i;
    if (is_file("$dir/$i/junit.xml")) {
        $xml = @simplexml_load_file("$dir/$i/junit.xml");
        if ($xml !== false) foreach ($xml->xpath('//testcase[@file]') ?: [] as $tc) {
            $rel = substr((string)$tc['file'], strlen($root) + 1);
            $times[$rel] = ($seen[$rel] ?? false ? $times[$rel] : 0.0) + (float)$tc['time'];
            $seen[$rel] = true;
        }
    }
}
if (!$failed) file_put_contents("$dir/zeiten.json", json_encode($times, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

foreach ($failed as $i) {
    $out = (string)file_get_contents("$dir/$i/ausgabe.log");
    $pos = preg_match('/^There (?:was|were) \d+/m', $out, $mm, PREG_OFFSET_CAPTURE) ? $mm[0][1] : max(0, strlen($out) - 4000);
    echo "\n── Prozess $i (Dateien: .phpunit.cache/parallel/verteilung.txt, Protokoll: .phpunit.cache/parallel/$i/ausgabe.log)\n";
    echo substr($out, $pos), "\n";
}
$missing = !$failed && $tests !== $expected;
printf("\n%s: %d Tests, %d Assertions in %.1f s mit %d Prozessen (PHPUnit listet %d Tests)\n",
    $failed || $missing ? 'FEHLER' : 'OK', $tests, $assertions, $wall, $jobs, $expected);
if ($missing) {
    fwrite(STDERR, "Abweichung: gelaufen $tests, erwartet $expected — ein Test fehlt in der Verteilung\n");
    exit(1);
}
exit($failed ? 1 : 0);
