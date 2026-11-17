<?php
declare(strict_types=1);

namespace Energietracker\Controllers;

use Energietracker\Config\Utilities;
use Energietracker\Http\Request;
use Energietracker\Http\Response;
use Energietracker\Services\MeterService;
use Energietracker\Services\I18nService;
use Energietracker\Services\ResponsibilityService;

/**
 * Zähler-CRUD inkl. F2-Zählertausch (`replace-device`). Beim
 * Anlegen ohne explizites Device wird ein Default-Device erzeugt.
 *
 * v3.7.0 — Zuständig: `assignee_id` (Person aus dem Verzeichnis) schreiben;
 * die Antworten tragen dazu `assignee`, `assignee_former`, `assignee_deleted`.
 */
final class MeterController
{
    public function __construct(
        private MeterService $meters,
        private I18nService $i18n,
        private ?ResponsibilityService $responsibility = null,
    ) {}

    /** v3.7.0 — Zähler mit Zuständigkeit für die Antwort. */
    private function out(?array $m): ?array
    {
        return $m === null || $this->responsibility === null ? $m : $this->responsibility->decorate($m);
    }

    public function index(Request $req): never
    {
        $list = $this->meters->list($req->param('utility'));
        Response::json($this->responsibility?->decorateAll($list) ?? $list);
    }

    /**
     * v3.13.0 — GET /api/meters-overview[?utilities=gas,strom]: Zähler und Zählergruppen mehrerer Verbrauchsarten
     * in einer Antwort, für Haushalt › Zähler „Alle“ (bis dahin je Art zwei Anfragen, bei neun Arten 18). Je Art
     * genau das, was `…/meters` und `…/meter-groups` liefern, in der verlangten Reihenfolge; ohne Angabe alle
     * Arten der SSOT. Eine unbekannte Art ist ein Fehler (400), wie bei den Einzelabfragen. Nur lesend
     * (Lektion 9: Aggregat zum Lesen, Schreiben bleibt je Zähler).
     */
    public function overview(Request $req): never
    {
        $raw = trim((string)($req->queryParam('utilities') ?? ''));
        $keys = $raw === '' ? Utilities::keys() : array_values(array_unique(array_filter(array_map('trim', explode(',', $raw)), 'strlen')));
        foreach ($keys as $k) {
            if (!Utilities::exists($k)) {
                throw new \InvalidArgumentException($this->i18n->t('errors.common.unknownUtility', ['utility' => $k]));
            }
        }
        $out = [];
        foreach ($keys as $k) {
            $list = $this->meters->list($k);
            $out[] = [
                'utility' => $k,
                'meters'  => $this->responsibility?->decorateAll($list) ?? $list,
                'groups'  => $this->meters->listGroups($k),
            ];
        }
        Response::json(['utilities' => $out]);
    }

    public function show(Request $req): never
    {
        $m = $this->meters->get($req->param('utility'), $req->param('id'));
        if (!$m) Response::error($this->i18n->t('errors.meter.notFound'), 404);
        Response::json($this->out($m));
    }

    public function create(Request $req): never
    {
        $m = $this->meters->create($req->param('utility'), (array)$req->body);
        Response::json($this->out($m), 201);
    }

    public function update(Request $req): never
    {
        $m = $this->meters->update($req->param('utility'), $req->param('id'), (array)$req->body);
        Response::json($this->out($m));
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
        Response::json(is_array($m) ? $this->out($m) : $m);
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
