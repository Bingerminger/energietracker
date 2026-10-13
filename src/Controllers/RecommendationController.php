<?php
declare(strict_types=1);

namespace Energietracker\Controllers;

use Energietracker\Http\Request;
use Energietracker\Http\Response;
use Energietracker\Services\RecommendationService;

/**
 * v1.3.0 — Empfehlungen (statistische Insights).
 *   GET  /api/recommendations
 *   POST /api/recommendations/{id}/dismiss   {until?: "YYYY-MM-DD"}
 *        v3.4.0 (F1024) — Antwort zusätzlich `until` und `activity_id`
 *   DELETE /api/recommendations/{id}/dismiss (v2.12.0 — wieder einblenden)
 */
final class RecommendationController
{
    public function __construct(private RecommendationService $recs) {}

    public function index(Request $req): never
    {
        $incl = $req->queryParam('include_dismissed') === '1';
        Response::json($this->recs->all($incl));
    }

    public function dismiss(Request $req): never
    {
        $id = $req->param('id');
        $until = is_array($req->body) ? ($req->body['until'] ?? null) : null;
        $r = $this->recs->dismiss($id, is_string($until) && $until !== '' ? $until : null);
        Response::json(['dismissed' => true, 'id' => $id, 'until' => $r['until'], 'activity_id' => $r['activity_id']]);
    }

    public function restore(Request $req): never
    {
        $id = $req->param('id');
        $this->recs->restore($id);
        Response::json(['dismissed' => false, 'id' => $id]);
    }
}
