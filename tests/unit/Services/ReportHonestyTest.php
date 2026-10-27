<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\ReportAdviceService;
use Energietracker\Services\ReportModelService;
use Energietracker\Tests\Support\ReportFixture;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * v3.9.0 (Skeptiker-Lauf 3.9.0: M2, M3, N3, N11, N14, N17) — der Jahresbericht vergleicht nur,
 * was vergleichbar ist, und sagt, was angenommen ist. Erfundener Haushalt, monatlich am
 * Monatsersten abgelesen:
 *   Strom    2024 je 300 kWh, 2025 je 330 kWh, 30 ct/kWh, Grundpreis 10 € im Monat (beide Jahre)
 *   Fernwärme 2024 und 2025 je 1.000 kWh im Monat, Vertrag (10 ct/kWh) erst ab 01.01.2025
 *   2026: nur der Januar abgelesen (Strom 330 kWh, Fernwärme 1.000 kWh, Wasser 9 m³)
 */
#[CoversClass(ReportModelService::class)]
#[CoversClass(ReportAdviceService::class)]
final class ReportHonestyTest extends ServiceTestCase
{
    use ReportFixture;

    private const TODAY = '2026-02-15';

    private function meter(string $u, string $id): void
    {
        $this->store->write("$u/meters.json", [['id' => $id, 'name' => ucfirst($u), 'icon' => '', 'created_at' => '2024-01-01', 'active' => true,
            'notes' => '', 'parent_meter_id' => null, 'meter_group_id' => null, 'external_id' => null, 'baseline_events' => [],
            'devices' => [['id' => "d_$id", 'serial' => null, 'installed_on' => '2024-01-01', 'initial_counter' => 0.0, 'removed_on' => null,
                'final_counter' => null, 'reason' => null]]]]);
    }

    /** Monatliche Stände ab 01.01.2024; $perMonth(ym) = Menge des Monats; letzter Stand am $until. */
    private function readings(string $u, string $id, callable $perMonth, string $until): void
    {
        $rows = []; $c = 0.0;
        for ($t = strtotime('2024-01-01'); date('Y-m-d', $t) <= $until; $t = strtotime('+1 month', $t)) {
            $rows[] = ['date' => date('Y-m-d', $t), 'counter' => round($c, 3), 'device_id' => "d_$id"];
            $c += $perMonth(date('Y-m', $t));
        }
        $this->setReadings($u, $id, $rows);
    }

    private function household(bool $persons = true): void
    {
        foreach (['gas', 'strom', 'wasser', 'fernwaerme'] as $u) foreach (['meters', 'readings', 'contracts'] as $pot) $this->store->write("$u/$pot.json", []);
        $this->settings->set(['active_utilities' => ['strom', 'fernwaerme', 'wasser']] + ($persons ? ['wasser_personen_anzahl' => 3] : []));
        $this->meter('strom', 'm_s');
        $this->readings('strom', 'm_s', fn($ym) => $ym < '2025-01' ? 300.0 : 330.0, '2026-02-01');
        $this->contracts->create('strom', ['meter_id' => 'm_s', 'provider' => 'Stadtwerke Musterstadt', 'start' => '2024-01-01',
            'working_prices' => [['from' => '2024-01-01', 'ct_per_kwh' => 30.0]], 'base_prices' => [['from' => '2024-01-01', 'eur_per_month' => 10.0]]]);
        $this->meter('fernwaerme', 'm_f');
        $this->readings('fernwaerme', 'm_f', fn($ym) => 1000.0, '2026-02-01');
        $this->contracts->create('fernwaerme', ['meter_id' => 'm_f', 'provider' => 'Wärme Musterstadt', 'start' => '2025-01-01',
            'working_prices' => [['from' => '2025-01-01', 'ct_per_kwh' => 10.0]]]);
        $this->meter('wasser', 'm_w');
        $this->setReadings('wasser', 'm_w', [['date' => '2026-01-01', 'counter' => 100.0, 'device_id' => 'd_m_w'], ['date' => '2026-02-01', 'counter' => 109.0, 'device_id' => 'd_m_w']]);
    }

    private function report(int $year): array
    {
        return $this->reportServices()['model']->build($year, ['today' => self::TODAY]);
    }

    /** M3 — der Vergleich nur über Arten mit Kosten in beiden Jahren; die neue Art steht getrennt da. */
    public function testTheCostComparisonAddsUpAndNamesTheNewlyBilledUtility(): void
    {
        $this->household();
        $m = $this->report(2025);
        $tile = array_column($m['headline']['tiles'], null, 'key')['cost'];
        // 2025: Strom 12 × (330 × 0,30 + 10) = 1.308 € + Fernwärme 12 × 100 € = 1.200 € → 2.508 €
        self::assertEqualsWithDelta(2508.0, $tile['value'], 0.01);
        // verglichen nur Strom: 1.308 / 1.200 − 1 = +9,0 % (bis dahin 2.508 / 1.200 − 1 = +109 %)
        self::assertSame(9.0, $tile['pct']);
        self::assertStringContainsString('ohne Fernwärme (neu abgerechnet)', $tile['sub']);
        $light = array_column($m['headline']['lights'], null, 'key')['cost'];
        // Menge (3.960 − 3.600) × 1.200 / 3.600 = +120 €, Preis 108 − 120 = −12 €: zusammen die +108 € des Vergleichs
        $plain = fn(string $s): string => (string)preg_replace('/\h/u', ' ', $s);
        self::assertStringContainsString('Preise: -12 €, Menge: +120 €.', $plain($light['detail']));
        self::assertStringContainsString('Neu abgerechnet und nicht im Vergleich: Fernwärme 1.200 €.', $plain($light['detail']));
    }

    /** M2, N17 — ein erfasster Monat: kein CO₂-Vergleich, je Tag über die erfassten Tage, „bisher“ je m². */
    public function testAPartYearIsNotComparedAsAWholeYear(): void
    {
        $this->household();
        $this->settings->set(['wohnflaeche_m2' => 100]);
        $m = $this->report(2026);
        $tiles = array_column($m['headline']['tiles'], null, 'key');
        self::assertNull($tiles['co2']['pct'], 'bis dahin „CO₂ … −92 % gegenüber 2025“ aus einem Monat');
        $light = array_column($m['headline']['lights'], null, 'key')['cost'];
        self::assertSame($this->i18n->tp('report.light.yearIncomplete', 1, ['year' => 2026, 'count' => 1, 'prev' => 2025]), $light['detail'],
            'N17: das Berichtsjahr ist unvollständig, nicht das Vorjahr');
        $water = array_column($m['pages'], null, 'utility')['wasser']['water'];
        // 9 m³ für 3 Personen über 31 erfasste Tage: 9.000 / 3 / 31 = 96,8 → 97 Liter (bis dahin ÷ 365: 8)
        self::assertSame(97.0, $water['liters_per_person_day']);
        self::assertSame(31, $water['days']);
        self::assertFalse($water['persons_assumed']);
        $eff = array_column($m['pages'], null, 'utility')['fernwaerme']['efficiency'];
        self::assertTrue($eff['partial']);
        self::assertStringContainsString('bisher', $eff['text'], '1.000 kWh / 100 m² aus einem Monat ist kein Jahreswert');
        self::assertStringNotContainsString('·a', $eff['value_text']);
    }

    /** N14 — die Personenzahl ist nur die Voreinstellung (2): der Bericht sagt es. */
    public function testAssumedPersonsAreNamed(): void
    {
        $this->household(false);
        $water = array_column($this->report(2026)['pages'], null, 'utility')['wasser']['water'];
        self::assertTrue($water['persons_assumed']);
        self::assertSame(2, $water['persons']);
        self::assertStringContainsString($this->i18n->tp('report.page.personsAssumed', 2, ['count' => 2]), $water['text']);
    }

    /** N3 — Vorschläge aus „Zu tun“ ohne Datum sind keine Fristen und Termine. */
    public function testSuggestionsAreNotListedAsDeadlines(): void
    {
        $model = $this->reportServices()['model'];
        (new \ReflectionProperty($model, 'today'))->setValue($model, self::TODAY);
        $section = (new \ReflectionMethod($model, 'agendaSection'))->invoke($model, [
            ['date' => self::TODAY, 'kind' => 'heat_limit', 'title' => 'Heizgrenze anpassen', 'days' => 0, 'severity' => 'due'],
            ['date' => self::TODAY, 'kind' => 'reading_check', 'title' => 'Ablesung prüfen', 'days' => 0, 'severity' => 'due'],
            ['date' => self::TODAY, 'kind' => 'reading_duplicate', 'title' => 'Doppelt', 'days' => 0, 'severity' => 'due'],
            ['date' => self::TODAY, 'kind' => 'level_shift', 'title' => 'Zäsur?', 'days' => 0, 'severity' => 'due'],
            ['date' => '2026-03-01', 'kind' => 'reminder', 'title' => 'Wartung', 'days' => 14, 'severity' => 'upcoming'],
        ], []);
        self::assertSame(['Wartung'], array_column($section['events'], 'title'));
    }

    /** N11 — der Satz zur Wallbox nur mit Wallbox. */
    public function testTheWallboxSentenceNeedsAWallbox(): void
    {
        foreach (['strom'] as $u) foreach (['meters', 'readings', 'contracts'] as $pot) $this->store->write("$u/$pot.json", []);
        $dev = fn($id) => [['id' => "d_$id", 'serial' => null, 'installed_on' => '2024-01-01', 'initial_counter' => 0.0, 'removed_on' => null, 'final_counter' => null, 'reason' => null]];
        $this->store->write('strom/meters.json', [
            ['id' => 'm_s', 'name' => 'Haus', 'icon' => '', 'created_at' => '2024-01-01', 'active' => true, 'notes' => '', 'parent_meter_id' => null, 'devices' => $dev('m_s')],
            ['id' => 'm_hp', 'name' => 'Wärmepumpe', 'icon' => '', 'created_at' => '2024-01-01', 'active' => true, 'notes' => '', 'parent_meter_id' => 'm_s', 'role' => 'heat_pump', 'devices' => $dev('m_hp')],
        ]);
        // beide Zähler in einer Datei (setReadings schreibt die ganze Liste der Art)
        $rows = [];
        foreach (['m_s' => 400.0, 'm_hp' => 150.0] as $id => $per) {
            $c = 0.0;
            for ($t = strtotime('2024-01-01'); date('Y-m-d', $t) <= '2026-01-01'; $t = strtotime('+1 month', $t)) {
                $rows[] = ['id' => 'r_' . $id . '_' . date('Ym', $t), 'meter_id' => $id, 'device_id' => "d_$id", 'date' => date('Y-m-d', $t), 'counter' => $c,
                    'price_cents' => null, 'note' => '', 'is_estimated' => false, 'is_future' => false];
                $c += $per;
            }
        }
        $this->store->write('strom/readings.json', $rows);
        $model = $this->reportServices()['model'];
        (new \ReflectionProperty($model, 'year'))->setValue($model, 2025);
        $roles = (new \ReflectionMethod($model, 'stromRoles'))->invoke($model, ['eigenverbrauch_kwh' => 500.0]);
        // Haushalt 12 × 400 − 12 × 150 = 3.000 kWh + 500 kWh Sonne, Wärmepumpe 1.800 kWh: zusammen 5.300 kWh
        self::assertSame($this->i18n->t('report.page.rolesWithPv', ['total' => $this->i18n->number(5300.0, 0)]), $roles['text'],
            'Wärmepumpe und Sonnenstrom, keine Wallbox: kein Satz zur Wallbox');
    }
}
