<?php
declare(strict_types=1);

namespace Energietracker\Controllers;

use Energietracker\Http\Request;
use Energietracker\Http\Response;
use Energietracker\Services\I18nService;
use Energietracker\Services\MeterService;
use Energietracker\Services\ScenarioService;

/**
 * v3.6.0 (F1031) — Prognose-Werkstatt: `POST /api/forecast/scenario`.
 *
 * Rechnet ein Szenario zu einem Zähler oder einer Zählergruppe und schreibt
 * nichts (POST nur wegen der verschachtelten Eingaben: Maßnahmen, Tarif). Die
 * Anfrage braucht deshalb keine Schreibsperre und ist auch mit einem
 * API-Schlüssel erlaubt, der nur lesen darf (App::READ_ONLY_POST).
 */
final class ScenarioController
{
    public function __construct(
        private ScenarioService $scenarios,
        private MeterService $meters,
        private I18nService $i18n,
    ) {}

    public function compute(Request $req): never
    {
        $body = $req->body;
        if (!is_array($body)) Response::error($this->i18n->t('errors.forecast.paramInvalid', ['param' => 'body', 'value' => '', 'range' => 'JSON']));
        $s = ScenarioService::normalize($body);   // ungültig → 400
        if (ScenarioService::supports($s['utility']) && !$this->meters->target($s['utility'], $s['meter_id'])) {
            Response::error($this->i18n->t('errors.meter.notFound'), 404);
        }
        Response::json($this->scenarios->compute($s));
    }
}
