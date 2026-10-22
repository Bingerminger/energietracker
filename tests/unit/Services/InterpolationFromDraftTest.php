<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\ActivityService;
use Energietracker\Services\AttachmentService;
use Energietracker\Services\BackupService;
use Energietracker\Services\BillService;
use Energietracker\Services\ConsumptionService;
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
 * v3.7.0 (#37, Zusammenführung mit „erstbefuellung“) — Zähler, die aus dem
 * Entwurf „Haushalt aus Dokumenten“ entstehen, tragen die Kennung gemessen /
 * verteilt wie jeder andere: Jahresrechnungen bringen eine Ablesung je Jahr,
 * also sind alle Monate verteilt, und die Abweichungstabelle prüft keinen.
 * Die Summe zwischen den Ablesungen bleibt die der Rechnungen. Erfundene
 * Dokumente, keine echten Anbieter, Nummern oder Beträge.
 */
#[CoversClass(ConsumptionService::class)]
final class InterpolationFromDraftTest extends ServiceTestCase
{
    private const TODAY = '2025-02-15';

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
    }

    private function invoice(string $tag, string $from, string $to, string $oldDate, string $old, string $new): void
    {
        DraftFixture::add($this->store, fn($b, $n) => $this->inbox->upload($b, $n), $tag, 'invoice', [
            'utility' => 'gas', 'supplier' => 'Stadtwerke Musterhausen', 'kind' => 'annual', 'market_location' => DraftFixture::MALO_A,
            'meter_number' => 'GAS-A', 'period_from' => $from, 'period_to' => $to,
            'reading_old' => $old, 'reading_old_date' => $oldDate, 'reading_new' => $new, 'reading_new_date' => $to,
            'total_amount' => '900,00', 'result_kind' => 'due', 'result_amount' => '10,00',
        ]);
    }

    /**
     * v3.7.0 (Zusammenführung mit „treffsicher“, Nachbesserung 4) — ein Stand, den die
     * Rechnung als geschätzt ausweist, legt der Posteingang mit `is_estimated: true` an
     * (Inbox\Applier über ReadingService::create). Er misst nichts: Der Abstand reicht von
     * der echten Ablesung davor bis zur echten danach, wie bei jedem geschätzten Stand.
     */
    public function testAnEstimatedReadingFromTheInboxDoesNotMeasure(): void
    {
        $id = $this->setMeterDevices('gas', [['id' => 'd1', 'serial' => null, 'installed_on' => '2025-01-01', 'initial_counter' => 0.0,
            'removed_on' => null, 'final_counter' => null, 'reason' => null]]);
        $this->readings->create('gas', ['meter_id' => $id, 'date' => '2025-01-01', 'counter' => 1000.0]);
        // wie Inbox\Applier::reading(): geschätzt laut Rechnung
        $this->readings->create('gas', ['is_estimated' => true, 'meter_id' => $id, 'date' => '2025-02-01', 'counter' => 1300.0,
            'note' => 'Posteingang', 'source' => 'inbox']);
        $this->readings->create('gas', ['meter_id' => $id, 'date' => '2025-03-01', 'counter' => 1560.0]);
        $this->readings->create('gas', ['meter_id' => $id, 'date' => '2025-04-01', 'counter' => 1800.0]);
        $meter = $this->meters->list('gas')[0];
        $byYm = array_column($this->consumption->forMeter('gas', $meter), null, 'ym');
        self::assertSame([true, true, false], [$byYm['2025-01']['interpolated'], $byYm['2025-02']['interpolated'], $byYm['2025-03']['interpolated']],
            '1.1. → 1.3. sind 59 Tage: verteilt, obwohl am 1.2. ein (geschätzter) Stand steht');
        self::assertSame(59, $byYm['2025-02']['reading_gap_days']);
        self::assertSame(3, $this->consumption->interpolationInfo('gas', $meter, array_values($byYm))['readings'], 'der geschätzte zählt nicht als Ablesung');
        self::assertFalse($this->consumption->boundaryMeasured('gas', $meter, '2025-02-01'), 'und misst keine Grenze');
    }

    public function testMetersFromTheDraftCarryTheMarker(): void
    {
        $this->invoice('g23', '01.01.2023', '31.12.2023', '31.12.2022', '1000', '2200');
        $this->invoice('g24', '01.01.2024', '31.12.2024', '31.12.2023', '2200', '3350');
        $d = $this->draft->draft(null, self::TODAY);
        $res = $this->draft->apply(array_column(array_filter($d['items'], fn($i) => $i['checked']), 'id'), [], [], null, self::TODAY);
        self::assertSame([], $res['failed'], json_encode($res['failed']));

        $meter = $this->meters->list('gas')[0];
        self::assertCount(3, $this->readings->list('gas', (string)$meter['id']), 'drei Stände aus zwei Rechnungen');
        $monthly = $this->consumption->forMeter('gas', $meter);
        $years = array_values(array_filter($monthly, fn($m) => in_array((int)$m['year'], [2023, 2024], true)));
        self::assertCount(24, $years);
        foreach ($years as $m) {
            self::assertTrue($m['interpolated'], "{$m['ym']} verteilt");
            self::assertSame(0.0, (float)$m['measured_share'], $m['ym']);
            self::assertContains($m['reading_gap_days'], [365, 366], $m['ym']);
        }
        $info = $this->consumption->interpolationInfo('gas', $meter, $monthly);
        self::assertSame(3, $info['readings']);
        self::assertSame($info['months'], $info['interpolated_months']);
        self::assertSame([], $this->anomalies->detect('gas', $monthly), 'kein verteilter Monat in der Tabelle');
        // die Summe zwischen den Ablesungen bleibt die der Rechnungen (der Stand gilt am Morgen
        // seines Tages: Der 31.12.2022 gehört schon zum ersten Abstand)
        self::assertEqualsWithDelta(2350.0, array_sum(array_map(fn($m) => (float)$m['m3'], $monthly)), 0.01);
    }
}
