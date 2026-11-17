<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\ForecastService;
use Energietracker\Services\SummaryService;
use Energietracker\Tests\Services\Mcp\McpHouseholdTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * v3.13.0 — Prognose einer HT/NT-Gruppe, deren Zählwerke an verschiedenen Tagen zuletzt abgelesen sind: Monat für Monat
 * HT + NT, auch in den letzten Monaten. Bis dahin reichte die Prognose des früher abgelesenen Zählwerks nur zwölf Monate
 * ab seinem eigenen letzten Stand; die Monate danach rechnete die Gruppe selbst, aus ihren eigenen Monatszeilen.
 *
 * Erfunden, von Hand: Stände an jedem Monatsersten ab 01.01.2025. HT 200 kWh je Monat im Jahr 2025, 240 kWh je Monat im
 * Jahr 2026; NT immer 90 kWh. Ohne Wetterbezug ist die Prognose eines Kalendermonats das Mittel seiner Jahre: HT 220 kWh,
 * wo es den Monat 2025 und 2026 gibt, sonst 200 kWh; NT 90 kWh. Gruppenvertrag ab 01.01.2025: HT 33 ct, NT 24 ct,
 * Grundpreis 8 €/Monat.
 */
#[CoversClass(ForecastService::class)]
final class HtNtGroupEarlierReadTest extends McpHouseholdTestCase
{
    /** Stände am Ersten von 2025-01 bis `$last` (JJJJ-MM-TT, ein Monatserster), Verbrauch je Monat nach Jahr. */
    private static function rising(string $last, float $per2025, float $per2026): array
    {
        $out = []; $c = 0.0;
        for ($d = new \DateTimeImmutable('2025-01-01'); $d->format('Y-m-d') <= $last; $d = $d->modify('+1 month')) {
            $out[] = [$d->format('Y-m-d'), $c];
            $c += $d->format('Y') === '2025' ? $per2025 : $per2026;
        }
        return $out;
    }

    private function group(string $htLast, string $ntLast, bool $perRegister = true): string
    {
        $gid = (string)$this->meters->createGroup('strom', ['name' => 'Doppeltarif'])['id'];
        $this->meters('strom', ['m_ht' => 'HT', 'm_nt' => 'NT'], ['m_ht' => ['meter_group_id' => $gid], 'm_nt' => ['meter_group_id' => $gid]]);
        $this->counters('strom', ['m_ht' => self::rising($htLast, 200.0, 240.0), 'm_nt' => self::rising($ntLast, 90.0, 90.0)]);
        $this->contracts->create('strom', ['meter_group_id' => $gid, 'provider' => 'Stromwerk Musterstadt', 'start' => '2025-01-01',
            'working_prices' => [['from' => '2025-01-01', 'ct_per_kwh' => 33.0]],
            'base_prices' => [['from' => '2025-01-01', 'eur_per_month' => 8.0]], 'advance_payments' => [['from' => '2025-01-01', 'amount_eur' => 110.0]]]
            + ($perRegister ? ['working_prices_by_meter' => ['m_ht' => [['from' => '2025-01-01', 'ct_per_kwh' => 33.0]], 'm_nt' => [['from' => '2025-01-01', 'ct_per_kwh' => 24.0]]]] : []));
        return $gid;
    }

    /** HT-Prognose eines Kalendermonats: 220 kWh, wenn HT ihn 2026 gemessen hat (vor dem Monat seines letzten Stands), sonst 200. */
    private static function ht(string $ym, string $htLast): float
    {
        return (int)substr($ym, 5, 2) < (int)substr($htLast, 5, 2) ? 220.0 : 200.0;
    }

    /**
     * @return array<string, array{string, string, string, float}> HT zuletzt, NT zuletzt, Stichtag, zwölf Monate von Hand
     *  - NT drei Monate früher: Oktober bis Dezember 200 + 90, Januar bis September 220 + 90 → 3.660 kWh
     *  - HT drei Monate früher: Oktober bis Dezember und Juli bis September 290, Januar bis Juni 310 → 3.600 kWh
     *  - NT zuletzt am Jahreswechsel (01.01.2026), HT bis 01.06.2026: Juni bis Dezember 290, Januar bis Mai 310 → 3.580 kWh
     */
    public static function spreads(): array
    {
        return [
            'NT drei Monate früher abgelesen' => ['2026-10-01', '2026-07-01', '2026-10-20', 3660.0],
            'HT drei Monate früher abgelesen' => ['2026-07-01', '2026-10-01', '2026-10-20', 3600.0],
            'NT zuletzt am Jahreswechsel'     => ['2026-06-01', '2026-01-01', '2026-06-10', 3580.0],
        ];
    }

    #[DataProvider('spreads')]
    public function testEveryMonthOfTheGroupIsPeakPlusOffPeak(string $htLast, string $ntLast, string $day, float $year): void
    {
        $gid = $this->group($htLast, $ntLast);
        $f = $this->consumption->atDate($day, fn() => $this->svc(ForecastService::class)->forMeter('strom', $this->meters->groupTarget('strom', $gid)));
        self::assertTrue((bool)$f['valid']);
        $rows = array_slice($f['forecast'], 0, 12);
        self::assertCount(12, $rows);
        foreach ($rows as $r) self::assertEqualsWithDelta(self::ht((string)$r['ym'], $htLast) + 90.0, (float)$r['kwh'], 0.2, "Monat {$r['ym']}: HT + NT");
        self::assertEqualsWithDelta($year, array_sum(array_column($rows, 'kwh')), 1.0, 'zwölf Monate von Hand');
        $htKwh = array_sum(array_map(fn($r) => self::ht((string)$r['ym'], $htLast), $rows));
        $cost = array_sum(array_map(fn($r) => (float)$r['cost_estimated'], $rows));
        self::assertEqualsWithDelta($htKwh * 0.33 + 12 * 90 * 0.24 + 96.0, $cost, 0.5, 'Arbeit je Zählwerk zu seinem Preis und Grundpreis');
        // /api/summary (Home Assistant): Gruppe − Zählwerke = Grundpreis
        $s = $this->svc(SummaryService::class)->build('strom', null, $day)['meters'];
        $by = array_column($s, null, 'meter_id');
        $g = $by['m_ht']['group_forecast_12m'];
        self::assertEqualsWithDelta($year, $g['value'], 1.0, 'Summary: Gruppe');
        self::assertEqualsWithDelta($by['m_ht']['forecast_12m']['value'] + $by['m_nt']['forecast_12m']['value'], $g['value'], 0.2, 'Summary: Gruppe = HT + NT');
        self::assertEqualsWithDelta(96.0, $g['cost'] - $by['m_ht']['forecast_12m']['cost'] - $by['m_nt']['forecast_12m']['cost'], 0.05, 'Summary: Gruppe − Zählwerke = Grundpreis');
    }

    /**
     * Rechnet die Gruppe selbst (Gruppenvertrag ohne Preise je Zählwerk), lernt sie einen Monat ohne NT (NT zuletzt am 01.07.,
     * Juli bis September 2026 nur HT) nicht mit, weil es denselben Kalendermonat aus 2025 vollständig gibt. Von Hand: Juli bis
     * Dezember 290 kWh (nur 2025), Januar bis Juni das Mittel von 290 und 330 kWh = 310 kWh. Bis dahin Juli bis September aus
     * dem Mittel von 290 und 240 kWh (265 kWh).
     */
    public function testAGroupThatCountsItselfLearnsOnlyFromCompleteMonths(): void
    {
        $gid = $this->group('2026-10-01', '2026-07-01', false);
        $f = $this->consumption->atDate('2026-10-20', fn() => $this->svc(ForecastService::class)->forMeter('strom', $this->meters->groupTarget('strom', $gid)));
        foreach (array_slice($f['forecast'], 0, 12) as $r) {
            self::assertEqualsWithDelta((int)substr((string)$r['ym'], 5, 2) <= 6 ? 310.0 : 290.0, (float)$r['kwh'], 0.5, "Monat {$r['ym']}");
        }
    }
}
