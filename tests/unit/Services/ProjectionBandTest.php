<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\ConsumptionService;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * v3.9.0 (Skeptiker-Lauf 3.9.0, M6) — eine sichere Schätzung hat trotzdem eine Breite. Bis dahin
 * stand der Saldo nach bestandener Gütesperre (etwa nach der Heizgrenze) auf den Cent da, auch
 * mit neun geschätzten Monaten. Erfundener Haushalt: Gas 5 kWh am Tag + 0,3 kWh je Gradtag,
 * Temperaturen mit Wetter (jedes Jahr anders), 10 ct/kWh; letzte Ablesung 01.01.2026, heute
 * 26.10.2026, Abrechnung 31.12.2026.
 *
 * Handrechnung der halben Breite: z × √( Σ (a × f_Heiz × σ_HGT,Monat × Anteil künftiger Tage)²
 *   + Σ (σ_Modell × Anteil geschätzter Tage)² ) × 0,10 €/kWh, z = 1,28 (80 %).
 */
#[CoversClass(ConsumptionService::class)]
final class ProjectionBandTest extends ServiceTestCase
{
    private const TODAY = '2026-10-26';
    private string $meterId = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->household('2026-01-01');
    }

    /** Haushalt mit Ablesungen am Monatsersten bis $last. */
    private function household(string $last): void
    {
        $this->meterId = $this->setMeterDevices('gas', [['id' => 'd_b', 'serial' => null, 'installed_on' => '2023-01-01', 'initial_counter' => 0.0,
            'removed_on' => null, 'final_counter' => null, 'reason' => null]]);
        $temps = []; $rows = []; $c = 0.0; $seed = 7;
        for ($t = strtotime('2023-01-01'); $t <= strtotime('2026-12-31'); $t += 86400) {
            $d = date('Y-m-d', $t);
            $seed = ($seed * 1103515245 + 12345) % 2147483648;
            $noise = ($seed / 2147483648 - 0.5) * 8.0;   // ±4 K Wetter, deterministisch
            $avg = 10.0 - 9.0 * cos(2 * M_PI * ((int)date('z', $t) - 15) / 365.25) + $noise;
            $temps[$d] = ['avg' => round($avg, 1), 'min' => round($avg - 3, 1), 'max' => round($avg + 3, 1)];
            if ($d <= $last && substr($d, 8) === '01') $rows[] = ['date' => $d, 'counter' => round($c, 1), 'device_id' => 'd_b'];
            $c += 5.0 + 0.3 * max(0.0, 15.0 - round($avg, 1));
        }
        $this->store->write('temperatures.json', $temps);
        $this->setReadings('gas', $this->meterId, $rows);
        $this->contracts->create('gas', ['meter_id' => $this->meterId, 'provider' => 'Gas Musterstadt', 'start' => '2026-01-01',
            'working_prices' => [['from' => '2026-01-01', 'ct_per_kwh' => 10.0]], 'advance_payments' => [['from' => '2026-01-01', 'amount_eur' => 80]]]);
    }

    public function testACertainEstimateCarriesWeatherAndModelBand(): void
    {
        $meter = $this->meters->get('gas', $this->meterId);
        $c = $this->consumption->contractStatus('gas', $meter, self::TODAY)['contracts'][0];
        self::assertNull($c['projection_uncertain'], 'bestandene Gütesperre: keine Spanne „unsicher“');
        $b = $c['projection_band'];
        self::assertNotNull($b, 'bis dahin keine Breite');
        $p = (new \ReflectionProperty($this->consumption, 'lastProjection'))->getValue($this->consumption);
        self::assertTrue($p['trusted']);
        // Handrechnung mit dem Modell und dem Normal des Zählers
        $model = $p['model'];
        $normals = $this->consumption->hddNormals($this->consumption->hddBaseFor($meter));
        $varW = 0.0; $varN = 0.0;
        for ($m = 1; $m <= 12; $m++) {
            $dim = (int)date('t', mktime(0, 0, 0, $m, 1, 2026));
            $future = $m < 10 ? 0 : ($m === 10 ? 6 : $dim);   // ab 26.10.2026
            $varN += ((float)$model['resid_sd']) ** 2;            // jeder Monat 2026 ganz geschätzt
            $varW += ((float)$model['a'] * (float)$p['heat'] * (float)$normals[$m]['sd'] * $future / $dim) ** 2;
        }
        $half = round(1.28 * sqrt($varW + $varN) * 0.10, 2);
        self::assertEqualsWithDelta($half, $b['half'], 0.02);
        self::assertSame(365, $b['estimated_days']);
        self::assertEqualsWithDelta($c['projected_end_balance'] - $b['half'], $b['low'], 0.011);
        self::assertEqualsWithDelta($c['projected_end_balance'] + $b['half'], $b['high'], 0.011);
        self::assertSame(80, $b['level_pct']);
        // die Breite verändert die Projektion nicht: der Saldo-Verlauf endet beim Endsaldo
        $path = $c['balance_path'];
        self::assertEqualsWithDelta($c['projected_end_balance'], $path[count($path) - 1]['balance'], 0.01);
        // kein „unsicher“: Vorschlag bzw. laufender Abschlag bleiben (hier zwei Abschläge bis zur Abrechnung, L12)
        self::assertSame('short', $c['suggestion_withheld']);
        self::assertNotNull($c['advance_ongoing']);
    }

    public function testTheBandLeavesTheBalancePathAlone(): void
    {
        // letzte Ablesung 01.06.2026, Saldo ab 01.01.2026: gemessene Monate und geschätzter Rest getrennt
        foreach (['meters', 'readings', 'contracts'] as $pot) $this->store->write("gas/$pot.json", []);
        $this->household('2026-06-01');
        $c = $this->consumption->contractStatus('gas', $this->meters->get('gas', $this->meterId), self::TODAY)['contracts'][0];
        self::assertNotNull($c['projection_band']);
        $path = $c['balance_path'];
        self::assertEqualsWithDelta($c['projected_end_balance'], $path[count($path) - 1]['balance'], 0.01, 'Verlauf endet beim Endsaldo');
        self::assertEqualsWithDelta($c['cost_to_date'] + $c['estimated_cost_remaining'], $path[count($path) - 1]['cost'], 0.02);
    }

    public function testAnUncertainEstimateKeepsItsRangeAndGetsNoBand(): void
    {
        // unordentlicher Testhaushalt: Gütesperre nicht bestanden → die Spanne „unsicher“, kein zweites Band
        $h = json_decode((string)file_get_contents(dirname(__DIR__, 2) . '/fixtures/messy-household/household.json'), true, 512, JSON_THROW_ON_ERROR);
        $this->store->write('settings.json', $h['settings']);
        $this->store->write('temperatures.json', $h['temperatures']);
        foreach (['gas', 'strom', 'wasser'] as $u) foreach (['meters', 'readings', 'contracts'] as $pot) $this->store->write("$u/$pot.json", []);
        foreach ($h['utilities'] as $u => $pots) foreach ($pots as $pot => $rows) $this->store->write("$u/$pot.json", $rows);
        $c = $this->consumption->contractStatus('gas', $this->meters->list('gas')[0], self::TODAY)['contracts'][0];
        self::assertNotNull($c['projection_uncertain']);
        self::assertNull($c['projection_band']);
    }
}
