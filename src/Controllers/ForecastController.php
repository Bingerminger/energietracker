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
        Response::json($this->forecasts->forMeter($utility, $meter, $opts));
    }
}
