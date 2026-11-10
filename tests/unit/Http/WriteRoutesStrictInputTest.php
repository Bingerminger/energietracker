<?php
declare(strict_types=1);

namespace Energietracker\Tests\Http;

use Energietracker\Tests\Support\HttpServerTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * v3.12.0 — Die restlichen Schreibwege prüfen wie die übrigen: falscher Typ, Zahl außerhalb, Datum außerhalb
 * 1900 bis 2100 und Unsinn als Wahrheitswert → 400 mit Code, nichts gespeichert, nie 500. Bisher ergaben
 * Vertragsende `[]`, Preislisten als Text, 10³⁰⁸ in Ganzzahlfeldern, Listen in Personen und Schlüsseln 500;
 * `end: []` und `forecast_model: []` wurden trotzdem gespeichert und legten danach Übersicht, Zu tun,
 * Agenda und Jahresbericht lahm. Am Ende antworten alle Ansichten mit 200.
 */
#[CoversNothing]
final class WriteRoutesStrictInputTest extends HttpServerTestCase
{
    /** @return array{status:int, code:?string} */
    private function call(string $method, string $path, mixed $body, array $cookie = []): array
    {
        $r = $this->request($method, $path, array_merge(['Content-Type: application/json', "Origin: {$this->base}", 'X-ET-Language: de'], $cookie), json_encode($body));
        $j = json_decode($r['body'], true);
        return ['status' => $r['status'], 'code' => is_array($j) ? ($j['code'] ?? null) : null];
    }

    public function testBadInputIs400OnEveryRemainingWriteRouteAndNoViewBreaks(): void
    {
        $this->startServer();
        $gas = (string)$this->json('GET', '/api/utility/gas/meters')[0]['id'];
        $this->call('POST', '/api/utility/gas/readings', ['meter_id' => $gas, 'date' => '2025-01-01', 'counter' => 100]);
        $this->call('POST', '/api/utility/gas/readings', ['meter_id' => $gas, 'date' => '2025-03-01', 'counter' => 400]);
        $contract = (string)($this->json('POST', '/api/utility/gas/contracts', ['meter_id' => $gas, 'provider' => 'P', 'start' => '2025-01-01', 'end' => '2026-12-31',
            'working_prices' => [['from' => '2025-01-01', 'ct_per_kwh' => 10]], 'advance_payments' => [['from' => '2025-01-01', 'amount_eur' => 40]]], ["Origin: {$this->base}"])['id'] ?? '');
        $reminder = (string)($this->json('POST', '/api/reminders', ['title' => 'Wartung', 'next_due' => '2026-12-01'], ["Origin: {$this->base}"])['id'] ?? '');
        self::assertNotSame('', $contract);
        self::assertNotSame('', $reminder);

        $huge = 1e308;
        $cases = [
            // Einstellungen
            ['PATCH', '/api/settings', ['forecast_model' => []]], ['PATCH', '/api/settings', ['gebaeudetyp' => ['a' => 1]]],
            ['PATCH', '/api/settings', ['active_utilities' => ['a' => 1]]], ['PATCH', '/api/settings', ['segmented_split_mode' => []]],
            ['PATCH', '/api/settings', ['location_name' => []]], ['PATCH', '/api/settings', ['reference_source' => []]],
            ['PATCH', '/api/settings', ['hdd_base_temp' => $huge]], ['PATCH', '/api/settings', ['dashboard_months' => $huge]],
            ['PATCH', '/api/settings', ['ocr_timeout_s' => $huge]], ['PATCH', '/api/settings', ['ha_notify_hour' => $huge]],
            ['PATCH', '/api/settings', ['ha_notify_enabled' => 'vielleicht']], ['PATCH', '/api/settings', ['env_name' => true]],
            // Vertrag
            ['PATCH', "/api/utility/gas/contracts/$contract", ['end' => []]], ['PATCH', "/api/utility/gas/contracts/$contract", ['end' => ['a' => 1]]],
            ['PATCH', "/api/utility/gas/contracts/$contract", ['end' => false]], ['PATCH', "/api/utility/gas/contracts/$contract", ['is_shadow' => 'vielleicht']],
            ['PATCH', "/api/utility/gas/contracts/$contract", ['working_prices' => 'abc']], ['PATCH', "/api/utility/gas/contracts/$contract", ['base_prices' => 5]],
            ['PATCH', "/api/utility/gas/contracts/$contract", ['advance_payments' => true]], ['PATCH', "/api/utility/gas/contracts/$contract", ['bonuses' => 'x']],
            ['PATCH', "/api/utility/gas/contracts/$contract", ['special_payments' => 3]], ['PATCH', "/api/utility/gas/contracts/$contract", ['notice_period_days' => $huge]],
            ['PATCH', "/api/utility/gas/contracts/$contract", ['working_prices' => [['from' => ['2025-01-01'], 'ct_per_kwh' => 10]]]],
            ['PATCH', "/api/utility/gas/contracts/$contract", ['working_prices' => [['from' => '2025-01-01', 'ct_per_kwh' => -1]]]],
            ['POST', '/api/utility/gas/contracts', ['meter_id' => $gas, 'provider' => 'Q', 'start' => '2030-01-01', 'end' => [], 'working_prices' => [['from' => '2030-01-01', 'ct_per_kwh' => 9]]]],
            // Zähler, Ablesung
            ['PATCH', "/api/utility/gas/meters/$gas", ['digits' => $huge]], ['PATCH', "/api/utility/gas/meters/$gas", ['active' => 'vielleicht']],
            ['POST', '/api/utility/gas/readings', ['meter_id' => $gas, 'date' => '2025-04-01', 'counter' => 450, 'is_suspect' => 'vielleicht']],
            // Termin
            ['POST', '/api/reminders', ['title' => 'T', 'next_due' => '2026-12-01', 'recurrence' => 'custom-months', 'recurrence_months' => $huge]],
            ['POST', '/api/reminders', ['title' => 'T', 'next_due' => '2026-12-01', 'recurrence' => 'custom-months', 'recurrence_months' => []]],
            ['POST', '/api/reminders', ['title' => 'T', 'next_due' => '2026-12-01', 'recurrence' => 'stündlich']],
            ['POST', '/api/reminders', ['title' => 'T', 'next_due' => '2026-12-01', 'last_done' => 'gestern']],
            ['PATCH', "/api/reminders/$reminder", ['last_done' => '0001-01-01']], ['PATCH', "/api/reminders/$reminder", ['active' => 'vielleicht']],
            ['PATCH', "/api/reminders/$reminder", ['recurrence' => 'jede Woche']],
            ['POST', "/api/reminders/$reminder/done", ['done_date' => '0001-01-01']],
            // Rechnung
            ['POST', '/api/utility/gas/bills', ['meter_id' => $gas, 'period_from' => '2025-01-01', 'period_to' => '2025-12-31', 'issued_on' => '0001-01-01']],
            ['POST', '/api/utility/gas/bills', ['meter_id' => $gas, 'period_from' => '2025-01-01', 'period_to' => '2025-12-31', 'invoice' => 'abc']],
            ['POST', '/api/utility/gas/bills', ['meter_id' => $gas, 'period_from' => '2025-01-01', 'period_to' => '2025-12-31', 'items' => 'abc']],
            ['POST', '/api/utility/gas/bills', ['meter_id' => $gas, 'period_from' => '2025-01-01', 'period_to' => '2025-12-31', 'co2' => 'abc']],
            ['POST', '/api/utility/gas/bills', ['meter_id' => $gas, 'period_from' => '2025-01-01', 'period_to' => '2025-12-31', 'contract_id' => 'NaN']],
            ['POST', '/api/utility/gas/bills', ['meter_id' => $gas, 'period_from' => '2025-01-01', 'period_to' => '2025-12-31', 'kind' => 'irgendwas']],
            // Temperatur, Ingest
            ['POST', '/api/temperatures', ['date' => '2025-01-05', 'avg' => $huge, 'min' => 0, 'max' => 2]],
            ['POST', '/api/ingest', ['utility' => 'gas', 'meter' => $gas, 'value' => '31002,5', 'date' => '2025-05-01']],
        ];
        $seen = [];
        foreach ($cases as [$m, $p, $b]) {
            $r = $this->call($m, $p, $b);
            $seen[] = "$m $p " . json_encode($b) . " → {$r['status']} {$r['code']}";
            self::assertSame(400, $r['status'], end($seen));
            self::assertNotNull($r['code'], end($seen));
        }
        self::assertSame('errors.reading.counterInvalid', $this->call('POST', '/api/ingest', ['utility' => 'gas', 'meter' => $gas, 'value' => '31002,5'])['code'],
            'ein Komma ist kein fehlender Wert');

        // Personen und Schlüssel (nur mit Anmeldung): Listen statt Text → 400 statt 500
        preg_match('/et_session=([^;]+)/', $this->request('POST', '/api/session/password', ['Content-Type: application/json'],
            json_encode(['password' => 'Verwalter-Probe-3']))['headers']['set-cookie'] ?? '', $mm);
        $admin = ['Cookie: et_session=' . ($mm[1] ?? '')];
        foreach ([['POST', '/api/users', ['name' => ['Ben'], 'password' => 'bens-passwort']], ['POST', '/api/users', ['name' => 'Ben', 'password' => ['x']]],
                  ['POST', '/api/users', ['name' => 'Ben', 'password' => 'bens-passwort', 'role' => ['admin']]],
                  ['POST', '/api/auth/keys', ['name' => ['K'], 'scope' => 'read']], ['POST', '/api/auth/keys', ['name' => 'K', 'scope' => ['read']]]] as [$m, $p, $b]) {
            $r = $this->call($m, $p, $b, $admin);
            self::assertSame([400, 'errors.common.textExpected'], [$r['status'], $r['code']], "$m $p " . json_encode($b));
        }

        foreach (['/api/summary', '/api/todo', '/api/agenda', '/api/recommendations', '/api/reports/yearly?year=2025',
                  "/api/utility/gas/meters/$gas/contract-status", "/api/utility/gas/meters/$gas/consumption", '/api/settings'] as $view) {
            self::assertSame(200, $this->request('GET', $view, array_merge(['X-ET-Language: de'], $admin))['status'], $view);
        }
        $c = $this->json('GET', '/api/utility/gas/contracts', null, $admin)[0];
        self::assertSame('2026-12-31', $c['end'], 'das Vertragsende blieb, wie es war');
        self::assertFalse($c['is_shadow']);
    }
}
