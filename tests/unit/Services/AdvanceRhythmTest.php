<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\BackupService;
use Energietracker\Services\ContractService;
use Energietracker\Services\ForecastService;
use Energietracker\Support\LocalizedException;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * v3.9.0 (F1034) — Abschlag mit Rhythmus: alle zwei oder drei Monate bzw. an
 * genannten Terminen, für jede Verbrauchsart. Der Betrag gilt je Termin und
 * wird nirgends still in einen Monatsbetrag umgedeutet: Saldo nach Kalender,
 * Abschlagsvorschlag und Prognose zählen die Raten an ihrem Tag. Monatliche
 * Abschläge bleiben genau wie bisher (nichts wird gespeichert).
 *
 * Alle Werte mit der Hand gerechnet, im Kommentar daneben.
 */
#[CoversClass(ContractService::class)]
final class AdvanceRhythmTest extends ServiceTestCase
{
    private string $meterId = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->meterId = $this->setMeterDevices('strom', [['id' => 'd_s1', 'serial' => null, 'installed_on' => '2025-01-01',
            'initial_counter' => 0.0, 'removed_on' => null, 'final_counter' => null, 'reason' => null]]);
        $this->settings->set(['billing_cycle_anchor_strom' => '01-01']);
        // 100 kWh je Monat, abgelesen am Monatsersten bis 01.08.2026
        $rows = [];
        for ($i = 0; $i <= 19; $i++) {
            $rows[] = ['date' => date('Y-m-d', (int)strtotime("2025-01-01 +$i months")), 'counter' => 100.0 * $i, 'device_id' => 'd_s1'];
        }
        $this->setReadings('strom', $this->meterId, $rows);
    }

    /** @param array<string,mixed> $advance */
    private function contract(array $advance): array
    {
        return $this->contracts->create('strom', ['meter_id' => $this->meterId, 'provider' => 'Stadtwerke Musterstadt', 'start' => '2026-01-01',
            'working_prices' => [['from' => '2026-01-01', 'ct_per_kwh' => 30.0]],
            'base_prices' => [['from' => '2026-01-01', 'eur_per_month' => 10.0]],
            'advance_payments' => [['from' => '2026-01-01'] + $advance]]);
    }

    private function balanceRow(): array
    {
        return $this->consumption->contractStatus('strom', $this->meters->get('strom', $this->meterId), '2026-07-31')['contracts'][0];
    }

    public function testMonthlyStaysExactlyAsBefore(): void
    {
        $c = $this->contract(['amount_eur' => 50, 'every_months' => 1]);
        self::assertSame([['from' => '2026-01-01', 'amount_eur' => 50.0]], $c['advance_payments'], 'monatlich: kein neues Feld');
        $a = $this->balanceRow();
        $this->contracts->update('strom', $c['id'], ['advance_payments' => [['from' => '2026-01-01', 'amount_eur' => 50]]]);
        self::assertSame($a, $this->balanceRow(), 'derselbe Saldo wie ohne Rhythmus');
        self::assertArrayNotHasKey('next_advance_dates', $a);
        self::assertArrayNotHasKey('current_advance_every_months', $a);
    }

    public function testAnUnknownRhythmOrADateBeforeTheStartIsAnError(): void
    {
        foreach ([[['every_months' => 4], 'errors.contract.advanceEveryInvalid'], [['every_months' => 1.5], 'errors.contract.advanceEveryInvalid'],
                  [['due_dates' => ['2025-12-15']], 'errors.contract.advanceDueBeforeStart']] as [$extra, $key]) {
            try {
                $this->contract(['amount_eur' => 150] + $extra);
                self::fail('angenommen: ' . json_encode($extra));
            } catch (LocalizedException $e) {
                self::assertSame($key, $e->key);
            }
        }
        self::assertSame([], $this->contracts->list('strom', $this->meterId), 'kein stiller Rückfall auf monatlich');
    }

    public function testRegularDueDatesGiveTheRhythmIrregularOnesStayDates(): void
    {
        $c = $this->contract(['amount_eur' => 165, 'due_dates' => ['2026-05-15', '2026-03-15', '2026-07-15', '2026-03-15']]);
        self::assertSame(['from' => '2026-01-01', 'amount_eur' => 165.0, 'every_months' => 2, 'due_dates' => ['2026-03-15', '2026-05-15', '2026-07-15']],
            $c['advance_payments'][0], 'sortiert, ohne Doppel, alle 2 Monate abgeleitet');
        self::assertNull(ContractService::rhythmOf(['2026-01-15', '2026-02-15', '2026-04-15']), 'ungleiche Abstände: nur Termine');
        self::assertSame(1, ContractService::rhythmOf(['2026-01-31', '2026-02-28', '2026-03-31']), 'Monatsende geklemmt');
    }

    public function testInstallmentsFollowTheRhythmAndRepeatTheListedDatesYearly(): void
    {
        // alle drei Monate ab 31.01.: am Monatsende geklemmt, vom Beginn aus gezählt (nicht 30.07.)
        $q = [['from' => '2026-01-31', 'amount_eur' => 150.0, 'every_months' => 3]];
        self::assertSame(['2026-01-31', '2026-04-30', '2026-07-31', '2026-10-31'],
            array_column(ContractService::installments($q, '2026-01-01', '2027-01-01'), 'date'));
        // fünf Termine laut Rechnung, danach jährlich zu denselben Tagen — die sechste Rate (März) ist die nächste Rechnung
        $d = [['from' => '2026-05-15', 'amount_eur' => 165.0, 'every_months' => 2,
               'due_dates' => ['2026-05-15', '2026-07-15', '2026-09-15', '2026-11-15', '2027-01-15']]];
        self::assertSame(['2026-05-15', '2026-07-15', '2026-09-15', '2026-11-15', '2027-01-15', '2027-05-15', '2027-07-15'],
            array_column(ContractService::installments($d, '2026-01-01', '2027-08-01'), 'date'));
        // eine neue Zeile beendet den Rhythmus der alten; monatlich zählt dort wie bisher
        $mixed = [['from' => '2026-01-01', 'amount_eur' => 150.0, 'every_months' => 3], ['from' => '2026-06-01', 'amount_eur' => 60.0]];
        self::assertSame(['2026-01-01', '2026-04-01'], array_column(ContractService::installments($mixed, '2026-01-01', '2027-01-01'), 'date'));
        self::assertEqualsWithDelta(60.0, ContractService::advanceIn($mixed, '2026-07-01', '2026-07-01', '2026-08-01', 1.0, 31), 1e-9);
        self::assertEqualsWithDelta(0.0, ContractService::advanceIn($mixed, '2026-05-01', '2026-05-01', '2026-06-01', 1.0, 31), 1e-9, 'Mai: keine Rate');
        self::assertEqualsWithDelta(150.0, ContractService::advanceIn($mixed, '2026-04-01', '2026-04-01', '2026-05-01', 1.0, 30), 1e-9, 'April: die ganze Rate');
    }

    public function testTheBalanceCountsTheQuarterlyRatesOnTheirDays(): void
    {
        $this->contract(['amount_eur' => 150, 'every_months' => 3]);
        $s = $this->balanceRow();
        // Raten am 01.01., 01.04. und 01.07. sind bis 31.07. bezahlt: 3 × 150; offen bis 31.12. nur die vom 01.10.
        self::assertEqualsWithDelta(450.0, (float)$s['advance_paid'], 0.001);
        self::assertEqualsWithDelta(150.0, (float)$s['advance_remaining'], 0.001);
        self::assertSame(3, $s['current_advance_every_months']);
        self::assertSame(['2026-10-01', '2027-01-01', '2027-04-01', '2027-07-01'], $s['next_advance_dates']);
        // Kosten bis 31.07.: 7 Monate × (100 kWh × 0,30 € + 10 €) = 280 €; Saldo 280 − 450 = −170 (Guthaben)
        self::assertEqualsWithDelta(280.0, (float)$s['cost_to_date'], 0.5);
        self::assertEqualsWithDelta(-170.0, (float)$s['current_balance'], 0.5);
        // Vorschlag je Rate: der Rest verteilt sich auf die eine Rate, die bis zur Abrechnung noch kommt
        if ($s['suggested_advance'] !== null) {
            self::assertEqualsWithDelta(max(0.0, round(150 + (float)$s['projected_end_balance'])), (float)$s['suggested_advance'], 0.001);
        }
        // Endsaldo: 12 × 40 € = 480 € Kosten gegen 4 × 150 € = 600 € → Guthaben 120 €
        self::assertEqualsWithDelta(-120.0, (float)$s['projected_end_balance'], 1.0);
    }

    public function testTheForecastPutsEachRateIntoItsMonth(): void
    {
        $this->contract(['amount_eur' => 150, 'every_months' => 3]);
        $f = (new ForecastService($this->consumption, $this->regression, $this->settings, $this->contracts, $this->i18n))
            ->forMeter('strom', $this->meters->get('strom', $this->meterId), ['forecast_months' => 12]);
        self::assertNotEmpty($f['forecast'] ?? [], json_encode($f));
        foreach ($f['forecast'] as $row) {
            $due = in_array($row['month'], [1, 4, 7, 10], true);
            self::assertEqualsWithDelta($due ? 150.0 : 0.0, (float)$row['advance_estimated'], 0.001, $row['ym'] . ': die Rate in ihrem Monat, sonst nichts');
        }
    }

    /** Neue Felder überstehen das Backup (BackupService kopiert die Verträge als Ganzes). */
    public function testRhythmAndWaterBasePricesSurviveTheBackup(): void
    {
        $this->contract(['amount_eur' => 165, 'every_months' => 2, 'due_dates' => ['2026-03-15', '2026-05-15']]);
        $w = $this->contracts->create('wasser', ['meter_id' => $this->meters->defaultId('wasser'), 'provider' => 'Stadtwerke Musterstadt', 'start' => '2026-01-01',
            'trinkwasser' => ['working_prices' => [['from' => '2026-01-01', 'ct_per_m3' => 224.7]], 'base_prices' => [['from' => '2026-01-01', 'eur_per_month' => 13.375]]],
            'schmutzwasser' => ['basis' => 'trinkwasser', 'working_prices' => [['from' => '2026-01-01', 'ct_per_m3' => 214.2]],
                'base_prices' => [['from' => '2026-01-01', 'eur_per_month' => 7.9333]]],
            'advance_payments' => [['from' => '2026-01-01', 'amount_eur' => 300, 'every_months' => 3]]]);
        $before = [$this->contracts->list('strom'), $this->contracts->list('wasser')];
        $backups = new BackupService($this->store, $this->i18n);
        $dump = $backups->export();
        $this->store->write('strom/contracts.json', []);
        $this->store->write('wasser/contracts.json', []);
        $backups->import($dump);
        $after = [$this->contracts->list('strom'), $this->contracts->list('wasser')];
        self::assertEquals($before, $after);
        $wa = $this->contracts->get('wasser', $w['id']);
        self::assertEquals([['from' => '2026-01-01', 'eur_per_month' => 7.9333]], $wa['schmutzwasser']['base_prices'], 'Schmutzwasser-Grundpreis verloren');
        self::assertSame(3, $wa['advance_payments'][0]['every_months'], 'Rhythmus verloren');
        self::assertSame(['2026-03-15', '2026-05-15'], $after[0][0]['advance_payments'][0]['due_dates'], 'Termine verloren');
    }
}
