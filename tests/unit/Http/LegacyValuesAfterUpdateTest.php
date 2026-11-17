<?php
declare(strict_types=1);

namespace Energietracker\Tests\Http;

use Energietracker\Tests\Support\HttpServerTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * v3.13.0 (K2d) — Update-Test: ein Datenordner im Format von 3.11/3.12 (Schema 1.8.0, ohne Migrationsschritt) mit
 * Werten, die ältere Versionen gespeichert haben und die heute abgewiesen würden. Bisher legten sie nach dem Update
 * Lesewege mit 500 lahm (ein Stand im Jahr 0150 erschöpfte beim Abrechnungsjahr den Speicher, ein Vertragsende als
 * Liste brach Verbrauch und Übersicht, `forecast_model: []` alle Ansichten, eine Heizgrenze von 10³⁰⁰ die Rechnung
 * nach dem Wetter, ein Zeitraum ab dem Jahr 0003 die Monatsrechnung), und ein Stand im Jahr 1200 rechnete achthundert
 * Abrechnungsjahre.
 *
 * Jetzt prüft dieselbe Regel wie auf den Schreibwegen (Support\FieldRules) den Bestand beim Lesen: Ein Datensatz mit
 * unbrauchbarem Pflichtfeld fällt aus der Rechnung, ein anderes Feld fällt weg, eine Einstellung gilt mit ihrer
 * Vorgabe. Die Dateien bleiben, wie sie sind. /api/health nennt die Funde als Feld `data_issues` und bleibt 200
 * (der HEALTHCHECK im Container kippt nicht auf „unhealthy“), die Diagnose listet sie.
 *
 * Alle GET-Routen kommen aus bootstrap.php (eine neue Route ist automatisch dabei); ausgelassen sind nur Routen mit
 * Kennungen, die dieser Haushalt nicht hat, und Abrufe nach außen.
 */
#[CoversNothing]
final class LegacyValuesAfterUpdateTest extends HttpServerTestCase
{
    private const SKIP = ['/api/geocode', '/api/update-check', '/api/inbox/cloud/models', '/api/inbox/local/check', '/api/docs/asset'];

    /** @return array{status:int, code:?string, data:mixed} */
    private function call(string $method, string $path, mixed $payload = null): array
    {
        $r = $this->request($method, $path, ['Content-Type: application/json', "Origin: {$this->base}", 'X-ET-Language: de'],
            $payload === null ? null : json_encode($payload));
        $j = json_decode($r['body'], true);
        return ['status' => $r['status'], 'code' => is_array($j) ? ($j['code'] ?? null) : null, 'data' => is_array($j) ? ($j['data'] ?? null) : null];
    }

    private function file(string $rel): array
    {
        return (array)json_decode((string)file_get_contents("{$this->dataDir}/$rel"), true);
    }

    private function put(string $rel, array $data): void
    {
        file_put_contents("{$this->dataDir}/$rel", json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    /** @return list<string> GET-Routen aus bootstrap.php, Platzhalter ersetzt */
    private function getRoutes(array $metersByUtility): array
    {
        preg_match_all("/\\\$r->get\\('([^']+)'/", (string)file_get_contents(dirname(__DIR__, 3) . '/src/bootstrap.php'), $m);
        $out = [];
        foreach ($m[1] as $route) {
            if (in_array($route, self::SKIP, true)) continue;
            $params = preg_match_all('/\{([a-z]+)\}/', $route, $pm) ? $pm[1] : [];
            if (array_diff($params, ['utility', 'id']) !== []) continue;
            if (in_array('id', $params, true) && !str_contains($route, '/meters/{id}')) continue;
            if ($params === []) { $out[] = $route; continue; }
            foreach ($metersByUtility as $u => $ids) {
                $r = str_replace('{utility}', $u, $route);
                if (!str_contains($r, '{id}')) { $out[] = $r; continue; }
                foreach ($ids as $id) $out[] = str_replace('{id}', $id, $r);
            }
        }
        return array_values(array_unique($out));
    }

    public function testValuesFromOlderVersionsBreakNoReadPathAndAreReported(): void
    {
        $this->startServer();
        // ── ein gewöhnlicher Haushalt über die API (so, wie 3.12 ihn speichert)
        $gas = (string)$this->json('GET', '/api/utility/gas/meters')[0]['id'];
        foreach (['2024-01-03' => 2000, '2024-06-04' => 2530, '2024-12-30' => 3180] as $d => $c) {
            self::assertSame(201, $this->call('POST', '/api/utility/gas/readings', ['meter_id' => $gas, 'date' => $d, 'counter' => $c])['status']);
        }
        $gasContract = (string)$this->call('POST', '/api/utility/gas/contracts', ['meter_id' => $gas, 'provider' => 'Gaswerk Muster', 'start' => '2024-01-01',
            'working_prices' => [['from' => '2024-01-01', 'ct_per_kwh' => 9.4]], 'base_prices' => [['from' => '2024-01-01', 'eur_per_month' => 12]],
            'advance_payments' => [['from' => '2024-01-01', 'amount_eur' => 95]]])['data']['id'];
        $strom = (string)$this->json('GET', '/api/utility/strom/meters')[0]['id'];
        foreach (['2024-01-03' => 41000, '2024-12-30' => 43900, '2025-09-01' => 46000] as $d => $c) {
            $this->call('POST', '/api/utility/strom/readings', ['meter_id' => $strom, 'date' => $d, 'counter' => $c]);
        }
        $this->call('POST', '/api/utility/strom/contracts', ['meter_id' => $strom, 'provider' => 'Strom Alt', 'start' => '2024-01-01', 'end' => '2025-07-31',
            'auto_renews' => false, 'working_prices' => [['from' => '2024-01-01', 'ct_per_kwh' => 31]]]);
        $successor = (string)$this->call('POST', '/api/utility/strom/contracts', ['meter_id' => $strom, 'provider' => 'Strom Neu', 'start' => '2025-08-01',
            'working_prices' => [['from' => '2025-08-01', 'ct_per_kwh' => 28]]])['data']['id'];
        $fw = (string)$this->call('POST', '/api/utility/fernwaerme/meters', ['name' => 'Übergabestation', 'installed_on' => '2024-01-01', 'capture' => 'period'])['data']['id'];
        self::assertSame(201, $this->call('POST', '/api/utility/fernwaerme/periods', ['meter_id' => $fw, 'from' => '2024-01-01', 'to' => '2024-12-31', 'value' => 9100])['status']);
        $tank = (string)$this->call('POST', '/api/utility/heizoel/meters', ['name' => 'Erdtank', 'capacity' => 5000, 'initial_stock' => 1800, 'installed_on' => '2024-01-01'])['data']['id'];
        $this->call('POST', '/api/utility/heizoel/deliveries', ['meter_id' => $tank, 'date' => '2024-09-10', 'quantity' => 2000, 'total_eur' => 2100]);
        $this->call('POST', '/api/reminders', ['title' => 'Wartung Therme', 'next_due' => '2027-03-01']);
        // ein zweiter Tank mit Lieferung, eine Gasrechnung mit Abschnitt des Brennwerts (für den Vergleich mit Altwerten daneben)
        $tank2 = (string)$this->call('POST', '/api/utility/heizoel/meters', ['name' => 'Kellertank', 'capacity' => 3000, 'initial_stock' => 600, 'installed_on' => '2024-01-01'])['data']['id'];
        self::assertSame(201, $this->call('POST', '/api/utility/heizoel/deliveries', ['meter_id' => $tank2, 'date' => '2024-11-05', 'quantity' => 1200, 'total_eur' => 1260])['status']);
        $bill = $this->call('POST', '/api/utility/gas/bills', ['meter_id' => $gas, 'period_from' => '2024-01-01', 'period_to' => '2024-12-31', 'issued_on' => '2025-01-20',
            'factors' => [['from' => '2024-01-01', 'to' => '2024-12-31', 'zustandszahl' => 0.97, 'brennwert' => 11.4]]]);
        self::assertSame(201, $bill['status'], json_encode($bill));
        $bill = (string)$bill['data']['id'];
        // Temperaturen 2024, damit die Heizgrenze wirkt
        $temps = [];
        for ($t = strtotime('2024-01-01'); $t <= strtotime('2024-12-31'); $t += 86400) {
            $doy = (int)date('z', $t);
            $avg = round(9 - 9 * cos(2 * M_PI * ($doy - 15) / 366), 1);
            $temps[date('Y-m-d', $t)] = ['avg' => $avg, 'min' => $avg - 4, 'max' => $avg + 4];
        }
        $this->put('temperatures.json', $temps);

        $cleanConsumption = $this->call('GET', "/api/utility/gas/meters/$gas/consumption")['data'];
        $cleanPeriods = $this->call('GET', "/api/utility/gas/meters/$gas/billing-periods")['data'];
        $cleanStrom = $this->call('GET', "/api/utility/strom/meters/$strom/consumption")['data'];
        $cleanTank = $this->call('GET', "/api/utility/heizoel/meters/$tank2/consumption")['data'];
        $cleanStock = $this->call('GET', "/api/utility/heizoel/meters/$tank2/stock-history")['data'];
        $cleanCheck = $this->call('GET', "/api/utility/gas/bills/$bill/check")['data'];
        self::assertNotEmpty($cleanStrom['monthly'] ?? null, 'Strom rechnet');
        self::assertNotNull($cleanCheck, 'Rechnungsprüfung rechnet');

        // ── Werte, die eine ältere Version gespeichert hat (direkt in die Dateien, wie damals ohne Prüfung)
        $r = $this->file('gas/readings.json');
        $firstReading = (string)$r[0]['id'];
        foreach (['0150-03-01', '1200-06-15', '1899-11-30'] as $i => $old) {
            $r[] = ['id' => "alt_$i", 'meter_id' => $gas, 'device_id' => $r[0]['device_id'], 'date' => $old, 'counter' => 0, 'note' => '', 'is_estimated' => false, 'is_future' => false];
        }
        // ein Tag als Liste an einer Ablesung von Home Assistant, eine Notiz als Objekt an einer gültigen Ablesung
        $r[] = ['id' => 'alt_ingest', 'meter_id' => $gas, 'device_id' => $r[0]['device_id'], 'date' => ['2024-03-03'], 'counter' => 2100, 'source' => 'ingest'];
        $r[1]['note'] = ['text' => 'alt'];
        $this->put('gas/readings.json', $r);
        $c = $this->file('gas/contracts.json');
        $c[0]['end'] = [];
        $this->put('gas/contracts.json', $c);
        $c = $this->file('strom/contracts.json');
        foreach ($c as $i => $x) {
            if ($x['id'] === $successor) $c[$i]['end'] = [];
            else $c[$i]['is_shadow'] = 'false';
        }
        $this->put('strom/contracts.json', $c);
        $s = $this->file('settings.json');
        $this->put('settings.json', $s + ['forecast_model' => [], 'hdd_base_temp' => 1e300]);
        $p = $this->file('fernwaerme/periods.json');
        $p[0]['from'] = '0003-02-01';
        $p[] = ['id' => 'p_alt', 'meter_id' => $fw, 'from' => '2025-01-01', 'to' => '2025-06-30', 'value' => 4100, 'note' => ['alt']];
        $this->put('fernwaerme/periods.json', $p);
        $m = $this->file('heizoel/meters.json');
        foreach ($m as $i => $x) if ($x['id'] === $tank) $m[$i]['initial_stock'] = 1e300;
        $this->put('heizoel/meters.json', $m);
        $m = $this->file('gas/meters.json');
        $m[] = ['id' => 'm_gas_alt', 'name' => ['Nebenzähler'], 'active' => true, 'meter_group_id' => 'g_gas_alt',
                'devices' => [['id' => 'd_gas_alt', 'installed_on' => '1850-06-01', 'initial_counter' => 0]]];
        $this->put('gas/meters.json', $m);
        $this->put('gas/meter_groups.json', [['id' => 'g_gas_alt', 'name' => ['Haus'], 'created_at' => '2024-01-01']]);
        $rem = $this->file('reminders.json');
        $rem[0]['title'] = ['Wartung'];
        $this->put('reminders.json', $rem);
        // ein Gerät mit Anfangsstand über 10¹² bleibt am Zähler (an ihm hängen die Ablesungen), nur der Stand gilt als nicht eingetragen
        $m = $this->file('strom/meters.json');
        foreach ($m as $i => $x) if ($x['id'] === $strom) $m[$i]['devices'][0]['initial_counter'] = 4e12;
        $this->put('strom/meters.json', $m);
        $stromDevice = (string)array_values(array_filter($m, fn($x) => $x['id'] === $strom))[0]['devices'][0]['id'];
        // eine Lieferung mit negativem Betrag bleibt Lieferung (Menge zählt), nur der Betrag gilt als nicht eingetragen
        $d = $this->file('heizoel/deliveries.json');
        foreach ($d as $i => $x) if ($x['meter_id'] === $tank2) $d[$i]['total_eur'] = -7.5;
        foreach ($d as $i => $x) if ($x['meter_id'] === $tank) $d[$i]['supplier'] = ['Händler'];
        $d[] = ['id' => 'l_alt', 'meter_id' => $tank, 'date' => ['2024-10-01'], 'quantity' => 300];
        $this->put('heizoel/deliveries.json', $d);
        // ein Abschnitt des Brennwerts vor 1900 fällt weg, die Rechnung bleibt; eine zweite Rechnung mit Rechnungsdatum als Liste
        $b = $this->file('gas/bills.json');
        $b[0]['factors'][] = ['from' => '1850-04-01', 'to' => '1850-12-31', 'zustandszahl' => 0.97, 'brennwert' => 11.4];
        $b[] = ['id' => 'b_alt', 'meter_id' => $gas, 'kind' => 'annual', 'period_from' => '2023-01-01', 'period_to' => '2023-12-31', 'issued_on' => ['2024-01-20']];
        $this->put('gas/bills.json', $b);
        $this->put('tenancies.json', [['id' => 't_alt', 'start' => '2024-01-01', 'label' => ['Wohnung'], 'prepayments' => [], 'prices' => [], 'fixed_costs' => []]]);
        $this->put('recommendations_dismissed.json', ['eine_empfehlung' => 'irgendwann']);
        $before = [];
        foreach (glob($this->dataDir . '/{,*/}*.json', GLOB_BRACE) ?: [] as $f) $before[$f] = sha1_file($f);

        // ── jede GET-Route: nie 500, nie ohne Antwort
        $routes = $this->getRoutes(['gas' => [$gas, 'm_gas_alt'], 'strom' => [$strom], 'fernwaerme' => [$fw], 'heizoel' => [$tank], 'wasser' => []]);
        self::assertGreaterThan(120, count($routes), 'Routen aus bootstrap.php');
        $broken = [];
        foreach ($routes as $route) {
            $res = $this->request('GET', $route, ['X-ET-Language: de']);
            if ($res['status'] === 0 || $res['status'] >= 500) $broken[] = "$route → {$res['status']} " . substr($res['body'], 0, 200);
        }
        // dazu die Auswertungen der Gruppe (Name als Liste)
        foreach (['consumption', 'contract-status', 'bill-check'] as $what) {
            $res = $this->request('GET', "/api/utility/gas/meter-groups/g_gas_alt/$what", ['X-ET-Language: de']);
            if ($res['status'] === 0 || $res['status'] >= 500) $broken[] = "Gruppe $what → {$res['status']} " . substr($res['body'], 0, 200);
        }
        self::assertSame([], $broken, 'Lesewege mit 500: ' . implode("\n", $broken) . "\n" . $this->serverLogTail());

        // ── die Rechnung rechnet ohne die Altwerte: dieselben Monate wie vorher, Abrechnungsjahre ab 2024
        $after = $this->call('GET', "/api/utility/gas/meters/$gas/consumption")['data'];
        self::assertSame($cleanConsumption['monthly'] ?? null, $after['monthly'] ?? null, 'Monatswerte wie ohne Altwerte');
        $types = array_column((array)($after['warnings'] ?? []), 'type');
        self::assertContains('out_of_range', $types, 'der Hinweis an der Ansicht bleibt');
        $bp = $this->call('GET', "/api/utility/gas/meters/$gas/billing-periods")['data'];
        self::assertSame($cleanPeriods['data_from'] ?? null, $bp['data_from'] ?? null, 'Daten ab wie ohne Altwerte');
        self::assertSame(count((array)($cleanPeriods['periods'] ?? [])), count((array)($bp['periods'] ?? [])), 'so viele Abrechnungsjahre wie ohne Altwerte');
        // Gerät, Lieferung und Rechnung bleiben in der Rechnung, nur das unbrauchbare Feld fehlt
        self::assertSame($cleanStrom['monthly'] ?? null, $this->call('GET', "/api/utility/strom/meters/$strom/consumption")['data']['monthly'] ?? null,
            'Strom: Monatswerte wie ohne den Anfangsstand des Geräts');
        $sm = array_values(array_filter((array)$this->call('GET', '/api/utility/strom/meters')['data'], fn($x) => ($x['id'] ?? null) === $strom))[0] ?? [];
        self::assertSame([$stromDevice], array_column((array)($sm['devices'] ?? []), 'id'), 'das Gerät bleibt am Zähler');
        self::assertNull($sm['devices'][0]['initial_counter'] ?? null, 'sein Anfangsstand gilt als nicht eingetragen');
        // auch ein Gerät mit Einbau vor 1900 bleibt (ein Tag fällt sonst mit seinem Eintrag weg, Geräte nie)
        $alt = array_values(array_filter((array)$this->call('GET', '/api/utility/gas/meters')['data'], fn($x) => ($x['id'] ?? null) === 'm_gas_alt'))[0] ?? [];
        self::assertSame(['d_gas_alt'], array_column((array)($alt['devices'] ?? []), 'id'), json_encode($alt));
        self::assertNull($alt['devices'][0]['installed_on'] ?? null);
        // Tank: dieselben Mengen; der Betrag fehlt (wie bei einer Lieferung ohne Betrag), Kosten nur ohne Preis
        self::assertSame($cleanStock, $this->call('GET', "/api/utility/heizoel/meters/$tank2/stock-history")['data'], 'Tank: Bestand wie ohne den negativen Betrag');
        $tankAfter = $this->call('GET', "/api/utility/heizoel/meters/$tank2/consumption")['data'];
        self::assertSame(array_column((array)$cleanTank['monthly'], 'kwh', 'ym'), array_column((array)$tankAfter['monthly'], 'kwh', 'ym'), 'Tank: Verbrauch je Monat wie vorher');
        self::assertGreaterThan(0, array_sum(array_column((array)$cleanTank['monthly'], 'kwh')));
        self::assertSame(0, array_sum(array_column((array)$tankAfter['monthly'], 'cost')), 'ohne Betrag keine Kosten (nicht −7,50 €)');
        self::assertSame($cleanCheck, $this->call('GET', "/api/utility/gas/bills/$bill/check")['data'], 'Rechnungsprüfung wie ohne den Abschnitt vor 1900');
        $bills = array_column((array)$this->call('GET', '/api/utility/gas/bills')['data'], null, 'id');
        self::assertCount(1, (array)($bills[$bill]['factors'] ?? []), 'der Abschnitt vor 1900 fällt weg');
        self::assertArrayHasKey('b_alt', $bills, 'die Rechnung mit Rechnungsdatum als Liste bleibt');
        self::assertNull($bills['b_alt']['issued_on'] ?? null);
        $ten = array_column((array)$this->call('GET', '/api/tenancies')['data'], null, 'id');
        self::assertArrayHasKey('t_alt', $ten, 'das Mietverhältnis bleibt');
        self::assertNull($ten['t_alt']['label'] ?? null, 'die Bezeichnung als Liste gilt als nicht eingetragen');
        $groups = array_column((array)$this->call('GET', '/api/utility/gas/meter-groups')['data'], null, 'id');
        self::assertNull($groups['g_gas_alt']['name'] ?? null, 'der Gruppenname als Liste gilt als nicht eingetragen');

        // ── gemeldet: /api/health 200 mit Feld, Diagnose mit Liste
        $h = $this->request('GET', '/api/health');
        self::assertSame(200, $h['status'], $h['body']);
        $health = json_decode($h['body'], true)['data'] ?? [];
        $issues = $health['data_issues'] ?? null;
        self::assertIsArray($issues, $h['body']);
        self::assertSame('ok', $health['status'] ?? null, 'der Status bleibt (die App rechnet ohne die Altwerte weiter)');
        self::assertArrayNotHasKey('data_issues', (array)($health['checks'] ?? []), 'nicht unter checks');
        self::assertGreaterThanOrEqual(9, $issues['count'], json_encode($issues));
        foreach (['date_range', 'date', 'setting', 'number', 'text'] as $kind) self::assertArrayHasKey($kind, $issues['kinds'], json_encode($issues));
        $diag = $this->call('GET', '/api/diagnostics')['data']['data_issues'] ?? [];
        $pots = array_unique(array_column((array)$diag, 'pot'));
        foreach (['gas/readings', 'gas/contracts', 'strom/contracts', 'fernwaerme/periods', 'heizoel/meters', 'gas/meters', 'reminders', 'settings', 'recommendations_dismissed',
                  'strom/meters', 'heizoel/deliveries', 'gas/bills', 'gas/meter_groups', 'tenancies'] as $pot) {
            self::assertContains($pot, $pots, json_encode($diag));
        }

        // ── die Dateien sind unverändert (nichts still repariert)
        clearstatcache();
        $now = [];
        foreach (array_keys($before) as $f) $now[$f] = sha1_file($f);
        self::assertSame($before, $now, 'die Altwerte bleiben in den Dateien');

        // ── der Vertrag mit dem Text „false“ ist kein Schatten (bisher fiel er mit `empty("false")` aus dem Saldo)
        $list = (array)$this->call('GET', '/api/utility/strom/contracts')['data'];
        $old = array_values(array_filter($list, fn($x) => $x['id'] !== $successor))[0] ?? [];
        self::assertFalse($old['is_shadow'] ?? null, json_encode($old));
        self::assertArrayNotHasKey('end', array_filter(array_values(array_filter($list, fn($x) => $x['id'] === $successor))[0] ?? [], fn($v) => $v !== null),
            'das Vertragsende als Liste gilt als nicht eingetragen');
        // ein Schreibweg auf dem Bestand geht (bisher 500 beim Speichern neben einem kaputten Vertrag)
        self::assertSame(201, $this->call('POST', '/api/utility/gas/readings', ['meter_id' => $gas, 'date' => '2025-01-07', 'counter' => 3200])['status']);
        // auch Ändern und der CSV-Import neben einer Ablesung mit Tag als Liste (Sortieren der gespeicherten Ablesungen)
        self::assertSame(200, $this->call('PATCH', "/api/utility/gas/readings/$firstReading", ['note' => 'geprüft'])['status']);
        $csv = $this->request('POST', "/api/utility/gas/meters/$gas/readings/import-csv", ['Content-Type: text/plain; charset=utf-8', "Origin: {$this->base}", 'X-ET-Language: de'],
            "datum;zählerstand\n03.02.2025;3260\n");
        self::assertSame(200, $csv['status'], $csv['body']);
        self::assertSame($gasContract, $this->file('gas/contracts.json')[0]['id']);
        // und ein Speichern daneben lässt den Altwert in der Datei (Schreibwege lesen die Datei, nicht die Sicht der Rechnung)
        self::assertSame(200, $this->call('PATCH', "/api/utility/gas/meters/$gas", ['name' => 'Hauptzähler Gas'])['status']);
        $alt = array_values(array_filter($this->file('gas/meters.json'), fn($x) => $x['id'] === 'm_gas_alt'))[0] ?? [];
        self::assertSame(['Nebenzähler'], $alt['name'] ?? null, 'der Altwert des anderen Zählers bleibt');
        self::assertSame(200, $this->call('PATCH', "/api/utility/heizoel/meters/$tank", ['name' => 'Erdtank Nord'])['status']);
        self::assertSame(1e300, (float)(array_values(array_filter($this->file('heizoel/meters.json'), fn($x) => $x['id'] === $tank))[0]['initial_stock'] ?? 0),
            'ein Altwert am selben Datensatz bleibt, solange niemand das Feld schickt');
        self::assertSame(200, $this->call('PATCH', '/api/utility/gas/meter-groups/g_gas_alt', ['hdd_base_temp' => 14])['status']);
        self::assertSame(['Haus'], $this->file('gas/meter_groups.json')[0]['name'] ?? null, 'auch an einer Gruppe');

        // ── Schreibwege neben einem Altwert in den übrigen Töpfen: nie 500
        $reminder = (string)$this->file('reminders.json')[0]['id'];
        $oldPeriod = (string)$p[0]['id'];   // der Zeitraum ab dem Jahr 0003
        $countIssues = fn(): int => (int)(json_decode($this->request('GET', '/api/health')['body'], true)['data']['data_issues']['count'] ?? -1);
        $before = $countIssues();
        foreach ([['POST', '/api/utility/heizoel/deliveries', ['meter_id' => $tank, 'date' => '2025-02-10', 'quantity' => 500, 'total_eur' => 520], 201],
                  ['POST', '/api/utility/fernwaerme/periods', ['meter_id' => $fw, 'from' => '2025-07-01', 'to' => '2025-12-31', 'value' => 3900], 201],
                  // ein Vertrag prüft beim Ändern den ganzen Datensatz: mit dem Vertragsende als Liste 400 (nicht 500),
                  // mit dem Feld, wie es der Dialog schickt (leer), 200
                  ['PATCH', "/api/utility/strom/contracts/$successor", ['notes' => 'geprüft'], 400],
                  ['PATCH', "/api/utility/strom/contracts/$successor", ['notes' => 'geprüft', 'end' => null], 200],
                  ['POST', '/api/reminders', ['title' => 'Zähler ablesen', 'next_due' => '2027-05-01'], 201],
                  ['PATCH', "/api/utility/gas/bills/$bill", ['note' => 'geprüft'], 200],
                  ['PATCH', '/api/tenancies/t_alt', ['notes' => 'geprüft'], 200]] as [$m, $path, $body, $want]) {
            $res = $this->call($m, $path, $body);
            self::assertSame($want, $res['status'], "$m $path: " . json_encode($res) . "\n" . $this->serverLogTail());
        }
        // ── und der Weg, den die Diagnose nennt: korrigieren oder löschen, per Kennung
        foreach ([['DELETE', '/api/utility/gas/readings/alt_ingest', null], ['DELETE', '/api/utility/heizoel/deliveries/l_alt', null],
                  ['DELETE', "/api/utility/fernwaerme/periods/$oldPeriod", null], ['PATCH', "/api/reminders/$reminder", ['title' => 'Wartung']],
                  ['PATCH', '/api/utility/gas/meters/m_gas_alt', ['name' => 'Nebenzähler']]] as [$m, $path, $body]) {
            $res = $this->call($m, $path, $body);
            self::assertSame(200, $res['status'], "$m $path: " . json_encode($res) . "\n" . $this->serverLogTail());
        }
        self::assertLessThanOrEqual($before - 5, $countIssues(), 'jede Korrektur nimmt einen Fund weg');
    }
}
