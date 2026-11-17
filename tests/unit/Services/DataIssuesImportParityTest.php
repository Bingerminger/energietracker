<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\BackupInvalidException;
use Energietracker\Services\BackupService;
use Energietracker\Services\DataIssues;
use Energietracker\Support\FieldRules;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * v3.13.0 — Eine Regel je Wert für den Bestand und den Backup-Import: Derselbe Datenordner mit Werten, die die Schreibwege
 * heute abweisen (wie aus einer älteren Version oder von Hand geändert), ergibt in den Funden des Bestands
 * (`/api/health` `data_issues`, Diagnose) und im Import des eigenen Exports dieselben Funde, je Topf, Zeile und Feld.
 * Bisher meldete der Bestand einen Fälligkeitstag von 40 und Verweise ins Leere nicht, während der Import des eigenen
 * Exports an ihnen scheiterte; umgekehrt spielte der Import einen Elternzähler ein, den es nicht gibt, und nannte nach
 * dem ersten Fund in einem Topf die weiteren nicht.
 */
#[CoversClass(DataIssues::class)]
#[CoversClass(BackupService::class)]
#[CoversClass(FieldRules::class)]
final class DataIssuesImportParityTest extends ServiceTestCase
{
    private BackupService $backup;

    protected function setUp(): void
    {
        parent::setUp();
        $this->backup = new BackupService($this->store, $this->i18n);
        $main = (string)$this->meters->list('strom')[0]['id'];
        $this->meters->create('strom', ['name' => 'Werkstatt', 'installed_on' => '2025-02-01']);
        foreach (['2025-02-03' => 4100, '2025-03-04' => 4390, '2025-04-02' => 4655, '2025-05-05' => 4902, '2025-06-03' => 5120] as $d => $c) {
            $this->readings->create('strom', ['meter_id' => $main, 'date' => $d, 'counter' => $c]);
        }
        $water = (string)$this->meters->list('wasser')[0]['id'];
        $this->readings->create('wasser', ['meter_id' => $water, 'date' => '2025-02-03', 'counter' => 812.4]);
        $this->contracts->create('strom', ['meter_id' => $main, 'provider' => 'Stadtwerk Beispielfeld', 'start' => '2025-02-01',
            'working_prices' => [['from' => '2025-02-01', 'ct_per_kwh' => 31.2]], 'advance_payments' => [['from' => '2025-02-01', 'amount_eur' => 64]]]);
    }

    /** Werte, die eine ältere Version oder eine Hand gespeichert hat: direkt in die Dateien. */
    private function poison(): void
    {
        $s = (array)$this->store->read('settings.json', []);
        $s['advance_due_day'] = 40;
        $s['location_name'] = str_repeat('Ortsteil ', 16);   // 144 Zeichen, erlaubt 120
        $this->store->write('settings.json', $s);
        $m = (array)$this->store->read('strom/meters.json', []);
        $m[1]['parent_meter_id'] = 'm_strom_abgebaut';
        $m[1]['meter_group_id'] = 'g_strom_aufgeloest';
        $this->store->write('strom/meters.json', $m);
        $r = (array)$this->store->read('strom/readings.json', []);
        $r[0]['is_estimated'] = 'womöglich';
        $r[1]['counter'] = 'elf';
        $r[2]['note'] = str_repeat('Keller, Regal links. ', 100);   // 2.100 Zeichen, erlaubt 2.000
        $r[3]['price_cents'] = '29,8';   // gültige Schreibweise: kein Fund
        $this->store->write('strom/readings.json', $r);
        $w = (array)$this->store->read('wasser/readings.json', []);
        $w[0]['meter_id'] = 'm_wasser_frueher';
        $this->store->write('wasser/readings.json', $w);
        $c = (array)$this->store->read('strom/contracts.json', []);
        $c[0]['meter_group_id'] = 'g_strom_frueher';
        $this->store->write('strom/contracts.json', $c);
    }

    /**
     * Fund als „Topf | Zeile | Feld“: Zeile ist der Index (Einstellungen: der Schlüssel), Feld das oberste Feld des
     * Datensatzes. Die Arten heißen in beiden Listen teils verschieden (der Import meldet einen Zählerstand seit v3.12 als
     * `counter`, der Bestand als `counter:counter`); verglichen wird, WAS gefunden ist.
     */
    private static function key(string $pot, int|string|null $row, string $problem): string
    {
        $field = str_contains($problem, ':') ? explode(':', $problem, 2)[1] : $problem;
        return "$pot | $row | " . explode('.', $field, 2)[0];
    }

    /** @return list<string> */
    private function bestand(): array
    {
        $out = [];
        foreach ((new DataIssues($this->store))->scan() as $i) {
            foreach ($i['problems'] as $p) $out[] = self::key($i['pot'], $i['pot'] === 'settings' ? $i['id'] : $i['index'], $p);
        }
        sort($out);
        return array_values(array_unique($out));
    }

    /** @return list<string> */
    private function import(): array
    {
        try {
            $this->backup->import($this->backup->export(false), true);
            return [];
        } catch (BackupInvalidException $e) {
            $out = [];
            foreach ($e->problems as $p) {
                $row = $p['pot'] === 'settings' ? explode(':', (string)$p['problem'], 2)[1] : $p['index'];
                $out[] = self::key((string)$p['pot'], $row, (string)$p['problem']);
            }
            sort($out);
            return array_values(array_unique($out));
        }
    }

    public function testAPoisonedFolderGivesTheSameFindingsInTheStockAndInTheImportOfItsOwnExport(): void
    {
        self::assertSame([], $this->bestand(), 'sauberer Haushalt ohne Fund');
        self::assertSame([], $this->import(), 'sauberer Haushalt: eigener Export geht durch');
        $this->poison();
        $expected = [
            'settings | advance_due_day | advance_due_day',
            'settings | location_name | location_name',
            'strom/contracts | 0 | meter_group_id',
            'strom/meters | 1 | meter_group_id',
            'strom/meters | 1 | parent_meter_id',
            'strom/readings | 0 | is_estimated',
            'strom/readings | 1 | counter',
            'strom/readings | 2 | note',
            'wasser/readings | 0 | meter_id',
        ];
        self::assertSame($expected, $this->bestand(), 'Bestand');
        self::assertSame($expected, $this->import(), 'Import des eigenen Exports');
    }

    /**
     * Der Fälligkeitstag: 1 bis 31 wie PATCH /api/settings, Rand davor und danach. Gemeldet wird er im Bestand, gelesen
     * bleibt er geklemmt (Paket wechsel: 40 rechnet als 31, 0 als 1), die Wirkung heißt deshalb „bleibt“.
     */
    public function testTheDueDayIsReportedOutsideOneToThirtyOneAndStillReadClamped(): void
    {
        $this->settings->set(['advance_counting' => 'due_day']);
        foreach ([0 => 1, 1 => null, 31 => null, 32 => 31, 47 => 31] as $day => $clamped) {
            $s = (array)$this->store->read('settings.json', []);
            $s['advance_due_day'] = $day;
            $this->store->write('settings.json', $s);
            $found = array_values(array_filter((new DataIssues($this->store))->scan(), fn($i) => $i['pot'] === 'settings'));
            if ($clamped === null) {
                self::assertSame([], $found, "Tag $day");
                self::assertSame([], $this->import(), "Tag $day: eigener Export geht durch");
                continue;
            }
            self::assertSame([['pot' => 'settings', 'index' => null, 'id' => 'advance_due_day', 'problems' => ['setting:advance_due_day'],
                'excluded' => false, 'effect' => 'kept']], $found, "Tag $day");
            self::assertSame(['settings | advance_due_day | advance_due_day'], $this->import(), "Tag $day: Import des Exports");
            self::assertSame($clamped, (new \Energietracker\Services\SettingsService($this->store))->advanceDueDay(), "Tag $day geklemmt gelesen");
        }
    }

    /**
     * Eine geleerte Einstellung, die eine ältere Version als null schrieb, heißt „nicht eingetragen“: kein Fund im Bestand,
     * und der eigene Export spielt sich ein (bisher wies der Import ihn ab, etwa an `forecast_model: null`). Leer ('')
     * bleibt ein Fund mit der Vorgabe, wie bisher, in Bestand und Import.
     */
    public function testANullSettingIsNotEnteredInTheStockAndInTheImport(): void
    {
        $s = (array)$this->store->read('settings.json', []);
        $s['forecast_model'] = null;
        $s['ocr_timeout_s'] = null;
        $this->store->write('settings.json', $s);
        self::assertSame([], $this->bestand());
        self::assertSame([], $this->import());
        $s['forecast_model'] = '';
        $this->store->write('settings.json', $s);
        self::assertSame(['settings | forecast_model | forecast_model'], $this->bestand());
        self::assertSame(['settings | forecast_model | forecast_model'], $this->import());
    }

    /** Wirkung je Fund in der Diagnose: Verweise und lange Texte bleiben, ein unbrauchbares Feld gilt als nicht eingetragen. */
    public function testEachFindingNamesItsEffect(): void
    {
        $this->poison();
        $effects = [];
        foreach ((new DataIssues($this->store))->scan() as $i) $effects[$i['pot'] . '#' . ($i['index'] ?? $i['id'])] = $i['effect'];
        ksort($effects);
        self::assertSame([
            'settings#advance_due_day' => 'kept', 'settings#location_name' => 'kept',
            'strom/contracts#0' => 'kept', 'strom/meters#1' => 'kept',
            'strom/readings#0' => 'field', 'strom/readings#1' => 'excluded', 'strom/readings#2' => 'kept',
            'wasser/readings#0' => 'kept',
        ], $effects);
        // ein Feld, das der Bestand mit der Vorgabe liest
        $s = (array)$this->store->read('settings.json', []);
        $s['forecast_model'] = ['linear'];
        $this->store->write('settings.json', $s);
        $byId = array_column(array_filter((new DataIssues($this->store))->scan(), fn($i) => $i['pot'] === 'settings'), 'effect', 'id');
        self::assertSame('default', $byId['forecast_model'] ?? null);
    }
}
