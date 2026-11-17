<?php
declare(strict_types=1);

namespace Energietracker\Tests\Http;

use Energietracker\Tests\Support\HttpServerTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * v3.13.0 — Antworten der Schreibwege, wie API-Referenz und CHANGELOG sie zusagen, an der echten HTTP-Schicht:
 *   - Fehlerantworten nennen Feld und Grenze maschinenlesbar (`detail {field, max, length}` bei Freitext über der
 *     Grenze, `detail {field, value, min, max}` bei einer Zahl außerhalb), nicht nur im übersetzten Satz; ein
 *     Anfangsstand über 10¹² nennt die Grenze statt „eine Zahl ab 0“.
 *   - Die Gerätenummer hat die Höchstlänge der Nummern (64), an jedem Weg, der eine anlegt.
 *   - Ein Beleg kommt auch als Formular (multipart/form-data) an; eine unbekannte Art ist 400, nicht still „other“.
 *   - Ohne Schreibrecht und ohne gespeicherte Kennung der Installation lesen Zusammenfassung, „Zu tun“ und Kalender
 *     weiter (200), mit derselben Kennung bei jeder Anfrage; Schreibwege bleiben 503.
 * Eigene Werte (Namen aus 121 Zeichen, Gerätenummern aus 65 Zeichen, ein PDF aus 80 Bytes).
 */
#[CoversNothing]
final class InputAnswersEndpointTest extends HttpServerTestCase
{
    /** @var list<string> */
    private array $locked = [];

    protected function tearDown(): void
    {
        foreach (array_reverse($this->locked) as $d) @chmod($d, 0755);
        parent::tearDown();
    }

    /** @return array{status:int, code:?string, detail:mixed, data:mixed} */
    private function call(string $method, string $path, mixed $payload = null): array
    {
        $r = $this->request($method, $path, ['Content-Type: application/json', "Origin: {$this->base}", 'X-ET-Language: de'],
            $payload === null ? null : json_encode($payload));
        $j = json_decode($r['body'], true);
        return ['status' => $r['status'], 'code' => $j['code'] ?? null, 'detail' => $j['detail'] ?? null, 'data' => $j['data'] ?? null];
    }

    public function testErrorAnswersNameFieldAndLimitAsValues(): void
    {
        $this->startServer();
        $name = str_repeat('Kellerzähler ', 10) . 'X';   // 131 Zeichen, erlaubt 120
        $len = mb_strlen($name);
        $r = $this->call('POST', '/api/utility/strom/meters', ['name' => $name, 'installed_on' => '2024-03-01']);
        self::assertSame([400, 'errors.common.tooLong'], [$r['status'], $r['code']], json_encode($r));
        self::assertSame(['field' => 'name', 'max' => 120, 'length' => $len], $r['detail']);

        // derselbe Fund an PATCH von Zähler und Vertrag und an der Notiz einer Ablesung
        $meter = (string)$this->json('GET', '/api/utility/strom/meters')[0]['id'];
        $r = $this->call('PATCH', "/api/utility/strom/meters/$meter", ['name' => $name]);
        self::assertSame(['field' => 'name', 'max' => 120, 'length' => $len], $r['detail'], json_encode($r));
        $c = $this->call('POST', '/api/utility/strom/contracts', ['meter_id' => $meter, 'provider' => 'Stadtwerk Beispielfeld', 'start' => '2024-03-01',
            'working_prices' => [['from' => '2024-03-01', 'ct_per_kwh' => '31,5']]]);
        self::assertSame(201, $c['status'], json_encode($c));
        self::assertSame(31.5, (float)$c['data']['working_prices'][0]['ct_per_kwh'], 'Vertragspreis mit Komma');
        $r = $this->call('PATCH', "/api/utility/strom/contracts/{$c['data']['id']}", ['tariff_name' => $name]);
        self::assertSame(['field' => 'tariff_name', 'max' => 120, 'length' => $len], $r['detail'], json_encode($r));
        $note = str_repeat('Zählerschrank im Flur. ', 90);   // 2.070 Zeichen, erlaubt 2.000
        $r = $this->call('POST', '/api/utility/strom/readings', ['meter_id' => $meter, 'date' => '2024-04-02', 'counter' => '1250.5', 'note' => $note]);
        self::assertSame(['field' => 'note', 'max' => 2000, 'length' => mb_strlen(trim($note))], $r['detail'], json_encode($r));

        // eine Zahl über der Grenze: Grenze im Satz und als Werte (Anfangsstand, Ablesung, Zählertausch)
        $r = $this->call('POST', '/api/utility/gas/meters', ['name' => 'Garage', 'installed_on' => '2024-03-01', 'initial_counter' => 3e13]);
        self::assertSame([400, 'errors.common.numberOutOfRange'], [$r['status'], $r['code']], json_encode($r));
        self::assertSame(['initial_counter', 0, 1000000000000], [$r['detail']['field'] ?? null, $r['detail']['min'] ?? null, $r['detail']['max'] ?? null]);
        $r = $this->call('POST', '/api/utility/strom/readings', ['meter_id' => $meter, 'date' => '2024-04-03', 'counter' => 2e12]);
        self::assertSame(['errors.common.numberOutOfRange', 'counter', 1000000000000], [$r['code'], $r['detail']['field'] ?? null, $r['detail']['max'] ?? null]);
        $r = $this->call('POST', "/api/utility/strom/meters/$meter/replace-device", ['date' => '2024-05-01', 'old_final_counter' => 1300, 'new_initial_counter' => 7e12]);
        self::assertSame(['errors.common.numberOutOfRange', 'new_initial_counter'], [$r['code'], $r['detail']['field'] ?? null], json_encode($r));
        // negativ bleibt „eine Zahl ab 0“ (kein Feldbezug in der Meldung, also kein detail)
        $r = $this->call('POST', '/api/utility/gas/meters', ['name' => 'Garage', 'installed_on' => '2024-03-01', 'initial_counter' => -4]);
        self::assertSame(['errors.reading.counterInvalid', null], [$r['code'], $r['detail']], json_encode($r));
        // ein Wahrheitswert außerhalb der Regel nennt sein Feld
        $r = $this->call('POST', '/api/utility/strom/readings', ['meter_id' => $meter, 'date' => '2024-04-04', 'counter' => 1260, 'is_estimated' => 'womöglich']);
        self::assertSame(['errors.common.boolExpected', ['field' => 'is_estimated']], [$r['code'], $r['detail']], json_encode($r));
    }

    public function testTheDeviceNumberHasTheLimitOfNumbersOnEveryPath(): void
    {
        $this->startServer();
        $long = 'GZ' . str_repeat('7', 63);   // 65 Zeichen
        $max = 'GZ' . str_repeat('7', 62);    // 64 Zeichen: geht
        foreach ([['device_serial' => $long], ['device' => ['serial' => $long, 'installed_on' => '2024-01-01']]] as $i => $in) {
            $r = $this->call('POST', '/api/utility/gas/meters', ['name' => "Gas $i", 'installed_on' => '2024-01-01'] + $in);
            self::assertSame([400, 'errors.common.tooLong', 'device_serial', 64, 65],
                [$r['status'], $r['code'], $r['detail']['field'] ?? null, $r['detail']['max'] ?? null, $r['detail']['length'] ?? null], json_encode($r));
        }
        $r = $this->call('POST', '/api/utility/gas/meters', ['name' => 'Gas Liste', 'devices' => [['id' => 'd_k4b_1', 'installed_on' => '2024-01-01', 'serial' => $long]]]);
        self::assertSame(['errors.common.tooLong', 'devices.serial'], [$r['code'], $r['detail']['field'] ?? null], json_encode($r));
        $r = $this->call('POST', '/api/utility/gas/meters', ['name' => 'Gas Liste', 'devices' => [['id' => 'd_k4b_2', 'installed_on' => '2024-01-01', 'serial' => ['GZ', '1']]]]);
        self::assertSame('errors.common.textExpected', $r['code'], 'Nummer als Liste');
        $ok = $this->call('POST', '/api/utility/gas/meters', ['name' => 'Gas genau 64', 'installed_on' => '2024-01-01', 'device_serial' => $max]);
        self::assertSame(201, $ok['status'], json_encode($ok));
        self::assertSame($max, $ok['data']['devices'][0]['serial']);
        // Zählertausch
        $r = $this->call('POST', "/api/utility/gas/meters/{$ok['data']['id']}/replace-device", ['date' => '2024-06-01', 'old_final_counter' => 410, 'serial' => $long]);
        self::assertSame(['errors.common.tooLong', 'serial'], [$r['code'], $r['detail']['field'] ?? null], json_encode($r));
        $r = $this->call('POST', "/api/utility/gas/meters/{$ok['data']['id']}/replace-device", ['date' => '2024-06-01', 'old_final_counter' => 410, 'new_device' => ['serial' => $long]]);
        self::assertSame(['errors.common.tooLong', 'serial'], [$r['code'], $r['detail']['field'] ?? null], json_encode($r));
        self::assertCount(1, array_values(array_filter((array)$this->json('GET', '/api/utility/gas/meters'), fn($m) => $m['id'] === $ok['data']['id']))[0]['devices'], 'kein Tausch');
    }

    /** Ein kleines PDF (eigene 80 Bytes), roh und als Formular. */
    private static function pdf(): string
    {
        return "%PDF-1.4\n% k4b Beispiel\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n";
    }

    /** @return array{status:int, code:?string, detail:mixed, data:mixed} */
    private function form(string $query, string $field, string $file, string $bytes): array
    {
        $b = 'k4bGrenze' . bin2hex(random_bytes(6));
        $body = "--$b\r\nContent-Disposition: form-data; name=\"$field\"; filename=\"$file\"\r\nContent-Type: application/pdf\r\n\r\n$bytes\r\n--$b--\r\n";
        $r = $this->request('POST', "/api/attachments$query", ["Content-Type: multipart/form-data; boundary=$b", "Origin: {$this->base}", 'X-ET-Language: de'], $body);
        $j = json_decode($r['body'], true);
        return ['status' => $r['status'], 'code' => $j['code'] ?? null, 'detail' => $j['detail'] ?? null, 'data' => $j['data'] ?? null];
    }

    public function testAReceiptArrivesAsAFormTooAndAnUnknownKindIs400(): void
    {
        $this->startServer();
        $pdf = self::pdf();
        $r = $this->form('?kind=bill_pdf', 'file', 'rechnung-maerz.pdf', $pdf);
        self::assertSame(201, $r['status'], json_encode($r));
        self::assertSame(['bill_pdf', 'application/pdf', strlen($pdf), 'rechnung-maerz.pdf'],
            [$r['data']['kind'], $r['data']['mime'], $r['data']['size'], $r['data']['original_name'] ?? null]);
        // ein anderer Feldname: die einzige Datei des Formulars; &name= geht vor
        $r = $this->form('?kind=statement_pdf&name=nebenkosten.pdf', 'beleg', 'scan.pdf', $pdf);
        self::assertSame([201, 'nebenkosten.pdf'], [$r['status'], $r['data']['original_name'] ?? null], json_encode($r));
        // roh wie bisher; ohne Art „other“
        $raw = $this->request('POST', '/api/attachments', ['Content-Type: application/pdf', "Origin: {$this->base}"], $pdf);
        self::assertSame([201, 'other'], [$raw['status'], json_decode($raw['body'], true)['data']['kind'] ?? null], $raw['body']);
        // unbekannte Art: 400 mit Feld und den erlaubten Arten, roh und als Formular, und nichts gespeichert
        $before = count((array)$this->json('GET', '/api/attachments')['attachments']);
        $raw = $this->request('POST', '/api/attachments?kind=rechnung', ['Content-Type: application/pdf', "Origin: {$this->base}", 'X-ET-Language: de'], $pdf);
        $j = json_decode($raw['body'], true);
        self::assertSame([400, 'errors.common.valueInvalid', 'kind', 'rechnung'], [$raw['status'], $j['code'] ?? null, $j['detail']['field'] ?? null, $j['detail']['value'] ?? null], $raw['body']);
        self::assertStringContainsString('bill_pdf', (string)($j['detail']['allowed'] ?? ''));
        $r = $this->form('?kind=foto', 'file', 'x.pdf', $pdf);
        self::assertSame([400, 'errors.common.valueInvalid'], [$r['status'], $r['code']], json_encode($r));
        self::assertCount($before, (array)$this->json('GET', '/api/attachments')['attachments'], 'nichts gespeichert');
        // ein Formular ohne Datei ist ein falscher Inhalt, nicht „zu groß“
        $b = 'k4bLeer';
        $r = $this->request('POST', '/api/attachments?kind=bill_pdf', ["Content-Type: multipart/form-data; boundary=$b", "Origin: {$this->base}"],
            "--$b\r\nContent-Disposition: form-data; name=\"note\"\r\n\r\nkein Beleg\r\n--$b--\r\n");
        self::assertSame([400, 'errors.attachment.type'], [$r['status'], json_decode($r['body'], true)['code'] ?? null], $r['body']);
    }

    public function testWithoutWriteAccessAndWithoutAStoredIdentifierReadingStays200(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) self::markTestSkipped('als root greift der Schreibschutz nicht');
        $this->startServer();
        // ein Ordner aus einer älteren Version, der die Kennung nie brauchte
        @unlink($this->dataDir . '/instance.json');
        $dirs = [$this->dataDir];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dataDir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST) as $f) {
            if ($f->isDir()) $dirs[] = (string)$f->getPathname();
        }
        foreach ($dirs as $d) { chmod($d, 0555); $this->locked[] = $d; }
        clearstatcache();

        $ids = [];
        foreach (['/api/summary', '/api/todo', '/api/calendar.ics', '/api/summary'] as $p) {
            $r = $this->request('GET', $p, ['X-ET-Language: de']);
            self::assertSame(200, $r['status'], "$p: {$r['body']}");
            if ($p === '/api/calendar.ics') { self::assertStringContainsString('BEGIN:VCALENDAR', $r['body']); continue; }
            $ids[] = (string)(json_decode($r['body'], true)['data']['instance_id'] ?? '');
        }
        self::assertMatchesRegularExpression('/^et_[0-9a-f]{16}$/', $ids[0]);
        self::assertSame([$ids[0], $ids[0], $ids[0]], $ids, 'dieselbe Kennung bei jeder Anfrage');
        self::assertFileDoesNotExist($this->dataDir . '/instance.json');
        // Schreibwege bleiben 503 mit Meldung
        $r = $this->call('PATCH', '/api/settings', ['dashboard_months' => 24]);
        self::assertSame([503, 'errors.storage.notWritable'], [$r['status'], $r['code']]);

        // wieder beschreibbar: dieselbe Kennung wird gespeichert (eine Integration behält ihre Sensoren)
        foreach (array_reverse($this->locked) as $d) @chmod($d, 0755);
        $this->locked = [];
        clearstatcache();
        $after = (string)($this->json('GET', '/api/summary')['instance_id'] ?? '');
        self::assertSame($ids[0], $after);
        self::assertSame($ids[0], json_decode((string)file_get_contents($this->dataDir . '/instance.json'), true)['instance_id'] ?? null);
    }

    /** Temperaturen lesen das Komma wie jede Zahl der Schreibwege (bisher war „4,2“ hier 400, an der Wohnfläche 4,2). */
    public function testTemperaturesReadTheCommaLikeEveryNumber(): void
    {
        $this->startServer();
        $r = $this->call('POST', '/api/temperatures', ['date' => '2025-01-14', 'avg' => '-3,5', 'min' => '-8,25', 'max' => '1,0']);
        self::assertSame(200, $r['status'], json_encode($r));
        $t = (array)$this->json('GET', '/api/temperatures');
        self::assertSame([-3.5, -8.25, 1.0], [(float)$t['2025-01-14']['avg'], (float)$t['2025-01-14']['min'], (float)$t['2025-01-14']['max']], json_encode($t['2025-01-14'] ?? null));
        $r = $this->call('POST', '/api/temperatures', ['date' => '2025-01-15', 'avg' => '1.003,5', 'min' => 0, 'max' => 2]);
        self::assertSame([400, 'errors.temperature.valuesMissing'], [$r['status'], $r['code']], 'mehrdeutig');
        $r = $this->call('POST', '/api/temperatures', ['date' => '2025-01-15', 'avg' => '61,5', 'min' => 0, 'max' => 2]);
        self::assertSame([400, 'errors.common.valueInvalid', 'avg'], [$r['status'], $r['code'], $r['detail']['field'] ?? null], json_encode($r));
    }

    /** Eine neue Installation legt die Kennung beim Erststart an (zufällig), nicht erst beim ersten Lesen. */
    public function testAFreshInstallationStoresItsIdentifierAtTheFirstStart(): void
    {
        $this->startServer();
        $stored = json_decode((string)@file_get_contents($this->dataDir . '/instance.json'), true);
        self::assertMatchesRegularExpression('/^et_[0-9a-f]{16}$/', (string)($stored['instance_id'] ?? ''));
        self::assertSame($stored['instance_id'], $this->json('GET', '/api/summary')['instance_id'] ?? null);
    }
}
