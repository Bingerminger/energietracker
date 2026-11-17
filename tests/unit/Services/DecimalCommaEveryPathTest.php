<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\BackupInvalidException;
use Energietracker\Services\BackupService;
use Energietracker\Services\FeedInPayoutService;
use Energietracker\Services\IngestService;
use Energietracker\Services\PeriodService;
use Energietracker\Support\FieldRules;
use Energietracker\Support\Input;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * v3.13.0 — Eine Regel je Art von Zahl an jedem Schreibweg und im Backup-Import, derselbe Text ergibt an allen Wegen
 * derselben Art dasselbe:
 *   - **Zählerstand** (Ablesung, Anfangsstand, Ingest, Zählertausch, Stände im Backup; FieldRules::counter): Punkt als
 *     Dezimaltrenner, „12,5“ ist `400` mit Meldung, wie seit v2.5.3. Ein Komma kann ein Tausendertrenner sein; lieber
 *     ein lautes 400 als still ein Stand um den Faktor 1000 daneben.
 *   - **Preis, Betrag, Einstellung** (Input::decimal): „12,5“ ist 12,5, Mehrdeutiges („4.812,5“) ist 400. Bis v3.12
 *     wiesen Vertragspreis und Einstellungen mit Bereich das Komma ab, Auszahlung und Preis je Ablesung nicht.
 *   - **Verbrauch je Zeitraum** (eine Menge, kein Stand) liest wie in v3.12 auch Tausendertrenner.
 *
 * Eigene Werte: Stand bzw. Betrag 4812,5, Arbeitspreis 30,25 ct, Auszahlung 86,40 €, Wohnfläche 92,5 m².
 */
#[CoversClass(Input::class)]
#[CoversClass(FieldRules::class)]
#[CoversClass(BackupService::class)]
final class DecimalCommaEveryPathTest extends ServiceTestCase
{
    /** Wege je Art: Zählerstände, Zahlen mit Dezimalkomma, Menge je Zeitraum. */
    private const COUNTER = ['Ablesung', 'Anfangsstand', 'Ingest', 'Zählertausch'];
    private const DECIMAL = ['Preis je Ablesung', 'Arbeitspreis', 'Auszahlung', 'Wohnfläche'];
    private const PERIOD = ['Zeitraum'];

    /** @return array<string, array{0:string, 1:?float, 2:?float, 3:?float}> Text → Zählerstand, Zahl, Zeitraum (null: 400) */
    public static function spellings(): array
    {
        return [
            'Komma'                    => ['4812,5', null, 4812.5, 4812.5],
            'Punkt'                    => ['4812.5', 4812.5, 4812.5, 4812.5],
            'Leerraum außen'           => [' 4812.5 ', 4812.5, 4812.5, 4812.5],
            'Exponent'                 => ['4.8125e3', 4812.5, 4812.5, 4812.5],
            'ganze Zahl als Text'      => ['4812', 4812.0, 4812.0, 4812.0],
            'Punkt und Komma'          => ['4.812,5', null, null, 4812.5],
            'Komma und Punkt'          => ['4,812.5', null, null, 4812.5],
            'zwei Kommas'              => ['4,812,5', null, null, null],
            'Wort'                     => ['viertausend', null, null, null],
            'Einheit dahinter'         => ['4812,5 kWh', null, null, null],
        ];
    }

    /** @return array<string, float|string> Weg → gespeicherte Zahl oder Code der Abweisung */
    private function everyPath(string $text): array
    {
        $out = [];
        $try = function (string $path, callable $fn) use (&$out): void {
            try {
                $out[$path] = $fn();
            } catch (\InvalidArgumentException $e) {
                $out[$path] = 'abgewiesen';
            }
        };
        $strom = (string)$this->meters->list('strom')[0]['id'];
        $try('Ablesung', fn() => (float)$this->readings->create('strom', ['meter_id' => $strom, 'date' => '2025-03-01', 'counter' => $text])['counter']);
        $try('Preis je Ablesung', fn() => (float)$this->readings->create('strom', ['meter_id' => $strom, 'date' => '2025-03-02', 'counter' => 4900, 'price_cents' => $text])['price_cents']);
        $try('Anfangsstand', fn() => (float)$this->meters->create('strom', ['name' => 'Garage', 'installed_on' => '2025-01-01', 'initial_counter' => $text])['devices'][0]['initial_counter']);
        $ingest = new IngestService($this->meters, $this->readings, $this->i18n, $this->store);
        $try('Ingest', function () use ($ingest, $strom, $text): float {
            $ingest->ingest(['utility' => 'strom', 'meter' => $strom, 'value' => $text, 'date' => '2025-03-03']);
            return (float)array_values(array_filter($this->readings->list('strom', $strom), fn($r) => $r['date'] === '2025-03-03'))[0]['counter'];
        });
        $try('Arbeitspreis', fn() => (float)$this->contracts->create('strom', ['meter_id' => $strom, 'provider' => 'Stadtwerk Beispielfeld', 'start' => '2025-01-01',
            'working_prices' => [['from' => '2025-01-01', 'ct_per_kwh' => $text]]])['working_prices'][0]['ct_per_kwh']);
        $pv = (string)$this->meters->create('pv_einspeisung', ['name' => 'Einspeisung', 'installed_on' => '2025-01-01'])['id'];
        $payouts = new FeedInPayoutService($this->store, $this->meters, $this->i18n);
        $try('Auszahlung', fn() => (float)$payouts->create(['meter_id' => $pv, 'date' => '2025-03-10', 'amount' => $text])['amount']);
        $try('Wohnfläche', fn() => (float)$this->settings->set(['wohnflaeche_m2' => $text])['wohnflaeche_m2']);
        $try('Zeitraum', fn() => (float)(new PeriodService($this->store, $this->meters, $this->i18n))->create('wasser', [
            'meter_id' => $this->waterPeriodMeter(), 'from' => '2025-01-01', 'to' => '2025-01-31', 'value' => $text])['value']);
        $gas = (string)$this->meters->list('gas')[0]['id'];
        $try('Zählertausch', fn() => (float)array_values(array_filter($this->meters->replaceDevice('gas', $gas,
            ['date' => '2025-02-01', 'old_final_counter' => 300, 'new_initial_counter' => $text])['devices'], fn($d) => empty($d['removed_on'])))[0]['initial_counter']);
        return $out;
    }

    private function waterPeriodMeter(): string
    {
        return (string)$this->meters->create('wasser', ['name' => 'Garten', 'installed_on' => '2024-12-01', 'capture' => 'period'])['id'];
    }

    #[DataProvider('spellings')]
    public function testTheSameTextGivesTheSameNumberOnEveryWritePathOfItsKind(string $text, ?float $counter, ?float $decimal, ?float $period): void
    {
        $got = $this->everyPath($text);
        self::assertCount(9, $got, json_encode($got));
        foreach ([[self::COUNTER, $counter], [self::DECIMAL, $decimal], [self::PERIOD, $period]] as [$paths, $expected]) {
            foreach ($paths as $path) {
                // Preise und Beträge in ct bzw. € runden auf ihre Stellen; 4812,5 bleibt überall 4812,5
                self::assertSame($expected ?? 'abgewiesen', $got[$path], "$path: „{$text}“ " . json_encode($got, JSON_UNESCAPED_UNICODE));
            }
        }
    }

    /** Die Meldung am Zählerstand sagt, wie es richtig geht: Punkt als Dezimaltrenner (an jedem Weg derselbe Satz). */
    public function testACommaAtAMeterReadingIsRejectedWithAClearMessage(): void
    {
        $strom = (string)$this->meters->list('strom')[0]['id'];
        foreach ([fn() => $this->readings->create('strom', ['meter_id' => $strom, 'date' => '2025-03-01', 'counter' => '3175,25']),
                  fn() => $this->meters->create('strom', ['name' => 'Keller', 'installed_on' => '2025-01-01', 'initial_counter' => '3175,25']),
                  fn() => (new IngestService($this->meters, $this->readings, $this->i18n, $this->store))->ingest(['utility' => 'strom', 'meter' => $strom, 'value' => '3175,25', 'date' => '2025-03-03'])] as $i => $fn) {
            try {
                $fn();
                self::fail("Weg $i: Komma am Zählerstand angenommen");
            } catch (\InvalidArgumentException $e) {
                $msg = $e instanceof \Energietracker\Support\LocalizedException ? $this->i18n->t($e->key, $e->params) : $e->getMessage();
                self::assertStringContainsString('3175,25', $msg, "Weg $i");
                self::assertStringContainsString('12.5', $msg, "Weg $i: die Meldung zeigt den Punkt als Dezimaltrenner");
            }
        }
        self::assertSame([], $this->readings->list('strom', $strom), 'nichts gespeichert');
    }

    /**
     * Jede Zahl der Einstellungen nach derselben Regel: auch die mit festem Bereich (Zeitlimit der Texterkennung), die
     * Beispielwerte der Flexibilität und die Werte für CO₂ und Eigenverbrauch (bis v3.12 teils `is_numeric`, „50,0“ war
     * dort 400, an der Wohnfläche daneben 50). Mehrdeutiges ist überall 400.
     */
    public function testEveryNumberSettingReadsTheCommaTheSameWay(): void
    {
        $cases = ['ocr_timeout_s' => ['50,0', 50], 'pv_assumed_self_consumption_pct' => ['32,5', 32.5],
                  'co2_price_scenario_eur_t' => ['55,25', 55.25], 'flex_washer_kwh_per_cycle' => ['0,9', 0.9], 'wohnflaeche_m2' => ['92,5', 92.5]];
        foreach ($cases as $k => [$text, $want]) {
            self::assertEquals($want, $this->settings->set([$k => $text])[$k], "$k „{$text}“");
            try {
                $this->settings->set([$k => '1.032,5']);
                self::fail("$k: mehrdeutig angenommen");
            } catch (\InvalidArgumentException) {
                // erwartet
            }
        }
    }

    /**
     * Der Backup-Import liest dieselben Texte nach derselben Regel wie die Schreibwege und schreibt die Zahl, wie sie
     * speichern: der Zählerstand nach der Regel der Stände, Preis, Auszahlung und Einstellung nach der Regel der Zahlen.
     */
    #[DataProvider('spellings')]
    public function testTheBackupImportReadsTheSameTextTheSameWay(string $text, ?float $counter, ?float $expected): void
    {
        $strom = (string)$this->meters->list('strom')[0]['id'];
        $this->readings->create('strom', ['meter_id' => $strom, 'date' => '2025-03-01', 'counter' => 4700]);
        $this->contracts->create('strom', ['meter_id' => $strom, 'provider' => 'Stadtwerk Beispielfeld', 'start' => '2025-01-01',
            'working_prices' => [['from' => '2025-01-01', 'ct_per_kwh' => 29]]]);
        $pv = (string)$this->meters->create('pv_einspeisung', ['name' => 'Einspeisung', 'installed_on' => '2025-01-01'])['id'];
        (new FeedInPayoutService($this->store, $this->meters, $this->i18n))->create(['meter_id' => $pv, 'date' => '2025-03-10', 'amount' => 50]);
        $backup = new BackupService($this->store, $this->i18n);
        $dump = $backup->export(false);
        $dump['utilities']['strom']['readings'][0]['counter'] = $text;
        $dump['utilities']['strom']['contracts'][0]['working_prices'][0]['ct_per_kwh'] = $text;
        $dump['feed_in_payouts'][0]['amount'] = $text;
        $dump['settings']['wohnflaeche_m2'] = $text;
        try {
            $backup->import($dump);
            self::assertNotNull($expected, "„{$text}“ eingespielt");
            self::assertNotNull($counter, "„{$text}“ als Zählerstand eingespielt");
        } catch (BackupInvalidException $e) {
            self::assertTrue($expected === null || $counter === null, "„{$text}“ abgewiesen: " . json_encode($e->problems));
            $pots = array_map(fn($p) => $p['pot'] . ':' . $p['problem'], $e->problems);
            $want = [...($counter === null ? ['strom/readings:counter'] : []),
                     ...($expected === null ? ['strom/contracts:amount:working_prices', 'feed_in_payouts:amount', 'settings:setting:wohnflaeche_m2'] : [])];
            foreach ($want as $w) self::assertContains($w, $pots, 'jedes abgewiesene Feld gemeldet: ' . json_encode($pots));
            self::assertCount(count($want), array_unique($pots), 'nur diese Felder: ' . json_encode($pots));
            return;
        }
        // als Zahl gespeichert (JSON schreibt 4812.0 als 4812), kein Text mehr
        self::assertSame($counter, (float)$this->store->read('strom/readings.json', [])[0]['counter']);
        self::assertIsNotString($this->store->read('strom/readings.json', [])[0]['counter']);
        self::assertSame($expected, (float)$this->store->read('strom/contracts.json', [])[0]['working_prices'][0]['ct_per_kwh']);
        self::assertIsNotString($this->store->read('feed_in_payouts.json', [])[0]['amount']);
        self::assertSame($expected, (float)$this->store->read('feed_in_payouts.json', [])[0]['amount']);
        self::assertSame($expected, (float)$this->settings->get('wohnflaeche_m2'));
    }
}
