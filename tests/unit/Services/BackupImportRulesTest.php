<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\BackupInvalidException;
use Energietracker\Services\BackupService;
use Energietracker\Support\FieldRules;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * v3.13.0 (K2d) — Der Backup-Import prüft mit denselben Regeln wie die Schreibwege (Support\FieldRules) und schreibt,
 * was er annimmt, so, wie die Schreibwege speichern. Bisher spielte er ein: eine Ablesung an einem unbekannten oder
 * artfremden Zähler, einen Preis „abc“ oder −5, eine Notiz als Objekt, zwei Ablesungen mit derselben Kennung, einen
 * Zählernamen und einen Termintitel als Liste (danach 500 auf Übersicht und Agenda), einen Tankbestand von 10³⁰⁰
 * (danach 500 auf Übersicht und Jahresbericht) und Wahrheitswerte als Text (`"false"`), die die Leser mit `empty()`
 * als ja nahmen: Ein Vertrag mit `is_shadow: "false"` fiel aus dem Saldo.
 */
#[CoversClass(BackupService::class)]
#[CoversClass(FieldRules::class)]
final class BackupImportRulesTest extends ServiceTestCase
{
    private BackupService $backup;
    private string $strom;
    private string $wasser;
    private string $tank;

    protected function setUp(): void
    {
        parent::setUp();
        $this->backup = new BackupService($this->store, $this->i18n);
        $this->strom = (string)$this->meters->list('strom')[0]['id'];
        $this->wasser = (string)$this->meters->list('wasser')[0]['id'];
        $this->readings->create('strom', ['meter_id' => $this->strom, 'date' => '2023-01-02', 'counter' => 18000]);
        $this->readings->create('strom', ['meter_id' => $this->strom, 'date' => '2023-07-03', 'counter' => 19650]);
        $this->readings->create('wasser', ['meter_id' => $this->wasser, 'date' => '2023-01-02', 'counter' => 310]);
        $this->contracts->create('strom', ['meter_id' => $this->strom, 'provider' => 'Stadtwerk Probe', 'start' => '2023-01-01',
            'working_prices' => [['from' => '2023-01-01', 'ct_per_kwh' => 29]], 'advance_payments' => [['from' => '2023-01-01', 'amount_eur' => 70]]]);
        $this->tank = (string)$this->meters->create('heizoel', ['name' => 'Kellertank', 'capacity' => 4500, 'initial_stock' => 1200,
            'installed_on' => '2023-01-01', 'tank_levels' => [['date' => '2023-03-01', 'level' => 1100]]])['id'];
        $this->store->write('reminders.json', [['id' => 'rem_k2d', 'title' => 'Schornsteinfeger', 'next_due' => '2027-02-01']]);
    }

    /** @return array<string, array{0: callable(array): array, 1: string}> */
    public static function poisoned(): array
    {
        $at = fn(string $u, string $pot, int $i, array $patch) => function (array $d) use ($u, $pot, $i, $patch): array {
            $d['utilities'][$u][$pot][$i] = array_merge($d['utilities'][$u][$pot][$i], $patch);
            return $d;
        };
        return [
            'Ablesung an unbekanntem Zähler'      => [$at('strom', 'readings', 0, ['meter_id' => 'm_strom_unbekannt']), 'reference:meter_id'],
            'Wasser-Ablesung am Stromzähler'      => [fn(array $d) => self::wasserAmStrom($d), 'reference:meter_id'],
            'Preis als Wort'                      => [$at('strom', 'readings', 0, ['price_cents' => 'billig']), 'number:price_cents'],
            'Preis negativ'                       => [$at('strom', 'readings', 0, ['price_cents' => -3]), 'number:price_cents'],
            'Notiz als Objekt'                    => [$at('strom', 'readings', 1, ['note' => ['x' => 2]]), 'text:note'],
            'zweimal dieselbe Kennung'            => [function (array $d): array { $d['utilities']['strom']['readings'][1]['id'] = $d['utilities']['strom']['readings'][0]['id']; return $d; }, 'duplicate_id'],
            'Zählername als Liste'                => [$at('strom', 'meters', 0, ['name' => ['Haus']]), 'text:name'],
            'Tankbestand 10^306'                  => [fn(array $d) => self::tank($d, ['initial_stock' => 5e306]), 'number:initial_stock'],
            'Tankgröße als Wort'                  => [fn(array $d) => self::tank($d, ['capacity' => 'groß']), 'number:capacity'],
            'Preis des Anfangsbestands negativ'   => [fn(array $d) => self::tank($d, ['initial_stock_price_ct' => -3]), 'number:initial_stock_price_ct'],
            'Peilstand im Jahr 0099'              => [fn(array $d) => self::tank($d, ['tank_levels' => [['date' => '0099-03-01', 'level' => 900]]]), 'date_range:tank_levels.date'],
            'Anfangsstand des Geräts über 10^12'  => [function (array $d): array { $d['utilities']['strom']['meters'][0]['devices'][0]['initial_counter'] = 4e12; return $d; }, 'counter:devices.initial_counter'],
            'Termintitel als Liste'               => [function (array $d): array { $d['reminders'][0]['title'] = ['Kamin']; return $d; }, 'text:title'],
            'Vertrag: Schatten als Wort'          => [$at('strom', 'contracts', 0, ['is_shadow' => 'kaum']), 'bool:is_shadow'],
            'Lieferung mit negativem Betrag'      => [fn(array $d) => self::delivery($d, ['total_eur' => -7.5]), 'number:total_eur'],
            // v3.13.0 — Verweise am Zähler nach derselben Regel wie der Bestand (FieldRules::references)
            'Elternzähler ins Leere'              => [$at('strom', 'meters', 0, ['parent_meter_id' => 'm_strom_abgebaut']), 'reference:parent_meter_id'],
            'Gruppe am Zähler ins Leere'          => [$at('strom', 'meters', 0, ['meter_group_id' => 'g_strom_aufgeloest']), 'reference:meter_group_id'],
            'Gruppe einer anderen Art am Zähler'  => [function (array $d): array {
                $d['utilities']['gas']['meter_groups'] = [['id' => 'g_gas_k4b', 'name' => 'Gas oben']];
                $d['utilities']['strom']['meters'][0]['meter_group_id'] = 'g_gas_k4b';
                return $d; }, 'reference:meter_group_id'],
            'Vertrag an einer Gruppe ins Leere'   => [$at('strom', 'contracts', 0, ['meter_group_id' => 'g_strom_frueher']), 'reference:meter_group_id'],
            'Brennwert-Abschnitt bis 2101'        => [function (array $d): array {
                $d['utilities']['gas']['bills'] = [['id' => 'b_k2d', 'meter_id' => (string)$d['utilities']['gas']['meters'][0]['id'], 'period_from' => '2023-01-01',
                    'period_to' => '2023-12-31', 'factors' => [['from' => '2023-01-01', 'to' => '2101-03-15', 'zustandszahl' => 0.97, 'brennwert' => 11.3]]]];
                return $d; }, 'date_range:factors.to'],
        ];
    }

    private static function wasserAmStrom(array $d): array
    {
        $d['utilities']['wasser']['readings'][0]['meter_id'] = (string)$d['utilities']['strom']['meters'][0]['id'];
        return $d;
    }

    private static function tank(array $d, array $patch): array
    {
        $d['utilities']['heizoel']['meters'][0] = array_merge($d['utilities']['heizoel']['meters'][0], $patch);
        return $d;
    }

    private static function delivery(array $d, array $patch): array
    {
        $d['utilities']['heizoel']['deliveries'] = [array_merge(['id' => 'del_k2d', 'meter_id' => (string)$d['utilities']['heizoel']['meters'][0]['id'],
            'date' => '2023-05-04', 'quantity' => 800], $patch)];
        return $d;
    }

    #[DataProvider('poisoned')]
    public function testWhatTheWriteRoutesRejectTheImportRejects(callable $poison, string $expected): void
    {
        $dump = $poison($this->backup->export(false));
        $before = [$this->store->read('strom/readings.json', []), $this->store->read('heizoel/meters.json', []), $this->store->read('reminders.json', [])];
        try {
            $this->backup->import($dump);
            self::fail("eingespielt trotz $expected");
        } catch (BackupInvalidException $e) {
            $found = array_column($e->problems, 'problem');
            self::assertContains($expected, $found, json_encode($found));
        }
        self::assertSame($before, [$this->store->read('strom/readings.json', []), $this->store->read('heizoel/meters.json', []), $this->store->read('reminders.json', [])], 'nichts eingespielt');
    }

    /** Was die eine Regel annimmt, schreibt der Import, wie die Schreibwege speichern: „false“ ist false, „12,5“ ist 12.5. */
    public function testAcceptedSpellingsAreWrittenAsTheWriteRoutesStoreThem(): void
    {
        $dump = $this->backup->export(false);
        $c0 = $this->contracts->list('strom')[0];
        $dump['utilities']['strom']['contracts'][0]['is_shadow'] = 'false';
        $dump['utilities']['strom']['contracts'][0]['auto_renews'] = 'ja';
        $dump['utilities']['strom']['readings'][0]['is_billing_only'] = 'nein';
        $dump['utilities']['strom']['readings'][0]['price_cents'] = '31,5';
        $dump['utilities']['strom']['meters'][0]['active'] = 'Ja';
        $dump['reminders'][0]['active'] = 'off';
        $this->backup->import($dump);

        $c = $this->contracts->list('strom')[0];
        self::assertFalse($c['is_shadow']);
        self::assertTrue($c['auto_renews']);
        $r = $this->store->read('strom/readings.json', [])[0];
        self::assertFalse($r['is_billing_only']);
        self::assertSame(31.5, $r['price_cents']);
        self::assertTrue($this->store->read('strom/meters.json', [])[0]['active']);
        self::assertFalse($this->store->read('reminders.json', [])[0]['active']);
        // der Vertrag rechnet weiter wie vorher (bisher fiel er mit dem Text „false“ als Schatten aus dem Saldo)
        $status = $this->consumption->contractStatus('strom', (array)$this->meters->get('strom', $this->strom), '2023-08-01');
        self::assertContains($c0['id'], array_column($status['contracts'] ?? [], 'contract_id'), json_encode($status));
    }

    /**
     * v3.13.0 — Der Bericht nennt jeden Fund, auch mehrere in derselben Datei und derselben Zeile (bisher endete die
     * Prüfung am ersten: Ein Stand „abc“ verdeckte einen Wahrheitswert „eventuell“ dahinter), jedes Feld einer Zeile einmal.
     */
    public function testTheReportNamesEveryFindingNotOnlyTheFirstOfAFile(): void
    {
        $dump = $this->backup->export(false);
        $dump['utilities']['strom']['readings'][0]['counter'] = 'achtzehntausend';
        $dump['utilities']['strom']['readings'][0]['is_billing_only'] = 'eventuell';
        $dump['utilities']['strom']['readings'][1]['is_estimated'] = 'womöglich';
        $dump['utilities']['strom']['meters'][0]['parent_meter_id'] = 'm_strom_abgebaut';
        $dump['reminders'][0]['recurrence'] = 'irgendwann';
        $dump['reminders'][0]['title'] = ['Kamin'];
        // zwei kaputte Felder in den Einträgen derselben Liste einer Zeile: zwei Funde
        $dump['utilities']['heizoel']['meters'][0]['tank_levels'] = [['date' => '0098-03-01', 'level' => -6]];
        try {
            $this->backup->import($dump, true);
            self::fail('eingespielt');
        } catch (BackupInvalidException $e) {
            $found = array_map(fn($p) => $p['pot'] . '#' . $p['index'] . ' ' . $p['problem'], $e->problems);
            sort($found);
            self::assertSame([
                'heizoel/meters#0 date_range:tank_levels.date', 'heizoel/meters#0 number:tank_levels.level',
                'reminders#0 recurrence', 'reminders#0 text:title',
                'strom/meters#0 reference:parent_meter_id',
                'strom/readings#0 bool:is_billing_only', 'strom/readings#0 counter',
                'strom/readings#1 bool:is_estimated',
            ], $found);
        }
    }

    /** v3.13.0 — Eine Rechnung ohne Zähler zählt für die CO₂-Kosten der Art (Bestand); der Import nimmt sie ebenso an. */
    public function testABillWithoutAMeterImportsLikeTheStockReadsIt(): void
    {
        $dump = $this->backup->export(false);
        $dump['utilities']['gas']['bills'] = [['id' => 'b_k4b', 'meter_id' => null, 'period_from' => '2023-01-01', 'period_to' => '2023-12-31']];
        self::assertSame([], $this->backup->import($dump, true)['problems']);
        $dump['utilities']['gas']['bills'][0]['meter_id'] = 'm_gas_abgebaut';
        try {
            $this->backup->import($dump, true);
            self::fail('Rechnung an unbekanntem Zähler eingespielt');
        } catch (BackupInvalidException $e) {
            self::assertContains('reference:meter_id', array_column($e->problems, 'problem'));
        }
    }

    public function testTheOwnExportOfEveryUtilityStillImports(): void
    {
        $dump = $this->backup->export(false);
        self::assertSame([], $this->backup->import($dump, true)['problems']);
        $this->backup->import($dump);
        self::assertSame($dump['utilities'], $this->backup->export(false)['utilities'], 'Rundreise ohne Änderung');
    }
}
