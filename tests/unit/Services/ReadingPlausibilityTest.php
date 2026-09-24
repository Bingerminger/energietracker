<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\ConsumptionService;
use Energietracker\Services\IngestService;
use Energietracker\Services\ReadingService;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * v2.6.0 — Plausibilität der Ablesungen (Review 2026-09-24).
 *
 * Bis v2.5.3 verwarf die Verbrauchsrechnung bei einem fallenden Stand nur das
 * negative Intervall und zählte das folgende ab dem falschen Stand voll: Ein
 * einziger Wert 0 aus Home Assistant machte aus 190 kWh im März 50.270 kWh.
 * Jetzt fällt der Ausreißer selbst heraus, und die Antwort sagt, welcher.
 */
#[CoversClass(ConsumptionService::class)]
#[CoversClass(IngestService::class)]
#[CoversClass(ReadingService::class)]
final class ReadingPlausibilityTest extends ServiceTestCase
{
    private string $meterId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->meterId = $this->setMeterDevices('strom', [[
            'id' => 'd1', 'serial' => null, 'installed_on' => '2024-01-01',
            'initial_counter' => 0.0, 'removed_on' => null, 'final_counter' => null, 'reason' => null,
        ]]);
    }

    /** @param array<string,float> $byDate date → counter */
    private function readings(array $byDate, array $extra = []): void
    {
        $rows = [];
        foreach ($byDate as $date => $counter) {
            $rows[] = array_merge(['date' => $date, 'counter' => $counter, 'device_id' => 'd1'], $extra[$date] ?? []);
        }
        $this->setReadings('strom', $this->meterId, $rows);
    }

    private function meter(): array
    {
        return $this->meters->get('strom', $this->meterId);
    }

    private function total(): float
    {
        return array_sum(array_map(fn($m) => (float)$m['kwh'], $this->consumption->forMeter('strom', $this->meter())));
    }

    public function testASingleZeroBetweenTwoPlausibleReadingsIsIgnored(): void
    {
        $this->readings(['2025-03-01' => 50000, '2025-03-09' => 50080, '2025-03-10' => 0, '2025-03-11' => 50100, '2025-03-31' => 50300]);
        self::assertEqualsWithDelta(300.0, $this->total(), 0.01, 'Verbrauch März = 10 kWh/Tag, nicht der ganze Zählerstand');
        $w = $this->consumption->readingWarnings('strom', $this->meter());
        self::assertCount(1, $w);
        self::assertSame(['outlier', 'dip', '2025-03-10'], [$w[0]['type'], $w[0]['kind'], $w[0]['date']]);
    }

    public function testATypoBelowThePreviousReadingIsResolvedByTheSmootherRate(): void
    {
        // 1050 ist ein Tippfehler für 1150. Die reine Spitzenregel hielte 1100
        // für den Ausreißer (1050 ≥ 1000); die Tagesraten entscheiden richtig.
        $this->readings(['2025-01-01' => 1000, '2025-01-31' => 1100, '2025-03-02' => 1050, '2025-04-01' => 1200]);
        self::assertEqualsWithDelta(200.0, $this->total(), 0.5);
        $w = $this->consumption->readingWarnings('strom', $this->meter());
        self::assertSame(['dip', '2025-03-02'], [$w[0]['kind'], $w[0]['date']]);
    }

    public function testASpikeIsIgnored(): void
    {
        $this->readings(['2025-01-01' => 1000, '2025-01-31' => 5000, '2025-03-02' => 1100, '2025-04-01' => 1200]);
        self::assertEqualsWithDelta(200.0, $this->total(), 0.5);
        $w = $this->consumption->readingWarnings('strom', $this->meter());
        self::assertSame(['spike', '2025-01-31'], [$w[0]['kind'], $w[0]['date']]);
    }

    public function testAFallingLastReadingIsReportedAsDecrease(): void
    {
        $this->readings(['2025-01-01' => 1000, '2025-02-01' => 1100, '2025-03-01' => 900]);
        self::assertEqualsWithDelta(100.0, $this->total(), 0.5);
        $types = array_column($this->consumption->readingWarnings('strom', $this->meter()), 'type');
        self::assertSame(['decrease'], $types, 'ohne Nachfolger lässt sich kein Ausreißer bestimmen — aber gemeldet wird er');
    }

    public function testASuspectReadingIsSkippedUntilConfirmed(): void
    {
        $this->readings(
            ['2025-01-01' => 1000, '2025-02-01' => 1100, '2025-03-01' => 1300],
            ['2025-02-01' => ['is_suspect' => true]]
        );
        $w = $this->consumption->readingWarnings('strom', $this->meter());
        self::assertSame('suspect', $w[0]['type']);
        self::assertEqualsWithDelta(300.0, $this->total(), 0.5);

        $this->readings->update('strom', 'r_001', ['is_suspect' => false]);
        self::assertSame([], $this->consumption->readingWarnings('strom', $this->meter()), 'bestätigt → wieder regulär');
    }

    public function testIngestMarksAFallingValueButStillAnswersCreated(): void
    {
        $this->readings(['2025-01-01' => 1000, '2025-02-01' => 1100]);
        $ingest = new IngestService($this->meters, $this->readings, $this->i18n);

        $res = $ingest->ingest(['utility' => 'strom', 'meter' => $this->meterId, 'value' => 0, 'date' => '2025-02-10']);
        self::assertSame('created', $res['status'], 'keine Home-Assistant-Automation darf brechen');
        self::assertTrue($res['suspect']);
        self::assertSame(1100.0, $res['previous']['counter']);
        $stored = array_values(array_filter($this->readings->list('strom', $this->meterId), fn($r) => $r['date'] === '2025-02-10'))[0];
        self::assertTrue($stored['is_suspect']);
        self::assertSame('ingest', $stored['source']);

        // Nächster regulärer Push: nicht verdächtig, und die 0 zählt nicht.
        $res = $ingest->ingest(['utility' => 'strom', 'meter' => $this->meterId, 'value' => 1200, 'date' => '2025-03-01']);
        self::assertFalse($res['suspect']);
        self::assertEqualsWithDelta(200.0, $this->total(), 0.5);
    }

    public function testAnOverviewIgnoresSuspectReadingsAndKnowsTheTypicalRate(): void
    {
        $this->readings(
            ['2025-01-01' => 1000, '2025-01-11' => 1100, '2025-01-21' => 1200, '2025-01-31' => 1300, '2025-02-05' => 0],
            ['2025-02-05' => ['is_suspect' => true]]
        );
        $row = array_values(array_filter($this->readings->overview([]), fn($r) => $r['meter_id'] === $this->meterId))[0];
        self::assertSame(1300.0, $row['last_reading']['counter']);
        self::assertSame(10.0, $row['typical_per_day']);
        self::assertSame(1, $row['suspect_count']);
    }

    public function testACounterRolloverIsConsumptionWhenTheDigitsAreKnown(): void
    {
        $this->setMeterDevices('strom', [[
            'id' => 'd1', 'serial' => null, 'installed_on' => '2024-01-01', 'digits' => 5,
            'initial_counter' => 0.0, 'removed_on' => null, 'final_counter' => null, 'reason' => null,
        ]], $this->meterId);
        $this->readings(['2025-01-01' => 99800, '2025-02-01' => 99900, '2025-03-01' => 212]);
        self::assertEqualsWithDelta(412.0, $this->total(), 0.01, '99.900 → 100.212 ist ein Überlauf, kein Fehler');
        self::assertSame([], $this->consumption->readingWarnings('strom', $this->meter()));
    }

    public function testWithoutDigitsTheSameDropIsADecrease(): void
    {
        $this->readings(['2025-01-01' => 99800, '2025-02-01' => 99900, '2025-03-01' => 212]);
        self::assertSame(['decrease'], array_column($this->consumption->readingWarnings('strom', $this->meter()), 'type'));
    }

    public function testIngestDoesNotSuspectARolloverWhenTheDigitsAreKnown(): void
    {
        $this->setMeterDevices('strom', [[
            'id' => 'd1', 'serial' => null, 'installed_on' => '2024-01-01', 'digits' => 5,
            'initial_counter' => 0.0, 'removed_on' => null, 'final_counter' => null, 'reason' => null,
        ]], $this->meterId);
        $this->readings(['2025-01-01' => 99800, '2025-02-01' => 99900]);
        $ingest = new IngestService($this->meters, $this->readings, $this->i18n);

        $res = $ingest->ingest(['utility' => 'strom', 'meter' => $this->meterId, 'value' => 212, 'date' => '2025-03-01']);
        self::assertFalse($res['suspect'], 'Überlauf mit bekannter Stellenzahl ist kein Verdacht');
        self::assertEqualsWithDelta(412.0, $this->total(), 0.01);

        // Ein Sturz auf 0 mitten im Zählbereich bleibt verdächtig.
        $res = $ingest->ingest(['utility' => 'strom', 'meter' => $this->meterId, 'value' => 0, 'date' => '2025-03-10']);
        self::assertTrue($res['suspect']);
    }
}
