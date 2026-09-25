<?php
declare(strict_types=1);

namespace Energietracker\Controllers;

use Energietracker\Http\Request;
use Energietracker\Http\Response;
use Energietracker\Services\TemperatureService;
use Energietracker\Services\I18nService;
use Energietracker\Services\SettingsService;
use Energietracker\Support\Dates;

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
        private SettingsService $settings,
    ) {}

    public function index(Request $req): never
    {
        Response::json($this->temps->all());
    }

    public function upsert(Request $req): never
    {
        $body = (array)$req->body;
        if (empty($body['date'])) Response::error($this->i18n->t('errors.temperature.dateMissing'));
        // v2.5.3 — kalendergültig; ein ungültiger Schlüssel in
        // temperatures.json bräche sonst die Heizgradtag-Rechnung.
        if (!Dates::isIsoDate((string)$body['date'])) {
            Response::error($this->i18n->t('errors.common.dateInvalid', ['date' => (string)$body['date']]));
        }
        // v2.6.0 — fehlende Werte wurden zu 0 °C (Lektion 24) und verfälschten
        // die Heizgradtage. Wie beim CSV-Import sind alle drei Pflicht.
        foreach (['avg', 'min', 'max'] as $k) {
            if (!isset($body[$k]) || is_bool($body[$k]) || !is_numeric($body[$k])) {
                Response::error($this->i18n->t('errors.temperature.valuesMissing'));
            }
        }
        $this->temps->upsert((string)$body['date'], (float)$body['avg'], (float)$body['min'], (float)$body['max']);
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
        // v2.6.0 — ungeprüft landeten beide in der Open-Meteo-URL.
        foreach (['start' => $start, 'end' => $end] as $v) {
            if ($v !== null && $v !== '' && !Dates::isIsoDate($v)) {
                Response::error($this->i18n->t('errors.common.dateInvalid', ['date' => $v]));
            }
        }
        $start = $start ?: null;
        $end   = $end ?: null;
        if ($start !== null && $end !== null && $start > $end) {
            Response::error($this->i18n->t('errors.temperature.rangeInvalid', ['start' => $start, 'end' => $end]));
        }
        // v2.8.0 — `reload=1`: auch Einträge von vor v2.8.0 (ohne Quelle) durch
        // Archivwerte ersetzen. `auto=1`: Aufruf beim App-Start — nur mit
        // eingeschaltetem `weather_auto_fill` und höchstens einmal am Tag.
        $reload = in_array($req->queryParam('reload'), ['1', 'true'], true);
        $auto   = in_array($req->queryParam('auto'), ['1', 'true'], true);
        if ($auto && !$this->settings->get('weather_auto_fill', true)) {
            Response::json(['skipped' => true, 'reason' => 'auto_fill_off']);
        }
        Response::json($this->temps->syncOpenMeteo($start, $end, $reload, $auto));
    }

    public function delete(Request $req): never
    {
        $this->temps->delete($req->param('date'));
        Response::json(['deleted' => true]);
    }
}
