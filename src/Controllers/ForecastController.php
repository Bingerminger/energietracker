<?php
declare(strict_types=1);

namespace Energietracker\Controllers;

use Energietracker\Http\Request;
use Energietracker\Http\Response;
use Energietracker\Services\ClimateNormalService;
use Energietracker\Services\ConsumptionService;
use Energietracker\Services\ForecastService;
use Energietracker\Services\MeterService;
use Energietracker\Services\I18nService;

/**
 * 12-Monats-Forecast pro Zähler. Mischt Regression (Modell wählbar)
 * und Saisonprofil R²-gewichtet (siehe ARCHITECTURE → Forecast).
 */
final class ForecastController
{
    public function __construct(
        private ForecastService $forecasts,
        private MeterService $meters,
        private I18nService $i18n,
        private ?ConsumptionService $consumption = null,   // v3.13.0 — Monate ohne Arbeitspreis kennzeichnen
    ) {}

    public function forMeter(Request $req): never
    {
        $utility = $req->param('utility');
        $meterId = $req->param('id');
        $meter = $this->meters->target($utility, $meterId);   // v3.1.0 (H6): auch Gruppe
        if (!$meter) Response::error($this->i18n->t('errors.meter.notFound'), 404);
        $opts = [];
        foreach (['forecast_months', 'temp_offset', 'price_factor', 'model', 'co2_scenario_eur_t', 'co2_scenario_from'] as $k) {
            if (($v = $req->queryParam($k)) !== null) $opts[$k] = $v;
        }
        // v2.6.0 — Grenzen. `forecast_months=100000` erschöpfte den Speicher
        // (500 ohne Antwortkörper), `price_factor=abc` rechnete mit 0 €.
        $bad = fn(string $param, string $range) => Response::error($this->i18n->t(
            'errors.forecast.paramInvalid', ['param' => $param, 'value' => (string)$opts[$param], 'range' => $range]
        ));
        if (isset($opts['forecast_months'])
            && (!ctype_digit((string)$opts['forecast_months']) || (int)$opts['forecast_months'] < 1 || (int)$opts['forecast_months'] > 60)) {
            $bad('forecast_months', '1–60');
        }
        if (isset($opts['temp_offset'])
            && (!is_numeric($opts['temp_offset']) || abs((float)$opts['temp_offset']) > 30)) {
            $bad('temp_offset', '−30…30');
        }
        if (isset($opts['price_factor'])
            && (!is_numeric($opts['price_factor']) || (float)$opts['price_factor'] < 0 || (float)$opts['price_factor'] > 10)) {
            $bad('price_factor', '0…10');
        }
        // v3.1.0 (H4, MKT-26) — CO₂-Preis-Szenario
        if (isset($opts['co2_scenario_eur_t']) && $opts['co2_scenario_eur_t'] !== ''
            && (!is_numeric($opts['co2_scenario_eur_t']) || (float)$opts['co2_scenario_eur_t'] < 0 || (float)$opts['co2_scenario_eur_t'] > 1000)) {
            $bad('co2_scenario_eur_t', '0…1000');
        }
        if (isset($opts['co2_scenario_from']) && (!ctype_digit((string)$opts['co2_scenario_from'])
            || (int)$opts['co2_scenario_from'] < 2021 || (int)$opts['co2_scenario_from'] > 2100)) {
            $bad('co2_scenario_from', '2021–2100');
        }
        if (isset($opts['model'])
            && !in_array($opts['model'], ['linear', 'polynomial', 'robust', 'segmented', 'sigmoid'], true)) {
            $bad('model', 'linear, polynomial, robust, segmented, sigmoid');
        }
        // v3.7.1 (GitHub #38) — `hdd_base` wie bei …/consumption: dieselbe Heizgrenze für
        // Gradtage, Heizmodell, Normal und Prognose, ohne sie am Zähler zu speichern
        if (($hb = $req->queryParam('hdd_base')) !== null) {
            if (!is_numeric($hb) || (float)$hb < ClimateNormalService::BASE_MIN || (float)$hb > ClimateNormalService::BASE_MAX) {
                Response::error($this->i18n->t('errors.forecast.paramInvalid', ['param' => 'hdd_base', 'value' => (string)$hb,
                    'range' => ClimateNormalService::BASE_MIN . '–' . ClimateNormalService::BASE_MAX]));
            }
            $meter['hdd_base_temp'] = (float)$hb;
        }
        Response::json(self::withPriceMissing($this->forecasts->forMeter($utility, $meter, $opts), $utility, $meter, $this->consumption));
    }

    /**
     * v3.13.0 (additiv, nur wenn wahr) — Prognosemonate, in denen der Vertrag keinen Arbeitspreis hat: `price_missing: true`
     * (`cost_estimated` sind dann keine Verbrauchskosten), dieselbe Regel wie an den Monatszeilen.
     *
     * @param array<string,mixed> $f Ergebnis von ForecastService::forMeter
     * @param array<string,mixed> $meter
     * @return array<string,mixed>
     */
    public static function withPriceMissing(array $f, string $utility, array $meter, ?ConsumptionService $consumption): array
    {
        if ($consumption === null || empty($f['forecast']) || !is_array($f['forecast'])) return $f;
        $missing = $consumption->priceMissingIn($utility, $meter, $f['forecast']);
        if ($missing === []) return $f;
        foreach ($f['forecast'] as &$r) if (isset($missing[(string)($r['ym'] ?? '')])) $r['price_missing'] = true;
        unset($r);
        return $f;
    }
}
