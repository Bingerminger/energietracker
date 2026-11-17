<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\BackupInvalidException;
use Energietracker\Services\BackupService;
use Energietracker\Services\DeliveryService;
use Energietracker\Services\IngestService;
use Energietracker\Services\ReadingImportService;
use Energietracker\Support\FieldRules;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * v3.13.0 (K2d) — Eine Prüfung je Regel, und jeder Eingang ruft sie: Schreibweg (Dienst, auch für Oberfläche und KI),
 * CSV-Import, Ingest von Home Assistant, Backup-Import und Bestand beim Lesen. Je Regel steht hier ein Wert, den sie
 * abweist, und je Eingang, dass er ihn abweist bzw. aus der Rechnung nimmt. Ein neuer Eingang, der an der Regel
 * vorbeischreibt, fällt hier auf; ebenso ein Eingang, der eine eigene, abweichende Regel bekommt.
 */
#[CoversClass(FieldRules::class)]
final class FieldRulesEntryPointsTest extends ServiceTestCase
{
    private string $strom;
    private string $tank;

    protected function setUp(): void
    {
        parent::setUp();
        $this->strom = (string)$this->meters->list('strom')[0]['id'];
        $this->tank = (string)$this->meters->create('heizoel', ['name' => 'Keller', 'capacity' => 3200, 'initial_stock' => 400, 'installed_on' => '2023-01-01'])['id'];
        $this->readings->create('strom', ['meter_id' => $this->strom, 'date' => '2023-02-01', 'counter' => 100]);
    }

    /** @return array<string, array{0: string, 1: callable(self): void}> Eingang → Aufruf mit dem Wert, den die Regel abweist */
    public static function entries(): array
    {
        return [
            'Zählerstand: Ablesung (REST, KI-Übernahme)'      => ['counter', fn(self $t) => $t->readings->create('strom', ['meter_id' => $t->strom, 'date' => '2023-03-01', 'counter' => 4e12])],
            'Zählerstand: Ablesung ändern'                    => ['counter', fn(self $t) => $t->readings->update('strom', (string)$t->readings->list('strom', $t->strom)[0]['id'], ['counter' => 4e12])],
            'Zählerstand: Anfangsstand am Zähler'             => ['counter', fn(self $t) => $t->meters->create('gas', ['name' => 'Z', 'installed_on' => '2023-01-01', 'initial_counter' => 4e12])],
            'Zählerstand: Ingest'                             => ['counter', fn(self $t) => (new IngestService($t->meters, $t->readings, $t->i18n))->ingest(['utility' => 'strom', 'meter' => $t->strom, 'value' => 4e12, 'date' => '2023-03-01'])],
            'Wahrheitswert: Vertrag'                          => ['bool', fn(self $t) => $t->contracts->create('strom', ['meter_id' => $t->strom, 'provider' => 'P', 'start' => '2023-01-01', 'is_shadow' => 'kaum',
                                                                    'working_prices' => [['from' => '2023-01-01', 'ct_per_kwh' => 30]]])],
            'Wahrheitswert: Zähler'                           => ['bool', fn(self $t) => $t->meters->update('strom', $t->strom, ['heat_source' => 'kaum'])],
            'Zahl mit Grenze: Tank ändern'                    => ['number', fn(self $t) => $t->meters->update('heizoel', $t->tank, ['initial_stock' => 1e300])],
            'Zahl mit Grenze: Lieferung'                      => ['number', fn(self $t) => (new DeliveryService($t->store, $t->meters, $t->i18n))->create('heizoel',
                                                                    ['meter_id' => $t->tank, 'date' => '2023-05-01', 'quantity' => 500, 'total_eur' => -7.5])],
            'Tag im Bereich: Ablesung'                        => ['date', fn(self $t) => $t->readings->create('strom', ['meter_id' => $t->strom, 'date' => '0150-03-01', 'counter' => 120])],
            'Tag im Bereich: Peilstand'                       => ['date', fn(self $t) => $t->meters->update('heizoel', $t->tank, ['tank_levels' => [['date' => '0150-03-01', 'level' => 100]]])],
            'Text: Zäsur'                                     => ['text', fn(self $t) => $t->meters->update('strom', $t->strom, ['baseline_events' => [['date' => '2023-04-01', 'label' => ['p1']]]])],
        ];
    }

    #[DataProvider('entries')]
    public function testEveryWritePathRejectsWhatTheRuleRejects(string $kind, callable $call): void
    {
        $before = [$this->store->read('strom/readings.json', []), $this->store->read('heizoel/meters.json', []), $this->store->read('strom/meters.json', [])];
        try {
            $call($this);
            self::fail("angenommen ($kind)");
        } catch (\InvalidArgumentException $e) {
            self::assertNotSame('', $e->getMessage());
        }
        self::assertSame($before, [$this->store->read('strom/readings.json', []), $this->store->read('heizoel/meters.json', []), $this->store->read('strom/meters.json', [])], 'nichts geschrieben');
    }

    public function testTheCsvImportReportsTheRowForTheSameRule(): void
    {
        $r = (new ReadingImportService($this->readings, $this->meters, $this->i18n))->importCsv('strom', $this->strom, "Datum;Zaehlerstand\n2023-03-01;4000000000000\n");
        self::assertSame([0, 1], [$r['imported'], $r['skipped']]);
    }

    /** Dieselben Werte im Backup: gemeldet, nichts eingespielt; im Bestand: aus der Rechnung bzw. nicht eingetragen. */
    public function testBackupAndStockUseTheSameRule(): void
    {
        $bad = [
            'readings' => [['id' => 'r1', 'meter_id' => 'm', 'date' => '2023-03-01', 'counter' => 4e12], 'counter:counter', true],
            'meters'   => [['id' => 'm1', 'devices' => [], 'initial_stock' => 1e300], 'number:initial_stock', false],
            'contracts' => [['id' => 'c1', 'meter_id' => 'm', 'start' => '2023-01-01', 'is_shadow' => 'kaum'], 'bool:is_shadow', false],
            'deliveries' => [['id' => 'd1', 'meter_id' => 'm', 'date' => '2023-05-01', 'quantity' => 500, 'total_eur' => -7.5], 'number:total_eur', false],
            'periods'  => [['id' => 'p1', 'meter_id' => 'm', 'from' => '0003-02-01', 'to' => '2023-01-31', 'value' => 5], 'date_range:from', true],
        ];
        foreach ($bad as $pot => [$row, $problem, $excluded]) {
            self::assertContains($problem, FieldRules::problems($row, $pot), $pot);
            $usable = FieldRules::usable($row, $pot);
            self::assertSame($excluded, $usable === null, "$pot: Bestand " . ($excluded ? 'aus der Rechnung' : 'ohne das Feld'));
            if (!$excluded) self::assertNull($usable[explode(':', $problem)[1]] ?? null, "$pot: das Feld gilt als nicht eingetragen");
        }
        // Backup-Import: derselbe Wert (Tank) wird gemeldet
        $backup = new BackupService($this->store, $this->i18n);
        $dump = $backup->export(false);
        $dump['utilities']['heizoel']['meters'][0]['initial_stock'] = 1e300;
        try {
            $backup->import($dump);
            self::fail('Backup mit Tankbestand 10³⁰⁰ eingespielt');
        } catch (BackupInvalidException $e) {
            self::assertContains('number:initial_stock', array_column($e->problems, 'problem'));
        }
    }

    /**
     * Bestand, Einträge einer Liste: Ein Eintrag, der kein Objekt ist, fällt weg, die Liste bleibt; ein Gerät bleibt
     * auch mit unbrauchbarem Tag (nur der Tag gilt als nicht eingetragen); ein Preis ohne brauchbaren Tag fällt weg.
     */
    public function testEntriesOfAListInTheStock(): void
    {
        $dev = ['id' => 'd1', 'installed_on' => '2024-01-01', 'initial_counter' => 0];
        $m = FieldRules::usable(['id' => 'm1', 'devices' => [$dev, 'kaputt']], 'meters');
        self::assertNotNull($m, 'der Zähler bleibt in der Rechnung');
        self::assertSame([$dev], $m['devices']);
        $m = FieldRules::usable(['id' => 'm1', 'devices' => [['id' => 'd1', 'installed_on' => '1850-06-01']]], 'meters');
        self::assertSame([['id' => 'd1', 'installed_on' => null]], $m['devices'] ?? null, 'das Gerät bleibt');
        $price = ['from' => '2024-01-01', 'ct_per_kwh' => 27.3];
        $c = FieldRules::usable(['id' => 'c1', 'start' => '2024-01-01', 'working_prices' => [$price, 7, ['from' => '1850-01-01', 'ct_per_kwh' => 9]]], 'contracts');
        self::assertSame([$price], $c['working_prices'] ?? null, 'nur der brauchbare Preis bleibt');
        $c = FieldRules::usable(['id' => 'c1', 'start' => '2024-01-01', 'working_prices' => 'keine Liste'], 'contracts');
        self::assertNotNull($c);
        self::assertArrayHasKey('working_prices', $c);
        self::assertNull($c['working_prices'], 'keine Liste: gilt als nicht eingetragen');
        self::assertNull(FieldRules::usable(['id' => 'm1', 'devices' => 'keine Liste'], 'meters'), 'Geräteliste ist Pflicht');
    }

    /** Keine eigene Regel für Wahrheitswerte an den Eingängen: kein `(bool)` auf eine Eingabe, kein FILTER_VALIDATE_BOOL. */
    public function testNoWritePathParsesTruthValuesItself(): void
    {
        $hits = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname(__DIR__, 3) . '/src', \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if (!str_ends_with((string)$f, '.php')) continue;
            foreach (file((string)$f) ?: [] as $n => $line) {
                if (preg_match('#^\s*(\*|//)#', $line)) continue;   // Kommentare
                if (preg_match('/\(bool\)\s*\$(req->input|input\[|in\[|body\[|payload\[)|FILTER_VALIDATE_BOOL|queryParam\(\'[a-z_]+\'\) === \'1\'/', $line)) {
                    $hits[] = basename((string)$f) . ':' . ($n + 1);
                }
            }
        }
        // Ausnahmen mit Grund: Argumente einer Vorlage der KI (Prompts, kein Schreibweg); Anzeigeschalter in der Adresse
        // von Lesewegen (GET: Bericht, PDF, Monate, Empfehlungen, Flexibilität, Rechte) schreiben nichts
        $allowed = '/^(Prompts\.php|ReportController\.php|RightsController\.php|RecommendationController\.php|Co2Controller\.php|FlexibilityController\.php|ConsumptionController\.php|EvChargingController\.php):/';
        self::assertSame([], array_values(array_filter($hits, fn($h) => !preg_match($allowed, $h))), 'eigene Lesart für Wahrheitswerte');
    }
}
