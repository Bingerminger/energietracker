<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services\Mcp;

use PHPUnit\Framework\Attributes\DataProvider;

/**
 * v3.13.0 — KI `get_consumption` mit `billing_period` für die ganze Verbrauchsart: Die Herkunft der Zeile kommt aus den
 * Zielen. Endet ein Ziel vor seiner nächsten Ablesung (am Vertragsende verteilt), heißt die Zeile verteilt, nicht
 * gemessen, wie die Zeile dieses Ziels allein. Die Summe über die Zeilen trägt `parts` und ist nie stärker als ihre
 * schwächste Zeile. Bis dahin prüfte die Herkunft der Art-Zeile die Ablesungen am Ende der Periode (dem Ende des
 * spätesten Ziels) und hieß gemessen, obwohl ein Ziel an seinem Vertragsende verteilt war; die Summe hieß gemessen ohne
 * `parts`, auch über verteilte Zeilen.
 *
 * Erfunden: Strom, Stichtag 01.01., heute 15.10.2026. Zähler A (4 kWh je Tag) mit Vertrag 01.01.2025 bis 17.03.2026, ohne
 * Folgevertrag, Ablesung am Monatsersten bis 01.01.2026, dann die Übergabe am 23.03.2026. Zähler B (6 kWh je Tag) mit
 * Vertrag ab 01.01.2025, Ablesung am Monatsersten bis 01.10.2026. Im Abrechnungsjahr 2026 zählt A vom 01.01. bis 17.03.
 * (76 Tage × 4 = 304 kWh), verteilt zwischen den Ablesungen vom 01.01. und 23.03.; B gemessen bis 30.09.
 */
final class McpUtilityBillingBasisTest extends McpHouseholdTestCase
{
    private const STAND = '2026-10-15';

    /** @param list<string> $extraA zusätzliche Ablesetage von A nach dem 01.01.2026 */
    private function home(array $extraA): void
    {
        $this->meters('strom', ['m_a' => 'Wohnung alt', 'm_b' => 'Wohnung neu']);
        $stand = fn(string $d, float $perDay) => [$d, $perDay * (int)(new \DateTimeImmutable('2025-01-01'))->diff(new \DateTimeImmutable($d))->days];
        $a = []; $b = [];
        for ($d = new \DateTimeImmutable('2025-01-01'); $d->format('Y-m-d') <= '2026-10-01'; $d = $d->modify('first day of next month')) {
            if ($d->format('Y-m-d') <= '2026-01-01') $a[] = $stand($d->format('Y-m-d'), 4.0);
            $b[] = $stand($d->format('Y-m-d'), 6.0);
        }
        foreach ($extraA as $d) $a[] = $stand($d, 4.0);
        $this->counters('strom', ['m_a' => $a, 'm_b' => $b]);
        $this->contracts->create('strom', ['meter_id' => 'm_a', 'provider' => 'Stadtwerk Nord', 'start' => '2025-01-01', 'end' => '2026-03-17',
            'auto_renews' => false, 'working_prices' => [['from' => '2025-01-01', 'ct_per_kwh' => 30.0]],
            'base_prices' => [['from' => '2025-01-01', 'eur_per_month' => 10.0]], 'advance_payments' => [['from' => '2025-01-01', 'amount_eur' => 40.0]]]);
        $this->contracts->create('strom', ['meter_id' => 'm_b', 'provider' => 'Stadtwerk Nord', 'start' => '2025-01-01',
            'working_prices' => [['from' => '2025-01-01', 'ct_per_kwh' => 30.0]],
            'base_prices' => [['from' => '2025-01-01', 'eur_per_month' => 10.0]], 'advance_payments' => [['from' => '2025-01-01', 'amount_eur' => 60.0]]]);
    }

    /** @return array<string,mixed> */
    private function billing(?string $meter = null): array
    {
        return $this->tool('get_consumption', ['utility' => 'strom', 'granularity' => 'billing_period'] + ($meter !== null ? ['meter' => $meter] : []), self::STAND);
    }

    private static function year(array $out, string $year): array
    {
        foreach ($out['rows'] as $r) if (str_starts_with((string)$r['from'], $year)) return $r;
        self::fail("Abrechnungsjahr $year fehlt");
    }

    public function testAnEndDistributedTargetMakesTheUtilityRowDistributed(): void
    {
        $this->home(['2026-03-23']);
        $alone = self::year($this->billing('m_a'), '2026');
        self::assertSame('distributed', $alone['basis'], 'A allein: am Vertragsende verteilt');
        $art = $this->billing();
        $row = self::year($art, '2026');
        self::assertSame('distributed', $row['basis'], 'die Art: nicht gemessen, solange ein Ziel verteilt endet');
        self::assertGreaterThan(0.0, $row['measured_share'], 'B ist gemessen');
        self::assertLessThan(1.0, $row['measured_share']);
        self::assertSame('measured', self::year($art, '2025')['basis'], '2025 enden beide an einer Ablesung');
        // die Summe über die Zeilen: nie stärker als die schwächste Zeile, mit Anteilen
        $q = $art['total']['quantity'];
        self::assertSame('distributed', $q['basis'], 'Summe nicht gemessen');
        self::assertArrayHasKey('parts', $q, 'Summe mit Anteilen');
        self::assertGreaterThan(0.0, $q['parts']['distributed'] ?? 0.0);
        self::assertGreaterThan(0.0, $q['parts']['measured'] ?? 0.0);
        $cost = $art['total']['cost'];
        self::assertNotSame('entered', $cost['basis'], 'auch das Geld nicht stärker als die Zeilen');
    }

    /**
     * Gegenproben: Liegt bei A eine Ablesung am Tag nach dem Ende (18.03.2026) oder am letzten Vertragstag (17.03.2026,
     * Schlussablesung, zählt den Tag mit), endet A an einer Ablesung: Die Zeile 2026 der Art bleibt gemessen.
     *
     * @return array<string, array{list<string>}>
     */
    public static function endsAtAReading(): array
    {
        return [
            'Ablesung am Tag nach dem Ende' => [['2026-03-18', '2026-03-23']],
            'Schlussablesung am letzten Vertragstag' => [['2026-03-17']],
        ];
    }

    #[DataProvider('endsAtAReading')]
    public function testATargetEndingAtAReadingKeepsTheUtilityRowMeasured(array $extraA): void
    {
        $this->home($extraA);
        $row = self::year($this->billing(), '2026');
        self::assertSame('measured', $row['basis']);
        self::assertSame('measured', $this->billing()['total']['quantity']['basis'], 'Summe gemessen, wenn jede Zeile gemessen ist');
    }
}
