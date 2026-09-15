<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\ConversionFactorService;
use Energietracker\Storage\Migrator;
use Energietracker\Tests\Support\ServiceTestCase;

/**
 * v2.5.0 — F1012: Datierte Gas-Umrechnungsfaktoren (Zustandszahl × Brennwert).
 *
 * Aufbau nach dem Vorbild einer echten Jahresrechnung — mit **erfundenen**
 * Zahlen: eine konstante Zustandszahl und ein Brennwert, der dreimal im Jahr
 * wechselt, jeweils mit eigenem Zeitraum. Jede erwartete Kilowattstunde ist
 * von Hand nachrechenbar: m³ × z × Hs.
 */
final class ConversionFactorServiceTest extends ServiceTestCase
{
    private const Z = 0.9500;

    /** Drei Brennwertperioden, dazu der undatierte Altwert davor. */
    private const LISTE = [
        ['from' => null,         'kwh_per_m3' => 10.0],
        ['from' => '2025-09-01', 'zustandszahl' => self::Z, 'brennwert' => 11.600],
        ['from' => '2025-11-01', 'zustandszahl' => self::Z, 'brennwert' => 11.400],
        ['from' => '2026-02-01', 'zustandszahl' => self::Z, 'brennwert' => 11.500],
    ];

    private ConversionFactorService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->settings->set(['gas_conversion_factors' => self::LISTE]);
        $this->svc = new ConversionFactorService($this->settings, $this->i18n);
    }

    // ── 1. Normalisierung ─────────────────────────────────────────────

    public function testFactorIsDerivedFromZAndHsWithFivePlaces(): void
    {
        $list = $this->svc->gasFactors();
        self::assertCount(4, $list);
        // 0,95 × 11,6 = 11,02 exakt
        self::assertSame(11.02, $list[1]['kwh_per_m3']);
        // 0,95 × 11,4 = 10,83
        self::assertSame(10.83, $list[2]['kwh_per_m3']);
        // Zustandszahl und Brennwert bleiben als Beleg erhalten
        self::assertSame(self::Z, $list[1]['zustandszahl']);
        self::assertSame(11.6,    $list[1]['brennwert']);
    }

    public function testListIsSortedUndatedFirst(): void
    {
        $this->settings->set(['gas_conversion_factors' => [
            ['from' => '2026-02-01', 'kwh_per_m3' => 11.0],
            ['from' => null,         'kwh_per_m3' => 10.0],
            ['from' => '2025-09-01', 'kwh_per_m3' => 11.5],
        ]]);
        $dates = array_column($this->svc->gasFactors(), 'from');
        self::assertSame([null, '2025-09-01', '2026-02-01'], $dates);
    }

    public function testTypoIsRejectedOnSave(): void
    {
        // 115 statt 11,5 würde jeden Gasverbrauch verzehnfachen.
        $this->expectException(\InvalidArgumentException::class);
        $this->settings->set(['gas_conversion_factors' => [['from' => null, 'kwh_per_m3' => 115]]]);
    }

    public function testHalfPairIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->settings->set(['gas_conversion_factors' => [
            ['from' => '2026-01-01', 'zustandszahl' => 0.95],   // Brennwert fehlt
        ]]);
    }

    public function testTwoUndatedEntriesAreRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->settings->set(['gas_conversion_factors' => [
            ['from' => null, 'kwh_per_m3' => 10.0],
            ['from' => null, 'kwh_per_m3' => 11.0],
        ]]);
    }

    public function testDuplicateDateIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->settings->set(['gas_conversion_factors' => [
            ['from' => '2026-01-01', 'kwh_per_m3' => 10.0],
            ['from' => '2026-01-01', 'kwh_per_m3' => 11.0],
        ]]);
    }

    public function testCommaDecimalIsAccepted(): void
    {
        // Wer die Rechnung abtippt, tippt „11,600".
        $this->settings->set(['gas_conversion_factors' => [
            ['from' => null, 'zustandszahl' => '0,9500', 'brennwert' => '11,600'],
        ]]);
        self::assertEqualsWithDelta(11.02, $this->svc->gasFactors()[0]["kwh_per_m3"], 1e-9);
    }

    public function testReadIsTolerantButSaveIsStrict(): void
    {
        // Auf der Platte darf ein Wert außerhalb der Plausibilität stehen
        // (Test-Vorrichtungen, Altbestand) — er wird beim Lesen nicht still
        // durch den Default ersetzt.
        $this->store->write('settings.json', ['gas_conversion_factors' => [
            ['from' => null, 'kwh_per_m3' => 1.0],
        ]]);
        self::assertSame(1.0, $this->svc->factorOn('gas', '2026-01-01'));
    }

    // ── 2. Auflösung je Tag ───────────────────────────────────────────

    public function testResolvesTheEntryValidOnEachDay(): void
    {
        self::assertSame(10.0,  $this->svc->factorOn('gas', '2020-01-01'), 'vor dem ersten Stichtag: Altwert');
        self::assertSame(10.0,  $this->svc->factorOn('gas', '2025-08-31'), 'Tag vor dem ersten Stichtag');
        self::assertSame(11.02, $this->svc->factorOn('gas', '2025-09-01'), 'Stichtag selbst zählt schon');
        self::assertSame(11.02, $this->svc->factorOn('gas', '2025-10-31'));
        self::assertSame(10.83, $this->svc->factorOn('gas', '2025-11-01'));
        self::assertSame(10.925, $this->svc->factorOn('gas', '2026-06-01'), 'nach dem letzten: der letzte');
    }

    public function testNonGasUtilitiesKeepTheirScalar(): void
    {
        $this->settings->set(['heizoel_kwh_per_l' => 9.8]);
        self::assertSame(9.8, $this->svc->factorOn('heizoel', '2026-01-01'));
        self::assertSame(1.0, $this->svc->factorOn('strom', '2026-01-01'), 'ohne Umrechnung: 1');
    }

    public function testBoundariesAreOnlyTheDatesStrictlyInside(): void
    {
        self::assertSame(
            ['2025-11-01', '2026-02-01'],
            $this->svc->boundariesBetween('2025-09-01', '2026-03-01'),
            'Anfang und Ende selbst sind keine Grenzen'
        );
        self::assertSame([], $this->svc->boundariesBetween('2026-03-01', '2026-06-01'));
    }

    // ── 3. Die Rechnung selbst: tagesgenau über den Stichtag ─────────

    /**
     * Ein Ableseintervall 2025-10-01 → 2025-12-01 (61 Tage) mit 61 m³, also
     * 1 m³/Tag. Der Stichtag 2025-11-01 liegt mittendrin:
     *   Oktober  31 Tage × 1 m³ × 11,02 = 341,62 kWh
     *   November 30 Tage × 1 m³ × 10,83 = 324,90 kWh
     * Mit dem alten „ein Faktor je Zähler" wäre beides gleich gewesen.
     */
    public function testConsumptionSplitsDayExactlyAtTheCutoff(): void
    {
        $meterId = $this->setMeterDevices('gas', [[
            'id' => 'd_gas_1', 'serial' => null,
            'installed_on' => '2025-10-01', 'initial_counter' => 0.0,
            'removed_on' => null, 'final_counter' => null, 'reason' => null,
        ]]);
        $this->setReadings('gas', $meterId, [
            ['date' => '2025-10-01', 'counter' => 1000.0, 'device_id' => 'd_gas_1'],
            ['date' => '2025-12-01', 'counter' => 1061.0, 'device_id' => 'd_gas_1'],
        ]);

        $byYm = [];
        foreach ($this->consumption->forMeter('gas', $this->meters->get('gas', $meterId)) as $m) {
            $byYm[$m['ym']] = $m;
        }
        self::assertEqualsWithDelta(31.0,   $byYm['2025-10']['m3'],  0.05);
        self::assertEqualsWithDelta(341.62, $byYm['2025-10']['kwh'], 0.05);
        self::assertEqualsWithDelta(30.0,   $byYm['2025-11']['m3'],  0.05);
        self::assertEqualsWithDelta(324.90, $byYm['2025-11']['kwh'], 0.05);
    }

    /**
     * Der Stichtag liegt MITTEN im Monat — dann reicht die Monatsteilung
     * nicht, das Segment muss zusätzlich am Stichtag geschnitten werden.
     * Intervall 2025-11-01 → 2025-12-01 (30 Tage, 1 m³/Tag), Stichtag
     * 2025-11-16: 15 Tage × 11,02 + 15 Tage × 9,50 = 165,30 + 142,50 = 307,80.
     */
    public function testMidMonthCutoffSplitsTheSegment(): void
    {
        $this->settings->set(['gas_conversion_factors' => [
            ['from' => null,         'kwh_per_m3' => 11.02],
            ['from' => '2025-11-16', 'kwh_per_m3' => 9.50],
        ]]);
        $meterId = $this->setMeterDevices('gas', [[
            'id' => 'd_gas_1', 'serial' => null,
            'installed_on' => '2025-11-01', 'initial_counter' => 0.0,
            'removed_on' => null, 'final_counter' => null, 'reason' => null,
        ]]);
        $this->setReadings('gas', $meterId, [
            ['date' => '2025-11-01', 'counter' => 100.0, 'device_id' => 'd_gas_1'],
            ['date' => '2025-12-01', 'counter' => 130.0, 'device_id' => 'd_gas_1'],
        ]);
        $monthly = $this->consumption->forMeter('gas', $this->meters->get('gas', $meterId));
        self::assertCount(1, $monthly);
        self::assertEqualsWithDelta(30.0,   $monthly[0]['m3'],  0.05);
        self::assertEqualsWithDelta(307.80, $monthly[0]['kwh'], 0.05,
            'ohne Teilung am Stichtag wären es 330,60 (alles × 11,02)');
    }

    /**
     * Die Ableitung gewinnt über einen mitgelieferten Faktor: Der Client
     * schickt `kwh_per_m3` mit; ändert der Nutzer z oder Hs, darf kein
     * veralteter Faktor daneben überleben.
     */
    public function testDerivedFactorOverridesASuppliedOne(): void
    {
        $this->settings->set(['gas_conversion_factors' => [
            ['from' => null, 'zustandszahl' => 0.95, 'brennwert' => 11.6, 'kwh_per_m3' => 99.0],
        ]]);
        self::assertSame(11.02, $this->svc->gasFactors()[0]['kwh_per_m3'],
            'z × Hs = 11,02 — nicht der mitgeschickte 99');
    }

    /**
     * Kontrolle: ohne Stichtag im Intervall ist das Ergebnis identisch zur
     * alten Rechnung „Differenz × Faktor".
     */
    public function testWithoutCutoffInsideTheIntervalNothingChanges(): void
    {
        $meterId = $this->setMeterDevices('gas', [[
            'id' => 'd_gas_1', 'serial' => null,
            'installed_on' => '2026-03-01', 'initial_counter' => 0.0,
            'removed_on' => null, 'final_counter' => null, 'reason' => null,
        ]]);
        $this->setReadings('gas', $meterId, [
            ['date' => '2026-03-01', 'counter' => 500.0, 'device_id' => 'd_gas_1'],
            ['date' => '2026-04-01', 'counter' => 600.0, 'device_id' => 'd_gas_1'],
        ]);
        $monthly = $this->consumption->forMeter('gas', $this->meters->get('gas', $meterId));
        self::assertCount(1, $monthly);
        self::assertEqualsWithDelta(100.0,  $monthly[0]['m3'],  0.05);
        self::assertEqualsWithDelta(1092.5, $monthly[0]['kwh'], 0.05, '100 × 10,925');
    }

    // ── 4. Rechnungsprüfung ───────────────────────────────────────────

    /**
     * Nachbau der Rechnungsstruktur: Ablesungen am 01.10., 01.12. und 01.03.;
     * Faktorwechsel am 01.11. und 01.02. Erwartet werden vier Zeilen, an
     * jeder Grenze eine, mit m³ × Faktor = kWh — wie auf der Rechnung.
     */
    public function testBillBreakdownSplitsAtReadingsAndFactorChanges(): void
    {
        $meterId = $this->setMeterDevices('gas', [[
            'id' => 'd_gas_1', 'serial' => null,
            'installed_on' => '2025-10-01', 'initial_counter' => 0.0,
            'removed_on' => null, 'final_counter' => null, 'reason' => null,
        ]]);
        $this->setReadings('gas', $meterId, [
            ['date' => '2025-10-01', 'counter' => 1000.0, 'device_id' => 'd_gas_1'],
            ['date' => '2025-12-01', 'counter' => 1061.0, 'device_id' => 'd_gas_1'],   // 1 m³/Tag
            ['date' => '2026-03-01', 'counter' => 1241.0, 'device_id' => 'd_gas_1'],   // 2 m³/Tag (90 Tage)
        ]);
        $meter = $this->meters->get('gas', $meterId);
        $bill  = $this->consumption->gasBillBreakdown($meter, '2025-10-01', '2026-03-01');

        $rows = $bill['rows'];
        self::assertCount(4, $rows);
        self::assertSame(
            ['2025-10-01', '2025-11-01', '2025-12-01', '2026-02-01'],
            array_column($rows, 'from')
        );
        self::assertSame(
            ['start', 'factor', 'reading', 'factor'],
            array_column($rows, 'reason')
        );
        // Inklusives Ende wie auf der Rechnung
        self::assertSame('2025-10-31', $rows[0]['to_inclusive']);

        // Zeile 1: Oktober, 31 m³ × 11,02
        self::assertEqualsWithDelta(31.0,   $rows[0]['m3'],  0.05);
        self::assertSame(11.02,             $rows[0]['kwh_per_m3']);
        self::assertEqualsWithDelta(341.62, $rows[0]['kwh'], 0.05);
        self::assertSame(self::Z,           $rows[0]['zustandszahl']);
        self::assertSame(11.6,              $rows[0]['brennwert']);
        // Zeile 3: Dez+Jan, 62 Tage × 2 m³ × 10,83
        self::assertEqualsWithDelta(124.0,   $rows[2]['m3'],  0.05);
        self::assertEqualsWithDelta(1342.92, $rows[2]['kwh'], 0.05);
        // Zeile 4: Februar, 28 Tage × 2 m³ × 10,925
        self::assertEqualsWithDelta(56.0,   $rows[3]['m3'],  0.05);
        self::assertEqualsWithDelta(611.8,  $rows[3]['kwh'], 0.05);

        // Summen: 31 + 30 + 124 + 56 = 241 m³; kWh = Summe der Zeilen
        self::assertEqualsWithDelta(241.0, $bill['totals']['m3'], 0.1);
        self::assertEqualsWithDelta(
            341.62 + 324.90 + 1342.92 + 611.8, $bill['totals']['kwh'], 0.2
        );
        self::assertSame(0, $bill['totals']['gaps']);
    }

    /**
     * v2.5.2 — Stand alt/neu je Abschnitt mit Ableseart. An einer Ablesung
     * steht der abgelesene Wert; an einem Faktorwechsel mitten im Intervall
     * ein Ersatzwert (tagesgenau interpoliert, `interpolated`); eine als
     * geschätzt erfasste Ablesung heißt `reading_estimated`.
     */
    public function testBillBreakdownCarriesCounterValuesWithReadingKind(): void
    {
        $meterId = $this->setMeterDevices('gas', [[
            'id' => 'd_gas_1', 'serial' => null,
            'installed_on' => '2025-10-01', 'initial_counter' => 0.0,
            'removed_on' => null, 'final_counter' => null, 'reason' => null,
        ]]);
        $this->setReadings('gas', $meterId, [
            ['date' => '2025-10-01', 'counter' => 1000.0, 'device_id' => 'd_gas_1'],
            ['date' => '2025-12-01', 'counter' => 1061.0, 'device_id' => 'd_gas_1', 'is_estimated' => true],
            ['date' => '2026-03-01', 'counter' => 1241.0, 'device_id' => 'd_gas_1'],
        ]);
        $meter = $this->meters->get('gas', $meterId);
        $rows  = $this->consumption->gasBillBreakdown($meter, '2025-10-01', '2026-03-01')['rows'];

        // Zeile 1: 01.10. (Ablesung 1000) → 01.11. (Faktorwechsel, Ersatzwert 1031 = 1000 + 31 × 1)
        self::assertSame(1000.0,         $rows[0]['counter_from']);
        self::assertSame('reading',      $rows[0]['counter_from_kind']);
        self::assertSame(1031.0,         $rows[0]['counter_to']);
        self::assertSame('interpolated', $rows[0]['counter_to_kind']);
        // Zeile 2 endet an der geschätzten Ablesung
        self::assertSame(1061.0,             $rows[1]['counter_to']);
        self::assertSame('reading_estimated', $rows[1]['counter_to_kind']);
        // Zeile 3: 01.12. → 01.02. (Faktorwechsel): 1061 + 62 × 2 = 1185
        self::assertSame(1185.0,         $rows[2]['counter_to']);
        self::assertSame('interpolated', $rows[2]['counter_to_kind']);
        // Zeile 4 endet an der letzten Ablesung
        self::assertSame(1241.0,    $rows[3]['counter_to']);
        self::assertSame('reading', $rows[3]['counter_to_kind']);
        // Stand neu einer Zeile = Stand alt der nächsten
        for ($i = 1; $i < count($rows); $i++) {
            self::assertSame($rows[$i - 1]['counter_to'], $rows[$i]['counter_from']);
        }
    }

    public function testBillBreakdownHasNoCounterAcrossADeviceSwapOrOutsideReadings(): void
    {
        // Zählertausch am 01.02.: alt endet bei 1100, neu beginnt bei 0.
        $meterId = $this->setMeterDevices('gas', [
            ['id' => 'd_old', 'serial' => null, 'installed_on' => '2026-01-01', 'initial_counter' => 0.0,
             'removed_on' => '2026-02-01', 'final_counter' => 1100.0, 'reason' => 'defekt'],
            ['id' => 'd_new', 'serial' => null, 'installed_on' => '2026-02-01', 'initial_counter' => 0.0,
             'removed_on' => null, 'final_counter' => null, 'reason' => null],
        ]);
        $this->setReadings('gas', $meterId, [
            ['date' => '2026-01-01', 'counter' => 1000.0, 'device_id' => 'd_old'],
            ['date' => '2026-03-01', 'counter' => 100.0,  'device_id' => 'd_new'],
        ]);
        $rows = $this->consumption->gasBillBreakdown(
            $this->meters->get('gas', $meterId), '2025-12-01', '2026-02-01'
        )['rows'];
        // Vor der ersten Ablesung: kein Stand, keine Art
        self::assertNull($rows[0]['counter_from']);
        self::assertNull($rows[0]['counter_from_kind']);
        // Am Faktorwechsel 01.02. liegt die Grenze im Tausch-Intervall: Ersatzwert
        // ohne Zahl — über zwei Geräte hinweg gibt es keinen fortlaufenden Stand.
        $last = end($rows);
        self::assertSame('2026-02-01', $last['to']);
        self::assertNull($last['counter_to']);
        self::assertSame('interpolated', $last['counter_to_kind']);
    }

    public function testBillBreakdownMarksDaysWithoutReadingsAsGap(): void
    {
        $meterId = $this->setMeterDevices('gas', [[
            'id' => 'd_gas_1', 'serial' => null,
            'installed_on' => '2026-01-01', 'initial_counter' => 0.0,
            'removed_on' => null, 'final_counter' => null, 'reason' => null,
        ]]);
        $this->setReadings('gas', $meterId, [
            ['date' => '2026-01-01', 'counter' => 100.0, 'device_id' => 'd_gas_1'],
            ['date' => '2026-02-01', 'counter' => 131.0, 'device_id' => 'd_gas_1'],
        ]);
        $bill = $this->consumption->gasBillBreakdown(
            $this->meters->get('gas', $meterId), '2025-12-01', '2026-03-01'
        );
        $gaps = array_filter($bill['rows'], fn($r) => $r['m3'] === null);
        self::assertCount(2, $gaps, 'vor der ersten und nach der letzten Ablesung');
        self::assertSame(2, $bill['totals']['gaps']);
    }

    // ── 5. Migration ──────────────────────────────────────────────────

    public function testMigrationTurnsTheScalarIntoTheUndatedEntry(): void
    {
        $this->store->write('settings.json', ['gas_conversion_factor' => 10.7, 'hdd_base_temp' => 15.0]);
        $this->store->write('meta.json', ['schema_version' => '1.4.0', 'migrated_at' => 'x', 'log' => []]);

        $migrator = new Migrator($this->store);
        self::assertTrue($migrator->needsV150Upgrade());
        self::assertTrue($migrator->needsMigration(), 'Bestand auf 1.4.0 will migrieren');
        $migrator->migrate();

        $s = $this->store->read('settings.json', []);
        self::assertArrayNotHasKey('gas_conversion_factor', $s, 'Altschlüssel entfernt');
        self::assertSame(null, $s['gas_conversion_factors'][0]['from']);
        self::assertSame(10.7, $s['gas_conversion_factors'][0]['kwh_per_m3']);
        self::assertEquals(15.0, $s['hdd_base_temp'], 'andere Einstellungen unangetastet');
        self::assertSame('1.5.0', $this->store->read('meta.json', [])['schema_version']);

        // Und die Historie rechnet mit dem Altwert weiter — nichts springt.
        self::assertSame(10.7, $this->svc->factorOn('gas', '2019-01-01'));
        self::assertFalse((new Migrator($this->store))->needsV150Upgrade(), 'idempotent');
    }

    public function testMigrationKeepsAnExistingListUntouched(): void
    {
        $this->store->write('settings.json', [
            'gas_conversion_factor'  => 10.7,                          // Rest aus altem Backup
            'gas_conversion_factors' => [['from' => null, 'kwh_per_m3' => 11.1]],
        ]);
        (new Migrator($this->store))->upgradeToV150();
        $s = $this->store->read('settings.json', []);
        self::assertArrayNotHasKey('gas_conversion_factor', $s);
        self::assertSame(11.1, $s['gas_conversion_factors'][0]['kwh_per_m3'], 'Liste gewinnt');
    }
}
