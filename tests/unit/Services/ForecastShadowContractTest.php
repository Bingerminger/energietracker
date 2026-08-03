<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Tests\Support\ServiceTestCase;
use Energietracker\Services\ForecastService;
use Energietracker\Services\ConsumptionService;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * v2.2.0 — Regression: Schattenverträge dürfen die Prognose nicht steuern.
 *
 * Ein Schattenvertrag (`is_shadow`) ist eine reine Was-wäre-wenn-Hypothese für
 * den Tarifvergleich. ConsumptionService filtert ihn in `applyContracts()` und
 * `contractStatus()` heraus; ForecastService tat das bis v2.1.5 NICHT. Sobald
 * der letzte echte Vertrag vor dem Prognosehorizont endete, übernahm der
 * Schattenvertrag Arbeitspreis, Grundpreis und Abschlagsplan — die Prognose
 * rechnete still mit einem Tarif, den es nie gab.
 *
 * Das ist exakt der Normalfall: Man legt Schattenverträge an, WEIL der laufende
 * Vertrag ausläuft.
 */
#[CoversClass(ForecastService::class)]
#[CoversClass(ConsumptionService::class)]
final class ForecastShadowContractTest extends ServiceTestCase
{
    private ForecastService $forecast;

    protected function setUp(): void
    {
        parent::setUp();
        $this->forecast = new ForecastService(
            $this->consumption, $this->regression, $this->settings, $this->contracts, $this->i18n
        );
    }

    /**
     * 24 Monate Historie, echter Vertrag endet in der Vergangenheit, ein
     * Schattenvertrag läuft ab heute mit offenem Ende — genau die Form, die das
     * Tarifvergleich-Formular anlegt (es hat kein Ende-Feld).
     */
    private function seedMeterWithExpiredRealAndOpenShadow(): string
    {
        $meterId = $this->setMeterDevices('strom', [[
            'id' => 'd_strom_1', 'serial' => null,
            'installed_on' => '2023-01-01', 'initial_counter' => 0.0,
            'removed_on' => null, 'final_counter' => null, 'reason' => null,
        ]]);

        // 25 Ablesungen ab vor zwei Jahren, je 300 kWh im Monat.
        $rows = [];
        $start = new \DateTimeImmutable(date('Y-m-01') . ' -25 months');
        for ($i = 0; $i <= 25; $i++) {
            $d = $start->modify("+$i months");
            $rows[] = [
                'date'      => $d->format('Y-m-d'),
                'counter'   => 1000.0 + $i * 300.0,
                'device_id' => 'd_strom_1',
            ];
        }
        $this->setReadings('strom', $meterId, $rows);

        // Echter Vertrag: gestern beendet, 25 ct/kWh.
        $realStart = $start->format('Y-m-d');
        $this->contracts->create('strom', [
            'meter_id'         => $meterId,
            'provider'         => 'Stadtwerke',
            'tariff_name'      => 'Basis',
            'start'            => $realStart,
            'end'              => date('Y-m-d', strtotime('-1 day')),
            'working_prices'   => [['from' => $realStart, 'ct_per_kwh' => 25.0]],
            'base_prices'      => [['from' => $realStart, 'eur_per_month' => 10.0]],
            'advance_payments' => [['from' => $realStart, 'amount_eur' => 80.0]],
        ]);

        // Schattenvertrag: ab heute, offenes Ende, deutlich anderer Preis.
        $this->contracts->create('strom', [
            'meter_id'       => $meterId,
            'tariff_name'    => 'Hypothese Billigtarif',
            'shadow_label'   => 'Hypothese Billigtarif',
            'is_shadow'      => true,
            'start'          => date('Y-m-d'),
            'end'            => null,
            'working_prices' => [['from' => date('Y-m-d'), 'ct_per_kwh' => 99.0]],
            'base_prices'    => [['from' => date('Y-m-d'), 'eur_per_month' => 500.0]],
        ]);

        return $meterId;
    }

    public function testForecastNeverUsesShadowContractAsPriceBasis(): void
    {
        $meterId = $this->seedMeterWithExpiredRealAndOpenShadow();
        $meter   = $this->meters->get('strom', $meterId);

        $shadowIds = [];
        foreach ($this->contracts->list('strom', $meterId) as $c) {
            if (!empty($c['is_shadow'])) $shadowIds[] = $c['id'];
        }
        self::assertNotEmpty($shadowIds, 'Testaufbau: ein Schattenvertrag muss existieren');

        $result = $this->forecast->forMeter('strom', $meter, ['forecast_months' => 12]);
        self::assertTrue($result['valid'] ?? false, 'Prognose muss gültig sein: ' . ($result['reason'] ?? ''));

        foreach ($result['forecast'] as $month) {
            self::assertNotContains(
                $month['contract_id'],
                $shadowIds,
                "Prognosemonat {$month['ym']} darf nicht auf einem Schattenvertrag beruhen"
            );
            // Der Schattenpreis (99 ct) darf in keinem Monat auftauchen.
            if ($month['working_price_ct'] !== null) {
                self::assertNotEqualsWithDelta(
                    99.0, (float)$month['working_price_ct'], 0.001,
                    "Prognosemonat {$month['ym']} nutzt den hypothetischen Arbeitspreis"
                );
            }
        }
    }

    /**
     * Gegenprobe: Ohne den Schattenvertrag muss dieselbe Prognose herauskommen.
     * Damit ist ausgeschlossen, dass der Filter nur zufällig greift.
     */
    public function testForecastIsIdenticalWithAndWithoutShadowContract(): void
    {
        $meterId = $this->seedMeterWithExpiredRealAndOpenShadow();
        $meter   = $this->meters->get('strom', $meterId);

        $withShadow = $this->forecast->forMeter('strom', $meter, ['forecast_months' => 12]);

        foreach ($this->contracts->list('strom', $meterId) as $c) {
            if (!empty($c['is_shadow'])) $this->contracts->delete('strom', $c['id']);
        }
        $withoutShadow = $this->forecast->forMeter('strom', $meter, ['forecast_months' => 12]);

        self::assertSame(
            array_column($withoutShadow['forecast'], 'cost_estimated', 'ym'),
            array_column($withShadow['forecast'], 'cost_estimated', 'ym'),
            'Ein Schattenvertrag darf die prognostizierten Kosten nicht verändern'
        );
        self::assertSame(
            array_column($withoutShadow['forecast'], 'advance_estimated', 'ym'),
            array_column($withShadow['forecast'], 'advance_estimated', 'ym'),
            'Ein Schattenvertrag darf den prognostizierten Abschlag nicht verändern'
        );
    }
}
