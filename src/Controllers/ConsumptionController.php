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
        // v2.8.0 (Review FE-18) — Lieferarten ohne Heizkurve: Ihre Monatswerte
        // sind selbst nach Gradtagen verteilt, jedes Modell „erklärte" sie mit
        // R² = 1,000. Die Oberfläche zeigt stattdessen einen Hinweis.
        $isDelivery = Utilities::isDelivery($utility);
        if (Utilities::isHgtRelevant($utility) && !$isDelivery) {
            // v1.4.0 — F1011: Monate vor der Zäsur beschreiben ein anderes
            // Gebäude und gehören nicht in den Fit. Sie bleiben im `monthly`
            // und werden im Chart ausgegraut dargestellt — ausgeschlossen wird
            // aus dem Modell, nicht aus der Anzeige. Welche Monate das sind,
            // entscheidet der ConsumptionService an einer Stelle für alle.
            $pts = $this->consumption->regressionPoints($monthly, $utility);
            // v2.8.0 — die Kurvenpunkte kommen mit; das Frontend rechnet
            // RegressionService::predict nicht mehr nach.
            $xMax = 0.0;
            foreach ($monthly as $m) $xMax = max($xMax, (float)($m['hdd'] ?? 0));
            $xMax = $xMax > 0 ? $xMax : 1.0;
            foreach (['linear', 'polynomial', 'robust', 'segmented', 'sigmoid'] as $model) {
                $reg = $this->regression->fit($model, $pts['x'], $pts['y'], $this->settings);
                if ($reg['valid'] ?? false) {
                    $steps = in_array($model, ['linear', 'robust'], true) ? 2 : 50;
                    $reg['curve'] = [];
                    for ($i = 0; $i <= $steps; $i++) {
                        $x = $xMax * $i / $steps;
                        $reg['curve'][] = ['x' => round($x, 2), 'y' => round($this->regression->predict($reg, $x), 2)];
                    }
                }
                $regressions[$model] = $reg;
            }
        }

        Response::json([
            'meter'       => $meter,
            'monthly'     => $monthly,
            'anomalies'   => $this->anomalies->detect($utility, $monthly),
            'regressions' => $regressions,
            // v2.8.0 — warum eine Heizkurve fehlt (nur bei Lieferarten)
            'regressions_note' => $isDelivery && Utilities::isHgtRelevant($utility) ? 'delivery_modelled' : null,
            // v1.4.0 — F1011: Zustand der Zäsur + warum ggf. etwas fehlt,
            // und der Vorher/Nachher-Vergleich der Heizkurve.
            'baseline'    => $this->consumption->baselineInfo($utility, $meter, $monthly),
            'baseline_comparison' => $this->consumption->baselineComparison($utility, $meter, $monthly),
            // v2.6.0 — Ablesungen, die die Rechnung übergangen hat, und warum
            'warnings'    => $this->consumption->readingWarnings($utility, $meter),
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

    /**
     * v2.5.0 — F1012: GET /api/utility/gas/meters/{id}/bill-check?from=&to=
     * Die Gasrechnung nachgerechnet: Abschnitte an jeder Ablesung und jedem
     * Faktorwechsel, je Abschnitt m³ · Zustandszahl · Brennwert = kWh.
     */
    public function billCheck(Request $req): never
    {
        $meterId = $req->param('id');
        $utility = $req->param('utility');
        if ($utility !== 'gas') {
            Response::error($this->i18n->t('errors.billCheck.gasOnly'), 400);
        }
        $meter = $this->meters->get($utility, $meterId);
        if (!$meter) Response::error($this->i18n->t('errors.meter.notFound'), 404);

        $from = (string)($req->queryParam('from') ?? '');
        $to   = (string)($req->queryParam('to') ?? '');
        $iso  = '/^\d{4}-\d{2}-\d{2}$/';
        if (!preg_match($iso, $from) || !preg_match($iso, $to) || $from >= $to) {
            Response::error($this->i18n->t('errors.billCheck.invalidRange'), 400);
        }
        Response::json($this->consumption->gasBillBreakdown($meter, $from, $to));
    }
}
