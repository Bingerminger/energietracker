<?php
declare(strict_types=1);

namespace Energietracker\Controllers;

use Energietracker\Http\Request;
use Energietracker\Http\Response;
use Energietracker\Services\ReadingService;

/**
 * Ablesungs-CRUD. Auto-Zuweisung von device_id zum aktiven Device
 * des Meters.
 */
final class ReadingController
{
    public function __construct(private ReadingService $readings) {}

    public function index(Request $req): never
    {
        $meterId = $req->queryParam('meter_id');
        Response::json($this->readings->list($req->param('utility'), $meterId));
    }

    public function create(Request $req): never
    {
        $r = $this->readings->create($req->param('utility'), (array)$req->body);
        Response::json($r, 201);
    }

    public function update(Request $req): never
    {
        $r = $this->readings->update($req->param('utility'), $req->param('id'), (array)$req->body);
        Response::json($r);
    }

    public function destroy(Request $req): never
    {
        $this->readings->delete($req->param('utility'), $req->param('id'));
        Response::json(['deleted' => true]);
    }
}
