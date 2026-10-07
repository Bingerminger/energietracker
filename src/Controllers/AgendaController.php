<?php
declare(strict_types=1);

namespace Energietracker\Controllers;

use Energietracker\Http\Request;
use Energietracker\Http\Response;
use Energietracker\Services\AgendaService;
use Energietracker\Services\CalendarService;
use Energietracker\Services\SummaryService;

/**
 * v3.1.0 (Paket H1) — Fristen und Kennzahlen für außen:
 *   GET /api/agenda?days=90   Ereignisse (Klasse B), Quelle für „Zu tun"
 *   GET /api/calendar.ics     Kalender-Abo (Klasse A: Form und UIDs)
 *   GET /api/summary          Kennzahlen für Home Assistant (Klasse A)
 */
final class AgendaController
{
    public function __construct(
        private AgendaService $agenda,
        private CalendarService $calendar,
        private SummaryService $summary,
    ) {}

    public function agenda(Request $req): never
    {
        $days = (int)($req->queryParam('days') ?? 90);
        Response::json(['events' => $this->agenda->events($days)]);
    }

    public function calendar(Request $req): never
    {
        $ics = $this->calendar->ics();
        while (ob_get_level() > 0) ob_end_clean();
        header('Content-Type: text/calendar; charset=utf-8');
        header('Content-Disposition: inline; filename="energietracker.ics"');
        header('Cache-Control: private, max-age=900');
        header('Content-Length: ' . strlen($ics));
        echo $ics;
        exit;
    }

    public function summary(Request $req): never
    {
        $utility = $req->queryParam('utility');
        $meter = $req->queryParam('meter');
        $data = $this->summary->build($utility !== '' ? $utility : null, $meter !== '' ? $meter : null);
        while (ob_get_level() > 0) ob_end_clean();
        // Home Assistant fragt periodisch; fünf Minuten genügen und schonen die Rechnung
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: private, max-age=300');
        header('Vary: X-ET-Language, Accept-Language');
        echo json_encode(['success' => true, 'data' => $data], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}
