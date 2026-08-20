<?php
declare(strict_types=1);

namespace Energietracker\Controllers;

use Energietracker\Http\Request;
use Energietracker\Http\Response;
use Energietracker\Services\ConsumptionService;
use Energietracker\Services\AnomalyService;
use Energietracker\Services\MeterService;
use Energietracker\Services\RegressionService;
use Energietracker\Services\SettingsService;
use Energietracker\Config\Utilities;
use Energietracker\Services\I18nService;

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
        private SettingsService $settings,
        private I18nService $i18n,
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
        if (!$meter) Response::error($this->i18n->t('errors.meter.notFound'), 404);
        $monthly = $this->consumption->forMeter($utility, $meter, $hddBase !== null ? (float)$hddBase : null);

        // For HGT-relevant utilities, fit all four regression models so the
        // analysis view can compare them. For non-HGT utilities this stays
        // empty and the frontend falls back to a seasonal profile.
        $regressions = [];
        if (Utilities::isHgtRelevant($utility)) {
            // v1.4.0 — F1011: Monate vor der Zäsur beschreiben ein anderes
            // Gebäude und gehören nicht in den Fit. Sie bleiben im `monthly`
            // und werden im Chart ausgegraut dargestellt — ausgeschlossen wird
            // aus dem Modell, nicht aus der Anzeige. Welche Monate das sind,
            // entscheidet der ConsumptionService an einer Stelle für alle.
            $pts = $this->consumption->regressionPoints($monthly, $utility);
            foreach (['linear', 'polynomial', 'robust', 'segmented', 'sigmoid'] as $model) {
                $regressions[$model] = $this->regression->fit($model, $pts['x'], $pts['y'], $this->settings);
            }
        }

        Response::json([
            'meter'       => $meter,
            'monthly'     => $monthly,
            'anomalies'   => $this->anomalies->detect($utility, $monthly),
            'regressions' => $regressions,
            // v1.4.0 — F1011: Zustand der Zäsur + warum ggf. etwas fehlt,
            // und der Vorher/Nachher-Vergleich der Heizkurve.
            'baseline'    => $this->consumption->baselineInfo($utility, $meter, $monthly),
            'baseline_comparison' => $this->consumption->baselineComparison($utility, $meter, $monthly),
        ]);
    }

    /** GET /api/utility/{utility}/meters/{id}/contract-status */
    public function contractStatus(Request $req): never
    {
        $meterId = $req->param('id');
        $utility = $req->param('utility');
        $meter = $this->meters->get($utility, $meterId);
        if (!$meter) Response::error($this->i18n->t('errors.meter.notFound'), 404);
        Response::json($this->consumption->contractStatus($utility, $meter));
    }
}
