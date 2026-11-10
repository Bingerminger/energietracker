<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\BackupInvalidException;
use Energietracker\Services\BackupService;
use Energietracker\Services\BillService;
use Energietracker\Services\ContractService;
use Energietracker\Services\CsvExportService;
use Energietracker\Services\DeliveryService;
use Energietracker\Services\LetterService;
use Energietracker\Services\SettingsService;
use Energietracker\Services\TemperatureService;
use Energietracker\Services\WeatherService;
use Energietracker\Storage\Migrator;
use Energietracker\Support\LocalizedException;
use Energietracker\Tests\Support\LetterFixture;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * v3.12.0 (m4b) — Briefe ziehen ihre Angaben aus dem Energietracker (kehrt Entscheidung 10a um): Absender und
 * Lieferanschrift als freiwillige Angaben im Haushalt (SettingsService::LETTER_KEYS), die Anschrift des Anbieters am
 * Vertrag (`provider_address`). Der Brief füllt damit vor; Eingaben im Dialog gelten nur für den Brief. Sicherung und
 * Wiederherstellung tragen sie, der CSV-Export nicht. Schema bleibt 1.8.0 (Felder optional, Vorgabe leer).
 * Stichtag fest (LetterFixture::TODAY = 2026-11-03), alle Angaben erfunden.
 */
#[CoversClass(LetterService::class)]
#[CoversClass(SettingsService::class)]
#[CoversClass(ContractService::class)]
#[CoversClass(BackupService::class)]
final class LetterDataTest extends ServiceTestCase
{
    use LetterFixture;

    private const SENDER = ['letter_sender_name' => 'Zora Quellfeld', 'letter_sender_address' => "Brombeerpfad 17\n54321 Musterstadt",
        'letter_supply_address' => 'Brombeerpfad 17, 54321 Musterstadt'];
    private const PROVIDER_ADDRESS = "Lindenallee 3\n98765 Musterstadt";

    private LetterService $withHousehold;

    protected function setUp(): void
    {
        parent::setUp();
        $this->letterSetUp();
        $bills = new BillService($this->store, $this->meters, $this->contracts, $this->consumption, $this->i18n);
        $this->withHousehold = new LetterService($this->rights, $this->contracts, $this->meters, $bills, consumption: $this->consumption, settings: $this->settings);
    }

    /** @return array<string,mixed> Modell der Kündigung am Stromvertrag, ohne Absender und Ort (wie die erste Anfrage des Dialogs) */
    private function termination(array $body = []): array
    {
        return $this->withHousehold->render(['utility' => 'strom', 'contract_id' => $this->stromContract, 'kind' => 'termination'] + $body, self::TODAY)['model'];
    }

    private function dataHash(): string
    {
        $files = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dataDir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) if ($f->isFile() && str_ends_with($f->getFilename(), '.json')) $files[(string)$f] = md5_file((string)$f);
        ksort($files);
        return md5((string)json_encode($files));
    }

    // ── Haushalt: Einstellungen ──────────────────────────────────────────

    public function testTheHouseholdKeepsLetterDataInShape(): void
    {
        self::assertSame('', $this->settings->get('letter_sender_name'), 'Vorgabe leer: freiwillig');
        $this->settings->set(['letter_sender_name' => "  Zora \u{0007} Quellfeld  ", 'letter_sender_address' => "Brombeerpfad 17\r\n\r\n 54321 Musterstadt \n",
            'letter_supply_address' => 'Brombeerpfad 17, 54321 Musterstadt']);
        self::assertSame(['Zora Quellfeld', "Brombeerpfad 17\n54321 Musterstadt"],
            [$this->settings->get('letter_sender_name'), $this->settings->get('letter_sender_address')], 'Steuerzeichen und leere Zeilen weg');
        foreach ([['letter_sender_address', "1\n2\n3\n4\n5"], ['letter_sender_name', str_repeat('x', 61)], ['letter_supply_address', "Weg 1\n12345 Ort"],
                  ['letter_sender_name', ['Zora']]] as [$key, $bad]) {
            try {
                $this->settings->set([$key => $bad]);
                self::fail("$key: Fehler erwartet");
            } catch (\InvalidArgumentException $e) {
                self::assertMatchesRegularExpression('/60|^errors\.settings\.letterLines?$/', $e->getMessage(), $key);   // ohne Übersetzer im Test: der Schlüssel
            }
        }
        self::assertSame('Zora Quellfeld', $this->settings->get('letter_sender_name'), 'ein Fehler ändert nichts');
        $this->settings->set(['letter_sender_name' => '']);
        self::assertArrayNotHasKey('letter_sender_name', (array)$this->store->read('settings.json', []), 'geleert = nicht angegeben');
        self::assertSame(4, SettingsService::LETTER_ADDRESS_LINES);
        self::assertSame(LetterService::LINE_MAX, SettingsService::LETTER_LINE_MAX, 'eine Zeilenlänge für Haushalt und Brief');
    }

    public function testTheContractKeepsTheSupplierAddress(): void
    {
        $id = (string)$this->contracts->create('gas', ['meter_id' => $this->meters->defaultId('gas'), 'provider' => 'Gaswerk Musterstadt', 'start' => '2027-01-01',
            'provider_address' => " Lindenallee 3 \n\n98765 Musterstadt", 'working_prices' => [['from' => '2027-01-01', 'ct_per_kwh' => 10.0]]])['id'];
        self::assertSame(self::PROVIDER_ADDRESS, $this->contracts->get('gas', $id)['provider_address']);
        $this->contracts->update('gas', $id, ['tariff_name' => 'Neu']);
        self::assertSame(self::PROVIDER_ADDRESS, $this->contracts->get('gas', $id)['provider_address'], 'andere Änderung lässt die Anschrift stehen');
        $this->contracts->update('gas', $id, ['provider_address' => '']);
        self::assertArrayNotHasKey('provider_address', $this->contracts->get('gas', $id), 'geleert = entfernt');
        try {
            $this->contracts->update('gas', $id, ['provider_address' => "a\nb\nc\nd\ne"]);
            self::fail('fünf Zeilen');
        } catch (LocalizedException $e) {
            self::assertSame('errors.settings.letterLines', $e->key);
        }
        // Wasser hat ein eigenes Formular und eigene Normalisierung
        $w = (string)$this->contracts->create('wasser', ['meter_id' => $this->wasserMeter, 'provider' => 'Wasserwerk Musterstadt', 'start' => '2025-01-01',
            'provider_address' => self::PROVIDER_ADDRESS])['id'];
        self::assertSame(self::PROVIDER_ADDRESS, $this->contracts->get('wasser', $w)['provider_address']);
    }

    // ── Brief: Vorbelegung ───────────────────────────────────────────────

    public function testTheLetterIsPrefilledFromHouseholdAndContract(): void
    {
        $this->settings->set(self::SENDER);
        $this->contracts->update('strom', $this->stromContract, ['provider_address' => self::PROVIDER_ADDRESS]);
        $m = $this->termination();
        self::assertSame(['Zora Quellfeld', 'Brombeerpfad 17', '54321 Musterstadt'], $m['sender']);
        self::assertSame(['Stadtwerke Musterstadt', 'Lindenallee 3', '98765 Musterstadt'], $m['recipient']);
        self::assertSame('Musterstadt', $m['place'], 'Ort aus der Zeile mit der Postleitzahl');
        self::assertSame('Musterstadt, 03.11.2026', $m['place_date']);
        self::assertSame('Zora Quellfeld, Brombeerpfad 17, 54321 Musterstadt', $m['return_line'], 'Rücksendeangabe im Fenster');
        self::assertSame('Zora Quellfeld', $m['signature']);
        self::assertStringContainsString("Lieferanschrift: Brombeerpfad 17, 54321 Musterstadt", $m['text']);
        self::assertNotContains('sender', $m['gaps']);
        self::assertSame(['sender' => 'household', 'supply_address' => 'household', 'recipient_address' => 'contract',
            'contract' => ['utility' => 'strom', 'id' => $this->stromContract]], $m['sources']);
        self::assertSame(['Stadtwerke Musterstadt'], $m['defaults']['recipient_name'], 'damit der Dialog die Anschrift vom Namen trennt');
        self::assertStringStartsWith('%PDF-', $this->withHousehold->pdf($m));
    }

    public function testChangesInTheDialogApplyToThisLetterOnly(): void
    {
        $this->settings->set(self::SENDER);
        $this->contracts->update('strom', $this->stromContract, ['provider_address' => self::PROVIDER_ADDRESS]);
        $before = $this->dataHash();
        $m = $this->termination(['sender' => ['Ida Quellfeld', 'Ahornweg 2', '54321 Musterstadt'], 'place' => '',
            'recipient' => ['Stadtwerke Musterstadt', 'Kundenservice', 'Postfach 10', '98765 Musterstadt'], 'refs' => ['supply_address' => '']]);
        self::assertSame('Ida Quellfeld', $m['sender'][0]);
        self::assertSame('03.11.2026', $m['place_date'], 'Ort bewusst geleert');
        self::assertSame('Kundenservice', $m['recipient'][1]);
        self::assertStringNotContainsString('Lieferanschrift', $m['text'], 'bewusst geleert');
        self::assertSame($before, $this->dataHash(), 'der Brief speichert nichts, auch nicht die Änderungen');
        self::assertSame('Zora Quellfeld', $this->settings->get('letter_sender_name'));
    }

    /**
     * v3.12.0 — Ort und Herkunft folgen dem Absender, der im Brief steht. Mit einem anderen Absender in der Anfrage und
     * ohne Ort standen bisher der Ort des Haushalts über dem Betreff und „household“ als Herkunft. Angaben erfunden.
     */
    public function testPlaceAndSourceFollowTheSenderOfTheLetter(): void
    {
        $this->settings->set(self::SENDER);
        $other = $this->termination(['sender' => ['Ida Beispiel', 'Kiefernring 8', '67890 Talheim']]);
        self::assertSame(['Talheim', 'Talheim, 03.11.2026', 'request'], [$other['place'], $other['place_date'], $other['sources']['sender']]);
        self::assertSame('Ida Beispiel, Kiefernring 8, 67890 Talheim', $other['return_line']);
        // derselbe Absender wie im Haushalt (der Dialog schickt ihn unverändert mit): Herkunft bleibt der Haushalt
        $same = $this->termination(['sender' => ['Zora Quellfeld', 'Brombeerpfad 17', '54321 Musterstadt']]);
        self::assertSame(['Musterstadt', 'household'], [$same['place'], $same['sources']['sender']]);
        // ein angegebener Ort gilt weiter vor dem Absender; ohne Postleitzahl kein Ort
        self::assertSame('Bergdorf', $this->termination(['sender' => ['Ida Beispiel', 'Talheim'], 'place' => 'Bergdorf'])['place']);
        self::assertSame(['', 'request'], [($m = $this->termination(['sender' => ['Ida Beispiel', 'Talheim']]))['place'], $m['sources']['sender']]);
        // ohne Haushalt: ein Absender aus der Anfrage ist „request“
        $this->settings->set(['letter_sender_name' => '', 'letter_sender_address' => '']);
        self::assertSame(['request', 'Talheim'], [($m = $this->termination(['sender' => ['Ida Beispiel', '67890 Talheim']]))['sources']['sender'], $m['place']]);
    }

    public function testWithoutHouseholdDataTheGapsStay(): void
    {
        $m = $this->termination();
        self::assertSame([], $m['sender']);
        self::assertContains('sender', $m['gaps']);
        self::assertSame('03.11.2026', $m['place_date']);
        self::assertSame(['sender' => null, 'supply_address' => null, 'recipient_address' => null,
            'contract' => ['utility' => 'strom', 'id' => $this->stromContract]], $m['sources']);
        self::assertSame(['Stadtwerke Musterstadt'], $m['recipient'], 'nur der Name, wie bisher');
        // im Dialog bewusst geleert: Lücke trotz Haushalt
        $this->settings->set(self::SENDER);
        self::assertContains('sender', $this->termination(['sender' => []])['gaps']);
        // Widerspruch an die Behörde: Den Empfänger kennt die App nicht, der Absender kommt trotzdem
        $o = $this->withHousehold->render(['finding_id' => $this->ids['fee_notice_deadline'], 'extra' => ['confirm_objection' => true]], self::TODAY)['model'];
        self::assertSame([[], 'Zora Quellfeld', null], [$o['recipient'], $o['sender'][0], $o['sources']['contract']]);
    }

    public function testTheRecipientFitsTheWindow(): void
    {
        // Name auf drei Zeilen und vier Zeilen Anschrift: Die Anschriftzone (DIN 5008) hat sechs, der Name bekommt
        // zwei (die dritte Zeile „Ost“ entfällt), die Anschrift bleibt ganz
        $long = 'Energieversorgung Musterstadt und Umgebung Gesellschaft mit beschränkter Haftung und Kompanie Kommanditgesellschaft Nord Ost';
        $this->contracts->update('strom', $this->stromContract, ['provider' => $long, 'provider_address' => "Kundenservice\nPostfach 10\nLindenallee 3\n98765 Musterstadt"]);
        $m = $this->termination();
        self::assertCount(LetterService::ADDRESS_LINES, $m['recipient']);
        self::assertSame(['Energieversorgung Musterstadt und Umgebung Gesellschaft mit', 'beschränkter Haftung und Kompanie Kommanditgesellschaft Nord'], $m['defaults']['recipient_name']);
        self::assertCount(3, LetterService::wrap($long), 'ohne Anschrift drei Zeilen');
        self::assertSame('98765 Musterstadt', $m['recipient'][5]);
    }

    /** @return array<string, array{list<string>, string}> */
    public static function places(): array
    {
        return [
            'Postleitzahl und Ort'      => [['Zora Quellfeld', 'Brombeerpfad 17', '54321 Musterstadt'], 'Musterstadt'],
            'mit Landeskennung'         => [['Zora Quellfeld', 'Brombeerpfad 17', 'D-54321 Musterstadt'], 'Musterstadt'],
            'vierstellig (AT, CH)'      => [['Zora Quellfeld', 'Brombeerpfad 17', '1010 Musterstadt'], 'Musterstadt'],
            'ohne Postleitzahl: leer'   => [['Zora Quellfeld', 'Brombeerpfad 17', 'Musterstadt'], ''],
            'nur der Name: leer'        => [['Zora Quellfeld'], ''],
            'Straße zuletzt: nie raten' => [['Zora Quellfeld', '54321 Musterstadt', 'Brombeerpfad 17'], ''],
        ];
    }

    #[DataProvider('places')]
    public function testThePlaceComesOnlyFromAPostcodeLine(array $sender, string $place): void
    {
        self::assertSame($place, LetterService::placeOf($sender));
    }

    // ── Sicherung, Export, Schema ────────────────────────────────────────

    public function testBackupRoundTripKeepsTheLetterData(): void
    {
        self::assertSame(['settings' => SettingsService::LETTER_KEYS, 'contracts' => ContractService::LETTER_FIELDS], BackupService::PERSONAL_FIELDS);
        $this->settings->set(self::SENDER);
        $this->contracts->update('strom', $this->stromContract, ['provider_address' => self::PROVIDER_ADDRESS]);
        $backup = new BackupService($this->store, $this->i18n);
        $export = json_decode((string)json_encode($backup->export(false)), true);
        // danach geändert und geleert, dann die Sicherung zurück
        $this->settings->set(['letter_sender_name' => 'Ida Quellfeld', 'letter_sender_address' => '', 'letter_supply_address' => '']);
        $this->contracts->update('strom', $this->stromContract, ['provider_address' => '']);
        $backup->import($export, false, true);
        foreach (BackupService::PERSONAL_FIELDS['settings'] as $k) self::assertSame(self::SENDER[$k], $this->settings->get($k), $k);
        self::assertSame(self::PROVIDER_ADDRESS, $this->contracts->get('strom', $this->stromContract)['provider_address']);
        self::assertSame('Musterstadt, 03.11.2026', $this->termination()['place_date'], 'der Brief liest die wiederhergestellten Angaben');

        // Form geprüft: Text oder nichts
        $bad = $export;
        $bad['settings']['letter_sender_name'] = ['Zora'];
        $bad['utilities']['strom']['contracts'][0]['provider_address'] = 42;
        try {
            $backup->import($bad, true);
            self::fail('falsche Form');
        } catch (BackupInvalidException $e) {
            $what = array_column($e->problems, 'problem');
            self::assertContains('personal:letter_sender_name', $what);
            self::assertContains('personal:provider_address', $what);
        }
    }

    public function testTheCsvExportCarriesNoLetterData(): void
    {
        $this->settings->set(self::SENDER);
        $this->contracts->update('strom', $this->stromContract, ['provider_address' => self::PROVIDER_ADDRESS]);
        $this->setReadings('strom', $this->stromMeter, [
            ['date' => '2026-01-01', 'counter' => 100.0, 'device_id' => 'd_strom_1'], ['date' => '2026-02-01', 'counter' => 350.0, 'device_id' => 'd_strom_1'],
        ]);
        $csv = new CsvExportService($this->consumption, $this->readings, $this->meters,
            new TemperatureService($this->store, $this->settings, new WeatherService()), new DeliveryService($this->store, $this->meters, $this->i18n), $this->i18n);
        $all = $csv->monthly('strom') . $csv->readings('strom') . $csv->deliveries('heizoel') . $csv->temperatures() . $csv->periods('strom', []);
        self::assertStringContainsString('350', $all, 'der Export hat Inhalt');
        foreach (['Quellfeld', 'Brombeerpfad', '54321', 'Lindenallee', '98765'] as $s) self::assertStringNotContainsString($s, $all);
    }

    public function testOldDataNeedNoMigration(): void
    {
        self::assertSame('1.8.0', Migrator::SCHEMA_VERSION, 'kein Schemaschritt: Felder optional, Vorgabe leer');
        $this->store->write('settings.json', ['country' => 'DE']);
        foreach (SettingsService::LETTER_KEYS as $k) self::assertSame('', $this->settings->get($k), $k);
        $m = $this->termination();
        self::assertSame(['Stadtwerke Musterstadt'], $m['recipient'], 'Vertrag ohne Anschrift: wie bisher');
        // Unpassendes aus einem alten Bestand gilt im Brief als nicht angegeben, statt den Brief zu sprengen
        $this->store->write('settings.json', ['country' => 'DE', 'letter_sender_name' => str_repeat('x', 80), 'letter_sender_address' => ['Weg']]);
        self::assertSame([], $this->termination()['sender']);
    }
}
