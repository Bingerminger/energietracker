<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\Co2CostService;
use Energietracker\Services\Co2SplitService;
use Energietracker\Services\TenancyService;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * v3.12.0 — CO₂-Aufteilung bei eigener Etagenheizung im Teiljahr: Die Grenzen der Stufen werden anteilig gekürzt
 * (§ 5 Abs. 3 Satz 4 CO2KostAufG: „Absatz 1 Satz 4 und 5 gilt entsprechend“; Abs. 1: Ist ein Abrechnungszeitraum
 * von unter einem Jahr vereinbart, „so sind die Werte der Einstufungstabelle in der Anlage anteilig zu kürzen“).
 * Bis v3.11 kürzte die App nur bei der Zentralheizung; wer im Juli einzog, bekam bei der Etagenheizung die Stufe
 * eines ganzen Jahres.
 *
 * Erfundener Haushalt: Miete, Einzug 01.07.2025, 50 m², Gas 10 kWh/m³, CO₂-Preis 2025 55 €/t, 19 % USt.
 * Teiljahr 01.07.–31.12.2025 = 184 Tage, Grenzen × 184/365. Von Hand:
 *   3.060 kWh × 0,18139 kg/kWh = 555,05 kg; ÷ 50 m² = 11,1 kg/m²
 *   Grenze Stufe 3/4: 22 × 184/365 = 11,09 → 11,1 liegt darüber: Stufe 4, 30 % (ganzes Jahr: unter 12, Stufe 1, 0 %)
 *   CO₂-Kosten 0,55505 t × 55 €/t = 30,53 € netto × 1,19 = 36,33 €; Vermieter 36,33 € × 30 % = 10,90 €
 *   Am Rand: 3.030 kWh → 549,61 kg ÷ 50 = 11,0 kg/m² liegt unter 11,09: Stufe 3, 20 %
 */
#[CoversClass(Co2SplitService::class)]
#[CoversClass(Co2CostService::class)]
final class Co2PartialYearTest extends ServiceTestCase
{
    private TenancyService $ten;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ten = new TenancyService($this->store, $this->meters, $this->i18n);
        $this->settings->set(['wohnverhaeltnis' => 'miete', 'gas_conversion_factors' => [['from' => null, 'kwh_per_m3' => 10.0]]]);
    }

    private function costs(): Co2CostService
    {
        return new Co2CostService($this->store, $this->settings, $this->meters, $this->consumption, $this->contracts, $this->i18n, $this->ten);
    }

    private function household(float $m3, ?string $end = null): void
    {
        $this->setReadings('gas', (string)$this->meters->defaultId('gas'), [
            ['date' => '2025-07-01', 'counter' => 0],
            ['date' => '2026-01-01', 'counter' => $m3],
        ]);
        $this->ten->create(['start' => '2025-07-01', 'wohnflaeche_m2' => 50, 'billing_anchor' => '01-01'] + ($end !== null ? ['end' => $end] : []));
    }

    /** @return array<string,mixed> */
    private function split(): array
    {
        return (new Co2SplitService($this->costs(), $this->ten, $this->settings, $this->i18n))->forYear(2025);
    }

    public function testMovingInMidYearShortensTheStageBounds(): void
    {
        $this->household(306.0);
        $r = $this->split();
        self::assertSame('self_supplied', $r['case']);
        self::assertSame(11.1, $r['kg_per_m2']);
        self::assertSame(184, $r['days'], 'Teiljahr 01.07.–31.12.2025');
        self::assertSame(4, $r['stage'], 'von Hand: 11,1 ≥ 22 × 184/365 = 11,09');
        self::assertSame(30, $r['landlord_share_pct']);
        self::assertSame(36.33, $r['co2_cost_eur']);
        self::assertSame(10.9, $r['landlord_amount_eur'], 'von Hand: 36,33 € × 30 %');
    }

    public function testJustBelowTheShortenedBoundStaysOneStageLower(): void
    {
        $this->household(303.0);
        $r = $this->split();
        self::assertSame(11.0, $r['kg_per_m2']);
        self::assertSame(3, $r['stage'], 'von Hand: 11,0 < 11,09');
        self::assertSame(20, $r['landlord_share_pct']);
    }

    public function testTheKeyFigureOfTheCo2CostsUsesTheSameStage(): void
    {
        $this->household(306.0);
        $perM2 = $this->costs()->forYear(2025)['per_m2'];
        self::assertSame(11.1, $perM2['kg']);
        self::assertSame(4, $perM2['stage'], 'Kennzahl je m² und Aufteilung: eine Stufe');
        self::assertSame(30, $perM2['landlord_share_pct']);
        self::assertSame(184, $perM2['days']);
    }

    /** Doku-Beispiel (CO₂-Aufteilung → Teiljahr): dieselben Zahlen wie die App. */
    public function testTheDocumentedExampleHolds(): void
    {
        $this->household(306.0);
        $r = $this->split();
        self::assertSame([184, 11.1, 4, 30], [$r['days'], $r['kg_per_m2'], $r['stage'], $r['landlord_share_pct']]);
        $limit = round(22 * $r['days'] / 365, 2);
        $root = dirname(__DIR__, 3);
        foreach (['docs/anleitungen/co2-aufteilung.md' => ['184 Tage', '11,1 kg', number_format($limit, 2, ',', '') . ' kg', 'Stufe 4, 30 %'],
                  'docs/en/anleitungen/co2-aufteilung.md' => ['184 days', '11.1 kg', number_format($limit, 2, '.', '') . ' kg', 'stage 4, 30 %']] as $file => $want) {
            $text = (string)file_get_contents("$root/$file");
            $pos = strpos($text, '<!-- doc-claim: co2-part-year');
            self::assertNotFalse($pos, "$file: Beispiel fehlt");
            foreach ($want as $w) self::assertStringContainsString($w, substr($text, $pos, 800), "$file: $w");
        }
    }

    public function testAWholeYearKeepsTheTableAsItIs(): void
    {
        $this->setReadings('gas', (string)$this->meters->defaultId('gas'), [
            ['date' => '2025-01-01', 'counter' => 0],
            ['date' => '2026-01-01', 'counter' => 306.0],
        ]);
        $this->ten->create(['start' => '2024-03-01', 'wohnflaeche_m2' => 50, 'billing_anchor' => '01-01']);
        $r = $this->split();
        self::assertSame(365, $r['days']);
        self::assertSame(1, $r['stage'], 'ganzes Jahr: 11,1 < 12');
        self::assertSame(0.0, (float)$r['landlord_amount_eur']);
    }
}
