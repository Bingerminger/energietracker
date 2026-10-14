<?php
declare(strict_types=1);

namespace Energietracker\Controllers;

use Energietracker\Http\Request;
use Energietracker\Http\Response;
use Energietracker\Services\EnergyFlowDayService;
use Energietracker\Services\I18nService;

/**
 * v3.5.0 (F1027) — Tagesfilm des Energieflusses.
 *
 *   GET /api/energy-flow/days                        Tage und Monate mit Viertelstunden
 *   GET /api/energy-flow/day?date=JJJJ-MM-TT         ein Tag je Viertelstunde
 *   GET /api/energy-flow/typical-day?month=JJJJ-MM   typischer Tag eines Monats
 */
final class EnergyFlowController
{
    public function __construct(private EnergyFlowDayService $flow, private I18nService $i18n) {}

    public function days(Request $req): never
    {
        Response::json($this->flow->days());
    }

    public function day(Request $req): never
    {
        $d = (string)($req->queryParam('date') ?? '');
        if (!EnergyFlowDayService::validDate($d)) $this->fail('errors.energyFlow.dateInvalid', ['date' => mb_substr($d, 0, 20)]);
        Response::json($this->flow->day($d));
    }

    public function typicalDay(Request $req): never
    {
        $ym = (string)($req->queryParam('month') ?? '');
        if (!EnergyFlowDayService::validMonth($ym)) $this->fail('errors.energyFlow.monthInvalid', ['month' => mb_substr($ym, 0, 20)]);
        Response::json($this->flow->typicalDay($ym));
    }

    private function fail(string $key, array $params): never
    {
        Response::error($this->i18n->t($key, $params), 400, null, $key);
    }
}
