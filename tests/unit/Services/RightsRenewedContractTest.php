<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\BillingPeriodService;
use Energietracker\Services\BillService;
use Energietracker\Services\ContractService;
use Energietracker\Services\RightsService;
use Energietracker\Services\Rights\ContractChecks;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * v3.11.0 (Skeptiker H1) — ein Vertrag, der nach seinem Ende ohne Kündigung weiterläuft, zählt für A2, A3 und
 * die Prognose wie ein laufender. Bis dahin sahen A2 (findActiveForDate) und A3 (Preis-Einträge nur bis zum
 * Ende) ihn nicht, und die Prognose rechnete mit den Preisen seines letzten Tags.
 *
 * Erfundener Haushalt wie RightsAdvanceTest: Strom, 3.000 kWh im Jahr, gleichmäßig abgelesen; Vertrag
 * 01.01.2025 bis 31.12.2025 (Mindestlaufzeit, verlängert sich), Sondervertrag, 30 ct/kWh, 12 €/Monat,
 * Abschlag 110 €; Jahresrechnung 2025 über 3.000 kWh; Stichtag 10.06.2026, Abrechnung zum 31.12.
 *   A2: 3.000 × 0,30 + 12 × 12 = 1.044 € ÷ 12 = 87 € je Rate; 110 € liegen 26 % darüber → Befund.
 *   Erhöhung zum 01.08.2026 auf 34,50 ct und 13,50 €, Schreiben vom 05.06.2026 (57 Tage vorher):
 *   A3: Sonderkündigung zum 01.08.2026, Zugang bis 31.07.2026 (Termin), keine verspätete Ankündigung.
 *   Prognose: der laufend angemessene Abschlag nach der Abrechnung (12 Monate ab 01.01.2027) mit dem neuen
 *   Preis: (3.000 × 0,345 + 12 × 13,50) ÷ 12 = (1.035 + 162) ÷ 12 = 99,75 € statt 87 € mit dem alten.
 */
#[CoversClass(ContractChecks::class)]
#[CoversClass(ContractService::class)]
final class RightsRenewedContractTest extends ServiceTestCase
{
    private const TODAY = '2026-06-10';
    private string $meterId = '';
    private RightsService $rights;
    private ContractChecks $checks;

    protected function setUp(): void
    {
        parent::setUp();
        $this->settings->set(['billing_cycle_anchor_strom' => '01-01', 'country' => 'DE']);
        $this->meterId = $this->setMeterDevices('strom', [['id' => 'd_r', 'serial' => null, 'installed_on' => '2024-12-01',
            'initial_counter' => 0.0, 'removed_on' => null, 'final_counter' => null, 'reason' => null]]);
        $rows = [];
        for ($d = '2024-12-01'; $d <= self::TODAY; $d = date('Y-m-d', (int)strtotime("$d +1 month"))) $rows[] = $d;
        $rows[] = self::TODAY;
        $this->setReadings('strom', $this->meterId, array_map(fn($d) => ['date' => $d, 'device_id' => 'd_r',
            'counter' => round(3000.0 / 365 * (strtotime($d) - strtotime('2024-12-01')) / 86400, 3)], array_values(array_unique($rows))));
        $bills = new BillService($this->store, $this->meters, $this->contracts, $this->consumption, $this->i18n);
        $bills->create('strom', ['meter_id' => $this->meterId, 'kind' => 'annual', 'period_from' => '2025-01-01', 'period_to' => '2025-12-31',
            'issued_on' => '2026-02-20', 'invoice' => ['energy_kwh' => 3000.0, 'amount_eur' => 1044.0]]);
        $periods = new BillingPeriodService($this->settings, $this->meters, $this->readings, $this->consumption, $bills);
        $this->checks = new ContractChecks($this->contracts, $this->meters, $this->consumption, $bills, $periods, $this->i18n);
        $this->rights = new RightsService($this->settings, $this->i18n);
        $this->rights->register('advance_high', fn(string $t, string $c): array => $this->checks->advanceHigh($t, $c));
        $this->rights->register('special_termination', fn(string $t, string $c): array => $this->checks->specialTermination($t, $c));
    }

    private function contract(array $more = []): array
    {
        return $this->contracts->create('strom', $more + ['meter_id' => $this->meterId, 'provider' => 'Stadtwerke Musterstadt', 'supply_kind' => 'special',
            'start' => '2025-01-01', 'end' => '2025-12-31',
            'working_prices' => [['from' => '2025-01-01', 'ct_per_kwh' => 30.0]], 'base_prices' => [['from' => '2025-01-01', 'eur_per_month' => 12.0]],
            'advance_payments' => [['from' => '2025-01-01', 'amount_eur' => 110.0]]]);
    }

    private function statusRow(string $id): array
    {
        foreach ($this->consumption->contractStatus('strom', $this->meters->get('strom', $this->meterId), self::TODAY)['contracts'] as $r) {
            if ($r['contract_id'] === $id) return $r;
        }
        self::fail("kein Vertragsstatus für $id");
    }

    public function testA2SeesTheRenewedContract(): void
    {
        $c = $this->contract();
        self::assertSame('renewed', $this->contracts->phase($c, $this->contracts->list('strom'), self::TODAY));
        $f = $this->rights->run('advance_high', self::TODAY);
        self::assertCount(1, $f, 'bis v3.10: kein Befund (findActiveForDate kennt keine Verlängerung)');
        self::assertSame([(string)$c['id'], 87.0, 110.0, 26], [$f[0]['contract_id'], $f[0]['facts']['assessment_eur'], $f[0]['facts']['rate_eur'], $f[0]['facts']['over_pct']]);
        self::assertSame('rights.advance_high.text', $f[0]['text_key'], 'am Fälligkeitstag (01.07.) gelten noch die Preise von heute');
    }

    public function testA3AndTheForecastSeeAnIncreaseAfterTheEnd(): void
    {
        $before = $this->statusRow((string)$this->contract()['id']);
        self::assertTrue($before['renewed']);
        self::assertNull($before['price_increase']);
        self::assertEqualsWithDelta(87.0, (float)$before['advance_ongoing'], 1.0, 'ohne Erhöhung: 87 €');
        $this->store->write('strom/contracts.json', []);

        $c = $this->contract([
            'working_prices' => [['from' => '2025-01-01', 'ct_per_kwh' => 30.0], ['from' => '2026-08-01', 'ct_per_kwh' => 34.5, 'announced_on' => '2026-06-05']],
            'base_prices' => [['from' => '2025-01-01', 'eur_per_month' => 12.0], ['from' => '2026-08-01', 'eur_per_month' => 13.5, 'announced_on' => '2026-06-05']],
        ]);
        $f = $this->rights->run('special_termination', self::TODAY);
        self::assertCount(1, $f, 'bis v3.10: kein Befund (Einträge nach dem Ende fielen weg)');
        self::assertSame([(string)$c['id'], '2026-08-01', '2026-07-31', 'warning', false],
            [$f[0]['contract_id'], $f[0]['facts']['valid_from'], $f[0]['deadline'], $f[0]['severity'], $f[0]['facts']['announced_late']]);
        self::assertSame('Strom: Sonderkündigung zum 01.08.2026 möglich', $f[0]['title']);
        // genau ein Termin: dieselbe Antwort, die der Posteingang bekommt
        self::assertTrue($this->checks->coversPriceChange('strom', $c, '2026-08-01', self::TODAY, 'DE'));
        self::assertFalse($this->checks->coversPriceChange('strom', $c, '2026-09-01', self::TODAY, 'DE'), 'eine andere Änderung');

        $s = $this->statusRow((string)$c['id']);
        self::assertSame(['2026-08-01', 'special'], [$s['price_increase']['from'] ?? null, $s['price_increase']['termination'] ?? null]);
        // Prognose mit dem neuen Preis: (1.035 + 162) ÷ 12 = 99,75 €
        self::assertEqualsWithDelta(99.75, (float)$s['advance_ongoing'], 1.0);
        self::assertGreaterThan((float)$before['projected_end_balance'], (float)$s['projected_end_balance'], 'die Erhöhung kostet bis zur Abrechnung');
        // die Vertragskarte zeigt die Werte von heute (verlängert), nicht die des letzten Tags
        $card = array_values(array_filter($this->contracts->withPhase($this->contracts->list('strom'), $this->contracts->list('strom'), '2026-09-01'),
            fn($x) => $x['id'] === $c['id']))[0];
        self::assertSame(['renewed', 34.5, 13.5], [$card['phase'], $card['values_on']['working_price_ct'], $card['values_on']['base_price_eur']]);
    }

    /**
     * Nur der Grundpreis steigt nach dem Ende (12 → 13,50 € ab 01.08.2026), dazu ein neuer Abschlag (110 → 120 €
     * ab 01.08.2026): bis zur Abrechnung am 31.12. fünf Monate. Erwarteter Saldo von Hand: + 5 × 1,50 € Grundpreis
     * − 5 × 10 € Abschlag = − 42,50 € gegenüber ohne Änderung. Bis v3.10 galten beide Einträge nicht (Preise des
     * letzten Vertragstags, ContractService::priceDate).
     */
    public function testBasePriceAndAdvanceAfterTheEndCountInTheBalance(): void
    {
        $plain = (float)$this->statusRow((string)$this->contract()['id'])['projected_end_balance'];
        $this->store->write('strom/contracts.json', []);
        $c = $this->contract([
            'base_prices' => [['from' => '2025-01-01', 'eur_per_month' => 12.0], ['from' => '2026-08-01', 'eur_per_month' => 13.5]],
            'advance_payments' => [['from' => '2025-01-01', 'amount_eur' => 110.0], ['from' => '2026-08-01', 'amount_eur' => 120.0]],
        ]);
        self::assertEqualsWithDelta($plain - 42.5, (float)$this->statusRow((string)$c['id'])['projected_end_balance'], 0.02);
    }

    /** Mit Folgevertrag vor dem Tag der Änderung (01.07.2026) ist die Frage beantwortet: kein Befund. */
    public function testASuccessorBeforeTheChangeEndsTheRenewal(): void
    {
        $this->contract(['working_prices' => [['from' => '2025-01-01', 'ct_per_kwh' => 30.0], ['from' => '2026-08-01', 'ct_per_kwh' => 34.5, 'announced_on' => '2026-06-05']]]);
        $this->contracts->create('strom', ['meter_id' => $this->meterId, 'provider' => 'Neuwerk', 'start' => '2026-07-01',
            'working_prices' => [['from' => '2026-07-01', 'ct_per_kwh' => 28.0]]]);
        self::assertSame([], $this->rights->run('special_termination', self::TODAY));
    }
}
