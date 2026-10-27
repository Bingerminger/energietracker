<?php
declare(strict_types=1);

namespace Energietracker\Controllers;

use Energietracker\Http\Request;
use Energietracker\Http\Response;
use Energietracker\Services\I18nService;
use Energietracker\Services\MeteringConceptService;

/**
 * v3.9.0 (F1032, GitHub #40) — Assistent „Messkonzept“:
 * `POST /api/metering-concept/preview` (schreibt nichts) und
 * `POST /api/metering-concept` (legt Zähler, Gruppen und Verträge an).
 */
final class MeteringConceptController
{
    public function __construct(
        private MeteringConceptService $concepts,
        private I18nService $i18n,
    ) {}

    public function preview(Request $req): never
    {
        Response::json($this->concepts->plan($this->body($req)));
    }

    public function apply(Request $req): never
    {
        Response::json($this->concepts->apply($this->body($req)), 201);
    }

    /** @return array<string,mixed> */
    private function body(Request $req): array
    {
        $b = $req->body;
        if (!is_array($b)) Response::error($this->i18n->t('errors.concept.field', ['field' => 'body']), 400, null, 'errors.concept.field');
        return $b;
    }
}
