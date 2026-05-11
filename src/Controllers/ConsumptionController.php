<?php
declare(strict_types=1);

namespace Energietracker\Controllers;

use Energietracker\Http\Request;
use Energietracker\Http\Response;
use Energietracker\Services\ConsumptionService;
use Energietracker\Services\AnomalyService;
use Energietracker\Services\MeterService;
use Energietracker\Services\RegressionService;
use Energietracker\Config\Utilities;

/**
 * Verbrauchs-Endpoints. Drei Granularitäten:
 *   - utility-weit aggregiert (`/consumption`)
 *   - pro Zähler mit Anomalien und Regressionsmodellen (`/meters/{id}/consumption`)
 *   - pro Vertrag mit Saldo-Aggregation (`/meters/{id}/contract-status`)
 */
final class ConsumptionController
{
    public function __construct(
        private ConsumptionService $consumption,
        private AnomalyService $anomalies,
        private MeterService $meters,
        private RegressionService $regression,
    ) {}

    /** GET /api/utility/{utility}/consumption — all meters + totals */
    public function utility(Request $req): never
    {
        $hddBase = $req->queryParam('hdd_base');
        Response::json($this->consumption->forUtility(
            $req->param('utility'),
            $hddBase !== null ? (float)$hddBase : null
        ));
    }

    /** GET /api/utility/{utility}/meters/{id}/consumption */
    public function meter(Request $req): never
    {
        $meterId = $req->param('id');
        $utility = $req->param('utility');
        $hddBase = $req->queryParam('hdd_base');
        $meter = $this->meters->get($utility, $meterId);
        if (!$meter) Response::error('Zähler nicht gefunden', 404);
        $monthly = $this->consumption->forMeter($utility, $meter, $hddBase !== null ? (float)$hddBase : null);

        // For HGT-relevant utilities, fit all four regression models so the
        // analysis view can compare them. For non-HGT utilities this stays
        // empty and the frontend falls back to a seasonal profile.
        $regressions = [];
        if (Utilities::isHgtRelevant($utility)) {
            $consKey = Utilities::get($utility)['consumption_unit'] === 'kWh' ? 'kwh' : 'm3';
            $points = array_values(array_filter($monthly, fn($m) =>
                ($m['hdd'] ?? 0) > 0 && ($m[$consKey] ?? 0) > 0
            ));
            $x = array_map(fn($m) => (float)$m['hdd'],     $points);
            $y = array_map(fn($m) => (float)$m[$consKey],  $points);
            foreach (['linear', 'polynomial', 'robust', 'segmented'] as $model) {
                $regressions[$model] = $this->regression->fit($model, $x, $y);
            }
        }

        Response::json([
            'meter'       => $meter,
            'monthly'     => $monthly,
            'anomalies'   => $this->anomalies->detect($utility, $monthly),
            'regressions' => $regressions,
        ]);
    }

    /** GET /api/utility/{utility}/meters/{id}/contract-status */
    public function contractStatus(Request $req): never
    {
        $meterId = $req->param('id');
        $utility = $req->param('utility');
        $meter = $this->meters->get($utility, $meterId);
        if (!$meter) Response::error('Zähler nicht gefunden', 404);
        Response::json($this->consumption->contractStatus($utility, $meter));
    }
}
