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
 * v3.9.0 — ein Stand aus einem Dokument gegen die eigenen Stände (erfundene Werte, nach einem Befund an einem echten
 * Dokument): Eigene Ablesungen 10.07.: 1200, 20.07.: 1210, 30.07.: 1212. Die Jahresrechnung nennt als Endstand
 * 1210 am 31.07., Code „I“: Der Kunde hat den Stand selbst gemeldet, der Versorger datiert ihn auf das Ende des
 * Zeitraums. Übernommen liefe der Zähler vom 30.07. auf den 31.07. rückwärts.
 *   - derselbe Wert schon im Zeitraum, Stand am Zeitraumende → „vorhanden (vom 20.07.)“, „Rechnung prüfen“
 *     rechnet die Menge bis dahin (keine Abweichung allein deshalb);
 *   - ein Stand, der nicht zwischen die eigenen passt → nicht vorbelegt, Grund `not_monotonic`, mit dem Nachbarn;
 *   - ein passender Stand bleibt vorbelegt.
 * Im Posteingang (Proposals::readingAt) und im Entwurf des Haushalts (HouseholdDraft).
 */
#[CoversClass(Proposals::class)]
#[CoversClass(HouseholdDraft::class)]
#[CoversClass(BillService::class)]
final class InboxReadingFitTest extends ServiceTestCase
{
    private const TODAY = '2026-08-20';

    private InboxService $inbox;
    private InboxDraftService $draft;
    private BillService $bills;

    protected function setUp(): void
    {
        parent::setUp();
        $att = new AttachmentService($this->store, $this->settings, $this->i18n);
        $this->bills = new BillService($this->store, $this->meters, $this->contracts, $this->consumption, $this->i18n);
        $reminders = new ReminderService($this->store, $this->settings, $this->i18n);
        $proposals = new Proposals($this->meters, $this->contracts, $this->readings, $this->bills, $reminders, $this->settings);
        $this->inbox = new InboxService($this->store, $this->i18n, $att, new ProviderRegistry($this->settings, InboxFixture::provider()),
            $proposals, new Applier($this->meters, $this->contracts, $this->readings, $this->bills, $reminders, $this->settings, $this->i18n));
        $this->draft = new InboxDraftService($this->inbox,
            new HouseholdDraft($this->meters, $this->contracts, $this->readings, $this->bills, $reminders, $this->settings, $this->i18n, $proposals),
            new DraftApplier($this->meters, $this->contracts, $this->readings, $this->bills, $reminders, $this->settings, $this->i18n));
        $this->draft->attach(new BackupService($this->store, $this->i18n), new ActivityService($this->store, $this->settings, $this->i18n));
        $mid = $this->setMeterDevices('wasser', [['id' => 'd_w1', 'serial' => 'WZ-4711', 'installed_on' => '2020-01-01',
            'initial_counter' => 0.0, 'removed_on' => null, 'final_counter' => null, 'reason' => null]]);
        $this->setReadings('wasser', $mid, [
            ['date' => '2025-08-01', 'counter' => 1000.0, 'device_id' => 'd_w1'],
            ['date' => '2026-07-10', 'counter' => 1200.0, 'device_id' => 'd_w1'],
            ['date' => '2026-07-20', 'counter' => 1210.0, 'device_id' => 'd_w1'],
            ['date' => '2026-07-30', 'counter' => 1212.0, 'device_id' => 'd_w1'],
        ]);
        $this->contracts->create('wasser', ['meter_id' => $mid, 'provider' => 'Stadtwerke Musterstadt', 'start' => '2025-08-01',
            'trinkwasser' => ['working_prices' => [['from' => '2025-08-01', 'ct_per_m3' => 200.0]]]]);
    }

    /** Eine Jahresrechnung 01.08.2025–31.07.2026 mit Endstand `$end` am 31.07. (erfunden). */
    private function invoice(string $tag, string $end): string
    {
        return DraftFixture::add($this->store, fn($b, $n) => $this->inbox->upload($b, $n), $tag, 'invoice', [
            'utility' => 'wasser', 'supplier' => 'Stadtwerke Musterstadt', 'kind' => 'annual',
            'period_from' => '01.08.2025', 'period_to' => '31.07.2026', 'consumption' => (string)((int)$end - 1000),
            'reading_old' => '1000', 'reading_old_date' => '01.08.2025', 'reading_new' => $end, 'reading_new_date' => '31.07.2026',
            'meter_number' => 'WZ-4711', 'total_amount' => '420,00', 'advances_total' => '400,00', 'result_kind' => 'due', 'result_amount' => '20,00',
        ]);
    }

    private function endReading(array $pv): array
    {
        $r = array_values(array_filter($pv['proposals'], fn($p) => $p['kind'] === 'reading' && $p['data']['date'] === '2026-07-31'));
        self::assertCount(1, $r, json_encode(array_map(fn($p) => [$p['kind'], $p['data']['date'] ?? null], $pv['proposals'])));
        return $r[0];
    }

    public function testYourReportedReadingDatedToThePeriodEndIsAlreadyThere(): void
    {
        $id = $this->invoice('rep', '1210');
        $pv = $this->inbox->preview($id);
        $r = $this->endReading($pv);
        self::assertSame(['exists', false, ['reported_on' => '2026-07-20', 'counter' => 1210.0]], [$r['status'], $r['checked'], $r['detail']]);
        $bill = array_values(array_filter($pv['proposals'], fn($p) => $p['kind'] === 'bill'))[0];
        self::assertSame('2026-07-20', $bill['data']['end_reading_on'] ?? null);
        $res = $this->inbox->apply($id, array_column(array_filter($pv['proposals'], fn($p) => $p['checked']), 'id'));
        self::assertSame([], $res['failed'], json_encode($res['failed']));
        self::assertNotContains('2026-07-31', array_column($this->readings->list('wasser', 'm_wasser_default'), 'date'), 'kein Stand, der rückwärts läuft');
        // „Rechnung prüfen“: Menge bis zu deinem Stand vom 20.07. — 1210 − 1000 = 210 m³ wie auf der Rechnung
        $b = $this->bills->list('wasser')[0];
        $cmp = $this->bills->compare('wasser', (string)$b['id']);
        self::assertSame(['2026-07-20', 210.0, 0.0], [$cmp['ours']['quantity_until'] ?? null, (float)$cmp['ours']['m3'], (float)$cmp['delta']['m3']]);
        self::assertNotContains('estimated_reading_at_boundary', $cmp['reasons']);
        // Betrag: 210 m³ × 2,00 € = 420,00 € wie auf der Rechnung (bis zum 31.07. wären es gut 212 m³: „prüfen“)
        self::assertSame(['ok', 420.0], [$cmp['verdict'], (float)$cmp['ours']['total']]);
    }

    public function testAReadingBelowYourLastReadingIsNotPreselected(): void
    {
        $r = $this->endReading($this->inbox->preview($this->invoice('low', '1209')));
        self::assertSame(['new', true, false], [$r['status'], $r['check'], $r['checked']]);
        self::assertSame(['neighbor' => ['side' => 'prev', 'date' => '2026-07-30', 'counter' => 1212.0]], $r['detail']);
        self::assertContains(['field' => 'reading_new', 'reason' => 'not_monotonic'], $r['hints']);
    }

    public function testAFittingReadingStaysPreselected(): void
    {
        $r = $this->endReading($this->inbox->preview($this->invoice('ok', '1215')));
        self::assertSame(['new', false, true, null], [$r['status'], $r['check'], $r['checked'], $r['detail']]);
    }

    /** Derselbe Wert mitten im Zeitraum (nicht am Zeitraumende) ist ein eigener Stand, der passt: neu, vorbelegt. */
    public function testTheSameValueInsideThePeriodIsAReadingOfItsOwn(): void
    {
        $id = DraftFixture::add($this->store, fn($b, $n) => $this->inbox->upload($b, $n), 'mid', 'invoice', [
            'utility' => 'wasser', 'supplier' => 'Stadtwerke Musterstadt', 'kind' => 'annual',
            'period_from' => '01.08.2025', 'period_to' => '31.07.2026', 'consumption' => '215',
            'reading_old' => '1210', 'reading_old_date' => '25.07.2026', 'reading_new' => '1215', 'reading_new_date' => '31.07.2026',
            'meter_number' => 'WZ-4711', 'total_amount' => '430,00', 'advances_total' => '400,00', 'result_kind' => 'due', 'result_amount' => '30,00',
        ]);
        $r = array_values(array_filter($this->inbox->preview($id)['proposals'], fn($p) => $p['kind'] === 'reading' && $p['data']['date'] === '2026-07-25'))[0];
        self::assertSame(['new', true, null], [$r['status'], $r['checked'], $r['detail']]);
    }

    /**
     * Skeptiker M12 — die Rechnung druckt den Stand gerundet: 1000 im Dokument, 1000,2 bei dir am selben Tag ist
     * derselbe Stand (auf die Stellen des Dokuments gerundet), kein Widerspruch; 1001 bleibt einer.
     */
    public function testARoundedPrintedReadingIsTheSameAsYours(): void
    {
        $this->setReadings('wasser', 'm_wasser_default', [
            ['date' => '2025-08-01', 'counter' => 1000.2, 'device_id' => 'd_w1'],
            ['date' => '2026-07-30', 'counter' => 1212.0, 'device_id' => 'd_w1'],
        ]);
        $old = fn(array $pv) => array_values(array_filter($pv['proposals'], fn($p) => $p['kind'] === 'reading' && $p['data']['date'] === '2025-08-01'))[0];
        $id = $this->invoice('round', '1215');
        self::assertSame('exists', $old($this->inbox->preview($id))['status']);
        $d = $this->draft->draft(null, self::TODAY);
        $r = array_values(array_filter($d['items'], fn($i) => $i['kind'] === 'readings'))[0];
        $f = array_values(array_filter($r['fields'], fn($x) => $x['name'] === 'r:2025-08-01'))[0];
        self::assertSame(['exists', false], [$f['status'], in_array('conflict_existing', $f['reasons'], true)], 'Entwurf: kein „Im Bestand steht etwas anderes“');
        self::assertTrue(Proposals::sameStand(29310.2, 29310.0));
        self::assertTrue(Proposals::sameStand(1235.62, 1235.6));
        self::assertFalse(Proposals::sameStand(1001.0, 1000.0));
        self::assertFalse(Proposals::sameStand(1000.6, 1000.0), '1000,6 gerundet ist 1001');
        self::assertFalse(Proposals::sameStand(1235.66, 1235.6));
    }

    /** Skeptiker M13 — auch beim Gas: die Ablesung am letzten Tag ist der Endstand, der Tag danach keine Lücke. */
    public function testGasEndReadingOnTheLastDayIsNoGap(): void
    {
        $gid = $this->setMeterDevices('gas', [['id' => 'd_g1', 'serial' => null, 'installed_on' => '2020-01-01',
            'initial_counter' => 0.0, 'removed_on' => null, 'final_counter' => null, 'reason' => null]]);
        $this->setReadings('gas', $gid, [['date' => '2024-12-31', 'counter' => 1000.0, 'device_id' => 'd_g1'],
            ['date' => '2025-12-31', 'counter' => 2200.0, 'device_id' => 'd_g1']]);
        $bd = $this->consumption->billBreakdown('gas', $this->meters->get('gas', $gid), '2025-01-01', '2026-01-01');
        self::assertSame([0, 0.0], [$bd['totals']['gaps'], (float)end($bd['rows'])['m3']]);
        // 1200 m³ vom 31.12. bis 31.12. ohne den letzten Tag: 1200 × 364/365
        self::assertEqualsWithDelta(1200 * 364 / 365, (float)$bd['totals']['m3'], 0.1);
    }

    /** Skeptiker N15 — „schon vorhanden“, der Versorger heißt im Bestand aber anders: der Name dort wird genannt. */
    public function testTheDraftNamesTheSupplierAsRecorded(): void
    {
        $this->store->write('wasser/contracts.json', []);
        // derselbe Versorger (Rechtsform zählt nicht), im Bestand ohne „GmbH“ geschrieben
        $this->contracts->create('wasser', ['meter_id' => 'm_wasser_default', 'provider' => 'Stadtwerke Musterstadt', 'start' => '2025-08-01',
            'trinkwasser' => ['working_prices' => [['from' => '2025-08-01', 'ct_per_m3' => 200.0]]]]);
        DraftFixture::add($this->store, fn($b, $n) => $this->inbox->upload($b, $n), 'conf', 'contract_confirmation', [
            'utility' => 'wasser', 'supplier' => 'Stadtwerke Musterstadt GmbH', 'supply_start' => '01.08.2025', 'meter_number' => 'WZ-4711',
        ]);
        $d = $this->draft->draft(null, self::TODAY);
        $c = array_values(array_filter($d['items'], fn($i) => $i['kind'] === 'contract' && $i['utility'] === 'wasser'));
        self::assertNotEmpty($c, json_encode(array_column($d['items'], 'kind')));
        $p = array_values(array_filter($c[0]['fields'], fn($x) => $x['name'] === 'provider'))[0];
        self::assertSame(['exists', 'Stadtwerke Musterstadt'], [$p['status'], $p['existing_name'] ?? null]);
    }

    public function testTheHouseholdDraftComparesWithYourReadingsToo(): void
    {
        $field = function (string $end): array {
            $this->store->write('inbox.json', []);
            $this->invoice('d' . $end, $end);
            $d = $this->draft->draft(null, self::TODAY);
            $items = array_values(array_filter($d['items'], fn($i) => $i['kind'] === 'readings' && $i['utility'] === 'wasser'));
            self::assertCount(1, $items, json_encode(array_column($d['items'], 'kind')));
            return array_values(array_filter($items[0]['fields'], fn($f) => $f['name'] === 'r:2026-07-31'))[0];
        };
        $rep = $field('1210');
        self::assertSame(['exists', '2026-07-20'], [$rep['status'], $rep['reported_on'] ?? null]);
        $low = $field('1209');
        self::assertSame([true, ['side' => 'prev', 'date' => '2026-07-30', 'counter' => 1212.0]], [$low['check'], $low['neighbor'] ?? null]);
        self::assertContains('not_monotonic', $low['reasons']);
        $ok = $field('1215');
        self::assertSame(['new', false], [$ok['status'], $ok['check']]);
        self::assertArrayNotHasKey('neighbor', $ok);
    }
}
