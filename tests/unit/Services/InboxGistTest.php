<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\ActivityService;
use Energietracker\Services\AttachmentService;
use Energietracker\Services\BillService;
use Energietracker\Services\ForecastService;
use Energietracker\Services\Inbox\Applier;
use Energietracker\Services\Inbox\Gist;
use Energietracker\Services\Inbox\Proposals;
use Energietracker\Services\Inbox\ProviderRegistry;
use Energietracker\Services\InboxService;
use Energietracker\Services\ReminderService;
use Energietracker\Services\ResponsibilityService;
use Energietracker\Services\TariffSwitchService;
use Energietracker\Tests\Support\InboxFixture;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * v3.7.0 (Paket betrieb) — Kernaussage und nächster Schritt je Dokument: Was
 * bedeutet der Brief, was ist jetzt zu tun? Jede Zahl muss aus den vorhandenen
 * Diensten kommen (Wechselentscheidung, Saldo, Rechnungsprüfung, Zuständigkeit).
 *
 * Haushalt wie ScenarioServiceTest: feste Monatsmittel der Temperatur, Gas mit
 * Umrechnung 1, drei Jahre Monatsablesungen, ein laufender Vertrag und ein
 * günstigeres Angebot im Tarifvergleich.
 */
#[CoversClass(Gist::class)]
#[CoversClass(Proposals::class)]
#[CoversClass(InboxService::class)]
final class InboxGistTest extends ServiceTestCase
{
    private const AVG_TEMP = [1 => -1.0, 2 => 0.5, 3 => 4.0, 4 => 9.0, 5 => 14.0, 6 => 17.0,
        7 => 19.0, 8 => 18.5, 9 => 14.5, 10 => 9.5, 11 => 4.5, 12 => 1.0];
    private const PERSON_METER = 'u_mia';
    private const PERSON_CONTRACT = 'u_tom';

    private AttachmentService $att;
    private BillService $bills;
    private ReminderService $reminders;
    private ResponsibilityService $responsibility;
    private TariffSwitchService $switch;
    private string $meterId;
    private string $contractId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store->write('settings.json', ['gas_conversion_factors' => [['from' => null, 'kwh_per_m3' => 1.0]]]);
        $first = new \DateTimeImmutable(date('Y-m-01') . ' -36 months');
        $temps = [];
        for ($d = $first; $d <= new \DateTimeImmutable('+1 year'); $d = $d->modify('+1 day')) {
            $avg = self::AVG_TEMP[(int)$d->format('n')];
            $temps[$d->format('Y-m-d')] = ['avg' => $avg, 'min' => $avg - 3, 'max' => $avg + 3];
        }
        $this->store->write('temperatures.json', $temps);
        $this->meterId = $this->setMeterDevices('gas', [[
            'id' => 'd_gas_1', 'serial' => InboxFixture::METER, 'installed_on' => $first->format('Y-m-d'), 'initial_counter' => 0.0,
            'removed_on' => null, 'final_counter' => null, 'reason' => null,
        ]]);
        $rows = [['date' => $first->format('Y-m-d'), 'counter' => 0.0, 'device_id' => 'd_gas_1']];
        $counter = 0.0;
        for ($cur = $first; $cur < new \DateTimeImmutable(date('Y-m-01')); ) {
            [$y, $m] = [(int)$cur->format('Y'), (int)$cur->format('n')];
            $days = (int)$cur->format('t');
            $counter += 4.0 * $days * max(0.0, 15.0 - self::AVG_TEMP[$m]) + 20.0 * $days;
            $cur = $cur->modify('first day of next month');
            $rows[] = ['date' => $cur->format('Y-m-d'), 'counter' => round($counter, 3), 'device_id' => 'd_gas_1'];
        }
        $this->setReadings('gas', $this->meterId, $rows);
        $start = $first->format('Y-m-d');
        $this->contractId = (string)$this->contracts->create('gas', [
            'meter_id' => $this->meterId, 'provider' => 'Stadtwerke Musterstadt', 'tariff_name' => 'Gas Basis', 'start' => $start,
            'contract_number' => 'VK-778899',
            'working_prices' => [['from' => $start, 'ct_per_kwh' => 11.9]], 'base_prices' => [['from' => $start, 'eur_per_month' => 12.5]],
            'advance_payments' => [['from' => $start, 'amount_eur' => 140.0]],
        ])['id'];

        $this->att = new AttachmentService($this->store, $this->settings, $this->i18n);
        $this->bills = new BillService($this->store, $this->meters, $this->contracts, $this->consumption, $this->i18n);
        $this->reminders = new ReminderService($this->store, $this->settings, $this->i18n);
        $people = fn(string $id): ?array => match ($id) {
            self::PERSON_METER => ['id' => $id, 'name' => 'Mia'],
            self::PERSON_CONTRACT => ['id' => $id, 'name' => 'Tom'],
            default => null,
        };
        $this->responsibility = new ResponsibilityService($this->store);
        $this->responsibility->attachPeople($people);
        $this->reminders->attachPeople($people);
        $this->reminders->attachResponsibility($this->responsibility);
        $forecast = new ForecastService($this->consumption, $this->regression, $this->settings, $this->contracts, $this->i18n);
        $this->switch = new TariffSwitchService($forecast, $this->contracts, $this->meters, $this->i18n);
    }

    private function inbox(array $types, array $fields): InboxService
    {
        $provider = InboxFixture::provider('local', $types, $fields);
        $proposals = new Proposals($this->meters, $this->contracts, $this->readings, $this->bills, $this->reminders, $this->settings);
        $proposals->attachResponsibility($this->responsibility);
        $s = new InboxService($this->store, $this->i18n, $this->att, new ProviderRegistry($this->settings, $provider), $proposals,
            new Applier($this->meters, $this->contracts, $this->readings, $this->bills, $this->reminders, $this->settings, $this->i18n));
        $s->attachActivity(new ActivityService($this->store, $this->settings, $this->i18n));
        $s->attachGist(new Gist($this->meters, $this->contracts, $this->consumption, $this->bills, $this->readings, $this->switch, $this->responsibility));
        return $s;
    }

    /** Ein Dokument ablegen, erkennen, auslesen; Vorschau zurück. */
    private function read(InboxService $inbox, string $tag, array $pages): array
    {
        $e = $inbox->upload(InboxFixture::pdf($tag), "$tag.pdf");
        $inbox->classify($e['id'], $pages);
        $inbox->extract($e['id'], $pages);
        return $inbox->preview($e['id']);
    }

    private function shadow(float $wp, float $bp): void
    {
        $this->contracts->create('gas', ['meter_id' => $this->meterId, 'tariff_name' => 'Angebot', 'shadow_label' => 'Angebot', 'is_shadow' => true,
            'start' => date('Y-m-d'), 'working_prices' => [['from' => date('Y-m-d'), 'ct_per_kwh' => $wp]],
            'base_prices' => [['from' => date('Y-m-d'), 'eur_per_month' => $bp]]]);
    }

    private static function dmy(string $iso): string
    {
        return date('d.m.Y', (int)strtotime($iso));
    }

    private static function kinds(array $proposals, string $kind): array
    {
        return array_values(array_filter($proposals, fn($p) => $p['kind'] === $kind));
    }

    // ── Preisänderung ────────────────────────────────────────────────────

    public function testPriceChangeCostsPerYearFromTheForecastOfTheTariffSwitch(): void
    {
        $inbox = $this->inbox(['Preisanpassung' => 'price_change'], ['price_change' => InboxFixture::priceChangeAnswer()]);
        $pv = $this->read($inbox, 'pc', InboxFixture::priceChangePages());
        $g = $pv['gist'];
        $kwh = (float)$this->switch->analyze('gas', $this->meterId)['expected_consumption'];
        self::assertGreaterThan(1000, $kwh, 'Prognose vorhanden');
        // (12,80 − 11,90) ct × Verbrauch der nächsten zwölf Monate + (13,10 − 12,50) € × 12
        $extra = round((12.80 - 11.90) * $kwh / 100 + (13.10 - 12.50) * 12, 2);
        self::assertSame('inbox.gist.priceChange.more', $g['key']);
        self::assertSame(['kind' => 'money_year', 'value' => $extra, 'tone' => 'bad', 'signed' => true], $g['figure']);
        self::assertSame('2026-04-01', $g['params']['date']);
        self::assertSame(['date' => '2026-03-31'], array_values(array_filter($g['details'], fn($d) => str_starts_with($d['key'], 'inbox.gist.detail.termination')))[0]['params']);
        self::assertSame(['kwh' => round($kwh), 'unit' => 'kWh'], array_values(array_filter($g['details'], fn($d) => $d['key'] === 'inbox.gist.detail.basis'))[0]['params']);
        // ohne Angebot im Tarifvergleich: übernehmen ist der nächste Schritt, der Hinweis nennt den Tarifvergleich
        self::assertSame('apply', $g['action']['kind']);
        self::assertContains('inbox.gist.detail.noOffers', array_column($g['details'], 'key'));
        // gespeichert für die Liste
        self::assertSame($g['figure'], $inbox->list()[0]['gist']['figure']);
    }

    public function testPriceChangeLinksToTheTariffComparisonWhenAnOfferSaves(): void
    {
        $this->shadow(8.0, 9.0);
        $inbox = $this->inbox(['Preisanpassung' => 'price_change'], ['price_change' => InboxFixture::priceChangeAnswer()]);
        $g = $this->read($inbox, 'pc2', InboxFixture::priceChangePages())['gist'];
        $a = $this->switch->analyze('gas', $this->meterId);
        $best = min(array_map(fn($c) => $c['vs_reference_year2_eur'], array_filter($a['candidates'], fn($c) => empty($c['is_reference']))));
        self::assertLessThan(0, $best);
        self::assertSame(['kind' => 'link', 'key' => 'inbox.gist.action.compareTariffs', 'params' => ['amount' => round(-$best, 2)], 'href' => '#/tariffs'], $g['action']);
    }

    public function testPriceChangeWithoutForecastSaysSoInsteadOfGuessing(): void
    {
        $this->setReadings('gas', $this->meterId, []);
        $inbox = $this->inbox(['Preisanpassung' => 'price_change'], ['price_change' => InboxFixture::priceChangeAnswer()]);
        $g = $this->read($inbox, 'pc3', InboxFixture::priceChangePages())['gist'];
        self::assertSame('inbox.gist.priceChange.new', $g['key'], 'ohne Verbrauch keine Mehrkosten aus dem Grundpreis allein');
        self::assertNull($g['params']['amount']);
        self::assertContains('inbox.gist.detail.noForecast', array_column($g['details'], 'key'));
    }

    // ── Abschlagsänderung ────────────────────────────────────────────────

    public function testAdvanceChangeAgainstTheAdvanceThatSettlesTheBalance(): void
    {
        // Abrechnungsjahr ab diesem Monat: die laufende Abrechnung hat zwölf Monate vor sich
        $this->settings->set(['billing_cycle_anchor_gas' => date('m') . '-01']);
        $from = date('Y-m-01');
        $inbox = $this->inbox(['Abschlagsänderung' => 'advance_change'], ['advance_change' => InboxFixture::advanceChangeAnswer(self::dmy($from))]);
        $pv = $this->read($inbox, 'adv', InboxFixture::advanceChangePages(self::dmy($from)));
        $id = $pv['entry']['id'];
        $basis = array_values(array_filter($pv['gist']['details'], fn($d) => $d['key'] === 'inbox.gist.detail.advanceBasis'))[0]['params'] ?? null;
        self::assertNotNull($basis, 'Saldo der laufenden Abrechnung vorhanden: ' . json_encode($pv['gist']));
        $rec = (float)$basis['recommended'];
        // aus dem Saldo: Abschlag laut Plan + erwarteter Endsaldo ÷ Monate bis zur Abrechnung
        $status = $this->consumption->contractStatus('gas', $this->meters->get('gas', $this->meterId));
        $row = array_values(array_filter($status['contracts'], fn($r) => $r['contract_id'] === $this->contractId))[0];
        $months = count(array_filter($row['balance_path'], fn($p) => $p['ym'] >= substr($from, 0, 7)));
        self::assertSame(max(0.0, round(140.0 + $row['projected_end_balance'] / $months)), $rec);

        $inbox->update($id, ['edits' => ['advance_new' => $rec]]);
        $g = $inbox->preview($id)['gist'];
        self::assertSame('inbox.gist.advance.ok', $g['key']);
        self::assertSame('good', $g['figure']['tone']);

        $inbox->update($id, ['edits' => ['advance_new' => $rec + 80]]);
        $g = $inbox->preview($id)['gist'];
        self::assertSame('inbox.gist.advance.high', $g['key']);
        self::assertSame(['kind' => 'money_month', 'value' => 80.0, 'tone' => 'warn', 'signed' => true], $g['figure']);

        $inbox->update($id, ['edits' => ['advance_new' => max(0.0, $rec - 80)]]);
        self::assertSame('inbox.gist.advance.low', $inbox->preview($id)['gist']['key']);

        // Übernommen steht der neue Abschlag im Plan — der Vergleichswert bleibt derselbe
        $inbox->update($id, ['edits' => ['advance_new' => $rec + 80]]);
        $p = $inbox->preview($id);
        $inbox->apply($id, array_column(array_filter($p['proposals'], fn($x) => $x['kind'] === 'contract_advance'), 'id'));
        $after = $inbox->preview($id)['gist'];
        self::assertSame('inbox.gist.advance.high', $after['key']);
        self::assertEqualsWithDelta($rec, $after['params']['recommended'], 1.0, 'gleiche Rechnung vor und nach dem Übernehmen');
    }

    // ── Kündigung ────────────────────────────────────────────────────────

    public function testCancellationWithoutFollowUpGetsAReminderBeforeTheEndOfSupply(): void
    {
        $inbox = $this->inbox(['Kündigungsbestätigung' => 'cancellation'], ['cancellation' => InboxFixture::cancellationAnswer('30.06.2031')]);
        $pv = $this->read($inbox, 'cxl', InboxFixture::cancellationPages('30.06.2031'));
        $rem = self::kinds($pv['proposals'], 'reminder');
        self::assertCount(1, $rem);
        self::assertSame('followUp', $rem[0]['data']['label']);
        self::assertSame(date('Y-m-d', strtotime('2031-06-30 -' . Proposals::FOLLOW_UP_LEAD_DAYS . ' days')), $rem[0]['data']['next_due']);
        self::assertSame(['utility' => 'gas', 'meter_id' => $this->meterId], array_intersect_key($rem[0]['data'], ['utility' => 1, 'meter_id' => 1]));
        self::assertTrue($rem[0]['checked']);
        $g = $pv['gist'];
        self::assertSame('inbox.gist.cancellation.open', $g['key']);
        self::assertSame(['kind' => 'date', 'value' => '2031-06-30', 'tone' => 'bad'], $g['figure']);
        self::assertSame('#/tariffs', $g['action']['href']);

        // mit Anschlussvertrag: kein Termin, die Kernaussage nennt ihn
        $this->contracts->create('gas', ['meter_id' => $this->meterId, 'provider' => 'Musterenergie Nord', 'start' => '2031-07-01',
            'working_prices' => [['from' => '2031-07-01', 'ct_per_kwh' => 10.0]]]);
        $pv = $inbox->preview($pv['entry']['id']);
        self::assertSame([], self::kinds($pv['proposals'], 'reminder'));
        self::assertSame('inbox.gist.cancellation.followed', $pv['gist']['key']);
        self::assertSame('Musterenergie Nord', $pv['gist']['params']['provider']);
    }

    public function testFollowUpReminderIsDueBeforeTheEndAndNeverAfterIt(): void
    {
        self::assertSame('2031-06-16', Proposals::followUpDue('2031-06-30', '2026-10-21'));
        self::assertSame('2031-06-25', Proposals::followUpDue('2031-06-30', '2031-06-25'), 'zu spät für den Vorlauf: heute');
        self::assertNull(Proposals::followUpDue('2031-06-30', '2031-07-01'), 'nach dem Lieferende kein Termin mehr');
    }

    // ── Zählerstandsanforderung ──────────────────────────────────────────

    public function testReadingRequestLandsWithTheResponsiblePersonAndLinksToTheReadingForm(): void
    {
        $by = date('Y-m-d', strtotime('+60 days'));
        $meters = $this->store->read('gas/meters.json', []);
        foreach ($meters as &$m) if ($m['id'] === $this->meterId) $m['assignee_id'] = self::PERSON_METER;
        unset($m);
        $this->store->write('gas/meters.json', $meters);
        $inbox = $this->inbox(['Zählerstand mitteilen' => 'reading_request'], ['reading_request' => InboxFixture::readingRequestAnswer(self::dmy($by))]);
        $pv = $this->read($inbox, 'rr', InboxFixture::readingRequestPages(self::dmy($by)));
        $rem = self::kinds($pv['proposals'], 'reminder')[0];
        self::assertSame(ReminderService::CATEGORY_READING, $rem['data']['category']);
        self::assertSame($this->meterId, $rem['data']['meter_id']);
        self::assertArrayNotHasKey('assignee_id', $rem['data'], 'am Zähler erbt der Termin dessen Zuständige');
        self::assertSame('Mia', $rem['params']['person']);
        $g = $pv['gist'];
        self::assertSame('inbox.gist.reading.who', $g['key'], json_encode($g));
        self::assertSame('Mia', $g['params']['person']);
        self::assertSame('#/zaehlerstaende?meter=' . rawurlencode($this->meterId), $g['action']['href']);

        // der Vertrag hat eine eigene Zuständige: Sie geht vor und steht am Termin
        $contracts = $this->store->read('gas/contracts.json', []);
        foreach ($contracts as &$c) if ($c['id'] === $this->contractId) $c['assignee_id'] = self::PERSON_CONTRACT;
        unset($c);
        $this->store->write('gas/contracts.json', $contracts);
        $inbox->update($pv['entry']['id'], ['assignment' => ['utility' => 'gas', 'meter_id' => $this->meterId, 'contract_id' => $this->contractId]]);
        $pv = $inbox->preview($pv['entry']['id']);
        $rem = self::kinds($pv['proposals'], 'reminder')[0];
        self::assertSame(self::PERSON_CONTRACT, $rem['data']['assignee_id']);
        self::assertSame('Tom', $pv['gist']['params']['person']);
        $res = $inbox->apply($pv['entry']['id'], [$rem['id']]);
        self::assertCount(1, $res['applied']);
        $saved = array_values(array_filter($this->reminders->listWithStatus(), fn($r) => $r['id'] === $res['applied'][0]['ref']['id']))[0];
        self::assertSame([ReminderService::CATEGORY_READING, 'gas', $this->meterId, 'Tom', $by],
            [$saved['category'], $saved['utility'], $saved['meter_id'], $saved['assignee'], $saved['next_due']]);
    }

    public function testReadingRequestIsDoneOnceAReadingIsThere(): void
    {
        $by = date('Y-m-d', strtotime('+10 days'));
        $this->readings->create('gas', ['meter_id' => $this->meterId, 'date' => date('Y-m-d'), 'counter' => 999999]);
        $inbox = $this->inbox(['Zählerstand mitteilen' => 'reading_request'], ['reading_request' => InboxFixture::readingRequestAnswer(self::dmy($by))]);
        $g = $this->read($inbox, 'rr2', InboxFixture::readingRequestPages(self::dmy($by)))['gist'];
        self::assertSame('inbox.gist.reading.done', $g['key']);
        self::assertNull($g['action']);
        self::assertSame('good', $g['figure']['tone']);
    }

    // ── Zählerwechsel ────────────────────────────────────────────────────

    public function testMeterChangeOpensTheSwapDialogWithBothReadings(): void
    {
        $inbox = $this->inbox(['Zählerwechsel' => 'meter_change'], ['meter_change' => InboxFixture::meterChangeAnswer()]);
        $pv = $this->read($inbox, 'mc', InboxFixture::meterChangePages());
        $g = $pv['gist'];
        self::assertSame('inbox.gist.meterChange.todo', $g['key']);
        parse_str(substr($g['action']['href'], strpos($g['action']['href'], '?') + 1), $q);
        self::assertStringStartsWith('#/utility/gas/meters?', $g['action']['href']);
        self::assertSame(['replace' => $this->meterId, 'date' => '2026-05-15', 'old' => '13902.5', 'new' => '0.8', 'serial' => 'TEST-4711-02'],
            array_intersect_key($q, ['replace' => 1, 'date' => 1, 'old' => 1, 'new' => 1, 'serial' => 1]));
    }

    // ── Rechnung ─────────────────────────────────────────────────────────

    public function testInvoiceIsCheckedRightAfterTakingItOver(): void
    {
        $inbox = $this->inbox(['Jahresabrechnung' => 'invoice'], ['invoice' => InboxFixture::invoiceAnswer()]);
        $pv = $this->read($inbox, 'inv', InboxFixture::invoicePages());
        self::assertSame('inbox.gist.invoice.pending', $pv['gist']['key']);
        self::assertSame(['kind' => 'result', 'value' => 42.7, 'tone' => 'bad'], $pv['gist']['figure']);
        self::assertSame('apply', $pv['gist']['action']['kind']);
        $bill = self::kinds($pv['proposals'], 'bill')[0];
        $res = $inbox->apply($pv['entry']['id'], [$bill['id']]);
        $billId = (string)$res['applied'][0]['ref']['id'];
        $cmp = $this->bills->compare('gas', $billId);
        $g = $res['gist'];
        self::assertSame($cmp['verdict'], $g['check']['verdict'], 'dasselbe Urteil wie „Rechnung prüfen“');
        self::assertSame($cmp['delta']['eur'], $g['check']['delta_eur']);
        $want = $cmp['verdict'] === 'ok' ? 'inbox.gist.invoice.ok' : ((float)$cmp['delta']['eur'] > 0 ? 'inbox.gist.invoice.offMore' : 'inbox.gist.invoice.offLess');
        self::assertSame($want, $g['key']);
        if ($want !== 'inbox.gist.invoice.ok') self::assertSame(round(abs((float)$cmp['delta']['eur']), 2), $g['params']['amount']);
        foreach ($cmp['reasons'] as $r) self::assertContains('billCheck.reason.' . $r, array_column($g['details'], 'key'));
        self::assertSame($g['key'], $inbox->list()[0]['gist']['key'], 'gespeichert');
    }

    // ── Mahnung, Widerruf ────────────────────────────────────────────────

    public function testDunningGetsAReminderWithADeadline(): void
    {
        $inbox = $this->inbox(['Mahnung' => 'dunning'], []);
        $e = $inbox->upload(InboxFixture::pdf('dun'), 'mahnung.pdf');
        $inbox->classify($e['id'], ["Stadtwerke Musterstadt GmbH\nMahnung\nZählernummer: TEST-4711-01"]);
        $g = $inbox->preview($e['id'])['gist'];
        $due = date('Y-m-d', strtotime('+' . Gist::DUNNING_DAYS . ' days'));
        self::assertSame('inbox.gist.dunning.todo', $g['key']);
        self::assertSame(['kind' => 'remind', 'key' => 'inbox.gist.action.remind', 'due' => $due], $g['action']);
        // „Wieder in den Posteingang“ geholt: Mit dem Termin ist die Mahnung wieder abgelegt
        $inbox->update($e['id'], ['status' => 'new', 'assignment' => ['utility' => 'gas', 'meter_id' => $this->meterId, 'contract_id' => null]]);
        self::assertNotSame('filed', $inbox->get($e['id'])['status']);
        $entry = $inbox->remind($e['id'], $due);
        self::assertSame('filed', $entry['status']);
        self::assertSame('inbox.gist.dunning.set', $entry['gist']['key']);
        $r = array_values(array_filter($this->reminders->list(), fn($x) => $x['id'] === $entry['applied'][0]['ref']['id']))[0];
        self::assertSame([$due, $this->meterId], [$r['next_due'], $r['meter_id']]);
        self::assertStringContainsString('mahnung.pdf', $r['title']);
    }

    public function testWithdrawalIsOnlyFiled(): void
    {
        $inbox = $this->inbox(['Widerruf' => 'withdrawal'], []);
        $e = $inbox->upload(InboxFixture::pdf('wd'), 'widerruf.pdf');
        $entry = $inbox->classify($e['id'], ["Widerrufsbestätigung\nWiderruf Ihres Vertrags"]);
        self::assertSame('filed', $entry['status']);
        self::assertSame(['key' => 'inbox.gist.filed', 'figure' => null, 'action' => null], array_intersect_key($entry['gist'], ['key' => 1, 'figure' => 1, 'action' => 1]));
    }
}
