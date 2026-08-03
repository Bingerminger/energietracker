<?php
declare(strict_types=1);

namespace Energietracker\Controllers;

use Energietracker\Http\Request;
use Energietracker\Http\Response;
use Energietracker\Services\TariffSwitchService;

/**
 * v2.3.0 — Wechselentscheidung.
 * GET /api/utility/{utility}/meters/{id}/tariff-switch?switch_date=YYYY-MM-DD
 *
 * `switch_date` überschreibt den aus Vertragsende und Kündigungsfrist
 * errechneten Termin — für „was wäre, wenn ich sofort wechseln könnte".
 */
final class TariffSwitchController
{
    public function __construct(private TariffSwitchService $switch) {}

    public function analyze(Request $req): never
    {
        Response::json($this->switch->analyze(
            $req->param('utility'),
            $req->param('id'),
            ['switch_date' => $req->queryParam('switch_date')]
        ));
    }
}
