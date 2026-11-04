<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\BackupService;
use Energietracker\Services\BillService;
use Energietracker\Services\ContractService;
use Energietracker\Services\Inbox\InboxSchema;
use Energietracker\Services\MeterService;
use Energietracker\Services\ReadingService;
use Energietracker\Support\LocalizedException;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * v3.11.0 (F1035, Paket basis) — die neuen Felder für „Deine Rechte als Kunde“ (Konzept §9),
 * alle optional, Schema 1.8.0 bleibt. Die Normalisierer mit Feldliste verwarfen bisher, was
 * sie nicht kannten (Lektion 18): Jetzt behalten sie die neuen Felder, prüfen sie (ein
 * ungültiger Wert ist ein Fehler, kein stilles Verwerfen, Lektion 4) und ein Backup bringt
 * sie zurück. Bestandsdaten ohne die Felder bleiben, wie sie sind.
 */
#[CoversClass(BillService::class)]
#[CoversClass(ContractService::class)]
#[CoversClass(MeterService::class)]
#[CoversClass(ReadingService::class)]
#[CoversClass(InboxSchema::class)]
final class RightsFieldsTest extends ServiceTestCase
{
    private BillService $bills;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bills = new BillService($this->store, $this->meters, $this->contracts, $this->consumption, $this->i18n);
    }

    /** Eine Wasserrechnung als Gebührenbescheid, eine Stromrechnung mit Messentgelt und geschätztem Endstand. */
    private function household(): array
    {
        $strom = $this->meters->defaultId('strom');
        $wasser = $this->meters->defaultId('wasser');
        $this->meters->update('strom', $strom, ['metering_device' => 'imsys', 'msb_basic' => false]);
        $feed = $this->meters->create('pv_einspeisung', ['name' => 'Einspeisung', 'pv_kw' => '9,8']);
        $reading = $this->readings->create('strom', ['meter_id' => $strom, 'date' => '2026-03-28', 'counter' => 1234.5, 'reported_on' => '2026-03-30']);
        $contract = $this->contracts->create('strom', ['meter_id' => $strom, 'provider' => 'Stadtwerke Musterstadt', 'start' => '2025-01-01',
            'supply_kind' => 'special',
            'working_prices' => [['from' => '2025-01-01', 'ct_per_kwh' => 30.0],
                                 ['from' => '2026-01-01', 'ct_per_kwh' => 33.0, 'announced_on' => '2025-11-20', 'vat_pct' => '19']]]);
        $bill = $this->bills->create('strom', ['meter_id' => $strom, 'period_from' => '2025-04-01', 'period_to' => '2026-03-31',
            'issued_on' => '2026-05-20', 'received_on' => '2026-05-23', 'reading_estimated' => ['end' => true],
            'estimated_end_reading' => ['value' => '1240,5', 'on' => '2026-04-01'],   // v3.11.0 (Skeptiker M5), am Tag nach dem Zeitraum
            'invoice_number' => ' R-2026-0042 ',   // v3.11.0 (integration)
            'invoice' => ['energy_kwh' => 3000, 'amount_eur' => 1100],
            'items' => [['label' => 'Messstellenbetrieb', 'amount_eur' => 25.21, 'kind' => 'metering', 'basis' => 'net', 'vat_pct' => 19],
                        ['label' => 'Umlage', 'amount_eur' => 3.0, 'kind' => 'levy']]]);
        $notice = $this->bills->create('wasser', ['meter_id' => $wasser, 'period_from' => '2025-01-01', 'period_to' => '2025-12-31',
            'invoice' => ['volume_m3' => 80, 'amount_eur' => 420],
            'notice' => ['public_law' => true, 'dated_on' => '2026-01-27', 'remedy' => 'objection', 'file_number' => ' AZ 12/345 ']]);
        return compact('strom', 'wasser', 'feed', 'reading', 'contract', 'bill', 'notice');
    }

    public function testTheNewFieldsAreKept(): void
    {
        $h = $this->household();
        $m = $this->meters->get('strom', $h['strom']);
        self::assertSame(['imsys', false], [$m['metering_device'], $m['msb_basic']]);
        self::assertSame(9.8, $h['feed']['pv_kw']);
        self::assertSame('2026-03-30', $h['reading']['reported_on']);
        self::assertSame('special', $h['contract']['supply_kind']);
        self::assertSame([['from' => '2025-01-01', 'ct_per_kwh' => 30.0],
                          ['from' => '2026-01-01', 'ct_per_kwh' => 33.0, 'announced_on' => '2025-11-20', 'vat_pct' => 19.0]], $h['contract']['working_prices']);
        self::assertSame(['2026-05-23', ['start' => false, 'end' => true]], [$h['bill']['received_on'], $h['bill']['reading_estimated']]);
        self::assertSame('R-2026-0042', $h['bill']['invoice_number'], 'Rechnungsnummer ohne Leerzeichen am Rand');
        self::assertSame(['value' => 1240.5, 'on' => '2026-04-01'], $h['bill']['estimated_end_reading']);
        self::assertCount(1, $this->readings->list('strom', $h['strom']), 'der geschätzte Endstand ist keine Ablesung');
        self::assertArrayNotHasKey('invoice_number', $h['notice'], 'ohne Angabe kein Feld');
        self::assertSame(['label' => 'Messstellenbetrieb', 'amount_eur' => 25.21, 'kind' => 'metering', 'basis' => 'net', 'vat_pct' => 19.0], $h['bill']['items'][0]);
        self::assertSame(['label' => 'Umlage', 'amount_eur' => 3.0, 'kind' => 'levy'], $h['bill']['items'][1], 'ohne Angabe kein Feld');
        self::assertSame(['public_law' => true, 'dated_on' => '2026-01-27', 'served_on' => null, 'remedy' => 'objection', 'file_number' => 'AZ 12/345'],
            $h['notice']['notice']);
    }

    public function testExistingRecordsStayAsTheyAreAndEmptyRemoves(): void
    {
        $h = $this->household();
        // eine Änderung an etwas anderem lässt die neuen Felder stehen
        $b = $this->bills->update('strom', $h['bill']['id'], ['note' => 'geprüft']);
        self::assertSame(['2026-05-23', 'R-2026-0042'], [$b['received_on'], $b['invoice_number']]);
        $c = $this->contracts->update('strom', $h['contract']['id'], ['notes' => 'x']);
        self::assertSame('special', $c['supply_kind']);
        $r = $this->readings->update('strom', $h['reading']['id'], ['note' => 'Foto']);
        self::assertSame('2026-03-30', $r['reported_on']);
        $m = $this->meters->update('strom', $h['strom'], ['name' => 'Haus']);
        self::assertSame('imsys', $m['metering_device'] ?? null);
        // leer entfernt
        $b = $this->bills->update('strom', $h['bill']['id'], ['received_on' => '', 'reading_estimated' => null, 'invoice_number' => '  ']);
        self::assertSame([null, null, null], [$b['received_on'], $b['reading_estimated'], $b['invoice_number']]);
        $c = $this->contracts->update('strom', $h['contract']['id'], ['supply_kind' => '']);
        self::assertArrayNotHasKey('supply_kind', $c);
        $r = $this->readings->update('strom', $h['reading']['id'], ['reported_on' => '']);
        self::assertArrayNotHasKey('reported_on', $r);
        $this->meters->update('strom', $h['strom'], ['metering_device' => '', 'msb_basic' => null]);
        $m = $this->meters->get('strom', $h['strom']);
        self::assertArrayNotHasKey('metering_device', $m);
        self::assertArrayNotHasKey('msb_basic', $m);
        // ein Bestandsvertrag ohne die Felder bekommt keine
        $old = $this->contracts->create('gas', ['meter_id' => $this->meters->defaultId('gas'), 'start' => '2025-01-01',
            'working_prices' => [['from' => '2025-01-01', 'ct_per_kwh' => 11.0]]]);
        self::assertArrayNotHasKey('supply_kind', $old);
        self::assertSame([['from' => '2025-01-01', 'ct_per_kwh' => 11.0]], $old['working_prices']);
    }

    public function testABackupBringsThemBack(): void
    {
        $h = $this->household();
        $backups = new BackupService($this->store, $this->i18n);
        $dump = $backups->export();
        foreach (['strom/meters.json', 'strom/readings.json', 'strom/contracts.json', 'strom/bills.json', 'wasser/bills.json', 'pv_einspeisung/meters.json'] as $pot) {
            $this->store->write($pot, []);
        }
        $backups->import($dump);
        $m = $this->meters->get('strom', $h['strom']);
        self::assertSame(['imsys', false], [$m['metering_device'] ?? null, $m['msb_basic'] ?? null], 'Zähler');
        self::assertEquals(9.8, $this->meters->get('pv_einspeisung', $h['feed']['id'])['pv_kw'] ?? null, 'Einspeisung');
        self::assertSame('2026-03-30', $this->readings->list('strom', $h['strom'])[0]['reported_on'] ?? null, 'Ablesung');
        $c = $this->contracts->get('strom', $h['contract']['id']);
        self::assertSame('special', $c['supply_kind'] ?? null, 'Vertrag');
        self::assertSame('2025-11-20', $c['working_prices'][1]['announced_on'] ?? null, 'Preis-Eintrag');
        self::assertEquals(19, $c['working_prices'][1]['vat_pct'] ?? null);
        $b = $this->bills->get('strom', $h['bill']['id']);
        self::assertSame(['2026-05-23', ['start' => false, 'end' => true], 'metering', 'net', 'R-2026-0042', ['value' => 1240.5, 'on' => '2026-04-01']],
            [$b['received_on'] ?? null, $b['reading_estimated'] ?? null, $b['items'][0]['kind'], $b['items'][0]['basis'] ?? null,
             $b['invoice_number'] ?? null, $b['estimated_end_reading'] ?? null], 'Rechnung');
        self::assertSame('objection', $this->bills->get('wasser', $h['notice']['id'])['notice']['remedy'] ?? null, 'Bescheid');
    }

    /** @return array<string, array{0:string, 1:array<string,mixed>}> */
    public static function invalidValues(): array
    {
        return [
            'Rechnung: Zugang kein Datum'     => ['bill', ['received_on' => '23.05.2026']],
            'Rechnung: Schätzung Text'        => ['bill', ['reading_estimated' => 'end']],
            'Rechnung: Schätzung fremder Rand' => ['bill', ['reading_estimated' => ['middle' => true]]],
            'Rechnung: Nummer als Liste'      => ['bill', ['invoice_number' => ['R-1']]],
            'Rechnung: Endstand als Text'     => ['bill', ['estimated_end_reading' => ['value' => 'viel', 'on' => '2025-12-31']]],
            'Rechnung: Endstand ohne Wert'    => ['bill', ['estimated_end_reading' => ['on' => '2025-12-31']]],
            'Rechnung: Endstand außerhalb'    => ['bill', ['estimated_end_reading' => ['value' => 10, 'on' => '2026-01-02']]],
            'Posten: netto/brutto'            => ['bill', ['items' => [['label' => 'x', 'amount_eur' => 1, 'basis' => 'brutto']]]],
            'Posten: Steuersatz'              => ['bill', ['items' => [['label' => 'x', 'amount_eur' => 1, 'vat_pct' => 119]]]],
            'Bescheid bei Strom'              => ['bill', ['notice' => ['public_law' => true, 'dated_on' => '2026-01-27']]],
            'Vertrag: Versorgungsart'         => ['contract', ['supply_kind' => 'grundversorgung']],
            'Preis: Ankündigung'              => ['contract', ['working_prices' => [['from' => '2026-01-01', 'ct_per_kwh' => 30, 'announced_on' => '2025-11-31']]]],
            'Preis: Steuersatz'               => ['contract', ['working_prices' => [['from' => '2026-01-01', 'ct_per_kwh' => 30, 'vat_pct' => 'neunzehn']]]],
            'Zähler: Gerät'                   => ['meter', ['metering_device' => 'smart']],
            'Zähler: grundzuständig'          => ['meter', ['msb_basic' => 'vielleicht']],
            'Zähler: Leistung bei Strom'      => ['meter', ['pv_kw' => 9.8]],
            'Ablesung: gemeldet am'           => ['reading', ['reported_on' => 'gestern']],
        ];
    }

    /** @param array<string,mixed> $input */
    #[DataProvider('invalidValues')]
    public function testAnInvalidValueIsAnErrorNotADrop(string $what, array $input): void
    {
        $strom = $this->meters->defaultId('strom');
        $this->expectException($what === 'bill' ? LocalizedException::class : \InvalidArgumentException::class);
        match ($what) {
            'bill'     => $this->bills->create('strom', $input + ['meter_id' => $strom, 'period_from' => '2025-01-01', 'period_to' => '2025-12-31']),
            'contract' => $this->contracts->create('strom', $input + ['meter_id' => $strom, 'start' => '2025-01-01']),
            'meter'    => $this->meters->update('strom', $strom, $input),
            'reading'  => $this->readings->create('strom', $input + ['meter_id' => $strom, 'date' => '2026-01-01', 'counter' => 1]),
        };
    }

    public function testABescheidNeedsItsDate(): void
    {
        $this->expectException(LocalizedException::class);
        $this->bills->create('wasser', ['meter_id' => $this->meters->defaultId('wasser'), 'period_from' => '2025-01-01', 'period_to' => '2025-12-31',
            'notice' => ['public_law' => true]]);
    }

    // ── Posteingang (Entscheidung 13a) ─────────────────────────────────

    public function testTheInboxSchemaVersion7AsksForAllNewFields(): void
    {
        self::assertSame(7, InboxSchema::VERSION);
        $inv = InboxSchema::fields('invoice');
        foreach (['basic_supply', 'credits', 'metering_fee', 'metering_fee_basis', 'metering_fee_vat_pct', 'fee_notice', 'remedy', 'file_number'] as $f) {
            self::assertArrayHasKey($f, $inv, "Rechnung: $f");
            self::assertEmpty($inv[$f]['core'] ?? null, "$f ist kein Kernfeld (keine Marke, wenn es fehlt)");
        }
        self::assertSame(['label', 'amount', 'kind'], array_keys($inv['credits']['items']));
        self::assertSame(['bonus', 'credit_other'], $inv['credits']['items']['kind']['options'], 'nicht `other`: das heißt in der Oberfläche „Aufschlag“');
        self::assertSame(['objection', 'action'], $inv['remedy']['options']);
        foreach (['basic_supply', 'advance_new', 'advance_new_from'] as $f) self::assertArrayHasKey($f, InboxSchema::fields('price_change'), "Preisänderung: $f");
        self::assertArrayHasKey('basic_supply', InboxSchema::fields('contract_confirmation'));
        self::assertSame(InboxSchema::NEEDS['invoice']['required'], ['period_from', 'period_to'], 'NEEDS unverändert');
    }

    /** Jedes Feld jeder Art hat seine Beschriftung (`inbox.field.<name>`), jede Auswahl ihren Namen (`inbox.option.<wert>`). */
    public function testEveryInboxFieldAndOptionHasALabel(): void
    {
        $de = json_decode((string)file_get_contents(dirname(__DIR__, 3) . '/public/locales/de.json'), true);
        $missing = [];
        foreach (InboxSchema::TYPES as $type) {
            foreach (InboxSchema::fields($type) as $name => $f) {
                if (!is_string($de['inbox']['field'][$name] ?? null)) $missing[] = "inbox.field.$name";
                foreach ([$f, ...array_values($f['items'] ?? [])] as $def) {
                    foreach ($def['kind'] === 'enum' ? $def['options'] : [] as $o) {
                        if (!is_string($de['inbox']['option'][$o] ?? null)) $missing[] = "inbox.option.$o";
                    }
                }
            }
        }
        self::assertSame([], array_values(array_unique($missing)));
    }
}
