<?php
declare(strict_types=1);

namespace Energietracker\Controllers;

use Energietracker\Http\Request;
use Energietracker\Http\Response;
use Energietracker\Services\AuthService;
use Energietracker\Services\IngestService;
use Energietracker\Services\I18nService;

/**
 * F1009 — Push-Ingest-Endpoint für Home Assistant.
 *
 *   POST /api/ingest
 *   Header: Authorization: Bearer <token>   (nur falls ein Token gesetzt ist)
 *   Body:   { "utility":"strom", "meter":"stromzaehler_haus",
 *             "value":12345.6, "date":"2026-06-01" }   // date optional
 *
 * Auth-Modell (opt-in): Ist KEIN Token konfiguriert, ist der Endpoint offen
 * (unverändertes LAN-Verhalten). Sobald ein Token existiert, muss er als
 * Bearer mitgeschickt werden — sonst 401.
 */
final class IngestController
{
    public function __construct(
        private IngestService $ingest,
        private AuthService $auth,
        private I18nService $i18n,
    ) {}

    public function store(Request $req): never
    {
        // v2.6.0 — Mit eingeschalteter Anmeldung braucht auch der Ingest einen
        // Token; sonst bliebe er die eine offene Tür. Ohne Anmeldung gilt das
        // bisherige opt-in-Verhalten unverändert.
        if ($this->auth->loginEnabled() && !$this->auth->requiresAuth()) {
            Response::error($this->i18n->t('errors.ingest.tokenRequiredWithLogin'), 401);
        }
        if ($this->auth->requiresAuth() && !$this->auth->verify($req->bearerToken())) {
            Response::error($this->i18n->t('errors.ingest.unauthorized'), 401);
        }
        $body = $req->body;
        // v3.1.0 (API-34) — Stapel: eine Liste oder {"readings": [...]}. Antwort 200
        // auch bei Teilfehlern (je Eintrag `status`/`code`); das Einzelobjekt
        // bleibt unverändert (201/200 wie bisher).
        if (is_array($body) && ((array_is_list($body) && $body !== []) || is_array($body['readings'] ?? null))) {
            $items = array_is_list($body) ? $body : $body['readings'];
            if (!array_is_list($items) || $items === []) {
                Response::error($this->i18n->t('errors.ingest.bodyInvalid'), 400, null, 'errors.ingest.bodyInvalid');
            }
            Response::json($this->ingest->ingestMany($items));
        }
        $result = $this->ingest->ingest((array)$body);
        // 201 bei neu angelegt, 200 bei Aktualisierung (upsert-by-date).
        Response::json($result, $result['status'] === 'created' ? 201 : 200);
    }
}
