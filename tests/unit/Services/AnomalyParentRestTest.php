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
 * v3.10.0 (Befund aus v3.9.0, Paket heizgrenze) — Empfehlung „statistische Anomalie“ (R4) am
 * Elternzähler mit seinem eigenen Verbrauch (Rest), nicht mit der Summe samt Subzählern.
 *
 * Erfundener Haushalt, Strom, am Monatsersten abgelesen bis 01.01.2026:
 *   - Hauszähler „Haus“ seit 01.01.2023: eigener Verbrauch 8 kWh am Tag, misst die Wallbox mit
 *   - Subzähler „Wallbox“ hinter dem Hauszähler: 5 kWh am Tag
 * Ein Ausschlag der Wallbox im November 2025 (20 statt 5 kWh am Tag) erschien bis v3.9 auch am
 * Hauszähler (28 statt 13 kWh am Tag gemessen). Jetzt prüft die Regel dort 8 kWh am Tag (Rest).
 */
#[CoversClass(RecommendationService::class)]
#[CoversClass(AnomalyService::class)]
final class AnomalyParentRestTest extends ServiceTestCase
{
    /**
     * @param array<string,float> $houseExtra Faktor auf den eigenen Verbrauch je Monat
     * @param array<string,float> $boxExtra   Faktor auf die Wallbox je Monat
     * @param list<string> $boxSkip           Ablesetage der Wallbox, die fehlen
     * @param string|null $boxRead            erste Ablesung der Wallbox (sonst ihr Einbau)
     * @return list<array<string,mixed>> Empfehlungen R4
     */
    private function household(array $houseExtra = [], array $boxExtra = [], string $boxFrom = '2023-01-01', array $boxSkip = [], ?string $boxRead = null): array
    {
        $boxRead ??= $boxFrom;
        $this->store->write('settings.json', ['active_utilities' => ['strom']]);
        $dev = fn(string $id, string $from) => [['id' => "d_$id", 'serial' => null, 'installed_on' => $from, 'initial_counter' => 0.0,
            'removed_on' => null, 'final_counter' => null, 'reason' => null]];
        $this->store->write('strom/meters.json', [
            ['id' => 'm_house', 'name' => 'Haus', 'icon' => '', 'created_at' => '2023-01-01', 'active' => true, 'notes' => '', 'devices' => $dev('m_house', '2023-01-01')],
            ['id' => 'm_box', 'name' => 'Wallbox', 'icon' => '', 'created_at' => $boxFrom, 'active' => true, 'notes' => '', 'parent_meter_id' => 'm_house',
             'devices' => $dev('m_box', $boxFrom)],
        ]);
        $this->store->write('strom/meter_groups.json', []);
        $this->store->write('strom/contracts.json', []);
        $rows = [];
        $house = 0.0; $box = 0.0;
        for ($t = strtotime('2023-01-01 12:00'); date('Y-m-d', $t) <= '2026-01-01'; $t += 86400) {
            $d = date('Y-m-d', $t);
            $first = substr($d, 8, 2) === '01';
            if ($first) $rows[] = ['id' => "r_h_$d", 'meter_id' => 'm_house', 'device_id' => 'd_m_house', 'date' => $d, 'counter' => round($house, 3),
                'price_cents' => null, 'note' => '', 'is_estimated' => false, 'is_future' => false];
            if (($first || $d === $boxRead) && $d >= $boxRead && !in_array($d, $boxSkip, true)) $rows[] = ['id' => "r_b_$d", 'meter_id' => 'm_box', 'device_id' => 'd_m_box',
                'date' => $d, 'counter' => round($box, 3), 'price_cents' => null, 'note' => '', 'is_estimated' => false, 'is_future' => false];
            $own = 8.0 * ($houseExtra[substr($d, 0, 7)] ?? 1.0);
            $wb = $d >= $boxFrom ? 5.0 * ($boxExtra[substr($d, 0, 7)] ?? 1.0) : 0.0;
            $house += $own + $wb;   // der Hauszähler misst die Wallbox mit
            $box += $wb;
        }
        $this->store->write('strom/readings.json', $rows);
        $recs = new RecommendationService($this->store, $this->meters, $this->consumption, $this->settings,
            new BenchmarkService($this->consumption, $this->meters, $this->settings, $this->i18n),
            new DeliveryService($this->store, $this->meters, $this->i18n), $this->i18n);
        return array_values(array_filter($recs->atDate('2026-01-31')->all(), fn($r) => str_starts_with((string)$r['id'], 'r4_')));
    }

    public function testASpikeOfTheSubmeterAppearsOnlyAtTheSubmeter(): void
    {
        $r4 = $this->household([], ['2025-11' => 4.0]);
        self::assertSame(['Strom · Wallbox Nov. 2025: statistische Anomalie'], array_column($r4, 'title'), 'bis v3.9 auch „Strom · Haus Nov. 2025“');
        // Kontrolle: gemessen liegt der Hauszähler im November 2025 deutlich über dem Üblichen (28 statt 13 kWh am Tag)
        $nov = array_column($this->consumption->forMeter('strom', $this->meters->get('strom', 'm_house')), null, 'ym')['2025-11'];
        self::assertEqualsWithDelta(28.0 * 30, (float)$nov['kwh'], 0.5);
    }

    public function testTheParentsOwnSpikeIsStillFoundAndSaysWhatWasLeftOut(): void
    {
        // eigener Verbrauch im November 2025 doppelt (16 statt 8 kWh am Tag), Wallbox gleichmäßig
        $r4 = $this->household(['2025-11' => 2.0]);
        self::assertSame(['Strom · Haus Nov. 2025: statistische Anomalie'], array_column($r4, 'title'));
        self::assertSame(['Wallbox'], $r4[0]['evidence']['without_submeters']);
        self::assertStringEndsWith($this->i18n->t('recommendations.engine.r4.rest', ['names' => 'Wallbox']), $r4[0]['detail']);
    }

    /**
     * Unordentlich: Die Wallbox kommt am 01.03.2024 (vorher kein Auto: der Hauszähler steigt dann um 5 kWh
     * am Tag), abgelesen wird sie erst ab dem 15.03.2024, und ihre Ablesung vom 01.09.2025 fehlt (August und
     * September verteilt). Im März 2024 misst die Wallbox nur 17 von 31 Tagen: Der Rest wäre um 14 × 5 =
     * 70 kWh zu hoch (318 statt 248 kWh, +28 %), der Monat fällt weg. Der Ausschlag der Wallbox im November
     * 2025 bleibt bei ihr.
     */
    public function testAMessySubmeterLeavesNoFalseAnomalyAtTheParent(): void
    {
        $r4 = $this->household([], ['2025-11' => 4.0], '2024-03-01', ['2025-09-01'], '2024-03-15');
        self::assertSame(['Strom · Wallbox Nov. 2025: statistische Anomalie'], array_column($r4, 'title'));
        // derselbe Haushalt mit einem echten Ausschlag am Haus im Oktober 2025: wird gefunden
        $r4 = $this->household(['2025-10' => 2.0], [], '2024-03-01', ['2025-09-01'], '2024-03-15');
        self::assertSame(['Strom · Haus Okt. 2025: statistische Anomalie'], array_column($r4, 'title'));
    }
}
