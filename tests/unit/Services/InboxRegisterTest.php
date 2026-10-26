<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\ActivityService;
use Energietracker\Services\AttachmentService;
use Energietracker\Services\BackupService;
use Energietracker\Services\BillService;
use Energietracker\Services\Inbox\Applier;
use Energietracker\Services\Inbox\Evidence;
use Energietracker\Services\Inbox\InboxSchema;
use Energietracker\Services\Inbox\Proposals;
use Energietracker\Services\Inbox\ProviderRegistry;
use Energietracker\Services\InboxService;
use Energietracker\Services\IngestService;
use Energietracker\Services\MeterService;
use Energietracker\Services\ReminderService;
use Energietracker\Support\LocalizedException;
use Energietracker\Tests\Support\InboxFixture;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * v3.9.0 (F1032, GitHub #40) — Zählwerke mit OBIS-Kennzahl: am Zähler (Feld `obis`,
 * geprüft, im Backup), im Home-Assistant-Eingang (Zählernummer + Kennzahl) und im
 * Posteingang: Steht dieselbe Zählernummer mit mehreren Zählwerken auf einer
 * Rechnung (HT/NT, Bezug/Einspeisung), landet jeder Stand am Zähler seines
 * Zählwerks. Alle Werte und Nummern erfunden.
 */
#[CoversClass(MeterService::class)]
#[CoversClass(Proposals::class)]
#[CoversClass(Evidence::class)]
#[CoversClass(IngestService::class)]
final class InboxRegisterTest extends ServiceTestCase
{
    private const SERIAL = 'TEST-ZW-7001';

    public function testObisFieldIsValidatedAndNormalisedForComparison(): void
    {
        $m = $this->meters->create('strom', ['name' => 'HT', 'obis' => ' 1-0:1.8.1*255 ']);
        self::assertSame('1-0:1.8.1*255', $m['obis']);
        self::assertSame('1.8.1', MeterService::obisKey($m['obis']));
        self::assertSame('7:3.0.0', MeterService::obisKey('7-0:3.0.0'), 'Gas: Medium vorangestellt');
        self::assertNull(MeterService::obisKey('HT'));
        $u = $this->meters->update('strom', (string)$m['id'], ['obis' => '']);
        self::assertArrayNotHasKey('obis', $u, 'leer entfernt das Zählwerk');
        try {
            $this->meters->update('strom', (string)$m['id'], ['obis' => '1.8']);
            self::fail('ungültige Kennzahl angenommen');
        } catch (LocalizedException $e) {
            self::assertSame('errors.meter.obisInvalid', $e->key);
        }
        self::assertSame('1.8.1', MeterService::registerKey('HT'));
        self::assertSame('1.8.2', MeterService::registerKey('Niedertarif'));
        self::assertSame('2.8.0', MeterService::registerKey('Einspeisung'));
        self::assertSame('1.8.0', MeterService::registerKey('Bezug'));
        self::assertNull(MeterService::registerKey('Stromlieferung'), '„Lieferung“ heißt auf Rechnungen auch der Bezug');
        self::assertSame('1.8.2', MeterService::meterRegister(['name' => 'Z2 Haushalt NT']));
    }

    public function testObisSurvivesBackupRoundtrip(): void
    {
        $m = $this->meters->create('strom', ['name' => 'Z1 Bezug HT', 'obis' => '1.8.1']);
        $backup = new BackupService($this->store, $this->i18n);
        $dump = $backup->export();
        $this->store->write('strom/meters.json', []);
        $backup->import($dump);
        self::assertSame('1.8.1', $this->meters->get('strom', (string)$m['id'])['obis'] ?? null);
    }

    public function testIngestFindsAMeterBySerialAndObis(): void
    {
        [$ht, $nt] = $this->registerMeters();
        $ingest = new IngestService($this->meters, $this->readings, $this->i18n);
        $r = $ingest->ingest(['utility' => 'strom', 'meter' => self::SERIAL, 'obis' => '1-0:1.8.2', 'value' => 4321.5, 'date' => '2025-06-01']);
        self::assertSame($nt, $r['meter_id']);
        $r = $ingest->ingest(['utility' => 'strom', 'meter' => self::SERIAL, 'obis' => '1.8.1', 'value' => 9876.5, 'date' => '2025-06-01']);
        self::assertSame($ht, $r['meter_id']);
        $this->expectException(\InvalidArgumentException::class);
        $ingest->ingest(['utility' => 'strom', 'meter' => self::SERIAL, 'value' => 1.0]);   // ohne Kennzahl: welcher Zähler?
    }

    /** @return array{0:string,1:string,2:string} HT, NT (Strom), Einspeisung (PV-Einspeisung) mit derselben Nummer */
    private function registerMeters(): array
    {
        $this->store->write('strom/meters.json', []);
        $mk = fn(string $u, string $name, string $obis) => (string)$this->meters->create($u, ['name' => $name, 'obis' => $obis,
            'device_serial' => self::SERIAL, 'installed_on' => '2020-01-01'])['id'];
        return [$mk('strom', 'Bezug Hochtarif', '1.8.1'), $mk('strom', 'Bezug Niedertarif', '1.8.2'), $mk('pv_einspeisung', 'Einspeisung', '2.8.0')];
    }

    private function inbox(array $answer): InboxService
    {
        $att = new AttachmentService($this->store, $this->settings, $this->i18n);
        $bills = new BillService($this->store, $this->meters, $this->contracts, $this->consumption, $this->i18n);
        $reminders = new ReminderService($this->store, $this->settings, $this->i18n);
        $p = InboxFixture::provider('local', ['Jahresabrechnung' => 'invoice'], ['invoice' => $answer]);
        $s = new InboxService($this->store, $this->i18n, $att, new ProviderRegistry($this->settings, $p),
            new Proposals($this->meters, $this->contracts, $this->readings, $bills, $reminders, $this->settings),
            new Applier($this->meters, $this->contracts, $this->readings, $bills, $reminders, $this->settings, $this->i18n));
        $s->attachActivity(new ActivityService($this->store, $this->settings, $this->i18n));
        return $s;
    }

    private static function pages(): array
    {
        return ["Netzwerk Beispiel GmbH · Musterstadt\nJahresabrechnung Strom 2025\nAbrechnungszeitraum: 01.01.2025 bis 31.12.2025\n"
            . "Zählernummer: " . self::SERIAL . "\n"
            . "Zählwerk 1.8.1 (HT)   Anfangsstand 10.000 kWh am 31.12.2024   Endstand 12.400 kWh am 31.12.2025\n"
            . "Zählwerk 1.8.2 (NT)   Anfangsstand 5.000 kWh am 31.12.2024   Endstand 6.100 kWh am 31.12.2025\n"
            . "Zählwerk 2.8.0 (Einspeisung)   Anfangsstand 800 kWh am 31.12.2024   Endstand 3.300 kWh am 31.12.2025\n"
            . "Rechnungsbetrag brutto: 1.000,00 €"];
    }

    private static function answer(bool $withRegisters = true): array
    {
        $f = fn($v, string $q) => ['value' => $v, 'quote' => $q, 'page' => 1];
        $row = fn(string $reg, string $label, string $old, string $new) => ['meter_number' => self::SERIAL, 'reading_old' => $old, 'reading_old_date' => '31.12.2024',
            'reading_new' => $new, 'reading_new_date' => '31.12.2025', 'page' => 1,
            'quote' => "Zählwerk $reg ($label) Anfangsstand $old kWh am 31.12.2024 Endstand $new kWh am 31.12.2025"]
            + ($withRegisters ? ['obis' => $reg, 'register' => $label] : []);
        return [
            'utility' => $f('strom', 'Jahresabrechnung Strom 2025'),
            'supplier' => $f('Netzwerk Beispiel GmbH', 'Netzwerk Beispiel GmbH'),
            'kind' => $f('annual', 'Jahresabrechnung Strom 2025'),
            'period_from' => $f('01.01.2025', 'Abrechnungszeitraum: 01.01.2025 bis 31.12.2025'),
            'period_to' => $f('31.12.2025', 'Abrechnungszeitraum: 01.01.2025 bis 31.12.2025'),
            'reading_new' => $f('12.400', 'Endstand 12.400 kWh am 31.12.2025'),
            'reading_new_date' => $f('31.12.2025', 'Endstand 12.400 kWh am 31.12.2025'),
            'total_amount' => $f('1.000,00', 'Rechnungsbetrag brutto: 1.000,00 €'),
            'meter_number' => $f(self::SERIAL, 'Zählernummer: ' . self::SERIAL),
            'meter_readings' => ['value' => [
                $row('1.8.1', 'HT', '10.000', '12.400'),
                $row('1.8.2', 'NT', '5.000', '6.100'),
                $row('2.8.0', 'Einspeisung', '800', '3.300'),
            ]],
        ];
    }

    public function testInvoiceReadingsGoToTheMatchingRegister(): void
    {
        [$ht, $nt, $feed] = $this->registerMeters();
        $s = $this->inbox(self::answer());
        $e = $s->upload(InboxFixture::pdf('zw'), 'zw.pdf');
        $s->extract($e['id'], self::pages());
        $p = $s->preview($e['id']);
        $readings = array_values(array_filter($p['proposals'], fn($x) => $x['kind'] === 'reading'));
        $got = [];
        foreach ($readings as $r) $got[] = [($r['data']['utility'] ?? 'strom') . '|' . $r['data']['meter_id'], $r['data']['date'], $r['data']['counter']];
        usort($got, fn($a, $b) => $a <=> $b);
        $want = [["pv_einspeisung|$feed", '2024-12-31', 800.0], ["pv_einspeisung|$feed", '2025-12-31', 3300.0],
                 ["strom|$ht", '2024-12-31', 10000.0], ["strom|$ht", '2025-12-31', 12400.0],
                 ["strom|$nt", '2024-12-31', 5000.0], ["strom|$nt", '2025-12-31', 6100.0]];
        usort($want, fn($a, $b) => $a <=> $b);
        self::assertSame($want, $got, 'jeder Stand am Zähler seines Zählwerks, keiner doppelt');
        $s->apply($e['id'], array_column($readings, 'id'));
        self::assertSame([3300.0], array_values(array_map(fn($r) => (float)$r['counter'],
            array_filter($this->readings->list('pv_einspeisung', $feed), fn($r) => $r['date'] === '2025-12-31'))), 'Einspeisung in ihrer Art angelegt');
        self::assertSame([6100.0], array_values(array_map(fn($r) => (float)$r['counter'],
            array_filter($this->readings->list('strom', $nt), fn($r) => $r['date'] === '2025-12-31'))));
    }

    /**
     * Zwei Zeiträume je Zählwerk (Preiswechsel zum 01.07.): Je Zählwerk werden die Einträge
     * zusammengefasst (Zwischenstand 30.06.), nie HT mit NT.
     */
    public function testTwoPeriodsPerRegisterAreMergedPerRegister(): void
    {
        [$ht, $nt] = $this->registerMeters();
        $a = self::answer();
        $row = fn(string $reg, string $label, string $d1, string $old, string $d2, string $new) => ['meter_number' => self::SERIAL, 'obis' => $reg, 'register' => $label,
            'reading_old' => $old, 'reading_old_date' => $d1, 'reading_new' => $new, 'reading_new_date' => $d2, 'page' => 1,
            'quote' => "Zählwerk $reg ($label) Anfangsstand $old kWh am $d1 Endstand $new kWh am $d2"];
        $a['meter_readings'] = ['value' => [
            $row('1.8.1', 'HT', '31.12.2024', '10.000', '30.06.2025', '11.100'), $row('1.8.1', 'HT', '30.06.2025', '11.100', '31.12.2025', '12.400'),
            $row('1.8.2', 'NT', '31.12.2024', '5.000', '30.06.2025', '5.500'), $row('1.8.2', 'NT', '30.06.2025', '5.500', '31.12.2025', '6.100'),
        ]];
        $pages = self::pages();
        $pages[0] = str_replace("Zählwerk 1.8.1 (HT)   Anfangsstand 10.000 kWh am 31.12.2024   Endstand 12.400 kWh am 31.12.2025\n"
            . "Zählwerk 1.8.2 (NT)   Anfangsstand 5.000 kWh am 31.12.2024   Endstand 6.100 kWh am 31.12.2025\n",
            "Zählwerk 1.8.1 (HT) Anfangsstand 10.000 kWh am 31.12.2024 Endstand 11.100 kWh am 30.06.2025\n"
            . "Zählwerk 1.8.1 (HT) Anfangsstand 11.100 kWh am 30.06.2025 Endstand 12.400 kWh am 31.12.2025\n"
            . "Zählwerk 1.8.2 (NT) Anfangsstand 5.000 kWh am 31.12.2024 Endstand 5.500 kWh am 30.06.2025\n"
            . "Zählwerk 1.8.2 (NT) Anfangsstand 5.500 kWh am 30.06.2025 Endstand 6.100 kWh am 31.12.2025\n", $pages[0]);
        $s = $this->inbox($a);
        $e = $s->upload(InboxFixture::pdf('zw2'), 'zw2.pdf');
        $s->extract($e['id'], $pages);
        $p = $s->preview($e['id']);
        $by = [];
        foreach (array_filter($p['proposals'], fn($x) => $x['kind'] === 'reading') as $r) $by[$r['data']['meter_id']][$r['data']['date']] = $r['data']['counter'];
        ksort($by[$ht]); ksort($by[$nt]);
        self::assertSame(['2024-12-31' => 10000.0, '2025-06-30' => 11100.0, '2025-12-31' => 12400.0], $by[$ht] ?? null);
        self::assertSame(['2024-12-31' => 5000.0, '2025-06-30' => 5500.0, '2025-12-31' => 6100.0], $by[$nt] ?? null);
    }

    /** Ein Zählwerk, das nicht im Zitat steht, zählt nicht (Hilfsfelder markieren nie, also nur mit Beleg). */
    public function testARegisterOutsideTheQuoteIsNotTrusted(): void
    {
        $this->registerMeters();
        $a = self::answer();
        foreach ($a['meter_readings']['value'] as &$it) $it['quote'] = 'Anfangsstand ' . $it['reading_old'] . ' kWh am 31.12.2024 Endstand ' . $it['reading_new'] . ' kWh am 31.12.2025';
        unset($it);
        $s = $this->inbox($a);
        $e = $s->upload(InboxFixture::pdf('zw3'), 'zw3.pdf');
        $s->extract($e['id'], self::pages());
        $p = $s->preview($e['id']);
        self::assertSame([], array_values(array_filter($p['proposals'], fn($x) => $x['kind'] === 'reading' && in_array('meter_readings', (array)$x['sources'], true))));
    }

    public function testWithoutRegistersNothingIsGuessed(): void
    {
        $this->registerMeters();
        $s = $this->inbox(self::answer(false));
        $e = $s->upload(InboxFixture::pdf('zw0'), 'zw0.pdf');
        $s->extract($e['id'], self::pages());
        $p = $s->preview($e['id']);
        $fromItems = array_filter($p['proposals'], fn($x) => $x['kind'] === 'reading' && in_array('meter_readings', (array)$x['sources'], true));
        self::assertSame([], array_values($fromItems), 'dieselbe Nummer dreimal ohne Zählwerk: kein Vorschlag aus den Ständen je Zähler (wie bisher)');
        self::assertSame(5, InboxSchema::VERSION, 'Schema mit Zählwerk je Stand');
    }
}
