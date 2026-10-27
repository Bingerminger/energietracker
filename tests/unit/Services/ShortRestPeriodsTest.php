<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\BillExpectedService;
use Energietracker\Services\BillingPeriodService;
use Energietracker\Services\BillService;
use Energietracker\Services\PeriodService;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * v3.7.0 (GitHub #33, Nachbesserung zu F1030) — Der Stichtag ist der erste
 * Tag der Abrechnungsperiode. Der gemeldete Fall: Stichtag 31-08, die Verträge
 * beginnen jedes Jahr am 01.09. (jeweils ein anderer Versorger). Bis v3.6
 * ergab das jedes Jahr eine Periode 31.08.–31.08.; jetzt geht so ein Kurzrest
 * in die Nachbarperiode auf, und die App schlägt den 01-09 als Stichtag vor.
 *
 * Daten (erfunden): Strom ab 2023-09-01, 250 kWh je Monat, Ablesung am
 * Monatsersten; drei Verträge je ein Jahr, 30/32/34 ct, Grundpreis 10 €,
 * Abschlag 90 €.
 */
#[CoversClass(BillingPeriodService::class)]
#[CoversClass(BillExpectedService::class)]
final class ShortRestPeriodsTest extends ServiceTestCase
{
    private const TODAY = '2026-03-15';
    private string $meterId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->meterId = $this->setMeterDevices('strom', [[
            'id' => 'd1', 'serial' => null, 'installed_on' => '2023-09-01', 'initial_counter' => 0.0,
            'removed_on' => null, 'final_counter' => null, 'reason' => null,
        ]]);
        $rows = [];
        for ($m = 0; $m <= 30; $m++) $rows[] = ['date' => date('Y-m-d', (int)strtotime("2023-09-01 +$m months")), 'counter' => 250.0 * $m, 'device_id' => 'd1'];
        $this->setReadings('strom', $this->meterId, $rows);
        // je Jahr ein anderer Versorger, Vertragsbeginn immer am 01.09.
        foreach ([['Stadtwerke Beispielstadt', '2023-09-01', '2024-08-31', 30.0], ['Nordlicht Energie', '2024-09-01', '2025-08-31', 32.0],
                  ['Südwind Strom', '2025-09-01', null, 34.0]] as [$provider, $start, $end, $ct]) {
            $this->contracts->create('strom', ['meter_id' => $this->meterId, 'provider' => $provider, 'start' => $start, 'end' => $end,
                'auto_renews' => false,
                'working_prices' => [['from' => $start, 'ct_per_kwh' => $ct]],
                'base_prices' => [['from' => $start, 'eur_per_month' => 10.0]],
                'advance_payments' => [['from' => $start, 'amount_eur' => 90.0]]]);
        }
    }

    private function bills(): BillService
    {
        return new BillService($this->store, $this->meters, $this->contracts, $this->consumption, $this->i18n);
    }

    private function forMeter(string $today = self::TODAY): array
    {
        return (new BillingPeriodService($this->settings, $this->meters, $this->readings, $this->consumption, $this->bills()))
            ->forTarget('strom', $this->meters->get('strom', $this->meterId), true, $today);
    }

    /** @return list<string> „von..bis“, `+S` am Wechsel, `/teil` für Teilperioden, `*` für die laufende */
    private static function spans(array $periods): array
    {
        return array_map(fn($p) => $p['from'] . '..' . $p['until'] . (!empty($p['ends_at_switch']) ? '+S' : '')
            . ($p['running'] ? '*' : ($p['complete'] ? '' : '/teil')), $periods);
    }

    public function testTheReportedCaseHasNoOneDayPeriodsAnymore(): void
    {
        $this->settings->set(['billing_cycle_anchor_strom' => '08-31']);
        $r = $this->forMeter();
        // bis v3.6: …2024-08-31..2024-08-31+S, …2025-08-31..2025-08-31+S
        self::assertSame(['2023-09-01..2024-08-31+S/teil', '2024-09-01..2025-08-31+S', '2025-09-01..2026-08-30*'], self::spans($r['periods']));
        self::assertSame('2024/25', $r['periods'][1]['label'], 'ein ganzes Vertragsjahr trägt seinen Namen');
        foreach ($r['periods'] as $p) {
            self::assertGreaterThanOrEqual(BillingPeriodService::SHORT_REST_DAYS, (int)$p['days'], "keine Periode unter zwei Wochen: {$p['from']}");
        }
        // das zweite Jahr nur zu den Preisen des zweiten Vertrags, mit seinem 31. August
        self::assertEqualsWithDelta(12 * 250 * 0.32 + 12 * 10, $r['periods'][1]['cost'], 0.05);
        self::assertEqualsWithDelta(12 * 90.0, $r['periods'][1]['advances'], 0.01);
        // und die Vorschläge: kein Rechnung, also der Beginn des jüngsten Lieferantenwechsels
        self::assertSame('09-01', $r['suggested_anchor']);
        self::assertSame('contracts', $r['suggested_anchor_source']);
    }

    public function testTheSumsStayTheSameWhenARestIsMerged(): void
    {
        foreach (['08-31', '08-29', '09-05', '09-01'] as $anchor) {
            $this->settings->set(['billing_cycle_anchor_strom' => $anchor]);
            $r = $this->forMeter();
            $q = array_sum(array_column($r['periods'], 'kwh'));
            $cost = array_sum(array_column($r['periods'], 'cost'));
            $adv = array_sum(array_column($r['periods'], 'advances'));
            // gerechnet wird bis zur letzten Ablesung (ausschließlich), wie jede Periode
            $all = $this->consumption->billBreakdown('strom', $this->meters->get('strom', $this->meterId), '2023-09-01',
                (string)$r['measured_until'])['totals'];
            self::assertEqualsWithDelta((float)$all['kwh'], $q, 0.5, "$anchor: Menge");
            self::assertEqualsWithDelta((float)$all['total'], $cost, 0.05, "$anchor: Kosten");
            self::assertEqualsWithDelta(30 * 90.0, $adv, 0.05, "$anchor: Abschläge");
            // lückenlos: jede Periode beginnt am Tag nach der vorigen
            foreach ($r['periods'] as $i => $p) {
                if ($i > 0) self::assertSame(date('Y-m-d', (int)strtotime($r['periods'][$i - 1]['until'] . ' +1 day')), $p['from'], "$anchor: lückenlos");
            }
        }
    }

    public function testARestAfterTheSwitchJoinsTheNextPeriod(): void
    {
        // Stichtag 05.09., Verträge ab 01.09.: vier Tage des neuen Vertrags vor dem Stichtag
        $this->settings->set(['billing_cycle_anchor_strom' => '09-05']);
        self::assertSame(['2023-09-01..2023-09-04/teil', '2023-09-05..2024-08-31+S', '2024-09-01..2025-08-31+S', '2025-09-01..2026-09-04*'],
            self::spans($this->forMeter()['periods']), 'am Beginn der Daten bleibt der Rest (dort wechselt nichts)');
        // die laufende Periode, solange heute im Rest liegt: schon bis zum übernächsten Stichtag
        $early = $this->forMeter('2025-09-03')['periods'];
        self::assertSame('2025-09-01..2026-09-04*', self::spans($early)[count($early) - 1]);
    }

    /**
     * 17 Tage (Stichtag 18.09.) sind kein Kurzrest — ein eigener, kurzer erster Abschnitt.
     * v3.9.0 (Skeptiker M7) — bis dahin auch im Vertragsjahr 2024/25, das an zwei Wechseln
     * beginnt und endet: Diese Lieferzeit rechnet der Versorger zum Vertragsende ab, sie ist
     * eine Periode (SWITCH_PERIOD_MAX_DAYS). Der Rest bleibt, wo die Lieferung nicht an
     * einem Wechsel endet (der laufende Vertrag ohne Nachfolger).
     */
    public function testAProperRestStaysItsOwnPeriodUnlessASwitchEndsTheSupply(): void
    {
        $this->settings->set(['billing_cycle_anchor_strom' => '09-18']);
        $spans = self::spans($this->forMeter()['periods']);
        self::assertContains('2024-09-01..2025-08-31+S', $spans, 'eine Lieferzeit zwischen zwei Wechseln, Ende = Vertragsende');
        self::assertNotContains('2024-09-01..2024-09-17', $spans);
        self::assertContains('2025-09-01..2025-09-17', $spans, 'ohne Wechsel am Ende: der Rest bleibt eigener Abschnitt');
        // passt der Stichtag zu den Verträgen, gibt es keinen Vorschlag
        $this->settings->set(['billing_cycle_anchor_strom' => '09-01']);
        $r = $this->forMeter();
        self::assertSame(['2023-09-01..2024-08-31+S', '2024-09-01..2025-08-31+S', '2025-09-01..2026-08-31*'], self::spans($r['periods']));
        self::assertNull($r['suggested_anchor']);
    }

    public function testTheSuggestionFromContractsWithoutASwitch(): void
    {
        // derselbe Versorger jedes Jahr zum 01.10. (Verlängerung): kein Wechsel, aber zwei Beginne am selben Tag
        $this->store->write('strom/contracts.json', []);
        foreach ([['2023-10-01', '2024-09-30'], ['2024-10-01', null]] as [$start, $end]) {
            $this->contracts->create('strom', ['meter_id' => $this->meterId, 'provider' => 'Stadtwerke Beispielstadt', 'start' => $start, 'end' => $end,
                'working_prices' => [['from' => $start, 'ct_per_kwh' => 30.0]]]);
        }
        $r = $this->forMeter();
        self::assertSame(['10-01', 'contracts'], [$r['suggested_anchor'], $r['suggested_anchor_source']]);
        // ein einzelner Vertrag ohne Wechsel: kein Vorschlag
        self::assertNull(BillingPeriodService::suggestedAnchorFromContracts(['2024-10-01'], [], '01-01'));
        // mit einer Rechnung zählt die Rechnung (Tag nach ihrem Ende)
        $this->bills()->create('strom', ['meter_id' => $this->meterId, 'period_from' => '2024-01-01', 'period_to' => '2024-12-31',
            'invoice' => ['amount_eur' => 1000.0]]);
        $r = $this->forMeter();
        self::assertSame([null, null], [$r['suggested_anchor'], $r['suggested_anchor_source']], 'Rechnung endet am Tag vor dem Stichtag 01-01');
    }

    /** Doku-Behauptung: Handbuch und API-Referenz nennen die Schwelle, mit der gerechnet wird (DE + EN). */
    public function testTheDocsNameTheThreshold(): void
    {
        $n = BillingPeriodService::SHORT_REST_DAYS;
        $root = dirname(__DIR__, 3);
        foreach (['docs/referenz/api.md' => "Ein Stück unter $n Tagen", 'docs/anleitungen/jahresabrechnung.md' => "Reste unter $n Tagen",
                  'docs/en/referenz/api.md' => "a piece under $n days", 'docs/en/anleitungen/jahresabrechnung.md' => "remainders under $n days"] as $rel => $claim) {
            self::assertStringContainsString($claim, preg_replace('/\s+/u', ' ', (string)file_get_contents("$root/$rel")), $rel);
        }
    }

    public function testTheExpectedBillsFollowTheSamePeriods(): void
    {
        $expected = new BillExpectedService($this->settings, $this->meters, $this->contracts, $this->readings,
            new PeriodService($this->store, $this->meters, $this->i18n), $this->bills());
        // Schlussrechnung des zweiten Versorgers, Stichtag 31-08: nicht 31.08.–31.08., sondern das ganze Vertragsjahr
        $this->settings->set(['billing_cycle_anchor_strom' => '08-31']);
        $final = array_values(array_filter($expected->expected('2025-10-20'), fn($x) => $x['final']))[0] ?? self::fail('keine Schlussrechnung erwartet');
        self::assertSame(['2024-09-01', '2025-08-31'], [$final['from'], $final['until']]);
        $p = array_values(array_filter($this->forMeter('2025-10-20')['periods'], fn($x) => $x['until'] === '2025-08-31'))[0];
        self::assertSame([$p['from'], $p['until']], [$final['from'], $final['until']], 'dieselbe Periode wie im Abrechnungsjahr');
        // Stichtag 05.09.: der laufende Vertrag begann vier Tage davor — die Jahresrechnung ab Vertragsbeginn
        $this->settings->set(['billing_cycle_anchor_strom' => '09-05']);
        $annual = array_values(array_filter($expected->expected('2026-10-20'), fn($x) => !$x['final']))[0] ?? self::fail('keine Jahresrechnung erwartet');
        self::assertSame(['2025-09-01', '2026-09-04'], [$annual['from'], $annual['until']]);
    }
}
