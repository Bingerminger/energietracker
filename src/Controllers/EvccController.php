<?php
declare(strict_types=1);

namespace Energietracker\Controllers;

use Energietracker\Http\Request;
use Energietracker\Http\Response;
use Energietracker\Services\EvccService;
use Energietracker\Services\I18nService;
use Energietracker\Support\LocalizedException;

/**
 * v3.2.0 (F1022) — Ladevorgänge aus evcc.
 *
 *   POST /api/utility/strom/meters/{id}/import-evcc[?dry_run=1]  Body {csv, counters?, loadpoint?}
 *   POST /api/utility/strom/meters/{id}/sync-evcc[?dry_run=1]    Body {counters?, loadpoint?} — Abruf im Heimnetz
 *   GET  /api/ev-sessions?meter_id=&year=                        Vorgänge + Monatssummen
 */
final class EvccController
{
    public function __construct(private EvccService $evcc, private I18nService $i18n) {}

    public function importCsv(Request $req): never
    {
        $this->respond(fn() => $this->evcc->importCsv((string)$req->param('id'), (string)(((array)$req->body)['csv'] ?? ''), $this->opts($req)));
    }

    public function sync(Request $req): never
    {
        $this->respond(fn() => $this->evcc->sync((string)$req->param('id'), $this->opts($req)));
    }

    /**
     * Unbekannter Zähler in der Adresse: 404 wie überall. Antwortet evcc nicht
     * oder unbrauchbar, ist das ein 502 (Fehler auf der anderen Seite) wie bei
     * Texterkennung und SMARD — keine falsche Eingabe. Alles andere: 400.
     */
    private function respond(callable $run): never
    {
        try {
            $r = $run();
        } catch (LocalizedException $e) {
            $status = match ($e->key) {
                'errors.common.meterNotFound' => 404,
                'errors.evcc.unreachable', 'errors.evcc.badAnswer' => 502,
                default => null,
            };
            if ($status === null) throw $e;
            Response::error($this->i18n->t($e->key, $e->params), $status, null, $e->key);
        }
        Response::json($r);
    }

    /** Body {counters, loadpoint} und ?dry_run=1 */
    private function opts(Request $req): array
    {
        $body = (array)$req->body;
        return ['dry_run' => $req->queryParam('dry_run') === '1', 'counters' => (string)($body['counters'] ?? 'auto'),
            'loadpoint' => isset($body['loadpoint']) ? (string)$body['loadpoint'] : null];
    }

    public function sessions(Request $req): never
    {
        $meterId = (string)($req->queryParam('meter_id') ?? '');
        $y = (string)($req->queryParam('year') ?? '');
        if ($y !== '' && (!ctype_digit($y) || (int)$y < 2017 || (int)$y > 2100)) {
            Response::error($this->i18n->t('errors.evReport.yearInvalid', ['year' => $y]), 400, null, 'errors.evReport.yearInvalid');
        }
        $year = $y === '' ? null : (int)$y;
        Response::json([
            'sessions' => $this->evcc->sessions($meterId === '' ? null : $meterId, $year),
            'monthly'  => $meterId !== '' && $year !== null ? $this->evcc->monthly($meterId, $year) : null,
        ]);
    }
}
