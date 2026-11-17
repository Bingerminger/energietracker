<?php
declare(strict_types=1);

namespace Energietracker\Controllers;

use Energietracker\Http\Request;
use Energietracker\Http\Response;
use Energietracker\Services\I18nService;
use Energietracker\Services\MeterService;
use Energietracker\Services\ReadingCheckService;
use Energietracker\Support\Dates;

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

    /**
     * v3.13.0 (#65) — `GET /api/utility/{u}/meters/{id}/reading-expectation?from=&to=[&exclude=]`:
     * die erwartete Menge zwischen dem vorigen Stand (`from`) und dem neuen (`to`) nach dem Wetter, für
     * die Rückfrage beim Eintragen (ReadingCheckService::expectationFor). `exclude`: der Stand, der gerade
     * bearbeitet wird. `basis` null heißt: keine Erwartung nach dem Wetter, die Erfassung prüft wie bisher.
     */
    public function expectation(Request $req): never
    {
        $utility = (string)$req->param('utility');
        $meter = $this->meters->get($utility, (string)$req->param('id'));
        if (!$meter) Response::error($this->i18n->t('errors.meter.notFound'), 404);
        $from = (string)($req->queryParam('from') ?? '');
        $to   = (string)($req->queryParam('to') ?? '');
        if (!Dates::isIsoDate($from) || !Dates::isIsoDate($to) || $from >= $to) {
            Response::error($this->i18n->t('errors.billCheck.invalidRange'), 400);
        }
        $exclude = trim((string)($req->queryParam('exclude') ?? ''));
        Response::json($this->checks->expectationFor($utility, $meter, $from, $to, $exclude !== '' ? $exclude : null));
    }

    /**
     * v3.13.0 (Paket K1, #65) — `POST /api/utility/{u}/meters/{id}/reading-expectations` mit
     * `{pairs: [{from, to}, …]}` → `{expectations: […]}`: dieselbe Erwartung wie `GET …/reading-expectation` für
     * viele Abstände in einer Anfrage (ReadingCheckService::expectationsFor), für die Vorschau eines CSV-Imports.
     * Schreibt nichts. Höchstens ReadingCheckService::MAX_PAIRS Abstände mit zusammen höchstens MAX_PAIR_DAYS Tagen;
     * ein Abstand ohne Datum oder rückwärts: 400. v3.13.0 (Paket K2e) — optional `exclude: [id, …]`: Stände, die die
     * Datei ersetzt; sie zählen nicht zur Vorgeschichte.
     */
    public function expectations(Request $req): never
    {
        $utility = (string)$req->param('utility');
        $meter = $this->meters->get($utility, (string)$req->param('id'));
        if (!$meter) Response::error($this->i18n->t('errors.meter.notFound'), 404);
        $body = $req->body;
        $list = is_array($body) && array_key_exists('pairs', $body) ? $body['pairs'] : null;
        if (!is_array($list) || !array_is_list($list)) {
            Response::error($this->i18n->t('errors.common.listExpected', ['field' => 'pairs']), 400);
        }
        if (count($list) > ReadingCheckService::MAX_PAIRS) {
            Response::error($this->i18n->t('errors.readingCheck.tooMany', ['max' => ReadingCheckService::MAX_PAIRS, 'count' => count($list)]), 400);
        }
        $pairs = [];
        foreach ($list as $p) {
            $from = is_array($p) && is_string($p['from'] ?? null) ? $p['from'] : '';
            $to   = is_array($p) && is_string($p['to'] ?? null) ? $p['to'] : '';
            if ($from >= $to || !Dates::isIsoDate($from) || !Dates::isIsoDate($to)) {
                Response::error($this->i18n->t('errors.billCheck.invalidRange'), 400);
            }
            $pairs[] = [$from, $to];
        }
        // die Arbeit hängt auch an den Tagen (Gradtage je Tag), nicht nur an der Zahl der Abstände
        $total = 0;
        foreach ($pairs as [$from, $to]) $total += (int)round((strtotime($to . 'T00:00:00Z') - strtotime($from . 'T00:00:00Z')) / 86400);
        if ($total > ReadingCheckService::MAX_PAIR_DAYS) {
            Response::error($this->i18n->t('errors.readingCheck.tooManyDays', ['max' => ReadingCheckService::MAX_PAIR_DAYS, 'count' => $total]), 400);
        }
        // v3.13.0 (Paket K2e) — `exclude` (optional): die gespeicherten Stände, die die Datei mit einem anderen Wert
        // überschreibt. Sie bestimmen für keinen Abstand die Erwartung mit, wie der bearbeitete Stand in der Einzelabfrage.
        $exclude = is_array($body) && array_key_exists('exclude', $body) ? $body['exclude'] : [];
        if (!is_array($exclude) || !array_is_list($exclude) || count($exclude) > ReadingCheckService::MAX_PAIRS
            || array_filter($exclude, fn($x) => !is_string($x) || trim($x) === '') !== []) {
            Response::error($this->i18n->t('errors.common.listExpected', ['field' => 'exclude']), 400);
        }
        Response::json(['expectations' => $this->checks->expectationsFor($utility, $meter, $pairs, $exclude)]);
    }
}
