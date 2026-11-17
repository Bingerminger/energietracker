<?php
declare(strict_types=1);

namespace Energietracker\Tests\Http;

use Energietracker\Tests\Support\HttpServerTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * v3.13.0 — Wahrheitswerte gelten an jedem Schreibweg nach einer Regel (Support\Input::flag): ja/nein,
 * true/false, 1/0, on/off (Groß- und Kleinschreibung egal), fehlt oder leer = nein, alles andere 400
 * `errors.common.boolExpected` mit dem Feld. Bisher lasen einige Wege selbst: `(bool)"nein"` ersetzte beim
 * Laden der Beispieldaten den Haushalt, `!empty("false")` kürzte die CO₂-Erstattung und zeigte PV in der
 * Vorschau, „Ja“ war am Wärmepumpenzähler und am Netzentgelt still nein, `?dry_run=true` spielte wirklich ein.
 *
 * Die Liste unten ist die Liste der Eingänge mit Schaltern außerhalb der Dienste, die schon v3.12 prüfte
 * (Ablesung, Vertrag, Zähler `active`, Lieferung, Zeitraum, Rechnung, Termin, Einstellungen): Ein neuer
 * Schalter gehört hier mit hinein.
 */
#[CoversNothing]
final class BoolRuleEndpointTest extends HttpServerTestCase
{
    /** @return array{status:int, code:?string, data:mixed} */
    private function call(string $method, string $path, mixed $payload = null): array
    {
        $r = $this->request($method, $path, ['Content-Type: application/json', "Origin: {$this->base}", 'X-ET-Language: de'],
            $payload === null ? null : json_encode($payload));
        $j = json_decode($r['body'], true);
        return ['status' => $r['status'], 'code' => is_array($j) ? ($j['code'] ?? null) : null, 'data' => is_array($j) ? ($j['data'] ?? null) : null];
    }

    private function boolError(array $r, string $field, string $what): void
    {
        self::assertSame(400, $r['status'], "$what: " . json_encode($r));
        self::assertSame('errors.common.boolExpected', $r['code'], "$what: " . json_encode($r));
    }

    public function testForceNoNeverReplacesTheHousehold(): void
    {
        $this->startServer();
        $gas = (string)$this->json('GET', '/api/utility/gas/meters')[0]['id'];
        // eigener Stand: Der Haushalt hat Daten, ein Laden ohne ausdrückliches Ja muss abweisen
        $made = $this->call('POST', '/api/utility/gas/readings', ['meter_id' => $gas, 'date' => '2024-03-07', 'counter' => 4711.5]);
        self::assertSame(201, $made['status'], json_encode($made));
        foreach (['nein', 'Nein', 'false', '0', 'off', 0, false, ''] as $no) {
            $r = $this->call('POST', '/api/demo/import', ['force' => $no]);
            self::assertSame(400, $r['status'], 'force ' . json_encode($no) . ': ' . json_encode($r));
            self::assertSame('errors.demo.dataExists', $r['code'], 'force ' . json_encode($no));
        }
        foreach (['vielleicht', ['p1'], 2] as $junk) {
            $this->boolError($this->call('POST', '/api/demo/import', ['force' => $junk]), 'force', 'force ' . json_encode($junk));
        }
        // eine Liste als Persona ist 400, nie 500
        $r = $this->call('POST', '/api/demo/import', ['force' => true, 'persona' => ['p1']]);
        self::assertSame([400, 'errors.common.textExpected'], [$r['status'], $r['code']], json_encode($r));
        // der eigene Stand ist noch da, nichts ersetzt
        $dates = array_column((array)$this->json('GET', "/api/utility/gas/readings?meter_id=$gas"), 'counter', 'date');
        self::assertSame(4711.5, (float)($dates['2024-03-07'] ?? 0));
        self::assertCount(1, $this->json('GET', '/api/utility/gas/meters'), 'kein Zähler der Beispieldaten');
    }

    public function testEverySwitchFollowsTheOneRule(): void
    {
        $this->startServer();
        $strom = (string)$this->json('GET', '/api/utility/strom/meters')[0]['id'];

        // Mietverhältnis: Kürzung der CO₂-Erstattung (eigene Geräte)
        foreach (['false' => false, 'nein' => false, 'Ja' => true, 'ON' => true] as $in => $want) {
            $t = $this->call('POST', '/api/tenancies', ['start' => '2024-02-01', 'label' => "W $in", 'co2_own_appliances' => $in]);
            self::assertSame(201, $t['status'], json_encode($t));
            self::assertSame($want, $t['data']['co2_own_appliances'], "co2_own_appliances „{$in}“");
        }
        $this->boolError($this->call('POST', '/api/tenancies', ['start' => '2024-02-01', 'label' => 'X', 'co2_own_appliances' => 'unklar']), 'co2_own_appliances', 'Mietverhältnis');

        // Abrechnung des Mietverhältnisses: Preise und Vorauszahlung übernehmen, geprüft BEVOR geschrieben wird
        $tid = (string)$this->json('GET', '/api/tenancies')[0]['id'];
        $before = count((array)$this->json('GET', "/api/tenancies/$tid/statements"));
        foreach (['apply_prices', 'apply_prepayment'] as $f) {
            $this->boolError($this->call('POST', "/api/tenancies/$tid/statements",
                ['period_from' => '2024-02-01', 'period_to' => '2024-12-31', 'total_cost_eur' => 900, 'prepaid_eur' => 880, $f => 'unklar']), $f, $f);
        }
        self::assertCount($before, (array)$this->json('GET', "/api/tenancies/$tid/statements"), 'abgewiesen heißt: keine Abrechnung angelegt');

        // Vorschau des Messkonzepts: „nein“ ist dieselbe Vorschau wie false, „ja“ wie true
        $preview = fn(mixed $pv) => $this->call('POST', '/api/metering-concept/preview', ['concept' => 'single', 'tou' => 'none', 'pv' => $pv]);
        self::assertSame($preview(false)['data'], $preview('nein')['data'], 'pv „nein“');
        self::assertSame($preview(true)['data'], $preview('ja')['data'], 'pv „ja“');
        self::assertNotSame($preview(false)['data'], $preview(true)['data'], 'die Vorschau unterscheidet PV überhaupt');
        $this->boolError($preview('vielleicht'), 'pv', 'Messkonzept pv');
        $this->boolError($this->call('POST', '/api/metering-concept/preview', ['concept' => 'single', 'tou' => 'none', 'pv' => true, 'battery' => 'unklar']), 'battery', 'Messkonzept battery');

        // Wärmepumpe am Stromzähler: „Ja“ ist ja
        $r = $this->call('PATCH', "/api/utility/strom/meters/$strom", ['heat_source' => 'Ja']);
        self::assertSame(200, $r['status'], json_encode($r));
        self::assertTrue($r['data']['heat_source'] ?? false, 'heat_source „Ja“');
        $r = $this->call('PATCH', "/api/utility/strom/meters/$strom", ['heat_source' => 'Nein']);
        self::assertArrayNotHasKey('heat_source', (array)$r['data'], 'heat_source „Nein“');
        $this->boolError($this->call('PATCH', "/api/utility/strom/meters/$strom", ['heat_source' => 'unklar']), 'heat_source', 'heat_source');

        // Netzentgelt nach Zeitfenster: „netto“ als Schalter
        $grid = fn(mixed $net) => $this->call('POST', '/api/utility/strom/contracts', ['meter_id' => $strom, 'provider' => 'Netz', 'start' => '2031-01-01',
            'working_prices' => [['from' => '2031-01-01', 'ct_per_kwh' => 21]], 'grid_tou' => ['standard_ct' => 7.5, 'net' => $net]]);
        $r = $grid('Ja');
        self::assertSame(201, $r['status'], json_encode($r));
        self::assertTrue($r['data']['grid_tou']['net'] ?? false, 'grid_tou.net „Ja“');
        $this->boolError($grid('unklar'), 'grid_tou.net', 'grid_tou.net');

        // „gekündigt“ beim Abhaken eines Kündigungstermins: geprüft, bevor der Termin gesucht wird
        $this->boolError($this->call('POST', '/api/agenda/done', ['uid' => 'gibt-es-nicht', 'cancelled' => 'unklar']), 'cancelled', 'agenda cancelled');
    }

    public function testDryRunInTheAddressFollowsTheOneRule(): void
    {
        $this->startServer();
        $gas = (string)$this->json('GET', '/api/utility/gas/meters')[0]['id'];
        $csv = "Datum;Zaehlerstand\n2023-05-02;120,5\n";
        $import = fn(string $q) => $this->request('POST', "/api/utility/gas/meters/$gas/readings/import-csv$q",
            ['Content-Type: text/plain', "Origin: {$this->base}"], $csv);
        foreach (['?dry_run=true', '?dry_run=ja', '?dry_run=1', '?dry_run=ON'] as $q) {
            $r = $import($q);
            self::assertSame(200, $r['status'], "$q: {$r['body']}");
            self::assertSame([], (array)$this->json('GET', "/api/utility/gas/readings?meter_id=$gas"), "$q ist ein Trockenlauf");
        }
        $r = $import('?dry_run=vielleicht');
        self::assertSame(400, $r['status'], $r['body']);
        self::assertSame('errors.common.boolExpected', json_decode($r['body'], true)['code'] ?? null);
        self::assertSame([], (array)$this->json('GET', "/api/utility/gas/readings?meter_id=$gas"), 'Unlesbares spielt nicht ein');
        // ohne Schalter: wirklich eingespielt (die Probe trifft also den Import)
        self::assertSame(200, $import('')['status']);
        self::assertCount(1, (array)$this->json('GET', "/api/utility/gas/readings?meter_id=$gas"));
    }
}
