<?php
declare(strict_types=1);

namespace Energietracker\Tests\Http;

use Energietracker\Storage\JsonStore;
use Energietracker\Tests\Support\DraftFixture;
use Energietracker\Tests\Support\HttpServerTestCase;

/**
 * v3.7.0 (Haushalt aus Dokumenten) — Ende zu Ende über die API mit einem
 * erfundenen Stapel: PDFs ablegen (POST /api/inbox), die gespeicherten
 * Auslesungen wie nach dem Auslesen (ohne Modell), den Entwurf holen
 * (GET /api/inbox/draft), das Vorbelegte übernehmen
 * (POST /api/inbox/draft/apply) und den Endstand über die API prüfen.
 *
 *   - Mieter nur Strom: Bestätigung ohne Beginn + Lieferbeginn-Brief + Rechnung
 *   - Eigenheim Gas + Strom: Zählertausch über die Marktlokation,
 *     Lieferantenwechsel mit Kündigung, Rechnung beim neuen Versorger
 */
final class InboxDraftEndpointTest extends HttpServerTestCase
{
    private function doc(string $tag, string $type, array $values, array $flag = []): string
    {
        $store = new JsonStore((string)realpath($this->dataDir));
        $upload = function (string $bytes, ?string $name): array {
            $r = $this->request('POST', '/api/inbox?name=' . rawurlencode((string)$name), ['Content-Type: application/pdf'], $bytes);
            self::assertSame(201, $r['status'], $r['body']);
            return json_decode($r['body'], true)['data'];
        };
        return DraftFixture::add($store, $upload, $tag, $type, $values, $flag);
    }

    private function draft(): array
    {
        $d = $this->json('GET', '/api/inbox/draft');
        self::assertIsArray($d['items'] ?? null, json_encode($d));
        return $d;
    }

    private function applyChecked(array $d): array
    {
        $ids = array_column(array_filter($d['items'], fn($i) => $i['checked']), 'id');
        $r = $this->request('POST', '/api/inbox/draft/apply', ['Content-Type: application/json'], json_encode(['items' => $ids]));
        self::assertSame(200, $r['status'], $r['body']);
        return json_decode($r['body'], true)['data'];
    }

    public function testTenantWithElectricityOnly(): void
    {
        $this->startServer();
        $this->doc('bestaetigung', 'contract_confirmation', ['utility' => 'strom', 'supplier' => 'Nordlicht Energie GmbH', 'tariff_name' => 'Nordlicht Basis 12',
            'working_price' => '31,20', 'base_price' => '12,90', 'base_price_period' => 'month', 'contract_number' => 'NL-4401', 'meter_number' => 'STR-1001']);
        $this->doc('lieferbeginn', 'supply_start', ['utility' => 'strom', 'supplier' => 'Nordlicht Energie', 'supply_start' => '01.04.2025',
            'meter_number' => 'STR-1001', 'reading_start' => '2000', 'contract_number' => 'NL-4401']);
        $this->doc('rechnung', 'invoice', ['utility' => 'strom', 'supplier' => 'Nordlicht Energie GmbH', 'kind' => 'annual',
            'period_from' => '01.04.2025', 'period_to' => '31.12.2025', 'consumption' => '1800', 'reading_old' => '2000', 'reading_old_date' => '01.04.2025',
            'reading_new' => '3800', 'reading_new_date' => '31.12.2025', 'total_amount' => '640,00', 'advances_total' => '600,00',
            'result_kind' => 'due', 'result_amount' => '40,00', 'meter_number' => 'STR-1001', 'contract_number' => 'NL-4401']);

        $d = $this->draft();
        self::assertSame(['documents' => 3, 'used' => 3, 'meters' => 1, 'contracts' => 1, 'readings' => 2, 'bills' => 1, 'questions' => 0],
            array_intersect_key($d['summary'], array_flip(['documents', 'used', 'meters', 'contracts', 'readings', 'bills', 'questions'])));
        self::assertSame(['strom'], $d['utilities']);
        self::assertSame(0, $d['queue']['queued']);
        self::assertContains('advance', array_column($d['missing'], 'kind'));
        foreach ($d['items'] as $it) {
            foreach (['id', 'kind', 'utility', 'status', 'check', 'checked', 'actionable', 'label_key', 'params', 'fields', 'docs'] as $k) {
                self::assertArrayHasKey($k, $it, "Eintrag {$it['kind']} ohne $k");
            }
        }

        $res = $this->applyChecked($d);
        self::assertSame([], $res['failed'], json_encode($res['failed']));
        self::assertSame(['meters' => 0, 'contracts' => 1, 'readings' => 2, 'bills' => 1, 'booked' => 1],
            array_intersect_key($res['created'], array_flip(['meters', 'contracts', 'readings', 'bills', 'booked'])), 'der Standardzähler wird übernommen, nicht angelegt');
        self::assertSame(3, $res['documents']['done']);
        self::assertSame('2025-04-01', $res['bills'][0]['period_from'], 'für „Rechnung prüfen“');
        self::assertMatchesRegularExpression('/^pre-inbox-/', (string)$res['snapshot']);

        $meters = $this->json('GET', '/api/utility/strom/meters');
        self::assertCount(1, $meters);
        self::assertSame('STR-1001', $meters[0]['devices'][0]['serial']);
        $contracts = $this->json('GET', '/api/utility/strom/contracts');
        self::assertCount(1, $contracts);
        self::assertSame(['Nordlicht Energie GmbH', '2025-04-01', 'NL-4401'], [$contracts[0]['provider'], $contracts[0]['start'], $contracts[0]['contract_number']]);
        self::assertSame([31.2], array_column($contracts[0]['working_prices'], 'ct_per_kwh'));
        self::assertCount(2, $contracts[0]['attachment_ids'], 'Bestätigung und Lieferbeginn-Brief als Belege');
        $readings = $this->json('GET', '/api/utility/strom/readings');
        self::assertSame(['2025-04-01' => 2000, '2025-12-31' => 3800], array_map('intval', array_column($readings, 'counter', 'date')));
        $bills = $this->json('GET', '/api/utility/strom/bills');
        self::assertCount(1, $bills);
        self::assertNotEmpty($bills[0]['special_payment_id']);
        $inbox = $this->json('GET', '/api/inbox');
        self::assertSame(['done', 'done', 'done'], array_column($inbox['entries'], 'status'));
        // danach ist der Entwurf leer
        self::assertSame([], $this->draft()['items']);
    }

    public function testHouseWithGasAndElectricityMeterSwapAndSupplierSwitch(): void
    {
        $this->startServer();
        // Gas: Bestätigung, zwei Jahresrechnungen; der Zähler wechselt zum Jahresende (gleiche Marktlokation)
        $this->doc('gas-bestaetigung', 'contract_confirmation', ['utility' => 'gas', 'supplier' => 'Stadtwerke Musterhausen GmbH', 'supply_start' => '01.01.2023',
            'tariff_name' => 'Gas Klassik', 'working_price' => '9,80', 'base_price' => '120,00', 'base_price_period' => 'year', 'advance' => '90,00',
            'meter_number' => 'GAS-A', 'market_location' => DraftFixture::MALO_A]);
        $gas = ['utility' => 'gas', 'supplier' => 'Musterhausen Energie AG', 'kind' => 'annual', 'market_location' => DraftFixture::MALO_A,
            'total_amount' => '1100,00', 'advances_total' => '1080,00', 'result_kind' => 'due', 'result_amount' => '20,00'];
        $this->doc('gas-2023', 'invoice', $gas + ['period_from' => '01.01.2023', 'period_to' => '31.12.2023', 'meter_number' => 'GAS-A',
            'reading_old' => '1000', 'reading_old_date' => '31.12.2022', 'reading_new' => '2200', 'reading_new_date' => '31.12.2023',
            'advance_new' => '95,00', 'advance_new_from' => '01.02.2024']);
        $this->doc('gas-2024', 'invoice', $gas + ['period_from' => '01.01.2024', 'period_to' => '31.12.2024', 'meter_number' => 'GAS-B',
            'reading_old' => '3', 'reading_old_date' => '31.12.2023', 'reading_new' => '1150', 'reading_new_date' => '31.12.2024']);
        // Strom: Wechsel des Versorgers zum 01.01.2025
        $this->doc('strom-alt', 'contract_confirmation', ['utility' => 'strom', 'supplier' => 'Nordlicht Energie GmbH', 'supply_start' => '01.01.2024',
            'tariff_name' => 'Nordlicht Basis 12', 'working_price' => '31,20', 'base_price' => '12,90', 'base_price_period' => 'month', 'advance' => '70,00',
            'contract_number' => 'NL-4401', 'meter_number' => 'STR-1001']);
        $this->doc('strom-kuendigung', 'cancellation', ['utility' => 'strom', 'supplier' => 'Nordlicht Energie GmbH', 'supply_end' => '31.12.2024',
            'cancel_kind' => 'switch', 'contract_number' => 'NL-4401']);
        $this->doc('strom-neu', 'contract_confirmation', ['utility' => 'strom', 'supplier' => 'Südwind Strom AG', 'supply_start' => '01.01.2025',
            'tariff_name' => 'Südwind Fix 24', 'working_price' => '28,00', 'base_price' => '11,00', 'base_price_period' => 'month', 'advance' => '65,00',
            'contract_number' => 'SW-77', 'meter_number' => 'STR-1001']);
        $this->doc('strom-2025', 'invoice', ['utility' => 'strom', 'supplier' => 'Südwind Strom AG', 'kind' => 'annual', 'period_from' => '01.01.2025',
            'period_to' => '31.12.2025', 'consumption' => '2400', 'reading_old' => '5000', 'reading_old_date' => '31.12.2024', 'reading_new' => '7400',
            'reading_new_date' => '31.12.2025', 'total_amount' => '800,00', 'advances_total' => '780,00', 'result_kind' => 'due', 'result_amount' => '20,00',
            'meter_number' => 'STR-1001', 'contract_number' => 'SW-77']);
        // dieselbe Rechnung noch einmal, als andere Datei
        $this->doc('strom-2025-kopie', 'invoice', ['utility' => 'strom', 'supplier' => 'Südwind Strom AG', 'kind' => 'annual', 'period_from' => '01.01.2025',
            'period_to' => '31.12.2025', 'consumption' => '2400', 'reading_old' => '5000', 'reading_old_date' => '31.12.2024', 'reading_new' => '7400',
            'reading_new_date' => '31.12.2025', 'total_amount' => '800,00', 'advances_total' => '780,00', 'result_kind' => 'due', 'result_amount' => '20,00',
            'meter_number' => 'STR-1001', 'contract_number' => 'SW-77']);

        $d = $this->draft();
        self::assertSame(['gas', 'strom'], $d['utilities']);
        self::assertSame(1, $d['summary']['duplicates']);
        self::assertSame([], array_values(array_filter($d['findings'], fn($f) => in_array($f['kind'], ['gap', 'overlap'], true))), 'lückenloser Wechsel');
        $res = $this->applyChecked($d);
        self::assertSame([], $res['failed'], json_encode($res['failed']));

        $gm = $this->json('GET', '/api/utility/gas/meters');
        self::assertCount(1, $gm, 'Zählertausch: derselbe Zähler');
        self::assertSame(['GAS-A', 'GAS-B'], array_column($gm[0]['devices'], 'serial'));
        self::assertSame(DraftFixture::MALO_A, $gm[0]['malo_id']);
        $gc = $this->json('GET', '/api/utility/gas/contracts');
        self::assertCount(1, $gc, 'zwei Schreibweisen, ein Versorger');
        self::assertSame([['from' => '2023-01-01', 'amount_eur' => 90], ['from' => '2024-02-01', 'amount_eur' => 95]],
            array_map(fn($r) => ['from' => $r['from'], 'amount_eur' => (int)$r['amount_eur']], $gc[0]['advance_payments']), 'Abschläge als Kette');
        self::assertSame(10.0, (float)$gc[0]['base_prices'][0]['eur_per_month']);
        self::assertCount(2, $this->json('GET', '/api/utility/gas/readings'), 'am Tauschtag trägt der Tausch die Stände');
        self::assertCount(2, $this->json('GET', '/api/utility/gas/bills'));

        $sm = $this->json('GET', '/api/utility/strom/meters');
        self::assertCount(1, $sm, 'Lieferantenwechsel: derselbe Zähler');
        $sc = $this->json('GET', '/api/utility/strom/contracts');
        usort($sc, fn($a, $b) => strcmp($a['start'], $b['start']));
        self::assertSame([['Nordlicht Energie GmbH', '2024-01-01', '2024-12-31'], ['Südwind Strom AG', '2025-01-01', null]],
            array_map(fn($c) => [$c['provider'], $c['start'], $c['end'] ?? null], $sc));
        $sb = $this->json('GET', '/api/utility/strom/bills');
        self::assertCount(1, $sb, 'die Kopie zählt nicht');
        self::assertSame($sc[1]['id'], $sb[0]['contract_id'], 'die Rechnung am neuen Vertrag');
        $states = array_count_values(array_column($this->json('GET', '/api/inbox')['entries'], 'status'));
        self::assertSame(['done' => 7, 'filed' => 1], $states, 'abgedeckt: übernommen; die Kopie: abgelegt');
    }

    public function testAStaleSelectionIsRejected(): void
    {
        $this->startServer();
        $r = $this->request('POST', '/api/inbox/draft/apply', ['Content-Type: application/json'], json_encode(['items' => ['d_000000000000']]));
        self::assertSame(400, $r['status']);
        self::assertSame('errors.inbox.proposalStale', json_decode($r['body'], true)['code']);
        $r = $this->request('POST', '/api/inbox/draft/apply', ['Content-Type: application/json'], json_encode(['items' => []]));
        self::assertSame('errors.inboxDraft.nothingSelected', json_decode($r['body'], true)['code']);
        // die Route steht vor /api/inbox/{id}
        self::assertSame(200, $this->request('GET', '/api/inbox/draft')['status']);
    }
}
