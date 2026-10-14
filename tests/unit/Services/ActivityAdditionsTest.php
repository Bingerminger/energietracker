<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\ActivityService;
use Energietracker\Services\AgendaService;
use Energietracker\Services\BenchmarkService;
use Energietracker\Services\CsvExportService;
use Energietracker\Services\DeliveryService;
use Energietracker\Services\PeriodService;
use Energietracker\Services\RecommendationService;
use Energietracker\Services\ReminderService;
use Energietracker\Services\TemperatureService;
use Energietracker\Services\TenancyService;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * v3.5.0 — Nachträge zu F1024 „Erledigt mit Verlauf“: Ein neuer Zeitraum bei
 * Zeitraum-Zählern erledigt „Zählerstand ablesen“ wie eine Ablesung; eine
 * geänderte Nebenkostenabrechnung steht im Verlauf („geändert“), ohne
 * doppelte Auto-Erledigung; der Verlauf als CSV zum Nachweis (Format 1 und
 * „local“, Filter, Schutz gegen Formeln).
 */
#[CoversClass(ActivityService::class)]
#[CoversClass(CsvExportService::class)]
final class ActivityAdditionsTest extends ServiceTestCase
{
    private ActivityService $activity;
    private AgendaService $agenda;
    private PeriodService $periods;
    private TenancyService $tenancies;
    private ReminderService $reminderService;
    private string $today;

    protected function setUp(): void
    {
        parent::setUp();
        $this->today = date('Y-m-d');
        $this->reminderService = new ReminderService($this->store, $this->settings, $this->i18n);
        $deliveries = new DeliveryService($this->store, $this->meters, $this->i18n);
        $this->tenancies = new TenancyService($this->store, $this->meters, $this->i18n);
        $recs = new RecommendationService($this->store, $this->meters, $this->consumption, $this->settings,
            new BenchmarkService($this->consumption, $this->meters, $this->settings, $this->i18n), $deliveries, $this->i18n);
        $this->agenda = new AgendaService($this->settings, $this->meters, $this->readings, $this->contracts,
            $this->consumption, $this->reminderService, $recs, $this->i18n, $this->tenancies);
        $this->periods = new PeriodService($this->store, $this->meters, $this->i18n);
        $this->activity = new ActivityService($this->store, $this->settings, $this->i18n);
        foreach ([$this->readings, $deliveries, $this->tenancies, $this->contracts, $this->reminderService, $recs, $this->agenda, $this->periods] as $svc) {
            $svc->attachActivity($this->activity);
        }
        $this->activity->attach($this->reminderService, $this->contracts, $recs);
    }

    private function day(int $offset): string
    {
        return date('Y-m-d', (int)strtotime("{$this->today} $offset days"));
    }

    private function readingDue(string $meterId): ?array
    {
        foreach ($this->agenda->events(90, $this->today) as $e) {
            if ($e['kind'] === 'reading_due' && ($e['ref']['meter_id'] ?? null) === $meterId) return $e;
        }
        return null;
    }

    // ── Zeitraum-Zähler ──────────────────────────────────────────────────

    public function testANewPeriodClosesReadingDueLikeAReading(): void
    {
        $m = (string)$this->meters->create('strom', ['name' => 'Portal', 'capture' => 'period', 'installed_on' => $this->day(-400)])['id'];
        self::assertTrue($this->readingDue($m)['due_now'], 'ohne Daten: Hinweis offen');
        $this->periods->create('strom', ['meter_id' => $m, 'from' => $this->day(-130), 'to' => $this->day(-100), 'value' => 300]);
        $first = $this->activity->list(['kind' => 'reading_due']);
        self::assertCount(1, $first, 'erster Zeitraum: der Hinweis war offen (wie bei der ersten Ablesung)');
        self::assertSame("reading-strom-$m@none", $first[0]['subject']['occurrence']);
        // Hinweis: letzter Zeitraum vor 100 Tagen, Warnung nach 45 Tagen
        self::assertTrue($this->readingDue($m)['due_now']);

        $p = $this->periods->create('strom', ['meter_id' => $m, 'from' => $this->day(-99), 'to' => $this->day(-2), 'value' => 900]);
        $log = $this->activity->list(['kind' => 'reading_due', 'from' => $this->day(-50)]);
        self::assertCount(1, $log);
        self::assertSame(['auto_done', "reading-strom-$m@" . $this->day(-100), $this->day(-2)],
            [$log[0]['action'], $log[0]['subject']['occurrence'], $log[0]['done_on']]);
        self::assertSame(['activity.reason.period', ['from' => $p['from'], 'to' => $p['to']]], [$log[0]['reason']['key'], $log[0]['reason']['params']]);
        self::assertFalse($this->readingDue($m)['due_now']);

        // nicht fällig: kein Eintrag (kein Fluten durch Importe)
        $this->periods->create('strom', ['meter_id' => $m, 'from' => $this->day(-1), 'to' => $this->day(-1), 'value' => 10]);
        self::assertCount(2, $this->activity->list(['kind' => 'reading_due']));
    }

    public function testThePeriodImportRecordsOnceWithTheNewestPeriod(): void
    {
        $m = (string)$this->meters->create('strom', ['name' => 'Portal', 'capture' => 'period', 'installed_on' => $this->day(-400)])['id'];
        $this->periods->create('strom', ['meter_id' => $m, 'from' => $this->day(-200), 'to' => $this->day(-150), 'value' => 300]);
        $csv = "von;bis;wert\n" . implode("\n", [
            date('d.m.Y', strtotime($this->day(-149))) . ';' . date('d.m.Y', strtotime($this->day(-100))) . ';200',
            date('d.m.Y', strtotime($this->day(-99))) . ';' . date('d.m.Y', strtotime($this->day(-3))) . ';500',
        ]);
        $r = $this->periods->importCsv('strom', $m, $csv);
        self::assertSame(2, $r['imported']);
        $log = $this->activity->list(['kind' => 'reading_due', 'from' => $this->day(-140)]);
        self::assertCount(1, $log, 'ein Eintrag je Import (der erste Zeitraum hatte seinen eigenen)');
        self::assertSame($this->day(-3), $log[0]['done_on']);
        self::assertSame("reading-strom-$m@" . $this->day(-150), $log[0]['subject']['occurrence']);
    }

    // ── Nebenkostenabrechnung geändert ───────────────────────────────────

    public function testAChangedStatementIsRecordedWithoutASecondAutoDone(): void
    {
        $this->settings->set(['wohnverhaeltnis' => 'miete']);
        $y = (int)substr($this->today, 0, 4);
        $t = $this->tenancies->create(['start' => ($y - 2) . '-01-01', 'billing_anchor' => '01-01', 'label' => 'Wohnung']);
        $open = ($y - 1) . '-12-31';
        $s = $this->tenancies->createStatement($t['id'], ['period_from' => ($y - 1) . '-01-01', 'period_to' => $open, 'total_cost_eur' => 1200, 'prepaid_eur' => 1000]);
        $auto = $this->activity->list(['kind' => 'tenancy_statement_due']);
        self::assertCount(1, $auto, 'Erfassen erledigt den Hinweis');

        $this->tenancies->updateStatement($t['id'], $s['id'], ['total_cost_eur' => 1250]);
        $all = $this->activity->all();
        self::assertCount(2, $all, json_encode(array_map(fn($e) => [$e['action'], $e['subject']], $all)));
        $upd = $this->activity->list(['kind' => 'tenancy_statement'])[0];
        self::assertSame(['updated', 'tenancy_statement-' . $s['id'], $t['id'], $s['id'], $this->today],
            [$upd['action'], $upd['subject']['uid'], $upd['ref']['tenancy_id'], $upd['ref']['statement_id'], $upd['done_on']]);
        self::assertSame('activity.changed.statement', $upd['title_key']);
        self::assertSame(['label' => 'Wohnung', 'from' => ($y - 1) . '-01-01', 'to' => $open], $upd['params']);
        self::assertCount(1, $this->activity->list(['kind' => 'tenancy_statement_due']), 'keine zweite Auto-Erledigung');
        self::assertNull($this->activity->doneEntry('tenancy_statement-' . $s['id'] . '@' . $open), '„geändert“ erledigt nichts');

        // nichts geändert: kein Eintrag
        $this->tenancies->updateStatement($t['id'], $s['id'], ['total_cost_eur' => 1250]);
        self::assertCount(2, $this->activity->all());

        // der Zeitraum rutscht weg: Erledigung durchgestrichen, Hinweis wieder offen
        $this->tenancies->updateStatement($t['id'], $s['id'], ['period_from' => ($y - 3) . '-01-01', 'period_to' => ($y - 3) . '-12-31']);
        self::assertNotEmpty($this->activity->get($auto[0]['id'])['undone']);
        // … und zurück: Die Änderung erledigt ihn (einmal), statt eines „geändert“
        $this->tenancies->updateStatement($t['id'], $s['id'], ['period_from' => ($y - 1) . '-01-01', 'period_to' => $open]);
        $open2 = array_values(array_filter($this->activity->list(['kind' => 'tenancy_statement_due']), fn($e) => empty($e['undone'])));
        self::assertCount(1, $open2, 'genau eine gültige Erledigung');
        self::assertSame($s['id'], $open2[0]['ref']['statement_id']);
    }

    // ── Verlauf als CSV ──────────────────────────────────────────────────

    private function csv(): CsvExportService
    {
        $temps = new TemperatureService($this->store, $this->settings, new \Energietracker\Services\WeatherService());
        return new CsvExportService($this->consumption, $this->readings, $this->meters, $temps,
            new DeliveryService($this->store, $this->meters, $this->i18n), $this->i18n);
    }

    public function testTheHistoryAsCsvWithFormulaProtection(): void
    {
        $r = $this->reminderService->create(['title' => 'Rauchmelder', 'next_due' => $this->day(-1), 'recurrence' => 'yearly', 'assignee' => 'Anna']);
        $this->reminderService->markDone($r['id'], $this->day(-1), '=HYPERLINK("http://example.org";"x")');
        $entries = $this->activity->list();
        self::assertCount(1, $entries);

        $v1 = $this->csv()->activity($entries, $this->activity, '1');
        self::assertStringStartsWith("\xEF\xBB\xBF", $v1, 'UTF-8-BOM wie die übrigen Exporte');
        $lines = explode("\r\n", substr($v1, 3));
        self::assertSame('Datum;Aktion;Titel;Wer;Grund;Notiz', $lines[0], 'Format 1: feste Kopfzeile');
        self::assertSame($this->day(-1) . ';done;Rauchmelder;;;"\'=HYPERLINK(""http://example.org"";""x"")"', $lines[1],
            'Aktion als Code, Notiz mit Apostroph gegen Formeln (und gequotet)');

        $this->settings->set(['language' => 'en']);
        $en = $this->csv()->activity($entries, $this->activity, 'local', 'en');
        $el = explode("\r\n", substr($en, 3));
        self::assertSame('Date,Action,Title,By,Reason,Note', $el[0], '„local“ englisch: Kopf der Sprache, Komma als Trenner');
        self::assertStringStartsWith(date('d/m/Y', strtotime($this->day(-1))) . ',done,Rauchmelder,', $el[1]);
        self::assertSame('verlauf', explode('-', basename($this->csv()->filename('activity', null, '1')))[1]);
        self::assertStringContainsString('-history-', $this->csv()->filename('activity', null, 'local', 'en'));
    }

    public function testDescribeNamesWhoAndWhyInTheLanguageOfTheExport(): void
    {
        $e = ['action' => 'auto_done', 'title_key' => 'calendar.event.readingDue', 'params' => ['label' => 'Strom', 'meter' => 'Haus'],
              'title' => 'alt', 'by' => ['type' => 'auto'], 'reason' => ['key' => 'activity.reason.reading', 'params' => ['date' => '2026-03-04']]];
        $d = $this->activity->describe($e);
        self::assertSame(['automatisch erledigt', 'Strom: Zählerstand ablesen (Haus)', 'automatisch', 'Zählerstand vom 04.03.2026 erfasst'],
            [$d['action'], $d['title'], $d['by'], $d['reason']]);
        self::assertSame('ehemalige Person', $this->activity->describe(['action' => 'done', 'by' => ['type' => 'person', 'former' => true]])['by']);
        self::assertSame('Home Assistant', $this->activity->describe(['action' => 'done', 'by' => ['type' => 'integration', 'name' => 'home_assistant']])['by']);
        self::assertSame('Momentaufnahme', $this->activity->describe(['action' => 'dismissed', 'title' => 'Momentaufnahme'])['title'], 'ohne Schlüssel: die Momentaufnahme');
        self::assertSame('geändert', $this->activity->describe(['action' => 'updated'])['action']);
    }
}
