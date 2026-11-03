<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\ActivityService;
use Energietracker\Services\AttachmentService;
use Energietracker\Services\BackupService;
use Energietracker\Services\BillService;
use Energietracker\Services\Inbox\Applier;
use Energietracker\Services\Inbox\DraftApplier;
use Energietracker\Services\Inbox\HouseholdDraft;
use Energietracker\Services\Inbox\Proposals;
use Energietracker\Services\Inbox\ProviderRegistry;
use Energietracker\Services\InboxDraftService;
use Energietracker\Services\InboxService;
use Energietracker\Services\ReminderService;
use Energietracker\Tests\Support\DraftFixture;
use Energietracker\Tests\Support\InboxFixture;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * v3.10.0 — Anfangsstand einer Rechnung = deine Ablesung kurz davor (Befund an einer Wasserrechnung,
 * 2026-10-28, hier mit erfundenen Werten). Eine Jahresrechnung 01.01.–31.12.2025 nennt als Anfangsstand
 * 1000 m³ am 01.01.2025; bei dir steht 1000 m³ schon am 31.12.2024 (die Jahresablesung am Vortag). Bis
 * v3.9 war der Anfangsstand „neu“ und legte einen zweiten Stand mit 0 Verbrauch an. Jetzt:
 *   - derselbe Stand höchstens Proposals::START_READING_DAYS (3) Tage davor, dein letzter vor dem Beginn,
 *     kein Zählertausch dazwischen → „schon vorhanden (dein Stand vom …)“;
 *   - vier Tage davor, ein anderer Stand oder ein Tausch dazwischen → wie bisher.
 * Im Posteingang (Proposals::readingAt) und im Entwurf des Haushalts (HouseholdDraft).
 */
#[CoversClass(Proposals::class)]
#[CoversClass(HouseholdDraft::class)]
final class InboxStartReadingTest extends ServiceTestCase
{
    private const TODAY = '2026-02-20';

    private InboxService $inbox;
    private InboxDraftService $draft;

    protected function setUp(): void
    {
        parent::setUp();
        $att = new AttachmentService($this->store, $this->settings, $this->i18n);
        $bills = new BillService($this->store, $this->meters, $this->contracts, $this->consumption, $this->i18n);
        $reminders = new ReminderService($this->store, $this->settings, $this->i18n);
        $proposals = new Proposals($this->meters, $this->contracts, $this->readings, $bills, $reminders, $this->settings);
        $this->inbox = new InboxService($this->store, $this->i18n, $att, new ProviderRegistry($this->settings, InboxFixture::provider()),
            $proposals, new Applier($this->meters, $this->contracts, $this->readings, $bills, $reminders, $this->settings, $this->i18n));
        $this->draft = new InboxDraftService($this->inbox,
            new HouseholdDraft($this->meters, $this->contracts, $this->readings, $bills, $reminders, $this->settings, $this->i18n, $proposals),
            new DraftApplier($this->meters, $this->contracts, $this->readings, $bills, $reminders, $this->settings, $this->i18n));
        $this->draft->attach(new BackupService($this->store, $this->i18n), new ActivityService($this->store, $this->settings, $this->i18n));
        $this->setMeterDevices('wasser', [['id' => 'd_w1', 'serial' => 'WZ-4711', 'installed_on' => '2020-01-01',
            'initial_counter' => 0.0, 'removed_on' => null, 'final_counter' => null, 'reason' => null]]);
        $this->contracts->create('wasser', ['meter_id' => 'm_wasser_default', 'provider' => 'Stadtwerke Musterstadt', 'start' => '2024-01-01',
            'trinkwasser' => ['working_prices' => [['from' => '2024-01-01', 'ct_per_m3' => 200.0]]]]);
    }

    /** Deine Stände: einer vor dem Beginn (Tag, Stand), dazu Mitte 2025 1100 m³. */
    private function stock(string $date, float $counter, string $device = 'd_w1'): void
    {
        $this->setReadings('wasser', 'm_wasser_default', [
            ['date' => '2024-01-01', 'counter' => 880.0, 'device_id' => 'd_w1'],
            ['date' => $date, 'counter' => $counter, 'device_id' => $device],
            ['date' => '2025-06-30', 'counter' => 1100.0, 'device_id' => $device],
        ]);
    }

    /** Jahresrechnung 01.01.–31.12.2025: Anfangsstand 1000 am 01.01.2025, Endstand 1210 am 31.12.2025 (erfunden). */
    private function invoice(string $tag): string
    {
        return DraftFixture::add($this->store, fn($b, $n) => $this->inbox->upload($b, $n), $tag, 'invoice', [
            'utility' => 'wasser', 'supplier' => 'Stadtwerke Musterstadt', 'kind' => 'annual',
            'period_from' => '01.01.2025', 'period_to' => '31.12.2025', 'consumption' => '210',
            'reading_old' => '1000', 'reading_old_date' => '01.01.2025', 'reading_new' => '1210', 'reading_new_date' => '31.12.2025',
            'meter_number' => 'WZ-4711', 'total_amount' => '420,00', 'advances_total' => '400,00', 'result_kind' => 'due', 'result_amount' => '20,00',
        ]);
    }

    private function startProposal(string $id): array
    {
        $r = array_values(array_filter($this->inbox->preview($id)['proposals'], fn($p) => $p['kind'] === 'reading' && $p['data']['date'] === '2025-01-01'));
        self::assertCount(1, $r);
        return $r[0];
    }

    public function testTheAnnualReadingTheDayBeforeIsTheOpeningReading(): void
    {
        $this->stock('2024-12-31', 1000.0);
        $id = $this->invoice('vortag');
        $r = $this->startProposal($id);
        self::assertSame(['exists', false, ['same_on' => '2024-12-31', 'counter' => 1000.0]], [$r['status'], $r['checked'], $r['detail']]);
        self::assertArrayNotHasKey('optional', $r);
        // übernommen: kein zweiter Stand 1000 am 01.01.2025 (bis v3.9 einer mit 0 Verbrauch)
        $pv = $this->inbox->preview($id);
        $res = $this->inbox->apply($id, array_column(array_filter($pv['proposals'], fn($p) => $p['checked']), 'id'));
        self::assertSame([], $res['failed'], json_encode($res['failed']));
        self::assertNotContains('2025-01-01', array_column($this->readings->list('wasser', 'm_wasser_default'), 'date'));
    }

    public function testThreeDaysBeforeStillCountsFourDaysDoNot(): void
    {
        // 29.12. ist drei Tage vor dem 01.01.: vorhanden
        $this->stock('2024-12-29', 1000.0);
        self::assertSame(['exists', '2024-12-29'], [($r = $this->startProposal($this->invoice('drei')))['status'], $r['detail']['same_on'] ?? null]);
        // 28.12. sind vier Tage: wie bisher ein eigener Stand (er passt zwischen deine Stände, also vorbelegt)
        $this->store->write('inbox.json', []);
        $this->stock('2024-12-28', 1000.0);
        $r = $this->startProposal($this->invoice('vier'));
        self::assertSame(['new', true, null], [$r['status'], $r['checked'], $r['detail']]);
        self::assertSame(3, Proposals::START_READING_DAYS);
    }

    public function testADifferentReadingOrASwapInBetweenIsNoMatch(): void
    {
        // am Vortag 998: ein anderer Stand
        $this->stock('2024-12-31', 998.0);
        self::assertSame('new', $this->startProposal($this->invoice('anders'))['status']);
        // derselbe Stand, aber am 30.12. ein Zählertausch dazwischen: nicht zu entscheiden
        $this->store->write('inbox.json', []);
        $this->setMeterDevices('wasser', [
            ['id' => 'd_w1', 'serial' => 'WZ-4711', 'installed_on' => '2020-01-01', 'initial_counter' => 0.0, 'removed_on' => '2024-12-30',
             'final_counter' => 1000.0, 'reason' => 'Eichtausch'],
            ['id' => 'd_w2', 'serial' => 'WZ-4711', 'installed_on' => '2024-12-30', 'initial_counter' => 1000.0, 'removed_on' => null,
             'final_counter' => null, 'reason' => null]]);
        $this->setReadings('wasser', 'm_wasser_default', [['date' => '2024-12-29', 'counter' => 1000.0, 'device_id' => 'd_w1']]);
        self::assertNull(Proposals::sameStandBefore($this->readings->list('wasser', 'm_wasser_default'),
            $this->meters->get('wasser', 'm_wasser_default'), '2025-01-01', 1000.0));
        self::assertNotSame('exists', $this->startProposal($this->invoice('tausch'))['status']);
    }

    public function testTheEndReadingKeepsItsOwnRule(): void
    {
        self::assertNull(Proposals::sameStandBefore([['date' => '2025-12-28', 'counter' => 1300.0]], [], '2025-12-31', 1210.0));
        // derselbe Endstand zwei Tage vorher: dein gemeldeter Stand (reportedEarlier, v3.9.0), nicht diese Regel
        $this->setReadings('wasser', 'm_wasser_default', [
            ['date' => '2024-12-31', 'counter' => 1000.0, 'device_id' => 'd_w1'],
            ['date' => '2025-12-29', 'counter' => 1210.0, 'device_id' => 'd_w1'],
        ]);
        $id = $this->invoice('ende');
        $end = array_values(array_filter($this->inbox->preview($id)['proposals'], fn($p) => $p['kind'] === 'reading' && $p['data']['date'] === '2025-12-31'))[0];
        self::assertSame(['exists', ['reported_on' => '2025-12-29', 'counter' => 1210.0]], [$end['status'], $end['detail']]);
    }

    public function testTheHouseholdDraftMatchesTheOpeningReadingToo(): void
    {
        // drei Tage davor (den Vortag fing der Entwurf schon seit 3.7.1 über „einen Tag daneben“)
        $this->stock('2024-12-29', 1000.0);
        $this->invoice('entwurf');
        $d = $this->draft->draft(null, self::TODAY);
        $items = array_values(array_filter($d['items'], fn($i) => $i['kind'] === 'readings' && $i['utility'] === 'wasser'));
        self::assertCount(1, $items, json_encode(array_column($d['items'], 'kind')));
        $f = array_values(array_filter($items[0]['fields'], fn($x) => $x['name'] === 'r:2025-01-01'))[0];
        self::assertSame(['exists', '2024-12-29'], [$f['status'], $f['same_on'] ?? null]);
    }
}
