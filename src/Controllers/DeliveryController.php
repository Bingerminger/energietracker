<?php
declare(strict_types=1);

namespace Energietracker\Controllers;

use Energietracker\Http\Request;
use Energietracker\Http\Response;
use Energietracker\Services\DeliveryService;
use Energietracker\Services\ConsumptionService;
use Energietracker\Services\MeterService;

/**
 * Lieferungs-CRUD für Heizöl und Pellets sowie der Tank-/Lager-
 * Bestandsverlauf als Tagesreihe (für die Bestandskurve im UI und die
 * Warnung „Tank fast leer").
 */
final class DeliveryController
{
    public function __construct(
        private DeliveryService $deliveries,
        private ConsumptionService $consumption,
        private MeterService $meters,
    ) {}

    public function index(Request $req): never
    {
        $meterId = $req->queryParam('meter_id');
        Response::json($this->deliveries->list($req->param('utility'), $meterId));
    }

    public function create(Request $req): never
    {
        $d = $this->deliveries->create($req->param('utility'), (array)$req->body);
        Response::json($d, 201);
    }

    public function update(Request $req): never
    {
        $d = $this->deliveries->update($req->param('utility'), $req->param('id'), (array)$req->body);
        Response::json($d);
    }

    public function destroy(Request $req): never
    {
        $this->deliveries->delete($req->param('utility'), $req->param('id'));
        Response::json(['deleted' => true]);
    }

    /**
     * GET /api/utility/{utility}/meters/{id}/stock-history
     * Tagesreihe des Tank-/Lagerbestands.
     */
    public function stockHistory(Request $req): never
    {
        Response::json($this->deliveries->stockHistory(
            $req->param('utility'),
            $req->param('id'),
            $this->consumption
        ));
    }
}
