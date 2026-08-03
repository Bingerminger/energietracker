<?php
declare(strict_types=1);

namespace Energietracker\Controllers;

use Energietracker\Http\Request;
use Energietracker\Http\Response;
use Energietracker\Services\MeterService;
use Energietracker\Services\I18nService;

/**
 * Zähler-CRUD inkl. F2-Zählertausch (`replace-device`). Beim
 * Anlegen ohne explizites Device wird ein Default-Device erzeugt.
 */
final class MeterController
{
    public function __construct(
        private MeterService $meters,
        private I18nService $i18n,
    ) {}

    public function index(Request $req): never
    {
        Response::json($this->meters->list($req->param('utility')));
    }

    public function show(Request $req): never
    {
        $m = $this->meters->get($req->param('utility'), $req->param('id'));
        if (!$m) Response::error($this->i18n->t('errors.meter.notFound'), 404);
        Response::json($m);
    }

    public function create(Request $req): never
    {
        $m = $this->meters->create($req->param('utility'), (array)$req->body);
        Response::json($m, 201);
    }

    public function update(Request $req): never
    {
        $m = $this->meters->update($req->param('utility'), $req->param('id'), (array)$req->body);
        Response::json($m);
    }

    public function destroy(Request $req): never
    {
        $this->meters->delete($req->param('utility'), $req->param('id'));
        Response::json(['deleted' => true]);
    }

    /** POST /api/utility/{utility}/meters/{id}/replace-device */
    public function replaceDevice(Request $req): never
    {
        $m = $this->meters->replaceDevice($req->param('utility'), $req->param('id'), (array)$req->body);
        Response::json($m);
    }

    // ── v1.2.0 — F1006 Zählergruppen ──────────────────────────────────────

    /** GET /api/utility/{utility}/meter-groups */
    public function listGroups(Request $req): never
    {
        Response::json($this->meters->listGroups($req->param('utility')));
    }

    /** POST /api/utility/{utility}/meter-groups */
    public function createGroup(Request $req): never
    {
        $g = $this->meters->createGroup($req->param('utility'), (array)$req->body);
        Response::json($g, 201);
    }

    /** PATCH /api/utility/{utility}/meter-groups/{groupId} */
    public function updateGroup(Request $req): never
    {
        $g = $this->meters->updateGroup($req->param('utility'), $req->param('groupId'), (array)$req->body);
        Response::json($g);
    }

    /** DELETE /api/utility/{utility}/meter-groups/{groupId} */
    public function destroyGroup(Request $req): never
    {
        $this->meters->deleteGroup($req->param('utility'), $req->param('groupId'));
        Response::json(['deleted' => true]);
    }

    /** POST /api/utility/{utility}/meter-groups/merge — Merge-Wizard */
    public function mergeGroup(Request $req): never
    {
        $result = $this->meters->mergeIntoGroup($req->param('utility'), (array)$req->body);
        Response::json($result, 201);
    }
}
