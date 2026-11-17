<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\ActivityService;
use Energietracker\Services\BackupService;
use Energietracker\Services\FeedInPayoutService;
use Energietracker\Support\LocalizedException;
use Energietracker\Tests\Support\ReportFixture;
use Energietracker\Tests\Support\ServiceTestCase;

/**
 * v3.13.0 (Paket vertrag) — Auszahlungen der Einspeisung: Topf `feed_in_payouts.json`, Zuordnung zum
 * Abrechnungsjahr (mit Zeitraum anteilig nach Tagen, sonst am Tag der Gutschrift), an der Saldo-Karte erhalten und
 * offen, im Verlauf, im Backup. Der Anspruch (`current_balance`, `projected_end_balance`) bleibt ohne Auszahlungen.
 * Das Beispiel steht so in docs/verstehen/12-pv.md, Abschnitt 12.
 */
final class FeedInPayoutServiceTest extends ServiceTestCase
{
    use ReportFixture;

    private const PV = 'm_pv_einspeisung_main';
    private const TODAY = '2026-06-15';   // Stand der Beispieldaten 31.05.2026, Abrechnungsjahr ab 01.01.

    private ActivityService $activity;

    private function payouts(): FeedInPayoutService
    {
        $this->activity = new ActivityService($this->store, $this->settings, $this->i18n);
        $s = new FeedInPayoutService($this->store, $this->meters, $this->i18n);
        $s->attachActivity($this->activity);
        $this->consumption->attachFeedInPayouts($s);
        return $s;
    }

    /** Das Schaufenster ohne seine zwei Auszahlungen (die Tests legen eigene an). */
    private function showcaseWithoutPayouts(): void
    {
        $this->importPersona('demo');
        $this->store->write('feed_in_payouts.json', []);
    }

    /**
     * Das Schaufenster bringt zwei Auszahlungen mit: die Jahresabrechnung 2025 (412,80 €, Zeitraum 2025, im Februar 2026
     * gutgeschrieben) und den Abschlag für das erste Quartal 2026 (60 €). Im Abrechnungsjahr 2026 zählen 60 €.
     */
    public function testShowcasePayoutsCountForTheirPeriod(): void
    {
        $this->importPersona('demo');
        $svc = $this->payouts();
        self::assertCount(2, $svc->list(self::PV));
        $row = $this->currentRow();
        self::assertSame(['received' => 60.0, 'count' => 1], array_intersect_key($row['payouts'], array_flip(['received', 'count'])));
        self::assertEqualsWithDelta((float)$row['current_balance'] - 60.0, $row['payouts']['open'], 0.005);
        self::assertSame(['received' => 412.8, 'count' => 1], $svc->receivedIn(self::PV, '2025-01-01', '2025-12-31', self::TODAY));
    }

    private function currentRow(): array
    {
        $rows = $this->consumption->contractStatus('pv_einspeisung', $this->meters->get('pv_einspeisung', self::PV), self::TODAY)['contracts'];
        return array_values(array_filter($rows, fn($r) => !empty($r['is_current'])))[0];
    }

    public function testReceivedFollowsTheBillingYearAndTheClaimStaysAsItWas(): void
    {
        $this->showcaseWithoutPayouts();
        $before = $this->currentRow();   // ohne angehängten Dienst: kein `payouts`
        self::assertArrayNotHasKey('payouts', $before);
        $svc = $this->payouts();
        $empty = $this->currentRow();
        self::assertSame(['from' => '2026-01-01', 'until' => '2026-12-31', 'received' => 0.0, 'count' => 0,
            'open' => $before['current_balance'], 'open_at_settle' => $before['projected_end_balance']], $empty['payouts']);

        // Doku-Beispiel: 120 € für Juli 2025 bis Juni 2026 → 181 von 365 Tagen in 2026 = 59,51 €
        $svc->create(['meter_id' => self::PV, 'date' => '2026-03-31', 'amount' => 120, 'period_from' => '2025-07-01', 'period_until' => '2026-06-30']);
        // ohne Zeitraum am Tag der Gutschrift
        $svc->create(['meter_id' => self::PV, 'date' => '2026-05-15', 'amount' => '100,00']);
        // die Jahresabrechnung für 2025, im Februar 2026 gutgeschrieben: zählt für 2025
        $svc->create(['meter_id' => self::PV, 'date' => '2026-02-15', 'amount' => 85, 'period_from' => '2025-01-01', 'period_until' => '2025-12-31']);
        // noch nicht gutgeschrieben
        $svc->create(['meter_id' => self::PV, 'date' => '2026-07-01', 'amount' => 50]);

        // am 01.04.: nur die 120 € (anteilig), die 100 € kommen erst im Mai
        self::assertSame(['received' => 59.51, 'count' => 1], $svc->receivedIn(self::PV, '2026-01-01', '2026-12-31', '2026-04-01'));
        // 2025: die Jahresabrechnung ganz und von den 120 € die 184 Tage Juli bis Dezember 2025 (60,49 €)
        self::assertSame(['received' => 145.49, 'count' => 2], $svc->receivedIn(self::PV, '2025-01-01', '2025-12-31', self::TODAY));

        $row = $this->currentRow();
        self::assertSame($before['current_balance'], $row['current_balance'], 'Anspruch bis heute unverändert');
        self::assertSame($before['projected_end_balance'], $row['projected_end_balance'], 'erwartet zur Abrechnung unverändert');
        self::assertSame($before['verdict'], $row['verdict']);
        self::assertSame(159.51, $row['payouts']['received']);
        self::assertSame(2, $row['payouts']['count']);
        self::assertEqualsWithDelta((float)$before['current_balance'] - 159.51, $row['payouts']['open'], 0.005);
        self::assertEqualsWithDelta((float)$before['projected_end_balance'] - 159.51, $row['payouts']['open_at_settle'], 0.005);
    }

    public function testInputIsCheckedAndAPeriodNeedsBothEnds(): void
    {
        $this->showcaseWithoutPayouts();
        $svc = $this->payouts();
        $cases = [
            'Strom ist keine Einspeisung' => [['meter_id' => 'm_strom_main', 'date' => '2026-03-31', 'amount' => 10], 'errors.feedInPayout.meterInvalid'],
            'ohne Tag'                    => [['meter_id' => self::PV, 'amount' => 10], 'errors.feedInPayout.dateInvalid'],
            'Betrag null'                 => [['meter_id' => self::PV, 'date' => '2026-03-31', 'amount' => 0], 'errors.feedInPayout.amountInvalid'],
            'Betrag Text'                 => [['meter_id' => self::PV, 'date' => '2026-03-31', 'amount' => 'viel'], 'errors.feedInPayout.amountInvalid'],
            'Betrag zu groß'              => [['meter_id' => self::PV, 'date' => '2026-03-31', 'amount' => 2e6], 'errors.feedInPayout.amountInvalid'],
            'nur Anfang'                  => [['meter_id' => self::PV, 'date' => '2026-03-31', 'amount' => 10, 'period_from' => '2026-01-01'], 'errors.feedInPayout.periodInvalid'],
            'Ende vor Anfang'             => [['meter_id' => self::PV, 'date' => '2026-03-31', 'amount' => 10, 'period_from' => '2026-02-01', 'period_until' => '2026-01-01'], 'errors.feedInPayout.periodInvalid'],
            'unbekannte Herkunft'         => [['meter_id' => self::PV, 'date' => '2026-03-31', 'amount' => 10, 'source' => 'magic'], 'errors.feedInPayout.sourceInvalid'],
            'Liste statt Text'            => [['meter_id' => self::PV, 'date' => '2026-03-31', 'amount' => 10, 'note' => ['x']], 'errors.common.textExpected'],
            // v3.13.0 (Paket F) — zu lang ist ein Fehler, gekürzt wird nie
            'Notiz zu lang'               => [['meter_id' => self::PV, 'date' => '2026-03-31', 'amount' => 10, 'note' => str_repeat('x', 501)], 'errors.common.tooLong'],
        ];
        foreach ($cases as $label => [$in, $key]) {
            try { $svc->create($in); self::fail("$label: angenommen"); }
            catch (LocalizedException $e) { self::assertSame($key, $e->key, $label); }
        }
        self::assertSame([], $svc->list(), 'nichts geschrieben');
        // eine Rückforderung ist negativ; eine Notiz genau an der Grenze (TextLimits: 500) bleibt ganz
        $p = $svc->create(['meter_id' => self::PV, 'date' => '2026-03-31', 'amount' => -12.345, 'note' => str_repeat('x', 500)]);
        self::assertSame([-12.35, 500, 'manual', null, null], [$p['amount'], mb_strlen($p['note']), $p['source'], $p['period_from'], $p['period_until']]);
        self::assertMatchesRegularExpression(FeedInPayoutService::ID, $p['id']);
        // ändern: nur die übergebenen Felder; ein leerer Zeitraum entfernt ihn
        $u = $svc->update($p['id'], ['period_from' => '2026-01-01', 'period_until' => '2026-03-31']);
        self::assertSame(['2026-01-01', '2026-03-31', -12.35], [$u['period_from'], $u['period_until'], $u['amount']]);
        self::assertSame([null, null], array_values(array_intersect_key($svc->update($p['id'], ['period_from' => '', 'period_until' => '']), array_flip(['period_from', 'period_until']))));
    }

    public function testHistoryRecordsAPayoutAndStrikesItThroughOnDelete(): void
    {
        $this->showcaseWithoutPayouts();
        $svc = $this->payouts();
        $p = $svc->create(['meter_id' => self::PV, 'date' => '2026-03-31', 'amount' => 120]);
        $e = array_values(array_filter($this->activity->all(), fn($x) => ($x['ref']['payout_id'] ?? null) === $p['id']));
        self::assertCount(1, $e);
        self::assertSame(['updated', 'feed_in_payout', 'activity.changed.feedInPayout', '2026-03-31'],
            [$e[0]['action'], $e[0]['subject']['kind'], $e[0]['title_key'], $e[0]['params']['date']]);
        self::assertArrayNotHasKey('undone', $e[0]);
        $deleted = $svc->delete($p['id']);
        self::assertSame($p, $deleted, 'die Antwort ist der gelöschte Eintrag (für „Rückgängig“)');
        self::assertSame([], $svc->list());
        $e = array_values(array_filter($this->activity->all(), fn($x) => ($x['ref']['payout_id'] ?? null) === $p['id']));
        self::assertArrayHasKey('undone', $e[0], 'durchgestrichen');
        $this->expectException(\Energietracker\Http\NotFoundException::class);
        $svc->delete($p['id']);
    }

    /** Home Assistant: `contract.payouts_*` an der Einspeisung, an allen übrigen Verträgen `null`; `balance` bleibt der Anspruch. */
    public function testSummaryCarriesReceivedAndOpen(): void
    {
        $this->showcaseWithoutPayouts();
        $svc = $this->payouts();
        $svc->create(['meter_id' => self::PV, 'date' => '2026-05-15', 'amount' => 100]);
        $reminders = new \Energietracker\Services\ReminderService($this->store, $this->settings, $this->i18n);
        $deliveries = new \Energietracker\Services\DeliveryService($this->store, $this->meters, $this->i18n);
        $recs = new \Energietracker\Services\RecommendationService($this->store, $this->meters, $this->consumption, $this->settings,
            new \Energietracker\Services\BenchmarkService($this->consumption, $this->meters, $this->settings, $this->i18n), $deliveries, $this->i18n);
        $agenda = new \Energietracker\Services\AgendaService($this->settings, $this->meters, $this->readings, $this->contracts, $this->consumption, $reminders, $recs, $this->i18n);
        $summary = (new \Energietracker\Services\SummaryService($this->settings, $this->meters, $this->readings, $this->consumption,
            new \Energietracker\Services\ForecastService($this->consumption, $this->regression, $this->settings, $this->contracts, $this->i18n),
            $deliveries, new \Energietracker\Services\PvSummaryService($this->consumption), new \Energietracker\Services\StromSaldoService($this->consumption),
            $agenda, new \Energietracker\Services\InstanceService($this->store)))->build(null, null, self::TODAY);
        $row = $this->currentRow();
        foreach ($summary['meters'] as $m) {
            $c = $m['contract'];
            if ($c === null) continue;
            if ($m['meter_id'] === self::PV) {
                self::assertSame([100.0, $row['payouts']['open'], $row['payouts']['open_at_settle'], $row['current_balance']],
                    [$c['payouts_received'], $c['payouts_open'], $c['payouts_open_at_settle'], $c['balance']], 'Einspeisung');
            } else {
                self::assertSame([null, null, null], [$c['payouts_received'], $c['payouts_open'], $c['payouts_open_at_settle']], $m['key']);
            }
        }
    }

    /** Lektion 18/89: ein neuer Topf steht in TOP_POTS und kommt aus der Sicherung zurück; unbrauchbare Einträge meldet der Import. */
    public function testBackupRoundTripAndImportCheck(): void
    {
        self::assertSame(FeedInPayoutService::FILE, BackupService::TOP_POTS['feed_in_payouts'] ?? null);
        $this->showcaseWithoutPayouts();
        $svc = $this->payouts();
        $a = $svc->create(['meter_id' => self::PV, 'date' => '2026-03-31', 'amount' => 120, 'period_from' => '2025-07-01', 'period_until' => '2026-06-30', 'note' => 'Halbjahr']);
        $b = $svc->create(['meter_id' => self::PV, 'date' => '2026-05-15', 'amount' => 100]);
        $backup = new BackupService($this->store, $this->i18n);
        $payload = $backup->export(false);
        self::assertEquals([$a, $b], $payload['feed_in_payouts'], 'im Backup (JSON: 120.0 steht als 120)');
        $this->store->write('feed_in_payouts.json', []);
        $backup->import($payload, false, true);
        self::assertSame([$a, $b], $svc->list(), 'Rundreise ohne Verlust');

        foreach ([['amount', 'viel', 'feed_in_payouts:0:amount'], ['amount', 2e6, 'feed_in_payouts:0:amount'], ['date', '15.05.2026', 'feed_in_payouts:0:date:date'],
                  ['id', 'p_1', 'feed_in_payouts:0:id'], ['meter_id', null, null]] as [$f, $v, $want]) {
            $bad = $payload;
            if ($want === null) unset($bad['feed_in_payouts'][0][$f]); else $bad['feed_in_payouts'][0][$f] = $v;
            $want ??= "feed_in_payouts:0:missing:$f";
            try {
                $backup->import($bad, true, true);
                self::fail("$want: angenommen");
            } catch (\Energietracker\Services\BackupInvalidException $e) {
                self::assertContains($want, array_map(fn($p) => $p['pot'] . ':' . $p['index'] . ':' . $p['problem'], $e->problems));
            }
        }
        self::assertSame([$a, $b], $svc->list(), 'nichts eingespielt');
    }
}
