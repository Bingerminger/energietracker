<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\CsvExportService;
use Energietracker\Services\DeliveryService;
use Energietracker\Services\PeriodService;
use Energietracker\Services\ReadingImportService;
use Energietracker\Services\ReminderService;
use Energietracker\Services\TemperatureService;
use Energietracker\Services\TenancyService;
use Energietracker\Services\WeatherService;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * v3.12.0 — die übrigen Schreibwege mit derselben Regel:
 *   - Wahrheitswerte nach Support\Input::bool: „false“ machte eine Ablesung bisher verdächtig bzw. zu
 *     „nur Abrechnung“, „nein“ einen Zähler aktiv, eine Mietabrechnung gebucht, einen Termin aktiv.
 *   - Obergrenzen: 10³⁰⁸ Liter, Zeitraumwerte, Termin-Monate.
 *   - Termin: ein unbekannter Rhythmus wurde still „none“, „12,5“ Monate 12, ein Datum im Jahr 0001 ging durch.
 *   - CSV: Leerraum am Rand der Notiz fiel weg, „Geschätzt“ der Zeiträume ging verloren, JSON statt CSV
 *     meldete „0 übernommen“ ohne Fehler.
 */
#[CoversClass(ReminderService::class)]
#[CoversClass(PeriodService::class)]
#[CoversClass(ReadingImportService::class)]
final class RemainingInputsStrictTest extends ServiceTestCase
{
    private function rejects(callable $fn, string $what): void
    {
        try {
            $fn();
            self::fail("angenommen: $what");
        } catch (\InvalidArgumentException) {
            self::addToAssertionCount(1);
        }
    }

    public function testReadingFlagsReadFalseAsFalse(): void
    {
        $m = (string)$this->meters->list('strom')[0]['id'];
        $r = $this->readings->create('strom', ['meter_id' => $m, 'date' => '2025-01-01', 'counter' => 10, 'is_suspect' => 'false', 'is_billing_only' => 'nein']);
        self::assertArrayNotHasKey('is_suspect', $r, '„false“ ist nicht verdächtig');
        self::assertArrayNotHasKey('is_billing_only', $r, '„nein“ ist nicht nur Abrechnung');
        $this->rejects(fn() => $this->readings->create('strom', ['meter_id' => $m, 'date' => '2025-02-01', 'counter' => 20, 'is_suspect' => 'vielleicht']), 'is_suspect');
        $p = $this->readings->update('strom', (string)$r['id'], ['is_verified' => 'ja', 'is_billing_only' => 'off']);
        self::assertTrue($p['is_verified']);
        self::assertArrayNotHasKey('is_billing_only', $p);
        $this->rejects(fn() => $this->readings->update('strom', (string)$r['id'], ['is_billing_only' => []]), 'is_billing_only []');
    }

    public function testMeterActiveIsABoolean(): void
    {
        $m = (string)$this->meters->list('strom')[0]['id'];
        self::assertFalse($this->meters->update('strom', $m, ['active' => 'nein'])['active']);
        self::assertTrue($this->meters->update('strom', $m, ['active' => 'ja'])['active']);
        $this->rejects(fn() => $this->meters->update('strom', $m, ['active' => 'vielleicht']), 'active');
        $this->rejects(fn() => $this->meters->update('strom', $m, ['digits' => 1e308]), 'digits 1e308');
    }

    public function testReminderFieldsAreChecked(): void
    {
        $rem = new ReminderService($this->store, $this->settings, $this->i18n);
        $this->rejects(fn() => $rem->create(['title' => 'T', 'next_due' => '2026-12-01', 'recurrence' => 'stündlich']), 'recurrence');
        $this->rejects(fn() => $rem->create(['title' => 'T', 'next_due' => '2026-12-01', 'recurrence' => 'custom-months', 'recurrence_months' => '12,5']), '12,5 Monate');
        $this->rejects(fn() => $rem->create(['title' => 'T', 'next_due' => '2026-12-01', 'recurrence' => 'custom-months', 'recurrence_months' => 1e308]), '1e308 Monate');
        $this->rejects(fn() => $rem->create(['title' => 'T', 'next_due' => '2026-12-01', 'last_done' => 'gestern']), 'last_done');
        $r = $rem->create(['title' => 'T', 'next_due' => '2026-12-01', 'recurrence' => 'custom-months', 'recurrence_months' => '18', 'last_done' => '2025-06-01']);
        self::assertSame(18, $r['recurrence_months']);
        self::assertSame('2025-06-01', $r['last_done'], 'angegeben und geprüft, nicht still null');
        self::assertFalse($rem->update((string)$r['id'], ['active' => 'nein'])['active']);
        $this->rejects(fn() => $rem->update((string)$r['id'], ['active' => 'vielleicht']), 'active');
        $this->rejects(fn() => $rem->update((string)$r['id'], ['last_done' => '0001-01-01']), 'last_done 0001');
        $this->rejects(fn() => $rem->update((string)$r['id'], ['recurrence' => 'jede Woche']), 'recurrence PATCH');
        $this->rejects(fn() => $rem->markDone((string)$r['id'], '0001-01-01'), 'erledigt 0001');
        $stored = array_values(array_filter((array)$this->store->read('reminders.json', []), fn($x) => ($x['id'] ?? null) === $r['id']))[0];
        self::assertSame(['custom-months', '2025-06-01', false], [$stored['recurrence'], $stored['last_done'], $stored['active']], 'der Termin blieb, wie er war');
    }

    public function testDeliveryAndPeriodNumbersHaveLimits(): void
    {
        $deliveries = new DeliveryService($this->store, $this->meters, $this->i18n);
        $tank = (string)$this->meters->create('heizoel', ['name' => 'Keller', 'installed_on' => '2024-01-01', 'capacity' => 3000, 'initial_stock' => 300])['id'];
        $this->rejects(fn() => $deliveries->create('heizoel', ['meter_id' => $tank, 'date' => '2025-01-10', 'quantity' => 1e308]), 'Menge 1e308');
        $this->rejects(fn() => $deliveries->create('heizoel', ['meter_id' => $tank, 'date' => '2025-01-10', 'quantity' => 1000, 'unit_price_cents' => 1e308]), 'Preis 1e308');
        $this->rejects(fn() => $deliveries->create('heizoel', ['meter_id' => $tank, 'date' => '2025-01-10', 'quantity' => 1000, 'fill_to_full' => 'vielleicht']), 'bis voll');
        self::assertSame(1000.0, (float)$deliveries->create('heizoel', ['meter_id' => $tank, 'date' => '2025-01-10', 'quantity' => '1000', 'fill_to_full' => 'nein'])['quantity']);

        $periods = new PeriodService($this->store, $this->meters, $this->i18n);
        $pm = (string)$this->meters->create('strom', ['name' => 'Portal', 'capture' => 'period', 'installed_on' => '2025-01-01'])['id'];
        $this->rejects(fn() => $periods->create('strom', ['meter_id' => $pm, 'month' => '2025-01', 'value' => 1e308]), 'Zeitraum 1e308');
    }

    public function testTenancyStatementBookedIsABoolean(): void
    {
        $ten = new TenancyService($this->store, $this->meters, $this->i18n);
        $t = $ten->create(['start' => '2024-01-01', 'label' => 'Wohnung']);
        $s = $ten->createStatement((string)$t['id'], ['period_from' => '2024-01-01', 'period_to' => '2024-12-31', 'booked' => 'nein']);
        self::assertFalse($s['booked'], '„nein“ ist nicht gebucht');
        $this->rejects(fn() => $ten->createStatement((string)$t['id'], ['period_from' => '2025-01-01', 'period_to' => '2025-12-31', 'booked' => 'vielleicht']), 'booked');
    }

    public function testCsvRoundTripsKeepNoteSpacesAndEstimatedPeriods(): void
    {
        $export = new CsvExportService($this->consumption, $this->readings, $this->meters,
            new TemperatureService($this->store, $this->settings, new WeatherService()),
            new DeliveryService($this->store, $this->meters, $this->i18n), $this->i18n);
        $import = new ReadingImportService($this->readings, $this->meters, $this->i18n);
        $m = (string)$this->meters->list('strom')[0]['id'];
        $this->readings->create('strom', ['meter_id' => $m, 'date' => '2025-01-01', 'counter' => 10, 'note' => '  Leer am Rand  ']);
        $this->readings->create('strom', ['meter_id' => $m, 'date' => '2025-02-01', 'counter' => 20, 'note' => 'ohne']);
        $csv = $export->readings('strom', '1');
        $this->store->write('strom/readings.json', []);
        $import->importCsv('strom', $m, $csv);
        self::assertSame(['  Leer am Rand  ', 'ohne'], array_column($this->readings->list('strom', $m), 'note'), 'Leerraum am Rand bleibt');

        // eine Notiz als letzte Zelle ohne Anführungszeichen behält ihren Leerraum ebenso
        $import->importCsv('strom', $m, "datum;zählerstand;notiz\n01.03.2025;30;  Rand  \n");
        self::assertSame('  Rand  ', array_values(array_filter($this->readings->list('strom', $m), fn($r) => $r['date'] === '2025-03-01'))[0]['note']);

        // JSON statt CSV: ein Fehler, nicht „0 übernommen“
        $this->rejects(fn() => $import->importCsv('strom', $m, '{"readings": [1, 2]}'), 'JSON statt CSV');

        $periods = new PeriodService($this->store, $this->meters, $this->i18n);
        $pm = (string)$this->meters->create('fernwaerme', ['name' => 'Portal', 'capture' => 'period', 'installed_on' => '2024-01-01'])['id'];
        foreach (['2025-01' => true, '2025-02' => false, '2025-03' => true] as $month => $est) {
            $periods->create('fernwaerme', ['meter_id' => $pm, 'month' => $month, 'value' => 500, 'is_estimated' => $est]);
        }
        $pcsv = $export->periods('fernwaerme', $periods->list('fernwaerme'));
        $this->store->write('fernwaerme/periods.json', []);
        $r = $periods->importCsv('fernwaerme', $pm, $pcsv);
        self::assertSame(3, $r['imported']);
        self::assertSame([true, false, true], array_column($periods->list('fernwaerme', $pm), 'is_estimated'), '„Geschätzt“ übersteht die Rundreise');
        // derselbe Export an einen anderen Zähler: gezählt, nicht still nichts
        $other = (string)$this->meters->create('fernwaerme', ['name' => 'Zweiter', 'capture' => 'period', 'installed_on' => '2024-01-01'])['id'];
        $r2 = $periods->importCsv('fernwaerme', $other, $pcsv);
        self::assertSame([0, 3], [$r2['imported'], $r2['other_meter_rows'] ?? 0]);
    }
}
