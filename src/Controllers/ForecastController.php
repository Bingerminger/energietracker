<?php
declare(strict_types=1);

namespace Energietracker\Controllers;

use Energietracker\Http\Request;
use Energietracker\Http\Response;
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
    ) {}

    public function forMeter(Request $req): never
    {
        $utility = $req->param('utility');
        $meterId = $req->param('id');
        $meter = $this->meters->get($utility, $meterId);
        if (!$meter) Response::error($this->i18n->t('errors.meter.notFound'), 404);
        $opts = [];
        foreach (['forecast_months', 'temp_offset', 'price_factor', 'model'] as $k) {
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
        if (isset($opts['model'])
            && !in_array($opts['model'], ['linear', 'polynomial', 'robust', 'segmented', 'sigmoid'], true)) {
            $bad('model', 'linear, polynomial, robust, segmented, sigmoid');
        }
        Response::json($this->forecasts->forMeter($utility, $meter, $opts));
    }
}
