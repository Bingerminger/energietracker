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
 */
#[CoversClass(ContractChecks::class)]
final class ShowcaseAdvanceHighTest extends ServiceTestCase
{
    /** @return array<string, array{0:string, 1:bool}> */
    public static function days(): array
    {
        return ['Juni' => ['2026-06-10', true], 'März' => ['2027-03-01', true], 'September' => ['2026-09-15', true], 'November' => ['2026-11-03', false]];
    }

    #[DataProvider('days')]
    public function testTheShowcaseShowsAnAdvanceThatIsTooHigh(string $today, bool $shown): void
    {
        $bills = new BillService($this->store, $this->meters, $this->contracts, $this->consumption, $this->i18n);
        $demo = new DemoService($this->store, new BackupService($this->store, $this->i18n), $this->i18n);
        $demo->attachBills($bills);
        $demo->import(true, $today, null);
        $this->settings->set(['country' => 'DE']);
        $cc = new ContractChecks($this->contracts, $this->meters, $this->consumption, $bills,
            new BillingPeriodService($this->settings, $this->meters, $this->readings, $this->consumption, $bills), $this->i18n);
        $f = array_map(fn($x) => [$x['utility'], $x['facts']['rate_eur'], $x['facts']['measure_eur']], $cc->advanceHigh($today, 'DE'));
        self::assertSame($shown ? [['gas', 130.0, 81.29]] : [], $f, $today);
    }
}
