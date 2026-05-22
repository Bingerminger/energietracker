<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Tests\Support\ServiceTestCase;
use Energietracker\Services\AnomalyService;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Issue #13 — Wechsel-Monate werden aus der z-Score-Erkennung
 * herausgefiltert. Ein Zählertausch ist ein erklärlicher Sondereffekt
 * (Bridging-Übergang), keine fachliche Anomalie.
 */
#[CoversClass(AnomalyService::class)]
final class AnomalyServiceTest extends ServiceTestCase
{
    /**
     * Baseline: ein einzelner extremer Ausreißer ohne `device_swap`-Flag
     * wird als Anomalie erkannt. Anker für den Vergleichstest weiter unten.
     */
    public function testHighOutlierWithoutSwapFlagIsReportedForNonHgtUtility(): void
    {
        $monthly = $this->seasonalBaseline('wasser', 7.0);
        // Einen einzelnen Monat massiv erhöhen — saisonale Mittelwerte sind ~7 m³/Monat,
        // dieser Punkt liegt bei 70 m³ → klare z-Score-Anomalie.
        $monthly[12]['m3'] = 70.0;

        $anomalies = $this->anomalies->detect('wasser', $monthly);

        self::assertNotEmpty($anomalies, 'Klare Anomalie ohne Tausch muss erkannt werden');
        $hit = array_filter($anomalies, fn($a) => $a['ym'] === $monthly[12]['ym']);
        self::assertNotEmpty($hit, 'Genau der manipulierte Monat muss in der Trefferliste sein');
    }

    /**
     * Derselbe Ausreißer mit `device_swap = true` darf NICHT als Anomalie
     * gemeldet werden — Wechsel-Monate sind Sondereffekte.
     */
    public function testSwapMonthIsExcludedFromAnomalyDetection(): void
    {
        $monthly = $this->seasonalBaseline('wasser', 7.0);
        $monthly[12]['m3'] = 70.0;
        $monthly[12]['device_swap'] = true;   // ⚠ Markierung aus markSwapMonths

        $anomalies = $this->anomalies->detect('wasser', $monthly);

        $hit = array_filter($anomalies, fn($a) => $a['ym'] === $monthly[12]['ym']);
        self::assertEmpty($hit,
            'Wechsel-Monat darf nicht in der Anomalie-Liste auftauchen');
    }

    /**
     * Feldnamen-Vertrag: AnomalyService liefert pro Treffer
     *   { ym, value, expected, deviation, z_score, percent, avg_temp, hdd, kind }.
     * Das Frontend liest exakt diese Namen (vgl. lessons #14).
     */
    public function testAnomalyShapeIsStable(): void
    {
        $monthly = $this->seasonalBaseline('wasser', 7.0);
        $monthly[12]['m3'] = 70.0;
        $anomalies = $this->anomalies->detect('wasser', $monthly);
        self::assertNotEmpty($anomalies);

        $a = $anomalies[0];
        foreach (['ym', 'value', 'expected', 'deviation', 'z_score', 'percent', 'avg_temp', 'hdd', 'kind'] as $f) {
            self::assertArrayHasKey($f, $a, "Feld $f muss in der Anomalie-Antwort vorhanden sein");
        }
        self::assertContains($a['kind'], ['high', 'low']);
    }

    /**
     * Baut eine Reihe von ~24 plausiblen Monatszeilen für `wasser`
     * (m³-Utility ohne HGT-Relevanz → AnomalyService nutzt den
     * saisonalen-Mittelwert-Pfad). Alle Werte ~$baseValue, `days` über
     * dem `min_days_period`-Default (20), `device_swap` aus.
     *
     * @return array<int,array<string,mixed>>
     */
    private function seasonalBaseline(string $utility, float $baseValue): array
    {
        $rows = [];
        for ($y = 2023; $y <= 2024; $y++) {
            for ($m = 1; $m <= 12; $m++) {
                $rows[] = [
                    'ym'          => sprintf('%04d-%02d', $y, $m),
                    'year'        => $y,
                    'month'       => $m,
                    'days'        => 30,
                    'm3'          => $baseValue + (($m % 3) - 1) * 0.5,   // leichte Streuung
                    'kwh'         => 0.0,
                    'avg_temp'    => 12.0,
                    'hdd'         => 0.0,
                    'device_swap' => false,
                ];
            }
        }
        return $rows;
    }
}
