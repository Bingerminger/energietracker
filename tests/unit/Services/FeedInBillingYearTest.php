<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\ConsumptionService;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * v3.12.0 — PV-Einspeisung: Der Saldo der Karte rechnet je Abrechnungsjahr (Stichtag der Einspeisung) wie ein
 * Strom- oder Gasvertrag ohne erfasste Rechnung. Bis v3.11 lief er seit Inbetriebnahme; die Karte zeigte die
 * Vergütung mehrerer Jahre als „Guthaben beim Netzbetreiber“.
 *
 * Erfundener Haushalt: Einspeisung 150 kWh je Monat seit 01.04.2024, Stände am Monatsersten bis 01.10.2026,
 * Vergütung 8,0 ct/kWh, unbefristet; heute 15.10.2026. Von Hand:
 *   Stichtag 01.01.: Abrechnungsjahr 01.01.–31.12.2026, gemessen bis 01.10.: 9 Monate × 150 kWh = 1.350 kWh
 *   × 0,08 € = 108,00 € (bis v3.11 seit 01.04.2024: 30 Monate × 12 € = 360,00 €)
 *   Stichtag 01.07.: Abrechnungsjahr 01.07.2026–30.06.2027, gemessen 3 Monate × 12 € = 36,00 €
 */
#[CoversClass(ConsumptionService::class)]
final class FeedInBillingYearTest extends ServiceTestCase
{
    private const TODAY = '2026-10-15';
    private string $meterId = '';

    protected function setUp(): void
    {
        parent::setUp();
        $meter = $this->meters->create('pv_einspeisung', ['name' => 'Einspeisung', 'installed_on' => '2024-04-01', 'initial_counter' => 0.0]);
        $this->meterId = (string)$meter['id'];
        $dev = $meter['devices'][0]['id'];
        $rows = []; $counter = 0.0;
        for ($d = '2024-04-01'; $d <= '2026-10-01'; $d = date('Y-m-d', (int)strtotime("$d +1 month"))) {
            $rows[] = ['date' => $d, 'counter' => $counter, 'device_id' => $dev];
            $counter += 150.0;
        }
        $this->setReadings('pv_einspeisung', $this->meterId, $rows);
        $this->contracts->create('pv_einspeisung', ['meter_id' => $this->meterId, 'provider' => 'Netz Musterstadt', 'tariff_name' => 'Einspeisevergütung',
            'start' => '2024-04-01', 'working_prices' => [['from' => '2024-04-01', 'ct_per_kwh' => 8.0]]]);
    }

    /** @return array<string,mixed> */
    private function row(): array
    {
        return $this->consumption->contractStatus('pv_einspeisung', $this->meters->get('pv_einspeisung', $this->meterId), self::TODAY)['contracts'][0];
    }

    public function testTheBalanceRunsOverTheBillingYear(): void
    {
        $r = $this->row();
        self::assertSame('2026-01-01', $r['balance_from'], 'nicht seit Inbetriebnahme');
        self::assertSame(['2026-01-01', '2026-12-31', 'anchor'], [$r['billing_period']['from'], $r['billing_period']['until'], $r['billing_period']['source']]);
        self::assertSame(108.0, (float)$r['current_balance'], 'von Hand: 1.350 kWh × 0,08 €');
        self::assertEqualsWithDelta(1350.0, (float)$r['actual_kwh'], 0.05);
        self::assertSame('2026-12-31', $r['settle_on']);
    }

    public function testTheAnchorOfTheFeedInSetsTheYear(): void
    {
        $this->settings->set(['billing_cycle_anchor_pv_einspeisung' => '07-01']);
        $r = $this->row();
        self::assertSame('2026-07-01', $r['balance_from']);
        self::assertSame('2027-06-30', $r['settle_on']);
        self::assertSame(36.0, (float)$r['current_balance'], 'von Hand: 3 × 150 kWh × 0,08 €');
    }

    public function testAPositiveBalanceStaysAPayout(): void
    {
        self::assertSame('payout', $this->row()['verdict']);
    }

    /** Doku-Beispiel (PV → Saldo je Abrechnungsjahr): derselbe Haushalt, dieselbe Zahl. */
    public function testTheDocumentedExampleHolds(): void
    {
        $v = (float)$this->row()['current_balance'];
        $root = dirname(__DIR__, 3);
        foreach (['docs/verstehen/12-pv.md' => number_format($v, 2, ',', '.') . ' €', 'docs/en/verstehen/12-pv.md' => '€' . number_format($v, 2, '.', ',')] as $file => $want) {
            $text = (string)file_get_contents("$root/$file");
            $pos = strpos($text, '<!-- doc-claim: feed-in-billing-year');
            self::assertNotFalse($pos, "$file: Beispiel fehlt");
            self::assertStringContainsString($want, substr($text, $pos, 700), "$file: $want");
        }
    }
}
