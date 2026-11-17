<?php
declare(strict_types=1);

namespace Energietracker\Tests\Http;

use Energietracker\Tests\Support\HttpServerTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * v3.13.0 — Ohne Schreibrecht im Datenordner (fremder Besitzer nach einem Wechsel der UID) antworten Schreibwege mit 503
 * `errors.storage.notWritable`, Vorschauen, die nichts schreiben, weiter mit 200. Der Trockenlauf eines Imports
 * (`?dry_run=…` wahr nach der Regel für Schalter) ist eine solche Vorschau: Er liest die Datei und schreibt nichts.
 * Bisher antwortete er ohne Schreibrecht mit 503, die Vorschau des CSV-Imports sagte also nicht, ob die Datei passt.
 *
 * Nachgestellt mit `chmod a-w` auf alle Ordner; als root greift der Schreibschutz nicht (dann übersprungen). Eigene
 * Werte: Strom und Gas mit Ständen, Fernwärme nach Zeitraum.
 */
#[CoversNothing]
final class DryRunWithoutWriteAccessTest extends HttpServerTestCase
{
    /** @var list<string> */
    private array $locked = [];

    protected function tearDown(): void
    {
        foreach (array_reverse($this->locked) as $d) @chmod($d, 0755);
        parent::tearDown();
    }

    /** @return array<string,string> Pfad → Prüfsumme aller Dateien im Datenordner */
    private function hashes(): array
    {
        $out = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dataDir, \FilesystemIterator::SKIP_DOTS)) as $f) {
            if ($f->isFile()) $out[substr((string)$f->getPathname(), strlen($this->dataDir))] = (string)md5_file((string)$f->getPathname());
        }
        ksort($out);
        return $out;
    }

    /** @return array{status:int, code:?string, data:mixed} */
    private function csv(string $path, string $body): array
    {
        $r = $this->request('POST', $path, ['Content-Type: text/csv', "Origin: {$this->base}", 'X-ET-Language: de'], $body);
        $j = json_decode($r['body'], true);
        return ['status' => $r['status'], 'code' => $j['code'] ?? null, 'data' => $j['data'] ?? null];
    }

    public function testTheDryRunOfAnImportAnswersLikeThePreviews(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) self::markTestSkipped('als root greift der Schreibschutz nicht');
        $this->startServer();
        $h = ['Content-Type: application/json', "Origin: {$this->base}", 'X-ET-Language: de'];
        $strom = (string)$this->json('GET', '/api/utility/strom/meters')[0]['id'];
        $gas = (string)$this->json('GET', '/api/utility/gas/meters')[0]['id'];
        $r = $this->request('POST', '/api/utility/fernwaerme/meters', $h, json_encode(['name' => 'Übergabestation', 'installed_on' => '2024-01-01', 'capture' => 'period']));
        self::assertSame(201, $r['status'], $r['body']);
        $fw = (string)json_decode($r['body'], true)['data']['id'];
        self::assertSame(201, $this->request('POST', '/api/utility/strom/readings', $h, json_encode(['meter_id' => $strom, 'date' => '2025-01-01', 'counter' => 2000]))['status']);
        self::assertSame(201, $this->request('POST', '/api/utility/gas/readings', $h, json_encode(['meter_id' => $gas, 'date' => '2025-01-01', 'counter' => 500]))['status']);
        $export = $this->request('GET', '/api/backup/export?attachments=0');
        self::assertSame(200, $export['status']);
        $backup = json_encode(json_decode($export['body'], true)['data']);

        $dirs = [$this->dataDir];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dataDir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST) as $f) {
            if ($f->isDir()) $dirs[] = (string)$f->getPathname();
        }
        foreach ($dirs as $d) { chmod($d, 0555); $this->locked[] = $d; }
        clearstatcache();
        $before = $this->hashes();

        $readings = "Datum;Zaehlerstand;Notiz\n01.02.2025;2310,5;Februar\n01.03.2025;2590;\n";
        // Strom mit 1, Gas mit true, Fernwärme (Zeiträume) mit ja: Vorschau wie mit Schreibrecht
        $r = $this->csv("/api/utility/strom/meters/$strom/readings/import-csv?dry_run=1", $readings);
        self::assertSame(200, $r['status'], json_encode($r));
        self::assertSame([true, 2, 0], [$r['data']['dry_run'] ?? null, count((array)($r['data']['rows'] ?? [])), $r['data']['imported'] ?? null]);
        $r = $this->csv("/api/utility/gas/meters/$gas/readings/import-csv?dry_run=true", "Datum;Zaehlerstand\n2025-02-01;612\n");
        self::assertSame([200, true, 1], [$r['status'], $r['data']['dry_run'] ?? null, count((array)($r['data']['rows'] ?? []))], json_encode($r));
        $r = $this->csv("/api/utility/fernwaerme/meters/$fw/periods/import-csv?dry_run=ja", "Von;Bis;Wert\n01.01.2025;31.01.2025;850\n01.02.2025;28.02.2025;790\n");
        self::assertSame([200, true, 2], [$r['status'], $r['data']['dry_run'] ?? null, $r['data']['would_import'] ?? null], json_encode($r));
        // die übrigen Importe mit Trockenlauf: Marktpreise und Backup (der eigene Export)
        $r = $this->csv('/api/market-prices/import-csv?dry_run=1', "2025-01;95,5\n2025-02;88,25\n");
        self::assertSame([200, 2], [$r['status'], $r['data']['would_import'] ?? null], json_encode($r));
        $b = $this->request('POST', '/api/backup/import?dry_run=1', $h, $backup);
        self::assertSame(200, $b['status'], $b['body']);
        self::assertTrue((bool)(json_decode($b['body'], true)['data']['dry_run'] ?? false));
        self::assertSame(503, $this->request('POST', '/api/backup/import', $h, $backup)['status'], 'Backup wirklich einspielen');

        // wirklich einspielen bleibt 503 mit Meldung, ebenso „nein“; Unlesbares ist 400 wie mit Schreibrecht
        foreach (['', '?dry_run=nein', '?dry_run=0'] as $q) {
            $r = $this->csv("/api/utility/strom/meters/$strom/readings/import-csv$q", $readings);
            self::assertSame([503, 'errors.storage.notWritable'], [$r['status'], $r['code']], "import $q: " . json_encode($r));
        }
        $r = $this->csv("/api/utility/fernwaerme/meters/$fw/periods/import-csv", "Von;Bis;Wert\n01.01.2025;31.01.2025;850\n");
        self::assertSame([503, 'errors.storage.notWritable'], [$r['status'], $r['code']], json_encode($r));
        $r = $this->csv("/api/utility/strom/meters/$strom/readings/import-csv?dry_run=vielleicht", $readings);
        self::assertSame([400, 'errors.common.boolExpected'], [$r['status'], $r['code']], json_encode($r));

        // dieselbe Antwort wie die Erwartung beim Eintragen im selben Ordner (eine Vorschau, die nichts schreibt)
        $e = $this->request('POST', "/api/utility/strom/meters/$strom/reading-expectations", $h, json_encode(['pairs' => [['from' => '2025-01-01', 'to' => '2025-02-01']]]));
        self::assertSame(200, $e['status'], $e['body']);
        clearstatcache();
        self::assertSame($before, $this->hashes(), 'nichts geschrieben');
    }
}
