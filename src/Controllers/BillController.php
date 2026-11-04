<?php
declare(strict_types=1);

namespace Energietracker\Controllers;

use Energietracker\Http\Request;
use Energietracker\Http\Response;
use Energietracker\Services\BillService;
use Energietracker\Services\ReadingService;

/**
 * v3.1.0 (Paket H5, UI-35) — Versorgerrechnungen: Liste, anlegen, ändern,
 * löschen, gegen die eigene Rechnung prüfen, Ergebnis buchen.
 *
 * v3.11.0 (Skeptiker M5) — Anlegen und Ändern antworten additiv mit `warnings`
 * (`estimated_reading_backwards`), wenn der geschätzte Endstand der Rechnung nicht zu den
 * eigenen Ablesungen passt (Zähler liefe rückwärts); gespeichert wird trotzdem.
 */
final class BillController
{
    public function __construct(private BillService $bills, private ?ReadingService $readings = null) {}

    public function index(Request $req): never
    {
        Response::json($this->bills->list((string)$req->param('utility'), $req->queryParam('meter_id')));
    }

    public function create(Request $req): never
    {
        $u = (string)$req->param('utility');
        Response::json($this->warn($u, $this->bills->create($u, (array)$req->body)), 201);
    }

    public function update(Request $req): never
    {
        $u = (string)$req->param('utility');
        Response::json($this->warn($u, $this->bills->update($u, (string)$req->param('id'), (array)$req->body)));
    }

    /** @param array<string,mixed> $b */
    private function warn(string $u, array $b): array
    {
        return $this->readings !== null ? $this->bills->withEstimateWarnings($u, $b, $this->readings) : $b;
    }

    public function destroy(Request $req): never
    {
        $this->bills->delete((string)$req->param('utility'), (string)$req->param('id'));
        Response::json(['deleted' => true]);
    }

    public function check(Request $req): never
    {
        Response::json($this->bills->compare((string)$req->param('utility'), (string)$req->param('id')));
    }

    public function book(Request $req): never
    {
        Response::json($this->bills->book((string)$req->param('utility'), (string)$req->param('id')));
    }
}
