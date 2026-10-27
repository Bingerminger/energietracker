<?php
declare(strict_types=1);

namespace Energietracker\Controllers;

use Energietracker\Http\Request;
use Energietracker\Http\Response;
use Energietracker\Services\I18nService;
use Energietracker\Services\MeterService;
use Energietracker\Services\ReadingCheckService;

/**
 * v3.9.0 (ablesung-pruefen) — Prüfung der Zwischenablesungen eines Zählers.
 *
 * `GET /api/utility/{u}/meters/{id}/reading-checks` → `checks` (Stände mit
 * gemeinsamem Versatz, gemessen gegen erwartet davor und danach), `duplicates`
 * (derselbe Stand am selben Tag zweimal), `level_shift` (Hinweis auf die
 * Analyse-Zäsur oder null), `basis`, `unit`, `billing_only` (IDs der Stände
 * „nur für die Abrechnung“). Die Antworten der Knöpfe gehen über die
 * bestehenden Routen der Ablesungen (`is_verified`, `is_billing_only`, Löschen).
 */
final class ReadingCheckController
{
    public function __construct(
        private ReadingCheckService $checks,
        private MeterService $meters,
        private I18nService $i18n,
    ) {}

    public function meter(Request $req): never
    {
        $utility = (string)$req->param('utility');
        $meter = $this->meters->get($utility, (string)$req->param('id'));
        if (!$meter) Response::error($this->i18n->t('errors.meter.notFound'), 404);
        Response::json($this->checks->forMeter($utility, $meter));
    }
}
