<?php
declare(strict_types=1);

namespace Energietracker\Tests\Http;

use Energietracker\Tests\Support\HttpServerTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * v3.13.0 — Ein gespeichertes Angebot (Schattenvertrag) ohne `shadow_label`, auch ohne `tariff_name`, wie es alte Daten,
 * ein Backup oder eine Handeingabe enthalten: Wechselentscheidung und Tarifvergleich antworten 200 und benennen das
 * Angebot nach der Bezeichnung des Tarifs, sonst mit dem allgemeinen Namen. Bisher brachen beide mit 500 ab
 * („Undefined array key“), für den Zähler und für die Gruppe.
 *
 * Daten: die Beispieldaten, darin zwei Angebote für Gas und ein eigenes Angebot für die Gruppe der Wärmepumpe, in der
 * Datei geändert wie von Hand.
 */
#[CoversNothing]
final class TariffOfferWithoutLabelTest extends HttpServerTestCase
{
    public function testAnOfferWithoutItsLabelIsNamedAndAnswers200(): void
    {
        $this->startServer();
        $r = $this->request('POST', '/api/demo/import', ['Content-Type: application/json', "Origin: {$this->base}"], '{"force":true}');
        self::assertSame(200, $r['status'], $r['body']);

        $gasFile = $this->dataDir . '/gas/contracts.json';
        $gas = json_decode((string)file_get_contents($gasFile), true);
        $offers = [];
        foreach ($gas as $i => $c) {
            if (empty($c['is_shadow'])) continue;
            unset($gas[$i]['shadow_label']);
            // das erste Angebot auch ohne Tarifnamen
            if ($offers === []) unset($gas[$i]['tariff_name']);
            $offers[] = $c;
        }
        self::assertCount(2, $offers, 'zwei Angebote in den Beispieldaten');
        file_put_contents($gasFile, json_encode(array_values($gas)));

        $stromFile = $this->dataDir . '/strom/contracts.json';
        $strom = json_decode((string)file_get_contents($stromFile), true);
        $strom[] = ['id' => 'c_k5b_gruppe_angebot', 'meter_id' => null, 'meter_group_id' => 'g_strom_wp', 'provider' => 'Musterwerk',
            'start' => '2024-01-01', 'end' => null, 'is_shadow' => true,
            'working_prices' => [['from' => '2024-01-01', 'ct_per_kwh' => 24.5]], 'base_prices' => [['from' => '2024-01-01', 'eur_per_month' => 9.0]],
            'advance_payments' => [], 'special_payments' => [], 'bonuses' => []];
        file_put_contents($stromFile, json_encode($strom));

        $fallback = (string)json_decode((string)file_get_contents(dirname(__DIR__, 3) . '/public/locales/de.json'), true)['tariff']['shadowFallbackLabel'];
        self::assertNotSame('', $fallback);

        // Wechselentscheidung des Zählers: das Angebot ohne beides heißt allgemein, das mit Tarifnamen nach ihm
        $r = $this->request('GET', '/api/utility/gas/meters/m_gas_main/tariff-switch', ['X-ET-Language: de']);
        self::assertSame(200, $r['status'], $r['body']);
        $labels = array_column((array)json_decode($r['body'], true)['data']['candidates'], 'label', 'contract_id');
        self::assertSame($fallback, $labels[$offers[0]['id']] ?? null, json_encode($labels));
        self::assertSame((string)$offers[1]['tariff_name'], $labels[$offers[1]['id']] ?? null);

        // Tarifvergleich: dieselben Namen
        $r = $this->request('GET', '/api/utility/gas/meters/m_gas_main/tariff-comparison', ['X-ET-Language: de']);
        self::assertSame(200, $r['status'], $r['body']);
        $rows = (array)(json_decode($r['body'], true)['data']['rows'] ?? []);
        foreach ($rows as $row) self::assertNotSame('', (string)$row['label'], json_encode($row));

        // die Gruppe mit ihrem Angebot ohne Namen
        $r = $this->request('GET', '/api/utility/strom/meter-groups/g_strom_wp/tariff-switch', ['X-ET-Language: de']);
        self::assertSame(200, $r['status'], $r['body']);
        $labels = array_column((array)json_decode($r['body'], true)['data']['candidates'], 'label', 'contract_id');
        self::assertSame($fallback, $labels['c_k5b_gruppe_angebot'] ?? null, json_encode($labels));
        // der Tarifvergleich nimmt die Gruppe über die Route der Zähler (die Kennung der Gruppe)
        $r = $this->request('GET', '/api/utility/strom/meters/g_strom_wp/tariff-comparison', ['X-ET-Language: de']);
        self::assertLessThan(500, $r['status'], $r['body']);
    }
}
