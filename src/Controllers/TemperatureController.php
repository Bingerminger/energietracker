<?php
declare(strict_types=1);

namespace Energietracker\Controllers;

use Energietracker\Http\Request;
use Energietracker\Http\Response;
use Energietracker\Services\TemperatureService;
use Energietracker\Services\I18nService;

/**
 * Tagestemperaturen: Read (als Map), Upsert pro Tag, CSV-Bulk-Import,
 * Open-Meteo-Sync. Wird sowohl von der Temperaturen-View als auch
 * indirekt vom ConsumptionService (HGT-Berechnung) konsumiert.
 */
final class TemperatureController
{
    public function __construct(
        private TemperatureService $temps,
        private I18nService $i18n,
    ) {}

    public function index(Request $req): never
    {
        Response::json($this->temps->all());
    }

    public function upsert(Request $req): never
    {
        $body = (array)$req->body;
        if (empty($body['date'])) Response::error($this->i18n->t('errors.temperature.dateMissing'));
        $this->temps->upsert(
            (string)$body['date'],
            (float)($body['avg'] ?? 0),
            (float)($body['min'] ?? 0),
            (float)($body['max'] ?? 0),
        );
        Response::json(['ok' => true]);
    }

    public function importCsv(Request $req): never
    {
        $csv = $req->rawBody;
        if ($csv === '') Response::error($this->i18n->t('errors.temperature.emptyCsv'));
        Response::json($this->temps->importCsv($csv));
    }

    public function syncOpenMeteo(Request $req): never
    {
        $start = $req->queryParam('start');
        $end   = $req->queryParam('end');
        Response::json($this->temps->syncOpenMeteo($start, $end));
    }

    public function delete(Request $req): never
    {
        $this->temps->delete($req->param('date'));
        Response::json(['deleted' => true]);
    }
}
