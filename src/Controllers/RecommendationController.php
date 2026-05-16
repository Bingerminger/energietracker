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
        $this->recs->dismiss($id, $until);
        Response::json(['dismissed' => true, 'id' => $id]);
    }
}
