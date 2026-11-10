<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\BackupService;
use Energietracker\Services\BillingPeriodService;
use Energietracker\Services\BillService;
use Energietracker\Services\DemoService;
use Energietracker\Services\Rights\ContractChecks;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * v3.11.0 (Skeptiker N11) — das Schaufenster zeigt „Abschlag deutlich zu hoch“ (A2): Der laufende Gasvertrag
 * verlangt 130 € je Rate, die letzte abgeschlossene Abrechnungsperiode aus den Ablesungen ergibt 81,29 €.
 * Wie jede A2-Meldung schweigt sie kurz vor der Abrechnung (weniger als drei Raten bis zum Stichtag 01.01.,
 * Konzept §3 A2): am 03.11. also nicht, im übrigen Jahr ja. Der Skeptiker prüfte am 03.11.
 *
 * v3.12.0 — die Bemessung hängt am Stichtag, nicht am Kalender: Bis zum 31.12.2026 ist 2025 die letzte
 * abgeschlossene Periode (10.359 kWh × 8,2 ct + 12 × 10,50 € = 975,44 € ÷ 12 = 81,29 €), am 01.03.2027 ist
 * es 2026 (8.385 kWh × 8,2 ct + 126 € = 813,57 € ÷ 12 = 67,80 €). Bis v3.11 rechneten die Monatszeilen am
 * echten Datum: Vor dem 01.03.2027 fehlten ihnen die Ablesungen bis zum Stichtag, die Periode 2026 hatte
 * Lücken, und die Prüfung griff auf 2025 zurück (81,29 €); ab März 2027 kam 67,80 € heraus.
 */
#[CoversClass(ContractChecks::class)]
final class ShowcaseAdvanceHighTest extends ServiceTestCase
{
    /** @return array<string, array{0:string, 1:?float}> Stichtag, Bemessung je Rate (null: kein Befund) */
    public static function days(): array
    {
        return ['Juni' => ['2026-06-10', 81.29], 'März' => ['2027-03-01', 67.8], 'September' => ['2026-09-15', 81.29], 'November' => ['2026-11-03', null]];
    }

    #[DataProvider('days')]
    public function testTheShowcaseShowsAnAdvanceThatIsTooHigh(string $today, ?float $measure): void
    {
        $bills = new BillService($this->store, $this->meters, $this->contracts, $this->consumption, $this->i18n);
        $demo = new DemoService($this->store, new BackupService($this->store, $this->i18n), $this->i18n);
        $demo->attachBills($bills);
        $demo->import(true, $today, null);
        $this->settings->set(['country' => 'DE']);
        $cc = new ContractChecks($this->contracts, $this->meters, $this->consumption, $bills,
            new BillingPeriodService($this->settings, $this->meters, $this->readings, $this->consumption, $bills), $this->i18n);
        $f = array_map(fn($x) => [$x['utility'], $x['facts']['rate_eur'], $x['facts']['measure_eur']], $cc->advanceHigh($today, 'DE'));
        self::assertSame($measure !== null ? [['gas', 130.0, $measure]] : [], $f, $today);
    }
}
