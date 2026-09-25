<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Http\NotFoundException;
use Energietracker\Services\BenchmarkService;
use Energietracker\Services\DeliveryService;
use Energietracker\Services\ReadingImportService;
use Energietracker\Services\RecommendationService;
use Energietracker\Services\ReminderService;
use Energietracker\Services\TariffComparisonService;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * v2.12.0 — was die Oberfläche für „Rückgängig" und die Import-Vorschau
 * braucht (Review UI-22, UI-23, UI-31):
 *
 *   - „Erledigt" lässt sich zurücknehmen: `last_done` ist änderbar
 *   - eine ausgeblendete Empfehlung lässt sich wieder einblenden
 *   - der CSV-Import hat einen Trockenlauf, der nichts schreibt
 *   - der Rückblick nennt die Jahre, in denen es Daten gibt
 */
#[CoversClass(ReminderService::class)]
#[CoversClass(RecommendationService::class)]
#[CoversClass(ReadingImportService::class)]
#[CoversClass(TariffComparisonService::class)]
final class UndoAndPreviewTest extends ServiceTestCase
{
    private function reminders(): ReminderService
    {
        return new ReminderService($this->store, $this->settings, $this->i18n);
    }

    private function recommendations(): RecommendationService
    {
        $benchmark  = new BenchmarkService($this->consumption, $this->meters, $this->settings, $this->i18n);
        $deliveries = new DeliveryService($this->store, $this->meters, $this->i18n);
        return new RecommendationService($this->store, $this->meters, $this->consumption, $this->settings, $benchmark, $deliveries, $this->i18n);
    }

    private function import(): ReadingImportService
    {
        return new ReadingImportService($this->readings, $this->meters, $this->i18n);
    }

    private function stromMeter(): string
    {
        return $this->setMeterDevices('strom', [[
            'id' => 'd1', 'serial' => null, 'installed_on' => '2023-12-01',
            'initial_counter' => 0.0, 'removed_on' => null, 'final_counter' => null, 'reason' => null,
        ]]);
    }

    public function testMarkingAReminderDoneCanBeUndone(): void
    {
        $svc = $this->reminders();
        $rem = $svc->create([
            'title' => 'Wartung', 'category' => 'heizung_wartung',
            'next_due' => '2024-05-10', 'recurrence' => 'yearly',
        ]);
        $done = $svc->markDone($rem['id'], '2024-05-12');
        self::assertSame('2025-05-12', $done['next_due']);
        self::assertSame('2024-05-12', $done['last_done']);

        // Was die Oberfläche beim „Rückgängig" schickt: der Zustand davor
        $back = $svc->update($rem['id'], [
            'next_due' => $rem['next_due'], 'active' => true, 'last_done' => $rem['last_done'] ?? null,
        ]);
        self::assertSame('2024-05-10', $back['next_due']);
        self::assertNull($back['last_done']);
        self::assertTrue($back['active']);
    }

    public function testAOneTimeReminderIsActiveAgainAfterUndo(): void
    {
        $svc = $this->reminders();
        $rem = $svc->create(['title' => 'Einmal', 'category' => 'custom', 'next_due' => '2024-05-10', 'recurrence' => 'none']);
        self::assertFalse($svc->markDone($rem['id'], '2024-05-10')['active']);
        self::assertTrue($svc->update($rem['id'], ['active' => true, 'last_done' => null])['active']);
    }

    public function testLastDoneMustBeADateOrEmpty(): void
    {
        $svc = $this->reminders();
        $rem = $svc->create(['title' => 'X', 'category' => 'custom', 'next_due' => '2024-05-10', 'recurrence' => 'none']);
        $this->expectException(\InvalidArgumentException::class);
        $svc->update($rem['id'], ['last_done' => 'gestern']);
    }

    public function testADismissedRecommendationCanBeShownAgain(): void
    {
        $recs = $this->recommendations();
        $recs->dismiss('rec_a');
        $recs->dismiss('rec_b');
        $recs->restore('rec_a');
        $map = $this->store->read('recommendations_dismissed.json', []);
        self::assertArrayNotHasKey('rec_a', $map);
        self::assertArrayHasKey('rec_b', $map, 'nur die eine Empfehlung kehrt zurück');

        $before = $this->store->generation();
        $recs->restore('rec_unbekannt');
        self::assertSame($before, $this->store->generation(), 'nichts ausgeblendet → nichts zu schreiben, kein Fehler');
    }

    public function testTheDryRunReadsButDoesNotWrite(): void
    {
        $meterId = $this->stromMeter();
        $before  = $this->store->generation();
        $res = $this->import()->importCsv('strom', $meterId, "datum;zählerstand;notiz\n01.02.2024;100;A\n2024-03-01;250,5;\nkaputt;1\n", true);

        self::assertTrue($res['dry_run']);
        self::assertSame(0, $res['imported']);
        self::assertSame(1, $res['skipped']);
        self::assertSame([['2024-02-01', 100.0], ['2024-03-01', 250.5]],
            array_map(fn($r) => [$r['date'], $r['counter']], $res['rows']));
        self::assertSame('A', $res['rows'][0]['note']);
        self::assertSame($before, $this->store->generation(), 'ein Trockenlauf schreibt nichts');
        self::assertSame([], $this->readings->list('strom', $meterId));
    }

    public function testSpacesAndApostrophesAsThousandsSeparators(): void
    {
        $meterId = $this->stromMeter();
        $res = $this->import()->importCsv('strom', $meterId,
            "datum;zählerstand\n01.02.2024;1 395,2\n01.03.2024;1\u{202F}396,5\n01.04.2024;1'397.5\n", true);
        self::assertSame([1395.2, 1396.5, 1397.5], array_column($res['rows'], 'counter'));
        self::assertSame(0, $res['skipped']);
    }

    public function testADryRunForAnUnknownMeterIsNotFound(): void
    {
        $this->expectException(NotFoundException::class);
        $this->import()->importCsv('strom', 'm_strom_gibtsnicht', "datum;zählerstand\n01.02.2024;100\n", true);
    }

    public function testTheRetrospectiveOffersOnlyYearsWithData(): void
    {
        $meterId = $this->stromMeter();
        $rows = [];
        for ($i = 0; $i <= 12; $i++) {
            $d = (new \DateTimeImmutable('2024-01-01'))->modify("+$i months");
            $rows[] = ['date' => $d->format('Y-m-d'), 'counter' => 1000.0 + $i * 100.0, 'device_id' => 'd1'];
        }
        $this->setReadings('strom', $meterId, $rows);
        $this->contracts->create('strom', [
            'meter_id' => $meterId, 'provider' => 'A', 'tariff_name' => 'Basis',
            'start' => '2024-01-01', 'end' => '2024-12-31',
            'working_prices' => [['from' => '2024-01-01', 'ct_per_kwh' => 20.0]],
        ]);
        $cmp = new TariffComparisonService($this->consumption, $this->contracts, $this->meters, $this->i18n);

        $all = $cmp->compare('strom', $meterId, null);
        self::assertContains(2024, $all['years']);
        self::assertNotContains(2020, $all['years'], 'keine leeren Jahre');
        $sorted = $all['years'];
        rsort($sorted);
        self::assertSame($sorted, $all['years'], 'neueste zuerst');

        // Ein Jahr ohne Daten: leere Antwort, aber die Auswahl bleibt bedienbar
        $empty = $cmp->compare('strom', $meterId, 2019);
        self::assertSame([], $empty['rows']);
        self::assertSame($all['years'], $empty['years']);
    }
}
