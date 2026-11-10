<?php
declare(strict_types=1);

namespace Energietracker\Tests\Http;

use Energietracker\Tests\Support\HttpServerTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * v3.12.0 — Eingabefehler über HTTP: falscher Typ → 400 mit Code (nie 500), kaputtes JSON
 * als solches, ein Stapel ohne Liste beim Ingest, unbekannte Datensätze beim Löschen → 404,
 * und ein gespeicherter Stand im Jahr 0001 legt keine Ansicht lahm.
 */
#[CoversNothing]
final class InputErrorsEndpointTest extends HttpServerTestCase
{
    /** @return array{status:int, code:?string} */
    private function call(string $method, string $path, ?string $raw, string $type = 'application/json'): array
    {
        $r = $this->request($method, $path, ["Content-Type: $type", "Origin: {$this->base}", 'X-ET-Language: de'], $raw);
        $j = json_decode($r['body'], true);
        return ['status' => $r['status'], 'code' => is_array($j) ? ($j['code'] ?? null) : null];
    }

    private function id(string $path): string
    {
        $d = $this->json('GET', $path);
        return (string)($d[0]['id'] ?? '');
    }

    public function testAListOrObjectInsteadOfTextIs400OnEveryWriteRoute(): void
    {
        $this->startServer();
        $gas = $this->id('/api/utility/gas/meters');
        $strom = $this->id('/api/utility/strom/meters');
        $this->call('POST', '/api/utility/gas/readings', json_encode(['meter_id' => $gas, 'date' => '2025-01-01', 'counter' => 1]));
        $reading = $this->id('/api/utility/gas/readings');
        $reminder = (string)($this->json('POST', '/api/reminders', ['title' => 'Wartung', 'next_due' => '2026-12-01'], ["Origin: {$this->base}"])['id'] ?? '');
        $cases = [
            ['POST', '/api/utility/gas/readings', ['meter_id' => $gas, 'date' => '2025-02-14', 'counter' => 2], ['date', 'note', 'meter_id', 'reported_on', 'client_ref']],
            ['PATCH', "/api/utility/gas/readings/$reading", ['note' => 'n'], ['note', 'date', 'meter_id']],
            ['POST', '/api/utility/gas/meters', ['name' => 'Neu', 'installed_on' => '2025-01-01'], ['name', 'installed_on', 'notes', 'role', 'obis', 'capture', 'external_id']],
            ['PATCH', "/api/utility/gas/meters/$gas", ['name' => 'Gas'], ['name', 'notes', 'role', 'obis', 'hdd_base_temp', 'parent_meter_id', 'malo_id']],
            ['POST', '/api/tenancies', ['start' => '2025-01-01', 'label' => 'Wohnung'], ['label', 'notes', 'billing_anchor', 'co2_restriction']],
            ['POST', "/api/utility/gas/meters/$gas/import-series?dry_run=1", ['csv' => "a;b\n"], ['csv']],
            ['POST', "/api/utility/strom/meters/$strom/import-evcc?dry_run=1", ['csv' => "a;b\n"], ['csv', 'counters', 'loadpoint']],
            ['POST', "/api/utility/gas/meters/$gas/replace-device", ['date' => '2026-10-01', 'old_final_counter' => 5, 'new_initial_counter' => 0], ['date']],
            ['POST', '/api/utility/gas/meter-groups', ['name' => 'G'], ['name']],
            ['POST', '/api/utility/gas/periods', ['meter_id' => $gas, 'from' => '2025-01-01', 'to' => '2025-01-31', 'value' => 1], ['meter_id']],
            ['POST', '/api/utility/gas/bills', ['meter_id' => $gas, 'period_from' => '2024-01-01', 'period_to' => '2024-12-31'], ['meter_id', 'period_from', 'period_to']],
            ['POST', '/api/utility/gas/contracts', ['meter_id' => $gas, 'provider' => 'P', 'start' => '2030-01-01',
                'working_prices' => [['from' => '2030-01-01', 'ct_per_kwh' => 9]]], ['meter_id', 'provider', 'notice_mode', 'min_term_end', 'price_guarantee_until']],
            ['POST', '/api/temperatures', ['date' => '2025-01-05', 'avg' => 1, 'min' => 0, 'max' => 2], ['date']],
            ['POST', '/api/reminders', ['title' => 'T', 'next_due' => '2026-12-01'], ['title', 'next_due']],
            ['PATCH', "/api/reminders/$reminder", ['title' => 'T'], ['title', 'next_due']],
            ['POST', '/api/metering-concept', ['concept' => 'single', 'tou' => 'none', 'slots' => ['main' => ['meter_id' => $strom]]], ['concept', 'tou']],
            ['POST', '/api/ingest', ['utility' => 'strom', 'meter' => $strom, 'value' => 99999], ['utility', 'meter', 'timestamp', 'date']],
        ];
        $seen = [];
        foreach ($cases as [$m, $path, $base, $fields]) {
            foreach ($fields as $f) {
                foreach ([['x'], ['a' => 1]] as $v) {
                    $r = $this->call($m, $path, json_encode([$f => $v] + $base));
                    $seen[] = "$m $path $f " . json_encode($v) . " → {$r['status']} {$r['code']}";
                    self::assertSame(400, $r['status'], end($seen));
                    self::assertSame('errors.common.textExpected', $r['code'], end($seen));
                }
            }
        }
        // eine Zahl als Zähler-ID ist kein Absturz (bisher TypeError im Vertrag)
        $r = $this->call('POST', '/api/utility/gas/contracts', json_encode(['meter_id' => 5, 'provider' => 'P', 'start' => '2030-01-01',
            'working_prices' => [['from' => '2030-01-01', 'ct_per_kwh' => 9]]]));
        self::assertSame([400, 'errors.common.meterNotFound'], [$r['status'], $r['code']]);
    }

    public function testBrokenJsonIsReportedAsBrokenJson(): void
    {
        $this->startServer();
        $gas = $this->id('/api/utility/gas/meters');
        foreach ([['POST', '/api/reminders'], ['PATCH', "/api/utility/gas/meters/$gas"], ['POST', '/api/ingest'], ['POST', '/api/utility/gas/readings']] as [$m, $p]) {
            $r = $this->call($m, $p, '{"title":"x",');
            self::assertSame([400, 'errors.http.bodyInvalid'], [$r['status'], $r['code']], "$m $p");
        }
        // ein einzelner Wert ist auch kein Körper der API
        self::assertSame([400, 'errors.http.bodyInvalid'], array_values($this->call('POST', '/api/reminders', '"x"')));
        // rohe Körper (CSV) mit eigenem Typ sind nicht betroffen
        $r = $this->call('POST', "/api/utility/gas/meters/$gas/readings/import-csv?dry_run=1", "datum;zählerstand\n01.02.2025;10\n", 'text/plain; charset=utf-8');
        self::assertSame(200, $r['status']);
        // ein Stapel, dessen readings keine Liste ist, ist ein kaputter Stapel (bisher „unbekannte Verbrauchsart“)
        self::assertSame([400, 'errors.ingest.bodyInvalid'], array_values($this->call('POST', '/api/ingest', '{"readings":"x"}')));
    }

    /**
     * Ein gespeicherter Preis von 10³⁰⁸ ct (der Schreibweg und der Backup-Import nehmen ihn seit v3.12.0 nicht
     * mehr an) macht die Kosten unendlich. Die Antwort war dann 200 mit leerem Körper, bei `/api/summary`
     * sogar mit fünf Minuten Zwischenspeicher; jetzt ein ehrlicher Fehler mit ID, ohne Cache-Kopfzeile.
     */
    public function testAResponseThatIsNoJsonIsAnErrorNotAnEmpty200(): void
    {
        $this->startServer();
        $gas = $this->id('/api/utility/gas/meters');
        $meters = json_decode((string)file_get_contents("$this->dataDir/gas/meters.json"), true);
        $dev = (string)$meters[0]['devices'][0]['id'];
        $meters[0]['devices'][0]['installed_on'] = '2024-01-01';
        file_put_contents("$this->dataDir/gas/meters.json", json_encode($meters));
        file_put_contents("$this->dataDir/gas/readings.json", json_encode([
            ['id' => 'r1', 'meter_id' => $gas, 'device_id' => $dev, 'date' => '2024-02-01', 'counter' => 0, 'price_cents' => null, 'note' => '', 'is_estimated' => false, 'is_future' => false],
            ['id' => 'r2', 'meter_id' => $gas, 'device_id' => $dev, 'date' => '2024-03-01', 'counter' => 500, 'price_cents' => null, 'note' => '', 'is_estimated' => false, 'is_future' => false],
        ]));
        file_put_contents("$this->dataDir/gas/contracts.json", json_encode([
            ['id' => 'c1', 'meter_id' => $gas, 'provider' => 'P', 'start' => '2024-01-01', 'end' => null, 'is_shadow' => false,
             'working_prices' => [['from' => '2024-01-01', 'ct_per_kwh' => 1e308]], 'base_prices' => [], 'advance_payments' => [['from' => '2024-01-01', 'amount_eur' => 50]],
             'bonuses' => [], 'special_payments' => []],
        ]));
        foreach (["/api/utility/gas/meters/$gas/consumption", '/api/summary'] as $p) {
            $r = $this->request('GET', $p, ['X-ET-Language: de']);
            $j = json_decode($r['body'], true);
            self::assertSame(500, $r['status'], $p);
            self::assertSame('errors.http.internal', $j['code'] ?? null, $p);
            self::assertMatchesRegularExpression('/^err_[0-9a-f]{8}$/', (string)($j['error_id'] ?? ''), $p);
            self::assertStringNotContainsString('max-age=300', (string)($r['headers']['cache-control'] ?? ''), "$p: ein Fehler wird nicht zwischengespeichert");
        }
    }

    /**
     * Ein gespeicherter Stand über der Grenze der Eingabe (10¹²) rechnete bisher still Billionen kWh. Jetzt
     * bleibt er draußen, mit Warnung, wie ein Stand im Jahr 0001.
     */
    public function testAStoredReadingAboveTheLimitIsLeftOutWithAWarning(): void
    {
        $this->startServer();
        $gas = $this->id('/api/utility/gas/meters');
        $meters = json_decode((string)file_get_contents("$this->dataDir/gas/meters.json"), true);
        $dev = (string)$meters[0]['devices'][0]['id'];
        $meters[0]['devices'][0]['installed_on'] = '2024-01-01';
        file_put_contents("$this->dataDir/gas/meters.json", json_encode($meters));
        $rows = [];
        foreach ([['2024-02-01', 100], ['2024-03-01', 2e12], ['2024-04-01', 300]] as $i => [$d, $c]) {
            $rows[] = ['id' => "r$i", 'meter_id' => $gas, 'device_id' => $dev, 'date' => $d, 'counter' => $c, 'price_cents' => null, 'note' => '', 'is_estimated' => false, 'is_future' => false];
        }
        file_put_contents("$this->dataDir/gas/readings.json", json_encode($rows));
        $cons = $this->json('GET', "/api/utility/gas/meters/$gas/consumption");
        self::assertContains('counter_out_of_range', array_column($cons['warnings'] ?? [], 'type'));
        // 100 → 300 m³ über zwei Monate; nie Billionen
        self::assertLessThan(1e6, array_sum(array_column($cons['monthly'] ?? [], 'kwh')));
    }

    public function testDeletingWhatDoesNotExistIs404(): void
    {
        $this->startServer();
        self::assertSame([404, 'errors.meter.notFound'], array_values($this->call('DELETE', '/api/utility/gas/meters/m_gas_gibtsnicht', null)));
        self::assertSame([404, 'errors.temperature.notFound'], array_values($this->call('DELETE', '/api/temperatures/1999-01-01', null)));
        self::assertSame([404, 'errors.temperature.notFound'], array_values($this->call('DELETE', '/api/temperatures/kein-datum', null)));
    }

    /**
     * Jahr 0001: die Eingabe wird abgewiesen, und ein schon gespeicherter Stand (Altbestand) legt
     * Übersicht, „Zu tun“, Agenda und Bericht nicht mehr mit 500 lahm.
     */
    public function testAReadingIn0001IsRejectedAndAStoredOneBreaksNoView(): void
    {
        $this->startServer();
        $gas = $this->id('/api/utility/gas/meters');
        $r = $this->call('POST', '/api/utility/gas/readings', json_encode(['meter_id' => $gas, 'date' => '0001-01-01', 'counter' => 5]));
        self::assertSame([400, 'errors.common.dateOutOfRange'], array_values($r));

        // Altbestand direkt in die Daten (wie aus einer älteren Version oder einem Restore)
        $meters = json_decode((string)file_get_contents("$this->dataDir/gas/meters.json"), true);
        $meters[0]['devices'][0]['installed_on'] = '0001-01-01';
        file_put_contents("$this->dataDir/gas/meters.json", json_encode($meters));
        $dev = (string)$meters[0]['devices'][0]['id'];
        $rows = [];
        foreach ([['0001-01-01', 3], ['2024-04-01', 20], ['2024-07-01', 55], ['2024-10-01', 90]] as $i => [$d, $c]) {
            $rows[] = ['id' => "r_alt_$i", 'meter_id' => $gas, 'device_id' => $dev, 'date' => $d, 'counter' => $c,
                       'price_cents' => null, 'note' => '', 'is_estimated' => false, 'is_future' => false];
        }
        file_put_contents("$this->dataDir/gas/readings.json", json_encode($rows));
        foreach (['/api/summary', '/api/todo', '/api/agenda', '/api/recommendations', '/api/reports/yearly?year=2024',
                  '/api/benchmarks/efficiency', "/api/utility/gas/meters/$gas/consumption", '/api/export/gas/readings.csv'] as $p) {
            $res = $this->request('GET', $p, ['X-ET-Language: de']);
            self::assertSame(200, $res['status'], $p);
        }
        $cons = $this->json('GET', "/api/utility/gas/meters/$gas/consumption");
        self::assertSame(['out_of_range'], array_column($cons['warnings'] ?? [], 'type'), 'der Stand im Jahr 0001 steht als Warnung da');
        self::assertSame('2024-04', $cons['monthly'][0]['ym'] ?? null, 'die Monate beginnen mit dem ersten echten Stand');
    }
}
