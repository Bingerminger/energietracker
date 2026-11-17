<?php
declare(strict_types=1);

namespace Energietracker\Tests\Http;

use Energietracker\Tests\Support\HttpServerTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * v3.13.0 (K2d) — Grenzen und Typen auf den Schreibwegen, mit denselben Regeln wie Backup-Import und Bestand
 * (Support\FieldRules):
 *   - Tank: Größe über 0, Bestand ab 0, beide höchstens FieldRules::TANK_MAX, Preis des Anfangsbestands bis
 *     PRICE_MAX, beim Anlegen und beim Ändern (bisher `(float)` beim Ändern: −1, „abc“ als 0 und 10³⁰⁰ gespeichert);
 *   - Anfangsstand eines Zählers wie jeder Zählerstand höchstens 10¹² (bisher 10¹⁴ und 10³⁰⁰ angenommen);
 *   - Abschnitte des Brennwerts im Jahresbereich 1900 bis 2100; „ausblenden bis“ ein Tag; Lieferung nie negativ;
 *   - eine Liste oder ein Objekt in einem Eintrag einer Liste ist 400, nie 500 (Zäsur, Peilstand, Rechnungsposten,
 *     Abschnitt, Festkosten, Gruppe, Vergleichswert eines Zeitraums, Abhaken eines Termins);
 *   - ein Vertrag hängt nach einem PATCH nie an nichts (leerer oder null-Zähler ohne Gruppe: 400).
 */
#[CoversNothing]
final class InputRulesEndpointTest extends HttpServerTestCase
{
    /** @return array{status:int, code:?string, data:mixed} */
    private function call(string $method, string $path, mixed $payload = null): array
    {
        $r = $this->request($method, $path, ['Content-Type: application/json', "Origin: {$this->base}", 'X-ET-Language: de'],
            $payload === null ? null : json_encode($payload));
        $j = json_decode($r['body'], true);
        return ['status' => $r['status'], 'code' => is_array($j) ? ($j['code'] ?? null) : null, 'data' => is_array($j) ? ($j['data'] ?? null) : null];
    }

    private function rejected(array $r, string $what, ?string $code = null): void
    {
        self::assertSame(400, $r['status'], "$what: " . json_encode($r));
        if ($code !== null) self::assertSame($code, $r['code'], "$what: " . json_encode($r));
    }

    public function testTankNumbersHaveTypesAndLimitsOnCreateAndChange(): void
    {
        $this->startServer();
        foreach (['heizoel', 'pellets'] as $u) {
            $made = $this->call('POST', "/api/utility/$u/meters", ['name' => "Lager $u", 'capacity' => 2800, 'initial_stock' => 900, 'installed_on' => '2024-02-01']);
            self::assertSame(201, $made['status'], json_encode($made));
            $id = (string)$made['data']['id'];
            foreach ([['capacity' => -2], ['capacity' => 0], ['capacity' => 'unklar'], ['capacity' => 5e306], ['initial_stock' => 'unklar'],
                      ['initial_stock' => -40], ['initial_stock' => 5e306], ['initial_stock_price_ct' => 5e306], ['initial_stock_price_ct' => -2]] as $patch) {
                $this->rejected($this->call('PATCH', "/api/utility/$u/meters/$id", $patch), "$u PATCH " . json_encode($patch));
            }
            $m = array_values(array_filter((array)$this->json('GET', "/api/utility/$u/meters"), fn($x) => $x['id'] === $id))[0];
            self::assertSame([2800.0, 900.0], [(float)$m['capacity'], (float)$m['initial_stock']], "$u unverändert");
            // gültig mit Komma geht weiter
            $ok = $this->call('PATCH', "/api/utility/$u/meters/$id", ['initial_stock' => '875,5']);
            self::assertSame(875.5, (float)($ok['data']['initial_stock'] ?? 0), json_encode($ok));
            foreach ([['capacity' => 5e306, 'initial_stock' => 1], ['capacity' => 900, 'initial_stock' => 'unklar'], ['capacity' => 'viel', 'initial_stock' => 1]] as $bad) {
                $this->rejected($this->call('POST', "/api/utility/$u/meters", ['name' => 'Neu', 'installed_on' => '2024-02-01'] + $bad), "$u POST " . json_encode($bad));
            }
        }
        // die Übersicht rechnet weiter (bisher 500 nach 10³⁰⁰)
        self::assertSame(200, $this->request('GET', '/api/summary')['status']);
    }

    public function testFurtherValuesAreRejectedLikeTheirNeighbours(): void
    {
        $this->startServer();
        $gas = (string)$this->json('GET', '/api/utility/gas/meters')[0]['id'];
        // v3.13.0 — über der Grenze nennt die Antwort die Grenze (numberOutOfRange), negativ bleibt „eine Zahl ab 0“
        foreach ([4e12 => 'errors.common.numberOutOfRange', '5e306' => 'errors.common.numberOutOfRange', -2 => 'errors.reading.counterInvalid'] as $c => $code) {
            $c = (float)$c;
            $this->rejected($this->call('POST', '/api/utility/gas/meters', ['name' => 'Z', 'initial_counter' => $c, 'installed_on' => '2024-01-01']),
                "Anfangsstand $c", $code);
            $this->rejected($this->call('POST', '/api/utility/gas/meters', ['name' => 'Z', 'devices' => [['id' => 'd_k2d_1', 'installed_on' => '2024-01-01', 'initial_counter' => $c]]]),
                "Geräteliste mit Anfangsstand $c", $code);
        }
        // der Rand selbst geht
        self::assertSame(201, $this->call('POST', '/api/utility/gas/meters', ['name' => 'Rand', 'initial_counter' => 1e12, 'installed_on' => '2024-01-01'])['status']);

        foreach ([['0099-01-01', '2024-12-31'], ['2024-01-01', '2101-03-15'], ['1899-11-30', '2024-12-31']] as [$f, $t]) {
            $this->rejected($this->call('POST', '/api/utility/gas/bills', ['meter_id' => $gas, 'period_from' => '2024-01-01', 'period_to' => '2024-12-31',
                'factors' => [['from' => $f, 'to' => $t, 'zustandszahl' => 0.96, 'brennwert' => 11.1]]]), "Abschnitt {$f} bis {$t}", 'errors.common.dateOutOfRange');
        }
        self::assertSame(201, $this->call('POST', '/api/utility/gas/bills', ['meter_id' => $gas, 'period_from' => '2024-01-01', 'period_to' => '2024-12-31',
            'factors' => [['from' => '1900-01-01', 'to' => '2100-12-31', 'zustandszahl' => 0.96, 'brennwert' => 11.1]]])['status'], 'Ränder 1900 und 2100 gehen');

        foreach (['irgendwann', '0099-05-05', '31.12.2026'] as $until) {
            $this->rejected($this->call('POST', '/api/recommendations/eine_empfehlung/dismiss', ['until' => $until]), "ausblenden bis $until");
        }
        $this->rejected($this->call('POST', '/api/recommendations/eine_empfehlung/dismiss', ['until' => ['2027-01-01']]), 'bis als Liste', 'errors.common.textExpected');
        self::assertFalse(is_file($this->dataDir . '/recommendations_dismissed.json')
            && str_contains((string)file_get_contents($this->dataDir . '/recommendations_dismissed.json'), 'eine_empfehlung'), 'nichts ausgeblendet');

        $tank = (string)$this->call('POST', '/api/utility/heizoel/meters', ['name' => 'T', 'capacity' => 3000, 'initial_stock' => 100, 'installed_on' => '2024-01-01'])['data']['id'];
        $this->rejected($this->call('POST', '/api/utility/heizoel/deliveries', ['meter_id' => $tank, 'date' => '2024-10-02', 'quantity' => 1500, 'total_eur' => -7.5]), 'Lieferung −7,50 €');
        self::assertSame(201, $this->call('POST', '/api/utility/heizoel/deliveries', ['meter_id' => $tank, 'date' => '2024-10-02', 'quantity' => 1500, 'total_eur' => 0])['status'], '0 € geht');
    }

    public function testListsOrObjectsInsideListEntriesAre400(): void
    {
        $this->startServer();
        $gas = (string)$this->json('GET', '/api/utility/gas/meters')[0]['id'];
        $second = (string)$this->call('POST', '/api/utility/gas/meters', ['name' => 'Werkstatt', 'installed_on' => '2024-01-01'])['data']['id'];
        $tank = (string)$this->call('POST', '/api/utility/heizoel/meters', ['name' => 'T', 'capacity' => 3000, 'initial_stock' => 100, 'installed_on' => '2024-01-01'])['data']['id'];
        $period = (string)$this->call('POST', '/api/utility/fernwaerme/meters', ['name' => 'FW', 'installed_on' => '2024-01-01', 'capture' => 'period'])['data']['id'];
        $cases = [
            ['PATCH', "/api/utility/gas/meters/$gas", ['baseline_events' => [['date' => ['2024-05-01'], 'label' => 'Dämmung']]]],
            ['PATCH', "/api/utility/gas/meters/$gas", ['baseline_events' => [['date' => '2024-05-01', 'label' => ['a' => 1]]]]],
            ['PATCH', "/api/utility/heizoel/meters/$tank", ['tank_levels' => [['date' => '2024-06-01', 'level' => 80, 'note' => ['a' => 1]]]]],
            ['PATCH', "/api/utility/heizoel/meters/$tank", ['tank_levels' => [['date' => ['2024-06-01'], 'level' => 80]]]],
            ['POST', '/api/utility/gas/bills', ['meter_id' => $gas, 'period_from' => '2024-01-01', 'period_to' => '2024-12-31', 'items' => [['label' => ['Grundpreis'], 'amount_eur' => 9]]]],
            ['POST', '/api/utility/gas/bills', ['meter_id' => $gas, 'period_from' => '2024-01-01', 'period_to' => '2024-12-31',
                'factors' => [['from' => ['2024-01-01'], 'zustandszahl' => 0.97, 'brennwert' => 11.4]]]],
            ['POST', '/api/tenancies', ['start' => '2024-01-01', 'label' => 'OG', 'fixed_costs' => [['from' => '2024-01-01', 'label' => ['Müll'], 'eur_per_year' => 120]]]],
            ['POST', '/api/tenancies', ['start' => '2024-01-01', 'label' => 'OG', 'meter_ids' => 'm_x']],
            ['POST', '/api/utility/gas/meter-groups/merge', ['meter_ids' => [['p1'], $second], 'name' => 'G']],
            ['POST', '/api/utility/gas/meter-groups/merge', ['meter_ids' => [$gas, $second], 'group_id' => ['g']]],
            ['POST', '/api/utility/fernwaerme/periods', ['meter_id' => $period, 'from' => '2024-01-01', 'to' => '2024-01-31', 'value' => 400, 'reference' => ['prev_month' => ['p1']]]],
            ['POST', '/api/agenda/done', ['uid' => 'x', 'occurrence' => ['2024']]],
        ];
        foreach ($cases as [$m, $p, $body]) {
            $r = $this->call($m, $p, $body);
            self::assertSame(400, $r['status'], "$m $p " . json_encode($body) . ' → ' . json_encode($r));
        }
        self::assertSame([], (array)$this->json('GET', '/api/utility/gas/meter-groups'), 'keine Gruppe angelegt');
    }

    public function testAContractNeverHangsOnNothingAfterAPatch(): void
    {
        $this->startServer();
        $gas = (string)$this->json('GET', '/api/utility/gas/meters')[0]['id'];
        $c = (string)$this->call('POST', '/api/utility/gas/contracts', ['meter_id' => $gas, 'provider' => 'Gaswerk Probe', 'start' => '2024-01-01',
            'end' => '2029-12-31', 'auto_renews' => false, 'working_prices' => [['from' => '2024-01-01', 'ct_per_kwh' => 10.5]]])['data']['id'];
        foreach (['', null] as $v) {
            $this->rejected($this->call('PATCH', "/api/utility/gas/contracts/$c", ['meter_id' => $v]), 'meter_id ' . json_encode($v), 'errors.common.meterNotFound');
            $this->rejected($this->call('PATCH', "/api/utility/gas/contracts/$c", ['meter_id' => $v, 'notes' => 'neu']), 'meter_id ' . json_encode($v) . ' mit Notiz', 'errors.common.meterNotFound');
        }
        self::assertSame([$c], array_column((array)$this->json('GET', "/api/utility/gas/contracts?meter_id=$gas"), 'id'), 'Vertrag hängt am Zähler');
        // ein Vertrag an einer Gruppe darf ohne Zähler bleiben (so schickt ihn der Dialog)
        $second = (string)$this->call('POST', '/api/utility/gas/meters', ['name' => 'Anbau', 'installed_on' => '2024-01-01'])['data']['id'];
        $g = (string)$this->call('POST', '/api/utility/gas/meter-groups/merge', ['meter_ids' => [$gas, $second], 'name' => 'Haus'])['data']['group']['id'];
        $gc = $this->call('POST', '/api/utility/gas/contracts', ['meter_group_id' => $g, 'provider' => 'Gaswerk Probe', 'start' => '2030-01-01',
            'working_prices' => [['from' => '2030-01-01', 'ct_per_kwh' => 10.5]]]);
        self::assertSame(201, $gc['status'], json_encode($gc));
        $r = $this->call('PATCH', '/api/utility/gas/contracts/' . $gc['data']['id'], ['meter_id' => null, 'notes' => 'Gruppe']);
        self::assertSame(200, $r['status'], json_encode($r));
        self::assertSame($g, $r['data']['meter_group_id']);
    }
}
