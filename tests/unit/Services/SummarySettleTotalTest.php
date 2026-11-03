<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\AgendaService;
use Energietracker\Services\BenchmarkService;
use Energietracker\Services\DeliveryService;
use Energietracker\Services\ForecastService;
use Energietracker\Services\InstanceService;
use Energietracker\Services\PvSummaryService;
use Energietracker\Services\RecommendationService;
use Energietracker\Services\ReminderService;
use Energietracker\Services\StromSaldoService;
use Energietracker\Services\SummaryService;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * v3.10.0 (Nachtrag) — Home Assistant bekommt die Summe der Abrechnung. Rechnet eine Abrechnung
 * mehrere Verträge ab (Tarifwechsel beim selben Versorger), zeigt die Saldo-Karte die Summe
 * (`settle_balance`). In `GET /api/summary` bleibt `projected_end_balance` der Wert des Vertrags
 * (bestehende Automationen); neu ist `projected_end_balance_total`, immer gefüllt: die Summe,
 * ohne Summe derselbe Wert wie `projected_end_balance`.
 *
 * Erwartungen von Hand wie PeriodEndEstimateTest: 10 kWh am Tag, Abrechnung 01.01.–31.12.2025,
 * 12 € Grundpreis und 60 € Abschlag im Monat, heute 30.12.2025.
 */
#[CoversClass(SummaryService::class)]
final class SummarySettleTotalTest extends ServiceTestCase
{
    private const TODAY = '2025-12-30';
    private string $meterId = '';

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['meters', 'readings', 'contracts'] as $pot) $this->store->write("strom/$pot.json", []);
        $this->meterId = $this->setMeterDevices('strom', [['id' => 'd_a', 'serial' => null, 'installed_on' => '2024-01-01', 'initial_counter' => 0.0,
            'removed_on' => null, 'final_counter' => null, 'reason' => null]]);
        $at = fn(string $d): float => 10.0 * (int)round((strtotime($d . ' 12:00') - strtotime('2024-01-01 12:00')) / 86400);
        $rows = [];
        for ($t = strtotime('2024-01-01'); $t <= strtotime('2025-12-01'); $t = strtotime('+1 month', $t)) {
            $rows[] = ['date' => date('Y-m-d', $t), 'counter' => $at(date('Y-m-d', $t)), 'device_id' => 'd_a'];
        }
        $rows[] = ['date' => '2025-12-30', 'counter' => $at('2025-12-30'), 'device_id' => 'd_a'];
        $this->setReadings('strom', $this->meterId, $rows);
    }

    private function contract(string $start, ?string $end, float $ct, string $provider = 'Strom Musterstadt'): void
    {
        $this->contracts->create('strom', ['meter_id' => $this->meterId, 'provider' => $provider, 'start' => $start, 'end' => $end,
            'working_prices' => [['from' => $start, 'ct_per_kwh' => $ct]], 'base_prices' => [['from' => $start, 'eur_per_month' => 12.0]],
            'advance_payments' => [['from' => $start, 'amount_eur' => 60.0]]]);
    }

    /** @return array<string,mixed> Vertragsblock des Stromzählers aus /api/summary */
    private function summaryContract(): array
    {
        $reminders = new ReminderService($this->store, $this->settings, $this->i18n);
        $deliveries = new DeliveryService($this->store, $this->meters, $this->i18n);
        $recs = new RecommendationService($this->store, $this->meters, $this->consumption, $this->settings,
            new BenchmarkService($this->consumption, $this->meters, $this->settings, $this->i18n), $deliveries, $this->i18n);
        $agenda = new AgendaService($this->settings, $this->meters, $this->readings, $this->contracts,
            $this->consumption, $reminders, $recs, $this->i18n);
        $summary = new SummaryService($this->settings, $this->meters, $this->readings, $this->consumption,
            new ForecastService($this->consumption, $this->regression, $this->settings, $this->contracts, $this->i18n),
            $deliveries, new PvSummaryService($this->consumption), new StromSaldoService($this->consumption), $agenda,
            new InstanceService($this->store));
        $row = array_values(array_filter($summary->build('strom', null, self::TODAY)['meters'], fn($m) => $m['meter_id'] === $this->meterId))[0];
        self::assertIsArray($row['contract'], 'laufender Vertrag erwartet');
        return $row['contract'];
    }

    public function testATariffChangeWithTheSameSupplierGivesHomeAssistantTheSum(): void
    {
        // A 01.01.–30.06.2025 (10 ct), B ab 01.07.2025 (12 ct), derselbe Versorger: eine Rechnung über beide
        //   A: 181 Tage × 1,00 € + 6 × 12 € − 6 × 60 € = −107 €; B: 184 Tage × 1,20 € + 72 − 360 = −67,20 €
        $this->contract('2024-01-01', '2025-06-30', 10.0);
        $this->contract('2025-07-01', null, 12.0);
        $c = $this->summaryContract();
        self::assertEqualsWithDelta(-67.2, $c['projected_end_balance'], 0.005, 'der Wert des Vertrags bleibt (bestehende Automationen)');
        self::assertEqualsWithDelta(-174.2, $c['settle_balance'], 0.005);
        self::assertEqualsWithDelta(-174.2, $c['projected_end_balance_total'], 0.005, 'die Summe wie die Saldo-Karte');
    }

    public function testWithoutASumTheTotalIsTheContractValue(): void
    {
        // ein Vertrag: 365 × 1,00 € + 12 × 12 € − 12 × 60 € = −211 €
        $this->contract('2024-01-01', null, 10.0);
        $c = $this->summaryContract();
        self::assertNull($c['settle_balance']);
        self::assertEqualsWithDelta(-211.0, $c['projected_end_balance'], 0.005);
        self::assertSame($c['projected_end_balance'], $c['projected_end_balance_total'], 'immer gefüllt');
    }

    public function testAnotherSupplierKeepsItsOwnValue(): void
    {
        // Lieferantenwechsel: Schlussrechnung beim alten Versorger, keine gemeinsame Summe
        $this->contract('2024-01-01', '2025-06-30', 10.0);
        $this->contract('2025-07-01', null, 12.0, 'Anderer Versorger');
        $c = $this->summaryContract();
        self::assertNull($c['settle_balance']);
        self::assertEqualsWithDelta(-67.2, $c['projected_end_balance_total'], 0.005);
    }

    /** Die Doku nennt das Feld in beiden Sprachen (Anleitung und API-Referenz, Lektion 31). */
    public function testTheDocsNameTheField(): void
    {
        $root = dirname(__DIR__, 3);
        foreach (['docs/anleitungen/home-assistant.md', 'docs/en/anleitungen/home-assistant.md', 'docs/referenz/api.md', 'docs/en/referenz/api.md'] as $rel) {
            self::assertStringContainsString('projected_end_balance_total', (string)file_get_contents("$root/$rel"), $rel);
        }
    }
}
