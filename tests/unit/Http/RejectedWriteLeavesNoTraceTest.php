<?php
declare(strict_types=1);

namespace Energietracker\Tests\Http;

use Energietracker\Tests\Support\HttpServerTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * v3.13.0 — Erst prüfen, dann schreiben: Eine abgewiesene Anfrage (4xx) hinterlässt nichts im Datenordner.
 * Bisher schrieben einige Wege vor der letzten Prüfung:
 *   - der Ingest setzte bei einem Stand mit Zeitstempel den Stützwert und verteilte Viertelstunden, bevor die
 *     Prüfung des Stands warf (negativ, über 10¹²): Die Antwort war 400, die Viertelstunde trug die Differenz
 *     zum Störwert;
 *   - das Zusammenfassen zu einer Gruppe legte die Gruppe an, bevor es die Zähler prüfte;
 *   - Vertrag, Rechnung und Ablesung schrieben den Datensatz und verknüpften dann die Belege; ein unbekannter
 *     Beleg war 400, der Datensatz stand trotzdem da.
 *
 * Geprüft wird der ganze Datenordner Byte für Byte (ohne Sicherungen und Sperrdatei), je abgewiesener Anfrage.
 */
#[CoversNothing]
final class RejectedWriteLeavesNoTraceTest extends HttpServerTestCase
{
    /** @return array<string,string> relativer Pfad → sha1 */
    private function fingerprint(): array
    {
        $out = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dataDir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            $rel = substr((string)$f->getPathname(), strlen($this->dataDir) + 1);
            if (str_starts_with($rel, 'backups/') || str_ends_with($rel, '.lock') || str_contains($rel, '.tmp.')) continue;
            $out[$rel] = sha1_file((string)$f->getPathname());
        }
        ksort($out);
        return $out;
    }

    /** @return array{status:int, code:?string, data:mixed} */
    private function call(string $method, string $path, mixed $payload): array
    {
        $r = $this->request($method, $path, ['Content-Type: application/json', "Origin: {$this->base}", 'X-ET-Language: de'], json_encode($payload));
        $j = json_decode($r['body'], true);
        return ['status' => $r['status'], 'code' => is_array($j) ? ($j['code'] ?? null) : null, 'data' => is_array($j) ? ($j['data'] ?? null) : null];
    }

    private function assertRejectedWithoutTrace(string $method, string $path, mixed $payload, string $what): void
    {
        $before = $this->fingerprint();
        $r = $this->call($method, $path, $payload);
        self::assertGreaterThanOrEqual(400, $r['status'], "$what: " . json_encode($r));
        self::assertLessThan(500, $r['status'], "$what: " . json_encode($r));
        $after = $this->fingerprint();
        $changed = array_keys(array_diff_assoc($after, $before) + array_diff_key($before, $after));
        self::assertSame([], $changed, "$what ({$r['status']} {$r['code']}) hat geschrieben: " . implode(', ', $changed));
    }

    public function testIngestWritesNoQuarterHourForARejectedReading(): void
    {
        $this->startServer();
        $m = (string)$this->call('POST', '/api/utility/strom/meters', ['name' => 'Werkbank', 'installed_on' => '2024-01-01'])['data']['id'];
        $push = fn(mixed $v, string $at) => $this->call('POST', '/api/ingest', ['utility' => 'strom', 'meter' => $m, 'value' => $v, 'timestamp' => $at]);
        self::assertSame(201, $push(2500, '2024-04-09T08:00:00+02:00')['status']);
        // Störwerte: negativ und über der Grenze eines Zählerstands — beide 400, beide ohne Spur
        $this->assertRejectedWithoutTrace('POST', '/api/ingest', ['utility' => 'strom', 'meter' => $m, 'value' => -3, 'timestamp' => '2024-04-09T08:15:00+02:00'], 'Ingest negativ');
        $this->assertRejectedWithoutTrace('POST', '/api/ingest', ['utility' => 'strom', 'meter' => $m, 'value' => 5e12, 'timestamp' => '2024-04-09T08:15:00+02:00'], 'Ingest über 10¹²');
        // der nächste gültige Stand verteilt die echte Menge: 2 kWh über 08:00–08:30 (zwei Viertelstunden zu je 1 kWh)
        $r = $push(2502, '2024-04-09T08:30:00+02:00');
        self::assertSame(200, $r['status'], json_encode($r));
        $files = glob($this->dataDir . '/intervals/*.json') ?: [];
        self::assertCount(1, $files, 'eine Reihe');
        $values = array_values(array_filter((array)(json_decode((string)file_get_contents($files[0]), true)['values'] ?? []), fn($v) => $v !== null && $v != 0));
        self::assertSame([1.0, 1.0], array_map('floatval', $values), 'Viertelstunden mit Menge');
    }

    public function testEveryRejectedWriteLeavesTheDataUntouched(): void
    {
        $this->startServer();
        $gas = (string)$this->json('GET', '/api/utility/gas/meters')[0]['id'];
        $second = (string)$this->call('POST', '/api/utility/gas/meters', ['name' => 'Nebenhaus', 'installed_on' => '2024-01-01'])['data']['id'];
        $contract = (string)$this->call('POST', '/api/utility/gas/contracts', ['meter_id' => $gas, 'provider' => 'Versorger Nord', 'start' => '2024-01-01',
            'working_prices' => [['from' => '2024-01-01', 'ct_per_kwh' => 11]]])['data']['id'];
        $tenancy = (string)$this->call('POST', '/api/tenancies', ['start' => '2024-01-01', 'label' => 'Erdgeschoss'])['data']['id'];
        $bill = (string)$this->call('POST', '/api/utility/gas/bills', ['meter_id' => $gas, 'period_from' => '2023-01-01', 'period_to' => '2023-12-31'])['data']['id'];
        $statement = $this->call('POST', "/api/tenancies/$tenancy/statements", ['period_from' => '2023-01-01', 'period_to' => '2023-12-31', 'total_cost_eur' => 1, 'prepaid_eur' => 1]);
        self::assertSame(201, $statement['status'], json_encode($statement));
        $statement = (string)$statement['data']['id'];
        $unknownAtt = 'att_' . str_repeat('0', 16);
        // ein vorhandener Beleg neben einem unbekannten: auch der vorhandene wird nicht verknüpft
        $up = $this->request('POST', '/api/attachments?kind=other', ["Origin: {$this->base}", 'Content-Type: image/jpeg'], \Energietracker\Tests\Services\AttachmentServiceTest::JPEG);
        self::assertSame(201, $up['status'], $up['body']);
        $knownAtt = (string)(json_decode($up['body'], true)['data']['id'] ?? '');

        $cases = [
            ['POST', '/api/utility/gas/contracts', ['meter_id' => $gas, 'provider' => 'P', 'start' => '2030-01-01',
                'working_prices' => [['from' => '2030-01-01', 'ct_per_kwh' => 9]], 'attachment_ids' => [$knownAtt, $unknownAtt]], 'Vertrag mit vorhandenem und unbekanntem Beleg'],
            ['POST', '/api/utility/gas/bills', ['meter_id' => $gas, 'period_from' => '2024-01-01', 'period_to' => '2024-12-31', 'attachment_ids' => [$knownAtt, $unknownAtt]],
                'Rechnung mit vorhandenem und unbekanntem Beleg'],
            ['PATCH', "/api/utility/gas/bills/$bill", ['note' => 'neu', 'attachment_ids' => [$knownAtt, $unknownAtt]], 'Rechnung ändern mit vorhandenem und unbekanntem Beleg'],
            ['PATCH', "/api/utility/gas/contracts/$contract", ['notes' => 'neu', 'attachment_ids' => [$knownAtt, $unknownAtt]], 'Vertrag ändern mit vorhandenem und unbekanntem Beleg'],
            ['POST', "/api/tenancies/$tenancy/statements", ['period_from' => '2024-01-01', 'period_to' => '2024-12-31', 'total_cost_eur' => 1, 'prepaid_eur' => 1,
                'attachment_ids' => [$knownAtt, $unknownAtt]], 'Mietabrechnung mit vorhandenem und unbekanntem Beleg'],
            ['PATCH', "/api/tenancies/$tenancy/statements/$statement", ['note' => 'neu', 'attachment_ids' => [$knownAtt, $unknownAtt]],
                'Mietabrechnung ändern mit vorhandenem und unbekanntem Beleg'],
            ['POST', '/api/utility/gas/meter-groups/merge', ['meter_ids' => ['m_gibt_es_nicht', $second], 'name' => 'Leer'], 'Gruppe mit unbekanntem Zähler'],
            ['POST', '/api/utility/gas/meter-groups/merge', ['meter_ids' => [['p1'], $second], 'name' => 'Leer'], 'Gruppe mit Liste als Kennung'],
            ['POST', '/api/utility/gas/contracts', ['meter_id' => $gas, 'provider' => 'P', 'start' => '2030-01-01',
                'working_prices' => [['from' => '2030-01-01', 'ct_per_kwh' => 9]], 'attachment_ids' => [$unknownAtt]], 'Vertrag mit unbekanntem Beleg'],
            ['PATCH', "/api/utility/gas/contracts/$contract", ['notes' => 'neu', 'attachment_ids' => [$unknownAtt]], 'Vertrag ändern mit unbekanntem Beleg'],
            ['POST', '/api/utility/gas/bills', ['meter_id' => $gas, 'period_from' => '2024-01-01', 'period_to' => '2024-12-31', 'attachment_ids' => [$unknownAtt]], 'Rechnung mit unbekanntem Beleg'],
            ['POST', '/api/utility/gas/readings', ['meter_id' => $gas, 'date' => '2024-06-01', 'counter' => 50, 'attachment_id' => $unknownAtt], 'Ablesung mit unbekanntem Beleg'],
            ['POST', "/api/tenancies/$tenancy/statements", ['period_from' => '2024-01-01', 'period_to' => '2024-12-31', 'total_cost_eur' => 1, 'prepaid_eur' => 1,
                'attachment_ids' => [$unknownAtt]], 'Mietabrechnung mit unbekanntem Beleg'],
            ['POST', "/api/tenancies/$tenancy/statements", ['period_from' => '2024-01-01', 'period_to' => '2024-12-31', 'apply_prices' => 'unklar'], 'Mietabrechnung mit unlesbarem Schalter'],
            ['POST', '/api/demo/import', ['force' => 'nein'], 'Beispieldaten mit force nein'],
            ['POST', '/api/utility/gas/readings', ['meter_id' => $gas, 'date' => '2024-06-01', 'counter' => -4], 'Ablesung negativ'],
            ['PATCH', "/api/utility/gas/meters/$gas", ['name' => 'Neu', 'baseline_events' => [['date' => ['p1'], 'label' => 'a']]], 'Zäsur mit Liste als Tag'],
        ];
        foreach ($cases as [$m, $p, $body, $what]) $this->assertRejectedWithoutTrace($m, $p, $body, $what);
        // die Gruppe gibt es nicht
        self::assertSame([], (array)$this->json('GET', '/api/utility/gas/meter-groups'));
    }
}
