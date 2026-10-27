<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\AnomalyService;
use Energietracker\Services\BenchmarkService;
use Energietracker\Services\DeliveryService;
use Energietracker\Services\RecommendationService;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * v3.9.0 (Paket doku, Schaufenster) — Empfehlung „statistische Anomalie“ (R4) bei einer
 * Wärmepumpe mit HT- und NT-Zählwerk als Zählergruppe.
 *
 * Bis dahin stand „Strom Okt. 2025: statistische Anomalie“ zweimal mit gleichem Text da, einmal
 * je Zählwerk, und der Befund selbst war keiner: Die Wärmepumpe läuft seit April, der Oktober ist
 * ihr erster Heizmonat und wurde mit September und November verglichen (es gab keinen Oktober
 * eines früheren Jahres).
 *
 * Erfundener Haushalt, Strom, am Monatsersten abgelesen:
 *   - Hauszähler „Haus“ seit 01.01.2023, 8 kWh am Tag
 *   - Wärmepumpe HT und NT (Gruppe „Wärmepumpe“), je 2 kWh am Tag im Sommer (April bis September),
 *     je 12 kWh am Tag von Oktober bis März
 */
#[CoversClass(RecommendationService::class)]
#[CoversClass(AnomalyService::class)]
final class AnomalyRecommendationGroupTest extends ServiceTestCase
{
    /** @param callable(string):float $wp Tagesverbrauch je Zählwerk am Tag (ISO) */
    private function household(string $wpFrom, callable $wp, array $houseExtra = []): array
    {
        $this->store->write('settings.json', ['active_utilities' => ['strom']]);
        $dev = fn(string $id, string $from) => [['id' => "d_$id", 'serial' => null, 'installed_on' => $from, 'initial_counter' => 0.0,
            'removed_on' => null, 'final_counter' => null, 'reason' => null]];
        $meter = fn(string $id, string $name, string $from, ?string $group) => ['id' => $id, 'name' => $name, 'icon' => '', 'created_at' => $from,
            'active' => true, 'notes' => '', 'devices' => $dev($id, $from)] + ($group ? ['meter_group_id' => $group] : []);
        $this->store->write('strom/meters.json', [
            $meter('m_house', 'Haus', '2023-01-01', null),
            $meter('m_wp_ht', 'Wärmepumpe HT', $wpFrom, 'g_wp'),
            $meter('m_wp_nt', 'Wärmepumpe NT', $wpFrom, 'g_wp'),
        ]);
        $this->store->write('strom/meter_groups.json', [['id' => 'g_wp', 'name' => 'Wärmepumpe', 'created_at' => $wpFrom]]);
        $this->store->write('strom/contracts.json', []);
        $rows = [];
        foreach (['m_house' => '2023-01-01', 'm_wp_ht' => $wpFrom, 'm_wp_nt' => $wpFrom] as $id => $from) {
            $c = 0.0;
            for ($t = strtotime("$from 12:00"); date('Y-m-d', $t) <= '2026-01-01'; $t += 86400) {
                $d = date('Y-m-d', $t);
                if (substr($d, 8, 2) === '01') $rows[] = ['id' => "r_{$id}_$d", 'meter_id' => $id, 'device_id' => "d_$id", 'date' => $d, 'counter' => round($c, 3),
                    'price_cents' => null, 'note' => '', 'is_estimated' => false, 'is_future' => false];
                $c += $id === 'm_house' ? 8.0 * ($houseExtra[substr($d, 0, 7)] ?? 1.0) : $wp($d);
            }
        }
        $this->store->write('strom/readings.json', $rows);
        $recs = new RecommendationService($this->store, $this->meters, $this->consumption, $this->settings,
            new BenchmarkService($this->consumption, $this->meters, $this->settings, $this->i18n),
            new DeliveryService($this->store, $this->meters, $this->i18n), $this->i18n);
        return array_values(array_filter($recs->atDate('2026-01-31')->all(), fn($r) => str_starts_with((string)$r['id'], 'r4_')));
    }

    private static function season(string $d, float $factor = 1.0, string $boost = ''): float
    {
        $mo = (int)substr($d, 5, 2);
        $v = $mo >= 4 && $mo <= 9 ? 2.0 : 12.0;
        return substr($d, 0, 7) === $boost ? $v * $factor : $v;
    }

    public function testTheFirstHeatingMonthOfAHeatPumpIsNoAnomaly(): void
    {
        // Wärmepumpe seit 01.04.2025: kein Oktober eines früheren Jahres, also keine Anomalie
        $r4 = $this->household('2025-04-01', fn(string $d) => self::season($d));
        self::assertSame([], $r4, json_encode(array_column($r4, 'title')));
        // die Analyse kennt den Ausschlag weiter, mit der Kennung „ohne denselben Monat davor“
        $group = $this->meters->groupTarget('strom', 'g_wp');
        $found = $this->anomalies->detect('strom', $this->consumption->forMeter('strom', $group));
        $oct = array_values(array_filter($found, fn($a) => $a['ym'] === '2025-10'));
        self::assertNotEmpty($oct, 'Kontrolle: Oktober gegen September und November ist ein Ausschlag');
        self::assertFalse($oct[0]['prior_same_month']);
    }

    public function testAGroupGetsOneNamedRecommendationAndSoDoesTheHouseMeter(): void
    {
        // Wärmepumpe seit 01.04.2024; im Oktober 2025 läuft sie dreimal so viel wie im Oktober 2024,
        // der Hauszähler im November 2025 doppelt so viel wie sonst
        $r4 = $this->household('2024-04-01', fn(string $d) => self::season($d, 3.0, '2025-10'), ['2025-11' => 2.0]);
        $titles = array_column($r4, 'title');
        sort($titles);
        self::assertSame(['Strom · Haus Nov. 2025: statistische Anomalie', 'Strom · Wärmepumpe Okt. 2025: statistische Anomalie'], $titles);
        $wp = array_values(array_filter($r4, fn($r) => $r['evidence']['meter_id'] === 'g_wp'))[0];
        self::assertTrue($wp['evidence']['is_group']);
        self::assertSame('2025-10', $wp['evidence']['ym']);
        self::assertSame([], array_filter($r4, fn($r) => in_array($r['evidence']['meter_id'], ['m_wp_ht', 'm_wp_nt'], true)), 'nicht je Zählwerk');
    }

    public function testASingleMeterKeepsTheShortTitle(): void
    {
        // nur der Hauszähler (keine Wärmepumpe in Betrieb): Titel ohne Namen
        $this->household('2024-04-01', fn(string $d) => 0.0, ['2025-11' => 2.0]);
        $meters = $this->store->read('strom/meters.json');
        $this->store->write('strom/meters.json', [$meters[0]]);
        $this->store->write('strom/meter_groups.json', []);
        $recs = new RecommendationService($this->store, $this->meters, $this->consumption, $this->settings,
            new BenchmarkService($this->consumption, $this->meters, $this->settings, $this->i18n),
            new DeliveryService($this->store, $this->meters, $this->i18n), $this->i18n);
        $r4 = array_values(array_filter($recs->atDate('2026-01-31')->all(), fn($r) => str_starts_with((string)$r['id'], 'r4_')));
        self::assertSame(['Strom Nov. 2025: statistische Anomalie'], array_column($r4, 'title'));
    }
}
